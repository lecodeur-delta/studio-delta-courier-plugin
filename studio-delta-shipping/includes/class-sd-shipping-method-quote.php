<?php
/**
 * "Request Quote" — Mode 3 of 3. Registered alongside Campos Delivery in
 * studio-delta-shipping.php via the woocommerce_shipping_methods filter.
 *
 * Deliberately not a price calculator: it never shows a Rand amount at
 * checkout. Per the brief this was built from — "it should only trigger
 * once a location is found" — calculate_shipping() below adds no rate at
 * all until the destination has a city or postcode, and once the order
 * is actually placed with this method chosen, sd_maybe_email_quote_request()
 * emails sales@studiodelta.co.za the products and address so a human can
 * quote it by hand. class-sd-checkout-display.php separately shows a
 * plain-language range ("between Campos and Standard, more in outlying
 * zones") next to this option — see sd_render_quote_range_notice() there.
 */

defined( 'ABSPATH' ) || exit;

class WC_Shipping_SD_RequestQuote extends WC_Shipping_Method {

	/**
	 * Fallback only. The live address is configurable at Studio Delta >
	 * Settings and read via sales_email() below — this constant is what
	 * that falls back to if the settings class isn't loaded (a direct
	 * unit-test include, say).
	 */
	const SALES_EMAIL = 'sales@studiodelta.co.za';

	/**
	 * @return string Where quote requests are emailed.
	 */
	public static function sales_email() {
		if ( class_exists( 'SD_Settings' ) ) {
			$configured = SD_Settings::get( 'sales_email' );
			if ( $configured && is_email( $configured ) ) {
				return $configured;
			}
		}
		return self::SALES_EMAIL;
	}

	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'sd_request_quote';
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'Request Quote (Studio Delta)', 'sd-shipping' );
		$this->method_description = __( 'No price shown at checkout. Appears only once a delivery address is known; placing the order with this method chosen emails sales@studiodelta.co.za the order\'s products and address for a manual quote. South Africa only.', 'sd-shipping' );
		$this->supports           = [ 'shipping-zones', 'instance-settings' ];

		$this->init();
	}

	public function init() {
		$this->init_form_fields();
		$this->init_settings();

		$this->enabled = $this->get_option( 'enabled', 'yes' );
		$this->title   = $this->get_option( 'title', __( 'Request a Delivery Quote', 'sd-shipping' ) );

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
				'description' => __( 'Shown to the customer at checkout.', 'sd-shipping' ),
				'default'     => __( 'Request a Delivery Quote', 'sd-shipping' ),
			],
			'info'    => [
				'title'       => __( 'How this works', 'sd-shipping' ),
				'type'        => 'title',
				'description' => sprintf(
					/* translators: 1: sales email address, 2: settings screen URL */
					__( 'Charges nothing at checkout — no CBM math runs for this method. Once the customer places the order, its products and delivery address are emailed to %1$s automatically so someone can quote it by hand. It only appears once a city or postcode is entered, and only for South African addresses. <a href="%2$s">Change the address or these rules</a>.', 'sd-shipping' ),
					esc_html( self::sales_email() ),
					esc_url( admin_url( 'admin.php?page=sd-delivery-settings' ) )
				),
			],
		];
	}

	/**
	 * South Africa only, and only once we actually know WHERE — a city or
	 * postcode — per "it should only trigger once a location is found"
	 * (the brief's own words). Before that, there's nothing yet for sales
	 * to quote against, so offering the option is just checkout noise.
	 *
	 * @param array $package WooCommerce cart package.
	 */
	public function calculate_shipping( $package = [] ) {
		if ( ! $this->is_south_africa( $package ) ) {
			return;
		}

		$destination  = $package['destination'] ?? [];
		$has_location = ! empty( $destination['city'] ) || ! empty( $destination['postcode'] );
		if ( ! $has_location ) {
			return;
		}

		$this->add_rate(
			[
				'id'      => $this->get_rate_id(),
				'label'   => $this->title,
				'cost'    => 0,
				'package' => $package,
			]
		);
	}

	/**
	 * True when this package may be offered a quote request: a South
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

/**
 * Once an order is actually placed with this method chosen, email sales
 * the products + delivery address. Hooked to
 * woocommerce_checkout_order_processed (the order object exists, before
 * payment necessarily completes) rather than something that fires on
 * every cart recalculation — we want exactly one email, when there's a
 * real order to quote, not one per keystroke while still shopping.
 *
 * @param int $order_id
 */
add_action( 'woocommerce_checkout_order_processed', 'sd_maybe_email_quote_request', 20, 1 );
function sd_maybe_email_quote_request( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return;
	}

	$is_quote_request = false;
	foreach ( $order->get_items( 'shipping' ) as $shipping_item ) {
		if ( 0 === strpos( $shipping_item->get_method_id(), 'sd_request_quote' ) ) {
			$is_quote_request = true;
			break;
		}
	}
	if ( ! $is_quote_request ) {
		return;
	}

	// Guard against a duplicate email if this hook is ever triggered
	// twice for the same order (some payment gateways re-fire checkout
	// hooks on redirect-back) — one email per order, not per hook call.
	if ( 'yes' === $order->get_meta( '_sd_quote_email_sent' ) ) {
		return;
	}

	$sent = wp_mail(
		WC_Shipping_SD_RequestQuote::sales_email(),
		sd_quote_request_email_subject( $order ),
		sd_quote_request_email_body( $order )
	);

	if ( $sent ) {
		$order->update_meta_data( '_sd_quote_email_sent', 'yes' );
		$order->save();
	} else {
		wc_get_logger()->error(
			'Quote-request email failed to send for order #' . $order->get_order_number(),
			[ 'source' => 'sd-shipping' ]
		);
	}
}

/**
 * @param WC_Order $order
 * @return string
 */
function sd_quote_request_email_subject( $order ) {
	return sprintf( '[Quote Request] Order #%s', $order->get_order_number() );
}

/**
 * Pulled out as its own function (rather than inlined in the hook above)
 * so it can be unit tested against a stub order without touching wp_mail
 * or the database — see /tmp/smoke_test5.php.
 *
 * @param WC_Order $order
 * @return string
 */
function sd_quote_request_email_body( $order ) {
	$lines = [];
	foreach ( $order->get_items() as $item ) {
		$product = $item->get_product();
		$sku     = ( $product && $product->get_sku() ) ? $product->get_sku() : '';
		$lines[] = sprintf(
			'- %s%s x%d',
			$item->get_name(),
			$sku ? " (SKU: {$sku})" : '',
			$item->get_quantity()
		);
	}

	$address = trim( implode( "\n", array_filter( [
		$order->get_formatted_shipping_full_name(),
		$order->get_shipping_address_1(),
		$order->get_shipping_address_2(),
		trim( $order->get_shipping_city() . ' ' . $order->get_shipping_postcode() ),
		$order->get_shipping_state(),
		$order->get_shipping_country(),
	] ) ) );

	return sprintf(
		"A customer placed an order requesting a delivery quote instead of Campos/Standard delivery.\n\n" .
		"Order: #%s\nCustomer: %s <%s>\n\nProducts:\n%s\n\nDeliver to:\n%s\n\n" .
		"Please quote this by hand and follow up with the customer directly.\n",
		$order->get_order_number(),
		$order->get_formatted_billing_full_name(),
		$order->get_billing_email(),
		implode( "\n", $lines ) ?: '(no line items found)',
		$address ?: '(no shipping address captured)'
	);
}
