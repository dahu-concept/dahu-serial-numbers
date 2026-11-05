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
 * @package UbacSerials
 */

defined( 'ABSPATH' ) || exit;

/**
 * The packing screen.
 */
class Ubac_Serials_Workshop {

	/**
	 * The query variable the rewrite rule sets.
	 */
	const QUERY_VAR = 'ubac_serials_workshop';

	/**
	 * The path the screen lives at.
	 */
	const PATH = 'packing';

	/**
	 * Option holding the rewrite state, so the rules are flushed once rather
	 * than on every load.
	 */
	const OPTION_REWRITE = 'ubac_serials_rewrite';

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

		return (bool) Ubac_Serials_Settings::get( 'workshop_enabled' );
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
	<?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- standalone document built with ob_start(): it has its own <html> and <head> and never calls wp_head(), so an enqueued style would be printed nowhere. ?>
	<link rel="stylesheet" href="<?php echo esc_url( UBAC_SERIALS_URL . 'assets/css/workshop.css?ver=' . UBAC_SERIALS_VERSION ); ?>" />
</head>
<body class="ubac-packing">
	<noscript>
		<p class="ubac-packing__noscript"><?php esc_html_e( 'This screen needs JavaScript. Serial numbers are scanned into it one at a time, and every scan is checked against the shop as it happens.', 'ubac-serials' ); ?></p>
	</noscript>

	<div id="ubac-packing-root" class="ubac-packing__root" aria-live="polite"></div>

	<script type="application/json" id="ubac-packing-config">
		<?php echo wp_json_encode( self::config() ); ?>
	</script>
	<?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- same standalone document: no wp_footer(), so an enqueued script would be printed nowhere. ?>
	<script src="<?php echo esc_url( UBAC_SERIALS_URL . 'assets/js/workshop.js?ver=' . UBAC_SERIALS_VERSION ); ?>"></script>
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
			__( 'Packing — %s', 'ubac-serials' ),
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
			'root'    => esc_url_raw( rest_url( Ubac_Serials_Rest::REST_NAMESPACE ) ),
			'shop'    => get_bloginfo( 'name' ),
			'strings' => array(
				'signIn'          => __( 'Sign in', 'ubac-serials' ),
				'login'           => __( 'Login', 'ubac-serials' ),
				'password'        => __( 'Password', 'ubac-serials' ),
				'signOut'         => __( 'Sign out', 'ubac-serials' ),
				'queue'           => __( 'To pack', 'ubac-serials' ),
				'refresh'         => __( 'Refresh', 'ubac-serials' ),
				'back'            => __( 'Back to the list', 'ubac-serials' ),
				'search'          => __( 'Search an order', 'ubac-serials' ),
				'nothingToPack'   => __( 'Nothing waiting to be packed.', 'ubac-serials' ),
				'noResults'       => __( 'No order matches that.', 'ubac-serials' ),
				/* translators: %d: how many units are still to be scanned on the order */
				'owed'            => __( '%d to scan', 'ubac-serials' ),
				'ready'           => __( 'Ready', 'ubac-serials' ),
				'scan'            => __( 'Scan or type a serial number', 'ubac-serials' ),
				// The label of the button that chooses a line, which is not
				// the placeholder of the field you then scan into. Reusing
				// the placeholder made a button as wide as the sentence, and
				// pushed the line's name off the row. Seen on a screenshot.
				'pickLine'        => __( 'Scan into this line', 'ubac-serials' ),
				/* translators: %s: the name of the order line being scanned into */
				'scanInto'        => __( 'Scanning into: %s', 'ubac-serials' ),
				'chooseLine'      => __( 'Choose which line you are packing.', 'ubac-serials' ),
				'remove'          => __( 'Remove', 'ubac-serials' ),
				/* translators: %s: the serial number being taken off the order */
				'removeConfirm'   => __( 'Take %s off this order?', 'ubac-serials' ),
				/* translators: %1$d: units scanned so far, %2$d: units on the line */
				'ofQuantity'      => __( '%1$d of %2$d', 'ubac-serials' ),
				'noSerialNeeded'  => __( 'No serial number needed', 'ubac-serials' ),
				'allScanned'      => __( 'Every unit on this order has been scanned.', 'ubac-serials' ),
				'ship'            => __( 'Mark as shipped', 'ubac-serials' ),
				'carrier'         => __( 'Carrier', 'ubac-serials' ),
				'tracking'        => __( 'Tracking number', 'ubac-serials' ),
				'parcel'          => __( 'The parcel', 'ubac-serials' ),
				'weight'          => sprintf(
					/* translators: %s: the shop's weight unit, e.g. kg. */
					__( 'Weight (%s)', 'ubac-serials' ),
					Ubac_Serials_Consignments::weight_unit()
				),
				'dimensions'      => sprintf(
					/* translators: %s: the shop's dimension unit, e.g. cm. */
					__( 'Length × width × height (%s)', 'ubac-serials' ),
					Ubac_Serials_Consignments::dimension_unit()
				),
				'shipping'        => __( 'Marking as shipped…', 'ubac-serials' ),
				'shipped'         => __( 'Order marked as shipped.', 'ubac-serials' ),
				'working'         => __( 'Working…', 'ubac-serials' ),
				'offline'         => __( 'The shop did not answer. Nothing was recorded — scan it again.', 'ubac-serials' ),
				'sessionOver'     => __( 'Session over. Sign in again.', 'ubac-serials' ),
				'dismiss'         => __( 'Dismiss', 'ubac-serials' ),
			),
		);
	}
}
