<?php
/**
 * The workshop-posts screen.
 *
 * @package DahuSerialNumbers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Create, rename, deactivate and re-password a workshop post.
 */
class Dahu_Serial_Numbers_Admin_Stations {

	/**
	 * Capability. Higher than the rest of the plugin's screens, because a
	 * post is a credential for a page reachable without a WordPress session.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Nonce action, shared by the three forms on this screen.
	 */
	const NONCE = 'dahu_serial_numbers_stations';

	/**
	 * Renders the screen.
	 *
	 * @return void
	 */
	public static function render() {

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage workshop posts.', 'dahu-serial-numbers' ) );
		}

		Dahu_Serial_Numbers_Admin::open(
			__( 'Workshop posts', 'dahu-serial-numbers' ),
			__( 'Dahu Serial Numbers signs the packing screen in with one login per bench, not one per person: a post is a tablet on a workbench, used by whoever is packing.', 'dahu-serial-numbers' )
		);

		if ( ! Dahu_Serial_Numbers_Admin::warn_if_no_tables() ) {
			self::handle();
			self::render_form();
			self::render_list();
		}

		Dahu_Serial_Numbers_Admin::close();
	}

	/**
	 * Runs whichever action was submitted.
	 *
	 * @return void
	 */
	private static function handle() {

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is checked below, once an action has been named.
		$action = isset( $_POST['dahu_action'] ) ? sanitize_key( wp_unslash( $_POST['dahu_action'] ) ) : '';

		if ( '' === $action ) {
			return;
		}

		check_admin_referer( self::NONCE );

		switch ( $action ) {
			case 'create':
				self::handle_create();
				break;

			case 'toggle':
				self::handle_toggle();
				break;

			case 'password':
				self::handle_password();
				break;
		}
	}

	/**
	 * Creates a post.
	 *
	 * @return void
	 */
	private static function handle_create() {

		$login    = isset( $_POST['login'] ) ? sanitize_user( wp_unslash( $_POST['login'] ), true ) : '';
		$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';
		$label    = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';

		$created = Dahu_Serial_Numbers_Stations::create( $login, $password, $label );

		if ( is_wp_error( $created ) ) {
			Dahu_Serial_Numbers_Admin::notice( 'error', $created->get_error_message() );

			return;
		}

		Dahu_Serial_Numbers_Admin::notice(
			'success',
			sprintf(
				/* translators: %s: workshop post login. */
				__( 'Workshop post “%s” created.', 'dahu-serial-numbers' ),
				$login
			)
		);
	}

	/**
	 * Switches a post on or off.
	 *
	 * @return void
	 */
	private static function handle_toggle() {

		$id   = isset( $_POST['station_id'] ) ? (int) $_POST['station_id'] : 0;
		$post = Dahu_Serial_Numbers_Stations::get( $id );

		if ( ! $post ) {
			Dahu_Serial_Numbers_Admin::notice( 'error', __( 'Unknown workshop post.', 'dahu-serial-numbers' ) );

			return;
		}

		$wanted = 1 !== (int) $post['active'];
		$result = Dahu_Serial_Numbers_Stations::set_active( $id, $wanted );

		if ( is_wp_error( $result ) ) {
			Dahu_Serial_Numbers_Admin::notice( 'error', $result->get_error_message() );

			return;
		}

		Dahu_Serial_Numbers_Admin::notice(
			'success',
			$wanted
				? __( 'Workshop post switched back on.', 'dahu-serial-numbers' )
				// Said explicitly, because it is the part somebody needs to
				// know: switching a post off does not wait for its session to
				// run out, it ends it now.
				: __( 'Workshop post switched off, and signed out everywhere it was signed in.', 'dahu-serial-numbers' )
		);
	}

	/**
	 * Replaces a post's password.
	 *
	 * @return void
	 */
	private static function handle_password() {

		$id       = isset( $_POST['station_id'] ) ? (int) $_POST['station_id'] : 0;
		$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';

		$result = Dahu_Serial_Numbers_Stations::set_password( $id, $password );

		if ( is_wp_error( $result ) ) {
			Dahu_Serial_Numbers_Admin::notice( 'error', $result->get_error_message() );

			return;
		}

		Dahu_Serial_Numbers_Admin::notice(
			'success',
			__( 'Password replaced, and the post signed out everywhere it was signed in.', 'dahu-serial-numbers' )
		);
	}

	/**
	 * The creation form.
	 *
	 * @return void
	 */
	private static function render_form() {

		// A suggestion, not an imposition: the field is a normal text input
		// with the value pre-filled, so whoever is setting the bench up can
		// read it out loud, write it on a card, or type their own.
		$suggestion = wp_generate_password( 16, false, false );

		?>
		<h2><?php esc_html_e( 'New post', 'dahu-serial-numbers' ); ?></h2>

		<form method="post" class="dahu-serial-numbers__panel">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="dahu_action" value="create" />

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="dahu-sn-login"><?php esc_html_e( 'Login', 'dahu-serial-numbers' ); ?></label></th>
					<td>
						<input type="text" id="dahu-sn-login" name="login" class="regular-text" autocomplete="off" required />
						<p class="dahu-serial-numbers__hint"><?php esc_html_e( 'Something the bench can read off a card: bench-1, packing-left.', 'dahu-serial-numbers' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="dahu-sn-password"><?php esc_html_e( 'Password', 'dahu-serial-numbers' ); ?></label></th>
					<td>
						<input
							type="text"
							id="dahu-sn-password"
							name="password"
							class="regular-text"
							value="<?php echo esc_attr( $suggestion ); ?>"
							autocomplete="off"
							minlength="<?php echo esc_attr( (string) Dahu_Serial_Numbers_Stations::MIN_PASSWORD_LENGTH ); ?>"
							required
						/>
						<p class="dahu-serial-numbers__hint">
							<?php
							printf(
								/* translators: %d: minimum number of characters. */
								esc_html__( 'Generated for you, and shown rather than hidden so you can write it down once. At least %d characters.', 'dahu-serial-numbers' ),
								(int) Dahu_Serial_Numbers_Stations::MIN_PASSWORD_LENGTH
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="dahu-sn-label"><?php esc_html_e( 'Name', 'dahu-serial-numbers' ); ?></label></th>
					<td>
						<input type="text" id="dahu-sn-label" name="label" class="regular-text" placeholder="<?php esc_attr_e( 'Bench 1', 'dahu-serial-numbers' ); ?>" />
						<p class="dahu-serial-numbers__hint"><?php esc_html_e( 'Shown on the packing screen and in the history. Defaults to the login.', 'dahu-serial-numbers' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Create post', 'dahu-serial-numbers' ) ); ?>
		</form>
		<?php
	}

	/**
	 * The list of posts.
	 *
	 * @return void
	 */
	private static function render_list() {

		$posts = Dahu_Serial_Numbers_Stations::all();

		?>
		<h2><?php esc_html_e( 'Existing posts', 'dahu-serial-numbers' ); ?></h2>

		<table class="widefat striped dahu-serial-numbers__table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Login', 'dahu-serial-numbers' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Name', 'dahu-serial-numbers' ); ?></th>
					<th scope="col"><?php esc_html_e( 'State', 'dahu-serial-numbers' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Created', 'dahu-serial-numbers' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Last sign-in', 'dahu-serial-numbers' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( ! $posts ) : ?>
				<tr>
					<td colspan="5"><?php esc_html_e( 'No workshop post yet. The packing screen needs one to sign in with.', 'dahu-serial-numbers' ); ?></td>
				</tr>
			<?php else : ?>
				<?php foreach ( $posts as $post ) : ?>
					<?php $active = 1 === (int) $post['active']; ?>
					<tr>
						<td>
							<strong><?php echo esc_html( $post['login'] ); ?></strong>

							<?php
							// Row actions as links, not framed buttons: a
							// framed button per row is reserved for the
							// action of the screen, and a column of them
							// hierarchises nothing.
							?>
							<div class="row-actions">
								<span class="dahu-serial-numbers__row-action">
									<form method="post">
										<?php wp_nonce_field( self::NONCE ); ?>
										<input type="hidden" name="dahu_action" value="toggle" />
										<input type="hidden" name="station_id" value="<?php echo esc_attr( (string) (int) $post['id'] ); ?>" />
										<button type="submit" class="button-link">
											<?php echo $active ? esc_html__( 'Switch off', 'dahu-serial-numbers' ) : esc_html__( 'Switch back on', 'dahu-serial-numbers' ); ?>
										</button>
									</form>
								</span>
								<span class="dahu-serial-numbers__row-action">
									<form method="post">
										<?php wp_nonce_field( self::NONCE ); ?>
										<input type="hidden" name="dahu_action" value="password" />
										<input type="hidden" name="station_id" value="<?php echo esc_attr( (string) (int) $post['id'] ); ?>" />
										<label class="screen-reader-text" for="dahu-sn-pass-<?php echo esc_attr( (string) (int) $post['id'] ); ?>">
											<?php esc_html_e( 'New password', 'dahu-serial-numbers' ); ?>
										</label>
										<input
											type="text"
											id="dahu-sn-pass-<?php echo esc_attr( (string) (int) $post['id'] ); ?>"
											name="password"
											class="dahu-serial-numbers__inline-field"
											placeholder="<?php esc_attr_e( 'New password', 'dahu-serial-numbers' ); ?>"
											autocomplete="off"
											minlength="<?php echo esc_attr( (string) Dahu_Serial_Numbers_Stations::MIN_PASSWORD_LENGTH ); ?>"
										/>
										<button type="submit" class="button-link"><?php esc_html_e( 'Replace', 'dahu-serial-numbers' ); ?></button>
									</form>
								</span>
							</div>
						</td>
						<td><?php echo esc_html( $post['label'] ); ?></td>
						<td>
							<?php if ( $active ) : ?>
								<span class="dahu-serial-numbers__state dahu-serial-numbers__state--on"><?php esc_html_e( 'On', 'dahu-serial-numbers' ); ?></span>
							<?php else : ?>
								<span class="dahu-serial-numbers__state dahu-serial-numbers__state--off"><?php esc_html_e( 'Off', 'dahu-serial-numbers' ); ?></span>
							<?php endif; ?>
						</td>
						<td class="dahu-serial-numbers__num"><?php echo esc_html( Dahu_Serial_Numbers_Admin::local( $post['created_at'] ) ); ?></td>
						<td class="dahu-serial-numbers__num"><?php echo esc_html( Dahu_Serial_Numbers_Admin::local( $post['last_login_at'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>

		<p class="dahu-serial-numbers__hint">
			<?php esc_html_e( 'A post is never deleted. The history points at post ids, and a line that answers “who packed this parcel” with a missing id answers nothing — so switching off refuses every future sign-in while leaving the past readable.', 'dahu-serial-numbers' ); ?>
		</p>
		<?php
	}
}
