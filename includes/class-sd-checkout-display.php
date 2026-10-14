<?php
/**
 * Everything the customer sees at cart/checkout beyond a single shipping
 * total: the fuel surcharge (with its icon), the optional badge note on
 * Campos Delivery (empty by default now that Campos covers the whole
 * catalogue rather than only large items), the itemised per-product cost
 * breakdown, and the Request Quote range notice.
 *
 * WooCommerce already carries a rate's meta_data onto the order once
 * placed - that's free, no code needed, and it's why the breakdown shows
 * up in wp-admin and order emails without anything here running. This
 * file is only for making the same numbers visible live, before the order
 * exists, on the cart and checkout pages.
 *
 * Every block below is individually switchable from Studio Delta >
 * Settings; turning one off changes what is shown, never what is charged.
 *
 * LAYOUT RULES - learned the hard way in v0.4.1, after the first version
 * of this file broke a live checkout. Everything injected here lands
 * inside WooCommerce's cart-totals / review-order table, which is TWO
 * columns wide and, on most themes, sits in a narrow sidebar column.
 * So:
 *   1. Never nest a multi-column <table> in there. A nested table's
 *      intrinsic minimum width is the sum of its columns' content and it
 *      will overflow a 320px sidebar no matter what width:100% says.
 *      Per-item detail is a flex list that wraps instead.
 *   2. Use <td colspan="2">, never <th>. Themes style totals-table <th>
 *      as a narrow bold label column; content in one inherits that and
 *      looks broken.
 *   3. Give the cell data-title="" - responsive themes generate mobile
 *      labels from td::before{content:attr(data-title)} and a missing
 *      attribute prints stray text.
 *   4. No white-space:nowrap on anything. A long shipping label must be
 *      allowed to wrap or it widens the whole table.
 *   5. Render at most once per request. Some themes call both the cart
 *      and review-order hooks on one page, which duplicated every row.
 */

defined( 'ABSPATH' ) || exit;

/**
 * The fuel-pump icon shown beside the surcharge at checkout.
 *
 * Inline SVG rather than a font icon or an image: it inherits the
 * surrounding text colour, costs no extra request, survives any theme
 * that hasn't loaded Dashicons on the front end, and can't 404 behind a
 * CDN. `aria-hidden` because the adjacent text already says what it is -
 * a screen reader announcing "fuel pump" adds nothing.
 *
 * @param string $title Tooltip text. Pass '' for no tooltip.
 * @return string
 */
function sd_fuel_icon_svg( $title = null ) {
	if ( null === $title ) {
		$title = class_exists( 'SD_Text' )
			? SD_Text::get( 'campos_fuel_tooltip' )
			: __( 'A courier fuel surcharge, set monthly by the transport company and applied to the delivery charge before VAT.', 'sd-shipping' );
	}

	return sprintf(
		'<span class="sd-fuel-icon" title="%s" aria-hidden="true">'
		. '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" '
		. 'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;">'
		. '<line x1="3" y1="22" x2="15" y2="22"></line>'
		. '<line x1="4" y1="9" x2="14" y2="9"></line>'
		. '<path d="M14 22V4a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v18"></path>'
		. '<path d="M14 13h2a2 2 0 0 1 2 2v2a2 2 0 0 0 2 2a2 2 0 0 0 2-2V9.83a2 2 0 0 0-.59-1.42L18 5"></path>'
		. '</svg></span>',
		esc_attr( $title )
	);
}

/**
 * Read one meta value off a WC_Shipping_Rate.
 *
 * This exists because of the bug that broke a live checkout in v0.4.0:
 * WC_Shipping_Rate does NOT extend WC_Data, so it has no get_meta()
 * method - only get_meta_data(), which returns the whole array keyed by
 * meta key. Calling $rate->get_meta( 'x' ) is a fatal "call to undefined
 * method", and because these functions run while WooCommerce renders the
 * review-order fragment, that fatal surfaced as a 500 on
 * ?wc-ajax=update_order_review and a checkout with no totals section.
 *
 * add_rate( [ 'meta_data' => [...] ] ) writes through add_meta_data(),
 * so the writing side was always correct - only the reads were wrong.
 * Every read now goes through here.
 *
 * @param WC_Shipping_Rate $rate
 * @param string           $key
 * @return string '' when absent, so callers can test with a plain falsy check.
 */
function sd_rate_meta( $rate, $key ) {
	if ( ! is_object( $rate ) ) {
		return '';
	}

	if ( method_exists( $rate, 'get_meta_data' ) ) {
		$meta = $rate->get_meta_data();
		if ( is_array( $meta ) && isset( $meta[ $key ] ) ) {
			return (string) $meta[ $key ];
		}
		return '';
	}

	// Belt and braces for a future WooCommerce that does add get_meta()
	// to this class - method_exists first, never a blind call.
	if ( method_exists( $rate, 'get_meta' ) ) {
		return (string) $rate->get_meta( $key );
	}

	return '';
}

/**
 * Pull the chosen Campos Delivery rate's sd_breakdown meta out of the
 * shipping packages, decoded back to an array.
 *
 * Result is cached for the request: both render hooks below may run on
 * one page load, and WC()->shipping()->get_packages() is not something to
 * call repeatedly while a totals table is mid-render.
 *
 * @param string|null $package_id
 * @return array|null
 */
function sd_get_chosen_breakdown( $package_id = null ) {
	static $cache = [];

	$cache_key = null === $package_id ? '__all' : (string) $package_id;
	if ( array_key_exists( $cache_key, $cache ) ) {
		return $cache[ $cache_key ];
	}
	$cache[ $cache_key ] = null;

	$packages = WC()->shipping() ? WC()->shipping()->get_packages() : [];
	if ( empty( $packages ) ) {
		return null;
	}

	$chosen = WC()->session ? WC()->session->get( 'chosen_shipping_methods' ) : [];

	foreach ( $packages as $pid => $package ) {
		if ( null !== $package_id && (string) $pid !== (string) $package_id ) {
			continue;
		}
		if ( empty( $package['rates'] ) ) {
			continue;
		}
		$chosen_id = $chosen[ $pid ] ?? null;
		foreach ( $package['rates'] as $rate_id => $rate ) {
			if ( 0 !== strpos( $rate_id, 'sd_campos_delivery' ) ) {
				continue;
			}
			if ( null !== $chosen_id && $rate_id !== $chosen_id ) {
				continue;
			}
			$json = sd_rate_meta( $rate, 'sd_breakdown' );
			if ( ! $json ) {
				continue;
			}
			$decoded = json_decode( $json, true );
			if ( is_array( $decoded ) ) {
				$cache[ $cache_key ] = $decoded;
				return $decoded;
			}
		}
	}

	return null;
}

/**
 * Format a fraction as a trimmed percentage: 0.33 -> "33", 0.325 -> "32.5".
 *
 * @param float $fraction
 * @return string
 */
function sd_pct( $fraction ) {
	return rtrim( rtrim( number_format( (float) $fraction * 100, 2 ), '0' ), '.' );
}

/**
 * Run a render callback with its output buffered, and swallow anything it
 * throws.
 *
 * The point: these callbacks run while WooCommerce is rendering the
 * review-order fragment. An uncaught Error in one doesn't just hide a
 * cost breakdown - it 500s ?wc-ajax=update_order_review and the customer
 * gets a checkout with no totals and no way to pay. A cosmetic extra
 * must never be able to do that, so on a throw we discard whatever the
 * renderer had emitted (avoiding a half-written table row), log it for
 * WooCommerce > Status > Logs, and let the page render without us.
 *
 * "Call to undefined method" is a catchable Error in PHP 7+, so this
 * guard would have contained the v0.4.0 bug this function was written in
 * response to, instead of taking the checkout down with it.
 *
 * @param callable $renderer
 */
function sd_safe_render( $renderer ) {
	$level = ob_get_level();

	try {
		ob_start();
		call_user_func( $renderer );
		// phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside the renderer.
		echo ob_get_clean();
	} catch ( Throwable $e ) {
		while ( ob_get_level() > $level ) {
			ob_end_clean();
		}
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error(
				sprintf(
					'Checkout display suppressed a %1$s: %2$s in %3$s:%4$d',
					get_class( $e ),
					$e->getMessage(),
					$e->getFile(),
					$e->getLine()
				),
				[ 'source' => 'sd-shipping' ]
			);
		}
	}
}

/**
 * Append the optional badge note and the fuel surcharge summary onto the
 * Campos Delivery label. The badge ships empty: Campos is no longer
 * restricted to large items, so there is nothing to qualify by default.
 *
 * Both are added here, as display, rather than baked into the method's
 * own Title setting - so whatever the merchant types into that field in
 * wp-admin is exactly what they get back, and the badge text stays
 * editable from Studio Delta > Settings.
 *
 * Deliberately allowed to wrap: this label sits beside a radio button in
 * a narrow column, and forcing it onto one line widens the entire totals
 * table (see LAYOUT RULES at the top of this file).
 */
add_filter( 'woocommerce_cart_shipping_method_full_label', function ( $label, $method ) {
	if ( 0 !== strpos( $method->get_id(), 'sd_campos_delivery' ) ) {
		return $label;
	}

	$badge = trim( SD_Text::get( 'campos_badge' ) );
	if ( '' !== $badge ) {
		$label .= sprintf(
			' <span class="sd-large-item-badge">(%s)</span>',
			esc_html( $badge )
		);
	}

	$fuel_pct = sd_rate_meta( $method, 'sd_fuel_pct' );
	$fuel_amt = sd_rate_meta( $method, 'sd_fuel_amt' );
	if ( '' === $fuel_pct || '' === $fuel_amt || ! SD_Text::has( 'campos_fuel_note' ) ) {
		return $label;
	}

	$icon = SD_Settings::is_on( 'show_fuel_icon' ) ? sd_fuel_icon_svg() . ' ' : '';

	return sprintf(
		'%s <span class="sd-fuel-note">%s%s</span>',
		$label,
		$icon,
		SD_Text::html( 'campos_fuel_note', [
			'percent' => sd_pct( $fuel_pct ),
			'amount'  => number_format( (float) $fuel_amt, 2 ),
		] )
	);
}, 10, 2 );

/**
 * The note beside Standard Nationwide Shipping ("by courier" by default),
 * editable at Studio Delta > Checkout Text. Blank hides it.
 */
add_filter( 'woocommerce_cart_shipping_method_full_label', function ( $label, $method ) {
	if ( 0 !== strpos( $method->get_id(), 'sd_standard_nationwide' ) ) {
		return $label;
	}
	$badge = trim( SD_Text::get( 'nw_badge' ) );
	if ( '' === $badge ) {
		return $label;
	}
	return sprintf( '%s <span class="sd-nw-badge">(%s)</span>', $label, esc_html( $badge ) );
}, 10, 2 );

/**
 * The itemised breakdown: one line per cart item with its size, then the
 * base charge / fuel / VAT arithmetic.
 *
 * A wrapping flex list, not a table. The previous version nested a
 * five-column table in here and overflowed narrow checkout columns -
 * see LAYOUT RULES at the top of this file.
 */
function sd_render_breakdown_table() {
	static $rendered = false;
	if ( $rendered || ! SD_Settings::is_on( 'show_breakdown' ) ) {
		return;
	}

	$breakdown = sd_get_chosen_breakdown();
	if ( ! $breakdown || empty( $breakdown['items'] ) ) {
		return;
	}
	$rendered = true;

	$show_class = SD_Settings::is_on( 'show_wc_class' );
	$estimated  = false;
	?>
	<tr class="sd-breakdown-row">
		<td colspan="2" data-title="">
			<details class="sd-breakdown">
				<summary><?php echo SD_Text::html( 'campos_breakdown_title' ); // phpcs:ignore -- escaped in SD_Text::html() ?></summary>

				<ul class="sd-breakdown-items">
					<?php
					// In per-item mode each line carries its own charge, so
					// the customer can see the addition rather than one
					// opaque total. Indexed by position: 'lines' is built
					// from 'items' in the same order.
					$lines = $breakdown['lines'] ?? [];
					foreach ( $breakdown['items'] as $idx => $item ) :
						$line_price = $lines[ $idx ]['line_charge'] ?? null;
						?>
						<?php
						$meta = [];

						if ( $item['cbm_each'] ) {
							// For a multi-quantity line show the arithmetic
							// (0.6347 x 2 = 1.2694 m³) so the customer can
							// see their own line adding into the cart total,
							// rather than being left to multiply it.
							$line = $item['cbm_line'] ?? null;
							if ( $line && (int) $item['qty'] > 1 ) {
								$size = sprintf(
									/* translators: 1: volume each, 2: quantity, 3: line total volume */
									esc_html__( '%1$s × %2$d = %3$s m³', 'sd-shipping' ),
									esc_html( $item['cbm_each'] ),
									(int) $item['qty'],
									esc_html( $line )
								);
							} else {
								$size = sprintf(
									/* translators: %s: cubic metres */
									esc_html__( '%s m³', 'sd-shipping' ),
									esc_html( $item['cbm_each'] )
								);
							}
							if ( 'parsed from description' === $item['cbm_source'] ) {
								$estimated = true;
								$size     .= '<span class="sd-cbm-flag" title="' . esc_attr__( 'Estimated from the product description, not a measured value.', 'sd-shipping' ) . '">*</span>';
							}
							$meta[] = $size;
						} else {
							$meta[] = '<span class="sd-cbm-missing">' . esc_html__( 'size to confirm', 'sd-shipping' ) . '</span>';
						}

						if ( ! empty( $item['size_class'] ) ) {
							$meta[] = esc_html( $item['size_class'] );
						}

						// The Shipping Class cross-check is a data-quality
						// signal for staff, not information a customer can
						// act on - off by default, and appended as plain
						// text rather than its own column so it can wrap.
						if ( $show_class && ! empty( $item['wc_class'] ) ) {
							$wc = esc_html( $item['wc_class'] );
							if ( true === $item['class_mismatch'] ) {
								$wc .= ' <span class="sd-class-flag" title="' . esc_attr__( 'This product\'s Shipping Class disagrees with its measured size. Price is unaffected - it always comes from volume.', 'sd-shipping' ) . '">&#9888;</span>';
							}
							$meta[] = $wc;
						}
						?>
						<li>
							<span class="sd-bd-name">
								<?php echo esc_html( $item['name'] ); ?>
								<?php if ( (int) $item['qty'] > 1 ) : ?>
									<span class="sd-bd-qty">&times;&nbsp;<?php echo esc_html( $item['qty'] ); ?></span>
								<?php endif; ?>
							</span>
							<span class="sd-bd-meta"><?php echo wp_kses_post( implode( ' &middot; ', $meta ) ); ?></span>
							<?php if ( null !== $line_price ) : ?>
								<span class="sd-bd-price">R <?php echo esc_html( number_format( (float) $line_price, 2 ) ); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>

				<?php if ( ! empty( $lines ) && SD_Text::has( 'campos_basis_note' ) ) : ?>
					<p class="sd-breakdown-basis">
						<?php echo SD_Text::html( 'campos_basis_note' ); // phpcs:ignore -- escaped in SD_Text::html() ?>
					</p>
				<?php endif; ?>

				<?php if ( ! empty( $breakdown['cbm_total'] ) && (float) $breakdown['cbm_total'] > 0 && SD_Text::has( 'campos_volume' ) ) : ?>
					<p class="sd-breakdown-volume">
						<?php echo SD_Text::html( 'campos_volume', [ 'cbm' => $breakdown['cbm_total'] ] ); // phpcs:ignore -- escaped in SD_Text::html() ?>
					</p>
				<?php endif; ?>

				<p class="sd-breakdown-totals">
					<?php
					echo wp_kses_post( sprintf(
						/* translators: 1: zone name, 2: base charge, 3: fuel icon, 4: fuel %, 5: fuel amount, 6: VAT amount, 7: total */
						__( '%1$s &middot; base R%2$s + %3$s%4$s%% fuel levy R%5$s + VAT R%6$s = <strong>R%7$s</strong>', 'sd-shipping' ),
						esc_html( $breakdown['zone_name'] ),
						esc_html( $breakdown['charge'] ),
						SD_Settings::is_on( 'show_fuel_icon' ) ? sd_fuel_icon_svg() . ' ' : '',
						esc_html( sd_pct( $breakdown['fuel_pct'] ) ),
						esc_html( $breakdown['fuel_amount'] ),
						esc_html( $breakdown['vat_amount'] ),
						esc_html( $breakdown['total'] )
					) );
					?>
				</p>

				<?php
				// The single most confusing thing about this pricing: the
				// courier's minimum is per DELIVERY, not per item, so
				// adding a second small item often changes nothing. Left
				// unexplained that reads as a broken calculator. Say it.
				if ( ! empty( $breakdown['floored'] ) ) :
					$at_min = (float) ( $breakdown['cbm_at_min'] ?? 0 );
					$now    = (float) ( $breakdown['cbm_total'] ?? 0 );
					$min    = number_format( (float) $breakdown['min_charge'], 2 );

					if ( 'per_item' === ( $breakdown['basis'] ?? '' ) ) {
						$floor = SD_Text::html( 'campos_floor_item', [
							'min'  => $min,
							'zone' => $breakdown['zone_name'],
							'cbm'  => number_format( $at_min, 2 ),
						] );
					} elseif ( $at_min > 0 && $now > 0 ) {
						$floor = SD_Text::html( 'campos_floor_order', [
							'zone'      => $breakdown['zone_name'],
							'min'       => $min,
							'cbm'       => number_format( $at_min, 2 ),
							'remaining' => number_format( max( 0, $at_min - $now ), 2 ),
						] );
					} else {
						$floor = SD_Text::html( 'campos_floor_plain', [
							'zone' => $breakdown['zone_name'],
							'min'  => $min,
						] );
					}

					if ( '' !== trim( $floor ) ) :
						?>
						<p class="sd-breakdown-floor"><?php echo $floor; // phpcs:ignore -- escaped in SD_Text::html() ?></p>
						<?php
					endif;
				endif;

				// Every item unsized means the volume total is meaningless
				// and the price is the floor by default, not by
				// calculation. That is worth flagging separately.
				$sized = array_filter( $breakdown['items'], static fn( $i ) => ! empty( $i['cbm_each'] ) );
				if ( empty( $sized ) && SD_Text::has( 'campos_unsized' ) ) :
					?>
					<p class="sd-breakdown-note">
						<?php echo SD_Text::html( 'campos_unsized' ); // phpcs:ignore -- escaped in SD_Text::html() ?>
					</p>
					<?php
				endif;
				?>

				<?php if ( $estimated && SD_Text::has( 'campos_estimated' ) ) : ?>
					<p class="sd-breakdown-note">
						<?php echo SD_Text::html( 'campos_estimated' ); // phpcs:ignore -- escaped in SD_Text::html() ?>
					</p>
				<?php endif; ?>
			</details>
		</td>
	</tr>
	<?php
}
/**
 * Named wrappers rather than closures, so a theme or snippet can still
 * remove_action() either of these if it ever needs to.
 */
function sd_output_breakdown_table() {
	sd_safe_render( 'sd_render_breakdown_table' );
}
add_action( 'woocommerce_review_order_after_shipping', 'sd_output_breakdown_table' );
add_action( 'woocommerce_cart_totals_after_shipping', 'sd_output_breakdown_table' );

/**
 * Beside the Request Quote option, say roughly what the hand-quoted
 * figure will look like: somewhere between what Campos Delivery and your
 * Standard Rates would charge for this same cart, higher in outlying
 * zones.
 *
 * Both numbers are read from the rates WooCommerce has ALREADY
 * calculated for this package - by the time these hooks fire, every
 * method in the matched zone has run its own calculate_shipping(), so
 * the figures are sitting in $package['rates']. Nothing is estimated a
 * second time, so the range can't drift from the real options next to it.
 */
function sd_render_quote_range_notice() {
	static $rendered = false;
	if ( $rendered || ! SD_Settings::is_on( 'quote_range_notice' ) ) {
		return;
	}

	$packages = WC()->shipping() ? WC()->shipping()->get_packages() : [];
	if ( empty( $packages ) ) {
		return;
	}

	$chosen = WC()->session ? WC()->session->get( 'chosen_shipping_methods' ) : [];

	foreach ( $packages as $pid => $package ) {
		if ( empty( $package['rates'] ) ) {
			continue;
		}

		$chosen_id      = $chosen[ $pid ] ?? null;
		$has_quote_rate = false;
		$campos_cost    = null;
		$standard_cost  = null;
		$is_outlying    = false;

		foreach ( $package['rates'] as $rate_id => $rate ) {
			if ( 0 === strpos( $rate_id, 'sd_request_quote' ) ) {
				if ( null !== $chosen_id && $rate_id !== $chosen_id ) {
					continue 2; // offered here, but not the option chosen
				}
				$has_quote_rate = true;
			} elseif ( 0 === strpos( $rate_id, 'sd_campos_delivery' ) ) {
				$campos_cost = (float) $rate->get_cost();
				$json        = sd_rate_meta( $rate, 'sd_breakdown' );
				$decoded     = $json ? json_decode( $json, true ) : null;
				if ( is_array( $decoded ) && ! empty( $decoded['zone_name'] ) ) {
					$is_outlying = false !== stripos( $decoded['zone_name'], 'outlying' );
				}
			} elseif ( 0 === strpos( $rate_id, 'sd_standard_nationwide' ) ) {
				// Standard Nationwide counts as a real price for the range.
				$standard_cost = null === $standard_cost
					? (float) $rate->get_cost()
					: max( $standard_cost, (float) $rate->get_cost() );
			} elseif ( 'flat_rate' === $rate->get_method_id() ) {
				// Standard Rates - the merchant's own flat-rate methods.
				// Take the dearest if a zone offers several, so the range
				// isn't understated.
				$standard_cost = null === $standard_cost
					? (float) $rate->get_cost()
					: max( $standard_cost, (float) $rate->get_cost() );
			}
		}

		if ( ! $has_quote_rate ) {
			continue;
		}

		$known = array_filter(
			[ $campos_cost, $standard_cost ],
			static fn( $v ) => null !== $v && $v > 0
		);

		$rendered = true;
		?>
		<tr class="sd-quote-range-row">
			<td colspan="2" data-title="">
				<p class="sd-quote-range-notice">
					<?php
					$parts = [];
					if ( count( $known ) === 2 ) {
						$parts[] = SD_Text::html( 'quote_range', [
							'low'  => number_format( min( $known ), 2 ),
							'high' => number_format( max( $known ), 2 ),
						] );
					} else {
						$parts[] = SD_Text::html( 'quote_range_unknown' );
					}
					if ( $is_outlying ) {
						$parts[] = SD_Text::html( 'quote_range_outlying' );
					}
					echo implode( ' ', array_filter( array_map( 'trim', $parts ), 'strlen' ) ); // phpcs:ignore -- escaped in SD_Text::html()
					?>
				</p>
			</td>
		</tr>
		<?php
		return; // one notice per page, for the chosen package only
	}
}
function sd_output_quote_range_notice() {
	sd_safe_render( 'sd_render_quote_range_notice' );
}
add_action( 'woocommerce_review_order_after_shipping', 'sd_output_quote_range_notice' );
add_action( 'woocommerce_cart_totals_after_shipping', 'sd_output_quote_range_notice' );

/**
 * Front-end styling for the bits above. Inlined rather than a separate
 * stylesheet - it's a couple of dozen rules, and a separate file would
 * cost a request on every cart page load to deliver them.
 *
 * Its whole job is containment: nothing here may exceed the width of the
 * table cell it sits in, on any theme, at any viewport. Hence flex-wrap
 * everywhere, max-width:100%, and word-break on the long bits. The few
 * !important rules override theme padding/borders that would otherwise
 * make the injected row look like a broken table row - that's a
 * deliberate, narrow use, scoped to this plugin's own classes only.
 */
add_action( 'wp_enqueue_scripts', function () {
	if ( is_admin() ) {
		return;
	}
	// Cart and checkout cover the classic templates. Some themes render
	// cart totals in a side drawer or on other pages, so a cheap extra
	// check keeps the styles present wherever the markup can appear
	// rather than only where it usually does.
	$relevant = ( function_exists( 'is_cart' ) && is_cart() )
		|| ( function_exists( 'is_checkout' ) && is_checkout() )
		|| ( function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page() );

	if ( ! apply_filters( 'sd_shipping_load_checkout_css', $relevant ) ) {
		return;
	}

	$css = '
		.sd-fuel-note{color:#666;font-size:.85em;}
		.sd-large-item-badge,.sd-nw-badge{color:#666;font-size:.85em;}
		.sd-fuel-icon{display:inline-block;line-height:1;cursor:help;}

		.sd-breakdown-row>td,.sd-quote-range-row>td,.sd-nw-row>td{
			padding:.2em 0 .5em!important;border:0!important;background:none!important;
			font-weight:400;text-align:left;max-width:100%;box-sizing:border-box;
		}
		.sd-breakdown-row>td::before,.sd-quote-range-row>td::before,.sd-nw-row>td::before{content:none!important;}
		.sd-nw-mixed-notice{margin:0;font-size:.85em;color:#8a5a10;font-weight:400;overflow-wrap:anywhere;}

		.sd-breakdown{max-width:100%;font-weight:400;}
		.sd-breakdown summary{cursor:pointer;font-size:.85em;font-weight:600;}
		.sd-breakdown-items{
			margin:.5em 0 .3em;padding:0;list-style:none;font-size:.85em;max-width:100%;
		}
		.sd-breakdown-items li{
			display:flex;flex-wrap:wrap;gap:.1em .8em;justify-content:space-between;
			padding:.2em 0;border-bottom:1px solid rgba(0,0,0,.07);margin:0;
		}
		.sd-breakdown-items li:last-child{border-bottom:0;}
		.sd-bd-name{flex:1 1 60%;min-width:0;overflow-wrap:anywhere;}
		.sd-bd-qty{color:#666;}
		.sd-bd-meta{flex:0 1 auto;color:#666;text-align:right;overflow-wrap:anywhere;}
		.sd-bd-price{flex:0 0 auto;font-weight:600;margin-left:.6em;white-space:nowrap;}
		.sd-breakdown-basis{font-size:.8em;margin:.4em 0 0;color:#666;}
		.sd-breakdown-totals{font-size:.85em;margin:.4em 0 0;overflow-wrap:anywhere;}
		.sd-breakdown-volume{font-size:.85em;margin:.5em 0 0;font-weight:600;}
		.sd-breakdown-floor{font-size:.8em;margin:.35em 0 0;color:#555;overflow-wrap:anywhere;}
		.sd-breakdown-note{font-size:.8em;color:#8a5a10;margin:.3em 0 0;}
		.sd-cbm-missing{color:#b8571e;}
		.sd-cbm-flag,.sd-class-flag{cursor:help;}
		.sd-quote-range-notice{
			font-size:.85em;color:#555;margin:0;font-weight:400;overflow-wrap:anywhere;
		}
	';

	wp_register_style( 'sd-checkout', false, [], SD_SHIPPING_VERSION );
	wp_enqueue_style( 'sd-checkout' );
	wp_add_inline_style( 'sd-checkout', $css );
} );

/**
 * When the quote-only rules have stripped the other options, say why.
 *
 * Without this the customer just sees a single option with no price and
 * no explanation, which reads as a broken checkout rather than a
 * deliberate one. The reason text comes from SD_Rate_Rules, which
 * decided it - this only renders it.
 */
function sd_render_quote_only_notice() {
	if ( ! class_exists( 'SD_Rate_Rules' ) ) {
		return;
	}
	$reason = SD_Rate_Rules::applied_reason();
	if ( empty( $reason['text'] ) ) {
		return;
	}
	?>
	<tr class="sd-quote-only-row">
		<td colspan="2" data-title="">
			<p class="sd-quote-only-notice">
				<?php echo esc_html( $reason['text'] ); ?>
			</p>
		</td>
	</tr>
	<?php
}
function sd_output_quote_only_notice() {
	sd_safe_render( 'sd_render_quote_only_notice' );
}
add_action( 'woocommerce_review_order_after_shipping', 'sd_output_quote_only_notice', 5 );
add_action( 'woocommerce_cart_totals_after_shipping', 'sd_output_quote_only_notice', 5 );

/**
 * Delivery policy and FAQ links at the very foot of the checkout.
 *
 * woocommerce_after_checkout_form rather than inside the totals table:
 * this is page furniture, not part of the order summary, and putting it
 * outside the table keeps it clear of the layout constraints that the
 * breakdown row has to respect.
 */
function sd_render_checkout_links() {
	if ( ! class_exists( 'SD_Settings' ) || ! SD_Settings::is_on( 'show_policy_links' ) ) {
		return;
	}

	$policy = SD_Settings::link( 'policy_url' );
	$faq    = SD_Settings::link( 'faq_url' );

	if ( '' === $policy && '' === $faq ) {
		return;
	}
	?>
	<p class="sd-checkout-links">
		<?php if ( '' !== $policy ) : ?>
			<a href="<?php echo esc_url( $policy ); ?>"><?php echo SD_Text::html( 'link_policy' ); // phpcs:ignore -- escaped in SD_Text::html() ?></a>
		<?php endif; ?>
		<?php if ( '' !== $policy && '' !== $faq ) : ?>
			<span class="sd-sep" aria-hidden="true">&middot;</span>
		<?php endif; ?>
		<?php if ( '' !== $faq ) : ?>
			<a href="<?php echo esc_url( $faq ); ?>"><?php echo SD_Text::html( 'link_faq' ); // phpcs:ignore -- escaped in SD_Text::html() ?></a>
		<?php endif; ?>
	</p>
	<?php
}
add_action( 'woocommerce_after_checkout_form', 'sd_render_checkout_links', 20 );
add_action( 'woocommerce_after_cart_totals', 'sd_render_checkout_links', 20 );

/**
 * The footer links and the quote-only notice need styling on the cart and
 * checkout even when the quote script isn't loaded, so a couple of rules
 * ride along with the inline stylesheet above.
 */
add_action( 'wp_enqueue_scripts', function () {
	if ( ! function_exists( 'is_cart' ) || ( ! is_cart() && ! is_checkout() ) ) {
		return;
	}
	if ( ! wp_style_is( 'sd-checkout', 'enqueued' ) ) {
		return;
	}
	wp_add_inline_style( 'sd-checkout', '
		.sd-quote-only-row>td{padding:.2em 0 .5em!important;border:0!important;background:none!important;font-weight:400;text-align:left;}
		.sd-quote-only-row>td::before{content:none!important;}
		.sd-quote-only-notice{margin:0;font-size:.85em;color:#8a5a10;font-weight:400;overflow-wrap:anywhere;}
		.sd-checkout-links{margin:22px 0 0;padding-top:14px;border-top:1px solid #e5e5e5;font-size:13px;color:#666;text-align:center;}
		.sd-checkout-links a{color:inherit;text-decoration:underline;}
		.sd-checkout-links .sd-sep{margin:0 8px;opacity:.5;}
	' );
}, 20 );

/*
 * ---------------------------------------------------------------------
 * Standard Nationwide Shipping (v0.7.0)
 * ---------------------------------------------------------------------
 */

/**
 * The chosen Standard Nationwide rate's breakdown, or null when another
 * option is chosen.
 *
 * @return array|null
 */
function sd_get_chosen_nationwide() {
	static $cache = false;
	if ( false !== $cache ) {
		return $cache;
	}
	$cache = null;

	$packages = WC()->shipping() ? WC()->shipping()->get_packages() : [];
	$chosen   = WC()->session ? WC()->session->get( 'chosen_shipping_methods' ) : [];

	foreach ( $packages as $pid => $package ) {
		$chosen_id = $chosen[ $pid ] ?? null;
		foreach ( (array) ( $package['rates'] ?? [] ) as $rate_id => $rate ) {
			if ( 0 !== strpos( $rate_id, 'sd_standard_nationwide' ) ) {
				continue;
			}
			if ( null !== $chosen_id && $rate_id !== $chosen_id ) {
				continue;
			}
			$decoded = json_decode( sd_rate_meta( $rate, 'sd_nw_breakdown' ), true );
			if ( is_array( $decoded ) ) {
				$cache = $decoded;
				return $cache;
			}
		}
	}

	return null;
}

/**
 * Item by item: what each courier item costs to this province, so the
 * total is visibly an addition rather than a number from nowhere.
 * Same layout rules as the Campos breakdown above.
 */
function sd_render_nationwide_breakdown() {
	static $rendered = false;
	if ( $rendered || ! SD_Settings::is_on( 'show_breakdown' ) ) {
		return;
	}
	$bd = sd_get_chosen_nationwide();
	if ( ! $bd || empty( $bd['lines'] ) ) {
		return;
	}
	$rendered = true;
	?>
	<tr class="sd-breakdown-row sd-nw-row">
		<td colspan="2" data-title="">
			<details class="sd-breakdown">
				<summary><?php echo SD_Text::html( 'nw_breakdown_title' ); // phpcs:ignore -- escaped in SD_Text::html() ?></summary>
				<ul class="sd-breakdown-items">
					<?php foreach ( $bd['lines'] as $line ) : ?>
						<li>
							<span class="sd-bd-name">
								<?php echo esc_html( $line['name'] ); ?>
								<?php if ( (int) $line['qty'] > 1 ) : ?>
									<span class="sd-bd-qty">&times;&nbsp;<?php echo esc_html( $line['qty'] ); ?></span>
								<?php endif; ?>
							</span>
							<?php if ( (int) $line['qty'] > 1 ) : ?>
								<span class="sd-bd-meta">
									<?php
									/* translators: %s: price per item */
									printf( esc_html__( 'R%s each', 'sd-shipping' ), esc_html( number_format( (float) $line['unit'], 2 ) ) );
									?>
								</span>
							<?php endif; ?>
							<span class="sd-bd-price">R <?php echo esc_html( number_format( (float) $line['line_total'], 2 ) ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
				<?php if ( SD_Text::has( 'nw_breakdown_basis' ) ) : ?>
					<p class="sd-breakdown-basis">
						<?php echo SD_Text::html( 'nw_breakdown_basis', [ 'region' => $bd['region_name'] ?? '' ] ); // phpcs:ignore -- escaped in SD_Text::html() ?>
					</p>
				<?php endif; ?>
			</details>
		</td>
	</tr>
	<?php
}
function sd_output_nationwide_breakdown() {
	sd_safe_render( 'sd_render_nationwide_breakdown' );
}
add_action( 'woocommerce_review_order_after_shipping', 'sd_output_nationwide_breakdown' );
add_action( 'woocommerce_cart_totals_after_shipping', 'sd_output_nationwide_breakdown' );

/**
 * When a basket mixes courier items with a larger one, Standard
 * Nationwide quietly disappears. A customer who saw courier pricing on a
 * product page deserves a sentence saying why.
 *
 * Recomputed here from the cart (cheap: a name match per line) rather
 * than remembered from calculate_shipping(), because WooCommerce caches
 * rates and calculate_shipping() does not run on every page load.
 *
 * Only shown when the customer's zone actually offers the method - it
 * would be odd to explain the absence of an option that never exists
 * there.
 */
function sd_render_nationwide_mixed_notice() {
	static $rendered = false;
	if ( $rendered || ! SD_Settings::is_on( 'nw_mixed_notice' ) || ! SD_Text::has( 'nw_mixed_notice' ) ) {
		return;
	}
	if ( ! WC()->cart || ! WC()->shipping() ) {
		return;
	}

	$packages = WC()->shipping()->get_packages();
	$package  = $packages ? reset( $packages ) : null;
	if ( ! $package ) {
		return;
	}

	// Already offered: nothing to explain.
	foreach ( array_keys( (array) ( $package['rates'] ?? [] ) ) as $rate_id ) {
		if ( 0 === strpos( $rate_id, 'sd_standard_nationwide' ) ) {
			return;
		}
	}

	$lines = SD_Courier_Rates::lines_from_contents( $package['contents'] ?? WC()->cart->get_cart() );
	$has_courier = false;
	foreach ( $lines as $line ) {
		if ( is_array( $line['row'] ) ) {
			$has_courier = true;
			break;
		}
	}
	if ( ! $has_courier ) {
		return; // nothing in the basket was ever going by courier
	}

	$priced = SD_Courier_Rates::price_cart( $lines, SD_Courier_Rates::region_for_destination( $package['destination'] ?? [] ) );
	if ( 'not_courier' !== $priced['reason'] ) {
		return;
	}

	if ( ! sd_zone_offers_method( $package, 'sd_standard_nationwide' ) ) {
		return;
	}

	$names = array_values( array_unique( $priced['not_courier'] ) );
	$items = count( $names ) > 1
		? implode( ', ', array_slice( $names, 0, -1 ) ) . ' ' . __( 'and', 'sd-shipping' ) . ' ' . end( $names )
		: (string) reset( $names );

	$rendered = true;
	?>
	<tr class="sd-nw-row">
		<td colspan="2" data-title="">
			<p class="sd-nw-mixed-notice"><?php echo SD_Text::html( 'nw_mixed_notice', [ 'items' => $items ] ); // phpcs:ignore -- escaped in SD_Text::html() ?></p>
		</td>
	</tr>
	<?php
}
function sd_output_nationwide_mixed_notice() {
	sd_safe_render( 'sd_render_nationwide_mixed_notice' );
}
add_action( 'woocommerce_review_order_after_shipping', 'sd_output_nationwide_mixed_notice', 6 );
add_action( 'woocommerce_cart_totals_after_shipping', 'sd_output_nationwide_mixed_notice', 6 );

/**
 * Does the WooCommerce zone this package lands in have an enabled
 * instance of the given method?
 *
 * @param array  $package
 * @param string $method_id
 * @return bool
 */
function sd_zone_offers_method( $package, $method_id ) {
	if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
		return false;
	}
	$zone = WC_Shipping_Zones::get_zone_matching_package( $package );
	if ( ! $zone ) {
		return false;
	}
	foreach ( $zone->get_shipping_methods( true ) as $method ) {
		if ( $method->id === $method_id ) {
			return true;
		}
	}
	return false;
}
