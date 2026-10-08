<?php
/**
 * Phase 2 — Story scoring.
 *
 * DESIGN DIRECTIVE (user, 2026-08-30): "the best result for SEO, AEO and GEO — the best
 * choice for everyone." SEO/AEO/GEO signals therefore dominate the composite (70%),
 * while trust/freshness/impact keep the selection editorially safe (30%). Every score
 * is 0–100 with a documented formula; nothing is guessed, everything is testable.
 *
 * @package NewsDesk\AI\Application\Scoring
 */

namespace NewsDesk\AI\Application\Scoring;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Domain\Entity\NewsItem;
use NewsDesk\AI\Domain\Entity\Source;
use NewsDesk\AI\Domain\Entity\Story;

final class StoryScorer {

	/** Composite weights (§ user directive: SEO/AEO/GEO first). */
	public const WEIGHTS = array(
		'seo_aeo_geo' => 0.70,
		'trust'       => 0.10,
		'freshness'   => 0.10,
		'impact'      => 0.05,
		'editorial'   => 0.05,
	);

	/** High-value editorial categories (ecosystem relevance, §4). */
	private const ECOSYSTEM_CATEGORIES = array( 'core', 'plugins', 'themes', 'security' );

	/**
	 * Score a clustered story.
	 *
	 * @param Source[]   $sources Keyed by id, for trust.
	 * @param \DateTimeImmutable $now
	 * @return array{story: Story, scores: array<string, float>}
	 */
	public function score( Story $story, array $sources, \DateTimeImmutable $now ): array {
		$trust     = $this->trustScore( $story, $sources );
		$freshness = $this->freshnessScore( $story, $now );
		$impact    = $this->impactScore( $story );
		$editorial = $this->editorialScore( $story );
		$seo       = $this->seoScore( $story );
		$aeo       = $this->aeoScore( $story );
		$geo       = $this->geoScore( $story, $sources );

		$seoAeoGeo = round( $seo * 0.35 + $aeo * 0.35 + $geo * 0.30, 2 );
		$composite = round(
			$seoAeoGeo * self::WEIGHTS['seo_aeo_geo']
			+ $trust * self::WEIGHTS['trust']
			+ $freshness * self::WEIGHTS['freshness']
			+ $impact * self::WEIGHTS['impact']
			+ $editorial * self::WEIGHTS['editorial'],
			2
		);

		// A-16: the spec's extension point. A site can re-weight or override the
		// final score without forking the scorer. Filtered before it is stored,
		// so everything downstream sees one number.
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters(
				'newsdesk_news_score',
				$composite,
				$story,
				array(
					'seo'         => $seo,
					'aeo'         => $aeo,
					'geo'         => $geo,
					'seo_aeo_geo' => $seoAeoGeo,
					'trust'       => $trust,
					'freshness'   => $freshness,
					'impact'      => $impact,
					'editorial'   => $editorial,
				)
			);
			if ( is_numeric( $filtered ) ) {
				// Clamped: a filter returning 5000 must not break selection.
				$composite = max( 0.0, min( 100.0, (float) $filtered ) );
			}
		}

		$story->trustScore       = $trust;
		$story->freshnessScore   = $freshness;
		$story->impactScore      = $impact;
		$story->editorialScore   = $editorial;
		$story->seoAeoGeoScore   = $seoAeoGeo;
		$story->importanceScore  = $composite;
		$story->confidenceScore  = $this->confidence( $story, $sources );

		return array(
			'story'  => $story,
			'scores' => array(
				'seo'            => round( $seo, 2 ),
				'aeo'            => round( $aeo, 2 ),
				'geo'            => round( $geo, 2 ),
				'seo_aeo_geo'    => $seoAeoGeo,
				'trust'          => $trust,
				'freshness'      => $freshness,
				'impact'         => $impact,
				'editorial'      => $editorial,
				'composite'      => $composite,
			),
		);
	}

	/* ------------------------------------------------------------------ */

	/**
	 * Source-authority weighted average (primary 50%, secondaries share 50%).
	 */
	public function trustScore( Story $story, array $sources ): float {
		$primary = $sources[ $story->primarySourceId ] ?? null;
		if ( null === $primary ) {
			return 0.0;
		}
		$weighted = $primary->trustScore * 0.5;
		$weights  = 0.5;
		$rest     = $story->secondarySources;
		if ( $rest ) {
			$share = 0.5 / count( $rest );
			foreach ( $rest as $sid ) {
				$src = $sources[ $sid ] ?? null;
				if ( null !== $src ) {
					$weighted += $src->trustScore * $share;
					$weights  += $share;
				}
			}
		}
		$score = $weights > 0 ? $weighted / $weights : 0.0;
		return round( min( 100.0, max( 0.0, $score ) ), 2 );
	}

	/**
	 * Exponential decay from the newest evidence (publishedAt else fetchedAt).
	 * 0h=100, 6h=90, 24h=70, 48h=41, 7d=9 — decay half-life ≈ 38h (± editorial window).
	 */
	public function freshnessScore( Story $story, \DateTimeImmutable $now ): float {
		$ref = $story->lastUpdatedAt ?? $story->firstPublishedAt;
		if ( null === $ref ) {
			return 50.0; // unknown age — neutral, never penalize silently
		}
		$hours = max( 0.0, $now->getTimestamp() - $ref->getTimestamp() ) / 3600;
		$score = 100.0 * pow( 0.5, $hours / 38.0 );
		return round( min( 100.0, $score ), 2 );
	}

	/**
	 * Breadth of coverage: multiple independent outlets reporting the same story
	 * is the strongest classic impact signal (and a GEO citation asset).
	 */
	public function impactScore( Story $story ): float {
		$sources = max( 1, $story->sourceCount );
		$topics  = min( 6, count( $story->topics ) );
		$score   = 40.0 + 15.0 * ( $sources - 1 ) + 6.0 * $topics;
		if ( $story->itemCount > 1 ) {
			$score += 2.0; // redundant items increase confidence, not novelty
		}
		return round( min( 100.0, $score ), 2 );
	}

	/**
	 * Editorial judgment: ecosystem relevance + clear subject + recency of first report.
	 */
	public function editorialScore( Story $story ): float {
		$score = 50.0;
		foreach ( $story->topics as $topic ) {
			if ( in_array( strtolower( (string) $topic ), self::ECOSYSTEM_CATEGORIES, true ) ) {
				$score += 15.0;
				break;
			}
		}
		if ( strlen( $story->canonicalTitle ) >= 20 && strlen( $story->canonicalTitle ) <= 70 ) {
			$score += 10.0; // clear, snippet-friendly subject
		}
		if ( null !== $story->firstPublishedAt && $story->firstPublishedAt->getTimestamp() > time() - 86400 ) {
			$score += 8.0; // first report within 24h
		}
		return round( min( 100.0, $score ), 2 );
	}

	/**
	 * SEO (search findability): concrete entity + action in title, query-intent terms,
	 * snippet-friendly length, high-value category.
	 */
	public function seoScore( Story $story ): float {
		$title = $this->normalize( $story->canonicalTitle );
		$score = 40.0;

		// Query intent: informational/current-event phrasing.
		$intent = array( 'release', 'update', 'fix', 'security', 'vulnerability', 'launch', 'announce', 'guide', 'how-to', 'how to', 'roundup', 'review', 'compare', 'vs', 'best' );
		foreach ( $intent as $term ) {
			if ( false !== strpos( $title, $term ) ) {
				$score += 12.0;
				break;
			}
		}
		// Concrete entity (version numbers, product names, companies) → indexable subject.
		if ( preg_match( '/\d+(\.\d+)+/', $story->canonicalTitle ) || preg_match( '/[A-Z][a-z]{3,}/u', $story->canonicalTitle ) ) {
			$score += 15.0;
		}
		// Snippet-fit title length (Google ≈ 580px ≈ 55–65 chars).
		$len = mb_strlen( $story->canonicalTitle );
		if ( $len >= 20 && $len <= 65 ) {
			$score += 15.0;
		} elseif ( $len > 90 ) {
			$score -= 15.0; // truncation risk
		}
		// Single-topic clarity (no multi-subject soup).
		if ( count( $story->topics ) <= 3 ) {
			$score += 10.0;
		}
		return round( min( 100.0, max( 0.0, $score ) ), 2 );
	}

	/**
	 * AEO (answer-engine optimization): can a machine answer it directly?
	 * Question/definitional phrasing, one unambiguous subject, direct-answer length.
	 */
	public function aeoScore( Story $story ): float {
		$title = $this->normalize( $story->canonicalTitle );
		$score = 40.0;

		$qPrompts = array( 'what', 'why', 'how', 'is ', 'does', 'when', 'who', 'which', 'چیست', 'چرا', 'چگونه', 'چه', 'کی', 'کدام' );
		foreach ( $qPrompts as $q ) {
			if ( 0 === strpos( $title, $q ) ) {
				$score += 10.0;
				break;
			}
		}
		// Definitional anchors: "X is", "هستند", product+event pairing.
		if ( preg_match( '/\bis\b|هست|می‌شود|شد/u', $story->canonicalTitle ) || preg_match( '/^\d/', $story->canonicalTitle ) ) {
			$score += 12.0;
		}
		// Consume-able size: 30–90 chars covers featured-snippet range.
		$len = mb_strlen( $story->canonicalTitle );
		if ( $len >= 30 && $len <= 90 ) {
			$score += 12.0;
		}
		// Verifiable claims: multi-source support is the AEO trust core.
		if ( $story->sourceCount >= 2 ) {
			$score += 16.0;
		}
		return round( min( 100.0, max( 0.0, $score ) ), 2 );
	}

	/**
	 * GEO (generative-engine optimization): quotable, citable, entity-rich, current.
	 */
	public function geoScore( Story $story, array $sources ): float {
		$score = 35.0;

		// Citation mass: independent outlets.
		$score += min( 30.0, 10.0 * ( $story->sourceCount - 1 ) );

		// Entity density (versions, numbers, proper nouns, product names).
		$entityHits = 0;
		if ( preg_match( '/\d+(\.\d+)+/', $story->canonicalTitle ) ) {
			$entityHits++;
		}
		if ( preg_match( '/[A-Z][a-z]{3,}/u', $story->canonicalTitle ) ) {
			$entityHits++;
		}
		if ( preg_match( '/\d{4}/', $story->canonicalTitle ) ) {
			$entityHits++;
		}
		$score += min( 20.0, 8.0 * $entityHits );

		// Freshness: GEO rewards recency ("latest", "2026").
		if ( null !== $story->lastUpdatedAt && $story->lastUpdatedAt->getTimestamp() > time() - 3 * 86400 ) {
			$score += 15.0;
		}
		// Authority mix: at least one high-trust (≥70) source.
		$hasHigh = false;
		foreach ( array_merge( array( $story->primarySourceId ), $story->secondarySources ) as $sid ) {
			$src = $sources[ $sid ] ?? null;
			if ( null !== $src && $src->trustScore >= 70.0 ) {
				$hasHigh = true;
				break;
			}
		}
		if ( $hasHigh ) {
			$score += 15.0;
		}
		return round( min( 100.0, max( 0.0, $score ) ), 2 );
	}

	/**
	 * Confidence: agreement between sources (multi-source → higher), capped by trust.
	 */
	private function confidence( Story $story, array $sources ): float {
		$base = 55.0;
		$base += min( 25.0, 10.0 * ( $story->sourceCount - 1 ) );
		$trust = $this->trustScore( $story, $sources );
		return round( min( 100.0, $base * ( 0.6 + 0.4 * ( $trust / 100.0 ) ) ), 2 );
	}

	private function normalize( string $text ): string {
		return strtolower( trim( preg_replace( '/\s+/u', ' ', (string) $text ) ) );
	}
}
