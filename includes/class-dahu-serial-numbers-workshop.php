<?php
/**
 * The packing screen: a page for a tablet on a workbench.
 *
 * ## Why it is not an admin screen
 *
 * Because the person using it is not an administrator, and should not have to
 * be. Packing is somebody standing at a bench with a barcode reader, and the
 * credential they sign in with is a credential for that bench — not a
 * WordPress account with a role, a capability set and an admin session behind
 * it. Giving a packer a WordPress login to scan serial numbers means giving
 * them wp-admin, and taking it away when they leave means remembering to.
 *
 * So this is a page of its own, outside the admin, that signs in against the
 * plugin's own workshop posts and talks to the plugin's own REST routes with
 * a bearer token. Nothing on it reads shop data without that token.
 *
 * ## It is off until somebody asks for it
 *
 * The setting defaults to off, the rewrite rule is only registered when it is
 * on, and the page answers 404 otherwise. A plugin that switched a public
 * page on at activation would have opened a door the shop did not ask for.
 *
 * ## A whole document, not a theme template
 *
 * The page prints its own `<html>`. A theme's header, menu, cookie banner and
 * newsletter pop-up are all things somebody at a bench has to scroll past
 * forty times a day, and a touch target that a theme's stylesheet has shrunk
 * to eleven pixels is a mis-scan. This screen owes the shop's visitors
 * nothing, because it has none.
 *
 * @package DahuSerialNumbers
 */

defined( 'ABSPATH' ) || exit;

/**
 * The packing screen.
 */
class Dahu_Serial_Numbers_Workshop {

	/**
	 * The query variable the rewrite rule sets.
	 */
	const QUERY_VAR = 'dahu_serial_numbers_workshop';

	/**
	 * The path the screen lives at.
	 */
	const PATH = 'packing';

	/**
	 * Option holding the rewrite state, so the rules are flushed once rather
	 * than on every load.
	 */
	const OPTION_REWRITE = 'dahu_serial_numbers_rewrite';

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public static function init() {

		add_filter( 'query_vars', array( __CLASS__, 'register_query_var' ) );
		add_action( 'init', array( __CLASS__, 'register_rewrite' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ) );
	}

	/**
	 * Is the screen switched on?
	 *
	 * @return bool
	 */
	public static function is_enabled() {

		return (bool) Dahu_Serial_Numbers_Settings::get( 'workshop_enabled' );
	}

	/**
	 * The screen's address.
	 *
	 * @return string
	 */
	public static function url() {

		// The pretty path when the shop has pretty permalinks, and the query
		// string when it does not. A plugin that only prints the pretty one
		// hands out a 404 on a shop running plain permalinks.
		if ( get_option( 'permalink_structure' ) ) {
			return home_url( '/' . self::PATH . '/' );
		}

		return add_query_arg( self::QUERY_VAR, '1', home_url( '/' ) );
	}

	/**
	 * Declares the query variable.
	 *
	 * @param string[] $vars Known query variables.
	 * @return string[]
	 */
	public static function register_query_var( $vars ) {

		$vars[] = self::QUERY_VAR;

		return $vars;
	}

	/**
	 * Declares the rewrite rule, and flushes once when it changes.
	 *
	 * @return void
	 */
	public static function register_rewrite() {

		$enabled = self::is_enabled();

		if ( $enabled ) {
			add_rewrite_rule( '^' . self::PATH . '/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
		}

		// Flushing rewrite rules is expensive and must not happen on every
		// page load. The stored flag says what the rules were last written
		// for, so this fires once when the setting changes and never again.
		$stored = get_option( self::OPTION_REWRITE );

		if ( $stored !== ( $enabled ? 'on' : 'off' ) ) {
			flush_rewrite_rules( false );
			update_option( self::OPTION_REWRITE, $enabled ? 'on' : 'off', false );
		}
	}

	/**
	 * Serves the page when the request is for it.
	 *
	 * @return void
	 */
	public static function maybe_render() {

		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		if ( ! self::is_enabled() ) {
			// 404 rather than a message saying the screen exists but is off:
			// an address that answers differently depending on a setting tells
			// a stranger that something is there.
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();

			return;
		}

		nocache_headers();
		header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
		// Not a page for search engines, and not a page to put in a frame.
		header( 'X-Robots-Tag: noindex, nofollow', true );
		header( 'X-Frame-Options: SAMEORIGIN', true );

		echo self::html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a whole document, escaped as it is built.
		exit;
	}

	/**
	 * The page, as a string.
	 *
	 * Returned rather than printed, and public rather than private, for one
	 * reason: a renderer that can only print and then exit cannot be asserted
	 * on. The bench needs to read this markup to check that a hostile shop
	 * name cannot close the script element the configuration sits in, and the
	 * first version of that assertion produced no output at all because the
	 * suite died on the exit().
	 *
	 * @return string
	 */
	public static function html() {

		ob_start();

		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
	<meta name="robots" content="noindex, nofollow" />
	<title><?php echo esc_html( self::title() ); ?></title>
	<link rel="stylesheet" href="<?php echo esc_url( DAHU_SERIAL_NUMBERS_URL . 'assets/css/workshop.css?ver=' . DAHU_SERIAL_NUMBERS_VERSION ); ?>" />
</head>
<body class="dahu-packing">
	<noscript>
		<p class="dahu-packing__noscript"><?php esc_html_e( 'This screen needs JavaScript. Serial numbers are scanned into it one at a time, and every scan is checked against the shop as it happens.', 'dahu-serial-numbers' ); ?></p>
	</noscript>

	<div id="dahu-packing-root" class="dahu-packing__root" aria-live="polite"></div>

	<script type="application/json" id="dahu-packing-config">
		<?php echo wp_json_encode( self::config() ); ?>
	</script>
	<script src="<?php echo esc_url( DAHU_SERIAL_NUMBERS_URL . 'assets/js/workshop.js?ver=' . DAHU_SERIAL_NUMBERS_VERSION ); ?>"></script>
</body>
</html>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * The page title.
	 *
	 * @return string
	 */
	private static function title() {

		return sprintf(
			/* translators: %s: shop name. */
			__( 'Packing — %s', 'dahu-serial-numbers' ),
			get_bloginfo( 'name' )
		);
	}

	/**
	 * What the script needs to know.
	 *
	 * Every string the screen can show is translated here, on the server,
	 * where the text domain is loaded. A script that carries its own English
	 * shows English to a French workshop and nobody can see why.
	 *
	 * @return array<string,mixed>
	 */
	private static function config() {

		return array(
			'root'    => esc_url_raw( rest_url( Dahu_Serial_Numbers_Rest::REST_NAMESPACE ) ),
			'shop'    => get_bloginfo( 'name' ),
			'strings' => array(
				'signIn'          => __( 'Sign in', 'dahu-serial-numbers' ),
				'login'           => __( 'Login', 'dahu-serial-numbers' ),
				'password'        => __( 'Password', 'dahu-serial-numbers' ),
				'signOut'         => __( 'Sign out', 'dahu-serial-numbers' ),
				'queue'           => __( 'To pack', 'dahu-serial-numbers' ),
				'refresh'         => __( 'Refresh', 'dahu-serial-numbers' ),
				'back'            => __( 'Back to the list', 'dahu-serial-numbers' ),
				'search'          => __( 'Search an order', 'dahu-serial-numbers' ),
				'nothingToPack'   => __( 'Nothing waiting to be packed.', 'dahu-serial-numbers' ),
				'noResults'       => __( 'No order matches that.', 'dahu-serial-numbers' ),
				'owed'            => __( '%d to scan', 'dahu-serial-numbers' ),
				'ready'           => __( 'Ready', 'dahu-serial-numbers' ),
				'scan'            => __( 'Scan or type a serial number', 'dahu-serial-numbers' ),
				// The label of the button that chooses a line, which is not
				// the placeholder of the field you then scan into. Reusing
				// the placeholder made a button as wide as the sentence, and
				// pushed the line's name off the row. Seen on a screenshot.
				'pickLine'        => __( 'Scan into this line', 'dahu-serial-numbers' ),
				'scanInto'        => __( 'Scanning into: %s', 'dahu-serial-numbers' ),
				'chooseLine'      => __( 'Choose which line you are packing.', 'dahu-serial-numbers' ),
				'remove'          => __( 'Remove', 'dahu-serial-numbers' ),
				'removeConfirm'   => __( 'Take %s off this order?', 'dahu-serial-numbers' ),
				'ofQuantity'      => __( '%1$d of %2$d', 'dahu-serial-numbers' ),
				'noSerialNeeded'  => __( 'No serial number needed', 'dahu-serial-numbers' ),
				'allScanned'      => __( 'Every unit on this order has been scanned.', 'dahu-serial-numbers' ),
				'ship'            => __( 'Mark as shipped', 'dahu-serial-numbers' ),
				'carrier'         => __( 'Carrier', 'dahu-serial-numbers' ),
				'tracking'        => __( 'Tracking number', 'dahu-serial-numbers' ),
				'parcel'          => __( 'The parcel', 'dahu-serial-numbers' ),
				'weight'          => sprintf(
					/* translators: %s: the shop's weight unit, e.g. kg. */
					__( 'Weight (%s)', 'dahu-serial-numbers' ),
					Dahu_Serial_Numbers_Consignments::weight_unit()
				),
				'dimensions'      => sprintf(
					/* translators: %s: the shop's dimension unit, e.g. cm. */
					__( 'Length × width × height (%s)', 'dahu-serial-numbers' ),
					Dahu_Serial_Numbers_Consignments::dimension_unit()
				),
				'shipping'        => __( 'Marking as shipped…', 'dahu-serial-numbers' ),
				'shipped'         => __( 'Order marked as shipped.', 'dahu-serial-numbers' ),
				'working'         => __( 'Working…', 'dahu-serial-numbers' ),
				'offline'         => __( 'The shop did not answer. Nothing was recorded — scan it again.', 'dahu-serial-numbers' ),
				'sessionOver'     => __( 'Session over. Sign in again.', 'dahu-serial-numbers' ),
				'dismiss'         => __( 'Dismiss', 'dahu-serial-numbers' ),
			),
		);
	}
}
