<?php
/**
 * Every word a customer reads from this plugin, in one editable place.
 *
 * Studio Delta > Checkout Text lists each piece of wording below, grouped
 * by where it appears - the four delivery options, the Request a Quote
 * popup, the customer's email copy and the footer links. Each one ships
 * with the plugin's default, can be rewritten freely, and supports
 * {placeholders} for the numbers that change per order.
 *
 * Two kinds of field:
 *   - Required: clear it and save, and the default comes back. A blank
 *     button or option label would leave a customer stuck.
 *   - Optional (marked so on the screen): clear it and it is hidden. Use
 *     these for badges, hints and explanatory notes you would rather not
 *     show.
 *
 * Stored as one option (sd_text) separate from sd_settings, so wording
 * can be reset to defaults without touching a single price or rule.
 *
 * Built by Levi Bugera - Delta Creative Agency - https://www.lecodeur.co.za
 */

defined( 'ABSPATH' ) || exit;

class SD_Text {

	const OPTION = 'sd_text';

	/** @var array|null request cache of saved values */
	protected static $saved = null;

	/**
	 * Section headings for the admin screen, in display order.
	 *
	 * @return array<string, array{0: string, 1: string}> group => [title, where it shows]
	 */
	public static function groups() {
		return [
			'nationwide'  => [ __( 'Standard Nationwide Shipping', 'sd-shipping' ), __( 'The flat-price courier option for smaller items.', 'sd-shipping' ) ],
			'campos'      => [ __( 'Campos Delivery', 'sd-shipping' ), __( 'The volume-priced option, its fuel levy note and its cost breakdown.', 'sd-shipping' ) ],
			'tbm'         => [ __( '3BM Delivery', 'sd-shipping' ), __( 'The distance-priced Gauteng option.', 'sd-shipping' ) ],
			'quote'       => [ __( 'Request a Quote option', 'sd-shipping' ), __( 'The option itself, the price-range note beside it, and the notices shown when it is the only option.', 'sd-shipping' ) ],
			'quote_popup' => [ __( 'Request a Quote popup', 'sd-shipping' ), __( 'The button that replaces Place order, and every message in the form popup it opens.', 'sd-shipping' ) ],
			'quote_email' => [ __( 'Quote request email to the customer', 'sd-shipping' ), __( 'The copy the customer receives when "Copy the customer" is on in Settings.', 'sd-shipping' ) ],
			'footer'      => [ __( 'Checkout footer links', 'sd-shipping' ), __( 'Link text only - the addresses are set in Settings.', 'sd-shipping' ) ],
		];
	}

	/**
	 * The registry: every customer-facing string.
	 *
	 * label    what the admin screen calls it
	 * default  the shipped wording
	 * tokens   {placeholders} it understands, with what each becomes
	 * long     true for a textarea rather than a one-line box
	 * optional true when blank means "hide it" rather than "use default"
	 *
	 * @return array<string, array>
	 */
	public static function fields() {
		return [

			// --- Standard Nationwide -----------------------------------
			'nw_label' => [
				'group'   => 'nationwide',
				'label'   => __( 'Option label', 'sd-shipping' ),
				'default' => __( 'Standard Nationwide Shipping', 'sd-shipping' ),
				'tokens'  => [ 'region' => __( 'delivery province, e.g. Western Cape', 'sd-shipping' ) ],
			],
			'nw_badge' => [
				'group'    => 'nationwide',
				'label'    => __( 'Note beside the label', 'sd-shipping' ),
				'default'  => __( 'by courier', 'sd-shipping' ),
				'optional' => true,
				'help'     => __( 'Shown in brackets after the option name. Delivery times work well here, e.g. "3 to 5 working days".', 'sd-shipping' ),
			],
			'nw_breakdown_title' => [
				'group'   => 'nationwide',
				'label'   => __( 'Breakdown heading', 'sd-shipping' ),
				'default' => __( 'Delivery cost breakdown', 'sd-shipping' ),
			],
			'nw_breakdown_basis' => [
				'group'    => 'nationwide',
				'label'    => __( 'Breakdown explanation', 'sd-shipping' ),
				'default'  => __( 'Each item has a fixed courier price to {region}, added together. Prices include VAT.', 'sd-shipping' ),
				'tokens'   => [ 'region' => __( 'delivery province', 'sd-shipping' ) ],
				'long'     => true,
				'optional' => true,
			],
			'nw_mixed_notice' => [
				'group'    => 'nationwide',
				'label'    => __( 'Notice when not available for this basket', 'sd-shipping' ),
				'default'  => __( 'Standard Nationwide Shipping is only offered when every item in your basket can go by courier, and this basket includes a larger item ({items}). The options above cover this order.', 'sd-shipping' ),
				'tokens'   => [ 'items' => __( 'the item(s) that cannot go by courier', 'sd-shipping' ) ],
				'long'     => true,
				'optional' => true,
				'help'     => __( 'Only shown when a courier item is in the basket alongside one that is not. Also needs "Explain when it is not offered" on in Settings.', 'sd-shipping' ),
			],

			// --- Campos -----------------------------------------------
			'campos_label' => [
				'group'   => 'campos',
				'label'   => __( 'Option label', 'sd-shipping' ),
				'default' => __( 'Campos Delivery - {zone}', 'sd-shipping' ),
				'tokens'  => [ 'zone' => __( 'delivery area, e.g. Western Cape', 'sd-shipping' ) ],
			],
			'campos_badge' => [
				'group'    => 'campos',
				'label'    => __( 'Note beside the label', 'sd-shipping' ),
				'default'  => '',
				'optional' => true,
				'help'     => __( 'Shown in brackets after the option name. Empty by default.', 'sd-shipping' ),
			],
			'campos_fuel_note' => [
				'group'    => 'campos',
				'label'    => __( 'Fuel levy note beside the label', 'sd-shipping' ),
				'default'  => __( 'incl. {percent}% fuel levy: R{amount}', 'sd-shipping' ),
				'tokens'   => [ 'percent' => __( 'levy percentage', 'sd-shipping' ), 'amount' => __( 'levy in Rand', 'sd-shipping' ) ],
				'optional' => true,
			],
			'campos_fuel_tooltip' => [
				'group'   => 'campos',
				'label'   => __( 'Fuel icon tooltip', 'sd-shipping' ),
				'default' => __( 'A courier fuel surcharge, set monthly by the transport company and applied to the delivery charge before VAT.', 'sd-shipping' ),
				'long'    => true,
			],
			'campos_breakdown_title' => [
				'group'   => 'campos',
				'label'   => __( 'Breakdown heading', 'sd-shipping' ),
				'default' => __( 'Delivery cost breakdown', 'sd-shipping' ),
			],
			'campos_basis_note' => [
				'group'    => 'campos',
				'label'    => __( 'Per-item pricing explanation', 'sd-shipping' ),
				'default'  => __( 'Each item is priced on its own volume and added together, which is how the courier invoices a multi-piece order.', 'sd-shipping' ),
				'long'     => true,
				'optional' => true,
			],
			'campos_volume' => [
				'group'    => 'campos',
				'label'    => __( 'Total volume line', 'sd-shipping' ),
				'default'  => __( 'Total volume in this order: {cbm} m³', 'sd-shipping' ),
				'tokens'   => [ 'cbm' => __( 'cubic metres', 'sd-shipping' ) ],
				'optional' => true,
			],
			'campos_floor_item' => [
				'group'    => 'campos',
				'label'    => __( 'Minimum charge note (per item)', 'sd-shipping' ),
				'default'  => __( 'One or more items is below the R{min} minimum per item for {zone}, so it is charged at that minimum. An item only costs more than the minimum once it exceeds {cbm} m³.', 'sd-shipping' ),
				'tokens'   => [ 'min' => __( 'minimum charge', 'sd-shipping' ), 'zone' => __( 'delivery area', 'sd-shipping' ), 'cbm' => __( 'volume the minimum covers', 'sd-shipping' ) ],
				'long'     => true,
				'optional' => true,
			],
			'campos_floor_order' => [
				'group'    => 'campos',
				'label'    => __( 'Minimum charge note (per order)', 'sd-shipping' ),
				'default'  => __( 'This is the minimum delivery charge for {zone} (R{min}), which covers up to {cbm} m³ - so you can add roughly {remaining} m³ more before the delivery price changes.', 'sd-shipping' ),
				'tokens'   => [ 'zone' => __( 'delivery area', 'sd-shipping' ), 'min' => __( 'minimum charge', 'sd-shipping' ), 'cbm' => __( 'volume the minimum covers', 'sd-shipping' ), 'remaining' => __( 'volume still free', 'sd-shipping' ) ],
				'long'     => true,
				'optional' => true,
			],
			'campos_floor_plain' => [
				'group'    => 'campos',
				'label'    => __( 'Minimum charge note (nothing sized)', 'sd-shipping' ),
				'default'  => __( 'This is the minimum delivery charge for {zone} (R{min}). The charge is per delivery, not per item.', 'sd-shipping' ),
				'tokens'   => [ 'zone' => __( 'delivery area', 'sd-shipping' ), 'min' => __( 'minimum charge', 'sd-shipping' ) ],
				'long'     => true,
				'optional' => true,
			],
			'campos_unsized' => [
				'group'    => 'campos',
				'label'    => __( 'Note when no item has a size', 'sd-shipping' ),
				'default'  => __( 'None of these items has a measured size on file yet, so this is the minimum charge for the area rather than a calculated price. Contact us to confirm delivery for a large order.', 'sd-shipping' ),
				'long'     => true,
				'optional' => true,
			],
			'campos_estimated' => [
				'group'    => 'campos',
				'label'    => __( 'Note when a size is estimated', 'sd-shipping' ),
				'default'  => __( '* Size estimated from the product description rather than measured - treat as approximate.', 'sd-shipping' ),
				'long'     => true,
				'optional' => true,
			],
			'campos_unmatched' => [
				'group'    => 'campos',
				'label'    => __( 'Notice when the town is not recognised', 'sd-shipping' ),
				'default'  => __( 'We couldn\'t match your address to a delivery zone automatically. Please contact us for a delivery quote.', 'sd-shipping' ),
				'long'     => true,
				'optional' => true,
				'help'     => __( 'Also needs "Unrecognised town" set to show a notice in Settings.', 'sd-shipping' ),
			],

			// --- 3BM --------------------------------------------------
			'tbm_label' => [
				'group'   => 'tbm',
				'label'   => __( 'Option label', 'sd-shipping' ),
				'default' => __( '3BM Delivery - {km}km', 'sd-shipping' ),
				'tokens'  => [ 'km' => __( 'distance from the depot', 'sd-shipping' ) ],
			],

			// --- Request a Quote option --------------------------------
			'quote_label' => [
				'group'   => 'quote',
				'label'   => __( 'Option label', 'sd-shipping' ),
				'default' => __( 'Request a Delivery Quote', 'sd-shipping' ),
			],
			'quote_range' => [
				'group'    => 'quote',
				'label'    => __( 'Price range note', 'sd-shipping' ),
				'default'  => __( 'A hand-quoted price for this order usually lands between R{low} and R{high} - the range between our other delivery options for the same basket. Outlying areas sit toward the higher end.', 'sd-shipping' ),
				'tokens'   => [ 'low' => __( 'cheapest other option', 'sd-shipping' ), 'high' => __( 'dearest other option', 'sd-shipping' ) ],
				'long'     => true,
				'optional' => true,
				'help'     => __( 'Used when at least two other priced options exist. Also needs "Range notice" on in Settings.', 'sd-shipping' ),
			],
			'quote_range_unknown' => [
				'group'    => 'quote',
				'label'    => __( 'Note when no range is known', 'sd-shipping' ),
				'default'  => __( 'We\'ll email you a delivery price once we\'ve confirmed your address. It will range depending on the items and the area - especially for outlying zones.', 'sd-shipping' ),
				'long'     => true,
				'optional' => true,
			],
			'quote_range_outlying' => [
				'group'    => 'quote',
				'label'    => __( 'Extra note for outlying areas', 'sd-shipping' ),
				'default'  => __( 'Your area is one of our outlying zones, so expect a figure toward the higher end of that range.', 'sd-shipping' ),
				'long'     => true,
				'optional' => true,
			],
			'quote_only_outside' => [
				'group'    => 'quote',
				'label'    => __( 'Notice: address outside our routes', 'sd-shipping' ),
				'default'  => __( 'Your area is outside our standard delivery routes, so delivery is quoted individually rather than priced automatically.', 'sd-shipping' ),
				'long'     => true,
				'optional' => true,
			],
			'quote_only_threshold' => [
				'group'    => 'quote',
				'label'    => __( 'Notice: delivery too high a share of the order', 'sd-shipping' ),
				'default'  => __( 'Delivery for this order would come to more than {percent}% of the order value, so we would rather quote it by hand and look for a better option for you.', 'sd-shipping' ),
				'tokens'   => [ 'percent' => __( 'the threshold set in Settings', 'sd-shipping' ) ],
				'long'     => true,
				'optional' => true,
			],

			// --- Request a Quote popup ---------------------------------
			'qp_button' => [
				'group'   => 'quote_popup',
				'label'   => __( 'Button (replaces Place order)', 'sd-shipping' ),
				'default' => __( 'Request a Quote', 'sd-shipping' ),
			],
			'qp_hint' => [
				'group'    => 'quote_popup',
				'label'    => __( 'Hint under the button', 'sd-shipping' ),
				'default'  => __( 'No payment is taken now. We will email you a delivery price for this order.', 'sd-shipping' ),
				'long'     => true,
				'optional' => true,
			],
			'qp_title' => [
				'group'   => 'quote_popup',
				'label'   => __( 'Popup heading', 'sd-shipping' ),
				'default' => __( 'Confirm your quote request', 'sd-shipping' ),
			],
			'qp_intro' => [
				'group'    => 'quote_popup',
				'label'    => __( 'Popup introduction', 'sd-shipping' ),
				'default'  => __( 'Check the details below, then send. Our team will reply with a delivery price for this order.', 'sd-shipping' ),
				'long'     => true,
				'optional' => true,
			],
			'qp_items_title' => [
				'group'   => 'quote_popup',
				'label'   => __( 'Items heading', 'sd-shipping' ),
				'default' => __( 'Items', 'sd-shipping' ),
			],
			'qp_details_title' => [
				'group'   => 'quote_popup',
				'label'   => __( 'Details heading', 'sd-shipping' ),
				'default' => __( 'Your details', 'sd-shipping' ),
			],
			'qp_send' => [
				'group'   => 'quote_popup',
				'label'   => __( 'Send button', 'sd-shipping' ),
				'default' => __( 'Send request', 'sd-shipping' ),
			],
			'qp_sending' => [
				'group'   => 'quote_popup',
				'label'   => __( 'Send button while sending', 'sd-shipping' ),
				'default' => __( 'Sending…', 'sd-shipping' ),
			],
			'qp_cancel' => [
				'group'   => 'quote_popup',
				'label'   => __( 'Cancel button', 'sd-shipping' ),
				'default' => __( 'Back to checkout', 'sd-shipping' ),
			],
			'qp_sent_title' => [
				'group'   => 'quote_popup',
				'label'   => __( 'Heading once sent', 'sd-shipping' ),
				'default' => __( 'Request sent', 'sd-shipping' ),
			],
			'qp_sent_message' => [
				'group'   => 'quote_popup',
				'label'   => __( 'Message once sent', 'sd-shipping' ),
				'default' => __( 'Your request has been emailed to our team. We will reply to {email} with a delivery price for this order.', 'sd-shipping' ),
				'tokens'  => [ 'email' => __( 'the customer\'s email address', 'sd-shipping' ) ],
				'long'    => true,
			],
			'qp_reference' => [
				'group'    => 'quote_popup',
				'label'    => __( 'Reference number line', 'sd-shipping' ),
				'default'  => __( 'Your reference is #{order}.', 'sd-shipping' ),
				'tokens'   => [ 'order' => __( 'order number', 'sd-shipping' ) ],
				'optional' => true,
				'help'     => __( 'Used in the popup and in the customer\'s email copy. Only shown when "Record an order" is on in Settings.', 'sd-shipping' ),
			],
			'qp_close' => [
				'group'   => 'quote_popup',
				'label'   => __( 'Close button', 'sd-shipping' ),
				'default' => __( 'Close', 'sd-shipping' ),
			],
			'qp_required' => [
				'group'   => 'quote_popup',
				'label'   => __( 'Missing details message', 'sd-shipping' ),
				'default' => __( 'Please fill in your name, email and delivery address before requesting a quote.', 'sd-shipping' ),
				'long'    => true,
			],
			'qp_err_email' => [
				'group'   => 'quote_popup',
				'label'   => __( 'Invalid email message', 'sd-shipping' ),
				'default' => __( 'A valid email address is needed so we can send you the quote.', 'sd-shipping' ),
			],
			'qp_err_name' => [
				'group'   => 'quote_popup',
				'label'   => __( 'Missing name message', 'sd-shipping' ),
				'default' => __( 'Please give us a name for the quote.', 'sd-shipping' ),
			],
			'qp_err_town' => [
				'group'   => 'quote_popup',
				'label'   => __( 'Missing town message', 'sd-shipping' ),
				'default' => __( 'Please enter the town or city we would be delivering to.', 'sd-shipping' ),
			],
			'qp_err_empty' => [
				'group'   => 'quote_popup',
				'label'   => __( 'Empty basket message', 'sd-shipping' ),
				'default' => __( 'Your basket is empty, so there is nothing to quote.', 'sd-shipping' ),
			],
			'qp_failed' => [
				'group'   => 'quote_popup',
				'label'   => __( 'Sending failed message', 'sd-shipping' ),
				'default' => __( 'Something went wrong sending your request. Please try again, or email us directly.', 'sd-shipping' ),
				'long'    => true,
			],

			// --- Customer email ----------------------------------------
			'qe_subject' => [
				'group'   => 'quote_email',
				'label'   => __( 'Email subject', 'sd-shipping' ),
				'default' => __( 'We have your delivery quote request', 'sd-shipping' ),
			],
			'qe_heading' => [
				'group'   => 'quote_email',
				'label'   => __( 'Email heading', 'sd-shipping' ),
				'default' => __( 'Thank you - we have your quote request', 'sd-shipping' ),
			],
			'qe_intro' => [
				'group'    => 'quote_email',
				'label'    => __( 'Email introduction', 'sd-shipping' ),
				'default'  => __( 'We will work out the delivery cost for these items to your address and email you a price. No payment has been taken.', 'sd-shipping' ),
				'long'     => true,
				'optional' => true,
			],
			'qe_delivering' => [
				'group'    => 'quote_email',
				'label'    => __( 'Delivery town line', 'sd-shipping' ),
				'default'  => __( 'Delivering to: {town}', 'sd-shipping' ),
				'tokens'   => [ 'town' => __( 'town or city', 'sd-shipping' ) ],
				'optional' => true,
			],

			// --- Footer ------------------------------------------------
			'link_policy' => [
				'group'   => 'footer',
				'label'   => __( 'Delivery policy link', 'sd-shipping' ),
				'default' => __( 'Delivery Policy', 'sd-shipping' ),
			],
			'link_faq' => [
				'group'   => 'footer',
				'label'   => __( 'FAQ link', 'sd-shipping' ),
				'default' => __( 'FAQ', 'sd-shipping' ),
			],
		];
	}

	/**
	 * @return array saved values, key => string
	 */
	protected static function saved() {
		if ( null === self::$saved ) {
			$raw         = function_exists( 'get_option' ) ? get_option( self::OPTION, [] ) : [];
			self::$saved = is_array( $raw ) ? $raw : [];
		}
		return self::$saved;
	}

	/**
	 * The raw template for a key: the saved wording, or the default.
	 *
	 * @param string $key
	 * @return string
	 */
	public static function template( $key ) {
		$fields = self::fields();
		if ( ! isset( $fields[ $key ] ) ) {
			return '';
		}
		$field = $fields[ $key ];
		$saved = self::saved();

		if ( array_key_exists( $key, $saved ) ) {
			$value = (string) $saved[ $key ];
			// A blank optional field means "hide it". A blank required
			// field can only be an accident, so the default stands.
			if ( '' !== trim( $value ) || ! empty( $field['optional'] ) ) {
				return $value;
			}
		}

		return (string) $field['default'];
	}

	/**
	 * Plain text with {tokens} filled in. NOT escaped - for wp_mail
	 * subjects, JSON for the popup script, and anything passed on to a
	 * function that escapes itself.
	 *
	 * @param string $key
	 * @param array  $tokens name => value
	 * @return string
	 */
	public static function get( $key, array $tokens = [] ) {
		return self::fill( self::template( $key ), $tokens );
	}

	/**
	 * Escaped HTML with {tokens} filled in.
	 *
	 * The wording itself is always escaped - a merchant cannot break the
	 * checkout by typing an angle bracket. Values in $tokens are escaped
	 * too; only values in $html_tokens (an icon, a <strong>) go in raw,
	 * and those only ever come from this plugin's own code.
	 *
	 * @param string $key
	 * @param array  $tokens      name => plain text value
	 * @param array  $html_tokens name => trusted HTML
	 * @return string
	 */
	public static function html( $key, array $tokens = [], array $html_tokens = [] ) {
		$out = esc_html( self::template( $key ) );

		foreach ( $tokens as $name => $value ) {
			$out = str_replace( '{' . $name . '}', esc_html( (string) $value ), $out );
		}
		foreach ( $html_tokens as $name => $value ) {
			$out = str_replace( '{' . $name . '}', (string) $value, $out );
		}

		return $out;
	}

	/**
	 * @param string $template
	 * @param array  $tokens
	 * @return string
	 */
	public static function fill( $template, array $tokens ) {
		foreach ( $tokens as $name => $value ) {
			$template = str_replace( '{' . $name . '}', (string) $value, $template );
		}
		return $template;
	}

	/**
	 * True when the wording for this key is non-empty, i.e. should be
	 * shown at all.
	 *
	 * @param string $key
	 * @return bool
	 */
	public static function has( $key ) {
		return '' !== trim( self::template( $key ) );
	}

	/**
	 * Sanitise a submitted form into storable values.
	 *
	 * Only differences from the default are stored, so a later plugin
	 * update that improves a default still reaches every field the
	 * merchant never touched. A blank optional field IS stored (as ''),
	 * because that is a decision to hide it.
	 *
	 * @param array $input raw $_POST['sd_text']
	 * @return array
	 */
	public static function sanitise( array $input ) {
		$clean = [];
		foreach ( self::fields() as $key => $field ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$raw   = wp_unslash( $input[ $key ] );
			$value = ! empty( $field['long'] ) ? sanitize_textarea_field( $raw ) : sanitize_text_field( $raw );

			if ( '' === trim( $value ) ) {
				if ( ! empty( $field['optional'] ) ) {
					$clean[ $key ] = '';
				}
				continue;
			}
			if ( $value === (string) $field['default'] ) {
				continue;
			}
			$clean[ $key ] = $value;
		}
		return $clean;
	}

	/**
	 * @param array $clean output of sanitise()
	 */
	public static function save( array $clean ) {
		update_option( self::OPTION, $clean );
		self::$saved = $clean;

		if ( class_exists( 'WC_Cache_Helper' ) ) {
			// Option labels are baked into cached rates.
			WC_Cache_Helper::get_transient_version( 'shipping', true );
		}
	}

	/** For tests. */
	public static function set_saved( array $values ) {
		self::$saved = $values;
	}
}
