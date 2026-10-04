<?php
/**
 * Settings screen.
 *
 * One form, one option. The API key field is never populated with the stored
 * value: leaving it empty keeps the existing key, so the secret never travels
 * back to the browser.
 *
 * @package Speedy_Sensor
 */

namespace Speedy_Sensor\Admin\Pages;

use Speedy_Sensor\Admin\Actions;
use Speedy_Sensor\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the settings screen.
 */
final class SettingsPage {

	/**
	 * Renders the screen.
	 *
	 * @param Settings $settings Settings store.
	 * @return void
	 */
	public static function render( Settings $settings ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this screen.', 'speedy-sensor' ) );
		}

		echo '<div class="wrap speedy-wrap">';
		echo '<h1>' . esc_html__( 'Speedy Sensor settings', 'speedy-sensor' ) . '</h1>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="speedy-form">';
		wp_nonce_field( Actions::NONCE_SETTINGS );
		echo '<input type="hidden" name="action" value="speedy_sensor_save_settings" />';

		echo '<h2>' . esc_html__( 'Connection', 'speedy-sensor' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		// The stored key is never written back into the input. An empty field
		// means "keep what is stored", and the mask is shown as help text, so
		// submitting the form unchanged cannot overwrite the key with the mask.
		$key_note = __( 'Find this in your Speedy account. Leave blank to keep the key already stored.', 'speedy-sensor' );
		$masked   = $settings->masked_api_key();

		if ( '' !== $masked ) {
			$key_note .= ' ' . sprintf(
				/* translators: %s: masked API key, for example ********1234. */
				__( 'Currently stored: %s', 'speedy-sensor' ),
				$masked
			);
		}

		self::text_row(
			'api_key',
			__( 'API key', 'speedy-sensor' ),
			'password',
			$key_note,
			''
		);

		self::text_row(
			'site_key',
			__( 'Site key', 'speedy-sensor' ),
			'text',
			__( 'Identifies this website within your Speedy account.', 'speedy-sensor' ),
			(string) $settings->get( 'site_key', '' )
		);

		self::text_row(
			'api_base_url',
			__( 'Speedy API address', 'speedy-sensor' ),
			'url',
			__( 'Must be https on a speedy.site host.', 'speedy-sensor' ),
			(string) $settings->get( 'api_base_url', '' )
		);

		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'What runs in the background', 'speedy-sensor' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		self::checkbox_row(
			'scan_enabled',
			__( 'Scan plugins and the database', 'speedy-sensor' ),
			__( 'Measures which plugins and tables cost this site performance.', 'speedy-sensor' ),
			(bool) $settings->get( 'scan_enabled', true )
		);

		self::number_row(
			'scan_interval_days',
			__( 'Scan every', 'speedy-sensor' ),
			__( 'days', 'speedy-sensor' ),
			(int) $settings->get( 'scan_interval_days', 7 )
		);

		self::checkbox_row(
			'vitals_enabled',
			__( 'Pull Web Vitals from Speedy', 'speedy-sensor' ),
			__( 'Shows the last seven days of Core Web Vitals on the overview screen.', 'speedy-sensor' ),
			(bool) $settings->get( 'vitals_enabled', true )
		);

		self::number_row(
			'vitals_refresh_days',
			__( 'Refresh vitals every', 'speedy-sensor' ),
			__( 'days', 'speedy-sensor' ),
			(int) $settings->get( 'vitals_refresh_days', 1 )
		);

		self::checkbox_row(
			'service_requests_enabled',
			__( 'Allow optimisation requests', 'speedy-sensor' ),
			__( 'Lets you hand the scan findings to Speedy from the overview screen.', 'speedy-sensor' ),
			(bool) $settings->get( 'service_requests_enabled', true )
		);

		self::number_row(
			'retain_scans',
			__( 'Keep', 'speedy-sensor' ),
			__( 'completed scans', 'speedy-sensor' ),
			(int) $settings->get( 'retain_scans', 12 )
		);

		echo '</tbody></table>';

		submit_button( __( 'Save settings', 'speedy-sensor' ) );

		echo '</form>';

		echo '<p class="speedy-muted">';
		esc_html_e( 'Speedy Sensor runs on WP-Cron and never during a page view, so nothing on this screen adds work to the front end of your site.', 'speedy-sensor' );
		echo '</p>';
	}

	/**
	 * Renders a text field row.
	 *
	 * The API key field is rendered empty on purpose. Populating it would send
	 * the stored secret back to the browser on every settings page view.
	 *
	 * @param string $name        Field name.
	 * @param string $label       Label text.
	 * @param string $type        Input type.
	 * @param string $description Help text.
	 * @param string $value       Current display value.
	 * @return void
	 */
	private static function text_row( $name, $label, $type, $description, $value ) {
		$id = 'speedy-sensor-' . $name;

		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="speedy_sensor[' . esc_attr( $name ) . ']" value="' . esc_attr( $value ) . '" class="regular-text" autocomplete="off" />';
		echo '<p class="description">' . esc_html( $description ) . '</p>';
		echo '</td></tr>';
	}

	/**
	 * Renders a checkbox row with an explicit false value.
	 *
	 * @param string $name        Field name.
	 * @param string $label       Label text.
	 * @param string $description Help text.
	 * @param bool   $checked     Current state.
	 * @return void
	 */
	private static function checkbox_row( $name, $label, $description, $checked ) {
		$id = 'speedy-sensor-' . $name;

		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';

		// An unchecked checkbox is not submitted, so the off value is explicit.
		echo '<input type="hidden" name="speedy_sensor[' . esc_attr( $name ) . ']" value="0" />';
		echo '<label for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="speedy_sensor[' . esc_attr( $name ) . ']" value="1" ' . checked( $checked, true, false ) . ' /> ' . esc_html( $label ) . '</label>';
		echo '<p class="description">' . esc_html( $description ) . '</p>';

		echo '</td></tr>';
	}

	/**
	 * Renders a number row.
	 *
	 * @param string $name    Field name.
	 * @param string $label   Label text.
	 * @param string $suffix  Unit shown after the input.
	 * @param int    $current Current value.
	 * @return void
	 */
	private static function number_row( $name, $label, $suffix, $current ) {
		$id  = 'speedy-sensor-' . $name;
		$max = ( 'retain_scans' === $name ) ? '52' : '90';

		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input type="number" min="1" max="' . esc_attr( $max ) . '" id="' . esc_attr( $id ) . '" name="speedy_sensor[' . esc_attr( $name ) . ']" value="' . esc_attr( (string) (int) $current ) . '" class="small-text" /> ';
		echo esc_html( $suffix );
		echo '</td></tr>';
	}
}
