<?php
/**
 * Studio Delta > Fuel Surcharge - the screen the digital team actually
 * touches month to month, now a first-class item in the sidebar rather
 * than a section buried in WooCommerce > Settings > Shipping.
 *
 * The mechanism is unchanged from v0.2.0 and deliberately so: rows are
 * INSERTED, never edited or deleted. That's what makes "adjusted monthly"
 * auditable, and what lets a past order's shipping total be reconstructed
 * months later when a customer queries it. The data layer is
 * SD_Fuel_Levy; this file is only its screen.
 *
 * The surcharge applies to Campos Delivery only. Standard Rates (your
 * existing WooCommerce flat-rate methods) and Request Quote never touch
 * it - Standard has its own fixed prices, and Request Quote calculates
 * nothing at all.
 */

defined( 'ABSPATH' ) || exit;

class SD_Admin_Fuel_Surcharge {

	public static function render() {
		SD_Admin_Menu::require_access();

		$zone_options = [];
		foreach ( SD_Zones::all() as $row ) {
			$zone_options[ $row['zone'] ] = $row['zone_name'];
		}

		$current = SD_Fuel_Levy::get_current();

		SD_Admin_Menu::header(
			'fuel',
			__( 'Fuel Surcharge', 'sd-shipping' ),
			__( 'The percentage added on top of the base Campos Delivery charge, before VAT. Recording a new figure never overwrites the old one - every change is kept with its effective date, so any past order can still be explained.', 'sd-shipping' )
		);

		if ( isset( $_GET['sd_saved'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'New fuel surcharge recorded.', 'sd-shipping' ); ?></p></div>
		<?php endif; ?>

		<div class="sd-summary">
			<div class="sd-stat sd-stat-big">
				<span class="sd-stat-label"><?php esc_html_e( 'In effect right now', 'sd-shipping' ); ?></span>
				<span class="sd-stat-value">
					<?php echo esc_html( SD_Admin_Menu::current_levy_label() ?? __( 'not set', 'sd-shipping' ) ); ?>
				</span>
			</div>
			<div class="sd-stat">
				<span class="sd-stat-label"><?php esc_html_e( 'Shown at checkout', 'sd-shipping' ); ?></span>
				<span class="sd-stat-value">
					<?php echo SD_Settings::is_on( 'show_fuel_icon' ) ? esc_html__( 'with icon', 'sd-shipping' ) : esc_html__( 'text only', 'sd-shipping' ); ?>
				</span>
				<a class="sd-stat-link" href="<?php echo esc_url( SD_Admin_Menu::page_url( '-settings' ) ); ?>"><?php esc_html_e( 'change', 'sd-shipping' ); ?></a>
			</div>
		</div>

		<?php if ( null === $current ) : ?>
			<div class="notice notice-error">
				<p><?php esc_html_e( 'No surcharge has ever been recorded. Until one is, every Campos Delivery quote is missing its fuel component - record the confirmed figure before taking orders.', 'sd-shipping' ); ?></p>
			</div>
		<?php endif; ?>

		<div class="sd-panel">
			<h2><?php esc_html_e( 'Record a new surcharge', 'sd-shipping' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'sd_save_fuel_levy' ); ?>
				<input type="hidden" name="action" value="sd_save_fuel_levy">

				<table class="form-table" role="presentation">
					<tr>
						<th><label for="sd_zone_id"><?php esc_html_e( 'Applies to', 'sd-shipping' ); ?></label></th>
						<td>
							<select name="zone_id" id="sd_zone_id">
								<option value=""><?php esc_html_e( 'All zones (national)', 'sd-shipping' ); ?></option>
								<?php foreach ( $zone_options as $zone_id => $zone_name ) : ?>
									<option value="<?php echo esc_attr( $zone_id ); ?>">
										<?php echo esc_html( $zone_id . ' - ' . $zone_name ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Leave on "All zones" unless the courier has quoted a different percentage for one specific zone. A zone-specific figure wins over the national one for that zone only.', 'sd-shipping' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="sd_percentage"><?php esc_html_e( 'New percentage', 'sd-shipping' ); ?></label></th>
						<td>
							<input type="number" step="0.01" min="0" max="200" name="percentage_display" id="sd_percentage" required class="small-text"> %
							<p class="description"><?php esc_html_e( 'Enter it as a whole percentage - 33 for 33%, not 0.33.', 'sd-shipping' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="sd_effective_from"><?php esc_html_e( 'Effective from', 'sd-shipping' ); ?></label></th>
						<td>
							<input type="date" name="effective_from" id="sd_effective_from" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" required>
							<p class="description"><?php esc_html_e( 'Checkout always uses the most recent row dated on or before today, so a future date is safe to enter in advance.', 'sd-shipping' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Record surcharge', 'sd-shipping' ) ); ?>
			</form>
		</div>

		<h2><?php esc_html_e( 'History', 'sd-shipping' ); ?></h2>
		<p class="sd-note"><?php esc_html_e( 'Newest first. Nothing here can be edited or deleted - that is the point of it.', 'sd-shipping' ); ?></p>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Effective from', 'sd-shipping' ); ?></th>
					<th><?php esc_html_e( 'Zone', 'sd-shipping' ); ?></th>
					<th class="sd-num"><?php esc_html_e( 'Percentage', 'sd-shipping' ); ?></th>
					<th><?php esc_html_e( 'Set by', 'sd-shipping' ); ?></th>
					<th><?php esc_html_e( 'Set at', 'sd-shipping' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				$history = SD_Fuel_Levy::get_history();
				if ( empty( $history ) ) :
					?>
					<tr><td colspan="5"><?php esc_html_e( 'Nothing recorded yet.', 'sd-shipping' ); ?></td></tr>
					<?php
				endif;
				foreach ( $history as $row ) :
					?>
					<tr>
						<td><?php echo esc_html( $row->effective_from ); ?></td>
						<td><?php echo esc_html( null === $row->zone_id ? __( 'All zones', 'sd-shipping' ) : ( $zone_options[ $row->zone_id ] ?? $row->zone_id ) ); ?></td>
						<td class="sd-num"><?php echo esc_html( round( (float) $row->percentage * 100, 2 ) . '%' ); ?></td>
						<td><?php echo esc_html( $row->set_by ? get_the_author_meta( 'display_name', $row->set_by ) : '-' ); ?></td>
						<td><?php echo esc_html( $row->set_at ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<p class="sd-note">
			<?php
			printf(
				/* translators: %s: link to the Export screen */
				wp_kses_post( __( 'The full history is downloadable as a CSV from <a href="%s">Export</a> - useful when reconciling a month of Campos invoices against what the site actually charged.', 'sd-shipping' ) ),
				esc_url( SD_Admin_Menu::page_url( '-export' ) )
			);
			?>
		</p>

		<?php
		SD_Admin_Menu::footer();
	}

	/**
	 * admin_post_sd_save_fuel_levy - insert a new dated row.
	 */
	public static function handle_save() {
		SD_Admin_Menu::require_access( 'sd_save_fuel_levy' );

		$zone_id        = ( isset( $_POST['zone_id'] ) && '' !== $_POST['zone_id'] ) ? absint( $_POST['zone_id'] ) : null;
		$percentage_pct = isset( $_POST['percentage_display'] ) ? (float) $_POST['percentage_display'] : null;
		$effective_from = isset( $_POST['effective_from'] ) ? sanitize_text_field( wp_unslash( $_POST['effective_from'] ) ) : '';

		if ( null === $percentage_pct || '' === $effective_from ) {
			wp_die( esc_html__( 'Missing percentage or effective date.', 'sd-shipping' ) );
		}

		// Stored as a fraction (0.33), entered as a whole percentage (33).
		SD_Fuel_Levy::insert( $zone_id, $percentage_pct / 100, $effective_from, get_current_user_id() );

		wp_safe_redirect( add_query_arg( 'sd_saved', '1', SD_Admin_Menu::page_url( '-fuel' ) ) );
		exit;
	}
}
