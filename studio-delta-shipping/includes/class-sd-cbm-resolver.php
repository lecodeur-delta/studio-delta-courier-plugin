<?php
/**
 * Resolves a per-product CBM (m³) for shipping pricing, with a fallback
 * for products that were never given a manually-measured _sd_cbm value.
 *
 * Priority order:
 *   1. _sd_cbm product meta (see product-meta.php) — a number someone
 *      actually measured and entered. Always wins if present and > 0.
 *   2. A "NNNmm W x NNNmm D x NNNmm H"-style triple parsed out of the
 *      product description or short description. Same regex shape used
 *      to mine dimensions out of the 2026/2027 order history spreadsheet
 *      (see the doc set, §1/§11) — requires each number to be explicitly
 *      axis-labelled (W/D/H/L) so it doesn't false-positive on something
 *      like "19mm tubular steel frame" in a product description.
 *   3. Neither — cbm is null. The shipping method treats that item as
 *      contributing zero volume and flags it, rather than guessing.
 *
 * A parsed-from-description CBM is never silently written back to
 * _sd_cbm — it's marked with a distinct source so admins and the
 * checkout breakdown can tell a measured figure from an inferred one.
 */

defined( 'ABSPATH' ) || exit;

class SD_CBM_Resolver {

	/**
	 * Same shape as the Python DIM_RE used to mine the order spreadsheet:
	 * three explicit axis-labelled "NNNmm[WDHL]" groups joined by "x".
	 * The axis letter is captured too (not just discarded) so callers can
	 * tell which of Length/Width/Height/Depth each number actually was —
	 * see parse_dimensions() below. L and D are treated as the same axis
	 * used under different names depending on product type (a cabinet's
	 * "Depth" and a table's "Length" both mean "front-to-back / long
	 * horizontal axis") — real products use one or the other, never both
	 * at once, so there is no ambiguity about which 3 numbers multiply
	 * together: whichever 3 axis letters appear are the 3 that count.
	 */
	const DIM_PATTERN = '/(\d+(?:\.\d+)?)\s*mm\s*([WDHL])\s*x\s*(\d+(?:\.\d+)?)\s*mm\s*([WDHL])\s*x\s*(\d+(?:\.\d+)?)\s*mm\s*([WDHL])/i';

	/**
	 * Parses a "NNNmm W x NNNmm D x NNNmm H"-style triple out of free text
	 * and returns the value found for each axis, plus the resulting CBM.
	 * Only 3 of the 4 possible letters (W, H, and either L or D) are ever
	 * populated for a given product — the other of L/D stays null.
	 *
	 * @param string $text
	 * @return array{L: float|null, W: float|null, H: float|null, D: float|null, cbm: float|null}
	 */
	public static function parse_dimensions( $text ) {
		$empty = [ 'L' => null, 'W' => null, 'H' => null, 'D' => null, 'cbm' => null ];
		if ( '' === trim( (string) $text ) ) {
			return $empty;
		}
		$plain = wp_strip_all_tags( $text );
		if ( ! preg_match( self::DIM_PATTERN, $plain, $m ) ) {
			return $empty;
		}
		$axes = $empty;
		unset( $axes['cbm'] );
		$values = [ (float) $m[1], (float) $m[3], (float) $m[5] ];
		$letters = [ strtoupper( $m[2] ), strtoupper( $m[4] ), strtoupper( $m[6] ) ];
		foreach ( $letters as $i => $letter ) {
			$axes[ $letter ] = $values[ $i ];
		}
		$cbm = round( ( $values[0] / 1000 ) * ( $values[1] / 1000 ) * ( $values[2] / 1000 ), 6 );
		return array_merge( $axes, [ 'cbm' => $cbm > 0 ? $cbm : null ] );
	}

	/**
	 * @param WC_Product $product
	 * @return array{cbm: float|null, source: string, dims: array} source
	 *         is one of 'measured', 'parsed from description', 'missing'.
	 *         'dims' is only populated (L/W/H/D) when source is 'parsed
	 *         from description' — a manually measured CBM has no known
	 *         per-axis breakdown, since only the final number was entered.
	 */
	public static function resolve( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return [ 'cbm' => null, 'source' => 'missing', 'dims' => [] ];
		}

		$measured = (float) $product->get_meta( '_sd_cbm', true );
		if ( $measured > 0 ) {
			return [ 'cbm' => $measured, 'source' => 'measured', 'dims' => [] ];
		}

		// The description fallback can be switched off entirely (Studio
		// Delta > Settings) for sites that would rather a product with no
		// measured CBM be obviously unpriced than approximately priced.
		if ( class_exists( 'SD_Settings' ) && ! SD_Settings::is_on( 'parse_descriptions' ) ) {
			return [ 'cbm' => null, 'source' => 'missing', 'dims' => [] ];
		}

		foreach ( [ $product->get_description(), $product->get_short_description() ] as $text ) {
			$parsed = self::parse_dimensions( $text );
			if ( $parsed['cbm'] ) {
				return [
					'cbm'    => $parsed['cbm'],
					'source' => 'parsed from description',
					'dims'   => [ 'L' => $parsed['L'], 'W' => $parsed['W'], 'H' => $parsed['H'], 'D' => $parsed['D'] ],
				];
			}
		}

		return [ 'cbm' => null, 'source' => 'missing', 'dims' => [] ];
	}

	/**
	 * Informational size grouping shown to the customer at checkout.
	 *
	 * The thresholds are editable at Studio Delta > Settings and default
	 * to <0.3 / 0.3-1.0 / >=1.0 m³. They are a display convenience, not a
	 * confirmed business rule: they exist so the checkout breakdown can
	 * label an item "Small / Medium / Large" for readability — they do
	 * NOT change price, and moving them changes no customer's total. The
	 * doc set (§7, "Multi-item cart logic") already covers the one
	 * proposal that WOULD have changed price by size class (the A/B/C
	 * half-price model from Studio_Delta_Delivery_Pricing.xlsx) and why
	 * it wasn't built: its Rand rates were never confirmed. If Delta BEC
	 * ever confirms real class boundaries and rates, replace the numbers
	 * below and wire the discount logic described there — don't treat
	 * this labelling as that model.
	 *
	 * @param float|null $cbm
	 * @return string|null
	 */
	public static function size_class( $cbm ) {
		if ( null === $cbm || $cbm <= 0 ) {
			return null;
		}

		$small  = 0.3;
		$medium = 1.0;
		if ( class_exists( 'SD_Settings' ) ) {
			$small  = (float) SD_Settings::get( 'size_small_max' );
			$medium = (float) SD_Settings::get( 'size_medium_max' );
		}

		if ( $cbm < $small ) {
			return 'Small';
		}
		if ( $cbm < $medium ) {
			return 'Medium';
		}
		return 'Large';
	}

	/**
	 * Reads the product's real WooCommerce Shipping Class (Product data >
	 * Shipping > Shipping class) — the actual A/B/C / Small/Medium/Large
	 * taxonomy your team already assigns products to. This is NOT used
	 * to compute price (calculate_shipping() still runs purely off CBM,
	 * see §2/§12) — you told us the class assignments themselves aren't
	 * reliable, so treating them as a pricing input would just import
	 * that unreliability into every quote. It's read here so it can be
	 * shown next to the CBM-derived size and flagged when the two
	 * disagree — that disagreement IS the useful signal: it's exactly
	 * the products whose Shipping Class assignment is worth someone
	 * double-checking.
	 *
	 * @param WC_Product $product
	 * @return string|null The class's display name (e.g. "Large"), or
	 *         null if the product has no shipping class assigned.
	 */
	public static function wc_shipping_class( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return null;
		}
		$slug = $product->get_shipping_class();
		if ( '' === $slug ) {
			return null;
		}
		$term = get_term_by( 'slug', $slug, 'product_shipping_class' );
		return $term ? $term->name : $slug;
	}

	/**
	 * Normalises a class name/slug from either side (WooCommerce's
	 * Shipping Class taxonomy, or our own CBM-derived label) down to
	 * Small/Medium/Large so the two can be compared. Recognises the
	 * obvious variants — "A"/"Class A"/"small", "B"/"medium", "C"/
	 * "large" — and returns null for anything it doesn't recognise
	 * rather than guessing, so an unfamiliar class name is shown as-is
	 * with no (possibly wrong) mismatch flag attached.
	 *
	 * @param string|null $label
	 * @return string|null
	 */
	public static function normalise_class( $label ) {
		if ( null === $label || '' === trim( (string) $label ) ) {
			return null;
		}
		$l = strtolower( trim( $label ) );
		if ( in_array( $l, [ 'a', 'class a', 'small', 's' ], true ) ) {
			return 'Small';
		}
		if ( in_array( $l, [ 'b', 'class b', 'medium', 'med', 'm' ], true ) ) {
			return 'Medium';
		}
		if ( in_array( $l, [ 'c', 'class c', 'large', 'l' ], true ) ) {
			return 'Large';
		}
		return null;
	}
}
