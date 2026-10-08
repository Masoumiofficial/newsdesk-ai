<?php
/**
 * Source CRUD + validation + cascade delete (§6, §47).
 *
 * @package NewsDesk\AI\Application
 */

namespace NewsDesk\AI\Application;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Contracts\NewsItemRepositoryInterface;
use NewsDesk\AI\Application\Contracts\SourceRepositoryInterface;
use NewsDesk\AI\Application\Contracts\StoryRepositoryInterface;
use NewsDesk\AI\Application\Exception\InputValidationException;
use NewsDesk\AI\Domain\Entity\Source;
use NewsDesk\AI\Infrastructure\Http\UrlValidator;
use NewsDesk\AI\Logging\Redaction;
use NewsDesk\AI\Support\Time;

final class SourceService {

	public const CATEGORIES = array( 'core', 'plugins', 'themes', 'security', 'community', 'business', 'general' );

	/** Allowed source settings keys (keys that could carry secrets are rejected). */
	public const ALLOWED_SETTINGS_KEYS = \NewsDesk\AI\News\Adapters\WebAdapter::SETTINGS_KEYS;

	/** @var SourceRepositoryInterface */
	private $sources;
	/** @var NewsItemRepositoryInterface */
	private $items;
	/** @var StoryRepositoryInterface */
	private $stories;

	public function __construct( SourceRepositoryInterface $sources, NewsItemRepositoryInterface $items, ?StoryRepositoryInterface $stories = null ) {
		$this->sources = $sources;
		$this->items   = $items;
		$this->stories = $stories;
	}

	/**
	 * @throws InputValidationException
	 */
	public function create( array $input ): Source {
		$data = $this->validate( $input );
		$source = new Source();
		$now = Time::now();
		$source->createdAt = $now;
		$source->updatedAt = $now;
		$this->apply( $source, $data );
		$id = $this->sources->insert( $source );
		if ( $id <= 0 ) {
			throw new InputValidationException( array( 'name' => __( 'Saving the source failed (duplicate key?). Please try again.', 'newsdesk-ai' ) ) );
		}
		return $source;
	}

	/**
	 * @throws InputValidationException
	 */
	public function update( int $id, array $input ): Source {
		$source = $this->sources->find( $id );
		if ( null === $source ) {
			throw new InputValidationException( array( 'id' => __( 'Source not found.', 'newsdesk-ai' ) ) );
		}
		$data = $this->validate( $input, $source );
		$source->updatedAt = Time::now();
		$this->apply( $source, $data );
		if ( ! $this->sources->update( $source ) ) {
			throw new InputValidationException( array( 'name' => __( 'Updating the source failed.', 'newsdesk-ai' ) ) );
		}
		return $source;
	}

	/**
	 * Cascade delete: news items first, then the source row.
	 */
	public function delete( int $id ): bool {
		$source = $this->sources->find( $id );
		if ( null === $source ) {
			return false;
		}
		$this->items->deleteBySource( $id );
		if ( null !== $this->stories ) {
			// Stories whose only primary evidence was this source expire (soft).
			$this->stories->deleteBySource( $id );
		}
		return $this->sources->delete( $id );
	}

	public function setActive( int $id, bool $active ): bool {
		$source = $this->sources->find( $id );
		if ( null === $source ) {
			return false;
		}
		$source->active  = $active;
		$source->status  = $active ? Source::STATUS_ACTIVE : Source::STATUS_DISABLED;
		$source->updatedAt = Time::now();
		return $this->sources->update( $source );
	}

	/**
	 * @return array<string, mixed> normalized input (throws on errors).
	 */
	public function validate( array $input, ?Source $existing = null ): array {
		$errors = array();

		$name = trim( (string) ( $input['name'] ?? '' ) );
		if ( '' === $name ) {
			$errors['name'] = __( 'Source name is required.', 'newsdesk-ai' );
		} elseif ( mb_strlen( $name ) > 191 ) {
			$errors['name'] = __( 'Source name is limited to 191 characters.', 'newsdesk-ai' );
		}

		$type = sanitize_key( (string) ( $input['type'] ?? 'rss' ) );
		if ( ! in_array( $type, Source::TYPES, true ) ) {
			$type   = 'rss';
			$errors['type'] = __( 'Invalid source type.', 'newsdesk-ai' );
		} elseif ( ! in_array( $type, Source::SUPPORTED_TYPES, true ) ) {
			$errors['type'] = __( 'This source type is not supported yet (RSS, Atom and Web only).', 'newsdesk-ai' );
		}

		$feedUrl = trim( (string) ( $input['feed_url'] ?? '' ) );
		if ( 'manual' !== $type ) {
			if ( '' === $feedUrl ) {
				$errors['feed_url'] = __( 'A feed URL is required for this source type.', 'newsdesk-ai' );
			} else {
				try {
					UrlValidator::validate( $feedUrl );
				} catch ( \Exception $e ) {
					$errors['feed_url'] = __( 'The feed URL is invalid or not allowed (SSRF).', 'newsdesk-ai' );
				}
			}
		}
		$url = trim( (string) ( $input['url'] ?? '' ) );
		if ( '' !== $url ) {
			try {
				UrlValidator::validate( $url );
			} catch ( \Exception $e ) {
				$errors['url'] = __( 'The source website URL is invalid or not allowed.', 'newsdesk-ai' );
			}
		}

		$priority = isset( $input['priority'] ) ? max( 0, min( 100, absint( $input['priority'] ) ) ) : 50;

		// A-12: editorial taxonomy. The tier is what an editor actually reasons
		// about ("is this the vendor, or a forum post?"), so it drives the trust
		// score rather than sitting beside it as decoration.
		$sourceType = strtoupper( sanitize_text_field( (string) ( $input['source_type'] ?? '' ) ) );
		if ( ! in_array( $sourceType, Source::SOURCE_TYPES, true ) ) {
			$sourceType = Source::SOURCE_MEDIA;
		}
		$tier = isset( $input['tier'] ) ? absint( $input['tier'] ) : 3;
		if ( ! in_array( $tier, Source::TIERS, true ) ) {
			$tier = 3;
		}

		// An explicit score always wins; otherwise the tier supplies the default
		// ceiling. Before this, picking tier 1 and tier 4 produced the same 50.0.
		$trust = isset( $input['base_trust_score'] ) && '' !== $input['base_trust_score']
			? max( 0, min( 100, (float) $input['base_trust_score'] ) )
			: (float) ( Source::TIER_TRUST[ $tier ] ?? 50.0 );
		$interval = isset( $input['fetch_interval_min'] ) ? max( 15, min( 1440, absint( $input['fetch_interval_min'] ) ) ) : 240;

		$language = sanitize_text_field( (string) ( $input['language'] ?? 'en_US' ) );
		if ( '' === $language || mb_strlen( $language ) > 20 ) {
			$language = 'en_US';
		}
		$category = sanitize_key( (string) ( $input['category'] ?? 'general' ) );
		if ( ! in_array( $category, self::CATEGORIES, true ) ) {
			$category = 'general';
		}

		$settings = array();
		if ( ! empty( $input['settings'] ) && is_array( $input['settings'] ) ) {
			foreach ( $input['settings'] as $k => $v ) {
				$k = sanitize_key( (string) $k );
				if ( ! in_array( $k, self::ALLOWED_SETTINGS_KEYS, true ) ) {
					$errors['settings'] = __( 'Unsupported settings key.', 'newsdesk-ai' );
					continue;
				}
				if ( Redaction::isSensitiveKey( $k ) ) {
					$errors['settings'] = __( 'Secret keys are not allowed in source settings.', 'newsdesk-ai' );
					continue;
				}
				$v = trim( sanitize_text_field( (string) $v ) );
				if ( '' === $v ) {
					continue; // empty = not set
				}
				if ( in_array( $k, array( 'list_item', 'list_link', 'article_title', 'article_content', 'article_date', 'article_author' ), true ) ) {
					try {
						\NewsDesk\AI\News\Adapters\Html\CssToXPath::convert( $v );
					} catch ( \InvalidArgumentException $e ) {
						/* translators: %s: settings key */
						$errors['settings'] = sprintf( __( 'Invalid or unsupported CSS selector in “%s”.', 'newsdesk-ai' ), $k );
						continue;
					}
				} elseif ( 'url_pattern' === $k ) {
					if ( false === @preg_match( '#' . str_replace( '#', '\\#', $v ) . '#u', '' ) ) { // phpcs:ignore
						$errors['settings'] = __( 'The URL pattern (regex) is invalid.', 'newsdesk-ai' );
						continue;
					}
				} elseif ( 'max_articles' === $k ) {
					$v = (string) max( 1, min( \NewsDesk\AI\News\Adapters\WebAdapter::HARD_MAX_ARTICLES, (int) $v ) );
				} elseif ( 'allow_offsite' === $k ) {
					$v = '1';
				}
				$settings[ $k ] = $v;
			}
		}

		if ( $errors ) {
			throw new InputValidationException( $errors );
		}

		return array(
			'name'              => $name,
			'type'              => $type,
			'url'               => $url,
			'feed_url'          => $feedUrl,
			'language'          => $language,
			'category'          => $category,
			'priority'          => $priority,
			'source_type'       => $sourceType,
			'tier'              => $tier,
			'base_trust_score'  => $trust,
			'fetch_interval_min' => $interval,
			'settings'          => $settings,
		);
	}

	private function apply( Source $source, array $data ): void {
		$source->name             = $data['name'];
		$source->type             = $data['type'];
		$source->url              = $data['url'];
		$source->feedUrl          = $data['feed_url'];
		$source->language         = $data['language'];
		$source->category         = $data['category'];
		$source->priority         = $data['priority'];
		$source->sourceType       = $data['source_type'];
		$source->tier             = $data['tier'];
		$source->baseTrustScore   = $data['base_trust_score'];
		if ( $source->trustOverride ) {
			$source->trustScore = $data['base_trust_score'];
		}
		$source->fetchIntervalMin = $data['fetch_interval_min'];
		$source->settings         = $data['settings'];
	}
}
