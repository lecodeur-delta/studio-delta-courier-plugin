<?php
/**
 * The Studio Delta admin console: one top-level item in the wp-admin
 * sidebar with four screens under it, so nothing about delivery pricing
 * has to be hunted for inside WooCommerce > Settings > Shipping.
 *
 *   Studio Delta
 *     ├── Product Rates    every product's price per destination (page-product-rates.php)
 *     ├── Fuel Surcharge   the dated levy history + the form that adds to it (page-fuel-surcharge.php)
 *     ├── Standard Nationwide  the courier price list, per product and province (page-nationwide.php)
 *     ├── Export           CSV in and out (page-export.php)
 *     ├── Checkout Text    every customer-facing word, editable (page-text.php)
 *     └── Settings         everything configurable, incl. which zones offer our methods (page-settings.php)
 *
 * WooCommerce is still where the money actually moves - these screens
 * only read and write the same data the shipping methods use at
 * checkout (SD_Settings, SD_Fuel_Levy, product meta, WC's own shipping
 * zone tables). There is no parallel copy of anything here.
 *
 * Built by Levi Bugera - Delta Creative Agency - https://www.lecodeur.co.za
 */

defined( 'ABSPATH' ) || exit;

class SD_Admin_Menu {

	const CAPABILITY = 'manage_woocommerce';
	const SLUG       = 'sd-delivery';

	/** Author credit, kept in one place so every screen agrees. */
	const AUTHOR      = 'Levi Bugera';
	const AGENCY      = 'Delta Creative Agency';
	const AGENCY_URL  = 'https://www.lecodeur.co.za';

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );

		// Form handlers. Each page file registers its own logic; these are
		// the admin-post endpoints they submit to.
		add_action( 'admin_post_sd_save_fuel_levy', [ 'SD_Admin_Fuel_Surcharge', 'handle_save' ] );
		add_action( 'admin_post_sd_save_settings', [ 'SD_Admin_Settings_Page', 'handle_save' ] );
		add_action( 'admin_post_sd_export', [ 'SD_Admin_Export', 'handle_export' ] );
		add_action( 'admin_post_sd_import_dimensions', [ 'SD_Admin_Export', 'handle_import' ] );
		add_action( 'admin_post_sd_save_nationwide', [ 'SD_Admin_Nationwide', 'handle_save' ] );
		add_action( 'admin_post_sd_reset_nationwide', [ 'SD_Admin_Nationwide', 'handle_reset' ] );
		add_action( 'admin_post_sd_save_text', [ 'SD_Admin_Text', 'handle_save' ] );
		add_action( 'admin_post_sd_reset_text', [ 'SD_Admin_Text', 'handle_reset' ] );
	}

	/**
	 * The sidebar icon: an S monogram, as a base64 data URI.
	 *
	 * A data URI rather than a file so the icon can never 404 behind a
	 * CDN or a moved plugin folder, and an SVG rather than a Dashicon so
	 * it is actually Studio Delta's initial rather than a generic truck.
	 * fill="black" is deliberate: WordPress recolours a data-URI menu
	 * icon by CSS filter, and only a solid single-colour glyph survives
	 * that cleanly in both the default and light admin schemes.
	 *
	 * @return string
	 */
	public static function menu_icon() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">'
			. '<text x="10" y="15.5" font-family="Georgia,\'Times New Roman\',serif" '
			. 'font-size="17" font-weight="700" text-anchor="middle" fill="black">S</text>'
			. '</svg>';

		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	public static function menu() {
		add_menu_page(
			__( 'Studio Delta Delivery', 'sd-shipping' ),
			__( 'Studio Delta', 'sd-shipping' ),
			self::CAPABILITY,
			self::SLUG,
			[ 'SD_Admin_Product_Rates', 'render' ],
			self::menu_icon(),
			56 // directly under WooCommerce
		);

		add_submenu_page(
			self::SLUG,
			__( 'Product Rates', 'sd-shipping' ),
			__( 'Product Rates', 'sd-shipping' ),
			self::CAPABILITY,
			self::SLUG,
			[ 'SD_Admin_Product_Rates', 'render' ]
		);

		add_submenu_page(
			self::SLUG,
			__( 'Fuel Surcharge', 'sd-shipping' ),
			__( 'Fuel Surcharge', 'sd-shipping' ),
			self::CAPABILITY,
			self::SLUG . '-fuel',
			[ 'SD_Admin_Fuel_Surcharge', 'render' ]
		);

		add_submenu_page(
			self::SLUG,
			__( 'Standard Nationwide', 'sd-shipping' ),
			__( 'Standard Nationwide', 'sd-shipping' ),
			self::CAPABILITY,
			self::SLUG . '-nationwide',
			[ 'SD_Admin_Nationwide', 'render' ]
		);

		add_submenu_page(
			self::SLUG,
			__( 'Export', 'sd-shipping' ),
			__( 'Export', 'sd-shipping' ),
			self::CAPABILITY,
			self::SLUG . '-export',
			[ 'SD_Admin_Export', 'render' ]
		);

		add_submenu_page(
			self::SLUG,
			__( 'Checkout Text', 'sd-shipping' ),
			__( 'Checkout Text', 'sd-shipping' ),
			self::CAPABILITY,
			self::SLUG . '-text',
			[ 'SD_Admin_Text', 'render' ]
		);

		add_submenu_page(
			self::SLUG,
			__( 'Settings', 'sd-shipping' ),
			__( 'Settings', 'sd-shipping' ),
			self::CAPABILITY,
			self::SLUG . '-settings',
			[ 'SD_Admin_Settings_Page', 'render' ]
		);
	}

	/**
	 * One small stylesheet, loaded only on our own screens - never
	 * globally, so nothing here can leak into other plugins' pages.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_style(
			'sd-admin',
			SD_SHIPPING_URL . 'assets/admin.css',
			[],
			SD_SHIPPING_VERSION
		);

		// The Standard Nationwide screen uses WooCommerce's own product
		// search box, which needs WC's admin script and styles.
		if ( false !== strpos( (string) $hook, self::SLUG . '-nationwide' ) ) {
			wp_enqueue_script( 'wc-enhanced-select' );
			wp_enqueue_style( 'woocommerce_admin_styles' );
		}
	}

	/**
	 * @param string $suffix '', '-fuel', '-nationwide', '-export', '-text', '-settings'
	 * @return string
	 */
	public static function page_url( $suffix = '' ) {
		return admin_url( 'admin.php?page=' . self::SLUG . $suffix );
	}

	/**
	 * Shared page header: the title, a one-line description, and the tab
	 * strip. Called at the top of every screen so they can't drift apart.
	 *
	 * @param string $current One of 'rates', 'fuel', 'nationwide', 'export', 'text', 'settings'.
	 * @param string $title
	 * @param string $intro
	 */
	public static function header( $current, $title, $intro = '' ) {
		$tabs = [
			'rates'    => [ __( 'Product Rates', 'sd-shipping' ), '' ],
			'fuel'       => [ __( 'Fuel Surcharge', 'sd-shipping' ), '-fuel' ],
			'nationwide' => [ __( 'Standard Nationwide', 'sd-shipping' ), '-nationwide' ],
			'export'     => [ __( 'Export', 'sd-shipping' ), '-export' ],
			'text'       => [ __( 'Checkout Text', 'sd-shipping' ), '-text' ],
			'settings'   => [ __( 'Settings', 'sd-shipping' ), '-settings' ],
		];
		?>
		<div class="wrap sd-wrap">
			<h1 class="sd-title"><?php echo esc_html( $title ); ?></h1>
			<?php if ( $intro ) : ?>
				<p class="sd-intro"><?php echo wp_kses_post( $intro ); ?></p>
			<?php endif; ?>
			<nav class="nav-tab-wrapper sd-tabs">
				<?php foreach ( $tabs as $key => $tab ) : ?>
					<a class="nav-tab <?php echo $key === $current ? 'nav-tab-active' : ''; ?>"
					   href="<?php echo esc_url( self::page_url( $tab[1] ) ); ?>">
						<?php echo esc_html( $tab[0] ); ?>
					</a>
				<?php endforeach; ?>
			</nav>
		<?php
	}

	/**
	 * Shared page footer, including the build credit. Closes the .wrap
	 * opened by header().
	 */
	public static function footer() {
		?>
			<p class="sd-credit">
				<?php
				printf(
					/* translators: 1: plugin version, 2: author name, 3: agency link */
					esc_html__( 'Studio Delta Shipping v%1$s - built by %2$s, %3$s', 'sd-shipping' ),
					esc_html( SD_SHIPPING_VERSION ),
					esc_html( self::AUTHOR ),
					'<a href="' . esc_url( self::AGENCY_URL ) . '" target="_blank" rel="noopener">' . esc_html( self::AGENCY ) . '</a>'
				);
				?>
			</p>
		</div><!-- /.wrap -->
		<?php
	}

	/**
	 * The live fuel levy, formatted for display, or null if never set.
	 * Used by more than one screen, so it lives here rather than being
	 * re-derived on each.
	 *
	 * @return string|null e.g. "33%"
	 */
	public static function current_levy_label() {
		$current = SD_Fuel_Levy::get_current();
		if ( null === $current ) {
			return null;
		}
		return rtrim( rtrim( number_format( $current * 100, 2 ), '0' ), '.' ) . '%';
	}

	/**
	 * Guard every screen and handler identically: right capability, and
	 * for handlers, a valid nonce.
	 *
	 * @param string|null $nonce_action Pass to also check_admin_referer().
	 */
	public static function require_access( $nonce_action = null ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Studio Delta delivery settings.', 'sd-shipping' ) );
		}
		if ( $nonce_action ) {
			check_admin_referer( $nonce_action );
		}
	}
}
