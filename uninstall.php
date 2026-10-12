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
 * the login lockout counters, the schema version. The data goes only when the
 * box was ticked, and the box says what it does.
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

	$settings = get_option( 'dahu_serial_numbers_settings', array() );

	if ( ! is_array( $settings ) || empty( $settings['delete_data_on_uninstall'] ) ) {
		// The schema version goes either way: leaving it behind means a
		// reinstall believes the tables are already up to date, and skips
		// the dbDelta that would have created them.
		delete_option( 'dahu_serial_numbers_schema' );

		return;
	}

	foreach ( array( 'dahu_serial_numbers', 'dahu_serial_numbers_log', 'dahu_serial_numbers_stations', 'dahu_serial_numbers_tokens' ) as $suffix ) {
		$table = $wpdb->prefix . $suffix;

		// Tokens and posts first, serials and history last: if this is
		// interrupted — a timeout on a large history table — what is left
		// behind is the traceability data rather than the credentials.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix, uninstall.
	}

	delete_option( 'dahu_serial_numbers_settings' );
	delete_option( 'dahu_serial_numbers_schema' );

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
