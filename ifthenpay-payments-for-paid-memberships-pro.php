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

define( 'IFTP_PMPRO_VERSION', '1.0.0' );
define( 'IFTP_PMPRO_FILE', __FILE__ );
define( 'IFTP_PMPRO_DIR', plugin_dir_path( __FILE__ ) );
define( 'IFTP_PMPRO_URL', plugin_dir_url( __FILE__ ) );

// PSR-4 autoloader: Ifthenpay\PaidMembershipsPro\ => src/.
// A hand-rolled autoloader (rather than requiring vendor/autoload.php) is
// used deliberately so the plugin works from a plain zip/SVN checkout with
// no composer install step, matching ifthenpay-payments-for-givewp's
// bootstrap for the same reason.
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
 * Hooked at priority 20 (PMPro core registers its own `PMProGateway` base
 * class from its main file at the default `plugins_loaded`-adjacent load
 * time -- i.e. simply by being included -- so by the time any
 * `plugins_loaded` callback runs, PMPro is already fully loaded regardless
 * of plugin activation order; priority 20 plus the `Requires Plugins`
 * header above are both belt-and-suspenders here, not strictly required).
 *
 * @since 1.0.0
 */
function ifthenpay_pmpro_boot() {
	if ( ! class_exists( 'PMProGateway' ) ) {
		// Paid Memberships Pro is not active. Nothing to register.
		return;
	}

	require_once IFTP_PMPRO_DIR . 'classes/class-pmprogateway-ifthenpay.php';

	// admin-ajax.php requests are is_admin() === true, so this also covers
	// the Ajax\Controller endpoints themselves, not just the settings screen
	// that calls them.
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
				'connecting'        => __( 'Connecting…', 'ifthenpay-payments-for-paid-memberships-pro' ),
				'invalidKey'        => __( 'This Backoffice Key could not be validated. Please check it and try again.', 'ifthenpay-payments-for-paid-memberships-pro' ),
				'confirmDisconnect' => __( 'Disconnect this ifthenpay Backoffice Key?', 'ifthenpay-payments-for-paid-memberships-pro' ),
				'noDefault'         => __( '-- No default, let the member choose --', 'ifthenpay-payments-for-paid-memberships-pro' ),
			),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'ifthenpay_pmpro_admin_assets' );
