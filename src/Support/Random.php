<?php
/**
 * Randomness helpers.
 *
 * @package NewsDesk\AI\Support
 */

namespace NewsDesk\AI\Support;

defined( 'ABSPATH' ) || exit;

final class Random {

	/**
	 * RFC 4122 v4 UUID.
	 */
	public static function uuid4(): string {
		$data    = random_bytes( 16 );
		$data[6] = chr( ( ord( $data[6] ) & 0x0f ) | 0x40 );
		$data[8] = chr( ( ord( $data[8] ) & 0x3f ) | 0x80 );
		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
	}

	/**
	 * Short correlation/trace id (12 hex chars).
	 */
	public static function traceId(): string {
		return substr( bin2hex( random_bytes( 8 ) ), 0, 12 );
	}
}
