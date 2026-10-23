<?php
/**
 * Re-filing units that went on the shelf under the wrong product.
 *
 * The mistake is mundane. Two references differ by a connector, or by a brake
 * standard, or by nothing a human eye catches on a label; a box of forty goes
 * in under the wrong one. From that minute both products count wrong, and the
 * shop finds out when a customer receives the other part.
 *
 * What makes this worth a screen of its own rather than a column somebody can
 * edit is that it is a correction of the record, applied to many units at
 * once, and that half of it is worse than none: twenty units moved and twenty
 * left behind is a state nobody can reason about afterwards. So:
 *
 * - **A dry run first.** The same code path as the real thing, reported and
 *   not written. The summary shows both products' counts before and after.
 * - **All or nothing.** One refusal anywhere refuses the whole list, and the
 *   write runs in a transaction.
 * - **A fingerprint.** What is applied is what was shown. If anything moved in
 *   between — somebody assigned one of those units to an order while the
 *   summary was on screen — the run is refused and redrawn.
 * - **Undoable.** Each run is kept, and can be put back, unit by unit, as long
 *   as nothing else has happened to the unit since.
 * - **Off at installation.** The dry run still works with the switch off: it
 *   writes nothing, and reading what would happen is how somebody decides
 *   whether to turn it on.
 *
 * @package DahuSerialNumbers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Batch product corrections on units in stock.
 */
class Dahu_Serial_Numbers_Product_Fix {

	/**
	 * Option holding the runs, newest first.
	 */
	const OPTION_RUNS = 'dahu_serial_numbers_product_fix_runs';

	/**
	 * How many runs are kept and can be undone.
	 */
	const RUN_LIMIT = 50;

	/**
	 * History event for a correction.
	 */
	const EVENT = 'product_fix';

	/**
	 * History event for an undone correction.
	 */
	const EVENT_UNDO = 'product_fix_undo';

	/**
	 * Is writing switched on?
	 *
	 * @return bool
	 */
	public static function enabled() {

		return (bool) Dahu_Serial_Numbers_Settings::get( 'product_fix_enabled' );
	}

	/* ---------------------------------------------------------------------
	 * Input
	 * ------------------------------------------------------------------ */

	/**
	 * Serial numbers out of a pasted list.
	 *
	 * People paste what they have: a column out of a spreadsheet, a line of
	 * scans separated by spaces, a comma-separated list out of an email.
	 * Duplicates are folded, because a list pasted twice is one list.
	 *
	 * @param string $raw Raw input.
	 * @return string[] Normalised serials, in the order they were given.
	 */
	public static function parse( $raw ) {

		$raw    = str_replace( array( "\r\n", "\r", "\n", "\t", ',', ';' ), ' ', (string) $raw );
		$chunks = explode( ' ', $raw );
		$out    = array();

		foreach ( $chunks as $chunk ) {
			$serial = Dahu_Serial_Numbers_Format::normalise( $chunk );

			if ( '' !== $serial && ! in_array( $serial, $out, true ) ) {
				$out[] = $serial;
			}
		}

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * The plan
	 * ------------------------------------------------------------------ */

	/**
	 * Why a destination product cannot receive units, if it cannot.
	 *
	 * @param int $product_id Destination product or variation.
	 * @return string Empty when it can.
	 */
	public static function destination_refusal( $product_id ) {

		$product_id = (int) $product_id;

		if ( $product_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return 'destination_unknown';
		}

		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return 'destination_unknown';
		}

		// A variable product is a container, and a unit filed against the
		// container belongs to no variation: every count of every variation
		// stays wrong and the screens show a part nobody sells. This is the
		// refusal that matters most, because the dropdown of a shop with
		// variations is exactly where somebody picks the parent by mistake.
		if ( $product->is_type( 'variable' ) ) {
			return 'destination_variable';
		}

		if ( ! Dahu_Serial_Numbers_Products::is_serialised( $product_id ) ) {
			return 'destination_not_serialised';
		}

		return '';
	}

	/**
	 * A refusal, in words.
	 *
	 * @param string $code Refusal code.
	 * @return string
	 */
	public static function refusal_label( $code ) {

		$labels = array(
			'destination_unknown'        => __( 'No such destination product.', 'dahu-serial-numbers' ),
			'destination_variable'       => __( 'The destination is a variable product: pick one of its variations.', 'dahu-serial-numbers' ),
			'destination_not_serialised' => __( 'The destination product is not marked as carrying serial numbers.', 'dahu-serial-numbers' ),
			'no_reason'                  => __( 'A reason is required: it is what the history line will say.', 'dahu-serial-numbers' ),
			'no_serial'                  => __( 'No serial number was given.', 'dahu-serial-numbers' ),
			'unknown'                    => __( 'Unknown serial number.', 'dahu-serial-numbers' ),
			'not_in_stock'               => __( 'Not in stock, and on no order line this could be squared against: its product is what an order or a case says it is.', 'dahu-serial-numbers' ),
			'not_in_stock_elsewhere'     => __( 'Not in stock: the only product it can be re-filed under is the one its own order line names.', 'dahu-serial-numbers' ),
			'attached'                   => __( 'Still carries an order.', 'dahu-serial-numbers' ),
			'no_product'                 => __( 'Filed under no product, so there is nothing to correct.', 'dahu-serial-numbers' ),
			'same_product'               => __( 'Already filed under that product.', 'dahu-serial-numbers' ),
		);

		return isset( $labels[ $code ] ) ? $labels[ $code ] : (string) $code;
	}

	/**
	 * What would happen, without writing anything.
	 *
	 * @param string[] $serials       Serial numbers.
	 * @param int      $to_product_id Destination product or variation.
	 * @param string   $reason        Why.
	 * @return array<string,mixed>
	 */
	public static function plan( array $serials, $to_product_id, $reason ) {

		$reason    = trim( (string) $reason );
		$requested = (int) $to_product_id;
		$to        = $requested > 0 ? Dahu_Serial_Numbers_Products::resolve( $requested ) : 0;

		$plan = array(
			'to'          => $to,
			'to_name'     => '',
			'reason'      => $reason,
			'rows'        => array(),
			'refusals'    => array(),
			'movable'     => 0,
			'counts'      => array(),
			'fingerprint' => '',
		);

		$destination = self::destination_refusal( $to );

		if ( '' !== $destination ) {
			$plan['refusals'][] = $destination;
		} elseif ( function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $to );

			if ( $product ) {
				$plan['to_name'] = $product->get_name();
			}
		}

		if ( '' === $reason ) {
			$plan['refusals'][] = 'no_reason';
		}

		if ( ! $serials ) {
			$plan['refusals'][] = 'no_serial';
		}

		foreach ( $serials as $serial ) {
			$row     = Dahu_Serial_Numbers_Manager::get( $serial );
			$refusal = '';
			$from    = 0;

			$converge = 0;

			if ( ! $row ) {
				$refusal = 'unknown';
			} else {
				$from = (int) $row['product_id'];

				if ( Dahu_Serial_Numbers_Statuses::IN_STOCK !== (string) $row['status'] ) {
					// A unit that left stock, filed under one product while
					// the order line it went out on names another. That is
					// not a judgement call: the line is the authority, and
					// squaring the unit with it is the one correction a unit
					// out of stock may receive here. The line is never
					// touched, and no stock count moves -- the unit was not
					// counted in either product's free stock to begin with.
					$allowed = Dahu_Serial_Numbers_Manager::order_line_products( $row );

					if ( ! $allowed ) {
						$refusal = 'not_in_stock';
					} elseif ( ! in_array( $to, $allowed, true ) ) {
						$refusal = 'not_in_stock_elsewhere';
					} elseif ( $from <= 0 ) {
						$refusal = 'no_product';
					} elseif ( $from === $to ) {
						$refusal = 'same_product';
					} else {
						$converge = (int) $row['order_item_id'];
					}
				} elseif ( ! empty( $row['order_id'] ) || ! empty( $row['order_item_id'] ) ) {
					$refusal = 'attached';
				} elseif ( $from <= 0 ) {
					$refusal = 'no_product';
				} elseif ( $from === $to ) {
					$refusal = 'same_product';
				}
			}

			if ( '' !== $refusal ) {
				$plan['refusals'][] = $refusal;
			} else {
				++$plan['movable'];
			}

			$plan['rows'][] = array(
				'serial'    => $serial,
				'from'      => $from,
				'from_name' => self::product_name( $from ),
				'status'    => $row ? (string) $row['status'] : '',
				'refusal'   => $refusal,
				'converge'  => $converge,
				'note'      => $converge > 0 ? sprintf(
					/* translators: 1: order number, 2: order line id. */
					__( 'Squared with order #%1$d, line %2$d. The order line is not changed, and no stock count moves.', 'dahu-serial-numbers' ),
					(int) $row['order_id'],
					$converge
				) : '',
			);
		}

		$plan['refusals']    = array_values( array_unique( $plan['refusals'] ) );
		$plan['counts']      = self::counts( $plan['rows'], $to );
		$plan['fingerprint'] = self::fingerprint( $plan );

		return $plan;
	}

	/**
	 * Units in stock per product, before and after the plan.
	 *
	 * The number somebody checks the plan against: forty units leaving one
	 * product and arriving at another is easy to read, and easy to recognise
	 * as wrong when the arithmetic does not match what was expected.
	 *
	 * @param array $rows Plan rows.
	 * @param int   $to   Destination product.
	 * @return array<int,array<string,mixed>>
	 */
	private static function counts( array $rows, $to ) {

		$free    = Dahu_Serial_Numbers_Stock::free_map();
		$moving  = array();
		$touched = array();

		foreach ( $rows as $row ) {
			// A converged unit is out of stock: it is in neither product's
			// free count, so moving it moves no count. Counting it here would
			// show the shop an arrival that never happens.
			if ( '' !== $row['refusal'] || $row['from'] <= 0 || ! empty( $row['converge'] ) ) {
				continue;
			}

			$moving[ $row['from'] ] = ( isset( $moving[ $row['from'] ] ) ? $moving[ $row['from'] ] : 0 ) + 1;
			$touched[]              = (int) $row['from'];
		}

		$touched[] = (int) $to;
		$touched   = array_values( array_unique( array_filter( $touched ) ) );
		sort( $touched );

		$out     = array();
		$arrived = array_sum( $moving );

		foreach ( $touched as $product_id ) {
			$before = isset( $free[ $product_id ] ) ? (int) $free[ $product_id ] : 0;
			$change = isset( $moving[ $product_id ] ) ? -(int) $moving[ $product_id ] : 0;

			if ( (int) $to === $product_id ) {
				$change += $arrived;
			}

			$out[] = array(
				'product_id' => $product_id,
				'name'       => self::product_name( $product_id ),
				'before'     => $before,
				'after'      => $before + $change,
			);
		}

		return $out;
	}

	/**
	 * A product's name, or its id when it has none left.
	 *
	 * @param int $product_id Product id.
	 * @return string
	 */
	public static function product_name( $product_id ) {

		$product_id = (int) $product_id;

		if ( $product_id <= 0 ) {
			return '';
		}

		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;

		return $product ? $product->get_name() : sprintf(
			/* translators: %d: product id. */
			__( 'Product #%d (gone)', 'dahu-serial-numbers' ),
			$product_id
		);
	}

	/**
	 * A fingerprint of a plan.
	 *
	 * Covers what each unit is now, not only what it would become: the point
	 * is to catch a unit that moved while the summary was on screen.
	 *
	 * @param array $plan Plan.
	 * @return string
	 */
	public static function fingerprint( array $plan ) {

		$parts = array( 'to:' . (int) $plan['to'] );

		foreach ( $plan['rows'] as $row ) {
			$parts[] = implode( '|', array( $row['serial'], (int) $row['from'], (string) $row['status'], (string) $row['refusal'], (int) ( isset( $row['converge'] ) ? $row['converge'] : 0 ) ) );
		}

		return md5( implode( "\n", $parts ) );
	}

	/* ---------------------------------------------------------------------
	 * The write
	 * ------------------------------------------------------------------ */

	/**
	 * Applies a plan, all of it or none of it.
	 *
	 * @param string[] $serials       Serial numbers.
	 * @param int      $to_product_id Destination product or variation.
	 * @param string   $reason        Why.
	 * @param string   $fingerprint   Fingerprint of the plan that was shown.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function apply( array $serials, $to_product_id, $reason, $fingerprint ) {

		global $wpdb;

		if ( ! self::enabled() ) {
			return new WP_Error(
				'dahu_serial_numbers_product_fix_off',
				__( 'Product corrections are switched off. The Settings screen turns them on.', 'dahu-serial-numbers' )
			);
		}

		$plan = self::plan( $serials, $to_product_id, $reason );

		if ( (string) $fingerprint !== $plan['fingerprint'] ) {
			return new WP_Error(
				'dahu_serial_numbers_product_fix_moved',
				__( 'Something moved since this summary was drawn up. Here it is again — read it and run it once more.', 'dahu-serial-numbers' ),
				array( 'plan' => $plan )
			);
		}

		if ( $plan['refusals'] ) {
			return new WP_Error(
				'dahu_serial_numbers_product_fix_refused',
				__( 'Nothing was changed: the list has at least one unit that cannot be re-filed.', 'dahu-serial-numbers' ),
				array( 'plan' => $plan )
			);
		}

		$run_id = self::run_id();
		$author = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$moved  = array();

		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		foreach ( $plan['rows'] as $row ) {
			$done = Dahu_Serial_Numbers_Manager::change_product(
				$row['serial'],
				$plan['to'],
				array(
					'event'        => self::EVENT,
					'reason'       => $plan['reason'],
					'run_id'       => $run_id,
					'author'       => $author,
					// Inside the transaction nobody is told anything. A
					// listener that acts on a change about to be rolled back
					// acts on a fact that never happened, and one that writes
					// — WooCommerce stock, a post, its own COMMIT — can
					// commit half of this batch from under it. They are all
					// fired below, once the write is real.
					'defer_action' => true,
				) + ( empty( $row['converge'] ) ? array() : array( 'converge_order_item_id' => (int) $row['converge'] ) )
			);

			if ( is_wp_error( $done ) ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

				return new WP_Error(
					'dahu_serial_numbers_product_fix_failed',
					sprintf(
						/* translators: 1: serial number, 2: error message. */
						__( 'Nothing was changed. Serial number %1$s could not be re-filed: %2$s', 'dahu-serial-numbers' ),
						$row['serial'],
						$done->get_error_message()
					)
				);
			}

			$moved[] = array(
				'serial'   => $row['serial'],
				'from'     => (int) $row['from'],
				'converge' => (int) ( empty( $row['converge'] ) ? 0 : $row['converge'] ),
			);
		}

		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		// Now, and only now, the rest of the world hears about it.
		foreach ( $moved as $entry ) {
			do_action(
				'dahu_serial_numbers_product_changed',
				$entry['serial'],
				(int) $entry['from'],
				(int) $plan['to'],
				array(
					'event'  => self::EVENT,
					'reason' => $plan['reason'],
					'run_id' => $run_id,
				)
			);
		}

		self::remember(
			array(
				'run_id' => $run_id,
				'at'     => gmdate( 'Y-m-d H:i:s' ),
				'to'     => (int) $plan['to'],
				'reason' => $plan['reason'],
				'author' => $author,
				'moved'  => $moved,
			)
		);

		return array(
			'run_id' => $run_id,
			'moved'  => $moved,
			'to'     => (int) $plan['to'],
		);
	}

	/* ---------------------------------------------------------------------
	 * Undo
	 * ------------------------------------------------------------------ */

	/**
	 * Puts a run back, unit by unit.
	 *
	 * A unit is only put back when the run is still the last thing that
	 * happened to it. Anything else — it was assigned, it was re-filed again,
	 * somebody already undid this — and the unit is skipped and named. Forcing
	 * it would mean undoing a decision somebody made after this one, which is
	 * the one thing an undo must never do.
	 *
	 * Not a transaction, deliberately: a run where three of forty units are
	 * skipped must still put the thirty-seven back. Each unit is its own
	 * correction and reads as one in the history.
	 *
	 * @param string $run_id Run id.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function undo( $run_id ) {

		$run_id = (string) $run_id;
		$run    = self::run( $run_id );

		if ( ! $run ) {
			return new WP_Error(
				'dahu_serial_numbers_product_fix_no_run',
				__( 'No such run — it may have aged out of the list.', 'dahu-serial-numbers' )
			);
		}

		if ( ! empty( $run['undone_at'] ) ) {
			return new WP_Error(
				'dahu_serial_numbers_product_fix_undone',
				__( 'This run has already been put back.', 'dahu-serial-numbers' )
			);
		}

		$put_back = array();
		$skipped  = array();

		foreach ( (array) $run['moved'] as $entry ) {
			$serial = (string) $entry['serial'];
			$back   = (int) $entry['from'];
			$row    = Dahu_Serial_Numbers_Manager::get( $serial );

			if ( ! $row ) {
				$skipped[] = array(
					'serial' => $serial,
					'why'    => 'unknown',
				);
				continue;
			}

			$converge = isset( $entry['converge'] ) ? (int) $entry['converge'] : 0;

			// A converged unit is out of stock and still carries its line:
			// asking it to be in stock and detached, as an ordinary unit must
			// be, would retire its undo for ever. What is asked of it instead
			// is that nothing else has happened -- same product, same line,
			// and this run still the last word on it.
			$undisturbed = $converge > 0
				? (int) $row['order_item_id'] === $converge
				: ( Dahu_Serial_Numbers_Statuses::IN_STOCK === (string) $row['status']
					&& empty( $row['order_id'] )
					&& empty( $row['order_item_id'] ) );

			if ( (int) $row['product_id'] !== (int) $run['to']
				|| ! $undisturbed
				|| ! self::run_is_last( $serial, $run_id ) ) {

				$skipped[] = array(
					'serial' => $serial,
					'why'    => 'moved_since',
				);
				continue;
			}

			// The product it would go back to has to be a product units may
			// be filed under TODAY, and not only on the day of the run. A
			// reference turned into a variable product, or stripped of its
			// serialised flag, or deleted outright: putting units back there
			// would file them where the outward journey refuses to, which is
			// a state the plugin would then have created itself. Found by an
			// adversarial review.
			$refusal = self::destination_refusal( $back );

			if ( '' !== $refusal ) {
				$skipped[] = array(
					'serial' => $serial,
					'why'    => 'destination_refused',
					'detail' => self::refusal_label( $refusal ),
				);
				continue;
			}

			$done = Dahu_Serial_Numbers_Manager::change_product(
				$serial,
				$back,
				array(
					'event'  => self::EVENT_UNDO,
					'reason' => sprintf(
						/* translators: %s: run id. */
						__( 'Correction %s put back', 'dahu-serial-numbers' ),
						$run_id
					),
					'run_id' => $run_id,
					'author' => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
				) + ( $converge > 0 ? array(
					'converge_order_item_id' => $converge,
					'converge_revert'        => true,
				) : array() )
			);

			if ( is_wp_error( $done ) ) {
				$skipped[] = array(
					'serial' => $serial,
					'why'    => 'refused',
					'detail' => $done->get_error_message(),
				);
				continue;
			}

			$put_back[] = $serial;
		}

		// Only a run that actually put something back is marked as undone. A
		// run of forty units whose units are all momentarily on an order puts
		// nothing back; marking it would retire the button and answer
		// "already put back" for ever, losing the undo on a run that is still
		// entirely in place. Found by an adversarial review.
		if ( $put_back ) {
			self::mark_undone( $run_id, count( $put_back ), count( $skipped ) );
		}

		return array(
			'put_back' => $put_back,
			'skipped'  => $skipped,
			'retried'  => ! $put_back,
		);
	}

	/**
	 * Is this run still the last thing that happened to this unit?
	 *
	 * @param string $serial Serial.
	 * @param string $run_id Run id.
	 * @return bool
	 */
	private static function run_is_last( $serial, $run_id ) {

		global $wpdb;

		$table = Dahu_Serial_Numbers_Install::log_table();

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
			$wpdb->prepare(
				"SELECT event, context FROM {$table} WHERE serial = %s ORDER BY id DESC LIMIT 1",
				(string) $serial
			),
			ARRAY_A
		);

		if ( ! $row || self::EVENT !== (string) $row['event'] ) {
			return false;
		}

		$context = json_decode( (string) $row['context'], true );

		return is_array( $context )
			&& isset( $context['run_id'] )
			&& (string) $context['run_id'] === (string) $run_id;
	}

	/* ---------------------------------------------------------------------
	 * Runs
	 * ------------------------------------------------------------------ */

	/**
	 * A new run id.
	 *
	 * Readable on purpose: it goes in a history line a human will read back
	 * months later, next to the reason they typed.
	 *
	 * @return string
	 */
	private static function run_id() {

		return 'pf-' . gmdate( 'Ymd-His' ) . '-' . substr( md5( uniqid( '', true ) ), 0, 6 );
	}

	/**
	 * The runs on file, newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function runs() {

		$runs = get_option( self::OPTION_RUNS, array() );

		return is_array( $runs ) ? $runs : array();
	}

	/**
	 * One run.
	 *
	 * @param string $run_id Run id.
	 * @return array<string,mixed>|null
	 */
	public static function run( $run_id ) {

		foreach ( self::runs() as $run ) {
			if ( isset( $run['run_id'] ) && (string) $run['run_id'] === (string) $run_id ) {
				return $run;
			}
		}

		return null;
	}

	/**
	 * Files a run.
	 *
	 * @param array $run Run.
	 * @return void
	 */
	private static function remember( array $run ) {

		$runs = self::runs();

		array_unshift( $runs, $run );

		update_option( self::OPTION_RUNS, array_slice( $runs, 0, self::RUN_LIMIT ), false );
	}

	/**
	 * Marks a run as put back.
	 *
	 * @param string $run_id   Run id.
	 * @param int    $put_back How many units went back.
	 * @param int    $skipped  How many were left alone.
	 * @return void
	 */
	private static function mark_undone( $run_id, $put_back, $skipped ) {

		$runs = self::runs();

		foreach ( $runs as $index => $run ) {
			if ( isset( $run['run_id'] ) && (string) $run['run_id'] === (string) $run_id ) {
				$runs[ $index ]['undone_at']       = gmdate( 'Y-m-d H:i:s' );
				$runs[ $index ]['undone_put_back'] = (int) $put_back;
				$runs[ $index ]['undone_skipped']  = (int) $skipped;
				break;
			}
		}

		update_option( self::OPTION_RUNS, $runs, false );
	}
}
