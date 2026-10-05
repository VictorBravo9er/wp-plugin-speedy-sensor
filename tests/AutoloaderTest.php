<?php
/**
 * PSR-4 autoloader regression test.
 *
 * The autoloader shipped a guard that rejected any relative name containing a
 * backslash. In PHP a single-quoted '\\' is one literal backslash, so the guard
 * rejected the namespace separator itself and silently unloaded every class
 * except Speedy_Sensor\Plugin. Activation then died with "Class not found".
 *
 * This test walks the real includes/ tree and asks the registered autoloader to
 * resolve each class, which is the only way the bug is visible.
 *
 * @package Speedy_Sensor
 */

namespace Speedy_Sensor\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Covers includes/autoload.php.
 */
final class AutoloaderTest extends TestCase {

	/**
	 * Every class the plugin references must actually resolve.
	 *
	 * @return void
	 */
	public function test_every_shipped_class_is_autoloadable() {
		$missing = array();

		foreach ( $this->shipped_classes() as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = $class;
			}
		}

		$this->assertSame(
			array(),
			$missing,
			'These classes could not be autoloaded: ' . implode( ', ', $missing )
		);
	}

	/**
	 * A deeply nested class is the exact case the broken guard rejected.
	 *
	 * @return void
	 */
	public function test_nested_namespace_class_resolves() {
		$this->assertTrue(
			class_exists( 'Speedy_Sensor\Admin\Pages\Dashboard' ),
			'A class in a nested namespace must resolve through the autoloader.'
		);
	}

	/**
	 * The activation hook target is the class that broke activation.
	 *
	 * @return void
	 */
	public function test_activator_resolves() {
		$this->assertTrue(
			class_exists( 'Speedy_Sensor\Install\Activator' ),
			'The activation callback class must be autoloadable.'
		);
	}

	/**
	 * A traversal attempt must still be refused after the fix.
	 *
	 * @return void
	 */
	public function test_traversal_is_still_rejected() {
		$this->assertFalse(
			class_exists( 'Speedy_Sensor\Evil\..\..\autoload' ),
			'A name containing ".." must never resolve to a file outside includes/.'
		);
	}

	/**
	 * Derives class names from the real file tree.
	 *
	 * @return string[] Fully qualified class names shipped in includes/.
	 */
	private function shipped_classes() {
		$dir   = dirname( __DIR__ ) . '/includes';
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir ) );

		$classes = array();

		foreach ( $files as $file ) {
			if ( $file->getExtension() !== 'php' || 'autoload.php' === $file->getBasename() ) {
				continue;
			}

			$relative = substr( $file->getPathname(), strlen( $dir ) + 1, -4 );

			$classes[] = 'Speedy_Sensor\\' . str_replace( '/', '\\', $relative );
		}

		sort( $classes );

		return $classes;
	}
}