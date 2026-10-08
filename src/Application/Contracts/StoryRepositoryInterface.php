<?php
/**
 * Story persistence contract (§5, §35).
 *
 * @package NewsDesk\AI\Application\Contracts
 */

namespace NewsDesk\AI\Application\Contracts;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Domain\Entity\Story;

interface StoryRepositoryInterface {

	public function insert( Story $story ): int;

	public function update( Story $story ): bool;

	/**
	 * A-3: persist extracted security intelligence for a story.
	 *
	 * @param array<string,mixed> $intel
	 */
	public function updateSecurityIntel( int $storyId, array $intel ): bool;

	public function find( int $storyId ): ?Story;

	public function findByClusterKey( string $clusterKey ): ?Story;

	/**
	 * A story selected for the same clusterKey within $since — used to avoid
	 * re-selecting the same story without new evidence.
	 */
	public function findSelectedByClusterKeySince( string $clusterKey, \DateTimeImmutable $since ): ?Story;

	/**
	 * Candidate stories for the given run window (status=candidate, run_job_id=0 or window).
	 *
	 * @return Story[]
	 */
	public function candidatesForWindow( string $windowKey ): array;

	/**
	 * @param array $filters status, category?, search
	 * @return array{items: Story[], total: int}
	 */
	public function paginate( array $filters, int $page, int $perPage ): array;

	public function countAll(): int;

	public function countByStatus( string $status ): int;

	/**
	 * Mark a story selected for a window (full metadata kept in the row).
	 */
	public function markSelected( int $storyId, \DateTimeImmutable $at, string $windowKey, string $reason, int $jobId ): bool;

	/* story_sources mapping */
	public function attachSource( int $storyId, int $sourceId, string $role ): bool;

	/**
	 * @return array<int, array{story_id: int, source_id: int, role: string}>
	 */
	public function storySources( int $storyId ): array;

	public function deleteBySource( int $sourceId ): int;

	/**
	 * Phase 3: persist research/fact-check stats on the story row.
	 */
	public function updateResearchStats( int $storyId, string $researchStatus, string $factCheckStatus, int $evidenceCount, int $verifiedCount, int $contradictionCount ): bool;
}
