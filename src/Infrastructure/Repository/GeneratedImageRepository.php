<?php
/**
 * Generated images (nd_generated_images) — ARCHITECTURE.md §3.2.
 *
 * @package NewsDesk\AI\Infrastructure\Repository
 */

namespace NewsDesk\AI\Infrastructure\Repository;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Contracts\GeneratedImageRepositoryInterface;
use NewsDesk\AI\Application\Contracts\WpDbInterface;
use NewsDesk\AI\Domain\Entity\GeneratedImage;
use NewsDesk\AI\Infrastructure\Database\TableNames;

final class GeneratedImageRepository implements GeneratedImageRepositoryInterface {

	/** @var WpDbInterface */
	private $db;
	/** @var TableNames */
	private $tables;

	public function __construct( WpDbInterface $db, TableNames $tables ) {
		$this->db     = $db;
		$this->tables = $tables;
	}

	public function insert( GeneratedImage $image ): int {
		$row    = $image->toDbRow();
		unset( $row['image_id'] );
		$result = $this->db->insert(
			$this->tables->generatedImages(),
			$row,
			array( '%d','%d','%s','%s','%s','%s','%d','%s','%d','%d','%d','%s','%d','%s','%s','%s' )
		);
		if ( false === $result ) {
			return 0;
		}
		$image->imageId = $this->db->insertId();
		return $image->imageId;
	}

	public function updateResult( GeneratedImage $image ): bool {
		return (bool) $this->db->update(
			$this->tables->generatedImages(),
			array(
				'status'          => $image->status,
				'error_code'      => $image->errorCode,
				'attachment_id'   => $image->attachmentId,
				'media_url'       => $image->mediaUrl,
				'width'           => $image->width,
				'height'          => $image->height,
				'size_bytes'      => $image->sizeBytes,
				'has_brand_composition' => $image->hasBrandComposition ? 1 : 0,
			),
			array( 'image_id' => $image->imageId ),
			array( '%s','%s','%d','%s','%d','%d','%d','%d' ),
			array( '%d' )
		);
	}

	public function find( int $imageId ): ?GeneratedImage {
		$row = $this->db->getRow(
			$this->db->prepare( 'SELECT * FROM ' . $this->tables->generatedImages() . ' WHERE image_id = %d LIMIT 1', $imageId )
		);
		return ( is_array( $row ) && $row ) ? GeneratedImage::fromDbRow( $row ) : null;
	}

	public function forStory( int $storyId ): array {
		$rows = $this->db->getResults(
			$this->db->prepare( 'SELECT * FROM ' . $this->tables->generatedImages() . ' WHERE story_id = %d ORDER BY image_id ASC', $storyId )
		);
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = GeneratedImage::fromDbRow( $row );
		}
		return $out;
	}

	public function countByStatus( string $status ): int {
		return (int) $this->db->getVar(
			$this->db->prepare( 'SELECT COUNT(*) FROM ' . $this->tables->generatedImages() . ' WHERE status = %s', $status )
		);
	}
}
