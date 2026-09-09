<?php
/**
 * Studio Delta > Settings — every deeper configuration value, plus the
 * one thing that otherwise forces a trip into WooCommerce: deciding which
 * shipping zones offer Campos Delivery and Request Quote.
 *
 * That zone section reads WooCommerce's own shipping-zone tables and
 * writes back through WooCommerce's own APIs — it is a remote control for
 * WC's screen, not a replacement for it. Your existing methods in each
 * zone (Gauteng Delivery, KZN Delivery, On-site Collection, Free
 * shipping, SA Delivery…) are listed read-only under "Standard Rates":
 * this plugin never edits, prices, or reorders them.
 */

defined( 'ABSPATH' ) || exit;

class SD_Admin_Settings_Page {

	const CAMPOS_METHOD = 'sd_campos_delivery';
	const QUOTE_METHOD  = 'sd_request_quote';
	const TBM_METHOD    = 'sd_3bm_delivery';

	public static function render() {
		SD_Admin_Menu::require_access();

		$s = SD_Settings::all();

		SD_Admin_Menu::header(
			'settings',
			__( 'Settings', 'sd-shipping' ),
			__( 'Everything configurable in one place. Rates themselves are not here — corridor rates come from the courier\'s rate card in code, and the fuel surcharge has its own dated screen so its history survives.', 'sd-shipping' )
		);

		if ( isset( $_GET['sd_saved'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'sd-shipping' ); ?></p></div>
		<?php endif; ?>
		<?php if ( isset( $_GET['sd_zones'] ) ) : ?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Shipping zone assignments updated in WooCommerce.', 'sd-shipping' ); ?></p>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'sd_save_settings' ); ?>
			<input type="hidden" name="action" value="sd_save_settings">

			<?php self::render_zones_panel(); ?>

			<div class="sd-panel">
				<h2><?php esc_html_e( 'Checkout behaviour', 'sd-shipping' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="vat_rate"><?php esc_html_e( 'VAT rate', 'sd-shipping' ); ?></label></th>
						<td>
							<input type="number" step="0.01" min="0" max="100" class="small-text" id="vat_rate" name="vat_rate" value="<?php echo esc_attr( $s['vat_rate'] ); ?>"> %
							<p class="description"><?php esc_html_e( 'Applied to the base charge plus fuel surcharge, last. South Africa is 15% — change this only if the rate itself changes.', 'sd-shipping' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'South Africa only', 'sd-shipping' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="za_only" value="yes" <?php checked( 'yes', $s['za_only'] ); ?>>
								<?php esc_html_e( 'Only offer Campos Delivery and Request Quote for South African addresses', 'sd-shipping' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'The courier corridor table only covers South Africa, so leaving this on is the safe default. Your Standard Rates are unaffected either way.', 'sd-shipping' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Unrecognised town', 'sd-shipping' ); ?></th>
						<td>
							<select name="unmatched_behaviour">
								<option value="notice" <?php selected( 'notice', $s['unmatched_behaviour'] ); ?>><?php esc_html_e( 'Show a "contact us for a quote" notice', 'sd-shipping' ); ?></option>
								<option value="silent" <?php selected( 'silent', $s['unmatched_behaviour'] ); ?>><?php esc_html_e( 'Say nothing — just don\'t offer Campos Delivery', 'sd-shipping' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'What happens when a South African address matches none of the 60 corridors. Either way no price is guessed. If Request Quote is offered in that zone, the customer still has a route forward.', 'sd-shipping' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Read dimensions from descriptions', 'sd-shipping' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="parse_descriptions" value="yes" <?php checked( 'yes', $s['parse_descriptions'] ); ?>>
								<?php esc_html_e( 'Fall back to parsing "600mm W x 450mm D x 900mm H" out of the product description', 'sd-shipping' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Only used for products with no measured Shipping CBM. Turning this off means such products contribute zero volume and fall to the destination minimum instead — stricter, but it prices more things wrongly-cheap. A parsed figure is always flagged as estimated at checkout.', 'sd-shipping' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<div class="sd-panel">
				<h2><?php esc_html_e( 'Campos Delivery', 'sd-shipping' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="campos_badge"><?php esc_html_e( 'Checkout badge', 'sd-shipping' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="campos_badge" name="campos_badge" value="<?php echo esc_attr( $s['campos_badge'] ); ?>">
							<p class="description"><?php esc_html_e( 'The small note shown beside the Campos Delivery option, marking it as the option for bulky goods. Leave blank to hide it.', 'sd-shipping' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Fuel surcharge icon', 'sd-shipping' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="show_fuel_icon" value="yes" <?php checked( 'yes', $s['show_fuel_icon'] ); ?>>
								<?php esc_html_e( 'Show a fuel-pump icon next to the surcharge at checkout', 'sd-shipping' ); ?>
							</label>
							<p class="description">
								<?php
								echo wp_kses_post( sprintf(
									/* translators: %s: rendered icon preview */
									__( 'Preview: %s 33%% fuel levy: R277.20 — the icon carries a tooltip explaining what the surcharge is, so customers get an answer without asking.', 'sd-shipping' ),
									sd_fuel_icon_svg()
								) );
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Itemised breakdown', 'sd-shipping' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="show_breakdown" value="yes" <?php checked( 'yes', $s['show_breakdown'] ); ?>>
								<?php esc_html_e( 'Show the collapsible per-item cost breakdown under the shipping line', 'sd-shipping' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Lists each item\'s size, where that size came from, and the base / fuel / VAT arithmetic. Turning it off does not change any price — the same figures still travel onto the order for wp-admin and order emails.', 'sd-shipping' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Shipping Class cross-check', 'sd-shipping' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="show_wc_class" value="yes" <?php checked( 'yes', $s['show_wc_class'] ); ?>>
								<?php esc_html_e( 'Also show each item\'s Shipping Class in the customer\'s breakdown', 'sd-shipping' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Off by default. This is a staff diagnostic — it shows each product\'s assigned A/B/C class beside the size computed from its volume and flags disagreements, which is useful for finding class assignments worth correcting but means nothing to a customer. The comparison is recorded on every order either way, so leaving this off loses nothing. Price always comes from volume, never from the class.', 'sd-shipping' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<div class="sd-panel">
				<h2><?php esc_html_e( 'Request Quote', 'sd-shipping' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="sales_email"><?php esc_html_e( 'Send quote requests to', 'sd-shipping' ); ?></label></th>
						<td>
							<input type="email" class="regular-text" id="sales_email" name="sales_email" value="<?php echo esc_attr( $s['sales_email'] ); ?>">
							<p class="description"><?php esc_html_e( 'Where the products-and-address email goes when someone places an order choosing Request Quote. One email per order — never one per cart change.', 'sd-shipping' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Range notice', 'sd-shipping' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="quote_range_notice" value="yes" <?php checked( 'yes', $s['quote_range_notice'] ); ?>>
								<?php esc_html_e( 'Tell the customer roughly what to expect', 'sd-shipping' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Shows that the hand-quoted figure will land between what Campos Delivery and your Standard Rates would charge for the same cart, with a warning that outlying zones sit at the higher end. Both figures are read from the rates WooCommerce already calculated — nothing is estimated separately.', 'sd-shipping' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<div class="sd-panel">
				<h2><?php esc_html_e( 'When only Request a Quote should show', 'sd-shipping' ); ?></h2>
				<p class="sd-note"><?php esc_html_e( 'Two situations where a computed price misleads more than it helps. In both, every other option is removed and only Request a Quote is offered — and neither rule ever fires when free shipping or collection is available in that zone, since delivery is then not a barrier at all.', 'sd-shipping' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th><?php esc_html_e( 'Outside our routes', 'sd-shipping' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="quote_only_outside_zone" value="yes" <?php checked( 'yes', $s['quote_only_outside_zone'] ); ?>>
								<?php esc_html_e( 'Quote only when the address matches none of the 60 Campos corridors', 'sd-shipping' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Campos already declines to quote there. What would otherwise remain is a flat rate priced for somewhere else — a Gauteng rate on a Karoo farm is not a number to hold a customer to.', 'sd-shipping' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="quote_only_threshold"><?php esc_html_e( 'Delivery share of the order', 'sd-shipping' ); ?></label></th>
						<td>
							<input type="number" step="1" min="0" max="500" class="small-text" id="quote_only_threshold" name="quote_only_threshold" value="<?php echo esc_attr( $s['quote_only_threshold'] ); ?>"> %
							<p class="description"><?php esc_html_e( 'When the cheapest real delivery option costs more than this share of the order, show only Request a Quote. Set to 0 to switch the rule off entirely.', 'sd-shipping' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Measured against', 'sd-shipping' ); ?></th>
						<td>
							<select name="threshold_basis">
								<option value="subtotal" <?php selected( 'subtotal', $s['threshold_basis'] ); ?>><?php esc_html_e( 'Goods subtotal, excluding tax', 'sd-shipping' ); ?></option>
								<option value="total" <?php selected( 'total', $s['threshold_basis'] ); ?>><?php esc_html_e( 'Goods including tax', 'sd-shipping' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Shipping is never part of its own denominator — that would be circular.', 'sd-shipping' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<div class="sd-panel">
				<h2><?php esc_html_e( 'Quote request handling', 'sd-shipping' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><?php esc_html_e( 'Record an order', 'sd-shipping' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="quote_creates_order" value="yes" <?php checked( 'yes', $s['quote_creates_order'] ); ?>>
								<?php esc_html_e( 'Create a pending order for every quote request', 'sd-shipping' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'The enquiry appears in Orders with its products and address, ready for you to add a delivery cost and send a payment link. No payment is taken and the basket is cleared, exactly as a normal checkout would. With this off, the email is the only record.', 'sd-shipping' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="quote_order_status"><?php esc_html_e( 'Order status to use', 'sd-shipping' ); ?></label></th>
						<td>
							<select name="quote_order_status" id="quote_order_status">
								<?php foreach ( wc_get_order_statuses() as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $s['quote_order_status'] ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Pending payment is the sensible default. If you have a custom "Awaiting quote" status, choose it here and these orders will be easy to filter.', 'sd-shipping' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Copy the customer', 'sd-shipping' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="quote_copy_customer" value="yes" <?php checked( 'yes', $s['quote_copy_customer'] ); ?>>
								<?php esc_html_e( 'Email the customer a copy of what they submitted', 'sd-shipping' ); ?>
							</label>
						</td>
					</tr>
				</table>
			</div>

			<?php self::render_tbm_panel( $s ); ?>

			<div class="sd-panel">
				<h2><?php esc_html_e( 'Checkout footer links', 'sd-shipping' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><?php esc_html_e( 'Show links', 'sd-shipping' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="show_policy_links" value="yes" <?php checked( 'yes', $s['show_policy_links'] ); ?>>
								<?php esc_html_e( 'Show Delivery Policy and FAQ links at the foot of the checkout', 'sd-shipping' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th><label for="policy_url"><?php esc_html_e( 'Delivery Policy', 'sd-shipping' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="policy_url" name="policy_url" value="<?php echo esc_attr( $s['policy_url'] ); ?>">
							<p class="description"><?php esc_html_e( 'A path like /delivery-policy/ or a full URL. Leave blank to hide this one.', 'sd-shipping' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="faq_url"><?php esc_html_e( 'FAQ', 'sd-shipping' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="faq_url" name="faq_url" value="<?php echo esc_attr( $s['faq_url'] ); ?>">
						</td>
					</tr>
				</table>
			</div>

			<div class="sd-panel">
				<h2><?php esc_html_e( 'Size labels', 'sd-shipping' ); ?></h2>
				<p class="sd-note"><?php esc_html_e( 'Display only. These thresholds decide whether an item reads as Small, Medium or Large in the checkout breakdown and never change a price — pricing is the volume itself, continuously, with no bands.', 'sd-shipping' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="size_small_max"><?php esc_html_e( 'Small is under', 'sd-shipping' ); ?></label></th>
						<td><input type="number" step="0.01" min="0" class="small-text" id="size_small_max" name="size_small_max" value="<?php echo esc_attr( $s['size_small_max'] ); ?>"> m³</td>
					</tr>
					<tr>
						<th><label for="size_medium_max"><?php esc_html_e( 'Medium is under', 'sd-shipping' ); ?></label></th>
						<td>
							<input type="number" step="0.01" min="0" class="small-text" id="size_medium_max" name="size_medium_max" value="<?php echo esc_attr( $s['size_medium_max'] ); ?>"> m³
							<p class="description"><?php esc_html_e( 'Anything at or above this is Large.', 'sd-shipping' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<?php submit_button( __( 'Save settings', 'sd-shipping' ) ); ?>
		</form>

		<?php
		SD_Admin_Menu::footer();
	}

	/**
	 * The zone assignment panel: every WooCommerce shipping zone, what it
	 * already offers, and two checkboxes per zone for our own methods.
	 */
	protected static function render_zones_panel() {
		if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
			return;
		}

		$zones = WC_Shipping_Zones::get_zones();

		// Zone 0 — "Locations not covered by your other zones" — isn't in
		// get_zones(), but it's a real zone that real customers land in,
		// so it belongs in this list or addresses can silently fall
		// through with no Campos option at all.
		$rows   = [];
		foreach ( $zones as $zone_data ) {
			$rows[] = WC_Shipping_Zones::get_zone( (int) $zone_data['id'] );
		}
		$rows[] = WC_Shipping_Zones::get_zone( 0 );
		?>
		<div class="sd-panel">
			<h2><?php esc_html_e( 'Where these methods are offered', 'sd-shipping' ); ?></h2>
			<p class="sd-note">
				<?php esc_html_e( 'Tick a zone to offer a method to customers in it. Saving writes straight into WooCommerce\'s shipping zones, so there is no need to open WooCommerce > Settings > Shipping. Your own methods are listed under Standard Rates and can be switched on and off here too — unticking one disables it, it is never deleted, so its settings survive.', 'sd-shipping' ); ?>
			</p>
			<table class="widefat striped sd-zones">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Zone', 'sd-shipping' ); ?></th>
						<th><?php esc_html_e( 'Regions', 'sd-shipping' ); ?></th>
						<th><?php esc_html_e( 'Standard Rates already there', 'sd-shipping' ); ?></th>
						<th class="sd-check"><?php esc_html_e( 'Campos Delivery', 'sd-shipping' ); ?></th>
						<th class="sd-check"><?php esc_html_e( 'Request Quote', 'sd-shipping' ); ?></th>
						<th class="sd-check"><?php esc_html_e( '3BM', 'sd-shipping' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $rows as $zone ) :
					$zone_id  = $zone->get_id();
					$methods  = $zone->get_shipping_methods( false );
					$campos   = self::instances_of( $methods, self::CAMPOS_METHOD );
					$quote    = self::instances_of( $methods, self::QUOTE_METHOD );
					$tbm      = self::instances_of( $methods, self::TBM_METHOD );
					$standard = [];
					foreach ( $methods as $inst_id => $method ) {
						if ( in_array( $method->id, [ self::CAMPOS_METHOD, self::QUOTE_METHOD, self::TBM_METHOD ], true ) ) {
							continue;
						}
						$standard[ $inst_id ] = [
							'title'   => $method->get_title() ?: $method->get_method_title(),
							'enabled' => $method->enabled,
							'id'      => $method->id,
						];
					}
					$regions = $zone->get_formatted_location();
					?>
					<tr>
						<td><strong><?php echo esc_html( $zone->get_zone_name() ); ?></strong></td>
						<td class="sd-regions"><?php echo esc_html( $regions ? $regions : __( 'everywhere else', 'sd-shipping' ) ); ?></td>
						<td>
							<?php if ( $standard ) : ?>
								<?php foreach ( $standard as $inst_id => $std ) : ?>
									<label class="sd-std-toggle">
										<input type="checkbox" name="sd_std[<?php echo esc_attr( $inst_id ); ?>]" value="1"
											<?php checked( 'yes' === $std['enabled'] ); ?>>
										<span class="sd-pill<?php echo 'yes' === $std['enabled'] ? '' : ' sd-pill-off'; ?>">
											<?php echo esc_html( $std['title'] ); ?>
										</span>
									</label>
								<?php endforeach; ?>
							<?php else : ?>
								<span class="sd-dim-none"><?php esc_html_e( 'none', 'sd-shipping' ); ?></span>
							<?php endif; ?>
						</td>
						<td class="sd-check">
							<input type="checkbox" name="sd_zone_campos[<?php echo esc_attr( $zone_id ); ?>]" value="1"
								<?php checked( self::any_enabled( $campos ) ); ?>>
						</td>
						<td class="sd-check">
							<input type="checkbox" name="sd_zone_quote[<?php echo esc_attr( $zone_id ); ?>]" value="1"
								<?php checked( self::any_enabled( $quote ) ); ?>>
						</td>
						<td class="sd-check">
							<input type="checkbox" name="sd_zone_tbm[<?php echo esc_attr( $zone_id ); ?>]" value="1"
								<?php checked( self::any_enabled( $tbm ) ); ?>>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="sd-note">
				<?php esc_html_e( 'WooCommerce matches the first zone whose regions fit the customer\'s address and stops there — so a zone left unticked will never show these options, even if a broader zone below it has them ticked.', 'sd-shipping' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * @param WC_Shipping_Method[] $methods
	 * @param string               $method_id
	 * @return WC_Shipping_Method[] keyed by instance id
	 */
	protected static function instances_of( $methods, $method_id ) {
		$found = [];
		foreach ( $methods as $instance_id => $method ) {
			if ( $method->id === $method_id ) {
				$found[ $instance_id ] = $method;
			}
		}
		return $found;
	}

	/**
	 * @param WC_Shipping_Method[] $instances
	 * @return bool
	 */
	protected static function any_enabled( $instances ) {
		foreach ( $instances as $method ) {
			if ( 'yes' === $method->enabled ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * admin_post_sd_save_settings — save the option array, then reconcile
	 * zone assignments.
	 */
	public static function handle_save() {
		SD_Admin_Menu::require_access( 'sd_save_settings' );

		SD_Settings::save( SD_Settings::sanitise( $_POST ) );

		$zones_changed = self::sync_zones(
			isset( $_POST['sd_zone_campos'] ) && is_array( $_POST['sd_zone_campos'] ) ? array_keys( $_POST['sd_zone_campos'] ) : [],
			isset( $_POST['sd_zone_quote'] ) && is_array( $_POST['sd_zone_quote'] ) ? array_keys( $_POST['sd_zone_quote'] ) : [],
			isset( $_POST['sd_zone_tbm'] ) && is_array( $_POST['sd_zone_tbm'] ) ? array_keys( $_POST['sd_zone_tbm'] ) : []
		);

		// Standard Rates: the merchant's own methods, switched on or off.
		$std_changed = self::sync_standard_rates(
			isset( $_POST['sd_std'] ) && is_array( $_POST['sd_std'] ) ? array_keys( $_POST['sd_std'] ) : []
		);
		$zones_changed = $zones_changed || $std_changed;

		$args = [ 'sd_saved' => '1' ];
		if ( $zones_changed ) {
			$args['sd_zones'] = '1';
		}

		wp_safe_redirect( add_query_arg( $args, SD_Admin_Menu::page_url( '-settings' ) ) );
		exit;
	}

	/**
	 * Add, enable, or disable our two methods across every zone so the
	 * live configuration matches the checkboxes that were just submitted.
	 *
	 * Unticking DISABLES the method instance rather than deleting it, so
	 * the Title typed into it (and any other instance setting) survives
	 * being switched off and back on. Deleting would silently discard
	 * that, which is a nasty surprise for a checkbox.
	 *
	 * @param array $campos_zone_ids Zone ids that should offer Campos Delivery.
	 * @param array $quote_zone_ids  Zone ids that should offer Request Quote.
	 * @return bool Whether anything actually changed.
	 */
	protected static function sync_zones( $campos_zone_ids, $quote_zone_ids, $tbm_zone_ids = [] ) {
		if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
			return false;
		}

		$campos_wanted = array_map( 'absint', $campos_zone_ids );
		$quote_wanted  = array_map( 'absint', $quote_zone_ids );
		$tbm_wanted    = array_map( 'absint', $tbm_zone_ids );

		$zone_ids = [ 0 ];
		foreach ( WC_Shipping_Zones::get_zones() as $zone_data ) {
			$zone_ids[] = (int) $zone_data['id'];
		}

		$changed = false;

		foreach ( $zone_ids as $zone_id ) {
			$zone = WC_Shipping_Zones::get_zone( $zone_id );
			if ( ! $zone ) {
				continue;
			}

			$map = [
				self::CAMPOS_METHOD => in_array( $zone_id, $campos_wanted, true ),
				self::QUOTE_METHOD  => in_array( $zone_id, $quote_wanted, true ),
				self::TBM_METHOD    => in_array( $zone_id, $tbm_wanted, true ),
			];

			foreach ( $map as $method_id => $wanted ) {
				$instances = self::instances_of( $zone->get_shipping_methods( false ), $method_id );

				if ( $wanted && empty( $instances ) ) {
					$zone->add_shipping_method( $method_id );
					$changed = true;
					continue;
				}

				foreach ( $instances as $instance_id => $method ) {
					$is_enabled = 'yes' === $method->enabled;
					if ( $wanted === $is_enabled ) {
						continue;
					}
					self::set_instance_enabled( $instance_id, $method_id, $wanted );
					$changed = true;
				}
			}
		}

		if ( $changed && class_exists( 'WC_Cache_Helper' ) ) {
			// Cached shipping rates are keyed by this version string;
			// bumping it is how WooCommerce's own zone screen makes a
			// method change show up at checkout immediately.
			WC_Cache_Helper::get_transient_version( 'shipping', true );
		}

		return $changed;
	}

	/**
	 * Flip one zone-method instance on or off.
	 *
	 * Writes both places WooCommerce looks: the `is_enabled` column on the
	 * zone-methods table (what WC_Shipping_Zone::get_shipping_methods()
	 * reads, and what WC's own UI toggle updates), and the instance's own
	 * settings option (what the method reads when constructed outside a
	 * zone context). Keeping them in step avoids a method that looks
	 * enabled on one screen and disabled on another.
	 *
	 * @param int    $instance_id
	 * @param string $method_id
	 * @param bool   $enabled
	 */
	protected static function set_instance_enabled( $instance_id, $method_id, $enabled ) {
		global $wpdb;

		$wpdb->update(
			"{$wpdb->prefix}woocommerce_shipping_zone_methods",
			[ 'is_enabled' => $enabled ? 1 : 0 ],
			[ 'instance_id' => absint( $instance_id ) ],
			[ '%d' ],
			[ '%d' ]
		);

		$option           = 'woocommerce_' . $method_id . '_' . absint( $instance_id ) . '_settings';
		$settings         = get_option( $option, [] );
		$settings         = is_array( $settings ) ? $settings : [];
		$settings['enabled'] = $enabled ? 'yes' : 'no';
		update_option( $option, $settings );
	}

	/**
	 * The 3BM panel: commercial terms, the depot, and a distance tester.
	 *
	 * The tester exists because everything here rests on an approximation
	 * — straight-line distance between suburb centroids, scaled by a road
	 * factor. An approximation nobody can check is just a guess, so this
	 * lets you type a town and see the exact km and price a customer would
	 * get, computed by the same functions checkout uses.
	 *
	 * @param array $s current settings
	 */
	protected static function render_tbm_panel( $s ) {
		$test_town   = isset( $_GET['sd_test_town'] ) ? sanitize_text_field( wp_unslash( $_GET['sd_test_town'] ) ) : '';
		$test_result = null;

		if ( '' !== $test_town && class_exists( 'SD_Geo' ) ) {
			$distance = SD_Geo::distance_to( $test_town );
			if ( null === $distance ) {
				$test_result = [ 'error' => __( 'That town is not in the coordinate table, so 3BM would not be offered for it. Add it with the sd_geo_coordinates filter, or check the spelling.', 'sd-shipping' ) ];
			} else {
				$priced      = WC_Shipping_SD_3BM::price(
					$distance['km'],
					(float) $s['tbm_rate_per_km'],
					'yes' === $s['tbm_round_trip'],
					(float) $s['tbm_min_charge'],
					SD_Settings::vat_fraction()
				);
				$radius      = (float) $s['tbm_radius_km'];
				$test_result = [
					'distance' => $distance,
					'priced'   => $priced,
					'outside'  => ( $radius > 0 && $distance['km'] > $radius ),
				];
			}
		}

		$unmatched = class_exists( 'SD_Geo' ) ? SD_Geo::unmatched() : [];
		?>
		<div class="sd-panel">
			<h2><?php esc_html_e( '3BM Delivery (distance-based)', 'sd-shipping' ); ?></h2>
			<p class="sd-note">
				<?php esc_html_e( 'Priced per kilometre from the 3BM depot rather than by volume. Distance is a straight line between suburb centroids, scaled by the road factor below — it is an estimate, not a routed distance, so use the tester before trusting it commercially.', 'sd-shipping' ); ?>
			</p>

			<?php if ( 'yes' !== $s['tbm_enabled'] ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php esc_html_e( '3BM Delivery is switched off and will not appear at checkout. It ships disabled on purpose: confirm the depot coordinate below against a map first, since every price depends on it.', 'sd-shipping' ); ?>
				</p></div>
			<?php endif; ?>

			<table class="form-table" role="presentation">
				<tr>
					<th><?php esc_html_e( 'Enable', 'sd-shipping' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="tbm_enabled" value="yes" <?php checked( 'yes', $s['tbm_enabled'] ); ?>>
							<?php esc_html_e( 'Offer 3BM Delivery at checkout', 'sd-shipping' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'It also has to be ticked for a zone in the table above.', 'sd-shipping' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="tbm_rate_per_km"><?php esc_html_e( 'Rate per kilometre', 'sd-shipping' ); ?></label></th>
					<td>R <input type="number" step="0.01" min="0" class="small-text" id="tbm_rate_per_km" name="tbm_rate_per_km" value="<?php echo esc_attr( $s['tbm_rate_per_km'] ); ?>"></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Charge on', 'sd-shipping' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="tbm_round_trip" value="yes" <?php checked( 'yes', $s['tbm_round_trip'] ); ?>>
							<?php esc_html_e( 'The round trip (distance x 2)', 'sd-shipping' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Matches how the Campos rate card treats outlying trips. Unticked bills the one-way distance only.', 'sd-shipping' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="tbm_radius_km"><?php esc_html_e( 'Service radius', 'sd-shipping' ); ?></label></th>
					<td>
						<input type="number" step="1" min="0" class="small-text" id="tbm_radius_km" name="tbm_radius_km" value="<?php echo esc_attr( $s['tbm_radius_km'] ); ?>"> km
						<p class="description"><?php esc_html_e( 'Beyond this the method is simply not offered. 0 removes the limit.', 'sd-shipping' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="tbm_road_factor"><?php esc_html_e( 'Road factor', 'sd-shipping' ); ?></label></th>
					<td>
						<input type="number" step="0.01" min="1" max="3" class="small-text" id="tbm_road_factor" name="tbm_road_factor" value="<?php echo esc_attr( $s['tbm_road_factor'] ); ?>">
						<p class="description"><?php esc_html_e( 'Roads are longer than straight lines. 1.25 adds 25%, which is a reasonable starting point for Gauteng — compare a few real trips against the tester below and tune it.', 'sd-shipping' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="tbm_min_charge"><?php esc_html_e( 'Minimum charge', 'sd-shipping' ); ?></label></th>
					<td>
						R <input type="number" step="0.01" min="0" class="small-text" id="tbm_min_charge" name="tbm_min_charge" value="<?php echo esc_attr( $s['tbm_min_charge'] ); ?>">
						<p class="description"><?php esc_html_e( 'Excluding VAT, applied before it. 0 means no floor, so a very short trip bills only its kilometres.', 'sd-shipping' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Gauteng only', 'sd-shipping' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="tbm_gauteng_only" value="yes" <?php checked( 'yes', $s['tbm_gauteng_only'] ); ?>>
							<?php esc_html_e( 'Only offer 3BM when the delivery province is Gauteng', 'sd-shipping' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'With this off, any address inside the radius qualifies — which reaches into North West, Mpumalanga and the northern Free State.', 'sd-shipping' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Depot coordinate', 'sd-shipping' ); ?></th>
					<td>
						<label><?php esc_html_e( 'Latitude', 'sd-shipping' ); ?>
							<input type="text" class="small-text" name="tbm_origin_lat" value="<?php echo esc_attr( $s['tbm_origin_lat'] ); ?>">
						</label>
						<label style="margin-left:12px;"><?php esc_html_e( 'Longitude', 'sd-shipping' ); ?>
							<input type="text" class="small-text" name="tbm_origin_lng" value="<?php echo esc_attr( $s['tbm_origin_lng'] ); ?>">
						</label>
						<p class="description">
							<?php esc_html_e( 'Currently set to an approximation of 320 The Hillside Road, Lynnwood, Pretoria, 0181 — not a surveyed point. Right-click the address in Google Maps, copy the coordinates, and paste them here. Latitude is negative in South Africa.', 'sd-shipping' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<h3><?php esc_html_e( 'Test a distance', 'sd-shipping' ); ?></h3>
			<p class="sd-note"><?php esc_html_e( 'Uses the saved settings, so save any changes above first.', 'sd-shipping' ); ?></p>
			<p>
				<input type="text" id="sd_test_town" class="regular-text" value="<?php echo esc_attr( $test_town ); ?>"
					placeholder="<?php esc_attr_e( 'e.g. Sandton', 'sd-shipping' ); ?>">
				<a class="button" id="sd_test_go" href="#"><?php esc_html_e( 'Check', 'sd-shipping' ); ?></a>
			</p>
			<script>
			( function () {
				var go = document.getElementById( 'sd_test_go' );
				var box = document.getElementById( 'sd_test_town' );
				if ( ! go || ! box ) { return; }
				function run() {
					var base = <?php echo wp_json_encode( SD_Admin_Menu::page_url( '-settings' ) ); ?>;
					window.location = base + '&sd_test_town=' + encodeURIComponent( box.value );
				}
				go.addEventListener( 'click', function ( e ) { e.preventDefault(); run(); } );
				box.addEventListener( 'keydown', function ( e ) { if ( 13 === e.keyCode ) { e.preventDefault(); run(); } } );
			} )();
			</script>

			<?php if ( is_array( $test_result ) && isset( $test_result['error'] ) ) : ?>
				<div class="notice notice-error inline"><p><?php echo esc_html( $test_result['error'] ); ?></p></div>
			<?php elseif ( is_array( $test_result ) ) : ?>
				<table class="widefat striped" style="max-width:620px;">
					<tbody>
						<tr><th><?php esc_html_e( 'Town', 'sd-shipping' ); ?></th><td><?php echo esc_html( $test_town ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Straight line', 'sd-shipping' ); ?></th><td><?php echo esc_html( number_format( $test_result['distance']['straight_km'], 2 ) ); ?> km</td></tr>
						<tr><th><?php esc_html_e( 'After road factor', 'sd-shipping' ); ?></th><td><?php echo esc_html( number_format( $test_result['distance']['km'], 2 ) ); ?> km</td></tr>
						<tr><th><?php esc_html_e( 'Billable distance', 'sd-shipping' ); ?></th><td><?php echo esc_html( number_format( $test_result['priced']['billable_km'], 2 ) ); ?> km</td></tr>
						<tr><th><?php esc_html_e( 'Charge excl. VAT', 'sd-shipping' ); ?></th><td>R <?php echo esc_html( number_format( $test_result['priced']['charge'], 2 ) ); ?><?php echo $test_result['priced']['floored'] ? ' <em>' . esc_html__( '(minimum applied)', 'sd-shipping' ) . '</em>' : ''; ?></td></tr>
						<tr><th><?php esc_html_e( 'VAT', 'sd-shipping' ); ?></th><td>R <?php echo esc_html( number_format( $test_result['priced']['vat'], 2 ) ); ?></td></tr>
						<tr><th><strong><?php esc_html_e( 'Customer pays', 'sd-shipping' ); ?></strong></th><td><strong>R <?php echo esc_html( number_format( $test_result['priced']['total'], 2 ) ); ?></strong></td></tr>
					</tbody>
				</table>
				<?php if ( $test_result['outside'] ) : ?>
					<div class="notice notice-warning inline"><p>
						<?php esc_html_e( 'This is outside the service radius, so 3BM would not actually be offered — the price above is what it would have been.', 'sd-shipping' ); ?>
					</p></div>
				<?php endif; ?>
			<?php endif; ?>

			<?php if ( $unmatched ) : ?>
				<h3><?php esc_html_e( 'Towns customers entered that are not in the table', 'sd-shipping' ); ?></h3>
				<p class="sd-note"><?php esc_html_e( 'Each of these was a checkout where 3BM could not be offered because the town could not be placed. Worth adding the ones that recur.', 'sd-shipping' ); ?></p>
				<table class="widefat striped" style="max-width:420px;">
					<thead><tr><th><?php esc_html_e( 'Town', 'sd-shipping' ); ?></th><th><?php esc_html_e( 'Times', 'sd-shipping' ); ?></th></tr></thead>
					<tbody>
					<?php arsort( $unmatched ); foreach ( $unmatched as $town => $count ) : ?>
						<tr><td><?php echo esc_html( $town ); ?></td><td><?php echo esc_html( $count ); ?></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Switch the merchant's own shipping methods on or off to match the
	 * Standard Rates checkboxes.
	 *
	 * Only ever toggles is_enabled — it never adds, deletes, reprices or
	 * reorders one of their methods. Anything not shown as a checkbox on
	 * that screen is untouched, and our own three methods are excluded
	 * here because the zone columns already own them.
	 *
	 * @param array $enabled_instance_ids instance ids that should be on
	 * @return bool whether anything changed
	 */
	protected static function sync_standard_rates( $enabled_instance_ids ) {
		if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
			return false;
		}

		$wanted  = array_map( 'absint', $enabled_instance_ids );
		$ours    = [ self::CAMPOS_METHOD, self::QUOTE_METHOD, self::TBM_METHOD ];
		$changed = false;

		$zone_ids = [ 0 ];
		foreach ( WC_Shipping_Zones::get_zones() as $zone_data ) {
			$zone_ids[] = (int) $zone_data['id'];
		}

		foreach ( $zone_ids as $zone_id ) {
			$zone = WC_Shipping_Zones::get_zone( $zone_id );
			if ( ! $zone ) {
				continue;
			}

			foreach ( $zone->get_shipping_methods( false ) as $instance_id => $method ) {
				if ( in_array( $method->id, $ours, true ) ) {
					continue;
				}
				$should_be = in_array( (int) $instance_id, $wanted, true );
				$is_now    = 'yes' === $method->enabled;
				if ( $should_be === $is_now ) {
					continue;
				}
				self::set_instance_enabled( $instance_id, $method->id, $should_be );
				$changed = true;
			}
		}

		if ( $changed && class_exists( 'WC_Cache_Helper' ) ) {
			WC_Cache_Helper::get_transient_version( 'shipping', true );
		}

		return $changed;
	}
}
