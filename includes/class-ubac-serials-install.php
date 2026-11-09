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
 *   ubac_serials              one row per physical unit
 *   ubac_serials_log          append-only history of every status change
 *   ubac_serials_stations     one row per workshop post
 *   ubac_serials_tokens       bearer tokens issued to those posts
 *   ubac_serials_consignments one row per set of units that travelled
 *                                    together, out or back
 *   ubac_serials_consignment_serials
 *                                    which units were in which consignment
 *
 * The last two are a published contract: another plugin reads them, so
 * changes to them are additive only. The reasoning, and the version constant
 * that goes with it, are in class-ubac-serials-consignments.php.
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
 * @package UbacSerials
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the tables.
 */
class Ubac_Serials_Install {

	/**
	 * Schema version. Bumped whenever a CREATE TABLE below changes.
	 */
	const SCHEMA_VERSION = '5';

	/**
	 * Option holding the installed schema version.
	 */
	const OPTION_SCHEMA = 'ubac_serials_schema';

	/**
	 * Option holding the consignment contract version.
	 */
	const OPTION_CONSIGNMENTS_API = 'ubac_serials_consignments_api';

	/**
	 * Table base name this plugin shipped under before the tables were
	 * renamed, and the option recording what became of them.
	 *
	 * The plugin was released as dahu-serial-numbers, whose six tables were
	 * named from that slug. The rename to the ubac_ identifiers shipped
	 * without a migration, so any shop that ran the earlier release still
	 * holds its units, its history and its consignments in tables no
	 * shipped code reads. Six tables of traceability data, unreachable, in
	 * a plugin whose whole purpose is not losing track of a unit.
	 */
	const LEGACY_BASE = 'dahu_serial_numbers';

	/**
	 * Option recording the outcome of the adoption, and nothing else.
	 */
	const OPTION_LEGACY = 'ubac_serials_legacy_tables';

	/**
	 * One row per physical unit.
	 *
	 * @return string
	 */
	public static function serials_table() {

		global $wpdb;

		return $wpdb->prefix . 'ubac_serials';
	}

	/**
	 * Append-only history.
	 *
	 * @return string
	 */
	public static function log_table() {

		global $wpdb;

		return $wpdb->prefix . 'ubac_serials_log';
	}

	/**
	 * Workshop posts.
	 *
	 * @return string
	 */
	public static function stations_table() {

		global $wpdb;

		return $wpdb->prefix . 'ubac_serials_stations';
	}

	/**
	 * Bearer tokens issued to workshop posts.
	 *
	 * @return string
	 */
	public static function tokens_table() {

		global $wpdb;

		return $wpdb->prefix . 'ubac_serials_tokens';
	}

	/**
	 * One row per set of units that travelled together.
	 *
	 * @return string
	 */
	public static function consignments_table() {

		global $wpdb;

		return $wpdb->prefix . 'ubac_serials_consignments';
	}

	/**
	 * Which units were in which consignment.
	 *
	 * @return string
	 */
	public static function consignment_serials_table() {

		global $wpdb;

		return $wpdb->prefix . 'ubac_serials_consignment_serials';
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
	 * Whether a table exists, by name.
	 *
	 * @param string $table Full table name, prefix included.
	 * @return bool
	 */
	private static function table_exists( $table ) {

		global $wpdb;

		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema check, not a query on content.

		return $found === $table;
	}

	/**
	 * Each table under its former name, mapped to its name today.
	 *
	 * The suffixes are taken from the current names rather than written out
	 * twice: a table added to tables() and forgotten here would be a table
	 * silently left behind.
	 *
	 * Refuses rather than guesses. If a name ever stops starting with the
	 * current base — a seventh table called something else — substr() would
	 * cut into the middle of it and hand RENAME a former name this plugin
	 * never used. An empty map stops the adoption; a fabricated one would
	 * pull a stranger's table in.
	 *
	 * @return array<string,string> Former name => current name.
	 */
	private static function legacy_map() {

		global $wpdb;

		$map     = array();
		$current = $wpdb->prefix . 'ubac_serials';

		foreach ( self::tables() as $table ) {
			if ( 0 !== strpos( $table, $current ) ) {
				return array();
			}

			$suffix         = substr( $table, strlen( $current ) );
			$legacy         = $wpdb->prefix . self::LEGACY_BASE . $suffix;
			$map[ $legacy ] = $table;
		}

		return $map;
	}

	/**
	 * Whether the release these tables came from is still installed and live.
	 *
	 * Two folders are two plugins as far as WordPress is concerned, so both
	 * can be active at once. Renaming tables out from under a plugin that is
	 * writing to them, or dropping them at uninstall, would take a shop's
	 * production data while it is in use — and the checkbox never said that.
	 *
	 * Matched on the folder rather than the file: the former release's main
	 * file name is not something this code should have to remember exactly.
	 *
	 * @return bool
	 */
	public static function legacy_plugin_active() {

		$actifs = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			$actifs = array_merge( $actifs, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		foreach ( $actifs as $entree ) {
			if ( 0 === strpos( (string) $entree, 'dahu-serial-numbers/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Record what was decided, and why, in one place.
	 *
	 * @param string               $statut Outcome.
	 * @param array<string,string> $extra  Context for the notice.
	 * @return void
	 */
	private static function record_legacy( $statut, $extra = array() ) {

		update_option( self::OPTION_LEGACY, array_merge( array( 'statut' => $statut ), $extra ), false );
	}

	/**
	 * Take over the tables of the earlier release, when it is safe to.
	 *
	 * ## Why this is not called from the schema upgrade
	 *
	 * It used to be reached only through install(), which maybe_upgrade()
	 * skips as soon as the stored schema version is current. The sites that
	 * have these tables are exactly the sites that already ran this plugin,
	 * so their schema version is already current: the adoption would never
	 * have fired on a single one of them. It is now called on its own
	 * condition — the option not yet written — and never on the schema number.
	 *
	 * ## All or nothing, and what that costs
	 *
	 * A run that moved three tables and refused three would leave a shop
	 * whose screens show part of a history against unrelated units, joined on
	 * the serial, with nothing saying so. Every refusal below exists because
	 * one of those half-states was reachable without any error occurring.
	 *
	 * The refusals, each measured and not assumed:
	 *
	 * - The former release is still active. Its tables are in use.
	 * - Fewer than six tables under the former name. An incomplete old set
	 *   cannot be grafted onto a live one.
	 * - Any of the six tables in use holds rows — all six are counted, not
	 *   only the ones with a former twin. Merging two populated sets of
	 *   serials is not a decision code makes: two rows can carry the same
	 *   serial with different histories.
	 * - The former pivot table has no `id` column. dbDelta never alters a
	 *   PRIMARY KEY, so that table could not be brought up to the current
	 *   schema after adoption, and the code that reads `id` would fail on data
	 *   just moved.
	 * - The former units table holds a duplicate serial, which the current
	 *   UNIQUE KEY cannot accept and which dbDelta would silently fail to add,
	 *   leaving the plugin believing uniqueness is enforced.
	 *
	 * ## Nothing is dropped
	 *
	 * An empty table in the way is renamed aside, not destroyed, so that a
	 * RENAME refused half way — metadata lock, permissions, tablespace — can
	 * be put back exactly as it was. The cost is a RENAME; the alternative is
	 * an irreversible DROP in a function whose whole purpose is not losing
	 * data. The names set aside are recorded, and uninstall removes them.
	 *
	 * @return void
	 */
	public static function adopt_legacy_tables() {

		global $wpdb;

		$map = self::legacy_map();

		if ( ! $map ) {
			return;
		}

		$legacy = array();

		foreach ( $map as $old => $new ) {
			if ( self::table_exists( $old ) ) {
				$legacy[ $old ] = $new;
			}
		}

		if ( ! $legacy ) {
			return;
		}

		if ( self::legacy_plugin_active() ) {
			self::record_legacy( 'bloque', array( 'motif' => 'plugin_ancien_actif', 'anciennes' => array_keys( $legacy ) ) );
			return;
		}

		if ( count( $legacy ) !== count( $map ) ) {
			self::record_legacy( 'bloque', array( 'motif' => 'jeu_ancien_incomplet', 'anciennes' => array_keys( $legacy ) ) );
			return;
		}

		// Les six en usage, pas seulement celles qui ont une jumelle ancienne.
		$occupees = array();
		$a_ecarter = array();

		foreach ( self::tables() as $new ) {
			if ( ! self::table_exists( $new ) ) {
				continue;
			}

			$lignes = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $new ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- counted to decide whether it may be moved aside.

			if ( $lignes > 0 ) {
				$occupees[] = $new;
			} else {
				$a_ecarter[] = $new;
			}
		}

		if ( $occupees ) {
			self::record_legacy( 'bloque', array( 'motif' => 'tables_en_usage_occupees', 'anciennes' => array_keys( $legacy ), 'occupees' => $occupees ) );
			return;
		}

		$motif = self::legacy_unfit( $map );

		if ( '' !== $motif ) {
			self::record_legacy( 'bloque', array( 'motif' => $motif, 'anciennes' => array_keys( $legacy ) ) );
			return;
		}

		// --- A partir d ici on ecrit. Chaque pas est annulable. -------------

		$suffixe = '_ecartee_' . gmdate( 'YmdHis' );
		$faits   = array();

		foreach ( $a_ecarter as $table ) {
			if ( false === self::rename_table( $table, $table . $suffixe ) ) {
				self::rollback( $faits );
				self::record_legacy( 'echec', array( 'motif' => 'ecart_impossible', 'table' => $table, 'anciennes' => array_keys( $legacy ) ) );
				return;
			}

			$faits[] = array( $table . $suffixe, $table );
		}

		foreach ( $legacy as $old => $new ) {
			if ( false === self::rename_table( $old, $new ) ) {
				self::rollback( $faits );
				self::record_legacy( 'echec', array( 'motif' => 'reprise_impossible', 'table' => $old, 'anciennes' => array_keys( $legacy ) ) );
				return;
			}

			$faits[] = array( $new, $old );
		}

		self::record_legacy(
			'adopte',
			array(
				'anciennes' => array_keys( $legacy ),
				'adoptees'  => array_values( $legacy ),
				'ecartees'  => array_map(
					static function ( $t ) use ( $suffixe ) {
						return $t . $suffixe;
					},
					$a_ecarter
				),
			)
		);

		// Le schema de ce qui vient d etre adopte est celui d une version
		// precedente : dbDelta doit repasser, et le marqueur de version lui
		// barrerait la route.
		delete_option( self::OPTION_SCHEMA );
		self::install();
	}

	/**
	 * Why the former tables cannot be adopted, or '' when they can.
	 *
	 * Both checks exist because dbDelta cannot repair what they would leave:
	 * it never alters a PRIMARY KEY, and an index it fails to add leaves no
	 * error behind.
	 *
	 * @param array<string,string> $map Former name => current name.
	 * @return string
	 */
	private static function legacy_unfit( $map ) {

		global $wpdb;

		$pivot_now = self::consignment_serials_table();
		$units_now = self::serials_table();

		foreach ( $map as $old => $new ) {
			if ( $new === $pivot_now ) {
				$colonne = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $old, 'id' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema check.

				if ( ! $colonne ) {
					return 'pivot_sans_id';
				}
			}

			if ( $new === $units_now ) {
				$doublons = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ( SELECT serial FROM %i GROUP BY serial HAVING COUNT(*) > 1 ) d', $old ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema check.

				if ( $doublons > 0 ) {
					return 'serials_en_double';
				}
			}
		}

		return '';
	}

	/**
	 * Rename a table. Rends false on failure, like $wpdb->query().
	 *
	 * @param string $de  Current name.
	 * @param string $vers New name.
	 * @return mixed
	 */
	private static function rename_table( $de, $vers ) {

		global $wpdb;

		return $wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $de, $vers ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- table names from this plugin's own schema.
	}

	/**
	 * Put back every rename already made, newest first.
	 *
	 * Best effort by nature: if the database is refusing renames, it may
	 * refuse these too. It is recorded either way, which is the difference
	 * between a shop that knows and a shop that does not.
	 *
	 * @param array<int,array{0:string,1:string}> $faits Pairs: current, original.
	 * @return void
	 */
	private static function rollback( $faits ) {

		foreach ( array_reverse( $faits ) as $pas ) {
			self::rename_table( $pas[0], $pas[1] );
		}
	}

	/**
	 * Say so when traceability data is sitting in tables nothing reads.
	 *
	 * Covers every outcome that left data unreachable: a refusal, and a run
	 * that stopped part way. An adoption that worked needs no notice, and a
	 * site that never ran the earlier release writes no option at all.
	 *
	 * @return void
	 */
	public static function legacy_notice() {

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$etat   = get_option( self::OPTION_LEGACY );
		$statut = is_array( $etat ) ? ( $etat['statut'] ?? '' ) : '';

		if ( 'bloque' !== $statut && 'echec' !== $statut ) {
			return;
		}

		$motifs = array(
			'plugin_ancien_actif'      => __( 'The earlier release of this plugin is still installed and active, and those tables are in use by it.', 'dahuconcept-serial-numbers' ),
			'jeu_ancien_incomplet'     => __( 'Only part of the earlier set of tables is still present, and an incomplete set cannot be grafted onto the one in use.', 'dahuconcept-serial-numbers' ),
			'tables_en_usage_occupees' => __( 'The tables in use already hold rows of their own, and merging two sets of serials is not a decision this plugin makes on its own: two rows can carry the same serial with different histories.', 'dahuconcept-serial-numbers' ),
			'pivot_sans_id'            => __( 'The earlier consignment contents table predates a column the current schema needs, and that column cannot be added to it after the fact.', 'dahuconcept-serial-numbers' ),
			'serials_en_double'        => __( 'The earlier units table holds the same serial more than once, which the current schema does not accept.', 'dahuconcept-serial-numbers' ),
			'ecart_impossible'         => __( 'The database refused to move an empty table out of the way, so nothing was changed.', 'dahuconcept-serial-numbers' ),
			'reprise_impossible'       => __( 'The database refused a rename part way through, and the tables were put back as they were.', 'dahuconcept-serial-numbers' ),
		);

		$motif     = isset( $etat['motif'] ) ? (string) $etat['motif'] : '';
		$anciennes = isset( $etat['anciennes'] ) ? (array) $etat['anciennes'] : array();

		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'Serial numbers: this site holds tables from an earlier release of this plugin, and they still contain data that no screen here reads.', 'dahuconcept-serial-numbers' );
		echo ' ';
		echo isset( $motifs[ $motif ] ) ? esc_html( $motifs[ $motif ] ) : '';
		echo '</p>';

		if ( $anciennes ) {
			echo '<p>';

			foreach ( $anciennes as $table ) {
				echo '<code>' . esc_html( $table ) . '</code> ';
			}

			echo '</p>';
		}

		echo '<p>';
		echo esc_html__( 'Nothing has been changed or deleted. Ask whoever maintains the site to reconcile the two sets.', 'dahuconcept-serial-numbers' );
		echo '</p></div>';
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
		//
		// `label_error` is the carrier's message when a label was asked for
		// and refused, NULL otherwise. It is a column rather than a line in
		// `note` so that "which parcels left without a label" is a WHERE
		// clause and not a LIKE over free text. Added in schema 5; additive,
		// nullable, and nothing that read the table before it existed notices.
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
			label_error text NULL,
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
		update_option( self::OPTION_CONSIGNMENTS_API, Ubac_Serials_Consignments::API_VERSION, false );
	}

	/**
	 * Run install() when the stored schema version is behind.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {

		// Both of these come before the early return, and that order is the
		// whole point. A site holding the earlier release's tables has
		// already run this plugin, so its schema version is current and the
		// return below fires: hanging either the adoption or its notice on
		// the schema number would mean neither ever happened on the only
		// sites that need them.
		add_action( 'admin_notices', array( __CLASS__, 'legacy_notice' ) );

		if ( false === get_option( self::OPTION_LEGACY ) ) {
			self::adopt_legacy_tables();
		}

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
