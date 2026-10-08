<?php
/**
 * External source attribution for a claim (ARCHITECTURE.md, layer 16) — the GEO/citation
 * trail. Links are built ONLY from verified claims' real source URLs (§52-safe).
 *
 * @package NewsDesk\AI\Domain\Entity
 */

namespace NewsDesk\AI\Domain\Entity;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Support\Time;

final class ExternalLink extends AbstractEntity {

	public const PRIORITY_PRIMARY   = 'primary';
	public const PRIORITY_SECONDARY = 'secondary';

	/** @var int */
	public $id = 0;
	/** @var string */
	public $claimId = '';
	/** @var int */
	public $sourceId = 0;
	/** @var string */
	public $url = '';
	/** @var string */
	public $anchor = '';
	/** @var string */
	public $reason = '';
	/** @var string */
	public $priority = self::PRIORITY_SECONDARY;
	/** @var \DateTimeImmutable|null */
	public $createdAt;

	public static function fromDbRow( array $row ): self {
		$l           = new self();
		$l->id       = self::intOr( $row['id'] ?? null, 0 );
		$l->claimId  = self::strOr( $row['claim_id'] ?? null );
		$l->sourceId = self::intOr( $row['source_id'] ?? null, 0 );
		$l->url      = self::strOr( $row['url'] ?? null );
		$l->anchor   = self::strOr( $row['anchor'] ?? null );
		$l->reason   = self::strOr( $row['reason'] ?? null );
		$l->priority = self::strOr( $row['priority'] ?? null, self::PRIORITY_SECONDARY );
		$l->createdAt = Time::fromDb( $row['created_at'] ?? null );
		return $l;
	}

	public function toDbRow(): array {
		return array(
			'id'         => $this->id,
			'claim_id'   => $this->claimId,
			'source_id'  => $this->sourceId,
			'url'        => $this->url,
			'anchor'     => $this->anchor,
			'reason'     => $this->reason,
			'priority'   => $this->priority,
			'created_at' => $this->createdAt ? $this->createdAt->format( 'Y-m-d H:i:s' ) : null,
		);
	}
}
