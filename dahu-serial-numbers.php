<?php
/**
 * Plugin Name:       Dahu Serial Numbers
 * Plugin URI:        https://dahu-concept.fr
 * Description:       Per-unit traceability for WooCommerce: a serial number on every item you ship, a lifecycle with a transition matrix that refuses the moves that make no sense, an append-only history, a workshop screen where the number is captured at packing time, and a consignment ledger so a tracking number resolves to the units that travelled under it.
 * Version:           1.5.1
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

define( 'DAHU_SERIAL_NUMBERS_VERSION', '1.5.1' );
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

		add_action( 'dahu_serial_numbers_consignment_tracking_amended', array( 'Dahu_Serial_Numbers_Shipment', 'sync_mirror' ), 10, 2 );

		Dahu_Serial_Numbers_Settings::init();

		// Before the screens: the stock push listens to the register's own
		// hooks, and a unit moved by the REST route or by the packing screen
		// has to count the same as one moved by hand in the admin.
		Dahu_Serial_Numbers_Stock::init();

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

/*
 * The welcome screen, shown once just after activation.
 *
 * Registered here rather than inside the admin include, because the
 * activation flag has to be set whatever else is loaded.
 */
require_once DAHU_SERIAL_NUMBERS_PATH . 'includes/dahu-welcome.php';

/*
 * The flag, not a redirect: an activation hook that redirects breaks bulk
 * activation and WP-CLI, which activate several plugins in one request. The
 * welcome screen reads the flag on the next page load, where a redirect is
 * safe, and clears it when several plugins were activated together.
 */
register_activation_hook(
	__FILE__,
	static function () {
		Dahu_Welcome::mark_activated( 'dahu-serial-numbers' );
	}
);

add_action(
	'init',
	static function () {
		Dahu_Welcome::register(
			array(
				'slug'       => 'dahu-serial-numbers',
				'name'       => __( 'Serial numbers', 'dahu-serial-numbers' ),
				'tagline'    => __( 'Follows every unit you hold, from goods-in to the customer.', 'dahu-serial-numbers' ),
				'file'       => DAHU_SERIAL_NUMBERS_FILE,
				'version'    => DAHU_SERIAL_NUMBERS_VERSION,
				'textdomain' => 'dahu-serial-numbers',
				'mark'       => array(
					'view'  => '0 0 112 112',
					'paths' => array(
						'M34 10 L104 10 Q110 10 110 16 L110 88 Q110 94 104 94 L34 94 L6 52 Z',
						'M54 34 L54 70 M70 30 L70 70 M86 38 L86 70',
						'M27.0 52.0 a9.0 9.0 0 1 0 18.0 0 a9.0 9.0 0 1 0 -18.0 0',
					),
				),
				/*
				 * The welcome screen's words come from here: the shared file is
				 * copied byte for byte across the whole family, so it can carry
				 * no text domain of its own.
				 */
				'strings'    => array(
					'next'   => __( 'Next', 'dahu-serial-numbers' ),
					'done'   => __( 'Done', 'dahu-serial-numbers' ),
					'skip'   => __( 'Skip', 'dahu-serial-numbers' ),
					'of'     => __( 'of', 'dahu-serial-numbers' ),
					'denied' => __( 'You are not allowed to view this page.', 'dahu-serial-numbers' ),
				),
				'points'     => array(
				array(
					'title' => __( 'Each unit, by name', 'dahu-serial-numbers' ),
					'text'  => __( 'A serial has a state, a place and a history you can read.', 'dahu-serial-numbers' ),
				),
				array(
					'title' => __( 'Consignments', 'dahu-serial-numbers' ),
					'text'  => __( 'What left together, what came back, and when.', 'dahu-serial-numbers' ),
				),
				array(
					'title' => __( 'No silent moves', 'dahu-serial-numbers' ),
					'text'  => __( 'A unit never changes hands without a line saying so.', 'dahu-serial-numbers' ),
				),
				),

				/*
				 * The one-time tour, on the main screen (the serial list).
				 *
				 * Every selector below points at something that screen always renders,
				 * on a shop that has just installed the plugin and holds no serial yet.
				 * A step whose target is missing is skipped without a sound, so the
				 * rows, the pagination and the per-status summary line -- all only
				 * rendered once data exists -- are deliberately not used. The filter
				 * form and the table (with its header and its empty-state row) are
				 * rendered either way.
				 */
				'tour_screen' => 'toplevel_page_dahu-serial-numbers',
				'tour'        => array(
					array(
						'selector' => '#dahu-sn-search',
						'title'    => __( 'Find any unit', 'dahu-serial-numbers' ),
						'text'     => __( 'Type a serial number to open that unit.', 'dahu-serial-numbers' ),
					),
					array(
						'selector' => '#dahu-sn-status',
						'title'    => __( 'Narrow by state', 'dahu-serial-numbers' ),
						'text'     => __( 'Show only the units in one state, such as shipped or in repair.', 'dahu-serial-numbers' ),
					),
					array(
						'selector' => '.dahu-serial-numbers__table',
						'title'    => __( 'One row per unit', 'dahu-serial-numbers' ),
						'text'     => __( 'Open a row to read that unit’s full history.', 'dahu-serial-numbers' ),
					),
				),
			)
		);
	}
);
