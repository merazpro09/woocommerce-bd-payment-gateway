<?php
/**
 * Plugin Name:          BD Payment Gateway for WooCommerce (SSLCommerz Starter)
 * Plugin URI:           https://github.com/merazpro09/woocommerce-bd-payment-gateway
 * Description:          Starter/demo SSLCommerz hosted-payment gateway for WooCommerce with server-side validation. Test in sandbox before going live.
 * Version:              0.1.0
 * Author:               Meraz.Pro
 * Author URI:           https://meraz.pro
 * License:              MIT
 * License URI:          https://opensource.org/licenses/MIT
 * Text Domain:          bd-payment-gateway
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * WC requires at least: 7.1
 *
 * @package BDPaymentGateway
 */

defined( 'ABSPATH' ) || exit;

define( 'BDPG_VERSION', '0.1.0' );
define( 'BDPG_PLUGIN_FILE', __FILE__ );
define( 'BDPG_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Declare compatibility with WooCommerce features.
 *
 * - HPOS (custom order tables): compatible. All order data goes through WC_Order CRUD methods.
 * - Cart & Checkout Blocks: NOT supported yet. This gateway shows on the classic
 *   [woocommerce_checkout] shortcode checkout. See README for adding a Blocks integration.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, false );
		}
	}
);

/**
 * Load the gateway once WooCommerce is available.
 */
add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>' .
						esc_html__( 'BD Payment Gateway requires WooCommerce to be installed and active.', 'bd-payment-gateway' ) .
						'</p></div>';
				}
			);
			return;
		}

		require_once BDPG_PLUGIN_DIR . 'includes/class-bdpg-sslcommerz-api.php';
		require_once BDPG_PLUGIN_DIR . 'includes/class-wc-gateway-bdpg-sslcommerz.php';

		add_filter(
			'woocommerce_payment_gateways',
			function ( $gateways ) {
				$gateways[] = 'WC_Gateway_BDPG_SSLCommerz';
				return $gateways;
			}
		);
	}
);

/**
 * Settings link on the Plugins screen.
 */
add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=bdpg_sslcommerz' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'bd-payment-gateway' ) . '</a>' );
		return $links;
	}
);
