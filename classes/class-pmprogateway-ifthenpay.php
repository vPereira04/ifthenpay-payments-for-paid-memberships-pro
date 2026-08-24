<?php
/**
 * The ifthenpay Pay-By-Link gateway bridge class for Paid Memberships Pro.
 *
 * IMPORTANT: this class is deliberately global (no namespace) and named
 * exactly `PMProGateway_ifthenpay`, extending the equally global
 * `PMProGateway` base class defined by PMPro core
 * (classes/gateways/class.pmprogateway.php). This is not a stylistic
 * choice: `MemberOrder::setGateway()` in PMPro core resolves the gateway
 * object with `class_exists( 'PMProGateway_' . $this->gateway )` followed
 * by `new $classname( $this->gateway )` -- a plain `class_exists()` check
 * against a global class name, never a namespaced one. A namespaced class
 * here would never be found and checkout would silently fall back to no
 * gateway. See the project blueprint (`.claude/projects/paid-memberships-pro-ifthenpay.md`)
 * section "3a/3d" for the full trace of this behavior through PMPro core.
 *
 * All other classes in this plugin (API client, settings repository,
 * admin field rendering) are namespaced under `Ifthenpay\PaidMembershipsPro\`
 * and PSR-4 autoloaded; only this bridge class, and the base class it
 * extends, live outside that namespace -- matching exactly how PMPro's own
 * bundled gateways (`PMProGateway_check`, `PMProGateway_paypalstandard`,
 * ...) are structured.
 *
 * @package Ifthenpay\PaidMembershipsPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Ifthenpay\PaidMembershipsPro\Admin\MethodsField;
use Ifthenpay\PaidMembershipsPro\Api\IfthenpayClient;
use Ifthenpay\PaidMembershipsPro\Api\IfthenpayPayload;
use Ifthenpay\PaidMembershipsPro\Settings\SettingsRepository;

// Load classes init method -- this file is only require_once'd (see the main
// plugin bootstrap) once PMPro core's own PMProGateway base class is
// confirmed loaded, matching exactly how PMPro core's own gateway files
// (classes/gateways/class.pmprogateway_check.php, ...) register their
// `init` hook at file scope, immediately when the file is included.
add_action( 'init', array( 'PMProGateway_ifthenpay', 'init' ) );

/**
 * ifthenpay Pay-By-Link gateway for Paid Memberships Pro.
 *
 * Payment flow: process() marks the order pending and lets checkout
 * continue; the actual off-site redirect happens in
 * pmpro_checkout_before_change_membership_level() -- fired by PMPro core's
 * pmpro_complete_checkout() *before* membership is granted (see
 * includes/checkout.php), exactly like PMProGateway_paypalstandard's
 * sendToPayPal(). The browser return (handle_offsite_return(), hooked to
 * template_redirect) is flash-only. Only the server-to-server webhook()
 * (registered on wp_ajax(_nopriv)_ifthenpay_pmpro_webhook, matching the
 * same admin-ajax.php convention PMPro core itself uses for its PayPal IPN,
 * Stripe and Braintree webhooks in preheaders/checkout.php) calls PMPro's
 * own pmpro_complete_checkout() to actually grant membership -- so
 * asynchronous/offline methods (Multibanco, Payshop) are fully supported.
 *
 * Recurring/automatic re-billing is intentionally NOT implemented: like
 * PMProGateway_check, this gateway only automates the initial payment.
 * Renewals for recurring membership levels are the member's/admin's
 * responsibility to complete manually via a new checkout, exactly as with
 * Pay by Check. See the project blueprint's Implementation Notes for why.
 *
 * @since 1.0.0
 */
#[AllowDynamicProperties]
class PMProGateway_ifthenpay extends PMProGateway {

	/**
	 * The `action` value for the server-to-server webhook, registered via
	 * `wp_ajax_ifthenpay_pmpro_webhook` / `wp_ajax_nopriv_ifthenpay_pmpro_webhook`,
	 * matching the admin-ajax.php convention PMPro core itself uses for
	 * `ipnhandler`, `stripe_webhook` and `braintree_webhook` in
	 * preheaders/checkout.php.
	 */
	const WEBHOOK_ACTION = 'ifthenpay_pmpro_webhook';

	/**
	 * Query var carrying the browser-return outcome (`success`|`error`|`cancel`).
	 */
	const RETURN_QUERY_VAR = 'ifthenpay_pmpro_return';

	/**
	 * Query var carrying the order code on the browser-return URL, so
	 * handle_offsite_return() can look up which level/checkout page to
	 * redirect back to.
	 */
	const RETURN_REF_VAR = 'ifthenpay_pmpro_ref';

	/**
	 * PMProGateway_ifthenpay constructor.
	 *
	 * @param null|string $gateway
	 *
	 * @return string
	 */
	public function __construct( $gateway = null ) {
		$this->gateway = $gateway;

		return $this->gateway;
	}

	/**
	 * Run on WP init.
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		// Make sure ifthenpay is a gateway option.
		add_filter( 'pmpro_gateways', array( 'PMProGateway_ifthenpay', 'pmpro_gateways' ) );

		// Patches PMPro core's own "is everything set up?" check (see
		// pmpro_is_ready() below) so the "Configure Payment Settings"
		// checklist item on the dashboard Welcome panel actually reflects
		// ifthenpay once it's connected, instead of always reading as
		// incomplete. Unconditional, like pmpro_gateways above -- the
		// callback itself checks whether ifthenpay is the active gateway.
		add_filter( 'pmpro_is_ready', array( 'PMProGateway_ifthenpay', 'pmpro_is_ready' ) );

		// Server-to-server webhook -- must be registered unconditionally
		// (not only when ifthenpay is the active gateway) since ifthenpay
		// calls this URL from its own servers, with no WP session/cookie,
		// and may do so well after the admin has changed the site's primary
		// gateway back and forth.
		add_action( 'wp_ajax_' . self::WEBHOOK_ACTION, array( 'PMProGateway_ifthenpay', 'webhook' ) );
		add_action( 'wp_ajax_nopriv_' . self::WEBHOOK_ACTION, array( 'PMProGateway_ifthenpay', 'webhook' ) );

		// Browser return flash page -- same reasoning as the webhook above,
		// must not be gated behind "is ifthenpay the current gateway" since
		// a donor's browser can return well after an admin switch.
		add_action( 'template_redirect', array( 'PMProGateway_ifthenpay', 'handle_offsite_return' ) );

		// Code to add at checkout, only when ifthenpay is the selected gateway.
		$gateway = pmpro_getGateway();
		if ( 'ifthenpay' === $gateway ) {
			add_filter( 'pmpro_include_billing_address_fields', '__return_false' );
			add_filter( 'pmpro_include_payment_information_fields', '__return_false' );
			add_filter( 'pmpro_required_billing_fields', array( 'PMProGateway_ifthenpay', 'pmpro_required_billing_fields' ) );
			add_filter( 'pmpro_checkout_after_payment_information_fields', array( 'PMProGateway_ifthenpay', 'pmpro_checkout_after_payment_information_fields' ) );
			add_filter( 'pmpro_checkout_before_change_membership_level', array( 'PMProGateway_ifthenpay', 'pmpro_checkout_before_change_membership_level' ), 10, 2 );
		}
	}

	/**
	 * Make sure this gateway is in the gateways list.
	 *
	 * @param array $gateways Array of recognized gateway identifiers.
	 *
	 * @return array
	 *
	 * @since 1.0.0
	 */
	public static function pmpro_gateways( $gateways ) {
		if ( empty( $gateways['ifthenpay'] ) ) {
			$gateways['ifthenpay'] = __( 'ifthenpay', 'ifthenpay-payments-for-paid-memberships-pro' );
		}

		return $gateways;
	}

	/**
	 * Filter callback for `pmpro_is_ready`. PMPro core's own readiness check
	 * (`pmpro_is_ready()` in includes/functions.php) is a hardcoded
	 * if/elseif chain over its own bundled gateway slugs (stripe, paypal*,
	 * braintree, ...) with no branch for a third-party gateway like
	 * `ifthenpay` -- it falls through to `else { $pmpro_gateway_ready =
	 * false; }`, so the "Configure Payment Settings" checklist item on the
	 * dashboard Welcome panel (adminpages/metaboxes/welcome.php) would
	 * otherwise never show as complete, no matter how correctly ifthenpay is
	 * configured. This is the documented extension point PMPro core
	 * provides for exactly this (see the docblock on the `pmpro_is_ready`
	 * filter): recompute the `$pmpro_gateway_ready` global ourselves when
	 * ifthenpay is the active gateway, then return the combined result the
	 * same way core does.
	 *
	 * @param bool $ready PMPro core's own computed readiness result.
	 *
	 * @return bool
	 *
	 * @since 1.0.0
	 */
	public static function pmpro_is_ready( $ready ) {
		global $pmpro_gateway_ready, $pmpro_pages_ready;

		if ( 'ifthenpay' !== get_option( 'pmpro_gateway' ) ) {
			return $ready;
		}

		$settings            = new SettingsRepository();
		$pmpro_gateway_ready = $settings->is_connected() && ! empty( $settings->get_active_methods() );

		return $pmpro_gateway_ready && $pmpro_pages_ready;
	}

	/**
	 * Get a description for this gateway, shown in the gateway list on the
	 * main Payment Settings screen.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function get_description_for_gateway_settings() {
		return esc_html__( 'Accept Multibanco, MB WAY, Payshop, Credit Card, Apple Pay, Google Pay, Cofidis and Pix via ifthenpay Pay by Link. Members are redirected to a secure ifthenpay-hosted page; ifthenpay confirms payment back to your site automatically, including for offline/async methods.', 'ifthenpay-payments-for-paid-memberships-pro' );
	}

	/**
	 * Remove billing/card fields -- ifthenpay's hosted page collects any
	 * payment details it needs, PMPro never sees card data.
	 *
	 * @param array $fields
	 *
	 * @return array
	 *
	 * @since 1.0.0
	 */
	public static function pmpro_required_billing_fields( $fields ) {
		unset( $fields['bfirstname'] );
		unset( $fields['blastname'] );
		unset( $fields['baddress1'] );
		unset( $fields['bcity'] );
		unset( $fields['bstate'] );
		unset( $fields['bzipcode'] );
		unset( $fields['bphone'] );
		unset( $fields['bemail'] );
		unset( $fields['bcountry'] );
		unset( $fields['CardType'] );
		unset( $fields['AccountNumber'] );
		unset( $fields['ExpirationMonth'] );
		unset( $fields['ExpirationYear'] );
		unset( $fields['CVV'] );

		return $fields;
	}

	/**
	 * Shows a short notice on the checkout page explaining the redirect,
	 * reusing PMPro's own card/fieldset classes (pmpro_get_element_class())
	 * the same way PMProGateway_check does for its instructions box.
	 *
	 * @since 1.0.0
	 */
	public static function pmpro_checkout_after_payment_information_fields() {
		global $gateway, $pmpro_level;

		if ( 'ifthenpay' !== $gateway || pmpro_isLevelFree( $pmpro_level ) ) {
			return;
		}
		?>
		<div id="pmpro_payment_information_fields" class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_form_fieldset', 'pmpro_payment_information_fields' ) ); ?>">
			<div class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_card' ) ); ?>">
				<h2 class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_card_title pmpro_font-large' ) ); ?>"><?php esc_html_e( 'Pay with ifthenpay', 'ifthenpay-payments-for-paid-memberships-pro' ); ?></h2>
				<div class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_card_content' ) ); ?>">
					<div class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_form_fields' ) ); ?>">
						<div class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_form_field pmpro_ifthenpay_instructions' ) ); ?>">
							<?php esc_html_e( 'After submitting, you will be redirected to a secure ifthenpay page to complete your payment (card, Apple Pay, Google Pay, Multibanco, MB WAY, and more depending on what is enabled).', 'ifthenpay-payments-for-paid-memberships-pro' ); ?>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Display the six-field ifthenpay settings contract for this gateway.
	 *
	 * @since 1.0.0
	 */
	public static function show_settings_fields() {
		?>
		<div id="pmpro_ifthenpay" class="pmpro_section" data-visibility="shown" data-activated="true">
			<div class="pmpro_section_toggle">
				<button class="pmpro_section-toggle-button" type="button" aria-expanded="true">
					<span class="dashicons dashicons-arrow-up-alt2"></span>
					<?php esc_html_e( 'Settings', 'ifthenpay-payments-for-paid-memberships-pro' ); ?>
				</button>
			</div>
			<div class="pmpro_section_inside">
				<table class="form-table">
					<tbody>
						<?php ( new MethodsField( new SettingsRepository() ) )->render(); ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Save settings for this gateway. The `savesettings` nonce is already
	 * verified by adminpages/paymentsettings.php before this is called
	 * (`check_admin_referer( 'savesettings', 'pmpro_paymentsettings_nonce' )`),
	 * matching every other PMPro gateway's save_settings_fields().
	 *
	 * @since 1.0.0
	 */
	public static function save_settings_fields() {
		$settings = new SettingsRepository();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified by adminpages/paymentsettings.php before save_settings_fields() is invoked.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- SettingsRepository::save_from_request() unslashes each field itself; do not double-unslash here.
		$settings->save_from_request( $_REQUEST );

		$new_gateway_key = $settings->get_backoffice_key() ? $settings->get_gateway_key() : '';

		// (Re)activate the server-to-server webhook whenever a Gateway Key
		// is configured, so a fresh key (or a key set for the first time)
		// always has a live callback registered. Best-effort: failures are
		// silently ignored here, matching GiveWP's own activate_callback()
		// behavior ("failures are logged but don't block the feed save").
		if ( '' !== $new_gateway_key ) {
			IfthenpayClient::activate_callback( $new_gateway_key, self::build_webhook_url() );
		}
	}

	/**
	 * Process checkout. Marks the order pending and lets PMPro core continue
	 * to pmpro_complete_checkout() -- the actual redirect happens in
	 * pmpro_checkout_before_change_membership_level(), fired from inside
	 * that function, before membership is granted.
	 *
	 * @param MemberOrder $order
	 *
	 * @return bool
	 *
	 * @since 1.0.0
	 */
	public function process( &$order ) {
		if ( empty( $order->code ) ) {
			$order->code = $order->getRandomCode();
		}

		$order->payment_type = 'ifthenpay';
		$order->CardType     = '';
		$order->cardtype     = '';
		$order->status       = 'pending';
		$order->saveOrder();

		return true;
	}

	/**
	 * There is no stored payment method to update -- ifthenpay's hosted page
	 * never leaves anything on file with PMPro. Matches the base class's own
	 * "simulate a successful billing update" default.
	 *
	 * @param MemberOrder $order
	 *
	 * @return bool
	 *
	 * @since 1.0.0
	 */
	public function update( &$order ) {
		return true;
	}

	/**
	 * ifthenpay's Pay-By-Link does not manage recurring subscriptions --
	 * there is nothing to cancel remotely. PMPro core has already marked
	 * the local PMPro_Subscription record 'cancelled' before calling this
	 * (see PMPro_Subscription::cancel_at_gateway()); simply confirm success
	 * like the base PMProGateway::cancel_subscription() default.
	 *
	 * @param PMPro_Subscription $subscription
	 *
	 * @return bool
	 *
	 * @since 1.0.0
	 */
	public function cancel_subscription( $subscription ) {
		return true;
	}

	/**
	 * Filter callback for `pmpro_checkout_before_change_membership_level`,
	 * fired from inside PMPro core's pmpro_complete_checkout()
	 * (includes/checkout.php) *before* pmpro_changeMembershipLevel() runs.
	 *
	 * Guarded by $order->payment_transaction_id: empty means this is the
	 * very first pass through checkout (right after process()) and the
	 * member has not been sent to ifthenpay yet, so redirect them there and
	 * exit. Non-empty means webhook() (below) has already confirmed payment
	 * and is itself calling pmpro_complete_checkout() to finish granting
	 * membership -- in that case we must NOT redirect again, just return and
	 * let core continue.
	 *
	 * @param int         $user_id
	 * @param MemberOrder $order
	 *
	 * @since 1.0.0
	 */
	public static function pmpro_checkout_before_change_membership_level( $user_id, $order ) {
		if ( empty( $order ) || 'ifthenpay' !== $order->gateway ) {
			return;
		}

		if ( ! empty( $order->payment_transaction_id ) ) {
			// Already confirmed by webhook() -- let checkout continue normally.
			return;
		}

		$order->user_id = $user_id;
		$order->saveOrder();

		self::redirect_to_ifthenpay( $order );
		// redirect_to_ifthenpay() exits on success. If it returns, building
		// the payment link failed and $order->error has been set; fall
		// through so checkout.php shows that error instead of a blank page.
	}

	/**
	 * Builds the ifthenpay Pay-By-Link payload for $order, requests the
	 * hosted payment page and redirects the browser there.
	 *
	 * @param MemberOrder $order
	 *
	 * @since 1.0.0
	 */
	private static function redirect_to_ifthenpay( $order ) {
		$settings    = new SettingsRepository();
		$gateway_key = $settings->get_gateway_key();

		if ( '' === $gateway_key ) {
			$order->error      = __( 'ifthenpay is not configured yet. Please contact the site owner.', 'ifthenpay-payments-for-paid-memberships-pro' );
			$order->shorterror = $order->error;

			return;
		}

		$urls = array(
			'success_url' => self::build_return_url( 'success', $order->code ),
			'error_url'   => self::build_return_url( 'error', $order->code ),
			'cancel_url'  => self::build_return_url( 'cancel', $order->code ),
		);

		try {
			$payload = IfthenpayPayload::build_pay_by_link_payload(
				$order->code,
				number_format( (float) $order->total, 2, '.', '' ),
				$settings->get_description(),
				$settings->get_active_methods(),
				$settings->get_default_method(),
				$settings->get_expire_days(),
				$urls
			);

			$response = IfthenpayClient::create_payment_link( $gateway_key, $payload );
		} catch ( Exception $e ) {
			$order->error      = sprintf(
				/* translators: %s: error message returned by the ifthenpay API. */
				__( 'Unable to start the ifthenpay payment: %s', 'ifthenpay-payments-for-paid-memberships-pro' ),
				$e->getMessage()
			);
			$order->shorterror = $order->error;

			return;
		}

		$redirect_url = (string) ( $response['RedirectUrl'] ?? '' );

		if ( '' === $redirect_url ) {
			$order->error      = __( 'ifthenpay did not return a payment URL. Please try again.', 'ifthenpay-payments-for-paid-memberships-pro' );
			$order->shorterror = $order->error;

			return;
		}

		$order->add_order_note(
			sprintf(
				/* translators: %s: ifthenpay hosted payment page URL. */
				__( 'ifthenpay Payment Url: %s', 'ifthenpay-payments-for-paid-memberships-pro' ),
				$redirect_url
			)
		);
		$order->saveOrder();

		wp_redirect( $redirect_url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- off-site redirect to the ifthenpay hosted payment page by design.
		exit;
	}

	/**
	 * Server-to-server payment confirmation. Registered on
	 * wp_ajax(_nopriv)_ifthenpay_pmpro_webhook (see init()).
	 *
	 * Success: ?action=ifthenpay_pmpro_webhook&ref={order_code}&apk={base64(gatewayKey)}&val={amount}&mtd={method}&req={request_id}
	 * Failure: ?action=ifthenpay_pmpro_webhook&ref={order_code}&apk={base64(gatewayKey)}&status=cancelled|error
	 *
	 * @since 1.0.0
	 */
	public static function webhook() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- server-to-server callback with no browser session; authenticated below via the anti-phishing "apk" key, not a WP nonce.
		$ref = sanitize_text_field( wp_unslash( $_GET['ref'] ?? '' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- server-to-server callback with no browser session; authenticated below via the anti-phishing "apk" key, not a WP nonce.
		$apk = sanitize_text_field( wp_unslash( $_GET['apk'] ?? '' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- server-to-server callback with no browser session; authenticated below via the anti-phishing "apk" key, not a WP nonce.
		$status = sanitize_text_field( wp_unslash( $_GET['status'] ?? '' ) );

		if ( '' === $ref || '' === $apk ) {
			status_header( 400 );
			exit( 'Missing required parameters' );
		}

		$order = new MemberOrder( $ref );
		if ( empty( $order->id ) ) {
			status_header( 404 );
			exit( 'Order not found' );
		}

		$gateway_key = ( new SettingsRepository() )->get_gateway_key();
		$decoded_apk = (string) base64_decode( $apk, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding ifthenpay's anti-phishing token, not obfuscation.
		if ( '' === $gateway_key || ! hash_equals( $gateway_key, $decoded_apk ) ) {
			status_header( 403 );
			exit( 'apk mismatch' );
		}

		if ( 'success' === $order->status ) {
			status_header( 200 );
			exit( 'Payment is already Paid, status cannot be changed.' );
		}

		if ( '' !== $status ) {
			if ( ! in_array( $status, array( 'cancelled', 'error' ), true ) ) {
				status_header( 400 );
				exit( 'Invalid status' );
			}

			$order->status = $status;
			$order->saveOrder();

			status_header( 200 );
			exit( 'OK' );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- server-to-server callback with no browser session; authenticated above via the anti-phishing "apk" key, not a WP nonce.
		$val = sanitize_text_field( wp_unslash( $_GET['val'] ?? '' ) );
		if ( '' === $val ) {
			status_header( 400 );
			exit( 'Missing val' );
		}

		$expected_amount = round( (float) $order->total, 2 );
		if ( $expected_amount <= 0 || round( (float) $val, 2 ) !== $expected_amount ) {
			status_header( 403 );
			exit( 'amount mismatch' );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- server-to-server callback with no browser session; authenticated above via the anti-phishing "apk" key, not a WP nonce.
		$mtd = sanitize_text_field( wp_unslash( $_GET['mtd'] ?? '' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- server-to-server callback with no browser session; authenticated above via the anti-phishing "apk" key, not a WP nonce.
		$req = sanitize_text_field( wp_unslash( $_GET['req'] ?? '' ) );

		// Mark the order confirmed BEFORE calling pmpro_complete_checkout() --
		// its call to pmpro_checkout_before_change_membership_level() (see
		// above) uses this to know not to redirect the member to ifthenpay
		// again.
		$order->payment_transaction_id = $ref . ( '' !== $mtd ? '-' . $mtd : '' );
		if ( '' !== $req ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: ifthenpay request ID, needed for any future refund. */
					__( 'ifthenpay Request ID (for refunds): %s', 'ifthenpay-payments-for-paid-memberships-pro' ),
					$req
				)
			);
		}
		$order->saveOrder();

		if ( ! function_exists( 'pmpro_complete_checkout' ) ) {
			require_once PMPRO_DIR . '/includes/checkout.php';
		}

		// pmpro_complete_checkout() reads the *global* $pmpro_level (not
		// $order->membership_id) to build the level change -- normally
		// populated by PMPro core while rendering the checkout page in the
		// same request. This webhook runs in its own request, potentially
		// long after that page was rendered (that's the whole point, for
		// async/offline methods like Multibanco/Payshop), so nothing else
		// ever sets that global here; without this, pmpro_complete_checkout()
		// operates on a null level and always returns false.
		global $pmpro_level;
		$pmpro_level = pmpro_getLevel( $order->membership_id );

		if ( empty( $pmpro_level ) ) {
			status_header( 500 );
			exit( 'Membership level not found' );
		}

		if ( pmpro_complete_checkout( $order ) ) {
			status_header( 200 );
			exit( 'OK' );
		}

		status_header( 500 );
		exit( 'Could not complete checkout' );
	}

	/**
	 * Browser return from the ifthenpay hosted page. Flash-only -- the
	 * webhook alone finishes granting membership (see webhook() above).
	 *
	 * Hooked directly onto WordPress's `template_redirect` (see init())
	 * rather than any PMPro-specific route, since PMPro core has no gateway
	 * return-route convention of its own (PayPal Standard relies on
	 * PayPal's own `return`/`rm=2` mechanism instead).
	 *
	 * @since 1.0.0
	 */
	public static function handle_offsite_return() {
		// Phase 1: a fresh browser return from the ifthenpay hosted page
		// (RETURN_QUERY_VAR is only ever present on that first hit).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- browser return with no state-changing side effect; only chooses which flash message to show, the webhook alone transitions order status.
		$type = sanitize_text_field( wp_unslash( $_GET[ self::RETURN_QUERY_VAR ] ?? '' ) );

		if ( '' !== $type ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- browser return with no state-changing side effect; see above.
			$ref      = sanitize_text_field( wp_unslash( $_GET[ self::RETURN_REF_VAR ] ?? '' ) );
			$order    = '' !== $ref ? new MemberOrder( $ref ) : null;
			$level_id = ! empty( $order->membership_id ) ? (int) $order->membership_id : 0;

			if ( 'success' === $type ) {
				$redirect_url = pmpro_url( 'confirmation', $level_id ? '?pmpro_level=' . $level_id : '' );
			} else {
				// Carry the outcome forward on the checkout-page redirect URL
				// (rather than calling pmpro_setMessage() here) because
				// pmpro_setMessage() only sets globals read while rendering
				// the checkout page *in the same request* -- it cannot
				// survive the wp_safe_redirect() below into a new request.
				// Phase 2 further down picks this query var back up once
				// we're actually on the checkout page's own request.
				$redirect_url = add_query_arg(
					'ifthenpay_pmpro_status',
					$type,
					pmpro_url( 'checkout', $level_id ? '?pmpro_level=' . $level_id : '' )
				);
			}

			wp_safe_redirect( $redirect_url );
			exit;
		}

		// Phase 2: we've arrived at the checkout page carrying the status
		// flag set in Phase 1 above -- surface it as a normal PMPro checkout
		// message for this page render.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash flag, no state-changing side effect.
		$status = sanitize_text_field( wp_unslash( $_GET['ifthenpay_pmpro_status'] ?? '' ) );
		if ( '' === $status ) {
			return;
		}

		pmpro_setMessage(
			'cancel' === $status
				? __( 'Your ifthenpay payment was cancelled. You can try again below.', 'ifthenpay-payments-for-paid-memberships-pro' )
				: __( 'Your ifthenpay payment could not be completed. You can try again below.', 'ifthenpay-payments-for-paid-memberships-pro' ),
			'pmpro_error'
		);
	}

	/**
	 * Builds the server-to-server webhook URL ifthenpay posts payment
	 * notifications to, matching the same admin-ajax.php convention PMPro
	 * core itself uses for `ipnhandler`/`stripe_webhook`/`braintree_webhook`.
	 *
	 * @return string
	 */
	private static function build_webhook_url() {
		return add_query_arg( 'action', self::WEBHOOK_ACTION, admin_url( 'admin-ajax.php' ) );
	}

	/**
	 * Builds the off-site return URL for a given result type, carrying the
	 * order code so handle_offsite_return() can resolve which membership
	 * level's checkout/confirmation page to send the member back to.
	 *
	 * @param string $type       One of "success", "error", "cancel".
	 * @param string $order_code The MemberOrder::$code for this checkout.
	 *
	 * @return string
	 */
	private static function build_return_url( $type, $order_code ) {
		return add_query_arg(
			array(
				self::RETURN_QUERY_VAR => $type,
				self::RETURN_REF_VAR   => $order_code,
			),
			home_url( '/' )
		);
	}
}
