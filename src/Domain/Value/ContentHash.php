<?php
namespace NewsDesk\AI\Domain\Value;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Support\Str;

/**
 * sha256 content fingerprint — cheap dedup signal (§9).
 */
final class ContentHash {

	private function __construct() {
	}

	/**
	 * Hash of canonical URL + normalized title + content signature.
	 */
	public static function fromParts( string $canonicalUrl, string $normalizedTitle, string $contentSignature ): string {
		$parts = array(
			Str::lower( $canonicalUrl ),
			Str::lower( Str::collapseWhitespace( $normalizedTitle ) ),
			Str::lower( Str::collapseWhitespace( $contentSignature ) ),
		);
		return hash( 'sha256', implode( "\n", $parts ) );
	}

	/**
	 * Validate a stored hash (64 lowercase hex).
	 */
	public static function isValid( string $hash ): bool {
		return (bool) preg_match( '/^[a-f0-9]{64}$/', $hash );
	}
}
