<?php
/**
 * HTTP client for the Speedy API.
 *
 * Every outbound call in the plugin goes through here so that the allowlisted
 * base URL, the timeout, the TLS requirement and the error mapping are
 * enforced in exactly one place.
 *
 * The endpoint paths below are placeholders. They are collected as constants
 * so that swapping in the real contract is a one-line change per endpoint,
 * with no effect on callers.
 *
 * @package Speedy_Sensor
 */

namespace Speedy_Sensor\Integration;

use Speedy_Sensor\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Authenticated client for Speedy.site.
 */
final class ApiClient {

	/**
	 * Path for the Web Vitals report.
	 *
	 * Placeholder pending the published API contract.
	 */
	const PATH_WEB_VITALS = '/v1/sites/web-vitals';

	/**
	 * Path for submitting an optimisation service request.
	 *
	 * Placeholder pending the published API contract.
	 */
	const PATH_SERVICE_REQUEST = '/v1/sites/optimisation-requests';

	/**
	 * Seconds to wait for the upstream API.
	 */
	const TIMEOUT = 10;

	/**
	 * Settings store.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings|null $settings Settings store.
	 */
	public function __construct( $settings = null ) {
		$this->settings = $settings instanceof Settings ? $settings : new Settings();
	}

	/**
	 * Performs a GET request.
	 *
	 * @param string $path Endpoint path, relative to the base URL.
	 * @param array  $args Query arguments.
	 * @return array|\WP_Error Decoded body, or an error.
	 */
	public function get( $path, array $args = array() ) {
		return $this->request( 'GET', $path, $args );
	}

	/**
	 * Performs a POST request with a JSON body.
	 *
	 * @param string $path Endpoint path, relative to the base URL.
	 * @param array  $body Body payload.
	 * @return array|\WP_Error Decoded body, or an error.
	 */
	public function post( $path, array $body ) {
		return $this->request( 'POST', $path, $body );
	}

	/**
	 * Whether the plugin holds the credentials an API call needs.
	 *
	 * @return true|\WP_Error
	 */
	public function can_call() {
		if ( ! $this->settings->is_linked() ) {
			return new \WP_Error(
				'speedy_sensor_not_linked',
				__( 'Add your Speedy API key and site key in Speedy Sensor settings first.', 'speedy-sensor' )
			);
		}

		return true;
	}

	/**
	 * Issues the request and normalises the outcome.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Endpoint path.
	 * @param array  $body   Query string on GET, JSON body on POST.
	 * @return array|\WP_Error
	 */
	private function request( $method, $path, array $body ) {
		$ready = $this->can_call();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$base = (string) $this->settings->get( 'api_base_url', '' );

		$url = untrailingslashit( $base ) . '/' . ltrim( (string) $path, '/' );

		$args = array(
			'method'    => $method,
			'timeout'   => self::TIMEOUT,
			'redirection' => 0,
			'sslverify' => true,
			'headers'   => array(
				'Authorization' => 'Bearer ' . (string) $this->settings->get( 'api_key', '' ),
				'Accept'        => 'application/json',
				'Cache-Control' => 'no-cache',
			),
		);

		if ( 'GET' === $method ) {
			if ( ! empty( $body ) ) {
				$url = add_query_arg( $body, $url );
			}
		} else {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		/**
		 * Filters the arguments sent to the Speedy API.
		 *
		 * @param array  $args   Request arguments.
		 * @param string $method HTTP method.
		 * @param string $path   Endpoint path.
		 */
		$args = apply_filters( 'speedy_sensor_api_request_args', $args, $method, $path );

		// Redirection is disabled, so a 30x here means the upstream moved and
		// the stored base URL is stale.
		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'speedy_sensor_network_error',
				__( 'Speedy could not be reached. Check the site can make outbound HTTPS requests.', 'speedy-sensor' ),
				array( 'status' => $response->get_error_message() )
			);
		}

		return $this->handle_response( wp_remote_retrieve_response_code( $response ), $response );
	}

	/**
	 * Maps an HTTP status onto either a decoded body or an error.
	 *
	 * Upstream messages are never shown verbatim: they can echo the site key,
	 * and they are written for Speedy's own support tooling, not site owners.
	 *
	 * @param int    $code     HTTP status code.
	 * @param array  $response Raw response from the HTTP API.
	 * @return array|\WP_Error
	 */
	private function handle_response( $code, $response ) {
		$code = (int) $code;

		switch ( $code ) {
			case 401:
			case 403:
				return new \WP_Error(
					'speedy_sensor_auth_failed',
					__( 'Speedy rejected the API key for this site. Check the key and site key in settings.', 'speedy-sensor' )
				);

			case 404:
				return new \WP_Error(
					'speedy_sensor_not_found',
					__( 'Speedy has no record for this site yet. Add the website in your Speedy account first.', 'speedy-sensor' )
				);

			case 429:
				return new \WP_Error(
					'speedy_sensor_rate_limited',
					__( 'Speedy is rate limiting this site. Try again later.', 'speedy-sensor' )
				);

			case 301:
			case 302:
			case 307:
			case 308:
				return new \WP_Error(
					'speedy_sensor_moved',
					__( 'The Speedy API address has moved. Update it in Speedy Sensor settings.', 'speedy-sensor' )
				);
		}

		if ( $code < 200 || $code > 299 ) {
			return new \WP_Error(
				'speedy_sensor_api_error',
				__( 'Speedy returned an unexpected response. Check the site error log for details.', 'speedy-sensor' ),
				array( 'status' => $code )
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) ) {
			return new \WP_Error(
				'speedy_sensor_invalid_response',
				__( 'Speedy sent a response that could not be read.', 'speedy-sensor' )
			);
		}

		return $body;
	}
}