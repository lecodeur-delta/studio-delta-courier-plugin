<?php
/**
 * Adds a "Shipping CBM (m3)" field to the Shipping tab of the product data
 * panel, and saves it to _sd_cbm. Deliberately a flat, manually-audited
 * number per product rather than something recomputed live from length x
 * width x height — see docs, "Data model", for why: the source catalogue's
 * dimension fields don't mean the same thing from product to product
 * (a planter's footprint comes from a diameter field, a table's from
 * length x width), which is exactly what made the old per-row spreadsheet
 * formula unmaintainable.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'woocommerce_product_options_shipping', function () {
	echo '<div class="options_group">';

	woocommerce_wp_text_input(
		[
			'id'                => '_sd_cbm',
			'label'             => __( 'Shipping CBM (m³)', 'sd-shipping' ),
			'desc_tip'          => true,
			'description'       => __( 'Cubic metres used for Campos Delivery pricing. Measure once and enter directly — do not rely on WooCommerce\'s own Length/Width/Height fields, they are not populated consistently across the catalogue. Leave blank and the plugin will try to read a dimension triple out of the product description instead, flagged as estimated. To fill many products at once, use Studio Delta > Export.', 'sd-shipping' ),
			'type'              => 'number',
			'custom_attributes' => [
				'step' => '0.0001',
				'min'  => '0',
			],
		]
	);

	echo '</div>';
} );

add_action( 'woocommerce_process_product_meta', function ( $post_id ) {
	if ( isset( $_POST['_sd_cbm'] ) ) {
		update_post_meta( $post_id, '_sd_cbm', wc_format_decimal( wp_unslash( $_POST['_sd_cbm'] ) ) );
	}
} );

/**
 * Flag products with no CBM set (0, or never entered) directly on the
 * product list screen — the alternative is finding out at checkout when
 * calculate_shipping() silently treats them as zero volume.
 */
add_filter( 'manage_edit-product_columns', function ( $columns ) {
	$columns['sd_cbm'] = __( 'Shipping CBM', 'sd-shipping' );
	return $columns;
} );

add_action( 'manage_product_posts_custom_column', function ( $column, $post_id ) {
	if ( 'sd_cbm' !== $column ) {
		return;
	}
	$cbm = get_post_meta( $post_id, '_sd_cbm', true );
	if ( '' === $cbm || null === $cbm ) {
		echo '<span style="color:#b8571e;font-weight:600;">' . esc_html__( 'not set', 'sd-shipping' ) . '</span>';
	} else {
		echo esc_html( $cbm ) . ' m³';
	}
}, 10, 2 );
