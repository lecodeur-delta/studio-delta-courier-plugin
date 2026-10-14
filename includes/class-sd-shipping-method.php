<?php
/**
 * "Campos Delivery". Registered in studio-delta-shipping.php via the
 * woocommerce_shipping_methods filter, so WooCommerce offers it under
 * WooCommerce > Settings > Shipping > (a zone) > Add shipping method.
 *
 * The CBM x corridor-rate formula matching the courier's commercial rate
 * card. As of v0.6.0 it covers the WHOLE catalogue rather than only bulky
 * goods, with one exception: the four outlying corridors (Hoedspruit,
 * Komatipoort, Mthatha, Hermanus) are special trips quoted by hand, so
 * this method stands aside there and Request a Quote takes the order.
 *
 * The corridor minimum is applied PER ITEM by default, because that is
 * how Campos invoices a multi-piece order - see
 * SD_Pricing::calculate_items() for the invoice evidence. It sits
 * alongside Standard Rates (the merchant's own untouched WooCommerce
 * methods), Request Quote and 3BM Delivery; the doc set covers how the
 * four coexist.
 *
 * Renamed from WC_Shipping_StudioDelta / id "sd_zone_delivery" in v0.3.0.
 * If an earlier version of this method was already added to a zone,
 * remove it and add "Campos Delivery" in its place - the id change means
 * WooCommerce treats it as a different method, not an upgrade in place.
 */

defined( 'ABSPATH' ) || exit;

class WC_Shipping_SD_Campos extends WC_Shipping_Method {

	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'sd_campos_delivery';
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'Campos Delivery (Studio Delta)', 'sd-shipping' );
		$this->method_description = __( 'Volume-based delivery for the whole catalogue: each item priced on its own cubic volume against the corridor rate, with the minimum charge applied per item, then the monthly fuel surcharge and VAT. South Africa only, and not offered for outlying areas, which are quoted by hand.', 'sd-shipping' );
		$this->supports           = [ 'shipping-zones', 'instance-settings' ];

		$this->init();
	}

	public function init() {
		$this->init_form_fields();
		$this->init_settings();

		$this->enabled = $this->get_option( 'enabled', 'yes' );
		$this->title   = $this->get_option( 'title', __( 'Campos Delivery', 'sd-shipping' ) );

		add_action( 'woocommerce_update_options_shipping_' . $this->id, [ $this, 'process_admin_options' ] );
	}

	public function init_form_fields() {
		$this->instance_form_fields = [
			'enabled'         => [
				'title'   => __( 'Enable', 'sd-shipping' ),
				'type'    => 'checkbox',
				'default' => 'yes',
			],
			'title'           => [
				'title'       => __( 'Title', 'sd-shipping' ),
				'type'        => 'text',
				'description' => __( 'Fallback only. The label customers see, and the note beside it, are set at Studio Delta > Checkout Text.', 'sd-shipping' ),
				'default'     => __( 'Campos Delivery', 'sd-shipping' ),
			],
			// Read-only - this is NOT where the levy is set. It exists so
			// whoever is on this screen (the natural first place to look
			// for "where do I change the delivery price") is pointed
			// straight at the actual manual entry screen instead of
			// hunting for it, without creating a second place that writes
			// the value (that would defeat the whole point of §6's dated,
			// never-overwritten history table).
			'fuel_levy_info' => [
				'title'       => __( 'Current fuel levy', 'sd-shipping' ),
				'type'        => 'title',
				'description' => $this->fuel_levy_info_text(),
			],
		];
	}

	/**
	 * Builds the description shown under "Current fuel levy" above: the
	 * live national percentage plus a direct link to the screen where
	 * it's actually changed (Studio Delta > Fuel Surcharge in the sidebar
	 * - see includes/admin/page-fuel-surcharge.php, §6/§14).
	 *
	 * @return string
	 */
	protected function fuel_levy_info_text() {
		if ( ! class_exists( 'SD_Fuel_Levy' ) ) {
			return __( 'Not available yet - save this page once, then reload it.', 'sd-shipping' );
		}
		$current = SD_Fuel_Levy::get_current();

		// Studio Delta > Fuel Surcharge. Spelled out rather than asking
		// SD_Admin_Menu, because that class only loads on admin requests
		// and this method must not depend on load order.
		$link = admin_url( 'admin.php?page=sd-delivery-fuel' );

		if ( null === $current ) {
			return sprintf(
				/* translators: %s: URL of the fuel levy settings screen */
				__( 'No levy has been set yet. <a href="%s">Set it here</a> before going live - every zone quote will be wrong until you do.', 'sd-shipping' ),
				esc_url( $link )
			);
		}

		return sprintf(
			/* translators: 1: current percentage, 2: URL of the fuel levy settings screen */
			__( '%1$s%%, applied to every zone unless a zone-specific override exists. This field is read-only - <a href="%2$s">change the value here</a> so the change stays in the dated history, not silently overwritten.', 'sd-shipping' ),
			esc_html( rtrim( rtrim( number_format( $current * 100, 2 ), '0' ), '.' ) ),
			esc_url( $link )
		);
	}

	/**
	 * The formula, per item by default:
	 *   per unit:  MAX( cbm x rate_per_m3, min_charge )
	 *   charge   = SUM( per unit x quantity ) across every cart line
	 *   fuel     = charge x fuel_levy_pct( zone, today )
	 *   subtotal = charge + fuel
	 *   total    = subtotal + VAT
	 *
	 * In per_order mode the minimum applies once to the whole cart
	 * instead: charge = MAX( cbm_total x rate_per_m3, min_charge ).
	 *
	 * @param array $package WooCommerce cart package.
	 */
	public function calculate_shipping( $package = [] ) {

		// South Africa only - see the doc set §13. The zone-corridor table
		// (class-sd-zones.php) only covers SA towns anyway, so this mostly
		// guards against an international address matching nothing and
		// silently falling through; it also keeps this method out of the
		// rates list entirely for a non-SA destination rather than showing
		// a "couldn't match your address" notice that isn't really true.
		if ( ! $this->is_south_africa( $package ) ) {
			return;
		}

		$breakdown = $this->build_cart_breakdown( $package );
		$cbm_total = $breakdown['cbm_total'];
		$zone_row  = SD_Zones::resolve( $package['destination'] );

		if ( null === $zone_row ) {
			// No corridor match. Until depot coordinates exist (see docs,
			// "Open questions", #4) we can't compute the km x 2 x 28.5
			// outlying rate, so don't offer a rate at all - a missing
			// shipping option at checkout is a far safer failure than a
			// silently wrong price. Whether to explain that to the
			// customer is configurable (Studio Delta > Settings): on a
			// zone that also offers Request Quote, the notice can be
			// redundant noise, since they already have a way forward.
			if ( 'silent' !== SD_Settings::get( 'unmatched_behaviour' ) ) {
				if ( SD_Text::has( 'campos_unmatched' ) ) {
					wc_add_notice( SD_Text::get( 'campos_unmatched' ), 'notice' );
				}
			}
			return;
		}

		// Outlying corridors (Hoedspruit, Komatipoort, Mthatha, Hermanus)
		// are special trips priced far above their zone's normal rate.
		// Campos covers everything else in the catalogue, but these are
		// quoted by hand rather than priced automatically, so this method
		// stands aside and Request a Quote takes the order.
		if ( ! empty( $zone_row['remote_override'] ) && SD_Settings::is_on( 'campos_skip_outlying' ) ) {
			return;
		}

		$fuel_pct = SD_Fuel_Levy::get_current( $zone_row['zone'] );
		if ( null === $fuel_pct ) {
			// Table exists but was never seeded for this zone or globally -
			// a configuration bug, not a zero-levy month. Fail loudly in
			// the log rather than under-charging every order.
			wc_get_logger()->error( 'SD_Fuel_Levy::get_current() returned null - has the levy ever been set?', [ 'source' => 'sd-shipping' ] );
			return;
		}

		// One formula, one home: SD_Pricing is what the Product Rates
		// admin screen and the CSV rate matrix call too, so none of them
		// can drift from what is charged here.
		//
		// Two ways to apply the corridor minimum. per_item is the default
		// and matches how Campos invoices a multi-piece order: each item
		// carries the minimum in its own right. per_order applies one
		// minimum to the whole delivery. See SD_Pricing for the invoice
		// evidence behind that choice.
		if ( 'per_order' === SD_Settings::get( 'campos_charge_basis' ) ) {
			$priced = SD_Pricing::calculate( $cbm_total, $zone_row, $fuel_pct );
		} else {
			$priced = SD_Pricing::calculate_items(
				array_map(
					static function ( $i ) {
						return [
							'cbm'  => (float) ( $i['cbm_each'] ?? 0 ),
							'qty'  => (int) $i['qty'],
							'name' => $i['name'],
						];
					},
					$breakdown['items']
				),
				$zone_row,
				$fuel_pct
			);

			// Safety net. Per-item pricing depends on every cart line
			// carrying a usable quantity; if that ever stops being true
			// (a rename in build_cart_breakdown(), a third-party filter
			// rewriting the cart) the sum would come out at zero and this
			// method would quote free delivery on a real order. Falling
			// back to the whole-cart calculation makes that failure mode a
			// slightly-too-low price instead of no price at all.
			if ( $priced['charge'] <= 0 && $cbm_total > 0 ) {
				$priced = SD_Pricing::calculate( $cbm_total, $zone_row, $fuel_pct );
				wc_get_logger()->warning(
					'Campos per-item pricing produced a zero charge for a non-empty cart; fell back to per-order.',
					[ 'source' => 'sd-shipping' ]
				);
			}
		}
		$charge      = $priced['charge'];
		$fuel_amount = $priced['fuel_amount'];
		$vat_amount  = $priced['vat_amount'];
		$total       = $priced['total'];

		// Everything the checkout breakdown (class-sd-checkout-display.php)
		// needs to render a human-readable line-by-line explanation,
		// carried as rate meta_data so it also survives onto the order
		// once placed (visible in wp-admin and order emails for free).
		$breakdown_meta = [
			'zone_name'   => $zone_row['zone_name'],
			'rate_per_m3' => $zone_row['rate_per_m3'],
			'min_charge'  => $zone_row['min_charge'],
			'cbm_total'   => wc_format_decimal( $cbm_total, 4 ),
			// The volume price before the minimum was applied, whether
			// the minimum ended up doing the work, and how much volume
			// this delivery covers for that money. Together these let the
			// checkout explain why a second item didn't change the price.
			'raw_charge'  => wc_format_decimal( $priced['raw_charge'], 2 ),
			'floored'     => $priced['floored'],
			'cbm_at_min'  => $priced['cbm_at_min'],
			'basis'       => $priced['basis'],
			// Per-item mode reports what each line contributed, so the
			// checkout can show the addition rather than one opaque total.
			'lines'       => $priced['lines'] ?? [],
			'charge'      => wc_format_decimal( $charge, 2 ),
			'fuel_pct'    => $fuel_pct,
			'fuel_amount' => wc_format_decimal( $fuel_amount, 2 ),
			'vat_amount'  => wc_format_decimal( $vat_amount, 2 ),
			'total'       => wc_format_decimal( $total, 2 ),
			'items'       => $breakdown['items'],
		];

		$this->add_rate(
			[
				'id'        => $this->get_rate_id() . ':' . $zone_row['zone'],
				// Wording from Studio Delta > Checkout Text; the instance
				// Title is only a fallback if that is ever blank.
				'label'     => self::label_for( $this->title, $zone_row['zone_name'] ),
				'cost'      => $total,
				'package'   => $package,
				'meta_data' => [
					// Flat scalar fields, kept for backwards compatibility
					// with anything already reading these directly.
					'sd_cbm_total' => $cbm_total,
					'sd_zone'      => $zone_row['zone_name'],
					'sd_charge'    => wc_format_decimal( $charge, 2 ),
					'sd_fuel_pct'  => $fuel_pct,
					'sd_fuel_amt'  => wc_format_decimal( $fuel_amount, 2 ),
					// The full breakdown, JSON-encoded, for the checkout
					// display and for anyone auditing an order later.
					'sd_breakdown' => wp_json_encode( $breakdown_meta ),
				],
			]
		);
	}

	/**
	 * @param string $title instance Title, the fallback
	 * @param string $zone  corridor zone name
	 * @return string
	 */
	public static function label_for( $title, $zone ) {
		$label = class_exists( 'SD_Text' ) ? SD_Text::get( 'campos_label', [ 'zone' => $zone ] ) : '';
		return '' !== trim( $label ) ? $label : sprintf( '%s - %s', $title, $zone );
	}

	/**
	 * Build a per-item CBM breakdown for the cart, using SD_CBM_Resolver
	 * so items without a manually-measured _sd_cbm still contribute their
	 * volume when a dimension triple can be parsed out of the product's
	 * description text, instead of silently pricing as zero.
	 *
	 * @param array $package
	 * @return array{cbm_total: float, items: array}
	 */
	protected function build_cart_breakdown( $package ) {
		$total = 0.0;
		$items = [];

		foreach ( $package['contents'] as $item ) {
			$product = $item['data'];
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$qty        = (int) $item['quantity'];
			$resolved   = SD_CBM_Resolver::resolve( $product );
			$cbm_each   = $resolved['cbm'] ?? 0.0;
			$total     += $cbm_each * $qty;

			$size_class = SD_CBM_Resolver::size_class( $resolved['cbm'] );
			$wc_class   = SD_CBM_Resolver::wc_shipping_class( $product );
			$wc_norm    = SD_CBM_Resolver::normalise_class( $wc_class );

			$items[] = [
				'name'          => $product->get_name(),
				'qty'           => $qty,
				'cbm_each'      => $cbm_each ? wc_format_decimal( $cbm_each, 4 ) : null,
				// cbm_each x qty, so a multi-quantity line shows its own
				// contribution to the cart total rather than leaving the
				// customer to multiply it themselves.
				'cbm_line'      => $cbm_each ? wc_format_decimal( $cbm_each * $qty, 4 ) : null,
				'cbm_source'    => $resolved['source'],
				// CBM-derived size - what actually prices the item.
				'size_class'    => $size_class,
				// The product's real WooCommerce Shipping Class - shown
				// alongside, never used to price (see class-sd-cbm-resolver.php).
				'wc_class'      => $wc_class,
				// true/false only when both sides normalise to a
				// recognised Small/Medium/Large; null when there's
				// nothing to compare (no WC class, or an unrecognised
				// class name) - a null here is not a clean bill of
				// health, just "nothing to flag automatically."
				'class_mismatch' => ( null !== $wc_norm && null !== $size_class )
					? ( $wc_norm !== $size_class )
					: null,
			];
		}

		return [ 'cbm_total' => $total, 'items' => $items ];
	}

	/**
	 * True when this package may be priced by this method: a South
	 * African destination, or any destination if the South-Africa-only
	 * guard has been switched off in Studio Delta > Settings.
	 *
	 * @param array $package
	 * @return bool
	 */
	protected function is_south_africa( $package ) {
		if ( class_exists( 'SD_Settings' ) && ! SD_Settings::is_on( 'za_only' ) ) {
			return true;
		}
		$country = $package['destination']['country'] ?? '';
		return 'ZA' === $country;
	}
}
