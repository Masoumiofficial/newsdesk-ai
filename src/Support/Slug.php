<?php
/**
 * Deterministic slug builder (WP-function-free so it is testable headlessly).
 *
 * @package NewsDesk\AI\Support
 */

namespace NewsDesk\AI\Support;

defined( 'ABSPATH' ) || exit;

final class Slug {

	public static function fromTitle( string $title ): string {
		$slug = trim( mb_strtolower( $title, 'UTF-8' ) );
		// Keep letters, digits, dash, space; drop everything else.
		$slug = preg_replace( '/[^\p{L}\p{N}\s\-]/u', '', $slug );
		$slug = preg_replace( '/[\s\-]+/u', '-', $slug );
		$slug = trim( (string) $slug, '-' );
		return mb_substr( (string) $slug, 0, 120 );
	}
}
