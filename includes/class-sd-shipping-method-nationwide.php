<?php
/**
 * "Standard Nationwide Shipping": flat courier prices for the smaller
 * items Studio Delta sends by courier, per product and per province.
 *
 * Not to be confused with "Standard Rates" elsewhere in this plugin,
 * which means the merchant's own untouched WooCommerce methods. This is
 * a Studio Delta method like Campos, priced from the table at
 * Studio Delta > Standard Nationwide (SD_Courier_Rates).
 *
 *   price = SUM( item's price for the province x quantity )
 *
 * Charged exactly as listed: VAT is already in the prices, no fuel levy
 * is added, and WooCommerce is told not to add tax on top. Offered only
 * when every item in the basket has a price for the delivery province;
 * otherwise this method adds no rate and the other options carry on.
 *
 * Built by Levi Bugera - Delta Creative Agency - https://www.lecodeur.co.za
 */

defined( 'ABSPATH' ) || exit;

class WC_Shipping_SD_Nationwide extends WC_Shipping_Method {

	const METHOD_ID = 'sd_standard_nationwide';

	public function __construct( $instance_id = 0 ) {
		$this->id                 = self::METHOD_ID;
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'Standard Nationwide Shipping (Studio Delta)', 'sd-shipping' );
		$this->method_description = __( 'Flat courier prices per product and per province, for smaller items. Offered only when every item in the basket has a courier price for the delivery province. Prices are charged exactly as listed, VAT included. Edit them at Studio Delta > Standard Nationwide.', 'sd-shipping' );
		$this->supports           = [ 'shipping-zones', 'instance-settings' ];

		$this->init();
	}

	public function init() {
		$this->init_form_fields();
		$this->init_settings();

		$this->enabled = $this->get_option( 'enabled', 'yes' );
		$this->title   = $this->get_option( 'title', __( 'Standard Nationwide Shipping', 'sd-shipping' ) );

		add_action( 'woocommerce_update_options_shipping_' . $this->id, [ $this, 'process_admin_options' ] );
	}

	public function init_form_fields() {
		$this->instance_form_fields = [
			'enabled' => [
				'title'   => __( 'Enable', 'sd-shipping' ),
				'type'    => 'checkbox',
				'default' => 'yes',
			],
			'info'    => [
				'title'       => __( 'Prices and wording', 'sd-shipping' ),
				'type'        => 'title',
				'description' => sprintf(
					/* translators: 1: prices screen URL, 2: wording screen URL */
					__( 'Nothing to set here. <a href="%1$s">Prices and products</a> are at Studio Delta > Standard Nationwide, and <a href="%2$s">what the customer reads</a> is at Studio Delta > Checkout Text.', 'sd-shipping' ),
					esc_url( admin_url( 'admin.php?page=sd-delivery-nationwide' ) ),
					esc_url( admin_url( 'admin.php?page=sd-delivery-text' ) )
				),
			],
		];
	}

	/**
	 * @param array $package WooCommerce cart package.
	 */
	public function calculate_shipping( $package = [] ) {
		$destination = $package['destination'] ?? [];

		if ( class_exists( 'SD_Settings' ) && SD_Settings::is_on( 'za_only' ) && 'ZA' !== ( $destination['country'] ?? '' ) ) {
			return;
		}

		$region = SD_Courier_Rates::region_for_destination( $destination );
		$priced = SD_Courier_Rates::price_cart(
			SD_Courier_Rates::lines_from_contents( $package['contents'] ?? [] ),
			$region
		);

		if ( ! $priced['available'] ) {
			return;
		}

		$label = SD_Text::get( 'nw_label', [ 'region' => $priced['region_name'] ] );
		if ( '' === trim( $label ) ) {
			$label = $this->title;
		}

		$this->add_rate(
			[
				'id'        => $this->get_rate_id() . ':' . $region,
				'label'     => $label,
				'cost'      => $priced['total'],
				// Exactly as listed: the prices already include VAT.
				'taxes'     => false,
				'package'   => $package,
				'meta_data' => [
					'sd_nw_region'    => $priced['region_name'],
					'sd_nw_breakdown' => wp_json_encode( [
						'region'      => $priced['region'],
						'region_name' => $priced['region_name'],
						'total'       => $priced['total'],
						'lines'       => $priced['lines'],
					] ),
				],
			]
		);
	}
}
