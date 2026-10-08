<?php
/**
 * Fact verification (§17): cross-source rules FIRST (deterministic, deterministic wins —
 * §19 business rules dominate), AI verdicts recorded for editorial on top.
 *
 * @package NewsDesk\AI\Application\Research
 */

namespace NewsDesk\AI\Application\Research;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Ai\AiGateway;
use NewsDesk\AI\Application\Ai\Exception\NonRetryableProviderException;
use NewsDesk\AI\Application\Ai\Exception\RetryableProviderException;
use NewsDesk\AI\Application\Ai\Prompts;
use NewsDesk\AI\Application\Contracts\ResearchRepositoryInterface;
use NewsDesk\AI\Application\FactCheck\ClaimRiskAssessor;
use NewsDesk\AI\Application\NewsroomSettings;
use NewsDesk\AI\Domain\Entity\EvidenceClaim;
use NewsDesk\AI\Domain\Entity\FactCheckRecord;
use NewsDesk\AI\Domain\Entity\NewsItem;
use NewsDesk\AI\Domain\Entity\Story;
use NewsDesk\AI\Logging\Contracts\LoggerInterface;
use NewsDesk\AI\Support\Time;

final class FactCheckEngine {

	private const MAX_AI_CHECKS = 6;

	/** @var ResearchRepositoryInterface */
	private $research;
	/** @var AiGateway */
	private $gateway;
	/** @var NewsroomSettings */
	private $settings;
	/** @var LoggerInterface */
	private $logger;

	/** @var ClaimRiskAssessor A-4 */
	private $riskAssessor;

	public function __construct( ResearchRepositoryInterface $research, AiGateway $gateway, NewsroomSettings $settings, LoggerInterface $logger, ?ClaimRiskAssessor $riskAssessor = null ) {
		$this->riskAssessor = $riskAssessor ?: new ClaimRiskAssessor();
		$this->research  = $research;
		$this->gateway   = $gateway;
		$this->settings  = $settings;
		$this->logger    = $logger;
	}

	/**
	 * @param NewsItem[] $items
	 * @return array{checked:int, verdicts:array<string,int>, has_contradictions:bool,
	 *               risk:array<string,int>, actions:array<string,int>, must_remove:string[]}
	 */
	public function run( Story $story, array $items, int $jobId ): array {
		$claims = $this->research->claimsForStory( $story->storyId );
		$out    = array( 'checked' => 0, 'verdicts' => array(), 'has_contradictions' => false, 'risk' => array(), 'actions' => array(), 'must_remove' => array() );

		if ( ! $claims ) {
			return $out;
		}

		$conflicts = $this->conflictValues( $claims );
		$normalizedItems = array();
		foreach ( $items as $item ) {
			$normalizedItems[ (int) $item->id ] = array(
				'source_id' => (int) $item->sourceId,
				'text'      => mb_strtolower( $this->normalizeSpace( (string) $item->contentText . ' ' . $item->title . ' ' . $item->excerpt ) ), // v1.3.1: case-insensitive matching
			);
		}

		$aiBudgetLeft = self::MAX_AI_CHECKS;
		foreach ( $claims as $claim ) {
			$verdict  = $this->ruleVerdict( $claim, $normalizedItems, $conflicts );
			$status   = $this->statusFor( $verdict );
			$this->research->insertFactCheck( $this->record( $story, $claim, $jobId, 'cross_source', '', '', $verdict, $this->rationale( $verdict, $claim ) ) );
			if ( $status !== $claim->verificationStatus ) {
				$this->research->updateClaimStatus( $claim->claimId, $status, Time::now() );
			}
			$out['checked']++;
			$out['verdicts'][ $verdict ] = ( $out['verdicts'][ $verdict ] ?? 0 ) + 1;
			if ( EvidenceClaim::STATUS_CONTRADICTED === $status ) {
				$out['has_contradictions'] = true;
			}

			// A-4: the status says what we know; the risk/action say what the
			// writer must DO about it. Assessed on the post-check status.
			$claim->verificationStatus = $status;
			$assessment                = $this->riskAssessor->assess( $claim );
			$this->research->updateClaimRisk( $claim->claimId, $assessment['risk'], $assessment['action'] );
			$out['risk'][ $assessment['risk'] ]       = ( $out['risk'][ $assessment['risk'] ] ?? 0 ) + 1;
			$out['actions'][ $assessment['action'] ]  = ( $out['actions'][ $assessment['action'] ] ?? 0 ) + 1;
			if ( EvidenceClaim::ACTION_REMOVE === $assessment['action'] ) {
				$out['must_remove'][] = $claim->claimId;
			}
		}

		// AI cross-check (recorded for editorial; rules keep the final say §19).
		if ( $this->settings->aiFactCheckEnabled() && $aiBudgetLeft > 0 ) {
			$aiCount = 0;
			foreach ( $claims as $claim ) {
				if ( $aiCount >= self::MAX_AI_CHECKS ) {
					break;
				}
				if ( EvidenceClaim::STATUS_CONTRADICTED === $claim->verificationStatus ) {
					continue;
				}
				$verdict = $this->aiVerdict( $story, $claim, $items, $jobId );
				if ( null === $verdict ) {
					continue;
				}
				$this->research->insertFactCheck( $this->record( $story, $claim, $jobId, 'ai', $verdict['provider'], $verdict['model'], $verdict['verdict'], $verdict['rationale'] ) );
				$aiCount++;
				$aiBudgetLeft--;
			}
		}

		$this->logger->info( 'Fact-check finished', array( 'story_id' => $story->storyId, 'checked' => $out['checked'], 'verdicts' => $out['verdicts'] ), 'research.factcheck', 'FACT_CHECK_DONE', $jobId );
		return $out;
	}

	/**
	 * Deterministic verdict — the only authority over verification_status.
	 */
	private function ruleVerdict( EvidenceClaim $claim, array $normalizedItems, array $conflicts ): string {
		if ( $this->claimsConflict( $claim, $conflicts ) ) {
			return FactCheckRecord::VERDICT_CONTRADICTED;
		}
		$support = array();
		if ( $claim->sourceId > 0 ) {
			$support[ $claim->sourceId ] = true;
		}
		$needle = mb_strtolower( $this->normalizeSpace( $claim->supportSnippet ) );
		if ( '' !== $needle ) {
			foreach ( $normalizedItems as $item ) {
				if ( false !== mb_strpos( $item['text'], $needle ) ) {
					$support[ $item['source_id'] ] = true;
				}
			}
		}
		// v1.3.1: independent sources paraphrase — count a source as corroborating
		// when it contains the claim's distinctive terms (≥60 %), not only the
		// exact snippet. Numbers/versions must match exactly to count.
		if ( count( $support ) < 2 ) {
			$terms = $this->keyTerms( $claim->claimText );
			if ( count( $terms ) >= 2 ) {
				foreach ( $normalizedItems as $item ) {
					if ( isset( $support[ $item['source_id'] ] ) ) {
						continue;
					}
					$hay = mb_strtolower( $item['text'] );
					$hit = 0;
					foreach ( $terms as $t ) {
						if ( false !== mb_strpos( $hay, $t ) ) {
							$hit++;
						}
					}
					if ( $hit / count( $terms ) >= 0.6 ) {
						$support[ $item['source_id'] ] = true;
					}
				}
			}
		}
		if ( count( $support ) >= 2 ) {
			return FactCheckRecord::VERDICT_SUPPORTED;
		}
		if ( 1 === count( $support ) ) {
			return FactCheckRecord::VERDICT_SINGLE_SOURCE;
		}
		return FactCheckRecord::VERDICT_INSUFFICIENT;
	}

	private function statusFor( string $verdict ): string {
		switch ( $verdict ) {
			case FactCheckRecord::VERDICT_SUPPORTED:
				return EvidenceClaim::STATUS_VERIFIED;
			case FactCheckRecord::VERDICT_SINGLE_SOURCE:
				return EvidenceClaim::STATUS_PARTIALLY_VERIFIED;
			case FactCheckRecord::VERDICT_CONTRADICTED:
				return EvidenceClaim::STATUS_CONTRADICTED;
		}
		return EvidenceClaim::STATUS_UNVERIFIED;
	}

	/**
	 * Distinct factual values per type (+ raw claim text) for conflict detection.
	 *
	 * @param EvidenceClaim[] $claims
	 * @return array<string, array<string, string>> type => value => example claim text
	 */
	/**
	 * v1.3.1: a contradiction is two claims that talk about the SAME thing
	 * (same type + same claim text once the numeric value is removed) but
	 * report DIFFERENT values — e.g. "WordPress 6.9 released" vs "WordPress
	 * 6.8 released". Previously ANY two different numbers of the same type
	 * in a story flagged every claim of that type as contradicted, which
	 * made almost every real story ineligible for content.
	 *
	 * @return array<string, array<string, string>> subjectKey => value => claim text
	 */
	private function conflictValues( array $claims ): array {
		$out = array();
		foreach ( $claims as $claim ) {
			$value = $this->claimValue( $claim->claimType, $claim->claimText );
			if ( null === $value ) {
				continue;
			}
			$out[ $this->subjectKey( $claim, $value ) ][ $value ] = $claim->claimText;
		}
		return $out;
	}

	private function claimsConflict( EvidenceClaim $claim, array $conflicts ): bool {
		$value = $this->claimValue( $claim->claimType, $claim->claimText );
		if ( null === $value ) {
			return false;
		}
		$key = $this->subjectKey( $claim, $value );
		if ( empty( $conflicts[ $key ] ) ) {
			return false;
		}
		return count( $conflicts[ $key ] ) > 1;
	}

	/** Claim text with its own value blanked out → what the claim is ABOUT. */
	private function subjectKey( EvidenceClaim $claim, string $value ): string {
		$subject = mb_strtolower( $this->normalizeSpace( str_replace( $value, ' # ', $claim->claimText ) ) );
		$subject = preg_replace( '/[^\p{L}\p{N}#]+/u', ' ', $subject );
		return $claim->claimType . '|' . trim( (string) $subject );
	}

	private function claimValue( string $type, string $claimText ): ?string {
		switch ( $type ) {
			case EvidenceClaim::TYPE_VERSION:
				return preg_match( '/\d{1,3}(?:\.\d{1,3}){1,3}/u', $claimText, $m ) ? $m[0] : null;
			case EvidenceClaim::TYPE_DATE:
				return preg_match( '/20\d{2}-\d{2}-\d{2}/u', $claimText, $m ) ? $m[0] : null;
			case EvidenceClaim::TYPE_STAT:
				return preg_match( '/\d{1,3}(?:[.,]\d+)?/u', $claimText, $m ) ? $m[0] : null;
		}
		return null;
	}

	private function record( Story $story, EvidenceClaim $claim, int $jobId, string $method, string $provider, string $model, string $verdict, string $rationale ): FactCheckRecord {
		$r            = new FactCheckRecord();
		$r->claimId   = $claim->claimId;
		$r->storyId   = $story->storyId;
		$r->jobId     = $jobId;
		$r->method    = $method;
		$r->provider  = $provider;
		$r->model     = $model;
		$r->verdict   = $verdict;
		$r->rationale = $rationale;
		$r->checkedAt = Time::now();
		return $r;
	}

	private function rationale( string $verdict, EvidenceClaim $claim ): string {
		switch ( $verdict ) {
			case FactCheckRecord::VERDICT_SUPPORTED:
				return 'Same claim found verbatim in ≥2 independent sources.';
			case FactCheckRecord::VERDICT_SINGLE_SOURCE:
				return 'Only one independent source supports this claim.';
			case FactCheckRecord::VERDICT_CONTRADICTED:
				return 'Conflicting values reported for the same type of claim.';
		}
		return 'No source in the cluster supports this claim verbatim.';
	}

	private function aiVerdict( Story $story, EvidenceClaim $claim, array $items, int $jobId ): ?array {
		try {
			$result = $this->gateway->structured(
				Prompts::ID_FACT_CHECK,
				$story->langCode(),
				array(
					'claim'   => $claim->claimText,
					'content' => $this->snippetContext( $claim, $items ),
				),
				Prompts::SCHEMA_FACT_CHECK,
				$jobId,
				'fact_check.verify'
			);
			return array(
				'provider'  => $result['provider'],
				'model'     => $result['model'],
				'verdict'   => (string) $result['data']['verdict'],
				'rationale' => (string) $result['data']['rationale'],
			);
		} catch ( NonRetryableProviderException $e ) {
			return null;
		} catch ( RetryableProviderException $e ) {
			return null;
		}
	}

	private function snippetContext( EvidenceClaim $claim, array $items ): string {
		$out = array();
		foreach ( $items as $item ) {
			$text = (string) $item->contentText;
			if ( '' !== $claim->supportSnippet && false !== mb_strpos( $this->normalizeSpace( $text ), $this->normalizeSpace( $claim->supportSnippet ) ) ) {
				$out[] = '[source:' . (int) $item->sourceId . '] ' . mb_substr( trim( $text ), 0, 1200 );
			}
		}
		if ( ! $out ) {
			$out[] = '[no verbatim evidence located]';
		}
		return implode( "\n\n", $out );
	}

	/**
	 * Distinctive tokens of a claim: numbers/versions kept verbatim, words ≥3 chars,
	 * common stop-words dropped. Language-agnostic (works for fa/en).
	 *
	 * @return string[]
	 */
	private function keyTerms( string $text ): array {
		$text  = mb_strtolower( $this->normalizeSpace( $text ) );
		$stop  = array( 'the', 'and', 'for', 'are', 'was', 'has', 'its', 'new', 'now', 'this', 'that', 'with', 'from', 'have', 'will', 'been', 'were', 'their', 'about', 'which', 'into', 'also', 'more', 'than', 'they', 'after', 'over', 'برای', 'است', 'این', 'شده', 'کرد', 'های', 'شود', 'می‌شود', 'خود', 'باید', 'بود', 'اند', 'ها', 'که', 'در', 'از', 'به', 'با', 'را', 'و' );
		preg_match_all( '/\d+(?:[.,]\d+)*|[\p{L}\x{200C}]{4,}/u', $text, $m );
		$out = array();
		foreach ( $m[0] as $tok ) {
			if ( in_array( $tok, $stop, true ) ) {
				continue;
			}
			$out[ $tok ] = true;
		}
		return array_slice( array_keys( $out ), 0, 12 );
	}

	private function normalizeSpace( string $text ): string {
		return trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
	}
}
