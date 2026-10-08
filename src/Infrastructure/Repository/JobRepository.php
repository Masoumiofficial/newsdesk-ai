<?php
/**
 * Job persistence + atomic optimistic transitions.
 *
 * @package NewsDesk\AI\Infrastructure\Repository
 */

namespace NewsDesk\AI\Infrastructure\Repository;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Contracts\JobRepositoryInterface;
use NewsDesk\AI\Application\Contracts\WpDbInterface;
use NewsDesk\AI\Domain\Entity\Job;
use NewsDesk\AI\Infrastructure\Database\TableNames;
use NewsDesk\AI\Support\Time;

final class JobRepository implements JobRepositoryInterface {

	/** @var WpDbInterface */
	private $db;
	/** @var TableNames */
	private $tables;

	public function __construct( WpDbInterface $db, TableNames $tables ) {
		$this->db     = $db;
		$this->tables = $tables;
	}

	public function insert( Job $job ): int {
		$row = $job->toDbRow();
		unset( $row['id'] );
		$result = $this->db->insert( $this->tables->jobs(), $row, self::formats() );
		if ( false === $result ) {
			return 0;
		}
		$job->id = $this->db->insertId();
		return $job->id;
	}

	public function update( Job $job ): bool {
		$row = $job->toDbRow();
		unset( $row['id'] );
		$result = $this->db->update(
			$this->tables->jobs(),
			$row,
			array( 'id' => $job->id ),
			self::formats(),
			array( '%d' )
		);
		return false !== $result;
	}

	public function find( int $id ): ?Job {
		$row = $this->db->getRow(
			$this->db->prepare( 'SELECT * FROM ' . $this->tables->jobs() . ' WHERE id = %d LIMIT 1', $id )
		);
		return ( is_array( $row ) && $row ) ? Job::fromDbRow( $row ) : null;
	}

	public function findByKey( string $jobKey ): ?Job {
		$row = $this->db->getRow(
			$this->db->prepare( 'SELECT * FROM ' . $this->tables->jobs() . ' WHERE job_key = %s LIMIT 1', $jobKey )
		);
		return ( is_array( $row ) && $row ) ? Job::fromDbRow( $row ) : null;
	}

	public function transition( int $id, string $expectedFrom, string $event, string $newStatus, string $stage, array $payloadUpdate ): bool {
		\NewsDesk\AI\Domain\JobStateMachine::assertTransition( $expectedFrom, $event, $newStatus );
		$job = $this->find( $id );
		if ( null === $job || $job->status !== $expectedFrom ) {
			return false;
		}
		$job->payload = array_merge( $job->payload, $payloadUpdate );
		$job->status  = $newStatus;
		$job->stage   = $stage;
		$job->updatedAt = Time::now();
		$row = $job->toDbRow();
		unset( $row['id'] );
		$result = $this->db->update(
			$this->tables->jobs(),
			$row,
			array( 'id' => $id, 'status' => $expectedFrom ),
			self::formats(),
			array( '%d', '%s' )
		);
		return false !== $result && 1 === (int) $result;
	}

	public function touchStarted( int $id, \DateTimeImmutable $at ): bool {
		$result = $this->db->update(
			$this->tables->jobs(),
			array( 'started_at' => Time::toDb( $at ), 'updated_at' => Time::toDb( $at ) ),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		return false !== $result;
	}

	public function touchFinished( int $id, \DateTimeImmutable $at, string $status ): bool {
		$result = $this->db->update(
			$this->tables->jobs(),
			array( 'finished_at' => Time::toDb( $at ), 'updated_at' => Time::toDb( $at ) ),
			array( 'id' => $id, 'status' => $status ),
			array( '%s', '%s' ),
			array( '%d', '%s' )
		);
		return false !== $result;
	}

	public function bumpAttempts( int $id, int $attempts, ?string $retryTarget ): bool {
		$result = $this->db->update(
			$this->tables->jobs(),
			array(
				'attempts'     => $attempts,
				'retry_target' => $retryTarget,
				'updated_at'   => Time::toDb( Time::now() ),
			),
			array( 'id' => $id ),
			array( '%d', '%s', '%s' ),
			array( '%d' )
		);
		return false !== $result;
	}

	public function recordError( int $id, string $code, string $message ): bool {
		$result = $this->db->update(
			$this->tables->jobs(),
			array(
				'error_code'    => substr( $code, 0, 100 ),
				'error_message' => substr( \NewsDesk\AI\Logging\Redaction::redact( $message ), 0, 2000 ),
				'updated_at'    => Time::toDb( Time::now() ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
		return false !== $result;
	}

	public function paginate( array $filters, int $page, int $perPage ): array {
		$where  = array();
		$params = array();
		if ( ! empty( $filters['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = $filters['status'];
		}
		if ( ! empty( $filters['type'] ) ) {
			$where[]  = 'type = %s';
			$params[] = $filters['type'];
		}
		$whereSql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';
		$table    = $this->tables->jobs();

		// No placeholders → never call wpdb::prepare() with zero args (PHP notice
		// on real WP; same class of bug as LogRepository).
		$countSql = "SELECT COUNT(*) FROM {$table} {$whereSql}";
		$total    = (int) $this->db->getVar( $params ? $this->db->prepare( $countSql, ...$params ) : $countSql );
		$offset = max( 0, ( $page - 1 ) * $perPage );
		$sql    = $this->db->prepare(
			"SELECT * FROM {$table} {$whereSql} ORDER BY id DESC LIMIT %d OFFSET %d",
			...array_merge( $params, array( $perPage, $offset ) )
		);
		return array(
			'items' => $this->mapRows( $this->db->getResults( $sql ) ),
			'total' => $total,
		);
	}

	public function countByStatus( string $status ): int {
		return (int) $this->db->getVar(
			$this->db->prepare( 'SELECT COUNT(*) FROM ' . $this->tables->jobs() . ' WHERE status = %s', $status )
		);
	}

	public function findRecent( int $limit ): array {
		$sql = $this->db->prepare(
			'SELECT * FROM ' . $this->tables->jobs() . ' ORDER BY id DESC LIMIT %d',
			$limit
		);
		return $this->mapRows( $this->db->getResults( $sql ) );
	}

	/**
	 * @return Job[]
	 */
	private function mapRows( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = Job::fromDbRow( (array) $row );
		}
		return $out;
	}

	/**
	 * @return string[]
	 */
	private static function formats(): array {
		return array(
			'%s', // job_key
			'%s', // type
			'%s', // status
			'%s', // stage
			'%s', // state_payload
			'%d', // priority
			'%s', // scheduled_at
			'%s', // started_at
			'%s', // finished_at
			'%d', // attempts
			'%d', // max_attempts
			'%s', // retry_target
			'%s', // error_code
			'%s', // error_message
			'%s', // correlation_id
			'%s', // created_at
			'%s', // updated_at
		);
	}
}
