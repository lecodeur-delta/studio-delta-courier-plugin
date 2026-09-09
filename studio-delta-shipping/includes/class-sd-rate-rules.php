<?php
/**
 * The two situations where showing a computed delivery price does the
 * customer a disservice, and Request a Quote should be the only option:
 *
 *   1. The address matches none of the 60 Campos corridors. Campos
 *      already declines to quote there (see calculate_shipping()), so
 *      what's left is a flat rate designed for somewhere else — a
 *      Gauteng-priced rate on a Northern Cape farm is a number nobody
 *      should be held to.
 *
 *   2. Delivery would cost more than 60% of the cart. At that point the
 *      price is doing the selling, badly. Better to have a human quote
 *      it and perhaps find a cheaper way than to show a customer R2,294
 *      of delivery on R3,000 of goods and lose them at checkout.
 *
 * Both are configurable and both can be switched off.
 *
 * IMPORTANT: this filter never invents or alters a price. It only
 * removes options from the list WooCommerce has already calculated. And
 * it only ever removes them when Request a Quote is actually available
 * to take their place — stripping every method would leave a customer
 * with no way to check out at all, which is worse than an awkward price.
 */

defined( 'ABSPATH' ) || exit;

class SD_Rate_Rules {

	const QUOTE_PREFIX  = 'sd_request_quote';
	const CAMPOS_PREFIX = 'sd_campos_delivery';

	public static function init() {
		// Priority 20: after every method has added its rates, and after
		// most other plugins that add their own.
		add_filter( 'woocommerce_package_rates', [ __CLASS__, 'filter_rates' ], 20, 2 );
	}

	/**
	 * @param array $rates   rate_id => WC_Shipping_Rate
	 * @param array $package
	 * @return array
	 */
	public static function filter_rates( $rates, $package ) {
		if ( empty( $rates ) || ! class_exists( 'SD_Settings' ) ) {
			return $rates;
		}

		$quote_ids = self::quote_rate_ids( $rates );
		if ( empty( $quote_ids ) ) {
			// No quote option in this zone, so there is nothing to fall
			// back to. Leave the customer's options alone.
			return $rates;
		}

		$reason = self::quote_only_reason( $rates, $package );
		if ( null === $reason ) {
			return $rates;
		}

		// Keep only the quote option(s), and record why for the notice
		// that class-sd-checkout-display.php renders.
		$kept = [];
		foreach ( $quote_ids as $id ) {
			$kept[ $id ] = $rates[ $id ];
		}

		self::$reason = $reason;

		return $kept;
	}

	/** @var array|null why quote-only was applied, for the checkout notice */
	protected static $reason = null;

	/**
	 * @return array|null ['code' => string, 'text' => string]
	 */
	public static function applied_reason() {
		return self::$reason;
	}

	/**
	 * @param array $rates
	 * @return string[] rate ids belonging to the quote method
	 */
	protected static function quote_rate_ids( $rates ) {
		$ids = [];
		foreach ( $rates as $id => $rate ) {
			if ( 0 === strpos( $id, self::QUOTE_PREFIX ) ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * Decide whether this package should be quote-only, and why.
	 *
	 * @param array $rates
	 * @param array $package
	 * @return array|null ['code','text'] or null to leave the rates alone
	 */
	protected static function quote_only_reason( $rates, $package ) {

		// --- Rule 1: outside the Campos corridor table -------------------
		if ( SD_Settings::is_on( 'quote_only_outside_zone' ) ) {
			$destination = $package['destination'] ?? [];
			$in_za       = 'ZA' === ( $destination['country'] ?? '' );
			$city        = trim( (string) ( $destination['city'] ?? '' ) );

			if ( $in_za && '' !== $city && class_exists( 'SD_Zones' ) ) {
				$corridor = SD_Zones::resolve( $destination );
				if ( null === $corridor ) {
					return [
						'code' => 'outside_zone',
						'text' => __( 'Your area is outside our standard delivery routes, so delivery is quoted individually rather than priced automatically.', 'sd-shipping' ),
					];
				}
			}
		}

		// --- Rule 2: delivery is a disproportionate share of the cart ----
		$threshold = SD_Settings::threshold_fraction();
		if ( $threshold > 0 ) {
			$basis = self::cart_basis();

			if ( $basis > 0 ) {
				$cheapest = self::cheapest_priced_rate( $rates );

				// Only a real, priced alternative counts. A R0 quote rate
				// is not "cheap delivery", it is the absence of a price.
				if ( null !== $cheapest && $cheapest > $basis * $threshold ) {
					return [
						'code' => 'over_threshold',
						'text' => sprintf(
							/* translators: %s: threshold percentage */
							__( 'Delivery for this order would come to more than %s%% of the order value, so we would rather quote it by hand and look for a better option for you.', 'sd-shipping' ),
							esc_html( rtrim( rtrim( number_format( $threshold * 100, 1 ), '0' ), '.' ) )
						),
					];
				}
			}
		}

		return null;
	}

	/**
	 * What the delivery cost is measured against.
	 *
	 * 'subtotal' is the goods value excluding tax and excluding shipping
	 * — the honest denominator for "how much of this order is delivery".
	 * Including shipping in its own comparison would be circular.
	 *
	 * @return float
	 */
	protected static function cart_basis() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return 0.0;
		}
		$cart = WC()->cart;

		if ( 'total' === SD_Settings::get( 'threshold_basis' ) ) {
			// Goods plus their tax, still excluding shipping.
			return (float) $cart->get_subtotal() + (float) $cart->get_subtotal_tax();
		}

		return (float) $cart->get_subtotal();
	}

	/**
	 * The cheapest genuinely-priced delivery option, ignoring the quote
	 * method itself and any free (zero-cost) option — a zone offering
	 * free shipping or collection is already the customer's best answer,
	 * and forcing a quote over the top of it would be perverse.
	 *
	 * @param array $rates
	 * @return float|null
	 */
	protected static function cheapest_priced_rate( $rates ) {
		$cheapest = null;

		foreach ( $rates as $id => $rate ) {
			if ( 0 === strpos( $id, self::QUOTE_PREFIX ) ) {
				continue;
			}
			$cost = (float) $rate->get_cost();
			if ( $cost <= 0 ) {
				// Free shipping or local collection is available, so
				// delivery is not a barrier on this order at all.
				return null;
			}
			if ( null === $cheapest || $cost < $cheapest ) {
				$cheapest = $cost;
			}
		}

		return $cheapest;
	}
}
