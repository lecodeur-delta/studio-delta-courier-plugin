<?php
/**
 * Fuel levy history. Every change is a new row, never an overwrite -
 * that's what makes "adjusted monthly" auditable, and what lets a past
 * order's shipping total be reconstructed later.
 */

defined( 'ABSPATH' ) || exit;

class SD_Fuel_Levy {

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'sd_fuel_levy';
	}

	/**
	 * Create the history table. Called on plugin activation.
	 * zone_id NULL means "applies to every zone" - the common case
	 * today, since the courier currently quotes one national percentage.
	 */
	public static function install_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			zone_id SMALLINT NULL DEFAULT NULL,
			percentage DECIMAL(6,4) NOT NULL,
			effective_from DATE NOT NULL,
			set_by BIGINT UNSIGNED NULL DEFAULT NULL,
			set_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY zone_effective (zone_id, effective_from)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * The percentage in effect today for a given zone (e.g. 0.33 for 33%).
	 *
	 * Looks for the most recent zone-specific row with effective_from
	 * on or before today; if none exists, falls back to the most recent
	 * global row (zone_id IS NULL). Returns null only if the table has
	 * never been seeded - calling code should treat that as a
	 * configuration error, not silently charge R0 fuel levy.
	 *
	 * @param int|null $zone_id
	 * @return float|null
	 */
	public static function get_current( $zone_id = null ) {
		global $wpdb;
		$table = self::table_name();
		$today = current_time( 'Y-m-d' );

		if ( null !== $zone_id ) {
			$specific = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT percentage FROM {$table}
					 WHERE zone_id = %d AND effective_from <= %s
					 ORDER BY effective_from DESC, id DESC LIMIT 1",
					$zone_id,
					$today
				)
			);
			if ( null !== $specific ) {
				return (float) $specific;
			}
		}

		$global = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT percentage FROM {$table}
				 WHERE zone_id IS NULL AND effective_from <= %s
				 ORDER BY effective_from DESC, id DESC LIMIT 1",
				$today
			)
		);

		return null === $global ? null : (float) $global;
	}

	/**
	 * Record a new levy - either for one zone, or for all zones (zone_id null).
	 *
	 * @param int|null $zone_id
	 * @param float    $percentage      e.g. 0.33 for 33%
	 * @param string   $effective_from  'Y-m-d'
	 * @param int      $user_id
	 * @return int|false inserted row id, or false on failure
	 */
	public static function insert( $zone_id, $percentage, $effective_from, $user_id ) {
		global $wpdb;
		$result = $wpdb->insert(
			self::table_name(),
			[
				'zone_id'        => $zone_id,
				'percentage'     => $percentage,
				'effective_from' => $effective_from,
				'set_by'         => $user_id ?: null,
				'set_at'         => current_time( 'mysql' ),
			],
			[ '%d', '%f', '%s', '%d', '%s' ]
		);
		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Recent changes, newest first - for the admin history table.
	 *
	 * @param int $limit
	 * @return array
	 */
	public static function get_history( $limit = 50 ) {
		global $wpdb;
		$table = self::table_name();
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY effective_from DESC, id DESC LIMIT %d", $limit )
		);
	}
}
