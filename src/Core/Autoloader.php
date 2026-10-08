<?php
/**
 * PSR-4 autoloader for the plugin runtime (no Composer required in production).
 *
 * @package NewsDesk\AI\Core
 */

namespace NewsDesk\AI\Core;

defined( 'ABSPATH' ) || exit;

final class Autoloader {

	const PREFIX = 'NewsDesk\\AI\\';

	/**
	 * Register the SPL autoloader.
	 */
	public static function register(): void {
		spl_autoload_register( array( self::class, 'load' ), true, true );
	}

	/**
	 * Load a class file for the plugin namespace.
	 *
	 * @param string $class Fully-qualified class name.
	 */
	public static function load( string $class ): void {
		if ( 0 !== strpos( $class, self::PREFIX ) ) {
			return;
		}
		$relative = substr( $class, strlen( self::PREFIX ) );
		$file     = NEWSDESK_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require $file; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingCustomFunction
		}
	}
}
