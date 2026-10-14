<?php
/**
 * The Campos Delivery pricing formula (§2 of the doc set), pulled out of
 * WC_Shipping_SD_Campos::calculate_shipping() into its own static method
 * so there is exactly one place this math lives. Both the live checkout
 * calculation and the Studio Delta > Product Rates admin table (which
 * shows every product's price across every destination corridor) call
 * this same function - a second, slightly-different copy of this formula
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
	 *                              - needs 'rate_per_m3' and 'min_charge'.
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

		$rate       = (float) $zone_row['rate_per_m3'];
		$min        = (float) $zone_row['min_charge'];
		$cbm_total  = (float) $cbm_total;

		// The volume price before the floor is considered. Kept and
		// returned because it is the difference between these two numbers
		// that explains why adding a second item to a cart can leave the
		// delivery price untouched - see 'floored' and 'cbm_at_min' below.
		$raw    = $cbm_total * $rate;
		$charge = max( $raw, $min );

		$fuel_amount = $charge * (float) $fuel_pct;
		$subtotal    = $charge + $fuel_amount;
		$vat_amount  = $subtotal * (float) $vat_pct;
		$total       = $subtotal + $vat_amount;

		return [
			'basis'       => 'per_order',
			'cbm_total'   => round( $cbm_total, 4 ),
			'rate_per_m3' => $rate,
			'min_charge'  => $min,
			// What the volume alone would have cost.
			'raw_charge'  => round( $raw, 2 ),
			'charge'      => round( $charge, 2 ),
			// True when the minimum charge is doing the work rather than
			// the volume. While this is true, adding more goods does not
			// move the price - which is correct (the courier's minimum is
			// per delivery, not per item) but needs saying out loud.
			'floored'     => $raw < $min - 0.0001,
			// How much volume this delivery already covers for the money:
			// the point at which volume overtakes the minimum.
			'cbm_at_min'  => $rate > 0 ? round( $min / $rate, 4 ) : 0.0,
			'fuel_pct'    => (float) $fuel_pct,
			'fuel_amount' => round( $fuel_amount, 2 ),
			'subtotal'    => round( $subtotal, 2 ),
			'vat_pct'     => (float) $vat_pct,
			'vat_amount'  => round( $vat_amount, 2 ),
			'total'       => round( $total, 2 ),
		];
	}

	/**
	 * Price a cart the way Campos actually invoices it: each unit priced
	 * on its own volume, each carrying the corridor's minimum in its own
	 * right, then summed.
	 *
	 * The evidence for this, from real Campos invoices:
	 *   S250617  2 x Thalia Display Cabinet to Pietermaritzburg  R1,680  = 2 x R840
	 *   S250614  Room Divider + Sideboard to Hilton              R1,680  = 2 x R840
	 * Both were billed two minimums, not one. Note the counter-example
	 * though: S260073, two small Angelica Planter Boxes (0.24 m3 each) to
	 * Somerset West, was billed a single R840. Campos appears to treat a
	 * substantial piece as its own consignment while small items travel
	 * together, and no threshold for that is documented anywhere. So this
	 * mode will over-charge a cart of several small items compared with
	 * that one invoice; per_order mode remains available for exactly that
	 * reason and the choice is a setting.
	 *
	 * The minimum applies per UNIT, not per cart line: two identical
	 * cabinets are one WooCommerce line with qty 2, and Campos billed
	 * that as two minimums.
	 *
	 * @param array      $lines    Each: ['cbm' => float|null, 'qty' => int, 'name' => string]
	 * @param array      $zone_row Corridor row: rate_per_m3, min_charge
	 * @param float      $fuel_pct
	 * @param float|null $vat_pct  Null reads the configured VAT rate.
	 * @return array Same shape as calculate(), plus 'lines' with each
	 *         line's own volume, raw price and charge.
	 */
	public static function calculate_items( array $lines, array $zone_row, $fuel_pct, $vat_pct = null ) {
		if ( null === $vat_pct ) {
			$vat_pct = class_exists( 'SD_Settings' ) ? SD_Settings::vat_fraction() : 0.15;
		}

		$rate = (float) $zone_row['rate_per_m3'];
		$min  = (float) $zone_row['min_charge'];

		$charge    = 0.0;
		$raw_total = 0.0;
		$cbm_total = 0.0;
		$priced    = [];
		$skipped   = 0;

		foreach ( $lines as $line ) {
			$cbm = (float) ( $line['cbm'] ?? 0 );

			// A missing qty key means one unit; an explicit zero or negative
			// means no units, and charging a minimum for a unit that isn't
			// in the cart would be inventing money. Counted rather than
			// silently dropped so the caller can tell the difference between
			// "empty cart" and "every line was malformed" - see the
			// zero-charge guard in WC_Shipping_SD_Campos.
			$qty = (int) ( $line['qty'] ?? 1 );
			if ( $qty < 1 ) {
				$skipped++;
				continue;
			}

			// Per unit: volume price for one, floored, then multiplied by
			// how many. An unsized unit still carries the minimum, because
			// the courier still has to move it.
			$unit_raw    = $cbm * $rate;
			$unit_charge = max( $unit_raw, $min );

			$line_raw    = $unit_raw * $qty;
			$line_charge = $unit_charge * $qty;

			$charge    += $line_charge;
			$raw_total += $line_raw;
			$cbm_total += $cbm * $qty;

			$priced[] = [
				'name'        => $line['name'] ?? '',
				'qty'         => $qty,
				'cbm_each'    => round( $cbm, 4 ),
				'cbm_line'    => round( $cbm * $qty, 4 ),
				'unit_raw'    => round( $unit_raw, 2 ),
				'unit_charge' => round( $unit_charge, 2 ),
				'line_charge' => round( $line_charge, 2 ),
				'floored'     => $unit_raw < $min - 0.0001,
			];
		}

		$fuel_amount = $charge * (float) $fuel_pct;
		$subtotal    = $charge + $fuel_amount;
		$vat_amount  = $subtotal * (float) $vat_pct;

		return [
			'basis'       => 'per_item',
			'cbm_total'   => round( $cbm_total, 4 ),
			'rate_per_m3' => $rate,
			'min_charge'  => $min,
			'raw_charge'  => round( $raw_total, 2 ),
			'charge'      => round( $charge, 2 ),
			// True when at least one unit was lifted to the minimum.
			'floored'     => (bool) array_filter( $priced, static function ( $l ) { return $l['floored']; } ),
			'cbm_at_min'  => $rate > 0 ? round( $min / $rate, 4 ) : 0.0,
			'fuel_pct'    => (float) $fuel_pct,
			'fuel_amount' => round( $fuel_amount, 2 ),
			'subtotal'    => round( $subtotal, 2 ),
			'vat_pct'     => (float) $vat_pct,
			'vat_amount'  => round( $vat_amount, 2 ),
			'total'       => round( $subtotal + $vat_amount, 2 ),
			'lines'       => $priced,
			// Lines that carried no units and so were not priced.
			'skipped'     => $skipped,
		];
	}
}
