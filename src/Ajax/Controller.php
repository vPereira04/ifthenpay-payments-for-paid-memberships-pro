<?php
/**
 * Admin-only AJAX endpoints backing the reactive ifthenpay settings screen:
 * connect/disconnect the Backoffice Key and switch the Gateway Key without
 * the merchant ever needing to click PMPro's main "Save Settings" button
 * and reload the page just to see the payment-methods table -- or the
 * Default Payment Method options -- update.
 *
 * @package Ifthenpay\PaidMembershipsPro
 */

namespace Ifthenpay\PaidMembershipsPro\Ajax;

defined( 'ABSPATH' ) || exit;

use Ifthenpay\PaidMembershipsPro\Admin\MethodsField;
use Ifthenpay\PaidMembershipsPro\Api\IfthenpayClient;
use Ifthenpay\PaidMembershipsPro\Settings\SettingsRepository;
use RuntimeException;

/**
 * Registers and handles the `ifthenpay_pmpro_*` admin-ajax actions.
 *
 * @since 1.0.0
 */
final class Controller {

	/**
	 * The `check_ajax_referer()` action name shared by every endpoint below
	 * and by the nonce localized into assets/js/admin.js.
	 */
	const NONCE_ACTION = 'ifthenpay_pmpro_admin';

	/**
	 * Registers every wp_ajax_ handler. Admin-only (no `_nopriv` counterpart)
	 * since these all require `manage_options`, unlike the payment webhook
	 * in PMProGateway_ifthenpay, which must stay reachable by ifthenpay's
	 * servers with no WP session.
	 *
	 * @since 1.0.0
	 */
	public function hooks() {
		add_action( 'wp_ajax_ifthenpay_pmpro_connect_backoffice', array( $this, 'connect_backoffice' ) );
		add_action( 'wp_ajax_ifthenpay_pmpro_disconnect_backoffice', array( $this, 'disconnect_backoffice' ) );
		add_action( 'wp_ajax_ifthenpay_pmpro_select_gateway_key', array( $this, 'select_gateway_key' ) );
	}

	/**
	 * Validates a Backoffice Key against the live ifthenpay API and, only if
	 * valid, persists it. The key itself is never echoed back in the JSON
	 * response -- only the resulting "connected" markup is.
	 *
	 * @since 1.0.0
	 */
	public function connect_backoffice() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage_options();

		$backoffice_key = isset( $_POST['backoffice_key'] ) ? sanitize_text_field( wp_unslash( $_POST['backoffice_key'] ) ) : '';
		if ( '' === $backoffice_key ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a Backoffice Key.', 'ifthenpay-payments-for-paid-memberships-pro' ) ) );
		}

		try {
			$gateway_rows = ( new IfthenpayClient( $backoffice_key ) )->get_gateway_keys();
		} catch ( RuntimeException $e ) {
			wp_send_json_error( array( 'message' => __( 'This Backoffice Key could not be validated. Please check it and try again.', 'ifthenpay-payments-for-paid-memberships-pro' ) ) );

			return;
		}

		$settings = new SettingsRepository();
		$settings->save_backoffice_key( $backoffice_key );
		set_transient( SettingsRepository::GATEWAY_KEYS_TRANSIENT, $gateway_rows, 5 * MINUTE_IN_SECONDS );

		// Auto-select the merchant's only Gateway Key, or keep an
		// already-saved one if it is still present in the fresh list;
		// otherwise leave the choice to them.
		$gateway_key_options = $settings->get_gateway_key_options();
		$gateway_key         = $settings->get_gateway_key();
		if ( ! isset( $gateway_key_options[ $gateway_key ] ) ) {
			$keys        = array_keys( $gateway_key_options );
			$gateway_key = 1 === count( $keys ) ? (string) $keys[0] : '';
		}
		$settings->save_gateway_key( $gateway_key );

		$methods_field = new MethodsField( $settings );

		wp_send_json_success(
			array(
				'backoffice_status_html'   => $methods_field->render_backoffice_status(),
				'gateway_key_options_html' => $methods_field->render_gateway_key_options( $gateway_key_options, $gateway_key ),
				'gateway_key'              => $gateway_key,
				'methods_section'          => $methods_field->render_methods_section( $gateway_key, true ),
			)
		);
	}

	/**
	 * Clears the Backoffice Key, Gateway Key and methods snapshot. The
	 * confirmation prompt happens client-side (assets/js/admin.js) --
	 * disconnecting here is unconditional once requested.
	 *
	 * @since 1.0.0
	 */
	public function disconnect_backoffice() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage_options();

		$settings = new SettingsRepository();
		$settings->disconnect();

		$methods_field = new MethodsField( $settings );

		wp_send_json_success(
			array(
				'backoffice_status_html'   => $methods_field->render_backoffice_status(),
				'gateway_key_options_html' => $methods_field->render_gateway_key_options( array(), '' ),
				'methods_section'          => $methods_field->render_methods_section( '', false ),
			)
		);
	}

	/**
	 * Persists the selected Gateway Key and returns a freshly rendered
	 * payment-methods table and Default Payment Method options for it.
	 *
	 * @since 1.0.0
	 */
	public function select_gateway_key() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage_options();

		$settings = new SettingsRepository();
		if ( '' === $settings->get_backoffice_key() ) {
			wp_send_json_error( array( 'message' => __( 'Connect a Backoffice Key first.', 'ifthenpay-payments-for-paid-memberships-pro' ) ) );

			return;
		}

		$gateway_key = isset( $_POST['gateway_key'] ) ? sanitize_text_field( wp_unslash( $_POST['gateway_key'] ) ) : '';
		$settings->save_gateway_key( $gateway_key );

		wp_send_json_success( ( new MethodsField( $settings ) )->render_methods_section( $gateway_key, true ) );
	}

	/**
	 * @since 1.0.0
	 */
	private function require_manage_options() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'ifthenpay-payments-for-paid-memberships-pro' ) ), 403 );
		}
	}
}
