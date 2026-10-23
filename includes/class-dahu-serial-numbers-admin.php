<?php
/**
 * The admin menu, and the pieces every screen of it shares.
 *
 * @package DahuSerialNumbers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Menu, assets, and the header every screen opens with.
 */
class Dahu_Serial_Numbers_Admin {

	/**
	 * Top-level menu slug.
	 */
	const SLUG = 'dahu-serial-numbers';

	/**
	 * Capability for every screen here.
	 *
	 * `manage_woocommerce` rather than `manage_options`: this is shop data,
	 * and a shop manager who may refund an order may certainly look at which
	 * unit went out with it. The workshop-posts screen asks for more, and
	 * says why on itself.
	 */
	const CAPABILITY = 'manage_woocommerce';

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public static function init() {

		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * The menu icon: the family's own tag glyph, as a data URI.
	 *
	 * A data URI rather than a URL, because WordPress recolours a menu icon to
	 * follow the admin colour scheme and its hover and current states, and it
	 * can only do that to an image it inlines. The glyph is drawn in `black`
	 * for the same reason: the colour here is never the colour on screen.
	 *
	 * It shares its OUTLINE with `.wordpress-org/icon.svg`, which
	 * `_assets/make-wporg-assets.py` draws, so the sidebar and the plugin
	 * directory show the same tag. The inside is deliberately not identical:
	 * measured at 20 px, the icon's three bars and its small hole turned to
	 * mush, so the menu carries two thicker bars and a wider hole. The shared
	 * outline is what `_harness/prove-menu-icon.php` compares, character for
	 * character -- the two drawings are never seen side by side, so nothing
	 * else would catch them drifting apart.
	 *
	 * @return string A `data:image/svg+xml;base64,...` URI.
	 */
	private static function menu_icon() {

		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 112 112">'
			. '<g fill="none" stroke="black" stroke-width="11" stroke-linecap="round" stroke-linejoin="round">'
			. '<path d="M34 10 L104 10 Q110 10 110 16 L110 88 Q110 94 104 94 L34 94 L6 52 Z"/>'
			. '<path d="M62 34 L62 70 M84 34 L84 70"/>'
			. '<circle cx="36" cy="52" r="10"/>'
			. '</g></svg>';

		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	/**
	 * Declares the menu and its screens.
	 *
	 * @return void
	 */
	public static function register_menu() {

		add_menu_page(
			// The page title and the menu title are the same string, and the
			// H1 below is the same string again. Arriving somewhere whose
			// name changed on the way in makes a reader doubt they are in the
			// right place.
			__( 'Serial numbers', 'dahu-serial-numbers' ),
			__( 'Serial numbers', 'dahu-serial-numbers' ),
			self::CAPABILITY,
			self::SLUG,
			array( 'Dahu_Serial_Numbers_Admin_Serials', 'render' ),
			self::menu_icon(),
			56
		);

		add_submenu_page(
			self::SLUG,
			__( 'Serial numbers', 'dahu-serial-numbers' ),
			__( 'All serial numbers', 'dahu-serial-numbers' ),
			self::CAPABILITY,
			self::SLUG,
			array( 'Dahu_Serial_Numbers_Admin_Serials', 'render' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'Consignments', 'dahu-serial-numbers' ),
			__( 'Consignments', 'dahu-serial-numbers' ),
			self::CAPABILITY,
			self::SLUG . '-consignments',
			array( 'Dahu_Serial_Numbers_Admin_Consignments', 'render' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'Stock', 'dahu-serial-numbers' ),
			__( 'Stock', 'dahu-serial-numbers' ),
			self::CAPABILITY,
			self::SLUG . '-stock',
			array( 'Dahu_Serial_Numbers_Admin_Stock', 'render' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'Workshop posts', 'dahu-serial-numbers' ),
			__( 'Workshop posts', 'dahu-serial-numbers' ),
			// Higher than the rest, deliberately: a workshop post is a
			// credential for a page reachable without a WordPress session,
			// which is a different kind of thing from shop data.
			'manage_options',
			self::SLUG . '-stations',
			array( 'Dahu_Serial_Numbers_Admin_Stations', 'render' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'Settings', 'dahu-serial-numbers' ),
			__( 'Settings', 'dahu-serial-numbers' ),
			'manage_options',
			self::SLUG . '-settings',
			array( 'Dahu_Serial_Numbers_Admin_Settings', 'render' )
		);
	}

	/**
	 * Puts the stylesheet where this plugin draws something, and nowhere else.
	 *
	 * Its own screens, and the order screen — the order panel is ours and it
	 * uses our classes, so a sheet that stopped at our own menu would leave
	 * that panel unstyled. Never on the whole admin.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function enqueue( $hook ) {

		$hook = (string) $hook;
		$mine = false !== strpos( $hook, self::SLUG );

		// Both order screens: `post.php`/`post-new.php` on the legacy tables
		// with a shop_order open, and `woocommerce_page_wc-orders` under HPOS.
		$order_screen = false !== strpos( $hook, 'wc-orders' );

		if ( ! $order_screen && in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			$screen       = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			$order_screen = $screen && 'shop_order' === $screen->id;
		}

		if ( ! $mine && ! $order_screen ) {
			return;
		}

		// The family sheet first, and its own second: the plugin's own rules
		// are written to sit on top of the shared components, so the order is
		// a dependency and not a hope. One handle across the family -- the
		// file is byte-identical everywhere, so whichever plugin loads it
		// first serves all of them, and a shop with six of these installed
		// downloads one copy.
		wp_enqueue_style(
			'dahu-ui',
			DAHU_SERIAL_NUMBERS_URL . 'assets/css/dahu-ui.css',
			array(),
			DAHU_SERIAL_NUMBERS_VERSION
		);

		wp_enqueue_style(
			'dahu-serial-numbers-admin',
			DAHU_SERIAL_NUMBERS_URL . 'assets/css/admin.css',
			array( 'dahu-ui' ),
			DAHU_SERIAL_NUMBERS_VERSION
		);
	}

	/**
	 * Opens a screen: wrap, H1, one introductory sentence, notice marker.
	 *
	 * The marker goes **after** the introduction. WordPress moves every admin
	 * notice to just after the first heading of `.wrap` — in JavaScript,
	 * after the page has loaded — unless the screen says where it wants them.
	 * Without it the introduction ends up underneath the stack of notices,
	 * which on a shop running half a dozen plugins is below the fold.
	 *
	 * @param string $title Screen title. The same string as the menu item.
	 * @param string $intro One sentence, which names the plugin.
	 * @return void
	 */
	public static function open( $title, $intro ) {

		echo '<div class="wrap dahu-ui dahu-serial-numbers">';
		echo '<div class="dahu-ui-head">';
		printf( '<h1 class="dahu-ui-head__title">%s</h1>', esc_html( $title ) );
		printf( '<p class="dahu-ui-head__sub">%s</p>', esc_html( $intro ) );
		echo '</div>';
		echo '<hr class="wp-header-end">';
	}

	/**
	 * Closes a screen.
	 *
	 * @return void
	 */
	public static function close() {

		echo '</div>';
	}

	/**
	 * Renders a notice.
	 *
	 * @param string $tone    success|error|warning|info.
	 * @param string $message Message, already translated.
	 * @return void
	 */
	public static function notice( $tone, $message ) {

		printf(
			'<div class="notice notice-%s"><p>%s</p></div>',
			esc_attr( $tone ),
			esc_html( $message )
		);
	}

	/**
	 * Says the tables are missing, when they are.
	 *
	 * Said out loud rather than shown as an empty list, because an empty list
	 * looks exactly like a shop that has not filed a serial yet and sends
	 * somebody looking in the wrong place.
	 *
	 * @return bool True when something was wrong and has been reported.
	 */
	public static function warn_if_no_tables() {

		if ( Dahu_Serial_Numbers_Install::tables_exist() ) {
			return false;
		}

		self::notice(
			'error',
			__( 'The plugin’s database tables are missing. Deactivating and reactivating the plugin creates them.', 'dahu-serial-numbers' )
		);

		return true;
	}

	/**
	 * A stored UTC datetime, as the shop's clock reads it.
	 *
	 * @param string|null $datetime Stored datetime, UTC.
	 * @return string An em dash when there is nothing to show.
	 */
	public static function local( $datetime ) {

		if ( empty( $datetime ) ) {
			return '—';
		}

		return get_date_from_gmt( (string) $datetime, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
	}
}
