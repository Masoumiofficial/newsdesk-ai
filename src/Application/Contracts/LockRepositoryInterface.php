<?php
namespace NewsDesk\AI\Application\Contracts;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Domain\Entity\Lock;

interface LockRepositoryInterface {

	public function insert( Lock $lock ): bool;

	public function update( Lock $lock ): bool;

	public function delete( string $lockId ): bool;

	public function find( string $lockId ): ?Lock;

	/**
	 * @return Lock[]
	 */
	public function all(): array;
}
