<?php
/**
 * Workshop posts: the store, and nothing else.
 *
 * A post is a place, not a person: a tablet on a packing bench, used by
 * whoever is packing. That distinction is why these rows are not WordPress
 * users — a user account carries a role, a capability set and an admin
 * session, and a credential that opens a public URL on a shop floor should
 * carry none of those.
 *
 * ## Why this class exists at all
 *
 * In the plugin this was extracted from, the same four queries on the same
 * table were written out three times: in the auth provider, in the admin
 * screen, and in the REST controller. They had already drifted — the screen
 * compared `active` as a string, the provider as an integer in one place and
 * a string in another. Every read and write of this table now goes through
 * here, so there is one spelling of each question.
 *
 * Nothing in this class hooks anything. It is a store.
 *
 * @package DahuSerialNumbers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Create, read and deactivate workshop posts.
 */
class Dahu_Serial_Numbers_Stations {

	/**
	 * Shortest password accepted for a post.
	 *
	 * Twelve, not the eight the extracted version asked for. The reasoning is
	 * worth keeping: this password opens an endpoint that is reachable
	 * without a WordPress session, the login is printed on a label stuck to
	 * the bench, and the lockout below is per login — so an attacker who
	 * knows the login gets a few hundred tries a day, indefinitely. Eight
	 * characters chosen by a human does not survive that. Twelve, generated,
	 * does, and the creation screen offers a generated one.
	 */
	const MIN_PASSWORD_LENGTH = 12;

	/**
	 * Creates a post.
	 *
	 * @param string $login    Login.
	 * @param string $password Password, clear text.
	 * @param string $label    Human name of the bench. Falls back to the login.
	 * @return int|WP_Error New post id.
	 */
	public static function create( $login, $password, $label = '' ) {

		global $wpdb;

		$login    = sanitize_user( (string) $login, true );
		$password = (string) $password;
		$label    = sanitize_text_field( (string) $label );

		if ( '' === $login ) {
			return new WP_Error(
				'dahu_serial_numbers_station_no_login',
				__( 'A login is required.', 'dahu-serial-numbers' )
			);
		}

		if ( strlen( $password ) < self::MIN_PASSWORD_LENGTH ) {
			return new WP_Error(
				'dahu_serial_numbers_station_weak_password',
				sprintf(
					/* translators: %d: minimum number of characters. */
					__( 'The password must be at least %d characters long.', 'dahu-serial-numbers' ),
					self::MIN_PASSWORD_LENGTH
				)
			);
		}

		if ( self::find_by_login( $login ) ) {
			return new WP_Error(
				'dahu_serial_numbers_station_exists',
				sprintf(
					/* translators: %s: login. */
					__( 'The login “%s” is already taken.', 'dahu-serial-numbers' ),
					$login
				)
			);
		}

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
			Dahu_Serial_Numbers_Install::stations_table(),
			array(
				'login'         => $login,
				'password_hash' => wp_hash_password( $password ),
				'label'         => '' !== $label ? $label : $login,
				'active'        => 1,
				'created_at'    => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%s', '%s', '%d', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error(
				'dahu_serial_numbers_station_insert_failed',
				__( 'The workshop post could not be created.', 'dahu-serial-numbers' )
			);
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Replaces a post's password.
	 *
	 * The version this was extracted from had no way to do this: changing a
	 * password meant creating a second post and abandoning the first, which
	 * leaves the history pointing at a row nobody uses. A password is going
	 * to leak — written on a sticky note, typed in front of a customer — and
	 * when it does, the post should keep its identity.
	 *
	 * @param int    $station_id Post id.
	 * @param string $password   New password, clear text.
	 * @return true|WP_Error
	 */
	public static function set_password( $station_id, $password ) {

		global $wpdb;

		$station_id = (int) $station_id;
		$password   = (string) $password;

		if ( ! self::get( $station_id ) ) {
			return new WP_Error(
				'dahu_serial_numbers_station_unknown',
				__( 'Unknown workshop post.', 'dahu-serial-numbers' )
			);
		}

		if ( strlen( $password ) < self::MIN_PASSWORD_LENGTH ) {
			return new WP_Error(
				'dahu_serial_numbers_station_weak_password',
				sprintf(
					/* translators: %d: minimum number of characters. */
					__( 'The password must be at least %d characters long.', 'dahu-serial-numbers' ),
					self::MIN_PASSWORD_LENGTH
				)
			);
		}

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
			Dahu_Serial_Numbers_Install::stations_table(),
			array( 'password_hash' => wp_hash_password( $password ) ),
			array( 'id' => $station_id ),
			array( '%s' ),
			array( '%d' )
		);

		self::credentials_revoked( $station_id );

		return true;
	}

	/**
	 * Activates or deactivates a post.
	 *
	 * There is no delete, on purpose. The history table points at post ids,
	 * and a row that answers "who packed this parcel" with a missing id
	 * answers nothing. Deactivating refuses every future token while leaving
	 * the past readable.
	 *
	 * @param int  $station_id Post id.
	 * @param bool $active     Wanted state.
	 * @return true|WP_Error
	 */
	public static function set_active( $station_id, $active ) {

		global $wpdb;

		$station_id = (int) $station_id;

		if ( ! self::get( $station_id ) ) {
			return new WP_Error(
				'dahu_serial_numbers_station_unknown',
				__( 'Unknown workshop post.', 'dahu-serial-numbers' )
			);
		}

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
			Dahu_Serial_Numbers_Install::stations_table(),
			array( 'active' => $active ? 1 : 0 ),
			array( 'id' => $station_id ),
			array( '%d' ),
			array( '%d' )
		);

		if ( ! $active ) {
			self::credentials_revoked( $station_id );
		}

		return true;
	}

	/**
	 * Says out loud that a post's credential must stop working.
	 *
	 * The store does not know about tokens, and should not: it announces,
	 * and the authentication layer listens. A shop that wants to record the
	 * event, or tell somebody, hooks the same action.
	 *
	 * @param int $station_id Post id.
	 * @return void
	 */
	private static function credentials_revoked( $station_id ) {

		/**
		 * Fires when a post's credential has been withdrawn.
		 *
		 * Either it was deactivated, or its password was replaced. Live
		 * bearer tokens must not outlive either.
		 *
		 * @param int $station_id Post id.
		 */
		do_action( 'dahu_serial_numbers_station_credentials_revoked', (int) $station_id );
	}

	/**
	 * One post, by id.
	 *
	 * @param int $station_id Post id.
	 * @return array<string,mixed>|null
	 */
	public static function get( $station_id ) {

		global $wpdb;

		$station_id = (int) $station_id;

		if ( $station_id <= 0 ) {
			return null;
		}

		$table = Dahu_Serial_Numbers_Install::stations_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from $wpdb->prefix, own table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $station_id ), ARRAY_A );

		return $row ? $row : null;
	}

	/**
	 * One post, by login.
	 *
	 * @param string $login Login.
	 * @return array<string,mixed>|null
	 */
	public static function find_by_login( $login ) {

		global $wpdb;

		$login = trim( (string) $login );

		if ( '' === $login ) {
			return null;
		}

		$table = Dahu_Serial_Numbers_Install::stations_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from $wpdb->prefix, own table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE login = %s", $login ), ARRAY_A );

		return $row ? $row : null;
	}

	/**
	 * Every post, newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function all() {

		global $wpdb;

		$table = Dahu_Serial_Numbers_Install::stations_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from $wpdb->prefix, own table.
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC, id DESC", ARRAY_A );

		return $rows ? $rows : array();
	}

	/**
	 * Is this post allowed to work?
	 *
	 * One spelling of the comparison, because `active` arrives from the
	 * database as the string "1" on MySQL and as the integer 1 on SQLite, and
	 * the extracted version compared it both ways in the same file.
	 *
	 * @param int $station_id Post id.
	 * @return bool
	 */
	public static function is_active( $station_id ) {

		$station = self::get( $station_id );

		return $station && (int) $station['active'] === 1;
	}

	/**
	 * Records that a post just signed in.
	 *
	 * @param int $station_id Post id.
	 * @return void
	 */
	public static function touch_login( $station_id ) {

		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
			Dahu_Serial_Numbers_Install::stations_table(),
			array( 'last_login_at' => gmdate( 'Y-m-d H:i:s' ) ),
			array( 'id' => (int) $station_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Label of a post, or an em dash when it has none.
	 *
	 * @param int $station_id Post id.
	 * @return string
	 */
	public static function label( $station_id ) {

		$station = self::get( $station_id );

		if ( ! $station ) {
			return '—';
		}

		return '' !== (string) $station['label'] ? (string) $station['label'] : (string) $station['login'];
	}
}
