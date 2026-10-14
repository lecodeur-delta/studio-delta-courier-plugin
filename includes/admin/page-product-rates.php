<?php
/**
 * Studio Delta > Product Rates - what every product costs to deliver to
 * every destination, using the live fuel levy and the same formula
 * checkout runs (SD_Pricing::calculate()).
 *
 * The full matrix is products x 60 destination corridors, which is far
 * too wide to render as one table. So the destination is a dropdown: pick
 * one, see every product's price for it. Any single product can then be
 * expanded in place to show all 60 destinations for that one item. Both
 * views are drawn from the same small data blob printed once into the
 * page, so switching destination is instant and needs no page reload.
 */

defined( 'ABSPATH' ) || exit;

class SD_Admin_Product_Rates {

	public static function render() {
		SD_Admin_Menu::require_access();

		$rows      = SD_Product_Data::get_all_product_rows();
		$corridors = SD_Product_Data::corridors();
		$levy      = SD_Fuel_Levy::get_current();
		$vat       = SD_Settings::vat_fraction();

		$priced  = array_values( array_filter( $rows, static fn( $r ) => (float) $r['cbm'] > 0 ) );
		$missing = array_values( array_filter( $rows, static fn( $r ) => ! ( (float) $r['cbm'] > 0 ) ) );

		SD_Admin_Menu::header(
			'rates',
			__( 'Product Rates', 'sd-shipping' ),
			__( 'Every product\'s Campos Delivery price, per destination, calculated live from its cubic volume - not a stored price list. Change the fuel surcharge or a product\'s dimensions and these numbers move with it.', 'sd-shipping' )
		);

		if ( null === $levy ) :
			?>
			<div class="notice notice-error">
				<p>
					<?php
					printf(
						/* translators: %s: link to the Fuel Surcharge screen */
						wp_kses_post( __( 'No fuel surcharge has ever been recorded, so the totals below are missing it entirely. <a href="%s">Record it first</a>.', 'sd-shipping' ) ),
						esc_url( SD_Admin_Menu::page_url( '-fuel' ) )
					);
					?>
				</p>
			</div>
			<?php
		endif;

		$levy_used = (float) $levy; // null becomes 0.0 - flagged above
		?>

		<div class="sd-summary">
			<div class="sd-stat">
				<span class="sd-stat-label"><?php esc_html_e( 'Fuel surcharge in effect', 'sd-shipping' ); ?></span>
				<span class="sd-stat-value"><?php echo esc_html( SD_Admin_Menu::current_levy_label() ?? __( 'not set', 'sd-shipping' ) ); ?></span>
				<a class="sd-stat-link" href="<?php echo esc_url( SD_Admin_Menu::page_url( '-fuel' ) ); ?>"><?php esc_html_e( 'change', 'sd-shipping' ); ?></a>
			</div>
			<div class="sd-stat">
				<span class="sd-stat-label"><?php esc_html_e( 'VAT', 'sd-shipping' ); ?></span>
				<span class="sd-stat-value"><?php echo esc_html( rtrim( rtrim( number_format( $vat * 100, 2 ), '0' ), '.' ) . '%' ); ?></span>
				<a class="sd-stat-link" href="<?php echo esc_url( SD_Admin_Menu::page_url( '-settings' ) ); ?>"><?php esc_html_e( 'change', 'sd-shipping' ); ?></a>
			</div>
			<div class="sd-stat">
				<span class="sd-stat-label"><?php esc_html_e( 'Products priced', 'sd-shipping' ); ?></span>
				<span class="sd-stat-value"><?php echo esc_html( count( $priced ) ); ?></span>
			</div>
			<div class="sd-stat <?php echo $missing ? 'sd-stat-warn' : ''; ?>">
				<span class="sd-stat-label"><?php esc_html_e( 'Missing dimensions', 'sd-shipping' ); ?></span>
				<span class="sd-stat-value"><?php echo esc_html( count( $missing ) ); ?></span>
				<?php if ( $missing ) : ?>
					<a class="sd-stat-link" href="#sd-missing"><?php esc_html_e( 'see list', 'sd-shipping' ); ?></a>
				<?php endif; ?>
			</div>
		</div>

		<div class="sd-controls">
			<label for="sd-destination"><strong><?php esc_html_e( 'Destination', 'sd-shipping' ); ?></strong></label>
			<select id="sd-destination">
				<?php
				$grouped = [];
				foreach ( $corridors as $c ) {
					$grouped[ $c['zone_name'] ][] = $c;
				}
				foreach ( $grouped as $zone_name => $zone_corridors ) :
					?>
					<optgroup label="<?php echo esc_attr( $zone_name ); ?>">
						<?php foreach ( $zone_corridors as $c ) : ?>
							<option value="<?php echo esc_attr( $c['key'] ); ?>">
								<?php
								echo esc_html( $c['label'] );
								echo $c['remote'] ? ' ' . esc_html__( '(outlying)', 'sd-shipping' ) : '';
								?>
							</option>
						<?php endforeach; ?>
					</optgroup>
				<?php endforeach; ?>
			</select>

			<label for="sd-search" class="sd-search-label"><strong><?php esc_html_e( 'Find a product', 'sd-shipping' ); ?></strong></label>
			<input type="search" id="sd-search" placeholder="<?php esc_attr_e( 'name or SKU…', 'sd-shipping' ); ?>">

			<span class="sd-controls-note" id="sd-context"></span>
		</div>

		<table class="widefat striped sd-rates">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Product', 'sd-shipping' ); ?></th>
					<th><?php esc_html_e( 'SKU', 'sd-shipping' ); ?></th>
					<th><?php esc_html_e( 'L × W × H (mm)', 'sd-shipping' ); ?></th>
					<th class="sd-num"><?php esc_html_e( 'CBM (m³)', 'sd-shipping' ); ?></th>
					<th class="sd-num"><?php esc_html_e( 'Base', 'sd-shipping' ); ?></th>
					<th class="sd-num"><?php esc_html_e( 'Fuel', 'sd-shipping' ); ?></th>
					<th class="sd-num"><?php esc_html_e( 'VAT', 'sd-shipping' ); ?></th>
					<th class="sd-num"><?php esc_html_e( 'Total', 'sd-shipping' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody id="sd-rates-body">
				<tr><td colspan="9"><?php esc_html_e( 'Loading rates…', 'sd-shipping' ); ?></td></tr>
			</tbody>
		</table>
		<noscript>
			<p class="sd-note"><?php esc_html_e( 'This screen needs JavaScript to switch destinations. Use the Export tab to download the full product × destination rate matrix as a CSV instead.', 'sd-shipping' ); ?></p>
		</noscript>

		<?php if ( $missing ) : ?>
			<h2 id="sd-missing"><?php esc_html_e( 'Products with no dimensions on file', 'sd-shipping' ); ?></h2>
			<p class="sd-note">
				<?php
				printf(
					/* translators: %s: link to the Export screen */
					wp_kses_post( __( 'These have neither a measured Shipping CBM nor a readable dimension triple in their description, so Campos Delivery treats them as zero volume - they fall straight to the destination\'s minimum charge. Fix them on the product\'s Shipping tab, or in bulk via <a href="%s">Export</a>.', 'sd-shipping' ) ),
					esc_url( SD_Admin_Menu::page_url( '-export' ) )
				);
				?>
			</p>
			<table class="widefat striped sd-missing">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Product', 'sd-shipping' ); ?></th>
						<th><?php esc_html_e( 'SKU', 'sd-shipping' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $missing as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row['name'] ); ?></td>
							<td><?php echo esc_html( $row['sku'] ?: '-' ); ?></td>
							<td>
								<a href="<?php echo esc_url( get_edit_post_link( $row['product_id'] ) ); ?>">
									<?php esc_html_e( 'Edit product', 'sd-shipping' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<script type="text/javascript">
		( function () {
			// Everything the table needs, printed once. Prices are computed
			// in the browser from the same three inputs the server uses
			// (CBM, corridor rate/min, levy + VAT) rather than shipping
			// 94 x 60 pre-rendered numbers down the wire.
			var DATA = <?php
				echo wp_json_encode( [
					'products'  => array_map( static function ( $r ) {
						return [
							'sku'    => (string) $r['sku'],
							'name'   => (string) $r['name'],
							'cbm'    => (float) $r['cbm'],
							'l'      => $r['L'],
							'w'      => $r['W'],
							'h'      => $r['H'],
							'd'      => $r['D'],
							'source' => (string) $r['source'],
						];
					}, $priced ),
					'corridors' => $corridors,
					'fuel'      => $levy_used,
					'vat'       => $vat,
				] );
			?>;

			var body    = document.getElementById( 'sd-rates-body' );
			var select  = document.getElementById( 'sd-destination' );
			var search  = document.getElementById( 'sd-search' );
			var context = document.getElementById( 'sd-context' );

			function money( n ) {
				return 'R ' + n.toFixed( 2 ).replace( /\B(?=(\d{3})+(?!\d))/g, ',' );
			}

			// The §2 formula, mirroring SD_Pricing::calculate() exactly.
			function price( cbm, corridor ) {
				var charge   = Math.max( cbm * corridor.rate, corridor.min );
				var fuel     = charge * DATA.fuel;
				var subtotal = charge + fuel;
				var vat      = subtotal * DATA.vat;
				return { charge: charge, fuel: fuel, vat: vat, total: subtotal + vat };
			}

			function dims( p ) {
				// Only ever 3 of the 4 axes are populated (Length and Depth
				// are the same axis under different names), so show
				// whichever of the two this product actually used.
				var long = p.l !== null ? p.l : p.d;
				var label = p.l !== null ? 'L' : ( p.d !== null ? 'D' : '' );
				if ( long === null || p.w === null || p.h === null ) {
					return '<span class="sd-dim-none">' + ( p.source === 'measured' ? 'measured CBM only' : '-' ) + '</span>';
				}
				return long + label + ' × ' + p.w + 'W × ' + p.h + 'H';
			}

			function corridorByKey( key ) {
				for ( var i = 0; i < DATA.corridors.length; i++ ) {
					if ( DATA.corridors[ i ].key === key ) { return DATA.corridors[ i ]; }
				}
				return DATA.corridors[ 0 ];
			}

			function allZonesTable( p ) {
				var html = '<table class="sd-inner"><thead><tr><th>Zone</th><th>Destination</th>'
					+ '<th class="sd-num">Rate/m³</th><th class="sd-num">Min</th><th class="sd-num">Total incl. VAT</th></tr></thead><tbody>';
				DATA.corridors.forEach( function ( c ) {
					var r = price( p.cbm, c );
					html += '<tr><td>' + c.zone_name + '</td><td>' + c.label
						+ ( c.remote ? ' <em>(outlying)</em>' : '' ) + '</td>'
						+ '<td class="sd-num">' + money( c.rate ) + '</td>'
						+ '<td class="sd-num">' + money( c.min ) + '</td>'
						+ '<td class="sd-num"><strong>' + money( r.total ) + '</strong></td></tr>';
				} );
				return html + '</tbody></table>';
			}

			function render() {
				var corridor = corridorByKey( select.value );
				var needle   = ( search.value || '' ).toLowerCase();
				var shown    = 0;
				var html     = '';

				DATA.products.forEach( function ( p, i ) {
					if ( needle && ( p.name + ' ' + p.sku ).toLowerCase().indexOf( needle ) === -1 ) {
						return;
					}
					shown++;
					var r = price( p.cbm, corridor );
					html += '<tr>'
						+ '<td>' + p.name + ( p.source === 'parsed from description'
							? ' <span class="sd-flag" title="Size read automatically from the product description, not a measured value.">*</span>' : '' ) + '</td>'
						+ '<td>' + ( p.sku || '-' ) + '</td>'
						+ '<td class="sd-dims">' + dims( p ) + '</td>'
						+ '<td class="sd-num">' + p.cbm.toFixed( 4 ) + '</td>'
						+ '<td class="sd-num">' + money( r.charge ) + '</td>'
						+ '<td class="sd-num">' + money( r.fuel ) + '</td>'
						+ '<td class="sd-num">' + money( r.vat ) + '</td>'
						+ '<td class="sd-num"><strong>' + money( r.total ) + '</strong></td>'
						+ '<td><button type="button" class="button-link sd-expand" data-i="' + i + '">all destinations</button></td>'
						+ '</tr>';
				} );

				if ( ! shown ) {
					html = '<tr><td colspan="9">No products match that search.</td></tr>';
				}

				body.innerHTML = html;
				context.textContent = shown + ' product' + ( 1 === shown ? '' : 's' )
					+ ' · ' + corridor.zone_name + ' · ' + ( DATA.fuel * 100 ).toFixed( 2 ).replace( /\.?0+$/, '' )
					+ '% fuel + ' + ( DATA.vat * 100 ).toFixed( 2 ).replace( /\.?0+$/, '' ) + '% VAT included';
			}

			// One delegated listener rather than one per row - the row set
			// is rebuilt on every render, so per-row handlers would leak.
			body.addEventListener( 'click', function ( e ) {
				var btn = e.target.closest( '.sd-expand' );
				if ( ! btn ) { return; }
				var tr = btn.closest( 'tr' );
				if ( tr.nextElementSibling && tr.nextElementSibling.classList.contains( 'sd-expanded' ) ) {
					tr.nextElementSibling.remove();
					btn.textContent = 'all destinations';
					return;
				}
				var p  = DATA.products[ parseInt( btn.getAttribute( 'data-i' ), 10 ) ];
				var row = document.createElement( 'tr' );
				row.className = 'sd-expanded';
				row.innerHTML = '<td colspan="9">' + allZonesTable( p ) + '</td>';
				tr.parentNode.insertBefore( row, tr.nextSibling );
				btn.textContent = 'hide';
			} );

			select.addEventListener( 'change', render );
			search.addEventListener( 'input', render );
			render();
		} )();
		</script>
		<?php
		SD_Admin_Menu::footer();
	}
}
