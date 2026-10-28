=== BD Payment Gateway for WooCommerce (SSLCommerz Starter) ===
Contributors: merazpro
Tags: woocommerce, sslcommerz, bangladesh, payment gateway, bkash
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Starter SSLCommerz hosted-payment gateway for WooCommerce with server-side validation. A demo, not a certified plugin. Test in sandbox first.

== Description ==

Adds SSLCommerz (cards, mobile banking and internet banking in Bangladesh) to the classic WooCommerce checkout.

* Hosted payment page, so card data never touches your site and nothing card-related is stored
* Sandbox/live toggle with separate credentials
* IPN listener plus redirect handling
* Orders are marked paid only after the SSLCommerz Order Validation API confirms the transaction, with tran_id, amount and currency checks
* Payments flagged as risky are put on hold
* HPOS (custom order tables) compatible
* Logging via WooCommerce → Status → Logs (source: bdpg-sslcommerz)

**Important:** This is a starter/demo plugin by Meraz.Pro. Review the code, run full sandbox tests, and get your SSLCommerz live credentials approved before accepting real payments.

== Installation ==

1. Upload the plugin folder to /wp-content/plugins/ and activate it.
2. Go to WooCommerce → Settings → Payments → SSLCommerz (BD Payment Gateway).
3. Turn on Sandbox mode and enter your sandbox Store ID and password (register at https://developer.sslcommerz.com/registration/).
4. Place a test order with the SSLCommerz test cards, then check the order notes.
5. When everything works, add your live credentials and turn Sandbox mode off.

== Frequently Asked Questions ==

= Does it work with the block-based checkout? =

Not yet. The plugin declares itself incompatible with Cart & Checkout Blocks, so use the classic checkout ([woocommerce_checkout] shortcode). A Blocks integration can be added later.

= Which currencies are supported? =

SSLCommerz settles in BDT and converts other currencies. The plugin compares the validated currency_type and currency_amount with the order.

== Changelog ==

= 0.1.0 =
* First starter release.
