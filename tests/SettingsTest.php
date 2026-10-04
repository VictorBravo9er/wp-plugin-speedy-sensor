<?php
/**
 * Settings validation tests.
 *
 * @package Speedy_Sensor
 */

namespace Speedy_Sensor\Tests;

use Speedy_Sensor\Settings\Settings;
use WP_UnitTestCase;

/**
 * Covers the settings store.
 *
 * @covers \Speedy_Sensor\Settings\Settings
 */
final class SettingsTest extends WP_UnitTestCase {

	/**
	 * Settings store under test.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Sets up a fresh store.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->settings = new Settings();
		$this->settings->delete();
		$this->settings = new Settings();
	}

	/**
	 * Remove stored settings between tests.
	 *
	 * @return void
	 */
	public function tear_down() {
		$this->settings->delete();

		parent::tear_down();
	}

	/**
	 * An untouched store returns the documented defaults.
	 *
	 * @return void
	 */
	public function test_defaults_are_returned_when_nothing_is_stored() {
		$all = $this->settings->all();

		$this->assertSame( '', $all['api_key'] );
		$this->assertSame( 'https://api.speedy.site', $all['api_base_url'] );
		$this->assertTrue( $all['scan_enabled'] );
		$this->assertSame( 7, $all['scan_interval_days'] );
	}

	/**
	 * Unknown keys are rejected rather than silently stored.
	 *
	 * @return void
	 */
	public function test_unknown_keys_are_rejected() {
		$result = $this->settings->save(
			array(
				'retain_scans' => 5,
				'evil'         => '1',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'speedy_sensor_unknown_settings', $result->get_error_code() );
		$this->assertSame( 12, $this->settings->get( 'retain_scans' ), 'Rejected input must not be applied.' );
	}

	/**
	 * Numeric settings are clamped rather than trusted.
	 *
	 * @dataProvider provide_clamps
	 *
	 * @param mixed $input    Raw value.
	 * @param int   $expected Stored value.
	 * @return void
	 */
	public function test_numeric_settings_are_clamped( $input, $expected ) {
		$result = $this->settings->save( array( 'scan_interval_days' => $input ) );

		$this->assertIsArray( $result );
		$this->assertSame( $expected, $this->settings->get( 'scan_interval_days' ) );
	}

	/**
	 * Cases for the clamp assertion.
	 *
	 * @return array
	 */
	public function provide_clamps() {
		return array(
			'below range' => array( 0, 1 ),
			'above range' => array( 5000, 90 ),
			'in range'    => array( 14, 14 ),
			'not numeric' => array( 'soon', 7 ),
			'negative'    => array( -30, 1 ),
		);
	}

	/**
	 * Only https addresses on speedy.site hosts are accepted.
	 *
	 * @dataProvider provide_base_urls
	 *
	 * @param string $url  Candidate base URL.
	 * @param bool   $valid Whether it should be accepted.
	 * @return void
	 */
	public function test_api_base_url_is_restricted( $url, $valid ) {
		$result = $this->settings->save( array( 'api_base_url' => $url ) );

		if ( $valid ) {
			$this->assertIsArray( $result );
			$this->assertSame( untrailingslashit( $url ), $this->settings->get( 'api_base_url' ) );

			return;
		}

		$this->assertWPError( $result );
		$this->assertSame( 'speedy_sensor_bad_api_url', $result->get_error_code() );
	}

	/**
	 * Base URL allowlist cases.
	 *
	 * @return array
	 */
	public function provide_base_urls() {
		return array(
			'api host'     => array( 'https://api.speedy.site', true ),
			'apex'         => array( 'https://speedy.site', true ),
			'subdomain'    => array( 'https://staging.speedy.site', true ),
			'plain http'   => array( 'http://api.speedy.site', false ),
			'other host'   => array( 'https://evil.example.com', false ),
			'suffix trick' => array( 'https://speedy.site.evil.com', false ),
			'with creds'   => array( 'https://user:pass@api.speedy.site', false ),
			'with query'   => array( 'https://api.speedy.site?a=1', false ),
			'no host'      => array( 'https://', false ),
			'blank'        => array( '', false ),
			'not a url'    => array( 'api.speedy.site', false ),
		);
	}

	/**
	 * Credentials are reduced to a safe character set.
	 *
	 * @return void
	 */
	public function test_credentials_are_stripped_to_safe_characters() {
		$result = $this->settings->save(
			array(
				'api_key'  => ' abc-123_XYZ.<script> ',
				'site_key' => "site 42!\n",
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'abc-123_XYZ.script', $this->settings->get( 'api_key' ) );
		$this->assertSame( 'site42', $this->settings->get( 'site_key' ) );
	}

	/**
	 * The raw key is never exposed for display.
	 *
	 * @return void
	 */
	public function test_masked_api_key_hides_the_secret() {
		$this->settings->save( array( 'api_key' => 'supersecret1234' ) );

		$masked = ( new Settings() )->masked_api_key();

		$this->assertStringNotContainsString( 'supersecret', $masked );
		$this->assertStringEndsWith( '1234', $masked );
	}

	/**
	 * Linking requires both credentials.
	 *
	 * @return void
	 */
	public function test_is_linked_requires_both_credentials() {
		$this->settings->save( array( 'api_key' => 'key' ) );
		$this->assertFalse( ( new Settings() )->is_linked() );

		$this->settings->save( array( 'site_key' => 'site' ) );
		$this->assertTrue( ( new Settings() )->is_linked() );
	}

	/**
	 * The settings form renders the key field empty, so a save that omits the
	 * key must leave the stored one untouched. Actions strips an empty field
	 * before calling save(); this covers the merge that depends on.
	 *
	 * @return void
	 */
	public function test_omitting_the_api_key_keeps_the_stored_one() {
		$this->settings->save( array( 'api_key' => 'secret-key' ) );
		$this->settings->save( array( 'site_key' => 'site-1' ) );

		$this->assertSame( 'secret-key', ( new Settings() )->get( 'api_key' ) );
	}

	/**
	 * Blanking the API address would break every outbound call, so it is
	 * rejected rather than stored.
	 *
	 * @return void
	 */
	public function test_empty_api_base_url_is_rejected() {
		$this->settings->save( array( 'api_base_url' => 'https://api.speedy.site' ) );

		$result = $this->settings->save( array( 'api_base_url' => '' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'speedy_sensor_bad_api_url', $result->get_error_code() );
		$this->assertSame(
			'https://api.speedy.site',
			( new Settings() )->get( 'api_base_url' ),
			'A rejected URL must not overwrite the stored one.'
		);
	}

	/**
	 * Checkbox values arrive as strings from the form.
	 *
	 * @return void
	 */
	public function test_boolean_settings_accept_form_values() {
		$this->settings->save( array( 'scan_enabled' => '1' ) );
		$this->assertTrue( ( new Settings() )->get( 'scan_enabled' ) );

		$this->settings->save( array( 'scan_enabled' => '0' ) );
		$this->assertFalse( ( new Settings() )->get( 'scan_enabled' ) );
	}
}
