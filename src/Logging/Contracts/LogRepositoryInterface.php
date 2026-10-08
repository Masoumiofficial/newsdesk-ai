<?php
namespace NewsDesk\AI\Logging\Contracts;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Domain\Entity\LogEntry;

interface LogRepositoryInterface {

	public function insert( LogEntry $entry ): int;

	/**
	 * @return array{items: LogEntry[], total: int}
	 */
	public function paginate( array $filters, int $page, int $perPage ): array;

	public function pruneOlderThan( \DateTimeImmutable $cutoff ): int;

	/**
	 * Delete log rows, optionally only those of one level.
	 *
	 * @param string $level '' = every level, otherwise info|warning|error|critical|debug.
	 * @return int rows deleted
	 */
	public function clear( string $level = '' ): int;
}
