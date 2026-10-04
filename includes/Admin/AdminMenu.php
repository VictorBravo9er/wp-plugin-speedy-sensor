<?php
/**
 * Admin menu and admin-only assets.
 *
 * The menu is registered for administrators only. The stylesheet is enqueued
 * on this plugin's own screens and nowhere else, so no other admin page in the
 * site pays for it.
 *
 * There is deliberately no JavaScript. Every screen is server-rendered, the
 * seven day chart is inline SVG, and every action is a plain form post, so the
 * plugin ships no script tags at all.
 *
 * @package Speedy_Sensor
 */

namespace Speedy_Sensor\Admin;

use Speedy_Sensor\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the admin screens.
 */
final class AdminMenu {

	/**
	 * Hook suffixes for the screens this plugin owns.
	 *
	 * @var array
	 */
	private static $screens = array();

	/**
	 * Registers hooks.
	 *
	 * @param Settings $settings Settings store.
	 * @return void
	 */
	public static function init( Settings $settings ) {
		unset( $settings );

		add_action( 'admin_menu', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Registers the menu and its subpages.
	 *
	 * @return void
	 */
	public static function register() {
		self::$screens[] = add_menu_page(
			__( 'Speedy Sensor', 'speedy-sensor' ),
			__( 'Speedy Sensor', 'speedy-sensor' ),
			'manage_options',
			Actions::PAGE_DASHBOARD,
			array( __CLASS__, 'render_dashboard' ),
			'dashicons-performance',
			80
		);

		self::$screens[] = add_submenu_page(
			Actions::PAGE_DASHBOARD,
			__( 'Speedy Sensor Overview', 'speedy-sensor' ),
			__( 'Overview', 'speedy-sensor' ),
			'manage_options',
			Actions::PAGE_DASHBOARD,
			array( __CLASS__, 'render_dashboard' )
		);

		self::$screens[] = add_submenu_page(
			Actions::PAGE_DASHBOARD,
			__( 'Speedy Sensor Scan', 'speedy-sensor' ),
			__( 'Scan & Plugins', 'speedy-sensor' ),
			'manage_options',
			Actions::PAGE_SCAN,
			array( __CLASS__, 'render_scan' )
		);

		self::$screens[] = add_submenu_page(
			Actions::PAGE_DASHBOARD,
			__( 'Speedy Sensor Database', 'speedy-sensor' ),
			__( 'Database', 'speedy-sensor' ),
			'manage_options',
			Actions::PAGE_DATABASE,
			array( __CLASS__, 'render_database' )
		);

		self::$screens[] = add_submenu_page(
			Actions::PAGE_DASHBOARD,
			__( 'Speedy Sensor Settings', 'speedy-sensor' ),
			__( 'Settings', 'speedy-sensor' ),
			'manage_options',
			Actions::PAGE_SETTINGS,
			array( __CLASS__, 'render_settings' )
		);
	}

	/**
	 * Enqueues the stylesheet on this plugin's screens only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public static function enqueue( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, self::$screens, true ) ) {
			return;
		}

		wp_enqueue_style(
			'speedy-sensor-admin',
			SPEEDY_SENSOR_URL . 'assets/css/admin.css',
			array( 'dashicons' ),
			SPEEDY_SENSOR_VERSION
		);
	}

	/**
	 * Renders the overview screen.
	 *
	 * @return void
	 */
	public static function render_dashboard() {
		Pages\Dashboard::render( new Settings() );
	}

	/**
	 * Renders the scan and plugin screen.
	 *
	 * @return void
	 */
	public static function render_scan() {
		Pages\Scan::render( new Settings() );
	}

	/**
	 * Renders the database screen.
	 *
	 * @return void
	 */
	public static function render_database() {
		Pages\Database::render( new Settings() );
	}

	/**
	 * Renders the settings screen.
	 *
	 * @return void
	 */
	public static function render_settings() {
		Pages\SettingsPage::render( new Settings() );
	}
}