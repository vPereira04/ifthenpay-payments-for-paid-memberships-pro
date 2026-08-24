<?php
/**
 * Thin HTTP client for the ifthenpay API.
 *
 * @package Ifthenpay\PaidMembershipsPro
 */

namespace Ifthenpay\PaidMembershipsPro\Api;

defined( 'ABSPATH' ) || exit;

use RuntimeException;

/**
 * Thin HTTP client for the ifthenpay API.
 *
 * Ported from the GiveWP/GravityForms integrations' clients (same endpoints,
 * same field casing) — kept close to those proven implementations rather
 * than re-derived, to avoid reintroducing bugs already fixed there.
 *
 * NOTE: the `GATEWAY_TYPE` value below ("PaidMembershipsPro") and the
 * webhook activation `cms` query value ("paidmembershipspro") are this
 * plugin's best-guess following the pattern of the other integrations
 * (`GravityForms`, `GiveWP`, ...). They must be confirmed against a real
 * ifthenpay Backoffice account and, if needed, with ifthenpay support
 * before going live — see the project blueprint's Implementation Notes.
 *
 * @since 1.0.0
 */
final class IfthenpayClient {

	const API_BASE = 'https://api.ifthenpay.com';

	const GATEWAY_TYPE = 'PaidMembershipsPro';

	/**
	 * The Backoffice key this client instance is scoped to.
	 *
	 * @var string
	 */
	private $backoffice_key;

	/**
	 * Constructor.
	 *
	 * @param string $backoffice_key The Backoffice key to scope this client to.
	 */
	public function __construct( $backoffice_key ) {
		$this->backoffice_key = sanitize_text_field( $backoffice_key );
	}

	/**
	 * Returns the gateway-key rows for this backoffice key, scoped to the
	 * "PaidMembershipsPro" gateway type.
	 *
	 * @param string $type The gateway type to scope the results to.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_gateway_keys( $type = self::GATEWAY_TYPE ) {
		$args = array( 'boKey' => $this->backoffice_key );

		$type = sanitize_text_field( $type );
		if ( '' !== $type ) {
			$args['type'] = $type;
		}

		$rows = self::request( 'GET', add_query_arg( $args, self::API_BASE . '/gateway/get' ) );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Returns the list of all payment methods supported by ifthenpay.
	 * The caller is responsible for filtering by IsVisible.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_available_methods() {
		$rows = self::request( 'GET', self::API_BASE . '/gateway/methods/available' );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * POSTs the Pay-By-Link payload and returns the gateway response
	 * (RedirectUrl, PinCode, ...).
	 *
	 * @param string               $gateway_key The gateway key to charge against.
	 * @param array<string, mixed> $payload     The Pay-By-Link payload body.
	 *
	 * @return array<string, mixed>
	 */
	public static function create_payment_link( $gateway_key, array $payload ) {
		$url = rtrim( self::API_BASE, '/' ) . '/gateway/pinpay/' . rawurlencode( $gateway_key );

		return self::request(
			'POST',
			$url,
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $payload ),
			)
		);
	}

	/**
	 * Registers the server-to-server webhook URL for a gateway key.
	 *
	 * Returns true when the API responds with "OK", false otherwise.
	 *
	 * @param string $gateway_key      The gateway key to activate the callback for.
	 * @param string $webhook_base_url The server-to-server webhook URL.
	 *
	 * @return bool
	 */
	public static function activate_callback( $gateway_key, $webhook_base_url ) {
		$url = self::API_BASE . '/endpoint/callback/activation/?cms=paidmembershipspro';

		$payload = array(
			'apKey' => base64_encode( $gateway_key ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required by ifthenpay anti-phishing specification, validated in PMProGateway_ifthenpay::webhook().
			'chave' => $gateway_key,
			'urlCb' => $webhook_base_url
				. ( false === strpos( $webhook_base_url, '?' ) ? '?' : '&' )
				. 'ref=[ORDER_ID]&apk=[ANTI_PHISHING_KEY]&val=[AMOUNT]&mtd=[PAYMENT_METHOD]&req=[REQUEST_ID]',
		);

		try {
			$res = self::request(
				'POST',
				$url,
				array(
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => wp_json_encode( $payload ),
				)
			);

			return 'OK' === (string) ( $res['data'] ?? '' );
		} catch ( RuntimeException $e ) {
			return false;
		}
	}

	/**
	 * Performs the HTTP request and decodes the JSON response body.
	 *
	 * @param string               $method  HTTP method, "GET" or "POST".
	 * @param string               $url     Fully-qualified request URL.
	 * @param array<string, mixed> $args    Extra `wp_remote_*()` args (headers, body, ...).
	 * @param int                  $timeout Request timeout in seconds.
	 *
	 * @return array<string, mixed>
	 * @throws RuntimeException When the HTTP transport fails or the API returns a non-2xx status.
	 */
	private static function request( $method, $url, array $args = array(), $timeout = 20 ) {
		$args = wp_parse_args(
			$args,
			array(
				'timeout'   => $timeout,
				'sslverify' => true,
			)
		);

		$response = 'POST' === strtoupper( $method )
			? wp_remote_post( $url, $args )
			: wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( esc_html( $response->get_error_message() ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 ) {
			throw new RuntimeException(
				sprintf( 'Ifthenpay API error (%s): %s', esc_html( (string) $code ), esc_html( mb_substr( $body, 0, 300 ) ) )
			);
		}

		return self::decode( $body );
	}

	/**
	 * Decodes the ifthenpay JSON envelope, unwrapping the legacy `{"d": ...}`
	 * shape when present.
	 *
	 * @param string $body Raw HTTP response body.
	 *
	 * @return array<string, mixed>
	 * @throws RuntimeException When the body is not valid JSON.
	 */
	private static function decode( $body ) {
		$data = json_decode( $body, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			throw new RuntimeException( 'Invalid JSON response from Ifthenpay API.' );
		}

		if ( isset( $data['d'] ) ) {
			$data = is_string( $data['d'] ) ? json_decode( $data['d'], true ) : $data['d'];
		}

		if ( ! is_array( $data ) ) {
			return array( 'data' => $data );
		}

		return $data;
	}

	/**
	 * This class is a static-only helper and must never be instantiated
	 * without a backoffice key -- constructor is public only for the
	 * instance methods that need it (get_gateway_keys()).
	 */
}
