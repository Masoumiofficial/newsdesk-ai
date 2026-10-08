<?php
/**
 * Pipeline job (§38–§50).
 *
 * @package NewsDesk\AI\Domain\Entity
 */

namespace NewsDesk\AI\Domain\Entity;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Domain\JobStateMachine;
use NewsDesk\AI\Support\Time;

final class Job extends AbstractEntity {

	public const TYPE_PIPELINE         = 'pipeline';
	public const TYPE_DISCOVERY        = 'discovery';
	public const TYPE_MANUAL_PIPELINE  = 'manual_pipeline';
	public const TYPE_MANUAL_DISCOVERY = 'manual_discovery';

	/** @var int */
	public $id = 0;
	/** @var string */
	public $jobKey = '';
	/** @var string */
	public $type = self::TYPE_PIPELINE;
	/** @var string */
	public $status = JobStateMachine::QUEUED;
	/** @var string */
	public $stage = JobStateMachine::QUEUED;
	/** @var array */
	public $payload = array();
	/** @var int */
	public $priority = 10;
	/** @var \DateTimeImmutable|null */
	public $scheduledAt;
	/** @var \DateTimeImmutable|null */
	public $startedAt;
	/** @var \DateTimeImmutable|null */
	public $finishedAt;
	/** @var int */
	public $attempts = 0;
	/** @var int */
	public $maxAttempts = 3;
	/** @var string|null */
	public $retryTarget;
	/** @var string */
	public $errorCode = '';
	/** @var string */
	public $errorMessage = '';
	/** @var string */
	public $correlationId = '';
	/** @var \DateTimeImmutable|null */
	public $createdAt;
	/** @var \DateTimeImmutable|null */
	public $updatedAt;

	public static function fromDbRow( array $row ): self {
		$j                  = new self();
		$j->id              = self::intOr( $row['id'] ?? null, 0 );
		$j->jobKey          = self::strOr( $row['job_key'] ?? null );
		$j->type            = self::strOr( $row['type'] ?? null, self::TYPE_PIPELINE );
		$j->status          = self::strOr( $row['status'] ?? null, JobStateMachine::QUEUED );
		$j->stage           = self::strOr( $row['stage'] ?? null, JobStateMachine::QUEUED );
		$j->payload         = self::jsonArray( $row['state_payload'] ?? null );
		$j->priority        = self::intOr( $row['priority'] ?? null, 10 );
		$j->scheduledAt     = Time::fromDb( $row['scheduled_at'] ?? null );
		$j->startedAt       = Time::fromDb( $row['started_at'] ?? null );
		$j->finishedAt      = Time::fromDb( $row['finished_at'] ?? null );
		$j->attempts        = self::intOr( $row['attempts'] ?? null, 0 );
		$j->maxAttempts     = self::intOr( $row['max_attempts'] ?? null, 3 );
		$j->retryTarget     = ( isset( $row['retry_target'] ) && '' !== $row['retry_target'] ) ? (string) $row['retry_target'] : null;
		$j->errorCode       = self::strOr( $row['error_code'] ?? null );
		$j->errorMessage    = self::strOr( $row['error_message'] ?? null );
		$j->correlationId   = self::strOr( $row['correlation_id'] ?? null );
		$j->createdAt       = Time::fromDb( $row['created_at'] ?? null );
		$j->updatedAt       = Time::fromDb( $row['updated_at'] ?? null );
		return $j;
	}

	public function toDbRow(): array {
		return array(
			'id'              => $this->id,
			'job_key'         => $this->jobKey,
			'type'            => $this->type,
			'status'          => $this->status,
			'stage'           => $this->stage,
			'state_payload'   => self::jsonEncode( $this->payload ),
			'priority'        => $this->priority,
			'scheduled_at'    => Time::toDb( $this->scheduledAt ),
			'started_at'      => Time::toDb( $this->startedAt ),
			'finished_at'     => Time::toDb( $this->finishedAt ),
			'attempts'        => $this->attempts,
			'max_attempts'    => $this->maxAttempts,
			'retry_target'    => $this->retryTarget,
			'error_code'      => $this->errorCode,
			'error_message'   => $this->errorMessage,
			'correlation_id'  => $this->correlationId,
			'created_at'      => Time::toDb( $this->createdAt ),
			'updated_at'      => Time::toDb( $this->updatedAt ),
		);
	}

	/**
	 * Human/API-facing outcome of the run.
	 */
	public function outcome(): string {
		return (string) ( $this->payload['outcome'] ?? '' );
	}

	public function isTerminal(): bool {
		return in_array( $this->status, JobStateMachine::terminalStates(), true );
	}
}
