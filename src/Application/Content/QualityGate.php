<?php
/**
 * QualityGate (§36) — deterministic score 0..100. Below the configured
 * threshold the content is revised (max 2 attempts) and then NEEDS_REVIEW.
 *
 * Rubric (weights explicit so the gate is auditable):
 *  factuality 40 · originality 20 · structure 10 · seo 15 · aeo_geo 10 · readability 5
 *
 * @package NewsDesk\AI\Application\Content
 */

namespace NewsDesk\AI\Application\Content;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\NewsroomSettings;
use NewsDesk\AI\Domain\Entity\Story;

final class QualityGate {

	public const WEIGHTS = array(
		'factuality'  => 35,
		'coverage'    => 15, // v1.6: completeness vs the available evidence
		'originality' => 15,
		'structure'   => 10,
		'seo'         => 12,
		'aeo_geo'     => 8,
		'readability' => 5,
	);

	/** @var NewsroomSettings */
	private $settings;
	/** @var FillerPhraseDetector A-7 — deterministic, no AI. */
	private $filler;

	public function __construct( NewsroomSettings $settings, ?FillerPhraseDetector $filler = null ) {
		$this->settings = $settings;
		$this->filler   = $filler ?: new FillerPhraseDetector();
	}

	public function threshold(): int {
		return $this->settings->contentQualityGate();
	}

	/**
	 * @param array $article sanitized article
	 * @param array $plan    ContentStrategy::plan()
	 * @param array $memory  ContentMemory::build()
	 * @return array{score: float, passed: bool, breakdown: array<string,float>, checks: array<int, array<string, mixed>>}
	 */
	public function score( array $article, array $plan, array $memory, Story $story ): array {
		$checks = array();
		/** @var array<string,float> $breakdown */
		$breakdown = array_fill_keys( array_keys( self::WEIGHTS ), 0.0 );

		// ---------------------------------------------------------------- factuality
		$claimedIds    = $this->allClaimIds( $article );
		$allowedCount  = count( $memory['allowed'] );
		$unknown       = array();
		$statsMiss     = 0; $datesMiss = 0; $quotesMiss = 0; $statCheck = 0; $dateCheck = 0; $quoteCheck = 0;
		foreach ( $claimedIds as $id ) {
			if ( ! isset( $memory['allowed'][ $id ] ) ) {
				$unknown[] = $id;
			}
		}
		foreach ( (array) ( $article['sections'] ?? array() ) as $section ) {
			$ids = (array) ( $section['claim_ids'] ?? array() );
			foreach ( (array) ( $section['paragraphs'] ?? array() ) as $p ) {
				$stats = Grounding::extractStats( $p );
				$dates = Grounding::extractDates( $p );
				$quotes = Grounding::extractQuotes( $p );
				foreach ( $stats as $s ) { $statCheck++; }
				foreach ( $dates as $d ) { $dateCheck++; }
				foreach ( $quotes as $q ) { $quoteCheck++; }
				$errors = ContentComposer::checkParagraphGrounding( $p, $ids, $memory['allowed'] );
				foreach ( $errors as $e ) {
					if ( 0 === strpos( $e, 'STAT_UNGROUNDED' ) ) { $statsMiss++; }
					elseif ( 0 === strpos( $e, 'DATE_UNGROUNDED' ) ) { $datesMiss++; }
					elseif ( 0 === strpos( $e, 'QUOTE_UNGROUNDED' ) ) { $quotesMiss++; }
				}
			}
		}
		$factuality = 40.0;
		if ( $unknown || ! $claimedIds ) {
			$factuality = 0.0;
		} elseif ( $statCheck > 0 && $statsMiss === $statCheck ) {
			$factuality -= 15.0;
		} elseif ( $statCheck > 0 ) {
			$factuality -= 10.0 * ( $statsMiss / $statCheck );
		}
		if ( $dateCheck > 0 && $datesMiss > 0 ) {
			$factuality -= 5.0 * ( $datesMiss / $dateCheck );
		}
		if ( $quoteCheck > 0 && $quotesMiss > 0 ) {
			$factuality -= 8.0 * ( $quotesMiss / $quoteCheck );
		}
		$factuality = max( 0.0, $factuality );
		$breakdown['factuality'] = $factuality;
		$checks[] = array( 'key' => 'factuality', 'score' => $factuality, 'note' => sprintf( 'claims=%d allowed=%d stats=%d/%d dates=%d/%d quotes=%d/%d', count( $claimedIds ), $allowedCount, $statCheck - $statsMiss, $statCheck, $dateCheck - $datesMiss, $dateCheck, $quoteCheck - $quotesMiss, $quoteCheck ) );

		// ---------------------------------------------------------------- coverage (v1.6)
		$covErrors = ContentComposer::coverageErrors( $article, $memory, $plan );
		$usedIds   = array_unique( $claimedIds );
		$usedOk    = 0;
		foreach ( $usedIds as $id ) {
			if ( isset( $memory['allowed'][ $id ] ) ) {
				$usedOk++;
			}
		}
		$coverage = $allowedCount > 0 ? 100.0 * min( 1.0, $usedOk / max( 1, min( $allowedCount, 25 ) ) ) : 0.0;
		foreach ( $covErrors as $e ) {
			if ( 0 === strpos( $e, 'PLATFORM_OMITTED' ) || 0 === strpos( $e, 'PRIVACY_OMITTED' ) ) { $coverage -= 20.0; }
			elseif ( 0 === strpos( $e, 'WHY_IT_MATTERS_MISSING' ) || 0 === strpos( $e, 'NEWS_TYPE_MISMATCH' ) ) { $coverage -= 15.0; }
			elseif ( 0 === strpos( $e, 'FAQ_TOO_FEW' ) ) { $coverage -= 10.0; }
		}
		$coverage = max( 0.0, min( 100.0, $coverage ) );
		$breakdown['coverage'] = $coverage;
		$checks[] = array( 'key' => 'coverage', 'score' => $coverage, 'note' => sprintf( 'facts used=%d/%d; %s', $usedOk, $allowedCount, $covErrors ? implode( '; ', array_map( static function ( $e ) { return strtok( $e, ':' ); }, $covErrors ) ) : 'complete' ) );

		// ---------------------------------------------------------------- originality
		$corpus    = Grounding::normalize( (string) $memory['corpus'] );
		$maxRun    = 0;
		$verbatim  = 0;
		foreach ( (array) ( $article['sections'] ?? array() ) as $section ) {
			$joined = implode( ' ', (array) ( $section['paragraphs'] ?? array() ) );
			foreach ( $memory['allowed'] as $c ) {
				if ( Grounding::containsSnippet( $joined, (string) $c['snippet'] ) ) {
					$verbatim++;
				}
			}
			$maxRun = max( $maxRun, Grounding::longestSharedRun( $joined, $corpus ) );
		}
		$originality = 20.0;
		if ( $verbatim > 0 || $maxRun >= 32 ) {
			$originality = 0.0;
		} elseif ( $maxRun >= 20 ) {
			$originality = 5.0;
		} elseif ( $maxRun >= 14 ) {
			$originality = 10.0;
		} elseif ( $maxRun >= 9 ) {
			$originality = 15.0;
		}
		// A-7: AI filler is an originality failure the grounding check cannot
		// see — cliché prose is not copied from a source, it is just empty.
		// v1.6.0 only asked the model not to do it and never verified.
		$filler = $this->filler->scanArticle( $article );
		if ( $filler['total'] > 0 ) {
			$originality = max( 0.0, $originality - $filler['penalty'] );
		}
		$breakdown['originality'] = $originality;
		$checks[] = array( 'key' => 'originality', 'score' => $originality, 'note' => sprintf( 'longest verbatim run=%d words, verbatim snippets=%d', $maxRun, $verbatim ) );
		$checks[] = array(
			'key'      => 'filler',
			'score'    => $filler['total'] > 0 ? -$filler['penalty'] : 0.0,
			'critical' => $filler['critical'],
			'note'     => $filler['total'] > 0
				? sprintf( 'AI filler phrases=%d: %s', $filler['total'], implode( ' | ', array_slice( $filler['phrases'], 0, 5 ) ) )
				: 'no AI filler detected',
		);

		// ---------------------------------------------------------------- structure
		$sections = (array) ( $article['sections'] ?? array() );
		$totalParas = 0;
		foreach ( $sections as $s ) { $totalParas += count( (array) ( $s['paragraphs'] ?? array() ) ); }
		$structure = 10.0;
		if ( count( $sections ) < 2 || $totalParas < 3 ) { $structure = 0.0; }
		elseif ( count( $sections ) < 3 || $totalParas < 4 ) { $structure = 6.0; }
		$breakdown['structure'] = $structure;
		$checks[] = array( 'key' => 'structure', 'score' => $structure, 'note' => sprintf( 'sections=%d paragraphs=%d', count( $sections ), $totalParas ) );

		// ---------------------------------------------------------------- seo
		$title  = (string) ( $article['title'] ?? '' );
		$meta   = (string) ( $article['meta_description'] ?? '' );
		$tLen   = mb_strlen( $title );
		$mLen   = mb_strlen( $meta );
		$primary = (string) ( $plan['primary_entity'] ?? '' );
		$seo = 0.0;
		if ( '' !== $primary && false !== mb_stripos( $title, $primary ) ) { $seo += 30.0; }
		if ( $tLen >= 30 && $tLen <= 70 ) { $seo += 20.0; } elseif ( $tLen >= 15 && $tLen < 30 ) { $seo += 10.0; }
		if ( $mLen >= 120 && $mLen <= 165 ) { $seo += 25.0; } elseif ( $mLen >= 80 ) { $seo += 12.0; }
		$hits = 0;
		foreach ( $sections as $s ) {
			if ( '' !== $primary && false !== mb_stripos( (string) ( $s['heading'] ?? '' ), $primary ) ) { $hits++; }
		}
		if ( $hits >= 1 ) { $seo += 15.0; } elseif ( $hits >= 0 && count( $sections ) >= 2 ) { $seo += 7.0; }
		$slugProposal = \NewsDesk\AI\Support\Slug::fromTitle( $title );
		if ( '' !== (string) ( $article['slug'] ?? '' ) && strlen( (string) $article['slug'] ) >= 5 ) { $seo += 10.0; }
		elseif ( '' !== $slugProposal && strlen( $slugProposal ) >= 5 ) { $seo += 10.0; }
		$seo = min( 100.0, $seo );
		$breakdown['seo'] = $seo;
		$checks[] = array( 'key' => 'seo', 'score' => $seo, 'note' => sprintf( 'title=%d chars (entity:%s) meta=%d chars heading_hits=%d slug_len=%d', $tLen, $primary ? 'yes' : 'no', $mLen, $hits, strlen( (string) ( $article['slug'] ?? '' ) ) ) );

		// ---------------------------------------------------------------- aeo + geo
		$lead = (string) ( $article['lead'] ?? '' );
		$leadWords = count( preg_split( '/\s+/u', trim( $lead ) ) );
		$aeoGeo = 0.0;
		if ( $leadWords >= 35 && $leadWords <= 90 ) { $aeoGeo += 35.0; }
		elseif ( $leadWords >= 25 ) { $aeoGeo += 18.0; }
		if ( '' !== $primary && false !== mb_stripos( $lead, $primary ) ) { $aeoGeo += 25.0; }
		if ( ! empty( $article['faq'] ) ) { $aeoGeo += 15.0; }
		$withUrl = 0;
		foreach ( $memory['allowed'] as $c ) {
			if ( ! empty( $c['source_url'] ) ) { $withUrl++; }
		}
		if ( $allowedCount > 0 ) { $aeoGeo += 30.0 * min( 1.0, $withUrl / $allowedCount ); }
		$aeoGeo = min( 100.0, $aeoGeo );
		$breakdown['aeo_geo'] = $aeoGeo;
		$checks[] = array( 'key' => 'aeo_geo', 'score' => $aeoGeo, 'note' => sprintf( 'lead_words=%d faq=%d cited_claims=%d/%d', $leadWords, count( (array) ( $article['faq'] ?? array() ) ), $withUrl, $allowedCount ) );

		// ---------------------------------------------------------------- readability
		$paraLens = array();
		foreach ( (array) ( $article['sections'] ?? array() ) as $s ) {
			foreach ( (array) ( $s['paragraphs'] ?? array() ) as $p ) { $paraLens[] = mb_strlen( (string) $p ); }
		}
		$avg = $paraLens ? array_sum( $paraLens ) / count( $paraLens ) : 0;
		$readability = 5.0;
		if ( $avg < 40 || $avg > 900 ) { $readability = 1.0; }
		elseif ( $avg < 60 ) { $readability = 3.0; }
		$breakdown['readability'] = $readability;
		$checks[] = array( 'key' => 'readability', 'score' => $readability, 'note' => sprintf( 'avg paragraph=%d chars', (int) round( $avg ) ) );

		// Axes are scored on their rubrics native ranges (factuality 0..40,
		// originality 0..20, …). Normalize every axis to 0..100, then apply the
		// weight: final = Σ axisNorm × weight/100 → max 100 (gate 90 default).
		$axisMax = array(
			'factuality'  => 40.0,
			'coverage'    => 100.0,
			'originality' => 20.0,
			'structure'   => 10.0,
			'seo'         => 100.0,
			'aeo_geo'     => 100.0,
			'readability' => 5.0,
		);
		$weighted = array();
		$normalized = array();
		foreach ( $breakdown as $axis => $axisScore ) {
			$normalized[ $axis ] = $axisMax[ $axis ] > 0 ? min( 100.0, $axisScore * ( 100.0 / $axisMax[ $axis ] ) ) : $axisScore;
			$weighted[ $axis ]   = round( $normalized[ $axis ] * ( self::WEIGHTS[ $axis ] / 100.0 ), 2 );
		}
		foreach ( $checks as &$check ) {
			if ( isset( $normalized[ $check['key'] ] ) ) {
				$check['score'] = round( $normalized[ $check['key'] ], 1 );
			}
		}
		unset( $check );
		$score     = array_sum( $weighted );
		$threshold = (float) $this->threshold();

		// Spec: a CRITICAL check blocks the draft regardless of the total.
		// A piece can score well on structure and SEO and still be unpublishable.
		$critical = array();
		foreach ( $checks as $check ) {
			if ( ! empty( $check['critical'] ) ) {
				$critical[] = (string) $check['key'];
			}
		}

		return array(
			'score'     => round( $score, 2 ),
			'passed'    => $score >= $threshold && ! $critical,
			'breakdown' => $weighted,
			'checks'    => $checks,
			'critical'  => $critical,
		);
	}

	/** @return string[] */
	private function allClaimIds( array $article ): array {
		$ids = array();
		foreach ( (array) ( $article['sections'] ?? array() ) as $s ) {
			foreach ( (array) ( $s['claim_ids'] ?? array() ) as $id ) { $ids[] = $id; }
		}
		foreach ( (array) ( $article['faq'] ?? array() ) as $f ) {
			foreach ( (array) ( $f['claim_ids'] ?? array() ) as $id ) { $ids[] = $id; }
		}
		return array_values( array_unique( $ids ) );
	}
}
