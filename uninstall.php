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
 * environments. Dropping four tables on the way past would be irreversible,
 * silent, and done at the moment nobody is watching.
 *
 * So what always goes is what costs nothing to rebuild: the scheduled event,
 * the login lockout counters, and the three version and rewrite markers. The
 * data goes only when the box was ticked, and the box says what it does.
 *
 * What the box covers is counted, not remembered: the plugin creates six
 * tables and writes nine options, and the lists below hold six and nine.
 * Anything added to `Dahu_Serial_Numbers_Install` or to a new `OPTION_`
 * constant belongs here the same day, or a "delete everything" quietly
 * becomes a "delete most of it".
 *
 * The drop order below matters and used to be wrong: it said credentials
 * first and traceability last, and dropped the serials table first. On a
 * shop large enough for this to time out half way, that is the one order
 * that loses the irreplaceable table and keeps the tokens.
 *
 * @package DahuSerialNumbers
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site.
 *
 * @return void
 */
function dahu_serial_numbers_uninstall_site() {

	// The welcome screen's own three flags.
	//
	// They are interface state, not the shop's data, which is why they sit
	// BEFORE any “remove data on uninstall” guard: whatever a shop chose to
	// keep, a reinstall has to greet it again. `includes/dahu-welcome.php`
	// writes them with update_option(), so they outlive the plugin unless
	// they are deleted here — and the welcome screen and the guided tour
	// would then stay silent for good, with nothing on screen to explain why.
	delete_option( 'dahu_welcome_seen_dahu-serial-numbers' );
	delete_option( 'dahu_welcome_pending_dahu-serial-numbers' );
	delete_option( 'dahu_welcome_tour_dahu-serial-numbers' );

	global $wpdb;

	wp_clear_scheduled_hook( 'dahu_serial_numbers_purge_tokens' );

	// The lockout counters are transients keyed on a hash of the login, so
	// there is no list of them to walk. They expire on their own within ten
	// minutes; this clears the ones still live, by pattern, in one statement.
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- uninstall, options table, pattern prepared below.
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_dahu_sn_login_fail_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_dahu_sn_login_fail_' ) . '%'
		)
	);

	// Markers, not data. These go either way: leaving the schema version
	// behind means a reinstall believes the tables are already up to date and
	// skips the dbDelta that would have created them, and the same reasoning
	// applies to the consignment API version and to the rewrite marker, which
	// is the only thing standing between a reinstall and a flush of the
	// workshop URL. Not one of them holds anything a shop would miss.
	$markers = array(
		'dahu_serial_numbers_schema',
		'dahu_serial_numbers_consignments_api',
		'dahu_serial_numbers_rewrite',
	);

	foreach ( $markers as $marker ) {
		delete_option( $marker );
	}

	$settings = get_option( 'dahu_serial_numbers_settings', array() );

	if ( ! is_array( $settings ) || empty( $settings['delete_data_on_uninstall'] ) ) {
		return;
	}

	$suffixes = array(
		// Credentials first: cheap to recreate, and the thing least worth
		// keeping if this is interrupted.
		'dahu_serial_numbers_tokens',
		'dahu_serial_numbers_stations',
		// Then the consignment record. The contents go before the headers,
		// and that order is a choice between two bad remnants rather than a
		// derivation: interrupted here, what survives is the headers —
		// reference, direction, order, carrier, tracking number, dates —
		// which is still a readable list of parcels. The other way round
		// leaves rows holding a consignment id that no longer names
		// anything, which is unreadable.
		'dahu_serial_numbers_consignment_serials',
		'dahu_serial_numbers_consignments',
		// The history and the units themselves last. If a timeout stops this
		// part way, what is left behind is the answer to "where did this unit
		// go", which is the only part nobody can rebuild.
		'dahu_serial_numbers_log',
		'dahu_serial_numbers',
	);

	foreach ( $suffixes as $suffix ) {
		$table = $wpdb->prefix . $suffix;

		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix, uninstall.
	}

	// Every option this plugin writes, with the markers above: nine in all.
	// Counted against the code rather than remembered, because an option left
	// behind by a "delete everything" is both a lie and, for the two run
	// records, shop data — the stock pushes carry product names and the id of
	// whoever asked for them, and the correction runs carry serial numbers.
	$options = array(
		'dahu_serial_numbers_settings',
		// What the Stock screen shows as history, and what a correction run
		// needs to be undone. Both are records of what was done, which is
		// exactly what the box asks to be rid of.
		'dahu_serial_numbers_stock_history',
		'dahu_serial_numbers_product_fix_runs',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// The serialised flag lives on products, which survive the plugin. Left
	// in place on purpose: a reinstall finds the shop's choices intact, and a
	// meta key nothing reads costs a row.
}

if ( is_multisite() ) {
	$dahu_serial_numbers_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $dahu_serial_numbers_sites as $dahu_serial_numbers_site ) {
		switch_to_blog( $dahu_serial_numbers_site );
		dahu_serial_numbers_uninstall_site();
		restore_current_blog();
	}

	unset( $dahu_serial_numbers_sites, $dahu_serial_numbers_site );
} else {
	dahu_serial_numbers_uninstall_site();
}
