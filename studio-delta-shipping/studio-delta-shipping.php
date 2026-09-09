<?php
/**
 * Plugin Name: Studio Delta Shipping
 * Plugin URI: https://www.lecodeur.co.za
 * Description: Cubic-metre delivery pricing for South Africa with its own admin console. Three modes side by side in your existing WooCommerce zones — Campos Delivery (CBM x corridor rate + monthly fuel surcharge, for large items), your existing Standard Rates (untouched), and Request Quote (emails sales once a destination is known).
 * Version: 0.5.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Levi Bugera — Delta Creative Agency
 * Author URI: https://www.lecodeur.co.za
 * Text Domain: sd-shipping
 *
 * Built by Levi Bugera under Delta Creative Agency (https://www.lecodeur.co.za)
 * for the Studio Delta store.
 *
 * This is a working implementation, not a polished public plugin: no
 * PHPCS/i18n pass, input hardening limited to nonces + sanitisation, and
 * the outlying-area (>75km) branch is still a stub until depot
 * coordinates are confirmed (see the doc set, "Open questions", #4).
 *
 * v0.5.0 changelog (quote flow, 3BM, rate rules):
 *   - Request a Quote is now a real flow rather than an order note:
 *     choosing it hides the payment methods and Place order, offers a
 *     "Request a Quote" button, and opens a confirmation panel showing
 *     the customer their own details before sending. On send it records
 *     a pending order and emails sales a uniform HTML table of every
 *     checkout field (class-sd-quote-request.php).
 *   - Quote-only rules (class-sd-rate-rules.php): when the address
 *     matches none of the 60 corridors, or when delivery would exceed
 *     60% of the cart, every other option is removed and only Request a
 *     Quote is offered. Both thresholds configurable, both skipped when
 *     free shipping or collection is available.
 *   - New 3BM Delivery method: Gauteng only, R5/km round trip from the
 *     Lynnwood depot within 150km, distance from a built-in coordinate
 *     table (class-sd-geo.php). SHIPS DISABLED — confirm the depot
 *     coordinate in Settings first.
 *   - Standard Rates can be switched on and off per zone from Studio
 *     Delta > Settings, without opening WooCommerce settings.
 *   - Delivery policy and FAQ links at the foot of the checkout.
 *   - Sidebar icon is now an S monogram.
 *
 * v0.4.1 changelog (checkout fix — install this over 0.4.0):
 *   - FIXED: a fatal error that broke the checkout page. Four places read
 *     rate meta with $rate->get_meta( 'key' ), but WC_Shipping_Rate does
 *     not extend WC_Data and has no get_meta() — only get_meta_data().
 *     The undefined-method Error was thrown while WooCommerce rendered
 *     the review-order fragment, so it surfaced as HTTP 500 on
 *     ?wc-ajax=update_order_review and a checkout with no totals
 *     section. All reads now go through sd_rate_meta().
 *   - Both checkout render hooks are now wrapped in sd_safe_render():
 *     output is buffered and any Throwable is logged and swallowed, so a
 *     display bug can never take the checkout down again.
 *   - Rebuilt the breakdown markup for narrow checkout columns: no
 *     nested table (it overflowed sidebars), <td colspan="2"> instead of
 *     <th>, data-title="" for responsive themes, no white-space:nowrap,
 *     and a once-per-request guard against duplicate rows.
 *   - The Shipping Class cross-check is now OFF by default — it is a
 *     staff diagnostic, not customer-facing information.
 *
 * v0.4.0 changelog (admin console):
 *   - Everything now lives under one "Studio Delta" item in the wp-admin
 *     sidebar: Product Rates, Fuel Surcharge, Export, Settings. The old
 *     WooCommerce > Settings > Shipping > "Studio Delta Fuel Levy"
 *     section is gone; its screen moved, its data table did not, so no
 *     history is lost.
 *   - Product Rates: every product priced against any of the 60
 *     destination corridors, chosen from a dropdown, with any one product
 *     expandable to show all destinations at once.
 *   - Settings can assign Campos Delivery / Request Quote to your
 *     existing WooCommerce shipping zones directly — no trip into
 *     WooCommerce settings. Your existing methods in those zones are
 *     listed read-only as "Standard Rates" and are never modified.
 *   - Fuel surcharge now shows a fuel-pump icon at checkout (toggleable).
 *   - New SD_Settings layer: VAT rate, sales email, badge text, ZA-only
 *     guard, description parsing, size-label thresholds, and which
 *     checkout blocks appear.
 *   - SD_Pricing is now the single home for the pricing formula, shared
 *     by checkout, the admin rates table, and the CSV rate matrix.
 *
 * v0.3.0 changelog (3-mode rebuild):
 *   - Renamed the custom method from "Studio Delta Zone Delivery" to
 *     "Campos Delivery" (id sd_zone_delivery -> sd_campos_delivery).
 *   - Added the Request Quote method and the South-Africa-only guard.
 *   - Added dimension (L/W/H/D) reading and the CSV workflow.
 */

defined( 'ABSPATH' ) || exit;

define( 'SD_SHIPPING_VERSION', '0.5.0' );
define( 'SD_SHIPPING_PATH', plugin_dir_path( __FILE__ ) );
define( 'SD_SHIPPING_URL', plugin_dir_url( __FILE__ ) );

/**
 * Bail early and say why if WooCommerce isn't active — this plugin is
 * useless without it, and a fatal error on a bare-PHP site is worse
 * than a clear admin notice.
 */
function sd_shipping_missing_wc_notice() {
	echo '<div class="notice notice-error"><p>';
	esc_html_e( 'Studio Delta Shipping requires WooCommerce to be installed and active.', 'sd-shipping' );
	echo '</p></div>';
}

add_action( 'plugins_loaded', function () {

	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'sd_shipping_missing_wc_notice' );
		return;
	}

	// Shared: needed on both front-end and admin requests.
	require_once SD_SHIPPING_PATH . 'includes/class-sd-settings.php';
	require_once SD_SHIPPING_PATH . 'includes/class-sd-zones.php';
	require_once SD_SHIPPING_PATH . 'includes/class-sd-fuel-levy.php';
	require_once SD_SHIPPING_PATH . 'includes/class-sd-pricing.php';
	require_once SD_SHIPPING_PATH . 'includes/class-sd-geo.php';
	require_once SD_SHIPPING_PATH . 'includes/class-sd-rate-rules.php';
	require_once SD_SHIPPING_PATH . 'includes/class-sd-quote-request.php';
	require_once SD_SHIPPING_PATH . 'includes/class-sd-cbm-resolver.php';
	require_once SD_SHIPPING_PATH . 'includes/class-sd-product-data.php';
	require_once SD_SHIPPING_PATH . 'includes/product-meta.php';
	require_once SD_SHIPPING_PATH . 'includes/class-sd-checkout-display.php';

	SD_Rate_Rules::init();
	SD_Quote_Request::init();

	// Admin console — only loaded for admin requests, so none of these
	// four screens costs a front-end page load anything.
	if ( is_admin() ) {
		require_once SD_SHIPPING_PATH . 'includes/admin/class-sd-admin-menu.php';
		require_once SD_SHIPPING_PATH . 'includes/admin/page-product-rates.php';
		require_once SD_SHIPPING_PATH . 'includes/admin/page-fuel-surcharge.php';
		require_once SD_SHIPPING_PATH . 'includes/admin/page-export.php';
		require_once SD_SHIPPING_PATH . 'includes/admin/page-settings.php';
		SD_Admin_Menu::init();
	}

	// The shipping method classes extend WC_Shipping_Method, so they can
	// only load once WooCommerce's own classes exist.
	add_action( 'woocommerce_shipping_init', function () {
		require_once SD_SHIPPING_PATH . 'includes/class-sd-shipping-method.php';
		require_once SD_SHIPPING_PATH . 'includes/class-sd-shipping-method-quote.php';
		require_once SD_SHIPPING_PATH . 'includes/class-sd-shipping-method-3bm.php';
	} );

	add_filter( 'woocommerce_shipping_methods', function ( $methods ) {
		$methods['sd_campos_delivery'] = 'WC_Shipping_SD_Campos';
		$methods['sd_request_quote']   = 'WC_Shipping_SD_RequestQuote';
		$methods['sd_3bm_delivery']    = 'WC_Shipping_SD_3BM';
		return $methods;
	} );
} );

/**
 * A direct link to the console from the Plugins screen — the fastest
 * route to it right after activating, before anyone has spotted the new
 * sidebar item.
 */
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	array_unshift(
		$links,
		'<a href="' . esc_url( admin_url( 'admin.php?page=sd-delivery-settings' ) ) . '">' . esc_html__( 'Settings', 'sd-shipping' ) . '</a>',
		'<a href="' . esc_url( admin_url( 'admin.php?page=sd-delivery' ) ) . '">' . esc_html__( 'Product Rates', 'sd-shipping' ) . '</a>'
	);
	return $links;
} );

/**
 * Activation: create the fuel-levy history table and seed it with one
 * starting value so calculate_shipping() always has something to read.
 * Adjust the seeded percentage from Studio Delta > Fuel Surcharge —
 * see the doc set, "Open questions", #1.
 */
register_activation_hook( __FILE__, function () {
	require_once SD_SHIPPING_PATH . 'includes/class-sd-fuel-levy.php';
	SD_Fuel_Levy::install_table();

	if ( ! SD_Fuel_Levy::get_current() ) {
		SD_Fuel_Levy::insert( null, 0.33, current_time( 'Y-m-d' ), get_current_user_id() );
	}
} );
