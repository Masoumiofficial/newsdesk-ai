<?php
/**
 * Evidence extraction (§16): claims grounded in VERBATIM source text.
 *
 * Deterministic pass (versions/dates/stats/quotes/URLs — always runs).
 * AI pass on top: every AI claim is REJECTED unless its support_snippet occurs
 * verbatim in the corpus — the anti-fabrication invariant, enforced per claim.
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
use NewsDesk\AI\Domain\Entity\EvidenceClaim;
use NewsDesk\AI\Domain\Entity\NewsItem;
use NewsDesk\AI\Domain\Entity\Story;
use NewsDesk\AI\Infrastructure\Http\UrlValidator;
use NewsDesk\AI\Logging\Contracts\LoggerInterface;
use NewsDesk\AI\Support\Random;
use NewsDesk\AI\Support\Time;

final class EvidenceExtractor {

	private const MAX_CONTENT_CHARS = 24000;
	private const MAX_CLAIMS        = 40; // v1.6: full coverage (was 15 — caused over-summarised articles)

	/** @var AiGateway */
	private $gateway;
	/** @var ResearchRepositoryInterface */
	private $research;
	/** @var LoggerInterface */
	private $logger;

	public function __construct( AiGateway $gateway, ResearchRepositoryInterface $research, LoggerInterface $logger ) {
		$this->gateway  = $gateway;
		$this->research = $research;
		$this->logger   = $logger;
	}

	/**
	 * @param NewsItem[] $items
	 * @return array{extracted: int, duplicated: int, rejected: int, ai: bool}
	 */
	public function run( Story $story, array $items, int $jobId ): array {
		$counts = array( 'extracted' => 0, 'duplicated' => 0, 'rejected' => 0, 'ai' => false );

		foreach ( $items as $item ) {
			$this->extractDeterministic( $story, $item, $jobId, $counts );
		}

		$hasAi = $this->extractWithAi( $story, $items, $jobId, $counts );

		$this->logger->info(
			'Evidence extraction finished',
			array_merge( $counts, array( 'story_id' => $story->storyId ) ),
			'research.evidence',
			$hasAi ? 'EVIDENCE_AI' : 'EVIDENCE_RULES',
			$jobId
		);
		return $counts;
	}

	/**
	 * Claim construction helper: snippet MUST be verbatim in $content.
	 */
	private function addClaim( Story $story, NewsItem $item, int $jobId, string $claimText, string $claimType, string $snippet, string $evidenceType, float $confidence, string $reportedBy, string $provider, string $model, array &$counts ): void {
		$snippet = $this->clean( $snippet );
		if ( '' === $snippet || mb_strlen( $snippet ) > 800 ) {
			$counts['rejected']++;
			return;
		}
		if ( false === $this->isVerbatim( $item->contentText, $snippet ) && false === $this->isVerbatim( $item->excerpt . ' ' . $item->title, $snippet ) ) {
			// Non-verbatim snippets are NEVER persisted (§16): no fabrication.
			$counts['rejected']++;
			return;
		}
		$claim = new EvidenceClaim();
		$claim->claimId       = Random::uuid4();
		$claim->storyId       = $story->storyId;
		$claim->jobId         = $jobId;
		$claim->itemId        = $item->id;
		$claim->claimText     = $this->clean( $claimText );
		$claim->claimType     = $claimType;
		$claim->sourceId      = $item->sourceId;
		$claim->sourceUrl     = $item->canonicalUrl;
		$claim->supportSnippet = $snippet;
		$claim->snippetHash   = hash( 'sha256', $claimType . '|' . mb_strtolower( $snippet ) );
		$claim->evidenceType  = $evidenceType;
		$claim->publishedAt   = $item->publishedAt;
		$claim->retrievedAt   = $item->fetchedAt;
		$claim->confidence    = max( 0.0, min( 1.0, round( $confidence, 3 ) ) );
		$claim->reportedBy    = $reportedBy;
		$claim->provider      = $provider;
		$claim->model         = $model;
		$claim->createdAt     = Time::now();

		$saved = $this->research->insertClaim( $claim );
		if ( '' === $saved ) {
			$counts['duplicated']++;
			return;
		}
		$counts['extracted']++;
	}

	private function extractDeterministic( Story $story, NewsItem $item, int $jobId, array &$counts ): void {
		$content = (string) $item->contentText;
		$limit   = self::MAX_CLAIMS;
		$used    = 0;

		// Versions (IPv4-looking tokens are NOT versions — they are addresses).
		if ( preg_match_all( '/\b\d{1,3}(?:\.\d{1,3}){1,3}\b/u', $content, $m ) ) {
			foreach ( array_unique( $m[0] ) as $version ) {
				if ( $used >= $limit ) {
					break;
				}
				if ( $this->looksLikeIpv4( $version ) ) {
					continue;
				}
				$this->addClaim( $story, $item, $jobId, 'Version ' . $version . ' is reported', EvidenceClaim::TYPE_VERSION, $version, 'changelog', 0.9, 'rules', '', '', $counts );
				$used++;
			}
		}
		// Dates (ISO YYYY-MM-DD)
		if ( preg_match_all( '/\b(20\d{2})-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])\b/u', $content, $m ) ) {
			foreach ( array_unique( $m[0] ) as $date ) {
				if ( $used >= $limit ) {
					break;
				}
				$this->addClaim( $story, $item, $jobId, 'Date ' . $date . ' is mentioned', EvidenceClaim::TYPE_DATE, $date, 'news_report', 0.8, 'rules', '', '', $counts );
				$used++;
			}
		}
		// Statistics
		if ( preg_match_all( '/\b\d{1,3}(?:[.,]\d+)?\s*(?:%|percent|درصد|million|billion|هزار|میلیون|میلیارد)(?![\w%])/iu', $content, $m ) ) {
			foreach ( array_unique( $m[0] ) as $stat ) {
				if ( $used >= $limit ) {
					break;
				}
				$this->addClaim( $story, $item, $jobId, 'Statistic ' . trim( $stat ) . ' is reported', EvidenceClaim::TYPE_STAT, trim( $stat ), 'data', 0.85, 'rules', '', '', $counts );
				$used++;
			}
		}
		// Quotes (verbatim double-quoted spans)
		if ( preg_match_all( '/"([^"]{40,300})"/u', $content, $m ) ) {
			foreach ( $m[1] as $quote ) {
				if ( $used >= $limit ) {
					break;
				}
				$this->addClaim( $story, $item, $jobId, 'Source quotes: ' . mb_substr( $this->clean( $quote ), 0, 120 ) . '…', EvidenceClaim::TYPE_QUOTE, '"' . $this->clean( $quote ) . '"', 'news_report', 0.9, 'rules', '', '', $counts );
				$used++;
			}
		}
		// URLs (validated — http/https only, §52)
		if ( preg_match_all( '~https?://[^\s"<>\]\)]+~iu', $content, $m ) ) {
			foreach ( array_unique( $m[0] ) as $url ) {
				if ( $used >= $limit ) {
					break;
				}
				if ( ! $this->urlValid( $url ) ) {
					continue;
				}
				$this->addClaim( $story, $item, $jobId, 'Referenced source link: ' . mb_substr( $url, 0, 120 ), EvidenceClaim::TYPE_FACT, $url, 'official_doc', 0.7, 'rules', '', '', $counts );
				$used++;
			}
		}
	}

	/**
	 * AI-assisted pass — verdict enforced per claim by the verbatim rule.
	 */
	private function extractWithAi( Story $story, array $items, int $jobId, array &$counts ): bool {
		$corpus = $this->corpus( $items );
		if ( '' === $corpus ) {
			return false;
		}
		try {
			$result = $this->gateway->structured(
				Prompts::ID_EVIDENCE,
				$story->langCode(),
				array(
					'story_title' => $story->canonicalTitle,
					'content'     => $corpus,
				),
				Prompts::SCHEMA_EVIDENCE,
				$jobId,
				'evidence.extract'
			);
		} catch ( NonRetryableProviderException $e ) {
			$this->logger->warning( 'AI evidence extraction unavailable', array( 'story_id' => $story->storyId, 'code' => $e->codeName() ), 'research.evidence', 'AI_SKIPPED', $jobId );
			return false;
		} catch ( RetryableProviderException $e ) {
			$this->logger->warning( 'AI evidence extraction unavailable', array( 'story_id' => $story->storyId, 'code' => $e->codeName() ), 'research.evidence', 'AI_SKIPPED', $jobId );
			return false;
		}

		$claims = (array) ( $result['data']['claims'] ?? array() );
		$normalized = $this->normalizeCorpus( $corpus );
		$saved = 0;
		foreach ( array_slice( $claims, 0, self::MAX_CLAIMS ) as $raw ) {
			$claimType = (string) ( $raw['claim_type'] ?? EvidenceClaim::TYPE_FACT );
			if ( ! in_array( $claimType, EvidenceClaim::TYPES, true ) ) {
				$counts['rejected']++;
				continue;
			}
			$claimText = $this->clean( (string) ( $raw['claim_text'] ?? '' ) );
			$snippet   = $this->clean( (string) ( $raw['support_snippet'] ?? '' ) );
			if ( '' === $claimText || '' === $snippet ) {
				$counts['rejected']++;
				continue;
			}
			// THE business rule: verbatim grounding or nothing.
			$owner = $this->findOwnerItem( $items, $normalized, $snippet, $jobId, $counts );
			if ( null === $owner ) {
				continue; // rejected inside findOwnerItem
			}
			$saved++;
			$this->addClaim( $story, $owner, $jobId, $claimText, $claimType, $snippet, 'news_report', (float) ( $raw['confidence'] ?? 0.5 ), 'ai', $result['provider'], $result['model'], $counts );
		}
		$this->logger->info( 'AI claims validated', array( 'story_id' => $story->storyId, 'validated' => $saved, 'rejected' => $counts['rejected'] ), 'research.evidence', 'AI_RULE_PASS', $jobId );
		return $saved > 0;
	}

	/**
	 * Find which item actually contains the snippet (injection-proof grounding).
	 *
	 * @param NewsItem[] $items
	 * @return NewsItem|null
	 */
	private function findOwnerItem( array $items, string $normalizedCorpus, string $snippet, int $jobId, array &$counts ): ?NewsItem {
		$needle = $this->normalizeSpace( $snippet );
		if ( '' === $needle ) {
			$counts['rejected']++;
			return null;
		}
		if ( false === mb_strpos( $normalizedCorpus, $needle ) ) {
			// Not verbatim anywhere → fabricated by the model → REJECTED.
			$counts['rejected']++;
			$this->logger->warning( 'AI claim rejected: snippet not found verbatim', array( 'snippet' => mb_substr( $snippet, 0, 80 ) ), 'research.evidence', 'AI_FABRICATION_BLOCKED', $jobId );
			return null;
		}
		foreach ( $items as $item ) {
			$hay = $this->normalizeSpace( (string) $item->contentText . ' ' . $item->excerpt . ' ' . $item->title );
			if ( false !== mb_strpos( $hay, $needle ) ) {
				return $item;
			}
		}
		$counts['rejected']++;
		return null;
	}

	private function normalizeCorpus( string $corpus ): string {
		return $this->normalizeSpace( $corpus );
	}

	private function normalizeSpace( string $text ): string {
		return trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
	}

	private function clean( string $text ): string {
		$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $text );
		return trim( (string) $text );
	}

	private function isVerbatim( string $content, string $snippet ): bool {
		// Whitespace-normalized compare: the model may re-newline a quote; the
		// WORDS must be identical and in order (§16).
		return false !== mb_strpos( $this->normalizeSpace( $content ), $this->normalizeSpace( $snippet ) );
	}

	private function looksLikeIpv4( string $token ): bool {
		$parts = explode( '.', $token );
		if ( 4 !== count( $parts ) ) {
			return false;
		}
		foreach ( $parts as $p ) {
			if ( ! preg_match( '/^\d{1,3}$/', $p ) || (int) $p > 255 ) {
				return false;
			}
		}
		return true;
	}

	private function urlValid( string $url ): bool {
		try {
			// §52: evidence URLs must be reachable-safe: http(s) only, no
			// localhost/private/metadata targets (both validators per URL).
			UrlValidator::validate( $url );
			\NewsDesk\AI\Infrastructure\Http\IpValidator::assertHostSafe( (string) parse_url( $url, PHP_URL_HOST ) );
			return true;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	private function corpus( array $items ): string {
		$parts = array();
		$used  = 0;
		foreach ( $items as $item ) {
			$chunk = (string) $item->title . "\n" . $item->contentText;
			if ( $used + mb_strlen( $chunk ) > self::MAX_CONTENT_CHARS ) {
				$chunk = mb_substr( $chunk, 0, max( 0, self::MAX_CONTENT_CHARS - $used ) );
			}
			$parts[] = '[item:' . (int) $item->id . ' source:' . (int) $item->sourceId . "]\n" . $chunk;
			$used   += mb_strlen( $chunk );
			if ( $used >= self::MAX_CONTENT_CHARS ) {
				break;
			}
		}
		return implode( "\n\n", $parts );
	}
}
