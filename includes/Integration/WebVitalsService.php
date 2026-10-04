<?php
/**
 * Web Vitals pipeline.
 *
 * Speedy measures Core Web Vitals at the edge. This class pulls that report
 * into the local cache so the dashboard renders from the database and never
 * waits on the upstream API, and schedules the refresh on cron.
 *
 * The response shape below is an assumption until the Speedy API contract is
 * published. The normaliser is written to be tolerant so that a shape change
 * degrades to fewer cached rows rather than a fatal.
 *
 * @package Speedy_Sensor
 */

namespace Speedy_Sensor\Integration;

use Speedy_Sensor\Db\Repositories\WebVitalRepository;
use Speedy_Sensor\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches and caches Web Vitals from Speedy.
 */
final class WebVitalsService {

	/**
	 * Cron hook for the periodic refresh.
	 */
	const CRON_HOOK = 'speedy_sensor_refresh_vitals';

	/**
	 * Option recording the last successful refresh.
	 */
	const LAST_REFRESH_OPTION = 'speedy_sensor_vitals_last_refresh';

	/**
	 * Days shown on the dashboard.
	 */
	const WINDOW_DAYS = 7;

	/**
	 * Settings store.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Cache repository.
	 *
	 * @var WebVitalRepository
	 */
	private $repository;

	/**
	 * API client.
	 *
	 * @var ApiClient
	 */
	private $client;

	/**
	 * Constructor.
	 *
	 * @param Settings|null          $settings   Settings store.
	 * @param WebVitalRepository|null $repository Cache.
	 * @param ApiClient|null         $client     API client.
	 */
	public function __construct( $settings = null, $repository = null, $client = null ) {
		$this->settings   = $settings instanceof Settings ? $settings : new Settings();
		$this->repository = $repository instanceof WebVitalRepository ? $repository : new WebVitalRepository();
		$this->client     = $client instanceof ApiClient ? $client : new ApiClient( $this->settings );
	}

	/**
	 * Registers the refresh schedule.
	 *
	 * @param Settings $settings Settings store.
	 * @return void
	 */
	public static function init( Settings $settings ) {
		add_action( self::CRON_HOOK, array( __CLASS__, 'refresh_on_cron' ) );
		add_action( 'speedy_sensor_settings_saved', array( __CLASS__, 'reschedule' ) );
	}

	/**
	 * Cron entry point. Uses its own settings instance.
	 *
	 * @return void
	 */
	public static function refresh_on_cron() {
		$service = new self( new Settings() );
		$service->refresh();
	}

	/**
	 * Re-arms the refresh after a settings change.
	 *
	 * @return void
	 */
	public static function reschedule() {
		self::unschedule();

		$settings = new Settings();

		if ( ! $settings->get( 'vitals_enabled', true ) || ! $settings->is_linked() ) {
			return;
		}

		wp_schedule_single_event( time() + 60, self::CRON_HOOK );
	}

	/**
	 * Removes the refresh schedule.
	 *
	 * @return void
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Fetches the report and writes it to the cache.
	 *
	 * @param string $field Device field.
	 * @param string $route Route pattern.
	 * @return array|\WP_Error Row count written, or the reason it failed.
	 */
	public function refresh( $field = 'mobile', $route = '/' ) {
		if ( ! $this->settings->get( 'vitals_enabled', true ) ) {
			return new \WP_Error(
				'speedy_sensor_vitals_disabled',
				__( 'Web Vitals reporting is switched off in Speedy Sensor settings.', 'speedy-sensor' )
			);
		}

		$body = $this->client->get(
			ApiClient::PATH_WEB_VITALS,
			array(
				'site'  => (string) $this->settings->get( 'site_key', '' ),
				'field' => $field,
				'days'  => self::WINDOW_DAYS,
			)
		);

		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$rows = $this->normalise( $body );

		if ( empty( $rows ) ) {
			return new \WP_Error(
				'speedy_sensor_vitals_empty',
				__( 'Speedy returned no Web Vitals data for this site yet. Data appears once real visitors have been measured.', 'speedy-sensor' )
			);
		}

		$written = $this->repository->upsert( $rows );

		update_option( self::LAST_REFRESH_OPTION, time(), false );

		$this->schedule_next();

		return array(
			'written' => $written,
			'rows'    => count( $rows ),
		);
	}

	/**
	 * Turns an API body into cache rows.
	 *
	 * Tolerates the field aliases the upstream API may use, so an unexpected
	 * payload drops rows rather than corrupting the cache.
	 *
	 * @param array $body Decoded API body.
	 * @return array
	 */
	public function normalise( array $body ) {
		$items = array();

		if ( isset( $body['data'] ) && is_array( $body['data'] ) ) {
			$items = $body['data'];
		} elseif ( isset( $body['results'] ) && is_array( $body['results'] ) ) {
			$items = $body['results'];
		} elseif ( array_keys( $body ) === range( 0, count( $body ) - 1 ) ) {
			$items = $body;
		}

		$rows = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$row = array(
				'recorded_on' => $this->first_value( $item, array( 'date', 'recorded_on', 'day' ) ),
				'field'       => $this->first_value( $item, array( 'field', 'device', 'form_factor' ) ),
				'route'       => $this->first_value( $item, array( 'route', 'path', 'page' ) ),
				'lcp_ms'      => $this->first_value( $item, array( 'lcp_ms', 'lcp' ) ),
				'cls'         => $this->first_value( $item, array( 'cls' ) ),
				'inp_ms'      => $this->first_value( $item, array( 'inp_ms', 'inp' ) ),
				'ttfb_ms'     => $this->first_value( $item, array( 'ttfb_ms', 'ttfb' ) ),
			);

			// A row without a date cannot be bucketed, so it is dropped.
			if ( ! is_string( $row['recorded_on'] ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $row['recorded_on'] ) ) {
				continue;
			}

			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * Returns the first present key from an alias list.
	 *
	 * @param array $item   Source item.
	 * @param array $keys   Candidate keys, in preference order.
	 * @return mixed|null
	 */
	private function first_value( array $item, array $keys ) {
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $item ) ) {
				return $item[ $key ];
			}
		}

		return null;
	}

	/**
	 * Queues the next refresh according to the configured interval.
	 *
	 * A single event that re-arms itself, rather than a recurring schedule,
	 * because the interval is user configurable and not one of WP-Cron's
	 * built-in periods.
	 *
	 * @return void
	 */
	public function schedule_next() {
		$days = Settings::clamp( $this->settings->get( 'vitals_refresh_days', 1 ), 1, 90, 1 );

		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_schedule_single_event( time() + ( $days * DAY_IN_SECONDS ), self::CRON_HOOK );
	}

	/**
	 * Whether enough time has passed to justify another upstream call.
	 *
	 * @return bool
	 */
	public function is_due() {
		$last = (int) get_option( self::LAST_REFRESH_OPTION, 0 );

		if ( 0 === $last ) {
			return true;
		}

		$days = Settings::clamp( $this->settings->get( 'vitals_refresh_days', 1 ), 1, 90, 1 );

		return ( time() - $last ) >= ( $days * DAY_IN_SECONDS );
	}

	/**
	 * Returns the cached series for the dashboard.
	 *
	 * @param int    $days  Days of history.
	 * @param string $field Device field.
	 * @param string $route Route pattern.
	 * @return array
	 */
	public function series( $days = self::WINDOW_DAYS, $field = 'mobile', $route = '/' ) {
		return $this->repository->get_series( $days, $field, $route );
	}

	/**
	 * Returns the most recent cached entry.
	 *
	 * @param string $field Device field.
	 * @param string $route Route pattern.
	 * @return array|null
	 */
	public function latest( $field = 'mobile', $route = '/' ) {
		return $this->repository->get_latest( $field, $route );
	}

	/**
	 * Timestamp of the last successful refresh, zero when never.
	 *
	 * @return int
	 */
	public function last_refresh() {
		return (int) get_option( self::LAST_REFRESH_OPTION, 0 );
	}
}