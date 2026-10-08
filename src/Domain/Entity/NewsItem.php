<?php
/**
 * One raw item from one source — NOT an editorial unit (see ARCHITECTURE.md).
 *
 * @package NewsDesk\AI\Domain\Entity
 */

namespace NewsDesk\AI\Domain\Entity;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Support\Time;

final class NewsItem extends AbstractEntity {

	public const STATUS_NEW        = 'new';
	public const STATUS_NORMALIZED = 'normalized';
	public const STATUS_DUPLICATE  = 'duplicate';
	public const STATUS_IGNORED    = 'ignored';

	/** @var int */
	public $id = 0;
	/** @var int */
	public $sourceId = 0;
	/** @var string */
	public $guid = '';
	/** @var string */
	public $canonicalUrl = '';
	/** @var string */
	public $contentHash = '';
	/** @var string */
	public $title = '';
	/** @var string */
	public $normalizedTitle = '';
	/** @var string */
	public $excerpt = '';
	/** @var string */
	public $contentText = '';
	/** @var string */
	public $author = '';
	/** @var array */
	public $categories = array();
	/** @var string */
	public $language = '';
	/** @var \DateTimeImmutable|null */
	public $publishedAt;
	/** @var \DateTimeImmutable|null */
	public $fetchedAt;
	/** @var \DateTimeImmutable|null */
	public $createdAt;
	/** @var \DateTimeImmutable|null */
	public $lastSeenAt;
	/** @var string */
	public $status = self::STATUS_NEW;
	/** @var \DateTimeImmutable|null */
	public $dedupConfirmedAt;
	/** @var int Winner item this row duplicates (0 = not a duplicate). */
	public $duplicateOfId = 0;
	/** @var string Which ladder rung matched: guid|url|hash|title|'' */
	public $duplicateLevel = '';

	public static function fromDbRow( array $row ): self {
		$n                    = new self();
		$n->id                = self::intOr( $row['id'] ?? null, 0 );
		$n->sourceId          = self::intOr( $row['source_id'] ?? null, 0 );
		$n->guid              = self::strOr( $row['guid'] ?? null );
		$n->canonicalUrl      = self::strOr( $row['canonical_url'] ?? null );
		$n->contentHash       = self::strOr( $row['content_hash'] ?? null );
		$n->title             = self::strOr( $row['title'] ?? null );
		$n->normalizedTitle   = self::strOr( $row['normalized_title'] ?? null );
		$n->excerpt           = self::strOr( $row['excerpt'] ?? null );
		$n->contentText       = self::strOr( $row['content_text'] ?? null );
		$n->author            = self::strOr( $row['author'] ?? null );
		$n->categories        = self::jsonArray( $row['categories'] ?? null );
		$n->language          = self::strOr( $row['language'] ?? null );
		$n->publishedAt       = Time::fromDb( $row['published_at'] ?? null );
		$n->fetchedAt         = Time::fromDb( $row['fetched_at'] ?? null );
		$n->createdAt         = Time::fromDb( $row['created_at'] ?? null );
		$n->lastSeenAt        = Time::fromDb( $row['last_seen_at'] ?? null );
		$n->status            = self::strOr( $row['status'] ?? null, self::STATUS_NEW );
		$n->dedupConfirmedAt  = Time::fromDb( $row['dedup_confirmed_at'] ?? null );
		$n->duplicateOfId     = self::intOr( $row['duplicate_of_id'] ?? null, 0 );
		$n->duplicateLevel    = self::strOr( $row['duplicate_level'] ?? null );
		return $n;
	}

	/** Is this row a recorded duplicate of another item? */
	public function isDuplicate(): bool {
		return self::STATUS_DUPLICATE === $this->status;
	}

	public function toDbRow(): array {
		return array(
			'id'                 => $this->id,
			'source_id'          => $this->sourceId,
			'guid'               => $this->guid,
			'canonical_url'      => $this->canonicalUrl,
			'content_hash'       => $this->contentHash,
			'title'              => $this->title,
			'normalized_title'   => $this->normalizedTitle,
			'excerpt'            => $this->excerpt,
			'content_text'       => $this->contentText,
			'author'             => $this->author,
			'categories'         => self::jsonEncode( $this->categories ),
			'language'           => $this->language,
			'published_at'       => Time::toDb( $this->publishedAt ),
			'fetched_at'         => Time::toDb( $this->fetchedAt ),
			'created_at'         => Time::toDb( $this->createdAt ),
			'last_seen_at'       => Time::toDb( $this->lastSeenAt ),
			'status'             => $this->status,
			'dedup_confirmed_at' => Time::toDb( $this->dedupConfirmedAt ),
			'duplicate_of_id'    => $this->duplicateOfId ?: null,
			'duplicate_level'    => '' !== $this->duplicateLevel ? $this->duplicateLevel : null,
		);
	}
}
