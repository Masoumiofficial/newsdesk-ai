<?php
/**
 * Source persistence.
 *
 * @package NewsDesk\AI\Infrastructure\Repository
 */

namespace NewsDesk\AI\Infrastructure\Repository;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Contracts\SourceRepositoryInterface;
use NewsDesk\AI\Application\Contracts\WpDbInterface;
use NewsDesk\AI\Domain\Entity\Source;
use NewsDesk\AI\Infrastructure\Database\TableNames;
use NewsDesk\AI\Logging\Redaction;
use NewsDesk\AI\Support\Time;

final class SourceRepository implements SourceRepositoryInterface {

	/** @var WpDbInterface */
	private $db;
	/** @var TableNames */
	private $tables;

	public function __construct( WpDbInterface $db, TableNames $tables ) {
		$this->db     = $db;
		$this->tables = $tables;
	}

	public function insert( Source $source ): int {
		$row = $source->toDbRow();
		unset( $row['id'] );
		$result = $this->db->insert( $this->tables->sources(), $row, self::formats() );
		if ( false === $result ) {
			return 0;
		}
		$source->id = $this->db->insertId();
		return $source->id;
	}

	public function update( Source $source ): bool {
		$row = $source->toDbRow();
		unset( $row['id'] );
		$result = $this->db->update(
			$this->tables->sources(),
			$row,
			array( 'id' => $source->id ),
			self::formats(),
			array( '%d' )
		);
		return false !== $result;
	}

	public function delete( int $id ): bool {
		$result = $this->db->delete( $this->tables->sources(), array( 'id' => $id ), array( '%d' ) );
		return false !== $result;
	}

	public function find( int $id ): ?Source {
		$sql = $this->db->prepare(
			'SELECT * FROM ' . $this->tables->sources() . ' WHERE id = %d LIMIT 1',
			$id
		);
		$row = $this->db->getRow( $sql );
		return ( is_array( $row ) && $row ) ? Source::fromDbRow( $row ) : null;
	}

	public function findAll(): array {
		$sql = 'SELECT * FROM ' . $this->tables->sources() . ' ORDER BY id ASC';
		return $this->mapRows( $this->db->getResults( $sql ) );
	}

	public function findActive(): array {
		$sql = $this->db->prepare(
			'SELECT * FROM ' . $this->tables->sources() . ' WHERE active = %d AND status = %s ORDER BY priority DESC, id ASC',
			1,
			Source::STATUS_ACTIVE
		);
		return $this->mapRows( $this->db->getResults( $sql ) );
	}

	public function paginate( array $filters, int $page, int $perPage ): array {
		$where  = array();
		$params = array();

		if ( ! empty( $filters['search'] ) ) {
			$where[]  = 'name LIKE %s';
			$params[] = '%' . $filters['search'] . '%';
		}
		if ( ! empty( $filters['type'] ) ) {
			$where[]  = 'type = %s';
			$params[] = $filters['type'];
		}
		if ( ! empty( $filters['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = $filters['status'];
		}
		$whereSql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';
		$table    = $this->tables->sources();

		// No placeholders → never call wpdb::prepare() with zero args (PHP notice
		// on real WP; same bug class as LogRepository/JobRepository).
		$countSql = "SELECT COUNT(*) FROM {$table} {$whereSql}";
		$total    = (int) $this->db->getVar( $params ? $this->db->prepare( $countSql, ...$params ) : $countSql );

		$offset = max( 0, ( $page - 1 ) * $perPage );
		$sql    = $this->db->prepare(
			"SELECT * FROM {$table} {$whereSql} ORDER BY priority DESC, id ASC LIMIT %d OFFSET %d",
			...array_merge( $params, array( $perPage, $offset ) )
		);
		return array(
			'items' => $this->mapRows( $this->db->getResults( $sql ) ),
			'total' => $total,
		);
	}

	public function countAll(): int {
		return (int) $this->db->getVar( 'SELECT COUNT(*) FROM ' . $this->tables->sources() );
	}

	public function countActive(): int {
		return (int) $this->db->getVar(
			$this->db->prepare( 'SELECT COUNT(*) FROM ' . $this->tables->sources() . ' WHERE active = %d', 1 )
		);
	}

	public function markFetchStart( int $id, \DateTimeImmutable $at ): bool {
		$result = $this->db->update(
			$this->tables->sources(),
			array( 'last_fetch_at' => Time::toDb( $at ), 'updated_at' => Time::toDb( $at ) ),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		return false !== $result;
	}

	public function markSuccess( int $id, \DateTimeImmutable $at ): bool {
		$result = $this->db->update(
			$this->tables->sources(),
			array(
				'last_success_at'    => Time::toDb( $at ),
				'status'             => Source::STATUS_ACTIVE,
				'last_error_message' => '',
				'updated_at'         => Time::toDb( $at ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
		return false !== $result;
	}

	public function markError( int $id, \DateTimeImmutable $at, string $message ): bool {
		$result = $this->db->update(
			$this->tables->sources(),
			array(
				'last_error_at'      => Time::toDb( $at ),
				'status'             => Source::STATUS_ERROR,
				'last_error_message' => substr( Redaction::redact( $message ), 0, 500 ),
				'updated_at'         => Time::toDb( $at ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
		return false !== $result;
	}

	/**
	 * @return Source[]
	 */
	private function mapRows( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = Source::fromDbRow( (array) $row );
		}
		return $out;
	}

	/**
	 * @return string[] 20 columns (after id removal) matching toDbRow order.
	 */
	private static function formats(): array {
		return array(
			'%s', '%s', '%s', '%s', '%s', '%s', // name,type,url,feed_url,language,category
			'%d',                                    // priority
			'%f',                                    // base_trust_score
			'%d',                                    // trust_override
			'%f',                                    // trust_score
			'%d',                                    // active
			'%s',                                    // status
			'%d',                                    // fetch_interval_min
			'%s', '%s', '%s', '%s', '%s',            // last_fetch_at,last_success_at,last_error_at,last_error_message,settings
			'%s',                                    // source_type (A-12)
			'%d',                                    // tier (A-12)
			'%s', '%s',                              // created_at,updated_at
		);
	}
}
