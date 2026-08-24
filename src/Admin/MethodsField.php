<?php
/**
 * Renders the ifthenpay settings fields shown on the PMPro Payment Settings
 * "Edit Payment Gateway: ifthenpay" screen, and the HTML fragments the
 * `ifthenpay_pmpro_*` AJAX endpoints (Ajax\Controller) swap into that screen
 * so connecting/disconnecting the Backoffice Key and switching the Gateway
 * Key update the page live -- no Save-and-reload round trip needed to see
 * the payment methods table or the Default Payment Method options.
 *
 * @package Ifthenpay\PaidMembershipsPro
 */

namespace Ifthenpay\PaidMembershipsPro\Admin;

defined( 'ABSPATH' ) || exit;

use Ifthenpay\PaidMembershipsPro\Settings\SettingsRepository;

/**
 * Renders the six-field ifthenpay settings contract (Backoffice Key,
 * Gateway Key, methods table, default method, description, expiry days) as
 * `<tr>` rows inside the `<table class="form-table">` that
 * `adminpages/paymentsettings.php` wraps around
 * `PMProGateway_ifthenpay::show_settings_fields()`.
 *
 * Reuses Paid Memberships Pro's own markup/CSS: `.form-table`, the
 * `.wp-list-table.widefat.striped` table used for the gateway list on the
 * main Payment Settings screen, and `.pmpro_message`/`.description` helper
 * classes -- no new visual vocabulary is introduced, only a handful of
 * `iftp-pmpro-*` classes for the parts (per-method row state, connect/
 * disconnect status, activate button) that PMPro's own markup has no
 * equivalent for.
 *
 * The Gateway Key row, Payment Methods row and Default Payment Method row
 * all carry the `iftp-pmpro-connected-row` class and are hidden inline
 * until a Backoffice Key is connected; assets/js/admin.js reveals them (and
 * replaces their contents) after a successful AJAX connect, with no page
 * reload. The Backoffice Key's own raw value is never rendered into this
 * screen once saved -- see render_backoffice_status().
 *
 * @since 1.0.0
 */
final class MethodsField {

	/**
	 * Plugin settings repository.
	 *
	 * @var SettingsRepository
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param SettingsRepository $settings Plugin settings repository.
	 */
	public function __construct( SettingsRepository $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Renders every settings row (Backoffice Key through Expiry Days).
	 */
	public function render() {
		$backoffice_key      = $this->settings->get_backoffice_key();
		$gateway_key         = $this->settings->get_gateway_key();
		$is_connected        = '' !== $backoffice_key;
		// Always force a live refresh here: this is the one and only screen
		// where these fields are shown (unlike multi-tab settings screens in
		// other ifthenpay integrations), so it should never show a
		// transient-cached, possibly stale, gateway-key/methods snapshot to
		// an admin actively looking at it.
		$gateway_key_options = $is_connected ? $this->settings->get_gateway_key_options( true ) : array();
		$methods_section     = $this->render_methods_section( $gateway_key, true );
		// Literal, hardcoded attribute markup -- not user data, so nothing
		// here needs esc_attr(); echoing this raw is what actually makes it
		// a `style` attribute rather than escaped text.
		$hidden_attr         = ' style="display:none"';
		?>
		<tr class="gateway gateway_ifthenpay">
			<th scope="row" valign="top">
				<label for="pmpro_ifthenpay_backoffice_key_input"><?php esc_html_e( 'Backoffice Key', 'ifthenpay-payments-for-paid-memberships-pro' ); ?></label>
			</th>
			<td>
				<div id="iftp-pmpro-backoffice-status"><?php echo $this->render_backoffice_status(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped internally ?></div>
				<p class="description"><?php esc_html_e( 'Your ifthenpay Backoffice Key. Everything below requires a valid Backoffice Key.', 'ifthenpay-payments-for-paid-memberships-pro' ); ?></p>
			</td>
		</tr>
		<tr class="gateway gateway_ifthenpay iftp-pmpro-disconnected-row"<?php echo $is_connected ? $hidden_attr : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $hidden_attr is a hardcoded literal, not user data ?>>
			<td colspan="2">
				<div class="notice notice-warning inline">
					<p><?php esc_html_e( 'Connect your Backoffice Key above to load your Gateway Keys and payment methods.', 'ifthenpay-payments-for-paid-memberships-pro' ); ?></p>
				</div>
			</td>
		</tr>
		<tr class="gateway gateway_ifthenpay iftp-pmpro-connected-row"<?php echo $is_connected ? '' : $hidden_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $hidden_attr is a hardcoded literal, not user data ?>>
			<th scope="row" valign="top">
				<label for="pmpro_ifthenpay_gateway_key"><?php esc_html_e( 'Gateway Key', 'ifthenpay-payments-for-paid-memberships-pro' ); ?></label>
			</th>
			<td>
				<select id="pmpro_ifthenpay_gateway_key" name="pmpro_ifthenpay_gateway_key" data-iftp-pmpro-gateway-key-select>
					<?php echo $this->render_gateway_key_options( $gateway_key_options, $gateway_key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped internally ?>
				</select>
				<p class="description"><?php esc_html_e( 'The ifthenpay Gateway Key provisioned for this site.', 'ifthenpay-payments-for-paid-memberships-pro' ); ?></p>
			</td>
		</tr>
		<tr class="gateway gateway_ifthenpay iftp-pmpro-connected-row"<?php echo $is_connected ? '' : $hidden_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $hidden_attr is a hardcoded literal, not user data ?>>
			<th scope="row" valign="top">
				<label><?php esc_html_e( 'Payment Methods', 'ifthenpay-payments-for-paid-memberships-pro' ); ?></label>
			</th>
			<td>
				<div id="iftp-pmpro-methods-table-wrap"><?php echo $methods_section['table_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped internally ?></div>
			</td>
		</tr>
		<tr class="gateway gateway_ifthenpay iftp-pmpro-connected-row"<?php echo $is_connected ? '' : $hidden_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $hidden_attr is a hardcoded literal, not user data ?>>
			<th scope="row" valign="top">
				<label for="pmpro_ifthenpay_default_method"><?php esc_html_e( 'Default Payment Method', 'ifthenpay-payments-for-paid-memberships-pro' ); ?></label>
			</th>
			<td>
				<select id="pmpro_ifthenpay_default_method" name="pmpro_ifthenpay_default_method">
					<?php echo $methods_section['default_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped internally ?>
				</select>
				<p class="description"><?php esc_html_e( 'Pre-selected on the ifthenpay hosted payment page. Only methods enabled above are offered.', 'ifthenpay-payments-for-paid-memberships-pro' ); ?></p>
			</td>
		</tr>
		<tr class="gateway gateway_ifthenpay">
			<th scope="row" valign="top">
				<label for="pmpro_ifthenpay_description"><?php esc_html_e( 'Payment Description', 'ifthenpay-payments-for-paid-memberships-pro' ); ?></label>
			</th>
			<td>
				<input type="text" id="pmpro_ifthenpay_description" name="pmpro_ifthenpay_description" class="regular-text" value="<?php echo esc_attr( $this->settings->get_description() ); ?>" placeholder="<?php echo esc_attr( sprintf( /* translators: %s: site name. */ __( 'Membership Payment - %s', 'ifthenpay-payments-for-paid-memberships-pro' ), get_bloginfo( 'name' ) ) ); ?>" />
				<p class="description"><?php esc_html_e( 'Shown to the member on the ifthenpay hosted payment page. Use {id} as a placeholder for the order code.', 'ifthenpay-payments-for-paid-memberships-pro' ); ?></p>
			</td>
		</tr>
		<tr class="gateway gateway_ifthenpay">
			<th scope="row" valign="top">
				<label for="pmpro_ifthenpay_expire_days"><?php esc_html_e( 'Expiry Days', 'ifthenpay-payments-for-paid-memberships-pro' ); ?></label>
			</th>
			<td>
				<input type="number" id="pmpro_ifthenpay_expire_days" name="pmpro_ifthenpay_expire_days" class="small-text" min="0" max="30" step="1" value="<?php echo esc_attr( (string) $this->settings->get_expire_days() ); ?>" />
				<p class="description"><?php esc_html_e( 'Days before an unpaid ifthenpay payment link expires. 0 disables expiry.', 'ifthenpay-payments-for-paid-memberships-pro' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Renders the Backoffice Key field's current state: an input + "Connect"
	 * button when nothing is saved yet, or a "Connected" indicator + a
	 * "Disconnect" button once it is.
	 *
	 * Deliberately never renders the saved key's value, in either state --
	 * not even masked. Unlike a card number or API token an admin might need
	 * to reference again, ifthenpay's Backoffice Key is a one-time
	 * credential handed to the account owner when their contract with
	 * ifthenpay is signed and never surfaced again afterwards; showing it
	 * (even partially) in this screen's HTML source would let anyone with
	 * wp-admin access -- not just the account owner -- recover a credential
	 * they were never meant to hold, and its exact format elsewhere would
	 * only help someone without an ifthenpay contract guess at valid keys.
	 * Used both for the initial page render and as the "Connect"/"Disconnect"
	 * AJAX responses' `backoffice_status_html` (see Ajax\Controller).
	 *
	 * @return string
	 *
	 * @since 1.0.0
	 */
	public function render_backoffice_status() {
		$is_connected = '' !== $this->settings->get_backoffice_key();

		ob_start();
		if ( ! $is_connected ) {
			?>
			<div class="iftp-pmpro-backoffice-connect">
				<input type="text" id="pmpro_ifthenpay_backoffice_key_input" class="regular-text code" autocomplete="off" placeholder="<?php esc_attr_e( 'Backoffice Key', 'ifthenpay-payments-for-paid-memberships-pro' ); ?>" data-iftp-pmpro-backoffice-key-input />
				<button type="button" class="button button-primary" data-iftp-pmpro-connect-button><?php esc_html_e( 'Connect', 'ifthenpay-payments-for-paid-memberships-pro' ); ?></button>
			</div>
			<?php
		} else {
			?>
			<div class="iftp-pmpro-backoffice-connected">
				<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
				<?php esc_html_e( 'Backoffice Key connected.', 'ifthenpay-payments-for-paid-memberships-pro' ); ?>
				<button type="button" class="button" data-iftp-pmpro-disconnect-button><?php esc_html_e( 'Disconnect', 'ifthenpay-payments-for-paid-memberships-pro' ); ?></button>
			</div>
			<?php
		}

		return (string) ob_get_clean();
	}

	/**
	 * Renders the `<option>` list for the "Gateway Key" `<select>`.
	 *
	 * @param array<string, string> $options  Gateway-key => alias options, as returned by SettingsRepository::get_gateway_key_options().
	 * @param string                $selected The currently selected Gateway Key.
	 *
	 * @return string
	 *
	 * @since 1.0.0
	 */
	public function render_gateway_key_options( array $options, $selected ) {
		if ( empty( $options ) ) {
			$options = array( '' => __( '-- No gateway keys found --', 'ifthenpay-payments-for-paid-memberships-pro' ) );
		}

		$html = '';
		foreach ( $options as $key => $label ) {
			$html .= sprintf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $key ),
				selected( $key, $selected, false ),
				esc_html( $label )
			);
		}

		return $html;
	}

	/**
	 * Builds the payment-methods table and Default Payment Method options
	 * for a given Gateway Key. The single entry point both render() and
	 * every AJAX endpoint that needs to refresh these two pieces together
	 * (Ajax\Controller::connect_backoffice(), ::disconnect_backoffice(),
	 * ::select_gateway_key()) call, so they can never drift out of sync with
	 * each other.
	 *
	 * @param string $gateway_key The Gateway Key to build rows for.
	 * @param bool   $force       Bypass the transient cache and hit the live ifthenpay API.
	 *
	 * @return array{table_html:string,default_html:string}
	 *
	 * @since 1.0.0
	 */
	public function render_methods_section( $gateway_key, $force = true ) {
		$rows          = $this->settings->build_method_rows( $gateway_key, $force );
		$saved_methods = $this->settings->get_methods();

		return array(
			'table_html'   => $this->render_methods_table( $rows, $saved_methods, $gateway_key ),
			'default_html' => $this->render_default_method_options( $rows, $saved_methods, $this->settings->get_default_method() ),
		);
	}

	/**
	 * Renders the payment-methods table: one row per method, showing whether
	 * it is provisioned (with a checkbox to enable it) or not (with a
	 * "Request Activation" note), reusing the same
	 * `.wp-list-table.widefat.striped` table `adminpages/paymentsettings.php`
	 * already uses for the top-level gateway list.
	 *
	 * Each checkbox carries `data-iftp-pmpro-entity`/`data-iftp-pmpro-label`
	 * so assets/js/admin.js can rebuild the Default Payment Method
	 * `<select>`'s options the instant a checkbox is (un)checked, entirely
	 * client-side -- no AJAX round trip, no need to click Save first.
	 *
	 * @param array<int, array<string, mixed>>    $rows          Rows built by SettingsRepository::build_method_rows().
	 * @param array<string, array<string, mixed>> $saved_methods The merchant's currently saved methods state.
	 * @param string                              $gateway_key   The currently selected Gateway Key.
	 *
	 * @return string
	 */
	private function render_methods_table( array $rows, array $saved_methods, $gateway_key ) {
		if ( empty( $rows ) ) {
			return '<p class="description">' . esc_html__( 'No payment methods found for this Gateway Key.', 'ifthenpay-payments-for-paid-memberships-pro' ) . '</p>';
		}

		ob_start();
		?>
		<table class="wp-list-table widefat striped iftp-pmpro-methods-table">
			<thead>
				<tr>
					<th class="manage-column" scope="col"><?php esc_html_e( 'Enabled', 'ifthenpay-payments-for-paid-memberships-pro' ); ?></th>
					<th class="manage-column" scope="col"><?php esc_html_e( 'Method', 'ifthenpay-payments-for-paid-memberships-pro' ); ?></th>
					<th class="manage-column" scope="col"><?php esc_html_e( 'Account', 'ifthenpay-payments-for-paid-memberships-pro' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<?php
					$entity         = $row['entity'];
					$is_provisioned = '' !== $row['account'];
					$is_active      = $is_provisioned && ! empty( $saved_methods[ $entity ]['is_active'] );
					$field_name     = sprintf( 'pmpro_ifthenpay_methods[%s]', $entity );
					?>
					<tr class="iftp-pmpro-method-row<?php echo $is_provisioned ? '' : ' iftp-pmpro-method-row--disabled'; ?>">
						<td>
							<input type="hidden" name="<?php echo esc_attr( $field_name ); ?>[label]" value="<?php echo esc_attr( $row['label'] ); ?>" />
							<input type="hidden" name="<?php echo esc_attr( $field_name ); ?>[account]" value="<?php echo esc_attr( $row['account'] ); ?>" />
							<input type="hidden" name="<?php echo esc_attr( $field_name ); ?>[position]" value="<?php echo esc_attr( (string) $row['position'] ); ?>" />
							<input type="hidden" name="<?php echo esc_attr( $field_name ); ?>[img_url]" value="<?php echo esc_attr( $row['img_url'] ); ?>" />
							<input type="hidden" name="<?php echo esc_attr( $field_name ); ?>[img_url_dark]" value="<?php echo esc_attr( $row['img_url_dark'] ); ?>" />
							<input type="hidden" name="<?php echo esc_attr( $field_name ); ?>[is_active]" value="0" />
							<input
								type="checkbox"
								name="<?php echo esc_attr( $field_name ); ?>[is_active]"
								value="1"
								data-iftp-pmpro-entity="<?php echo esc_attr( $entity ); ?>"
								data-iftp-pmpro-label="<?php echo esc_attr( $row['label'] ); ?>"
								<?php checked( $is_active ); ?>
								<?php disabled( ! $is_provisioned ); ?>
							/>
						</td>
						<td class="iftp-pmpro-method-row__method">
							<?php if ( '' !== $row['img_url'] ) : ?>
								<img src="<?php echo esc_url( $row['img_url'] ); ?>" alt="" class="iftp-pmpro-method-row__logo" />
							<?php endif; ?>
							<span><?php echo esc_html( $row['label'] ); ?></span>
						</td>
						<td>
							<?php if ( $is_provisioned ) : ?>
								<code><?php echo esc_html( $row['account'] ); ?></code>
							<?php else : ?>
								<span class="iftp-pmpro-method-row__status"><?php esc_html_e( 'Not activated in Backoffice', 'ifthenpay-payments-for-paid-memberships-pro' ); ?></span>
								<a class="button button-secondary button-small" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url( 'mailto:suporte@ifthenpay.com?subject=' . rawurlencode( sprintf( 'Activate %s for Gateway Key %s', $row['label'], $gateway_key ) ) ); ?>">
									<?php esc_html_e( 'Request Activation', 'ifthenpay-payments-for-paid-memberships-pro' ); ?>
								</a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Renders the <option> list for the "Default Payment Method" dropdown --
	 * restricted to methods that are both provisioned and currently enabled.
	 *
	 * @param array<int, array<string, mixed>>    $rows            Rows built by SettingsRepository::build_method_rows().
	 * @param array<string, array<string, mixed>> $saved_methods   The merchant's currently saved methods state.
	 * @param string                              $current_default The currently saved default-method entity.
	 *
	 * @return string
	 */
	private function render_default_method_options( array $rows, array $saved_methods, $current_default ) {
		$options = sprintf(
			'<option value="">%s</option>',
			esc_html__( '-- No default, let the member choose --', 'ifthenpay-payments-for-paid-memberships-pro' )
		);

		foreach ( $rows as $row ) {
			$entity = $row['entity'];
			if ( '' === $row['account'] || empty( $saved_methods[ $entity ]['is_active'] ) ) {
				continue;
			}

			$options .= sprintf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $entity ),
				selected( $entity, strtoupper( $current_default ), false ),
				esc_html( $row['label'] )
			);
		}

		return $options;
	}
}
