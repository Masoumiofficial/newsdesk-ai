<?php
/**
 * Lock manager (§40): global / job / story scopes over nd_locks.
 *
 * @package NewsDesk\AI\Infrastructure\Locks
 */

namespace NewsDesk\AI\Infrastructure\Locks;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Contracts\LockRepositoryInterface;
use NewsDesk\AI\Domain\Entity\Lock;
use NewsDesk\AI\Logging\Contracts\LoggerInterface;

final class LockManager {

	/** @var LockRepositoryInterface */
	private $repo;
	/** @var LoggerInterface */
	private $logger;
	/** @var int seconds */
	private $ttl;
	/** @var int seconds */
	private $heartbeat;

	public function __construct( LockRepositoryInterface $repo, LoggerInterface $logger, int $ttl = 1800, int $heartbeat = 300 ) {
		$this->repo      = $repo;
		$this->logger    = $logger;
		$this->ttl       = $ttl;
		$this->heartbeat = $heartbeat;
	}

	public function jobLockName( int $jobId ): string {
		return 'job:' . $jobId;
	}

	public function storyLockName( int $storyId ): string {
		return 'story:' . $storyId;
	}

	/**
	 * Acquire (or recover) a lock. Returns true when held by $owner.
	 */
	public function acquire( string $lockId, string $owner, ?int $jobId = null ): bool {
		$now  = time();
		$lock = $this->repo->find( $lockId );

		if ( null === $lock ) {
			$lock             = new Lock();
			$lock->lockId     = $lockId;
			$lock->owner      = $owner;
			$lock->jobId      = $jobId;
			$lock->acquiredAt = $now;
			$lock->expiresAt  = $now + $this->ttl;
			$lock->heartbeatAt = $now;
			if ( $this->repo->insert( $lock ) ) {
				return true;
			}
			return false; // lost the race
		}

		if ( $lock->isExpired( $now ) || $lock->owner === $owner ) {
			$lock->owner       = $owner;
			$lock->jobId       = $jobId;
			$lock->acquiredAt  = $now;
			$lock->expiresAt   = $now + $this->ttl;
			$lock->heartbeatAt = $now;
			return $this->repo->update( $lock );
		}
		return false;
	}

	/**
	 * Extend ownership (heartbeat) — keeps the lock alive during long stages.
	 */
	public function extend( string $lockId, string $owner ): bool {
		$lock = $this->repo->find( $lockId );
		if ( null === $lock || $lock->owner !== $owner ) {
			return false;
		}
		$lock->heartbeatAt = time();
		$lock->expiresAt   = time() + $this->ttl;
		return $this->repo->update( $lock );
	}

	/**
	 * Release a lock only if owned.
	 */
	public function release( string $lockId, string $owner ): bool {
		$lock = $this->repo->find( $lockId );
		if ( null === $lock || $lock->owner !== $owner ) {
			return false;
		}
		return $this->repo->delete( $lockId );
	}

	public function isHeld( string $lockId ): bool {
		$lock = $this->repo->find( $lockId );
		return null !== $lock && ! $lock->isExpired( time() );
	}

	public function get( string $lockId ): ?Lock {
		return $this->repo->find( $lockId );
	}

	/**
	 * Recover stale locks. Returns number cleared.
	 */
	public function clearStale(): int {
		$now   = time();
		$count = 0;
		foreach ( $this->repo->all() as $lock ) {
			if ( $lock->isExpired( $now ) ) {
				$this->repo->delete( $lock->lockId );
				$count++;
			}
		}
		return $count;
	}

	/**
	 * @return Lock[]
	 */
	public function all(): array {
		return $this->repo->all();
	}
}
