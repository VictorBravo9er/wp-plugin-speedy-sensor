<?php
/**
 * Uninstall entry point.
 *
 * Runs when the plugin is deleted from the Plugins screen, not when it is
 * deactivated. Deactivation is reversible and keeps the data.
 *
 * @package Speedy_Sensor
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/autoload.php';

\Speedy_Sensor\Install\Uninstaller::uninstall();