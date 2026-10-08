<?php
namespace NewsDesk\AI\Domain\Entity;

defined( 'ABSPATH' ) || exit;

final class Lock extends AbstractEntity {

	public const GLOBAL = 'global';

	/** @var string */
	public $lockId = '';
	/** @var string */
	public $owner = '';
	/** @var int|null */
	public $jobId;
	/** @var int */
	public $acquiredAt = 0;
	/** @var int */
	public $expiresAt = 0;
	/** @var int */
	public $heartbeatAt = 0;

	public static function fromDbRow( array $row ): self {
		$l              = new self();
		$l->lockId      = self::strOr( $row['lock_id'] ?? null );
		$l->owner       = self::strOr( $row['owner'] ?? null );
		$l->jobId       = self::intOrNull( $row['job_id'] ?? null );
		$l->acquiredAt  = (int) ( $row['acquired_at'] ?? 0 );
		$l->expiresAt   = (int) ( $row['expires_at'] ?? 0 );
		$l->heartbeatAt = (int) ( $row['heartbeat_at'] ?? 0 );
		return $l;
	}

	public function toDbRow(): array {
		return array(
			'lock_id'      => $this->lockId,
			'owner'        => $this->owner,
			'job_id'       => $this->jobId,
			'acquired_at'  => $this->acquiredAt,
			'expires_at'   => $this->expiresAt,
			'heartbeat_at' => $this->heartbeatAt,
		);
	}

	/**
	 * Is the lock expired (recoverable)?
	 */
	public function isExpired( int $now ): bool {
		return $this->expiresAt <= $now;
	}
}
