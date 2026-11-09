<?php
/**
 * Uninstall routine.
 *
 * The tables are not dropped unless that was asked for, and the setting is
 * off by default. This is not timidity about data: the serials table and its
 * history are the only record of which unit went to which customer, going
 * back as far as the shop has been serialising. A warranty claim arrives two
 * years later and that table is the answer.
 *
 * Uninstalling is also, most of the time, a step in reinstalling — a plugin
 * deactivated to test a conflict, deleted by a host's updater, moved between
 * environments. Dropping six tables on the way past would be irreversible,
 * silent, and done at the moment nobody is watching.
 *
 * So what always goes is what costs nothing to rebuild: the scheduled event,
 * the login lockout counters, and the three version and rewrite markers. The
 * data goes only when the box was ticked, and the box says what it does.
 *
 * What the box covers is counted, not remembered: the plugin creates six
 * tables and writes ten options, and the lists below hold six and ten.
 *
 * Six tables, three times over: the same six under the name the earlier
 * release gave them, and the empty ones the adoption moved aside, are
 * dropped as well. The reason for each is at its loop, and so is the one
 * case where the former six are left alone.
 * Anything added to `Ubac_Serials_Install` or to a new `OPTION_`
 * constant belongs here the same day, or a "delete everything" quietly
 * becomes a "delete most of it".
 *
 * The drop order below matters and used to be wrong: it said credentials
 * first and traceability last, and dropped the serials table first. On a
 * shop large enough for this to time out half way, that is the one order
 * that loses the irreplaceable table and keeps the tokens.
 *
 * @package UbacSerials
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site.
 *
 * @return void
 */
function ubac_serials_uninstall_site() {

	// The welcome screen's own three flags.
	//
	// They are interface state, not the shop's data, which is why they sit
	// BEFORE any “remove data on uninstall” guard: whatever a shop chose to
	// keep, a reinstall has to greet it again. `includes/ubac-welcome.php`
	// writes them with update_option(), so they outlive the plugin unless
	// they are deleted here — and the welcome screen and the guided tour
	// would then stay silent for good, with nothing on screen to explain why.
	delete_option( 'ubac_welcome_seen_ubac-serials' );
	// Les memes clefs sous le nouveau slug : elle est construite au runtime
	// a partir du slug, donc elle a change avec lui. Sans ces trois lignes,
	// une mise a jour depuis la version Ubac laisse des orphelines que plus
	// rien ne nomme.
	delete_option( 'ubac_welcome_seen_dahuconcept-serial-numbers' );
	delete_option( 'ubac_welcome_pending_ubac-serials' );
	delete_option( 'ubac_welcome_pending_dahuconcept-serial-numbers' );
	delete_option( 'ubac_welcome_tour_ubac-serials' );
	delete_option( 'ubac_welcome_tour_dahuconcept-serial-numbers' );

	global $wpdb;

	wp_clear_scheduled_hook( 'ubac_serials_purge_tokens' );

	// The lockout counters are transients keyed on a hash of the login, so
	// there is no list of them to walk. They expire on their own within ten
	// minutes; this clears the ones still live, by pattern, in one statement.
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- uninstall, options table, pattern prepared below.
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_ubac_sn_login_fail_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_ubac_sn_login_fail_' ) . '%'
		)
	);

	// Markers, not data. These go either way: leaving the schema version
	// behind means a reinstall believes the tables are already up to date and
	// skips the dbDelta that would have created them, and the same reasoning
	// applies to the consignment API version and to the rewrite marker, which
	// is the only thing standing between a reinstall and a flush of the
	// workshop URL. Not one of them holds anything a shop would miss.
	// Lu AVANT la suppression des marqueurs, qui l emporte : il porte le nom
	// des tables vides ecartees au moment de la reprise, et c est la seule
	// liste qui les nomme.
	$ubac_serials_legacy = get_option( 'ubac_serials_legacy_tables' );
	$ubac_serials_legacy = is_array( $ubac_serials_legacy ) ? $ubac_serials_legacy : array();

	$markers = array(
		'ubac_serials_schema',
		'ubac_serials_consignments_api',
		'ubac_serials_rewrite',
		// What became of the tables of the earlier release. A record of one
		// decision, holding six table names and nothing else; it goes either
		// way, like the rest of this list.
		'ubac_serials_legacy_tables',
	);

	foreach ( $markers as $marker ) {
		delete_option( $marker );
	}

	$settings = get_option( 'ubac_serials_settings', array() );

	if ( ! is_array( $settings ) || empty( $settings['delete_data_on_uninstall'] ) ) {
		return;
	}

	$suffixes = array(
		// Credentials first: cheap to recreate, and the thing least worth
		// keeping if this is interrupted.
		'ubac_serials_tokens',
		'ubac_serials_stations',
		// Then the consignment record. The contents go before the headers,
		// and that order is a choice between two bad remnants rather than a
		// derivation: interrupted here, what survives is the headers —
		// reference, direction, order, carrier, tracking number, dates —
		// which is still a readable list of parcels. The other way round
		// leaves rows holding a consignment id that no longer names
		// anything, which is unreadable.
		'ubac_serials_consignment_serials',
		'ubac_serials_consignments',
		// The history and the units themselves last. If a timeout stops this
		// part way, what is left behind is the answer to "where did this unit
		// go", which is the only part nobody can rebuild.
		'ubac_serials_log',
		'ubac_serials',
	);

	foreach ( $suffixes as $suffix ) {
		$table = $wpdb->prefix . $suffix;

		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix, uninstall.
	}

	// The empty tables that the adoption moved out of the way. Empty by
	// definition, named only by the option read at the top of this function.
	foreach ( (array) ( $ubac_serials_legacy['ecartees'] ?? array() ) as $ecartee ) {
		if ( ! is_string( $ecartee ) || 0 !== strpos( $ecartee, $wpdb->prefix . 'ubac_serials' ) ) {
			continue;
		}

		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $ecartee ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- uninstall.
	}

	// The same six tables under the name the earlier release gave them.
	//
	// On nearly every site these do not exist, and this loop does nothing. On
	// a site where the adoption was refused — the tables in use already held
	// rows, the old set was incomplete, its schema could not be brought
	// forward — they are still there, still holding that shop's units and
	// history, and they are this plugin's data: a box that says it removes
	// everything this plugin stored would be lying if it left them. Same
	// order, for the same reason.
	//
	// Unless the release that owns them is still installed and active, in
	// which case they are not a remnant, they are a running plugin's
	// production data. The checkbox never offered to take that, so it does
	// not. The check is inlined rather than borrowed from
	// `Ubac_Serials_Install`: at uninstall there is no guarantee this
	// plugin's classes are loadable.
	$ubac_serials_ancien_actif = false;
	$ubac_serials_actifs       = (array) get_option( 'active_plugins', array() );

	if ( is_multisite() ) {
		$ubac_serials_actifs = array_merge( $ubac_serials_actifs, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
	}

	foreach ( $ubac_serials_actifs as $ubac_serials_entree ) {
		if ( 0 === strpos( (string) $ubac_serials_entree, 'dahu-serial-numbers/' ) ) {
			$ubac_serials_ancien_actif = true;
			break;
		}
	}

	if ( ! $ubac_serials_ancien_actif ) {
		foreach ( $suffixes as $suffix ) {
			$ancienne = $wpdb->prefix . str_replace( 'ubac_serials', 'dahu_serial_numbers', $suffix );

			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $ancienne ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- uninstall.
		}
	}

	// Every option this plugin writes, with the markers above: ten in all.
	// Counted against the code rather than remembered, because an option left
	// behind by a "delete everything" is both a lie and, for the two run
	// records, shop data — the stock pushes carry product names and the id of
	// whoever asked for them, and the correction runs carry serial numbers.
	$options = array(
		'ubac_serials_settings',
		// What the Stock screen shows as history, and what a correction run
		// needs to be undone. Both are records of what was done, which is
		// exactly what the box asks to be rid of.
		'ubac_serials_stock_history',
		'ubac_serials_product_fix_runs',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// The serialised flag lives on products, which survive the plugin. Left
	// in place on purpose: a reinstall finds the shop's choices intact, and a
	// meta key nothing reads costs a row.
}

if ( is_multisite() ) {
	$ubac_serials_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $ubac_serials_sites as $ubac_serials_site ) {
		switch_to_blog( $ubac_serials_site );
		ubac_serials_uninstall_site();
		restore_current_blog();
	}

	unset( $ubac_serials_sites, $ubac_serials_site );
} else {
	ubac_serials_uninstall_site();
}
