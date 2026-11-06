<?php
/**
 * The settings screen.
 *
 * @package UbacSerials
 */

defined( 'ABSPATH' ) || exit;

/**
 * Three settings, and a word about each.
 */
class Ubac_Serials_Admin_Settings {

	/**
	 * Capability.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Nonce action.
	 */
	const NONCE = 'ubac_serials_settings';

	/**
	 * Renders the screen.
	 *
	 * @return void
	 */
	public static function render() {

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'ubac-serials' ) );
		}

		Ubac_Serials_Admin::open(
			__( 'Settings', 'ubac-serials' ),
			__( 'Ubac Serial Numbers has three settings. Everything else about it is a filter, so that a shop can decide in its own code rather than through a screen somebody has to find.', 'ubac-serials' )
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked immediately.
		if ( isset( $_POST['ubac_action'] ) && 'save' === sanitize_key( wp_unslash( $_POST['ubac_action'] ) ) ) {
			check_admin_referer( self::NONCE );
			self::handle_save();
		}

		self::render_form();

		Ubac_Serials_Admin::close();
	}

	/**
	 * Stores the submitted settings.
	 *
	 * @return void
	 */
	private static function handle_save() {

		/* THE NONCE IS CHECKED BY THE CALLER, immediately before.
		 * render() calls check_admin_referer() and then this method; the sniff
		 * does not look beyond the function. The statuses are an array of keys,
		 * sanitised by Ubac_Serials_Settings::save(), hence the wp_unslash()
		 * without a sanitiser here. */
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- see above.
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- see above.
		$submitted = array(
			'queue_statuses'           => isset( $_POST['queue_statuses'] ) ? (array) wp_unslash( $_POST['queue_statuses'] ) : array(),
			'workshop_enabled'         => ! empty( $_POST['workshop_enabled'] ),
			'stock_push_enabled'       => ! empty( $_POST['stock_push_enabled'] ),
			'product_fix_enabled'      => ! empty( $_POST['product_fix_enabled'] ),
			'delete_data_on_uninstall' => ! empty( $_POST['delete_data_on_uninstall'] ),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$stored = Ubac_Serials_Settings::save( $submitted );

		Ubac_Serials_Admin::notice( 'success', __( 'Settings saved.', 'ubac-serials' ) );

		// Said out loud rather than silently corrected: a submission that
		// selected nothing fell back to the default, and a screen that
		// re-renders a ticked box without explaining it looks like it ignored
		// the click.
		if ( empty( $submitted['queue_statuses'] ) ) {
			Ubac_Serials_Admin::notice(
				'warning',
				__( 'No order status was selected, so the queue has gone back to watching orders being processed. A queue that watches nothing shows an empty screen with no explanation.', 'ubac-serials' )
			);
		}

		unset( $stored );
	}

	/**
	 * The form.
	 *
	 * @return void
	 */
	private static function render_form() {

		$settings = Ubac_Serials_Settings::all();
		$statuses = wc_get_order_statuses();

		?>
		<form method="post" class="ubac-serials__panel">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="ubac_action" value="save" />

			<h2><?php esc_html_e( 'The packing queue', 'ubac-serials' ); ?></h2>

			<p class="ubac-serials__hint">
				<?php esc_html_e( 'Which orders the packing screen lists. Completed orders are deliberately not the default: a completed order has left, and a queue that lists it invites somebody to open a parcel that is already on a van.', 'ubac-serials' ); ?>
			</p>

			<fieldset class="ubac-serials__fieldset">
				<legend class="screen-reader-text"><?php esc_html_e( 'Order statuses the queue watches', 'ubac-serials' ); ?></legend>
				<?php foreach ( $statuses as $key => $name ) : ?>
					<label class="ubac-serials__check">
						<input
							type="checkbox"
							name="queue_statuses[]"
							value="<?php echo esc_attr( $key ); ?>"
							<?php checked( in_array( $key, (array) $settings['queue_statuses'], true ) ); ?>
						/>
						<?php echo esc_html( $name ); ?>
					</label>
				<?php endforeach; ?>
			</fieldset>

			<h2><?php esc_html_e( 'The packing screen', 'ubac-serials' ); ?></h2>

			<label class="ubac-serials__check">
				<input type="checkbox" name="workshop_enabled" value="1" <?php checked( ! empty( $settings['workshop_enabled'] ) ); ?> />
				<?php esc_html_e( 'Make the packing screen reachable', 'ubac-serials' ); ?>
			</label>

			<p class="ubac-serials__hint">
				<?php esc_html_e( 'Off until you ask for it. The packing screen is a page that opens without a WordPress session — it signs in with a workshop post of its own — and a plugin that switched one on at activation would have opened a door the shop did not ask for.', 'ubac-serials' ); ?>
			</p>

			<?php if ( ! empty( $settings['workshop_enabled'] ) && ! Ubac_Serials_Stations::all() ) : ?>
				<p class="ubac-serials__caution">
					<?php esc_html_e( 'The screen is on but no workshop post exists yet, so nobody can sign in to it.', 'ubac-serials' ); ?>
				</p>
			<?php endif; ?>

			<h2><?php esc_html_e( 'WooCommerce stock', 'ubac-serials' ); ?></h2>

			<label class="ubac-serials__check">
				<input type="checkbox" name="stock_push_enabled" value="1" <?php checked( ! empty( $settings['stock_push_enabled'] ) ); ?> />
				<?php esc_html_e( 'Let the units on the shelf set the WooCommerce stock of serialised products', 'ubac-serials' ); ?>
			</label>

			<p class="ubac-serials__hint">
				<?php esc_html_e( 'Off until you ask for it. With it on, the stock of a serialised product becomes the number of units in stock on no order, less the pieces orders being prepared have already reserved — written as a whole number, never as an increment. Products whose stock WooCommerce does not manage, and variable parents, are left alone and named on the Stock screen.', 'ubac-serials' ); ?>
			</p>

			<p class="ubac-serials__caution">
				<?php esc_html_e( 'This changes what customers can buy. The Stock screen shows every product and what would be written before anything is, and the manual resynchronisation works whether this is on or off.', 'ubac-serials' ); ?>
			</p>

			<h2><?php esc_html_e( 'Product corrections', 'ubac-serials' ); ?></h2>

			<label class="ubac-serials__check">
				<input type="checkbox" name="product_fix_enabled" value="1" <?php checked( ! empty( $settings['product_fix_enabled'] ) ); ?> />
				<?php esc_html_e( 'Allow units in stock to be re-filed under another product', 'ubac-serials' ); ?>
			</label>

			<p class="ubac-serials__hint">
				<?php esc_html_e( 'For the box that went on the shelf under the wrong reference. Only units in stock and on no order can be re-filed, a reason is required, a run is all-or-nothing and can be put back. The dry run works with this off, so you can read what would happen before deciding.', 'ubac-serials' ); ?>
			</p>

			<h2><?php esc_html_e( 'Uninstalling', 'ubac-serials' ); ?></h2>

			<label class="ubac-serials__check">
				<input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked( ! empty( $settings['delete_data_on_uninstall'] ) ); ?> />
				<?php esc_html_e( 'Delete the serial numbers and their history when the plugin is deleted', 'ubac-serials' ); ?>
			</label>

			<p class="ubac-serials__caution">
				<?php esc_html_e( 'Off, and worth leaving off. The serial numbers and their history are the only record of which unit went to which customer, going back as far as you have been serialising — and a warranty claim arrives two years later. Uninstalling is also, most of the time, a step in reinstalling.', 'ubac-serials' ); ?>
			</p>

			<?php submit_button( __( 'Save changes', 'ubac-serials' ) ); ?>
		</form>
		<?php
	}
}
