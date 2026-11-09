<?php
/**
 * The serial-numbers screen: the list, and one unit's history.
 *
 * @package UbacSerials
 */

defined( 'ABSPATH' ) || exit;

/**
 * Browse the serials, and read one unit's history.
 */
class Ubac_Serials_Admin_Serials {

	/**
	 * Rows per page.
	 */
	const PER_PAGE = 50;

	/**
	 * Renders the screen: the list, or one unit when one was asked for.
	 *
	 * @return void
	 */
	public static function render() {

		if ( ! current_user_can( Ubac_Serials_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view serial numbers.', 'dahuconcept-serial-numbers' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only filter on a capability-checked screen.
		$one = isset( $_GET['serial'] ) ? Ubac_Serials_Format::normalise( wp_unslash( $_GET['serial'] ) ) : '';

		Ubac_Serials_Admin::open(
			__( 'Serial numbers', 'dahuconcept-serial-numbers' ),
			__( 'DahuConcept Serial Numbers keeps one row per physical unit you ship, with the history of everywhere it has been.', 'dahuconcept-serial-numbers' )
		);

		self::render_move_notice();

		if ( ! Ubac_Serials_Admin::warn_if_no_tables() ) {
			if ( '' !== $one ) {
				self::render_one( $one );
			} else {
				self::render_summary();
				self::render_filters();
				self::render_list();
			}
		}

		Ubac_Serials_Admin::close();
	}

	/**
	 * The filters as they were submitted.
	 *
	 * @return array<string,mixed>
	 */
	private static function filters() {

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters on a capability-checked screen.
		return array(
			'search' => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'status' => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
			'batch'  => isset( $_GET['batch'] ) ? sanitize_text_field( wp_unslash( $_GET['batch'] ) ) : '',
			'page'   => isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1,
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * A row of tiles above the table, one per status that holds units.
	 *
	 * Each tile is a real count with the status label it already has, so
	 * the row adds no wording of its own.
	 *
	 * @return void
	 */
	private static function render_summary() {

		$counts = Ubac_Serials_Manager::counts_by_status();

		if ( 0 === array_sum( $counts ) ) {
			return;
		}

		echo '<div class="ubac-ui-tiles">';

		foreach ( $counts as $status => $count ) {
			if ( $count > 0 ) {
				printf(
					'<div class="ubac-ui-card"><div class="ubac-ui-tile__label">%s</div><div class="ubac-ui-tile__value">%s</div></div>',
					esc_html( Ubac_Serials_Statuses::label( $status ) ),
					esc_html( number_format_i18n( $count ) )
				);
			}
		}

		echo '</div>';
	}

	/**
	 * The filter form.
	 *
	 * @return void
	 */
	private static function render_filters() {

		$filters = self::filters();

		?>
		<div class="ubac-ui-card">
		<form method="get" class="ubac-serials__filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( Ubac_Serials_Admin::SLUG ); ?>" />

			<label class="screen-reader-text" for="ubac-sn-search"><?php esc_html_e( 'Search a serial number', 'dahuconcept-serial-numbers' ); ?></label>
			<input
				type="search"
				id="ubac-sn-search"
				name="s"
				value="<?php echo esc_attr( $filters['search'] ); ?>"
				placeholder="<?php esc_attr_e( 'Serial number', 'dahuconcept-serial-numbers' ); ?>"
			/>

			<label class="screen-reader-text" for="ubac-sn-status"><?php esc_html_e( 'Status', 'dahuconcept-serial-numbers' ); ?></label>
			<select id="ubac-sn-status" name="status">
				<option value=""><?php esc_html_e( 'Any status', 'dahuconcept-serial-numbers' ); ?></option>
				<?php foreach ( Ubac_Serials_Statuses::labels() as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filters['status'], $key ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<label class="screen-reader-text" for="ubac-sn-batch"><?php esc_html_e( 'Supplier batch', 'dahuconcept-serial-numbers' ); ?></label>
			<input
				type="text"
				id="ubac-sn-batch"
				name="batch"
				value="<?php echo esc_attr( $filters['batch'] ); ?>"
				placeholder="<?php esc_attr_e( 'Supplier batch', 'dahuconcept-serial-numbers' ); ?>"
			/>

			<?php submit_button( __( 'Filter', 'dahuconcept-serial-numbers' ), 'secondary', '', false ); ?>
		</form>
		</div>
		<?php
	}

	/**
	 * The list.
	 *
	 * @return void
	 */
	private static function render_list() {

		$filters = self::filters();
		$result  = Ubac_Serials_Manager::query(
			array(
				'search'   => $filters['search'],
				'status'   => $filters['status'],
				'batch'    => $filters['batch'],
				'per_page' => self::PER_PAGE,
				'page'     => $filters['page'],
			)
		);

		?>
		<div class="ubac-ui-card">
		<table class="widefat striped ubac-serials__table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Serial number', 'dahuconcept-serial-numbers' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'dahuconcept-serial-numbers' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Product', 'dahuconcept-serial-numbers' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Supplier batch', 'dahuconcept-serial-numbers' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Order', 'dahuconcept-serial-numbers' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Made', 'dahuconcept-serial-numbers' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Last movement', 'dahuconcept-serial-numbers' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( ! $result['rows'] ) : ?>
				<tr>
					<td colspan="7">
						<?php if ( '' !== $filters['search'] || '' !== $filters['status'] || '' !== $filters['batch'] ) : ?>
							<p class="ubac-ui-hint"><?php esc_html_e( 'Nothing matches those filters.', 'dahuconcept-serial-numbers' ); ?></p>
						<?php else : ?>
							<p class="ubac-ui-hint"><?php esc_html_e( 'No serial number on file yet. One appears the first time a workshop post scans a unit.', 'dahuconcept-serial-numbers' ); ?></p>
							<p>
								<a class="ubac-ui-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=ubac-serials-stations' ) ); ?>">
									<?php esc_html_e( 'Set up a workshop post', 'dahuconcept-serial-numbers' ); ?>
								</a>
							</p>
						<?php endif; ?>
					</td>
				</tr>
			<?php else : ?>
				<?php foreach ( $result['rows'] as $row ) : ?>
					<tr>
						<td>
							<strong>
								<a href="<?php echo esc_url( self::link( $row['serial'] ) ); ?>">
									<?php echo esc_html( $row['serial'] ); ?>
								</a>
							</strong>
						</td>
						<td><?php echo esc_html( Ubac_Serials_Statuses::label( (string) $row['status'] ) ); ?></td>
						<td><?php echo esc_html( self::product_name( $row ) ); ?></td>
						<td><?php echo esc_html( '' !== (string) $row['supplier_order'] ? (string) $row['supplier_order'] : '—' ); ?></td>
						<td><?php echo wp_kses_post( self::order_link( $row ) ); ?></td>
						<td class="ubac-serials__num"><?php echo esc_html( self::made( $row ) ); ?></td>
						<td class="ubac-serials__num"><?php echo esc_html( Ubac_Serials_Admin::local( $row['updated_at'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>

		<?php self::render_pagination( $result['total'], $filters ); ?>
		</div>
		<?php
	}

	/**
	 * Pagination, and the count it is paging through.
	 *
	 * Printed even on a single page, because the count is the useful half: a
	 * table that shows fifty rows out of nine hundred and says nothing is a
	 * wall, not a list.
	 *
	 * @param int   $total   Rows matching the filters.
	 * @param array $filters Current filters.
	 * @return void
	 */
	private static function render_pagination( $total, array $filters ) {

		if ( 0 === $total ) {
			return;
		}

		$pages = (int) ceil( $total / self::PER_PAGE );

		printf(
			'<p class="ubac-serials__hint">%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: rows shown, 2: total rows. */
					__( 'Showing %1$s of %2$s matching units.', 'dahuconcept-serial-numbers' ),
					number_format_i18n( min( self::PER_PAGE, $total - ( ( $filters['page'] - 1 ) * self::PER_PAGE ) ) ),
					number_format_i18n( $total )
				)
			)
		);

		if ( $pages < 2 ) {
			return;
		}

		$base = add_query_arg(
			array(
				'page'   => Ubac_Serials_Admin::SLUG,
				's'      => $filters['search'],
				'status' => $filters['status'],
				'batch'  => $filters['batch'],
			),
			admin_url( 'admin.php' )
		);

		echo '<div class="tablenav"><div class="tablenav-pages">';

		echo wp_kses_post(
			paginate_links(
				array(
					'base'      => add_query_arg( 'paged', '%#%', $base ),
					'format'    => '',
					'current'   => $filters['page'],
					'total'     => $pages,
					'prev_text' => '&laquo;',
					'next_text' => '&raquo;',
				)
			)
		);

		echo '</div></div>';
	}

	/**
	 * One unit, with its whole history.
	 *
	 * @param string $serial Serial.
	 * @return void
	 */
	private static function render_one( $serial ) {

		$row = Ubac_Serials_Manager::get( $serial );

		printf(
			'<p><a href="%s">%s</a></p>',
			esc_url( admin_url( 'admin.php?page=' . Ubac_Serials_Admin::SLUG ) ),
			esc_html__( '← All serial numbers', 'dahuconcept-serial-numbers' )
		);

		if ( ! $row ) {
			Ubac_Serials_Admin::notice(
				'error',
				sprintf(
					/* translators: %s: serial number. */
					__( 'No unit on file under %s.', 'dahuconcept-serial-numbers' ),
					$serial
				)
			);

			return;
		}

		printf( '<h2 class="ubac-serials__serial">%s</h2>', esc_html( $row['serial'] ) );

		// A state report: several label/value pairs describing the situation
		// at page load. A definition table is what WordPress uses for this
		// everywhere, so it is what this uses.
		$pairs = array(
			__( 'Status', 'dahuconcept-serial-numbers' )         => Ubac_Serials_Statuses::label( (string) $row['status'] ),
			__( 'Product', 'dahuconcept-serial-numbers' )        => self::product_name( $row ),
			__( 'Supplier batch', 'dahuconcept-serial-numbers' ) => '' !== (string) $row['supplier_order'] ? (string) $row['supplier_order'] : '—',
			__( 'Supplier name', 'dahuconcept-serial-numbers' )  => '' !== (string) $row['supplier_name'] ? (string) $row['supplier_name'] : '—',
			__( 'Made', 'dahuconcept-serial-numbers' )           => self::made( $row ),
			__( 'Received', 'dahuconcept-serial-numbers' )       => Ubac_Serials_Admin::local( $row['received_at'] ),
			__( 'Last checked', 'dahuconcept-serial-numbers' )   => Ubac_Serials_Admin::local( $row['checked_at'] ),
			__( 'Last movement', 'dahuconcept-serial-numbers' )  => Ubac_Serials_Admin::local( $row['updated_at'] ),
		);

		echo '<table class="widefat striped ubac-serials__table"><tbody>';

		foreach ( $pairs as $label => $value ) {
			printf(
				'<tr><th scope="row">%s</th><td>%s</td></tr>',
				esc_html( $label ),
				esc_html( $value )
			);
		}

		printf(
			'<tr><th scope="row">%s</th><td>%s</td></tr>',
			esc_html__( 'Order', 'dahuconcept-serial-numbers' ),
			wp_kses_post( self::order_link( $row ) )
		);

		echo '</tbody></table>';

		self::render_move( $row );
		self::render_history( $row['serial'] );
	}

	/**
	 * What the move handler decided, for the screen to say.
	 *
	 * The handler runs on `admin_init`, before a byte of the page exists,
	 * because a successful move redirects and a redirect needs headers. A
	 * refusal does not redirect — there is nothing dangerous to replay in a
	 * move the register refuses — so its message waits here for the render,
	 * in the same request.
	 *
	 * @var string
	 */
	private static $move_error = '';

	/**
	 * A final move waiting to be confirmed: the wanted status and the note.
	 *
	 * @var array<string,string>
	 */
	private static $move_pending = array();

	/**
	 * Moves one unit to another status, when that is what was asked for.
	 *
	 * POST, nonce, capability, and the register's own matrix — in that order,
	 * and all four, because this writes.
	 *
	 * This screen was read-only until now, and that was a real gap rather
	 * than a design choice: the only code anywhere that could move a unit was
	 * the workshop's two REST routes and `Shipment`, so a shop whose unit had
	 * ended up somewhere awkward could not get it out without installing
	 * something else or writing SQL. An adversarial review found a published
	 * upgrade note promising this control before it existed. Here it is.
	 *
	 * The nonce is tied to the serial, not just to the action: a token handed
	 * out for one unit must not move another.
	 *
	 * Hooked on `admin_init` rather than called from the render, so that a
	 * move that went through can answer with a redirect. A move left in the
	 * POST body is a move the browser will offer to repeat on reload, and
	 * repeating this one writes a second history line saying the unit moved
	 * again when it did not.
	 *
	 * @return void
	 */
	public static function handle_move() {

		if ( empty( $_POST['ubac_serial_move_to'] ) || empty( $_POST['ubac_serial'] ) ) {
			return;
		}

		$serial = Ubac_Serials_Format::normalise( wp_unslash( $_POST['ubac_serial'] ) );

		if ( '' === $serial ) {
			return;
		}

		check_admin_referer( 'ubac-serials-move-' . $serial );

		if ( ! current_user_can( Ubac_Serials_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to move a unit.', 'dahuconcept-serial-numbers' ) );
		}

		$to   = sanitize_key( wp_unslash( $_POST['ubac_serial_move_to'] ) );
		$note = isset( $_POST['ubac_serial_move_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ubac_serial_move_note'] ) ) : '';
		$row  = Ubac_Serials_Manager::get( $serial );

		if ( ! $row ) {
			return;
		}

		// The target is checked against the matrix here as well as inside
		// transition(). Not belt-and-braces: this screen only ever offers the
		// legal targets, so a target that is not one did not come from this
		// screen, and saying so is more use than the register's generic
		// refusal.
		//
		// next() answers key => label. Checking the key is the whole point:
		// the first version of this screen compared the posted key against
		// the labels, and so refused every move anybody ever asked for while
		// telling the operator the move had not come from this screen.
		if ( ! array_key_exists( $to, Ubac_Serials_Statuses::next( (string) $row['status'] ) ) ) {
			self::$move_error = sprintf(
				/* translators: 1: current status label, 2: wanted status label. */
				__( 'A unit cannot go from “%1$s” to “%2$s”. The list on this screen only offers the moves that are allowed, so this one did not come from here.', 'dahuconcept-serial-numbers' ),
				Ubac_Serials_Statuses::label( (string) $row['status'] ),
				Ubac_Serials_Statuses::label( $to )
			);

			return;
		}

		// A status nothing leaves is asked for twice. Not because the write
		// is dangerous — the history keeps every line, and the unit is still
		// there — but because the next screen will have no way out to offer,
		// and finding that out by arriving there is finding out too late.
		if ( ! Ubac_Serials_Statuses::next( $to ) && empty( $_POST['ubac_serial_move_confirm'] ) ) {
			self::$move_pending = array(
				'to'   => $to,
				'note' => $note,
			);

			return;
		}

		$moved = Ubac_Serials_Manager::transition(
			$serial,
			$to,
			array(
				'event' => 'admin_move',
				'note'  => '' !== $note ? $note : __( 'Moved by hand from the serial numbers screen.', 'dahuconcept-serial-numbers' ),
			)
		);

		if ( is_wp_error( $moved ) ) {
			self::$move_error = $moved->get_error_message();

			return;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => Ubac_Serials_Admin::SLUG,
					'serial'     => rawurlencode( $serial ),
					'ubac-moved' => $to,
				),
				admin_url( 'admin.php' )
			)
		);

		exit;
	}

	/**
	 * Says what the last move did, whether it went through or not.
	 *
	 * @return void
	 */
	private static function render_move_notice() {

		if ( '' !== self::$move_error ) {
			Ubac_Serials_Admin::notice( 'error', self::$move_error );

			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a label to print, written by the redirect above.
		$moved = isset( $_GET['ubac-moved'] ) ? sanitize_key( wp_unslash( $_GET['ubac-moved'] ) ) : '';

		if ( '' === $moved || ! array_key_exists( $moved, Ubac_Serials_Statuses::labels() ) ) {
			return;
		}

		Ubac_Serials_Admin::notice(
			'success',
			sprintf(
				/* translators: %s: new status label. */
				__( 'The unit is now “%s”, and the move is in its history.', 'dahuconcept-serial-numbers' ),
				Ubac_Serials_Statuses::label( $moved )
			)
		);
	}

	/**
	 * The one action this screen offers on a unit: move it.
	 *
	 * Only the targets the matrix allows, so the screen never offers
	 * something the register will refuse — a screen that offers a move and is
	 * then refused teaches somebody that the screen lies.
	 *
	 * One framed button, because this is the action of the page. A terminal
	 * unit gets a sentence instead, which is the honest answer.
	 *
	 * @param array $row The unit.
	 * @return void
	 */
	private static function render_move( array $row ) {

		$next = Ubac_Serials_Statuses::next( (string) $row['status'] );

		printf( '<h3>%s</h3>', esc_html__( 'Where it can go next', 'dahuconcept-serial-numbers' ) );

		if ( ! $next ) {
			printf(
				'<p>%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: status label. */
						__( 'Nothing leaves “%s”. That is deliberate: a unit that was replaced or destroyed reappearing elsewhere is either a mistake or a story this history should be able to tell.', 'dahuconcept-serial-numbers' ),
						Ubac_Serials_Statuses::label( (string) $row['status'] )
					)
				)
			);

			return;
		}

		if ( self::$move_pending ) {
			self::render_move_confirm( $row );

			return;
		}

		echo '<form method="post" class="ubac-serials__move">';
		wp_nonce_field( 'ubac-serials-move-' . $row['serial'] );
		printf( '<input type="hidden" name="ubac_serial" value="%s">', esc_attr( $row['serial'] ) );

		printf(
			'<label class="screen-reader-text" for="ubac-serial-move-to">%s</label>',
			esc_html__( 'New status', 'dahuconcept-serial-numbers' )
		);

		echo '<select id="ubac-serial-move-to" name="ubac_serial_move_to">';

		foreach ( $next as $target => $label ) {
			printf(
				'<option value="%s">%s</option>',
				esc_attr( $target ),
				esc_html( $label )
			);
		}

		echo '</select>';

		printf(
			'<input type="text" name="ubac_serial_move_note" class="regular-text" placeholder="%s">',
			esc_attr__( 'Why, for the history (optional)', 'dahuconcept-serial-numbers' )
		);

		submit_button( __( 'Move this unit', 'dahuconcept-serial-numbers' ), 'secondary', '', false );

		printf(
			'<p class="ubac-serials__hint">%s</p>',
			esc_html__( 'Every move is recorded in the history below, with who made it and when. Nothing here skips the lifecycle: only the moves the register allows from where this unit is are offered.', 'dahuconcept-serial-numbers' )
		);

		echo '</form>';
	}

	/**
	 * Asks again before a status nothing leaves.
	 *
	 * The wanted status and the note travel in hidden fields rather than in
	 * the session, so that abandoning this page abandons the move: there is
	 * nothing left behind to go through later by itself.
	 *
	 * @param array $row The unit.
	 * @return void
	 */
	private static function render_move_confirm( array $row ) {

		$to   = isset( self::$move_pending['to'] ) ? (string) self::$move_pending['to'] : '';
		$note = isset( self::$move_pending['note'] ) ? (string) self::$move_pending['note'] : '';

		echo '<form method="post" class="ubac-serials__move">';
		wp_nonce_field( 'ubac-serials-move-' . $row['serial'] );
		printf( '<input type="hidden" name="ubac_serial" value="%s">', esc_attr( $row['serial'] ) );
		printf( '<input type="hidden" name="ubac_serial_move_to" value="%s">', esc_attr( $to ) );
		printf( '<input type="hidden" name="ubac_serial_move_note" value="%s">', esc_attr( $note ) );
		echo '<input type="hidden" name="ubac_serial_move_confirm" value="1">';

		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: wanted status label. */
					__( 'Nothing leaves “%s”. The unit stays in the register with everything it has already done, and this screen will have no move left to offer it.', 'dahuconcept-serial-numbers' ),
					Ubac_Serials_Statuses::label( $to )
				)
			)
		);

		submit_button(
			sprintf(
				/* translators: %s: wanted status label. */
				__( 'Yes, move it to “%s”', 'dahuconcept-serial-numbers' ),
				Ubac_Serials_Statuses::label( $to )
			),
			'primary',
			'',
			false
		);

		printf(
			' <a href="%s">%s</a>',
			esc_url( self::link( (string) $row['serial'] ) ),
			esc_html__( 'Leave it where it is', 'dahuconcept-serial-numbers' )
		);

		echo '</form>';
	}

	/**
	 * One unit's history, newest first.
	 *
	 * @param string $serial Serial.
	 * @return void
	 */
	private static function render_history( $serial ) {

		$lines = Ubac_Serials_Log::for_serial( $serial, 0 );

		printf( '<h3>%s</h3>', esc_html__( 'History', 'dahuconcept-serial-numbers' ) );

		printf(
			'<p class="ubac-serials__hint">%s</p>',
			esc_html__( 'Nothing in this plugin ever deletes a line from here — not a status change, not an un-assignment, not deleting the order. A history with holes in it answers worse than no history, because it answers confidently.', 'dahuconcept-serial-numbers' )
		);

		echo '<table class="widefat striped ubac-serials__table"><thead><tr>';
		printf( '<th scope="col">%s</th>', esc_html__( 'When', 'dahuconcept-serial-numbers' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Event', 'dahuconcept-serial-numbers' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Movement', 'dahuconcept-serial-numbers' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'By', 'dahuconcept-serial-numbers' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Details', 'dahuconcept-serial-numbers' ) );
		echo '</tr></thead><tbody>';

		if ( ! $lines ) {
			printf( '<tr><td colspan="5">%s</td></tr>', esc_html__( 'No history line, which should not happen: filing a unit writes one.', 'dahuconcept-serial-numbers' ) );
		}

		foreach ( $lines as $line ) {
			$movement = '—';

			if ( $line['new_status'] ) {
				$movement = $line['old_status']
					? sprintf(
						/* translators: 1: status before, 2: status after. */
						__( '%1$s → %2$s', 'dahuconcept-serial-numbers' ),
						Ubac_Serials_Statuses::label( (string) $line['old_status'] ),
						Ubac_Serials_Statuses::label( (string) $line['new_status'] )
					)
					: Ubac_Serials_Statuses::label( (string) $line['new_status'] );
			}

			echo '<tr>';
			printf( '<td class="ubac-serials__num">%s</td>', esc_html( Ubac_Serials_Admin::local( $line['created_at'] ) ) );
			printf( '<td><code>%s</code></td>', esc_html( (string) $line['event'] ) );
			printf( '<td>%s</td>', esc_html( $movement ) );
			printf( '<td>%s</td>', esc_html( self::author( $line ) ) );
			printf( '<td class="ubac-serials__context">%s</td>', esc_html( self::context_summary( $line ) ) );
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/* ---------------------------------------------------------------------
	 * Small renderers
	 * ------------------------------------------------------------------ */

	/**
	 * Link to one unit's own page.
	 *
	 * @param string $serial Serial.
	 * @return string
	 */
	private static function link( $serial ) {

		return add_query_arg(
			array(
				'page'   => Ubac_Serials_Admin::SLUG,
				'serial' => $serial,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * The product a unit is filed under.
	 *
	 * @param array $row Serials row.
	 * @return string
	 */
	private static function product_name( array $row ) {

		$product_id = (int) $row['product_id'];

		if ( $product_id <= 0 ) {
			return '—';
		}

		$product = wc_get_product( $product_id );

		if ( ! $product instanceof WC_Product ) {
			// The product was deleted. Said as such rather than left blank:
			// the unit is still on file and the id is still the truth about
			// what it was filed as.
			return sprintf(
				/* translators: %d: product id. */
				__( 'Deleted product #%d', 'dahuconcept-serial-numbers' ),
				$product_id
			);
		}

		return $product->get_name();
	}

	/**
	 * A link to the order, when there is one.
	 *
	 * @param array $row Serials row.
	 * @return string HTML.
	 */
	private static function order_link( array $row ) {

		$order_id = (int) $row['order_id'];

		if ( $order_id <= 0 ) {
			return '—';
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return sprintf(
				/* translators: %d: order id. */
				esc_html__( 'Deleted order #%d', 'dahuconcept-serial-numbers' ),
				$order_id
			);
		}

		return sprintf(
			'<a href="%s">#%s</a>',
			esc_url( $order->get_edit_order_url() ),
			esc_html( $order->get_order_number() )
		);
	}

	/**
	 * The manufacturing date, as far as it is known.
	 *
	 * @param array $row Serials row.
	 * @return string
	 */
	private static function made( array $row ) {

		$year  = (int) $row['manufactured_year'];
		$month = (int) $row['manufactured_month'];

		if ( $year <= 0 ) {
			return '—';
		}

		if ( $month <= 0 ) {
			return (string) $year;
		}

		// A month and a year, in the site's own language, without inventing a
		// day: the day is not known and a date that showed one would be a
		// fact nobody recorded.
		return sprintf(
			/* translators: 1: month name, 2: year. */
			__( '%1$s %2$d', 'dahuconcept-serial-numbers' ),
			date_i18n( 'F', mktime( 0, 0, 0, $month, 1, $year ) ),
			$year
		);
	}

	/**
	 * Who or what wrote a history line.
	 *
	 * @param array $line Log row.
	 * @return string
	 */
	private static function author( array $line ) {

		$context    = Ubac_Serials_Log::context( $line );
		$station_id = isset( $context['station_id'] ) ? (int) $context['station_id'] : 0;

		if ( $station_id > 0 ) {
			return sprintf(
				/* translators: %s: workshop post name. */
				__( 'Post: %s', 'dahuconcept-serial-numbers' ),
				Ubac_Serials_Stations::label( $station_id )
			);
		}

		$user_id = (int) $line['user_id'];

		if ( $user_id <= 0 ) {
			// Zero is not a missing value: it says the site did this, on a
			// schedule or from the command line, with nobody signed in.
			return __( 'The site', 'dahuconcept-serial-numbers' );
		}

		$user = get_userdata( $user_id );

		return $user ? $user->display_name : sprintf(
			/* translators: %d: user id. */
			__( 'Deleted user #%d', 'dahuconcept-serial-numbers' ),
			$user_id
		);
	}

	/**
	 * The free payload of a history line, in one readable string.
	 *
	 * @param array $line Log row.
	 * @return string
	 */
	private static function context_summary( array $line ) {

		$context = Ubac_Serials_Log::context( $line );

		// The post is already its own column, so it is not repeated here.
		unset( $context['station_id'] );

		if ( ! $context ) {
			return '—';
		}

		$parts = array();

		foreach ( $context as $key => $value ) {
			if ( is_array( $value ) ) {
				$value = wp_json_encode( $value );
			}

			$parts[] = $key . ': ' . (string) $value;
		}

		return implode( ', ', $parts );
	}
}
