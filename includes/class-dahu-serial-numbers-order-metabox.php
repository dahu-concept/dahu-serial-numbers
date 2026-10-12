<?php
/**
 * The serial numbers of an order, on the order screen.
 *
 * Read-only, and that is a decision. Assigning a serial means somebody has the
 * unit in their hands, and the place that happens is the packing screen, where
 * a scan is one movement and the history records which bench made it. A box on
 * the order screen that let somebody type a serial number from memory would
 * record the same movement with none of that true.
 *
 * What this box is for is the other direction: an order is on screen because
 * of a customer question, and the answer is which units went out with it.
 *
 * @package DahuSerialNumbers
 */

defined( 'ABSPATH' ) || exit;

/**
 * The order-screen panel.
 */
class Dahu_Serial_Numbers_Order_Metabox {

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public static function init() {

		add_action( 'add_meta_boxes', array( __CLASS__, 'register' ) );
	}

	/**
	 * Declares the box on both order screens.
	 *
	 * Two screen ids, because an order edit screen is `shop_order` on the
	 * legacy post tables and `woocommerce_page_wc-orders` under HPOS. A
	 * plugin that declares one works on half the shops that install it.
	 *
	 * @return void
	 */
	public static function register() {

		foreach ( array( 'shop_order', 'woocommerce_page_wc-orders' ) as $screen ) {
			add_meta_box(
				'dahu-serial-numbers-order',
				__( 'Serial numbers', 'dahu-serial-numbers' ),
				array( __CLASS__, 'render' ),
				$screen,
				'normal',
				'default'
			);
		}
	}

	/**
	 * Renders the box.
	 *
	 * @param mixed $post_or_order A WP_Post on the legacy tables, a WC_Order under HPOS.
	 * @return void
	 */
	public static function render( $post_or_order ) {

		$order = $post_or_order instanceof WC_Order
			? $post_or_order
			: wc_get_order( is_object( $post_or_order ) ? $post_or_order->ID : (int) $post_or_order );

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( ! Dahu_Serial_Numbers_Install::tables_exist() ) {
			printf(
				'<p>%s</p>',
				esc_html__( 'The plugin’s database tables are missing.', 'dahu-serial-numbers' )
			);

			return;
		}

		$rows    = Dahu_Serial_Numbers_Manager::get_by_order( $order->get_id() );
		$missing = Dahu_Serial_Numbers_Manager::missing_for_order( $order->get_id() );

		if ( ! $rows && ! $missing ) {
			printf(
				'<p>%s</p>',
				esc_html__( 'Nothing on this order carries a serial number.', 'dahu-serial-numbers' )
			);

			return;
		}

		if ( $missing ) {
			$owed = 0;

			foreach ( $missing as $line ) {
				$owed += (int) $line['required'] - (int) $line['assigned'];
			}

			printf(
				'<p class="dahu-serial-numbers__caution">%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: number of units. */
						_n(
							'%s unit on this order still has no serial number.',
							'%s units on this order still have no serial number.',
							$owed,
							'dahu-serial-numbers'
						),
						number_format_i18n( $owed )
					)
				)
			);
		}

		if ( $rows ) {
			self::render_table( $rows, $order );
		}

		printf(
			'<p class="dahu-serial-numbers__hint">%s</p>',
			esc_html__( 'Read-only here on purpose: a serial number is assigned by scanning the unit at the bench, so that the history records which bench did it and when.', 'dahu-serial-numbers' )
		);
	}

	/**
	 * The table of units on this order.
	 *
	 * @param array    $rows  Serials rows.
	 * @param WC_Order $order Order.
	 * @return void
	 */
	private static function render_table( array $rows, WC_Order $order ) {

		echo '<table class="widefat striped dahu-serial-numbers__table"><thead><tr>';
		printf( '<th scope="col">%s</th>', esc_html__( 'Serial number', 'dahu-serial-numbers' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Line', 'dahu-serial-numbers' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Status', 'dahu-serial-numbers' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Supplier batch', 'dahu-serial-numbers' ) );
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$item = $order->get_item( (int) $row['order_item_id'] );

			echo '<tr>';

			printf(
				'<td><a href="%s"><code>%s</code></a></td>',
				esc_url(
					add_query_arg(
						array(
							'page'   => Dahu_Serial_Numbers_Admin::SLUG,
							'serial' => $row['serial'],
						),
						admin_url( 'admin.php' )
					)
				),
				esc_html( (string) $row['serial'] )
			);

			printf(
				'<td>%s</td>',
				esc_html( $item instanceof WC_Order_Item ? $item->get_name() : '—' )
			);

			printf( '<td>%s</td>', esc_html( Dahu_Serial_Numbers_Statuses::label( (string) $row['status'] ) ) );
			printf( '<td>%s</td>', esc_html( '' !== (string) $row['supplier_order'] ? (string) $row['supplier_order'] : '—' ) );

			echo '</tr>';
		}

		echo '</tbody></table>';
	}
}
