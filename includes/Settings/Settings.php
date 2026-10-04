<?php
/**
 * Settings store.
 *
 * One option, one array, allowlist validation. Unknown keys are rejected rather
 * than silently defaulted, so a renamed or retired key can never linger.
 *
 * @package Speedy_Sensor
 */

namespace Speedy_Sensor\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the plugin settings array.
 */
final class Settings {

	/**
	 * Option name.
	 */
	const OPTION = 'speedy_sensor_settings';

	/**
	 * Cached settings for the current request.
	 *
	 * @var array|null
	 */
	private $cache = null;

	/**
	 * Runtime memo. Not a transient: reading settings must never cost a query.
	 *
	 * @return array
	 */
	public function all() {
		if ( null === $this->cache ) {
			$stored = get_option( self::OPTION, array() );

			$this->cache = wp_parse_args( is_array( $stored ) ? $stored : array(), $this->defaults() );
		}

		/**
		 * Filters the resolved Speedy Sensor settings.
		 *
		 * @param array    $settings Settings array.
		 * @param Settings $instance Settings store.
		 */
		return apply_filters( 'speedy_sensor_settings', $this->cache, $this );
	}

	/**
	 * Returns one setting, or the fallback when unset.
	 *
	 * @param string $key           Setting key.
	 * @param mixed  $default_value Fallback.
	 * @return mixed
	 */
	public function get( $key, $default_value = null ) {
		$all = $this->all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $default_value;
	}

	/**
	 * Default values. Single source of truth for sanitisation ranges.
	 *
	 * @return array
	 */
	public function defaults() {
		return array(
			'api_key'                  => '',
			'site_key'                 => '',
			'api_base_url'             => 'https://api.speedy.site',
			'vitals_enabled'           => true,
			'vitals_refresh_days'      => 1,
			'scan_enabled'             => true,
			'scan_interval_days'       => 7,
			'service_requests_enabled' => true,
			'retain_scans'             => 12,
		);
	}

	/**
	 * Validates and persists settings.
	 *
	 * @param array $input Raw, unslashed input.
	 * @return array|\WP_Error Saved settings, or the reason input was rejected.
	 */
	public function save( array $input ) {
		$defaults = $this->defaults();
		$clean    = array();

		foreach ( $defaults as $key => $default_value ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}

			switch ( $key ) {
				case 'api_key':
					$clean[ $key ] = $this->sanitize_api_key( $input[ $key ] );
					break;

				case 'site_key':
					$clean[ $key ] = $this->sanitize_site_key( $input[ $key ] );
					break;

				case 'api_base_url':
					$clean[ $key ] = $this->sanitize_api_base_url( $input[ $key ] );
					break;

				case 'retain_scans':
					$clean[ $key ] = self::clamp( $input[ $key ], 1, 52, $default_value );
					break;

				case 'vitals_refresh_days':
				case 'scan_interval_days':
					$clean[ $key ] = self::clamp( $input[ $key ], 1, 90, $default_value );
					break;

				default:
					$clean[ $key ] = self::to_bool( $input[ $key ] );
					break;
			}
		}

		$unknown = array_diff( array_keys( $input ), array_keys( $defaults ) );

		if ( ! empty( $unknown ) ) {
			return new \WP_Error(
				'speedy_sensor_unknown_settings',
				__( 'One or more settings are not recognised and were not saved.', 'speedy-sensor' ),
				array( 'keys' => array_values( $unknown ) )
			);
		}

		$merged = array_merge( $this->all(), $clean );

		if ( isset( $clean['api_base_url'] ) && '' === $clean['api_base_url'] ) {
			// Covers both an empty field and a rejected URL. Blanking the value
			// would silently break every API call, so it is never accepted.
			return new \WP_Error(
				'speedy_sensor_bad_api_url',
				__( 'The Speedy API address is required and must be an https address on a speedy.site host, with no query string or credentials.', 'speedy-sensor' )
			);
		}

		update_option( self::OPTION, $merged, false );
		$this->cache = null;

		/**
		 * Fires after settings are saved.
		 *
		 * @param array $merged Saved settings.
		 */
		do_action( 'speedy_sensor_settings_saved', $merged );

		return $merged;
	}

	/**
	 * API keys are opaque tokens: printable ASCII, no whitespace.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function sanitize_api_key( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return trim( preg_replace( '/[^A-Za-z0-9._\-]/', '', (string) $value ) );
	}

	/**
	 * Site keys are opaque identifiers, same charset as API keys.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function sanitize_site_key( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return trim( preg_replace( '/[^A-Za-z0-9._\-]/', '', (string) $value ) );
	}

	/**
	 * Restricts the API base URL to https on a speedy.site host.
	 *
	 * Without this, anyone able to write settings could point the site at an
	 * arbitrary host and use the plugin as a request proxy.
	 *
	 * @param mixed $value Raw value.
	 * @return string Sanitised URL, or an empty string when rejected.
	 */
	private function sanitize_api_base_url( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$raw = trim( (string) $value );

		if ( '' === $raw ) {
			return '';
		}

		$url = wp_parse_url( $raw );

		if ( empty( $url['scheme'] ) || empty( $url['host'] ) ) {
			return '';
		}

		if ( 'https' !== strtolower( $url['scheme'] ) ) {
			return '';
		}

		if ( isset( $url['user'] ) || isset( $url['pass'] ) || isset( $url['query'] ) || isset( $url['fragment'] ) ) {
			return '';
		}

		if ( ! $this->is_allowed_api_host( $url['host'] ) ) {
			return '';
		}

		return untrailingslashit( $raw );
	}

	/**
	 * Allowlist check for API hostnames.
	 *
	 * @param string $host Hostname.
	 * @return bool
	 */
	private function is_allowed_api_host( $host ) {
		$host = strtolower( $host );
		$root = 'speedy.site';
		$dot  = '.' . $root;

		$allowed = ( $host === $root || strlen( $host ) > strlen( $dot ) && substr( $host, -strlen( $dot ) ) === $dot );

		/**
		 * Filters the hostnames the plugin may talk to.
		 *
		 * Only needed for staging or self-hosted Speedy endpoints.
		 *
		 * @param bool   $allowed Whether the host is allowed.
		 * @param string $host    Hostname being checked.
		 */
		return (bool) apply_filters( 'speedy_sensor_allowed_api_host', $allowed, $host );
	}

	/**
	 * Masked display form of the API key. The raw value is never rendered.
	 *
	 * @return string
	 */
	public function masked_api_key() {
		$key = (string) $this->get( 'api_key', '' );

		if ( '' === $key ) {
			return '';
		}

		return str_repeat( '*', 8 ) . substr( $key, -4 );
	}

	/**
	 * Whether the plugin has what it needs to talk to Speedy.
	 *
	 * @return bool
	 */
	public function is_linked() {
		return '' !== (string) $this->get( 'api_key', '' ) && '' !== (string) $this->get( 'site_key', '' );
	}

	/**
	 * Casts checkbox input to a boolean.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public static function to_bool( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		return in_array( $value, array( '1', 1, 'on', 'true', 'yes' ), true );
	}

	/**
	 * Clamps an integer into range, falling back when not numeric.
	 *
	 * @param mixed $value    Raw value.
	 * @param int   $min      Lower bound.
	 * @param int   $max      Upper bound.
	 * @param int   $fallback Value used when input is not an integer.
	 * @return int
	 */
	public static function clamp( $value, $min, $max, $fallback ) {
		if ( ! is_numeric( $value ) ) {
			return $fallback;
		}

		return max( $min, min( $max, (int) $value ) );
	}

	/**
	 * Installs default settings without clobbering existing ones.
	 *
	 * @return void
	 */
	public function install_defaults() {
		add_option( self::OPTION, $this->defaults(), '', false );
		$this->cache = null;
	}

	/**
	 * Removes the settings option.
	 *
	 * @return void
	 */
	public function delete() {
		delete_option( self::OPTION );
		$this->cache = null;
	}
}