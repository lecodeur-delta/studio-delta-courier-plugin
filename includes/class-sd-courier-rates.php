<?php
/**
 * Standard Nationwide Shipping: the courier price table and the maths
 * that turns a cart into one delivery price.
 *
 * The table is Studio Delta's own courier price list - a flat Rand price
 * per product, per province - rather than anything volume-based. It ships
 * seeded with the 12 products Studio Delta already sends by courier and
 * is edited at Studio Delta > Standard Nationwide, where rows can be
 * added for more products.
 *
 * The rules, as agreed:
 *   - Price = each item's price for the delivery province x quantity,
 *     added together. Charged exactly as listed: VAT is already included
 *     and no fuel levy is added.
 *   - Offered only when EVERY item in the cart has a price for that
 *     province. One bulky item, or a blank price, and the option is
 *     hidden so Campos / Request a Quote take the order instead. A blank
 *     price is never treated as free.
 *   - Gauteng is a column like any other, empty until prices are added.
 *
 * Which row a product belongs to is decided in this order:
 *   1. The product (or its parent, for a variation) is on the exclusion
 *      list -> never by courier.
 *   2. The product (or its parent) is linked to a row explicitly, from
 *      the admin table or the product's own Shipping tab.
 *   3. Its name contains every word of a row's match phrase ("Small
 *      Planter" matches "Angelica Planter - Small"). Where several rows
 *      match, the phrase with the most words wins, so "Thandi Bedside
 *      Cabinet" never catches the Thandi Display Cabinet.
 *
 * Everything below the WordPress-facing helpers is a pure function of its
 * arguments, so it can be tested without WordPress (smoke_test12.php).
 *
 * Built by Levi Bugera - Delta Creative Agency - https://www.lecodeur.co.za
 */

defined( 'ABSPATH' ) || exit;

class SD_Courier_Rates {

	const OPTION = 'sd_courier_rates';

	/** Product-level override, set from the product's Shipping tab. */
	const META = '_sd_courier_row';

	/**
	 * WooCommerce's own South African province codes, in the order the
	 * price list is written. Gauteng last: it had no prices on the list.
	 *
	 * @return array<string, string>
	 */
	public static function regions() {
		return [
			'WC'  => __( 'Western Cape', 'sd-shipping' ),
			'KZN' => __( 'KwaZulu-Natal', 'sd-shipping' ),
			'LP'  => __( 'Limpopo', 'sd-shipping' ),
			'FS'  => __( 'Free State', 'sd-shipping' ),
			'NW'  => __( 'North West', 'sd-shipping' ),
			'EC'  => __( 'Eastern Cape', 'sd-shipping' ),
			'MP'  => __( 'Mpumalanga', 'sd-shipping' ),
			'NC'  => __( 'Northern Cape', 'sd-shipping' ),
			'GP'  => __( 'Gauteng', 'sd-shipping' ),
		];
	}

	/**
	 * The town each province's price was actually quoted to, where the
	 * price list named one. Shown in the admin column headers so nobody
	 * forgets the province-wide price was quoted for one city.
	 *
	 * @return array<string, string>
	 */
	public static function region_hints() {
		return [
			'WC'  => 'Cape Town',
			'KZN' => 'Umhlanga',
			'LP'  => 'Polokwane',
		];
	}

	/**
	 * The courier price list as supplied, Sept 2026. Blank (null) means
	 * "no price given": Olivia to Polokwane, and the whole Gauteng column.
	 *
	 * @return array
	 */
	public static function seed() {
		// Order: WC, KZN, LP, FS, NW, EC, MP, NC, GP
		$list = [
			'olivia-bathroom-mirror-cabinet' => [ 'Olivia Bathroom Mirror Cabinet', [ 350, 250, null, 215, 375, 450, 186, 278, null ] ],
			'small-planter'                  => [ 'Small Planter', [ 260, 230, 320, 250, 415, 265, 215, 315, null ] ],
			'medium-planter'                 => [ 'Medium Planter', [ 400, 350, 420, 405, 575, 445, 300, 470, null ] ],
			'large-planter'                  => [ 'Large Planter', [ 550, 450, 540, 420, 595, 465, 310, 485, null ] ],
			'daphne-mirror'                  => [ 'Daphne Mirror', [ 850, 670, 780, 760, 600, 365, 350, 485, null ] ],
			'zola-side-table'                => [ 'Zola Side Table', [ 620, 400, 490, 305, 470, 330, 250, 370, null ] ],
			'blaire-bedside-cabinet'         => [ 'Blaire Bedside Cabinet', [ 450, 400, 470, 550, 670, 550, 350, 560, null ] ],
			'thandi-bedside-cabinet'         => [ 'Thandi Bedside Cabinet', [ 750, 590, 700, 765, 960, 860, 500, 830, null ] ],
			'ophelia-coffee-table'           => [ 'Ophelia Coffee Table', [ 1470, 1150, 1350, 1430, 1670, 1630, 870, 1490, null ] ],
			'claire-coffee-table'            => [ 'Claire Coffee Table', [ 1450, 1130, 1350, 1630, 1880, 1870, 990, 1700, null ] ],
			'marison-coffee-table'           => [ 'Marison Coffee Table', [ 1320, 1050, 1210, 1260, 1480, 1430, 780, 1320, null ] ],
			'raye-coffee-table'              => [ 'Raye Coffee Table', [ 1250, 990, 1170, 1190, 1410, 1360, 750, 1260, null ] ],
		];

		$codes = array_keys( self::regions() );
		$rows  = [];
		foreach ( $list as $key => $def ) {
			$rows[ $key ] = [
				'key'      => $key,
				'label'    => $def[0],
				'match'    => $def[0],
				'products' => [],
				'prices'   => array_combine( $codes, $def[1] ),
			];
		}

		return [
			'rows'     => $rows,
			'excluded' => [],
		];
	}

	// -----------------------------------------------------------------
	// Storage
	// -----------------------------------------------------------------

	/** @var array|null request cache */
	protected static $cache = null;

	/**
	 * The live table: the saved option, or the seeded price list if it
	 * has never been saved.
	 *
	 * @return array{rows: array, excluded: int[]}
	 */
	public static function table() {
		if ( null === self::$cache ) {
			$saved       = function_exists( 'get_option' ) ? get_option( self::OPTION, null ) : null;
			self::$cache = self::normalise_table( is_array( $saved ) ? $saved : self::seed() );
		}
		return self::$cache;
	}

	/**
	 * @return array[] keyed by row key
	 */
	public static function rows() {
		return self::table()['rows'];
	}

	/**
	 * @param array $table
	 */
	public static function save( array $table ) {
		$table = self::normalise_table( $table );
		update_option( self::OPTION, $table, false );
		self::$cache = $table;

		// Cached shipping rates are keyed by this; bumping it makes a new
		// price show at checkout straight away rather than after the
		// customer's session cache expires.
		if ( class_exists( 'WC_Cache_Helper' ) ) {
			WC_Cache_Helper::get_transient_version( 'shipping', true );
		}
	}

	/** For tests. */
	public static function set_table( array $table ) {
		self::$cache = self::normalise_table( $table );
	}

	/**
	 * Make any table shape safe to read: every row has every key, every
	 * region has a price slot, prices are floats or null.
	 *
	 * @param array $table
	 * @return array
	 */
	public static function normalise_table( array $table ) {
		$codes = array_keys( self::regions() );
		$rows  = [];

		foreach ( (array) ( $table['rows'] ?? [] ) as $key => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$key = (string) ( $row['key'] ?? $key );
			if ( '' === $key ) {
				continue;
			}
			$prices = [];
			foreach ( $codes as $code ) {
				$prices[ $code ] = self::to_price( $row['prices'][ $code ] ?? null );
			}
			$rows[ $key ] = [
				'key'      => $key,
				'label'    => (string) ( $row['label'] ?? $key ),
				'match'    => (string) ( $row['match'] ?? '' ),
				'products' => array_values( array_unique( array_filter( array_map( 'intval', (array) ( $row['products'] ?? [] ) ) ) ) ),
				'prices'   => $prices,
			];
		}

		return [
			'rows'     => $rows,
			'excluded' => array_values( array_unique( array_filter( array_map( 'intval', (array) ( $table['excluded'] ?? [] ) ) ) ) ),
		];
	}

	/**
	 * Blank means "no price", never zero. Accepts "R 1 450", "1450.00",
	 * "1,450".
	 *
	 * @param mixed $value
	 * @return float|null
	 */
	public static function to_price( $value ) {
		if ( null === $value ) {
			return null;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return $value >= 0 ? (float) $value : null;
		}
		$clean = preg_replace( '/[^0-9.]/', '', str_replace( ',', '', (string) $value ) );
		if ( '' === $clean || ! is_numeric( $clean ) ) {
			return null;
		}
		return (float) $clean;
	}

	/**
	 * A stable key for a new row, unique within the table.
	 *
	 * @param string $label
	 * @param array  $taken existing keys
	 * @return string
	 */
	public static function make_key( $label, array $taken ) {
		$base = trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $label ) ), '-' );
		if ( '' === $base ) {
			$base = 'item';
		}
		$key = $base;
		$n   = 2;
		while ( in_array( $key, $taken, true ) ) {
			$key = $base . '-' . $n++;
		}
		return $key;
	}

	// -----------------------------------------------------------------
	// Which row does a product belong to?
	// -----------------------------------------------------------------

	/**
	 * @param int    $product_id variation id for a variation
	 * @param int    $parent_id  0 for a simple product
	 * @param string $name       the product's name (a variation's includes its attributes)
	 * @param array  $table      as returned by table()
	 * @return array|null the row, or null when the product does not go by courier
	 */
	public static function resolve( $product_id, $parent_id, $name, array $table ) {
		$ids = array_values( array_filter( [ (int) $product_id, (int) $parent_id ] ) );

		// 1. Excluded outright.
		foreach ( $ids as $id ) {
			if ( in_array( $id, $table['excluded'], true ) ) {
				return null;
			}
		}

		// 2. Linked explicitly. The product's own link beats its parent's,
		//    so one variation of a planter can sit on a different row.
		foreach ( $ids as $id ) {
			foreach ( $table['rows'] as $row ) {
				if ( in_array( $id, $row['products'], true ) ) {
					return $row;
				}
			}
		}

		// 3. Name match.
		return self::match_by_name( $name, $table['rows'] );
	}

	/**
	 * @param string $name
	 * @param array  $rows
	 * @return array|null
	 */
	public static function match_by_name( $name, array $rows ) {
		$words = self::words( $name );
		if ( empty( $words ) ) {
			return null;
		}

		$best       = null;
		$best_score = 0;

		foreach ( $rows as $row ) {
			// A row's match field may hold several phrases, comma separated.
			foreach ( explode( ',', (string) $row['match'] ) as $phrase ) {
				$need = self::words( $phrase );
				if ( empty( $need ) ) {
					continue;
				}
				if ( array_diff( $need, $words ) ) {
					continue; // a word is missing
				}
				if ( count( $need ) > $best_score ) {
					$best       = $row;
					$best_score = count( $need );
				}
			}
		}

		return $best;
	}

	/**
	 * @param string $text
	 * @return string[] lower-case words, punctuation dropped
	 */
	public static function words( $text ) {
		$text = strtolower( (string) $text );
		$text = preg_replace( '/[^a-z0-9]+/', ' ', $text );
		return array_values( array_unique( array_filter( explode( ' ', trim( $text ) ), 'strlen' ) ) );
	}

	/**
	 * WooCommerce-facing wrapper around resolve().
	 *
	 * @param WC_Product $product
	 * @return array|null
	 */
	public static function row_for_product( $product ) {
		if ( ! is_object( $product ) ) {
			return null;
		}
		$id        = (int) $product->get_id();
		$parent_id = method_exists( $product, 'get_parent_id' ) ? (int) $product->get_parent_id() : 0;
		return self::resolve( $id, $parent_id, (string) $product->get_name(), self::table() );
	}

	// -----------------------------------------------------------------
	// Where is it going?
	// -----------------------------------------------------------------

	/**
	 * The delivery province as a WooCommerce code, or null.
	 *
	 * Normally the checkout's Province field gives this directly. A theme
	 * that renders it as free text, or a blank field, falls back to the
	 * province name and then to the town via the Campos corridor table.
	 *
	 * @param array $destination $package['destination']
	 * @return string|null
	 */
	public static function region_for_destination( array $destination ) {
		$regions = self::regions();
		$state   = strtoupper( trim( (string) ( $destination['state'] ?? '' ) ) );

		if ( isset( $regions[ $state ] ) ) {
			return $state;
		}

		// Older WooCommerce and some address plugins use these.
		$legacy = [ 'ZN' => 'KZN', 'NL' => 'KZN', 'GT' => 'GP', 'NP' => 'LP' ];
		if ( isset( $legacy[ $state ] ) ) {
			return $legacy[ $state ];
		}

		// Province typed out in full.
		$by_name = [
			'western cape'  => 'WC',
			'kwazulu natal' => 'KZN',
			'kzn'           => 'KZN',
			'limpopo'       => 'LP',
			'free state'    => 'FS',
			'north west'    => 'NW',
			'northwest'     => 'NW',
			'eastern cape'  => 'EC',
			'mpumalanga'    => 'MP',
			'northern cape' => 'NC',
			'gauteng'       => 'GP',
		];
		$typed = implode( ' ', self::words( $state ) );
		if ( isset( $by_name[ $typed ] ) ) {
			return $by_name[ $typed ];
		}

		// Last resort: the town, through the Campos corridor table.
		if ( class_exists( 'SD_Zones' ) && ! empty( $destination['city'] ) ) {
			$row = SD_Zones::resolve( $destination );
			if ( $row ) {
				return self::region_for_corridor( $row );
			}
		}

		return null;
	}

	/**
	 * Campos zone number -> province. Garden Route straddles two
	 * provinces, so it is decided by town.
	 *
	 * @param array $row a zone-data.php row
	 * @return string|null
	 */
	public static function region_for_corridor( array $row ) {
		$map = [ 0 => 'GP', 1 => 'LP', 2 => 'LP', 3 => 'MP', 4 => 'MP', 5 => 'NW', 6 => 'NC', 7 => 'FS', 8 => 'KZN', 9 => 'EC', 11 => 'WC' ];
		$zone = (int) ( $row['zone'] ?? -1 );

		if ( 10 === $zone ) {
			$eastern = [ 'st francis bay', 'jeffreys bay', 'humansdorp' ];
			$town    = strtolower( (string) ( $row['towns'][0] ?? '' ) );
			return in_array( $town, $eastern, true ) ? 'EC' : 'WC';
		}

		return $map[ $zone ] ?? null;
	}

	// -----------------------------------------------------------------
	// The price
	// -----------------------------------------------------------------

	/**
	 * Price a cart for one province.
	 *
	 * @param array       $lines  Each: ['name' => string, 'qty' => int, 'row' => array|null]
	 * @param string|null $region WooCommerce province code
	 * @return array {
	 *     available:   bool    true only when every line is priced
	 *     reason:      string  '' | no_region | not_courier | no_price | empty
	 *     region:      string|null
	 *     region_name: string
	 *     total:       float   VAT inclusive, exactly as listed
	 *     lines:       array[] name, qty, row_key, row_label, unit, line_total
	 *     not_courier: string[] names of items with no courier row
	 *     no_price:    string[] names of items whose row has no price for this province
	 * }
	 */
	public static function price_cart( array $lines, $region ) {
		$regions = self::regions();
		$out     = [
			'available'   => false,
			'reason'      => '',
			'region'      => $region,
			'region_name' => isset( $regions[ $region ] ) ? $regions[ $region ] : '',
			'total'       => 0.0,
			'lines'       => [],
			'not_courier' => [],
			'no_price'    => [],
		];

		$counted = 0;

		foreach ( $lines as $line ) {
			$qty = (int) ( $line['qty'] ?? 1 );
			if ( $qty < 1 ) {
				continue;
			}
			$counted++;
			$name = (string) ( $line['name'] ?? '' );
			$row  = $line['row'] ?? null;

			if ( ! is_array( $row ) ) {
				$out['not_courier'][] = $name;
				continue;
			}

			$unit = ( null !== $region && isset( $row['prices'][ $region ] ) ) ? $row['prices'][ $region ] : null;
			if ( null === $unit ) {
				$out['no_price'][] = $name;
				continue;
			}

			$line_total     = round( (float) $unit * $qty, 2 );
			$out['total']  += $line_total;
			$out['lines'][] = [
				'name'       => $name,
				'qty'        => $qty,
				'row_key'    => $row['key'],
				'row_label'  => $row['label'],
				'unit'       => (float) $unit,
				'line_total' => $line_total,
			];
		}

		$out['total'] = round( $out['total'], 2 );

		// Order matters: the first failing reason is the one reported, and
		// "a bulky item is in the basket" is the most useful to say.
		if ( 0 === $counted ) {
			$out['reason'] = 'empty';
		} elseif ( ! isset( $regions[ (string) $region ] ) ) {
			$out['reason'] = 'no_region';
		} elseif ( $out['not_courier'] ) {
			$out['reason'] = 'not_courier';
		} elseif ( $out['no_price'] ) {
			$out['reason'] = 'no_price';
		} else {
			$out['available'] = true;
		}

		return $out;
	}

	/**
	 * Turn a WooCommerce package (or cart contents) into price_cart() lines.
	 *
	 * @param array $contents $package['contents'] or WC()->cart->get_cart()
	 * @return array[]
	 */
	public static function lines_from_contents( array $contents ) {
		$lines = [];
		foreach ( $contents as $item ) {
			$product = $item['data'] ?? null;
			if ( ! is_object( $product ) ) {
				continue;
			}
			$lines[] = [
				'name' => (string) $product->get_name(),
				'qty'  => (int) ( $item['quantity'] ?? 1 ),
				'row'  => self::row_for_product( $product ),
			];
		}
		return $lines;
	}

	/**
	 * Every published product (and variation) a row currently applies to,
	 * for the admin screen. Same resolve() checkout uses, so what the
	 * screen shows is what the customer gets.
	 *
	 * @return array<string, array[]> row key => [ ['id','name','how'] ]
	 */
	public static function matched_products() {
		$table = self::table();
		$out   = array_fill_keys( array_keys( $table['rows'] ), [] );

		if ( ! function_exists( 'wc_get_products' ) ) {
			return $out;
		}

		$products = wc_get_products( [
			'status' => 'publish',
			'type'   => [ 'simple', 'variable', 'variation' ],
			'limit'  => -1,
			'return' => 'objects',
		] );

		foreach ( $products as $product ) {
			if ( $product->is_type( 'variable' ) ) {
				// Variations are checked individually below; the parent
				// itself never sits in a cart.
				continue;
			}
			$row = self::row_for_product( $product );
			if ( ! $row ) {
				continue;
			}
			$id     = (int) $product->get_id();
			$parent = (int) $product->get_parent_id();
			$how    = ( in_array( $id, $row['products'], true ) || in_array( $parent, $row['products'], true ) ) ? 'linked' : 'name';

			$out[ $row['key'] ][] = [
				'id'   => $parent ? $parent : $id,
				'name' => $product->get_name(),
				'how'  => $how,
			];
		}

		return $out;
	}
}
