<?php
/**
 * Plugin Name: Studio Delta Shipping
 * Plugin URI: https://www.lecodeur.co.za
 * Description: Delivery pricing for South Africa with its own admin console. Side by side in your existing WooCommerce zones - Standard Nationwide Shipping (flat courier prices per product and province, for smaller items), Campos Delivery (CBM x corridor rate + monthly fuel surcharge, charged per product, for the whole catalogue except outlying areas), your existing Standard Rates (untouched), and Request Quote (a button and form popup that emails sales once a destination is known). Every customer-facing word is editable.
 * Version: 0.7.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Levi Bugera - Delta Creative Agency
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
 * v0.7.0 changelog (Standard Nationwide Shipping, editable checkout text):
 *   - NEW METHOD: Standard Nationwide Shipping (id sd_standard_nationwide,
 *     class-sd-shipping-method-nationwide.php). Flat courier prices per
 *     product and per province, from Studio Delta's own courier price
 *     list: Olivia Bathroom Mirror Cabinet, Small / Medium / Large
 *     Planter, Daphne Mirror, Zola Side Table, Blaire and Thandi Bedside
 *     Cabinets, Ophelia / Claire / Marison / Raye Coffee Tables, to
 *     Western Cape (Cape Town), KwaZulu-Natal (Umhlanga), Limpopo
 *     (Polokwane), Free State, North West, Eastern Cape, Mpumalanga and
 *     Northern Cape. Gauteng is a column too, blank until prices are
 *     added; Olivia to Limpopo was not on the list and is blank as well.
 *     Rules: each item's price x quantity, added together; charged
 *     exactly as listed (VAT included, no fuel levy, WooCommerce told not
 *     to add tax); offered only when EVERY item in the basket has a price
 *     for the delivery province - a blank is never free, and one bulky
 *     item hides the option so Campos / Request a Quote take the order.
 *   - New screen Studio Delta > Standard Nationwide (page-nationwide.php):
 *     edit every price, add more courier products, link each row to the
 *     real WooCommerce products it covers (or let a name phrase find
 *     them), see live which products each row applies to, and keep a
 *     "never by courier" list. Products can also be put on a courier
 *     price from their own Shipping tab. Data: SD_Courier_Rates, option
 *     sd_courier_rates; seeded from the supplied list until first saved.
 *   - Settings: a Standard Nationwide column in the zone table, plus
 *     "Hide Campos when Standard Nationwide is offered" (off) and "Explain
 *     when it is not offered" (on). The quote-only "outside our routes"
 *     rule no longer fires when Standard Nationwide has priced the basket,
 *     since courier prices are per province, not per Campos corridor.
 *   - New screen Studio Delta > Checkout Text (SD_Text, option sd_text):
 *     every customer-facing word is editable - all four option labels,
 *     the notes beside them, the fuel levy wording, both cost breakdowns,
 *     every notice, the Request a Quote button, hint and popup messages,
 *     the customer's email copy and the footer links - with {placeholders}
 *     for the per-order figures. Optional fields can be cleared to hide
 *     them; required ones fall back to the default. The Campos badge
 *     moved here from Settings and is carried across automatically
 *     (settings schema 3).
 *
 * v0.6.0 changelog (per-product charging, quote form, whole catalogue):
 *   - CAMPOS NOW CHARGES PER PRODUCT. Each cart line is priced on its own
 *     CBM, the R840-type corridor minimum is applied to each unit, and the
 *     results are added up: SD_Pricing::calculate_items(). Before this,
 *     the cart's total volume was priced once and one minimum applied to
 *     the whole delivery, which is why two cabinets cost the same as one.
 *     Evidence for the change is in that method's docblock: invoices
 *     S250617 (2 x Thalia to Pietermaritzburg) and S250614 (Room Divider +
 *     Sideboard to Hilton) both billed R1,680, i.e. 2 x R840.
 *     COUNTER-EVIDENCE, and worth confirming with Campos: S260073 (2 x
 *     Angelica Planter Box, 0.24 m3 each, to Somerset West) billed R840,
 *     one minimum for both. So per-product will over-charge a basket of
 *     several small items. The old behaviour is still available - Studio
 *     Delta > Settings > Campos > "Charge basis" > per order.
 *   - CAMPOS IS NO LONGER LARGE-ITEMS-ONLY. It now offers a price for the
 *     whole catalogue, everywhere except the outlying areas (the four
 *     remote_override corridors in zone-data.php), where it steps aside
 *     for Request a Quote. Toggle: campos_skip_outlying, on by default.
 *     The "(for large items)" badge is gone from the checkout label, and
 *     because a default change cannot clear a value already saved in the
 *     database, SD_Settings::maybe_upgrade() removes it from existing
 *     installs. A badge you typed yourself is left alone.
 *   - REQUEST A QUOTE IS NOW A BUTTON WITH A FORM POPUP. The popup used to
 *     show the checkout details read-only for confirmation; it now renders
 *     real, editable inputs prefilled from the checkout, so a customer can
 *     correct an address or add a note without going back. What the form
 *     holds when Send is pressed is what gets emailed (assets/quote.js,
 *     buildForm() / collectForm()).
 *   - Every em-dash removed from the plugin source, the doc set and the
 *     formula workbook.
 *   - The checkout breakdown now prices each line separately, states the
 *     charge basis in words, and says which minimum is doing the work.
 *
 * v0.5.1 changelog (make the cart total visible):
 *   - Campos always did sum the whole cart - verified in
 *     /tmp/smoke_test10.php, which drives the real
 *     build_cart_breakdown() across multiple lines and quantities. What
 *     hid it was the courier's minimum charge, which is per DELIVERY:
 *     two Thalia cabinets are 1.269 m3 x R471 = R597.87, still under the
 *     R840 floor, so the price is identical and looks stuck.
 *   - The checkout breakdown now states the cart's total volume, shows a
 *     multi-quantity line's own contribution (0.6347 x 2 = 1.2694 m3),
 *     and when the floor is doing the work says so: which minimum
 *     applies, how much volume it covers, and how much more can be added
 *     before the price moves.
 *   - A cart where nothing has a measured size is flagged separately -
 *     that price is the area minimum by default, not by calculation.
 *   - SD_Pricing::calculate() now also returns raw_charge, floored and
 *     cbm_at_min so any caller can explain the same thing.
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
 *     table (class-sd-geo.php). SHIPS DISABLED - confirm the depot
 *     coordinate in Settings first.
 *   - Standard Rates can be switched on and off per zone from Studio
 *     Delta > Settings, without opening WooCommerce settings.
 *   - Delivery policy and FAQ links at the foot of the checkout.
 *   - Sidebar icon is now an S monogram.
 *
 * v0.4.1 changelog (checkout fix - install this over 0.4.0):
 *   - FIXED: a fatal error that broke the checkout page. Four places read
 *     rate meta with $rate->get_meta( 'key' ), but WC_Shipping_Rate does
 *     not extend WC_Data and has no get_meta() - only get_meta_data().
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
 *   - The Shipping Class cross-check is now OFF by default - it is a
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
 *     existing WooCommerce shipping zones directly - no trip into
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

define( 'SD_SHIPPING_VERSION', '0.7.0' );
define( 'SD_SHIPPING_PATH', plugin_dir_path( __FILE__ ) );
define( 'SD_SHIPPING_URL', plugin_dir_url( __FILE__ ) );

/**
 * Bail early and say why if WooCommerce isn't active - this plugin is
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
	require_once SD_SHIPPING_PATH . 'includes/class-sd-text.php';
	require_once SD_SHIPPING_PATH . 'includes/class-sd-courier-rates.php';
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

	// One-time settings migrations. Cheap (a single option read that is
	// already in the options cache) and idempotent, so it can run on every
	// request rather than needing an activation hook - which would never
	// fire on a plugin updated in place over FTP.
	SD_Settings::maybe_upgrade();

	SD_Rate_Rules::init();
	SD_Quote_Request::init();

	// Admin console - only loaded for admin requests, so none of these
	// screens costs a front-end page load anything.
	if ( is_admin() ) {
		require_once SD_SHIPPING_PATH . 'includes/admin/class-sd-admin-menu.php';
		require_once SD_SHIPPING_PATH . 'includes/admin/page-product-rates.php';
		require_once SD_SHIPPING_PATH . 'includes/admin/page-fuel-surcharge.php';
		require_once SD_SHIPPING_PATH . 'includes/admin/page-export.php';
		require_once SD_SHIPPING_PATH . 'includes/admin/page-settings.php';
		require_once SD_SHIPPING_PATH . 'includes/admin/page-nationwide.php';
		require_once SD_SHIPPING_PATH . 'includes/admin/page-text.php';
		SD_Admin_Menu::init();
	}

	// The shipping method classes extend WC_Shipping_Method, so they can
	// only load once WooCommerce's own classes exist.
	add_action( 'woocommerce_shipping_init', function () {
		require_once SD_SHIPPING_PATH . 'includes/class-sd-shipping-method.php';
		require_once SD_SHIPPING_PATH . 'includes/class-sd-shipping-method-quote.php';
		require_once SD_SHIPPING_PATH . 'includes/class-sd-shipping-method-3bm.php';
		require_once SD_SHIPPING_PATH . 'includes/class-sd-shipping-method-nationwide.php';
	} );

	add_filter( 'woocommerce_shipping_methods', function ( $methods ) {
		$methods['sd_standard_nationwide'] = 'WC_Shipping_SD_Nationwide';
		$methods['sd_campos_delivery']     = 'WC_Shipping_SD_Campos';
		$methods['sd_request_quote']   = 'WC_Shipping_SD_RequestQuote';
		$methods['sd_3bm_delivery']    = 'WC_Shipping_SD_3BM';
		return $methods;
	} );
} );

/**
 * A direct link to the console from the Plugins screen - the fastest
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
 * Adjust the seeded percentage from Studio Delta > Fuel Surcharge -
 * see the doc set, "Open questions", #1.
 */
register_activation_hook( __FILE__, function () {
	require_once SD_SHIPPING_PATH . 'includes/class-sd-fuel-levy.php';
	SD_Fuel_Levy::install_table();

	if ( ! SD_Fuel_Levy::get_current() ) {
		SD_Fuel_Levy::insert( null, 0.33, current_time( 'Y-m-d' ), get_current_user_id() );
	}
} );
