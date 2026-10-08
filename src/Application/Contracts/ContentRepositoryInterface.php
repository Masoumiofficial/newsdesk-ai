<?php
/**
 * Content version persistence (§31 audit trail).
 *
 * @package NewsDesk\AI\Application\Contracts
 */

namespace NewsDesk\AI\Application\Contracts;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Domain\Entity\ContentVersion;

interface ContentRepositoryInterface {

	/** @return int version_id (0 on failure) */
	public function insert( ContentVersion $version ): int;

	public function find( int $versionId ): ?ContentVersion;

	/**
	 * Versions of one story, oldest first.
	 *
	 * @return ContentVersion[]
	 */
	public function versionsForStory( int $storyId ): array;

	public function latestForStory( int $storyId ): ?ContentVersion;

	/** @return int next version_no for a story (max+1) */
	public function nextVersionNo( int $storyId ): int;

	public function countAll(): int;

	/**
	 * Latest needs_review version per story (human-review queue, §36).
	 * @return ContentVersion[]  one per story, newest first
	 */
	public function latestNeedsReview(): array;

	/**
	 * Latest version per story with status approved OR needs_review (v1.3.4).
	 * @return ContentVersion[]
	 */
	public function awaitingDecision(): array;

	public function updateStatus( int $versionId, string $status, float $qualityScore, string $errorCode = '' ): bool;

	public function linkDraft( int $versionId, int $draftPostId ): bool;
}
