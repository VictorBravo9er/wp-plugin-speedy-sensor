<?php
/**
 * admin-post handlers for every mutation the plugin performs.
 *
 * All of them are deliberately server-rendered form posts rather than fetch
 * calls: the screens stay fully functional with JavaScript disabled, and the
 * nonce and capability checks sit in one auditable place.
 *
 * The order inside every handler is fixed: unslash, verify nonce, verify
 * capability, sanitise, act.
 *
 * @package Speedy_Sensor
 */

namespace Speedy_Sensor\Admin;

use Speedy_Sensor\Integration\ServiceRequestService;
use Speedy_Sensor\Integration\WebVitalsService;
use Speedy_Sensor\Scanner\Cron;
use Speedy_Sensor\Scanner\ScanLock;
use Speedy_Sensor\Scanner\ScanRunner;
use Speedy_Sensor\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Handles the plugin's form submissions.
 */
final class Actions {

	const PAGE_DASHBOARD = 'speedy-sensor';
	const PAGE_SCAN      = 'speedy-sensor-scan';
	const PAGE_DATABASE  = 'speedy-sensor-database';
	const PAGE_SETTINGS  = 'speedy-sensor-settings';

	const NONCE_SETTINGS  = 'speedy_sensor_settings';
	const NONCE_SCAN      = 'speedy_sensor_scan';
	const NONCE_VITALS    = 'speedy_sensor_vitals';
	const NONCE_REQUEST   = 'speedy_sensor_request';
	const NONCE_CLEARLOCK = 'speedy_sensor_clear_lock';

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
	 * Registers every handler.
	 *
	 * @param Settings|null $settings Settings store.
	 * @return void
	 */
	public static function init( $settings = null ) {
		$self = new self( $settings );

		add_action( 'admin_post_speedy_sensor_save_settings', array( $self, 'handle_save_settings' ) );
		add_action( 'admin_post_speedy_sensor_run_scan', array( $self, 'handle_run_scan' ) );
		add_action( 'admin_post_speedy_sensor_refresh_vitals', array( $self, 'handle_refresh_vitals' ) );
		add_action( 'admin_post_speedy_sensor_service_request', array( $self, 'handle_service_request' ) );
		add_action( 'admin_post_speedy_sensor_clear_lock', array( $self, 'handle_clear_lock' ) );
	}

	/**
	 * Saves the settings form.
	 *
	 * @return void
	 */
	public function handle_save_settings() {
		$raw = $this->read_post();

		$this->authorize( self::NONCE_SETTINGS );

		$input = isset( $raw['speedy_sensor'] ) && is_array( $raw['speedy_sensor'] )
			? $raw['speedy_sensor']
			: array();

		// An empty key field means "keep the stored key". The raw value is
		// never rendered back into the form.
		if ( isset( $input['api_key'] ) && '' === trim( (string) $input['api_key'] ) ) {
			unset( $input['api_key'] );
		}

		$result = $this->settings->save( $input );

		if ( is_wp_error( $result ) ) {
			$this->redirect(
				self::PAGE_SETTINGS,
				array(
					'message' => 'error',
					'code'    => $result->get_error_code(),
				)
			);
		}

		// The schedule encodes the interval setting, so it must be rebuilt.
		Cron::schedule( $this->settings );
		WebVitalsService::reschedule();

		$this->redirect( self::PAGE_SETTINGS, array( 'message' => 'saved' ) );
	}

	/**
	 * Runs one scan pass from the admin.
	 *
	 * @return void
	 */
	public function handle_run_scan() {
		$this->authorize( self::NONCE_SCAN );

		$result = ( new ScanRunner( $this->settings ) )->run();

		$status = isset( $result['status'] ) ? (string) $result['status'] : 'failed';

		$this->redirect(
			self::PAGE_SCAN,
			array(
				'message' => ( 'complete' === $status ) ? 'scan-complete' : 'scan-' . $status,
			)
		);
	}

	/**
	 * Pulls Web Vitals from Speedy.
	 *
	 * @return void
	 */
	public function handle_refresh_vitals() {
		$this->authorize( self::NONCE_VITALS );

		$service = new WebVitalsService( $this->settings );
		$result  = $service->refresh();

		if ( is_wp_error( $result ) ) {
			$this->redirect(
				self::PAGE_DASHBOARD,
				array(
					'message' => 'vitals-error',
					'code'    => $result->get_error_code(),
				)
			);
		}

		$this->redirect( self::PAGE_DASHBOARD, array( 'message' => 'vitals-refreshed' ) );
	}

	/**
	 * Submits an optimisation service request.
	 *
	 * @return void
	 */
	public function handle_service_request() {
		$raw = $this->read_post();

		$this->authorize( self::NONCE_REQUEST );

		$note = isset( $raw['note'] ) ? sanitize_text_field( (string) $raw['note'] ) : '';

		$result = ( new ServiceRequestService( $this->settings ) )->submit(
			array( 'note' => substr( $note, 0, 1000 ) )
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect(
				self::PAGE_DASHBOARD,
				array(
					'message' => 'request-error',
					'code'    => $result->get_error_code(),
				)
			);
		}

		$this->redirect( self::PAGE_DASHBOARD, array( 'message' => 'request-sent' ) );
	}

	/**
	 * Releases a scan lock held by a scan that died mid-pass.
	 *
	 * @return void
	 */
	public function handle_clear_lock() {
		$this->authorize( self::NONCE_CLEARLOCK );

		ScanLock::force_release();

		$this->redirect( self::PAGE_SCAN, array( 'message' => 'lock-cleared' ) );
	}

	/**
	 * Reads the unslashed POST body.
	 *
	 * @return array
	 */
	private function read_post() {
		if ( ! isset( $_POST ) || ! is_array( $_POST ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return array();
		}

		return wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}

	/**
	 * Verifies the nonce, then the capability.
	 *
	 * wp_die() terminates, so nothing below this call can run unverified.
	 *
	 * @param string $action Nonce action.
	 * @return void
	 */
	private function authorize( $action ) {
		$nonce = isset( $_POST['_wpnonce'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: '';

		if ( ! wp_verify_nonce( $nonce, $action ) ) {
			wp_die(
				esc_html__( 'That request expired. Go back, reload the page and try again.', 'speedy-sensor' ),
				esc_html__( 'Security check failed', 'speedy-sensor' ),
				array( 'response' => 403 )
			);
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to manage Speedy Sensor settings.', 'speedy-sensor' ),
				esc_html__( 'Permission denied', 'speedy-sensor' ),
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * Redirects back to one of the plugin's own screens.
	 *
	 * The page slug comes from the class constants, never from request input,
	 * and wp_safe_redirect() refuses anything outside this host.
	 *
	 * @param string $page Page slug.
	 * @param array  $args Extra query arguments.
	 * @return void
	 */
	private function redirect( $page, array $args = array() ) {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) ) );

		exit;
	}
}