<?php
/**
 * The Campos Delivery pricing formula (§2 of the doc set), pulled out of
 * WC_Shipping_SD_Campos::calculate_shipping() into its own static method
 * so there is exactly one place this math lives. Both the live checkout
 * calculation and the Studio Delta > Product Rates admin table (which
 * shows every product's price across every destination corridor) call
 * this same function — a second, slightly-different copy of this formula
 * living in an admin screen is exactly the kind of drift this whole
 * project was built to eliminate (see the fuel levy's three disagreeing
 * sources, doc §1).
 */

defined( 'ABSPATH' ) || exit;

class SD_Pricing {

	/**
	 * charge   = MAX( cbm_total x rate_per_m3, min_charge )
	 * fuel     = charge x fuel_pct
	 * subtotal = charge + fuel
	 * vat      = subtotal x vat_pct
	 * total    = subtotal + vat
	 *
	 * @param float      $cbm_total Total CBM (m³) being priced.
	 * @param array      $zone_row  A row from SD_Zones::all() / zone-data.php
	 *                              — needs 'rate_per_m3' and 'min_charge'.
	 * @param float      $fuel_pct  e.g. 0.33 for 33%.
	 * @param float|null $vat_pct   e.g. 0.15. Null reads the configured VAT
	 *                              rate from SD_Settings; pass it explicitly
	 *                              from tests, or anywhere a historical rate
	 *                              needs reproducing rather than today's.
	 * @return array{charge: float, fuel_pct: float, fuel_amount: float, subtotal: float, vat_pct: float, vat_amount: float, total: float}
	 */
	public static function calculate( $cbm_total, array $zone_row, $fuel_pct, $vat_pct = null ) {
		if ( null === $vat_pct ) {
			$vat_pct = class_exists( 'SD_Settings' ) ? SD_Settings::vat_fraction() : 0.15;
		}

		$charge      = max( (float) $cbm_total * (float) $zone_row['rate_per_m3'], (float) $zone_row['min_charge'] );
		$fuel_amount = $charge * (float) $fuel_pct;
		$subtotal    = $charge + $fuel_amount;
		$vat_amount  = $subtotal * (float) $vat_pct;
		$total       = $subtotal + $vat_amount;

		return [
			'charge'      => round( $charge, 2 ),
			'fuel_pct'    => (float) $fuel_pct,
			'fuel_amount' => round( $fuel_amount, 2 ),
			'subtotal'    => round( $subtotal, 2 ),
			'vat_pct'     => (float) $vat_pct,
			'vat_amount'  => round( $vat_amount, 2 ),
			'total'       => round( $total, 2 ),
		];
	}
}
