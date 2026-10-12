<?php
/**
 * The schema.
 *
 * Six tables, and the reason there are six rather than seventeen is worth
 * recording: the plugin this was extracted from had grown a stock ledger,
 * supplier lead times, reorder forecasts, firmware update channels and a
 * supplier after-sales workflow around the same core. Those are real features
 * and they are not this plugin — a table that no shipped code reads is a table
 * somebody has to migrate anyway.
 *
 *   dahu_serial_numbers              one row per physical unit
 *   dahu_serial_numbers_log          append-only history of every status change
 *   dahu_serial_numbers_stations     one row per workshop post
 *   dahu_serial_numbers_tokens       bearer tokens issued to those posts
 *   dahu_serial_numbers_consignments one row per set of units that travelled
 *                                    together, out or back
 *   dahu_serial_numbers_consignment_serials
 *                                    which units were in which consignment
 *
 * The last two are a published contract: another plugin reads them, so
 * changes to them are additive only. The reasoning, and the version constant
 * that goes with it, are in class-dahu-serial-numbers-consignments.php.
 *
 * A join table rather than a column on the serials table, because a unit
 * ships, comes back and ships again: a column holds the last of those and
 * loses the rest, which is the opposite of what a traceability plugin is for.
 *
 * ## The parcel's own weight and dimensions, and why the unit travels with them
 *
 * A consignment carries six nullable columns describing the box itself:
 * `weight`, `length`, `width`, `height`, and — next to them — `weight_unit`
 * and `dimension_unit`.
 *
 * Storing the unit alongside the number looks redundant, since WooCommerce
 * already has a shop-wide setting for each. It is not. That setting can be
 * changed, and the day somebody switches a shop from kilograms to pounds,
 * every figure already on file would silently start meaning something else —
 * a register that rewrites its own history is worse than one that holds no
 * figures at all. The unit written at the same moment as the number is what
 * makes a row from two years ago still readable.
 *
 * These are the box, not the goods: a unit's own weight belongs to its
 * product. Nothing here computes one from the other, because nobody but the
 * person taping the box knows how it was filled.
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
	const SCHEMA_VERSION = '4';

	/**
	 * Option holding the installed schema version.
	 */
	const OPTION_SCHEMA = 'dahu_serial_numbers_schema';

	/**
	 * Option holding the consignment contract version.
	 */
	const OPTION_CONSIGNMENTS_API = 'dahu_serial_numbers_consignments_api';

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
	 * One row per set of units that travelled together.
	 *
	 * @return string
	 */
	public static function consignments_table() {

		global $wpdb;

		return $wpdb->prefix . 'dahu_serial_numbers_consignments';
	}

	/**
	 * Which units were in which consignment.
	 *
	 * @return string
	 */
	public static function consignment_serials_table() {

		global $wpdb;

		return $wpdb->prefix . 'dahu_serial_numbers_consignment_serials';
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
			self::consignments_table(),
			self::consignment_serials_table(),
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
		$consignments    = self::consignments_table();
		$pivot           = self::consignment_serials_table();

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

		// A consignment is a set of units that left, or came back, together.
		//
		// `reference` is nullable on purpose. It is assigned straight after
		// the insert, from the row's own id, and a nullable unique index is
		// what makes that safe: MySQL allows several NULLs in one, so two
		// benches inserting at the same moment cannot collide on the way to
		// their references. A counter in an option would hand out the same
		// number twice.
		//
		// `carrier` and `tracking` are a name and a string, and this plugin
		// never contacts anything with either. `source` records which plugin
		// created the row, which is what keeps the history readable after a
		// carrier integration is removed.
		$sql_consignments = "CREATE TABLE {$consignments} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			reference varchar(64) NULL DEFAULT NULL,
			direction varchar(8) NOT NULL DEFAULT 'out',
			status varchar(16) NOT NULL DEFAULT 'open',
			order_id bigint(20) NULL DEFAULT NULL,
			carrier varchar(64) NOT NULL DEFAULT '',
			tracking varchar(128) NOT NULL DEFAULT '',
			source varchar(64) NOT NULL DEFAULT 'manual',
			station_id bigint(20) NULL DEFAULT NULL,
			weight decimal(12,4) NULL DEFAULT NULL,
			weight_unit varchar(8) NULL DEFAULT NULL,
			length decimal(12,4) NULL DEFAULT NULL,
			width decimal(12,4) NULL DEFAULT NULL,
			height decimal(12,4) NULL DEFAULT NULL,
			dimension_unit varchar(8) NULL DEFAULT NULL,
			note text NULL,
			created_at datetime NULL DEFAULT NULL,
			closed_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY reference (reference),
			KEY order_id (order_id),
			KEY tracking (tracking),
			KEY status (status),
			KEY direction (direction),
			KEY created_at (created_at),
			KEY closed_at (closed_at)
		) {$charset_collate};";

		// The contents. No foreign key: WordPress tables are MyISAM on
		// enough hosts that one would silently not exist, and a constraint
		// that only holds on some installations is worse than none — the
		// code would start trusting it. Nothing deletes a consignment, so
		// the orphan this would prevent has no way to occur.
		//
		// A surrogate `id` rather than the composite primary key this table
		// started with, and the reason is the contract. An outside reader
		// walking the contents in order needs a stable cursor, and the only
		// other candidate was `added_at` — nullable, not unique, and not
		// indexed. Adding the column later would have meant changing the
		// PRIMARY KEY, which is precisely the kind of change the additive-only
		// rule forbids, so it had to be here from the start or never.
		//
		// The uniqueness rule it used to carry is now its own unique key: a
		// unit appears at most once in a given consignment, still enforced by
		// the index rather than by a check somebody can forget.
		$sql_pivot = "CREATE TABLE {$pivot} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			consignment_id bigint(20) unsigned NOT NULL,
			serial varchar(64) NOT NULL,
			added_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY consignment_serial (consignment_id,serial),
			KEY serial (serial)
		) {$charset_collate};";

		dbDelta( $sql_serials );
		dbDelta( $sql_log );
		dbDelta( $sql_stations );
		dbDelta( $sql_tokens );
		dbDelta( $sql_consignments );
		dbDelta( $sql_pivot );

		update_option( self::OPTION_SCHEMA, self::SCHEMA_VERSION, false );

		// The consignment contract version, in an option, because the plugins
		// that read those two tables should be able to check what they are
		// reading without loading a class out of somebody else's plugin
		// directory — which is a file path, and file paths are the thing that
		// differs between installations.
		update_option( self::OPTION_CONSIGNMENTS_API, Dahu_Serial_Numbers_Consignments::API_VERSION, false );
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
