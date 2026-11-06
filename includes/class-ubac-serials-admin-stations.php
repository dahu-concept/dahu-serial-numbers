<?php
/**
 * The workshop-posts screen.
 *
 * @package UbacSerials
 */

defined( 'ABSPATH' ) || exit;

/**
 * Create, rename, deactivate and re-password a workshop post.
 */
class Ubac_Serials_Admin_Stations {

	/**
	 * Capability. Higher than the rest of the plugin's screens, because a
	 * post is a credential for a page reachable without a WordPress session.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Nonce action, shared by the three forms on this screen.
	 */
	const NONCE = 'ubac_serials_stations';

	/**
	 * Renders the screen.
	 *
	 * @return void
	 */
	public static function render() {

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage workshop posts.', 'ubac-serials' ) );
		}

		Ubac_Serials_Admin::open(
			__( 'Workshop posts', 'ubac-serials' ),
			__( 'Ubac Serial Numbers signs the packing screen in with one login per bench, not one per person: a post is a tablet on a workbench, used by whoever is packing.', 'ubac-serials' )
		);

		if ( ! Ubac_Serials_Admin::warn_if_no_tables() ) {
			self::handle();
			self::render_form();
			self::render_list();
		}

		Ubac_Serials_Admin::close();
	}

	/**
	 * Runs whichever action was submitted.
	 *
	 * @return void
	 */
	private static function handle() {

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is checked below, once an action has been named.
		$action = isset( $_POST['ubac_action'] ) ? sanitize_key( wp_unslash( $_POST['ubac_action'] ) ) : '';

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

		/* THE NONCE IS CHECKED, ONE FRAME UP.
		 * handle() calls check_admin_referer() before dispatching to this
		 * method, but the sniff does not look beyond the function: it
		 * reports a password form "without nonce verification", which stops
		 * a reviewer dead. The guard is named here because it is invisible
		 * from here.
		 *
		 * The password is NOT sanitised, and that is deliberate: sanitising
		 * it would quietly change it, and the user could no longer sign in
		 * with what they typed. It is unslashed, then handed to
		 * wp_hash_password(), whose business it is. */
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- see above.
		$login    = isset( $_POST['login'] ) ? sanitize_user( wp_unslash( $_POST['login'] ), true ) : '';
		$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';
		$label    = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$created = Ubac_Serials_Stations::create( $login, $password, $label );

		if ( is_wp_error( $created ) ) {
			Ubac_Serials_Admin::notice( 'error', $created->get_error_message() );

			return;
		}

		Ubac_Serials_Admin::notice(
			'success',
			sprintf(
				/* translators: %s: workshop post login. */
				__( 'Workshop post “%s” created.', 'ubac-serials' ),
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

		// Same guard as handle_create(): handle() checked the nonce before
		// dispatching here.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see handle().
		$id   = isset( $_POST['station_id'] ) ? (int) $_POST['station_id'] : 0;
		$post = Ubac_Serials_Stations::get( $id );

		if ( ! $post ) {
			Ubac_Serials_Admin::notice( 'error', __( 'Unknown workshop post.', 'ubac-serials' ) );

			return;
		}

		$wanted = 1 !== (int) $post['active'];
		$result = Ubac_Serials_Stations::set_active( $id, $wanted );

		if ( is_wp_error( $result ) ) {
			Ubac_Serials_Admin::notice( 'error', $result->get_error_message() );

			return;
		}

		Ubac_Serials_Admin::notice(
			'success',
			$wanted
				? __( 'Workshop post switched back on.', 'ubac-serials' )
				// Said explicitly, because it is the part somebody needs to
				// know: switching a post off does not wait for its session to
				// run out, it ends it now.
				: __( 'Workshop post switched off, and signed out everywhere it was signed in.', 'ubac-serials' )
		);
	}

	/**
	 * Replaces a post's password.
	 *
	 * @return void
	 */
	private static function handle_password() {

		/* THE NONCE IS CHECKED, ONE FRAME UP.
		 * handle() calls check_admin_referer() before dispatching to this
		 * method, but the sniff does not look beyond the function: it
		 * reports a password form "without nonce verification", which stops
		 * a reviewer dead. The guard is named here because it is invisible
		 * from here.
		 *
		 * The password is NOT sanitised, and that is deliberate: sanitising
		 * it would quietly change it, and the user could no longer sign in
		 * with what they typed. It is unslashed, then handed to
		 * wp_hash_password(), whose business it is. */
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- see above.
		$id       = isset( $_POST['station_id'] ) ? (int) $_POST['station_id'] : 0;
		$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$result = Ubac_Serials_Stations::set_password( $id, $password );

		if ( is_wp_error( $result ) ) {
			Ubac_Serials_Admin::notice( 'error', $result->get_error_message() );

			return;
		}

		Ubac_Serials_Admin::notice(
			'success',
			__( 'Password replaced, and the post signed out everywhere it was signed in.', 'ubac-serials' )
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
		<h2><?php esc_html_e( 'New post', 'ubac-serials' ); ?></h2>

		<form method="post" class="ubac-serials__panel">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="ubac_action" value="create" />

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="ubac-sn-login"><?php esc_html_e( 'Login', 'ubac-serials' ); ?></label></th>
					<td>
						<input type="text" id="ubac-sn-login" name="login" class="regular-text" autocomplete="off" required />
						<p class="ubac-serials__hint"><?php esc_html_e( 'Something the bench can read off a card: bench-1, packing-left.', 'ubac-serials' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ubac-sn-password"><?php esc_html_e( 'Password', 'ubac-serials' ); ?></label></th>
					<td>
						<input
							type="text"
							id="ubac-sn-password"
							name="password"
							class="regular-text"
							value="<?php echo esc_attr( $suggestion ); ?>"
							autocomplete="off"
							minlength="<?php echo esc_attr( (string) Ubac_Serials_Stations::MIN_PASSWORD_LENGTH ); ?>"
							required
						/>
						<p class="ubac-serials__hint">
							<?php
							printf(
								/* translators: %d: minimum number of characters. */
								esc_html__( 'Generated for you, and shown rather than hidden so you can write it down once. At least %d characters.', 'ubac-serials' ),
								(int) Ubac_Serials_Stations::MIN_PASSWORD_LENGTH
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ubac-sn-label"><?php esc_html_e( 'Name', 'ubac-serials' ); ?></label></th>
					<td>
						<input type="text" id="ubac-sn-label" name="label" class="regular-text" placeholder="<?php esc_attr_e( 'Bench 1', 'ubac-serials' ); ?>" />
						<p class="ubac-serials__hint"><?php esc_html_e( 'Shown on the packing screen and in the history. Defaults to the login.', 'ubac-serials' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Create post', 'ubac-serials' ) ); ?>
		</form>
		<?php
	}

	/**
	 * The list of posts.
	 *
	 * @return void
	 */
	private static function render_list() {

		$posts = Ubac_Serials_Stations::all();

		?>
		<h2><?php esc_html_e( 'Existing posts', 'ubac-serials' ); ?></h2>

		<table class="widefat striped ubac-serials__table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Login', 'ubac-serials' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Name', 'ubac-serials' ); ?></th>
					<th scope="col"><?php esc_html_e( 'State', 'ubac-serials' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Created', 'ubac-serials' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Last sign-in', 'ubac-serials' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( ! $posts ) : ?>
				<tr>
					<td colspan="5"><?php esc_html_e( 'No workshop post yet. The packing screen needs one to sign in with.', 'ubac-serials' ); ?></td>
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
								<span class="ubac-serials__row-action">
									<form method="post">
										<?php wp_nonce_field( self::NONCE ); ?>
										<input type="hidden" name="ubac_action" value="toggle" />
										<input type="hidden" name="station_id" value="<?php echo esc_attr( (string) (int) $post['id'] ); ?>" />
										<button type="submit" class="button-link">
											<?php echo $active ? esc_html__( 'Switch off', 'ubac-serials' ) : esc_html__( 'Switch back on', 'ubac-serials' ); ?>
										</button>
									</form>
								</span>
								<span class="ubac-serials__row-action">
									<form method="post">
										<?php wp_nonce_field( self::NONCE ); ?>
										<input type="hidden" name="ubac_action" value="password" />
										<input type="hidden" name="station_id" value="<?php echo esc_attr( (string) (int) $post['id'] ); ?>" />
										<label class="screen-reader-text" for="ubac-sn-pass-<?php echo esc_attr( (string) (int) $post['id'] ); ?>">
											<?php esc_html_e( 'New password', 'ubac-serials' ); ?>
										</label>
										<input
											type="text"
											id="ubac-sn-pass-<?php echo esc_attr( (string) (int) $post['id'] ); ?>"
											name="password"
											class="ubac-serials__inline-field"
											placeholder="<?php esc_attr_e( 'New password', 'ubac-serials' ); ?>"
											autocomplete="off"
											minlength="<?php echo esc_attr( (string) Ubac_Serials_Stations::MIN_PASSWORD_LENGTH ); ?>"
										/>
										<button type="submit" class="button-link"><?php esc_html_e( 'Replace', 'ubac-serials' ); ?></button>
									</form>
								</span>
							</div>
						</td>
						<td><?php echo esc_html( $post['label'] ); ?></td>
						<td>
							<?php if ( $active ) : ?>
								<span class="ubac-serials__state ubac-serials__state--on"><?php esc_html_e( 'On', 'ubac-serials' ); ?></span>
							<?php else : ?>
								<span class="ubac-serials__state ubac-serials__state--off"><?php esc_html_e( 'Off', 'ubac-serials' ); ?></span>
							<?php endif; ?>
						</td>
						<td class="ubac-serials__num"><?php echo esc_html( Ubac_Serials_Admin::local( $post['created_at'] ) ); ?></td>
						<td class="ubac-serials__num"><?php echo esc_html( Ubac_Serials_Admin::local( $post['last_login_at'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>

		<p class="ubac-serials__hint">
			<?php esc_html_e( 'A post is never deleted. The history points at post ids, and a line that answers “who packed this parcel” with a missing id answers nothing — so switching off refuses every future sign-in while leaving the past readable.', 'ubac-serials' ); ?>
		</p>
		<?php
	}
}
