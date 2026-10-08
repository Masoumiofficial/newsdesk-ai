<?php
/**
 * Job event trace persistence (§50).
 *
 * @package NewsDesk\AI\Infrastructure\Repository
 */

namespace NewsDesk\AI\Infrastructure\Repository;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Contracts\JobEventRepositoryInterface;
use NewsDesk\AI\Application\Contracts\WpDbInterface;
use NewsDesk\AI\Domain\Entity\JobEvent;
use NewsDesk\AI\Infrastructure\Database\TableNames;
use NewsDesk\AI\Support\Time;

final class JobEventRepository implements JobEventRepositoryInterface {

	/** @var WpDbInterface */
	private $db;
	/** @var TableNames */
	private $tables;

	public function __construct( WpDbInterface $db, TableNames $tables ) {
		$this->db     = $db;
		$this->tables = $tables;
	}

	public function insert( JobEvent $event ): int {
		$event->seq   = $this->nextSeq( $event->jobId );
		$event->createdAt = Time::now();
		$row = $event->toDbRow();
		unset( $row['id'] );
		$result = $this->db->insert( $this->tables->jobEvents(), $row, self::formats() );
		if ( false === $result ) {
			return 0;
		}
		$event->id = $this->db->insertId();
		return $event->id;
	}

	public function findByJob( int $jobId ): array {
		$rows = $this->db->getResults(
			$this->db->prepare(
				'SELECT * FROM ' . $this->tables->jobEvents() . ' WHERE job_id = %d ORDER BY seq ASC',
				$jobId
			)
		);
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = JobEvent::fromDbRow( (array) $row );
		}
		return $out;
	}

	public function nextSeq( int $jobId ): int {
		$max = (int) $this->db->getVar(
			$this->db->prepare(
				'SELECT COALESCE(MAX(seq),0) FROM ' . $this->tables->jobEvents() . ' WHERE job_id = %d',
				$jobId
			)
		);
		return $max + 1;
	}

	public function countSince( \DateTimeImmutable $since ): int {
		return (int) $this->db->getVar(
			$this->db->prepare(
				'SELECT COUNT(*) FROM ' . $this->tables->jobEvents() . ' WHERE created_at >= %s',
				Time::toDb( $since )
			)
		);
	}

	/**
	 * @return string[]
	 */
	private static function formats(): array {
		return array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );
	}
}
