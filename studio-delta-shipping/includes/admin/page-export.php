<?php
/**
 * Studio Delta > Export — everything in and out as CSV.
 *
 * Three exports and one import:
 *   - Product dimensions  (round-trippable: this is the one you edit and upload back)
 *   - Product rate matrix (read-only: every product x every destination, with the arithmetic broken out)
 *   - Fuel surcharge history (read-only: the audit trail)
 *   - Upload edited dimensions
 *
 * Only the dimensions import writes anything, and it writes to exactly
 * one field — `_sd_cbm`, the same per-product value the product edit
 * screen sets. Nothing here can change a rate card, a fuel surcharge, or
 * a setting; those have their own screens with their own history.
 */

defined( 'ABSPATH' ) || exit;

class SD_Admin_Export {

	public static function render() {
		SD_Admin_Menu::require_access();

		$rows    = SD_Product_Data::get_all_product_rows();
		$missing = count( array_filter( $rows, static fn( $r ) => ! ( (float) $r['cbm'] > 0 ) ) );

		SD_Admin_Menu::header(
			'export',
			__( 'Export', 'sd-shipping' ),
			__( 'Download the data behind these screens, or bulk-update product dimensions by uploading an edited file.', 'sd-shipping' )
		);

		if ( isset( $_GET['sd_import'] ) ) {
			self::render_import_notice();
		}
		?>

		<div class="sd-cards">

			<div class="sd-card">
				<h2><?php esc_html_e( 'Product dimensions', 'sd-shipping' ); ?></h2>
				<p><?php esc_html_e( 'SKU, name, Length/Width/Height/Depth in mm, the resulting CBM, and where that CBM came from. This is the file to edit and upload back — it is the only export that round-trips.', 'sd-shipping' ); ?></p>
				<p>
					<a class="button button-primary" href="<?php echo esc_url( self::export_url( 'dimensions' ) ); ?>">
						<?php esc_html_e( 'Download dimensions CSV', 'sd-shipping' ); ?>
					</a>
				</p>
				<?php if ( $missing ) : ?>
					<p class="sd-warn">
						<?php
						printf(
							/* translators: %d: number of products with no CBM */
							esc_html( _n( '%d product currently has no dimensions on file.', '%d products currently have no dimensions on file.', $missing, 'sd-shipping' ) ),
							(int) $missing
						);
						?>
					</p>
				<?php endif; ?>
			</div>

			<div class="sd-card">
				<h2><?php esc_html_e( 'Product rate matrix', 'sd-shipping' ); ?></h2>
				<p><?php esc_html_e( 'Every product priced against every one of the 60 destination corridors, using the fuel surcharge in effect today — with base charge, fuel, and VAT as separate columns so any line can be checked by hand against a courier invoice.', 'sd-shipping' ); ?></p>
				<p>
					<a class="button" href="<?php echo esc_url( self::export_url( 'rates' ) ); ?>">
						<?php esc_html_e( 'Download rate matrix CSV', 'sd-shipping' ); ?>
					</a>
				</p>
				<p class="sd-note"><?php esc_html_e( 'Read-only — uploading this file back does nothing. Rates come from the courier rate card in code; product volumes come from the dimensions file above.', 'sd-shipping' ); ?></p>
			</div>

			<div class="sd-card">
				<h2><?php esc_html_e( 'Fuel surcharge history', 'sd-shipping' ); ?></h2>
				<p><?php esc_html_e( 'Every recorded surcharge with its effective date, zone, and who entered it — the audit trail behind any past order\'s shipping total.', 'sd-shipping' ); ?></p>
				<p>
					<a class="button" href="<?php echo esc_url( self::export_url( 'levy' ) ); ?>">
						<?php esc_html_e( 'Download surcharge history CSV', 'sd-shipping' ); ?>
					</a>
				</p>
			</div>

			<div class="sd-card sd-card-import">
				<h2><?php esc_html_e( 'Upload edited dimensions', 'sd-shipping' ); ?></h2>
				<p><?php esc_html_e( 'Fill in Width, Height, and either Length or Depth (never both — they are the same axis under different names) for any row you want to add or correct, then upload the file here.', 'sd-shipping' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<?php wp_nonce_field( 'sd_import_dimensions' ); ?>
					<input type="hidden" name="action" value="sd_import_dimensions">
					<input type="file" name="sd_csv" accept=".csv" required>
					<?php submit_button( __( 'Upload and update', 'sd-shipping' ), 'secondary', 'submit', false ); ?>
				</form>
				<ul class="sd-rules">
					<li><?php esc_html_e( 'Rows with exactly 3 of the 4 dimension columns filled in are updated.', 'sd-shipping' ); ?></li>
					<li><?php esc_html_e( 'Rows with all 4 blank are left completely alone.', 'sd-shipping' ); ?></li>
					<li><?php esc_html_e( 'Rows with 1, 2, or all 4 filled in are skipped and listed by SKU afterwards — never guessed at.', 'sd-shipping' ); ?></li>
					<li><?php esc_html_e( 'Products are matched by SKU. A row whose SKU is not on the site is reported, not created.', 'sd-shipping' ); ?></li>
					<li><?php esc_html_e( 'An imported dimension becomes that product\'s measured CBM, which from then on takes priority over anything read from its description.', 'sd-shipping' ); ?></li>
				</ul>
			</div>

		</div>

		<?php
		SD_Admin_Menu::footer();
	}

	/**
	 * @param string $what 'dimensions' | 'rates' | 'levy'
	 * @return string Nonced admin-post URL.
	 */
	protected static function export_url( $what ) {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=sd_export&what=' . $what ),
			'sd_export'
		);
	}

	protected static function render_import_notice() {
		$updated = isset( $_GET['updated'] ) ? absint( $_GET['updated'] ) : 0;
		$skipped = isset( $_GET['skipped'] ) ? absint( $_GET['skipped'] ) : 0;
		$notes   = get_transient( 'sd_import_notes_' . get_current_user_id() );
		?>
		<div class="notice <?php echo $skipped ? 'notice-warning' : 'notice-success'; ?> is-dismissible">
			<p>
				<?php
				printf(
					/* translators: 1: number updated, 2: number skipped */
					esc_html__( 'Import finished: %1$d product(s) updated, %2$d row(s) skipped.', 'sd-shipping' ),
					$updated,
					$skipped
				);
				?>
			</p>
			<?php if ( $notes && is_array( $notes ) ) : ?>
				<ul class="sd-import-notes">
					<?php foreach ( $notes as $note ) : ?>
						<li><?php echo esc_html( $note ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * admin_post_sd_export — stream whichever CSV was asked for.
	 */
	public static function handle_export() {
		SD_Admin_Menu::require_access( 'sd_export' );

		$what = isset( $_GET['what'] ) ? sanitize_key( $_GET['what'] ) : '';
		$date = current_time( 'Y-m-d' );

		if ( 'rates' === $what ) {
			$levy = (float) SD_Fuel_Levy::get_current();
			SD_Product_Data::stream_csv(
				SD_Product_Data::build_rates_csv_rows(
					SD_Product_Data::get_all_product_rows(),
					SD_Product_Data::corridors(),
					$levy,
					SD_Settings::vat_fraction()
				),
				"studio-delta-rate-matrix-{$date}.csv"
			);
		}

		if ( 'levy' === $what ) {
			SD_Product_Data::stream_csv(
				SD_Product_Data::build_levy_csv_rows(),
				"studio-delta-fuel-surcharge-history-{$date}.csv"
			);
		}

		// Default: the round-trippable dimensions file.
		SD_Product_Data::stream_csv(
			SD_Product_Data::build_csv_rows( SD_Product_Data::get_all_product_rows() ),
			"studio-delta-product-dimensions-{$date}.csv"
		);
	}

	/**
	 * admin_post_sd_import_dimensions — bulk-update _sd_cbm from an
	 * edited dimensions CSV.
	 */
	public static function handle_import() {
		SD_Admin_Menu::require_access( 'sd_import_dimensions' );

		if ( empty( $_FILES['sd_csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['sd_csv']['tmp_name'] ) ) {
			wp_die( esc_html__( 'No file uploaded.', 'sd-shipping' ) );
		}

		$result = SD_Product_Data::import_csv( $_FILES['sd_csv']['tmp_name'] );

		// Short-lived, per-user note list — shown once on the redirect,
		// then left to expire. Upload history isn't worth storing.
		set_transient(
			'sd_import_notes_' . get_current_user_id(),
			array_slice( $result['notes'], 0, 25 ),
			5 * MINUTE_IN_SECONDS
		);

		wp_safe_redirect( add_query_arg(
			[
				'sd_import' => 1,
				'updated'   => $result['updated'],
				'skipped'   => $result['skipped'],
			],
			SD_Admin_Menu::page_url( '-export' )
		) );
		exit;
	}
}
