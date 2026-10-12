<?php
/**
 * The “this product carries a serial number” box, on the product screen.
 *
 * @package DahuSerialNumbers
 */

defined( 'ABSPATH' ) || exit;

/**
 * One checkbox, on a simple product and on each variation.
 */
class Dahu_Serial_Numbers_Product_Field {

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public static function init() {

		// Inventory, not General: whether a unit is individually identified
		// is a stock question, and that is the tab somebody managing stock
		// already has open.
		add_action( 'woocommerce_product_options_inventory_product_data', array( __CLASS__, 'render_simple' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_simple' ) );

		add_action( 'woocommerce_variation_options_inventory', array( __CLASS__, 'render_variation' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation' ), 10, 2 );
	}

	/**
	 * The box on a simple or variable product.
	 *
	 * @return void
	 */
	public static function render_simple() {

		global $post;

		if ( ! $post ) {
			return;
		}

		echo '<div class="options_group">';

		woocommerce_wp_checkbox(
			array(
				'id'          => '_dahu_serial_numbers_serialised',
				'value'       => 'yes' === get_post_meta( $post->ID, Dahu_Serial_Numbers_Products::META_KEY, true ) ? 'yes' : 'no',
				'label'       => __( 'Serial numbers', 'dahu-serial-numbers' ),
				'description' => __( 'Each unit of this product carries its own serial number', 'dahu-serial-numbers' ),
				'desc_tip'    => false,
			)
		);

		printf(
			'<p class="form-field"><span class="description">%s</span></p>',
			esc_html__( 'Orders of this product will ask the packing screen for one serial number per unit. On a variable product, ticking it here covers every variation that has no answer of its own.', 'dahu-serial-numbers' )
		);

		echo '</div>';
	}

	/**
	 * Stores the box on a simple or variable product.
	 *
	 * @param WC_Product $product Product being saved.
	 * @return void
	 */
	public static function save_simple( $product ) {

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		// WooCommerce has already checked the nonce and the capability for
		// this screen before firing this hook; re-checking here would be
		// checking a nonce this code never issued.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$ticked = isset( $_POST['_dahu_serial_numbers_serialised'] );

		Dahu_Serial_Numbers_Products::set_serialised( $product->get_id(), $ticked );
	}

	/**
	 * The box on one variation.
	 *
	 * @param int     $loop           Row index in the variations list.
	 * @param array   $variation_data Legacy variation data, unused.
	 * @param WP_Post $variation      Variation post.
	 * @return void
	 */
	public static function render_variation( $loop, $variation_data, $variation ) {

		unset( $variation_data );

		$own = get_post_meta( $variation->ID, Dahu_Serial_Numbers_Products::META_KEY, true );

		woocommerce_wp_checkbox(
			array(
				'id'            => 'dahu_serial_numbers_serialised_' . $loop,
				'name'          => 'dahu_serial_numbers_serialised[' . $loop . ']',
				'value'         => 'yes' === $own ? 'yes' : 'no',
				'label'         => __( 'Serial numbers', 'dahu-serial-numbers' ),
				'description'   => Dahu_Serial_Numbers_Products::is_serialised( (int) $variation->post_parent )
					// Said on the variation, because a reader looking at an
					// unticked box on a variation of a ticked parent would
					// otherwise conclude this variation is not serialised.
					? __( 'The parent product is ticked, so this variation already carries serial numbers. Tick this only to be explicit.', 'dahu-serial-numbers' )
					: __( 'Each unit of this variation carries its own serial number', 'dahu-serial-numbers' ),
				'desc_tip'      => false,
				'wrapper_class' => 'form-row form-row-full',
			)
		);
	}

	/**
	 * Stores the box on one variation.
	 *
	 * @param int $variation_id Variation id.
	 * @param int $loop         Row index in the variations list.
	 * @return void
	 */
	public static function save_variation( $variation_id, $loop ) {

		// Same reasoning as save_simple(): WooCommerce verified the request
		// before firing this hook.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$submitted = isset( $_POST['dahu_serial_numbers_serialised'] ) ? (array) wp_unslash( $_POST['dahu_serial_numbers_serialised'] ) : array();

		Dahu_Serial_Numbers_Products::set_serialised( $variation_id, ! empty( $submitted[ $loop ] ) );
	}
}
