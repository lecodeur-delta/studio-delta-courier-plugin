<?php
/**
 * Distance from the 3BM depot to a customer, for the Gauteng
 * distance-based method (class-sd-shipping-method-3bm.php).
 *
 * HOW THIS WORKS, AND WHAT IT IS NOT
 * There is no geocoding API call here. A checkout that depends on an
 * external HTTP request is a checkout that breaks when that service is
 * slow, rate-limited, or unbilled - and it leaks every customer address
 * to a third party on every keystroke. Instead this file carries a table
 * of approximate coordinates for Gauteng towns and suburbs, matches the
 * customer's town against it the same way SD_Zones matches corridors,
 * and measures the great-circle distance.
 *
 * That gives a STRAIGHT-LINE distance, which always understates the road
 * distance. A configurable road factor (default 1.25) scales it up. The
 * factor is a setting rather than a constant precisely because it is an
 * approximation someone should tune against real trips.
 *
 * THE COORDINATES ARE APPROXIMATE SUBURB CENTROIDS, not surveyed points,
 * and the depot origin is likewise an approximation of 320 The Hillside
 * Road, Lynnwood. Confirm both before going live - Studio Delta >
 * Settings has a distance tester that shows the computed km and price
 * for any town, so this is checkable rather than a black box.
 *
 * When a town is not in the table, no distance is returned and 3BM is
 * simply not offered. Guessing a distance would mean guessing a price.
 * Unmatched towns are logged so the table can be extended - see
 * SD_Geo::log_unmatched() and the filter below.
 */

defined( 'ABSPATH' ) || exit;

class SD_Geo {

	/** Earth's mean radius in kilometres. */
	const EARTH_KM = 6371.0088;

	/** Where unmatched town names accumulate, so gaps are visible. */
	const UNMATCHED_OPTION = 'sd_geo_unmatched';

	/**
	 * Approximate centroids for Gauteng towns, suburbs and metros, plus
	 * the nearer North West / Mpumalanga / Free State towns that fall
	 * inside a 150km radius of Pretoria and so are reachable.
	 *
	 * Keys are normalised town names (lowercase, alphanumeric + spaces).
	 * Values are [ latitude, longitude ].
	 *
	 * Extend or override with the sd_geo_coordinates filter rather than
	 * editing this list, so a plugin update doesn't discard your work.
	 *
	 * @return array<string, array{0: float, 1: float}>
	 */
	public static function coordinates() {
		$coords = [
			// --- Pretoria / Tshwane ---------------------------------------
			'pretoria'          => [ -25.7479, 28.2293 ],
			'tshwane'           => [ -25.7479, 28.2293 ],
			'lynnwood'          => [ -25.7615, 28.3180 ],
			'menlyn'            => [ -25.7830, 28.2770 ],
			'menlo park'        => [ -25.7720, 28.2600 ],
			'brooklyn'          => [ -25.7660, 28.2360 ],
			'hatfield'          => [ -25.7480, 28.2380 ],
			'arcadia'           => [ -25.7450, 28.2110 ],
			'silver lakes'      => [ -25.7850, 28.3700 ],
			'garsfontein'       => [ -25.8000, 28.3200 ],
			'faerie glen'       => [ -25.7830, 28.3230 ],
			'moreleta park'     => [ -25.8330, 28.3000 ],
			'centurion'         => [ -25.8603, 28.1894 ],
			'irene'             => [ -25.8770, 28.2160 ],
			'midstream'         => [ -25.9160, 28.1900 ],
			'akasia'            => [ -25.6420, 28.1080 ],
			'pretoria north'    => [ -25.6720, 28.1780 ],
			'montana'           => [ -25.6720, 28.2350 ],
			'wonderboom'        => [ -25.6800, 28.2100 ],
			'soshanguve'        => [ -25.5240, 28.1080 ],
			'mabopane'          => [ -25.5000, 28.1000 ],
			'ga rankuwa'        => [ -25.6180, 27.9990 ],
			'atteridgeville'    => [ -25.7700, 28.0700 ],
			'mamelodi'          => [ -25.7200, 28.3900 ],
			'hammanskraal'      => [ -25.4020, 28.2830 ],
			'cullinan'          => [ -25.6740, 28.5230 ],
			'bronkhorstspruit'  => [ -25.8060, 28.7440 ],

			// --- Johannesburg / Ekurhuleni / West Rand --------------------
			'johannesburg'      => [ -26.2041, 28.0473 ],
			'jhb'               => [ -26.2041, 28.0473 ],
			'sandton'           => [ -26.1076, 28.0567 ],
			'rosebank'          => [ -26.1440, 28.0430 ],
			'randburg'          => [ -26.0940, 28.0060 ],
			'fourways'          => [ -26.0160, 28.0100 ],
			'midrand'           => [ -25.9990, 28.1280 ],
			'kyalami'           => [ -25.9930, 28.0700 ],
			'bryanston'         => [ -26.0570, 28.0230 ],
			'roodepoort'        => [ -26.1625, 27.8725 ],
			'krugersdorp'       => [ -26.0850, 27.7750 ],
			'soweto'            => [ -26.2678, 27.8585 ],
			'germiston'         => [ -26.2180, 28.1670 ],
			'alberton'          => [ -26.2670, 28.1220 ],
			'boksburg'          => [ -26.2120, 28.2590 ],
			'benoni'            => [ -26.1880, 28.3210 ],
			'brakpan'           => [ -26.2350, 28.3700 ],
			'springs'           => [ -26.2540, 28.4400 ],
			'kempton park'      => [ -26.1000, 28.2300 ],
			'edenvale'          => [ -26.1420, 28.1550 ],
			'nigel'             => [ -26.4260, 28.4740 ],
			'heidelberg'        => [ -26.5030, 28.3590 ],
			'vereeniging'       => [ -26.6730, 27.9260 ],
			'vanderbijlpark'    => [ -26.7100, 27.8380 ],
			'sasolburg'         => [ -26.8140, 27.8300 ],
			'meyerton'          => [ -26.5560, 28.0180 ],
			'magaliesburg'      => [ -26.0080, 27.5400 ],
			'lanseria'          => [ -25.9380, 27.9260 ],

			// --- Just outside Gauteng but inside ~150km -------------------
			'rustenburg'        => [ -25.6670, 27.2420 ],
			'brits'             => [ -25.6350, 27.7800 ],
			'hartbeespoort'     => [ -25.7480, 27.8560 ],
			'witbank'           => [ -25.8770, 29.1990 ],
			'emalahleni'        => [ -25.8770, 29.1990 ],
			'middelburg'        => [ -25.7750, 29.4640 ],
			'secunda'           => [ -26.5150, 29.2020 ],
			'potchefstroom'     => [ -26.7150, 27.0970 ],
			'klerksdorp'        => [ -26.8520, 26.6660 ],
			'parys'             => [ -26.9030, 27.4560 ],
			'bela bela'         => [ -24.8830, 28.2910 ],
			'warmbaths'         => [ -24.8830, 28.2910 ],
			'modimolle'         => [ -24.7000, 28.4100 ],
			'nylstroom'         => [ -24.7000, 28.4100 ],
		];

		/**
		 * Add or correct coordinates without editing the plugin.
		 *
		 * add_filter( 'sd_geo_coordinates', function ( $c ) {
		 *     $c['my town'] = [ -25.1234, 28.1234 ];
		 *     return $c;
		 * } );
		 */
		return apply_filters( 'sd_geo_coordinates', $coords );
	}

	/**
	 * Same normalisation SD_Zones uses, so a town spelled with hyphens,
	 * extra spaces or punctuation matches either table identically.
	 *
	 * @param string $string
	 * @return string
	 */
	public static function normalise( $string ) {
		$string = strtolower( trim( (string) $string ) );
		$string = preg_replace( '/[^a-z0-9\s]/', ' ', $string );
		return trim( preg_replace( '/\s+/', ' ', $string ) );
	}

	/**
	 * Look up a town name. Tries an exact match first, then a "contains"
	 * match so "Sandton, Johannesburg" or "Lynnwood Ridge" still resolve.
	 *
	 * The contains pass requires the table entry to be at least 4
	 * characters, otherwise a short name would match almost anything -
	 * the same guard SD_Zones::resolve() uses for the same reason.
	 *
	 * @param string $town
	 * @return array{0: float, 1: float}|null
	 */
	public static function lookup( $town ) {
		$needle = self::normalise( $town );
		if ( '' === $needle ) {
			return null;
		}

		$coords = self::coordinates();

		if ( isset( $coords[ $needle ] ) ) {
			return $coords[ $needle ];
		}

		foreach ( $coords as $name => $latlng ) {
			if ( strlen( $name ) > 3 && false !== strpos( $needle, $name ) ) {
				return $latlng;
			}
		}

		return null;
	}

	/**
	 * Great-circle distance between two points, in kilometres.
	 *
	 * Haversine rather than a flat-earth approximation: over 150km at
	 * Gauteng's latitude the difference is small but free to avoid, and
	 * the formula is stable for the short distances that matter here
	 * (the naive spherical cosine law loses precision on those).
	 *
	 * @param float $lat1
	 * @param float $lng1
	 * @param float $lat2
	 * @param float $lng2
	 * @return float
	 */
	public static function haversine_km( $lat1, $lng1, $lat2, $lng2 ) {
		$phi1 = deg2rad( (float) $lat1 );
		$phi2 = deg2rad( (float) $lat2 );
		$dphi = $phi2 - $phi1;
		$dlam = deg2rad( (float) $lng2 - (float) $lng1 );

		$a = sin( $dphi / 2 ) ** 2
			+ cos( $phi1 ) * cos( $phi2 ) * sin( $dlam / 2 ) ** 2;

		return 2 * self::EARTH_KM * asin( min( 1.0, sqrt( $a ) ) );
	}

	/**
	 * Distance from the configured depot to a destination town.
	 *
	 * @param string     $town
	 * @param float|null $road_factor Null reads the configured factor.
	 * @return array{km: float, straight_km: float, town: string}|null
	 *         Null when the town isn't in the table - the caller must then
	 *         not offer the method rather than assume a distance.
	 */
	public static function distance_to( $town, $road_factor = null ) {
		$latlng = self::lookup( $town );
		if ( null === $latlng ) {
			self::log_unmatched( $town );
			return null;
		}

		$origin_lat = (float) SD_Settings::get( 'tbm_origin_lat' );
		$origin_lng = (float) SD_Settings::get( 'tbm_origin_lng' );

		if ( null === $road_factor ) {
			$road_factor = (float) SD_Settings::get( 'tbm_road_factor' );
		}
		$road_factor = $road_factor > 0 ? $road_factor : 1.0;

		$straight = self::haversine_km( $origin_lat, $origin_lng, $latlng[0], $latlng[1] );

		return [
			'km'          => round( $straight * $road_factor, 2 ),
			'straight_km' => round( $straight, 2 ),
			'town'        => self::normalise( $town ),
		];
	}

	/**
	 * Remember a town we couldn't place, so the gaps in the table are
	 * discoverable instead of invisible. Capped at 50 entries - this is a
	 * to-do list, not an address log, and it stores only the town name a
	 * customer typed, never a full address.
	 *
	 * @param string $town
	 */
	public static function log_unmatched( $town ) {
		$town = self::normalise( $town );
		if ( '' === $town ) {
			return;
		}

		$list = get_option( self::UNMATCHED_OPTION, [] );
		$list = is_array( $list ) ? $list : [];

		if ( isset( $list[ $town ] ) ) {
			$list[ $town ] = (int) $list[ $town ] + 1;
		} elseif ( count( $list ) < 50 ) {
			$list[ $town ] = 1;
		} else {
			return; // full; nothing more to learn from another entry
		}

		update_option( self::UNMATCHED_OPTION, $list, false );
	}

	/**
	 * @return array<string, int> town => times seen
	 */
	public static function unmatched() {
		$list = get_option( self::UNMATCHED_OPTION, [] );
		return is_array( $list ) ? $list : [];
	}

	public static function clear_unmatched() {
		delete_option( self::UNMATCHED_OPTION );
	}
}
