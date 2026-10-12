<?php
/**
 * The settings screen.
 *
 * @package DahuSerialNumbers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Three settings, and a word about each.
 */
class Dahu_Serial_Numbers_Admin_Settings {

	/**
	 * Capability.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Nonce action.
	 */
	const NONCE = 'dahu_serial_numbers_settings';

	/**
	 * Renders the screen.
	 *
	 * @return void
	 */
	public static function render() {

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'dahu-serial-numbers' ) );
		}

		Dahu_Serial_Numbers_Admin::open(
			__( 'Settings', 'dahu-serial-numbers' ),
			__( 'Dahu Serial Numbers has three settings. Everything else about it is a filter, so that a shop can decide in its own code rather than through a screen somebody has to find.', 'dahu-serial-numbers' )
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked immediately.
		if ( isset( $_POST['dahu_action'] ) && 'save' === sanitize_key( wp_unslash( $_POST['dahu_action'] ) ) ) {
			check_admin_referer( self::NONCE );
			self::handle_save();
		}

		self::render_form();

		Dahu_Serial_Numbers_Admin::close();
	}

	/**
	 * Stores the submitted settings.
	 *
	 * @return void
	 */
	private static function handle_save() {

		$submitted = array(
			'queue_statuses'           => isset( $_POST['queue_statuses'] ) ? (array) wp_unslash( $_POST['queue_statuses'] ) : array(),
			'workshop_enabled'         => ! empty( $_POST['workshop_enabled'] ),
			'delete_data_on_uninstall' => ! empty( $_POST['delete_data_on_uninstall'] ),
		);

		$stored = Dahu_Serial_Numbers_Settings::save( $submitted );

		Dahu_Serial_Numbers_Admin::notice( 'success', __( 'Settings saved.', 'dahu-serial-numbers' ) );

		// Said out loud rather than silently corrected: a submission that
		// selected nothing fell back to the default, and a screen that
		// re-renders a ticked box without explaining it looks like it ignored
		// the click.
		if ( empty( $submitted['queue_statuses'] ) ) {
			Dahu_Serial_Numbers_Admin::notice(
				'warning',
				__( 'No order status was selected, so the queue has gone back to watching orders being processed. A queue that watches nothing shows an empty screen with no explanation.', 'dahu-serial-numbers' )
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

		$settings = Dahu_Serial_Numbers_Settings::all();
		$statuses = wc_get_order_statuses();

		?>
		<form method="post" class="dahu-serial-numbers__panel">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="dahu_action" value="save" />

			<h2><?php esc_html_e( 'The packing queue', 'dahu-serial-numbers' ); ?></h2>

			<p class="dahu-serial-numbers__hint">
				<?php esc_html_e( 'Which orders the packing screen lists. Completed orders are deliberately not the default: a completed order has left, and a queue that lists it invites somebody to open a parcel that is already on a van.', 'dahu-serial-numbers' ); ?>
			</p>

			<fieldset class="dahu-serial-numbers__fieldset">
				<legend class="screen-reader-text"><?php esc_html_e( 'Order statuses the queue watches', 'dahu-serial-numbers' ); ?></legend>
				<?php foreach ( $statuses as $key => $name ) : ?>
					<label class="dahu-serial-numbers__check">
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

			<h2><?php esc_html_e( 'The packing screen', 'dahu-serial-numbers' ); ?></h2>

			<label class="dahu-serial-numbers__check">
				<input type="checkbox" name="workshop_enabled" value="1" <?php checked( ! empty( $settings['workshop_enabled'] ) ); ?> />
				<?php esc_html_e( 'Make the packing screen reachable', 'dahu-serial-numbers' ); ?>
			</label>

			<p class="dahu-serial-numbers__hint">
				<?php esc_html_e( 'Off until you ask for it. The packing screen is a page that opens without a WordPress session — it signs in with a workshop post of its own — and a plugin that switched one on at activation would have opened a door the shop did not ask for.', 'dahu-serial-numbers' ); ?>
			</p>

			<?php if ( ! empty( $settings['workshop_enabled'] ) && ! Dahu_Serial_Numbers_Stations::all() ) : ?>
				<p class="dahu-serial-numbers__caution">
					<?php esc_html_e( 'The screen is on but no workshop post exists yet, so nobody can sign in to it.', 'dahu-serial-numbers' ); ?>
				</p>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Uninstalling', 'dahu-serial-numbers' ); ?></h2>

			<label class="dahu-serial-numbers__check">
				<input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked( ! empty( $settings['delete_data_on_uninstall'] ) ); ?> />
				<?php esc_html_e( 'Delete the serial numbers and their history when the plugin is deleted', 'dahu-serial-numbers' ); ?>
			</label>

			<p class="dahu-serial-numbers__caution">
				<?php esc_html_e( 'Off, and worth leaving off. The serial numbers and their history are the only record of which unit went to which customer, going back as far as you have been serialising — and a warranty claim arrives two years later. Uninstalling is also, most of the time, a step in reinstalling.', 'dahu-serial-numbers' ); ?>
			</p>

			<?php submit_button( __( 'Save changes', 'dahu-serial-numbers' ) ); ?>
		</form>
		<?php
	}
}
