<?php
/**
 * A publisher/canal the system reads from (§6).
 *
 * @package NewsDesk\AI\Domain\Entity
 */

namespace NewsDesk\AI\Domain\Entity;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Support\Time;

final class Source extends AbstractEntity {

	public const TYPES = array( 'rss', 'atom', 'rest', 'json', 'web', 'manual' );

	/** Types with a working adapter today (others are contract stubs; hidden from the UI). */
	public const SUPPORTED_TYPES = array( 'rss', 'atom', 'web' );

	/**
	 * A-12: what KIND of outlet this is, per the spec taxonomy. Distinct from
	 * `type`, which is the transport (rss/atom/web).
	 */
	public const SOURCE_PRIMARY   = 'PRIMARY';
	public const SOURCE_TECHNICAL = 'TECHNICAL';
	public const SOURCE_MEDIA     = 'MEDIA';
	public const SOURCE_COMMUNITY = 'COMMUNITY';
	public const SOURCE_SECURITY  = 'SECURITY';

	public const SOURCE_TYPES = array(
		self::SOURCE_PRIMARY,
		self::SOURCE_TECHNICAL,
		self::SOURCE_MEDIA,
		self::SOURCE_COMMUNITY,
		self::SOURCE_SECURITY,
	);

	/** A-12: 1 = official/authoritative … 4 = unverified. Lower is better. */
	public const TIERS = array( 1, 2, 3, 4 );

	/** Default trust ceiling per tier, used when no override is set. */
	public const TIER_TRUST = array( 1 => 95.0, 2 => 80.0, 3 => 60.0, 4 => 35.0 );

	public const STATUS_ACTIVE   = 'active';
	public const STATUS_PAUSED   = 'paused';
	public const STATUS_ERROR    = 'error';
	public const STATUS_DISABLED = 'disabled';

	/** @var int */
	public $id = 0;
	/** @var string */
	public $name = '';
	/** @var string */
	public $type = 'rss';
	/** @var string */
	public $url = '';
	/** @var string */
	public $feedUrl = '';
	/** @var string */
	public $language = 'en_US';
	/** @var string */
	public $category = 'general';
	/** @var int */
	public $priority = 50;
	/** @var float */
	public $baseTrustScore = 50.0;
	/** @var bool */
	public $trustOverride = false;
	/** @var float */
	public $trustScore = 50.0;
	/** @var bool */
	public $active = true;
	/** @var string */
	public $status = self::STATUS_ACTIVE;
	/** @var int */
	public $fetchIntervalMin = 240;
	/** @var \DateTimeImmutable|null */
	public $lastFetchAt;
	/** @var \DateTimeImmutable|null */
	public $lastSuccessAt;
	/** @var \DateTimeImmutable|null */
	public $lastErrorAt;
	/** @var string */
	public $lastErrorMessage = '';
	/** @var string A-12 — PRIMARY/TECHNICAL/MEDIA/COMMUNITY/SECURITY. */
	public $sourceType = self::SOURCE_MEDIA;
	/** @var int A-12 — 1..4, lower is more authoritative. */
	public $tier = 3;
	/** @var array */
	public $settings = array();
	/** @var \DateTimeImmutable|null */
	public $createdAt;
	/** @var \DateTimeImmutable|null */
	public $updatedAt;

	/**
	 * Map a DB row to entity.
	 */
	public static function fromDbRow( array $row ): self {
		$s                  = new self();
		$s->id              = self::intOr( $row['id'] ?? null, 0 );
		$s->name            = self::strOr( $row['name'] ?? null );
		$s->type            = self::strOr( $row['type'] ?? null, 'rss' );
		$s->url             = self::strOr( $row['url'] ?? null );
		$s->feedUrl         = self::strOr( $row['feed_url'] ?? null );
		$s->language        = self::strOr( $row['language'] ?? null, 'en_US' );
		$s->category        = self::strOr( $row['category'] ?? null, 'general' );
		$s->priority        = self::intOr( $row['priority'] ?? null, 50 );
		$s->baseTrustScore  = self::floatOr( $row['base_trust_score'] ?? null, 50.0 );
		$s->trustOverride   = (bool) ( $row['trust_override'] ?? false );
		$s->trustScore      = self::floatOr( $row['trust_score'] ?? null, $s->baseTrustScore );
		$s->active          = (bool) ( $row['active'] ?? true );
		$s->status          = self::strOr( $row['status'] ?? null, self::STATUS_ACTIVE );
		$s->fetchIntervalMin = self::intOr( $row['fetch_interval_min'] ?? null, 240 );
		$s->lastFetchAt     = Time::fromDb( $row['last_fetch_at'] ?? null );
		$s->lastSuccessAt   = Time::fromDb( $row['last_success_at'] ?? null );
		$s->lastErrorAt     = Time::fromDb( $row['last_error_at'] ?? null );
		$s->lastErrorMessage = self::strOr( $row['last_error_message'] ?? null );
		$s->settings        = self::jsonArray( $row['settings'] ?? null );
		$st                 = strtoupper( self::strOr( $row['source_type'] ?? null ) );
		$s->sourceType      = in_array( $st, self::SOURCE_TYPES, true ) ? $st : self::SOURCE_MEDIA;
		$tier               = self::intOr( $row['tier'] ?? null, 3 );
		$s->tier            = in_array( $tier, self::TIERS, true ) ? $tier : 3;
		$s->createdAt       = Time::fromDb( $row['created_at'] ?? null );
		$s->updatedAt       = Time::fromDb( $row['updated_at'] ?? null );
		return $s;
	}

	/**
	 * Map to DB row.
	 *
	 * @return array
	 */
	public function toDbRow(): array {
		return array(
			'id'                 => $this->id,
			'name'               => $this->name,
			'type'               => $this->type,
			'url'                => $this->url,
			'feed_url'           => $this->feedUrl,
			'language'           => $this->language,
			'category'           => $this->category,
			'priority'           => $this->priority,
			'base_trust_score'   => $this->baseTrustScore,
			'trust_override'     => $this->trustOverride ? 1 : 0,
			'trust_score'        => $this->trustOverride ? $this->baseTrustScore : $this->trustScore,
			'active'             => $this->active ? 1 : 0,
			'status'             => $this->status,
			'fetch_interval_min' => $this->fetchIntervalMin,
			'last_fetch_at'      => Time::toDb( $this->lastFetchAt ),
			'last_success_at'    => Time::toDb( $this->lastSuccessAt ),
			'last_error_at'      => Time::toDb( $this->lastErrorAt ),
			'last_error_message' => $this->lastErrorMessage,
			'settings'           => self::jsonEncode( $this->settings ),
			'source_type'        => $this->sourceType,
			'tier'               => $this->tier,
			'created_at'         => Time::toDb( $this->createdAt ),
			'updated_at'         => Time::toDb( $this->updatedAt ),
		);
	}

	/**
	 * Feed-based adapter types.
	 */
	public function isFeedType(): bool {
		return in_array( $this->type, array( 'rss', 'atom' ), true );
	}

	/**
	 * Is the source eligible for autonomous discovery?
	 */
	public function isFetchable(): bool {
		return $this->active && self::STATUS_ACTIVE === $this->status && $this->feedUrl !== '';
	}
}
