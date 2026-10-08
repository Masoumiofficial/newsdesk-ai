<?php
/**
 * Array helpers.
 *
 * @package NewsDesk\AI\Support
 */

namespace NewsDesk\AI\Support;

defined( 'ABSPATH' ) || exit;

final class Arr {

	/**
	 * Get a value by key with default.
	 *
	 * @param array $array Haystack.
	 * @param mixed $key Key.
	 * @param mixed $default Default.
	 * @return mixed
	 */
	public static function get( array $array, $key, $default = null ) {
		return array_key_exists( $key, $array ) ? $array[ $key ] : $default;
	}

	/**
	 * Keep only whitelisted keys.
	 *
	 * @param array $array Source.
	 * @param array $keys Allowed keys.
	 */
	public static function only( array $array, array $keys ): array {
		$out = array();
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $array ) ) {
				$out[ $key ] = $array[ $key ];
			}
		}
		return $out;
	}
}
