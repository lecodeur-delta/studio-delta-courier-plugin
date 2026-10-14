<?php
/**
 * Studio Delta > Checkout Text - every customer-facing word this plugin
 * shows, editable, grouped by where it appears. The registry and the
 * rules (required vs optional, {placeholders}) live in SD_Text.
 *
 * Built by Levi Bugera - Delta Creative Agency - https://www.lecodeur.co.za
 */

defined( 'ABSPATH' ) || exit;

class SD_Admin_Text {

	public static function render() {
		SD_Admin_Menu::require_access();

		SD_Admin_Menu::header(
			'text',
			__( 'Checkout Text', 'sd-shipping' ),
			__( 'Everything the customer reads from these delivery options: the option labels, the notes beside them, the cost breakdowns, the notices, the Request a Quote button and popup, and the email copy. Words in {curly brackets} are filled in per order - keep them where you want the figure to appear. Fields marked optional can be cleared to hide them; clearing any other field brings back its default.', 'sd-shipping' )
		);

		if ( isset( $_GET['sd_saved'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Checkout text saved.', 'sd-shipping' ); ?></p></div>
		<?php endif; ?>
		<?php if ( isset( $_GET['sd_reset'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'All checkout text is back to the defaults.', 'sd-shipping' ); ?></p></div>
		<?php endif;

		$by_group = [];
		foreach ( SD_Text::fields() as $key => $field ) {
			$by_group[ $field['group'] ][ $key ] = $field;
		}
		?>

		<nav class="sd-text-jump">
			<?php foreach ( SD_Text::groups() as $group => $meta ) : ?>
				<a href="#sd-text-<?php echo esc_attr( $group ); ?>"><?php echo esc_html( $meta[0] ); ?></a>
			<?php endforeach; ?>
		</nav>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'sd_save_text' ); ?>
			<input type="hidden" name="action" value="sd_save_text">

			<?php foreach ( SD_Text::groups() as $group => $meta ) :
				if ( empty( $by_group[ $group ] ) ) {
					continue;
				}
				?>
				<div class="sd-panel" id="sd-text-<?php echo esc_attr( $group ); ?>">
					<h2><?php echo esc_html( $meta[0] ); ?></h2>
					<p class="sd-note"><?php echo esc_html( $meta[1] ); ?></p>
					<table class="form-table sd-text-table" role="presentation">
						<?php foreach ( $by_group[ $group ] as $key => $field ) :
							$value    = SD_Text::template( $key );
							$is_long  = ! empty( $field['long'] );
							$optional = ! empty( $field['optional'] );
							$changed  = $value !== (string) $field['default'];
							?>
							<tr>
								<th>
									<label for="sd_text_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $field['label'] ); ?></label>
									<?php if ( $optional ) : ?>
										<span class="sd-text-optional"><?php esc_html_e( 'optional', 'sd-shipping' ); ?></span>
									<?php endif; ?>
									<?php if ( $changed ) : ?>
										<span class="sd-text-changed"><?php esc_html_e( 'edited', 'sd-shipping' ); ?></span>
									<?php endif; ?>
								</th>
								<td>
									<?php if ( $is_long ) : ?>
										<textarea class="large-text" rows="3" id="sd_text_<?php echo esc_attr( $key ); ?>" name="sd_text[<?php echo esc_attr( $key ); ?>]"><?php echo esc_textarea( $value ); ?></textarea>
									<?php else : ?>
										<input type="text" class="large-text" id="sd_text_<?php echo esc_attr( $key ); ?>" name="sd_text[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value ); ?>">
									<?php endif; ?>

									<?php if ( ! empty( $field['tokens'] ) ) : ?>
										<p class="description sd-text-tokens">
											<?php foreach ( $field['tokens'] as $token => $meaning ) : ?>
												<code>{<?php echo esc_html( $token ); ?>}</code> <?php echo esc_html( $meaning ); ?>&nbsp;&nbsp;
											<?php endforeach; ?>
										</p>
									<?php endif; ?>

									<?php if ( ! empty( $field['help'] ) ) : ?>
										<p class="description"><?php echo esc_html( $field['help'] ); ?></p>
									<?php endif; ?>

									<?php if ( $changed ) : ?>
										<p class="description sd-text-default">
											<?php
											echo esc_html(
												'' === (string) $field['default']
													? __( 'Default: empty (hidden).', 'sd-shipping' )
													/* translators: %s: the default wording */
													: sprintf( __( 'Default: %s', 'sd-shipping' ), $field['default'] )
											);
											?>
										</p>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</table>
				</div>
			<?php endforeach; ?>

			<?php submit_button( __( 'Save checkout text', 'sd-shipping' ) ); ?>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			onsubmit="return window.confirm(<?php echo esc_attr( wp_json_encode( __( 'Put every piece of checkout text back to its default?', 'sd-shipping' ) ) ); ?>);">
			<?php wp_nonce_field( 'sd_reset_text' ); ?>
			<input type="hidden" name="action" value="sd_reset_text">
			<button type="submit" class="button-link sd-nw-reset-link"><?php esc_html_e( 'Reset all checkout text to defaults', 'sd-shipping' ); ?></button>
		</form>
		<?php

		SD_Admin_Menu::footer();
	}

	/**
	 * admin_post_sd_save_text
	 */
	public static function handle_save() {
		SD_Admin_Menu::require_access( 'sd_save_text' );
		$input = isset( $_POST['sd_text'] ) && is_array( $_POST['sd_text'] ) ? $_POST['sd_text'] : []; // phpcs:ignore -- sanitised in SD_Text::sanitise()
		SD_Text::save( SD_Text::sanitise( $input ) );
		wp_safe_redirect( add_query_arg( 'sd_saved', '1', SD_Admin_Menu::page_url( '-text' ) ) );
		exit;
	}

	/**
	 * admin_post_sd_reset_text
	 */
	public static function handle_reset() {
		SD_Admin_Menu::require_access( 'sd_reset_text' );
		SD_Text::save( [] );
		wp_safe_redirect( add_query_arg( 'sd_reset', '1', SD_Admin_Menu::page_url( '-text' ) ) );
		exit;
	}
}
