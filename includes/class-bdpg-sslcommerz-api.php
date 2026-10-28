<?php
/**
 * Thin client for the SSLCommerz v4 APIs.
 *
 * Endpoints (from https://developer.sslcommerz.com/doc/v4/):
 * - Create session:  POST {base}/gwprocess/v4/api.php
 * - Order validation: GET {base}/validator/api/validationserverAPI.php
 * - Transaction query: GET {base}/validator/api/merchantTransIDvalidationAPI.php
 *
 * @package BDPaymentGateway
 */

defined( 'ABSPATH' ) || exit;

/**
 * SSLCommerz API client.
 */
class BDPG_SSLCommerz_API {

	const SANDBOX_BASE = 'https://sandbox.sslcommerz.com';
	const LIVE_BASE    = 'https://securepay.sslcommerz.com';

	/**
	 * Store ID.
	 *
	 * @var string
	 */
	private $store_id;

	/**
	 * Store password (never logged).
	 *
	 * @var string
	 */
	private $store_passwd;

	/**
	 * Whether to use the sandbox.
	 *
	 * @var bool
	 */
	private $sandbox;

	/**
	 * Constructor.
	 *
	 * @param string $store_id     Store ID.
	 * @param string $store_passwd Store password.
	 * @param bool   $sandbox      Use sandbox.
	 */
	public function __construct( $store_id, $store_passwd, $sandbox = true ) {
		$this->store_id     = (string) $store_id;
		$this->store_passwd = (string) $store_passwd;
		$this->sandbox      = (bool) $sandbox;
	}

	/**
	 * Base URL for the current mode.
	 *
	 * @return string
	 */
	public function base_url() {
		return $this->sandbox ? self::SANDBOX_BASE : self::LIVE_BASE;
	}

	/**
	 * Create a payment session.
	 *
	 * @param array $params Request parameters (without credentials).
	 * @return array|WP_Error Decoded response or error.
	 */
	public function create_session( array $params ) {
		$body = array_merge(
			$params,
			array(
				'store_id'     => $this->store_id,
				'store_passwd' => $this->store_passwd,
			)
		);

		$response = wp_remote_post(
			$this->base_url() . '/gwprocess/v4/api.php',
			array(
				'timeout' => 30,
				'body'    => $body,
			)
		);

		$data = $this->decode( $response );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( ( $data['status'] ?? '' ) !== 'SUCCESS' || empty( $data['GatewayPageURL'] ) ) {
			return new WP_Error( 'bdpg_session_failed', $data['failedreason'] ?? 'Could not create payment session.' );
		}
		return $data;
	}

	/**
	 * Validate a transaction by val_id with the Order Validation API.
	 *
	 * @param string $val_id Validation ID from SSLCommerz.
	 * @return array|WP_Error Decoded response or error.
	 */
	public function validate( $val_id ) {
		$url = add_query_arg(
			array(
				'val_id'       => rawurlencode( $val_id ),
				'store_id'     => rawurlencode( $this->store_id ),
				'store_passwd' => rawurlencode( $this->store_passwd ),
				'v'            => 1,
				'format'       => 'json',
			),
			$this->base_url() . '/validator/api/validationserverAPI.php'
		);

		return $this->decode( wp_remote_get( $url, array( 'timeout' => 30 ) ) );
	}

	/**
	 * Advisory check of the IPN verify_sign hash, following the algorithm used by
	 * SSLCommerz's official PHP library: md5 of the sorted key=value pairs listed in
	 * verify_key plus md5(store_passwd). The Order Validation API remains the real gate.
	 *
	 * @param array $post Unslashed POST data.
	 * @return bool
	 */
	public function verify_ipn_hash( array $post ) {
		if ( empty( $post['verify_sign'] ) || empty( $post['verify_key'] ) ) {
			return false;
		}

		$data = array();
		foreach ( explode( ',', $post['verify_key'] ) as $key ) {
			$data[ $key ] = isset( $post[ $key ] ) ? $post[ $key ] : '';
		}
		$data['store_passwd'] = md5( $this->store_passwd );
		ksort( $data );

		$pairs = array();
		foreach ( $data as $key => $value ) {
			$pairs[] = $key . '=' . $value;
		}

		return hash_equals( md5( implode( '&', $pairs ) ), (string) $post['verify_sign'] );
	}

	/**
	 * Decode a wp_remote_* response.
	 *
	 * @param array|WP_Error $response Response.
	 * @return array|WP_Error
	 */
	private function decode( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $code ) {
			return new WP_Error( 'bdpg_http_error', 'SSLCommerz returned HTTP ' . $code );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'bdpg_bad_json', 'Unexpected response from SSLCommerz.' );
		}
		return $data;
	}
}
