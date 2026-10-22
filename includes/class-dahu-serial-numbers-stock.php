<?php
/**
 * WooCommerce stock, told the truth by the units actually on the shelf.
 *
 * A shop that serialises knows exactly how many units it holds: it holds one
 * row per unit. WooCommerce, meanwhile, holds a number that drifts — a refund
 * processed by hand, a cancelled order restocked twice, a unit written off in
 * the workshop and nowhere else. After a few months the two disagree, and the
 * number customers buy against is the one that is wrong.
 *
 * This class writes the count back, absolutely rather than by increment:
 * `wc_update_product_stock( $product, $value, 'set' )`. An increment has to be
 * right every single time to stay right; a set is right as soon as it runs.
 *
 * ## What gets written
 *
 *     free units in stock  −  pieces already reserved by an order
 *
 * A "free" unit is one whose status is `in_stock` and which is on no order. A
 * reserved piece is one WooCommerce has already deducted for an order being
 * prepared — `_reduced_stock` on the order line — but which nobody has put a
 * serial against yet. Both halves are needed, and leaving either out gives a
 * number that is wrong in a direction somebody will feel:
 *
 * - Drop the reservations and the units a customer has already paid for are
 *   offered for sale a second time, because they are still on the shelf and
 *   still `in_stock` until the workshop scans them.
 * - Count a reservation whose serials have already been assigned, and the
 *   same units are deducted twice: once by WooCommerce when the order came
 *   in, once by us because the line still carries `_reduced_stock`. So the
 *   serials already assigned or shipped against a line are subtracted from
 *   that line's reservation, never below zero.
 *
 * ## Off until somebody asks
 *
 * Writing to another plugin's stock column is not something to do to a shop
 * that installed this one to read histories. The setting is off at
 * installation, every trigger checks it, and the manual resynchronisation —
 * which a human asked for, on a screen, with a summary in front of them —
 * is the one path that still works when it is off, behind a second tick box.
 *
 * ## Never blocking
 *
 * Every push is wrapped: a product that refuses to save must not take the
 * import, the packing screen or the order that triggered it down with it. A
 * stock number is worth less than the operation that produced it.
 *
 * @package DahuSerialNumbers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pushes the serial count into WooCommerce stock.
 */
class Dahu_Serial_Numbers_Stock {

	/**
	 * Option holding the last pushes, newest first.
	 */
	const OPTION_HISTORY = 'dahu_serial_numbers_stock_history';

	/**
	 * How many pushes are kept.
	 *
	 * Enough to answer "what changed my stock this morning" on a busy day,
	 * and small enough that the option stays a few kilobytes.
	 */
	const HISTORY_LIMIT = 500;

	/**
	 * Products waiting to be pushed, as ids.
	 *
	 * A single order can move a dozen units of the same product, and each
	 * move would otherwise recount and rewrite the same product a dozen
	 * times. They are gathered here and pushed once each, on `shutdown`.
	 *
	 * @var array<int,string> Product id => origin that queued it.
	 */
	private static $queue = array();

	/**
	 * Whether the shutdown flush is hooked.
	 *
	 * @var bool
	 */
	private static $flush_hooked = false;

	/**
	 * Registers the triggers.
	 *
	 * @return void
	 */
	public static function init() {

		add_action( 'dahu_serial_numbers_transitioned', array( __CLASS__, 'on_transition' ), 10, 4 );
		add_action( 'dahu_serial_numbers_created', array( __CLASS__, 'on_created' ), 10, 2 );
		add_action( 'dahu_serial_numbers_product_changed', array( __CLASS__, 'on_product_changed' ), 10, 4 );

		/*
		 * NOT hooked: the serialised flag on a product.
		 *
		 * Pushing when somebody ticks the box looks obliging and is a trap. A
		 * shop part way through filing its stock has forty units on the shelf
		 * and three on file; ticking the box would write 3 and take the
		 * product out of sale, from a product-edit screen that showed no
		 * summary and asked for no confirmation. An adversarial review found
		 * this before anybody's stock did.
		 *
		 * The Stock screen is the answer: it names the gap for every product,
		 * writes nothing until asked, and the run is the same code path. A
		 * newly serialised product appears there with its WooCommerce number
		 * and ours side by side, which is exactly the decision the shop has
		 * to make.
		 */
	}

	/**
	 * Is the automatic push switched on?
	 *
	 * @return bool
	 */
	public static function enabled() {

		return (bool) Dahu_Serial_Numbers_Settings::get( 'stock_push_enabled' );
	}

	/* ---------------------------------------------------------------------
	 * Triggers
	 * ------------------------------------------------------------------ */

	/**
	 * A unit moved: push when the move crossed the stock line.
	 *
	 * Only `in_stock` matters. A unit going from `shipped` to
	 * `return_expected` changes nothing a customer can buy, and pushing on
	 * every move would rewrite the stock of every product all day for no
	 * reason.
	 *
	 * @param string $serial  Serial.
	 * @param string $to      New status.
	 * @param string $from    Previous status.
	 * @param array  $context Caller context.
	 * @return void
	 */
	public static function on_transition( $serial, $to, $from, $context = array() ) {

		unset( $context );

		if ( Dahu_Serial_Numbers_Statuses::IN_STOCK !== $to && Dahu_Serial_Numbers_Statuses::IN_STOCK !== $from ) {
			return;
		}

		$row = Dahu_Serial_Numbers_Manager::get( $serial );

		if ( $row && ! empty( $row['product_id'] ) ) {
			self::queue( (int) $row['product_id'], 'transition' );
		}
	}

	/**
	 * A unit was filed: push when it was filed as stock.
	 *
	 * @param string $serial Serial.
	 * @param array  $row    Row as inserted.
	 * @return void
	 */
	public static function on_created( $serial, $row ) {

		unset( $serial );

		if ( empty( $row['product_id'] ) || Dahu_Serial_Numbers_Statuses::IN_STOCK !== (string) $row['status'] ) {
			return;
		}

		self::queue( (int) $row['product_id'], 'create' );
	}

	/**
	 * A unit changed product: push both, because both counts moved.
	 *
	 * @param string $serial Serial.
	 * @param int    $from   Product it was filed under.
	 * @param int    $to     Product it is filed under now.
	 * @param array  $context Caller context.
	 * @return void
	 */
	public static function on_product_changed( $serial, $from, $to, $context = array() ) {

		unset( $serial, $context );

		self::queue( (int) $from, 'product_fix' );
		self::queue( (int) $to, 'product_fix' );
	}

	/* ---------------------------------------------------------------------
	 * Queue
	 * ------------------------------------------------------------------ */

	/**
	 * Remembers a product to push at the end of the request.
	 *
	 * @param int    $product_id Product or variation id.
	 * @param string $origin     What queued it, for the history line.
	 * @return void
	 */
	public static function queue( $product_id, $origin ) {

		$product_id = (int) $product_id;

		if ( $product_id <= 0 || ! self::enabled() ) {
			return;
		}

		if ( ! isset( self::$queue[ $product_id ] ) ) {
			self::$queue[ $product_id ] = (string) $origin;
		}

		if ( ! self::$flush_hooked ) {
			self::$flush_hooked = true;
			add_action( 'shutdown', array( __CLASS__, 'flush' ), 5 );
		}
	}

	/**
	 * Pushes everything queued, one product at a time.
	 *
	 * @return array<int,array<string,mixed>> What was pushed, keyed by product.
	 */
	public static function flush() {

		$done = array();

		if ( ! self::enabled() ) {
			self::$queue = array();

			return $done;
		}

		$queue       = self::$queue;
		self::$queue = array();

		foreach ( $queue as $product_id => $origin ) {
			// Each product in its own guard. `push()` catches what it can,
			// but it reads WooCommerce orders and a third-party filter on the
			// way, and an exception there used to abandon every product left
			// in the queue without a word. Found by an adversarial review.
			try {
				$done[ $product_id ] = self::push( $product_id, $origin );
			} catch ( Throwable $e ) { // phpcs:ignore PHPCompatibility.Classes.NewClasses.throwableFound -- guarded by Requires PHP 7.4.
				$done[ $product_id ] = array(
					'product_id' => (int) $product_id,
					'written'    => false,
					'error'      => $e->getMessage(),
				);
			}
		}

		return $done;
	}

	/**
	 * Whether anything is waiting.
	 *
	 * @return int[] Product ids.
	 */
	public static function queued() {

		return array_map( 'intval', array_keys( self::$queue ) );
	}

	/* ---------------------------------------------------------------------
	 * The count
	 * ------------------------------------------------------------------ */

	/**
	 * Order statuses whose lines hold stock back.
	 *
	 * Orders being prepared, and orders on hold. Not completed ones: a
	 * completed order has left the building, and WooCommerce keeps
	 * `_reduced_stock` on its lines for refunds — counting it would hold
	 * stock back forever.
	 *
	 * @return string[] Statuses without the `wc-` prefix.
	 */
	public static function reserving_statuses() {

		/**
		 * Filters which order statuses reserve stock.
		 *
		 * @param string[] $statuses Statuses, without the `wc-` prefix.
		 */
		$statuses = (array) apply_filters(
			'dahu_serial_numbers_stock_reserving_statuses',
			array( 'processing', 'on-hold' )
		);

		$clean = array();

		foreach ( $statuses as $status ) {
			$status = sanitize_key( (string) $status );
			$status = 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;

			if ( '' !== $status && ! in_array( $status, $clean, true ) ) {
				$clean[] = $status;
			}
		}

		return $clean;
	}

	/**
	 * How many pieces each product owes to orders being prepared.
	 *
	 * @return array<int,int> Product id => pieces reserved.
	 */
	public static function reserved_map() {

		$map      = array();
		$statuses = self::reserving_statuses();

		if ( ! $statuses || ! function_exists( 'wc_get_orders' ) ) {
			return $map;
		}

		$order_ids = wc_get_orders(
			array(
				'limit'  => -1,
				'status' => $statuses,
				'return' => 'ids',
			)
		);

		if ( ! $order_ids ) {
			return $map;
		}

		$held = self::held( $order_ids );

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				continue;
			}

			foreach ( $order->get_items() as $item_id => $item ) {
				if ( ! $item instanceof WC_Order_Item_Product ) {
					continue;
				}

				$reduced = (int) $item->get_meta( '_reduced_stock', true );

				if ( $reduced <= 0 ) {
					continue;
				}

				$product_id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
				$product_id = Dahu_Serial_Numbers_Products::resolve( (int) $product_id );

				if ( $product_id <= 0 ) {
					continue;
				}

				$key     = (int) $order_id . ':' . (int) $item_id . ':' . $product_id;
				$already = isset( $held['lines'][ $key ] ) ? (int) $held['lines'][ $key ] : 0;

				// Never below zero. A line can be over-assigned — four
				// serials against a line of three, which `transition()` does
				// not refuse — and a negative reservation would invent stock.
				$left = max( 0, $reduced - $already );

				if ( $left > 0 ) {
					$map[ $product_id ] = ( isset( $map[ $product_id ] ) ? (int) $map[ $product_id ] : 0 ) + $left;
				}
			}
		}

		// Units attached to an order but to no LINE are credited against the
		// product's total rather than against a line of it. An order with two
		// lines of the same product gives no way to tell which line such a
		// unit belongs to, and the first version of this picked the first
		// line — which, with a line of one and a line of three, lost two
		// credits to the floor above and held two pieces back from sale.
		// Found by an adversarial review. At the product level there is
		// nothing to guess: the unit is spoken for on that order, whichever
		// line carries it.
		foreach ( $held['loose'] as $key => $count ) {
			list( , $product_id ) = explode( ':', $key );

			$product_id = (int) $product_id;

			if ( isset( $map[ $product_id ] ) ) {
				$map[ $product_id ] = max( 0, (int) $map[ $product_id ] - (int) $count );
			}
		}

		/**
		 * Filters the reserved pieces per product.
		 *
		 * @param array<int,int> $map       Product id => pieces reserved.
		 * @param int[]          $order_ids Orders that were counted.
		 */
		return (array) apply_filters( 'dahu_serial_numbers_stock_reserved_map', $map, $order_ids );
	}

	/**
	 * Units already standing against the orders in the window.
	 *
	 * One query for every order rather than one per line. Returns two sets:
	 * `lines`, keyed `order:line:product`, and `loose`, keyed
	 * `order:product`, for the units that name an order and no line.
	 *
	 * Both are keyed by the RESOLVED product id, like `reserved_map()` and
	 * `free_map()`. They were not, and that was a defect worth stating: with
	 * a `dahu_serial_numbers_resolve_product` filter in place, the
	 * reservation landed on the resolved id while the units were counted
	 * under the raw one, so neither side ever met the other and units a
	 * customer had paid for went back on sale.
	 *
	 * @param int[] $order_ids Orders to look at.
	 * @return array{lines:array<string,int>,loose:array<string,int>}
	 */
	private static function held( array $order_ids ) {

		global $wpdb;

		$held      = array(
			'lines' => array(),
			'loose' => array(),
		);
		$order_ids = array_values( array_unique( array_map( 'intval', $order_ids ) ) );

		if ( ! $order_ids ) {
			return $held;
		}

		$table        = Dahu_Serial_Numbers_Install::serials_table();
		$in_orders    = implode( ',', array_fill( 0, count( $order_ids ), '%d' ) );
		$in_statuses  = implode( ',', array_fill( 0, 2, '%s' ) );
		$placeholders = array_merge(
			$order_ids,
			array( Dahu_Serial_Numbers_Statuses::ASSIGNED, Dahu_Serial_Numbers_Statuses::SHIPPED )
		);

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- own table, placeholders built above.
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders -- the lists are %d/%s placeholders.
				"SELECT order_id, order_item_id, product_id, COUNT(*) AS held
				 FROM {$table}
				 WHERE order_id IN ({$in_orders}) AND status IN ({$in_statuses})
				 GROUP BY order_id, order_item_id, product_id",
				$placeholders
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$order_id   = (int) $row['order_id'];
			$item_id    = (int) $row['order_item_id'];
			$product_id = Dahu_Serial_Numbers_Products::resolve( (int) $row['product_id'] );
			$count      = (int) $row['held'];

			if ( $product_id <= 0 ) {
				continue;
			}

			if ( $item_id > 0 ) {
				$key                     = $order_id . ':' . $item_id . ':' . $product_id;
				$held['lines'][ $key ]   = ( isset( $held['lines'][ $key ] ) ? $held['lines'][ $key ] : 0 ) + $count;
				continue;
			}

			$key                   = $order_id . ':' . $product_id;
			$held['loose'][ $key ] = ( isset( $held['loose'][ $key ] ) ? $held['loose'][ $key ] : 0 ) + $count;
		}

		return $held;
	}

	/**
	 * Free units in stock, per product.
	 *
	 * Free means: `in_stock`, and on no order. A unit `in_stock` that still
	 * carries an order is a contradiction the lifecycle normally prevents —
	 * going back to stock clears the order — and counting it would offer a
	 * unit for sale that somebody is looking for. It is left out, and the
	 * resynchronisation screen names the products where that happens.
	 *
	 * @return array<int,int> Product id => free units.
	 */
	public static function free_map() {

		global $wpdb;

		$table = Dahu_Serial_Numbers_Install::serials_table();

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
			$wpdb->prepare(
				"SELECT product_id, COUNT(*) AS free
				 FROM {$table}
				 WHERE status = %s
				   AND product_id IS NOT NULL
				   AND ( order_id IS NULL OR order_id = 0 )
				   AND ( order_item_id IS NULL OR order_item_id = 0 )
				 GROUP BY product_id",
				Dahu_Serial_Numbers_Statuses::IN_STOCK
			),
			ARRAY_A
		);

		return self::resolved_counts( $rows, 'free' );
	}

	/**
	 * Units `in_stock` that still carry an order, per product.
	 *
	 * Reported rather than fixed. Something put a unit back in stock without
	 * going through the lifecycle — a SQL edit, an import, another plugin —
	 * and a stock pusher is the wrong place to decide what that meant.
	 *
	 * @return array<int,int> Product id => units.
	 */
	public static function stuck_map() {

		global $wpdb;

		$table = Dahu_Serial_Numbers_Install::serials_table();

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
			$wpdb->prepare(
				"SELECT product_id, COUNT(*) AS stuck
				 FROM {$table}
				 WHERE status = %s
				   AND product_id IS NOT NULL
				   AND ( ( order_id IS NOT NULL AND order_id <> 0 ) OR ( order_item_id IS NOT NULL AND order_item_id <> 0 ) )
				 GROUP BY product_id",
				Dahu_Serial_Numbers_Statuses::IN_STOCK
			),
			ARRAY_A
		);

		return self::resolved_counts( $rows, 'stuck' );
	}

	/**
	 * Rows of `product_id` and a count, folded onto resolved product ids.
	 *
	 * The resolution cannot happen in SQL — it is a filter the shop owns —
	 * so it happens here, and it happens on every map so that all of them
	 * speak about the same products.
	 *
	 * @param array  $rows   Rows with `product_id` and the count column.
	 * @param string $column Name of the count column.
	 * @return array<int,int>
	 */
	private static function resolved_counts( $rows, $column ) {

		$map = array();

		foreach ( (array) $rows as $row ) {
			$product_id = Dahu_Serial_Numbers_Products::resolve( (int) $row['product_id'] );

			if ( $product_id <= 0 ) {
				continue;
			}

			$map[ $product_id ] = ( isset( $map[ $product_id ] ) ? (int) $map[ $product_id ] : 0 ) + (int) $row[ $column ];
		}

		return $map;
	}

	/* ---------------------------------------------------------------------
	 * Guards
	 * ------------------------------------------------------------------ */

	/**
	 * Why this product's stock must not be written, if it must not.
	 *
	 * @param int $product_id Product or variation id.
	 * @return string Empty when the product can be written.
	 */
	public static function refusal( $product_id ) {

		$product_id = (int) $product_id;

		if ( $product_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return 'unknown_product';
		}

		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return 'unknown_product';
		}

		// A variable product is a container. Its stock column exists but the
		// units are its variations', and writing the parent would hide the
		// variation numbers behind a total that means nothing.
		if ( $product->is_type( 'variable' ) ) {
			return 'variable_parent';
		}

		if ( ! $product->get_manage_stock() ) {
			// A variation whose stock is the parent's says so this way. Both
			// cases mean the same thing here: there is no number of ours to
			// write on this row.
			return 'stock_not_managed';
		}

		if ( ! Dahu_Serial_Numbers_Products::is_serialised( $product_id ) ) {
			return 'not_serialised';
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
			// A push that wrote nothing and refused nothing failed on the
			// way; the history row says so rather than showing an empty cell.
			''                   => __( 'Not written.', 'dahu-serial-numbers' ),
			'unknown_product'    => __( 'No such product.', 'dahu-serial-numbers' ),
			'variable_parent'    => __( 'Variable product: stock belongs to its variations.', 'dahu-serial-numbers' ),
			'stock_not_managed'  => __( 'WooCommerce stock management is off for this product.', 'dahu-serial-numbers' ),
			'not_serialised'     => __( 'Not marked as carrying serial numbers.', 'dahu-serial-numbers' ),
		);

		return isset( $labels[ $code ] ) ? $labels[ $code ] : (string) $code;
	}

	/* ---------------------------------------------------------------------
	 * The push
	 * ------------------------------------------------------------------ */

	/**
	 * What this product's stock should be, and what it is.
	 *
	 * @param int            $product_id Product or variation id.
	 * @param array<int,int> $free       Optional. Free map, to avoid recounting.
	 * @param array<int,int> $reserved   Optional. Reserved map, same reason.
	 * @return array<string,mixed>
	 */
	public static function plan_one( $product_id, array $free = null, array $reserved = null ) {

		$product_id = (int) $product_id;
		$free       = null === $free ? self::free_map() : $free;
		$reserved   = null === $reserved ? self::reserved_map() : $reserved;

		$in_stock      = isset( $free[ $product_id ] ) ? (int) $free[ $product_id ] : 0;
		$held_back     = isset( $reserved[ $product_id ] ) ? (int) $reserved[ $product_id ] : 0;
		$wanted        = $in_stock - $held_back;
		$refusal       = self::refusal( $product_id );
		$product       = '' === $refusal && function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		$before        = $product ? $product->get_stock_quantity() : null;
		$negative_gap  = $wanted < 0 ? abs( $wanted ) : 0;

		// Never written below zero. A negative target means the reservations
		// outrun the units on the shelf — an oversell, or serials nobody has
		// filed yet — and writing a negative stock would let WooCommerce
		// carry on selling against a backorder the shop never set up. The
		// shortfall is reported instead.
		$wanted = max( 0, $wanted );

		return array(
			'product_id' => $product_id,
			'name'       => $product ? $product->get_name() : '',
			'sku'        => $product ? $product->get_sku() : '',
			'in_stock'   => $in_stock,
			'reserved'   => $held_back,
			'wanted'     => $wanted,
			'before'     => null === $before ? null : (int) $before,
			'gap'        => $negative_gap,
			'refusal'    => $refusal,
			// A product whose stock column is NULL while WooCommerce manages
			// its stock counts as changing: `(int) null` is zero, so a
			// product that should be written zero was being left at NULL for
			// ever, which reads as "no stock figure" rather than "none left".
			'changes'    => '' === $refusal && ( null === $before || (int) $before !== (int) $wanted ),
		);
	}

	/**
	 * Writes one product's stock.
	 *
	 * Never throws: a product that cannot be saved is logged and the caller
	 * carries on. The operation that triggered the push — an import, a
	 * packing scan, an order — matters more than the number.
	 *
	 * @param int            $product_id Product or variation id.
	 * @param string         $origin     What asked for this push.
	 * @param array<int,int> $free       Optional. Free map.
	 * @param array<int,int> $reserved   Optional. Reserved map.
	 * @return array<string,mixed> The plan, plus `written`.
	 */
	public static function push( $product_id, $origin, array $free = null, array $reserved = null ) {

		try {
			$plan = self::plan_one( $product_id, $free, $reserved );
		} catch ( Throwable $e ) { // phpcs:ignore PHPCompatibility.Classes.NewClasses.throwableFound -- guarded by Requires PHP 7.4.
			// Counting is not safe either: it reads WooCommerce's orders and
			// passes through a filter the shop may own. "Never blocking" has
			// to cover the measurement, not only the write.
			return array(
				'product_id' => (int) $product_id,
				'origin'     => (string) $origin,
				'written'    => false,
				'error'      => $e->getMessage(),
			);
		}

		$plan['origin']  = (string) $origin;
		$plan['written'] = false;

		if ( '' !== $plan['refusal'] ) {
			self::remember( $plan );

			return $plan;
		}

		if ( ! $plan['changes'] ) {
			// Nothing to write, and nothing worth a history line either: a
			// history where nine lines in ten say "unchanged" is a history
			// nobody reads.
			return $plan;
		}

		try {
			$product = wc_get_product( $plan['product_id'] );

			if ( $product ) {
				wc_update_product_stock( $product, $plan['wanted'], 'set' );
				$plan['written'] = true;
			}
		} catch ( Throwable $e ) { // phpcs:ignore PHPCompatibility.Classes.NewClasses.throwableFound -- guarded by Requires PHP 7.4.
			$plan['error'] = $e->getMessage();

			// error_log, not a notice: this runs on shutdown, where there is
			// no screen left to put a message on.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					sprintf(
						'dahu-serial-numbers: stock push failed for product %d: %s',
						$plan['product_id'],
						$e->getMessage()
					)
				);
			}
		}

		self::remember( $plan );

		return $plan;
	}

	/* ---------------------------------------------------------------------
	 * Manual resynchronisation
	 * ------------------------------------------------------------------ */

	/**
	 * Every serialised product, and what its stock should be.
	 *
	 * @return array<string,mixed> `rows`, `changing`, `refused`, `fingerprint`.
	 */
	public static function resync_plan() {

		$free     = self::free_map();
		$reserved = self::reserved_map();
		$stuck    = self::stuck_map();

		// Every serialised product, and not only those that hold a unit or
		// owe one to an order. A product whose last unit has shipped has no
		// row in either map, and leaving it out meant the screen said
		// "nothing to change" while WooCommerce went on selling four
		// exemplars that no longer exist — which is precisely the drift this
		// screen exists to catch. Found by an adversarial review.
		$ids = array_values(
			array_unique(
				array_merge(
					array_keys( $free ),
					array_keys( $reserved ),
					self::serialised_product_ids()
				)
			)
		);
		sort( $ids );

		$rows     = array();
		$changing = 0;
		$refused  = 0;

		foreach ( $ids as $product_id ) {
			$row          = self::plan_one( $product_id, $free, $reserved );
			$row['stuck'] = isset( $stuck[ $product_id ] ) ? (int) $stuck[ $product_id ] : 0;

			if ( '' !== $row['refusal'] ) {
				++$refused;
			} elseif ( $row['changes'] ) {
				++$changing;
			}

			$rows[] = $row;
		}

		return array(
			'rows'        => $rows,
			'changing'    => $changing,
			'refused'     => $refused,
			'fingerprint' => self::fingerprint( $rows ),
			// The maps the rows were built from. The run writes from THESE,
			// never from a fresh count: the decision came from the figures
			// that were shown, so the value must come from them too. Before
			// this, `resync()` re-counted after comparing the fingerprint and
			// could write a number nobody had ever seen — the fingerprint
			// was protecting nothing at all.
			'free'        => $free,
			'reserved'    => $reserved,
		);
	}

	/**
	 * Every product marked as carrying serial numbers.
	 *
	 * Read from the meta rather than from `wc_get_products()`: a shop with
	 * four hundred serialised references does not need four hundred product
	 * objects built to be listed, and this runs on a screen somebody is
	 * waiting on. Products that answer the `is_serialised` filter without
	 * carrying the flag are not here — nothing can enumerate a filter — and
	 * they appear anyway as soon as they hold a unit or owe one to an order.
	 *
	 * @return int[]
	 */
	public static function serialised_product_ids() {

		global $wpdb;

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery -- one indexed meta read, on a screen.
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
				Dahu_Serial_Numbers_Products::META_KEY,
				'yes'
			)
		);

		$out = array();

		foreach ( (array) $ids as $id ) {
			$resolved = Dahu_Serial_Numbers_Products::resolve( (int) $id );

			// A variable parent is here because the shop ticked the box once
			// rather than on each of nine variations. Its variations are the
			// products that hold units, so they are the ones worth listing;
			// the parent itself is reported as a refusal when it holds any.
			if ( $resolved > 0 && function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $resolved );

				if ( $product && $product->is_type( 'variable' ) ) {
					foreach ( $product->get_children() as $child ) {
						$out[] = (int) $child;
					}

					continue;
				}
			}

			if ( $resolved > 0 ) {
				$out[] = $resolved;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * A fingerprint of a plan, so that what is applied is what was shown.
	 *
	 * Somebody reads a summary, goes to lunch, comes back and clicks. In
	 * between, an order came in. The fingerprint is what turns that into a
	 * refusal rather than a surprise.
	 *
	 * @param array $rows Plan rows.
	 * @return string
	 */
	public static function fingerprint( array $rows ) {

		$parts = array();

		foreach ( $rows as $row ) {
			$parts[] = implode(
				'|',
				array(
					(int) $row['product_id'],
					(int) $row['in_stock'],
					(int) $row['reserved'],
					(int) $row['wanted'],
					null === $row['before'] ? 'null' : (int) $row['before'],
					(string) $row['refusal'],
				)
			);
		}

		return md5( implode( "\n", $parts ) );
	}

	/**
	 * Writes every product the plan says should change.
	 *
	 * Works with the automatic push switched off — a human asked for this
	 * one, on a screen, with the numbers in front of them. It is not a
	 * transaction: each product's stock is WooCommerce's own row, written by
	 * WooCommerce's own function, and a half-finished run leaves every
	 * product it touched correct and every product it did not where it was.
	 * Rolling back would mean restoring numbers that were wrong.
	 *
	 * @param string $fingerprint Fingerprint of the plan that was shown.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function resync( $fingerprint ) {

		$plan = self::resync_plan();

		if ( (string) $fingerprint !== $plan['fingerprint'] ) {
			return new WP_Error(
				'dahu_serial_numbers_stock_moved',
				__( 'Stock changed since this list was drawn up. Here it is again — read it and run it once more.', 'dahu-serial-numbers' )
			);
		}

		$free     = $plan['free'];
		$reserved = $plan['reserved'];
		$written  = array();
		$failed   = array();

		foreach ( $plan['rows'] as $row ) {
			if ( '' !== $row['refusal'] || ! $row['changes'] ) {
				continue;
			}

			$done = self::push( $row['product_id'], 'resync', $free, $reserved );

			if ( ! empty( $done['written'] ) ) {
				$written[] = $done;
			} else {
				$failed[] = $done;
			}
		}

		return array(
			'written' => $written,
			'failed'  => $failed,
		);
	}

	/* ---------------------------------------------------------------------
	 * History
	 * ------------------------------------------------------------------ */

	/**
	 * Files a push, newest first.
	 *
	 * @param array $plan Push result.
	 * @return void
	 */
	private static function remember( array $plan ) {

		$history = self::history();

		array_unshift(
			$history,
			array(
				'at'       => gmdate( 'Y-m-d H:i:s' ),
				'origin'   => isset( $plan['origin'] ) ? (string) $plan['origin'] : '',
				'product'  => (int) $plan['product_id'],
				'name'     => (string) $plan['name'],
				'before'   => $plan['before'],
				'in_stock' => (int) $plan['in_stock'],
				'reserved' => (int) $plan['reserved'],
				'written'  => ! empty( $plan['written'] ) ? (int) $plan['wanted'] : null,
				'gap'      => (int) $plan['gap'],
				'refusal'  => (string) $plan['refusal'],
				'user'     => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
			)
		);

		update_option( self::OPTION_HISTORY, array_slice( $history, 0, self::HISTORY_LIMIT ), false );
	}

	/**
	 * The pushes on file, newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function history() {

		$history = get_option( self::OPTION_HISTORY, array() );

		return is_array( $history ) ? $history : array();
	}
}
