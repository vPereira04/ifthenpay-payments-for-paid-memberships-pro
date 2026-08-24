/**
 * Makes the ifthenpay settings screen reactive: connecting/disconnecting the
 * Backoffice Key, switching the Gateway Key, and (un)checking a payment
 * method all update the page immediately, with no Save-and-reload round
 * trip -- see src/Ajax/Controller.php for the endpoints this talks to and
 * src/Admin/MethodsField.php for the markup it replaces.
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
		$( '#pmpro_ifthenpay_default_method' ).html( section.default_html || '' );
	}

	// Rebuilds the Default Payment Method <select> from whichever method
	// checkboxes are currently checked and enabled -- purely client-side,
	// so toggling a checkbox updates the dropdown instantly instead of
	// requiring a Save + page refresh to see it change.
	function rebuildDefaultMethodOptions() {
		var $select = $( '#pmpro_ifthenpay_default_method' );
		var current = $select.val();
		var options = '<option value="">' + ( ( cfg.i18n && cfg.i18n.noDefault ) || '' ) + '</option>';

		$( '#iftp-pmpro-methods-table-wrap input[data-iftp-pmpro-entity]:checked:not(:disabled)' ).each( function () {
			var $checkbox = $( this );
			var entity    = String( $checkbox.data( 'iftpPmproEntity' ) || '' );
			var label     = String( $checkbox.data( 'iftpPmproLabel' ) || entity );

			options += '<option value="' + entity + '"' + ( entity === current ? ' selected' : '' ) + '>' + label + '</option>';
		} );

		$select.html( options );
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

	// Client-side only -- see rebuildDefaultMethodOptions() above.
	$( document ).on( 'change', '#iftp-pmpro-methods-table-wrap input[data-iftp-pmpro-entity]', rebuildDefaultMethodOptions );
} )( jQuery );
