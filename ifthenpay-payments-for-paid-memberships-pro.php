<?php
/**
 * Plugin Name:       ifthenpay | Payments for Paid Memberships Pro
 * Plugin URI:        https://ifthenpay.com
 * Description:       Adds ifthenpay as a payment gateway for Paid Memberships Pro: Multibanco, MB WAY, Payshop, Credit Card, Apple Pay, Google Pay and more via Pay by Link.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  paid-memberships-pro
 * Author:            ifthenpay
 * Author URI:        https://ifthenpay.com/
 * License:           GPL v3
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       ifthenpay-payments-for-paid-memberships-pro
 * Domain Path:       /languages
 *
 * @package Ifthenpay\PaidMembershipsPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'IFTP_PMPRO_VERSION', '1.0.1' );
define( 'IFTP_PMPRO_FILE', __FILE__ );
define( 'IFTP_PMPRO_DIR', plugin_dir_path( __FILE__ ) );
define( 'IFTP_PMPRO_URL', plugin_dir_url( __FILE__ ) );

// Hand-rolled PSR-4 autoloader (Ifthenpay\PaidMembershipsPro\ => src/) so the
// plugin works from a plain zip/SVN checkout with no composer install step.
spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'Ifthenpay\\PaidMembershipsPro\\';
		if ( strpos( $class_name, $prefix ) !== 0 ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = IFTP_PMPRO_DIR . 'src/' . str_replace( '\\', DIRECTORY_SEPARATOR, $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

/**
 * Boots the plugin once Paid Memberships Pro core is confirmed active.
 *
 * Priority 20 is belt-and-suspenders: PMPro core's `PMProGateway` base class
 * is already loaded by the time any `plugins_loaded` callback runs.
 *
 * @since 1.0.0
 */
function ifthenpay_pmpro_boot() {
	if ( ! class_exists( 'PMProGateway' ) ) {
		return;
	}

	require_once IFTP_PMPRO_DIR . 'classes/class-pmprogateway-ifthenpay.php';

	// admin-ajax.php requests are is_admin() === true too, so this also
	// covers the Ajax\Controller endpoints themselves.
	if ( is_admin() ) {
		( new \Ifthenpay\PaidMembershipsPro\Ajax\Controller() )->hooks();
	}
}
add_action( 'plugins_loaded', 'ifthenpay_pmpro_boot', 20 );

/**
 * Enqueues the small additive admin stylesheet and the settings screen's
 * reactivity script only on PMPro's own Payment Settings screen
 * (`page=pmpro-paymentsettings`), never site-wide.
 *
 * @since 1.0.0
 */
function ifthenpay_pmpro_admin_assets() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page-slug check, no state change.
	if ( ! isset( $_GET['page'] ) || 'pmpro-paymentsettings' !== sanitize_text_field( wp_unslash( $_GET['page'] ) ) ) {
		return;
	}

	wp_enqueue_style( 'ifthenpay-pmpro-admin', IFTP_PMPRO_URL . 'assets/css/admin.css', array(), IFTP_PMPRO_VERSION );

	wp_enqueue_script( 'ifthenpay-pmpro-admin', IFTP_PMPRO_URL . 'assets/js/admin.js', array( 'jquery' ), IFTP_PMPRO_VERSION, true );
	wp_localize_script(
		'ifthenpay-pmpro-admin',
		'ifthenpayPmproAdmin',
		array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( \Ifthenpay\PaidMembershipsPro\Ajax\Controller::NONCE_ACTION ),
			'i18n'     => array(
				'connecting'              => __( 'Connecting…', 'ifthenpay-payments-for-paid-memberships-pro' ),
				'invalidKey'              => __( 'This Backoffice Key could not be validated. Please check it and try again.', 'ifthenpay-payments-for-paid-memberships-pro' ),
				'confirmDisconnect'       => __( 'Disconnect this ifthenpay Backoffice Key?', 'ifthenpay-payments-for-paid-memberships-pro' ),
				'requestingActivation'    => __( 'Requesting…', 'ifthenpay-payments-for-paid-memberships-pro' ),
				'activationRequested'     => __( 'Requested', 'ifthenpay-payments-for-paid-memberships-pro' ),
				'activationRequestSent'   => __( 'Activation request sent. You can request this method again in 24 hours.', 'ifthenpay-payments-for-paid-memberships-pro' ),
				'activationRequestFailed' => __( 'Could not send the activation request. Please try again.', 'ifthenpay-payments-for-paid-memberships-pro' ),
			),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'ifthenpay_pmpro_admin_assets' );

/**
 * Enqueues the small additive checkout stylesheet only on PMPro's checkout
 * page, never site-wide.
 *
 * @since 1.0.0
 */
function ifthenpay_pmpro_checkout_assets() {
	if ( ! function_exists( 'pmpro_is_checkout' ) || ! pmpro_is_checkout() ) {
		return;
	}

	wp_enqueue_style( 'ifthenpay-pmpro-checkout', IFTP_PMPRO_URL . 'assets/css/checkout.css', array(), IFTP_PMPRO_VERSION );
}
add_action( 'wp_enqueue_scripts', 'ifthenpay_pmpro_checkout_assets' );
