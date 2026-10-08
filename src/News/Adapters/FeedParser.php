<?php
/**
 * XML feed parser (RSS 2.0 / Atom) with XXE hardening (§8-C).
 *
 * @package NewsDesk\AI\News\Adapters
 */

namespace NewsDesk\AI\News\Adapters;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\News\Exception\SourceParseException;

final class FeedParser {

	/**
	 * Parse feed XML → raw item arrays.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function parse( string $xml, string $expectedType ): array {
		if ( '' === trim( $xml ) ) {
			throw new SourceParseException( 'EMPTY_FEED', 'Feed body is empty.' );
		}
		// XXE hard block before any parsing.
		if ( false !== stripos( $xml, '<!DOCTYPE' ) || false !== stripos( $xml, '<!ENTITY' ) ) {
			throw new SourceParseException( 'XXE_BLOCKED', 'Feed declares DOCTYPE/ENTITY — refused.' );
		}

		libxml_use_internal_errors( true );
		// PHP >= 8.0: entity loading is disabled by default (libxml 2.9+); the legacy
		// toggler is deprecated and a no-op there. Guard remains for PHP 7.4.
		$prev = null;
		if ( PHP_VERSION_ID < 80000 && function_exists( 'libxml_disable_entity_loader' ) ) {
			$prev = libxml_disable_entity_loader( true ); // phpcs:ignore
		}
		try {
			$xmlObj = simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT );
		} finally {
			if ( null !== $prev ) {
				libxml_disable_entity_loader( $prev ); // phpcs:ignore
			}
		}
		libxml_clear_errors();

		if ( false === $xmlObj ) {
			throw new SourceParseException( 'INVALID_XML', 'Feed is not valid XML.' );
		}

		$root = $xmlObj->getName();
		if ( 'rss' === $root ) {
			if ( 'atom' === $expectedType ) {
				throw new SourceParseException( 'TYPE_MISMATCH', 'Source declared atom but feed is RSS.' );
			}
			return self::parseRss( $xmlObj );
		}
		if ( 'feed' === $root ) {
			if ( 'rss' === $expectedType ) {
				throw new SourceParseException( 'TYPE_MISMATCH', 'Source declared rss but feed is Atom.' );
			}
			return self::parseAtom( $xmlObj );
		}
		throw new SourceParseException( 'UNKNOWN_FEED_TYPE', 'Unknown root element: ' . $root );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function parseRss( \SimpleXMLElement $root ): array {
		$items = array();
		if ( ! isset( $root->channel ) ) {
			throw new SourceParseException( 'MALFORMED_RSS', 'Missing <channel>.' );
		}
		foreach ( $root->channel->item as $item ) {
			$content = '';
			if ( isset( $item->children( 'http://purl.org/rss/1.0/modules/content/' )->encoded ) ) {
				$content = (string) $item->children( 'http://purl.org/rss/1.0/modules/content/' )->encoded;
			}
			if ( '' === $content && isset( $item->description ) ) {
				$content = (string) $item->description;
			}
			$categories = array();
			foreach ( $item->category as $cat ) {
				$v = trim( (string) $cat );
				if ( '' !== $v ) {
					$categories[] = $v;
				}
			}
			$guid = isset( $item->guid ) ? trim( (string) $item->guid ) : '';
			$items[] = array(
				'title'        => isset( $item->title ) ? trim( (string) $item->title ) : '',
				'link'         => isset( $item->link ) ? trim( (string) $item->link ) : '',
				'guid'         => $guid,
				'description'  => isset( $item->description ) ? trim( (string) $item->description ) : '',
				'content'      => trim( $content ),
				'author'       => isset( $item->author ) ? trim( (string) $item->author ) : '',
				'categories'   => $categories,
				'published_at' => isset( $item->pubDate ) ? trim( (string) $item->pubDate ) : '',
			);
		}
		return $items;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function parseAtom( \SimpleXMLElement $root ): array {
		$items = array();
		foreach ( $root->entry as $entry ) {
			$link = '';
			foreach ( $entry->link as $l ) {
				$rel = (string) $l['rel'];
				if ( '' === $rel || 'alternate' === $rel ) {
					$link = (string) $l['href'];
					break;
				}
			}
			$content = '';
			foreach ( array( 'content', 'summary' ) as $field ) {
				if ( isset( $entry->{$field} ) ) {
					$content = (string) $entry->{$field};
					break;
				}
			}
			$categories = array();
			foreach ( $entry->category as $cat ) {
				$v = trim( (string) $cat['term'] );
				if ( '' !== $v ) {
					$categories[] = $v;
				}
			}
			$items[] = array(
				'title'        => isset( $entry->title ) ? trim( (string) $entry->title ) : '',
				'link'         => $link,
				'guid'         => isset( $entry->id ) ? trim( (string) $entry->id ) : '',
				'description'  => '',
				'content'      => trim( $content ),
				'author'       => isset( $entry->author->name ) ? trim( (string) $entry->author->name ) : '',
				'categories'   => $categories,
				'published_at' => isset( $entry->updated ) ? trim( (string) $entry->updated ) : ( isset( $entry->published ) ? trim( (string) $entry->published ) : '' ),
			);
		}
		return $items;
	}
}
