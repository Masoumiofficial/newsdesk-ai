<?php
/**
 * Research persistence (§15–§17): packages, claims, fact-check records.
 *
 * @package NewsDesk\AI\Application\Contracts
 */

namespace NewsDesk\AI\Application\Contracts;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Domain\Entity\EvidenceClaim;
use NewsDesk\AI\Domain\Entity\FactCheckRecord;
use NewsDesk\AI\Domain\Entity\ResearchPackage;

interface ResearchRepositoryInterface {

	public function insertPackage( ResearchPackage $package ): int;

	public function updatePackage( ResearchPackage $package ): bool;

	public function findPackageByStory( int $storyId ): ?ResearchPackage;

	/**
	 * @return string claim_id — '' when the (story_id, snippet_hash) already exists (dup).
	 */
	public function insertClaim( EvidenceClaim $claim ): string;

	public function claimsForStory( int $storyId ): array;

	public function claimBySnippetHash( int $storyId, string $hash ): ?EvidenceClaim;

	public function updateClaimStatus( string $claimId, string $status, ?\DateTimeImmutable $at ): bool;

	/**
	 * A-4: persist the risk band and the editorial action for a claim.
	 */
	public function updateClaimRisk( string $claimId, string $risk, string $action ): bool;

	public function insertFactCheck( FactCheckRecord $record ): int;

	public function factChecksForClaim( string $claimId ): array;

	/**
	 * @return array<string, int> status => count for a story
	 */
	public function countsByStatus( int $storyId ): array;

	public function countAllClaims(): int;

	public function deleteClaimsForStory( int $storyId ): int;
}
