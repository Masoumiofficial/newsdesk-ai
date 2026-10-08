<?php
/**
 * Rank Math adapter (§76).
 *
 * VERIFIED CONTRACT — Rank Math stores these as plain post meta on the post:
 *   rank_math_title           SEO title
 *   rank_math_description     meta description
 *   rank_math_focus_keyword   focus keyword (comma-separated for extras)
 *   rank_math_canonical_url   canonical URL
 *   rank_math_robots          serialized array, e.g. array('index','follow')
 *
 * Unlike Yoast, Rank Math's keys are NOT underscore-prefixed (they are visible
 * custom fields). The engine computes the package; we only mirror it.
 *
 * @package NewsDesk\AI\Application\Seo
 */

namespace NewsDesk\AI\Application\Seo;

defined( 'ABSPATH' ) || exit;

final class RankMathAdapter implements SeoAdapterInterface {

	/** Rank Math defines this in rank-math.php. */
	private const DETECT_CONSTANT = 'RANK_MATH_VERSION';
	/** Fallback detection: the main plugin class. */
	private const DETECT_CLASS = '\RankMath';

	public function id(): string {
		return 'rankmath';
	}

	public function isActive(): bool {
		return defined( self::DETECT_CONSTANT ) || class_exists( self::DETECT_CLASS );
	}

	public function apply( int $postId, array $seo ): void {
		if ( $postId <= 0 || ! $this->isActive() ) {
			return;
		}

		$keywords = (string) ( $seo['focus_keyword'] ?? '' );
		$extra    = (array) ( $seo['secondary_keywords'] ?? array() );
		if ( $extra ) {
			// Rank Math reads additional keywords from the same comma list.
			$keywords = trim( $keywords . ', ' . implode( ', ', array_map( 'strval', $extra ) ), ', ' );
		}

		$map = array(
			'rank_math_title'         => (string) ( $seo['title'] ?? '' ),
			'rank_math_description'   => (string) ( $seo['meta_description'] ?? '' ),
			'rank_math_focus_keyword' => $keywords,
			'rank_math_canonical_url' => (string) ( $seo['canonical'] ?? '' ),
		);

		foreach ( $map as $key => $value ) {
			if ( '' === $value ) {
				continue;
			}
			$existing = get_post_meta( $postId, $key, true );
			if ( '' !== (string) $existing ) {
				continue;
			}
			update_post_meta( $postId, $key, $value );
		}
	}
}
