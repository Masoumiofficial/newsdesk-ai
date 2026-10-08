<?php
/**
 * One atomic, attributable statement extracted from source content (§16).
 * `support_snippet` MUST be a verbatim substring of item content — business-rule
 * enforced at insert (the anti-fabrication invariant).
 *
 * @package NewsDesk\AI\Domain\Entity
 */

namespace NewsDesk\AI\Domain\Entity;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Support\Time;

final class EvidenceClaim extends AbstractEntity {

	public const TYPE_FACT    = 'fact';
	public const TYPE_VERSION = 'version';
	public const TYPE_DATE    = 'date';
	public const TYPE_STAT    = 'stat';
	public const TYPE_QUOTE   = 'quote';
	public const TYPE_NAME    = 'name';
	public const TYPE_EVENT   = 'event';

	public const STATUS_UNVERIFIED         = 'UNVERIFIED';
	public const STATUS_VERIFIED            = 'VERIFIED';
	public const STATUS_PARTIALLY_VERIFIED  = 'PARTIALLY_VERIFIED';
	public const STATUS_CONTRADICTED        = 'CONTRADICTED';
	public const STATUS_REJECTED            = 'REJECTED';

	/** A-4: how damaging it is to publish this claim unverified. */
	public const RISK_CRITICAL = 'CRITICAL';
	public const RISK_HIGH     = 'HIGH';
	public const RISK_MEDIUM   = 'MEDIUM';
	public const RISK_LOW      = 'LOW';

	public const RISKS = array( self::RISK_CRITICAL, self::RISK_HIGH, self::RISK_MEDIUM, self::RISK_LOW );

	/** A-4: what the editorial pipeline should do about an unverified claim. */
	public const ACTION_KEEP           = 'KEEP';
	public const ACTION_REMOVE         = 'REMOVE';
	public const ACTION_ATTRIBUTE      = 'ATTRIBUTE';
	public const ACTION_RESEARCH_MORE  = 'RESEARCH_MORE';
	public const ACTION_MARK_UNCERTAIN = 'MARK_UNCERTAIN';

	public const ACTIONS = array(
		self::ACTION_KEEP,
		self::ACTION_REMOVE,
		self::ACTION_ATTRIBUTE,
		self::ACTION_RESEARCH_MORE,
		self::ACTION_MARK_UNCERTAIN,
	);

	public const TYPES = array( self::TYPE_FACT, self::TYPE_VERSION, self::TYPE_DATE, self::TYPE_STAT, self::TYPE_QUOTE, self::TYPE_NAME, self::TYPE_EVENT );

	/** @var string */
	public $claimId = '';
	/** @var int */
	public $storyId = 0;
	/** @var int */
	public $jobId = 0;
	/** @var int */
	public $itemId = 0;
	/** @var string */
	public $claimText = '';
	/** @var string */
	public $claimType = self::TYPE_FACT;
	/** @var int */
	public $sourceId = 0;
	/** @var string */
	public $sourceUrl = '';
	/** @var string */
	public $supportSnippet = '';
	/** @var string */
	public $snippetHash = '';
	/** @var string */
	public $evidenceType = 'news_report';
	/** @var \DateTimeImmutable|null */
	public $publishedAt;
	/** @var \DateTimeImmutable|null */
	public $retrievedAt;
	/** @var float */
	public $confidence = 0.0;
	/** @var string A-4 — CRITICAL/HIGH/MEDIUM/LOW. */
	public $risk = self::RISK_MEDIUM;
	/** @var string A-4 — KEEP/REMOVE/ATTRIBUTE/RESEARCH_MORE/MARK_UNCERTAIN. */
	public $action = self::ACTION_KEEP;
	/** @var string */
	public $verificationStatus = self::STATUS_UNVERIFIED;
	/** @var \DateTimeImmutable|null */
	public $verifiedAt;
	/** @var string */
	public $reportedBy = 'rules';
	/** @var string */
	public $provider = '';
	/** @var string */
	public $model = '';
	/** @var \DateTimeImmutable|null */
	public $createdAt;

	public static function fromDbRow( array $row ): self {
		$c                    = new self();
		$c->claimId           = self::strOr( $row['claim_id'] ?? null );
		$c->storyId           = self::intOr( $row['story_id'] ?? null, 0 );
		$c->jobId             = self::intOr( $row['job_id'] ?? null, 0 );
		$c->itemId            = self::intOr( $row['item_id'] ?? null, 0 );
		$c->claimText         = self::strOr( $row['claim_text'] ?? null );
		$c->claimType         = self::strOr( $row['claim_type'] ?? null, self::TYPE_FACT );
		$c->sourceId          = self::intOr( $row['source_id'] ?? null, 0 );
		$c->sourceUrl         = self::strOr( $row['source_url'] ?? null );
		$c->supportSnippet    = self::strOr( $row['support_snippet'] ?? null );
		$c->snippetHash       = self::strOr( $row['snippet_hash'] ?? null );
		$c->evidenceType      = self::strOr( $row['evidence_type'] ?? null, 'news_report' );
		$c->publishedAt       = Time::fromDb( $row['published_at'] ?? null );
		$c->retrievedAt       = Time::fromDb( $row['retrieved_at'] ?? null );
		$c->confidence        = self::floatOr( $row['confidence'] ?? null, 0.0 );
		$risk                 = strtoupper( self::strOr( $row['risk'] ?? null ) );
		$c->risk              = in_array( $risk, self::RISKS, true ) ? $risk : self::RISK_MEDIUM;
		$action               = strtoupper( self::strOr( $row['action'] ?? null ) );
		$c->action            = in_array( $action, self::ACTIONS, true ) ? $action : self::ACTION_KEEP;
		$c->verificationStatus = self::strOr( $row['verification_status'] ?? null, self::STATUS_UNVERIFIED );
		$c->verifiedAt        = Time::fromDb( $row['verified_at'] ?? null );
		$c->reportedBy        = self::strOr( $row['reported_by'] ?? null, 'rules' );
		$c->provider          = self::strOr( $row['provider'] ?? null );
		$c->model             = self::strOr( $row['model'] ?? null );
		$c->createdAt         = Time::fromDb( $row['created_at'] ?? null );
		return $c;
	}

	public function toDbRow(): array {
		return array(
			'claim_id'            => $this->claimId,
			'story_id'            => $this->storyId,
			'job_id'              => $this->jobId,
			'item_id'             => $this->itemId,
			'claim_text'          => $this->claimText,
			'claim_type'          => $this->claimType,
			'source_id'           => $this->sourceId,
			'source_url'          => $this->sourceUrl,
			'support_snippet'     => $this->supportSnippet,
			'snippet_hash'        => $this->snippetHash,
			'evidence_type'       => $this->evidenceType,
			'published_at'        => Time::toDb( $this->publishedAt ),
			'retrieved_at'        => Time::toDb( $this->retrievedAt ),
			'confidence'          => $this->confidence,
			'risk'                => $this->risk,
			'action'              => $this->action,
			'verification_status' => $this->verificationStatus,
			'verified_at'         => Time::toDb( $this->verifiedAt ),
			'reported_by'         => $this->reportedBy,
			'provider'            => $this->provider,
			'model'               => $this->model,
			'created_at'          => Time::toDb( $this->createdAt ),
		);
	}
}
