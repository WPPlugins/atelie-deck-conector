<?php
/**
 * Signs and sends requests to the Ateliê.deck API (docs/CONTRATO-AGENTE.md).
 *
 * Signature = hex HMAC-SHA256, keyed with the site secret, of "<timestamp>\n<METHOD>\n<path>\n<sha256 hex of the raw body>".
 */
class Atelie_WPM_Client {

	const SYNC_PATH      = '/api/v1/agent/sync';
	const HEARTBEAT_PATH = '/api/v1/agent/heartbeat';

	/** @var string */
	private $base_url;
	/** @var string */
	private $site_id;
	/** @var string */
	private $secret;

	public function __construct( $base_url, $site_id, $secret ) {
		$this->base_url = rtrim( (string) $base_url, '/' );
		$this->site_id  = (string) $site_id;
		$this->secret   = (string) $secret;
	}

	/** Pure function, shared with the tests that check the API accepts exactly this signature. */
	public static function signature( $secret, $timestamp, $method, $path, $body ) {
		return hash_hmac( 'sha256', implode( "\n", array( $timestamp, strtoupper( $method ), $path, hash( 'sha256', $body ) ) ), $secret );
	}

	/** True when every setting needed to talk to the API is present and the address is HTTPS (or HTTP explicitly allowed for local work). */
	public function is_configured() {
		if ( '' === $this->base_url || '' === $this->site_id || '' === $this->secret ) {
			return false;
		}
		$scheme = wp_parse_url( $this->base_url, PHP_URL_SCHEME );

		return 'https' === $scheme || ( 'http' === $scheme && defined( 'ATELIE_WPM_ALLOW_HTTP' ) && ATELIE_WPM_ALLOW_HTTP );
	}

	/** @return array{ok: bool, status: int, message: string, data: array<string, mixed>} */
	public function post( $path, array $payload ) {
		if ( ! $this->is_configured() ) {
			return array( 'ok' => false, 'status' => 0, 'message' => esc_html__( 'Set the address (HTTPS), the installation ID and the secret.', 'atelie-deck-conector' ), 'data' => array() );
		}

		$body      = wp_json_encode( $payload );
		$timestamp = (string) time();

		$response = wp_remote_post(
			$this->base_url . $path,
			array(
				'timeout'     => 20,
				'redirection' => 0, // a redirect would resend the signed body somewhere else
				'sslverify'   => true,
				'headers'     => array(
					'Content-Type'       => 'application/json',
					'Accept'             => 'application/json',
					'X-Atelie-Site'      => $this->site_id,
					'X-Atelie-Timestamp' => $timestamp,
					'X-Atelie-Signature' => self::signature( $this->secret, $timestamp, 'POST', $path, $body ),
				),
				'body'        => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'status' => 0, 'message' => $response->get_error_message(), 'data' => array() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$json   = json_decode( wp_remote_retrieve_body( $response ), true );
		$json   = is_array( $json ) ? $json : array();

		return array(
			'ok'      => $status >= 200 && $status < 300,
			'status'  => $status,
			'message' => 401 === $status ? esc_html__( 'Credentials refused (wrong ID or secret, renewed secret, or the server clock is off).', 'atelie-deck-conector' ) : (string) ( $json['message'] ?? 'HTTP ' . $status ),
			'data'    => is_array( $json['data'] ?? null ) ? $json['data'] : array(),
		);
	}
}
