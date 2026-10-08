<?php
/**
 * One generated image attempt for a story (ARCHITECTURE.md). Append-only audit row:
 * prompt (the prompt text itself is stored for provenance — it never contains
 * secrets §23), provider/job ref, download result, WP attachment link and the
 * terminal status. NEVERTHELESS: failing to produce an image never blocks the
 * job (§66) — a row with status=failed + error_code is the trace, and the
 * article proceeds without a thumbnail.
 *
 * @package NewsDesk\AI\Domain\Entity
 */

namespace NewsDesk\AI\Domain\Entity;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Support\Time;

final class GeneratedImage extends AbstractEntity {

	public const STATUS_QUEUED   = 'queued';
	public const STATUS_GENERATED = 'generated';
	public const STATUS_UPLOADED = 'uploaded';
	public const STATUS_FAILED   = 'failed';

	/** @var int */
	public $imageId = 0;
	/** @var int */
	public $storyId = 0;
	/** @var int */
	public $jobId = 0;
	/** @var string */
	public $provider = '';
	/** @var string */
	public $providerJobRef = '';
	/** @var string */
	public $prompt = '';
	/** @var string */
	public $promptVersion = '';
	/** @var int */
	public $attachmentId = 0;
	/** @var string */
	public $mediaUrl = '';
	/** @var int */
	public $width = 0;
	/** @var int */
	public $height = 0;
	/** @var int */
	public $sizeBytes = 0;
	/** @var string */
	public $altText = '';
	/** @var bool */
	public $hasBrandComposition = false;
	/** @var string */
	public $status = self::STATUS_QUEUED;
	/** @var string */
	public $errorCode = '';
	/** @var \DateTimeImmutable|null */
	public $createdAt;

	public static function fromDbRow( array $row ): self {
		$g                      = new self();
		$g->imageId             = self::intOr( $row['image_id'] ?? null, 0 );
		$g->storyId             = self::intOr( $row['story_id'] ?? null, 0 );
		$g->jobId               = self::intOr( $row['job_id'] ?? null, 0 );
		$g->provider            = self::strOr( $row['provider'] ?? null );
		$g->providerJobRef      = self::strOr( $row['provider_job_ref'] ?? null );
		$g->prompt              = self::strOr( $row['prompt'] ?? null );
		$g->promptVersion       = self::strOr( $row['prompt_version'] ?? null );
		$g->attachmentId        = self::intOr( $row['attachment_id'] ?? null, 0 );
		$g->mediaUrl            = self::strOr( $row['media_url'] ?? null );
		$g->width               = self::intOr( $row['width'] ?? null, 0 );
		$g->height              = self::intOr( $row['height'] ?? null, 0 );
		$g->sizeBytes           = self::intOr( $row['size_bytes'] ?? null, 0 );
		$g->altText             = self::strOr( $row['alt_text'] ?? null );
		$g->hasBrandComposition = ! empty( $row['has_brand_composition'] );
		$g->status              = self::strOr( $row['status'] ?? null, self::STATUS_QUEUED );
		$g->errorCode           = self::strOr( $row['error_code'] ?? null );
		$g->createdAt           = Time::fromDb( $row['created_at'] ?? null );
		return $g;
	}

	public function toDbRow(): array {
		return array(
			'image_id'                => $this->imageId,
			'story_id'                => $this->storyId,
			'job_id'                  => $this->jobId,
			'provider'                => $this->provider,
			'provider_job_ref'        => $this->providerJobRef,
			'prompt'                  => $this->prompt,
			'prompt_version'          => $this->promptVersion,
			'attachment_id'           => $this->attachmentId,
			'media_url'               => $this->mediaUrl,
			'width'                   => $this->width,
			'height'                  => $this->height,
			'size_bytes'              => $this->sizeBytes,
			'alt_text'                => $this->altText,
			'has_brand_composition'   => $this->hasBrandComposition ? 1 : 0,
			'status'                  => $this->status,
			'error_code'              => $this->errorCode,
			'created_at'              => $this->createdAt ? $this->createdAt->format( 'Y-m-d H:i:s' ) : null,
		);
	}
}
