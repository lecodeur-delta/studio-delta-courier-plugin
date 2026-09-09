<?php
/**
 * The data layer behind the admin console — product dimensions, the
 * product x region rate matrix, and CSV import/export shaping.
 *
 * Deliberately contains no HTML and calls no admin functions: the pages
 * under includes/admin/ do all the rendering. That split is what lets the
 * pricing maths in here be unit tested standalone (see /tmp/smoke_test5.php
 * and /tmp/smoke_test7.php) without standing up WordPress.
 *
 * Renamed from class-sd-product-dimensions-admin.php in v0.4.0, when the
 * dimensions screen became one page of a four-page console rather than a
 * screen of its own.
 *
 * Everything here reads through SD_CBM_Resolver — the same resolver
 * calculate_shipping() uses — so a price shown on the Product Rates page
 * can never quietly disagree with what a customer is charged.
 */

defined( 'ABSPATH' ) || exit;

class SD_Product_Data {

	/**
	 * Every published product, with whatever dimensions and CBM are known.
	 *
	 * @return array[] Each row: product_id, sku, name, L, W, H, D (floats
	 *         or null, mm), cbm (float|null, m³), source (string).
	 */
	public static function get_all_product_rows() {
		$rows     = [];
		$products = wc_get_products( [
			'status' => 'publish',
			'type'   => [ 'simple', 'variable' ],
			'limit'  => -1,
			'return' => 'objects',
		] );

		foreach ( $products as $product ) {
			$resolved = SD_CBM_Resolver::resolve( $product );
			$dims     = $resolved['dims'] ?? [];
			$rows[]   = [
				'product_id' => $product->get_id(),
				'sku'        => $product->get_sku(),
				'name'       => $product->get_name(),
				'L'          => $dims['L'] ?? null,
				'W'          => $dims['W'] ?? null,
				'H'          => $dims['H'] ?? null,
				'D'          => $dims['D'] ?? null,
				'cbm'        => $resolved['cbm'],
				'source'     => $resolved['source'],
			];
		}

		usort( $rows, static function ( $a, $b ) {
			return strcasecmp( (string) $a['name'], (string) $b['name'] );
		} );

		return $rows;
	}

	/**
	 * The 60 corridor rows (zone-data.php) flattened for the rates UI:
	 * one entry per destination, carrying its own rate and minimum, and
	 * grouped under its zone name for an <optgroup> dropdown.
	 *
	 * Corridors are the real pricing granularity, not zones — KwaZulu
	 * Natal alone spans R471/m³ (Durban) to R709/m³ with a R1,500 floor
	 * (South Coast, Hillcrest), so collapsing a zone to one number would
	 * misprice most of it. Hence a dropdown of 60 destinations rather
	 * than a table 60 columns wide.
	 *
	 * @return array[] Each: key, zone, zone_name, label, towns, rate, min, remote.
	 */
	public static function corridors() {
		$out = [];
		foreach ( SD_Zones::all() as $i => $row ) {
			$out[] = [
				'key'       => (string) $i,
				'zone'      => (int) $row['zone'],
				'zone_name' => $row['zone_name'],
				'label'     => implode( ' / ', $row['towns'] ),
				'towns'     => $row['towns'],
				'rate'      => (float) $row['rate_per_m3'],
				'min'       => (float) $row['min_charge'],
				'remote'    => ! empty( $row['remote_override'] ),
			];
		}
		return $out;
	}

	/**
	 * The delivery total for one product in one corridor.
	 *
	 * Deliberately a thin adapter over SD_Pricing::calculate() rather than
	 * its own copy of the arithmetic — SD_Pricing is the single home for
	 * the §2 formula, shared with live checkout, so an admin table can
	 * never drift a cent away from what a customer is actually charged.
	 * All this does is reshape a corridor's rate/min into the zone-row
	 * shape SD_Pricing expects.
	 *
	 * @param float $cbm       Cubic metres.
	 * @param float $rate      Rand per m³ for the corridor.
	 * @param float $min       Minimum charge floor for the corridor.
	 * @param float $fuel_pct  Fuel levy as a fraction, e.g. 0.33.
	 * @param float $vat_pct   VAT as a fraction, e.g. 0.15.
	 * @return array{charge: float, fuel: float, subtotal: float, vat: float, total: float}
	 */
	public static function price( $cbm, $rate, $min, $fuel_pct, $vat_pct ) {
		$p = SD_Pricing::calculate(
			$cbm,
			[ 'rate_per_m3' => $rate, 'min_charge' => $min ],
			$fuel_pct,
			$vat_pct
		);

		return [
			'charge'   => $p['charge'],
			'fuel'     => $p['fuel_amount'],
			'subtotal' => $p['subtotal'],
			'vat'      => $p['vat_amount'],
			'total'    => $p['total'],
		];
	}

	// -----------------------------------------------------------------
	// CSV: dimensions (round-trippable — this is the one that writes)
	// -----------------------------------------------------------------

	/**
	 * @param array[] $rows As returned by get_all_product_rows().
	 * @return array[] Rows of values, header first — ready for fputcsv().
	 */
	public static function build_csv_rows( $rows ) {
		$csv   = [];
		$csv[] = [ 'SKU', 'Product Name', 'Length (mm)', 'Width (mm)', 'Height (mm)', 'Depth (mm)', 'CBM (m3)', 'Source' ];
		foreach ( $rows as $row ) {
			$csv[] = [
				$row['sku'],
				$row['name'],
				$row['L'],
				$row['W'],
				$row['H'],
				$row['D'],
				$row['cbm'],
				$row['source'],
			];
		}
		return $csv;
	}

	/**
	 * Given the 4 raw CSV cells for Length/Width/Height/Depth, decide what
	 * the import should do with the row:
	 *   - exactly 3 of 4 non-blank -> compute and return a CBM (m³)
	 *   - 0 non-blank              -> 'skip', nothing was being changed
	 *   - 1, 2, or all 4 non-blank -> 'error' with a readable reason
	 *
	 * "All 4" is an error, not a guess: you confirmed Depth is an
	 * alternate name for the same axis as Length, so a row carrying both
	 * is contradictory input and the importer says so rather than picking
	 * one and silently mispricing that product forever.
	 *
	 * @param string $l
	 * @param string $w
	 * @param string $h
	 * @param string $d
	 * @return array{status: string, cbm?: float, reason?: string}
	 */
	public static function decide_row( $l, $w, $h, $d ) {
		$axes   = [ 'Length' => $l, 'Width' => $w, 'Height' => $h, 'Depth' => $d ];
		$filled = [];
		foreach ( $axes as $label => $value ) {
			if ( '' !== trim( (string) $value ) ) {
				$filled[ $label ] = (float) $value;
			}
		}

		$count = count( $filled );

		if ( 0 === $count ) {
			return [ 'status' => 'skip' ];
		}

		if ( isset( $filled['Length'] ) && isset( $filled['Depth'] ) ) {
			return [
				'status' => 'error',
				'reason' => __( 'both Length and Depth given — pick one axis (they\'re the same axis under different names); leave the other blank.', 'sd-shipping' ),
			];
		}

		if ( 3 !== $count ) {
			return [
				'status' => 'error',
				'reason' => sprintf(
					/* translators: %d: number of dimension columns filled in */
					__( 'need exactly 3 of Width/Height and Length-or-Depth filled in, found %d.', 'sd-shipping' ),
					$count
				),
			];
		}

		foreach ( $filled as $label => $value ) {
			if ( $value <= 0 ) {
				return [
					'status' => 'error',
					'reason' => sprintf(
						/* translators: %s: axis label */
						__( '%s must be a positive number.', 'sd-shipping' ),
						$label
					),
				];
			}
		}

		$values = array_values( $filled );
		$cbm    = round( ( $values[0] / 1000 ) * ( $values[1] / 1000 ) * ( $values[2] / 1000 ), 6 );

		return [ 'status' => 'ok', 'cbm' => $cbm ];
	}

	/**
	 * Read an uploaded dimensions CSV and write the CBMs it implies to
	 * product meta. Writes only to `_sd_cbm` — the same field the product
	 * edit screen uses (product-meta.php) — so an imported row simply
	 * becomes a "measured" product from then on, taking priority over
	 * description parsing exactly as SD_CBM_Resolver's priority order
	 * already documents.
	 *
	 * @param string $path Local path to the uploaded CSV.
	 * @return array{updated: int, skipped: int, notes: string[]}
	 */
	public static function import_csv( $path ) {
		$updated = 0;
		$skipped = 0;
		$notes   = [];

		$handle = fopen( $path, 'r' );
		if ( ! $handle ) {
			return [ 'updated' => 0, 'skipped' => 0, 'notes' => [ __( 'Could not read the uploaded file.', 'sd-shipping' ) ] ];
		}

		fgetcsv( $handle ); // header row

		while ( false !== ( $line = fgetcsv( $handle ) ) ) {
			// Columns: SKU, Product Name, Length, Width, Height, Depth, CBM, Source
			$sku = trim( (string) ( $line[0] ?? '' ) );
			if ( '' === $sku ) {
				continue; // nothing to match this row to
			}

			$decision = self::decide_row( $line[2] ?? '', $line[3] ?? '', $line[4] ?? '', $line[5] ?? '' );

			if ( 'skip' === $decision['status'] ) {
				continue;
			}
			if ( 'error' === $decision['status'] ) {
				$skipped++;
				$notes[] = sprintf( '%s: %s', $sku, $decision['reason'] );
				continue;
			}

			$product_id = wc_get_product_id_by_sku( $sku );
			if ( ! $product_id ) {
				$skipped++;
				$notes[] = sprintf( '%s: %s', $sku, __( 'no product found with this SKU.', 'sd-shipping' ) );
				continue;
			}

			update_post_meta( $product_id, '_sd_cbm', wc_format_decimal( $decision['cbm'] ) );
			$updated++;
		}

		fclose( $handle );

		return [ 'updated' => $updated, 'skipped' => $skipped, 'notes' => $notes ];
	}

	// -----------------------------------------------------------------
	// CSV: the product x region rate matrix (read-only export)
	// -----------------------------------------------------------------

	/**
	 * The full rate matrix as CSV rows: one line per product per
	 * corridor, with the arithmetic broken out so the numbers can be
	 * checked by hand against an invoice.
	 *
	 * Products with no known CBM are still listed once, flagged, rather
	 * than dropped — an export that silently omits them hides exactly the
	 * products someone needs to go and measure.
	 *
	 * @param array[] $rows      get_all_product_rows()
	 * @param array[] $corridors corridors()
	 * @param float   $fuel_pct
	 * @param float   $vat_pct
	 * @return array[] Header row first.
	 */
	public static function build_rates_csv_rows( $rows, $corridors, $fuel_pct, $vat_pct ) {
		$csv   = [];
		$csv[] = [
			'SKU', 'Product Name', 'CBM (m3)', 'CBM source', 'Zone', 'Zone name', 'Destination',
			'Rate per m3', 'Min charge', 'Base charge', 'Fuel %', 'Fuel amount', 'VAT', 'Total (incl VAT)',
		];

		$fuel_display = round( $fuel_pct * 100, 2 );

		foreach ( $rows as $row ) {
			if ( ! $row['cbm'] ) {
				$csv[] = [
					$row['sku'], $row['name'], '', $row['source'], '', '', '',
					'', '', '', '', '', '', 'NOT PRICED — no CBM on file',
				];
				continue;
			}

			foreach ( $corridors as $c ) {
				$p     = self::price( $row['cbm'], $c['rate'], $c['min'], $fuel_pct, $vat_pct );
				$csv[] = [
					$row['sku'],
					$row['name'],
					$row['cbm'],
					$row['source'],
					$c['zone'],
					$c['zone_name'],
					$c['label'],
					$c['rate'],
					$c['min'],
					$p['charge'],
					$fuel_display,
					$p['fuel'],
					$p['vat'],
					$p['total'],
				];
			}
		}

		return $csv;
	}

	/**
	 * The fuel levy history as CSV rows — the audit trail behind every
	 * past order's shipping total, exportable for whoever asks.
	 *
	 * @return array[] Header row first.
	 */
	public static function build_levy_csv_rows() {
		$csv   = [];
		$csv[] = [ 'Effective from', 'Zone', 'Percentage', 'Set by (user ID)', 'Set at' ];

		$zone_names = [];
		foreach ( SD_Zones::all() as $row ) {
			$zone_names[ $row['zone'] ] = $row['zone_name'];
		}

		foreach ( SD_Fuel_Levy::get_history( 500 ) as $row ) {
			$csv[] = [
				$row->effective_from,
				null === $row->zone_id ? 'All zones' : ( $zone_names[ $row->zone_id ] ?? $row->zone_id ),
				round( (float) $row->percentage * 100, 2 ),
				$row->set_by,
				$row->set_at,
			];
		}

		return $csv;
	}

	/**
	 * Stream an array of rows as a CSV download and stop. Shared by every
	 * export on the Export page so they all behave identically (headers,
	 * no-cache, UTF-8).
	 *
	 * @param array[] $csv_rows
	 * @param string  $filename
	 */
	public static function stream_csv( $csv_rows, $filename ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$out = fopen( 'php://output', 'w' );
		foreach ( $csv_rows as $row ) {
			fputcsv( $out, $row );
		}
		fclose( $out );
		exit;
	}
}
