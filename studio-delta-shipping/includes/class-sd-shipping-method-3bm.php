<?php
/**
 * "3BM Delivery" — Mode 4. Gauteng only, priced by distance rather than
 * volume: R5 per kilometre, round trip, from the depot at 320 The
 * Hillside Road, Lynnwood, Pretoria, within a 150km radius.
 *
 * Every one of those numbers is a setting (Studio Delta > Settings), not
 * a constant, because they are commercial terms that will change without
 * a code deploy — the same reasoning that put the fuel levy in a table.
 *
 * The distance itself comes from SD_Geo: a straight line between suburb
 * centroids, scaled by a road factor. Read that file's header before
 * trusting a price from this method — the approximation is deliberate
 * and its limits matter.
 *
 * Unlike Campos Delivery this method has NO fuel levy and no CBM: it
 * doesn't care what is in the cart, only how far away the customer is.
 * VAT still applies, from the same shared setting.
 *
 * It is disabled by default (tbm_enabled = no) so that installing this
 * version cannot start quoting distance prices before the depot
 * coordinate has been confirmed on a map.
 */

defined( 'ABSPATH' ) || exit;

class WC_Shipping_SD_3BM extends WC_Shipping_Method {

	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'sd_3bm_delivery';
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( '3BM Delivery (Studio Delta)', 'sd-shipping' );
		$this->method_description = __( 'Distance-based delivery for Gauteng and its surrounds: a per-kilometre rate from the 3BM depot, within a set radius. Rate, radius and depot are configured under Studio Delta > Settings.', 'sd-shipping' );
		$this->supports           = [ 'shipping-zones', 'instance-settings' ];

		$this->init();
	}

	public function init() {
		$this->init_form_fields();
		$this->init_settings();

		$this->enabled = $this->get_option( 'enabled', 'yes' );
		$this->title   = $this->get_option( 'title', __( '3BM Delivery', 'sd-shipping' ) );

		add_action( 'woocommerce_update_options_shipping_' . $this->id, [ $this, 'process_admin_options' ] );
	}

	public function init_form_fields() {
		$this->instance_form_fields = [
			'enabled' => [
				'title'   => __( 'Enable', 'sd-shipping' ),
				'type'    => 'checkbox',
				'default' => 'yes',
			],
			'title'   => [
				'title'       => __( 'Title', 'sd-shipping' ),
				'type'        => 'text',
				'description' => __( 'Shown to the customer at checkout. The distance is appended automatically.', 'sd-shipping' ),
				'default'     => __( '3BM Delivery', 'sd-shipping' ),
			],
			'info'    => [
				'title'       => __( 'How this is priced', 'sd-shipping' ),
				'type'        => 'title',
				'description' => $this->settings_summary(),
			],
		];
	}

	/**
	 * A plain-language summary of the live configuration, so whoever
	 * opens this screen sees the actual terms rather than having to go
	 * and look them up.
	 *
	 * @return string
	 */
	protected function settings_summary() {
		if ( ! class_exists( 'SD_Settings' ) ) {
			return __( 'Not available yet — save this page once, then reload it.', 'sd-shipping' );
		}

		$link = admin_url( 'admin.php?page=sd-delivery-settings' );

		if ( ! SD_Settings::is_on( 'tbm_enabled' ) ) {
			return sprintf(
				/* translators: %s: settings screen URL */
				__( '<strong>3BM Delivery is currently switched off</strong> and will not appear at checkout, whatever this instance says. <a href="%s">Turn it on and confirm the depot coordinate</a> first — it ships disabled so it cannot quote a distance price before someone has checked the depot is in the right place.', 'sd-shipping' ),
				esc_url( $link )
			);
		}

		return sprintf(
			/* translators: 1: rate per km, 2: one-way or round trip, 3: radius, 4: road factor, 5: settings URL */
			__( 'R%1$s per kilometre, %2$s, within %3$skm of the depot. Straight-line distance scaled by a road factor of %4$s. <a href="%5$s">Change any of this</a>.', 'sd-shipping' ),
			esc_html( number_format( (float) SD_Settings::get( 'tbm_rate_per_km' ), 2 ) ),
			SD_Settings::is_on( 'tbm_round_trip' ) ? esc_html__( 'charged on the round trip', 'sd-shipping' ) : esc_html__( 'charged one way', 'sd-shipping' ),
			esc_html( (float) SD_Settings::get( 'tbm_radius_km' ) ),
			esc_html( (float) SD_Settings::get( 'tbm_road_factor' ) ),
			esc_url( $link )
		);
	}

	/**
	 * The formula, as a pure function of its inputs so it can be unit
	 * tested and so the Settings screen's distance tester produces
	 * exactly the same number a customer would be charged.
	 *
	 * charge = km x (2 if round trip) x rate_per_km, floored at the
	 *          minimum charge, then VAT.
	 *
	 * @param float $km          Road-adjusted distance, one way.
	 * @param float $rate_per_km
	 * @param bool  $round_trip
	 * @param float $min_charge
	 * @param float $vat_fraction
	 * @return array{billable_km: float, charge: float, vat: float, total: float, floored: bool}
	 */
	public static function price( $km, $rate_per_km, $round_trip, $min_charge, $vat_fraction ) {
		$billable_km = (float) $km * ( $round_trip ? 2 : 1 );
		$raw         = $billable_km * (float) $rate_per_km;
		$charge      = max( $raw, (float) $min_charge );
		$vat         = $charge * (float) $vat_fraction;

		return [
			'billable_km' => round( $billable_km, 2 ),
			'charge'      => round( $charge, 2 ),
			'vat'         => round( $vat, 2 ),
			'total'       => round( $charge + $vat, 2 ),
			'floored'     => $charge > $raw + 0.0001,
		];
	}

	/**
	 * @param array $package WooCommerce cart package.
	 */
	public function calculate_shipping( $package = [] ) {
		if ( ! SD_Settings::is_on( 'tbm_enabled' ) ) {
			return;
		}

		$destination = $package['destination'] ?? [];

		// South Africa, and — unless that guard is relaxed — Gauteng.
		// State comes through as the WooCommerce code, 'GP' for Gauteng.
		if ( 'ZA' !== ( $destination['country'] ?? '' ) ) {
			return;
		}
		if ( SD_Settings::is_on( 'tbm_gauteng_only' ) && 'GP' !== ( $destination['state'] ?? '' ) ) {
			return;
		}

		$town = $destination['city'] ?? '';
		if ( '' === trim( (string) $town ) ) {
			return; // nothing to measure from yet
		}

		$distance = SD_Geo::distance_to( $town );
		if ( null === $distance ) {
			// Town isn't in the coordinate table. Offering a price would
			// mean inventing a distance, so this method simply steps
			// aside — Campos or Request Quote can still serve the order.
			return;
		}

		$radius = (float) SD_Settings::get( 'tbm_radius_km' );
		if ( $radius > 0 && $distance['km'] > $radius ) {
			return; // outside the service area
		}

		$priced = self::price(
			$distance['km'],
			(float) SD_Settings::get( 'tbm_rate_per_km' ),
			SD_Settings::is_on( 'tbm_round_trip' ),
			(float) SD_Settings::get( 'tbm_min_charge' ),
			SD_Settings::vat_fraction()
		);

		$breakdown = [
			'carrier'      => '3BM',
			'town'         => $town,
			'straight_km'  => $distance['straight_km'],
			'road_km'      => $distance['km'],
			'road_factor'  => (float) SD_Settings::get( 'tbm_road_factor' ),
			'round_trip'   => SD_Settings::is_on( 'tbm_round_trip' ),
			'billable_km'  => $priced['billable_km'],
			'rate_per_km'  => (float) SD_Settings::get( 'tbm_rate_per_km' ),
			'charge'       => $priced['charge'],
			'vat_amount'   => $priced['vat'],
			'total'        => $priced['total'],
			'floored'      => $priced['floored'],
		];

		$this->add_rate(
			[
				'id'        => $this->get_rate_id(),
				'label'     => sprintf(
					/* translators: 1: method title, 2: distance in km */
					__( '%1$s — %2$skm', 'sd-shipping' ),
					$this->title,
					number_format( $distance['km'], 0 )
				),
				'cost'      => $priced['total'],
				'package'   => $package,
				'meta_data' => [
					'sd_3bm_km'        => $distance['km'],
					'sd_3bm_billable'  => $priced['billable_km'],
					'sd_3bm_breakdown' => wp_json_encode( $breakdown ),
				],
			]
		);
	}
}
