<?php
/**
 * Plugin Name:       Ubac Serials
 * Description:       Per-unit traceability for WooCommerce: a serial number on every item you ship, a lifecycle with a transition matrix that refuses the moves that make no sense, an append-only history, a workshop screen where the number is captured at packing time, and a consignment ledger so a tracking number resolves to the units that travelled under it.
 * Version:           1.5.2
 * Author:            Hugo Vial-Jaime
 * Author URI:        https://dahu-concept.fr
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       ubac-serials
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * WC tested up to:   11.2
 *
 * @package UbacSerials
 */

defined( 'ABSPATH' ) || exit;

define( 'UBAC_SERIALS_VERSION', '1.5.2' );
define( 'UBAC_SERIALS_FILE', __FILE__ );
define( 'UBAC_SERIALS_PATH', plugin_dir_path( __FILE__ ) );
define( 'UBAC_SERIALS_URL', plugin_dir_url( __FILE__ ) );

/* -------------------------------------------------------------------------
 * Translations
 *
 * There is deliberately no load_plugin_textdomain() call here. Since
 * WordPress 4.6 the translations of a plugin hosted on WordPress.org are
 * loaded on demand, and the directory's review asks that the call be removed
 * rather than kept: this plugin requires 6.0, so there is no older WordPress
 * to cover. Translations come from translate.wordpress.org.
 * ---------------------------------------------------------------------- */

/* -------------------------------------------------------------------------
 * Autoloader
 *
 * Ubac_Serials_Foo_Bar => includes/class-ubac-serials-foo-bar.php
 *
 * No Composer: a plugin whose whole job is to be installed on somebody else's
 * site has no business shipping a vendor directory.
 * ---------------------------------------------------------------------- */

spl_autoload_register(
	static function ( $class ) {

		if ( 0 !== strpos( $class, 'Ubac_Serials' ) ) {
			return;
		}

		$file = UBAC_SERIALS_PATH . 'includes/class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';

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
		Ubac_Serials_Install::install();
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
add_action( 'plugins_loaded', array( 'Ubac_Serials_Install', 'maybe_upgrade' ), 5 );

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

		add_action( 'ubac_serials_consignment_tracking_amended', array( 'Ubac_Serials_Shipment', 'sync_mirror' ), 10, 2 );

		Ubac_Serials_Settings::init();

		// Before the screens: the stock push listens to the register's own
		// hooks, and a unit moved by the REST route or by the packing screen
		// has to count the same as one moved by hand in the admin.
		Ubac_Serials_Stock::init();

		Ubac_Serials_Auth::init();
		Ubac_Serials_Rest::init();
		Ubac_Serials_Workshop::init();

		if ( is_admin() ) {
			Ubac_Serials_Admin::init();
			Ubac_Serials_Product_Field::init();
			Ubac_Serials_Order_Metabox::init();
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

define( 'UBAC_SERIALS_CRON', 'ubac_serials_purge_tokens' );

add_action(
	UBAC_SERIALS_CRON,
	static function () {
		Ubac_Serials_Auth::purge_expired_tokens();
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
function ubac_serials_schedule() {

	if ( wp_next_scheduled( UBAC_SERIALS_CRON ) ) {
		return;
	}

	// 04:30 site time, tomorrow. Late enough that nobody is packing, and not
	// midnight, which is where every other plugin's daily job already is.
	$now   = (int) current_time( 'timestamp' );
	$at    = (int) strtotime( 'tomorrow 04:30', $now );
	$delay = max( HOUR_IN_SECONDS, $at - $now );

	wp_schedule_event( time() + $delay, 'daily', UBAC_SERIALS_CRON );
}

add_action( 'admin_init', 'ubac_serials_schedule' );

register_activation_hook( __FILE__, 'ubac_serials_schedule' );

register_deactivation_hook(
	__FILE__,
	static function () {
		wp_clear_scheduled_hook( UBAC_SERIALS_CRON );
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
			esc_html__( 'Hugo Vial-Jaime — Dahu-Concept', 'ubac-serials' )
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
require_once UBAC_SERIALS_PATH . 'includes/ubac-welcome.php';

/*
 * The flag, not a redirect: an activation hook that redirects breaks bulk
 * activation and WP-CLI, which activate several plugins in one request. The
 * welcome screen reads the flag on the next page load, where a redirect is
 * safe, and clears it when several plugins were activated together.
 */
register_activation_hook(
	__FILE__,
	static function () {
		Ubac_Welcome::mark_activated( 'ubac-serials' );
	}
);

add_action(
	'init',
	static function () {
		Ubac_Welcome::register(
			array(
				'slug'       => 'ubac-serials',
				'name'       => __( 'Serial numbers', 'ubac-serials' ),
				'tagline'    => __( 'Follows every unit you hold, from goods-in to the customer.', 'ubac-serials' ),
				'file'       => UBAC_SERIALS_FILE,
				'version'    => UBAC_SERIALS_VERSION,
				'textdomain' => 'ubac-serials',
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
					'next'   => __( 'Next', 'ubac-serials' ),
					'done'   => __( 'Done', 'ubac-serials' ),
					'skip'   => __( 'Skip', 'ubac-serials' ),
					'of'     => __( 'of', 'ubac-serials' ),
					'denied' => __( 'You are not allowed to view this page.', 'ubac-serials' ),
				),
				'points'     => array(
				array(
					'title' => __( 'Each unit, by name', 'ubac-serials' ),
					'text'  => __( 'A serial has a state, a place and a history you can read.', 'ubac-serials' ),
				),
				array(
					'title' => __( 'Consignments', 'ubac-serials' ),
					'text'  => __( 'What left together, what came back, and when.', 'ubac-serials' ),
				),
				array(
					'title' => __( 'No silent moves', 'ubac-serials' ),
					'text'  => __( 'A unit never changes hands without a line saying so.', 'ubac-serials' ),
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
				'tour_screen' => 'toplevel_page_ubac-serials',
				'tour'        => array(
					array(
						'selector' => '#ubac-sn-search',
						'title'    => __( 'Find any unit', 'ubac-serials' ),
						'text'     => __( 'Type a serial number to open that unit.', 'ubac-serials' ),
					),
					array(
						'selector' => '#ubac-sn-status',
						'title'    => __( 'Narrow by state', 'ubac-serials' ),
						'text'     => __( 'Show only the units in one state, such as shipped or in repair.', 'ubac-serials' ),
					),
					array(
						'selector' => '.ubac-serials__table',
						'title'    => __( 'One row per unit', 'ubac-serials' ),
						'text'     => __( 'Open a row to read that unit’s full history.', 'ubac-serials' ),
					),
				),
			)
		);
	}
);
