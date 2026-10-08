<?php
/**
 * A Story (§5): one editorial unit that aggregates one or more NewsItems/sources.
 * A Story is NEVER an article — it becomes at most one article, after human approval (§73).
 *
 * @package NewsDesk\AI\Domain\Entity
 */

namespace NewsDesk\AI\Domain\Entity;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Support\Time;
use NewsDesk\AI\Support\Locale;

final class Story extends AbstractEntity {

	public const STATUS_CANDIDATE = 'candidate';
	public const STATUS_SELECTED   = 'selected';
	public const STATUS_REJECTED   = 'rejected';
	public const STATUS_EXPIRED    = 'expired';

	/** @var int */
	public $storyId = 0;
	/** @var string */
	public $canonicalTitle = '';
	/** @var int */
	public $primarySourceId = 0;
	/** @var array<int, int> */
	public $secondarySources = array();
	/** @var \DateTimeImmutable|null */
	public $firstPublishedAt;
	/** @var \DateTimeImmutable|null */
	public $lastUpdatedAt;
	/** @var float */
	public $importanceScore = 0.0;
	/** @var float */
	public $confidenceScore = 0.0;
	/** @var string */
	public $status = self::STATUS_CANDIDATE;
	/** @var string|null */
	public $editorialDecision;
	/** @var string|null */
	public $editorialStrategy;
	/** @var string|null */
	public $outcome;
	/** @var int */
	public $existingArticleId = 0;
	/** @var int */
	public $runJobId = 0;

	/* Phase 3 columns (migration 1.2.0) */
	/** @var string */
	public $researchStatus = '';
	/** @var string */
	public $factCheckStatus = '';
	/** @var int */
	public $evidenceCount = 0;
	/** @var int */
	public $verifiedClaimCount = 0;
	/** @var int */
	public $contradictionCount = 0;

	/** @var string Phase 4: '' | 'pending' | 'generated' | 'drafted' | 'needs_review' | 'blocked' */
	public $contentStatus = '';
	/** @var bool A-3 — security intelligence, extracted during research. */
	public $isSecurity = false;
	/** @var string[] */
	public $cveIds = array();
	/** @var float|null */
	public $cvssScore = null;
	/** @var string */
	public $severity = '';
	/** @var string[] */
	public $affectedVersions = array();
	/** @var string[] */
	public $fixedVersions = array();
	/** @var bool */
	public $exploited = false;

	/** @var string Official vendor advisory URL, when the source named one. */
	public $vendorAdvisory = '';

	/** @var \DateTimeImmutable|null */
	public $createdAt;
	/** @var \DateTimeImmutable|null */
	public $updatedAt;

	/* Phase 2 columns (migration 1.1.0) */
	/** @var string */
	public $clusterKey = '';
	/** @var string */
	public $language = '';  // resolved from the site locale via Locale::locale()
	/** @var int */
	public $itemCount = 0;
	/** @var int */
	public $sourceCount = 0;
	/** @var array<int, string> */
	public $topics = array();
	/** @var array<int, int> */
	public $itemIds = array();
	/** @var float */
	public $trustScore = 0.0;
	/** @var float */
	public $freshnessScore = 0.0;
	/** @var float */
	public $impactScore = 0.0;
	/** @var float */
	public $editorialScore = 0.0;
	/** @var float */
	public $seoAeoGeoScore = 0.0;
	/** @var string */
	public $windowKey = '';
	/** @var \DateTimeImmutable|null */
	public $selectedAt;
	/** @var string */
	public $selectionReason = '';
	/** @var array<string, mixed> */
	public $aeoSignals = array();

	public static function fromDbRow( array $row ): self {
		$s = new self();
		$s->storyId          = self::intOr( $row['story_id'] ?? null, 0 );
		$s->canonicalTitle   = self::strOr( $row['canonical_title'] ?? null );
		$s->primarySourceId  = self::intOr( $row['primary_source_id'] ?? null, 0 );
		$s->secondarySources = self::jsonArray( $row['secondary_sources'] ?? null );
		$s->firstPublishedAt = Time::fromDb( $row['first_published_at'] ?? null );
		$s->lastUpdatedAt    = Time::fromDb( $row['last_updated_at'] ?? null );
		$s->importanceScore  = self::floatOr( $row['importance_score'] ?? null, 0.0 );
		$s->confidenceScore  = self::floatOr( $row['confidence_score'] ?? null, 0.0 );
		$s->status           = self::strOr( $row['status'] ?? null, self::STATUS_CANDIDATE );
		$s->editorialDecision = isset( $row['editorial_decision'] ) && '' !== $row['editorial_decision'] ? (string) $row['editorial_decision'] : null;
		$s->editorialStrategy = isset( $row['editorial_strategy'] ) && '' !== $row['editorial_strategy'] ? (string) $row['editorial_strategy'] : null;
		$s->outcome          = isset( $row['outcome'] ) && '' !== $row['outcome'] ? (string) $row['outcome'] : null;
		$s->existingArticleId = self::intOr( $row['existing_article_id'] ?? null, 0 );
		$s->runJobId         = self::intOr( $row['run_job_id'] ?? null, 0 );
		$s->createdAt        = Time::fromDb( $row['created_at'] ?? null );
		$s->updatedAt        = Time::fromDb( $row['updated_at'] ?? null );
		$s->clusterKey       = self::strOr( $row['cluster_key'] ?? null );
		$s->language         = self::strOr( $row['language'] ?? null, Locale::locale() );
		$s->itemCount        = self::intOr( $row['item_count'] ?? null, 0 );
		$s->sourceCount      = self::intOr( $row['source_count'] ?? null, 0 );
		$s->topics           = self::jsonArray( $row['topics'] ?? null );
		$s->itemIds          = self::jsonArray( $row['item_ids'] ?? null );
		$s->trustScore       = self::floatOr( $row['trust_score'] ?? null, 0.0 );
		$s->freshnessScore   = self::floatOr( $row['freshness_score'] ?? null, 0.0 );
		$s->impactScore      = self::floatOr( $row['impact_score'] ?? null, 0.0 );
		$s->editorialScore   = self::floatOr( $row['editorial_score'] ?? null, 0.0 );
		$s->seoAeoGeoScore   = self::floatOr( $row['seo_aeo_geo_score'] ?? null, 0.0 );
		$s->windowKey        = self::strOr( $row['window_key'] ?? null );
		$s->selectedAt       = Time::fromDb( $row['selected_at'] ?? null );
		$s->selectionReason  = self::strOr( $row['selection_reason'] ?? null );
		$s->aeoSignals       = self::jsonArray( $row['aeo_signals'] ?? null );
		$s->researchStatus   = self::strOr( $row['research_status'] ?? null );
		$s->factCheckStatus  = self::strOr( $row['fact_check_status'] ?? null );
		$s->evidenceCount    = self::intOr( $row['evidence_count'] ?? null, 0 );
		$s->verifiedClaimCount = self::intOr( $row['verified_claim_count'] ?? null, 0 );
		$s->contradictionCount = self::intOr( $row['contradiction_count'] ?? null, 0 );
		$s->contentStatus      = self::strOr( $row['content_status'] ?? null );
		$s->isSecurity         = ! empty( $row['is_security'] );
		$s->cveIds             = self::jsonArray( $row['cve_ids'] ?? null );
		$s->cvssScore          = isset( $row['cvss_score'] ) && '' !== $row['cvss_score'] && null !== $row['cvss_score'] ? (float) $row['cvss_score'] : null;
		$s->severity           = self::strOr( $row['severity'] ?? null );
		$s->affectedVersions   = self::jsonArray( $row['affected_versions'] ?? null );
		$s->fixedVersions      = self::jsonArray( $row['fixed_versions'] ?? null );
		$s->exploited          = ! empty( $row['exploited'] );
		$s->vendorAdvisory     = self::strOr( $row['vendor_advisory'] ?? null );
		return $s;
	}

	public function toDbRow(): array {
		return array(
			'story_id'            => $this->storyId,
			'canonical_title'     => $this->canonicalTitle,
			'primary_source_id'   => $this->primarySourceId,
			'secondary_sources'   => self::jsonEncode( $this->secondarySources ),
			'first_published_at'  => Time::toDb( $this->firstPublishedAt ),
			'last_updated_at'     => Time::toDb( $this->lastUpdatedAt ),
			'importance_score'    => $this->importanceScore,
			'confidence_score'    => $this->confidenceScore,
			'status'              => $this->status,
			'editorial_decision'  => $this->editorialDecision,
			'editorial_strategy'  => $this->editorialStrategy,
			'outcome'             => $this->outcome,
			'existing_article_id' => $this->existingArticleId,
			'run_job_id'          => $this->runJobId,
			'created_at'          => Time::toDb( $this->createdAt ),
			'updated_at'          => Time::toDb( $this->updatedAt ),
			'cluster_key'         => $this->clusterKey,
			'language'            => $this->language,
			'item_count'          => $this->itemCount,
			'source_count'        => $this->sourceCount,
			'topics'              => self::jsonEncode( $this->topics ),
			'item_ids'            => self::jsonEncode( $this->itemIds ),
			'trust_score'         => $this->trustScore,
			'freshness_score'     => $this->freshnessScore,
			'impact_score'        => $this->impactScore,
			'editorial_score'     => $this->editorialScore,
			'seo_aeo_geo_score'   => $this->seoAeoGeoScore,
			'window_key'          => $this->windowKey,
			'selected_at'         => Time::toDb( $this->selectedAt ),
			'selection_reason'    => $this->selectionReason,
			'aeo_signals'         => self::jsonEncode( $this->aeoSignals ),
			'research_status'     => $this->researchStatus,
			'fact_check_status'   => $this->factCheckStatus,
			'evidence_count'      => $this->evidenceCount,
			'verified_claim_count'=> $this->verifiedClaimCount,
			'contradiction_count' => $this->contradictionCount,
			'content_status'       => $this->contentStatus,
			// A-3: without these, an ordinary update() would blank the
			// security intelligence that updateSecurityIntel() just wrote.
			'is_security'          => $this->isSecurity ? 1 : 0,
			'cve_ids'              => self::jsonEncode( $this->cveIds ),
			'cvss_score'           => $this->cvssScore,
			'severity'             => $this->severity,
			'affected_versions'    => self::jsonEncode( $this->affectedVersions ),
			'fixed_versions'       => self::jsonEncode( $this->fixedVersions ),
			'vendor_advisory'      => $this->vendorAdvisory,
			'exploited'            => $this->exploited ? 1 : 0,
		);
	}

	/**
	 * Prompt/UI language code derived from the story language (fa|en).
	 */
	public function langCode(): string {
		$lang = strtolower( substr( $this->language ?: Locale::locale(), 0, 2 ) );
		return in_array( $lang, array( 'fa', 'en' ), true ) ? $lang : 'fa';
	}

	/**
	 * Sources that reported this story (primary + secondary).
	 *
	 * @return int[]
	 */
	public function allSourceIds(): array {
		return array_values( array_unique( array_merge( array( $this->primarySourceId ), $this->secondarySources ) ) );
	}
}
