<?php
/**
 * Optimisation service requests.
 *
 * Hands a diagnostic summary to Speedy so a human can pick the job up. The
 * payload is built from our own scan, so it carries no credentials and no
 * visitor data.
 *
 * The endpoint and body shape are placeholders pending the published API
 * contract.
 *
 * @package Speedy_Sensor
 */

namespace Speedy_Sensor\Integration;

use Speedy_Sensor\Db\Repositories\DbMetricRepository;
use Speedy_Sensor\Db\Repositories\PluginMetricRepository;
use Speedy_Sensor\Db\Repositories\ScanRepository;
use Speedy_Sensor\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Submits optimisation requests to Speedy.
 */
final class ServiceRequestService {

	/**
	 * Offenders included in the payload.
	 */
	const MAX_OFFENDERS = 5;

	/**
	 * Settings store.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * API client.
	 *
	 * @var ApiClient
	 */
	private $client;

	/**
	 * Constructor.
	 *
	 * @param Settings|null  $settings Settings store.
	 * @param ApiClient|null $client   API client.
	 */
	public function __construct( $settings = null, $client = null ) {
		$this->settings = $settings instanceof Settings ? $settings : new Settings();
		$this->client   = $client instanceof ApiClient ? $client : new ApiClient( $this->settings );
	}

	/**
	 * Submits a request for the site's latest scan.
	 *
	 * @param array $context Optional caller context, for example a free-text note.
	 * @return array|\WP_Error
	 */
	public function submit( array $context = array() ) {
		if ( ! $this->settings->get( 'service_requests_enabled', true ) ) {
			return new \WP_Error(
				'speedy_sensor_requests_disabled',
				__( 'Service requests are switched off in Speedy Sensor settings.', 'speedy-sensor' )
			);
		}

		$scan = ( new ScanRepository() )->get_latest( ScanRepository::STATUS_COMPLETE );

		if ( ! $scan ) {
			return new \WP_Error(
				'speedy_sensor_no_scan',
				__( 'Run a scan before requesting an optimisation so there are findings to send.', 'speedy-sensor' )
			);
		}

		$payload = $this->build_payload( $scan, $context );

		/**
		 * Filters the optimisation request payload before it is sent.
		 *
		 * @param array $payload Request body.
		 * @param array $scan    Scan the payload was built from.
		 */
		$payload = apply_filters( 'speedy_sensor_service_request_payload', $payload, $scan );

		$result = $this->client->post( ApiClient::PATH_SERVICE_REQUEST, $payload );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'sent'     => true,
			'scan_id'  => (int) $scan['id'],
			'response' => $result,
		);
	}

	/**
	 * Builds the request body from a completed scan.
	 *
	 * @param array $scan    Scan row.
	 * @param array $context Caller context.
	 * @return array
	 */
	public function build_payload( array $scan, array $context = array() ) {
		$scan_id = (int) $scan['id'];

		$payload = array(
			'site_key'  => (string) $this->settings->get( 'site_key', '' ),
			'site_url'  => home_url(),
			'scan'      => array(
				'id'            => $scan_id,
				'started_at'    => (string) $scan['started_at'],
				'plugins'       => (int) $scan['plugins_total'],
				'issues_found'  => (int) $scan['issues_found'],
				'duration_ms'   => (int) $scan['duration_ms'],
			),
			'environment' => array(
				'wp_version'  => get_bloginfo( 'version' ),
				'php_version' => PHP_VERSION,
				'multisite'   => is_multisite(),
			),
			'database'   => ( new DbMetricRepository() )->get_by_scan( $scan_id ),
			'offenders'  => $this->offenders( $scan_id ),
		);

		$note = isset( $context['note'] ) ? sanitize_text_field( (string) $context['note'] ) : '';

		if ( '' !== $note ) {
			$payload['note'] = $note;
		}

		return $payload;
	}

	/**
	 * Highest-scoring plugins from a scan, for the Speedy team to look at first.
	 *
	 * @param int $scan_id Scan id.
	 * @return array
	 */
	private function offenders( $scan_id ) {
		$rows = ( new PluginMetricRepository() )->get_by_scan( $scan_id, self::MAX_OFFENDERS, 0, 'score' );

		$offenders = array();

		foreach ( $rows as $row ) {
			if ( ! empty( $row['findings'] ) ) {
				$offenders[] = array(
					'plugin'      => $row['plugin_file'],
					'name'        => $row['plugin_name'],
					'score'       => (int) $row['score'],
					'autoload'    => (int) $row['autoload_bytes'],
					'transients'  => (int) $row['transients_count'],
					'table_bytes' => (int) $row['table_bytes'],
					'findings'    => array_column( $row['findings'], 'code' ),
				);
			}
		}

		return $offenders;
	}
}