<?php
/**
 * The shipped authentication backend: a login and password per bench.
 *
 * Tokens are a random string. What is stored is its SHA-256 hash, never the
 * string itself, so a dump of the token table does not let anybody sign in.
 * There is no JWT library, and that is a decision rather than an omission: a
 * signed token is useful when the signature has to be checked somewhere the
 * database is not reachable, which is never the case here. A dependency whose
 * only job is to sign a string for the site that issued it is a dependency
 * that will one day need updating for somebody else's advisory.
 *
 * @package DahuSerialNumbers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Login and password against the plugin's own posts table.
 */
class Dahu_Serial_Numbers_Station_Auth_Provider implements Dahu_Serial_Numbers_Auth_Provider {

	/**
	 * Default token lifetime, in seconds. Twelve hours: longer than a shift.
	 */
	const TOKEN_TTL = 43200;

	/**
	 * Shortest lifetime a filter may ask for, in seconds.
	 *
	 * A filter returning 0 or a negative number would issue tokens that are
	 * already expired, and the screen would show a sign-in loop with no
	 * message explaining it. One minute is short enough for a test and long
	 * enough to be usable.
	 */
	const MIN_TOKEN_TTL = 60;

	/**
	 * Token lifetime for this site.
	 *
	 * @return int Seconds.
	 */
	public function token_ttl() {

		/**
		 * Filters the bearer token lifetime, in seconds.
		 *
		 * A shop whose shifts are four hours long has no reason to hand out
		 * tokens that outlive the day.
		 *
		 * @param int $ttl Lifetime in seconds.
		 */
		$ttl = (int) apply_filters( 'dahu_serial_numbers_token_ttl', self::TOKEN_TTL );

		return max( self::MIN_TOKEN_TTL, $ttl );
	}

	/**
	 * Checks a login and password pair.
	 *
	 * ## On the timing of a failed sign-in
	 *
	 * An unknown login returns before any hashing happens, so it answers
	 * measurably faster than a known login with a wrong password. That leaks
	 * whether a given login exists, and it is left as it is on purpose.
	 *
	 * The leak is cheap: a post's login is printed on a label stuck to the
	 * bench it belongs to, so it was never a secret. The usual fix — hashing
	 * a throwaway value so both paths cost the same — would spend a bcrypt on
	 * every request to an endpoint that needs no authentication to reach,
	 * which hands an attacker a way to load the server for free. What does
	 * protect this endpoint is the lockout in `Dahu_Serial_Numbers_Auth`,
	 * which also caps how much hashing an attacker can make the site do.
	 *
	 * The error message is deliberately the same either way: whatever the
	 * timing says, the response does not confirm a login.
	 *
	 * @param string $login    Post login.
	 * @param string $password Post password.
	 * @return int|WP_Error Post id.
	 */
	public function authenticate( $login, $password ) {

		$login    = trim( (string) $login );
		$password = (string) $password;

		if ( '' === $login || '' === $password ) {
			return self::invalid_credentials();
		}

		$station = Dahu_Serial_Numbers_Stations::find_by_login( $login );

		if ( ! $station || (int) $station['active'] !== 1 ) {
			return self::invalid_credentials();
		}

		if ( ! wp_check_password( $password, (string) $station['password_hash'] ) ) {
			return self::invalid_credentials();
		}

		Dahu_Serial_Numbers_Stations::touch_login( (int) $station['id'] );

		return (int) $station['id'];
	}

	/**
	 * Issues a bearer token.
	 *
	 * @param int $station_id Post id.
	 * @return string|WP_Error Raw token, shown once.
	 */
	public function issue_token( $station_id ) {

		global $wpdb;

		$station_id = (int) $station_id;

		if ( ! Dahu_Serial_Numbers_Stations::is_active( $station_id ) ) {
			return new WP_Error(
				'dahu_serial_numbers_auth_unknown_station',
				__( 'Unknown workshop post.', 'dahu-serial-numbers' ),
				array( 'status' => 401 )
			);
		}

		$token = wp_generate_password( 48, false, false );

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
			Dahu_Serial_Numbers_Install::tokens_table(),
			array(
				'station_id' => $station_id,
				'token_hash' => hash( 'sha256', $token ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + $this->token_ttl() ),
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error(
				'dahu_serial_numbers_auth_token_failed',
				__( 'The sign-in token could not be issued.', 'dahu-serial-numbers' )
			);
		}

		return $token;
	}

	/**
	 * Checks a bearer token, and slides its deadline when it is getting close.
	 *
	 * The deadline moves forward only when less than half the lifetime is
	 * left. The version this was extracted from wrote a new deadline on every
	 * single request, which is one UPDATE per API call — on a packing screen
	 * that polls, that is a write every few seconds, for a value that changes
	 * nothing until the shift is nearly over. The guarantee is the same: a
	 * post that is being used is never signed out mid-shift.
	 *
	 * @param string $token Raw token.
	 * @return int|WP_Error Post id.
	 */
	public function verify_token( $token ) {

		global $wpdb;

		$token = trim( (string) $token );

		if ( '' === $token ) {
			return new WP_Error(
				'dahu_serial_numbers_auth_missing_token',
				__( 'Missing token.', 'dahu-serial-numbers' ),
				array( 'status' => 401 )
			);
		}

		$table = Dahu_Serial_Numbers_Install::tokens_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from $wpdb->prefix, own table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE token_hash = %s", hash( 'sha256', $token ) ), ARRAY_A );

		if ( ! $row ) {
			return new WP_Error(
				'dahu_serial_numbers_auth_invalid_token',
				__( 'Invalid token.', 'dahu-serial-numbers' ),
				array( 'status' => 401 )
			);
		}

		$now     = time();
		$expires = self::to_timestamp( (string) $row['expires_at'] );

		if ( $expires <= $now ) {
			// Drop it rather than leave it lying there: an expired token that
			// stays in the table is a string that only has to survive until
			// somebody widens the window.
			$wpdb->delete( $table, array( 'id' => (int) $row['id'] ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.

			return new WP_Error(
				'dahu_serial_numbers_auth_expired_token',
				__( 'Session expired, please sign in again.', 'dahu-serial-numbers' ),
				array( 'status' => 401 )
			);
		}

		$station_id = (int) $row['station_id'];

		if ( ! Dahu_Serial_Numbers_Stations::is_active( $station_id ) ) {
			return new WP_Error(
				'dahu_serial_numbers_auth_station_disabled',
				__( 'This workshop post has been deactivated.', 'dahu-serial-numbers' ),
				array( 'status' => 401 )
			);
		}

		$ttl = $this->token_ttl();

		if ( ( $expires - $now ) < ( $ttl / 2 ) ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
				$table,
				array( 'expires_at' => gmdate( 'Y-m-d H:i:s', $now + $ttl ) ),
				array( 'id' => (int) $row['id'] ),
				array( '%s' ),
				array( '%d' )
			);
		}

		return $station_id;
	}

	/**
	 * Drops a token.
	 *
	 * Always reports success, including for a token that was never valid: a
	 * sign-out that answers differently depending on whether the token
	 * existed is a way to test tokens.
	 *
	 * @param string $token Raw token.
	 * @return true
	 */
	public function revoke_token( $token ) {

		global $wpdb;

		$token = trim( (string) $token );

		if ( '' === $token ) {
			return true;
		}

		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
			Dahu_Serial_Numbers_Install::tokens_table(),
			array( 'token_hash' => hash( 'sha256', $token ) ),
			array( '%s' )
		);

		return true;
	}

	/**
	 * Drops every token whose deadline has passed.
	 *
	 * Called from a daily event. Without it the table only ever grows: every
	 * sign-in adds a row and nothing but this removes the ones nobody can use
	 * any more. On a shop with three benches that is a few rows a day, which
	 * is exactly why it would never be noticed until it was years of them.
	 *
	 * @return int Rows removed.
	 */
	public function purge_expired_tokens() {

		global $wpdb;

		$table = Dahu_Serial_Numbers_Install::tokens_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from $wpdb->prefix, own table.
		$removed = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE expires_at < %s", gmdate( 'Y-m-d H:i:s' ) ) );

		return (int) $removed;
	}

	/**
	 * Drops every token held by one post.
	 *
	 * Used when a post is deactivated or its password changed: the point of
	 * either is that whoever had the old credential stops working now, and a
	 * live token would keep them going for up to twelve hours.
	 *
	 * @param int $station_id Post id.
	 * @return int Rows removed.
	 */
	public function revoke_station_tokens( $station_id ) {

		global $wpdb;

		$removed = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
			Dahu_Serial_Numbers_Install::tokens_table(),
			array( 'station_id' => (int) $station_id ),
			array( '%d' )
		);

		return (int) $removed;
	}

	/**
	 * Reads a stored datetime as a UTC timestamp.
	 *
	 * Every datetime this plugin writes is UTC. Reading one back with a bare
	 * `strtotime()` reads it in whatever timezone PHP happens to be set to —
	 * WordPress sets UTC, and anything on the site may change it. Saying UTC
	 * out loud costs nothing and removes the question.
	 *
	 * @param string $datetime MySQL datetime, UTC.
	 * @return int
	 */
	private static function to_timestamp( $datetime ) {

		$timestamp = strtotime( $datetime . ' UTC' );

		return $timestamp ? (int) $timestamp : 0;
	}

	/**
	 * The one refusal a sign-in may answer with.
	 *
	 * @return WP_Error
	 */
	private static function invalid_credentials() {

		return new WP_Error(
			'dahu_serial_numbers_auth_invalid',
			__( 'Invalid credentials.', 'dahu-serial-numbers' ),
			array( 'status' => 401 )
		);
	}
}
