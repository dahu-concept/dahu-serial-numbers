<?php
/**
 * The API the workshop screen talks to.
 *
 * ## Eight routes, down from eighteen
 *
 * The plugin this was extracted from had two namespaces, v1 and v3, with
 * eighteen routes between them, several of which existed only because the
 * first version of the screen had asked for a slightly different shape. Some
 * were dead: a `declare` endpoint had been removed from the screen and left
 * registered, and twelve rows had been created through it before anybody
 * noticed. An endpoint nothing calls is not harmless — it is an unauthenticated
 * surface that no longer has a reader.
 *
 * So this is eight, every one of them used by the screen that ships with the
 * plugin, and the two that read an order answer with one shape rather than
 * three variants of it.
 *
 * ## What guards them
 *
 * Every route but sign-in names `Dahu_Serial_Numbers_Auth::check_rest_request`
 * as its permission callback — the authentication class itself, not a wrapper
 * in this file. That is deliberate and it is the whole point of extraction
 * number one: in the source these routes were guarded by
 * `array( 'Dahu_Serials_Rest_Controller', 'check_auth' )`, a class name held
 * in a variable, which a dependency map reading code as code cannot see. The
 * map reported two references to the controller and no edge at all to the
 * authentication layer.
 *
 * Sign-in is `__return_true`, which is correct and is the one place in the
 * plugin where it is: an endpoint whose job is to issue the credential cannot
 * require the credential. What protects it is the lockout in the auth class.
 *
 * @package DahuSerialNumbers
 */

defined( 'ABSPATH' ) || exit;

/**
 * REST routes for the workshop screen.
 */
class Dahu_Serial_Numbers_Rest {

	/**
	 * Namespace. Versioned, because a screen in a browser cache outlives a
	 * deploy and an API that changes shape under it fails in a way nobody
	 * can read.
	 */
	const REST_NAMESPACE = 'dahu-serial-numbers/v1';

	/**
	 * Orders per page when the caller does not say.
	 */
	const DEFAULT_PAGE_SIZE = 20;

	/**
	 * Most orders one page may hold, whatever was asked for.
	 */
	const MAX_PAGE_SIZE = 100;

	/**
	 * Registers the hook.
	 *
	 * @return void
	 */
	public static function init() {

		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Declares the routes.
	 *
	 * @return void
	 */
	public static function register_routes() {

		$guard = array( 'Dahu_Serial_Numbers_Auth', 'check_rest_request' );

		register_rest_route(
			self::REST_NAMESPACE,
			'/auth/login',
			array(
				'methods'  => WP_REST_Server::CREATABLE,
				'callback' => array( 'Dahu_Serial_Numbers_Auth', 'rest_login' ),
				// The one endpoint that cannot ask for the credential it
				// issues. Rate-limited in Dahu_Serial_Numbers_Auth::login().
				'permission_callback' => '__return_true',
				'args'                => array(
					'login'    => array(
						'required' => true,
						'type'     => 'string',
					),
					'password' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/auth/logout',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( 'Dahu_Serial_Numbers_Auth', 'rest_logout' ),
				'permission_callback' => $guard,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/orders',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'orders' ),
				'permission_callback' => $guard,
				'args'                => array(
					'q'      => array(
						'required' => false,
						'type'     => 'string',
					),
					'status' => array(
						'required' => false,
						'type'     => 'string',
					),
					'limit'  => array(
						'required' => false,
						'type'     => 'integer',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/orders/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'order' ),
				'permission_callback' => $guard,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/orders/(?P<id>\d+)/serials',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'assign' ),
				'permission_callback' => $guard,
				'args'                => array(
					'serial'        => array(
						'required' => true,
						'type'     => 'string',
					),
					'order_item_id' => array(
						'required' => true,
						'type'     => 'integer',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/orders/(?P<id>\d+)/serials/(?P<serial>[^/]+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'unassign' ),
				'permission_callback' => $guard,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/orders/(?P<id>\d+)/shipment',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'ship' ),
				'permission_callback' => $guard,
				'args'                => array(
					'carrier'  => array(
						'required' => false,
						'type'     => 'string',
					),
					'tracking' => array(
						'required' => false,
						'type'     => 'string',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/serials/(?P<serial>[^/]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'serial' ),
				'permission_callback' => $guard,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/serials/(?P<serial>[^/]+)/checks',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'check' ),
				'permission_callback' => $guard,
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Handlers
	 * ------------------------------------------------------------------ */

	/**
	 * GET /orders
	 *
	 * With no `q`, the orders waiting to be packed. With a `q`, a search —
	 * order number, customer name, email, whatever WooCommerce's own order
	 * search looks at. The search goes through `wc_get_orders()` rather than
	 * through SQL of its own, which is what makes it work the same on the
	 * legacy order tables and on HPOS.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function orders( WP_REST_Request $request ) {

		$query = (string) $request->get_param( 'q' );
		$limit = self::page_size( $request->get_param( 'limit' ) );

		$status = self::validate_status( $request->get_param( 'status' ) );

		if ( is_wp_error( $status ) ) {
			return $status;
		}

		$args = array(
			'limit'   => $limit,
			'orderby' => 'date',
			'order'   => 'DESC',
			'status'  => $status ? $status : self::default_statuses(),
		);

		if ( '' !== trim( $query ) ) {
			$args['s'] = trim( $query );
		}

		$orders  = wc_get_orders( $args );
		$payload = array();

		foreach ( (array) $orders as $order ) {
			if ( $order instanceof WC_Order ) {
				$payload[] = self::order_summary( $order );
			}
		}

		return new WP_REST_Response(
			array(
				'orders' => $payload,
				'count'  => count( $payload ),
			),
			200
		);
	}

	/**
	 * GET /orders/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function order( WP_REST_Request $request ) {

		$order = self::order_or_error( (int) $request->get_param( 'id' ) );

		if ( is_wp_error( $order ) ) {
			return $order;
		}

		return new WP_REST_Response( array( 'order' => self::order_detail( $order ) ), 200 );
	}

	/**
	 * POST /orders/{id}/serials
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function assign( WP_REST_Request $request ) {

		$order = self::order_or_error( (int) $request->get_param( 'id' ) );

		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$order_item_id = (int) $request->get_param( 'order_item_id' );
		$item          = $order->get_item( $order_item_id );

		// Two refusals in one test, and both matter. A line id that is not on
		// this order returns false — that is the common case, a stale screen
		// or a typed id. A line id that IS on the order but is not a product
		// line — a shipping line, a fee, a coupon — is the one that would go
		// on to call get_variation_id() on an object that has no such method,
		// and take the request down with a fatal rather than a 404.
		if ( ! $item instanceof WC_Order_Item_Product ) {
			return new WP_Error(
				'dahu_serial_numbers_item_not_found',
				__( 'That order line cannot carry a serial number.', 'dahu-serial-numbers' ),
				array( 'status' => 404 )
			);
		}

		$serial = Dahu_Serial_Numbers_Format::normalise( (string) $request->get_param( 'serial' ) );

		if ( '' === $serial ) {
			return new WP_Error(
				'dahu_serial_numbers_empty_serial',
				__( 'The serial number is empty or unreadable.', 'dahu-serial-numbers' ),
				array( 'status' => 400 )
			);
		}

		// A line that already has its full count takes no more. Found by
		// looking at the screen: a seeded order for two units showed "5 of 2",
		// because nothing anywhere refused the third scan. The parcel holds
		// two motors whatever the record says, so three serials on that line
		// means two of them are somewhere else — and the plugin had just
		// recorded that they were not.
		//
		// The test lives here rather than in the manager on purpose. The
		// manager is the primitive, and a migration or an import has every
		// right to write whatever a shop's history actually was. This is the
		// endpoint a person scans into, and a person scanning a third unit
		// onto a line of two has made a mistake.
		$full = self::assigned_count( $order, $order_item_id );

		if ( $full >= (int) $item->get_quantity() ) {
			return new WP_Error(
				'dahu_serial_numbers_line_full',
				sprintf(
					/* translators: %d: quantity ordered on that line. */
					_n(
						'That line is for %d unit and already has its serial number.',
						'That line is for %d units and already has all of them.',
						(int) $item->get_quantity(),
						'dahu-serial-numbers'
					),
					(int) $item->get_quantity()
				),
				array( 'status' => 409 )
			);
		}

		$row = Dahu_Serial_Numbers_Manager::get( $serial );

		if ( ! $row ) {
			return new WP_Error(
				'dahu_serial_numbers_not_found',
				sprintf(
					/* translators: %s: serial number. */
					__( 'Unknown serial number: %s', 'dahu-serial-numbers' ),
					$serial
				),
				array( 'status' => 404 )
			);
		}

		// Already on an order is its own answer, and a different one from
		// "wrong status": the person at the bench needs to know whether they
		// have scanned a unit that belongs to somebody else's parcel, which
		// is a mistake to undo, or one that is in repair, which is not.
		if ( in_array( $row['status'], array( Dahu_Serial_Numbers_Statuses::ASSIGNED, Dahu_Serial_Numbers_Statuses::SHIPPED ), true ) ) {
			$same_order = (int) $row['order_id'] === $order->get_id();

			return new WP_Error(
				'dahu_serial_numbers_already_assigned',
				$same_order
					? sprintf(
						/* translators: %s: serial number. */
						__( 'Serial number %s is already on this order.', 'dahu-serial-numbers' ),
						$serial
					)
					: sprintf(
						/* translators: %s: serial number. */
						__( 'Serial number %s is on another order.', 'dahu-serial-numbers' ),
						$serial
					),
				array(
					'status'   => 409,
					'order_id' => (int) $row['order_id'],
				)
			);
		}

		if ( Dahu_Serial_Numbers_Statuses::IN_STOCK !== $row['status'] ) {
			return new WP_Error(
				'dahu_serial_numbers_wrong_status',
				sprintf(
					/* translators: 1: serial number, 2: current status label. */
					__( 'Serial number %1$s is not in stock — it is currently “%2$s”.', 'dahu-serial-numbers' ),
					$serial,
					Dahu_Serial_Numbers_Statuses::label( (string) $row['status'] )
				),
				array( 'status' => 409 )
			);
		}

		$line_product_id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
		$warning         = '';

		// A warning, not a refusal. The unit in somebody's hand is the unit
		// going in the box, and a plugin that refused on a product mismatch
		// would be refusing a box that is correct and a mapping that is not.
		// It says so, and lets the person decide.
		if ( ! empty( $row['product_id'] ) && Dahu_Serial_Numbers_Products::resolve( (int) $row['product_id'] ) !== Dahu_Serial_Numbers_Products::resolve( (int) $line_product_id ) ) {
			$warning = __( 'This serial number is on file under a different product from this order line. Check before carrying on.', 'dahu-serial-numbers' );
		}

		$assigned = Dahu_Serial_Numbers_Manager::transition(
			$serial,
			Dahu_Serial_Numbers_Statuses::ASSIGNED,
			array(
				'event'         => 'workshop_assign',
				'order_id'      => $order->get_id(),
				'order_item_id' => $order_item_id,
				'station_id'    => Dahu_Serial_Numbers_Auth::station_id_from_request( $request ),
			)
		);

		if ( is_wp_error( $assigned ) ) {
			return new WP_Error(
				'dahu_serial_numbers_transition_failed',
				$assigned->get_error_message(),
				array( 'status' => 409 )
			);
		}

		$response = array(
			'success' => true,
			'serial'  => $serial,
			'status'  => Dahu_Serial_Numbers_Statuses::ASSIGNED,
			'order'   => self::order_detail( $order ),
		);

		if ( '' !== $warning ) {
			$response['warning'] = $warning;
		}

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * How many units one order line already has.
	 *
	 * Counts `assigned` and `shipped`, like `missing_for_order()` does, so
	 * the two agree about what "done" means. A unit that came back and went
	 * to repair is not on this line any more and does not count.
	 *
	 * @param WC_Order $order         Order.
	 * @param int      $order_item_id Order line.
	 * @return int
	 */
	private static function assigned_count( WC_Order $order, $order_item_id ) {

		$done  = array( Dahu_Serial_Numbers_Statuses::ASSIGNED, Dahu_Serial_Numbers_Statuses::SHIPPED );
		$count = 0;

		foreach ( Dahu_Serial_Numbers_Manager::get_by_order( $order->get_id() ) as $row ) {
			if ( (int) $row['order_item_id'] === (int) $order_item_id && in_array( $row['status'], $done, true ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * DELETE /orders/{id}/serials/{serial}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function unassign( WP_REST_Request $request ) {

		$order = self::order_or_error( (int) $request->get_param( 'id' ) );

		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$serial = Dahu_Serial_Numbers_Format::normalise( (string) $request->get_param( 'serial' ) );
		$row    = Dahu_Serial_Numbers_Manager::get( $serial );

		// One refusal for "no such serial" and for "not on this order",
		// because from this endpoint they are the same request: take this
		// number off this order, and it was not on it.
		if ( ! $row || (int) $row['order_id'] !== $order->get_id() ) {
			return new WP_Error(
				'dahu_serial_numbers_not_on_this_order',
				sprintf(
					/* translators: %s: serial number. */
					__( 'Serial number %s is not on this order.', 'dahu-serial-numbers' ),
					$serial
				),
				array( 'status' => 404 )
			);
		}

		$freed = Dahu_Serial_Numbers_Manager::transition(
			$serial,
			Dahu_Serial_Numbers_Statuses::IN_STOCK,
			array(
				'event'      => 'workshop_unassign',
				'station_id' => Dahu_Serial_Numbers_Auth::station_id_from_request( $request ),
			)
		);

		if ( is_wp_error( $freed ) ) {
			return new WP_Error(
				'dahu_serial_numbers_transition_failed',
				$freed->get_error_message(),
				array( 'status' => 409 )
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'order'   => self::order_detail( $order ),
			),
			200
		);
	}

	/**
	 * POST /orders/{id}/shipment
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function ship( WP_REST_Request $request ) {

		$order = self::order_or_error( (int) $request->get_param( 'id' ) );

		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$result = Dahu_Serial_Numbers_Shipment::ship(
			$order,
			array(
				'carrier'    => (string) $request->get_param( 'carrier' ),
				'tracking'   => (string) $request->get_param( 'tracking' ),
				'station_id' => Dahu_Serial_Numbers_Auth::station_id_from_request( $request ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$payload = array(
			'success' => true,
			'shipped' => $result['shipped'],
			'status'  => $result['status'],
		);

		// The parcel went, but the carrier did not answer. Said on the screen
		// rather than only in the order notes: the person at the bench is the
		// one who can still do something about it, and in five minutes they
		// will be at the next parcel.
		if ( '' !== $result['label_error'] ) {
			$payload['warning'] = sprintf(
				/* translators: %s: the carrier's error message. */
				__( 'Recorded as shipped, but the carrier could not create the label: %s', 'dahu-serial-numbers' ),
				$result['label_error']
			);
		}

		return new WP_REST_Response( $payload, 200 );
	}

	/**
	 * GET /serials/{serial}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function serial( WP_REST_Request $request ) {

		// Normalised at the edge, which is where input belongs. The manager
		// normalises too, so this call changes no lookup — what it changes is
		// the refusal: the message names the serial the plugin actually went
		// looking for, not the string as it arrived. A person reading
		// "unknown serial number: ?series=zg an06001" learns something; one
		// reading their own typo back learns nothing.
		$serial = Dahu_Serial_Numbers_Format::normalise( (string) $request->get_param( 'serial' ) );
		$row    = Dahu_Serial_Numbers_Manager::get( $serial );

		if ( ! $row ) {
			return new WP_Error(
				'dahu_serial_numbers_not_found',
				sprintf(
					/* translators: %s: serial number. */
					__( 'Unknown serial number: %s', 'dahu-serial-numbers' ),
					$serial
				),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response(
			array(
				'serial'  => self::serial_payload( $row ),
				'history' => self::history_payload( $serial ),
			),
			200
		);
	}

	/**
	 * POST /serials/{serial}/checks
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function check( WP_REST_Request $request ) {

		$serial  = Dahu_Serial_Numbers_Format::normalise( (string) $request->get_param( 'serial' ) );
		$checked = Dahu_Serial_Numbers_Manager::mark_checked( $serial );

		if ( is_wp_error( $checked ) ) {
			$checked->add_data( array( 'status' => 404 ) );

			return $checked;
		}

		$row = Dahu_Serial_Numbers_Manager::get( $serial );

		return new WP_REST_Response(
			array(
				'success'    => true,
				'serial'     => $serial,
				'checked_at' => self::local( $row['checked_at'] ),
			),
			200
		);
	}

	/* ---------------------------------------------------------------------
	 * Payloads
	 * ------------------------------------------------------------------ */

	/**
	 * How many orders one page of the queue holds.
	 *
	 * A method rather than two expressions inside the handler, for one
	 * reason: a bound worth having is a bound worth asserting, and asserting
	 * this one through the endpoint needs a hundred and one orders in the
	 * database. The injection run said so — it removed the cap entirely and
	 * the bench stayed green, because the bench shop has a dozen orders and
	 * asking for five hundred returned a dozen either way.
	 *
	 * @param mixed $requested Raw `limit` parameter.
	 * @return int
	 */
	public static function page_size( $requested ) {

		$requested = (int) $requested;

		// The ceiling is not politeness. This endpoint is reachable with a
		// bench credential, and each order costs a WooCommerce load plus one
		// pass over its serials.
		return $requested > 0 ? min( $requested, self::MAX_PAGE_SIZE ) : self::DEFAULT_PAGE_SIZE;
	}

	/**
	 * The statuses the packing queue shows when nothing was asked for.
	 *
	 * @return string[]
	 */
	public static function default_statuses() {

		/**
		 * Filters the order statuses the packing queue lists by default.
		 *
		 * @param string[] $statuses WooCommerce status keys, with the wc- prefix.
		 */
		$statuses = (array) apply_filters( 'dahu_serial_numbers_queue_statuses', array( 'wc-processing' ) );

		return $statuses ? $statuses : array( 'wc-processing' );
	}

	/**
	 * A status parameter, checked against the statuses this shop actually has.
	 *
	 * @param mixed $status Raw parameter.
	 * @return string|null|WP_Error Status key, null when none was asked for.
	 */
	private static function validate_status( $status ) {

		$status = trim( (string) $status );

		if ( '' === $status ) {
			return null;
		}

		// Both spellings are accepted, because half of WooCommerce says
		// "processing" and the other half says "wc-processing", and a screen
		// author has no way to guess which one an endpoint wants.
		$prefixed = 0 === strpos( $status, 'wc-' ) ? $status : 'wc-' . $status;

		if ( ! array_key_exists( $prefixed, wc_get_order_statuses() ) ) {
			return new WP_Error(
				'dahu_serial_numbers_unknown_status',
				sprintf(
					/* translators: %s: status key. */
					__( 'Unknown order status: %s', 'dahu-serial-numbers' ),
					$status
				),
				array( 'status' => 400 )
			);
		}

		return $prefixed;
	}

	/**
	 * An order, or a 404.
	 *
	 * @param int $order_id Order id.
	 * @return WC_Order|WP_Error
	 */
	private static function order_or_error( $order_id ) {

		$order = $order_id > 0 ? wc_get_order( $order_id ) : false;

		if ( ! $order instanceof WC_Order ) {
			return new WP_Error(
				'dahu_serial_numbers_order_not_found',
				__( 'Order not found.', 'dahu-serial-numbers' ),
				array( 'status' => 404 )
			);
		}

		return $order;
	}

	/**
	 * One order, as the queue lists it.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string,mixed>
	 */
	private static function order_summary( WC_Order $order ) {

		$missing = Dahu_Serial_Numbers_Manager::missing_for_order( $order->get_id() );
		$owed    = 0;

		foreach ( $missing as $line ) {
			$owed += (int) $line['required'] - (int) $line['assigned'];
		}

		return array(
			'id'            => $order->get_id(),
			'number'        => $order->get_order_number(),
			'status'        => $order->get_status(),
			'status_label'  => wc_get_order_status_name( $order->get_status() ),
			'created_at'    => self::local( $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : '' ),
			'customer'      => trim( $order->get_formatted_billing_full_name() ),
			'item_count'    => (int) $order->get_item_count(),
			'serials_owed'  => $owed,
			// The one thing the queue is for: is this parcel ready to close?
			'serials_ready' => array() === $missing,
			'carrier'       => Dahu_Serial_Numbers_Shipment::carrier( $order ),
			'tracking'      => Dahu_Serial_Numbers_Shipment::tracking( $order ),
		);
	}

	/**
	 * One order, with its lines and their serials.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string,mixed>
	 */
	private static function order_detail( WC_Order $order ) {

		$detail       = self::order_summary( $order );
		$by_item      = Dahu_Serial_Numbers_Manager::get_by_order_items( array_keys( $order->get_items() ), $order->get_id() );
		$detail['lines'] = array();

		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$product_id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
			$product    = $item->get_product();
			$serialised = Dahu_Serial_Numbers_Products::is_serialised( $product_id );
			$serials    = array();

			foreach ( (array) $by_item[ $item_id ] as $row ) {
				$serials[] = array(
					'serial' => (string) $row['serial'],
					'status' => (string) $row['status'],
					'label'  => Dahu_Serial_Numbers_Statuses::label( (string) $row['status'] ),
				);
			}

			$detail['lines'][] = array(
				'order_item_id' => (int) $item_id,
				'product_id'    => (int) $product_id,
				// The line's own stored name, not the product's current one:
				// a product renamed last month must not rewrite what an order
				// placed last year says was bought.
				'name'          => (string) $item->get_name(),
				'sku'           => $product instanceof WC_Product ? (string) $product->get_sku() : '',
				'quantity'      => (int) $item->get_quantity(),
				'serialised'    => $serialised,
				'required'      => $serialised ? (int) $item->get_quantity() : 0,
				'serials'       => $serials,
			);
		}

		return $detail;
	}

	/**
	 * One serial's row, as the API shows it.
	 *
	 * @param array $row Serials table row.
	 * @return array<string,mixed>
	 */
	private static function serial_payload( array $row ) {

		$product_id = (int) $row['product_id'];
		$product    = $product_id ? wc_get_product( $product_id ) : false;

		return array(
			'serial'             => (string) $row['serial'],
			'status'             => (string) $row['status'],
			'status_label'       => Dahu_Serial_Numbers_Statuses::label( (string) $row['status'] ),
			'batch'              => (string) $row['supplier_order'],
			'supplier_name'      => (string) $row['supplier_name'],
			'manufactured_year'  => $row['manufactured_year'] ? (int) $row['manufactured_year'] : null,
			'manufactured_month' => $row['manufactured_month'] ? (int) $row['manufactured_month'] : null,
			'product_id'         => $product_id ? $product_id : null,
			'product_name'       => $product instanceof WC_Product ? $product->get_name() : '',
			'order_id'           => $row['order_id'] ? (int) $row['order_id'] : null,
			'received_at'        => self::local( $row['received_at'] ),
			'checked_at'         => self::local( $row['checked_at'] ),
			'updated_at'         => self::local( $row['updated_at'] ),
			'next'               => Dahu_Serial_Numbers_Statuses::next( (string) $row['status'] ),
		);
	}

	/**
	 * One serial's history, as the API shows it.
	 *
	 * @param string $serial Serial.
	 * @return array<int,array<string,mixed>>
	 */
	private static function history_payload( $serial ) {

		$payload = array();

		foreach ( Dahu_Serial_Numbers_Log::for_serial( $serial, 50 ) as $line ) {
			$payload[] = array(
				'event'      => (string) $line['event'],
				'old_status' => $line['old_status'] ? (string) $line['old_status'] : null,
				'new_status' => $line['new_status'] ? (string) $line['new_status'] : null,
				'order_id'   => $line['order_id'] ? (int) $line['order_id'] : null,
				'created_at' => self::local( $line['created_at'] ),
				'context'    => Dahu_Serial_Numbers_Log::context( $line ),
			);
		}

		return $payload;
	}

	/**
	 * A stored UTC datetime, as the shop's clock reads it.
	 *
	 * Every datetime in this plugin's tables is UTC, and this is the one
	 * place that undoes it. A screen on a bench shows the shop's time or it
	 * shows the wrong time.
	 *
	 * @param string|null $datetime Stored datetime, UTC.
	 * @return string|null
	 */
	private static function local( $datetime ) {

		if ( empty( $datetime ) ) {
			return null;
		}

		return get_date_from_gmt( (string) $datetime, 'Y-m-d H:i:s' );
	}
}
