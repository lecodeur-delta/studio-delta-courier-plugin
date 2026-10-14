<?php
/**
 * Studio Delta > Standard Nationwide - the courier price list.
 *
 * One row per courier product (or family of products sharing a price),
 * one column per province. Add a row to put another product on courier
 * delivery; link it to the actual WooCommerce products it covers, or let
 * the match phrase find them by name. The "Applies to" column shows,
 * live, exactly which products each row is catching, using the same
 * lookup checkout uses.
 *
 * Built by Levi Bugera - Delta Creative Agency - https://www.lecodeur.co.za
 */

defined( 'ABSPATH' ) || exit;

class SD_Admin_Nationwide {

	public static function render() {
		SD_Admin_Menu::require_access();

		$table   = SD_Courier_Rates::table();
		$rows    = $table['rows'];
		$regions = SD_Courier_Rates::regions();
		$hints   = SD_Courier_Rates::region_hints();
		$matched = SD_Courier_Rates::matched_products();

		// Summary figures.
		$blank = 0;
		$gp    = 0;
		foreach ( $rows as $row ) {
			foreach ( $row['prices'] as $code => $price ) {
				if ( null === $price ) {
					$blank++;
				} elseif ( 'GP' === $code ) {
					$gp++;
				}
			}
		}
		$unmatched_rows = array_filter( $matched, static function ( $list ) { return empty( $list ); } );

		SD_Admin_Menu::header(
			'nationwide',
			__( 'Standard Nationwide Shipping', 'sd-shipping' ),
			__( 'Flat courier prices for smaller items, per product and per province. A customer sees this option only when every item in their basket has a price here for their province - one item without a price and it is hidden, so a bulky piece never travels at a courier price. Prices are charged exactly as entered, VAT included, with no fuel levy. Leave a cell blank for "not available there"; a blank is never treated as free.', 'sd-shipping' )
		);

		if ( isset( $_GET['sd_saved'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Courier prices saved. Checkout uses them straight away.', 'sd-shipping' ); ?></p></div>
		<?php endif; ?>
		<?php if ( isset( $_GET['sd_reset'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'The supplied price list has been restored.', 'sd-shipping' ); ?></p></div>
		<?php endif; ?>

		<div class="sd-summary">
			<div class="sd-stat">
				<span class="sd-stat-label"><?php esc_html_e( 'Courier products', 'sd-shipping' ); ?></span>
				<span class="sd-stat-value"><?php echo esc_html( count( $rows ) ); ?></span>
			</div>
			<div class="sd-stat <?php echo $blank ? 'sd-stat-warn' : ''; ?>">
				<span class="sd-stat-label"><?php esc_html_e( 'Blank prices', 'sd-shipping' ); ?></span>
				<span class="sd-stat-value"><?php echo esc_html( $blank ); ?></span>
			</div>
			<div class="sd-stat <?php echo $gp < count( $rows ) ? 'sd-stat-warn' : ''; ?>">
				<span class="sd-stat-label"><?php esc_html_e( 'Gauteng prices set', 'sd-shipping' ); ?></span>
				<span class="sd-stat-value"><?php echo esc_html( $gp . ' / ' . count( $rows ) ); ?></span>
			</div>
			<div class="sd-stat <?php echo $unmatched_rows ? 'sd-stat-warn' : ''; ?>">
				<span class="sd-stat-label"><?php esc_html_e( 'Rows matching no product', 'sd-shipping' ); ?></span>
				<span class="sd-stat-value"><?php echo esc_html( count( $unmatched_rows ) ); ?></span>
			</div>
		</div>

		<?php if ( $unmatched_rows ) : ?>
			<div class="notice notice-warning inline"><p>
				<?php esc_html_e( 'Some rows do not apply to any product yet, so those prices can never be charged. Link the real products in the "Linked products" box, or adjust the match phrase until the right products appear under "Applies to".', 'sd-shipping' ); ?>
			</p></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="sd-nw-form">
			<?php wp_nonce_field( 'sd_save_nationwide' ); ?>
			<input type="hidden" name="action" value="sd_save_nationwide">

			<div class="sd-panel">
				<h2><?php esc_html_e( 'Courier price list (Rand, VAT included)', 'sd-shipping' ); ?></h2>
				<p class="sd-note">
					<?php esc_html_e( 'Which products a row covers: anything in "Linked products" first, then any product whose name contains every word of the match phrase. "Small Planter" therefore catches "Angelica Planter - Small" as well as "Small Planter". Separate several phrases with commas. Where two rows match, the longer phrase wins.', 'sd-shipping' ); ?>
				</p>

				<div class="sd-nw-scroll">
					<table class="widefat striped sd-nw-table">
						<thead>
							<tr>
								<th class="sd-nw-name"><?php esc_html_e( 'Product / match phrase', 'sd-shipping' ); ?></th>
								<th class="sd-nw-link"><?php esc_html_e( 'Linked products', 'sd-shipping' ); ?></th>
								<?php foreach ( $regions as $code => $name ) : ?>
									<th class="sd-nw-price">
										<?php echo esc_html( $name ); ?>
										<?php if ( isset( $hints[ $code ] ) ) : ?>
											<span class="sd-nw-hint"><?php echo esc_html( $hints[ $code ] ); ?></span>
										<?php endif; ?>
									</th>
								<?php endforeach; ?>
								<th class="sd-nw-applies"><?php esc_html_e( 'Applies to', 'sd-shipping' ); ?></th>
								<th class="sd-check"><?php esc_html_e( 'Remove', 'sd-shipping' ); ?></th>
							</tr>
						</thead>
						<tbody id="sd-nw-rows">
							<?php
							$i = 0;
							foreach ( $rows as $row ) {
								self::render_row( $i++, $row, $regions, $matched[ $row['key'] ] ?? [] );
							}
							?>
						</tbody>
					</table>
				</div>

				<p>
					<button type="button" class="button" id="sd-nw-add"><?php esc_html_e( '+ Add a courier product', 'sd-shipping' ); ?></button>
				</p>
			</div>

			<div class="sd-panel">
				<h2><?php esc_html_e( 'Never by courier', 'sd-shipping' ); ?></h2>
				<p class="sd-note"><?php esc_html_e( 'Products listed here are never priced by Standard Nationwide, even if a match phrase would catch them - useful when a name like "Large Planter Stand" is caught by "Large Planter" but ships differently. Having one of these in the basket hides the option.', 'sd-shipping' ); ?></p>
				<?php self::product_select( 'sd_nw_excluded[]', $table['excluded'], 'sd-nw-excluded' ); ?>
			</div>

			<?php submit_button( __( 'Save courier prices', 'sd-shipping' ) ); ?>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sd-nw-reset"
			onsubmit="return window.confirm(<?php echo esc_attr( wp_json_encode( __( 'Replace every row with the original supplied price list? Rows you added and prices you changed will be lost.', 'sd-shipping' ) ) ); ?>);">
			<?php wp_nonce_field( 'sd_reset_nationwide' ); ?>
			<input type="hidden" name="action" value="sd_reset_nationwide">
			<button type="submit" class="button-link sd-nw-reset-link"><?php esc_html_e( 'Restore the supplied price list', 'sd-shipping' ); ?></button>
		</form>

		<script type="text/template" id="sd-nw-template">
			<?php self::render_row( '__i__', null, $regions, [] ); ?>
		</script>

		<script>
		( function ( $ ) {
			var next = <?php echo (int) $i; ?>;
			$( '#sd-nw-add' ).on( 'click', function () {
				var html = $( '#sd-nw-template' ).html().replace( /__i__/g, String( next++ ) );
				var $row = $( html );
				$( '#sd-nw-rows' ).append( $row );
				$( document.body ).trigger( 'wc-enhanced-select-init' );
				$row.find( 'input[type=text]' ).first().trigger( 'focus' );
			} );
			$( '#sd-nw-rows' ).on( 'change', '.sd-nw-del', function () {
				$( this ).closest( 'tr' ).toggleClass( 'sd-nw-deleting', this.checked );
			} );
			$( '#sd-nw-rows' ).on( 'input', '.sd-nw-price-input', function () {
				$( this ).toggleClass( 'sd-blank', '' === $.trim( this.value ) );
			} );
		} )( jQuery );
		</script>
		<?php

		SD_Admin_Menu::footer();
	}

	/**
	 * One editable row. $index is a number, or '__i__' for the template
	 * that "Add a courier product" clones.
	 *
	 * @param int|string $index
	 * @param array|null $row
	 * @param array      $regions
	 * @param array[]    $applies
	 */
	protected static function render_row( $index, $row, $regions, $applies ) {
		$name = 'sd_nw_rows[' . $index . ']';
		$row  = $row ?: [ 'key' => '', 'label' => '', 'match' => '', 'products' => [], 'prices' => [] ];
		?>
		<tr>
			<td class="sd-nw-name">
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>[key]" value="<?php echo esc_attr( $row['key'] ); ?>">
				<input type="text" class="sd-nw-label" name="<?php echo esc_attr( $name ); ?>[label]" value="<?php echo esc_attr( $row['label'] ); ?>"
					placeholder="<?php esc_attr_e( 'e.g. Iris Wall Mirror', 'sd-shipping' ); ?>" aria-label="<?php esc_attr_e( 'Product name', 'sd-shipping' ); ?>">
				<input type="text" class="sd-nw-match" name="<?php echo esc_attr( $name ); ?>[match]" value="<?php echo esc_attr( $row['match'] ); ?>"
					placeholder="<?php esc_attr_e( 'match phrase (blank = linked only)', 'sd-shipping' ); ?>" aria-label="<?php esc_attr_e( 'Match phrase', 'sd-shipping' ); ?>">
			</td>
			<td class="sd-nw-link">
				<?php self::product_select( $name . '[products][]', $row['products'], '', '__i__' === $index ); ?>
			</td>
			<?php foreach ( $regions as $code => $region_name ) :
				$price = $row['prices'][ $code ] ?? null;
				$shown = null === $price ? '' : rtrim( rtrim( number_format( (float) $price, 2, '.', '' ), '0' ), '.' );
				?>
				<td class="sd-nw-price">
					<input type="text" inputmode="decimal" class="sd-nw-price-input<?php echo '' === $shown ? ' sd-blank' : ''; ?>"
						name="<?php echo esc_attr( $name ); ?>[prices][<?php echo esc_attr( $code ); ?>]"
						value="<?php echo esc_attr( $shown ); ?>"
						aria-label="<?php echo esc_attr( $region_name ); ?>">
				</td>
			<?php endforeach; ?>
			<td class="sd-nw-applies">
				<?php if ( '__i__' === $index ) : ?>
					<span class="sd-dim-none"><?php esc_html_e( 'shown after saving', 'sd-shipping' ); ?></span>
				<?php elseif ( empty( $applies ) ) : ?>
					<span class="sd-nw-none"><?php esc_html_e( 'no products', 'sd-shipping' ); ?></span>
				<?php else : ?>
					<details>
						<summary>
							<?php
							printf(
								/* translators: %d: number of products */
								esc_html( _n( '%d product', '%d products', count( $applies ), 'sd-shipping' ) ),
								count( $applies )
							);
							?>
						</summary>
						<ul>
							<?php foreach ( $applies as $p ) : ?>
								<li>
									<a href="<?php echo esc_url( get_edit_post_link( $p['id'] ) ); ?>"><?php echo esc_html( $p['name'] ); ?></a>
									<span class="sd-nw-how"><?php echo 'linked' === $p['how'] ? esc_html__( 'linked', 'sd-shipping' ) : esc_html__( 'by name', 'sd-shipping' ); ?></span>
								</li>
							<?php endforeach; ?>
						</ul>
					</details>
				<?php endif; ?>
			</td>
			<td class="sd-check">
				<input type="checkbox" class="sd-nw-del" name="<?php echo esc_attr( $name ); ?>[delete]" value="1" aria-label="<?php esc_attr_e( 'Remove this row', 'sd-shipping' ); ?>">
			</td>
		</tr>
		<?php
	}

	/**
	 * WooCommerce's own product search box (the one on the coupon screen),
	 * pre-filled with the current selection.
	 *
	 * @param string $name
	 * @param int[]  $ids
	 * @param string $id
	 * @param bool   $template true inside the row template
	 */
	protected static function product_select( $name, $ids, $id = '', $template = false ) {
		?>
		<select class="wc-product-search" multiple="multiple" style="width:100%;"
			<?php echo $id ? 'id="' . esc_attr( $id ) . '"' : ''; ?>
			name="<?php echo esc_attr( $name ); ?>"
			data-placeholder="<?php esc_attr_e( 'Search products…', 'sd-shipping' ); ?>"
			data-action="woocommerce_json_search_products_and_variations">
			<?php
			if ( ! $template ) {
				foreach ( (array) $ids as $pid ) {
					$product = function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;
					if ( $product ) {
						printf(
							'<option value="%d" selected="selected">%s</option>',
							(int) $pid,
							esc_html( wp_strip_all_tags( $product->get_formatted_name() ) )
						);
					}
				}
			}
			?>
		</select>
		<?php
	}

	/**
	 * admin_post_sd_save_nationwide
	 */
	public static function handle_save() {
		SD_Admin_Menu::require_access( 'sd_save_nationwide' );

		$posted = isset( $_POST['sd_nw_rows'] ) && is_array( $_POST['sd_nw_rows'] ) ? wp_unslash( $_POST['sd_nw_rows'] ) : [];
		$table  = self::rows_from_post(
			$posted,
			isset( $_POST['sd_nw_excluded'] ) ? (array) wp_unslash( $_POST['sd_nw_excluded'] ) : []
		);

		SD_Courier_Rates::save( $table );

		wp_safe_redirect( add_query_arg( 'sd_saved', '1', SD_Admin_Menu::page_url( '-nationwide' ) ) );
		exit;
	}

	/**
	 * Turn the posted form into a table. Public and free of WordPress
	 * request handling so it can be tested directly.
	 *
	 * @param array $posted   sd_nw_rows, already unslashed
	 * @param array $excluded product ids
	 * @return array
	 */
	public static function rows_from_post( array $posted, array $excluded ) {
		$codes = array_keys( SD_Courier_Rates::regions() );
		$rows  = [];

		foreach ( $posted as $in ) {
			if ( ! is_array( $in ) || ! empty( $in['delete'] ) ) {
				continue;
			}
			$label  = sanitize_text_field( (string) ( $in['label'] ?? '' ) );
			$prices = [];
			foreach ( $codes as $code ) {
				$prices[ $code ] = SD_Courier_Rates::to_price( sanitize_text_field( (string) ( $in['prices'][ $code ] ?? '' ) ) );
			}

			// An untouched "Add" row: nothing typed, nothing to keep.
			if ( '' === $label && ! array_filter( $prices, static function ( $p ) { return null !== $p; } ) ) {
				continue;
			}
			if ( '' === $label ) {
				$label = __( 'Unnamed courier product', 'sd-shipping' );
			}

			$key = sanitize_key( (string) ( $in['key'] ?? '' ) );
			if ( '' === $key || isset( $rows[ $key ] ) ) {
				$key = SD_Courier_Rates::make_key( $label, array_keys( $rows ) );
			}

			$rows[ $key ] = [
				'key'      => $key,
				'label'    => $label,
				'match'    => sanitize_text_field( (string) ( $in['match'] ?? '' ) ),
				'products' => array_map( 'absint', (array) ( $in['products'] ?? [] ) ),
				'prices'   => $prices,
			];
		}

		return [
			'rows'     => $rows,
			'excluded' => array_map( 'absint', $excluded ),
		];
	}

	/**
	 * admin_post_sd_reset_nationwide
	 */
	public static function handle_reset() {
		SD_Admin_Menu::require_access( 'sd_reset_nationwide' );
		SD_Courier_Rates::save( SD_Courier_Rates::seed() );
		wp_safe_redirect( add_query_arg( 'sd_reset', '1', SD_Admin_Menu::page_url( '-nationwide' ) ) );
		exit;
	}
}
