<?php
/**
 * Plugin Name:       Dahu Serial Numbers
 * Plugin URI:        https://dahu-concept.fr
 * Description:       Per-unit traceability for WooCommerce: a serial number on every item you ship, a lifecycle with a transition matrix that refuses the moves that make no sense, an append-only history, a workshop screen where the number is captured at packing time, and a consignment ledger so a tracking number resolves to the units that travelled under it.
 * Version:           1.1.0
 * Author:            Hugo Vial-Jaime
 * Author URI:        mailto:contact@dahu-concept.fr
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       dahu-serial-numbers
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 *
 * @package DahuSerialNumbers
 */

defined( 'ABSPATH' ) || exit;

define( 'DAHU_SERIAL_NUMBERS_VERSION', '1.1.0' );
define( 'DAHU_SERIAL_NUMBERS_FILE', __FILE__ );
define( 'DAHU_SERIAL_NUMBERS_PATH', plugin_dir_path( __FILE__ ) );
define( 'DAHU_SERIAL_NUMBERS_URL', plugin_dir_url( __FILE__ ) );

/* -------------------------------------------------------------------------
 * Translations
 * ---------------------------------------------------------------------- */

add_action(
	'init',
	static function () {
		load_plugin_textdomain(
			'dahu-serial-numbers',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages'
		);
	}
);

/* -------------------------------------------------------------------------
 * Autoloader
 *
 * Dahu_Serial_Numbers_Foo_Bar => includes/class-dahu-serial-numbers-foo-bar.php
 *
 * No Composer: a plugin whose whole job is to be installed on somebody else's
 * site has no business shipping a vendor directory.
 * ---------------------------------------------------------------------- */

spl_autoload_register(
	static function ( $class ) {

		if ( 0 !== strpos( $class, 'Dahu_Serial_Numbers' ) ) {
			return;
		}

		$file = DAHU_SERIAL_NUMBERS_PATH . 'includes/class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

/* -------------------------------------------------------------------------
 * HPOS
 *
 * Every order access goes through wc_get_order() and the WC_Order accessors;
 * the plugin's own tables are its own, and none of them is an order table.
 * ---------------------------------------------------------------------- */

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				__FILE__,
				true
			);
		}
	}
);

/* -------------------------------------------------------------------------
 * Schema
 * ---------------------------------------------------------------------- */

register_activation_hook(
	__FILE__,
	static function () {
		Dahu_Serial_Numbers_Install::install();
	}
);

/**
 * Late schema check.
 *
 * The activation hook never fires on an update deployed by FTP or by git, and
 * those are how a plugin reaches most of the sites that run it. Checking a
 * stored version number on every load costs one option read and is the only
 * way a schema change actually lands.
 */
add_action( 'plugins_loaded', array( 'Dahu_Serial_Numbers_Install', 'maybe_upgrade' ), 5 );

/* -------------------------------------------------------------------------
 * Bootstrap
 *
 * The order here is the order of the product: a serial exists, it carries a
 * status, the status changes under rules, and the change is recorded. Screens
 * come after, and only in the admin.
 *
 * Everything below the WooCommerce test needs WooCommerce. Nothing above it
 * does, which is why the schema check and the daily purge sit outside: a shop
 * that switched WooCommerce off still has four tables, and the one thing that
 * should keep working is the housekeeping.
 * ---------------------------------------------------------------------- */

add_action(
	'plugins_loaded',
	static function () {

		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		Dahu_Serial_Numbers_Settings::init();
		Dahu_Serial_Numbers_Auth::init();
		Dahu_Serial_Numbers_Rest::init();
		Dahu_Serial_Numbers_Workshop::init();

		if ( is_admin() ) {
			Dahu_Serial_Numbers_Admin::init();
			Dahu_Serial_Numbers_Product_Field::init();
			Dahu_Serial_Numbers_Order_Metabox::init();
		}
	},
	20
);

/* -------------------------------------------------------------------------
 * Housekeeping
 *
 * One daily event, which drops bearer tokens whose deadline has passed. It is
 * registered outside the WooCommerce check above on purpose: a shop that has
 * switched WooCommerce off still has a table full of tokens, and the cleanup
 * is the one thing that should keep working when the rest does not.
 * ---------------------------------------------------------------------- */

define( 'DAHU_SERIAL_NUMBERS_CRON', 'dahu_serial_numbers_purge_tokens' );

add_action(
	DAHU_SERIAL_NUMBERS_CRON,
	static function () {
		Dahu_Serial_Numbers_Auth::purge_expired_tokens();
	}
);

/**
 * Schedules the daily purge when it is not already scheduled.
 *
 * Called on activation and, as a safety net, on admin_init: the activation
 * hook never fires on an update deployed by FTP or by git, and an optimisation
 * plugin that rewrites the cron array takes this event with it. The
 * wp_next_scheduled() test is what keeps this from writing to an option on
 * every admin page load.
 *
 * @return void
 */
function dahu_serial_numbers_schedule() {

	if ( wp_next_scheduled( DAHU_SERIAL_NUMBERS_CRON ) ) {
		return;
	}

	// 04:30 site time, tomorrow. Late enough that nobody is packing, and not
	// midnight, which is where every other plugin's daily job already is.
	$now   = (int) current_time( 'timestamp' );
	$at    = (int) strtotime( 'tomorrow 04:30', $now );
	$delay = max( HOUR_IN_SECONDS, $at - $now );

	wp_schedule_event( time() + $delay, 'daily', DAHU_SERIAL_NUMBERS_CRON );
}

add_action( 'admin_init', 'dahu_serial_numbers_schedule' );

register_activation_hook( __FILE__, 'dahu_serial_numbers_schedule' );

register_deactivation_hook(
	__FILE__,
	static function () {
		wp_clear_scheduled_hook( DAHU_SERIAL_NUMBERS_CRON );
	}
);

/* -------------------------------------------------------------------------
 * Plugins screen
 * ---------------------------------------------------------------------- */

add_filter(
	'plugin_row_meta',
	static function ( $links, $file ) {

		if ( plugin_basename( __FILE__ ) !== $file ) {
			return $links;
		}

		$links[] = sprintf(
			'<a href="%s" target="_blank" rel="noopener">%s</a>',
			esc_url( 'https://dahu-concept.fr' ),
			esc_html__( 'Hugo Vial-Jaime — Dahu-Concept', 'dahu-serial-numbers' )
		);

		return $links;
	},
	10,
	2
);
