<?php
/**
 * PSR-4 autoloader for the SEOConsole namespace (includes/).
 *
 * @package SEOConsole
 */

namespace SEOConsole;

defined( 'ABSPATH' ) || exit;

/**
 * Maps SEOConsole\Foo\Bar to includes/Foo/Bar.php.
 */
final class Autoloader {

	/**
	 * Registers the autoloader.
	 */
	public static function register(): void {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * Loads one class.
	 *
	 * @param string $class_name Fully qualified class name.
	 */
	public static function load( string $class_name ): void {
		$prefix = __NAMESPACE__ . '\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
