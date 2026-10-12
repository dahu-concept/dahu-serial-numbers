<?php
/**
 * Consignments: which units went out together, and under what reference.
 *
 * ## Why this exists at all
 *
 * Before this class, the fact that a set of units left together was implicit.
 * The carrier and the tracking number were order meta, which allowed exactly
 * one of each per order; the link between a tracking number and the units
 * under it had to be reconstructed from `order_id`, a status, and a line of
 * free text in the history. So the one question support is actually asked —
 * "which units were in the parcel with this tracking number?" — had no answer
 * the database could give.
 *
 * A consignment is the answer: a set of units that left, or came back,
 * together, under one reference.
 *
 * ## What it deliberately is not
 *
 * It is not shipping. There is no carrier API here, no label, no parcel
 * format, no relay point, no manifest. The carrier is a name the shop types,
 * the tracking number is a string somebody pastes in, and this class never
 * fetches anything from anywhere. That is not a limitation waiting to be
 * lifted — it is the line that keeps this a traceability record rather than
 * a logistics product, and the record is the part that must survive any
 * carrier integration being added, changed or removed.
 *
 * What it gives a shop with no integration at all:
 *
 *   - partial shipments. Two of five units today under one tracking number,
 *     three next week under another. Order meta could not express that.
 *   - a tracking number that resolves to a list of units, both ways round.
 *   - inbound consignments, so a return is recorded the same way a departure
 *     is, with its own reference and its own units — which is what makes a
 *     unit's round trip readable end to end.
 *
 * ## It is a ledger, not an actor
 *
 * Nothing here moves a unit between statuses. Opening a consignment and
 * adding a serial to it records an intention; `Dahu_Serial_Numbers_Shipment`
 * is what actually transitions the units, and it uses this class to write
 * down what it did. Keeping the two apart is what lets a consignment mean the
 * same thing whether a parcel was closed by the packing screen, by a carrier
 * integration, or by somebody typing it in afterwards.
 *
 * The consequence is that the API below can be used to write a record that
 * does not match the serials' statuses. That is deliberate, and it is the
 * same bargain the history table makes: a record of what somebody said
 * happened is more useful than a record that refuses anything surprising.
 *
 * ## A cancelled consignment keeps its contents
 *
 * `cancel()` does not empty the consignment, so a unit that was scanned into
 * a parcel that never went still shows that parcel in `for_serial()`. That is
 * on purpose: somebody put that unit in that box, and when the box was
 * abandoned because the carrier refused a label, the fact that it had been
 * packed is part of the story — deleting the rows would leave a reference, a
 * reason, and no indication of what the parcel was going to contain.
 *
 * The price is that every reader has to look at `status`. A list of a unit's
 * consignments that ignores it will show departures that never happened, so
 * anything counting movements filters on `closed` first. The screens and the
 * REST payloads in this plugin carry the status for exactly that reason.
 *
 * ## One guard is not opposable under concurrency, and that is written down
 *
 * `add()` refuses a serial that is already in another open consignment, and
 * that refusal is a read followed by an insert with no lock between them. Two
 * benches closing two parcels in the same instant can therefore both be told
 * the unit is free and both record it. Measured on the bench, not supposed.
 *
 * It is not left unguarded: the status matrix refuses `shipped -> shipped`,
 * so the second of the two movements fails and that unit comes back out of
 * its consignment. The window is between the two writes, and what it costs
 * is a pivot row that is removed again a moment later.
 *
 * The proper fix is a uniqueness rule the database enforces — a separate
 * table of units currently in an open consignment, with a unique key on the
 * serial. That is additive, so it can arrive in a later release without
 * breaking the contract below, and it is not in this one because a shop with
 * two benches on the same order in the same second is rare enough to be
 * worth measuring before it is engineered for.
 *
 * ## This is a published contract
 *
 * Other plugins read these two tables and call these methods. `API_VERSION`
 * is what they assert against, and the rule that goes with it is: changes to
 * the two tables are additive only. A new nullable column is fine. Renaming,
 * narrowing or removing one is not, whatever a future release would gain by
 * it.
 *
 * @package DahuSerialNumbers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Consignments.
 */
class Dahu_Serial_Numbers_Consignments {

	/**
	 * The version of the contract described in this file's header.
	 *
	 * Bumped only by a change that could break a reader: a column that stops
	 * being written, a method whose meaning changes. Additive changes leave
	 * it alone, which is the point of it.
	 */
	const API_VERSION = '1';

	/**
	 * Leaving the shop.
	 */
	const OUT = 'out';

	/**
	 * Coming back.
	 */
	const IN = 'in';

	/**
	 * Still being filled.
	 */
	const OPEN = 'open';

	/**
	 * Gone, and no longer modifiable.
	 */
	const CLOSED = 'closed';

	/**
	 * Opened and abandoned. Kept rather than deleted — see cancel().
	 */
	const CANCELLED = 'cancelled';

	/**
	 * Reference prefix per direction.
	 *
	 * Two letters rather than one shared series, because a shop reading
	 * "R-2026-00013" in a support ticket knows it is a return before opening
	 * anything.
	 *
	 * @return array<string,string>
	 */
	public static function prefixes() {

		return array(
			self::OUT => 'C',
			self::IN  => 'R',
		);
	}

	/**
	 * Every status a consignment can hold.
	 *
	 * @return string[]
	 */
	public static function statuses() {

		return array( self::OPEN, self::CLOSED, self::CANCELLED );
	}

	/**
	 * Human labels for the statuses.
	 *
	 * @return array<string,string>
	 */
	public static function status_labels() {

		return array(
			self::OPEN      => __( 'Open', 'dahu-serial-numbers' ),
			self::CLOSED    => __( 'Closed', 'dahu-serial-numbers' ),
			self::CANCELLED => __( 'Cancelled', 'dahu-serial-numbers' ),
		);
	}

	/**
	 * Human labels for the directions.
	 *
	 * @return array<string,string>
	 */
	public static function direction_labels() {

		return array(
			self::OUT => __( 'Outbound', 'dahu-serial-numbers' ),
			self::IN  => __( 'Inbound', 'dahu-serial-numbers' ),
		);
	}

	/**
	 * Opens a consignment.
	 *
	 * The reference is assigned after the insert, from the row's own id, and
	 * the column is left NULL until then. That is not laziness about
	 * sequences: a counter in an option races two packing benches against
	 * each other and hands out the same reference twice, and MySQL allows
	 * several NULLs in a unique index, so the window between the insert and
	 * the update cannot collide with anything.
	 *
	 * @param array $args {
	 *     @type string $direction  'out' or 'in'. Default 'out'.
	 *     @type int    $order_id   Optional. Order this belongs to.
	 *     @type string $carrier    Optional. Carrier name, as the shop calls it.
	 *     @type string $tracking   Optional. Tracking number, when known this early.
	 *     @type string $source     Optional. Slug of whatever created it. Default 'manual'.
	 *     @type int    $station_id Optional. Workshop post doing it.
	 *     @type string $note       Optional. Free text.
	 *     @type float  $weight     Optional. Weight of the parcel itself.
	 *     @type float  $length     Optional. Length of the parcel.
	 *     @type float  $width      Optional. Width of the parcel.
	 *     @type float  $height     Optional. Height of the parcel.
	 * }
	 * @return int|WP_Error Consignment id.
	 */
	public static function open( array $args = array() ) {

		global $wpdb;

		$direction = isset( $args['direction'] ) ? (string) $args['direction'] : self::OUT;

		if ( self::OUT !== $direction && self::IN !== $direction ) {
			return new WP_Error(
				'dahu_serial_numbers_bad_direction',
				__( 'A consignment is either outbound or inbound.', 'dahu-serial-numbers' ),
				array( 'status' => 400 )
			);
		}

		$now = gmdate( 'Y-m-d H:i:s' );

		$row = array(
			'reference'  => null,
			'direction'  => $direction,
			'status'     => self::OPEN,
			'order_id'   => isset( $args['order_id'] ) && $args['order_id'] ? (int) $args['order_id'] : null,
			'carrier'    => isset( $args['carrier'] ) ? sanitize_text_field( (string) $args['carrier'] ) : '',
			'tracking'   => isset( $args['tracking'] ) ? sanitize_text_field( (string) $args['tracking'] ) : '',
			'source'     => isset( $args['source'] ) ? sanitize_key( (string) $args['source'] ) : 'manual',
			'station_id' => isset( $args['station_id'] ) && $args['station_id'] ? (int) $args['station_id'] : null,
			'note'       => isset( $args['note'] ) ? sanitize_textarea_field( (string) $args['note'] ) : null,
			'created_at' => $now,
			'closed_at'  => null,
		);

		$row = array_merge( $row, self::parcel_columns( $args ) );

		$inserted = $wpdb->insert( Dahu_Serial_Numbers_Install::consignments_table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.

		if ( ! $inserted ) {
			return new WP_Error(
				'dahu_serial_numbers_consignment_not_opened',
				__( 'The consignment could not be opened.', 'dahu-serial-numbers' ),
				array( 'status' => 500 )
			);
		}

		$id = (int) $wpdb->insert_id;

		// The reference is a second statement, so it is a second thing that
		// can fail. It used to be assumed to work, and what that left behind
		// was the worst row in the table: open forever, listed on the screen
		// with no name, unreachable by `get()` or by the REST route because
		// both look it up by reference, and invisible to the unique index
		// because several NULLs are allowed in one.
		//
		// A consignment with no reference is of no use to anybody, so the
		// row goes rather than staying as a puzzle.
		$named = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
			Dahu_Serial_Numbers_Install::consignments_table(),
			array( 'reference' => self::build_reference( $direction, $id, $now ) ),
			array( 'id' => $id )
		);

		if ( ! $named ) {
			$wpdb->delete( Dahu_Serial_Numbers_Install::consignments_table(), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.

			return new WP_Error(
				'dahu_serial_numbers_consignment_not_named',
				__( 'The consignment could not be given a reference, so it was not opened.', 'dahu-serial-numbers' ),
				array( 'status' => 500 )
			);
		}

		/**
		 * Fires once a consignment has been opened.
		 *
		 * It holds no units yet. A carrier integration that needs the list
		 * wants `dahu_serial_numbers_before_shipment` instead, which runs
		 * after the units are in.
		 *
		 * @param int   $id   Consignment id.
		 * @param array $args The arguments it was opened with.
		 */
		do_action( 'dahu_serial_numbers_consignment_opened', $id, $args );

		return $id;
	}

	/**
	 * The reference for a row.
	 *
	 * Year from the creation date, zero-padded id. The id is global rather
	 * than per-year, so the numbers do not restart in January — a shop that
	 * quotes "C-2027-00001" to support when "C-2026-00001" also exists has
	 * two parcels one keystroke apart.
	 *
	 * @param string $direction  Direction.
	 * @param int    $id         Row id.
	 * @param string $created_at UTC datetime.
	 * @return string
	 */
	private static function build_reference( $direction, $id, $created_at ) {

		$prefixes = self::prefixes();
		$prefix   = isset( $prefixes[ $direction ] ) ? $prefixes[ $direction ] : 'C';

		return sprintf( '%s-%s-%05d', $prefix, substr( (string) $created_at, 0, 4 ), (int) $id );
	}

	/**
	 * Adds units to an open consignment.
	 *
	 * Refuses rather than filters. Three refusals, and each one is a record
	 * error somebody would otherwise discover much later:
	 *
	 *   - a closed or cancelled consignment. Its contents are a statement
	 *     about a parcel that is already on a van.
	 *   - a serial this installation has never heard of. Almost always a
	 *     typo, and a typo silently accepted becomes a unit that appears to
	 *     have shipped.
	 *   - a serial already in another open consignment. One physical unit
	 *     cannot be in two boxes, and the second bench to scan it is the one
	 *     that needs to be told.
	 *
	 * Already present in *this* consignment is not an error: scanning the
	 * same unit twice at the same bench is a normal thing to do, and it is
	 * reported as skipped.
	 *
	 * A unit whose insert fails is reported in `failed`, and the caller is
	 * expected to look. This used to be dropped on the floor: the return said
	 * nothing about it, and `ship()` went on to mark every unit it had asked
	 * for as shipped — so a key collision or a deadlock produced exactly the
	 * lie this register exists to prevent, a unit recorded as gone and
	 * present in no parcel.
	 *
	 * @param int      $id      Consignment id.
	 * @param string[] $serials Serial numbers.
	 * @return array|WP_Error {added: string[], skipped: string[], failed: string[]}
	 */
	public static function add( $id, array $serials ) {

		global $wpdb;

		$id  = (int) $id;
		$row = self::row( $id );

		if ( ! $row ) {
			return new WP_Error(
				'dahu_serial_numbers_consignment_unknown',
				__( 'No such consignment.', 'dahu-serial-numbers' ),
				array( 'status' => 404 )
			);
		}

		if ( self::OPEN !== $row['status'] ) {
			return new WP_Error(
				'dahu_serial_numbers_consignment_closed',
				__( 'This consignment is no longer open, so nothing can be added to it.', 'dahu-serial-numbers' ),
				array( 'status' => 409 )
			);
		}

		$present = array_map( 'strval', self::serials( $id ) );
		$known   = array();
		$added   = array();
		$skipped = array();
		$failed  = array();
		$unknown = array();
		$taken   = array();

		foreach ( $serials as $serial ) {
			$serial = sanitize_text_field( (string) $serial );

			if ( '' === $serial ) {
				continue;
			}

			if ( in_array( $serial, $present, true ) || in_array( $serial, $known, true ) ) {
				$skipped[] = $serial;

				continue;
			}

			if ( ! Dahu_Serial_Numbers_Manager::exists( $serial ) ) {
				$unknown[] = $serial;

				continue;
			}

			$elsewhere = self::open_consignment_for( $serial, $id );

			if ( $elsewhere ) {
				$taken[ $serial ] = $elsewhere;

				continue;
			}

			$known[] = $serial;
		}

		if ( $unknown ) {
			return new WP_Error(
				'dahu_serial_numbers_serial_unknown',
				sprintf(
					/* translators: %s: comma-separated serial numbers. */
					__( 'Not a serial number on this site: %s', 'dahu-serial-numbers' ),
					implode( ', ', $unknown )
				),
				array(
					'status'  => 404,
					'unknown' => $unknown,
				)
			);
		}

		if ( $taken ) {
			return new WP_Error(
				'dahu_serial_numbers_serial_in_open_consignment',
				sprintf(
					/* translators: 1: serial number, 2: consignment reference. */
					__( '%1$s is already in open consignment %2$s.', 'dahu-serial-numbers' ),
					(string) array_key_first( $taken ),
					(string) reset( $taken )
				),
				array(
					'status' => 409,
					'taken'  => $taken,
				)
			);
		}

		$now = gmdate( 'Y-m-d H:i:s' );

		foreach ( $known as $serial ) {
			$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
				Dahu_Serial_Numbers_Install::consignment_serials_table(),
				array(
					'consignment_id' => $id,
					'serial'         => $serial,
					'added_at'       => $now,
				)
			);

			if ( $ok ) {
				$added[] = $serial;
			} else {
				$failed[] = $serial;

				// In the history as well as in the return value. The caller
				// is told, but the caller is a screen somebody has already
				// walked away from, and this is the kind of failure whose
				// trace has to outlive the request.
				Dahu_Serial_Numbers_Log::record(
					$serial,
					'consignment_add_failed',
					array(
						'context' => array( 'consignment_id' => $id ),
					)
				);
			}
		}

		return array(
			'added'   => $added,
			'skipped' => $skipped,
			'failed'  => $failed,
		);
	}

	/**
	 * Takes a unit back out of an open consignment.
	 *
	 * The parcel is still being filled and somebody put the wrong box in it.
	 * Refused once closed, for the same reason add() is.
	 *
	 * @param int      $id      Consignment id.
	 * @param string[] $serials Serial numbers.
	 * @return int|WP_Error Rows removed.
	 */
	public static function remove( $id, array $serials ) {

		global $wpdb;

		$id  = (int) $id;
		$row = self::row( $id );

		if ( ! $row ) {
			return new WP_Error(
				'dahu_serial_numbers_consignment_unknown',
				__( 'No such consignment.', 'dahu-serial-numbers' ),
				array( 'status' => 404 )
			);
		}

		if ( self::OPEN !== $row['status'] ) {
			return new WP_Error(
				'dahu_serial_numbers_consignment_closed',
				__( 'This consignment is no longer open, so nothing can be taken out of it.', 'dahu-serial-numbers' ),
				array( 'status' => 409 )
			);
		}

		$removed = 0;

		foreach ( $serials as $serial ) {
			$serial = sanitize_text_field( (string) $serial );

			if ( '' === $serial ) {
				continue;
			}

			$removed += (int) $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
				Dahu_Serial_Numbers_Install::consignment_serials_table(),
				array(
					'consignment_id' => $id,
					'serial'         => $serial,
				)
			);
		}

		return $removed;
	}

	/**
	 * Closes a consignment.
	 *
	 * This is where the carrier and the tracking number arrive, because this
	 * is when they are known: a label is created at the moment the parcel is
	 * finished, not when the first unit went in.
	 *
	 * An empty consignment closes without complaint. An order with nothing
	 * serialised on it still ships, and the plugin already says so on the
	 * order note; refusing here would mean the packing screen worked for
	 * serialised orders and failed for the others.
	 *
	 * @param int   $id   Consignment id.
	 * @param array $args {
	 *     @type string $carrier  Optional. Overwrites if non-empty.
	 *     @type string $tracking Optional. Overwrites if non-empty.
	 *     @type string $note     Optional. Appended to whatever is there.
	 *     @type float  $weight   Optional. Overwrites if given.
	 *     @type float  $length   Optional. Overwrites if given.
	 *     @type float  $width    Optional. Overwrites if given.
	 *     @type float  $height   Optional. Overwrites if given.
	 * }
	 * @return array|WP_Error The closed row, with its serials.
	 */
	public static function close( $id, array $args = array() ) {

		global $wpdb;

		$id  = (int) $id;
		$row = self::row( $id );

		if ( ! $row ) {
			return new WP_Error(
				'dahu_serial_numbers_consignment_unknown',
				__( 'No such consignment.', 'dahu-serial-numbers' ),
				array( 'status' => 404 )
			);
		}

		if ( self::CLOSED === $row['status'] ) {
			return new WP_Error(
				'dahu_serial_numbers_consignment_already_closed',
				sprintf(
					/* translators: %s: consignment reference. */
					__( 'Consignment %s is already closed.', 'dahu-serial-numbers' ),
					(string) $row['reference']
				),
				array( 'status' => 409 )
			);
		}

		if ( self::CANCELLED === $row['status'] ) {
			return new WP_Error(
				'dahu_serial_numbers_consignment_cancelled',
				sprintf(
					/* translators: %s: consignment reference. */
					__( 'Consignment %s was cancelled and cannot be closed.', 'dahu-serial-numbers' ),
					(string) $row['reference']
				),
				array( 'status' => 409 )
			);
		}

		$update = array(
			'status'    => self::CLOSED,
			'closed_at' => gmdate( 'Y-m-d H:i:s' ),
		);

		// Non-empty only. Closing without a tracking number must not wipe one
		// that was already known — a shop that pastes the number in while
		// packing and then closes the parcel would lose it.
		if ( isset( $args['carrier'] ) && '' !== (string) $args['carrier'] ) {
			$update['carrier'] = sanitize_text_field( (string) $args['carrier'] );
		}

		if ( isset( $args['tracking'] ) && '' !== (string) $args['tracking'] ) {
			$update['tracking'] = sanitize_text_field( (string) $args['tracking'] );
		}

		if ( isset( $args['note'] ) && '' !== (string) $args['note'] ) {
			$update['note'] = trim( (string) $row['note'] . "\n" . sanitize_textarea_field( (string) $args['note'] ) );
		}

		// The realistic moment for the box: it is weighed once it is sealed,
		// not when the first unit went in. Only what was given is written, so
		// closing without a weight does not erase one typed earlier.
		$conflict = self::unit_conflict( $row, $args );

		if ( $conflict ) {
			return $conflict;
		}

		$update = array_merge( $update, self::parcel_columns( $args ) );

		// The write is checked, like every other write in this class. It was
		// the last one that was not, and it is the one whose silent failure
		// costs the most: an unchecked failure here leaves a parcel that has
		// gone marked `open`, which in turn stops every unit in it from ever
		// joining another consignment — the inbound one that records its
		// return included.
		//
		// `false` only. An update that matches the row and changes nothing
		// returns 0, and that is not a failure.
		$updated = $wpdb->update( Dahu_Serial_Numbers_Install::consignments_table(), $update, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.

		if ( false === $updated ) {
			return new WP_Error(
				'dahu_serial_numbers_consignment_not_closed',
				sprintf(
					/* translators: %s: consignment reference. */
					__( 'Consignment %s could not be closed.', 'dahu-serial-numbers' ),
					(string) $row['reference']
				),
				array( 'status' => 500 )
			);
		}

		$closed = self::get( $id );

		/**
		 * Fires once a consignment has been closed.
		 *
		 * @param int   $id     Consignment id.
		 * @param array $closed The closed row, with its serials.
		 */
		do_action( 'dahu_serial_numbers_consignment_closed', $id, $closed );

		return $closed;
	}

	/**
	 * Records the parcel's own weight and dimensions on an open consignment.
	 *
	 * For the bench that weighs the box before closing it, and for a carrier
	 * integration that needs the figures to ask for a label. Refused once the
	 * parcel has gone: its dimensions are a statement about something that is
	 * no longer there to measure.
	 *
	 * @param int   $id     Consignment id.
	 * @param array $parcel {
	 *     @type float $weight Optional.
	 *     @type float $length Optional.
	 *     @type float $width  Optional.
	 *     @type float $height Optional.
	 * }
	 * @return bool|WP_Error
	 */
	public static function set_parcel( $id, array $parcel ) {

		global $wpdb;

		$id  = (int) $id;
		$row = self::row( $id );

		if ( ! $row ) {
			return new WP_Error(
				'dahu_serial_numbers_consignment_unknown',
				__( 'No such consignment.', 'dahu-serial-numbers' ),
				array( 'status' => 404 )
			);
		}

		if ( self::OPEN !== $row['status'] ) {
			return new WP_Error(
				'dahu_serial_numbers_consignment_closed',
				__( 'This consignment is no longer open, so its parcel cannot be measured.', 'dahu-serial-numbers' ),
				array( 'status' => 409 )
			);
		}

		$conflict = self::unit_conflict( $row, $parcel );

		if ( $conflict ) {
			return $conflict;
		}

		$update = self::parcel_columns( $parcel );

		if ( ! $update ) {
			return true;
		}

		$written = $wpdb->update( Dahu_Serial_Numbers_Install::consignments_table(), $update, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.

		if ( false === $written ) {
			return new WP_Error(
				'dahu_serial_numbers_parcel_not_written',
				__( 'The parcel’s measurements could not be recorded.', 'dahu-serial-numbers' ),
				array( 'status' => 500 )
			);
		}

		return true;
	}

	/**
	 * Refuses a measure taken in a different unit from the one already on file.
	 *
	 * A parcel measured in centimetres and then in inches would hold one unit
	 * column for two families of figures. Converting is rejected: it would
	 * invent a precision nobody measured. `open()` has no earlier row, so it is
	 * not concerned.
	 *
	 * @param array $row  The consignment as it stands.
	 * @param array $args What the caller wants to write.
	 * @return WP_Error|null
	 */
	private static function unit_conflict( array $row, array $args ) {

		$pairs = array(
			'weight_unit'    => array( 'weight' ),
			'dimension_unit' => array( 'length', 'width', 'height' ),
		);

		foreach ( $pairs as $column => $measures ) {
			if ( empty( $row[ $column ] ) ) {
				continue;
			}

			$writing = false;

			foreach ( $measures as $measure ) {
				if ( isset( $args[ $measure ] ) && '' !== $args[ $measure ] && is_numeric( $args[ $measure ] ) ) {
					$writing = true;
				}
			}

			if ( ! $writing ) {
				continue;
			}

			$now = 'weight_unit' === $column ? self::weight_unit() : self::dimension_unit();

			if ( (string) $row[ $column ] === $now ) {
				continue;
			}

			return new WP_Error(
				'dahu_serial_numbers_unit_changed',
				sprintf(
					/* translators: 1: unit already on file, 2: the shop's current unit. */
					__( 'This parcel was already measured in %1$s and the shop now uses %2$s. Set the shop back, or clear the measurements already recorded, rather than mixing two units on one parcel.', 'dahu-serial-numbers' ),
					(string) $row[ $column ],
					$now
				),
				array( 'status' => 409 )
			);
		}

		return null;
	}

	/**
	 * The parcel columns to write, from whatever the caller supplied.
	 *
	 * Only the keys that were given, so a call that says nothing about the box
	 * leaves the box alone. A figure that is not a number, or is negative, is
	 * dropped rather than stored as zero: a parcel weighing nothing is a
	 * measurement nobody took, and zero would read as one.
	 *
	 * The unit is read from the shop and written beside the figure. See the
	 * header of class-dahu-serial-numbers-install.php for why it is stored
	 * rather than looked up again at reading time.
	 *
	 * @param array $args Arguments.
	 * @return array<string,mixed>
	 */
	private static function parcel_columns( array $args ) {

		$out = array();

		foreach ( array( 'weight', 'length', 'width', 'height' ) as $key ) {
			if ( ! isset( $args[ $key ] ) || '' === $args[ $key ] ) {
				continue;
			}

			if ( ! is_numeric( $args[ $key ] ) || (float) $args[ $key ] < 0 ) {
				continue;
			}

			$out[ $key ] = (float) $args[ $key ];
		}

		if ( isset( $out['weight'] ) ) {
			$out['weight_unit'] = self::weight_unit();
		}

		if ( isset( $out['length'] ) || isset( $out['width'] ) || isset( $out['height'] ) ) {
			$out['dimension_unit'] = self::dimension_unit();
		}

		return $out;
	}

	/**
	 * The shop's weight unit.
	 *
	 * @return string
	 */
	public static function weight_unit() {

		return (string) get_option( 'woocommerce_weight_unit', 'kg' );
	}

	/**
	 * The shop's dimension unit.
	 *
	 * @return string
	 */
	public static function dimension_unit() {

		return (string) get_option( 'woocommerce_dimension_unit', 'cm' );
	}

	/**
	 * The parcel of a consignment row, as something a screen can print.
	 *
	 * Empty string when nothing was measured, rather than a row of dashes or
	 * a zero: not knowing is a different thing from knowing it is nothing.
	 *
	 * @param array $row Consignment row.
	 * @return string
	 */
	public static function parcel_label( array $row ) {

		$parts = array();

		if ( isset( $row['weight'] ) && null !== $row['weight'] ) {
			$parts[] = sprintf(
				/* translators: 1: weight, 2: unit. */
				_x( '%1$s %2$s', 'parcel weight', 'dahu-serial-numbers' ),
				number_format_i18n( (float) $row['weight'], 3 ),
				(string) $row['weight_unit']
			);
		}

		$sides = array();

		foreach ( array( 'length', 'width', 'height' ) as $side ) {
			if ( isset( $row[ $side ] ) && null !== $row[ $side ] ) {
				$sides[] = number_format_i18n( (float) $row[ $side ], 2 );
			}
		}

		if ( $sides ) {
			$parts[] = sprintf(
				/* translators: 1: dimensions joined by a multiplication sign, 2: unit. */
				_x( '%1$s %2$s', 'parcel dimensions', 'dahu-serial-numbers' ),
				implode( ' × ', $sides ),
				(string) $row['dimension_unit']
			);
		}

		return implode( ' · ', $parts );
	}

	/**
	 * Cancels a consignment that was opened and not used.
	 *
	 * Marked, never deleted. A reference that was quoted to somebody — on a
	 * screen, in a log line, to a carrier's API — has to keep resolving to an
	 * explanation, and "cancelled, because the carrier would not issue a
	 * label" is an explanation. A row that has vanished is not.
	 *
	 * @param int    $id     Consignment id.
	 * @param string $reason Optional. Recorded in the note.
	 * @return bool|WP_Error
	 */
	public static function cancel( $id, $reason = '' ) {

		global $wpdb;

		$id  = (int) $id;
		$row = self::row( $id );

		if ( ! $row ) {
			return new WP_Error(
				'dahu_serial_numbers_consignment_unknown',
				__( 'No such consignment.', 'dahu-serial-numbers' ),
				array( 'status' => 404 )
			);
		}

		if ( self::CLOSED === $row['status'] ) {
			return new WP_Error(
				'dahu_serial_numbers_consignment_already_closed',
				__( 'A closed consignment cannot be cancelled: the parcel has gone.', 'dahu-serial-numbers' ),
				array( 'status' => 409 )
			);
		}

		// Already cancelled: nothing to do, and saying so by doing nothing.
		// A second call used to move `closed_at` forward and append its
		// reason to the note again, so a retried request — which is the
		// normal way a cancel arrives twice — rewrote the time the parcel
		// was abandoned and left the reason in the note twice.
		if ( self::CANCELLED === $row['status'] ) {
			return true;
		}

		$update = array(
			'status'    => self::CANCELLED,
			'closed_at' => gmdate( 'Y-m-d H:i:s' ),
		);

		if ( '' !== (string) $reason ) {
			$update['note'] = trim( (string) $row['note'] . "\n" . sanitize_textarea_field( (string) $reason ) );
		}

		$updated = $wpdb->update( Dahu_Serial_Numbers_Install::consignments_table(), $update, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.

		if ( false === $updated ) {
			return new WP_Error(
				'dahu_serial_numbers_consignment_not_cancelled',
				sprintf(
					/* translators: %s: consignment reference. */
					__( 'Consignment %s could not be cancelled.', 'dahu-serial-numbers' ),
					(string) $row['reference']
				),
				array( 'status' => 500 )
			);
		}

		/**
		 * Fires once a consignment has been cancelled.
		 *
		 * @param int    $id     Consignment id.
		 * @param string $reason Why.
		 */
		do_action( 'dahu_serial_numbers_consignment_cancelled', $id, (string) $reason );

		return true;
	}

	/**
	 * One raw row, without its serials.
	 *
	 * @param int $id Consignment id.
	 * @return array|null
	 */
	private static function row( $id ) {

		global $wpdb;

		$table = Dahu_Serial_Numbers_Install::consignments_table();

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, value prepared.
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * One consignment, by id or by reference, with its serials.
	 *
	 * Accepting both is for the support call: the reference is the thing
	 * written on a screen and read out loud, the id is what another plugin
	 * holds.
	 *
	 * @param int|string $id_or_reference Id or reference.
	 * @return array|null
	 */
	public static function get( $id_or_reference ) {

		global $wpdb;

		$table = Dahu_Serial_Numbers_Install::consignments_table();

		if ( is_numeric( $id_or_reference ) ) {
			$row = self::row( (int) $id_or_reference );
		} else {
			$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, value prepared.
				$wpdb->prepare( "SELECT * FROM {$table} WHERE reference = %s", (string) $id_or_reference ),
				ARRAY_A
			);
		}

		if ( ! $row ) {
			return null;
		}

		$row['id']       = (int) $row['id'];
		$row['order_id'] = null !== $row['order_id'] ? (int) $row['order_id'] : null;
		foreach ( array( 'weight', 'length', 'width', 'height' ) as $measure ) {
			$row[ $measure ] = null !== $row[ $measure ] ? (float) $row[ $measure ] : null;
		}

		$row['serials'] = self::serials( $row['id'] );
		$row['count']   = count( $row['serials'] );
		$row['parcel']  = self::parcel_label( $row );

		return $row;
	}

	/**
	 * The serials in a consignment, in the order they were added.
	 *
	 * @param int $id Consignment id.
	 * @return string[]
	 */
	public static function serials( $id ) {

		global $wpdb;

		$table = Dahu_Serial_Numbers_Install::consignment_serials_table();

		return array_map(
			'strval',
			(array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, value prepared.
				$wpdb->prepare( "SELECT serial FROM {$table} WHERE consignment_id = %d ORDER BY id ASC", (int) $id )
			)
		);
	}

	/**
	 * The serials of several consignments at once.
	 *
	 * One statement for a whole screen. The list screen prints the units
	 * under every line, and calling `serials()` per row turned a page of
	 * twenty-five into twenty-five queries — on top of the grouped count
	 * query that was already there, which is what made that count query
	 * pointless.
	 *
	 * @param int[] $ids Consignment ids.
	 * @return array<int,string[]> Keyed by consignment id, in insertion order.
	 */
	public static function serials_for( array $ids ) {

		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );

		if ( ! $ids ) {
			return array();
		}

		$table        = Dahu_Serial_Numbers_Install::consignment_serials_table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, values prepared.
			$wpdb->prepare( "SELECT consignment_id, serial FROM {$table} WHERE consignment_id IN ({$placeholders}) ORDER BY id ASC", $ids ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		$out = array_fill_keys( $ids, array() );

		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['consignment_id'] ][] = (string) $row['serial'];
		}

		return $out;
	}

	/**
	 * One consignment, by reference only.
	 *
	 * `get()` accepts either an id or a reference and tells them apart by
	 * asking whether the string is numeric. That is fine for a caller holding
	 * one or the other on purpose, and wrong for a published route, where a
	 * request for reference "5" would quietly answer with consignment id 5.
	 * Anything resolving user input uses this.
	 *
	 * @param string $reference Reference.
	 * @return array|null
	 */
	public static function get_by_reference( $reference ) {

		global $wpdb;

		$table = Dahu_Serial_Numbers_Install::consignments_table();

		$id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, value prepared.
			$wpdb->prepare( "SELECT id FROM {$table} WHERE reference = %s", (string) $reference )
		);

		return null === $id ? null : self::get( (int) $id );
	}

	/**
	 * The open consignment holding a serial, if there is one.
	 *
	 * @param string $serial  Serial number.
	 * @param int    $besides Optional. A consignment id to ignore.
	 * @return string Reference, or '' when the unit is in no open consignment.
	 */
	public static function open_consignment_for( $serial, $besides = 0 ) {

		global $wpdb;

		$consignments = Dahu_Serial_Numbers_Install::consignments_table();
		$pivot        = Dahu_Serial_Numbers_Install::consignment_serials_table();

		$reference = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own tables, values prepared.
			$wpdb->prepare(
				"SELECT c.reference FROM {$pivot} p
				INNER JOIN {$consignments} c ON c.id = p.consignment_id
				WHERE p.serial = %s AND c.status = %s AND c.id <> %d
				ORDER BY c.id DESC LIMIT 1",
				(string) $serial,
				self::OPEN,
				(int) $besides
			)
		);

		return null === $reference ? '' : (string) $reference;
	}

	/**
	 * Every consignment on an order, newest first.
	 *
	 * @param int    $order_id  Order id.
	 * @param string $direction Optional. Restrict to one direction.
	 * @return array[]
	 */
	public static function for_order( $order_id, $direction = '' ) {

		// Zero is a missing order, not a filter: `query()` reads an `order_id`
		// of 0 as "no restriction", and a deleted order answers
		// `get_id() === 0`.
		if ( (int) $order_id <= 0 ) {
			return array();
		}

		return self::query(
			array(
				'order_id'  => (int) $order_id,
				'direction' => $direction,
				'per_page'  => 100,
			)
		);
	}

	/**
	 * Every consignment a unit has travelled in, newest first.
	 *
	 * This is the round trip: out in C-2026-00042, back in R-2026-00007, out
	 * again after repair. A column on the serials table could hold one of
	 * those; the join table holds all of them, which is the reason it is a
	 * join table.
	 *
	 * @param string $serial Serial number.
	 * @return array[]
	 */
	public static function for_serial( $serial ) {

		global $wpdb;

		$consignments = Dahu_Serial_Numbers_Install::consignments_table();
		$pivot        = Dahu_Serial_Numbers_Install::consignment_serials_table();

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own tables, value prepared.
			$wpdb->prepare(
				"SELECT c.*, p.added_at AS added_at FROM {$pivot} p
				INNER JOIN {$consignments} c ON c.id = p.consignment_id
				WHERE p.serial = %s
				ORDER BY c.id DESC",
				(string) $serial
			),
			ARRAY_A
		);

		return self::cast( (array) $rows );
	}

	/**
	 * Lists consignments.
	 *
	 * The `tracking` and `reference` arguments are prefix matches rather than
	 * substring matches: a tracking number is read off a label from the left,
	 * and a leading wildcard means no index is used on a table that grows by
	 * one row per parcel forever.
	 *
	 * @param array $args {
	 *     @type string $direction Optional.
	 *     @type string $status    Optional.
	 *     @type int    $order_id  Optional.
	 *     @type string $tracking  Optional. Prefix.
	 *     @type string $reference Optional. Prefix.
	 *     @type string $serial    Optional. Exact.
	 *     @type int    $paged     Optional.
	 *     @type int    $per_page  Optional.
	 * }
	 * @return array[]
	 */
	public static function query( array $args = array() ) {

		global $wpdb;

		$consignments = Dahu_Serial_Numbers_Install::consignments_table();
		$pivot        = Dahu_Serial_Numbers_Install::consignment_serials_table();

		$per_page = Dahu_Serial_Numbers_Manager::page_size( isset( $args['per_page'] ) ? $args['per_page'] : 0 );
		$paged    = Dahu_Serial_Numbers_Manager::page_number( isset( $args['paged'] ) ? $args['paged'] : 0 );

		$where  = array( '1=1' );
		$params = array();
		$join   = '';

		if ( ! empty( $args['direction'] ) ) {
			$where[]  = 'c.direction = %s';
			$params[] = (string) $args['direction'];
		}

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'c.status = %s';
			$params[] = (string) $args['status'];
		}

		if ( ! empty( $args['order_id'] ) ) {
			$where[]  = 'c.order_id = %d';
			$params[] = (int) $args['order_id'];
		}

		if ( ! empty( $args['tracking'] ) ) {
			$where[]  = 'c.tracking LIKE %s';
			$params[] = $wpdb->esc_like( (string) $args['tracking'] ) . '%';
		}

		if ( ! empty( $args['reference'] ) ) {
			$where[]  = 'c.reference LIKE %s';
			$params[] = $wpdb->esc_like( (string) $args['reference'] ) . '%';
		}

		if ( ! empty( $args['serial'] ) ) {
			$join     = " INNER JOIN {$pivot} p ON p.consignment_id = c.id ";
			$where[]  = 'p.serial = %s';
			$params[] = (string) $args['serial'];
		}

		$sql = "SELECT c.* FROM {$consignments} c{$join} WHERE " . implode( ' AND ', $where )
			. ' ORDER BY c.id DESC LIMIT %d OFFSET %d';

		$params[] = $per_page;
		$params[] = ( $paged - 1 ) * $per_page;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own tables, values prepared.
			$wpdb->prepare( $sql, $params ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		return self::cast( (array) $rows );
	}

	/**
	 * How many consignments match, ignoring paging.
	 *
	 * @param array $args Same as query(), minus paging.
	 * @return int
	 */
	public static function count( array $args = array() ) {

		global $wpdb;

		$consignments = Dahu_Serial_Numbers_Install::consignments_table();
		$pivot        = Dahu_Serial_Numbers_Install::consignment_serials_table();

		$where  = array( '1=1' );
		$params = array();
		$join   = '';

		if ( ! empty( $args['direction'] ) ) {
			$where[]  = 'c.direction = %s';
			$params[] = (string) $args['direction'];
		}

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'c.status = %s';
			$params[] = (string) $args['status'];
		}

		if ( ! empty( $args['order_id'] ) ) {
			$where[]  = 'c.order_id = %d';
			$params[] = (int) $args['order_id'];
		}

		if ( ! empty( $args['tracking'] ) ) {
			$where[]  = 'c.tracking LIKE %s';
			$params[] = $wpdb->esc_like( (string) $args['tracking'] ) . '%';
		}

		if ( ! empty( $args['reference'] ) ) {
			$where[]  = 'c.reference LIKE %s';
			$params[] = $wpdb->esc_like( (string) $args['reference'] ) . '%';
		}

		if ( ! empty( $args['serial'] ) ) {
			$join     = " INNER JOIN {$pivot} p ON p.consignment_id = c.id ";
			$where[]  = 'p.serial = %s';
			$params[] = (string) $args['serial'];
		}

		$sql = "SELECT COUNT(DISTINCT c.id) FROM {$consignments} c{$join} WHERE " . implode( ' AND ', $where );

		if ( $params ) {
			$sql = $wpdb->prepare( $sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- own tables, values prepared above.
	}

	/**
	 * How many units are in each of a set of consignments.
	 *
	 * One statement for a whole screen, rather than one per row. The list
	 * screen shows a count next to every reference, and a shop with four
	 * thousand parcels would otherwise do four thousand queries to paint
	 * twenty lines.
	 *
	 * @param int[] $ids Consignment ids.
	 * @return array<int,int>
	 */
	public static function counts( array $ids ) {

		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );

		if ( ! $ids ) {
			return array();
		}

		$pivot        = Dahu_Serial_Numbers_Install::consignment_serials_table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, values prepared.
			$wpdb->prepare( "SELECT consignment_id, COUNT(*) AS n FROM {$pivot} WHERE consignment_id IN ({$placeholders}) GROUP BY consignment_id", $ids ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		$counts = array();

		foreach ( (array) $rows as $row ) {
			$counts[ (int) $row['consignment_id'] ] = (int) $row['n'];
		}

		return $counts;
	}

	/**
	 * Integer columns back to integers.
	 *
	 * @param array[] $rows Rows.
	 * @return array[]
	 */
	private static function cast( array $rows ) {

		foreach ( $rows as $index => $row ) {
			$rows[ $index ]['id']         = (int) $row['id'];
			$rows[ $index ]['order_id']   = isset( $row['order_id'] ) && null !== $row['order_id'] ? (int) $row['order_id'] : null;
			$rows[ $index ]['station_id'] = isset( $row['station_id'] ) && null !== $row['station_id'] ? (int) $row['station_id'] : null;

			// DECIMAL comes back as a string from every driver. A consumer
			// comparing it to a number would be comparing "2.4000" to 2.4.
			foreach ( array( 'weight', 'length', 'width', 'height' ) as $measure ) {
				$rows[ $index ][ $measure ] = isset( $row[ $measure ] ) && null !== $row[ $measure ] ? (float) $row[ $measure ] : null;
			}
		}

		return $rows;
	}
}
