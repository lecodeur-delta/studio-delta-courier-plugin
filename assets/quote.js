/**
 * Request a Quote - checkout behaviour.
 *
 * Two jobs:
 *   1. While the quote method is the chosen shipping option, hide the
 *      payment section and Place order button and show "Request a Quote"
 *      instead. There is no price yet, so there is nothing to pay, and a
 *      live Place order button would create an order the customer cannot
 *      complete.
 *   2. On click, read the checkout fields, show them back for
 *      confirmation, and send them by AJAX.
 *
 * Deliberately self-contained: no dependency on the theme's own modal
 * (plainOverlay, .ts-popup-modal) or its stylesheet. A checkout is the
 * worst possible place to inherit someone else's markup assumptions -
 * this plugin has already broken this page once by doing that.
 *
 * Built by Levi Bugera - Delta Creative Agency - https://www.lecodeur.co.za
 */
( function ( $ ) {
	'use strict';

	if ( typeof window.SDQuote === 'undefined' ) {
		return;
	}

	var cfg  = window.SDQuote;
	var i18n = cfg.i18n || {};

	var $modal, $actions, sending = false;

	/**
	 * Is the currently selected shipping method the quote option?
	 * WooCommerce names these inputs shipping_method[0], and replaces the
	 * whole block on every address change, so this is always read live
	 * rather than cached.
	 */
	function quoteChosen() {
		var $chosen = $( 'input[name^="shipping_method"]:checked' );
		if ( ! $chosen.length ) {
			// Single available method renders as a hidden input rather
			// than a radio, so fall back to that.
			$chosen = $( 'input[name^="shipping_method"]' ).first();
		}
		return $chosen.length
			? String( $chosen.val() || '' ).indexOf( cfg.methodMatch ) === 0
			: false;
	}

	/** Show the quote button and hide anything payment-related. */
	function applyMode() {
		var quote = quoteChosen();

		$actions = $( '.sd-quote-actions' );

		var $payment = $( '#payment' );
		var $methods = $payment.find( '.wc_payment_methods, .payment_methods' );
		var $place   = $( '#place_order' );
		var $terms   = $payment.find( '.woocommerce-terms-and-conditions-wrapper' );

		if ( quote ) {
			$methods.attr( 'hidden', true ).hide();
			$terms.hide();
			// Disabled as well as hidden: a hidden-but-enabled submit can
			// still be triggered by Enter in a text field.
			$place.attr( 'hidden', true ).hide().prop( 'disabled', true );
			$actions.removeAttr( 'hidden' ).show();
			$payment.addClass( 'sd-quote-mode' );
		} else {
			$methods.removeAttr( 'hidden' ).show();
			$terms.show();
			$place.removeAttr( 'hidden' ).show().prop( 'disabled', false );
			$actions.attr( 'hidden', true ).hide();
			$payment.removeClass( 'sd-quote-mode' );
		}
	}

	/** Read one checkout field, preferring the shipping value when the
	 *  customer is shipping to a different address. */
	function fieldValue( name ) {
		var $el = $( '#' + name );
		if ( ! $el.length ) {
			return '';
		}
		// A <select> (province, country) should report its label, not its
		// code - "Gauteng" reads better in an email than "GP".
		if ( $el.is( 'select' ) ) {
			var text = $el.find( 'option:selected' ).text();
			return $.trim( text || $el.val() || '' );
		}
		return $.trim( $el.val() || '' );
	}

	/**
	 * Collect every field the server expects. When "ship to a different
	 * address" is unticked WooCommerce hides the shipping inputs, so the
	 * billing values are copied across - otherwise the email would show
	 * an empty delivery address for most orders.
	 */
	function collect() {
		var data = {};
		var separate = $( '#ship-to-different-address-checkbox' ).is( ':checked' );

		Object.keys( cfg.fields ).forEach( function ( name ) {
			data[ name ] = fieldValue( name );
		} );

		if ( ! separate ) {
			[ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode' ]
				.forEach( function ( part ) {
					if ( ! data[ 'shipping_' + part ] ) {
						data[ 'shipping_' + part ] = data[ 'billing_' + part ] || '';
					}
				} );
		}

		return data;
	}

	/** The cart lines, read from the order review table already on the page. */
	function collectItems() {
		var items = [];
		$( '.woocommerce-checkout-review-order-table .cart_item' ).each( function () {
			var $row = $( this );
			var name = $.trim( $row.find( '.product-name' ).clone().children( 'strong, .product-quantity' ).remove().end().text() );
			var qty  = $.trim( $row.find( '.product-quantity' ).text() ).replace( /[^0-9]/g, '' );
			if ( name ) {
				items.push( { name: name, qty: qty || '1' } );
			}
		} );
		return items;
	}

	function esc( s ) {
		return $( '<div>' ).text( s == null ? '' : s ).html();
	}

	/**
	 * Build the popup body as a real FORM, not a read-only summary.
	 *
	 * The fields are prefilled from the checkout but editable, because a
	 * customer who spots a wrong phone number in the confirmation panel
	 * should be able to fix it there rather than closing the panel,
	 * scrolling back up the checkout, and starting again.
	 */
	function buildForm( data, items ) {
		var html = '';

		if ( items.length ) {
			html += '<h3 class="sd-quote-sub">' + esc( i18n.itemsTitle ) + '</h3>';
			html += '<ul class="sd-quote-items">';
			items.forEach( function ( it ) {
				html += '<li><span>' + esc( it.name ) + '</span><span>&times; ' + esc( it.qty ) + '</span></li>';
			} );
			html += '</ul>';
		}

		html += '<h3 class="sd-quote-sub">' + esc( i18n.detailTitle ) + '</h3>';
		html += '<div class="sd-quote-grid">';

		Object.keys( cfg.fields ).forEach( function ( name ) {
			var label = cfg.fields[ name ];
			var value = data[ name ] || '';
			var req   = ( 'billing_first_name' === name || 'billing_email' === name ) ? ' required' : '';
			var wide  = ( 'order_comments' === name || name.indexOf( 'address_1' ) > -1 ) ? ' sd-quote-wide' : '';
			var type  = 'billing_email' === name ? 'email' : ( 'billing_phone' === name ? 'tel' : 'text' );

			html += '<p class="sd-quote-field' + wide + '">';
			html += '<label for="sdq_' + name + '">' + esc( label ) + ( req ? ' <abbr title="required">*</abbr>' : '' ) + '</label>';

			if ( 'order_comments' === name ) {
				html += '<textarea id="sdq_' + name + '" name="' + name + '" rows="3">' + esc( value ) + '</textarea>';
			} else {
				html += '<input type="' + type + '" id="sdq_' + name + '" name="' + name + '" value="'
					+ esc( value ).replace( /"/g, '&quot;' ) + '"' + req + '>';
			}
			html += '</p>';
		} );

		html += '</div>';
		return html;
	}

	/** Read the popup's own form back, so any edits are what gets sent. */
	function collectForm() {
		var data = {};
		Object.keys( cfg.fields ).forEach( function ( name ) {
			var el = document.getElementById( 'sdq_' + name );
			data[ name ] = el ? $.trim( el.value || '' ) : '';
		} );
		return data;
	}

	function openModal() {
		var data  = collect();
		var items = collectItems();

		if ( ! data.billing_email || ! ( data.billing_first_name || data.billing_last_name )
			|| ! ( data.shipping_city || data.billing_city ) ) {
			window.alert( i18n.required );
			return;
		}

		$modal.find( '.sd-quote-heading' ).text( i18n.title );
		$modal.find( '.sd-quote-intro' ).text( i18n.intro );
		$modal.find( '.sd-quote-body' ).html( buildForm( data, items ) );
		$modal.find( '.sd-quote-error' ).attr( 'hidden', true ).text( '' );
		$modal.find( '.sd-quote-cancel' ).text( i18n.cancel ).show();
		$modal.find( '.sd-quote-send' ).text( i18n.send ).show().prop( 'disabled', false );

		$modal.removeAttr( 'hidden' ).addClass( 'is-open' );
		$( 'body' ).addClass( 'sd-quote-locked' );
		$modal.find( '.sd-quote-send' ).trigger( 'focus' );
	}

	function closeModal() {
		if ( sending ) {
			return;
		}
		$modal.attr( 'hidden', true ).removeClass( 'is-open' );
		$( 'body' ).removeClass( 'sd-quote-locked' );
	}

	function send() {
		if ( sending ) {
			return;
		}

		var check = collectForm();
		if ( ! check.billing_email || ! ( check.billing_first_name || check.billing_last_name )
			|| ! ( check.shipping_city || check.billing_city ) ) {
			fail( i18n.required );
			return;
		}

		sending = true;

		var $send = $modal.find( '.sd-quote-send' );
		$send.prop( 'disabled', true ).text( i18n.sending );
		$modal.find( '.sd-quote-error' ).attr( 'hidden', true );

		// Read the popup's form, so a correction made in the panel is what
		// actually gets sent rather than the original checkout value.
		var payload = collectForm();
		payload.action = 'sd_submit_quote';
		payload.nonce  = cfg.nonce;

		$.post( cfg.ajaxUrl, payload )
			.done( function ( res ) {
				if ( res && res.success ) {
					$modal.find( '.sd-quote-heading' ).text( res.data.title || i18n.sentTitle );
					$modal.find( '.sd-quote-intro' ).text( res.data.message || '' );
					$modal.find( '.sd-quote-body' ).html(
						'<div class="sd-quote-sent" role="status">'
						+ '<svg width="46" height="46" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
						+ 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
						+ '<path d="M4 4h16v16H4z"></path><path d="M4 6l8 6 8-6"></path></svg>'
						+ '</div>'
					);
					$modal.find( '.sd-quote-send' ).hide();
					$modal.find( '.sd-quote-cancel' ).text( i18n.close ).show();
					// The basket was cleared server-side, so leaving the
					// customer on a stale checkout would be confusing.
					$( document.body ).trigger( 'update_checkout' );
				} else {
					fail( ( res && res.data && res.data.message ) || i18n.failed );
				}
			} )
			.fail( function () {
				fail( i18n.failed );
			} )
			.always( function () {
				sending = false;
			} );
	}

	function fail( message ) {
		$modal.find( '.sd-quote-error' ).text( message ).removeAttr( 'hidden' );
		$modal.find( '.sd-quote-send' ).prop( 'disabled', false ).text( i18n.send );
	}

	$( function () {
		$modal = $( '#sd-quote-modal' );

		applyMode();

		// WooCommerce replaces the review-order block wholesale on every
		// address or method change, which destroys our button and unhides
		// the payment section - so reapply after each update.
		$( document.body ).on( 'updated_checkout payment_method_selected', applyMode );
		$( document ).on( 'change', 'input[name^="shipping_method"]', applyMode );

		$( document ).on( 'click', '#sd-quote-open', function ( e ) {
			e.preventDefault();
			openModal();
		} );

		$( document ).on( 'click', '[data-sd-close]', function ( e ) {
			e.preventDefault();
			closeModal();
		} );

		$( document ).on( 'click', '.sd-quote-send', function ( e ) {
			e.preventDefault();
			send();
		} );

		$( document ).on( 'keydown', function ( e ) {
			if ( 27 === e.keyCode && $modal.hasClass( 'is-open' ) ) {
				closeModal();
			}
		} );
	} );

} )( jQuery );
