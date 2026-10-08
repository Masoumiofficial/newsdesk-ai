<?php
namespace NewsDesk\AI\Application\Contracts;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Domain\Entity\Source;

interface SourceRepositoryInterface {

	public function insert( Source $source ): int;

	public function update( Source $source ): bool;

	public function delete( int $id ): bool;

	public function find( int $id ): ?Source;

	public function findActive(): array;

	/**
	 * All sources (any status) keyed for trust/attribution lookups.
	 *
	 * @return \NewsDesk\AI\Domain\Entity\Source[]
	 */
	public function findAll(): array;

	/**
	 * @param array  $filters name, type, status, active, category
	 * @return array{items: Source[], total: int}
	 */
	public function paginate( array $filters, int $page, int $perPage ): array;

	public function countAll(): int;

	public function countActive(): int;

	public function markFetchStart( int $id, \DateTimeImmutable $at ): bool;

	public function markSuccess( int $id, \DateTimeImmutable $at ): bool;

	public function markError( int $id, \DateTimeImmutable $at, string $message ): bool;
}
