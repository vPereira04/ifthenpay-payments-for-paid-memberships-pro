<?php
/**
 * Wraps this plugin's `pmpro_ifthenpay_*` WordPress options.
 *
 * @package Ifthenpay\PaidMembershipsPro
 */

namespace Ifthenpay\PaidMembershipsPro\Settings;

defined( 'ABSPATH' ) || exit;

use Ifthenpay\PaidMembershipsPro\Api\IfthenpayClient;
use RuntimeException;

/**
 * Wraps this plugin's settings, stored as individual `pmpro_ifthenpay_*`
 * options -- one option per field, matching how Paid Memberships Pro's own
 * gateways store settings (`pmpro_gateway_email`, `pmpro_instructions`, ...)
 * via `get_option()`/`update_option()` rather than a single serialized
 * array, per `pmpro_getOption()`/`pmpro_setOption()` in
 * includes/functions.php (soft-deprecated in favor of calling
 * `get_option()`/`update_option()` directly, which is what this class does).
 *
 * @since 1.0.0
 */
final class SettingsRepository {

	const BACKOFFICE_KEY_OPTION = 'pmpro_ifthenpay_backoffice_key';
	const GATEWAY_KEY_OPTION    = 'pmpro_ifthenpay_gateway_key';
	const METHODS_OPTION        = 'pmpro_ifthenpay_methods';
	const DEFAULT_METHOD_OPTION = 'pmpro_ifthenpay_default_method';
	const DESCRIPTION_OPTION    = 'pmpro_ifthenpay_description';
	const EXPIRE_DAYS_OPTION    = 'pmpro_ifthenpay_expire_days';

	const GATEWAY_KEYS_TRANSIENT      = 'pmpro_ifthenpay_gateway_keys';
	const AVAILABLE_METHODS_TRANSIENT = 'pmpro_ifthenpay_available_methods';

	/**
	 * Prefix for the per Gateway-Key/method "activation requested" transient
	 * (see is_activation_requested()/mark_activation_requested()). Keyed
	 * dynamically per gateway_key+entity, unlike the other transients above,
	 * so it can't be a single constant name.
	 */
	const ACTIVATION_REQUESTED_TRANSIENT_PREFIX = 'pmpro_ifthenpay_activation_requested_';

	/**
	 * Per-request memoization so multiple calls within the same request
	 * (rendering the gateway-key dropdown and the methods table off the
	 * same page load) only hit the live ifthenpay API once each.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private $gateway_keys_request_cache = null;

	/**
	 * Whether $gateway_keys_request_cache was populated by a live API call
	 * this request (rather than a transient-cache hit) -- a later
	 * fetch_gateway_keys( true ) must still hit the API for real if this is
	 * false.
	 *
	 * @var bool
	 */
	private $gateway_keys_live_fetch_done = false;

	/**
	 * Per-request memoization for fetch_available_methods_catalog().
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private $methods_catalog_request_cache = null;

	/**
	 * Mirrors $gateway_keys_live_fetch_done, for fetch_available_methods_catalog().
	 *
	 * @var bool
	 */
	private $methods_catalog_live_fetch_done = false;

	/**
	 * @return string
	 */
	public function get_backoffice_key() {
		return (string) get_option( self::BACKOFFICE_KEY_OPTION, '' );
	}

	/**
	 * @return string
	 */
	public function get_gateway_key() {
		return (string) get_option( self::GATEWAY_KEY_OPTION, '' );
	}

	/**
	 * Persists the Backoffice Key. Used exclusively by the AJAX
	 * "Connect"/"Disconnect" flow (Ajax\Controller) -- the raw key is never
	 * part of the main gateway settings form and is never echoed back into
	 * any admin screen once saved (see MethodsField::render_backoffice_status()),
	 * so an admin cannot retrieve it again through the UI after connecting.
	 * It is a one-time credential issued by ifthenpay only the account
	 * owner is meant to hold.
	 *
	 * @param string $key
	 */
	public function save_backoffice_key( $key ) {
		update_option( self::BACKOFFICE_KEY_OPTION, sanitize_text_field( $key ), false );
	}

	/**
	 * Persists the Gateway Key. Called both by the AJAX "Payment Methods"
	 * flow (Ajax\Controller), so switching Gateway Key refreshes the methods
	 * table immediately, and by save_from_request() when the main gateway
	 * settings form is submitted.
	 *
	 * @param string $key
	 */
	public function save_gateway_key( $key ) {
		update_option( self::GATEWAY_KEY_OPTION, sanitize_text_field( $key ), false );
	}

	/**
	 * Full saved methods state, keyed by uppercase entity.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_methods() {
		$methods = get_option( self::METHODS_OPTION, array() );

		return is_array( $methods ) ? $methods : array();
	}

	/**
	 * Only entries the merchant has enabled/provisioned.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_active_methods() {
		return array_filter(
			$this->get_methods(),
			static function ( $method ) {
				return ! empty( $method['is_active'] );
			}
		);
	}

	/**
	 * Whether a "Request Activation" email was already sent for this
	 * Gateway Key/method within the last 24 hours -- used to disable the
	 * button client-side (see MethodsField::render_methods_table()) and to
	 * reject a duplicate send server-side (see Ajax\Controller::request_activation()).
	 *
	 * @param string $gateway_key
	 * @param string $entity
	 *
	 * @return bool
	 */
	public function is_activation_requested( $gateway_key, $entity ) {
		return (bool) get_transient( self::activation_requested_transient_key( $gateway_key, $entity ) );
	}

	/**
	 * Starts the 24-hour cooldown for a Gateway Key/method pair once its
	 * activation email has been sent.
	 *
	 * @param string $gateway_key
	 * @param string $entity
	 */
	public function mark_activation_requested( $gateway_key, $entity ) {
		set_transient( self::activation_requested_transient_key( $gateway_key, $entity ), true, DAY_IN_SECONDS );
	}

	/**
	 * The transient key is hashed (rather than a plain concatenation) so it
	 * stays within the options table's key-length limit regardless of how
	 * long the Gateway Key happens to be.
	 *
	 * @param string $gateway_key
	 * @param string $entity
	 *
	 * @return string
	 */
	private static function activation_requested_transient_key( $gateway_key, $entity ) {
		return self::ACTIVATION_REQUESTED_TRANSIENT_PREFIX . md5( $gateway_key . '|' . strtoupper( $entity ) );
	}

	/**
	 * @return string
	 */
	public function get_default_method() {
		return (string) get_option( self::DEFAULT_METHOD_OPTION, '' );
	}

	/**
	 * @return string
	 */
	public function get_description() {
		$description = (string) get_option( self::DESCRIPTION_OPTION, '' );

		return '' !== $description ? $description : __( 'Membership Payment #{id}', 'ifthenpay-payments-for-paid-memberships-pro' );
	}

	/**
	 * @return int
	 */
	public function get_expire_days() {
		$value = get_option( self::EXPIRE_DAYS_OPTION, '' );

		return '' === $value ? 3 : max( 0, (int) $value );
	}

	/**
	 * Whether a Backoffice key and a Gateway Key are both configured.
	 *
	 * @return bool
	 */
	public function is_connected() {
		return '' !== $this->get_backoffice_key() && '' !== $this->get_gateway_key();
	}

	/**
	 * Sanitizes and persists the gateway settings fields submitted on the
	 * main "Save Settings" form. The Backoffice Key is deliberately NOT
	 * among them -- it is only ever written by save_backoffice_key() via the
	 * AJAX Connect/Disconnect flow (see Ajax\Controller), never as part of
	 * this form, so the raw key never has to travel through this request at
	 * all. Pass `$_REQUEST` directly -- unslashing happens per-field inside
	 * this method, do not pre-unslash the whole array yourself. Nonce
	 * verification already happened in `adminpages/paymentsettings.php`
	 * before this is ever called.
	 *
	 * @param array<string, mixed> $request Raw, still-slashed request data (e.g. `$_REQUEST`).
	 */
	public function save_from_request( array $request ) {
		if ( isset( $request['pmpro_ifthenpay_gateway_key'] ) ) {
			$this->save_gateway_key( sanitize_text_field( wp_unslash( $request['pmpro_ifthenpay_gateway_key'] ) ) );
		}

		if ( isset( $request['pmpro_ifthenpay_methods'] ) && is_array( $request['pmpro_ifthenpay_methods'] ) ) {
			update_option( self::METHODS_OPTION, $this->sanitize_methods( wp_unslash( $request['pmpro_ifthenpay_methods'] ) ), false );
		}

		if ( isset( $request['pmpro_ifthenpay_default_method'] ) ) {
			update_option(
				self::DEFAULT_METHOD_OPTION,
				strtoupper( sanitize_text_field( wp_unslash( $request['pmpro_ifthenpay_default_method'] ) ) ),
				false
			);
		}

		if ( isset( $request['pmpro_ifthenpay_description'] ) ) {
			update_option(
				self::DESCRIPTION_OPTION,
				sanitize_text_field( wp_unslash( $request['pmpro_ifthenpay_description'] ) ),
				false
			);
		}

		if ( isset( $request['pmpro_ifthenpay_expire_days'] ) ) {
			update_option( self::EXPIRE_DAYS_OPTION, max( 0, (int) $request['pmpro_ifthenpay_expire_days'] ), false );
		}

		// Restrict the saved default method to one that is actually active,
		// mirroring the "never let the merchant default to a method they
		// haven't turned on" rule from the Default Settings Page Contract.
		$active = $this->get_active_methods();
		if ( '' !== $this->get_default_method() && ! isset( $active[ $this->get_default_method() ] ) ) {
			update_option( self::DEFAULT_METHOD_OPTION, '', false );
		}
	}

	/**
	 * Sanitizes the raw `methods[]` array submitted by the settings form.
	 *
	 * @param array<string, array<string, mixed>> $raw_methods Raw methods array from the request.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function sanitize_methods( array $raw_methods ) {
		$clean = array();

		foreach ( $raw_methods as $entity => $method ) {
			$entity = strtoupper( sanitize_text_field( (string) $entity ) );
			if ( '' === $entity || ! is_array( $method ) ) {
				continue;
			}

			$clean[ $entity ] = array(
				'label'        => sanitize_text_field( (string) ( $method['label'] ?? $entity ) ),
				'account'      => sanitize_text_field( (string) ( $method['account'] ?? '' ) ),
				'is_active'    => ! empty( $method['is_active'] ) && '' !== (string) ( $method['account'] ?? '' ),
				'position'     => (int) ( $method['position'] ?? 0 ),
				'img_url'      => esc_url_raw( (string) ( $method['img_url'] ?? '' ) ),
				'img_url_dark' => esc_url_raw( (string) ( $method['img_url_dark'] ?? '' ) ),
			);
		}

		return $clean;
	}

	/**
	 * Clears the Backoffice Key, Gateway Key and methods snapshot.
	 */
	public function disconnect() {
		delete_option( self::BACKOFFICE_KEY_OPTION );
		delete_option( self::GATEWAY_KEY_OPTION );
		delete_option( self::METHODS_OPTION );
		delete_option( self::DEFAULT_METHOD_OPTION );
		delete_transient( self::GATEWAY_KEYS_TRANSIENT );
		delete_transient( self::AVAILABLE_METHODS_TRANSIENT );
	}

	/**
	 * Fetches (and 5-minute-caches) the gateway-key rows for the currently
	 * saved Backoffice Key, scoped to the "PaidMembershipsPro" gateway type.
	 *
	 * @param bool $force Bypass the transient cache and hit the live ifthenpay API.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function fetch_gateway_keys( $force = false ) {
		if ( null !== $this->gateway_keys_request_cache && ( ! $force || $this->gateway_keys_live_fetch_done ) ) {
			return $this->gateway_keys_request_cache;
		}

		$cached = get_transient( self::GATEWAY_KEYS_TRANSIENT );
		$cached = is_array( $cached ) ? $cached : null;

		if ( ! $force && null !== $cached ) {
			$this->gateway_keys_request_cache = $cached;

			return $cached;
		}

		$backoffice_key = $this->get_backoffice_key();
		if ( '' === $backoffice_key ) {
			return array();
		}

		try {
			$rows = ( new IfthenpayClient( $backoffice_key ) )->get_gateway_keys();
		} catch ( RuntimeException $e ) {
			// Live refresh failed -- fall back to the last known-good cache
			// (if any) rather than blanking out the gateway-key dropdown.
			$this->gateway_keys_request_cache   = null !== $cached ? $cached : array();
			$this->gateway_keys_live_fetch_done = true;

			return $this->gateway_keys_request_cache;
		}

		set_transient( self::GATEWAY_KEYS_TRANSIENT, $rows, 5 * MINUTE_IN_SECONDS );
		$this->gateway_keys_request_cache   = $rows;
		$this->gateway_keys_live_fetch_done = true;

		return $rows;
	}

	/**
	 * Fetches (and 5-minute-caches) the full ifthenpay methods catalog,
	 * keyed by uppercase entity, filtered to visible methods only.
	 *
	 * @param bool $force Bypass the transient cache and hit the live ifthenpay API.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function fetch_available_methods_catalog( $force = false ) {
		if ( null !== $this->methods_catalog_request_cache && ( ! $force || $this->methods_catalog_live_fetch_done ) ) {
			return $this->methods_catalog_request_cache;
		}

		$cached = get_transient( self::AVAILABLE_METHODS_TRANSIENT );
		$cached = is_array( $cached ) ? $cached : null;

		if ( ! $force && null !== $cached ) {
			$this->methods_catalog_request_cache = $cached;

			return $cached;
		}

		$catalog = array();

		try {
			foreach ( IfthenpayClient::get_available_methods() as $row ) {
				if ( empty( $row['IsVisible'] ) ) {
					continue;
				}

				$entity = strtoupper( (string) ( $row['Entity'] ?? '' ) );
				if ( '' === $entity ) {
					continue;
				}

				$catalog[ $entity ] = array(
					'entity'       => $entity,
					'label'        => (string) ( $row['Method'] ?? $entity ),
					'position'     => (int) ( $row['Position'] ?? 0 ),
					'img_url'      => (string) ( $row['SmallImageUrl'] ?? $row['ImageUrl'] ?? '' ),
					'img_url_dark' => (string) ( $row['SmallImageUrlDark'] ?? '' ),
				);
			}
		} catch ( RuntimeException $e ) {
			$this->methods_catalog_request_cache   = null !== $cached ? $cached : array();
			$this->methods_catalog_live_fetch_done = true;

			return $this->methods_catalog_request_cache;
		}

		set_transient( self::AVAILABLE_METHODS_TRANSIENT, $catalog, 5 * MINUTE_IN_SECONDS );
		$this->methods_catalog_request_cache   = $catalog;
		$this->methods_catalog_live_fetch_done = true;

		return $catalog;
	}

	/**
	 * Joins the ifthenpay methods catalog against the selected Gateway Key
	 * row so each method carries its provisioned account (if any).
	 *
	 * @param string $gateway_key The Gateway Key to join the catalog against.
	 * @param bool   $force       Bypass the transient cache and hit the live ifthenpay API.
	 *
	 * @return array<int, array{entity:string,label:string,account:string,img_url:string,img_url_dark:string,position:int}>
	 */
	public function build_method_rows( $gateway_key, $force = false ) {
		if ( '' === $gateway_key ) {
			return array();
		}

		$row = null;
		foreach ( $this->fetch_gateway_keys( $force ) as $candidate ) {
			if ( (string) ( $candidate['GatewayKey'] ?? '' ) === $gateway_key ) {
				$row = $candidate;
				break;
			}
		}

		if ( null === $row ) {
			return array();
		}

		$rows = array();
		foreach ( $this->fetch_available_methods_catalog( $force ) as $entity => $method ) {
			$rows[] = array(
				'entity'       => $entity,
				'label'        => (string) $method['label'],
				'account'      => $this->resolve_account_in_row( $row, $entity, (string) $method['label'] ),
				'img_url'      => (string) $method['img_url'],
				'img_url_dark' => (string) $method['img_url_dark'],
				'position'     => (int) $method['position'],
			);
		}

		return $rows;
	}

	/**
	 * Builds the gateway-key => alias options for the "Gateway Key" dropdown.
	 *
	 * @param bool $force Bypass the transient cache and hit the live ifthenpay API.
	 *
	 * @return array<string, string>
	 */
	public function get_gateway_key_options( $force = false ) {
		$rows = $this->fetch_gateway_keys( $force );
		if ( empty( $rows ) ) {
			return array( '' => __( '-- No gateway keys found --', 'ifthenpay-payments-for-paid-memberships-pro' ) );
		}

		$options = array();
		foreach ( $rows as $row ) {
			$key = (string) ( $row['GatewayKey'] ?? '' );
			if ( '' === $key ) {
				continue;
			}
			$options[ $key ] = (string) ( $row['Alias'] ?? $key );
		}

		return $options;
	}

	/**
	 * Finds the provisioned account for a single catalog entity/label inside
	 * a gateway-key row, trying several casing/alias variants.
	 *
	 * @param array<string, mixed> $row    The gateway-key row returned by the ifthenpay API.
	 * @param string               $entity The catalog entity code (e.g. "MBWAY").
	 * @param string               $label  The catalog method label (e.g. "MB WAY").
	 *
	 * @return string
	 */
	private function resolve_account_in_row( array $row, $entity, $label ) {
		$candidates = array_unique(
			array_filter(
				array(
					$entity,
					strtoupper( $entity ),
					strtolower( $entity ),
					$label,
					strtoupper( $label ),
					strtolower( $label ),
				)
			)
		);

		if ( 'MB' === strtoupper( $entity ) || 'MULTIBANCO' === strtoupper( $label ) ) {
			$candidates[] = 'Multibanco';
			$candidates[] = 'MULTIBANCO';
			$candidates[] = 'MB';
		}

		foreach ( $candidates as $key ) {
			if ( '' === $key || ! array_key_exists( $key, $row ) ) {
				continue;
			}
			$value = sanitize_text_field( (string) $row[ $key ] );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}
}
