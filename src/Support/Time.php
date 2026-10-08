<?php
/**
 * Time helpers — site timezone aware when running inside WordPress.
 *
 * @package NewsDesk\AI\Support
 */

namespace NewsDesk\AI\Support;

defined( 'ABSPATH' ) || exit;

final class Time {

	/**
	 * WordPress site timezone object (fallback UTC outside WP).
	 */
	public static function wpTimezone(): \DateTimeZone {
		if ( function_exists( 'wp_timezone' ) ) {
			return wp_timezone();
		}
		return new \DateTimeZone( 'UTC' );
	}

	/**
	 * Current moment in site timezone.
	 */
	public static function now(): \DateTimeImmutable {
		return new \DateTimeImmutable( 'now', self::wpTimezone() );
	}

	/**
	 * Parse a DB datetime string (empty/null safe).
	 */
	public static function fromDb( $value ): ?\DateTimeImmutable {
		if ( empty( $value ) ) {
			return null;
		}
		try {
			$dt = new \DateTimeImmutable( (string) $value, self::wpTimezone() );
			return $dt;
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/**
	 * Format for DB storage (site timezone).
	 */
	public static function toDb( ?\DateTimeInterface $dt ): ?string {
		if ( null === $dt ) {
			return null;
		}
		// createFromInterface() requires PHP 8.0; the plugin supports PHP 7.4.
		// A Unix timestamp preserves the instant for mutable and immutable inputs.
		return ( new \DateTimeImmutable( '@' . $dt->getTimestamp() ) )
			->setTimezone( self::wpTimezone() )
			->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Parse an RFC-ish feed date via strtotime (null on failure).
	 */
	public static function parseFeedDate( string $value ): ?\DateTimeImmutable {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}
		$ts = strtotime( $value );
		if ( false === $ts ) {
			return null;
		}
		return ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( self::wpTimezone() );
	}

	/**
	 * Unix timestamp.
	 */
	public static function unix(): int {
		return time();
	}
}
