<?php
/**
 * Phase 2 — Story clustering (§13, §5).
 *
 * One Story aggregates one or more NewsItems from different sources; a Story is NEVER
 * converted into multiple articles. Clustering is deterministic and idempotent:
 * the same window re-run produces the same cluster_key set.
 *
 * @package NewsDesk\AI\Application\Scoring
 */

namespace NewsDesk\AI\Application\Scoring;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Domain\Entity\NewsItem;
use NewsDesk\AI\Domain\Entity\Source;
use NewsDesk\AI\Domain\Entity\Story;
use NewsDesk\AI\Support\Str;

final class StoryClusterer {

	/** Persian + English stopwords (titles only — content is untouched). */
	private const STOPWORDS = array(
		'the', 'a', 'an', 'of', 'in', 'on', 'at', 'for', 'with', 'to', 'is', 'are', 'and', 'or',
		'بررسی', 'خبر', 'گزارش', 'تازه', 'جدید', 'و', 'در', 'با', 'از', 'به', 'برای', 'این',
	);

	/**
	 * Neutral event verbs: "X released" and "X is here" are the same story (§5).
	 * Version tokens are intentionally NOT verbs — they stay in the cluster key.
	 */
	private const EVENT_VERBS = array(
		'release', 'released', 'releases', 'announce', 'announced', 'announces',
		'launch', 'launched', 'launches', 'unveil', 'unveiled', 'unveils',
		'introduce', 'introduced', 'introduces', 'ship', 'shipped', 'ships',
		'here', 'out', 'now', 'live', 'update', 'updated', 'updates',
		'fix', 'fixes', 'fixed', 'add', 'adds', 'added', 'gets', 'get',
		'available', 'coming', 'arrives', 'arrived', 'debuts', 'debuted',
	);

	/**
	 * Cluster normalized items into Story drafts.
	 *
	 * @param NewsItem[] $items
	 * @param Source[]   $sources Keyed by id (primary selection).
	 * @return Story[]   Unsaved stories (storyId=0), one per cluster.
	 */
	public function cluster( array $items, array $sources ): array {
		$groups = array();
		foreach ( $items as $item ) {
			$key = $this->clusterKeyOf( $item );
			if ( '' === $key ) {
				continue; // no usable title/link → not clusterable
			}
			$groups[ $key ][] = $item;
		}

		$stories = array();
		foreach ( $groups as $key => $group ) {
			$stories[] = $this->buildStory( $key, $group, $sources );
		}

		usort( $stories, static function ( $a, $b ) {
			return $b->sourceCount <=> $a->sourceCount;
		} );
		return $stories;
	}

	/**
	 * Deterministic cluster key: sha256 over significant title tokens + canonical URL.
	 * Same story from N sources → same key → same Story row (no article multiplication).
	 */
	public function clusterKeyOf( NewsItem $item ): string {
		$tokens = $this->titleTokens( $item->normalizedTitle );
		if ( empty( $tokens ) ) {
			// Title-less items fall back to their canonical URL alone.
			$canonical = $item->canonicalUrl;
			return '' !== $canonical ? hash( 'sha256', 'url:' . Str::lower( $canonical ) ) : '';
		}
		sort( $tokens );
		return hash( 'sha256', implode( ' ', $tokens ) );
	}

	/**
	 * @return string[]
	 */
	public function titleTokens( string $normalizedTitle ): array {
		// Dots are meaningful (version tokens: 6.9, 9.8) — keep them.
		$clean = preg_replace( '/[^\p{L}\p{N}\s.-]/u', ' ', (string) $normalizedTitle );
		$words = preg_split( '/\s+/u', trim( (string) $clean ) );
		if ( ! is_array( $words ) ) {
			return array();
		}
		$out = array();
		foreach ( $words as $word ) {
			$word = trim( $word, " \t\n\r\0\x0B-" );
			$word = Str::lower( $word );
			if ( '' === $word || mb_strlen( $word ) < 3 ) {
				continue; // tokens < 3 chars are noise for Persian/English
			}
			if ( in_array( $word, self::STOPWORDS, true ) || in_array( $word, self::EVENT_VERBS, true ) ) {
				continue;
			}
			$out[] = $word;
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * @param NewsItem[] $group
	 * @param Source[]   $sources
	 */
	private function buildStory( string $key, array $group, array $sources ): Story {
		$story = new Story();
		$story->clusterKey = $key;

		// Primary = highest-trust item's source (ties → earliest published).
		usort( $group, static function ( $a, $b ) use ( $sources ) {
			$ta = isset( $sources[ $a->sourceId ] ) ? $sources[ $a->sourceId ]->trustScore : 0.0;
			$tb = isset( $sources[ $b->sourceId ] ) ? $sources[ $b->sourceId ]->trustScore : 0.0;
			if ( abs( $ta - $tb ) > 0.001 ) {
				return $tb <=> $ta;
			}
			$pa = $a->publishedAt ? $a->publishedAt->getTimestamp() : PHP_INT_MAX;
			$pb = $b->publishedAt ? $b->publishedAt->getTimestamp() : PHP_INT_MAX;
			return $pa <=> $pb;
		} );

		$primary = $group[0];
		$story->primarySourceId   = $primary->sourceId;
		$story->canonicalTitle    = $primary->title;
		$story->language          = $primary->language;

		$first = null;
		$last  = null;
		$topics = array();
		$ids    = array();
		$sids   = array();
		foreach ( $group as $item ) {
			$ids[] = $item->id;
			if ( $item->sourceId !== $story->primarySourceId ) {
				$sids[] = $item->sourceId;
			}
			foreach ( $item->categories as $cat ) {
				$cat = trim( (string) $cat );
				if ( '' !== $cat ) {
					$topics[] = $cat;
				}
			}
			$published = $item->publishedAt;
			if ( null !== $published ) {
				if ( null === $first || $published < $first ) {
					$first = $published;
				}
				if ( null === $last || $published > $last ) {
					$last = $published;
				}
			}
			$fetched = $item->fetchedAt;
			if ( null !== $fetched && ( null === $last || $fetched > $last ) ) {
				$last = $fetched;
			}
		}

		$story->firstPublishedAt    = $first;
		$story->lastUpdatedAt       = $last;
		$story->topics              = array_values( array_unique( $topics ) );
		$story->itemIds             = $ids;
		$story->itemCount           = count( $ids );
		$story->secondarySources    = array_values( array_unique( $sids ) );
		$story->sourceCount         = count( array_unique( array_merge( array( $story->primarySourceId ), $sids ) ) );
		$story->status              = Story::STATUS_CANDIDATE;
		$story->editorialStrategy   = $this->strategyFor( $story->topics );

		// "external source attribution" is prepared here (Phase 4 uses it): the
		// primary/secondary source ids travel with the story, never merged into text.
		$story->aeoSignals = array(
			'source_count' => $story->sourceCount,
			'item_count'   => $story->itemCount,
			'topics'       => $story->topics,
			'cluster_key'  => $key,
		);

		return $story;
	}

	private function strategyFor( array $topics ): ?string {
		foreach ( $topics as $topic ) {
			$t = strtolower( (string) $topic );
			if ( in_array( $t, array( 'core', 'plugins', 'themes', 'security' ), true ) ) {
				return $t;
			}
		}
		return 'general';
	}
}
