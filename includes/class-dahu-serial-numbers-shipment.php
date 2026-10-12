<?php
/**
 * Closing a parcel: the units go out, the tracking number goes on the order.
 *
 * ## What this is, and what it deliberately is not
 *
 * It is not a carrier integration. The plugin this was extracted from talks to
 * Sendcloud and to Colissimo, creates labels through their APIs, downloads the
 * PDF and stores it — and every line of that needs an account, credentials, a
 * contract and a parcel-type mapping that is particular to one shop. Shipping
 * an interface with no implementation behind it would be shipping scaffolding:
 * a feature in the readme that does nothing on a fresh install until somebody
 * writes a PHP class.
 *
 * What generalises is the rest of the same step, and it is the part that
 * matters for traceability: the parcel is closed, every unit in it is recorded
 * as having left, the carrier and tracking number are written on the order
 * where support will look for them, and the order moves on. That works on any
 * shop, with no account anywhere.
 *
 * A carrier integration hooks `dahu_serial_numbers_before_shipment` to create
 * its label and hand back a tracking number, or
 * `dahu_serial_numbers_shipped` to do something once the parcel is closed.
 *
 * @package DahuSerialNumbers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Marking an order as shipped.
 */
class Dahu_Serial_Numbers_Shipment {

	/**
	 * Order meta holding the carrier.
	 */
	const META_CARRIER = '_dahu_serial_numbers_carrier';

	/**
	 * Order meta holding the tracking number.
	 */
	const META_TRACKING = '_dahu_serial_numbers_tracking';

	/**
	 * Closes a parcel.
	 *
	 * @param WC_Order $order      Order.
	 * @param array    $args {
	 *     @type string $carrier    Optional. Carrier name, as the shop calls it.
	 *     @type string $tracking   Optional. Tracking number.
	 *     @type int    $station_id Optional. Which bench did it.
	 * }
	 * @return array|WP_Error {shipped: string[], status: string}
	 */
	public static function ship( WC_Order $order, array $args = array() ) {

		$carrier    = isset( $args['carrier'] ) ? sanitize_text_field( (string) $args['carrier'] ) : '';
		$tracking   = isset( $args['tracking'] ) ? sanitize_text_field( (string) $args['tracking'] ) : '';
		$station_id = isset( $args['station_id'] ) ? (int) $args['station_id'] : 0;

		$missing = Dahu_Serial_Numbers_Manager::missing_for_order( $order->get_id() );

		// Refused rather than shipped-with-a-warning. The whole point of the
		// screen is that the parcel and the record agree, and a parcel closed
		// with a unit unaccounted for is a record that says a unit is still in
		// stock when it is in a van.
		if ( $missing ) {
			$owed = 0;

			foreach ( $missing as $line ) {
				$owed += (int) $line['required'] - (int) $line['assigned'];
			}

			return new WP_Error(
				'dahu_serial_numbers_incomplete',
				sprintf(
					/* translators: %s: number of units. */
					_n(
						'%s unit on this order still has no serial number.',
						'%s units on this order still have no serial number.',
						$owed,
						'dahu-serial-numbers'
					),
					number_format_i18n( $owed )
				),
				array(
					'status'  => 409,
					'missing' => $missing,
				)
			);
		}

		$label_error = '';

		try {
			/**
			 * Fires before a parcel is closed.
			 *
			 * Where a carrier integration creates its label. Returning a
			 * tracking number here is how it gets onto the order without this
			 * class knowing anything about that carrier.
			 *
			 * @param string   $tracking   Tracking number so far, possibly empty.
			 * @param WC_Order $order      Order being shipped.
			 * @param string   $carrier    Carrier name.
			 * @param int      $station_id Workshop post doing it.
			 */
			$tracking = (string) apply_filters( 'dahu_serial_numbers_before_shipment', $tracking, $order, $carrier, $station_id );
			$tracking = sanitize_text_field( $tracking );
		} catch ( Throwable $e ) {
			// A carrier's API is a third party over a network, and it will be
			// down one morning. What happens then is a decision, and this is
			// the one this plugin takes by default:
			//
			// The parcel is closed anyway, and the failure is recorded.
			//
			// Because the two things that could be lost are not worth the
			// same. A tracking number lost is recoverable — somebody adds it
			// to the order this afternoon. The record of WHICH UNITS WENT IN
			// WHICH BOX is not: the box is taped shut and on a van, and the
			// only person who knew is at the next bench.
			//
			// Letting the exception through would mean a 500 on the packing
			// screen, a sealed parcel, and nothing on file — the plugin
			// failing at the one job it has because somebody else's server
			// did not answer.
			//
			// A shop that would rather refuse says so, below.
			$label_error = $e->getMessage();
		}

		/**
		 * Filters whether a carrier failure stops the parcel being closed.
		 *
		 * False by default — see the reasoning just above. True for a shop
		 * where nothing may be recorded as shipped without a real label.
		 *
		 * @param bool     $block Whether to refuse.
		 * @param WC_Order $order Order being shipped.
		 * @param string   $error The carrier's message.
		 */
		if ( '' !== $label_error && apply_filters( 'dahu_serial_numbers_block_on_label_failure', false, $order, $label_error ) ) {
			// Recorded on the order, not in the serial history. Nothing
			// happened to any unit — the parcel was refused — so a line in
			// each unit's history would say a unit moved when none did. The
			// order is the subject of this event, and an order note is where
			// support looks.
			$order->add_order_note(
				sprintf(
					/* translators: %s: the carrier's error message. */
					__( 'Shipment refused: the carrier could not create the label (%s). Nothing was recorded as shipped.', 'dahu-serial-numbers' ),
					$label_error
				)
			);

			return new WP_Error(
				'dahu_serial_numbers_label_failed',
				sprintf(
					/* translators: %s: the carrier's error message. */
					__( 'The carrier could not create the label: %s', 'dahu-serial-numbers' ),
					$label_error
				),
				array( 'status' => 502 )
			);
		}

		$shipped = array();

		foreach ( Dahu_Serial_Numbers_Manager::get_by_order( $order->get_id() ) as $row ) {
			if ( Dahu_Serial_Numbers_Statuses::ASSIGNED !== $row['status'] ) {
				continue;
			}

			$moved = Dahu_Serial_Numbers_Manager::transition(
				$row['serial'],
				Dahu_Serial_Numbers_Statuses::SHIPPED,
				array(
					'event'       => 'shipment',
					'station_id'  => $station_id ? $station_id : null,
					'carrier'     => '' !== $carrier ? $carrier : null,
					'tracking'    => '' !== $tracking ? $tracking : null,
					'label_error' => '' !== $label_error ? $label_error : null,
				)
			);

			// One unit that refuses to move does not stop the parcel. It is
			// recorded and reported, because the alternative is a half-shipped
			// order whose screen says it failed.
			if ( is_wp_error( $moved ) ) {
				Dahu_Serial_Numbers_Log::record(
					$row['serial'],
					'shipment_failed',
					array(
						'order_id' => $order->get_id(),
						'context'  => array( 'reason' => $moved->get_error_message() ),
					)
				);

				continue;
			}

			$shipped[] = (string) $row['serial'];
		}

		if ( '' !== $carrier ) {
			$order->update_meta_data( self::META_CARRIER, $carrier );
		}

		if ( '' !== $tracking ) {
			$order->update_meta_data( self::META_TRACKING, $tracking );
		}

		// A note on the order, because that is where support looks — and
		// because an order note is the one record of this that survives the
		// plugin being removed.
		$order->add_order_note( self::note( $shipped, $carrier, $tracking, $label_error ) );
		$order->save();

		/**
		 * Filters the status an order moves to once its parcel is closed.
		 *
		 * @param string   $status Status key, without the wc- prefix.
		 * @param WC_Order $order  Order.
		 */
		$status = (string) apply_filters( 'dahu_serial_numbers_shipment_status', 'completed', $order );

		if ( '' !== $status && $order->get_status() !== $status ) {
			$order->update_status( $status, __( 'Packed and shipped.', 'dahu-serial-numbers' ) );
		}

		/**
		 * Fires once a parcel has been closed.
		 *
		 * @param WC_Order $order    Order.
		 * @param string[] $shipped  Serials that left with it.
		 * @param string   $carrier  Carrier name.
		 * @param string   $tracking Tracking number.
		 */
		do_action( 'dahu_serial_numbers_shipped', $order, $shipped, $carrier, $tracking );

		return array(
			'shipped'     => $shipped,
			'status'      => $order->get_status(),
			'label_error' => $label_error,
		);
	}

	/**
	 * The order note.
	 *
	 * @param string[] $shipped     Serials.
	 * @param string   $carrier     Carrier.
	 * @param string   $tracking    Tracking number.
	 * @param string   $label_error The carrier's message, when it failed.
	 * @return string
	 */
	private static function note( array $shipped, $carrier, $tracking, $label_error = '' ) {

		$lines = array();

		$lines[] = $shipped
			? sprintf(
				/* translators: %s: comma-separated serial numbers. */
				__( 'Shipped with serial numbers: %s', 'dahu-serial-numbers' ),
				implode( ', ', $shipped )
			)
			: __( 'Shipped. No serial number was on this order.', 'dahu-serial-numbers' );

		if ( '' !== $carrier ) {
			$lines[] = sprintf(
				/* translators: %s: carrier name. */
				__( 'Carrier: %s', 'dahu-serial-numbers' ),
				$carrier
			);
		}

		if ( '' !== $tracking ) {
			$lines[] = sprintf(
				/* translators: %s: tracking number. */
				__( 'Tracking: %s', 'dahu-serial-numbers' ),
				$tracking
			);
		}

		if ( '' !== $label_error ) {
			$lines[] = sprintf(
				/* translators: %s: the carrier's error message. */
				__( 'The carrier could not create the label: %s — the parcel was recorded as shipped anyway, so the units are on file. Add the tracking number by hand once you have it.', 'dahu-serial-numbers' ),
				$label_error
			);
		}

		return implode( "\n", $lines );
	}

	/**
	 * The carrier recorded on an order.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function carrier( WC_Order $order ) {

		return (string) $order->get_meta( self::META_CARRIER );
	}

	/**
	 * The tracking number recorded on an order.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function tracking( WC_Order $order ) {

		return (string) $order->get_meta( self::META_TRACKING );
	}

	/**
	 * A link to follow the parcel, when the shop can build one.
	 *
	 * Empty by default: there is no list of carrier tracking URLs in this
	 * plugin, and a guessed one that 404s is worse than none.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function tracking_url( WC_Order $order ) {

		$tracking = self::tracking( $order );

		if ( '' === $tracking ) {
			return '';
		}

		/**
		 * Filters the tracking URL for an order.
		 *
		 * @param string   $url      Empty by default.
		 * @param string   $tracking Tracking number.
		 * @param string   $carrier  Carrier name.
		 * @param WC_Order $order    Order.
		 */
		return esc_url_raw( (string) apply_filters( 'dahu_serial_numbers_tracking_url', '', $tracking, self::carrier( $order ), $order ) );
	}
}
