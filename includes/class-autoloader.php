<?php
/**
 * Autoloader for Plugin Classes
 *
 * @package EtsyWooCommerceAIImporter
 * @since 1.1.0
 */

namespace EtsyWooCommerceAIImporter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Autoloader class
 *
 * Automatically loads plugin classes as needed.
 */
class Autoloader {
	/**
	 * Register the autoloader.
	 */
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * Load a class file.
	 *
	 * @param string $class Class name to load.
	 */
	public static function load( $class ) {
		// Only autoload our own namespace.
		$namespace = 'EtsyWooCommerceAIImporter\\';

		if ( strpos( $class, $namespace ) !== 0 ) {
			return;
		}

		// Remove namespace prefix.
		$class_name = str_replace( $namespace, '', $class );

		// Convert CamelCase to kebab-case (ImageSync -> image-sync).
		$file_name = strtolower( preg_replace( '/([a-z])([A-Z])/', '$1-$2', $class_name ) );

		// Build the full file path.
		$file = plugin_dir_path( __DIR__ ) . 'includes/class-' . $file_name . '.php';

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
}
