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
 * as having left, the carrier and tracking number are written where support
 * will look for them, and the order moves on. That works on any shop, with no
 * account anywhere.
 *
 * ## The parcel is the record, and the record is a consignment
 *
 * This class used to write the carrier and tracking number to order meta and
 * stop there, which allowed exactly one parcel per order and left the link
 * between a tracking number and its units to be guessed from the history. It
 * now opens a consignment, puts the units in it, and closes it —
 * `Dahu_Serial_Numbers_Consignments` holds the record, this class performs the
 * movement. Two consequences worth stating:
 *
 *   - an order can ship in several parcels, each with its own units and its
 *     own tracking number, by passing `serials` below.
 *   - the order meta is still written, as a mirror of the most recent
 *     outbound parcel. Not for this plugin's benefit — for the theme, the
 *     e-mail, the export and the support person who has been looking there
 *     for two years.
 *
 * ## Where a carrier integration goes
 *
 * `dahu_serial_numbers_before_shipment` runs with the consignment already
 * open and already holding its units, so an integration can read the list,
 * put it on a customs declaration, and hand back a tracking number. That
 * ordering is the whole point of the hook: before this, the filter received
 * an order and a carrier name and had no way to know which units it was
 * labelling.
 *
 * @package DahuSerialNumbers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Marking an order as shipped.
 */
class Dahu_Serial_Numbers_Shipment {

	/**
	 * Order meta holding the carrier of the most recent parcel.
	 */
	const META_CARRIER = '_dahu_serial_numbers_carrier';

	/**
	 * Order meta holding the tracking number of the most recent parcel.
	 */
	const META_TRACKING = '_dahu_serial_numbers_tracking';

	/**
	 * Order meta holding the reference of the most recent parcel.
	 */
	const META_CONSIGNMENT = '_dahu_serial_numbers_consignment';

	/**
	 * Closes a parcel.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $args {
	 *     @type string   $carrier    Optional. Carrier name, as the shop calls it.
	 *     @type string   $tracking   Optional. Tracking number.
	 *     @type int      $station_id Optional. Which bench did it.
	 *     @type string[] $serials    Optional. Ship only these units. Omitted
	 *                                means every assigned unit on the order,
	 *                                which is the behaviour this method has
	 *                                always had.
	 *     @type string   $source     Optional. Slug of whatever is calling.
	 *     @type float    $weight     Optional. Weight of the parcel itself.
	 *     @type float    $length     Optional. Length of the parcel.
	 *     @type float    $width      Optional. Width of the parcel.
	 *     @type float    $height     Optional. Height of the parcel.
	 * }
	 * @return array|WP_Error {shipped: string[], status: string, consignment_id: int, reference: string}
	 */
	public static function ship( WC_Order $order, array $args = array() ) {

		$carrier    = isset( $args['carrier'] ) ? sanitize_text_field( (string) $args['carrier'] ) : '';
		$tracking   = isset( $args['tracking'] ) ? sanitize_text_field( (string) $args['tracking'] ) : '';
		$station_id = isset( $args['station_id'] ) ? (int) $args['station_id'] : 0;
		$source     = isset( $args['source'] ) ? sanitize_key( (string) $args['source'] ) : 'manual';
		$partial    = isset( $args['serials'] ) && is_array( $args['serials'] );

		$assigned = array();

		foreach ( Dahu_Serial_Numbers_Manager::get_by_order( $order->get_id() ) as $row ) {
			if ( Dahu_Serial_Numbers_Statuses::ASSIGNED === $row['status'] ) {
				$assigned[] = (string) $row['serial'];
			}
		}

		if ( $partial ) {
			$to_ship = self::resolve_subset( $args['serials'], $assigned );

			if ( is_wp_error( $to_ship ) ) {
				return $to_ship;
			}
		} else {
			// The whole order, and the completeness rule that has always
			// applied to it: refused rather than shipped-with-a-warning. The
			// whole point of the screen is that the parcel and the record
			// agree, and a parcel closed with a unit unaccounted for is a
			// record that says a unit is still in stock when it is in a van.
			//
			// This check is deliberately NOT applied to a partial shipment.
			// An order shipping in two parcels has unserialised units on it
			// by definition while the first one goes out, and refusing that
			// would mean the only way to ship half an order is to lie about
			// the other half.
			$missing = Dahu_Serial_Numbers_Manager::missing_for_order( $order->get_id() );

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

			$to_ship = $assigned;
		}

		// No line ships more units than it ordered.
		//
		// This is the mirror of the completeness rule above, and it was
		// missing. `missing_for_order()` has always counted per order line and
		// treated assigned and shipped alike; nothing counted the other way,
		// so every assigned unit of an order shipped regardless of how many
		// the line was for. Measured by an adversarial review: a
		// quantity-one line whose replacement had already gone out, with the
		// original unit then assigned back to it, ended up with TWO units
		// recorded as shipped against one ordered — and the second was still
		// on the bench while its history said it was with the customer.
		//
		// Refused, not trimmed, for the reason the completeness rule gives:
		// the plugin does not choose which of two units went in the box. A
		// line with more units pointed at it than it can hold is something
		// somebody has to look at.
		$over = self::overshipped_products( $order, $to_ship );

		if ( $over ) {

			$first = reset( $over );

			return new WP_Error(
				'dahu_serial_numbers_line_overshipped',
				sprintf(
					/* translators: 1: product name, 2: number of units already shipped, 3: number this parcel would add, 4: ordered quantity. */
					_n(
						'“%1$s” already has %2$d unit recorded as shipped on this order, and this parcel would add %3$d more, against %4$d ordered. Nothing has been shipped. Record what became of the unit that is already out before sending another.',
						'“%1$s” already has %2$d units recorded as shipped on this order, and this parcel would add %3$d more, against %4$d ordered. Nothing has been shipped. Record what became of the units that are already out before sending another.',
						$first['shipped'],
						'dahu-serial-numbers'
					),
					$first['product_name'],
					$first['shipped'],
					$first['going'],
					$first['ordered']
				),
				array(
					'status'   => 409,
					'products' => $over,
					// Kept under the old key as well: the first version of
					// this guard counted per order line and published that
					// name in its error data.
					'lines'    => $over,
				)
			);
		}

		// The box is measured before the label is asked for, not after: a
		// carrier integration listening on the filter below needs the weight
		// to get a price and a label at all.
		$parcel = array();

		foreach ( array( 'weight', 'length', 'width', 'height' ) as $measure ) {
			if ( isset( $args[ $measure ] ) ) {
				$parcel[ $measure ] = $args[ $measure ];
			}
		}

		$consignment_id = Dahu_Serial_Numbers_Consignments::open(
			array_merge(
				array(
					'direction'  => Dahu_Serial_Numbers_Consignments::OUT,
					'order_id'   => $order->get_id(),
					'carrier'    => $carrier,
					'tracking'   => $tracking,
					'station_id' => $station_id,
					'source'     => $source,
				),
				$parcel
			)
		);

		if ( is_wp_error( $consignment_id ) ) {
			return $consignment_id;
		}

		// The units go in before the label is asked for, so the integration
		// below can read them. A refusal here — a unit already in another
		// open parcel — means nothing has moved yet, so the consignment is
		// cancelled rather than left open to confuse the next person.
		$unregistered = array();

		if ( $to_ship ) {
			$added = Dahu_Serial_Numbers_Consignments::add( $consignment_id, $to_ship );

			if ( is_wp_error( $added ) ) {
				Dahu_Serial_Numbers_Consignments::cancel(
					$consignment_id,
					$added->get_error_message()
				);

				return $added;
			}

			// What ships is what the register actually holds, not what was
			// asked for. The two used to be assumed identical, and the gap
			// between them is the one failure this whole class exists to
			// prevent: a unit moved to `shipped` while no parcel contains it,
			// which is a unit nobody can ever place again.
			$unregistered = $added['failed'];
			$to_ship      = $added['added'];

			if ( ! $to_ship ) {
				// Nothing could be written down. Nothing has moved yet
				// either — the label has not been asked for — so this is the
				// one moment where refusing costs nothing and is honest.
				Dahu_Serial_Numbers_Consignments::cancel(
					$consignment_id,
					__( 'No unit could be recorded in this consignment.', 'dahu-serial-numbers' )
				);

				return new WP_Error(
					'dahu_serial_numbers_consignment_not_filled',
					__( 'Not one unit could be recorded in the parcel, so nothing was shipped. Nothing has moved; try again.', 'dahu-serial-numbers' ),
					array(
						'status'       => 500,
						'unregistered' => $unregistered,
					)
				);
			}
		}

		$label_error = '';

		try {
			/**
			 * Fires before a parcel is closed.
			 *
			 * Where a carrier integration creates its label. The consignment
			 * is already open and already holds its units, so
			 * `Dahu_Serial_Numbers_Consignments::get( $consignment_id )`
			 * answers "which units is this label for". Returning a tracking
			 * number is how it gets onto the order without this class
			 * knowing anything about that carrier.
			 *
			 * @since 1.1.0 The consignment id replaced nothing — it is a new
			 *               second parameter, and the order moved to third.
			 *               A listener written against 1.0.0 must be updated.
			 *
			 * @param string   $tracking       Tracking number so far, possibly empty.
			 * @param int      $consignment_id The open consignment.
			 * @param WC_Order $order          Order being shipped.
			 * @param string   $carrier        Carrier name.
			 * @param int      $station_id     Workshop post doing it.
			 */
			$tracking = (string) apply_filters( 'dahu_serial_numbers_before_shipment', $tracking, $consignment_id, $order, $carrier, $station_id );
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
		 * @param bool     $block          Whether to refuse.
		 * @param WC_Order $order          Order being shipped.
		 * @param string   $error          The carrier's message.
		 * @param int      $consignment_id The consignment about to be cancelled.
		 */
		if ( '' !== $label_error && apply_filters( 'dahu_serial_numbers_block_on_label_failure', false, $order, $label_error, $consignment_id ) ) {
			// Cancelled, not deleted: the reference may already have been
			// quoted to the carrier's API before it failed, and it has to
			// keep resolving to an explanation.
			Dahu_Serial_Numbers_Consignments::cancel( $consignment_id, $label_error );

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
				array(
					'status'         => 502,
					'consignment_id' => $consignment_id,
				)
			);
		}

		$shipped = array();
		$stuck   = array();

		foreach ( $to_ship as $serial ) {
			$moved = Dahu_Serial_Numbers_Manager::transition(
				$serial,
				Dahu_Serial_Numbers_Statuses::SHIPPED,
				array(
					'event'          => 'shipment',
					'station_id'     => $station_id ? $station_id : null,
					'carrier'        => '' !== $carrier ? $carrier : null,
					'tracking'       => '' !== $tracking ? $tracking : null,
					'consignment_id' => $consignment_id,
					'label_error'    => '' !== $label_error ? $label_error : null,
				)
			);

			// One unit that refuses to move does not stop the parcel. It is
			// recorded and reported, because the alternative is a half-shipped
			// order whose screen says it failed.
			//
			// It also comes back out of the consignment. The consignment is a
			// statement about what is in the box, and a unit the database
			// refused to move is a unit whose whereabouts nobody can vouch
			// for — leaving it listed would be the one lie this record is not
			// allowed to tell.
			if ( is_wp_error( $moved ) ) {
				Dahu_Serial_Numbers_Log::record(
					$serial,
					'shipment_failed',
					array(
						'order_id' => $order->get_id(),
						'context'  => array(
							'reason'         => $moved->get_error_message(),
							'consignment_id' => $consignment_id,
						),
					)
				);

				Dahu_Serial_Numbers_Consignments::remove( $consignment_id, array( $serial ) );

				$stuck[] = $serial;

				continue;
			}

			$shipped[] = $serial;
		}

		$closed = Dahu_Serial_Numbers_Consignments::close(
			$consignment_id,
			array(
				'carrier'     => $carrier,
				'tracking'    => $tracking,
				'note'        => self::consignment_note( $stuck, $unregistered, $label_error ),
				'label_error' => $label_error,
			)
		);

		// A failed close used to be ignored, and what it left was a parcel
		// that had gone but was still marked open — which also kept every
		// unit in it from ever joining another consignment, including the
		// inbound one that records its return. Silent, permanent, and only
		// undoable with SQL.
		//
		// The units have genuinely shipped at this point, so this is not
		// turned into a refusal. It is said out loud, on the order, naming
		// the consignment, so that the open parcel can be found and
		// cancelled from the consignments screen.
		$still_open = is_wp_error( $closed );

		if ( $still_open ) {
			$open_row  = Dahu_Serial_Numbers_Consignments::get( $consignment_id );
			$reference = $open_row ? (string) $open_row['reference'] : '';

			$order->add_order_note(
				sprintf(
					/* translators: 1: consignment reference, 2: the error. */
					__( 'The units shipped, but consignment %1$s could not be closed (%2$s). It is still open, which also stops those units joining another consignment — cancel it on the Consignments screen, or close it by hand.', 'dahu-serial-numbers' ),
					'' !== $reference ? $reference : (string) $consignment_id,
					$closed->get_error_message()
				)
			);
		} else {
			$reference = isset( $closed['reference'] ) ? (string) $closed['reference'] : '';
		}

		// The three order meta are written together, from the consignment as
		// it was actually closed, or not at all.
		//
		// They used to be written one at a time, each only when it had a
		// value, and that produced a mirror describing two different
		// parcels: a second shipment with no tracking number overwrote the
		// reference and left the first parcel's carrier and tracking next to
		// it. Anything reading the three as a set — a theme, a shipping
		// e-mail, an export — then showed a tracking number that belonged to
		// another box.
		//
		// So the set is consistent and it describes the most recent parcel,
		// including when that parcel has no tracking number yet, in which
		// case the mirror is empty rather than stale. The history is in the
		// consignments, which is where anything that must be right about
		// which number belongs to which units reads it.
		$mirror_carrier  = ! $still_open && isset( $closed['carrier'] ) ? (string) $closed['carrier'] : $carrier;
		$mirror_tracking = ! $still_open && isset( $closed['tracking'] ) ? (string) $closed['tracking'] : $tracking;

		if ( '' !== $reference ) {
			$order->update_meta_data( self::META_CONSIGNMENT, $reference );
			$order->update_meta_data( self::META_CARRIER, $mirror_carrier );
			$order->update_meta_data( self::META_TRACKING, $mirror_tracking );
		}

		// A note on the order, because that is where support looks — and
		// because an order note is the one record of this that survives the
		// plugin being removed.
		$order->add_order_note( self::note( $shipped, $stuck, $mirror_carrier, $mirror_tracking, $label_error, $reference, $unregistered, $consignment_id ) );
		$order->save();

		/**
		 * Filters the status an order moves to once its parcel is closed.
		 *
		 * The default is 'completed' for an order that has nothing left to
		 * send, and an empty string — meaning leave the status alone — for
		 * one that has. A partial shipment that completed the order would
		 * tell the customer their second parcel has already arrived.
		 *
		 * @param string   $status         Status key, without the wc- prefix.
		 * @param WC_Order $order          Order.
		 * @param int      $consignment_id The parcel just closed.
		 */
		$status = (string) apply_filters( 'dahu_serial_numbers_shipment_status', self::default_status( $order ), $order, $consignment_id );

		if ( '' !== $status && $order->get_status() !== $status ) {
			$order->update_status( $status, __( 'Packed and shipped.', 'dahu-serial-numbers' ) );
		}

		/**
		 * Fires once a parcel has been closed.
		 *
		 * @param WC_Order $order          Order.
		 * @param string[] $shipped        Serials that left with it.
		 * @param string   $carrier        Carrier name.
		 * @param string   $tracking       Tracking number.
		 * @param int      $consignment_id The consignment holding them.
		 */
		do_action( 'dahu_serial_numbers_shipped', $order, $shipped, $carrier, $tracking, $consignment_id );

		return array(
			'shipped'        => $shipped,
			'status'         => $order->get_status(),
			'label_error'    => $label_error,
			'consignment_id' => $consignment_id,
			'reference'      => $reference,
			'unregistered'   => $unregistered,
			'still_open'     => $still_open,
		);
	}

	/**
	 * Works out which of the named units may ship.
	 *
	 * Strict on purpose. A serial that is not assigned to this order is
	 * either a typo or a unit from somebody else's parcel, and both are
	 * record errors that get much more expensive once the box is taped.
	 *
	 * The comparison is case-insensitive because the list comes from a
	 * barcode scanner on one bench and a keyboard on another, and a shop
	 * should not have to care which.
	 *
	 * @param array    $named    What the caller asked for.
	 * @param string[] $assigned Serials assigned to the order.
	 * @return string[]|WP_Error
	 */
	private static function resolve_subset( array $named, array $assigned ) {

		$map = array();

		foreach ( $assigned as $serial ) {
			$map[ strtoupper( $serial ) ] = $serial;
		}

		$to_ship = array();
		$rejects = array();

		foreach ( $named as $serial ) {
			$serial = sanitize_text_field( (string) $serial );

			if ( '' === $serial ) {
				continue;
			}

			$key = strtoupper( $serial );

			if ( ! isset( $map[ $key ] ) ) {
				$rejects[] = $serial;

				continue;
			}

			if ( ! in_array( $map[ $key ], $to_ship, true ) ) {
				$to_ship[] = $map[ $key ];
			}
		}

		if ( $rejects ) {
			return new WP_Error(
				'dahu_serial_numbers_not_assigned',
				sprintf(
					/* translators: %s: comma-separated serial numbers. */
					__( 'Not assigned to this order, so it cannot ship with it: %s', 'dahu-serial-numbers' ),
					implode( ', ', $rejects )
				),
				array(
					'status'   => 409,
					'rejected' => $rejects,
				)
			);
		}

		if ( ! $to_ship ) {
			return new WP_Error(
				'dahu_serial_numbers_nothing_to_ship',
				__( 'No unit was named, so there is nothing to put in the parcel.', 'dahu-serial-numbers' ),
				array( 'status' => 400 )
			);
		}

		return $to_ship;
	}

	/**
	 * The status an order should move to, when nobody overrides it.
	 *
	 * 'completed' when the order has nothing left to send: no unit without a
	 * serial, and no serial still waiting in `assigned`. An empty string
	 * otherwise, which leaves the status where it is.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private static function default_status( WC_Order $order ) {

		if ( Dahu_Serial_Numbers_Manager::missing_for_order( $order->get_id() ) ) {
			return '';
		}

		foreach ( Dahu_Serial_Numbers_Manager::get_by_order( $order->get_id() ) as $row ) {
			if ( Dahu_Serial_Numbers_Statuses::ASSIGNED === $row['status'] ) {
				return '';
			}
		}

		return 'completed';
	}

	/**
	 * What gets written on the consignment itself.
	 *
	 * @param string[] $stuck        Units that would not move.
	 * @param string[] $unregistered Units the register would not accept.
	 * @param string   $label_error  The carrier's message, when it failed.
	 * @return string
	 */
	/**
	 * Products that would end up with more units shipped than ordered.
	 *
	 * Counted per PRODUCT across the whole order, not per order line, and
	 * that is the correction rather than a simplification. The first version
	 * of this guard counted per line and skipped any unit whose
	 * `order_item_id` was zero or no longer resolved — which an adversarial
	 * review measured as the commonest case rather than the edge case:
	 * `Manager::resolve_order_item()` returns zero BY DESIGN as soon as an
	 * order has two lines of the same product, `attach_order()` defaults the
	 * line to zero, and an order line deleted and re-added from the order
	 * screen gets a new id while the units keep pointing at the old one. The
	 * guard was therefore switched off on the paths most likely to need it.
	 *
	 * Counting per product needs no line at all: what is ordered is the sum
	 * of the quantities of every line of that product, and what is out is the
	 * number of units of that product already recorded as shipped on this
	 * order. A unit whose own `product_id` is not recorded cannot be
	 * attributed to anything and is skipped — the one hole left, and it is
	 * named here rather than left to be found.
	 *
	 * @param WC_Order $order   The order.
	 * @param string[] $serials The serials about to ship.
	 * @return array<int,array<string,mixed>> product id => {product_name, ordered, shipped, going}
	 */
	private static function overshipped_products( WC_Order $order, array $serials ) {

		$ordered = array();
		$names   = array();

		foreach ( $order->get_items() as $item ) {

			// The same resolution `missing_for_order()` uses: a variation is
			// the product that was sold, not its parent.
			$product_id = $item->get_variation_id() ? (int) $item->get_variation_id() : (int) $item->get_product_id();

			if ( ! $product_id ) {
				continue;
			}

			$ordered[ $product_id ] = isset( $ordered[ $product_id ] ) ? $ordered[ $product_id ] + (int) $item->get_quantity() : (int) $item->get_quantity();

			if ( ! isset( $names[ $product_id ] ) ) {
				$names[ $product_id ] = (string) $item->get_name();
			}
		}

		$shipped = array();
		$going   = array();

		foreach ( Dahu_Serial_Numbers_Manager::get_by_order( $order->get_id() ) as $row ) {

			$product_id = (int) $row['product_id'];

			if ( ! $product_id ) {
				continue;
			}

			if ( Dahu_Serial_Numbers_Statuses::SHIPPED === $row['status'] ) {
				$shipped[ $product_id ] = isset( $shipped[ $product_id ] ) ? $shipped[ $product_id ] + 1 : 1;
			}

			if ( in_array( (string) $row['serial'], $serials, true ) ) {
				$going[ $product_id ] = isset( $going[ $product_id ] ) ? $going[ $product_id ] + 1 : 1;
			}
		}

		$over = array();

		foreach ( $going as $product_id => $count ) {

			// A unit of a product the order does not contain is somebody
			// else's problem to explain, not this guard's to refuse: it has
			// no ordered quantity to be measured against.
			if ( ! isset( $ordered[ $product_id ] ) ) {
				continue;
			}

			$out = isset( $shipped[ $product_id ] ) ? (int) $shipped[ $product_id ] : 0;

			// No clamping. The first version used `max( 0, quantity - shipped )`,
			// which turned a line whose quantity had been reduced after
			// shipping into a flat refusal with a message that contradicted
			// itself — "ordered 1 and 2 already shipped". The three numbers
			// are reported as they are, and the shop can see which one is
			// the surprising one.
			if ( $out + $count > (int) $ordered[ $product_id ] ) {
				$over[ $product_id ] = array(
					'product_name' => isset( $names[ $product_id ] ) ? $names[ $product_id ] : (string) $product_id,
					'ordered'      => (int) $ordered[ $product_id ],
					'shipped'      => $out,
					'going'        => (int) $count,
				);
			}
		}

		return $over;
	}

	private static function consignment_note( array $stuck, array $unregistered, $label_error ) {

		$lines = array();

		if ( $unregistered ) {
			$lines[] = sprintf(
				/* translators: %s: comma-separated serial numbers. */
				__( 'Refused by the register and left out of this parcel: %s', 'dahu-serial-numbers' ),
				implode( ', ', $unregistered )
			);
		}

		if ( $stuck ) {
			$lines[] = sprintf(
				/* translators: %s: comma-separated serial numbers. */
				__( 'Taken back out, because the status change was refused: %s', 'dahu-serial-numbers' ),
				implode( ', ', $stuck )
			);
		}

		if ( '' !== $label_error ) {
			$lines[] = sprintf(
				/* translators: %s: the carrier's error message. */
				__( 'The carrier could not create the label: %s', 'dahu-serial-numbers' ),
				$label_error
			);
		}

		return implode( "\n", $lines );
	}

	/**
	 * The order note.
	 *
	 * @param string[] $shipped     Serials that left.
	 * @param string[] $stuck       Serials the database refused to move.
	 * @param string   $carrier     Carrier.
	 * @param string   $tracking    Tracking number.
	 * @param string   $label_error The carrier's message, when it failed.
	 * @param string   $reference    Consignment reference.
	 * @param string[] $unregistered Serials the register would not accept.
	 * @param int      $consignment_id The consignment, for the filter on the failure line.
	 * @return string
	 */
	private static function note( array $shipped, array $stuck, $carrier, $tracking, $label_error = '', $reference = '', array $unregistered = array(), $consignment_id = 0 ) {

		$lines = array();

		// Three cases, not two, and the third used to be told as the second.
		// An order whose every unit refused to move got "No serial number was
		// on this order", which is a sentence about a shop that does not
		// serialise — said on an order that does, about units that exist and
		// are now unaccounted for. The parcel is still recorded as having
		// gone, because it has: the box is taped and on a van, and refusing
		// here would leave nothing on file at all. What changes is that the
		// note says what actually happened.
		if ( $shipped ) {
			$lines[] = sprintf(
				/* translators: %s: comma-separated serial numbers. */
				__( 'Shipped with serial numbers: %s', 'dahu-serial-numbers' ),
				implode( ', ', $shipped )
			);
		} elseif ( $stuck ) {
			$lines[] = sprintf(
				/* translators: %s: comma-separated serial numbers. */
				__( 'Shipped, but not one unit could be recorded as having left: %s. Each unit’s own history says why it was refused, and the consignment below is empty as a result.', 'dahu-serial-numbers' ),
				implode( ', ', $stuck )
			);
		} else {
			$lines[] = __( 'Shipped. No serial number was on this order.', 'dahu-serial-numbers' );
		}

		// The reference goes on the note because this is where a second
		// parcel stops being ambiguous: two notes on one order, each naming
		// its own consignment and its own units.
		if ( '' !== $reference ) {
			$lines[] = sprintf(
				/* translators: %s: consignment reference. */
				__( 'Consignment: %s', 'dahu-serial-numbers' ),
				$reference
			);
		}

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
			$text = sprintf(
				/* translators: %s: the carrier's error message. */
				__( 'The carrier could not create the label: %s — the parcel was recorded as shipped anyway, so the units are on file. Add the tracking number by hand once you have it.', 'dahu-serial-numbers' ),
				$label_error
			);

			/**
			 * Filters the order note line written when the carrier failed.
			 *
			 * "Could not create the label" is not always true. A call that is
			 * not idempotent and ran out of time may well have created one,
			 * and billed it; an integration that knows it timed out can say
			 * so here instead of asserting a failure it cannot vouch for.
			 * Nothing listening, nothing changes.
			 *
			 * @param string $text           The line, as this plugin words it.
			 * @param string $label_error    The carrier's message.
			 * @param int    $consignment_id The consignment the label was for.
			 */
			$lines[] = (string) apply_filters( 'dahu_serial_numbers_label_failure_note', $text, $label_error, (int) $consignment_id );
		}

		// Last, and spelled out, because it is the one line on this note that
		// somebody has to act on: these units were in the box and the
		// register would not take them, so nothing says where they are.
		if ( $unregistered ) {
			$lines[] = sprintf(
				/* translators: %s: comma-separated serial numbers. */
				__( 'Could not be recorded in the consignment and did NOT ship: %s. They are still assigned to this order — put them in the next parcel.', 'dahu-serial-numbers' ),
				implode( ', ', $unregistered )
			);
		}

		return implode( "\n", $lines );
	}

	/**
	 * Adds a carrier and a tracking number to a parcel that has already gone.
	 *
	 * The ledger write is `Dahu_Serial_Numbers_Consignments::amend_tracking()`,
	 * which refuses everything but those two fields. This is the entry point
	 * that also keeps the order meta mirror honest; the mirror follows the
	 * action that call fires, so either route ends up in the same place.
	 *
	 * @param int   $consignment_id Consignment id.
	 * @param array $args {
	 *     @type string $carrier  Optional.
	 *     @type string $tracking Optional.
	 * }
	 * @return array|WP_Error The consignment as it now stands.
	 */
	public static function amend_tracking( $consignment_id, array $args ) {

		return Dahu_Serial_Numbers_Consignments::amend_tracking( $consignment_id, $args );
	}

	/**
	 * Keeps the order meta mirror in step with an amended consignment.
	 *
	 * The mirror describes ONE parcel, the most recent outbound one that has
	 * gone, and the three values are written together or not at all — see the
	 * long comment in `ship()` for the defect that rule came from: a mirror
	 * assembled from two parcels shows a tracking number that belongs to
	 * another box. A correction to an older parcel must therefore leave the
	 * mirror alone, and a correction to the newest one must rewrite all three
	 * from the consignment, never the one value that changed.
	 *
	 * "Most recent" is the highest id among the order's closed outbound
	 * consignments. Open ones have not been mirrored yet and cancelled ones
	 * never were.
	 *
	 * @param int   $consignment_id Consignment id.
	 * @param array $amended        The consignment as it now stands.
	 * @return void
	 */
	public static function sync_mirror( $consignment_id, $amended ) {

		if ( ! is_array( $amended ) || Dahu_Serial_Numbers_Consignments::OUT !== $amended['direction'] || empty( $amended['order_id'] ) ) {
			return;
		}

		$latest = 0;

		foreach ( Dahu_Serial_Numbers_Consignments::for_order( (int) $amended['order_id'], Dahu_Serial_Numbers_Consignments::OUT ) as $parcel ) {
			if ( Dahu_Serial_Numbers_Consignments::CLOSED === $parcel['status'] && (int) $parcel['id'] > $latest ) {
				$latest = (int) $parcel['id'];
			}
		}

		if ( (int) $consignment_id !== $latest ) {
			return;
		}

		$order = wc_get_order( (int) $amended['order_id'] );

		if ( ! $order ) {
			return;
		}

		$order->update_meta_data( self::META_CONSIGNMENT, (string) $amended['reference'] );
		$order->update_meta_data( self::META_CARRIER, (string) $amended['carrier'] );
		$order->update_meta_data( self::META_TRACKING, (string) $amended['tracking'] );
		$order->save();
	}

	/**
	 * Every parcel on an order, newest first.
	 *
	 * @param WC_Order $order     Order.
	 * @param string   $direction Optional. 'out' or 'in'.
	 * @return array[]
	 */
	public static function consignments( WC_Order $order, $direction = '' ) {

		return Dahu_Serial_Numbers_Consignments::for_order( $order->get_id(), $direction );
	}

	/**
	 * The carrier of the most recent parcel on an order.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function carrier( WC_Order $order ) {

		return (string) $order->get_meta( self::META_CARRIER );
	}

	/**
	 * The tracking number of the most recent parcel on an order.
	 *
	 * An order with three parcels has three tracking numbers, and this
	 * returns the last one. That is a convenience, not the record: the record
	 * is `consignments()`, and anything that has to be right about which
	 * number belongs to which units reads that instead.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function tracking( WC_Order $order ) {

		return (string) $order->get_meta( self::META_TRACKING );
	}

	/**
	 * A link to follow the most recent parcel, when the shop can build one.
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

		return self::url_for( $tracking, self::carrier( $order ), $order, 0 );
	}

	/**
	 * A link to follow one particular parcel.
	 *
	 * What a multi-parcel order needs, and what `tracking_url()` cannot give
	 * it.
	 *
	 * @param int $consignment_id Consignment id.
	 * @return string
	 */
	public static function consignment_tracking_url( $consignment_id ) {

		$consignment = Dahu_Serial_Numbers_Consignments::get( (int) $consignment_id );

		return $consignment ? self::tracking_url_for_row( $consignment ) : '';
	}

	/**
	 * The same link, for a caller that already holds the row.
	 *
	 * Which is every list screen, and the reason this exists: going back
	 * through `consignment_tracking_url()` re-read the row and its serials
	 * for each line of a page, and then loaded the order on top. A list of
	 * fifty parcels did over a hundred queries to print a column that is
	 * empty on a shop with no tracking-URL filter installed.
	 *
	 * The order is only fetched when a filter is actually listening, for the
	 * same reason.
	 *
	 * @param array $row Consignment row.
	 * @return string
	 */
	public static function tracking_url_for_row( array $row ) {

		$tracking = isset( $row['tracking'] ) ? (string) $row['tracking'] : '';

		if ( '' === $tracking ) {
			return '';
		}

		if ( ! has_filter( 'dahu_serial_numbers_tracking_url' ) ) {
			return '';
		}

		$order = ! empty( $row['order_id'] ) ? wc_get_order( (int) $row['order_id'] ) : null;

		return self::url_for(
			$tracking,
			isset( $row['carrier'] ) ? (string) $row['carrier'] : '',
			$order instanceof WC_Order ? $order : null,
			isset( $row['id'] ) ? (int) $row['id'] : 0
		);
	}

	/**
	 * The one place the tracking URL filter is applied.
	 *
	 * @param string        $tracking       Tracking number.
	 * @param string        $carrier        Carrier name.
	 * @param WC_Order|null $order          Order, when there is one.
	 * @param int           $consignment_id Consignment, when known.
	 * @return string
	 */
	private static function url_for( $tracking, $carrier, $order, $consignment_id ) {

		/**
		 * Filters the tracking URL.
		 *
		 * @param string        $url            Empty by default.
		 * @param string        $tracking       Tracking number.
		 * @param string        $carrier        Carrier name.
		 * @param WC_Order|null $order          Order, when there is one.
		 * @param int           $consignment_id Consignment, or 0 when the
		 *                                      caller only had an order.
		 */
		return esc_url_raw( (string) apply_filters( 'dahu_serial_numbers_tracking_url', '', $tracking, $carrier, $order, $consignment_id ) );
	}
}
