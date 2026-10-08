<?php
/**
 * nd_internal_links + nd_external_links.
 *
 * @package NewsDesk\AI\Infrastructure\Repository
 */

namespace NewsDesk\AI\Infrastructure\Repository;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Contracts\LinkRepositoryInterface;
use NewsDesk\AI\Application\Contracts\WpDbInterface;
use NewsDesk\AI\Domain\Entity\ExternalLink;
use NewsDesk\AI\Domain\Entity\InternalLinkSuggestion;
use NewsDesk\AI\Infrastructure\Database\TableNames;

final class LinkRepository implements LinkRepositoryInterface {

	/** @var WpDbInterface */
	private $db;
	/** @var TableNames */
	private $tables;

	public function __construct( WpDbInterface $db, TableNames $tables ) {
		$this->db     = $db;
		$this->tables = $tables;
	}

	public function insertInternal( InternalLinkSuggestion $suggestion ): int {
		$row = $suggestion->toDbRow();
		unset( $row['id'] );
		$result = $this->db->insert( $this->tables->internalLinks(), $row, array( '%d','%d','%s','%s','%f','%s','%s','%s' ) );
		if ( false === $result ) {
			return 0;
		}
		$suggestion->id = $this->db->insertId();
		return $suggestion->id;
	}

	public function internalForTarget( int $targetPostId ): array {
		$rows = $this->db->getResults(
			$this->db->prepare( 'SELECT * FROM ' . $this->tables->internalLinks() . ' WHERE target_post_id = %d ORDER BY confidence DESC', $targetPostId )
		);
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = InternalLinkSuggestion::fromDbRow( $row );
		}
		return $out;
	}

	public function insertExternal( ExternalLink $link ): int {
		$row = $link->toDbRow();
		unset( $row['id'] );
		$result = $this->db->insert( $this->tables->externalLinks(), $row, array( '%s','%d','%s','%s','%s','%s','%s' ) );
		if ( false === $result ) {
			return 0;
		}
		$link->id = $this->db->insertId();
		return $link->id;
	}

	public function externalForClaimIds( array $claimIds ): array {
		if ( ! $claimIds ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $claimIds ), '%s' ) );
		$rows = $this->db->getResults(
			$this->db->prepare( 'SELECT * FROM ' . $this->tables->externalLinks() . ' WHERE claim_id IN (' . $placeholders . ') ORDER BY id ASC', $claimIds )
		);
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = ExternalLink::fromDbRow( $row );
		}
		return $out;
	}

	public function externalForStory( int $storyId ): array {
		$rows = $this->db->getResults(
			$this->db->prepare(
				'SELECT l.* FROM ' . $this->tables->externalLinks() . ' l INNER JOIN ' . $this->tables->researchClaims() . ' c ON c.claim_id = l.claim_id WHERE c.story_id = %d ORDER BY l.priority ASC, l.id ASC',
				$storyId
			)
		);
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = ExternalLink::fromDbRow( $row );
		}
		return $out;
	}
}
