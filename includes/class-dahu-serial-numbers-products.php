<?php
/**
 * Which products carry serial numbers.
 *
 * One flag per product, and a filter over the top. The flag is the simple
 * answer and covers a shop with a dozen serialised references; the filter is
 * for the shop with four hundred, where "everything in these two categories"
 * is the rule and ticking four hundred boxes is not.
 *
 * @package DahuSerialNumbers
 */

defined( 'ABSPATH' ) || exit;

/**
 * The serialised flag on a product.
 */
class Dahu_Serial_Numbers_Products {

	/**
	 * Meta key holding the flag.
	 */
	const META_KEY = '_dahu_serial_numbers_serialised';

	/**
	 * Does this product ship with a serial number per unit?
	 *
	 * A variation answers for itself when it carries the flag, and falls back
	 * to its parent when it does not — which is what a shop means when it
	 * ticks the box on a variable product rather than on each of its nine
	 * variations.
	 *
	 * @param int $product_id Product or variation id.
	 * @return bool
	 */
	public static function is_serialised( $product_id ) {

		$product_id = (int) $product_id;
		$serialised = false;

		if ( $product_id > 0 ) {
			$serialised = 'yes' === get_post_meta( $product_id, self::META_KEY, true );

			if ( ! $serialised && function_exists( 'wp_get_post_parent_id' ) ) {
				$parent_id = (int) wp_get_post_parent_id( $product_id );

				if ( $parent_id > 0 ) {
					$serialised = 'yes' === get_post_meta( $parent_id, self::META_KEY, true );
				}
			}
		}

		/**
		 * Filters whether a product is serialised.
		 *
		 * For deciding by category, by shipping class, by anything — without
		 * touching a flag on every product:
		 *
		 *     add_filter(
		 *         'dahu_serial_numbers_is_serialised',
		 *         function ( $serialised, $product_id ) {
		 *             return $serialised || has_term( 'motors', 'product_cat', $product_id );
		 *         },
		 *         10,
		 *         2
		 *     );
		 *
		 * @param bool $serialised Whether the flag says yes.
		 * @param int  $product_id Product or variation id.
		 */
		return (bool) apply_filters( 'dahu_serial_numbers_is_serialised', $serialised, $product_id );
	}

	/**
	 * Sets or clears the flag.
	 *
	 * The key is deleted rather than set to 'no', so that a variation with no
	 * opinion of its own goes back to answering like its parent. Storing 'no'
	 * would make "not ticked" and "deliberately not serialised" the same
	 * thing, and they are not.
	 *
	 * @param int  $product_id Product or variation id.
	 * @param bool $serialised Wanted state.
	 * @return void
	 */
	public static function set_serialised( $product_id, $serialised ) {

		$product_id = (int) $product_id;

		if ( $product_id <= 0 ) {
			return;
		}

		if ( $serialised ) {
			update_post_meta( $product_id, self::META_KEY, 'yes' );
		} else {
			delete_post_meta( $product_id, self::META_KEY );
		}
	}

	/**
	 * The id a serial should actually be filed under.
	 *
	 * A simple product converted to a variable one leaves order lines, and
	 * sometimes whole workflows, pointing at an id that is now a container.
	 * The plugin this was extracted from resolved that through a migration
	 * tool of its own; here it is a filter, because the mapping is the shop's
	 * and no plugin can know it.
	 *
	 * @param int $product_id Product or variation id, as the caller has it.
	 * @return int
	 */
	public static function resolve( $product_id ) {

		$product_id = (int) $product_id;

		if ( $product_id <= 0 ) {
			return 0;
		}

		/**
		 * Filters the product id a serial is filed under.
		 *
		 * @param int $product_id Id as the caller supplied it.
		 */
		$resolved = (int) apply_filters( 'dahu_serial_numbers_resolve_product', $product_id );

		return $resolved > 0 ? $resolved : $product_id;
	}
}
