<?php
/**
 * Research package + claims + fact-check records.
 *
 * @package NewsDesk\AI\Infrastructure\Repository
 */

namespace NewsDesk\AI\Infrastructure\Repository;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Contracts\ResearchRepositoryInterface;
use NewsDesk\AI\Application\Contracts\WpDbInterface;
use NewsDesk\AI\Domain\Entity\EvidenceClaim;
use NewsDesk\AI\Domain\Entity\FactCheckRecord;
use NewsDesk\AI\Domain\Entity\ResearchPackage;
use NewsDesk\AI\Infrastructure\Database\TableNames;
use NewsDesk\AI\Support\Time;

final class ResearchRepository implements ResearchRepositoryInterface {

	/** @var WpDbInterface */
	private $db;
	/** @var TableNames */
	private $tables;

	public function __construct( WpDbInterface $db, TableNames $tables ) {
		$this->db     = $db;
		$this->tables = $tables;
	}

	public function insertPackage( ResearchPackage $package ): int {
		$row = $package->toDbRow();
		unset( $row['research_id'] );
		$result = $this->db->insert( $this->tables->researchPackages(), $row, array( '%s','%d','%d','%s','%s','%s','%s','%s','%s','%f','%s','%s','%s','%s','%s','%s' ) );
		if ( false === $result ) {
			return 0;
		}
		$package->researchId = $this->db->insertId();
		return $package->researchId;
	}

	public function updatePackage( ResearchPackage $package ): bool {
		$row = $package->toDbRow();
		unset( $row['research_id'] );
		$result = $this->db->update(
			$this->tables->researchPackages(),
			$row,
			array( 'research_id' => $package->researchId ),
			array( '%s','%d','%d','%s','%s','%s','%s','%s','%s','%f','%s','%s','%s','%s','%s','%s' ),
			array( '%d' )
		);
		return false !== $result;
	}

	public function findPackageByStory( int $storyId ): ?ResearchPackage {
		$row = $this->db->getRow(
			$this->db->prepare( 'SELECT * FROM ' . $this->tables->researchPackages() . ' WHERE story_id = %d ORDER BY research_id DESC LIMIT 1', $storyId )
		);
		return ( is_array( $row ) && $row ) ? ResearchPackage::fromDbRow( $row ) : null;
	}

	public function insertClaim( EvidenceClaim $claim ): string {
		// Business-rule guard (also enforced by the UNIQUE key): never duplicate
		// the same grounded snippet within a story.
		if ( '' !== $claim->snippetHash && null !== $this->claimBySnippetHash( $claim->storyId, $claim->snippetHash ) ) {
			return '';
		}
		$row = $claim->toDbRow();
		$result = $this->db->insert( $this->tables->researchClaims(), $row, self::claimFormats() );
		if ( false === $result ) {
			return '';
		}
		return $claim->claimId;
	}

	public function claimsForStory( int $storyId ): array {
		$rows = $this->db->getResults(
			$this->db->prepare(
				'SELECT * FROM ' . $this->tables->researchClaims() . ' WHERE story_id = %d ORDER BY created_at ASC, claim_id ASC',
				$storyId
			)
		);
		return $this->mapClaims( $rows );
	}

	public function claimBySnippetHash( int $storyId, string $hash ): ?EvidenceClaim {
		$row = $this->db->getRow(
			$this->db->prepare(
				'SELECT * FROM ' . $this->tables->researchClaims() . ' WHERE story_id = %d AND snippet_hash = %s LIMIT 1',
				$storyId,
				$hash
			)
		);
		return ( is_array( $row ) && $row ) ? EvidenceClaim::fromDbRow( $row ) : null;
	}

	public function updateClaimStatus( string $claimId, string $status, ?\DateTimeImmutable $at ): bool {
		$result = $this->db->update(
			$this->tables->researchClaims(),
			array(
				'verification_status' => $status,
				'verified_at'         => Time::toDb( $at ),
			),
			array( 'claim_id' => $claimId ),
			array( '%s', '%s' ),
			array( '%s' )
		);
		return false !== $result;
	}

	public function updateClaimRisk( string $claimId, string $risk, string $action ): bool {
		$result = $this->db->update(
			$this->tables->researchClaims(),
			array(
				'risk'   => $risk,
				'action' => $action,
			),
			array( 'claim_id' => $claimId ),
			array( '%s', '%s' ),
			array( '%s' )
		);
		return false !== $result;
	}

	public function insertFactCheck( FactCheckRecord $record ): int {
		$row = $record->toDbRow();
		unset( $row['id'] );
		$result = $this->db->insert( $this->tables->factChecks(), $row, array( '%s','%d','%d','%s','%s','%s','%s','%s','%s' ) );
		if ( false === $result ) {
			return 0;
		}
		$record->id = $this->db->insertId();
		return $record->id;
	}

	public function factChecksForClaim( string $claimId ): array {
		$rows = $this->db->getResults(
			$this->db->prepare(
				'SELECT * FROM ' . $this->tables->factChecks() . ' WHERE claim_id = %s ORDER BY id ASC',
				$claimId
			)
		);
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = FactCheckRecord::fromDbRow( (array) $row );
		}
		return $out;
	}

	public function countsByStatus( int $storyId ): array {
		$rows = $this->db->getResults(
			$this->db->prepare(
				'SELECT verification_status AS st, COUNT(*) AS n FROM ' . $this->tables->researchClaims() . ' WHERE story_id = %d GROUP BY verification_status',
				$storyId
			)
		);
		$out = array();
		foreach ( $rows as $row ) {
			$out[ (string) $row['st'] ] = (int) $row['n'];
		}
		return $out;
	}

	public function countAllClaims(): int {
		return (int) $this->db->getVar( 'SELECT COUNT(*) FROM ' . $this->tables->researchClaims() );
	}

	public function deleteClaimsForStory( int $storyId ): int {
		$result = $this->db->delete( $this->tables->researchClaims(), array( 'story_id' => $storyId ), array( '%d' ) );
		return false === $result ? 0 : (int) $result;
	}

	/**
	 * @return EvidenceClaim[]
	 */
	private function mapClaims( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = EvidenceClaim::fromDbRow( (array) $row );
		}
		return $out;
	}

	private static function claimFormats(): array {
		return array( '%s','%d','%d','%d','%s','%s','%d','%s','%s','%s','%s','%s','%s','%f','%s','%s','%s','%s','%s','%s' );
	}
}
