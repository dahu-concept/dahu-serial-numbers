<?php
/**
 * The consignments screen: a tracking number in, a list of units out.
 *
 * This screen exists because of one question, asked by telephone, several
 * times a week in any shop that ships serialised goods: *the customer has
 * this tracking number and says the wrong thing arrived — what was in that
 * parcel?* Before the consignment ledger there was nowhere to look. The
 * order knew one carrier and one tracking number, and the units knew which
 * order they belonged to, and nothing joined a particular parcel to a
 * particular set of units.
 *
 * So the search box takes a reference, a tracking number or a serial number,
 * and the three of them land on the same list. Which one somebody has in
 * front of them depends on whether they are holding a label, an e-mail or a
 * product, and a screen that made them choose a field first would be a
 * screen that gets the answer slower.
 *
 * It is read-only but for one button, and the exception is deliberate.
 * Opening and closing a consignment happens where the movement happens — the
 * packing screen, or a carrier integration — and a form here that let
 * somebody fabricate a departure would make the ledger worth less than the
 * order notes it replaces.
 *
 * What it does allow is cancelling a parcel that is still OPEN. That is the
 * way out of a specific dead end: a process that dies between the units
 * moving and the parcel closing leaves a consignment open forever, and a
 * unit in an open consignment is refused by every other consignment —
 * including the inbound one that would record its return. Without this
 * button the only remedy is SQL by hand, which on a client's shop means a
 * telephone call. A closed parcel can never be cancelled here: it has gone.
 *
 * @package DahuSerialNumbers
 */

defined( 'ABSPATH' ) || exit;

/**
 * The consignments list.
 */
class Dahu_Serial_Numbers_Admin_Consignments {

	/**
	 * Rows per page.
	 */
	const PER_PAGE = 25;

	/**
	 * Draws the screen.
	 *
	 * @return void
	 */
	public static function render() {

		if ( ! current_user_can( Dahu_Serial_Numbers_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view consignments.', 'dahu-serial-numbers' ) );
		}

		self::maybe_cancel();

		Dahu_Serial_Numbers_Admin::open(
			__( 'Consignments', 'dahu-serial-numbers' ),
			__( 'Every set of units that left or came back together. Search by consignment reference, tracking number or serial number.', 'dahu-serial-numbers' )
		);

		Dahu_Serial_Numbers_Admin::warn_if_no_tables();

		// Nonce-free, and that is correct rather than an omission: this
		// screen reads, it does not act. A nonce on a GET search form buys
		// nothing and expires in the middle of somebody's afternoon, which
		// turns a bookmarked filter into a scary red failure.
		$search    = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$direction = isset( $_GET['direction'] ) ? sanitize_key( wp_unslash( (string) $_GET['direction'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$status    = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$paged     = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.

		$args = self::args_from_search( $search );

		$args['direction'] = $direction;
		$args['status']    = $status;
		$args['paged']     = $paged;
		$args['per_page']  = self::PER_PAGE;

		$rows  = Dahu_Serial_Numbers_Consignments::query( $args );
		$total = Dahu_Serial_Numbers_Consignments::count( $args );

		self::filters( $search, $direction, $status );
		self::table( $rows, $search );
		self::pagination( $total, $paged );

		Dahu_Serial_Numbers_Admin::close();
	}

	/**
	 * Cancels an open consignment, when that is what was asked for.
	 *
	 * POST, nonce, capability, and a last check that the row is still open —
	 * in that order, and all four, because this writes.
	 *
	 * @return void
	 */
	private static function maybe_cancel() {

		if ( empty( $_POST['dahu_consignment_cancel'] ) ) {
			return;
		}

		check_admin_referer( 'dahu-serial-numbers-cancel-consignment' );

		if ( ! current_user_can( Dahu_Serial_Numbers_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to cancel a consignment.', 'dahu-serial-numbers' ) );
		}

		$id        = (int) $_POST['dahu_consignment_cancel'];
		$cancelled = Dahu_Serial_Numbers_Consignments::cancel(
			$id,
			__( 'Cancelled by hand from the consignments screen.', 'dahu-serial-numbers' )
		);

		if ( is_wp_error( $cancelled ) ) {
			Dahu_Serial_Numbers_Admin::notice( 'error', $cancelled->get_error_message() );

			return;
		}

		Dahu_Serial_Numbers_Admin::notice(
			'success',
			__( 'The consignment is cancelled. Its units are free to go in another parcel.', 'dahu-serial-numbers' )
		);
	}

	/**
	 * Turns one search box into the right query argument.
	 *
	 * The shape of the string says what it is, so nobody has to tell the
	 * screen: a reference looks like `C-2026-00042`, and anything else is
	 * tried as a tracking number first and a serial number second. Trying
	 * tracking first is deliberate — it is the thing somebody is most often
	 * reading off an e-mail — and the fallback means a serial number typed
	 * into the same box still finds its parcels.
	 *
	 * @param string $search What was typed.
	 * @return array
	 */
	private static function args_from_search( $search ) {

		if ( '' === $search ) {
			return array();
		}

		$prefixes = array_values( Dahu_Serial_Numbers_Consignments::prefixes() );

		foreach ( $prefixes as $prefix ) {
			if ( 0 === stripos( $search, $prefix . '-' ) ) {
				return array( 'reference' => $search );
			}
		}

		if ( Dahu_Serial_Numbers_Consignments::count( array( 'tracking' => $search ) ) ) {
			return array( 'tracking' => $search );
		}

		$serial = Dahu_Serial_Numbers_Format::normalise( $search );

		if ( Dahu_Serial_Numbers_Consignments::count( array( 'serial' => $serial ) ) ) {
			return array( 'serial' => $serial );
		}

		// Nothing matched. The tracking prefix is returned rather than an
		// empty filter, so the screen says "no consignment" instead of
		// quietly listing all of them — a list that looks like a successful
		// search for the wrong thing.
		return array( 'tracking' => $search );
	}

	/**
	 * The search box and the two dropdowns.
	 *
	 * @param string $search    Current search.
	 * @param string $direction Current direction.
	 * @param string $status    Current status.
	 * @return void
	 */
	private static function filters( $search, $direction, $status ) {

		$directions = Dahu_Serial_Numbers_Consignments::direction_labels();
		$statuses    = Dahu_Serial_Numbers_Consignments::status_labels();

		echo '<form method="get" class="dahu-serial-numbers__filters">';
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( Dahu_Serial_Numbers_Admin::SLUG . '-consignments' ) );

		printf(
			'<label class="screen-reader-text" for="dahu-consignment-search">%s</label>',
			esc_html__( 'Search consignments', 'dahu-serial-numbers' )
		);

		printf(
			'<input type="search" id="dahu-consignment-search" name="s" value="%s" placeholder="%s" />',
			esc_attr( $search ),
			esc_attr__( 'Reference, tracking or serial', 'dahu-serial-numbers' )
		);

		echo '<select name="direction">';
		printf( '<option value="">%s</option>', esc_html__( 'Any direction', 'dahu-serial-numbers' ) );

		foreach ( $directions as $key => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $key ),
				selected( $direction, $key, false ),
				esc_html( $label )
			);
		}

		echo '</select>';

		echo '<select name="status">';
		printf( '<option value="">%s</option>', esc_html__( 'Any status', 'dahu-serial-numbers' ) );

		foreach ( $statuses as $key => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $key ),
				selected( $status, $key, false ),
				esc_html( $label )
			);
		}

		echo '</select>';

		printf( '<button type="submit" class="dahu-ui-btn">%s</button>', esc_html__( 'Search', 'dahu-serial-numbers' ) );
		echo '</form>';
	}

	/**
	 * The list.
	 *
	 * The units are in the table rather than behind a link. The whole point
	 * of arriving here with a tracking number is to read the list of units,
	 * and a screen that answers with "3 units" and a link has not answered.
	 *
	 * @param array[] $rows   Consignments.
	 * @param string  $search What was searched for, to highlight nothing and
	 *                        explain an empty result.
	 * @return void
	 */
	private static function table( array $rows, $search ) {

		// One query for every line's units, rather than one per line. The
		// count comes out of the same result, so the grouped count query
		// that used to sit here is gone too.
		$units = Dahu_Serial_Numbers_Consignments::serials_for( wp_list_pluck( $rows, 'id' ) );

		echo '<table class="widefat striped dahu-serial-numbers__table">';
		echo '<thead><tr>';
		printf( '<th scope="col">%s</th>', esc_html__( 'Reference', 'dahu-serial-numbers' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Direction', 'dahu-serial-numbers' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Status', 'dahu-serial-numbers' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Order', 'dahu-serial-numbers' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Carrier', 'dahu-serial-numbers' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Tracking', 'dahu-serial-numbers' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Units', 'dahu-serial-numbers' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Parcel', 'dahu-serial-numbers' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Opened', 'dahu-serial-numbers' ) );
		printf( '<th scope="col"><span class="screen-reader-text">%s</span></th>', esc_html__( 'Actions', 'dahu-serial-numbers' ) );
		echo '</tr></thead><tbody>';

		if ( ! $rows ) {
			printf(
				'<tr><td colspan="10">%s</td></tr>',
				'' === $search
					? esc_html__( 'No consignment yet. One is recorded every time a parcel is closed on the packing screen.', 'dahu-serial-numbers' )
					: esc_html__( 'No consignment matches that reference, tracking number or serial number.', 'dahu-serial-numbers' )
			);
		}

		$directions = Dahu_Serial_Numbers_Consignments::direction_labels();
		$statuses   = Dahu_Serial_Numbers_Consignments::status_labels();

		foreach ( $rows as $row ) {
			$id        = (int) $row['id'];
			$direction = (string) $row['direction'];
			$status    = (string) $row['status'];
			$serials   = isset( $units[ $id ] ) ? $units[ $id ] : array();
			$count     = count( $serials );
			$url       = Dahu_Serial_Numbers_Shipment::tracking_url_for_row( $row );

			echo '<tr>';

			printf( '<td><strong>%s</strong></td>', esc_html( (string) $row['reference'] ) );
			printf( '<td>%s</td>', esc_html( isset( $directions[ $direction ] ) ? $directions[ $direction ] : $direction ) );
			printf( '<td>%s</td>', esc_html( isset( $statuses[ $status ] ) ? $statuses[ $status ] : $status ) );

			if ( $row['order_id'] ) {
				printf(
					'<td><a href="%s">#%s</a></td>',
					esc_url( admin_url( 'post.php?post=' . (int) $row['order_id'] . '&action=edit' ) ),
					esc_html( (string) $row['order_id'] )
				);
			} else {
				echo '<td>&mdash;</td>';
			}

			printf( '<td>%s</td>', '' !== (string) $row['carrier'] ? esc_html( (string) $row['carrier'] ) : '&mdash;' );

			if ( '' === (string) $row['tracking'] ) {
				echo '<td>&mdash;</td>';
			} elseif ( '' !== $url ) {
				printf(
					'<td><a href="%s" target="_blank" rel="noopener">%s</a></td>',
					esc_url( $url ),
					esc_html( (string) $row['tracking'] )
				);
			} else {
				printf( '<td>%s</td>', esc_html( (string) $row['tracking'] ) );
			}

			printf( '<td>%s</td>', esc_html( number_format_i18n( $count ) ) );

			// Empty rather than a dash: a parcel nobody measured and a parcel
			// weighing nothing are not the same statement.
			$parcel = Dahu_Serial_Numbers_Consignments::parcel_label( $row );
			printf( '<td>%s</td>', '' !== $parcel ? esc_html( $parcel ) : '' );
			printf( '<td>%s</td>', esc_html( Dahu_Serial_Numbers_Admin::local( (string) $row['created_at'] ) ) );

			// The way out of the dead end described at the top of this file,
			// and only for a parcel that has not gone.
			echo '<td>';

			if ( Dahu_Serial_Numbers_Consignments::OPEN === $status ) {
				echo '<form method="post">';
				wp_nonce_field( 'dahu-serial-numbers-cancel-consignment' );
				printf(
					'<button type="submit" name="dahu_consignment_cancel" value="%d" class="button button-small">%s</button>',
					(int) $id,
					esc_html__( 'Cancel', 'dahu-serial-numbers' )
				);
				echo '</form>';
			}

			echo self::extra_actions( $row ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in extra_actions().

			echo '</td>';

			echo '</tr>';

			if ( $serials ) {
				echo '<tr class="dahu-serial-numbers__subrow">';
				printf( '<td colspan="10"><span class="dahu-serial-numbers__hint">%s</span> %s</td>',
					esc_html__( 'Units:', 'dahu-serial-numbers' ),
					esc_html( implode( ', ', $serials ) )
				);
				echo '</tr>';
			}
		}

		echo '</tbody></table>';
	}

	/**
	 * The actions other plugins add to a line.
	 *
	 * Modelled on WordPress's `plugin_action_links`: the filter receives an
	 * array of ready-made HTML fragments keyed by a slug, and the row, and
	 * returns the array. A label download link for a parcel belongs here, next
	 * to the parcel, and not in a second screen.
	 *
	 * What comes back is not trusted. Anything that is not a string is dropped,
	 * and every fragment goes through `wp_kses()` with links, spans and emphasis
	 * only: a filter that returns a `<script>`, or an `onclick`, gets neither.
	 *
	 * @param array $row The consignment, as the list holds it.
	 * @return string HTML, already escaped.
	 */
	private static function extra_actions( array $row ) {

		/**
		 * Filters the actions shown on a consignment line.
		 *
		 * @param string[] $actions HTML fragments keyed by slug. Empty by default.
		 * @param array    $row     The consignment: id, reference, status, order_id,
		 *                          carrier, tracking, label_error, and the rest of the row.
		 */
		$actions = apply_filters( 'dahu_serial_numbers_consignment_row_actions', array(), $row );

		if ( ! is_array( $actions ) || ! $actions ) {
			return '';
		}

		$allowed = array(
			'a'      => array(
				'href'   => true,
				'class'  => true,
				'target' => true,
				'rel'    => true,
				'title'  => true,
			),
			'span'   => array( 'class' => true ),
			'strong' => array(),
			'em'     => array(),
		);

		$out = array();

		foreach ( $actions as $key => $html ) {
			if ( ! is_string( $html ) || '' === $html ) {
				continue;
			}

			$out[] = sprintf(
				'<span class="%s">%s</span>',
				esc_attr( sanitize_html_class( (string) $key ) ),
				wp_kses( $html, $allowed )
			);
		}

		return $out ? '<div class="dahu-serial-numbers__actions">' . implode( ' | ', $out ) . '</div>' : '';
	}

	/**
	 * Page links.
	 *
	 * @param int $total How many rows match.
	 * @param int $paged Current page.
	 * @return void
	 */
	private static function pagination( $total, $paged ) {

		$pages = (int) ceil( $total / self::PER_PAGE );

		if ( $pages < 2 ) {
			return;
		}

		$links = paginate_links(
			array(
				'base'      => add_query_arg( 'paged', '%#%' ),
				'format'    => '',
				'current'   => max( 1, (int) $paged ),
				'total'     => $pages,
				'type'      => 'plain',
				'prev_text' => '&laquo;',
				'next_text' => '&raquo;',
			)
		);

		if ( $links ) {
			printf(
				'<div class="tablenav"><div class="tablenav-pages">%s</div></div>',
				wp_kses_post( $links )
			);
		}
	}
}
