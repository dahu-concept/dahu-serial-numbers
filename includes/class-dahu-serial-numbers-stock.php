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
	 * The statuses a product has to carry for its stock to be written.
	 *
	 * `publish` and `private` both count: a private product is still sold, to
	 * whoever can see it. Everything else — `draft`, `pending`, `future`,
	 * `trash`, an auto-draft, a parent that no longer exists — does not.
	 *
	 * ONE list, read in two places, and that is the point. `on_sale_status()`
	 * decides whether a push is refused, and `on_back_on_sale()` decides when
	 * a product has come back and owes itself a fresh push. The two have to
	 * agree exactly: a status the first refuses and the second does not treat
	 * as a return is a status whose stock is never written and never caught up
	 * either. Written twice, they would drift the day one of them gained a
	 * status, and the hole would be silent.
	 */
	const ON_SALE_STATUSES = array( 'publish', 'private' );

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

		// The serialised flag, in all the shapes WordPress writes a meta in.
		// A product that has just been marked serialised has a WooCommerce
		// stock number that owes nothing to its serials, and the first push
		// is what makes the two agree.
		foreach ( array( 'added_post_meta', 'updated_post_meta' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'on_flag_written' ), 10, 4 );
		}

		add_action( 'deleted_post_meta', array( __CLASS__, 'on_flag_removed' ), 10, 4 );

		// A product that goes back on sale. Units may have moved while it was
		// out of sale, and nothing wrote a stock for it in the meantime.
		add_action( 'transition_post_status', array( __CLASS__, 'on_back_on_sale' ), 10, 3 );
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

	/**
	 * The serialised flag was written on a product.
	 *
	 * @param int    $meta_id    Meta row id.
	 * @param int    $object_id  Product id.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 * @return void
	 */
	public static function on_flag_written( $meta_id, $object_id, $meta_key, $meta_value ) {

		unset( $meta_id );

		if ( Dahu_Serial_Numbers_Products::META_KEY !== $meta_key || 'yes' !== $meta_value ) {
			return;
		}

		self::queue( (int) $object_id, 'serialised_flag' );
	}

	/**
	 * The serialised flag was removed from a product.
	 *
	 * One last push, and then never again: the product's stock is left where
	 * the serials put it, and from here on it is the shop's to manage. Not
	 * pushing at all would leave a number that counted serials this product
	 * no longer claims.
	 *
	 * @param array  $meta_ids  Meta row ids.
	 * @param int    $object_id Product id.
	 * @param string $meta_key  Meta key.
	 * @param mixed  $meta_value Meta value.
	 * @return void
	 */
	public static function on_flag_removed( $meta_ids, $object_id, $meta_key, $meta_value ) {

		unset( $meta_ids, $meta_value );

		if ( Dahu_Serial_Numbers_Products::META_KEY !== $meta_key ) {
			return;
		}

		self::queue( (int) $object_id, 'serialised_flag_removed' );
	}

	/**
	 * A product that comes back on sale — out of the bin, or published again.
	 *
	 * WHY THIS TRIGGER EXISTS. While a product is out of sale the push is
	 * refused for the whole time, and rightly so: nothing should write a
	 * stock number on a product nobody can buy. But units go on moving on the
	 * shelf, and the number WooCommerce still holds is the one from before.
	 * Without this, a product restored from the bin kept that stale number
	 * until somebody happened to open the Stock screen or a further unit
	 * moved — the shop offering a quantity no unit agrees with, which is the
	 * one thing a stock is for.
	 *
	 * Only the crossing counts. A save that leaves a published product
	 * published changes nothing here: the serial events already queue what
	 * they need, and queueing on every product save would push the whole
	 * catalogue for nothing.
	 *
	 * A variable parent holds no stock of its own, so restoring one queues
	 * each variation that is itself on sale. A variation still out of sale is
	 * left alone: its own crossing will queue it when it comes.
	 *
	 * @param string  $new_status Status it moves to.
	 * @param string  $old_status Status it comes from.
	 * @param WP_Post $post       The post that moved.
	 * @return void
	 */
	public static function on_back_on_sale( $new_status, $old_status, $post ) {

		if ( ! $post instanceof WP_Post ) {
			return;
		}

		if ( ! in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) {
			return;
		}

		$on_sale = self::ON_SALE_STATUSES;

		if ( ! in_array( (string) $new_status, $on_sale, true ) || in_array( (string) $old_status, $on_sale, true ) ) {
			return;
		}

		// A product being BORN is not a product coming back.
		//
		// WHY THIS LINE EXISTS. wp_insert_post() emits `new` as the old
		// status, so without this an import of a thousand products queued a
		// thousand pushes. Each one is then refused — they carry no serial
		// numbers — but a refusal is remembered, and the history keeps only
		// its last five hundred lines: measured, an import of six hundred
		// products wiped every real stock write from the history and left
		// nothing but refusals. A product that has just been created has no
		// units that moved while it was out of sale, and if it is marked as
		// serialised, the flag written on it queues it already.
		if ( in_array( (string) $old_status, array( 'new', 'auto-draft' ), true ) ) {
			return;
		}

		$variations = 'product' === $post->post_type
			? get_posts(
				array(
					'post_type'      => 'product_variation',
					'post_parent'    => (int) $post->ID,
					'post_status'    => $on_sale,
					'fields'         => 'ids',
					'posts_per_page' => -1,
					'orderby'        => 'ID',
					'order'          => 'ASC',
				)
			)
			: array();

		// A product that has variations is a variable parent, and a variable
		// parent holds no stock of its own: pushing it is refused every time,
		// and the refusal would take a line of history for nothing. Its
		// variations are what carry the units. Read as a count and not as a
		// product type, so nothing has to be loaded to find out.
		if ( ! $variations ) {
			self::queue( (int) $post->ID, 'product_back_on_sale' );

			return;
		}

		foreach ( (array) $variations as $variation_id ) {
			self::queue( (int) $variation_id, 'product_back_on_sale' );
		}
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

		if ( ! $queue ) {
			return $done;
		}

		// The two maps are read ONCE for the whole queue, as resync_plan()
		// already does.
		//
		// WHY IT MATTERS. plan_one() rebuilds both maps in full whenever it is
		// not handed them, and each of them walks the orders. Restoring a
		// hundred products from the bin in one go therefore loaded every order
		// a hundred times over — measured at 18 000 order loads for 120
		// products — which ends in a timeout half way through. And the queue is
		// emptied at the top of this method, so nothing is retried: the
		// products that did not get their turn keep a stock number no unit
		// agrees with. That is the very hole this queue exists to close, moved
		// from the bin to the timeout.
		$free     = self::free_map();
		$reserved = self::reserved_map();

		foreach ( $queue as $product_id => $origin ) {
			$done[ $product_id ] = self::push( $product_id, $origin, $free, $reserved );
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

		$held = self::held_by_line( $order_ids );

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

				$line_key = (int) $order_id . ':' . (int) $item_id . ':' . $product_id;
				$already  = isset( $held[ $line_key ] ) ? (int) $held[ $line_key ] : 0;
				$left     = max( 0, $reduced - $already );

				if ( $left > 0 ) {
					$map[ $product_id ] = ( isset( $map[ $product_id ] ) ? (int) $map[ $product_id ] : 0 ) + $left;
				}
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
	 * Units already standing against each order line.
	 *
	 * One query for every order in the window rather than one per line. The
	 * key is `order:line:product` so that an order with two lines of the
	 * same product does not have one line's serials counted against the
	 * other — and a unit attached to an order without a line falls back to
	 * its product, which is the best that can honestly be said about it.
	 *
	 * @param int[] $order_ids Orders to look at.
	 * @return array<string,int>
	 */
	private static function held_by_line( array $order_ids ) {

		global $wpdb;

		$held      = array();
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
			$product_id = (int) $row['product_id'];
			$count      = (int) $row['held'];

			if ( $item_id > 0 ) {
				$key          = $order_id . ':' . $item_id . ':' . $product_id;
				$held[ $key ] = ( isset( $held[ $key ] ) ? $held[ $key ] : 0 ) + $count;
				continue;
			}

			// No line: remembered under a wildcard the caller resolves by
			// product, below.
			$key          = $order_id . ':*:' . $product_id;
			$held[ $key ] = ( isset( $held[ $key ] ) ? $held[ $key ] : 0 ) + $count;
		}

		// Fold the wildcards into the lines of the same product. A unit that
		// says "order 42, product 7" and nothing more is counted against
		// whichever line of order 42 sells product 7; when there are two such
		// lines it lands on the first, and that is stated rather than hidden:
		// the alternative is not counting it at all, which offers a unit for
		// sale that a customer has already bought.
		foreach ( $held as $key => $count ) {
			if ( false === strpos( $key, ':*:' ) ) {
				continue;
			}

			list( $order_id, , $product_id ) = explode( ':', $key );

			unset( $held[ $key ] );

			foreach ( array_keys( $held ) as $other ) {
				$parts = explode( ':', $other );

				if ( $parts[0] === $order_id && $parts[2] === $product_id ) {
					$held[ $other ] += $count;
					continue 2;
				}
			}

			// No line of that order sells that product: keep it under a line
			// id of zero, where nothing will match it. Counting it against an
			// unrelated line would be worse than losing it.
			$held[ $order_id . ':0:' . $product_id ] = $count;
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

		$map = array();

		foreach ( (array) $rows as $row ) {
			$map[ (int) $row['product_id'] ] = (int) $row['free'];
		}

		return $map;
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

		$map = array();

		foreach ( (array) $rows as $row ) {
			$map[ (int) $row['product_id'] ] = (int) $row['stuck'];
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

		// Out of sale, out of the count. A product in the bin, a draft or a
		// pending revision is not on the shelf, and a variation stays
		// published when its parent goes to the bin — so the parent is asked
		// too. Without this, units filed against a withdrawn reference keep
		// writing a stock figure on a row nobody can buy, and the screen
		// counts them among the products it manages.
		if ( ! self::on_sale_status( $product_id, $product ) ) {
			return 'not_published';
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
	 * Is this row on sale, parent included?
	 *
	 * The statuses that count are `self::ON_SALE_STATUSES`, and the reason
	 * they live there rather than here is written on the constant. A variation
	 * carries its own status and keeps it when its parent is binned, which is
	 * why the parent is read as well.
	 *
	 * @param int        $product_id Product or variation id.
	 * @param WC_Product $product    The product, already loaded.
	 * @return bool
	 */
	private static function on_sale_status( $product_id, $product ) {

		$allowed = self::ON_SALE_STATUSES;

		if ( ! in_array( (string) get_post_status( $product_id ), $allowed, true ) ) {
			return false;
		}

		$parent_id = (int) $product->get_parent_id();

		if ( $parent_id <= 0 ) {
			return true;
		}

		return in_array( (string) get_post_status( $parent_id ), $allowed, true );
	}

	/**
	 * A refusal, in words.
	 *
	 * @param string $code Refusal code.
	 * @return string
	 */
	public static function refusal_label( $code ) {

		$labels = array(
			'unknown_product'    => __( 'No such product.', 'dahu-serial-numbers' ),
			'not_published'      => __( 'Not on sale: the product, or its parent, is in the bin or unpublished.', 'dahu-serial-numbers' ),
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
			'changes'    => '' === $refusal && (int) $before !== (int) $wanted,
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

		$plan            = self::plan_one( $product_id, $free, $reserved );
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

		$ids = array_values( array_unique( array_merge( array_keys( $free ), array_keys( $reserved ) ) ) );
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
		);
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

		$free     = self::free_map();
		$reserved = self::reserved_map();
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
