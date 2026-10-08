<?php
/**
 * GeneratedImage persistence (nd_generated_images, ARCHITECTURE.md §3.2).
 *
 * @package NewsDesk\AI\Application\Contracts
 */

namespace NewsDesk\AI\Application\Contracts;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Domain\Entity\GeneratedImage;

interface GeneratedImageRepositoryInterface {

	/** @return int image_id (0 on failure) */
	public function insert( GeneratedImage $image ): int;

	/** Persist the result fields of an existing row (status/error/media/attachment). */
	public function updateResult( GeneratedImage $image ): bool;

	public function find( int $imageId ): ?GeneratedImage;

	/** @return GeneratedImage[] ordered by image_id ASC */
	public function forStory( int $storyId ): array;

	/** @return int  count of rows in a given terminal status (uploaded|failed|…) */
	public function countByStatus( string $status ): int;
}
