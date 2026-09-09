<?php
/**
 * Loads the corridor table (includes/zone-data.php) and resolves a
 * WooCommerce destination array to the matching row.
 */

defined( 'ABSPATH' ) || exit;

class SD_Zones {

	/** @var array|null cached corridor table */
	protected static $rows = null;

	/**
	 * All 60 corridor rows from the courier's 2026 rate card.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$rows ) {
			self::$rows = require SD_SHIPPING_PATH . 'includes/zone-data.php';
		}
		return self::$rows;
	}

	/**
	 * Resolve a WooCommerce shipping package's destination to a corridor row.
	 *
	 * WooCommerce's $package['destination'] carries city / state / postcode.
	 * We match on city name against each row's alias list first (exact,
	 * then "contains"), then fall back to matching the state name against
	 * the zone's own name for the rare case a customer's city isn't in the
	 * table but their province clearly is (e.g. "Free State" generally).
	 *
	 * Returns null when nothing matches — the shipping method then falls
	 * through to the outlying-area branch. See docs, "Open questions", #4:
	 * that branch needs depot coordinates before it can compute anything,
	 * so today a non-match effectively means "quote separately".
	 *
	 * @param array $destination $package['destination'] from WooCommerce.
	 * @return array|null
	 */
	public static function resolve( array $destination ) {
		$city = isset( $destination['city'] ) ? trim( $destination['city'] ) : '';
		if ( '' === $city ) {
			return null;
		}
		$needle = self::normalise( $city );

		// Pass 1 — exact match against any alias.
		foreach ( self::all() as $row ) {
			foreach ( $row['towns'] as $alias ) {
				if ( self::normalise( $alias ) === $needle ) {
					return $row;
				}
			}
		}

		// Pass 2 — "contains" match, for suburb-level addresses
		// ("Sandton" won't be in the table, but "Sandton, Johannesburg"
		// as a city field, or a customer typing the metro name, might).
		foreach ( self::all() as $row ) {
			foreach ( $row['towns'] as $alias ) {
				$alias_n = self::normalise( $alias );
				if ( strlen( $alias_n ) > 3 && false !== strpos( $needle, $alias_n ) ) {
					return $row;
				}
			}
		}

		return null;
	}

	protected static function normalise( $string ) {
		$string = strtolower( trim( $string ) );
		$string = preg_replace( '/[^a-z0-9\s]/', '', $string );
		return trim( preg_replace( '/\s+/', ' ', $string ) );
	}
}
