<?php
/**
 * Research artifact — one per story (ARCHITECTURE.md). `brief` is the grounded analysis:
 * entities/angles/questions synthesized ONLY from provided content; the schema
 * + business rules forbid invented facts (UNKNOWN is the correct value).
 *
 * @package NewsDesk\AI\Domain\Entity
 */

namespace NewsDesk\AI\Domain\Entity;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Support\Time;

final class ResearchPackage extends AbstractEntity {

	public const STATUS_PENDING = 'pending';
	public const STATUS_DONE    = 'done';
	public const STATUS_FAILED  = 'failed';
	public const STATUS_SKIPPED = 'skipped';

	/** @var int */
	public $researchId = 0;
	/** @var int */
	public $storyId = 0;
	/** @var int */
	public $jobId = 0;
	/** @var string */
	public $status = self::STATUS_PENDING;
	/** @var string */
	public $provider = '';
	/** @var string */
	public $model = '';
	/** @var string */
	public $promptVersion = '';
	/** @var string */
	public $schemaVersion = 'newsdesk.research.v1';
	/** @var array */
	public $brief = array();
	/** @var float */
	public $confidence = 0.0;
	/** @var array */
	public $contradictions = array();
	/** @var \DateTimeImmutable|null */
	public $startedAt;
	/** @var \DateTimeImmutable|null */
	public $completedAt;
	/** @var string */
	public $errorCode = '';
	/** @var \DateTimeImmutable|null */
	public $createdAt;
	/** @var \DateTimeImmutable|null */
	public $updatedAt;

	public static function fromDbRow( array $row ): self {
		$p                    = new self();
		$p->researchId        = self::intOr( $row['research_id'] ?? null, 0 );
		$p->storyId           = self::intOr( $row['story_id'] ?? null, 0 );
		$p->jobId             = self::intOr( $row['job_id'] ?? null, 0 );
		$p->status            = self::strOr( $row['status'] ?? null, self::STATUS_PENDING );
		$p->provider          = self::strOr( $row['provider'] ?? null );
		$p->model             = self::strOr( $row['model'] ?? null );
		$p->promptVersion     = self::strOr( $row['prompt_version'] ?? null );
		$p->schemaVersion     = self::strOr( $row['schema_version'] ?? null, 'newsdesk.research.v1' );
		$p->brief             = self::jsonArray( $row['brief_json'] ?? null );
		$p->confidence        = self::floatOr( $row['confidence'] ?? null, 0.0 );
		$p->contradictions    = self::jsonArray( $row['contradictions'] ?? null );
		$p->startedAt         = Time::fromDb( $row['started_at'] ?? null );
		$p->completedAt       = Time::fromDb( $row['completed_at'] ?? null );
		$p->errorCode         = self::strOr( $row['error_code'] ?? null );
		$p->createdAt         = Time::fromDb( $row['created_at'] ?? null );
		$p->updatedAt         = Time::fromDb( $row['updated_at'] ?? null );
		return $p;
	}

	public function toDbRow(): array {
		return array(
			'research_id'   => $this->researchId,
			'story_id'      => $this->storyId,
			'job_id'        => $this->jobId,
			'status'        => $this->status,
			'provider'      => $this->provider,
			'model'         => $this->model,
			'prompt_version'=> $this->promptVersion,
			'schema_version'=> $this->schemaVersion,
			'brief_json'    => self::jsonEncode( $this->brief ),
			'confidence'    => $this->confidence,
			'contradictions'=> self::jsonEncode( $this->contradictions ),
			'started_at'    => Time::toDb( $this->startedAt ),
			'completed_at'  => Time::toDb( $this->completedAt ),
			'error_code'    => $this->errorCode,
			'created_at'    => Time::toDb( $this->createdAt ),
			'updated_at'    => Time::toDb( $this->updatedAt ),
		);
	}
}
