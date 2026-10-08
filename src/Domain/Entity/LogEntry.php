<?php
namespace NewsDesk\AI\Domain\Entity;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Support\Time;

final class LogEntry extends AbstractEntity {

	/** @var int */
	public $id = 0;
	/** @var string */
	public $level = 'info';
	/** @var string */
	public $component = '';
	/** @var string */
	public $event = '';
	/** @var int|null */
	public $jobId;
	/** @var string */
	public $correlationId = '';
	/** @var string */
	public $message = '';
	/** @var array */
	public $context = array();
	/** @var \DateTimeImmutable|null */
	public $createdAt;

	public static function fromDbRow( array $row ): self {
		$l                = new self();
		$l->id            = self::intOr( $row['id'] ?? null, 0 );
		$l->level         = self::strOr( $row['level'] ?? null, 'info' );
		$l->component     = self::strOr( $row['component'] ?? null );
		$l->event         = self::strOr( $row['event'] ?? null );
		$l->jobId         = self::intOrNull( $row['job_id'] ?? null );
		$l->correlationId = self::strOr( $row['correlation_id'] ?? null );
		$l->message       = self::strOr( $row['message'] ?? null );
		$l->context       = self::jsonArray( $row['context'] ?? null );
		$l->createdAt     = Time::fromDb( $row['created_at'] ?? null );
		return $l;
	}

	public function toDbRow(): array {
		return array(
			'id'             => $this->id,
			'timestamp'      => Time::toDb( $this->createdAt ),
			'level'          => $this->level,
			'component'      => $this->component,
			'event'          => $this->event,
			'job_id'         => $this->jobId,
			'correlation_id' => $this->correlationId,
			'message'        => $this->message,
			'context'        => self::jsonEncode( $this->context ),
			'created_at'     => Time::toDb( $this->createdAt ),
		);
	}
}
