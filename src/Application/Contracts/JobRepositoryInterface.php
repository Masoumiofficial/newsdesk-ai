<?php
namespace NewsDesk\AI\Application\Contracts;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Domain\Entity\Job;

interface JobRepositoryInterface {

	public function insert( Job $job ): int;

	public function update( Job $job ): bool;

	public function find( int $id ): ?Job;

	public function findByKey( string $jobKey ): ?Job;

	/**
	 * Optimistic atomic transition: only applies when current status matches $expectedFrom.
	 * Returns true when the row was actually transitioned (guards against concurrent workers).
	 */
	public function transition( int $id, string $expectedFrom, string $event, string $newStatus, string $stage, array $payloadUpdate ): bool;

	public function touchStarted( int $id, \DateTimeImmutable $at ): bool;

	public function touchFinished( int $id, \DateTimeImmutable $at, string $status ): bool;

	public function bumpAttempts( int $id, int $attempts, ?string $retryTarget ): bool;

	public function recordError( int $id, string $code, string $message ): bool;

	/**
	 * @return array{items: Job[], total: int}
	 */
	public function paginate( array $filters, int $page, int $perPage ): array;

	public function countByStatus( string $status ): int;

	public function findRecent( int $limit ): array;
}
