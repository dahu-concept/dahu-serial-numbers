<?php
/**
 * The settings, which are three.
 *
 * Three, and the restraint is the design. The plugin this was extracted from
 * had a settings screen with supplier mappings, reorder thresholds, firmware
 * channels, category equivalences and two carrier configurations — because
 * each of those was a decision that shop had to record somewhere. None of
 * them is a decision a plugin can ask a stranger to make on installation.
 *
 * What is left is what cannot be inferred: which order statuses the packing
 * queue should watch, whether the workshop screen is reachable at all, and
 * whether uninstalling should take the data with it. Everything else is a
 * filter, documented in README.md, for the shop that needs it.
 *
 * @package DahuSerialNumbers
 */

defined( 'ABSPATH' ) || exit;

/**
 * One option, read through here.
 */
class Dahu_Serial_Numbers_Settings {

	/**
	 * Option name. One option, not three: three options are three reads on
	 * every page load and three chances for one of them to be missing.
	 */
	const OPTION = 'dahu_serial_numbers_settings';

	/**
	 * The settings, with everything missing filled in.
	 *
	 * @return array<string,mixed>
	 */
	public static function all() {

		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array_merge( self::defaults(), $stored );
	}

	/**
	 * What the plugin does before anybody has opened the screen.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {

		return array(
			// Orders being prepared. Not completed ones: a completed order has
			// left, and a queue that lists it invites somebody to open a
			// parcel that is already on a van.
			'queue_statuses'           => array( 'wc-processing' ),

			// Off. The workshop screen is a page reachable without a
			// WordPress session, and a plugin that switches one on at
			// activation has opened a door the shop did not ask for.
			'workshop_enabled'         => false,

			// Off. This one writes to WooCommerce's own stock column, which
			// is the number customers buy against. A plugin installed to
			// read histories must not start changing what the shop can sell
			// because it was activated.
			'stock_push_enabled'       => false,

			// Off. The dry run works either way, which is how somebody
			// decides whether to turn this on: they read what it would do.
			'product_fix_enabled'      => false,

			// Off, and uninstall.php says at length why.
			'delete_data_on_uninstall' => false,
		);
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed Null when there is no such setting.
	 */
	public static function get( $key ) {

		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : null;
	}

	/**
	 * Writes the settings, cleaned up.
	 *
	 * @param array $input Raw input, from the screen or from code.
	 * @return array<string,mixed> What was actually stored.
	 */
	public static function save( array $input ) {

		$clean = self::sanitise( $input );

		// Not autoloaded. Read on the screens, on the REST routes and in the
		// queue — none of which is every page of the site.
		update_option( self::OPTION, $clean, false );

		return $clean;
	}

	/**
	 * Cleans up submitted settings.
	 *
	 * @param array $input Raw input.
	 * @return array<string,mixed>
	 */
	public static function sanitise( array $input ) {

		$known    = wc_get_order_statuses();
		$statuses = array();

		foreach ( (array) ( isset( $input['queue_statuses'] ) ? $input['queue_statuses'] : array() ) as $status ) {
			$status = sanitize_key( (string) $status );
			$status = 0 === strpos( $status, 'wc-' ) ? $status : 'wc-' . $status;

			if ( array_key_exists( $status, $known ) && ! in_array( $status, $statuses, true ) ) {
				$statuses[] = $status;
			}
		}

		// An empty list is not "watch nothing", it is a queue that shows an
		// empty screen with no explanation. A submission that selects nothing
		// falls back to the default, and the screen says so.
		if ( ! $statuses ) {
			$statuses = self::defaults()['queue_statuses'];
		}

		return array(
			'queue_statuses'           => $statuses,
			'workshop_enabled'         => ! empty( $input['workshop_enabled'] ),
			'stock_push_enabled'       => ! empty( $input['stock_push_enabled'] ),
			'product_fix_enabled'      => ! empty( $input['product_fix_enabled'] ),
			'delete_data_on_uninstall' => ! empty( $input['delete_data_on_uninstall'] ),
		);
	}

	/**
	 * Hooks the settings onto the filters the rest of the plugin reads.
	 *
	 * The queue reads a filter, not this class, so that a shop can decide in
	 * code without a stored setting — and so that the stored setting is one
	 * caller of the same door rather than a second, competing one.
	 *
	 * @return void
	 */
	public static function init() {

		add_filter(
			'dahu_serial_numbers_queue_statuses',
			static function ( $statuses ) {
				$stored = self::get( 'queue_statuses' );

				return is_array( $stored ) && $stored ? $stored : $statuses;
			}
		);
	}
}
