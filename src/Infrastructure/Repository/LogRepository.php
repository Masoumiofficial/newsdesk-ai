<?php
/**
 * Structured log persistence (newsdesk_logs).
 *
 * @package NewsDesk\AI\Infrastructure\Repository
 */

namespace NewsDesk\AI\Infrastructure\Repository;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Contracts\WpDbInterface;
use NewsDesk\AI\Domain\Entity\LogEntry;
use NewsDesk\AI\Infrastructure\Database\TableNames;
use NewsDesk\AI\Logging\Contracts\LogRepositoryInterface;
use NewsDesk\AI\Support\Time;

final class LogRepository implements LogRepositoryInterface {

	/** @var WpDbInterface */
	private $db;
	/** @var TableNames */
	private $tables;

	public function __construct( WpDbInterface $db, TableNames $tables ) {
		$this->db     = $db;
		$this->tables = $tables;
	}

	public function insert( LogEntry $entry ): int {
		// §77: bootstrap logging may fire before the schema exists (first
		// activation) — a $wpdb->insert on a missing table raises a WP database
		// error. Skip silently; the pipeline must never break because of a log.
		if ( ! $this->db->tableExists( $this->tables->logs() ) ) {
			return 0;
		}
		$entry->createdAt = $entry->createdAt ?: Time::now();
		$row = $entry->toDbRow();
		unset( $row['id'] );
		$result = $this->db->insert( $this->tables->logs(), $row, self::formats() );
		if ( false === $result ) {
			return 0;
		}
		$entry->id = $this->db->insertId();
		return $entry->id;
	}

	public function paginate( array $filters, int $page, int $perPage ): array {
		$where  = array();
		$params = array();
		if ( ! empty( $filters['level'] ) ) {
			$where[]  = 'level = %s';
			$params[] = $filters['level'];
		}
		if ( ! empty( $filters['job_id'] ) ) {
			$where[]  = 'job_id = %d';
			$params[] = (int) $filters['job_id'];
		}
		if ( ! empty( $filters['component'] ) ) {
			$where[]  = 'component = %s';
			$params[] = $filters['component'];
		}
		$whereSql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';
		$table    = $this->tables->logs();

		// No placeholders → never call wpdb::prepare() with zero args (PHP notice
		// on real WP; found in the LogsPage staging drill).
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

	/**
	 * B-8: admin-triggered log clearing. An unknown level is rejected rather
	 * than silently falling back to "delete everything".
	 */
	public function clear( string $level = '' ): int {
		$table = $this->tables->logs();

		if ( '' === $level ) {
			$result = $this->db->query( 'DELETE FROM ' . $table );
			return false === $result ? 0 : (int) $result;
		}

		$allowed = array( 'debug', 'info', 'warning', 'error', 'critical' );
		$level   = strtolower( $level );
		if ( ! in_array( $level, $allowed, true ) ) {
			return 0;
		}

		$result = $this->db->query(
			$this->db->prepare( 'DELETE FROM ' . $table . ' WHERE level = %s', $level )
		);
		return false === $result ? 0 : (int) $result;
	}

	public function pruneOlderThan( \DateTimeImmutable $cutoff ): int {
		// v2.0 fix: this used to call delete() with timestamp = cutoff, i.e. an
		// EXACT match, and only fell back to the correct "< cutoff" query when
		// that returned false. delete() returns 0 (not false) when nothing
		// matches, so the fallback almost never ran and pruning deleted only
		// rows landing on the exact cutoff second. Use the range query directly.
		$query = $this->db->prepare(
			'DELETE FROM ' . $this->tables->logs() . ' WHERE timestamp < %s',
			Time::toDb( $cutoff )
		);
		$result = $this->db->query( $query );
		return false === $result ? 0 : (int) $result;
	}

	/**
	 * @return LogEntry[]
	 */
	private function mapRows( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = LogEntry::fromDbRow( (array) $row );
		}
		return $out;
	}

	/**
	 * @return string[]
	 */
	private static function formats(): array {
		return array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );
	}
}
