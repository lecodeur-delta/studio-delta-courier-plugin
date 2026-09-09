<?php
/**
 * The Request a Quote flow.
 *
 * Choosing Request a Quote at checkout is not a purchase — there is no
 * price yet, so there is nothing to pay. This file makes the checkout say
 * so honestly: payment options are hidden, Place order is replaced by
 * "Request a Quote", and pressing it opens a confirmation panel showing
 * exactly what will be sent, with a Send button.
 *
 * On send:
 *   1. A pending order is created (configurable) so the enquiry exists in
 *      WooCommerce with its products and address, ready to have a
 *      delivery cost added and a payment link sent.
 *   2. A uniform HTML table of every checkout field goes to the sales
 *      address, so each enquiry arrives in the same shape and can be
 *      read at a glance.
 *   3. The customer gets told, on the page, that it has been sent — and
 *      optionally a copy by email.
 *
 * Why a confirmation panel rather than submitting straight away: the
 * customer has just typed an address they are being quoted against.
 * Showing it back to them before it goes is the cheapest possible way to
 * catch a typo in the one field the whole quote depends on.
 */

defined( 'ABSPATH' ) || exit;

class SD_Quote_Request {

	const QUOTE_PREFIX = 'sd_request_quote';
	const NONCE        = 'sd_quote_request';
	const ORDER_FLAG   = '_sd_quote_request';

	/**
	 * The checkout fields captured and reported, in the order they appear
	 * in the email. Kept as one list so the form, the confirmation panel
	 * and the email can never disagree about what a quote request
	 * contains.
	 *
	 * @return array<string, string> field name => human label
	 */
	public static function fields() {
		return [
			'billing_first_name'  => __( 'First name', 'sd-shipping' ),
			'billing_last_name'   => __( 'Last name', 'sd-shipping' ),
			'billing_company'     => __( 'Company', 'sd-shipping' ),
			'billing_email'       => __( 'Email', 'sd-shipping' ),
			'billing_phone'       => __( 'Phone', 'sd-shipping' ),
			'billing_address_1'   => __( 'Billing address', 'sd-shipping' ),
			'billing_address_2'   => __( 'Billing address line 2', 'sd-shipping' ),
			'billing_city'        => __( 'Billing town / city', 'sd-shipping' ),
			'billing_state'       => __( 'Billing province', 'sd-shipping' ),
			'billing_postcode'    => __( 'Billing postcode', 'sd-shipping' ),
			'shipping_first_name' => __( 'Delivery first name', 'sd-shipping' ),
			'shipping_last_name'  => __( 'Delivery last name', 'sd-shipping' ),
			'shipping_company'    => __( 'Delivery company', 'sd-shipping' ),
			'shipping_address_1'  => __( 'Delivery address', 'sd-shipping' ),
			'shipping_address_2'  => __( 'Delivery address line 2', 'sd-shipping' ),
			'shipping_city'       => __( 'Delivery town / city', 'sd-shipping' ),
			'shipping_state'      => __( 'Delivery province', 'sd-shipping' ),
			'shipping_postcode'   => __( 'Delivery postcode', 'sd-shipping' ),
			'order_comments'      => __( 'Order notes', 'sd-shipping' ),
		];
	}

	/**
	 * Where quote requests are emailed.
	 *
	 * Read from settings here rather than through
	 * WC_Shipping_SD_RequestQuote::sales_email(), because that class only
	 * loads on woocommerce_shipping_init — which has not necessarily
	 * fired during an admin-ajax request. Depending on it here would make
	 * the submit handler fatal exactly when a customer presses Send.
	 *
	 * @return string
	 */
	public static function sales_email() {
		$configured = class_exists( 'SD_Settings' ) ? SD_Settings::get( 'sales_email' ) : '';
		if ( $configured && is_email( $configured ) ) {
			return $configured;
		}
		return get_option( 'admin_email' );
	}

	public static function init() {
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'assets' ] );
		add_action( 'woocommerce_review_order_after_submit', [ __CLASS__, 'render_ui' ] );
		add_action( 'wp_ajax_sd_submit_quote', [ __CLASS__, 'handle_submit' ] );
		add_action( 'wp_ajax_nopriv_sd_submit_quote', [ __CLASS__, 'handle_submit' ] );
	}

	public static function assets() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		wp_enqueue_style(
			'sd-quote',
			SD_SHIPPING_URL . 'assets/quote.css',
			[],
			SD_SHIPPING_VERSION
		);

		wp_enqueue_script(
			'sd-quote',
			SD_SHIPPING_URL . 'assets/quote.js',
			[ 'jquery' ],
			SD_SHIPPING_VERSION,
			true
		);

		wp_localize_script( 'sd-quote', 'SDQuote', [
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( self::NONCE ),
			'methodMatch' => self::QUOTE_PREFIX,
			'fields'      => self::fields(),
			'i18n'        => [
				'button'      => __( 'Request a Quote', 'sd-shipping' ),
				'title'       => __( 'Confirm your quote request', 'sd-shipping' ),
				'intro'       => __( 'Check the details below, then send. Our team will reply with a delivery price for this order.', 'sd-shipping' ),
				'send'        => __( 'Send request', 'sd-shipping' ),
				'sending'     => __( 'Sending…', 'sd-shipping' ),
				'cancel'      => __( 'Back to checkout', 'sd-shipping' ),
				'sentTitle'   => __( 'Request sent', 'sd-shipping' ),
				'close'       => __( 'Close', 'sd-shipping' ),
				'empty'       => __( 'not given', 'sd-shipping' ),
				'required'    => __( 'Please fill in your name, email and delivery address before requesting a quote.', 'sd-shipping' ),
				'failed'      => __( 'Something went wrong sending your request. Please try again, or email us directly.', 'sd-shipping' ),
				'itemsTitle'  => __( 'Items', 'sd-shipping' ),
				'detailTitle' => __( 'Your details', 'sd-shipping' ),
			],
		] );
	}

	/**
	 * The button and the (hidden) confirmation panel, output once after
	 * the Place order button. The panel is built here in PHP rather than
	 * assembled in JavaScript so its markup is inspectable and its text
	 * is translatable.
	 */
	public static function render_ui() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		?>
		<div class="sd-quote-actions" hidden>
			<button type="button" class="button alt sd-quote-open" id="sd-quote-open">
				<?php esc_html_e( 'Request a Quote', 'sd-shipping' ); ?>
			</button>
			<p class="sd-quote-hint">
				<?php esc_html_e( 'No payment is taken now. We will email you a delivery price for this order.', 'sd-shipping' ); ?>
			</p>
		</div>

		<div class="sd-quote-modal" id="sd-quote-modal" hidden role="dialog" aria-modal="true" aria-labelledby="sd-quote-heading">
			<div class="sd-quote-backdrop" data-sd-close></div>
			<div class="sd-quote-panel" role="document">
				<button type="button" class="sd-quote-x" data-sd-close aria-label="<?php esc_attr_e( 'Close', 'sd-shipping' ); ?>">&times;</button>

				<h2 id="sd-quote-heading" class="sd-quote-heading"></h2>
				<p class="sd-quote-intro"></p>

				<div class="sd-quote-body"></div>

				<p class="sd-quote-error" hidden></p>

				<div class="sd-quote-foot">
					<button type="button" class="button sd-quote-cancel" data-sd-close></button>
					<button type="button" class="button alt sd-quote-send"></button>
				</div>
			</div>
		</div>
		<?php
	}

	// -----------------------------------------------------------------
	// Submission
	// -----------------------------------------------------------------

	public static function handle_submit() {
		check_ajax_referer( self::NONCE, 'nonce' );

		$data = self::sanitise_submission( $_POST );

		// The three things a quote cannot be produced without.
		if ( '' === $data['billing_email'] || ! is_email( $data['billing_email'] ) ) {
			wp_send_json_error( [ 'message' => __( 'A valid email address is needed so we can send you the quote.', 'sd-shipping' ) ] );
		}
		if ( '' === trim( $data['billing_first_name'] . $data['billing_last_name'] ) ) {
			wp_send_json_error( [ 'message' => __( 'Please give us a name for the quote.', 'sd-shipping' ) ] );
		}
		$delivery_town = '' !== $data['shipping_city'] ? $data['shipping_city'] : $data['billing_city'];
		if ( '' === $delivery_town ) {
			wp_send_json_error( [ 'message' => __( 'Please enter the town or city we would be delivering to.', 'sd-shipping' ) ] );
		}

		$items = self::cart_items();
		if ( empty( $items ) ) {
			wp_send_json_error( [ 'message' => __( 'Your basket is empty, so there is nothing to quote.', 'sd-shipping' ) ] );
		}

		$order_id     = 0;
		$order_number = '';
		if ( SD_Settings::is_on( 'quote_creates_order' ) ) {
			$order = self::create_quote_order( $data, $items );
			if ( $order && ! is_wp_error( $order ) ) {
				$order_id     = $order->get_id();
				$order_number = $order->get_order_number();
			}
		}

		$html = self::build_email_html( $data, $items, $order_number );

		$sent = wp_mail(
			self::sales_email(),
			self::subject( $data, $order_number ),
			$html,
			[ 'Content-Type: text/html; charset=UTF-8' ]
		);

		if ( ! $sent ) {
			// The order (if created) still exists, so the enquiry is not
			// lost — but say plainly that the email leg failed rather
			// than reporting success.
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error(
					'Quote request email failed to send' . ( $order_number ? ' for order #' . $order_number : '' ),
					[ 'source' => 'sd-shipping' ]
				);
			}
			wp_send_json_error( [
				'message' => $order_number
					? sprintf(
						/* translators: %s: order number */
						__( 'Your request was recorded as #%s but the notification email failed. Please contact us quoting that number.', 'sd-shipping' ),
						$order_number
					)
					: __( 'We could not send your request just now. Please try again shortly.', 'sd-shipping' ),
			] );
		}

		if ( SD_Settings::is_on( 'quote_copy_customer' ) ) {
			wp_mail(
				$data['billing_email'],
				__( 'We have your delivery quote request', 'sd-shipping' ),
				self::build_customer_html( $data, $items, $order_number ),
				[ 'Content-Type: text/html; charset=UTF-8' ]
			);
		}

		// An order now holds these goods, so the basket is cleared the
		// same way a completed checkout clears it. Leaving it full
		// invites a second, duplicate order for the same items.
		if ( $order_id && function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}

		wp_send_json_success( [
			'title'   => __( 'Request sent', 'sd-shipping' ),
			'message' => self::confirmation_text( $data, $order_number ),
			'order'   => $order_number,
		] );
	}

	/**
	 * @param array $raw $_POST
	 * @return array<string, string> every field in fields(), sanitised
	 */
	public static function sanitise_submission( $raw ) {
		$out = [];
		foreach ( array_keys( self::fields() ) as $key ) {
			$value = isset( $raw[ $key ] ) ? wp_unslash( $raw[ $key ] ) : '';

			if ( 'billing_email' === $key ) {
				$out[ $key ] = sanitize_email( $value );
				continue;
			}
			if ( 'order_comments' === $key ) {
				$out[ $key ] = sanitize_textarea_field( $value );
				continue;
			}
			$out[ $key ] = sanitize_text_field( $value );
		}
		return $out;
	}

	/**
	 * @return array[] name, sku, qty, cbm, product_id, variation_id
	 */
	public static function cart_items() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return [];
		}

		$items = [];
		foreach ( WC()->cart->get_cart() as $line ) {
			$product = $line['data'] ?? null;
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$resolved = class_exists( 'SD_CBM_Resolver' ) ? SD_CBM_Resolver::resolve( $product ) : [ 'cbm' => null ];

			$items[] = [
				'name'         => $product->get_name(),
				'sku'          => $product->get_sku(),
				'qty'          => (int) $line['quantity'],
				'cbm'          => $resolved['cbm'] ?? null,
				'product_id'   => (int) ( $line['product_id'] ?? 0 ),
				'variation_id' => (int) ( $line['variation_id'] ?? 0 ),
			];
		}
		return $items;
	}

	/**
	 * Record the enquiry as a pending WooCommerce order.
	 *
	 * Deliberately NOT marked paid or processing: it is an order awaiting
	 * a delivery price. The order note explains that to whoever opens it,
	 * and the _sd_quote_request flag makes them findable later.
	 *
	 * @param array   $data
	 * @param array[] $items
	 * @return WC_Order|WP_Error|null
	 */
	protected static function create_quote_order( $data, $items ) {
		if ( ! function_exists( 'wc_create_order' ) ) {
			return null;
		}

		$order = wc_create_order( [ 'customer_id' => get_current_user_id() ] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		foreach ( $items as $item ) {
			$product = wc_get_product( $item['variation_id'] ?: $item['product_id'] );
			if ( $product ) {
				$order->add_product( $product, $item['qty'] );
			}
		}

		$order->set_address( [
			'first_name' => $data['billing_first_name'],
			'last_name'  => $data['billing_last_name'],
			'company'    => $data['billing_company'],
			'email'      => $data['billing_email'],
			'phone'      => $data['billing_phone'],
			'address_1'  => $data['billing_address_1'],
			'address_2'  => $data['billing_address_2'],
			'city'       => $data['billing_city'],
			'state'      => $data['billing_state'],
			'postcode'   => $data['billing_postcode'],
			'country'    => 'ZA',
		], 'billing' );

		$order->set_address( [
			'first_name' => $data['shipping_first_name'] ?: $data['billing_first_name'],
			'last_name'  => $data['shipping_last_name'] ?: $data['billing_last_name'],
			'company'    => $data['shipping_company'],
			'address_1'  => $data['shipping_address_1'] ?: $data['billing_address_1'],
			'address_2'  => $data['shipping_address_2'],
			'city'       => $data['shipping_city'] ?: $data['billing_city'],
			'state'      => $data['shipping_state'] ?: $data['billing_state'],
			'postcode'   => $data['shipping_postcode'] ?: $data['billing_postcode'],
			'country'    => 'ZA',
		], 'shipping' );

		if ( '' !== $data['order_comments'] ) {
			$order->set_customer_note( $data['order_comments'] );
		}

		$order->update_meta_data( self::ORDER_FLAG, 'yes' );
		$order->calculate_totals( false ); // goods only — no shipping line yet

		$status = (string) SD_Settings::get( 'quote_order_status' );
		$order->set_status(
			str_replace( 'wc-', '', $status ?: 'pending' ),
			__( 'Delivery quote requested at checkout — awaiting a delivery price. No payment has been taken.', 'sd-shipping' )
		);

		$order->add_order_note(
			__( 'Customer requested a delivery quote instead of paying at checkout. Add the delivery cost to this order, then send the customer a payment link.', 'sd-shipping' ),
			0,
			false
		);

		$order->save();

		return $order;
	}

	/**
	 * @param array  $data
	 * @param string $order_number
	 * @return string
	 */
	public static function subject( $data, $order_number ) {
		$town = $data['shipping_city'] ?: $data['billing_city'];
		$name = trim( $data['billing_first_name'] . ' ' . $data['billing_last_name'] );

		return sprintf(
			'[Quote Request]%s %s — %s',
			$order_number ? ' #' . $order_number : '',
			$name ?: __( 'Website enquiry', 'sd-shipping' ),
			$town ?: __( 'no town given', 'sd-shipping' )
		);
	}

	/**
	 * The uniform HTML table sent to sales.
	 *
	 * A pure function of its arguments — no globals, no database — so the
	 * exact email body can be asserted in a unit test rather than only
	 * discovered in an inbox. Inline styles because email clients discard
	 * stylesheets.
	 *
	 * @param array   $data
	 * @param array[] $items
	 * @param string  $order_number
	 * @return string
	 */
	public static function build_email_html( $data, $items, $order_number = '' ) {
		$labels = self::fields();

		$cell  = 'padding:6px 10px;border:1px solid #dddddd;font-size:13px;';
		$head  = $cell . 'background:#1f3864;color:#ffffff;text-align:left;';
		$label = $cell . 'background:#f6f7f7;width:38%;font-weight:600;';

		$html  = '<div style="font-family:Arial,Helvetica,sans-serif;color:#222222;max-width:680px;">';
		$html .= '<h2 style="font-size:17px;margin:0 0 4px;">' . esc_html__( 'Delivery quote request', 'sd-shipping' ) . '</h2>';

		$html .= '<p style="font-size:13px;color:#555555;margin:0 0 14px;">';
		$html .= esc_html__( 'Submitted from the website checkout. No payment has been taken.', 'sd-shipping' );
		if ( $order_number ) {
			$html .= ' ' . sprintf(
				/* translators: %s: order number */
				esc_html__( 'Recorded as order #%s.', 'sd-shipping' ),
				esc_html( $order_number )
			);
		}
		$html .= '</p>';

		// --- Items ---------------------------------------------------
		$html .= '<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;margin-bottom:18px;">';
		$html .= '<tr><th style="' . $head . '">' . esc_html__( 'Item', 'sd-shipping' ) . '</th>'
			. '<th style="' . $head . '">' . esc_html__( 'SKU', 'sd-shipping' ) . '</th>'
			. '<th style="' . $head . '">' . esc_html__( 'Qty', 'sd-shipping' ) . '</th>'
			. '<th style="' . $head . '">' . esc_html__( 'Volume (m³)', 'sd-shipping' ) . '</th></tr>';

		$total_cbm = 0.0;
		$unsized   = 0;
		foreach ( $items as $item ) {
			$cbm = (float) ( $item['cbm'] ?? 0 );
			if ( $cbm > 0 ) {
				$total_cbm += $cbm * (int) $item['qty'];
			} else {
				$unsized++;
			}
			$html .= '<tr>'
				. '<td style="' . $cell . '">' . esc_html( $item['name'] ) . '</td>'
				. '<td style="' . $cell . '">' . esc_html( $item['sku'] ?: '—' ) . '</td>'
				. '<td style="' . $cell . '">' . esc_html( $item['qty'] ) . '</td>'
				. '<td style="' . $cell . '">' . ( $cbm > 0 ? esc_html( number_format( $cbm, 4 ) ) : esc_html__( 'not sized', 'sd-shipping' ) ) . '</td>'
				. '</tr>';
		}

		$html .= '<tr><td style="' . $cell . 'font-weight:600;" colspan="3">'
			. esc_html__( 'Total volume', 'sd-shipping' ) . '</td>'
			. '<td style="' . $cell . 'font-weight:600;">' . esc_html( number_format( $total_cbm, 4 ) ) . '</td></tr>';
		$html .= '</table>';

		if ( $unsized > 0 ) {
			$html .= '<p style="font-size:12px;color:#8a5a10;margin:0 0 18px;">'
				. sprintf(
					/* translators: %d: number of items with no volume on file */
					esc_html( _n( '%d item has no volume on file, so the total above is understated.', '%d items have no volume on file, so the total above is understated.', $unsized, 'sd-shipping' ) ),
					(int) $unsized
				)
				. '</p>';
		}

		// --- Customer details ----------------------------------------
		$html .= '<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;">';
		$html .= '<tr><th style="' . $head . '" colspan="2">' . esc_html__( 'Customer details', 'sd-shipping' ) . '</th></tr>';

		foreach ( $labels as $key => $text ) {
			$value = trim( (string) ( $data[ $key ] ?? '' ) );
			$html .= '<tr>'
				. '<td style="' . $label . '">' . esc_html( $text ) . '</td>'
				. '<td style="' . $cell . '">'
				. ( '' !== $value ? nl2br( esc_html( $value ) ) : '<span style="color:#999999;">—</span>' )
				. '</td></tr>';
		}

		$html .= '</table>';

		$html .= '<p style="font-size:12px;color:#777777;margin-top:18px;">'
			. esc_html( sprintf(
				/* translators: %s: date and time */
				__( 'Received %s', 'sd-shipping' ),
				function_exists( 'current_time' ) ? current_time( 'Y-m-d H:i' ) : gmdate( 'Y-m-d H:i' )
			) )
			. '</p>';

		return $html . '</div>';
	}

	/**
	 * The customer's copy — the same facts, without the internal notes.
	 *
	 * @param array   $data
	 * @param array[] $items
	 * @param string  $order_number
	 * @return string
	 */
	public static function build_customer_html( $data, $items, $order_number = '' ) {
		$cell = 'padding:6px 10px;border:1px solid #dddddd;font-size:13px;';
		$head = $cell . 'background:#1f3864;color:#ffffff;text-align:left;';

		$html  = '<div style="font-family:Arial,Helvetica,sans-serif;color:#222222;max-width:680px;">';
		$html .= '<h2 style="font-size:17px;margin:0 0 8px;">' . esc_html__( 'Thank you — we have your quote request', 'sd-shipping' ) . '</h2>';
		$html .= '<p style="font-size:13px;margin:0 0 14px;">'
			. esc_html__( 'We will work out the delivery cost for these items to your address and email you a price. No payment has been taken.', 'sd-shipping' )
			. '</p>';

		$html .= '<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;margin-bottom:16px;">';
		$html .= '<tr><th style="' . $head . '">' . esc_html__( 'Item', 'sd-shipping' ) . '</th>'
			. '<th style="' . $head . '">' . esc_html__( 'Qty', 'sd-shipping' ) . '</th></tr>';
		foreach ( $items as $item ) {
			$html .= '<tr><td style="' . $cell . '">' . esc_html( $item['name'] ) . '</td>'
				. '<td style="' . $cell . '">' . esc_html( $item['qty'] ) . '</td></tr>';
		}
		$html .= '</table>';

		$town = $data['shipping_city'] ?: $data['billing_city'];
		$html .= '<p style="font-size:13px;margin:0;">'
			. esc_html( sprintf(
				/* translators: %s: town or city */
				__( 'Delivering to: %s', 'sd-shipping' ),
				$town
			) )
			. '</p>';

		if ( $order_number ) {
			$html .= '<p style="font-size:13px;color:#555555;">'
				. esc_html( sprintf(
					/* translators: %s: order number */
					__( 'Your reference is #%s.', 'sd-shipping' ),
					$order_number
				) )
				. '</p>';
		}

		return $html . '</div>';
	}

	/**
	 * @param array  $data
	 * @param string $order_number
	 * @return string
	 */
	public static function confirmation_text( $data, $order_number ) {
		$text = sprintf(
			/* translators: %s: the customer's email address */
			__( 'Your request has been emailed to our team. We will reply to %s with a delivery price for this order.', 'sd-shipping' ),
			$data['billing_email']
		);

		if ( $order_number ) {
			$text .= ' ' . sprintf(
				/* translators: %s: order number */
				__( 'Your reference is #%s.', 'sd-shipping' ),
				$order_number
			);
		}

		return $text;
	}
}
