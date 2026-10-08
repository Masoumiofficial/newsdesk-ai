<?php
namespace NewsDesk\AI\Domain\Entity;

defined( 'ABSPATH' ) || exit;

/**
 * Common entity helpers (array mapping kept explicit per entity).
 */
abstract class AbstractEntity {

	/**
	 * @param mixed $value
	 */
	protected static function intOrNull( $value ): ?int {
		return ( null === $value || '' === $value ) ? null : (int) $value;
	}

	/**
	 * @param mixed $value
	 */
	protected static function intOr( $value, int $default ): int {
		return ( null === $value || '' === $value ) ? $default : (int) $value;
	}

	/**
	 * @param mixed $value
	 */
	protected static function floatOr( $value, float $default ): float {
		return ( null === $value || '' === $value ) ? $default : (float) $value;
	}

	/**
	 * @param mixed $value
	 */
	protected static function strOr( $value, string $default = '' ): string {
		return null === $value ? $default : (string) $value;
	}

	/**
	 * @param mixed $value
	 * @return array
	 */
	protected static function jsonArray( $value ): array {
		if ( is_array( $value ) ) {
			return $value;
		}
		if ( ! is_string( $value ) || '' === $value ) {
			return array();
		}
		$decoded = json_decode( $value, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * @param mixed $value
	 */
	protected static function jsonEncode( $value ): string {
		$json = function_exists( 'wp_json_encode' )
			? wp_json_encode( $value )
			: json_encode( $value, JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? $json : '{}';
	}
}
