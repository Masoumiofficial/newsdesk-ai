<?php
/**
 * Story persistence (§5): clustering output, scoring columns, selection.
 *
 * @package NewsDesk\AI\Infrastructure\Repository
 */

namespace NewsDesk\AI\Infrastructure\Repository;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Contracts\StoryRepositoryInterface;
use NewsDesk\AI\Application\Contracts\WpDbInterface;
use NewsDesk\AI\Domain\Entity\Story;
use NewsDesk\AI\Infrastructure\Database\TableNames;
use NewsDesk\AI\Support\Time;

final class StoryRepository implements StoryRepositoryInterface {

	/** @var WpDbInterface */
	private $db;
	/** @var TableNames */
	private $tables;

	public function __construct( WpDbInterface $db, TableNames $tables ) {
		$this->db     = $db;
		$this->tables = $tables;
	}

	public function insert( Story $story ): int {
		$row = $story->toDbRow();
		unset( $row['story_id'] );
		$result = $this->db->insert( $this->tables->stories(), $row, self::formats() );
		if ( false === $result ) {
			return 0;
		}
		$story->storyId = $this->db->insertId();
		return $story->storyId;
	}

	public function update( Story $story ): bool {
		$row = $story->toDbRow();
		unset( $row['story_id'] );
		$result = $this->db->update(
			$this->tables->stories(),
			$row,
			array( 'story_id' => $story->storyId ),
			self::formats(),
			array( '%d' )
		);
		return false !== $result;
	}

	public function updateSecurityIntel( int $storyId, array $intel ): bool {
		$result = $this->db->update(
			$this->tables->stories(),
			array(
				'is_security'       => ! empty( $intel['is_security'] ) ? 1 : 0,
				'cve_ids'           => wp_json_encode( array_values( (array) ( $intel['cve_ids'] ?? array() ) ) ),
				'cvss_score'        => isset( $intel['cvss_score'] ) ? $intel['cvss_score'] : null,
				'severity'          => (string) ( $intel['severity'] ?? '' ),
				'affected_versions' => wp_json_encode( array_values( (array) ( $intel['affected_versions'] ?? array() ) ) ),
				'fixed_versions'    => wp_json_encode( array_values( (array) ( $intel['fixed_versions'] ?? array() ) ) ),
				'exploited'         => ! empty( $intel['exploited'] ) ? 1 : 0,
				'vendor_advisory'   => (string) ( $intel['vendor_advisory'] ?? '' ),
			),
			array( 'story_id' => $storyId ),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);
		return false !== $result;
	}

	public function find( int $storyId ): ?Story {
		$row = $this->db->getRow(
			$this->db->prepare( 'SELECT * FROM ' . $this->tables->stories() . ' WHERE story_id = %d LIMIT 1', $storyId )
		);
		return ( is_array( $row ) && $row ) ? Story::fromDbRow( $row ) : null;
	}

	public function findByClusterKey( string $clusterKey ): ?Story {
		$row = $this->db->getRow(
			$this->db->prepare( 'SELECT * FROM ' . $this->tables->stories() . ' WHERE cluster_key = %s LIMIT 1', $clusterKey )
		);
		return ( is_array( $row ) && $row ) ? Story::fromDbRow( $row ) : null;
	}

	public function findSelectedByClusterKeySince( string $clusterKey, \DateTimeImmutable $since ): ?Story {
		$row = $this->db->getRow(
			$this->db->prepare(
				'SELECT * FROM ' . $this->tables->stories() . ' WHERE cluster_key = %s AND status = %s AND selected_at >= %s ORDER BY selected_at DESC LIMIT 1',
				$clusterKey,
				Story::STATUS_SELECTED,
				Time::toDb( $since )
			)
		);
		return ( is_array( $row ) && $row ) ? Story::fromDbRow( $row ) : null;
	}

	public function candidatesForWindow( string $windowKey ): array {
		$rows = $this->db->getResults(
			$this->db->prepare(
				'SELECT * FROM ' . $this->tables->stories() . ' WHERE status = %s AND (window_key = %s OR run_job_id = %d) ORDER BY editorial_score DESC, importance_score DESC',
				Story::STATUS_CANDIDATE,
				$windowKey,
				0
			)
		);
		return $this->mapRows( $rows );
	}

	public function paginate( array $filters, int $page, int $perPage ): array {
		$where  = array();
		$params = array();
		if ( ! empty( $filters['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = $filters['status'];
		}
		$whereSql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';
		$table    = $this->tables->stories();

		$total  = (int) $this->db->getVar( $this->db->prepare( "SELECT COUNT(*) FROM {$table} {$whereSql}", ...$params ) );
		$offset = max( 0, ( $page - 1 ) * $perPage );
		$sql    = $this->db->prepare(
			"SELECT * FROM {$table} {$whereSql} ORDER BY editorial_score DESC, importance_score DESC, story_id DESC LIMIT %d OFFSET %d",
			...array_merge( $params, array( $perPage, $offset ) )
		);
		return array(
			'items' => $this->mapRows( $this->db->getResults( $sql ) ),
			'total' => $total,
		);
	}

	public function countAll(): int {
		return (int) $this->db->getVar( 'SELECT COUNT(*) FROM ' . $this->tables->stories() );
	}

	public function countByStatus( string $status ): int {
		return (int) $this->db->getVar(
			$this->db->prepare( 'SELECT COUNT(*) FROM ' . $this->tables->stories() . ' WHERE status = %s', $status )
		);
	}

	public function markSelected( int $storyId, \DateTimeImmutable $at, string $windowKey, string $reason, int $jobId ): bool {
		$result = $this->db->update(
			$this->tables->stories(),
			array(
				'status'            => Story::STATUS_SELECTED,
				'window_key'        => $windowKey,
				'selected_at'       => Time::toDb( $at ),
				'selection_reason'  => $reason,
				'run_job_id'        => $jobId,
				'updated_at'        => Time::toDb( $at ),
			),
			array( 'story_id' => $storyId ),
			array( '%s', '%s', '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);
		return false !== $result && (int) $result > 0;
	}

	public function attachSource( int $storyId, int $sourceId, string $role ): bool {
		$result = $this->db->insert(
			$this->tables->storySources(),
			array(
				'story_id'          => $storyId,
				'source_id'         => $sourceId,
				'role'              => $role,
				'added_at'          => Time::toDb( Time::now() ),
			),
			array( '%d', '%d', '%s', '%s' )
		);
		return false !== $result && (int) $result > 0;
	}

	public function storySources( int $storyId ): array {
		$rows = $this->db->getResults(
			$this->db->prepare(
				'SELECT story_id, source_id, role FROM ' . $this->tables->storySources() . ' WHERE story_id = %d',
				$storyId
			)
		);
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'story_id'  => (int) ( $row['story_id'] ?? 0 ),
				'source_id' => (int) ( $row['source_id'] ?? 0 ),
				'role'      => (string) ( $row['role'] ?? 'secondary' ),
			);
		}
		return $out;
	}

	public function updateResearchStats( int $storyId, string $researchStatus, string $factCheckStatus, int $evidenceCount, int $verifiedCount, int $contradictionCount ): bool {
		$result = $this->db->update(
			$this->tables->stories(),
			array(
				'research_status'      => $researchStatus,
				'fact_check_status'    => $factCheckStatus,
				'evidence_count'       => $evidenceCount,
				'verified_claim_count' => $verifiedCount,
				'contradiction_count'  => $contradictionCount,
				'updated_at'           => Time::toDb( Time::now() ),
			),
			array( 'story_id' => $storyId ),
			array( '%s', '%s', '%d', '%d', '%d', '%s' ),
			array( '%d' )
		);
		return false !== $result;
	}

	public function countByContentStatus( string $status ): int {
		return (int) $this->db->getVar(
			$this->db->prepare( 'SELECT COUNT(*) FROM ' . $this->tables->stories() . ' WHERE content_status = %s', $status )
		);
	}

	/** @return array<string, int> content_status => count */
	public function contentStatusCounts(): array {
		$rows = $this->db->getResults(
			'SELECT content_status AS st, COUNT(*) AS n FROM ' . $this->tables->stories() . ' GROUP BY content_status'
		);
		$out = array();
		foreach ( $rows as $row ) {
			$out[ (string) $row['st'] ] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * Phase 4: content pipeline status per story.
	 */
	public function updateContentStatus( int $storyId, string $status ): bool {
		$result = $this->db->update(
			$this->tables->stories(),
			array( 'content_status' => $status, 'updated_at' => Time::now()->format( 'Y-m-d H:i:s' ) ),
			array( 'story_id' => $storyId ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		return false !== $result;
	}

	public function deleteBySource( int $sourceId ): int {
		// Deletes both the mapping rows and (softly) stories whose only evidence was this source.
		$deleted = 0;
		$mapping = $this->db->delete( $this->tables->storySources(), array( 'source_id' => $sourceId ), array( '%d' ) );
		if ( false !== $mapping ) {
			$deleted += (int) $mapping;
		}
		$orphans = $this->db->getResults(
			$this->db->prepare(
				'SELECT story_id FROM ' . $this->tables->stories() . ' WHERE primary_source_id = %d',
				$sourceId
			)
		);
		foreach ( $orphans as $orphan ) {
			$sid = (int) ( $orphan['story_id'] ?? 0 );
			if ( $sid > 0 ) {
				$result = $this->db->update(
					$this->tables->stories(),
					array( 'status' => Story::STATUS_EXPIRED, 'updated_at' => Time::toDb( Time::now() ) ),
					array( 'story_id' => $sid ),
					array( '%s', '%s' ),
					array( '%d' )
				);
				if ( false !== $result ) {
					$deleted++;
				}
			}
		}
		return $deleted;
	}

	/**
	 * @return Story[]
	 */
	private function mapRows( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = Story::fromDbRow( (array) $row );
		}
		return $out;
	}

	private static function formats(): array {
		return array(
			'%s', '%d', '%s', '%s', '%s', '%f', '%f', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s',
			'%s', '%d', '%d', '%s', '%s', '%f', '%f', '%f', '%f', '%f', '%s', '%s', '%s', '%s',
			'%s', '%s', '%d', '%d', '%d', '%s',
			// A-3 security intelligence: is_security, cve_ids, cvss_score,
			// severity, affected_versions, fixed_versions, vendor_advisory,
			// exploited. ORDER MUST MATCH Story::toDbRow() exactly -- wpdb maps
			// $format to $data positionally, so a missing specifier silently
			// mis-casts every column after it.
			'%d', '%s', '%f', '%s', '%s', '%s', '%s', '%d',
		);
	}
}
