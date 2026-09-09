<?php
/**
 * Every configurable value in the plugin, in one place, behind typed
 * getters — so no other file ever calls get_option() directly or invents
 * its own default.
 *
 * Stored as a single option array (`sd_settings`) rather than one option
 * per field: it's one row to read on every page load, one thing to back
 * up, and one thing the Settings screen (includes/admin/page-settings.php)
 * writes atomically. Anything that is data rather than configuration
 * stays where it already lives — the fuel levy in its dated history table
 * (class-sd-fuel-levy.php), the corridor rates in code (zone-data.php),
 * per-product CBM in product meta — because those each need history,
 * versioning, or per-product scope that a settings blob can't give them.
 */

defined( 'ABSPATH' ) || exit;

class SD_Settings {

	const OPTION = 'sd_settings';

	/** @var array|null runtime cache, so repeat reads in one request are free */
	protected static $cache = null;

	/**
	 * The shipped defaults. Every key the plugin reads must appear here —
	 * get() falls back to this array, so a site that has never opened the
	 * Settings screen behaves identically to one that has saved it once
	 * without changing anything.
	 *
	 * @return array
	 */
	public static function defaults() {
		return [
			// --- Checkout behaviour ---------------------------------------
			'vat_rate'            => 15.0,   // % added after the fuel levy
			'za_only'             => 'yes',  // only offer our methods for ZA addresses
			'unmatched_behaviour' => 'notice', // 'notice' = explain, 'silent' = just don't offer
			'parse_descriptions'  => 'yes',  // allow the CBM fallback that reads product descriptions

			// --- Campos Delivery ------------------------------------------
			'campos_badge'        => 'for large items',
			'show_fuel_icon'      => 'yes',
			'show_breakdown'      => 'yes',
			// Off by default since v0.4.1: the Shipping Class cross-check
			// is a data-quality signal for staff, and a customer can do
			// nothing with it. The comparison still rides onto the order
			// meta either way, so the diagnostic isn't lost — it just
			// isn't printed at checkout unless someone asks for it.
			'show_wc_class'       => 'no',

			// --- Request Quote --------------------------------------------
			'sales_email'         => 'sales@studiodelta.co.za',
			'quote_range_notice'  => 'yes',

			// --- When ONLY Request Quote should be offered ----------------
			// Two situations where showing a computed price would be
			// misleading rather than helpful.
			'quote_only_outside_zone' => 'yes',  // address matches none of the 60 corridors
			'quote_only_threshold'    => 60.0,   // delivery exceeds this % of the cart
			'threshold_basis'         => 'subtotal', // 'subtotal' (goods only) or 'total'

			// --- Request Quote handling ----------------------------------
			'quote_creates_order' => 'yes',  // also record a pending order
			'quote_order_status'  => 'wc-pending',
			'quote_copy_customer' => 'yes',  // send the customer a copy

			// --- 3BM Delivery (Gauteng, distance-based) -------------------
			'tbm_enabled'      => 'no',      // off until the origin is confirmed
			'tbm_rate_per_km'  => 5.0,
			'tbm_radius_km'    => 150.0,
			'tbm_round_trip'   => 'yes',     // km x 2, as the Campos card does
			'tbm_road_factor'  => 1.25,      // straight line understates road distance
			'tbm_min_charge'   => 0.0,       // 0 = no floor
			'tbm_gauteng_only' => 'yes',
			// 320 The Hillside Road, Lynnwood, Pretoria, 0181 — an
			// approximate suburb centroid, NOT a surveyed coordinate.
			// Confirm it against a map before going live; the Settings
			// screen has a distance tester for exactly that.
			'tbm_origin_lat'   => -25.7615,
			'tbm_origin_lng'   => 28.3180,

			// --- Checkout footer links -----------------------------------
			'show_policy_links' => 'yes',
			'policy_url'        => '/delivery-policy/',
			'faq_url'           => '/faq/',

			// --- Informational size labels (never affect price) -----------
			'size_small_max'      => 0.3,
			'size_medium_max'     => 1.0,
		];
	}

	/**
	 * @return array The saved settings merged over the defaults.
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, [] );
			self::$cache = wp_parse_args( is_array( $saved ) ? $saved : [], self::defaults() );
		}
		return self::$cache;
	}

	/**
	 * @param string $key
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/**
	 * Checkbox-style settings are stored as 'yes'/'no' (WooCommerce's own
	 * convention) so they read the same in the database as WC's method
	 * settings do. This is the only place that comparison is spelled out.
	 *
	 * @param string $key
	 * @return bool
	 */
	public static function is_on( $key ) {
		return 'yes' === self::get( $key );
	}

	/**
	 * VAT as a multiplier fraction (0.15), not a percentage (15) — the
	 * formula wants the fraction, the admin screen wants the percentage,
	 * and mixing them up silently mis-prices every order, so the
	 * conversion happens here once.
	 *
	 * @return float
	 */
	public static function vat_fraction() {
		return max( 0.0, (float) self::get( 'vat_rate' ) ) / 100;
	}

	/**
	 * The quote-only threshold as a fraction (0.60), not a percentage.
	 * Same reasoning as vat_fraction(): the formula wants the fraction,
	 * the admin screen wants the percentage, and confusing the two would
	 * silently change which carts get forced to a quote.
	 *
	 * @return float
	 */
	public static function threshold_fraction() {
		return max( 0.0, (float) self::get( 'quote_only_threshold' ) ) / 100;
	}

	/**
	 * Resolve a stored link setting to a usable URL. A relative path is
	 * expanded against the site root so "/faq/" works on any domain.
	 *
	 * @param string $key 'policy_url' or 'faq_url'
	 * @return string '' when not set.
	 */
	public static function link( $key ) {
		$raw = trim( (string) self::get( $key ) );
		if ( '' === $raw ) {
			return '';
		}
		return preg_match( '#^https?://#i', $raw ) ? $raw : home_url( $raw );
	}

	/**
	 * @param array $input Raw $_POST values from the Settings screen.
	 * @return array Sanitised, ready to save.
	 */
	public static function sanitise( array $input ) {
		$defaults = self::defaults();
		$clean    = [];

		foreach ( $defaults as $key => $default ) {
			// Coordinates MUST be handled before the generic float branch
			// below, which clamps negatives to zero. South African
			// latitudes are negative (-25.76), so clamping would move the
			// depot to the equator and every distance would be wrong.
			if ( in_array( $key, [ 'tbm_origin_lat', 'tbm_origin_lng' ], true ) ) {
				$raw = isset( $input[ $key ] ) ? trim( (string) $input[ $key ] ) : '';
				if ( '' === $raw || ! is_numeric( $raw ) ) {
					$clean[ $key ] = $default;
					continue;
				}
				$value = (float) $raw;
				$limit = ( 'tbm_origin_lat' === $key ) ? 90.0 : 180.0;
				// Out of range means a typo, not an intention.
				$clean[ $key ] = ( abs( $value ) <= $limit ) ? $value : $default;
				continue;
			}

			if ( is_float( $default ) ) {
				// Numeric fields: fall back to the default rather than 0 if
				// the box was cleared — a blank VAT field should not
				// silently become a 0% VAT site.
				$value         = isset( $input[ $key ] ) && '' !== trim( (string) $input[ $key ] )
					? (float) $input[ $key ]
					: $default;
				$clean[ $key ] = max( 0.0, $value );
				continue;
			}

			if ( 'yes' === $default || 'no' === $default ) {
				// Unchecked checkboxes aren't posted at all, so absence
				// means 'no' here — not "use the default".
				$clean[ $key ] = isset( $input[ $key ] ) ? 'yes' : 'no';
				continue;
			}

			if ( 'sales_email' === $key ) {
				$email         = isset( $input[ $key ] ) ? sanitize_email( wp_unslash( $input[ $key ] ) ) : '';
				$clean[ $key ] = is_email( $email ) ? $email : $default;
				continue;
			}

			if ( in_array( $key, [ 'policy_url', 'faq_url' ], true ) ) {
				// Accept either a full URL or a site-relative path — a
				// merchant typing "/faq/" should not have it mangled.
				$raw           = isset( $input[ $key ] ) ? trim( wp_unslash( $input[ $key ] ) ) : '';
				$clean[ $key ] = ( '' === $raw )
					? ''
					: ( preg_match( '#^https?://#i', $raw ) ? esc_url_raw( $raw ) : '/' . ltrim( sanitize_text_field( $raw ), '/' ) );
				continue;
			}

			if ( 'threshold_basis' === $key ) {
				$value         = isset( $input[ $key ] ) ? sanitize_text_field( wp_unslash( $input[ $key ] ) ) : '';
				$clean[ $key ] = in_array( $value, [ 'subtotal', 'total' ], true ) ? $value : $default;
				continue;
			}

			if ( 'quote_order_status' === $key ) {
				$value         = isset( $input[ $key ] ) ? sanitize_text_field( wp_unslash( $input[ $key ] ) ) : '';
				$clean[ $key ] = ( '' !== $value && function_exists( 'wc_get_order_statuses' ) && array_key_exists( $value, wc_get_order_statuses() ) )
					? $value
					: $default;
				continue;
			}

			if ( 'unmatched_behaviour' === $key ) {
				$value         = isset( $input[ $key ] ) ? sanitize_text_field( wp_unslash( $input[ $key ] ) ) : '';
				$clean[ $key ] = in_array( $value, [ 'notice', 'silent' ], true ) ? $value : $default;
				continue;
			}

			$clean[ $key ] = isset( $input[ $key ] )
				? sanitize_text_field( wp_unslash( $input[ $key ] ) )
				: $default;
		}

		// size_small_max must stay below size_medium_max or the Small/
		// Medium/Large labels become nonsense (everything lands in one
		// bucket). Swap rather than reject — the intent is obvious.
		if ( $clean['size_small_max'] >= $clean['size_medium_max'] ) {
			$clean['size_medium_max'] = $clean['size_small_max'] + 0.1;
		}

		return $clean;
	}

	/**
	 * @param array $clean Output of sanitise().
	 */
	public static function save( array $clean ) {
		update_option( self::OPTION, $clean );
		self::$cache = null;
	}
}
