<?php
/**
 * Normalize RawNewsItem → NewsItem (§2, §9). Pure; no persistence here.
 *
 * @package NewsDesk\AI\News\Normalizer
 */

namespace NewsDesk\AI\News\Normalizer;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Domain\Entity\NewsItem;
use NewsDesk\AI\Domain\Entity\Source;
use NewsDesk\AI\Domain\Exception\DomainException;
use NewsDesk\AI\Domain\Value\ContentHash;
use NewsDesk\AI\News\Canonical\UrlCanonicalizer;
use NewsDesk\AI\News\Value\RawNewsItem;
use NewsDesk\AI\Support\Str;
use NewsDesk\AI\Support\Time;

final class NewsItemNormalizer {

	/** @var int */
	private $maxContentChars;
	/** @var int */
	private $maxExcerptChars;

	public function __construct( int $maxContentChars = 10000, int $maxExcerptChars = 300 ) {
		$this->maxContentChars = $maxContentChars;
		$this->maxExcerptChars = $maxExcerptChars;
	}

	/**
	 * @throws DomainException when the item has neither title nor link (unusable).
	 */
	public function normalize( RawNewsItem $raw, Source $source ): NewsItem {
		$item = new NewsItem();

		$title = Str::limit( Str::plainText( $raw->title ), 500 );
		$link  = trim( $raw->link );

		if ( '' === $title && '' === $link ) {
			throw new DomainException( 'Feed item has neither title nor link' );
		}

		$canonical = '';
		if ( '' !== $link ) {
			try {
				$canonical = (string) UrlCanonicalizer::canonicalize( $link );
			} catch ( DomainException $e ) {
				$canonical = '';
			}
		}

		$contentText = Str::plainText( '' !== $raw->content ? $raw->content : $raw->description );
		$contentText = function_exists( 'mb_substr' )
			? mb_substr( $contentText, 0, $this->maxContentChars, 'UTF-8' )
			: substr( $contentText, 0, $this->maxContentChars );

		$normalizedTitle = self::normalizeTitle( $title );

		$item->sourceId        = $source->id;
		$item->guid            = '' !== $raw->guid ? trim( $raw->guid ) : $canonical;
		$item->canonicalUrl    = '' !== $canonical ? $canonical : $link;
		$item->title           = '' !== $title ? $title : $link;
		$item->normalizedTitle = '' !== $normalizedTitle ? $normalizedTitle : Str::lower( $item->title );
		$item->excerpt         = Str::limit( $contentText, $this->maxExcerptChars );
		$item->contentText     = $contentText;
		$item->author          = Str::limit( Str::plainText( $raw->author ), 191 );
		$item->categories      = array_slice( array_values( array_filter( array_map( 'trim', $raw->categories ) ) ), 0, 10 );
		$item->language        = $source->language;
		$item->publishedAt     = $raw->publishedAt;
		$item->fetchedAt       = Time::now();
		$item->createdAt       = Time::now();
		$item->lastSeenAt      = Time::now();
		$item->status          = NewsItem::STATUS_NEW;
		$item->contentHash     = ContentHash::fromParts( $item->canonicalUrl, $item->normalizedTitle, $contentText );
		return $item;
	}

	/**
	 * Title key used for cheaper dedup signals (§9 step 4).
	 */
	public static function normalizeTitle( string $title ): string {
		$title = Str::plainText( $title );
		// Lowercase, latinize-dash, drop punctuation (keep letters/digits/space).
		$title = Str::lower( $title );
		$title = (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $title );
		return Str::collapseWhitespace( $title );
	}
}
