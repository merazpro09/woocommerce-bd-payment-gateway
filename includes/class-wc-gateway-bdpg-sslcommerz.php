<?php
/**
 * SSLCommerz hosted-payment gateway (starter / demo).
 *
 * Flow:
 * 1. process_payment() creates an SSLCommerz session and redirects the customer to GatewayPageURL.
 * 2. SSLCommerz POSTs to the IPN listener (server to server) and redirects the customer to success/fail/cancel.
 * 3. Before an order is marked paid, the val_id is checked with the Order Validation API, and
 *    tran_id, amount and currency are compared with the order. Card data never touches this site.
 *
 * @package BDPaymentGateway
 */

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce gateway class.
 */
class WC_Gateway_BDPG_SSLCommerz extends WC_Payment_Gateway {

	const META_TRAN_ID = '_bdpg_sslcommerz_tran_id';
	const META_VAL_ID  = '_bdpg_sslcommerz_val_id';
	const LOG_SOURCE   = 'bdpg-sslcommerz';

	/**
	 * Sandbox mode.
	 *
	 * @var bool
	 */
	public $testmode;

	/**
	 * Debug logging.
	 *
	 * @var bool
	 */
	public $debug;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = 'bdpg_sslcommerz';
		$this->method_title       = __( 'SSLCommerz (BD Payment Gateway)', 'bd-payment-gateway' );
		$this->method_description = __( 'Starter SSLCommerz hosted checkout. Customers pay on the SSLCommerz page; orders are marked paid only after server-side validation. Test in sandbox first.', 'bd-payment-gateway' );
		$this->has_fields         = false;
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->testmode    = 'yes' === $this->get_option( 'testmode', 'yes' );
		$this->debug       = 'yes' === $this->get_option( 'debug', 'no' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_api_wc_gateway_bdpg_sslcommerz', array( $this, 'handle_callback' ) );
	}

	/**
	 * Admin settings.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'          => array(
				'title'   => __( 'Enable/Disable', 'bd-payment-gateway' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable SSLCommerz', 'bd-payment-gateway' ),
				'default' => 'no',
			),
			'title'            => array(
				'title'   => __( 'Title', 'bd-payment-gateway' ),
				'type'    => 'text',
				'default' => __( 'Card, bKash, Nagad & Net Banking (SSLCommerz)', 'bd-payment-gateway' ),
			),
			'description'      => array(
				'title'   => __( 'Description', 'bd-payment-gateway' ),
				'type'    => 'textarea',
				'default' => __( 'You will be redirected to SSLCommerz to complete your payment securely.', 'bd-payment-gateway' ),
			),
			'testmode'         => array(
				'title'       => __( 'Sandbox mode', 'bd-payment-gateway' ),
				'type'        => 'checkbox',
				'label'       => __( 'Use the SSLCommerz sandbox (no real money)', 'bd-payment-gateway' ),
				'default'     => 'yes',
				'description' => __( 'Keep this on until you have completed test orders end to end.', 'bd-payment-gateway' ),
			),
			'sandbox_store_id' => array(
				'title' => __( 'Sandbox Store ID', 'bd-payment-gateway' ),
				'type'  => 'text',
			),
			'sandbox_password' => array(
				'title' => __( 'Sandbox Store Password', 'bd-payment-gateway' ),
				'type'  => 'password',
			),
			'live_store_id'    => array(
				'title' => __( 'Live Store ID', 'bd-payment-gateway' ),
				'type'  => 'text',
			),
			'live_password'    => array(
				'title' => __( 'Live Store Password', 'bd-payment-gateway' ),
				'type'  => 'password',
			),
			'ipn_info'         => array(
				'title'       => __( 'IPN URL', 'bd-payment-gateway' ),
				'type'        => 'title',
				/* translators: %s: IPN listener URL */
				'description' => sprintf( __( 'This URL is sent with every payment as ipn_url. You can also set it in your SSLCommerz merchant panel: %s', 'bd-payment-gateway' ), '<code>' . esc_html( $this->callback_url( 'ipn' ) ) . '</code>' ),
			),
			'debug'            => array(
				'title'       => __( 'Debug log', 'bd-payment-gateway' ),
				'type'        => 'checkbox',
				'label'       => __( 'Log events to WooCommerce → Status → Logs', 'bd-payment-gateway' ),
				'default'     => 'no',
				'description' => __( 'Credentials and card data are never logged.', 'bd-payment-gateway' ),
			),
		);
	}

	/**
	 * Only show the gateway when credentials are set.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}
		list( $store_id, $password ) = $this->credentials();
		return '' !== $store_id && '' !== $password;
	}

	/**
	 * Current credentials.
	 *
	 * @return array{0:string,1:string}
	 */
	private function credentials() {
		$prefix = $this->testmode ? 'sandbox' : 'live';
		return array(
			trim( (string) $this->get_option( $prefix . '_store_id' ) ),
			trim( (string) $this->get_option( $prefix . '_password' ) ),
		);
	}

	/**
	 * API client.
	 *
	 * @return BDPG_SSLCommerz_API
	 */
	private function api() {
		list( $store_id, $password ) = $this->credentials();
		return new BDPG_SSLCommerz_API( $store_id, $password, $this->testmode );
	}

	/**
	 * Callback URL for a given action.
	 *
	 * @param string $action success|fail|cancel|ipn.
	 * @return string
	 */
	private function callback_url( $action ) {
		return add_query_arg( 'bdpg_action', $action, WC()->api_request_url( 'wc_gateway_bdpg_sslcommerz' ) );
	}

	/**
	 * Log a message (only when debug is on, errors always).
	 *
	 * @param string $message Message.
	 * @param string $level   Log level.
	 */
	private function log( $message, $level = 'info' ) {
		if ( $this->debug || 'error' === $level ) {
			wc_get_logger()->log( $level, $message, array( 'source' => self::LOG_SOURCE ) );
		}
	}

	/**
	 * Create the SSLCommerz session and redirect.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array( 'result' => 'failure' );
		}

		// tran_id must be unique and at most 30 characters: "<order id>-<random>".
		$tran_id = substr( $order->get_id() . '-' . wp_generate_password( 10, false, false ), 0, 30 );
		$order->update_meta_data( self::META_TRAN_ID, $tran_id );
		$order->save();

		$items = array();
		foreach ( $order->get_items() as $item ) {
			$items[] = $item->get_name();
		}

		$params = array(
			'total_amount'     => wc_format_decimal( $order->get_total(), 2 ),
			'currency'         => $order->get_currency(),
			'tran_id'          => $tran_id,
			'success_url'      => $this->callback_url( 'success' ),
			'fail_url'         => $this->callback_url( 'fail' ),
			'cancel_url'       => $this->callback_url( 'cancel' ),
			'ipn_url'          => $this->callback_url( 'ipn' ),
			'cus_name'         => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'cus_email'        => $order->get_billing_email(),
			'cus_phone'        => $order->get_billing_phone(),
			'cus_add1'         => $order->get_billing_address_1(),
			'cus_add2'         => $order->get_billing_address_2(),
			'cus_city'         => $order->get_billing_city(),
			'cus_state'        => $order->get_billing_state(),
			'cus_postcode'     => $order->get_billing_postcode(),
			'cus_country'      => $order->get_billing_country(),
			'shipping_method'  => $order->needs_shipping_address() ? 'YES' : 'NO',
			'num_of_item'      => max( 1, $order->get_item_count() ),
			'product_name'     => substr( implode( ', ', $items ), 0, 255 ),
			'product_category' => 'general',
			'product_profile'  => 'general',
			'value_a'          => (string) $order->get_id(),
		);

		if ( $order->needs_shipping_address() ) {
			$params += array(
				'ship_name'     => trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ),
				'ship_add1'     => $order->get_shipping_address_1(),
				'ship_add2'     => $order->get_shipping_address_2(),
				'ship_city'     => $order->get_shipping_city(),
				'ship_state'    => $order->get_shipping_state(),
				'ship_postcode' => $order->get_shipping_postcode(),
				'ship_country'  => $order->get_shipping_country(),
			);
		}

		$session = $this->api()->create_session( $params );

		if ( is_wp_error( $session ) ) {
			$this->log( sprintf( 'Session failed for order %d: %s', $order->get_id(), $session->get_error_message() ), 'error' );
			wc_add_notice( __( 'Could not connect to the payment gateway. Please try again or choose another method.', 'bd-payment-gateway' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$this->log( sprintf( 'Session created for order %d (tran_id %s).', $order->get_id(), $tran_id ) );

		return array(
			'result'   => 'success',
			'redirect' => esc_url_raw( $session['GatewayPageURL'] ),
		);
	}

	/**
	 * Handles success / fail / cancel redirects and the IPN.
	 */
	public function handle_callback() {
		// phpcs:disable WordPress.Security.NonceVerification -- requests come from SSLCommerz, verified via the validation API.
		$action = isset( $_GET['bdpg_action'] ) ? sanitize_key( wp_unslash( $_GET['bdpg_action'] ) ) : '';
		$post   = wc_clean( wp_unslash( $_POST ) );
		// phpcs:enable

		$order = $this->find_order( $post['tran_id'] ?? '' );

		if ( 'ipn' === $action ) {
			if ( $order && in_array( $post['status'] ?? '', array( 'VALID', 'VALIDATED' ), true ) ) {
				if ( ! $this->api()->verify_ipn_hash( $post ) ) {
					$this->log( sprintf( 'IPN hash check did not match for order %d; relying on the validation API.', $order->get_id() ), 'warning' );
				}
				$this->verify_and_complete( $order, $post['val_id'] ?? '' );
			} elseif ( $order ) {
				$this->mark_unpaid( $order, $post['status'] ?? 'UNKNOWN' );
			}
			status_header( 200 );
			echo 'OK';
			exit;
		}

		if ( ! $order ) {
			wc_add_notice( __( 'We could not find your order. Please contact us.', 'bd-payment-gateway' ), 'error' );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		if ( 'success' === $action && $this->verify_and_complete( $order, $post['val_id'] ?? '' ) ) {
			wp_safe_redirect( $this->get_return_url( $order ) );
			exit;
		}

		if ( 'cancel' === $action ) {
			$this->mark_unpaid( $order, 'CANCELLED' );
			wc_add_notice( __( 'Payment was cancelled.', 'bd-payment-gateway' ), 'notice' );
		} else {
			$this->mark_unpaid( $order, $post['status'] ?? 'FAILED' );
			wc_add_notice( __( 'Payment was not completed. Please try again.', 'bd-payment-gateway' ), 'error' );
		}
		wp_safe_redirect( $order->is_paid() ? $this->get_return_url( $order ) : wc_get_checkout_url() );
		exit;
	}

	/**
	 * Finds the order for a tran_id and confirms it matches what we stored.
	 *
	 * @param string $tran_id Transaction ID.
	 * @return WC_Order|null
	 */
	private function find_order( $tran_id ) {
		$tran_id = (string) $tran_id;
		if ( ! preg_match( '/^(\d+)-[A-Za-z0-9]+$/', $tran_id, $m ) ) {
			return null;
		}
		$order = wc_get_order( (int) $m[1] );
		if ( ! $order || $order->get_payment_method() !== $this->id ) {
			return null;
		}
		$stored = (string) $order->get_meta( self::META_TRAN_ID );
		return ( '' !== $stored && hash_equals( $stored, $tran_id ) ) ? $order : null;
	}

	/**
	 * Server-side validation. Marks the order paid only if every check passes.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $val_id Validation ID.
	 * @return bool True if the order is (now) paid.
	 */
	private function verify_and_complete( WC_Order $order, $val_id ) {
		if ( $order->is_paid() ) {
			return true; // Already handled (IPN and redirect can both arrive).
		}
		if ( '' === (string) $val_id ) {
			return false;
		}

		$result = $this->api()->validate( $val_id );
		if ( is_wp_error( $result ) ) {
			$this->log( sprintf( 'Validation API error for order %d: %s', $order->get_id(), $result->get_error_message() ), 'error' );
			return false;
		}

		$status   = $result['status'] ?? '';
		$tran_id  = (string) ( $result['tran_id'] ?? '' );
		$currency = strtoupper( (string) ( $result['currency_type'] ?? '' ) );
		$amount   = (float) ( $result['currency_amount'] ?? 0 );
		$expected = (float) $order->get_total();

		$checks = array(
			'status'   => in_array( $status, array( 'VALID', 'VALIDATED' ), true ),
			'tran_id'  => hash_equals( (string) $order->get_meta( self::META_TRAN_ID ), $tran_id ),
			'currency' => $currency === strtoupper( $order->get_currency() ),
			'amount'   => abs( $amount - $expected ) < 0.01,
		);

		$failed = array_keys( array_filter( $checks, fn( $ok ) => ! $ok ) );
		if ( $failed ) {
			$this->log( sprintf( 'Validation failed for order %d (%s). Status: %s', $order->get_id(), implode( ', ', $failed ), $status ), 'error' );
			$order->add_order_note( sprintf( 'SSLCommerz validation failed: %s. Payment NOT accepted.', implode( ', ', $failed ) ) );
			return false;
		}

		$order->update_meta_data( self::META_VAL_ID, sanitize_text_field( $val_id ) );

		if ( '1' === (string) ( $result['risk_level'] ?? '0' ) ) {
			$order->update_status( 'on-hold', __( 'SSLCommerz marked this payment as risky. Verify the customer before fulfilling.', 'bd-payment-gateway' ) );
			$this->log( sprintf( 'Order %d put on hold (risky payment).', $order->get_id() ), 'warning' );
			return true;
		}

		$order->payment_complete( sanitize_text_field( $result['bank_tran_id'] ?? '' ) );
		$order->add_order_note(
			sprintf(
				'SSLCommerz payment validated (%s). Card type: %s.',
				$this->testmode ? 'sandbox' : 'live',
				sanitize_text_field( $result['card_type'] ?? 'n/a' )
			)
		);
		$this->log( sprintf( 'Order %d paid and validated.', $order->get_id() ) );
		return true;
	}

	/**
	 * Records a failed / cancelled attempt without touching paid orders.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $status SSLCommerz status.
	 */
	private function mark_unpaid( WC_Order $order, $status ) {
		if ( $order->is_paid() ) {
			return;
		}
		$status = sanitize_text_field( $status );
		if ( 'CANCELLED' === $status ) {
			// Keep the order pending so the customer can try again.
			$order->add_order_note( __( 'Customer cancelled the SSLCommerz payment.', 'bd-payment-gateway' ) );
		} elseif ( ! $order->has_status( 'failed' ) ) {
			/* translators: %s: SSLCommerz status */
			$order->update_status( 'failed', sprintf( __( 'SSLCommerz payment not completed (status: %s).', 'bd-payment-gateway' ), $status ) );
		}
		$this->log( sprintf( 'Order %d payment status: %s', $order->get_id(), $status ) );
	}
}
