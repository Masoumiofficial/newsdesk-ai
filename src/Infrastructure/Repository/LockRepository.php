<?php
/**
 * Lock row persistence.
 *
 * @package NewsDesk\AI\Infrastructure\Repository
 */

namespace NewsDesk\AI\Infrastructure\Repository;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Contracts\LockRepositoryInterface;
use NewsDesk\AI\Application\Contracts\WpDbInterface;
use NewsDesk\AI\Domain\Entity\Lock;
use NewsDesk\AI\Infrastructure\Database\TableNames;

final class LockRepository implements LockRepositoryInterface {

	/** @var WpDbInterface */
	private $db;
	/** @var TableNames */
	private $tables;

	public function __construct( WpDbInterface $db, TableNames $tables ) {
		$this->db     = $db;
		$this->tables = $tables;
	}

	public function insert( Lock $lock ): bool {
		$result = $this->db->insert( $this->tables->locks(), $lock->toDbRow(), array( '%s', '%s', '%d', '%d', '%d', '%d' ) );
		return false !== $result && (int) $result > 0;
	}

	public function update( Lock $lock ): bool {
		$row = $lock->toDbRow();
		unset( $row['lock_id'] );
		$result = $this->db->update(
			$this->tables->locks(),
			$row,
			array( 'lock_id' => $lock->lockId ),
			array( '%s', '%d', '%d', '%d', '%d' ),
			array( '%s' )
		);
		return false !== $result;
	}

	public function delete( string $lockId ): bool {
		$result = $this->db->delete( $this->tables->locks(), array( 'lock_id' => $lockId ), array( '%s' ) );
		return false !== $result;
	}

	public function find( string $lockId ): ?Lock {
		$row = $this->db->getRow(
			$this->db->prepare( 'SELECT * FROM ' . $this->tables->locks() . ' WHERE lock_id = %s LIMIT 1', $lockId )
		);
		return ( is_array( $row ) && $row ) ? Lock::fromDbRow( $row ) : null;
	}

	public function all(): array {
		$rows = $this->db->getResults( 'SELECT * FROM ' . $this->tables->locks() . ' ORDER BY lock_id ASC' );
		$out  = array();
		foreach ( $rows as $row ) {
			$out[] = Lock::fromDbRow( (array) $row );
		}
		return $out;
	}
}
