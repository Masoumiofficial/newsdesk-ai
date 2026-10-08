<?php
/**
 * §8 URL canonicalization pipeline (pure, testable).
 *
 * @package NewsDesk\AI\News\Canonical
 */

namespace NewsDesk\AI\News\Canonical;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Domain\Exception\DomainException;
use NewsDesk\AI\Domain\Value\CanonicalUrl;
use NewsDesk\AI\Support\Str;

final class UrlCanonicalizer {

	/** Tracking params removed unconditionally. */
	private const TRACKING_EXACT = array(
		'fbclid', 'gclid', 'yclid', 'msclkid', 'twclid', 'mc_cid', 'mc_eid',
		'srsltid', 'gs', 'ref', 'ref_src', 'source', 'spm', 'from', 'share',
		'si', 'igshid', '_hsenc', '_hsmi', 'ved', 'ei', 'scm', 'wickedid',
		'wickedid', 'oly_anon_id', 'oly_enc_id', 'rb_clickid', 'dclid',
	);

	/**
	 * Canonicalize a feed item URL per §8.
	 *
	 * @throws DomainException when the URL is unusable.
	 */
	public static function canonicalize( string $url ): CanonicalUrl {
		$url = trim( $url );
		if ( '' === $url ) {
			throw new DomainException( 'Cannot canonicalize an empty URL' );
		}

		$parts = parse_url( $url );
		if ( false === $parts || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			throw new DomainException( 'Malformed URL: ' . $url );
		}
		$scheme = strtolower( (string) $parts['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			throw new DomainException( 'Unsupported scheme: ' . $scheme );
		}

		$host = strtolower( (string) $parts['host'] );
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : null;

		// Normalize default ports.
		if ( ( 'http' === $scheme && 80 === $port ) || ( 'https' === $scheme && 443 === $port ) ) {
			$port = null;
		}

		$path = (string) ( $parts['path'] ?? '' );
		$path = rawurldecode( $path );
		if ( '/' !== $path ) {
			$path = rtrim( $path, '/' );
		}
		if ( '' === $path ) {
			$path = '/';
		}

		$query = (string) ( $parts['query'] ?? '' );
		$query = self::stripTracking( $query );

		$out = $scheme . '://' . $host;
		if ( null !== $port ) {
			$out .= ':' . $port;
		}
		$out .= $path;
		if ( '' !== $query ) {
			$out .= '?' . $query;
		}
		// No fragments, ever.
		return new CanonicalUrl( $out );
	}

	/**
	 * Remove tracking params (utm_* + exact list), keep order of remaining params.
	 */
	public static function stripTracking( string $query ): string {
		if ( '' === $query ) {
			return '';
		}
		$kept = array();
		foreach ( explode( '&', $query ) as $pair ) {
			if ( '' === $pair ) {
				continue;
			}
			$name = strtok( $pair, '=' );
			$name = strtolower( (string) $name );
			if ( Str::startsWith( $name, 'utm_' ) || in_array( $name, self::TRACKING_EXACT, true ) ) {
				continue;
			}
			$kept[] = $pair;
		}
		return implode( '&', $kept );
	}
}
