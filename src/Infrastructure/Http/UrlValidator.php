<?php
/**
 * URL validation for all outbound traffic (§52).
 *
 * @package NewsDesk\AI\Infrastructure\Http
 */

namespace NewsDesk\AI\Infrastructure\Http;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Support\Http\UrlValidationException;

final class UrlValidator {

	/** Blocked host names (exact or suffix match, case-insensitive). */
	private const BLOCKED_HOST_EXACT = array(
		'localhost', '0.0.0.0', 'metadata', 'metadata.google.internal',
		'169.254.169.254', '169.254.170.2',
	);

	private const BLOCKED_HOST_SUFFIXES = array(
		'.localhost', '.local', '.internal', '.intranet', '.lan', '.home.arpa', '.corp', '.test',
		'.localhost.localdomain', '.localdomain',
	);

	/**
	 * Validate a URL → parsed parts. Throws on any violation.
	 *
	 * @return array{scheme: string, host: string, port: ?int, path: string, query: string, userinfo: string}
	 */
	public static function validate( string $url ): array {
		$url = trim( $url );
		if ( '' === $url ) {
			throw new UrlValidationException( $url, 'EMPTY_URL' );
		}
		$parts = parse_url( $url );
		if ( false === $parts || ! isset( $parts['scheme'], $parts['host'] ) ) {
			throw new UrlValidationException( $url, 'MALFORMED_URL' );
		}
		$scheme = strtolower( (string) $parts['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			throw new UrlValidationException( $url, 'SCHEME_NOT_ALLOWED' );
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			throw new UrlValidationException( $url, 'CREDENTIALS_IN_URL' );
		}
		$host = strtolower( (string) $parts['host'] );
		if ( '' === $host ) {
			throw new UrlValidationException( $url, 'EMPTY_HOST' );
		}
		if ( self::isBlockedHostname( $host ) ) {
			throw new UrlValidationException( $url, 'HOST_BLOCKED' );
		}
		return array(
			'scheme'   => $scheme,
			'host'     => $host,
			'port'     => isset( $parts['port'] ) ? (int) $parts['port'] : null,
			'path'     => (string) ( $parts['path'] ?? '/' ),
			'query'    => (string) ( $parts['query'] ?? '' ),
			'userinfo' => '',
		);
	}

	/**
	 * Hostname blocklist (no DNS resolution here — see IpValidator).
	 */
	public static function isBlockedHostname( string $host ): bool {
		if ( in_array( $host, self::BLOCKED_HOST_EXACT, true ) ) {
			return true;
		}
		foreach ( self::BLOCKED_HOST_SUFFIXES as $suffix ) {
			if ( substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Resolve a Location header against the current URL (RFC 3986-ish, conservative).
	 */
	public static function resolveRedirect( string $location, string $baseUrl ): ?string {
		$location = trim( $location );
		if ( '' === $location || 0 === strpos( $location, '#' ) ) {
			return null;
		}
		$base = parse_url( $baseUrl );
		if ( false === $base || ! isset( $base['scheme'], $base['host'] ) ) {
			return null;
		}
		$scheme = (string) $base['scheme'];
		$host   = (string) $base['host'];
		$port   = isset( $base['port'] ) ? ':' . $base['port'] : '';
		$path   = (string) ( $base['path'] ?? '/' );

		if ( 0 === strpos( $location, '//' ) ) {
			return $scheme . ':' . $location;
		}
		if ( 0 === strpos( $location, '/' ) ) {
			return $scheme . '://' . $host . $port . $location;
		}
		if ( preg_match( '#^[a-z][a-z0-9+.-]*://#i', $location ) ) {
			return $location; // absolute — re-validated by caller
		}
		// relative: drop last path segment
		$dir = substr( $path, 0, (int) strrpos( $path, '/' ) + 1 );
		return $scheme . '://' . $host . $port . $dir . $location;
	}
}
