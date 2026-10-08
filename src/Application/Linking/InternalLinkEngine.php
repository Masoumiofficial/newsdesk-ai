<?php
/**
 * InternalLinkEngine (layer 15) — deterministic suggestions only. The engine
 * matches the story's entities/topics against existing post titles and returns
 * candidates (anchor = entity). Nothing is ever inserted into posts here —
 * suggestions stay in nd_internal_links for the human editor (§73).
 *
 * @package NewsDesk\AI\Application\Linking
 */

namespace NewsDesk\AI\Application\Linking;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Contracts\LinkRepositoryInterface;
use NewsDesk\AI\Application\Contracts\WpPostIndexInterface;
use NewsDesk\AI\Domain\Entity\InternalLinkSuggestion;
use NewsDesk\AI\Support\Time;

final class InternalLinkEngine {

	/** @var WpPostIndexInterface */
	private $index;
	/** @var LinkRepositoryInterface */
	private $links;

	public function __construct( WpPostIndexInterface $index, LinkRepositoryInterface $links ) {
		$this->index = $index;
		$this->links = $links;
	}

	/** How many published posts to scan when the caller does not say. */
	const DEFAULT_SCAN_POOL = 200;

	/**
	 * Suggest internal links for a story.
	 *
	 * @param string   $primaryEntity Main entity of the story.
	 * @param string[] $topics        Secondary topics.
	 * @param int      $limit         Max suggestions to return (NOT the scan size).
	 * @param int      $scanPool      How many recent posts to consider as targets.
	 *
	 * @return InternalLinkSuggestion[] Persisted suggestions, best match first.
	 */
	public function suggest( string $primaryEntity, array $topics, int $limit = 10, int $scanPool = self::DEFAULT_SCAN_POOL ): array {
		$limit = max( 1, $limit );
		// v2.0 fix (B-4): $limit used to be forwarded to existingPosts(), so a
		// request for 10 links only ever *looked at* the 10 newest posts. The
		// candidate pool is now independent of — and far larger than — the
		// number of suggestions returned.
		$scanPool = max( $limit, min( 200, $scanPool ) );
		$posts    = $this->index->existingPosts( $scanPool );

		$terms     = array_values( array_filter( array_merge( array( $primaryEntity ), array_map( 'strval', $topics ) ) ) );
		$normTerms = array();
		foreach ( $terms as $t ) {
			$normTerms[] = mb_strtolower( trim( $t ), 'UTF-8' );
		}
		// The primary entity outranks topics when both match a post.
		$primaryNorm = $normTerms[0] ?? '';

		$candidates = array();
		$seenPosts  = array();
		foreach ( $posts as $post ) {
			$postId = (int) ( $post['post_id'] ?? 0 );
			if ( $postId <= 0 || isset( $seenPosts[ $postId ] ) ) {
				continue;
			}
			$title     = (string) ( $post['title'] ?? '' );
			$titleNorm = mb_strtolower( $title, 'UTF-8' );
			$bestTerm  = '';
			$bestLen   = 0;
			$isPrimary = false;
			foreach ( $normTerms as $i => $term ) {
				if ( '' === $term ) {
					continue;
				}
				if ( false !== mb_strpos( $titleNorm, $term ) && mb_strlen( $term ) > $bestLen ) {
					$bestTerm  = $terms[ $i ];
					$bestLen   = mb_strlen( $term );
					$isPrimary = ( '' !== $primaryNorm && $term === $primaryNorm );
				}
			}
			if ( '' === $bestTerm ) {
				continue;
			}
			$seenPosts[ $postId ] = true;
			$candidates[]         = array(
				'post_id'   => $postId,
				'title'     => $title,
				'anchor'    => $bestTerm,
				'len'       => $bestLen,
				'primary'   => $isPrimary,
				// Longer anchors are more specific; a primary-entity hit wins ties.
				'score'     => ( $isPrimary ? 1000 : 0 ) + $bestLen,
			);
		}

		// Rank before truncating so "top 10" means the 10 best matches in the
		// whole pool, not the first 10 encountered.
		usort(
			$candidates,
			static function ( array $a, array $b ): int {
				if ( $a['score'] === $b['score'] ) {
					return $a['post_id'] <=> $b['post_id'];
				}
				return $b['score'] <=> $a['score'];
			}
		);
		$candidates = array_slice( $candidates, 0, $limit );

		$suggestions = array();
		foreach ( $candidates as $c ) {
			$s               = new InternalLinkSuggestion();
			$s->targetPostId = $c['post_id'];
			$s->anchor       = $c['anchor'];
			$s->placement    = 'content';
			$s->confidence   = $c['primary'] ? 0.9 : 0.75;
			$s->reason       = sprintf( 'Entity "%s" matches existing post "%s"', $c['anchor'], $c['title'] );
			$s->status       = InternalLinkSuggestion::STATUS_SUGGESTED;
			$s->createdAt    = Time::now();
			if ( $this->links->insertInternal( $s ) > 0 ) {
				$suggestions[] = $s;
			}
		}
		return $suggestions;
	}
}
