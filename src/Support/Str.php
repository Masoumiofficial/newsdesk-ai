<?php
/**
 * Small string helpers (PHP 7.4-safe).
 *
 * @package NewsDesk\AI\Support
 */

namespace NewsDesk\AI\Support;

defined( 'ABSPATH' ) || exit;

final class Str {

	/**
	 * Whether a string starts with a prefix.
	 */
	public static function startsWith( string $haystack, string $needle ): bool {
		return '' !== $needle && 0 === strpos( $haystack, $needle );
	}

	/**
	 * Whether a string ends with a suffix.
	 */
	public static function endsWith( string $haystack, string $needle ): bool {
		$len = strlen( $needle );
		return '' !== $needle && strlen( $haystack ) >= $len && substr( $haystack, -$len ) === $needle;
	}

	/**
	 * Whether a string contains a needle.
	 */
	public static function contains( string $haystack, string $needle ): bool {
		return '' !== $needle && false !== strpos( $haystack, $needle );
	}

	/**
	 * Lowercase (multibyte-safe, UTF-8 fallback).
	 */
	public static function lower( string $value ): string {
		if ( function_exists( 'mb_strtolower' ) ) {
			return mb_strtolower( $value, 'UTF-8' );
		}
		return strtolower( $value );
	}

	/**
	 * Collapse all whitespace runs to single spaces and trim.
	 */
	public static function collapseWhitespace( string $value ): string {
		$value = (string) preg_replace( '/\s+/u', ' ', $value );
		return trim( (string) $value );
	}

	/**
	 * Strip HTML tags + decode entities + collapse whitespace.
	 */
	public static function plainText( string $value ): string {
		$value = (string) preg_replace( '/<script\b[^>]*>.*?<\/script>/is', ' ', $value );
		$value = (string) preg_replace( '/<style\b[^>]*>.*?<\/style>/is', ' ', $value );
		$value = strip_tags( $value );
		$value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return self::collapseWhitespace( $value );
	}

	/**
	 * Truncate to a max length on a word boundary.
	 */
	public static function limit( string $value, int $max ): string {
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $value, 'UTF-8' ) <= $max ) {
			return $value;
		}
		if ( ! function_exists( 'mb_strlen' ) && strlen( $value ) <= $max ) {
			return $value;
		}
		$slice = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max, 'UTF-8' ) : substr( $value, 0, $max );
		if ( false !== strpos( $slice, ' ' ) ) {
			$slice = substr( $slice, 0, (int) strrpos( $slice, ' ' ) );
		}
		return trim( (string) $slice ) . '…';
	}
}
