<?php
/**
 * Every change to a serial goes through here. There is no second door.
 *
 * ## What was left behind, and why that is the interesting part
 *
 * The class this was extracted from is 4,576 lines and sixty-seven methods.
 * Fifteen of them are here. The other fifty-two are a supplier after-sales
 * exchange workflow, a replacement chain with pending states and retries, a
 * stock ledger with reorder thresholds, a manufacturer lookup over HTTP, a
 * cohort analytics feed, and a product-migration resolver. Every one of them
 * is real, working code that a shop paid for. None of them is traceability.
 *
 * They were not dropped for being complicated. They were dropped because each
 * one encodes a decision only that shop can make — which categories count as
 * the same family, when a unit is "pending replacement", what a reorder point
 * is — and a plugin that ships those decisions as code is a plugin that is
 * wrong everywhere else. Where the need generalises, what is left is a hook:
 * `dahu_serial_numbers_resolve_product` in place of the migration resolver,
 * `dahu_serial_numbers_decode` in place of the supplier's letter table,
 * `dahu_serial_numbers_transitions` in place of the extra lifecycle states.
 *
 * ## One thing that was deliberately not carried over
 *
 * `transition()` in the source takes a fourth argument, `$bypass_matrix_check`,
 * which skips the legality test. It exists for one caller and the docblock
 * says so. A boolean that turns off the only rule a class enforces is the kind
 * of parameter that is passed `true` by the third caller who meets it, in good
 * faith, at 18:40 on a Friday. A shop that needs a move the matrix refuses
 * widens the matrix with a filter, in one visible line, where the next reader
 * can see it.
 *
 * @package DahuSerialNumbers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Serial numbers: create, read, move, record.
 */
class Dahu_Serial_Numbers_Manager {

	/**
	 * Columns a caller's context is allowed to write during a transition.
	 *
	 * An allow-list rather than "whatever was passed", because context
	 * arrives from a REST body on a tablet on a shop floor.
	 *
	 * @var string[]
	 */
	const CONTEXT_COLUMNS = array( 'order_id', 'order_item_id', 'service_ticket', 'replaced_by', 'product_id', 'checked_at' );

	/**
	 * Rows a query returns when the caller does not say.
	 */
	const DEFAULT_PER_PAGE = 50;

	/**
	 * Most rows one query may return, whatever was asked for.
	 */
	const MAX_PER_PAGE = 500;

	/* ---------------------------------------------------------------------
	 * Reading
	 * ------------------------------------------------------------------ */

	/**
	 * One serial's row.
	 *
	 * @param string $serial Serial.
	 * @return array<string,mixed>|null
	 */
	public static function get( $serial ) {

		global $wpdb;

		$serial = Dahu_Serial_Numbers_Format::normalise( $serial );

		if ( '' === $serial ) {
			return null;
		}

		$table = Dahu_Serial_Numbers_Install::serials_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from $wpdb->prefix, own table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE serial = %s", $serial ), ARRAY_A );

		return $row ? $row : null;
	}

	/**
	 * Is this serial already known?
	 *
	 * @param string $serial Serial.
	 * @return bool
	 */
	public static function exists( $serial ) {

		global $wpdb;

		$serial = Dahu_Serial_Numbers_Format::normalise( $serial );

		if ( '' === $serial ) {
			return false;
		}

		$table = Dahu_Serial_Numbers_Install::serials_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from $wpdb->prefix, own table.
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE serial = %s", $serial ) );
	}

	/**
	 * Every serial on one order.
	 *
	 * @param int $order_id Order id.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_by_order( $order_id ) {

		global $wpdb;

		$order_id = (int) $order_id;

		if ( $order_id <= 0 ) {
			return array();
		}

		$table = Dahu_Serial_Numbers_Install::serials_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from $wpdb->prefix, own table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d ORDER BY order_item_id ASC, serial ASC", $order_id ), ARRAY_A );

		return $rows ? $rows : array();
	}

	/**
	 * The serials of specific order lines, grouped by line.
	 *
	 * Every id asked for comes back as a key, even when its list is empty, so
	 * a caller can walk its own lines without an isset() on each one.
	 *
	 * @param int[]    $order_item_ids Order line ids.
	 * @param int|null $order_id       Optional. Also filter on the order, so a
	 *                                 caller that mixes up line ids from two
	 *                                 orders gets nothing rather than somebody
	 *                                 else's serials.
	 * @return array<int,array<int,array<string,mixed>>>
	 */
	public static function get_by_order_items( array $order_item_ids, $order_id = null ) {

		global $wpdb;

		$ids = array();

		foreach ( $order_item_ids as $id ) {
			$id = (int) $id;

			if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}

		$grouped = array();

		foreach ( $ids as $id ) {
			$grouped[ $id ] = array();
		}

		if ( ! $ids ) {
			return $grouped;
		}

		$table        = Dahu_Serial_Numbers_Install::serials_table();
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$params       = $ids;

		$sql = "SELECT * FROM {$table} WHERE order_item_id IN ( {$placeholders} )";

		if ( null !== $order_id && (int) $order_id > 0 ) {
			$sql     .= ' AND order_id = %d';
			$params[] = (int) $order_id;
		}

		$sql .= ' ORDER BY order_item_id ASC, serial ASC';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from $wpdb->prefix, placeholders generated here, every value prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		foreach ( (array) $rows as $row ) {
			$grouped[ (int) $row['order_item_id'] ][] = $row;
		}

		return $grouped;
	}

	/**
	 * Order lines that still owe serial numbers.
	 *
	 * Counts both `assigned` and `shipped` as done, so an order whose
	 * completion hook fires twice — a bulk action replayed, a status walked
	 * back and forward — is not reported as incomplete the second time.
	 *
	 * @param int $order_id Order id.
	 * @return array<int,array{product_name:string,required:int,assigned:int}>
	 */
	public static function missing_for_order( $order_id ) {

		$order_id = (int) $order_id;

		if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return array();
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return array();
		}

		$rows    = self::get_by_order( $order_id );
		$done    = array( Dahu_Serial_Numbers_Statuses::ASSIGNED, Dahu_Serial_Numbers_Statuses::SHIPPED );
		$missing = array();

		foreach ( $order->get_items() as $item_id => $item ) {
			$product_id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();

			if ( ! Dahu_Serial_Numbers_Products::is_serialised( $product_id ) ) {
				continue;
			}

			$required = (int) $item->get_quantity();
			$assigned = 0;

			foreach ( $rows as $row ) {
				if ( (int) $row['order_item_id'] === (int) $item_id && in_array( $row['status'], $done, true ) ) {
					++$assigned;
				}
			}

			if ( $assigned < $required ) {
				$missing[ (int) $item_id ] = array(
					'product_name' => $item->get_name(),
					'required'     => $required,
					'assigned'     => $assigned,
				);
			}
		}

		return $missing;
	}

	/**
	 * Rows per page, bounded.
	 *
	 * A method rather than an expression inside `query()`, for one reason:
	 * asserting the cap through `query()` needs five hundred and one rows in
	 * the database. The injection run said so — it removed the cap and the
	 * bench stayed green, because the bench shop has a dozen units and asking
	 * for a hundred thousand returned a dozen either way. A bound worth having
	 * is a bound worth asserting.
	 *
	 * @param mixed $requested Rows asked for.
	 * @return int
	 */
	public static function page_size( $requested ) {

		$requested = (int) $requested;

		return $requested > 0 ? min( $requested, self::MAX_PER_PAGE ) : self::DEFAULT_PER_PAGE;
	}

	/**
	 * Page number, never below one.
	 *
	 * Page zero would give a negative offset, which MySQL refuses and SQLite
	 * quietly treats as zero — so the same mistake is an error on one engine
	 * and silently the first page on the other.
	 *
	 * @param mixed $requested Page asked for.
	 * @return int
	 */
	public static function page_number( $requested ) {

		return max( 1, (int) $requested );
	}

	/**
	 * A filtered, paged slice of the serials, plus how many match.
	 *
	 * The count comes back with the rows because a table that shows fifty of
	 * nine hundred and says nothing is a wall, not a list. One extra query,
	 * and the screen can say what it is not showing.
	 *
	 * @param array $args {
	 *     @type string $search     Optional. Part of a serial number.
	 *     @type string $status     Optional. One status key.
	 *     @type string $batch      Optional. Part of a supplier batch reference.
	 *     @type int    $product_id Optional.
	 *     @type int    $order_id   Optional.
	 *     @type int    $per_page   Optional. Default 50, capped at 500.
	 *     @type int    $page       Optional. Default 1.
	 * }
	 * @return array{rows:array<int,array<string,mixed>>,total:int}
	 */
	public static function query( array $args = array() ) {

		global $wpdb;

		$table    = Dahu_Serial_Numbers_Install::serials_table();
		$where    = array( '1=1' );
		$params   = array();
		$per_page = self::page_size( isset( $args['per_page'] ) ? $args['per_page'] : 0 );
		$page     = self::page_number( isset( $args['page'] ) ? $args['page'] : 0 );

		// Normalised before it is used as a pattern: somebody pasting a
		// scanned URL into the search box is looking for the serial inside
		// it, and somebody typing in lower case is looking for the same unit.
		$search = isset( $args['search'] ) ? Dahu_Serial_Numbers_Format::normalise( $args['search'] ) : '';

		if ( '' !== $search ) {
			$where[]  = 'serial LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$status = isset( $args['status'] ) ? (string) $args['status'] : '';

		// An unknown status filters on nothing rather than on everything: a
		// typed status that silently returned the whole table would read as
		// "this status has nine hundred units in it".
		if ( '' !== $status ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}

		$batch = isset( $args['batch'] ) ? Dahu_Serial_Numbers_Format::normalise_batch( $args['batch'] ) : '';

		if ( '' !== $batch ) {
			$where[]  = 'supplier_order LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $batch ) . '%';
		}

		if ( ! empty( $args['product_id'] ) ) {
			$where[]  = 'product_id = %d';
			$params[] = (int) $args['product_id'];
		}

		if ( ! empty( $args['order_id'] ) ) {
			$where[]  = 'order_id = %d';
			$params[] = (int) $args['order_id'];
		}

		$clause = implode( ' AND ', $where );

		// Counted before the slice, so the total is the total and not the
		// length of the page.
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$clause}";
		$total     = (int) ( $params
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- clause built from literals here, every value prepared.
			? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) )
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- no user value in the statement.
			: $wpdb->get_var( $count_sql ) );

		$rows_sql       = "SELECT * FROM {$table} WHERE {$clause} ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d";
		$rows_params    = $params;
		$rows_params[]  = $per_page;
		$rows_params[]  = ( $page - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- clause built from literals here, every value prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( $rows_sql, $rows_params ), ARRAY_A );

		return array(
			'rows'  => $rows ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * How many units are in each status.
	 *
	 * @return array<string,int> Status key => count. Every known status is a key.
	 */
	public static function counts_by_status() {

		global $wpdb;

		$counts = array();

		foreach ( Dahu_Serial_Numbers_Statuses::all() as $status ) {
			$counts[ $status ] = 0;
		}

		$table = Dahu_Serial_Numbers_Install::serials_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from $wpdb->prefix, own table.
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status", ARRAY_A );

		foreach ( (array) $rows as $row ) {
			// A status the plugin does not know still gets a line: it is in
			// the data, and hiding it would make the totals not add up.
			$counts[ (string) $row['status'] ] = (int) $row['total'];
		}

		return $counts;
	}

	/* ---------------------------------------------------------------------
	 * Writing
	 * ------------------------------------------------------------------ */

	/**
	 * Files a new serial.
	 *
	 * @param array $data {
	 *     @type string $serial         Required. Normalised here.
	 *     @type string $batch          Optional. Supplier batch reference.
	 *     @type string $supplier_name  Optional. Supplier's name for the part.
	 *     @type string $supplier_spec  Optional. Supplier's specification.
	 *     @type int    $product_id     Optional. WooCommerce product or variation.
	 *     @type string $status         Optional. Defaults to `in_stock`.
	 *     @type int    $order_id       Optional.
	 *     @type int    $order_item_id  Optional.
	 *     @type string $received_at    Optional. UTC MySQL datetime.
	 *     @type string $log_created_at Optional. UTC MySQL datetime for the
	 *                                  history line, when filing a unit whose
	 *                                  real arrival date is known and past.
	 * }
	 * @return int|WP_Error Row id.
	 */
	public static function create( array $data ) {

		global $wpdb;

		$serial = Dahu_Serial_Numbers_Format::normalise( isset( $data['serial'] ) ? $data['serial'] : '' );

		if ( '' === $serial ) {
			return new WP_Error(
				'dahu_serial_numbers_empty_serial',
				__( 'The serial number is empty or unreadable.', 'dahu-serial-numbers' )
			);
		}

		if ( strlen( $serial ) > Dahu_Serial_Numbers_Format::MAX_LENGTH ) {
			return new WP_Error(
				'dahu_serial_numbers_too_long',
				sprintf(
					/* translators: 1: maximum number of characters, 2: serial number. */
					__( 'Serial number longer than %1$d characters: %2$s', 'dahu-serial-numbers' ),
					Dahu_Serial_Numbers_Format::MAX_LENGTH,
					$serial
				)
			);
		}

		if ( self::exists( $serial ) ) {
			return new WP_Error(
				'dahu_serial_numbers_duplicate',
				sprintf(
					/* translators: %s: serial number. */
					__( 'Serial number %s is already on file.', 'dahu-serial-numbers' ),
					$serial
				),
				array( 'serial' => $serial )
			);
		}

		$status = isset( $data['status'] ) ? (string) $data['status'] : Dahu_Serial_Numbers_Statuses::IN_STOCK;

		if ( ! Dahu_Serial_Numbers_Statuses::is_valid( $status ) ) {
			return new WP_Error(
				'dahu_serial_numbers_invalid_status',
				sprintf(
					/* translators: %s: status key. */
					__( 'Unknown status: %s', 'dahu-serial-numbers' ),
					$status
				)
			);
		}

		$now        = gmdate( 'Y-m-d H:i:s' );
		$requested  = isset( $data['product_id'] ) ? (int) $data['product_id'] : 0;
		$product_id = $requested > 0 ? Dahu_Serial_Numbers_Products::resolve( $requested ) : 0;
		$date       = Dahu_Serial_Numbers_Format::decode( $serial );

		// An explicit year or month wins over the decoder: a supplier file
		// that states the manufacturing date knows better than a scheme
		// reading letters, and an import should not have its own data
		// overwritten by a guess.
		$year  = isset( $data['manufactured_year'] ) && $data['manufactured_year'] ? (int) $data['manufactured_year'] : $date['year'];
		$month = isset( $data['manufactured_month'] ) && $data['manufactured_month'] ? (int) $data['manufactured_month'] : $date['month'];

		$row = array(
			'serial'             => $serial,
			'supplier_order'     => Dahu_Serial_Numbers_Format::normalise_batch( isset( $data['batch'] ) ? $data['batch'] : '' ),
			'supplier_name'      => isset( $data['supplier_name'] ) ? substr( (string) $data['supplier_name'], 0, 191 ) : '',
			'supplier_spec'      => isset( $data['supplier_spec'] ) && '' !== $data['supplier_spec'] ? substr( (string) $data['supplier_spec'], 0, 191 ) : null,
			'manufactured_year'  => $year ? (int) $year : null,
			'manufactured_month' => $month ? (int) $month : null,
			'product_id'         => $product_id ? $product_id : null,
			'status'             => $status,
			'order_id'           => isset( $data['order_id'] ) && $data['order_id'] ? (int) $data['order_id'] : null,
			'order_item_id'      => isset( $data['order_item_id'] ) && $data['order_item_id'] ? (int) $data['order_item_id'] : null,
			'received_at'        => isset( $data['received_at'] ) && $data['received_at'] ? (string) $data['received_at'] : $now,
			'updated_at'         => $now,
		);

		$inserted = $wpdb->insert( Dahu_Serial_Numbers_Install::serials_table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.

		if ( ! $inserted ) {
			return new WP_Error(
				'dahu_serial_numbers_insert_failed',
				sprintf(
					/* translators: %s: serial number. */
					__( 'Serial number %s could not be filed.', 'dahu-serial-numbers' ),
					$serial
				)
			);
		}

		$id = (int) $wpdb->insert_id;

		Dahu_Serial_Numbers_Log::record(
			$serial,
			'create',
			array(
				'new_status' => $status,
				'order_id'   => $row['order_id'],
				'created_at' => isset( $data['log_created_at'] ) && '' !== $data['log_created_at'] ? (string) $data['log_created_at'] : null,
				'context'    => array(
					'batch'         => $row['supplier_order'],
					'supplier_name' => $row['supplier_name'],
					'product_id'    => $row['product_id'],
					// Only present when the two differ, which is the only
					// case worth a reader's attention.
					'requested_product_id' => $requested !== $product_id ? $requested : null,
				),
			)
		);

		/**
		 * Fires once a serial has been filed.
		 *
		 * @param string               $serial Normalised serial.
		 * @param array<string,mixed>  $row    The row as inserted.
		 */
		do_action( 'dahu_serial_numbers_created', $serial, $row );

		return $id;
	}

	/**
	 * Columns a move has to clear.
	 *
	 * This is the invariant the source found in staging and it is worth
	 * stating plainly: a unit back in stock is a free unit. Leaving the order
	 * it came off behind means every query filtering on `order_id` returns
	 * units that are not on that order any more — and it reads as data, not
	 * as a leftover, so nobody doubts it.
	 *
	 * Going from shipped back to assigned is a cancelled shipment, not an
	 * un-assignment: the unit stays on its order. Only the replacement
	 * pointer, which means nothing before shipping, goes.
	 *
	 * Nothing is lost either way — the previous values go into the history
	 * line.
	 *
	 * @param string $from Current status.
	 * @param string $to   Wanted status.
	 * @return string[]
	 */
	public static function columns_cleared_by( $from, $to ) {

		$cleared = array();

		if ( Dahu_Serial_Numbers_Statuses::IN_STOCK === $to ) {
			$cleared = array( 'order_id', 'order_item_id', 'service_ticket', 'replaced_by' );
		} elseif ( Dahu_Serial_Numbers_Statuses::SHIPPED === $from && Dahu_Serial_Numbers_Statuses::ASSIGNED === $to ) {
			$cleared = array( 'replaced_by' );
		} elseif ( Dahu_Serial_Numbers_Statuses::REPAIRED === $to ) {
			// A unit that was mended was not replaced, so a replacement
			// pointer left on it is now a false statement rather than a
			// stale one. Same reasoning as the clause above, applied to an
			// edge that did not exist until 1.2.0 — an adversarial review
			// found it stated here and not applied there. The order and the
			// service ticket stay: the repair belongs to that case and that
			// order, and losing the link is what makes a repair untraceable.
			$cleared = array( 'replaced_by' );
		}

		/**
		 * Filters the columns a move clears.
		 *
		 * @param string[] $cleared Column names.
		 * @param string   $from    Current status.
		 * @param string   $to      Wanted status.
		 */
		return (array) apply_filters( 'dahu_serial_numbers_columns_cleared', $cleared, $from, $to );
	}

	/**
	 * Moves a serial from one status to another.
	 *
	 * @param string $serial  Serial.
	 * @param string $to      Wanted status.
	 * @param array  $context Optional. Any of CONTEXT_COLUMNS to write, plus
	 *                        `event` for the history line's event key, `note`
	 *                        for a free note, and `created_at` for a line
	 *                        recorded after the fact.
	 * @return true|WP_Error
	 */
	public static function transition( $serial, $to, array $context = array() ) {

		global $wpdb;

		$serial = Dahu_Serial_Numbers_Format::normalise( $serial );
		$row    = self::get( $serial );

		if ( ! $row ) {
			return new WP_Error(
				'dahu_serial_numbers_not_found',
				sprintf(
					/* translators: %s: serial number. */
					__( 'Unknown serial number: %s', 'dahu-serial-numbers' ),
					$serial
				)
			);
		}

		if ( ! Dahu_Serial_Numbers_Statuses::is_valid( $to ) ) {
			return new WP_Error(
				'dahu_serial_numbers_invalid_status',
				sprintf(
					/* translators: %s: status key. */
					__( 'Unknown status: %s', 'dahu-serial-numbers' ),
					$to
				)
			);
		}

		$from = (string) $row['status'];

		if ( ! Dahu_Serial_Numbers_Statuses::is_transition_allowed( $from, $to ) ) {
			return new WP_Error(
				'dahu_serial_numbers_illegal_transition',
				sprintf(
					/* translators: 1: current status label, 2: wanted status label. */
					__( 'Moving from “%1$s” to “%2$s” is not allowed.', 'dahu-serial-numbers' ),
					Dahu_Serial_Numbers_Statuses::label( $from ),
					Dahu_Serial_Numbers_Statuses::label( $to )
				),
				array(
					'serial' => $serial,
					'from'   => $from,
					'to'     => $to,
					'status' => 409,
				)
			);
		}

		$update = array(
			'status'     => $to,
			'updated_at' => gmdate( 'Y-m-d H:i:s' ),
		);

		// Clearing happens before the caller's context is applied, so that a
		// caller cannot attach an order to a unit it is putting back in stock
		// in the same call. Order matters here and it is not an accident.
		$cleared = self::columns_cleared_by( $from, $to );

		foreach ( $cleared as $column ) {
			$update[ $column ] = null;
		}

		foreach ( self::CONTEXT_COLUMNS as $column ) {
			if ( in_array( $column, $cleared, true ) || ! array_key_exists( $column, $context ) ) {
				continue;
			}

			$value = $context[ $column ];

			if ( null === $value || '' === $value ) {
				$update[ $column ] = null;
				continue;
			}

			if ( 'product_id' === $column ) {
				$update[ $column ] = Dahu_Serial_Numbers_Products::resolve( (int) $value );
			} elseif ( in_array( $column, array( 'order_id', 'order_item_id' ), true ) ) {
				$update[ $column ] = (int) $value;
			} else {
				$update[ $column ] = (string) $value;
			}
		}

		$updated = $wpdb->update( Dahu_Serial_Numbers_Install::serials_table(), $update, array( 'serial' => $serial ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.

		if ( false === $updated ) {
			return new WP_Error(
				'dahu_serial_numbers_update_failed',
				sprintf(
					/* translators: %s: serial number. */
					__( 'Serial number %s could not be updated.', 'dahu-serial-numbers' ),
					$serial
				)
			);
		}

		$log_context = $context;
		unset( $log_context['event'] );

		// What was dropped goes in the line, or an un-assignment records that
		// something was detached without saying from what.
		foreach ( $cleared as $column ) {
			if ( ! empty( $row[ $column ] ) ) {
				$log_context[ 'cleared_' . $column ] = $row[ $column ];
			}
		}

		$log_order_id = array_key_exists( 'order_id', $update ) && null !== $update['order_id']
			? $update['order_id']
			: $row['order_id'];

		Dahu_Serial_Numbers_Log::record(
			$serial,
			isset( $context['event'] ) ? (string) $context['event'] : 'transition',
			array(
				'old_status' => $from,
				'new_status' => $to,
				'order_id'   => $log_order_id,
				'created_at' => isset( $context['created_at'] ) && '' !== $context['created_at'] ? (string) $context['created_at'] : null,
				'context'    => $log_context,
			)
		);

		/**
		 * Fires once a move has been recorded.
		 *
		 * @param string $serial  Normalised serial.
		 * @param string $to      New status.
		 * @param string $from    Previous status.
		 * @param array  $context Caller context.
		 */
		do_action( 'dahu_serial_numbers_transitioned', $serial, $to, $from, $context );

		return true;
	}

	/**
	 * Files a unit under another product, without touching its status.
	 *
	 * The mistake this exists for is ordinary and, until now, unfixable
	 * without SQL: a box of units goes onto the shelf under the wrong
	 * reference — two parts that differ by a connector, an import column one
	 * line out — and every count of both products is wrong from that moment
	 * on. Nothing in the lifecycle can mend it, because being filed under the
	 * wrong product is not a state a unit passes through.
	 *
	 * It is deliberately not a transition. The unit has not moved, nothing
	 * happened to it, and giving it a status change would put a lie in the
	 * one place this plugin exists to keep honest. What is recorded is a
	 * correction: a history line that names both products and the reason.
	 *
	 * Three refusals are invariants rather than policy, so they live here and
	 * not in the screen that calls this:
	 *
	 * - A unit that is not `in_stock` is refused. Assigned, shipped, in
	 *   repair — its product is what an order, a parcel or a case says it is,
	 *   and changing it here would leave those documents describing a part
	 *   the shop no longer claims it sent.
	 * - A unit carrying an order is refused for the same reason, even when
	 *   its status says stock: the order line is the stronger statement.
	 * - A unit with no product at all is refused. There is nothing to
	 *   correct, and filing it would be data entry disguised as a fix —
	 *   `transition()` already carries `product_id` for that.
	 *
	 * @param string $serial         Serial.
	 * @param int    $to_product_id  Product or variation it should be under.
	 * @param array  $context        Optional. `reason`, `event`, `run_id`,
	 *                               and anything else the caller wants on the
	 *                               history line.
	 * @return true|WP_Error
	 */
	public static function change_product( $serial, $to_product_id, array $context = array() ) {

		global $wpdb;

		$serial = Dahu_Serial_Numbers_Format::normalise( $serial );
		$row    = self::get( $serial );

		if ( ! $row ) {
			return new WP_Error(
				'dahu_serial_numbers_not_found',
				sprintf(
					/* translators: %s: serial number. */
					__( 'Unknown serial number: %s', 'dahu-serial-numbers' ),
					$serial
				)
			);
		}

		$requested = (int) $to_product_id;
		$to        = $requested > 0 ? Dahu_Serial_Numbers_Products::resolve( $requested ) : 0;

		if ( $to <= 0 ) {
			return new WP_Error(
				'dahu_serial_numbers_invalid_product',
				__( 'A destination product is required.', 'dahu-serial-numbers' )
			);
		}

		$from = (int) $row['product_id'];

		if ( $from <= 0 ) {
			return new WP_Error(
				'dahu_serial_numbers_no_product',
				sprintf(
					/* translators: %s: serial number. */
					__( 'Serial number %s is filed under no product, so there is nothing to correct.', 'dahu-serial-numbers' ),
					$serial
				)
			);
		}

		if ( $from === $to ) {
			return new WP_Error(
				'dahu_serial_numbers_same_product',
				sprintf(
					/* translators: %s: serial number. */
					__( 'Serial number %s is already filed under that product.', 'dahu-serial-numbers' ),
					$serial
				)
			);
		}

		if ( Dahu_Serial_Numbers_Statuses::IN_STOCK !== (string) $row['status'] ) {
			return new WP_Error(
				'dahu_serial_numbers_not_in_stock',
				sprintf(
					/* translators: 1: serial number, 2: status label. */
					__( 'Serial number %1$s is “%2$s”, so its product is what an order or a case says it is. Only a unit in stock can be re-filed.', 'dahu-serial-numbers' ),
					$serial,
					Dahu_Serial_Numbers_Statuses::label( (string) $row['status'] )
				)
			);
		}

		if ( ! empty( $row['order_id'] ) || ! empty( $row['order_item_id'] ) ) {
			return new WP_Error(
				'dahu_serial_numbers_attached',
				sprintf(
					/* translators: 1: serial number, 2: order number. */
					__( 'Serial number %1$s still carries order #%2$d. Detach it before re-filing it.', 'dahu-serial-numbers' ),
					$serial,
					(int) $row['order_id']
				)
			);
		}

		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
			Dahu_Serial_Numbers_Install::serials_table(),
			array(
				'product_id' => $to,
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array(
				'serial'     => $serial,
				// Part of the WHERE on purpose: two screens correcting the
				// same unit at the same second would otherwise both report
				// success while one of them wrote over the other. The second
				// one finds no row and says so.
				'product_id' => $from,
				'status'     => Dahu_Serial_Numbers_Statuses::IN_STOCK,
			)
		);

		if ( ! $updated ) {
			return new WP_Error(
				'dahu_serial_numbers_update_failed',
				sprintf(
					/* translators: %s: serial number. */
					__( 'Serial number %s could not be re-filed — it changed while this was being prepared.', 'dahu-serial-numbers' ),
					$serial
				)
			);
		}

		$log_context = $context;
		unset( $log_context['event'] );

		$log_context['from_product_id'] = $from;
		$log_context['to_product_id']   = $to;

		if ( $requested !== $to ) {
			$log_context['requested_product_id'] = $requested;
		}

		$logged = Dahu_Serial_Numbers_Log::record(
			$serial,
			isset( $context['event'] ) ? (string) $context['event'] : 'product_change',
			array(
				// No status, on purpose: nothing happened to the unit. A
				// reader scanning for moves must not find one here.
				'order_id' => null,
				'context'  => $log_context,
			)
		);

		// A correction with no history line is the one outcome this plugin
		// must never produce: the column would say one thing and nothing
		// anywhere would say why, the batch that made it could never be put
		// back — an undo reads the last line to know whether it may — and the
		// history, which is the whole product, would lie by omission. So the
		// column goes back and the caller is told. Inside a batch the
		// transaction covers this anyway; a lone call has no transaction, and
		// this is what it has instead. Found by an adversarial review.
		if ( is_wp_error( $logged ) ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
				Dahu_Serial_Numbers_Install::serials_table(),
				array( 'product_id' => $from ),
				array(
					'serial'     => $serial,
					'product_id' => $to,
				)
			);

			return new WP_Error(
				'dahu_serial_numbers_log_failed',
				sprintf(
					/* translators: %s: serial number. */
					__( 'Serial number %s was left where it was: its history line could not be written, and a correction with no history is worse than none.', 'dahu-serial-numbers' ),
					$serial
				)
			);
		}

		/**
		 * Fires once a unit has been re-filed under another product.
		 *
		 * Both counts moved, which is why the stock push listens to this
		 * rather than to the transition hook — there was no transition.
		 *
		 * Not fired when the caller passes `defer_action`: a batch runs in a
		 * transaction, and a listener told about a change inside it may act
		 * on data that is about to be rolled back — or commit the
		 * transaction from under the batch. The batch fires them itself once
		 * the write is committed.
		 *
		 * @param string $serial  Normalised serial.
		 * @param int    $from    Product it was under.
		 * @param int    $to      Product it is under now.
		 * @param array  $context Caller context.
		 */
		if ( empty( $context['defer_action'] ) ) {
			do_action( 'dahu_serial_numbers_product_changed', $serial, $from, $to, $context );
		}

		return true;
	}

	/**
	 * Records that somebody physically had the unit in their hands.
	 *
	 * Touches no status. A stock count is not a lifecycle event, and making
	 * it one would mean a warehouse audit rewrote the history of every unit
	 * it looked at.
	 *
	 * @param string $serial Serial.
	 * @return true|WP_Error
	 */
	public static function mark_checked( $serial ) {

		global $wpdb;

		$serial = Dahu_Serial_Numbers_Format::normalise( $serial );

		if ( ! self::exists( $serial ) ) {
			return new WP_Error(
				'dahu_serial_numbers_not_found',
				sprintf(
					/* translators: %s: serial number. */
					__( 'Unknown serial number: %s', 'dahu-serial-numbers' ),
					$serial
				)
			);
		}

		$now = gmdate( 'Y-m-d H:i:s' );

		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
			Dahu_Serial_Numbers_Install::serials_table(),
			array(
				'checked_at' => $now,
				'updated_at' => $now,
			),
			array( 'serial' => $serial )
		);

		if ( false === $updated ) {
			return new WP_Error(
				'dahu_serial_numbers_update_failed',
				sprintf(
					/* translators: %s: serial number. */
					__( 'The check on serial number %s could not be recorded.', 'dahu-serial-numbers' ),
					$serial
				)
			);
		}

		Dahu_Serial_Numbers_Log::record( $serial, 'checked', array( 'context' => array( 'checked_at' => $now ) ) );

		return true;
	}

	/**
	 * Attaches a serial to an order without moving its status.
	 *
	 * Refuses when the unit is already on an order. Overwriting would be the
	 * convenient behaviour and it is the wrong one: the second attachment is
	 * almost always a mistake — a number typed on the wrong screen — and
	 * silently replacing the first makes the earlier order lose a unit it
	 * shipped.
	 *
	 * @param string $serial        Serial.
	 * @param int    $order_id      Order id.
	 * @param int    $order_item_id Optional. Order line.
	 * @return true|WP_Error
	 */
	public static function attach_order( $serial, $order_id, $order_item_id = 0 ) {

		global $wpdb;

		$serial        = Dahu_Serial_Numbers_Format::normalise( $serial );
		$order_id      = (int) $order_id;
		$order_item_id = (int) $order_item_id;

		if ( '' === $serial || $order_id <= 0 ) {
			return new WP_Error(
				'dahu_serial_numbers_invalid_attach',
				__( 'A serial number and an order are both required.', 'dahu-serial-numbers' )
			);
		}

		$row = self::get( $serial );

		if ( ! $row ) {
			return new WP_Error(
				'dahu_serial_numbers_not_found',
				sprintf(
					/* translators: %s: serial number. */
					__( 'Unknown serial number: %s', 'dahu-serial-numbers' ),
					$serial
				)
			);
		}

		if ( ! empty( $row['order_id'] ) ) {
			return new WP_Error(
				'dahu_serial_numbers_already_attached',
				sprintf(
					/* translators: %d: order number. */
					__( 'Already attached to order #%d — left as it is.', 'dahu-serial-numbers' ),
					(int) $row['order_id']
				),
				array( 'existing_order_id' => (int) $row['order_id'] )
			);
		}

		$update = array(
			'order_id'   => $order_id,
			'updated_at' => gmdate( 'Y-m-d H:i:s' ),
		);

		if ( $order_item_id > 0 ) {
			$update['order_item_id'] = $order_item_id;
		}

		$updated = $wpdb->update( Dahu_Serial_Numbers_Install::serials_table(), $update, array( 'serial' => $serial ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.

		if ( false === $updated ) {
			return new WP_Error(
				'dahu_serial_numbers_update_failed',
				sprintf(
					/* translators: %s: serial number. */
					__( 'Serial number %s could not be attached.', 'dahu-serial-numbers' ),
					$serial
				)
			);
		}

		Dahu_Serial_Numbers_Log::record(
			$serial,
			'attach_order',
			array(
				'order_id' => $order_id,
				'context'  => array( 'order_item_id' => $order_item_id ? $order_item_id : null ),
			)
		);

		return true;
	}

	/**
	 * The order line a product belongs to, when there is exactly one.
	 *
	 * Returns 0 for none and 0 for several, and the second case is the point:
	 * an order with two lines of the same product gives no way to tell which
	 * one a unit belongs to, and a plugin that picked the first would be
	 * right about half the time while looking certain. Nothing here guesses.
	 *
	 * @param int $order_id   Order id.
	 * @param int $product_id Product or variation id.
	 * @return int Order line id, or 0.
	 */
	public static function resolve_order_item( $order_id, $product_id ) {

		$order_id   = (int) $order_id;
		$product_id = (int) $product_id;

		if ( $order_id <= 0 || $product_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return 0;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return 0;
		}

		$matches = array();

		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$line_product_id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();

			if ( (int) $line_product_id === $product_id ) {
				$matches[] = (int) $item_id;
			}
		}

		return 1 === count( $matches ) ? $matches[0] : 0;
	}
}
