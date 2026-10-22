<?php
/**
 * The Stock screen: what WooCommerce would be told, and the product fixer.
 *
 * Two tabs, because they are two halves of the same question — "is the number
 * on the shop right?" — asked from either end. The first compares the units on
 * the shelf with WooCommerce's stock column and offers to write the
 * difference. The second re-files units that went on the shelf under the wrong
 * product, which is the most common reason the first one disagrees.
 *
 * Both are read-first screens. Nothing here writes on arrival, both tabs show
 * their summary before anything happens, and what is applied is checked
 * against what was shown.
 *
 * @package DahuSerialNumbers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stock reconciliation and product corrections.
 */
class Dahu_Serial_Numbers_Admin_Stock {

	/**
	 * Nonce action for the resynchronisation.
	 */
	const NONCE_SYNC = 'dahu-serial-numbers-stock-resync';

	/**
	 * Nonce action for a product correction.
	 */
	const NONCE_FIX = 'dahu-serial-numbers-product-fix';

	/**
	 * Notices gathered while handling a submission, shown after the header.
	 *
	 * @var array<int,array<string,string>>
	 */
	private static $notices = array();

	/**
	 * The plan a submission asked to see again, if any.
	 *
	 * @var array<string,mixed>|null
	 */
	private static $plan = null;

	/**
	 * The screen.
	 *
	 * @return void
	 */
	public static function render() {

		if ( ! current_user_can( Dahu_Serial_Numbers_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to look at stock.', 'dahu-serial-numbers' ) );
		}

		self::handle();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- choosing a tab is a read.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'sync';
		$tab = in_array( $tab, array( 'sync', 'fix' ), true ) ? $tab : 'sync';

		Dahu_Serial_Numbers_Admin::open(
			__( 'Stock', 'dahu-serial-numbers' ),
			__( 'What the units on your shelves say the WooCommerce stock of each serialised product should be — and a way to re-file units that went in under the wrong product.', 'dahu-serial-numbers' )
		);

		foreach ( self::$notices as $notice ) {
			Dahu_Serial_Numbers_Admin::notice( $notice['tone'], $notice['message'] );
		}

		if ( ! Dahu_Serial_Numbers_Admin::warn_if_no_tables() ) {
			self::render_tabs( $tab );

			if ( 'fix' === $tab ) {
				self::render_fix();
			} else {
				self::render_sync();
			}
		}

		Dahu_Serial_Numbers_Admin::close();
	}

	/**
	 * The two tabs.
	 *
	 * @param string $current Current tab.
	 * @return void
	 */
	private static function render_tabs( $current ) {

		$tabs = array(
			'sync' => __( 'WooCommerce stock', 'dahu-serial-numbers' ),
			'fix'  => __( 'Wrong product', 'dahu-serial-numbers' ),
		);

		echo '<nav class="nav-tab-wrapper dahu-serial-numbers__tabs">';

		foreach ( $tabs as $slug => $label ) {
			printf(
				'<a class="nav-tab%1$s" href="%2$s">%3$s</a>',
				$slug === $current ? ' nav-tab-active' : '',
				esc_url( admin_url( 'admin.php?page=' . Dahu_Serial_Numbers_Admin::SLUG . '-stock&tab=' . $slug ) ),
				esc_html( $label )
			);
		}

		echo '</nav>';
	}

	/* ---------------------------------------------------------------------
	 * Submissions
	 * ------------------------------------------------------------------ */

	/**
	 * Handles whichever form was posted.
	 *
	 * @return void
	 */
	private static function handle() {

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- each branch checks its own nonce.
		$action = isset( $_POST['dahu_action'] ) ? sanitize_key( wp_unslash( $_POST['dahu_action'] ) ) : '';

		if ( '' === $action ) {
			return;
		}

		if ( 'resync' === $action ) {
			self::handle_resync();

			return;
		}

		if ( in_array( $action, array( 'fix_preview', 'fix_apply', 'fix_undo' ), true ) ) {
			self::handle_fix( $action );
		}
	}

	/**
	 * Writes the stock of every product the plan says should change.
	 *
	 * @return void
	 */
	private static function handle_resync() {

		check_admin_referer( self::NONCE_SYNC );

		if ( ! current_user_can( Dahu_Serial_Numbers_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to write stock.', 'dahu-serial-numbers' ) );
		}

		// The second tick box. The automatic push is off, so this run is the
		// only thing that will change what customers can buy, and it should
		// not be one misplaced click away.
		if ( ! Dahu_Serial_Numbers_Stock::enabled() && empty( $_POST['dahu_confirm'] ) ) {
			self::notice(
				'warning',
				__( 'The automatic push is off, so this run needs the confirmation box ticked.', 'dahu-serial-numbers' )
			);

			return;
		}

		$fingerprint = isset( $_POST['dahu_fingerprint'] ) ? sanitize_text_field( wp_unslash( $_POST['dahu_fingerprint'] ) ) : '';
		$done        = Dahu_Serial_Numbers_Stock::resync( $fingerprint );

		if ( is_wp_error( $done ) ) {
			self::notice( 'error', $done->get_error_message() );

			return;
		}

		$written = count( $done['written'] );
		$failed  = count( $done['failed'] );

		if ( 0 === $written && 0 === $failed ) {
			self::notice( 'info', __( 'Nothing to write: every serialised product already shows what its units say.', 'dahu-serial-numbers' ) );

			return;
		}

		self::notice(
			'success',
			sprintf(
				/* translators: %d: number of products. */
				_n( 'Stock written for %d product.', 'Stock written for %d products.', $written, 'dahu-serial-numbers' ),
				$written
			)
		);

		if ( $failed ) {
			self::notice(
				'error',
				sprintf(
					/* translators: %d: number of products. */
					_n( '%d product could not be written. The history below names it.', '%d products could not be written. The history below names them.', $failed, 'dahu-serial-numbers' ),
					$failed
				)
			);
		}
	}

	/**
	 * Previews, applies or undoes a product correction.
	 *
	 * @param string $action Which.
	 * @return void
	 */
	private static function handle_fix( $action ) {

		check_admin_referer( self::NONCE_FIX );

		if ( ! current_user_can( Dahu_Serial_Numbers_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to re-file a unit.', 'dahu-serial-numbers' ) );
		}

		if ( 'fix_undo' === $action ) {
			$run_id = isset( $_POST['dahu_run_id'] ) ? sanitize_text_field( wp_unslash( $_POST['dahu_run_id'] ) ) : '';
			$done   = Dahu_Serial_Numbers_Product_Fix::undo( $run_id );

			if ( is_wp_error( $done ) ) {
				self::notice( 'error', $done->get_error_message() );

				return;
			}

			if ( $done['put_back'] ) {
				self::notice(
					'success',
					sprintf(
						/* translators: %d: number of units. */
						_n( '%d unit put back.', '%d units put back.', count( $done['put_back'] ), 'dahu-serial-numbers' ),
						count( $done['put_back'] )
					)
				);
			} else {
				// The run keeps its button: nothing was put back, so there is
				// nothing to call undone, and the units may well be free again
				// in an hour.
				self::notice(
					'warning',
					__( 'Nothing could be put back, so this correction is still on the list and can be tried again later.', 'dahu-serial-numbers' )
				);
			}

			if ( $done['skipped'] ) {
				self::notice(
					'warning',
					sprintf(
						/* translators: 1: number of units, 2: list of serial numbers. */
						__( '%1$d unit was left alone because something else has happened to it since, or because the product it would go back to can no longer hold units: %2$s', 'dahu-serial-numbers' ),
						count( $done['skipped'] ),
						implode( ', ', wp_list_pluck( $done['skipped'], 'serial' ) )
					)
				);
			}

			return;
		}

		$serials = Dahu_Serial_Numbers_Product_Fix::parse( isset( $_POST['dahu_serials'] ) ? wp_unslash( $_POST['dahu_serials'] ) : '' );
		$to      = isset( $_POST['dahu_to_product'] ) ? (int) $_POST['dahu_to_product'] : 0;
		$reason  = isset( $_POST['dahu_reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['dahu_reason'] ) ) : '';

		if ( 'fix_preview' === $action ) {
			self::$plan = Dahu_Serial_Numbers_Product_Fix::plan( $serials, $to, $reason );

			return;
		}

		$fingerprint = isset( $_POST['dahu_fingerprint'] ) ? sanitize_text_field( wp_unslash( $_POST['dahu_fingerprint'] ) ) : '';
		$done        = Dahu_Serial_Numbers_Product_Fix::apply( $serials, $to, $reason, $fingerprint );

		if ( is_wp_error( $done ) ) {
			self::notice( 'error', $done->get_error_message() );

			$data = $done->get_error_data();

			// Hand the summary back rather than an empty form: the operator
			// has forty numbers pasted in and a refusal to read, and losing
			// the paste is how a screen turns one mistake into two.
			self::$plan = is_array( $data ) && isset( $data['plan'] ) ? $data['plan'] : Dahu_Serial_Numbers_Product_Fix::plan( $serials, $to, $reason );

			return;
		}

		self::notice(
			'success',
			sprintf(
				/* translators: 1: number of units, 2: product name. */
				_n( '%1$d unit re-filed under %2$s.', '%1$d units re-filed under %2$s.', count( $done['moved'] ), 'dahu-serial-numbers' ),
				count( $done['moved'] ),
				Dahu_Serial_Numbers_Product_Fix::product_name( $done['to'] )
			)
		);
	}

	/**
	 * Remembers a notice for after the header.
	 *
	 * @param string $tone    Tone.
	 * @param string $message Message.
	 * @return void
	 */
	private static function notice( $tone, $message ) {

		self::$notices[] = array(
			'tone'    => (string) $tone,
			'message' => (string) $message,
		);
	}

	/* ---------------------------------------------------------------------
	 * Tab: WooCommerce stock
	 * ------------------------------------------------------------------ */

	/**
	 * The comparison, and the button.
	 *
	 * @return void
	 */
	private static function render_sync() {

		$plan = Dahu_Serial_Numbers_Stock::resync_plan();
		$on   = Dahu_Serial_Numbers_Stock::enabled();

		echo '<div class="dahu-serial-numbers__panel">';

		printf(
			'<p class="dahu-serial-numbers__hint">%s</p>',
			esc_html(
				$on
					? __( 'The automatic push is on: the stock of a serialised product is rewritten whenever its units move. This screen is the full picture, and the way to put a product right that drifted while the push was off.', 'dahu-serial-numbers' )
					: __( 'The automatic push is off. Nothing here has been written; this is what would be. The Settings screen turns the push on, and this run works either way.', 'dahu-serial-numbers' )
			)
		);

		if ( ! $plan['rows'] ) {
			printf(
				'<p>%s</p>',
				esc_html__( 'No serialised product holds a unit yet, so there is nothing to compare.', 'dahu-serial-numbers' )
			);
			echo '</div>';

			return;
		}

		echo '<table class="widefat striped dahu-serial-numbers__table">';
		echo '<thead><tr>';
		printf( '<th>%s</th>', esc_html__( 'Product', 'dahu-serial-numbers' ) );
		printf( '<th>%s</th>', esc_html__( 'Units in stock', 'dahu-serial-numbers' ) );
		printf( '<th>%s</th>', esc_html__( 'Reserved by orders', 'dahu-serial-numbers' ) );
		printf( '<th>%s</th>', esc_html__( 'WooCommerce now', 'dahu-serial-numbers' ) );
		printf( '<th>%s</th>', esc_html__( 'Would be written', 'dahu-serial-numbers' ) );
		printf( '<th>%s</th>', esc_html__( 'Note', 'dahu-serial-numbers' ) );
		echo '</tr></thead><tbody>';

		foreach ( $plan['rows'] as $row ) {
			$notes = array();

			if ( '' !== $row['refusal'] ) {
				$notes[] = Dahu_Serial_Numbers_Stock::refusal_label( $row['refusal'] );
			}

			if ( $row['gap'] > 0 ) {
				$notes[] = sprintf(
					/* translators: %d: number of pieces. */
					_n(
						'Orders reserve %d piece more than there are units on the shelf — written as zero rather than as a negative stock.',
						'Orders reserve %d pieces more than there are units on the shelf — written as zero rather than as a negative stock.',
						$row['gap'],
						'dahu-serial-numbers'
					),
					$row['gap']
				);
			}

			if ( ! empty( $row['stuck'] ) ) {
				$notes[] = sprintf(
					/* translators: %d: number of units. */
					_n(
						'%d unit says in stock while still carrying an order, so it is not counted.',
						'%d units say in stock while still carrying an order, so they are not counted.',
						$row['stuck'],
						'dahu-serial-numbers'
					),
					$row['stuck']
				);
			}

			echo '<tr>';
			printf(
				'<td><strong>%1$s</strong><br /><span class="dahu-serial-numbers__muted">#%2$d%3$s</span></td>',
				esc_html( '' !== $row['name'] ? $row['name'] : Dahu_Serial_Numbers_Product_Fix::product_name( $row['product_id'] ) ),
				(int) $row['product_id'],
				'' !== $row['sku'] ? ' · ' . esc_html( $row['sku'] ) : ''
			);
			printf( '<td>%d</td>', (int) $row['in_stock'] );
			printf( '<td>%d</td>', (int) $row['reserved'] );
			printf( '<td>%s</td>', null === $row['before'] ? '—' : (int) $row['before'] );
			printf(
				'<td>%s</td>',
				'' !== $row['refusal'] ? '—' : ( $row['changes'] ? '<strong>' . (int) $row['wanted'] . '</strong>' : (int) $row['wanted'] )
			);
			printf( '<td>%s</td>', esc_html( implode( ' ', $notes ) ) );
			echo '</tr>';
		}

		echo '</tbody></table>';

		?>
		<form method="post">
			<?php wp_nonce_field( self::NONCE_SYNC ); ?>
			<input type="hidden" name="dahu_action" value="resync" />
			<input type="hidden" name="dahu_fingerprint" value="<?php echo esc_attr( $plan['fingerprint'] ); ?>" />

			<?php if ( ! $on ) : ?>
				<label class="dahu-serial-numbers__check">
					<input type="checkbox" name="dahu_confirm" value="1" />
					<?php esc_html_e( 'I have read the table above and want these numbers written to WooCommerce', 'dahu-serial-numbers' ); ?>
				</label>
			<?php endif; ?>

			<p class="dahu-serial-numbers__hint">
				<?php
				printf(
					/* translators: %d: number of products. */
					esc_html( _n( '%d product would change.', '%d products would change.', (int) $plan['changing'], 'dahu-serial-numbers' ) ),
					(int) $plan['changing']
				);
				?>
			</p>

			<?php submit_button( __( 'Write these numbers to WooCommerce', 'dahu-serial-numbers' ), 'primary', 'submit', false, $plan['changing'] ? array() : array( 'disabled' => 'disabled' ) ); ?>
		</form>
		<?php

		echo '</div>';

		self::render_history();
	}

	/**
	 * The last pushes.
	 *
	 * @return void
	 */
	private static function render_history() {

		$history = array_slice( Dahu_Serial_Numbers_Stock::history(), 0, 20 );

		if ( ! $history ) {
			return;
		}

		echo '<div class="dahu-serial-numbers__panel">';
		printf( '<h2>%s</h2>', esc_html__( 'Recent pushes', 'dahu-serial-numbers' ) );
		echo '<table class="widefat striped dahu-serial-numbers__table"><thead><tr>';
		printf( '<th>%s</th>', esc_html__( 'When', 'dahu-serial-numbers' ) );
		printf( '<th>%s</th>', esc_html__( 'Product', 'dahu-serial-numbers' ) );
		printf( '<th>%s</th>', esc_html__( 'Triggered by', 'dahu-serial-numbers' ) );
		printf( '<th>%s</th>', esc_html__( 'Before', 'dahu-serial-numbers' ) );
		printf( '<th>%s</th>', esc_html__( 'Written', 'dahu-serial-numbers' ) );
		echo '</tr></thead><tbody>';

		foreach ( $history as $line ) {
			echo '<tr>';
			printf( '<td>%s</td>', esc_html( Dahu_Serial_Numbers_Admin::local( isset( $line['at'] ) ? $line['at'] : '' ) ) );
			printf(
				'<td>%1$s <span class="dahu-serial-numbers__muted">#%2$d</span></td>',
				esc_html( isset( $line['name'] ) ? $line['name'] : '' ),
				(int) ( isset( $line['product'] ) ? $line['product'] : 0 )
			);
			printf( '<td>%s</td>', esc_html( isset( $line['origin'] ) ? $line['origin'] : '' ) );
			printf( '<td>%s</td>', isset( $line['before'] ) && null !== $line['before'] ? (int) $line['before'] : '—' );
			printf(
				'<td>%s</td>',
				isset( $line['written'] ) && null !== $line['written']
					? (int) $line['written']
					: esc_html( Dahu_Serial_Numbers_Stock::refusal_label( isset( $line['refusal'] ) ? $line['refusal'] : '' ) )
			);
			echo '</tr>';
		}

		echo '</tbody></table></div>';
	}

	/* ---------------------------------------------------------------------
	 * Tab: wrong product
	 * ------------------------------------------------------------------ */

	/**
	 * The form, the summary when there is one, and the runs.
	 *
	 * @return void
	 */
	private static function render_fix() {

		$plan = self::$plan;

		echo '<div class="dahu-serial-numbers__panel">';

		printf(
			'<p class="dahu-serial-numbers__hint">%s</p>',
			esc_html__( 'For a box that went on the shelf under the wrong reference. Only units in stock and on no order can be re-filed: once a unit is on an order, in a parcel or in repair, its product is what that document says it is.', 'dahu-serial-numbers' )
		);

		if ( ! Dahu_Serial_Numbers_Product_Fix::enabled() ) {
			printf(
				'<p class="dahu-serial-numbers__caution">%s</p>',
				esc_html__( 'Corrections are switched off, so this form will show what would happen and write nothing. The Settings screen turns them on.', 'dahu-serial-numbers' )
			);
		}

		?>
		<form method="post">
			<?php wp_nonce_field( self::NONCE_FIX ); ?>
			<input type="hidden" name="dahu_action" value="fix_preview" />

			<p>
				<label for="dahu-serials"><strong><?php esc_html_e( 'Serial numbers', 'dahu-serial-numbers' ); ?></strong></label><br />
				<textarea id="dahu-serials" name="dahu_serials" rows="5" class="large-text code" placeholder="<?php esc_attr_e( 'One per line, or separated by spaces, commas or semicolons', 'dahu-serial-numbers' ); ?>"><?php echo esc_textarea( self::posted( 'dahu_serials' ) ); ?></textarea>
			</p>

			<p>
				<label for="dahu-to-product"><strong><?php esc_html_e( 'File them under', 'dahu-serial-numbers' ); ?></strong></label><br />
				<select id="dahu-to-product" name="dahu_to_product">
					<option value="0"><?php esc_html_e( '— pick a product —', 'dahu-serial-numbers' ); ?></option>
					<?php foreach ( self::destinations() as $id => $label ) : ?>
						<option value="<?php echo esc_attr( $id ); ?>" <?php selected( (int) self::posted( 'dahu_to_product' ), (int) $id ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>

			<p>
				<label for="dahu-reason"><strong><?php esc_html_e( 'Reason', 'dahu-serial-numbers' ); ?></strong></label><br />
				<input id="dahu-reason" type="text" class="large-text" name="dahu_reason" value="<?php echo esc_attr( self::posted( 'dahu_reason' ) ); ?>" />
				<span class="dahu-serial-numbers__hint"><?php esc_html_e( 'Required: it is what the history line will say, to whoever reads it next year.', 'dahu-serial-numbers' ); ?></span>
			</p>

			<?php submit_button( __( 'Show me what would happen', 'dahu-serial-numbers' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php

		echo '</div>';

		if ( is_array( $plan ) ) {
			self::render_plan( $plan );
		}

		self::render_runs();
	}

	/**
	 * Serialised products a unit can be filed under.
	 *
	 * Variable parents are left out rather than offered and refused: a
	 * dropdown that lists a choice nobody may pick is a dropdown that wastes
	 * somebody's afternoon.
	 *
	 * @return array<int,string>
	 */
	private static function destinations() {

		$out = array();

		if ( ! function_exists( 'wc_get_products' ) ) {
			return $out;
		}

		$products = wc_get_products(
			array(
				'limit'      => 200,
				'status'     => 'publish',
				'orderby'    => 'title',
				'order'      => 'ASC',
				'meta_key'   => Dahu_Serial_Numbers_Products::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => 'yes', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		foreach ( $products as $product ) {
			if ( $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $variation_id ) {
					$variation = wc_get_product( $variation_id );

					if ( $variation ) {
						$out[ (int) $variation_id ] = $variation->get_name();
					}
				}

				continue;
			}

			$out[ (int) $product->get_id() ] = $product->get_name();
		}

		return $out;
	}

	/**
	 * What was posted, for putting back in the form.
	 *
	 * @param string $key Field name.
	 * @return string
	 */
	private static function posted( $key ) {

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- echoed back escaped; the nonce is checked where this is acted on.
		return isset( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : '';
	}

	/**
	 * The dry run.
	 *
	 * @param array $plan Plan.
	 * @return void
	 */
	private static function render_plan( array $plan ) {

		echo '<div class="dahu-serial-numbers__panel">';
		printf( '<h2>%s</h2>', esc_html__( 'What would happen', 'dahu-serial-numbers' ) );

		if ( $plan['refusals'] ) {
			printf(
				'<p class="dahu-serial-numbers__caution">%s</p>',
				esc_html__( 'Nothing can be written while anything in this list is refused: a run is all of it or none of it, because half a correction is a state nobody can reason about afterwards.', 'dahu-serial-numbers' )
			);

			echo '<ul class="dahu-serial-numbers__list">';

			foreach ( $plan['refusals'] as $code ) {
				printf( '<li>%s</li>', esc_html( Dahu_Serial_Numbers_Product_Fix::refusal_label( $code ) ) );
			}

			echo '</ul>';
		}

		if ( $plan['rows'] ) {
			echo '<table class="widefat striped dahu-serial-numbers__table"><thead><tr>';
			printf( '<th>%s</th>', esc_html__( 'Serial', 'dahu-serial-numbers' ) );
			printf( '<th>%s</th>', esc_html__( 'Filed under', 'dahu-serial-numbers' ) );
			printf( '<th>%s</th>', esc_html__( 'State', 'dahu-serial-numbers' ) );
			printf( '<th>%s</th>', esc_html__( 'Would become', 'dahu-serial-numbers' ) );
			echo '</tr></thead><tbody>';

			foreach ( $plan['rows'] as $row ) {
				echo '<tr>';
				printf( '<td><code>%s</code></td>', esc_html( $row['serial'] ) );
				printf( '<td>%s</td>', esc_html( $row['from_name'] ) );
				printf( '<td>%s</td>', esc_html( '' !== $row['status'] ? Dahu_Serial_Numbers_Statuses::label( $row['status'] ) : '—' ) );
				printf(
					'<td>%s</td>',
					'' === $row['refusal']
						? esc_html( $plan['to_name'] )
						: '<em>' . esc_html( Dahu_Serial_Numbers_Product_Fix::refusal_label( $row['refusal'] ) ) . '</em>'
				);
				echo '</tr>';
			}

			echo '</tbody></table>';
		}

		if ( $plan['counts'] ) {
			printf( '<h3>%s</h3>', esc_html__( 'Units in stock, before and after', 'dahu-serial-numbers' ) );
			echo '<table class="widefat striped dahu-serial-numbers__table"><thead><tr>';
			printf( '<th>%s</th>', esc_html__( 'Product', 'dahu-serial-numbers' ) );
			printf( '<th>%s</th>', esc_html__( 'Before', 'dahu-serial-numbers' ) );
			printf( '<th>%s</th>', esc_html__( 'After', 'dahu-serial-numbers' ) );
			echo '</tr></thead><tbody>';

			foreach ( $plan['counts'] as $count ) {
				echo '<tr>';
				printf( '<td>%s</td>', esc_html( $count['name'] ) );
				printf( '<td>%d</td>', (int) $count['before'] );
				printf( '<td>%d</td>', (int) $count['after'] );
				echo '</tr>';
			}

			echo '</tbody></table>';
		}

		if ( ! $plan['refusals'] && $plan['movable'] > 0 && Dahu_Serial_Numbers_Product_Fix::enabled() ) {
			?>
			<form method="post">
				<?php wp_nonce_field( self::NONCE_FIX ); ?>
				<input type="hidden" name="dahu_action" value="fix_apply" />
				<input type="hidden" name="dahu_fingerprint" value="<?php echo esc_attr( $plan['fingerprint'] ); ?>" />
				<input type="hidden" name="dahu_to_product" value="<?php echo esc_attr( (int) $plan['to'] ); ?>" />
				<input type="hidden" name="dahu_reason" value="<?php echo esc_attr( $plan['reason'] ); ?>" />
				<input type="hidden" name="dahu_serials" value="<?php echo esc_attr( implode( ' ', wp_list_pluck( $plan['rows'], 'serial' ) ) ); ?>" />

				<?php
				submit_button(
					sprintf(
						/* translators: %d: number of units. */
						_n( 'Re-file %d unit', 'Re-file %d units', (int) $plan['movable'], 'dahu-serial-numbers' ),
						(int) $plan['movable']
					),
					'primary',
					'submit',
					false
				);
				?>
			</form>
			<?php
		}

		echo '</div>';
	}

	/**
	 * The runs, with a button to put one back.
	 *
	 * @return void
	 */
	private static function render_runs() {

		$runs = array_slice( Dahu_Serial_Numbers_Product_Fix::runs(), 0, 20 );

		if ( ! $runs ) {
			return;
		}

		echo '<div class="dahu-serial-numbers__panel">';
		printf( '<h2>%s</h2>', esc_html__( 'Corrections made', 'dahu-serial-numbers' ) );

		printf(
			'<p class="dahu-serial-numbers__hint">%s</p>',
			esc_html__( 'A run can be put back as long as nothing else has happened to the units since. Those that have moved on are named and left alone — undoing them would undo a later decision.', 'dahu-serial-numbers' )
		);

		echo '<table class="widefat striped dahu-serial-numbers__table"><thead><tr>';
		printf( '<th>%s</th>', esc_html__( 'When', 'dahu-serial-numbers' ) );
		printf( '<th>%s</th>', esc_html__( 'Units', 'dahu-serial-numbers' ) );
		printf( '<th>%s</th>', esc_html__( 'Filed under', 'dahu-serial-numbers' ) );
		printf( '<th>%s</th>', esc_html__( 'Reason', 'dahu-serial-numbers' ) );
		printf( '<th>%s</th>', esc_html__( 'Put back', 'dahu-serial-numbers' ) );
		echo '</tr></thead><tbody>';

		foreach ( $runs as $run ) {
			echo '<tr>';
			printf( '<td>%s</td>', esc_html( Dahu_Serial_Numbers_Admin::local( isset( $run['at'] ) ? $run['at'] : '' ) ) );
			printf( '<td>%d</td>', count( (array) ( isset( $run['moved'] ) ? $run['moved'] : array() ) ) );
			printf( '<td>%s</td>', esc_html( Dahu_Serial_Numbers_Product_Fix::product_name( isset( $run['to'] ) ? $run['to'] : 0 ) ) );
			printf( '<td>%s</td>', esc_html( isset( $run['reason'] ) ? $run['reason'] : '' ) );

			echo '<td>';

			if ( ! empty( $run['undone_at'] ) ) {
				printf(
					// A label and two figures rather than a sentence: "1 units
					// put back" needs a plural form, and this string carries
					// two counts that would each need their own. Found while
					// the French was being written.
					/* translators: 1: date, 2: number of units put back, 3: number skipped. */
					esc_html__( '%1$s — back: %2$d, left alone: %3$d', 'dahu-serial-numbers' ),
					esc_html( Dahu_Serial_Numbers_Admin::local( $run['undone_at'] ) ),
					(int) ( isset( $run['undone_put_back'] ) ? $run['undone_put_back'] : 0 ),
					(int) ( isset( $run['undone_skipped'] ) ? $run['undone_skipped'] : 0 )
				);
			} else {
				?>
				<form method="post">
					<?php wp_nonce_field( self::NONCE_FIX ); ?>
					<input type="hidden" name="dahu_action" value="fix_undo" />
					<input type="hidden" name="dahu_run_id" value="<?php echo esc_attr( isset( $run['run_id'] ) ? $run['run_id'] : '' ); ?>" />
					<?php submit_button( __( 'Put back', 'dahu-serial-numbers' ), 'secondary small', 'submit', false ); ?>
				</form>
				<?php
			}

			echo '</td></tr>';
		}

		echo '</tbody></table></div>';
	}
}
