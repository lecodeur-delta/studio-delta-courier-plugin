<?php
/**
 * Adds a "Shipping CBM (m3)" field to the Shipping tab of the product data
 * panel, and saves it to _sd_cbm. Deliberately a flat, manually-audited
 * number per product rather than something recomputed live from length x
 * width x height - see docs, "Data model", for why: the source catalogue's
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
			'description'       => __( 'Cubic metres used for Campos Delivery pricing. Measure once and enter directly - do not rely on WooCommerce\'s own Length/Width/Height fields, they are not populated consistently across the catalogue. Leave blank and the plugin will try to read a dimension triple out of the product description instead, flagged as estimated. To fill many products at once, use Studio Delta > Export.', 'sd-shipping' ),
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
 * product list screen - the alternative is finding out at checkout when
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

/**
 * Standard Nationwide (v0.7.0): which courier price a product uses, set
 * from the product's own Shipping tab. This is the quickest way to put
 * another product on courier delivery at an existing price - e.g. a new
 * planter that ships like the Small Planter.
 *
 * There is no separate product meta behind this: the choice is written
 * into the courier price table itself (a row's linked products, or the
 * "never by courier" list), so the Standard Nationwide screen and this
 * dropdown can never disagree.
 */
add_action( 'woocommerce_product_options_shipping', function () {
	global $post;
	if ( ! $post || ! class_exists( 'SD_Courier_Rates' ) ) {
		return;
	}

	$id      = (int) $post->ID;
	$table   = SD_Courier_Rates::table();
	$current = sd_courier_choice_for( $id, $table );

	$options = [
		''     => __( 'Automatic - match by product name', 'sd-shipping' ),
		'none' => __( 'Never by courier', 'sd-shipping' ),
	];
	foreach ( $table['rows'] as $key => $row ) {
		$options[ $key ] = $row['label'];
	}

	// Say what "Automatic" currently resolves to, so the choice is informed.
	$auto = SD_Courier_Rates::match_by_name( get_the_title( $id ), $table['rows'] );
	$desc = $auto
		/* translators: %s: courier price row */
		? sprintf( __( 'Automatic currently matches "%s" by name.', 'sd-shipping' ), $auto['label'] )
		: __( 'Automatic currently matches nothing, so this product does not go by courier.', 'sd-shipping' );

	echo '<div class="options_group">';
	woocommerce_wp_select(
		[
			'id'          => '_sd_courier_row_choice',
			'label'       => __( 'Standard Nationwide price', 'sd-shipping' ),
			'options'     => $options,
			'value'       => $current,
			'desc_tip'    => false,
			'description' => $desc . ' ' . sprintf(
				/* translators: %s: URL of the courier price list */
				__( 'Prices per province are on the <a href="%s">Standard Nationwide</a> screen.', 'sd-shipping' ),
				esc_url( admin_url( 'admin.php?page=sd-delivery-nationwide' ) )
			),
		]
	);
	echo '</div>';
}, 20 );

add_action( 'woocommerce_process_product_meta', function ( $post_id ) {
	if ( ! isset( $_POST['_sd_courier_row_choice'] ) || ! class_exists( 'SD_Courier_Rates' ) ) {
		return;
	}
	$choice = sanitize_key( wp_unslash( $_POST['_sd_courier_row_choice'] ) );
	$table  = SD_Courier_Rates::table();
	$id     = (int) $post_id;

	if ( sd_courier_choice_for( $id, $table ) === $choice ) {
		return; // unchanged - don't rewrite the table on every product save
	}

	SD_Courier_Rates::save( sd_courier_apply_choice( $id, $choice, $table ) );
} );

/**
 * What the dropdown should show for a product: a row key, 'none', or ''.
 *
 * @param int   $id
 * @param array $table
 * @return string
 */
function sd_courier_choice_for( $id, array $table ) {
	if ( in_array( (int) $id, $table['excluded'], true ) ) {
		return 'none';
	}
	foreach ( $table['rows'] as $key => $row ) {
		if ( in_array( (int) $id, $row['products'], true ) ) {
			return (string) $key;
		}
	}
	return '';
}

/**
 * Move a product to a row, to the exclusion list, or back to automatic.
 *
 * @param int    $id
 * @param string $choice row key, 'none' or ''
 * @param array  $table
 * @return array the updated table
 */
function sd_courier_apply_choice( $id, $choice, array $table ) {
	$id = (int) $id;

	// Out of everywhere first, so a product is only ever in one place.
	$table['excluded'] = array_values( array_diff( $table['excluded'], [ $id ] ) );
	foreach ( $table['rows'] as $key => $row ) {
		$table['rows'][ $key ]['products'] = array_values( array_diff( $row['products'], [ $id ] ) );
	}

	if ( 'none' === $choice ) {
		$table['excluded'][] = $id;
	} elseif ( '' !== $choice && isset( $table['rows'][ $choice ] ) ) {
		$table['rows'][ $choice ]['products'][] = $id;
	}

	return $table;
}
