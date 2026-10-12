<?php
/**
 * The serial-numbers screen: the list, and one unit's history.
 *
 * @package DahuSerialNumbers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Browse the serials, and read one unit's history.
 */
class Dahu_Serial_Numbers_Admin_Serials {

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

		if ( ! current_user_can( Dahu_Serial_Numbers_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view serial numbers.', 'dahu-serial-numbers' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only filter on a capability-checked screen.
		$one = isset( $_GET['serial'] ) ? Dahu_Serial_Numbers_Format::normalise( wp_unslash( $_GET['serial'] ) ) : '';

		Dahu_Serial_Numbers_Admin::open(
			__( 'Serial numbers', 'dahu-serial-numbers' ),
			__( 'Dahu Serial Numbers keeps one row per physical unit you ship, with the history of everywhere it has been.', 'dahu-serial-numbers' )
		);

		if ( ! Dahu_Serial_Numbers_Admin::warn_if_no_tables() ) {
			if ( '' !== $one ) {
				self::render_one( $one );
			} else {
				self::render_summary();
				self::render_filters();
				self::render_list();
			}
		}

		Dahu_Serial_Numbers_Admin::close();
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
	 * One line above the table, saying what the table holds.
	 *
	 * A summary line rather than a definition table: it is one calculated
	 * figure per status, summarising what is underneath, and a bordered box
	 * around that would be a box around a sentence.
	 *
	 * @return void
	 */
	private static function render_summary() {

		$counts = Dahu_Serial_Numbers_Manager::counts_by_status();
		$total  = array_sum( $counts );

		if ( 0 === $total ) {
			return;
		}

		$parts = array();

		foreach ( $counts as $status => $count ) {
			if ( $count > 0 ) {
				$parts[] = sprintf(
					'<span class="dahu-serial-numbers__tally"><strong class="dahu-serial-numbers__num">%s</strong> %s</span>',
					esc_html( number_format_i18n( $count ) ),
					esc_html( Dahu_Serial_Numbers_Statuses::label( $status ) )
				);
			}
		}

		printf(
			'<p class="dahu-serial-numbers__summary">%s %s</p>',
			sprintf(
				/* translators: %s: number of units. */
				esc_html( _n( '%s unit on file:', '%s units on file:', $total, 'dahu-serial-numbers' ) ),
				esc_html( number_format_i18n( $total ) )
			),
			// Each part is built from escaped pieces just above.
			implode( ' ', $parts ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}

	/**
	 * The filter form.
	 *
	 * @return void
	 */
	private static function render_filters() {

		$filters = self::filters();

		?>
		<form method="get" class="dahu-serial-numbers__filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( Dahu_Serial_Numbers_Admin::SLUG ); ?>" />

			<label class="screen-reader-text" for="dahu-sn-search"><?php esc_html_e( 'Search a serial number', 'dahu-serial-numbers' ); ?></label>
			<input
				type="search"
				id="dahu-sn-search"
				name="s"
				value="<?php echo esc_attr( $filters['search'] ); ?>"
				placeholder="<?php esc_attr_e( 'Serial number', 'dahu-serial-numbers' ); ?>"
			/>

			<label class="screen-reader-text" for="dahu-sn-status"><?php esc_html_e( 'Status', 'dahu-serial-numbers' ); ?></label>
			<select id="dahu-sn-status" name="status">
				<option value=""><?php esc_html_e( 'Any status', 'dahu-serial-numbers' ); ?></option>
				<?php foreach ( Dahu_Serial_Numbers_Statuses::labels() as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filters['status'], $key ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<label class="screen-reader-text" for="dahu-sn-batch"><?php esc_html_e( 'Supplier batch', 'dahu-serial-numbers' ); ?></label>
			<input
				type="text"
				id="dahu-sn-batch"
				name="batch"
				value="<?php echo esc_attr( $filters['batch'] ); ?>"
				placeholder="<?php esc_attr_e( 'Supplier batch', 'dahu-serial-numbers' ); ?>"
			/>

			<?php submit_button( __( 'Filter', 'dahu-serial-numbers' ), 'secondary', '', false ); ?>
		</form>
		<?php
	}

	/**
	 * The list.
	 *
	 * @return void
	 */
	private static function render_list() {

		$filters = self::filters();
		$result  = Dahu_Serial_Numbers_Manager::query(
			array(
				'search'   => $filters['search'],
				'status'   => $filters['status'],
				'batch'    => $filters['batch'],
				'per_page' => self::PER_PAGE,
				'page'     => $filters['page'],
			)
		);

		?>
		<table class="widefat striped dahu-serial-numbers__table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Serial number', 'dahu-serial-numbers' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'dahu-serial-numbers' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Product', 'dahu-serial-numbers' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Supplier batch', 'dahu-serial-numbers' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Order', 'dahu-serial-numbers' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Made', 'dahu-serial-numbers' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Last movement', 'dahu-serial-numbers' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( ! $result['rows'] ) : ?>
				<tr>
					<td colspan="7">
						<?php
						if ( '' !== $filters['search'] || '' !== $filters['status'] || '' !== $filters['batch'] ) {
							esc_html_e( 'Nothing matches those filters.', 'dahu-serial-numbers' );
						} else {
							esc_html_e( 'No serial number on file yet.', 'dahu-serial-numbers' );
						}
						?>
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
						<td><?php echo esc_html( Dahu_Serial_Numbers_Statuses::label( (string) $row['status'] ) ); ?></td>
						<td><?php echo esc_html( self::product_name( $row ) ); ?></td>
						<td><?php echo esc_html( '' !== (string) $row['supplier_order'] ? (string) $row['supplier_order'] : '—' ); ?></td>
						<td><?php echo wp_kses_post( self::order_link( $row ) ); ?></td>
						<td class="dahu-serial-numbers__num"><?php echo esc_html( self::made( $row ) ); ?></td>
						<td class="dahu-serial-numbers__num"><?php echo esc_html( Dahu_Serial_Numbers_Admin::local( $row['updated_at'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>

		<?php self::render_pagination( $result['total'], $filters ); ?>
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
			'<p class="dahu-serial-numbers__hint">%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: rows shown, 2: total rows. */
					__( 'Showing %1$s of %2$s matching units.', 'dahu-serial-numbers' ),
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
				'page'   => Dahu_Serial_Numbers_Admin::SLUG,
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

		$row = Dahu_Serial_Numbers_Manager::get( $serial );

		printf(
			'<p><a href="%s">%s</a></p>',
			esc_url( admin_url( 'admin.php?page=' . Dahu_Serial_Numbers_Admin::SLUG ) ),
			esc_html__( '← All serial numbers', 'dahu-serial-numbers' )
		);

		if ( ! $row ) {
			Dahu_Serial_Numbers_Admin::notice(
				'error',
				sprintf(
					/* translators: %s: serial number. */
					__( 'No unit on file under %s.', 'dahu-serial-numbers' ),
					$serial
				)
			);

			return;
		}

		printf( '<h2 class="dahu-serial-numbers__serial">%s</h2>', esc_html( $row['serial'] ) );

		// A state report: several label/value pairs describing the situation
		// at page load. A definition table is what WordPress uses for this
		// everywhere, so it is what this uses.
		$pairs = array(
			__( 'Status', 'dahu-serial-numbers' )         => Dahu_Serial_Numbers_Statuses::label( (string) $row['status'] ),
			__( 'Product', 'dahu-serial-numbers' )        => self::product_name( $row ),
			__( 'Supplier batch', 'dahu-serial-numbers' ) => '' !== (string) $row['supplier_order'] ? (string) $row['supplier_order'] : '—',
			__( 'Supplier name', 'dahu-serial-numbers' )  => '' !== (string) $row['supplier_name'] ? (string) $row['supplier_name'] : '—',
			__( 'Made', 'dahu-serial-numbers' )           => self::made( $row ),
			__( 'Received', 'dahu-serial-numbers' )       => Dahu_Serial_Numbers_Admin::local( $row['received_at'] ),
			__( 'Last checked', 'dahu-serial-numbers' )   => Dahu_Serial_Numbers_Admin::local( $row['checked_at'] ),
			__( 'Last movement', 'dahu-serial-numbers' )  => Dahu_Serial_Numbers_Admin::local( $row['updated_at'] ),
		);

		echo '<table class="widefat striped dahu-serial-numbers__table"><tbody>';

		foreach ( $pairs as $label => $value ) {
			printf(
				'<tr><th scope="row">%s</th><td>%s</td></tr>',
				esc_html( $label ),
				esc_html( $value )
			);
		}

		printf(
			'<tr><th scope="row">%s</th><td>%s</td></tr>',
			esc_html__( 'Order', 'dahu-serial-numbers' ),
			wp_kses_post( self::order_link( $row ) )
		);

		echo '</tbody></table>';

		self::render_history( $row['serial'] );
	}

	/**
	 * One unit's history, newest first.
	 *
	 * @param string $serial Serial.
	 * @return void
	 */
	private static function render_history( $serial ) {

		$lines = Dahu_Serial_Numbers_Log::for_serial( $serial, 0 );

		printf( '<h3>%s</h3>', esc_html__( 'History', 'dahu-serial-numbers' ) );

		printf(
			'<p class="dahu-serial-numbers__hint">%s</p>',
			esc_html__( 'Nothing in this plugin ever deletes a line from here — not a status change, not an un-assignment, not deleting the order. A history with holes in it answers worse than no history, because it answers confidently.', 'dahu-serial-numbers' )
		);

		echo '<table class="widefat striped dahu-serial-numbers__table"><thead><tr>';
		printf( '<th scope="col">%s</th>', esc_html__( 'When', 'dahu-serial-numbers' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Event', 'dahu-serial-numbers' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Movement', 'dahu-serial-numbers' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'By', 'dahu-serial-numbers' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Details', 'dahu-serial-numbers' ) );
		echo '</tr></thead><tbody>';

		if ( ! $lines ) {
			printf( '<tr><td colspan="5">%s</td></tr>', esc_html__( 'No history line, which should not happen: filing a unit writes one.', 'dahu-serial-numbers' ) );
		}

		foreach ( $lines as $line ) {
			$movement = '—';

			if ( $line['new_status'] ) {
				$movement = $line['old_status']
					? sprintf(
						/* translators: 1: status before, 2: status after. */
						__( '%1$s → %2$s', 'dahu-serial-numbers' ),
						Dahu_Serial_Numbers_Statuses::label( (string) $line['old_status'] ),
						Dahu_Serial_Numbers_Statuses::label( (string) $line['new_status'] )
					)
					: Dahu_Serial_Numbers_Statuses::label( (string) $line['new_status'] );
			}

			echo '<tr>';
			printf( '<td class="dahu-serial-numbers__num">%s</td>', esc_html( Dahu_Serial_Numbers_Admin::local( $line['created_at'] ) ) );
			printf( '<td><code>%s</code></td>', esc_html( (string) $line['event'] ) );
			printf( '<td>%s</td>', esc_html( $movement ) );
			printf( '<td>%s</td>', esc_html( self::author( $line ) ) );
			printf( '<td class="dahu-serial-numbers__context">%s</td>', esc_html( self::context_summary( $line ) ) );
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
				'page'   => Dahu_Serial_Numbers_Admin::SLUG,
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
				__( 'Deleted product #%d', 'dahu-serial-numbers' ),
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
				esc_html__( 'Deleted order #%d', 'dahu-serial-numbers' ),
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
			__( '%1$s %2$d', 'dahu-serial-numbers' ),
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

		$context    = Dahu_Serial_Numbers_Log::context( $line );
		$station_id = isset( $context['station_id'] ) ? (int) $context['station_id'] : 0;

		if ( $station_id > 0 ) {
			return sprintf(
				/* translators: %s: workshop post name. */
				__( 'Post: %s', 'dahu-serial-numbers' ),
				Dahu_Serial_Numbers_Stations::label( $station_id )
			);
		}

		$user_id = (int) $line['user_id'];

		if ( $user_id <= 0 ) {
			// Zero is not a missing value: it says the site did this, on a
			// schedule or from the command line, with nobody signed in.
			return __( 'The site', 'dahu-serial-numbers' );
		}

		$user = get_userdata( $user_id );

		return $user ? $user->display_name : sprintf(
			/* translators: %d: user id. */
			__( 'Deleted user #%d', 'dahu-serial-numbers' ),
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

		$context = Dahu_Serial_Numbers_Log::context( $line );

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
