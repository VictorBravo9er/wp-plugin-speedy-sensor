<?php
/**
 * Admin notices.
 *
 * Two kinds: a result notice carrying the outcome of the form post that just
 * happened, and a contextual notice for the screen the admin is looking at.
 * Nothing is rendered on the frontend and nothing is shown to users who cannot
 * manage options.
 *
 * @package Speedy_Sensor
 */

namespace Speedy_Sensor\Admin;

use Speedy_Sensor\Db\Repositories\ScanRepository;
use Speedy_Sensor\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Renders notices inside wp-admin.
 */
final class Notices {

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
	 * Registers the hooks.
	 *
	 * @param Settings|null $settings Settings store.
	 * @return void
	 */
	public static function init( $settings = null ) {
		$self = new self( $settings );

		add_action( 'admin_notices', array( $self, 'render_result' ) );
		add_action( 'admin_notices', array( $self, 'render_contextual' ), 20 );
	}

	/**
	 * Renders the outcome of the last form post.
	 *
	 * @return void
	 */
	public function render_result() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$message = isset( $_GET['message'] ) ? sanitize_key( wp_unslash( $_GET['message'] ) ) : '';

		if ( '' === $message ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code = isset( $_GET['code'] ) ? sanitize_key( wp_unslash( $_GET['code'] ) ) : '';

		$map = self::messages();

		if ( isset( $map[ $message ] ) ) {
			$this->print_notice( $map[ $message ][0], $map[ $message ][1] );

			return;
		}

		// Unknown token: fall back to the error code if we recognise it.
		$errors = self::error_messages();

		if ( '' !== $code && isset( $errors[ $code ] ) ) {
			$this->print_notice( 'error', $errors[ $code ] );
		}
	}

	/**
	 * Renders state the admin should know about on the plugin screens.
	 *
	 * @return void
	 */
	public function render_contextual() {
		if ( ! current_user_can( 'manage_options' ) || ! $this->is_plugin_screen() ) {
			return;
		}

		if ( ! $this->settings->is_linked() ) {
			$this->print_notice(
				'info',
				__( 'Speedy Sensor is not connected yet. Add your API key and site key to pull Web Vitals and request optimisation.', 'speedy-sensor' )
			);

			return;
		}

		$scans = new ScanRepository();

		if ( $scans->get_latest( ScanRepository::STATUS_COMPLETE ) ) {
			return;
		}

		if ( $scans->get_resumable() ) {
			$this->print_notice(
				'info',
				__( 'The first scan is still in progress. Speedy Sensor continues it in the background, so you can leave this page.', 'speedy-sensor' )
			);

			return;
		}

		$this->print_notice(
			'info',
			__( 'No scan results yet. Run a scan from the Scan & Plugins screen to see which plugins and tables are costing this site performance.', 'speedy-sensor' )
		);
	}

	/**
	 * Whether the current screen belongs to this plugin.
	 *
	 * @return bool
	 */
	private function is_plugin_screen() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen identifier; nothing is written or deleted here.
		if ( ! isset( $_GET['page'] ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = sanitize_key( wp_unslash( $_GET['page'] ) );

		return in_array(
			$page,
			array( Actions::PAGE_DASHBOARD, Actions::PAGE_SCAN, Actions::PAGE_DATABASE, Actions::PAGE_SETTINGS ),
			true
		);
	}

	/**
	 * Result messages keyed by the redirect token.
	 *
	 * @return array
	 */
	private static function messages() {
		return array(
			'saved'            => array( 'success', __( 'Settings saved.', 'speedy-sensor' ) ),
			'scan-complete'    => array( 'success', __( 'Scan complete. Results are shown below.', 'speedy-sensor' ) ),
			'scan-partial'     => array( 'info', __( 'Scan paused after its time budget. It continues automatically on the next background run.', 'speedy-sensor' ) ),
			'scan-locked'      => array( 'info', __( 'A scan is already running. Try again once it finishes.', 'speedy-sensor' ) ),
			'scan-disabled'    => array( 'info', __( 'Scanning is switched off in settings.', 'speedy-sensor' ) ),
			'scan-failed'      => array( 'error', __( 'The scan could not finish. Check the site error log.', 'speedy-sensor' ) ),
			'vitals-refreshed' => array( 'success', __( 'Web Vitals refreshed from Speedy.', 'speedy-sensor' ) ),
			'request-sent'     => array( 'success', __( 'Request sent. Speedy will review your site and follow up by email.', 'speedy-sensor' ) ),
			'lock-cleared'     => array( 'success', __( 'Scan lock released.', 'speedy-sensor' ) ),
			'error'            => array( 'error', __( 'Something was rejected. See the message below.', 'speedy-sensor' ) ),
		);
	}

	/**
	 * Friendly text for the error codes this plugin returns.
	 *
	 * The upstream message is deliberately not shown: it can echo identifiers
	 * and is written for Speedy's own support tooling.
	 *
	 * @return array
	 */
	private static function error_messages() {
		return array(
			'speedy_sensor_bad_api_url'       => __( 'That API address was not accepted. Use an https address on a speedy.site host.', 'speedy-sensor' ),
			'speedy_sensor_unknown_settings'  => __( 'One or more settings were not recognised and were not saved.', 'speedy-sensor' ),
			'speedy_sensor_not_linked'        => __( 'Add your API key and site key in settings first.', 'speedy-sensor' ),
			'speedy_sensor_auth_failed'       => __( 'Speedy rejected the API key for this site.', 'speedy-sensor' ),
			'speedy_sensor_not_found'         => __( 'Speedy has no record of this website yet. Add it in your Speedy account.', 'speedy-sensor' ),
			'speedy_sensor_rate_limited'      => __( 'Speedy is rate limiting this site. Try again later.', 'speedy-sensor' ),
			'speedy_sensor_moved'             => __( 'The Speedy API address has moved. Update it in settings.', 'speedy-sensor' ),
			'speedy_sensor_api_error'         => __( 'Speedy returned an unexpected response.', 'speedy-sensor' ),
			'speedy_sensor_invalid_response'  => __( 'Speedy sent a response that could not be read.', 'speedy-sensor' ),
			'speedy_sensor_network_error'     => __( 'Speedy could not be reached. Check outbound HTTPS from this server.', 'speedy-sensor' ),
			'speedy_sensor_vitals_disabled'   => __( 'Web Vitals reporting is switched off in settings.', 'speedy-sensor' ),
			'speedy_sensor_vitals_empty'      => __( 'Speedy has no Web Vitals for this site yet. Data appears once real visitors have been measured.', 'speedy-sensor' ),
			'speedy_sensor_requests_disabled' => __( 'Service requests are switched off in settings.', 'speedy-sensor' ),
			'speedy_sensor_no_scan'           => __( 'Run a scan first so there are findings to send.', 'speedy-sensor' ),
		);
	}

	/**
	 * Echoes a dismissible admin notice.
	 *
	 * @param string $level   One of success, info, warning, error.
	 * @param string $message Already-translated plain text.
	 * @return void
	 */
	private function print_notice( $level, $message ) {
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $level ),
			esc_html( $message )
		);
	}
}
