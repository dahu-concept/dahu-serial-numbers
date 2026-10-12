<?php
/**
 * The one door: provider resolution, lockout, and the REST adapters.
 *
 * ## Why the REST adapters live here
 *
 * In the plugin this was extracted from they sat in the REST controller, as
 * three static methods that did nothing but call this class. The permission
 * callback of every protected route was then handed around like this:
 *
 *     $v1   = 'Dahu_Serials_Rest_Controller';
 *     $auth = array( $v1, 'check_auth' );
 *
 * and passed to fifteen routes. That is the shape that cost this port a
 * measurement: a dependency map built from the tokenizer saw two references
 * to the controller and no edge at all to the authentication layer, because
 * a class name travelling inside a string is invisible to anything reading
 * code as code. A plan drawn on that map would have shipped an API with no
 * access control and no failing test to say so.
 *
 * Moving the fifty-eight lines back onto the class they already delegated to
 * does not make the code shorter. It makes the callable name the thing it
 * authorises, so a reader — and a tool — sees what guards the routes.
 *
 * ## What the lockout does and does not do
 *
 * Five failures on one login close it for ten minutes. That caps guessing on
 * a known login, and it also caps how much password hashing an unauthenticated
 * caller can make the server do, which matters more than it looks: hashing is
 * deliberately slow, so an endpoint that hashes on demand is an endpoint that
 * can be used to load the site.
 *
 * It does not stop somebody spraying one password across many logins, because
 * the counter is per login. Keying on the caller's address instead would be
 * worse than useless here: every bench in a workshop shares one address, so
 * one person fumbling a password would lock out the whole floor.
 *
 * @package DahuSerialNumbers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Authentication facade.
 */
class Dahu_Serial_Numbers_Auth {

	/**
	 * Failures allowed on one login before it is locked.
	 */
	const MAX_ATTEMPTS = 5;

	/**
	 * How long a locked login stays locked, in seconds.
	 */
	const LOCKOUT_WINDOW = 600;

	/**
	 * Name of the request parameter the signed-in post id is written to.
	 *
	 * A route handler reads the post id from here rather than re-checking the
	 * token: the permission callback has already done it, and doing it twice
	 * is how the two answers come to disagree.
	 */
	const REQUEST_PARAM = 'dahu_serial_numbers_station_id';

	/**
	 * Resolved provider.
	 *
	 * @var Dahu_Serial_Numbers_Auth_Provider|null
	 */
	private static $provider = null;

	/**
	 * Hooks.
	 *
	 * One, and it exists so that a credential stops working when somebody
	 * decides it should, rather than when it happens to run out. The daily
	 * purge is registered by the bootstrap instead, because it has no reason
	 * to wait for WooCommerce.
	 *
	 * @return void
	 */
	public static function init() {

		add_action( 'dahu_serial_numbers_station_credentials_revoked', array( __CLASS__, 'on_credentials_revoked' ) );
	}

	/**
	 * A post was switched off, or its password was replaced.
	 *
	 * The store fires this rather than calling the token layer itself: a
	 * store that knows about authentication is a store that cannot be read
	 * on its own, and this way a shop that wants to log the event or warn
	 * somebody hooks the same action.
	 *
	 * @param int $station_id Post id.
	 * @return void
	 */
	public static function on_credentials_revoked( $station_id ) {

		self::revoke_station_tokens( (int) $station_id );
	}

	/**
	 * Drops every token held by one post.
	 *
	 * @param int $station_id Post id.
	 * @return int
	 */
	public static function revoke_station_tokens( $station_id ) {

		return (int) self::get_provider()->revoke_station_tokens( (int) $station_id );
	}

	/**
	 * Drops every expired token. Target of the daily event.
	 *
	 * @return int
	 */
	public static function purge_expired_tokens() {

		return (int) self::get_provider()->purge_expired_tokens();
	}

	/**
	 * The active provider.
	 *
	 * @return Dahu_Serial_Numbers_Auth_Provider
	 */
	public static function get_provider() {

		if ( null !== self::$provider ) {
			return self::$provider;
		}

		/**
		 * Filters the authentication backend.
		 *
		 * @param Dahu_Serial_Numbers_Auth_Provider $provider Default backend.
		 */
		$provider = apply_filters(
			'dahu_serial_numbers_auth_provider',
			new Dahu_Serial_Numbers_Station_Auth_Provider()
		);

		// A filter that returns something that is not a provider gets ignored
		// rather than obeyed. The alternative is a fatal error on a packing
		// screen, from a one-line mistake in a child theme.
		if ( ! $provider instanceof Dahu_Serial_Numbers_Auth_Provider ) {
			$provider = new Dahu_Serial_Numbers_Station_Auth_Provider();
		}

		self::$provider = $provider;

		return self::$provider;
	}

	/**
	 * Forgets the resolved provider.
	 *
	 * For tests, and for a site that swaps the backend after this class has
	 * already answered once in the same request.
	 *
	 * @return void
	 */
	public static function reset_provider() {

		self::$provider = null;
	}

	/**
	 * Signs a post in.
	 *
	 * @param string $login    Login.
	 * @param string $password Password.
	 * @return array{token:string,station_id:int}|WP_Error
	 */
	public static function login( $login, $password ) {

		$login = trim( (string) $login );

		if ( self::is_locked( $login ) ) {
			return new WP_Error(
				'dahu_serial_numbers_auth_locked',
				sprintf(
					/* translators: %d: number of minutes. */
					_n(
						'Too many failed attempts. Try again in %d minute.',
						'Too many failed attempts. Try again in %d minutes.',
						(int) ceil( self::lockout_window() / 60 ),
						'dahu-serial-numbers'
					),
					(int) ceil( self::lockout_window() / 60 )
				),
				array( 'status' => 429 )
			);
		}

		$station_id = self::get_provider()->authenticate( $login, $password );

		if ( is_wp_error( $station_id ) ) {
			self::register_failure( $login );

			return $station_id;
		}

		self::clear_failures( $login );

		$token = self::get_provider()->issue_token( (int) $station_id );

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		return array(
			'token'      => $token,
			'station_id' => (int) $station_id,
		);
	}

	/**
	 * Signs a post out.
	 *
	 * @param string $token Raw token.
	 * @return true|WP_Error
	 */
	public static function logout( $token ) {

		return self::get_provider()->revoke_token( $token );
	}

	/**
	 * Checks a token.
	 *
	 * @param string $token Raw token.
	 * @return int|WP_Error Post id.
	 */
	public static function verify_token( $token ) {

		return self::get_provider()->verify_token( $token );
	}

	/**
	 * Pulls the bearer token out of a request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string
	 */
	public static function extract_token( WP_REST_Request $request ) {

		$header = (string) $request->get_header( 'authorization' );

		if ( 0 === stripos( $header, 'Bearer ' ) ) {
			return trim( substr( $header, 7 ) );
		}

		return '';
	}

	/* ---------------------------------------------------------------------
	 * REST adapters
	 *
	 * These three are the whole public surface of authentication. Routes name
	 * them; nothing else does.
	 * ------------------------------------------------------------------ */

	/**
	 * Permission callback for every route but sign-in.
	 *
	 * Returns the provider's own WP_Error when it refuses, so the caller is
	 * told whether the token was missing, wrong, expired, or belongs to a
	 * post that has been switched off. Collapsing those into one message
	 * would turn "your session ran out" into "something went wrong", on a
	 * screen whose user cannot read a log.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function check_rest_request( WP_REST_Request $request ) {

		$station_id = self::verify_token( self::extract_token( $request ) );

		if ( is_wp_error( $station_id ) ) {
			return $station_id;
		}

		$request->set_param( self::REQUEST_PARAM, (int) $station_id );

		return true;
	}

	/**
	 * The post id a route handler is running as.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return int 0 when the request did not go through the permission callback.
	 */
	public static function station_id_from_request( WP_REST_Request $request ) {

		return (int) $request->get_param( self::REQUEST_PARAM );
	}

	/**
	 * POST /auth/login
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_login( WP_REST_Request $request ) {

		$result = self::login(
			(string) $request->get_param( 'login' ),
			(string) $request->get_param( 'password' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			array(
				'token'         => $result['token'],
				'station_id'    => $result['station_id'],
				'station_label' => Dahu_Serial_Numbers_Stations::label( $result['station_id'] ),
			),
			200
		);
	}

	/**
	 * POST /auth/logout
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function rest_logout( WP_REST_Request $request ) {

		self::logout( self::extract_token( $request ) );

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	/* ---------------------------------------------------------------------
	 * Lockout
	 * ------------------------------------------------------------------ */

	/**
	 * Failures allowed before a login is locked.
	 *
	 * @return int
	 */
	public static function max_attempts() {

		/**
		 * Filters how many failed sign-ins a login gets.
		 *
		 * @param int $attempts Attempts.
		 */
		$attempts = (int) apply_filters( 'dahu_serial_numbers_auth_max_attempts', self::MAX_ATTEMPTS );

		// Nothing below one: a filter returning 0 would lock every login on
		// its first failure, which reads from the outside exactly like the
		// plugin being broken.
		return max( 1, $attempts );
	}

	/**
	 * How long a locked login stays locked.
	 *
	 * @return int Seconds.
	 */
	public static function lockout_window() {

		/**
		 * Filters the lockout window, in seconds.
		 *
		 * @param int $window Seconds.
		 */
		$window = (int) apply_filters( 'dahu_serial_numbers_auth_lockout_window', self::LOCKOUT_WINDOW );

		return max( 60, $window );
	}

	/**
	 * Transient key for a login's failure counter.
	 *
	 * The login is hashed rather than used as it stands, for two reasons: it
	 * may contain characters a transient key may not, and a key is a value
	 * that ends up in a cache dump.
	 *
	 * @param string $login Login.
	 * @return string
	 */
	private static function attempts_key( $login ) {

		return 'dahu_sn_login_fail_' . md5( strtolower( $login ) );
	}

	/**
	 * Is this login locked right now?
	 *
	 * @param string $login Login.
	 * @return bool
	 */
	public static function is_locked( $login ) {

		$login = trim( (string) $login );

		if ( '' === $login ) {
			return false;
		}

		return (int) get_transient( self::attempts_key( $login ) ) >= self::max_attempts();
	}

	/**
	 * Counts a failure, and restarts the window.
	 *
	 * The window restarts on each failure on purpose: an attacker pacing
	 * attempts to stay just under the limit should not get a fresh allowance
	 * for free.
	 *
	 * @param string $login Login.
	 * @return void
	 */
	private static function register_failure( $login ) {

		$login = trim( (string) $login );

		if ( '' === $login ) {
			return;
		}

		$key   = self::attempts_key( $login );
		$count = (int) get_transient( $key );

		set_transient( $key, $count + 1, self::lockout_window() );
	}

	/**
	 * Clears a login's counter.
	 *
	 * @param string $login Login.
	 * @return void
	 */
	public static function clear_failures( $login ) {

		$login = trim( (string) $login );

		if ( '' === $login ) {
			return;
		}

		delete_transient( self::attempts_key( $login ) );
	}
}
