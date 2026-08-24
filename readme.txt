=== ifthenpay | Payments for Paid Memberships Pro ===
Contributors: ifthenpay
Tags: ifthenpay, paid memberships pro, membership, payment, multibanco
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Requires Plugins: paid-memberships-pro

Adds ifthenpay as a payment gateway for Paid Memberships Pro: Multibanco, MB WAY, Payshop, Credit Card and more via Pay-By-Link.

== Description ==

This plugin integrates the ifthenpay payment gateway with Paid Memberships Pro so members can pay for their membership using Portugal's most popular local payment methods, directly from your membership checkout.

Membership payments are processed through ifthenpay's secure Pay-By-Link system — no card or banking data is ever stored on your website.

After starting checkout, the member is redirected to a secure ifthenpay-hosted payment page to complete the transaction. ifthenpay then notifies your site via a server-to-server callback so the order status updates and membership is granted automatically, including for offline/async methods like Multibanco and Payshop.

In plain terms you get:
* Membership checkout through Paid Memberships Pro's own checkout flow
* A single settings screen to connect your ifthenpay Backoffice Key
* Automatic Gateway Key and payment-method sync from your ifthenpay Backoffice
* Secure, automatic payment confirmation via server-to-server callback
* Support for Multibanco, MB WAY, Payshop, Credit Card, Apple Pay, Google Pay, Cofidis and Pix
* No card numbers stored on your website

All settings are managed within Paid Memberships Pro's own Payment Settings screen and your ifthenpay Backoffice.

== Key Features ==

1. Full integration with Paid Memberships Pro's payment gateway framework
2. Secure transactions via ifthenpay Pay-By-Link (off-site redirect to a hosted checkout)
3. Automatic, server-to-server payment confirmation (works for asynchronous/offline methods like Multibanco and Payshop)
4. Support for multiple payment methods (Multibanco, MB WAY, Payshop, Credit Card, and more)
5. Backoffice Key connection with automatic Gateway Key and payment-method sync
6. Configurable default payment method, payment description and link expiry
7. Multi-language support (EN, ES, FR, PT)
8. Security-first approach (no card data stored)

== Requirements ==
* An active ifthenpay merchant account.
* A Paid Memberships Pro Gateway Key configured for Paid Memberships Pro (request via ifthenpay support).
* Backoffice Key
* Paid Memberships Pro installed and activated.
* WordPress 6.0+ and PHP 7.4+.
* HTTPS (SSL) enabled on your site.

== Installation ==
1. Install and activate Paid Memberships Pro, then upload the plugin zip via Plugins → Add New → Upload, or install from WordPress.org, and Activate.
2. Credentials: Ensure your ifthenpay account has an active Paid Memberships Pro Gateway Key with the desired payment methods enabled.
3. Setup: Go to Memberships → Payment Settings → ifthenpay and enter your Backoffice Key.
4. Configure: Choose your Gateway Key, enable payment methods, set a default method, description and expiry days.
5. Set ifthenpay as your site's primary payment gateway (or leave levels on other gateways as needed) under Memberships → Payment Settings.

== Frequently Asked Questions ==

= Does this plugin require Paid Memberships Pro? =
Yes. Paid Memberships Pro must be installed and active to use this plugin.

= Where do I get an ifthenpay Backoffice key? =
Sign up at https://ifthenpay.com to get your Backoffice key and a Paid Memberships Pro Gateway Key.

= Does it support recurring membership levels? =
Recurring levels can still be selected at checkout, but ifthenpay Pay-By-Link only automates the initial payment — like the built-in "Pay by Check" gateway, renewals are not automatically re-billed and must be completed manually by the member or admin.

= Are payment details stored? =
No. The plugin does not store card numbers or full banking details. Only minimal references required for payment matching are stored on the order.

= Which payment methods are supported? =
Any method enabled on your ifthenpay Gateway Key (e.g. Multibanco, MB WAY, Payshop, Credit Card, Cofidis, Google Pay, Apple Pay, Pix).

= How does the payment process work? =
After starting checkout, the member is redirected off-site to a secure ifthenpay-hosted payment page. The member's browser return only shows a confirmation/error page — the actual order status is set by a server-to-server callback, so it also works for offline/async methods like Multibanco or Payshop.

= What happens if a payment fails or is cancelled? =
The order is marked Failed or Cancelled once ifthenpay's callback reports the outcome, and the member is shown a message inviting them to try again.

== Changelog ==

= 1.0.0 =
* Initial release: ifthenpay Pay-By-Link gateway for Paid Memberships Pro.

== External services ==

This plugin connects to the ifthenpay API (https://ifthenpay.com) to create payment links, look up your Gateway Keys and available payment methods, and to receive payment confirmations.

* What it is used for: creating Pay-By-Link payment sessions, listing the Gateway Keys and payment methods available on your ifthenpay Backoffice account, and registering the server-to-server webhook that confirms payment.
* What data is sent: your ifthenpay Backoffice Key and Gateway Key, the order code, the amount due, a payment description, and your site's webhook URL. No card or banking details are collected or transmitted by this plugin.
* When data is sent: when an admin loads or saves the ifthenpay settings screen, and when a member checks out using the ifthenpay gateway.
* Service provider: ifthenpay (https://ifthenpay.com), see their Terms of Service (https://ifthenpay.com/eula/) and Privacy Policy (https://ifthenpay.com/politica-de-privacidade/).
