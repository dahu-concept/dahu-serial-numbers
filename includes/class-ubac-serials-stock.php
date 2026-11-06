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
 * @package UbacSerials
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pushes the serial count into WooCommerce stock.
 */
class Ubac_Serials_Stock {

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
	const OPTION_HISTORY = 'ubac_serials_stock_history';

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
	 * Whether flush() is running right now.
	 *
	 * Writing a stock number fires WooCommerce's own hooks, which can reach
	 * back into queue(). Re-entering the queue mid-flush would either lose
	 * the product silently or, now that the queue is persisted, have it
	 * retried on every single request for ever. The product being written is
	 * the one whose number is up to date, so there is nothing to retry.
	 *
	 * @var bool
	 */
	private static $flushing = false;

	/**
	 * Option holding the products a failed flush could not write.
	 *
	 * Autoloaded on purpose: it is a short list of integers, it rides along
	 * with the options WordPress already loads, and reading it therefore
	 * costs no query. It is deleted as soon as it has been drained.
	 */
	const FILE_REPRISE = 'ubac_serials_stock_a_reprendre';

	/**
	 * Registers the triggers.
	 *
	 * @return void
	 */
	public static function init() {

		add_action( 'ubac_serials_transitioned', array( __CLASS__, 'on_transition' ), 10, 4 );
		add_action( 'ubac_serials_created', array( __CLASS__, 'on_created' ), 10, 2 );
		add_action( 'ubac_serials_product_changed', array( __CLASS__, 'on_product_changed' ), 10, 4 );

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

		// Whatever a failed `flush()` left behind. Without this hook, putting the
		// queue back served no purpose: see reprendre().
		add_action( 'init', array( __CLASS__, 'reprendre' ), 20 );
	}

	/**
	 * Re-queues whatever a failed flush left behind.
	 *
	 * WHY THIS HOOK EXISTS. flush() is hooked on `shutdown` at priority 5. By
	 * the time it abandons a batch, `shutdown` is already running: nothing left
	 * in the request will call it again, `self::$flush_hooked` stays true, and
	 * `self::$queue` is a static property the next request does not see. "The
	 * queue is therefore put back, and that is what gives the next request a
	 * chance to do the work properly" was false, and a bench assertion
	 * certified that non-existent recovery. An adversarial review measured it.
	 *
	 * The queue is therefore written to an option, and it is that option the
	 * next request picks up. It is deleted BEFORE the re-queue: if the new
	 * attempt fails in turn, flush() is what writes it again, so a permanent
	 * failure does not loop on an option that keeps growing.
	 *
	 * @return void
	 */
	public static function reprendre() {

		$attente = get_option( self::FILE_REPRISE, array() );

		if ( ! is_array( $attente ) || ! $attente ) {
			return;
		}

		delete_option( self::FILE_REPRISE );

		foreach ( $attente as $product_id => $origin ) {
			self::queue( (int) $product_id, (string) $origin );
		}
	}

	/**
	 * Writes a queue down so the next request can pick it up.
	 *
	 * @param array<int,string> $queue Product id => origin.
	 * @return void
	 */
	private static function persister( array $queue ) {

		if ( ! $queue ) {
			return;
		}

		$deja = get_option( self::FILE_REPRISE, array() );
		$deja = is_array( $deja ) ? $deja : array();

		// The oldest origin wins: it is the one that says why this product has
		// been waiting from the start.
		update_option( self::FILE_REPRISE, $deja + $queue, true );
	}

	/**
	 * Is the automatic push switched on?
	 *
	 * @return bool
	 */
	public static function enabled() {

		return (bool) Ubac_Serials_Settings::get( 'stock_push_enabled' );
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

		if ( Ubac_Serials_Statuses::IN_STOCK !== $to && Ubac_Serials_Statuses::IN_STOCK !== $from ) {
			return;
		}

		$row = Ubac_Serials_Manager::get( $serial );

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

		if ( empty( $row['product_id'] ) || Ubac_Serials_Statuses::IN_STOCK !== (string) $row['status'] ) {
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

		if ( Ubac_Serials_Products::META_KEY !== $meta_key || 'yes' !== $meta_value ) {
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

		if ( Ubac_Serials_Products::META_KEY !== $meta_key ) {
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

		// Writing a stock figure runs WooCommerce's hooks, which can come back
		// here. The product being written is precisely the one whose number is up
		// to date: there is nothing to replay.
		if ( self::$flushing ) {
			return;
		}

		/* QUEUED AFTER `shutdown:5` HAS GONE BY.
		 *
		 * A plugin hooked on `shutdown` at priority 10 that moves a number
		 * arrives after flush(). The product then entered a queue nothing will
		 * ever empty, and nothing said so. It is therefore written straight out
		 * for the next request. */
		if ( did_action( 'shutdown' ) ) {
			self::persister( array( $product_id => (string) $origin ) );

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
	 * WHAT STATE THE SHOP IS LEFT IN IF THIS STOPS HALF WAY. This is not a
	 * transaction, no more than resync() is, and it is said here because it
	 * was said only there. Every product reached carries a correct quantity
	 * and a line of history; every product not reached keeps the one it had,
	 * and its turn comes back through reprendre() if the stop came from
	 * reading a map. If the stop is a fatal error `catch ( Throwable )` does
	 * not catch -- execution time exceeded, memory exhausted, a fatal error in
	 * a plugin hooked on `woocommerce_product_set_stock` -- the queue is lost
	 * with the request and the products not reached keep a number no unit
	 * agrees with, with no trace: remember() only writes for products
	 * reached. That is the price of reading the maps once, and the manual
	 * resynchronisation is what makes up for it.
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
		/* THE MAPS ARE READ UNDER GUARD, AND A FAILURE WRITES NOTHING.
		 *
		 * reserved_map() ends on a filter the SHOP owns, and a shop is free to
		 * throw there. Read outside a try, that exception left flush() on the
		 * spot -- after the queue had been emptied above. Every product waiting
		 * then kept a stock quantity no unit agrees with, nothing was replayed,
		 * and no line said so: the very hole this queue exists to close, moved
		 * from the bin to the filter.
		 *
		 * WHY THE BATCH IS ABANDONED RATHER THAN RETRIED PRODUCT BY PRODUCT. An
		 * adversarial review measured the second option: without the maps, push()
		 * reads them per product and throws in the same place, which for 120
		 * products is some 18,150 order loads for zero writes -- exactly the
		 * volume reading them once exists to remove, and enough to run past the
		 * execution time. And exceeding it is a fatal error catch ( Throwable )
		 * does not catch: the queue would be lost anyway, and this time without
		 * even a log line.
		 *
		 * THE QUEUE IS WRITTEN TO THE DATABASE, NOT PUT BACK IN THE STATIC
		 * PROPERTY. `self::$queue = $queue` did not survive the request, and with
		 * `shutdown` already running nothing called flush() again: the sentence
		 * "that gives the next request a chance to do the work" was false, and a
		 * bench assertion certified it. A second adversarial review measured it.
		 * See reprendre(). */
		try {
			$free     = self::free_map();
			$reserved = self::reserved_map();
		} catch ( Throwable $e ) { // phpcs:ignore PHPCompatibility.Classes.NewClasses.throwableFound -- guarded by Requires PHP 7.4.
			self::persister( $queue );

			/* error_log, and not the return array: flush() is hooked on
			 * `shutdown`, whose return value WordPress THROWS AWAY. An error filed
			 * in $done would be read by nobody, which would leave the original
			 * complaint untouched -- nothing in the logs. */
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				sprintf(
					'ubac-serials: stock maps unreadable, nothing written, %d product(s) carried over to the next request: %s',
					count( $queue ),
					$e->getMessage()
				)
			);

			return $done;
		}

		self::$flushing = true;

		try {
			foreach ( $queue as $product_id => $origin ) {
				$done[ $product_id ] = self::push( $product_id, $origin, $free, $reserved );
			}
		} finally {
			self::$flushing = false;
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
			'ubac_serials_stock_reserving_statuses',
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

		/* A LIST EMPTIED BY A FILTER IS NOT "NOTHING RESERVED".
		 *
		 * The default is never empty: an empty list here means a filter emptied
		 * it, or that every one of its statuses was rejected by sanitize_key().
		 * Returned as it was, it made reserved_map() leave on an empty map, so
		 * `wanted = free - 0`, so a stock figure written TOO HIGH and units
		 * already paid for put back on sale -- the danger this class's docblock
		 * names first. */
		if ( ! $clean ) {
			throw new RuntimeException(
				'ubac-serials: no order status reserves stock, so reservations cannot be counted.'
			);
		}

		return $clean;
	}

	/**
	 * How many pieces each product owes to orders being prepared.
	 *
	 * WHAT THIS METHOD CANNOT TELL APART, AND WHICH STAYS OPEN.
	 * `wc_get_orders()` returns an empty array for a shop with no open order
	 * and for an order query that failed alike -- HPOS included. The two read
	 * the same, and the second would have a stock figure written too high.
	 * What can be checked is checked: the return's type, the list of
	 * statuses, and the type of what the output filter returns. The case of a
	 * legitimately empty array is not detectable from here, and it is said
	 * rather than left unsaid.
	 *
	 * @return array<int,int> Product id => pieces reserved.
	 * @throws RuntimeException When reservations cannot be counted at all.
	 */
	public static function reserved_map() {

		$map      = array();
		$statuses = self::reserving_statuses();

		if ( ! function_exists( 'wc_get_orders' ) ) {
			throw new RuntimeException(
				'ubac-serials: WooCommerce is not loaded, so reservations cannot be counted.'
			);
		}

		$order_ids = wc_get_orders(
			array(
				'limit'  => -1,
				'status' => $statuses,
				'return' => 'ids',
			)
		);

		// A third-party filter on the order query can return anything at all
		// rather than a list. That is not "no open order".
		if ( ! is_array( $order_ids ) ) {
			throw new RuntimeException(
				'ubac-serials: the order query did not return a list of ids.'
			);
		}

		if ( ! $order_ids ) {
			return $map;
		}

		$held = self::held_by_line( $order_ids );

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				continue;
			}

			$reste = array();

			foreach ( $order->get_items() as $item_id => $item ) {
				if ( ! $item instanceof WC_Order_Item_Product ) {
					continue;
				}

				$reduced = (int) $item->get_meta( '_reduced_stock', true );

				if ( $reduced <= 0 ) {
					continue;
				}

				$product_id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
				$product_id = Ubac_Serials_Products::resolve( (int) $product_id );

				if ( $product_id <= 0 ) {
					continue;
				}

				$line_key = (int) $order_id . ':' . (int) $item_id . ':' . $product_id;
				$already  = isset( $held[ $line_key ] ) ? (int) $held[ $line_key ] : 0;
				$left     = max( 0, $reduced - $already );

				/* Accumulated per order AND per product rather than straight
				 * into the map: this order's wildcard pool is subtracted from
				 * the total, so the total has to be whole before deciding. */
				if ( ! isset( $reste[ $product_id ] ) ) {
					$reste[ $product_id ] = 0;
				}

				$reste[ $product_id ] += $left;
			}

			/* UNITS WITH NO LINE, CREDITED TO THE PRODUCT.
			 *
			 * A unit that says "order 42, product 7" and nothing more is a
			 * piece already sold: it has to come off the reserved count, or
			 * the piece is offered twice. Applied to the product's total
			 * rather than to one line, it can no longer be eaten by the floor
			 * of a line too small to hold it. The `max` stays, for the case
			 * where more units carry the order than pieces were committed. */
			foreach ( $reste as $product_id => $left ) {
				$joker = isset( $held[ (int) $order_id . ':*:' . $product_id ] )
					? (int) $held[ (int) $order_id . ':*:' . $product_id ]
					: 0;

				$net = max( 0, $left - $joker );

				if ( $net > 0 ) {
					$map[ $product_id ] = ( isset( $map[ $product_id ] ) ? (int) $map[ $product_id ] : 0 ) + $net;
				}
			}
		}

		/**
		 * Filters the reserved pieces per product.
		 *
		 * @param array<int,int> $map       Product id => pieces reserved.
		 * @param int[]          $order_ids Orders that were counted.
		 */
		$filtre = apply_filters( 'ubac_serials_stock_reserved_map', $map, $order_ids );

		/* `(array) $scalar` GIVES `array( 0 => $scalar )`.
		 *
		 * That is, a map of one product with id 0, meaning no real product: every
		 * reservation disappeared, and the stock went too high. A filter that
		 * does not return a list is refused. */
		if ( ! is_array( $filtre ) ) {
			throw new RuntimeException(
				'ubac-serials: the ubac_serials_stock_reserved_map filter did not return a list.'
			);
		}

		return $filtre;
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

		$table        = Ubac_Serials_Install::serials_table();
		$in_orders    = implode( ',', array_fill( 0, count( $order_ids ), '%d' ) );
		$in_statuses  = implode( ',', array_fill( 0, 2, '%s' ) );
		$placeholders = array_merge(
			$order_ids,
			array( Ubac_Serials_Statuses::ASSIGNED, Ubac_Serials_Statuses::SHIPPED )
		);

		$rows = self::lignes(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders -- the lists are %d/%s placeholders.
				"SELECT order_id, order_item_id, product_id, COUNT(*) AS held
				 FROM %i
				 WHERE order_id IN ({$in_orders}) AND status IN ({$in_statuses})
				 GROUP BY order_id, order_item_id, product_id",
				array_merge( array( $table ), $placeholders )
			),
			array( 'order_id', 'order_item_id', 'product_id', 'held' )
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

		/* THE WILDCARD STAYS A WILDCARD; IT IS NO LONGER FOLDED ONTO A LINE.
		 *
		 * The fold picked the first line of the same product and gave it the
		 * whole batch. Two defects, and the second one is silent:
		 *
		 * - on an order with two lines of the same product, one of one and
		 *   one of three, three units with no line landed on the line of one.
		 *   `max( 0, 1 - 3 )` ate two credits at the floor, and the reserved
		 *   count held two pieces too many;
		 * - when no line entry exists for that product at all -- the normal
		 *   case when no unit names a line -- the fold found nothing and the
		 *   whole batch was lost. The reserved count stayed full, the stock
		 *   went that much too low, and the product left the shelf with
		 *   nothing on screen to say so.
		 *
		 * The pool is therefore returned as it is, under its wildcard key,
		 * and `reserved_map()` subtracts it from the order's committed total
		 * for that product -- where no line's floor can eat it. */
		return $held;
	}

	/**
	 * Runs one of this class's own queries, or says it could not.
	 *
	 * THE SINGLE PLACE THESE THREE QUERIES GO THROUGH, AND IT EXISTS FOR ONE
	 * REASON: `$wpdb->get_results()` RETURNS null ON FAILURE AND THROWS
	 * NOTHING.
	 *
	 * A missing table after an interrupted update, a deadlock, a prepare that
	 * came back null -- any of these, and `(array) null` is `array()`. An
	 * empty map is then indistinguishable from a shop whose units are all
	 * sold, and everything downstream believes it: `$wanted` becomes 0, no
	 * refusal fires, and `wc_update_product_stock( $product, 0, 'set' )`
	 * writes zero over every serialised product in the queue, with a history
	 * line that says the count was zero. No exception, so nothing in the log.
	 *
	 * The resynchronisation screen reached the same lie by its own route: with
	 * an empty plan it prints « no serialised product holds a unit yet, so
	 * there is nothing to compare », which is the most reassuring possible
	 * wording for a broken database.
	 *
	 * So failure is made loud, by three checks rather than one -- a second
	 * adversarial review found the first version blind to exactly the family
	 * of cases a third-party plugin causes, and each check below names the
	 * hole it closes.
	 *
	 * @param string   $sql       Prepared SQL.
	 * @param string[] $colonnes  Columns the result must carry.
	 * @return array<int,array<string,mixed>>
	 * @throws RuntimeException When the query failed, was short-circuited, or
	 *                          came back carrying someone else's columns.
	 */
	private static function lignes( $sql, array $colonnes ) {

		global $wpdb;

		/* A NULL `prepare()` NEVER REACHES THE DATABASE AT ALL.
		 *
		 * prepare() returns null when the number of arguments does not match the
		 * number of placeholders, and get_results() then returns null without
		 * ever reaching query(): so without setting last_error. The map came
		 * back empty swearing all was well, and that is the plausible case here
		 * since held_by_line() builds its placeholder lists on the fly. */
		if ( ! is_string( $sql ) || '' === trim( $sql ) ) {
			throw new RuntimeException( 'ubac-serials: the query was not prepared.' );
		}

		/* query() AND last_result, RATHER THAN get_results().
		 *
		 * get_results() does not look at query()'s return value: it rebuilds its
		 * array from $wpdb->last_result. But a third-party `query` filter --
		 * maintenance mode, read-only, sharding -- that empties the query makes
		 * wpdb::query() leave through a `return false` placed BEFORE flush():
		 * last_error is not set and last_result keeps THE ROWS OF THE PREVIOUS
		 * QUERY. get_results() therefore returned rows belonging to something
		 * else, (int) null was 0, and an invented quantity went out on every
		 * serialised product without a line of log.
		 *
		 * query()'s return value is the only signal that tells this
		 * short-circuit apart from a legitimately empty result. */
		$issue = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- own table, prepared by the caller.

		if ( false === $issue ) {
			$raison = (string) $wpdb->last_error;

			/* esc_html() ON THE VARIABLE PART OF THE MESSAGE.
			 *
			 * An uncaught exception's message ends up on screen, and
			 * `$wpdb->last_error` carries content the database composed. Plugin
			 * Check refuses it under WordPress.Security.EscapeOutput -- measured on
			 * the zip, after wrongly assuming this fix added nothing any sniff
			 * looks at. */
			throw new RuntimeException(
				esc_html( 'ubac-serials: ' . ( '' !== $raison ? $raison : 'the query was refused without an error message.' ) )
			);
		}

		if ( '' !== (string) $wpdb->last_error ) {
			throw new RuntimeException( esc_html( 'ubac-serials: ' . $wpdb->last_error ) );
		}

		$rows = array();

		foreach ( (array) $wpdb->last_result as $objet ) {
			$ligne = is_object( $objet ) ? get_object_vars( $objet ) : (array) $objet;

			/* SECOND NET: ROWS THAT ARE NOT OURS.
			 *
			 * If last_result carried the result of another query despite all the
			 * above, its columns are not these ones. Better to refuse than to read
			 * null and write zero. */
			foreach ( $colonnes as $colonne ) {
				if ( ! array_key_exists( $colonne, $ligne ) ) {
					throw new RuntimeException(
						esc_html( 'ubac-serials: the result carries no column named ' . $colonne . '.' )
					);
				}
			}

			$rows[] = $ligne;
		}

		return $rows;
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

		$table = Ubac_Serials_Install::serials_table();

		$rows = self::lignes(
			$wpdb->prepare(
				"SELECT product_id, COUNT(*) AS free
				 FROM %i
				 WHERE status = %s
				   AND product_id IS NOT NULL
				   AND ( order_id IS NULL OR order_id = 0 )
				   AND ( order_item_id IS NULL OR order_item_id = 0 )
				 GROUP BY product_id",
				$table, Ubac_Serials_Statuses::IN_STOCK
			),
			array( 'product_id', 'free' )
		);

		/* THE ID IS RESOLVED HERE TOO, AND THE UNITS ACCUMULATE.
		 *
		 * `reserved_map()` resolves and `free_map()` did not. A shop using the
		 * `resolve` filter therefore had its reservations on the target id and
		 * its free units on the source id: two columns of the same plan that
		 * were not talking about the same product, with a stock figure worked
		 * out from the difference between them.
		 *
		 * Accumulating is not a detail: `resolve()` may fold several source
		 * ids onto a single target, and a plain assignment would have kept
		 * the last row and thrown the others away. */
		$map = array();

		foreach ( (array) $rows as $row ) {
			$product_id = Ubac_Serials_Products::resolve( (int) $row['product_id'] );

			if ( $product_id <= 0 ) {
				continue;
			}

			if ( ! isset( $map[ $product_id ] ) ) {
				$map[ $product_id ] = 0;
			}

			$map[ $product_id ] += (int) $row['free'];
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
	/**
	 * Every product the serial table knows, whatever the status of its units.
	 *
	 * WHY THIS EXISTS, AND WHY IT IS NOT A LIST OF SERIALISED PRODUCTS.
	 *
	 * A product whose last unit has shipped has no free line and no
	 * reservation. Built from `free_map()` and `reserved_map()` alone, the
	 * plan did not name it at all — and the resynchronisation screen said
	 * « nothing changes » while WooCommerce went on selling four units that
	 * no longer exist. That is the exact case the screen exists to catch, and
	 * it was the one case it could not see.
	 *
	 * The obvious fix — enumerate the serialised products — cannot be
	 * written: `Ubac_Serials_Products::is_serialised()` is filterable, so a
	 * shop may decide by category with no meta on any product, and no query
	 * can list them. Enumerating by meta key would cover the common shop and
	 * quietly miss that one, which is the same silence in a new place.
	 *
	 * So the table answers instead. A product whose units have all gone still
	 * has its rows there; only their status changed. That is precisely the
	 * case to catch, and it needs no product list at all.
	 *
	 * @return array<int,int> Product id => units recorded, any status.
	 */
	public static function known_map() {

		global $wpdb;

		$table = Ubac_Serials_Install::serials_table();

		/* Cette requete n'avait pas besoin de prepare() — aucune valeur
		 * utilisateur — et passait son nom de table par interpolation. Avec
		 * le plancher a WordPress 6.2, le marqueur %i existe : le nom passe
		 * par prepare() comme partout ailleurs, et plus rien n'est
		 * interpole dans du SQL. */
		$rows = self::lignes(
			$wpdb->prepare(
				"SELECT product_id, COUNT(*) AS units
				 FROM %i
				 WHERE product_id IS NOT NULL AND product_id <> 0
				 GROUP BY product_id",
				$table
			),
			array( 'product_id', 'units' )
		);

		/* Same resolution and same accumulation as `free_map()`, for the same
		 * reason: the plan's three maps must index the same product. */
		$map = array();

		foreach ( (array) $rows as $row ) {
			$product_id = Ubac_Serials_Products::resolve( (int) $row['product_id'] );

			if ( $product_id <= 0 ) {
				continue;
			}

			if ( ! isset( $map[ $product_id ] ) ) {
				$map[ $product_id ] = 0;
			}

			$map[ $product_id ] += (int) $row['units'];
		}

		/* THE SECOND CASE: SERIALISED, AND NEVER ONE SINGLE UNIT.
		 *
		 * The table knows nothing about this one. It does carry the meta,
		 * though, so it is enumerable -- and it has to be: marking a product
		 * "serialised" without giving it a unit is precisely what preparing it
		 * looks like, and its WooCommerce stock must fall to zero rather than
		 * stay sellable. */
		/* NO CEILING, AND THAT IS DELIBERATE.
		 *
		 * A page of 500 would have two defects, and the second is worse than
		 * the first. Past 500, the oldest fall out of the plan silently --
		 * so precisely those that have had the most time to drift. And the
		 * window would be dated (`ORDER BY post_date DESC` by default), so
		 * creating a ticked product between the screen being drawn and the
		 * button being pressed would shift the list and answer "Stock changed
		 * since this list was drawn up" for a product with no part in the
		 * plan.
		 *
		 * This is a query for ids, not a page: `no_found_rows` skips the
		 * COUNT, and the screen only runs on demand, behind a capability. */
		$drapeau = get_posts(
			array(
				'post_type'      => array( 'product', 'product_variation' ),
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_key'       => Ubac_Serials_Products::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- the flag is the only way to name these products, and the screen runs on demand.
				'meta_value'     => 'yes', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- same reason.
			)
		);

		foreach ( (array) $drapeau as $product_id ) {
			$product_id = (int) $product_id;

			if ( $product_id <= 0 ) {
				continue;
			}

			/* THE FLAG SITS ON THE PARENT; THE STOCK LIVES ON THE CHILD.
			 *
			 * `is_serialised()` walks up to the parent: ticking the box on
			 * the variable product rather than on each of its variations is
			 * the normal way to do it, and its docblock says so. But the
			 * stock belongs to the variations, and the plan refuses every
			 * variable parent (`variable_parent`). Keeping the parent here
			 * would therefore produce a row that looks examined and decides
			 * nothing, while the variations -- invisible to the plan -- stay
			 * sellable with not one unit in the table. That is the very
			 * silence this map exists to end.
			 *
			 * The parent is therefore replaced by its children rather than
			 * added alongside them: it has no stock to reconcile. */
			$produit = wc_get_product( $product_id );

			if ( $produit && $produit->is_type( 'variable' ) ) {
				foreach ( (array) $produit->get_children() as $enfant_id ) {
					$enfant_id = (int) $enfant_id;

					if ( $enfant_id > 0 && ! isset( $map[ $enfant_id ] ) ) {
						$map[ $enfant_id ] = 0;
					}
				}

				continue;
			}

			if ( ! isset( $map[ $product_id ] ) ) {
				$map[ $product_id ] = 0;
			}
		}

		/**
		 * Filters the products the resynchronisation plan examines.
		 *
		 * THE ONE CASE NEITHER QUERY CAN REACH. `is_serialised()` is
		 * filterable, so a shop may decide by category, by shipping class, by
		 * anything — with no meta on any product. Such a shop is invisible to
		 * both queries above, and no query could find it. It adds its own ids
		 * here:
		 *
		 *     add_filter(
		 *         'ubac_serials_stock_known_products',
		 *         function ( $map ) {
		 *             foreach ( my_serialised_ids() as $id ) {
		 *                 if ( ! isset( $map[ $id ] ) ) { $map[ $id ] = 0; }
		 *             }
		 *             return $map;
		 *         }
		 *     );
		 *
		 * @param array<int,int> $map Product id => units recorded.
		 */
		$map = apply_filters( 'ubac_serials_stock_known_products', $map );

		/* A FILTER THAT RETURNS A LIST IS REFUSED, NOT PATCHED UP.
		 *
		 * The natural mistake is to return a flat list of ids rather than a
		 * map. The keys are then 0, 1, 2: the plan would carry
		 * `unknown_product` rows for ids that do not exist, and the real ids
		 * would be lost silently -- the very symptom this map exists to end,
		 * laid back down in the extension path.
		 *
		 * Filtering the keys would not be enough: only key 0 is invalid, and
		 * 1, 2, 3 would pass for product ids. What is recognisable is the
		 * shape -- integer keys running consecutively from zero. No
		 * legitimate map can have it, since id 0 is not a product. The
		 * refusal is therefore safe, and it is explicit rather than silent,
		 * as for `ubac_serials_stock_reserved_map`. */
		if ( ! is_array( $map ) ) {
			throw new RuntimeException(
				'ubac-serials: the ubac_serials_stock_known_products filter did not return an array.'
			);
		}

		$clefs = array_keys( $map );

		if ( $clefs === range( 0, count( $clefs ) - 1 ) ) {
			throw new RuntimeException(
				'ubac-serials: the ubac_serials_stock_known_products filter returned a list of ids; it must return a map of product id => units.'
			);
		}

		$propre = array();

		foreach ( $map as $product_id => $units ) {
			if ( is_int( $product_id ) && $product_id > 0 ) {
				$propre[ $product_id ] = (int) $units;
			}
		}

		return $propre;
	}

	public static function stuck_map() {

		global $wpdb;

		$table = Ubac_Serials_Install::serials_table();

		$rows = self::lignes(
			$wpdb->prepare(
				"SELECT product_id, COUNT(*) AS stuck
				 FROM %i
				 WHERE status = %s
				   AND product_id IS NOT NULL
				   AND ( ( order_id IS NOT NULL AND order_id <> 0 ) OR ( order_item_id IS NOT NULL AND order_item_id <> 0 ) )
				 GROUP BY product_id",
				$table, Ubac_Serials_Statuses::IN_STOCK
			),
			array( 'product_id', 'stuck' )
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

		if ( ! Ubac_Serials_Products::is_serialised( $product_id ) ) {
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
			'unknown_product'    => __( 'No such product.', 'ubac-serials' ),
			'not_published'      => __( 'Not on sale: the product, or its parent, is in the bin or unpublished.', 'ubac-serials' ),
			'variable_parent'    => __( 'Variable product: stock belongs to its variations.', 'ubac-serials' ),
			'stock_not_managed'  => __( 'WooCommerce stock management is off for this product.', 'ubac-serials' ),
			'not_serialised'     => __( 'Not marked as carrying serial numbers.', 'ubac-serials' ),
			'counts_unreadable'  => __( 'The units could not be counted: the database refused the query. Nothing was written — that way no stock figure is invented.', 'ubac-serials' ),
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
	public static function plan_one( $product_id, array $free = null, array $reserved = null, array $known = null ) {

		$product_id = (int) $product_id;

		/* AN UNREADABLE MAP IS A REFUSAL, NOT A ZERO.
		 *
		 * This is the channel that already exists for "there is no number for us
		 * to write on this row", and push() honours it without writing anything.
		 * Sending it through there rather than through an exception also gives,
		 * for free, the right sentence on the resynchronisation screen: the row
		 * says why it is not moving. */
		try {
			$free     = null === $free ? self::free_map() : $free;
			$reserved = null === $reserved ? self::reserved_map() : $reserved;
		} catch ( Throwable $e ) { // phpcs:ignore PHPCompatibility.Classes.NewClasses.throwableFound -- guarded by Requires PHP 7.4.
			return array(
				'product_id' => $product_id,
				'name'       => '',
				'sku'        => '',
				'in_stock'   => 0,
				'reserved'   => 0,
				'wanted'     => 0,
				'before'     => null,
				'gap'        => 0,
				'refusal'      => 'counts_unreadable',
				'changes'      => false,
				'never_filed'  => false,
			);
		}

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

		/* "TICKED, AND NEVER ONE SINGLE UNIT" IS NOT THE SAME EVIDENCE.
		 *
		 * Two rows can say `wanted = 0` for reasons that have nothing to do with
		 * each other:
		 *
		 * - a product whose units have all gone. The table carries its rows, the
		 *   zero is measured, and writing it is this screen's very job;
		 * - a product just ticked "serialised" while being prepared, against
		 *   which no unit has ever been recorded. The zero is not measured: it is
		 *   the absence of a measurement. Writing it takes the product off sale,
		 *   which is intended, but it is a decision of another kind -- and it is
		 *   taken on a product the shop may still be preparing.
		 *
		 * The flag does push the stock in both cases: that is the contract that
		 * was chosen. But the screen must be able to tell them apart, or whoever
		 * approves is approving four rows that look identical, of which only two
		 * rest on real units.
		 *
		 * `known_map()` counts the table's rows whatever their status (see that
		 * method): zero there means "never one unit", and it is the only source
		 * that knows. Without the map the field is `false` rather than falsely
		 * true -- a caller that does not pass it must not make the screen believe
		 * every one of its products is new. */
		$jamais_fichee = null !== $known
			&& isset( $known[ $product_id ] )
			&& 0 === (int) $known[ $product_id ]
			&& 0 === $in_stock
			&& 0 === $held_back;

		return array(
			'product_id'  => $product_id,
			'name'        => $product ? $product->get_name() : '',
			'sku'         => $product ? $product->get_sku() : '',
			'in_stock'    => $in_stock,
			'reserved'    => $held_back,
			'wanted'      => $wanted,
			'before'      => null === $before ? null : (int) $before,
			'gap'         => $negative_gap,
			'refusal'     => $refusal,
			'changes'     => '' === $refusal && (int) $before !== (int) $wanted,
			'never_filed' => $jamais_fichee,
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
						'ubac-serials: stock push failed for product %d: %s',
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

		/* THE SCREEN MUST BE ABLE TO SAY "I COULD NOT COUNT".
		 *
		 * Without this third state, an empty plan displays "no serialised
		 * product carries a unit yet, so there is nothing to compare" -- the most
		 * reassuring sentence possible in front of a broken database. The message
		 * is therefore passed up as it is, and the screen shows it as an
		 * error. */
		try {
			$free     = self::free_map();
			$reserved = self::reserved_map();
			$stuck    = self::stuck_map();
			$connus   = self::known_map();
		} catch ( Throwable $e ) { // phpcs:ignore PHPCompatibility.Classes.NewClasses.throwableFound -- guarded by Requires PHP 7.4.
			/* THE SCREEN SAYS "THE ERROR IS IN THE SITE LOG": IT HAD BETTER BE.
			 *
			 * Only flush() logged. The resynchronisation screen therefore sent the
			 * reader to an empty log. */
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				'ubac-serials: stock maps unreadable, resynchronisation plan empty: ' . $e->getMessage()
			);

			return array(
				'rows'        => array(),
				'changing'    => 0,
				'refused'     => 0,
				'fingerprint' => '',
				'cartes'      => null,
				'erreur'      => $e->getMessage(),
			);
		}

		/* known_map() COMES IN HERE, AND THAT IS THE WHOLE POINT.
		 * Without it, a product whose last unit has gone has a key in neither of
		 * the first two maps, so no plan row, so no gap shown -- while
		 * WooCommerce is still selling it. */
		$ids = array_values( array_unique( array_merge( array_keys( $free ), array_keys( $reserved ), array_keys( $connus ) ) ) );
		sort( $ids );

		$rows     = array();
		$changing = 0;
		$refused  = 0;

		foreach ( $ids as $product_id ) {
			$row          = self::plan_one( $product_id, $free, $reserved, $connus );
			$row['stuck'] = isset( $stuck[ $product_id ] ) ? (int) $stuck[ $product_id ] : 0;

			if ( '' !== $row['refusal'] ) {
				++$refused;
			} elseif ( $row['changes'] ) {
				++$changing;
			}

			$rows[] = $row;
		}

		return array(
			'cartes'      => array(
				'free'     => $free,
				'reserved' => $reserved,
			),
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

		/* A BROKEN DATABASE IS SAID BEFORE THE FINGERPRINT, NOT AFTER.
		 *
		 * The other way round, a failed plan returns an empty fingerprint, so the
		 * comparison fell first and answered "Stock changed since this list was
		 * drawn up": the reassuring sentence, moved up one floor. And the branch
		 * below was reachable only with an empty submitted fingerprint, which is
		 * to say almost never. */
		if ( isset( $plan['erreur'] ) ) {
			return new WP_Error(
				'ubac_serials_counts_unreadable',
				__( 'The units could not be counted: the database refused the query. Nothing was written.', 'ubac-serials' )
			);
		}

		if ( (string) $fingerprint !== $plan['fingerprint'] ) {
			return new WP_Error(
				'ubac_serials_stock_moved',
				__( 'Stock changed since this list was drawn up. Here it is again — read it and run it once more.', 'ubac-serials' )
			);
		}

		/* THE PLAN'S OWN MAPS, NOT A SECOND READING.
		 *
		 * The fingerprint was worked out on a first reading, then the maps were
		 * READ AGAIN and it was the second reading that supplied the number
		 * written. An order landing between the two had a number written other
		 * than the one shown and approved -- the exact opposite of what
		 * fingerprint()'s docblock promises -- and it was duplicated work into
		 * the bargain. */
		$free     = $plan['cartes']['free'];
		$reserved = $plan['cartes']['reserved'];

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
