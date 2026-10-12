<?php
/**
 * The schema.
 *
 * Four tables, and the reason there are four rather than seventeen is worth
 * recording: the plugin this was extracted from had grown a stock ledger,
 * supplier lead times, reorder forecasts, firmware update channels and a
 * supplier after-sales workflow around the same core. Those are real features
 * and they are not this plugin — a table that no shipped code reads is a table
 * somebody has to migrate anyway.
 *
 *   dahu_serial_numbers          one row per physical unit
 *   dahu_serial_numbers_log      append-only history of every status change
 *   dahu_serial_numbers_stations one row per workshop post
 *   dahu_serial_numbers_tokens   bearer tokens issued to those posts
 *
 * The `serials` table keeps the columns of the whole lifecycle, including the
 * after-sales ones, because the transition matrix ships whole: `in_repair` and
 * `replaced` are statuses a serial can legally reach, and a status you can
 * reach needs somewhere to record how you got there. The screens that drive
 * those transitions are a later release; the vocabulary is not.
 *
 * ## One rule about time, and it holds everywhere
 *
 * Every datetime column below holds UTC. Not the shop's timezone, not
 * whatever `current_time()` answers: UTC, written with `gmdate()`, read back
 * with the timezone named out loud, and converted with `get_date_from_gmt()`
 * at the moment it is shown to somebody.
 *
 * The plugin this was extracted from mixed the two — a token's deadline in
 * UTC, the row that created it in shop time, in the same INSERT — and any
 * comparison between the two was wrong by the site's offset. A rule that
 * holds everywhere costs one conversion on screens and removes a class of
 * bug that only shows up when the clocks change.
 *
 * @package DahuSerialNumbers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the tables.
 */
class Dahu_Serial_Numbers_Install {

	/**
	 * Schema version. Bumped whenever a CREATE TABLE below changes.
	 */
	const SCHEMA_VERSION = '1';

	/**
	 * Option holding the installed schema version.
	 */
	const OPTION_SCHEMA = 'dahu_serial_numbers_schema';

	/**
	 * One row per physical unit.
	 *
	 * @return string
	 */
	public static function serials_table() {

		global $wpdb;

		return $wpdb->prefix . 'dahu_serial_numbers';
	}

	/**
	 * Append-only history.
	 *
	 * @return string
	 */
	public static function log_table() {

		global $wpdb;

		return $wpdb->prefix . 'dahu_serial_numbers_log';
	}

	/**
	 * Workshop posts.
	 *
	 * @return string
	 */
	public static function stations_table() {

		global $wpdb;

		return $wpdb->prefix . 'dahu_serial_numbers_stations';
	}

	/**
	 * Bearer tokens issued to workshop posts.
	 *
	 * @return string
	 */
	public static function tokens_table() {

		global $wpdb;

		return $wpdb->prefix . 'dahu_serial_numbers_tokens';
	}

	/**
	 * Every table this plugin owns.
	 *
	 * @return string[]
	 */
	public static function tables() {

		return array(
			self::serials_table(),
			self::log_table(),
			self::stations_table(),
			self::tokens_table(),
		);
	}

	/**
	 * Create or upgrade the tables.
	 *
	 * @return void
	 */
	public static function install() {

		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$serials         = self::serials_table();
		$log             = self::log_table();
		$stations        = self::stations_table();
		$tokens          = self::tokens_table();

		// dbDelta is particular: two spaces after PRIMARY KEY, one field per
		// line, KEY names spelled the same way every time. It compares the
		// text of this statement with the live table, so a cosmetic edit here
		// becomes an ALTER on every site.
		$sql_serials = "CREATE TABLE {$serials} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			serial varchar(64) NOT NULL,
			supplier_order varchar(32) NOT NULL DEFAULT '',
			supplier_name varchar(191) NOT NULL DEFAULT '',
			supplier_spec varchar(191) NULL DEFAULT NULL,
			manufactured_year smallint NULL DEFAULT NULL,
			manufactured_month tinyint NULL DEFAULT NULL,
			product_id bigint(20) NULL DEFAULT NULL,
			status varchar(32) NOT NULL DEFAULT 'in_stock',
			order_id bigint(20) NULL DEFAULT NULL,
			order_item_id bigint(20) NULL DEFAULT NULL,
			service_ticket varchar(64) NULL DEFAULT NULL,
			replaced_by varchar(64) NULL DEFAULT NULL,
			received_at datetime NULL DEFAULT NULL,
			checked_at datetime NULL DEFAULT NULL,
			updated_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY serial (serial),
			KEY supplier_order (supplier_order),
			KEY product_id (product_id),
			KEY status (status),
			KEY order_id (order_id),
			KEY replaced_by (replaced_by)
		) {$charset_collate};";

		// The history is append-only and nothing in this plugin deletes from
		// it. It is what answers "where did this unit go", which is the whole
		// reason somebody serialises in the first place.
		$sql_log = "CREATE TABLE {$log} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			serial varchar(64) NOT NULL,
			event varchar(64) NOT NULL,
			old_status varchar(32) NULL DEFAULT NULL,
			new_status varchar(32) NULL DEFAULT NULL,
			order_id bigint(20) NULL DEFAULT NULL,
			user_id bigint(20) NOT NULL DEFAULT 0,
			context text NULL,
			created_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY serial (serial),
			KEY created_at (created_at),
			KEY event (event)
		) {$charset_collate};";

		// A station is a physical post, not a person: a tablet on a bench,
		// shared by whoever is packing. Deactivating one keeps its id, so the
		// history that points at it stays readable — this plugin never hard
		// deletes a row another row refers to.
		$sql_stations = "CREATE TABLE {$stations} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			login varchar(64) NOT NULL,
			password_hash varchar(255) NOT NULL,
			label varchar(191) NOT NULL DEFAULT '',
			active tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NULL DEFAULT NULL,
			last_login_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY login (login)
		) {$charset_collate};";

		// Plain bearer tokens, hashed at rest. No JWT library: a dependency
		// whose only job is to sign a string that never leaves this site is a
		// dependency that will need updating for somebody else's CVE. The
		// index on expires_at is there for the daily purge, which is a
		// DELETE over a range: without it, a full scan of a table nobody
		// ever thinks to look at.
		$sql_tokens = "CREATE TABLE {$tokens} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			station_id bigint(20) unsigned NOT NULL,
			token_hash varchar(64) NOT NULL,
			expires_at datetime NOT NULL,
			created_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			KEY station_id (station_id),
			KEY expires_at (expires_at)
		) {$charset_collate};";

		dbDelta( $sql_serials );
		dbDelta( $sql_log );
		dbDelta( $sql_stations );
		dbDelta( $sql_tokens );

		update_option( self::OPTION_SCHEMA, self::SCHEMA_VERSION, false );
	}

	/**
	 * Run install() when the stored schema version is behind.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {

		if ( get_option( self::OPTION_SCHEMA ) === self::SCHEMA_VERSION ) {
			return;
		}

		self::install();
	}

	/**
	 * Whether every table exists.
	 *
	 * Used by the screens to say "the tables are missing" rather than to
	 * print an empty list, which looks exactly like a shop with no serials
	 * yet and sends somebody looking in the wrong place.
	 *
	 * @return bool
	 */
	public static function tables_exist() {

		global $wpdb;

		foreach ( self::tables() as $table ) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema check, not a query on content.

			if ( $found !== $table ) {
				return false;
			}
		}

		return true;
	}
}
