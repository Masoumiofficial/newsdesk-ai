<?php
/**
 * Phase 2 — Editorial selection (§14, §83): picks the stories that give the best
 * SEO/AEO/GEO result within one run window, and records WHY (selection_reason).
 *
 * Results are never articles; selection is a decision record only.
 * `NO_PUBLISHABLE_STORY_FOUND` is a legitimate, expected outcome (no noise, no junk).
 *
 * @package NewsDesk\AI\Application\Scoring
 */

namespace NewsDesk\AI\Application\Scoring;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Domain\Entity\Story;

final class EditorialSelector {

	public const OUTCOME_SELECTED                = 'SELECTED';
	public const OUTCOME_NO_PUBLISHABLE_STORY    = 'NO_PUBLISHABLE_STORY_FOUND';
	public const OUTCOME_ALREADY_SELECTED        = 'ALREADY_SELECTED_IN_WINDOW';

	/**
	 * @param Story[] $candidates      Scored candidate stories (desc by editorial_score).
	 * @param array   $opts            max_stories (default 3), min_composite (60),
	 *                                 min_trust (40), window_key, now (DateTimeImmutable),
	 *                                 recentClusterKeys (string[] already selected recently).
	 * @return array{outcome: string, selected: Story[], reasons: array<int,string>, gates: array<int,string>}
	 */
	public function select( array $candidates, array $opts = array() ): array {
		$maxStories   = max( 1, (int) ( $opts['max_stories'] ?? 3 ) );
		$minComposite = max( 0.0, (float) ( $opts['min_composite'] ?? 60.0 ) );
		$minTrust     = max( 0.0, (float) ( $opts['min_trust'] ?? 40.0 ) );
		$recent       = (array) ( $opts['recent_cluster_keys'] ?? array() );

		// Best choice first — the module must never prefer a weaker story.
		$candidates = array_values( $candidates );
		usort( $candidates, static function ( $a, $b ) {
			return $b->importanceScore <=> $a->importanceScore;
		} );

		/** @var Story[] $selected */
		$selected = array();
		$reasons  = array();
		$gates    = array();

		foreach ( $candidates as $story ) {
			if ( count( $selected ) >= $maxStories ) {
				$gates[ $story->storyId ] = 'CAPACITY_REACHED';
				continue;
			}
			if ( in_array( $story->clusterKey, $recent, true ) ) {
				$gates[ $story->storyId ] = 'ALREADY_SELECTED_RECENTLY';
				continue;
			}
			if ( $story->trustScore < $minTrust ) {
				$gates[ $story->storyId ] = 'BELOW_TRUST_GATE';
				continue;
			}
			if ( $story->importanceScore < $minComposite ) {
				$gates[ $story->storyId ] = 'BELOW_QUALITY_GATE';
				continue;
			}

			$selected[]     = $story;
			$reasons[ $story->storyId ] = $this->reasonFor( $story );
		}

		// NO_PUBLISHABLE_STORY_FOUND is a VALID outcome (§14): no fabrication, no filler.
		$outcome = empty( $selected ) ? self::OUTCOME_NO_PUBLISHABLE_STORY : self::OUTCOME_SELECTED;

		return array(
			'outcome'  => $outcome,
			'selected' => $selected,
			'reasons'  => $reasons,
			'gates'    => $gates,
		);
	}

	/**
	 * Human-readable WHY — the score card that travels into the draft metadata
	 * (Phase 5) and the admin UI (Phase 2). Numbers only, no invented claims.
	 */
	private function reasonFor( Story $story ): string {
		$bits = array();
		$sources = $story->sourceCount;
		if ( $sources >= 2 ) {
			$bits[] = sprintf( '%d independent sources corroborate', $sources );
		}
		if ( null !== $story->lastUpdatedAt ) {
			$bits[] = sprintf( 'evidence %s hours old', max( 0, (int) round( ( time() - $story->lastUpdatedAt->getTimestamp() ) / 3600 ) ) );
		}
		if ( $story->seoAeoGeoScore >= 70 ) {
			$bits[] = 'high SEO/AEO/GEO potential (' . $story->seoAeoGeoScore . '/100)';
		} elseif ( $story->seoAeoGeoScore >= 55 ) {
			$bits[] = 'solid search & answer-engine fit (' . $story->seoAeoGeoScore . '/100)';
		}
		$bits[] = sprintf( 'composite %s/100', $story->importanceScore );
		return implode( ' · ', $bits );
	}
}
