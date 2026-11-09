<?php
/**
 * The “this product carries a serial number” box, on the product screen.
 *
 * @package UbacSerials
 */

defined( 'ABSPATH' ) || exit;

/**
 * One checkbox, on a simple product and on each variation.
 */
class Ubac_Serials_Product_Field {

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
				'id'          => '_ubac_serials_serialised',
				'value'       => 'yes' === get_post_meta( $post->ID, Ubac_Serials_Products::META_KEY, true ) ? 'yes' : 'no',
				'label'       => __( 'Serial numbers', 'dahuconcept-serial-numbers' ),
				'description' => __( 'Each unit of this product carries its own serial number', 'dahuconcept-serial-numbers' ),
				'desc_tip'    => false,
			)
		);

		printf(
			'<p class="form-field"><span class="description">%s</span></p>',
			esc_html__( 'Orders of this product will ask the packing screen for one serial number per unit. On a variable product, ticking it here covers every variation that has no answer of its own.', 'dahuconcept-serial-numbers' )
		);

		echo '</div>';
	}

	/**
	 * May the current request write the box on this product?
	 *
	 * WooCommerce does check the request before firing these hooks, but
	 * relying on that is relying on somebody else's implementation detail:
	 * the day a third party fires `woocommerce_save_product_variation` from
	 * its own code, the check disappears with it and nothing here notices.
	 * So the guard is written out — the capability, then whichever of the
	 * two nonces matches the way the product was saved: the whole edit
	 * screen posting to post.php, or the variations panel over AJAX.
	 *
	 * @param int $post_id Product or variation id.
	 * @return bool
	 */
	private static function may_save( $post_id ) {

		if ( ! current_user_can( 'edit_product', $post_id ) ) {
			return false;
		}

		if ( isset( $_POST['woocommerce_meta_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' ) ) {
			return true;
		}

		if ( isset( $_POST['security'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['security'] ) ), 'save-variations' ) ) {
			return true;
		}

		return false;
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

		if ( ! self::may_save( $product->get_id() ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- may_save() verified the nonce just above.
		$ticked = isset( $_POST['_ubac_serials_serialised'] );

		Ubac_Serials_Products::set_serialised( $product->get_id(), $ticked );
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

		$own = get_post_meta( $variation->ID, Ubac_Serials_Products::META_KEY, true );

		woocommerce_wp_checkbox(
			array(
				'id'            => 'ubac_serials_serialised_' . $loop,
				'name'          => 'ubac_serials_serialised[' . $loop . ']',
				'value'         => 'yes' === $own ? 'yes' : 'no',
				'label'         => __( 'Serial numbers', 'dahuconcept-serial-numbers' ),
				'description'   => Ubac_Serials_Products::is_serialised( (int) $variation->post_parent )
					// Said on the variation, because a reader looking at an
					// unticked box on a variation of a ticked parent would
					// otherwise conclude this variation is not serialised.
					? __( 'The parent product is ticked, so this variation already carries serial numbers. Tick this only to be explicit.', 'dahuconcept-serial-numbers' )
					: __( 'Each unit of this variation carries its own serial number', 'dahuconcept-serial-numbers' ),
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

		if ( ! self::may_save( $variation_id ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- may_save() verified the nonce just above.
		$submitted = isset( $_POST['ubac_serials_serialised'] ) ? (array) wp_unslash( $_POST['ubac_serials_serialised'] ) : array();

		Ubac_Serials_Products::set_serialised( $variation_id, ! empty( $submitted[ $loop ] ) );
	}
}
