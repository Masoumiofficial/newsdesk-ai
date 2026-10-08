<?php
namespace NewsDesk\AI\Domain\Entity;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Support\Time;

final class JobEvent extends AbstractEntity {

	/** @var int */
	public $id = 0;
	/** @var int */
	public $jobId = 0;
	/** @var int */
	public $seq = 0;
	/** @var string */
	public $event = '';
	/** @var string */
	public $stateBefore = '';
	/** @var string */
	public $stateAfter = '';
	/** @var string */
	public $message = '';
	/** @var array */
	public $context = array();
	/** @var string */
	public $correlationId = '';
	/** @var \DateTimeImmutable|null */
	public $createdAt;

	public static function fromDbRow( array $row ): self {
		$e                = new self();
		$e->id            = self::intOr( $row['id'] ?? null, 0 );
		$e->jobId         = self::intOr( $row['job_id'] ?? null, 0 );
		$e->seq           = self::intOr( $row['seq'] ?? null, 0 );
		$e->event         = self::strOr( $row['event'] ?? null );
		$e->stateBefore   = self::strOr( $row['state_before'] ?? null );
		$e->stateAfter    = self::strOr( $row['state_after'] ?? null );
		$e->message       = self::strOr( $row['message'] ?? null );
		$e->context       = self::jsonArray( $row['context'] ?? null );
		$e->correlationId = self::strOr( $row['correlation_id'] ?? null );
		$e->createdAt     = Time::fromDb( $row['created_at'] ?? null );
		return $e;
	}

	public function toDbRow(): array {
		return array(
			'id'             => $this->id,
			'job_id'         => $this->jobId,
			'seq'            => $this->seq,
			'event'          => $this->event,
			'state_before'   => $this->stateBefore,
			'state_after'    => $this->stateAfter,
			'message'        => $this->message,
			'context'        => self::jsonEncode( $this->context ),
			'correlation_id' => $this->correlationId,
			'created_at'     => Time::toDb( $this->createdAt ),
		);
	}
}
