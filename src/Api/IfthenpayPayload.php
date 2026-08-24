<?php
/**
 * Builds the Pay-By-Link request payload for the ifthenpay API.
 *
 * @package Ifthenpay\PaidMembershipsPro
 */

namespace Ifthenpay\PaidMembershipsPro\Api;

defined( 'ABSPATH' ) || exit;

use InvalidArgumentException;

/**
 * Builds the Pay-By-Link request payload and the accounts string.
 *
 * Mirrors `Ifthenpay\GiveWP\Api\IfthenpayPayload` / `Ifthenpay\GravityForms`'s
 * inline payload building -- same field shapes, same accounts-string
 * strategy (iterate active methods, "ENTITY|ACCOUNT" joined with ";").
 *
 * @since 1.0.0
 */
final class IfthenpayPayload {

	/**
	 * Builds the full Pay-By-Link JSON payload for a single membership order.
	 *
	 * @param string                                                            $order_code     The MemberOrder::$code used as the ifthenpay "id".
	 * @param string                                                            $amount         The amount, formatted "0.00".
	 * @param string                                                            $description    Description template; "{id}" is replaced with $order_code.
	 * @param array<string, array<string, mixed>>                               $methods        Methods keyed by uppercase entity, as stored by SettingsRepository.
	 * @param string                                                            $default_method The default entity to pre-select.
	 * @param int                                                               $expire_days    Days until the link expires; 0 disables expiry.
	 * @param array{success_url: string, error_url: string, cancel_url: string} $urls           Return URLs.
	 *
	 * @return array<string, mixed>
	 * @throws InvalidArgumentException When required data is missing or no methods are active.
	 */
	public static function build_pay_by_link_payload(
		$order_code,
		$amount,
		$description,
		array $methods,
		$default_method,
		$expire_days,
		array $urls
	) {
		if ( '' === $order_code || '' === $amount || empty( $methods ) ) {
			throw new InvalidArgumentException( 'Missing required payload data: order code, amount or payment methods.' );
		}

		$built = self::build_accounts_string( $methods, strtoupper( (string) $default_method ) );

		if ( '' === $built['accounts'] ) {
			throw new InvalidArgumentException( 'No active ifthenpay payment methods are configured.' );
		}

		return array(
			'id'              => $order_code,
			'amount'          => $amount,
			'description'     => self::build_description( $order_code, $description ),
			'lang'            => self::map_locale_to_lang( get_locale() ),
			'expiredate'      => self::default_expiredate( (int) $expire_days ),
			'accounts'        => $built['accounts'],
			'success_url'     => $urls['success_url'],
			'error_url'       => $urls['error_url'],
			'cancel_url'      => $urls['cancel_url'],
			'selected_method' => $built['selected_method'],
			'otp'             => 'true',
		);
	}

	/**
	 * Pay-By-Link expiry, formatted as YYYYMMDD. Empty string disables it (no expiry).
	 *
	 * @param int $days_from_now Number of days from now until expiry; 0 disables expiry.
	 *
	 * @return string
	 */
	public static function default_expiredate( $days_from_now ) {
		if ( $days_from_now <= 0 ) {
			return '';
		}

		return gmdate( 'Ymd', time() + ( $days_from_now * DAY_IN_SECONDS ) );
	}

	/**
	 * Maps a WordPress locale to one of the ifthenpay-supported languages.
	 *
	 * @param string $locale A WordPress locale string, e.g. "pt_PT".
	 *
	 * @return string
	 */
	public static function map_locale_to_lang( $locale ) {
		$prefix = substr( strtolower( (string) $locale ), 0, 2 );

		return in_array( $prefix, array( 'pt', 'es', 'fr' ), true ) ? $prefix : 'en';
	}

	/**
	 * Fills the "{id}" placeholder in the description, falling back to a
	 * default when the merchant left it blank.
	 *
	 * @param string $order_code  The MemberOrder::$code used as the ifthenpay "id".
	 * @param string $description Description template; "{id}" is replaced with $order_code.
	 *
	 * @return string
	 */
	private static function build_description( $order_code, $description ) {
		$description = str_replace( '{id}', $order_code, (string) $description );

		return '' !== trim( $description ) ? $description : sprintf( 'Membership Payment #%s', $order_code );
	}

	/**
	 * Builds the semicolon-separated "accounts" string ifthenpay expects, and
	 * resolves which position (if any) should be pre-selected.
	 *
	 * @param array<string, array<string, mixed>> $methods        Methods keyed by uppercase entity.
	 * @param string                              $default_method The default entity to pre-select.
	 *
	 * @return array{accounts: string, selected_method: string}
	 */
	private static function build_accounts_string( array $methods, $default_method ) {
		$parts             = array();
		$selected_position = 0;

		foreach ( $methods as $entity => $method ) {
			if ( empty( $method['is_active'] ) ) {
				continue;
			}

			$entity   = strtoupper( (string) $entity );
			$account  = trim( (string) ( $method['account'] ?? '' ) );
			$position = abs( (int) ( $method['position'] ?? 0 ) );

			if ( '' === $entity || '' === $account ) {
				continue;
			}

			$parts[] = preg_replace( '/\s*\|\s*/', '|', $account );

			if ( $entity === $default_method && $position > 0 ) {
				$selected_position = $position;
			}
		}

		return array(
			'accounts'        => implode( ';', $parts ),
			'selected_method' => $selected_position > 0 ? (string) $selected_position : '',
		);
	}

	/**
	 * This class is a static-only helper and must never be instantiated.
	 */
	private function __construct() {}
}
