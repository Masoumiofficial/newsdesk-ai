<?php
/**
 * Content versions (nd_content_versions).
 *
 * @package NewsDesk\AI\Infrastructure\Repository
 */

namespace NewsDesk\AI\Infrastructure\Repository;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Contracts\ContentRepositoryInterface;
use NewsDesk\AI\Application\Contracts\WpDbInterface;
use NewsDesk\AI\Domain\Entity\ContentVersion;
use NewsDesk\AI\Infrastructure\Database\TableNames;

final class ContentRepository implements ContentRepositoryInterface {

	/** @var WpDbInterface */
	private $db;
	/** @var TableNames */
	private $tables;

	public function __construct( WpDbInterface $db, TableNames $tables ) {
		$this->db     = $db;
		$this->tables = $tables;
	}

	public function insert( ContentVersion $version ): int {
		$row = $version->toDbRow();
		unset( $row['version_id'] );
		$result = $this->db->insert( $this->tables->contentVersions(), $row, array( '%d','%d','%d','%d','%s','%s','%s','%s','%f','%s','%s','%s','%s' ) );
		if ( false === $result ) {
			return 0;
		}
		$version->versionId = $this->db->insertId();
		return $version->versionId;
	}

	public function find( int $versionId ): ?ContentVersion {
		$row = $this->db->getRow(
			$this->db->prepare( 'SELECT * FROM ' . $this->tables->contentVersions() . ' WHERE version_id = %d LIMIT 1', $versionId )
		);
		return ( is_array( $row ) && $row ) ? ContentVersion::fromDbRow( $row ) : null;
	}

	public function versionsForStory( int $storyId ): array {
		$rows = $this->db->getResults(
			$this->db->prepare( 'SELECT * FROM ' . $this->tables->contentVersions() . ' WHERE story_id = %d ORDER BY version_no ASC', $storyId )
		);
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = ContentVersion::fromDbRow( $row );
		}
		return $out;
	}

	public function latestForStory( int $storyId ): ?ContentVersion {
		$row = $this->db->getRow(
			$this->db->prepare( 'SELECT * FROM ' . $this->tables->contentVersions() . ' WHERE story_id = %d ORDER BY version_no DESC LIMIT 1', $storyId )
		);
		return ( is_array( $row ) && $row ) ? ContentVersion::fromDbRow( $row ) : null;
	}

	public function countAll(): int {
		return (int) $this->db->getVar( 'SELECT COUNT(*) FROM ' . $this->tables->contentVersions() );
	}

	public function latestNeedsReview(): array {
		return $this->latestWithStatuses( array( ContentVersion::STATUS_NEEDS_REVIEW ) );
	}

	/**
	 * v1.3.4: everything awaiting a human decision — gate-passed drafts
	 * (approved) AND below-gate versions (needs_review). Latest per story.
	 */
	public function awaitingDecision(): array {
		return $this->latestWithStatuses( array( ContentVersion::STATUS_APPROVED, ContentVersion::STATUS_NEEDS_REVIEW ) );
	}

	/** @param string[] $statuses */
	private function latestWithStatuses( array $statuses ): array {
		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$rows = $this->db->getResults(
			$this->db->prepare( 'SELECT * FROM ' . $this->tables->contentVersions() . ' WHERE status IN (' . $placeholders . ')', ...$statuses ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		// Group + dedupe in PHP (latest version_no wins per story).
		$byStory = array();
		foreach ( $rows as $row ) {
			$v = ContentVersion::fromDbRow( $row );
			if ( ! isset( $byStory[ $v->storyId ] ) || $v->versionNo > $byStory[ $v->storyId ]->versionNo ) {
				$byStory[ $v->storyId ] = $v;
			}
		}
		usort( $byStory, static function ( ContentVersion $a, ContentVersion $b ) {
			$t = ( $a->createdAt ? $a->createdAt->getTimestamp() : 0 ) <=> ( $b->createdAt ? $b->createdAt->getTimestamp() : 0 );
			return -1 * $t; // newest first
		} );
		return array_values( $byStory );
	}

	public function nextVersionNo( int $storyId ): int {
		return (int) $this->db->getVar(
			$this->db->prepare( 'SELECT COALESCE(MAX(version_no), 0) + 1 FROM ' . $this->tables->contentVersions() . ' WHERE story_id = %d', $storyId )
		);
	}

	public function updateStatus( int $versionId, string $status, float $qualityScore, string $errorCode = '' ): bool {
		$result = $this->db->update(
			$this->tables->contentVersions(),
			array(
				'status'        => $status,
				'quality_score' => (float) $qualityScore,
				'error_code'    => $errorCode,
			),
			array( 'version_id' => $versionId ),
			array( '%s', '%f', '%s' ),
			array( '%d' )
		);
		return false !== $result;
	}

	public function linkDraft( int $versionId, int $draftPostId ): bool {
		$result = $this->db->update(
			$this->tables->contentVersions(),
			array( 'draft_post_id' => $draftPostId ),
			array( 'version_id' => $versionId ),
			array( '%d' ),
			array( '%d' )
		);
		return false !== $result;
	}
}
