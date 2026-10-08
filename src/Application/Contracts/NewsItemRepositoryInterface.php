<?php
namespace NewsDesk\AI\Application\Contracts;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Domain\Entity\NewsItem;

interface NewsItemRepositoryInterface {

	public function insert( NewsItem $item ): int;

	/**
	 * Do we already know this item? Cheap dedup ladder (§9 steps 1–3).
	 *
	 * @param string $guid      Feed GUID (may be '').
	 * @param string $canonical Canonical URL.
	 * @param string $hash      Content hash (may be '').
	 * @param int    $sourceId  Source scope (0 = any source).
	 */
	public function exists( string $guid, string $canonical, string $hash, int $sourceId = 0 ): bool;

	/**
	 * Resolve the winning row a candidate duplicates.
	 *
	 * @return array|null array{id:int, level:string} or null when unique.
	 */
	public function findDuplicate( string $guid, string $canonical, string $hash, int $sourceId = 0 );

	/** Record $itemId as a duplicate of $winnerId (level: guid|url|hash|title). */
	public function markDuplicate( int $itemId, int $winnerId, string $level ): bool;

	/** Bump last_seen_at on an existing item. */
	public function touchLastSeen( int $itemId ): bool;

	public function countAll(): int;

	public function countSince( \DateTimeImmutable $since ): int;

	public function countDuplicates(): int;

	public function deleteBySource( int $sourceId ): int;

	/**
	 * @return array{items: NewsItem[], total: int}
	 */
	public function paginate( array $filters, int $page, int $perPage ): array;

	public function findRecent( int $limit ): array;

	/**
	 * Normalized items seen since $since (Phase 2 clustering window).
	 *
	 * @return \NewsDesk\AI\Domain\Entity\NewsItem[]
	 */
	public function findNormalizedSince( \DateTimeImmutable $since ): array;

	/**
	 * @param int[] $ids
	 * @return \NewsDesk\AI\Domain\Entity\NewsItem[]
	 */
	public function findByIds( array $ids ): array;
}
