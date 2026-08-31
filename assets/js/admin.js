/**
 * Makes the ifthenpay settings screen reactive: connecting/disconnecting the
 * Backoffice Key, switching the Gateway Key, (un)checking a payment method,
 * and starring a method as the default all update the page immediately,
 * with no Save-and-reload round trip -- see src/Ajax/Controller.php for the
 * endpoints this talks to and src/Admin/MethodsField.php for the markup it
 * replaces.
 */
( function ( $ ) {
	'use strict';

	var cfg = window.ifthenpayPmproAdmin || {};

	function apiPost( action, data ) {
		return $.post(
			cfg.ajax_url,
			$.extend( { action: action, nonce: cfg.nonce }, data || {} ),
			null,
			'json'
		);
	}

	function toggleConnectedRows( connected ) {
		$( '.iftp-pmpro-connected-row' ).css( 'display', connected ? '' : 'none' );
		$( '.iftp-pmpro-disconnected-row' ).css( 'display', connected ? 'none' : '' );
	}

	function reportError( $container, message ) {
		var $warning = $container.find( '.iftp-pmpro-runtime-warning' );

		if ( ! $warning.length ) {
			$warning = $( '<p class="iftp-pmpro-runtime-warning"></p>' ).appendTo( $container );
		}

		$warning.text( message || '' );
	}

	function applyMethodsSection( section ) {
		section = section || {};
		$( '#iftp-pmpro-methods-table-wrap' ).html( section.table_html || '' );
	}

	// Swaps a star's icon between filled/empty -- kept as its own function so
	// both the click handler and the "method got disabled" cleanup below stay
	// in sync on what a checked/unchecked star actually looks like.
	function setStarIcon( $star, isDefault ) {
		$star.next( '.iftp-pmpro-star-label' )
			.find( '.dashicons' )
			.toggleClass( 'dashicons-star-filled', isDefault )
			.toggleClass( 'dashicons-star-empty', ! isDefault );
	}

	// A quick, playful "wink" whenever a star is toggled -- purely cosmetic,
	// re-triggered via a class add/remove (a CSS-only :checked animation
	// can't replay on repeated clicks of the same state).
	function winkStar( $star ) {
		var $label = $star.next( '.iftp-pmpro-star-label' );

		$label.removeClass( 'iftp-pmpro-star-label--wink' );
		// Force reflow so re-adding the class restarts the animation even if
		// it's already present from a very quick repeated click.
		void $label[ 0 ].offsetWidth;
		$label.addClass( 'iftp-pmpro-star-label--wink' );
	}

	$( document ).on( 'click', '[data-iftp-pmpro-connect-button]', function ( e ) {
		e.preventDefault();

		var $button         = $( this );
		var $status         = $( '#iftp-pmpro-backoffice-status' );
		var backofficeKey   = String( $status.find( '[data-iftp-pmpro-backoffice-key-input]' ).val() || '' ).trim();
		var originalLabel   = $button.text();

		if ( ! backofficeKey ) {
			return;
		}

		$button.prop( 'disabled', true ).text( ( cfg.i18n && cfg.i18n.connecting ) || originalLabel );

		apiPost( 'ifthenpay_pmpro_connect_backoffice', { backoffice_key: backofficeKey } )
			.done( function ( response ) {
				var data = ( response && response.data ) || {};

				if ( ! response || ! response.success ) {
					reportError( $status, data.message || ( cfg.i18n && cfg.i18n.invalidKey ) );
					$button.prop( 'disabled', false ).text( originalLabel );

					return;
				}

				$status.html( data.backoffice_status_html || '' );
				$( '#pmpro_ifthenpay_gateway_key' ).html( data.gateway_key_options_html || '' ).val( data.gateway_key || '' );
				applyMethodsSection( data.methods_section );
				toggleConnectedRows( true );
			} )
			.fail( function () {
				reportError( $status, ( cfg.i18n && cfg.i18n.invalidKey ) || '' );
				$button.prop( 'disabled', false ).text( originalLabel );
			} );
	} );

	$( document ).on( 'click', '[data-iftp-pmpro-disconnect-button]', function ( e ) {
		e.preventDefault();

		if ( ! window.confirm( ( cfg.i18n && cfg.i18n.confirmDisconnect ) || '' ) ) {
			return;
		}

		var $button = $( this );
		var $status = $( '#iftp-pmpro-backoffice-status' );

		$button.prop( 'disabled', true );

		apiPost( 'ifthenpay_pmpro_disconnect_backoffice', {} )
			.done( function ( response ) {
				var data = ( response && response.data ) || {};

				if ( ! response || ! response.success ) {
					return;
				}

				$status.html( data.backoffice_status_html || '' );
				$( '#pmpro_ifthenpay_gateway_key' ).html( data.gateway_key_options_html || '' );
				applyMethodsSection( data.methods_section );
				toggleConnectedRows( false );
			} )
			.always( function () {
				$button.prop( 'disabled', false );
			} );
	} );

	$( document ).on( 'change', '[data-iftp-pmpro-gateway-key-select]', function () {
		var $select = $( this );

		$select.prop( 'disabled', true );

		apiPost( 'ifthenpay_pmpro_select_gateway_key', { gateway_key: String( $select.val() || '' ) } )
			.done( function ( response ) {
				if ( response && response.success ) {
					applyMethodsSection( response.data );
				}
			} )
			.always( function () {
				$select.prop( 'disabled', false );
			} );
	} );

	// Starring a method: the stars are a native radio group (shared `name`),
	// so the browser already enforces a single default and, unlike a
	// checkbox, a click on the already-selected star is a no-op -- there is
	// no way to click a star back to "no default", the merchant always has
	// to pick a different one. This just resyncs every star's icon to its
	// current checked state and plays the wink on the one just clicked.
	$( document ).on( 'change', '#iftp-pmpro-methods-table-wrap [data-iftp-pmpro-star]', function () {
		$( '#iftp-pmpro-methods-table-wrap [data-iftp-pmpro-star]' ).each( function () {
			setStarIcon( $( this ), $( this ).prop( 'checked' ) );
		} );

		winkStar( $( this ) );
	} );

	// A method that just got disabled can no longer be the default: hide and
	// disable its star live, and clear the default if it was the one set.
	$( document ).on( 'change', '#iftp-pmpro-methods-table-wrap input[data-iftp-pmpro-entity]', function () {
		var enabled = $( this ).prop( 'checked' );
		var $star   = $( this ).closest( 'tr' ).find( '[data-iftp-pmpro-star]' );

		if ( ! $star.length ) {
			return;
		}

		$star.prop( 'disabled', ! enabled );
		$star.next( '.iftp-pmpro-star-label' ).toggleClass( 'iftp-pmpro-star-label--hidden', ! enabled );

		if ( ! enabled && $star.prop( 'checked' ) ) {
			$star.prop( 'checked', false );
			setStarIcon( $star, false );
		}
	} );

	$( document ).on( 'click', '[data-iftp-pmpro-request-activation]', function () {
		var $button       = $( this );
		var entity        = String( $button.data( 'entity' ) || '' );
		var originalLabel = $button.text();

		$button.prop( 'disabled', true ).text( ( cfg.i18n && cfg.i18n.requestingActivation ) || originalLabel );

		apiPost( 'ifthenpay_pmpro_request_activation', { entity: entity } )
			.done( function ( response ) {
				if ( response && response.success ) {
					// Stays disabled for the 24-hour cooldown Ajax\Controller
					// just started server-side (Settings\SettingsRepository's
					// transient) -- only this method's button, every other
					// still-unprovisioned method's button is untouched.
					$button.prop( 'disabled', true ).text( ( cfg.i18n && cfg.i18n.activationRequested ) || originalLabel );
					window.alert( ( cfg.i18n && cfg.i18n.activationRequestSent ) || '' );

					return;
				}

				var data = ( response && response.data ) || {};
				window.alert( data.message || ( cfg.i18n && cfg.i18n.activationRequestFailed ) || '' );
				$button.prop( 'disabled', false ).text( originalLabel );
			} )
			.fail( function () {
				window.alert( ( cfg.i18n && cfg.i18n.activationRequestFailed ) || '' );
				$button.prop( 'disabled', false ).text( originalLabel );
			} );
	} );
} )( jQuery );
