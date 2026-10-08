<?php
/**
 * One generated content version for a story (ARCHITECTURE.md). The whole artifact
 * (article structure, grounding claim map, SEO/AEO/GEO package) lives in
 * `content_json`; the row is the versioned audit trail (§31, §36).
 *
 * @package NewsDesk\AI\Domain\Entity
 */

namespace NewsDesk\AI\Domain\Entity;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Support\Time;

final class ContentVersion extends AbstractEntity {

	public const STATUS_DRAFT       = 'draft';
	public const STATUS_APPROVED    = 'approved';
	public const STATUS_NEEDS_REVIEW = 'needs_review';
	public const STATUS_REVIEWED    = 'reviewed'; // human-approved for publication (§36/§73)
	public const STATUS_REJECTED    = 'rejected';

	/** @var int */
	public $versionId = 0;
	/** @var int */
	public $storyId = 0;
	/** @var int */
	public $jobId = 0;
	/** @var int */
	public $draftPostId = 0;
	/** @var int */
	public $versionNo = 1;
	/** @var array */
	public $content = array();
	/** @var string */
	public $contentHash = '';
	/** @var string */
	public $provider = '';
	/** @var string */
	public $model = '';
	/** @var string */
	public $promptVersion = '';
	/** @var float */
	public $qualityScore = 0.0;
	/** @var string */
	public $status = self::STATUS_DRAFT;
	/** @var string */
	public $errorCode = '';
	/** @var \DateTimeImmutable|null */
	public $createdAt;

	public static function fromDbRow( array $row ): self {
		$v                 = new self();
		$v->versionId      = self::intOr( $row['version_id'] ?? null, 0 );
		$v->storyId        = self::intOr( $row['story_id'] ?? null, 0 );
		$v->jobId          = self::intOr( $row['job_id'] ?? null, 0 );
		$v->draftPostId    = self::intOr( $row['draft_post_id'] ?? null, 0 );
		$v->versionNo      = self::intOr( $row['version_no'] ?? null, 1 );
		$v->content        = self::jsonArray( $row['content_json'] ?? null );
		$v->contentHash    = self::strOr( $row['content_hash'] ?? null );
		$v->provider       = self::strOr( $row['provider'] ?? null );
		$v->model          = self::strOr( $row['model'] ?? null );
		$v->promptVersion  = self::strOr( $row['prompt_version'] ?? null );
		$v->qualityScore   = self::floatOr( $row['quality_score'] ?? null, 0.0 );
		$v->status         = self::strOr( $row['status'] ?? null, self::STATUS_DRAFT );
		$v->errorCode      = self::strOr( $row['error_code'] ?? null );
		$v->createdAt      = Time::fromDb( $row['created_at'] ?? null );
		return $v;
	}

	public function toDbRow(): array {
		return array(
			'version_id'     => $this->versionId,
			'story_id'       => $this->storyId,
			'job_id'         => $this->jobId,
			'draft_post_id'  => $this->draftPostId,
			'version_no'     => $this->versionNo,
			'content_json'   => json_encode( $this->content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			'content_hash'   => $this->contentHash,
			'provider'       => $this->provider,
			'model'          => $this->model,
			'prompt_version' => $this->promptVersion,
			'quality_score'  => $this->qualityScore,
			'status'         => $this->status,
			'error_code'     => $this->errorCode,
			'created_at'     => $this->createdAt ? $this->createdAt->format( 'Y-m-d H:i:s' ) : null,
		);
	}
}
