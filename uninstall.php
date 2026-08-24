<?php
/**
 * Fires on plugin uninstall (Plugins screen "Delete", not deactivation).
 *
 * @package Ifthenpay\PaidMembershipsPro
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$ifthenpay_pmpro_options = array(
	'pmpro_ifthenpay_backoffice_key',
	'pmpro_ifthenpay_gateway_key',
	'pmpro_ifthenpay_methods',
	'pmpro_ifthenpay_default_method',
	'pmpro_ifthenpay_description',
	'pmpro_ifthenpay_expire_days',
);

foreach ( $ifthenpay_pmpro_options as $ifthenpay_pmpro_option ) {
	delete_option( $ifthenpay_pmpro_option );
}

delete_transient( 'pmpro_ifthenpay_gateway_keys' );
delete_transient( 'pmpro_ifthenpay_available_methods' );

// If ifthenpay was the site's active payment gateway, do not leave the site
// pointed at a now-removed gateway.
if ( 'ifthenpay' === get_option( 'pmpro_gateway' ) ) {
	delete_option( 'pmpro_gateway' );
}
