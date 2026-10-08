<?php
/**
 * Suggested internal link between existing site posts (ARCHITECTURE.md, layer 15).
 * Suggestions are editorial inputs — never auto-inserted (§73: human control).
 *
 * @package NewsDesk\AI\Domain\Entity
 */

namespace NewsDesk\AI\Domain\Entity;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Support\Time;

final class InternalLinkSuggestion extends AbstractEntity {

	public const STATUS_SUGGESTED = 'suggested';
	public const STATUS_ACCEPTED  = 'accepted';
	public const STATUS_IGNORED   = 'ignored';

	/** @var int */
	public $id = 0;
	/** @var int */
	public $sourcePostId = 0;
	/** @var int */
	public $targetPostId = 0;
	/** @var string */
	public $anchor = '';
	/** @var string */
	public $placement = 'content';
	/** @var float */
	public $confidence = 0.0;
	/** @var string */
	public $reason = '';
	/** @var string */
	public $status = self::STATUS_SUGGESTED;
	/** @var \DateTimeImmutable|null */
	public $createdAt;

	public static function fromDbRow( array $row ): self {
		$l               = new self();
		$l->id           = self::intOr( $row['id'] ?? null, 0 );
		$l->sourcePostId = self::intOr( $row['source_post_id'] ?? null, 0 );
		$l->targetPostId = self::intOr( $row['target_post_id'] ?? null, 0 );
		$l->anchor       = self::strOr( $row['anchor'] ?? null );
		$l->placement    = self::strOr( $row['placement'] ?? null, 'content' );
		$l->confidence   = self::floatOr( $row['confidence'] ?? null, 0.0 );
		$l->reason       = self::strOr( $row['reason'] ?? null );
		$l->status       = self::strOr( $row['status'] ?? null, self::STATUS_SUGGESTED );
		$l->createdAt    = Time::fromDb( $row['created_at'] ?? null );
		return $l;
	}

	public function toDbRow(): array {
		return array(
			'id'            => $this->id,
			'source_post_id'=> $this->sourcePostId,
			'target_post_id'=> $this->targetPostId,
			'anchor'        => $this->anchor,
			'placement'     => $this->placement,
			'confidence'    => $this->confidence,
			'reason'        => $this->reason,
			'status'        => $this->status,
			'created_at'    => $this->createdAt ? $this->createdAt->format( 'Y-m-d H:i:s' ) : null,
		);
	}
}
