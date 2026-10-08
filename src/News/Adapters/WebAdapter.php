<?php
/**
 * HTML scraping adapter (v1.1).
 *
 * Flow: fetch listing page (source.feed_url) → collect article links
 * (auto: JSON-LD ItemList, <article> links, heading links, same-host
 * heuristics — or the per-source `list_item` / `list_link` CSS overrides)
 * → fetch each article through the SSRF-safe client → ArticleExtractor.
 *
 * Every hop goes through SafeHttpClient (§52). Per-source settings keys are
 * whitelisted in SourceService::ALLOWED_SETTINGS_KEYS. Articles that fail
 * are skipped (never abort the whole source). Off-host links are ignored
 * unless `allow_offsite=1`.
 *
 * @package NewsDesk\AI\News\Adapters
 */

namespace NewsDesk\AI\News\Adapters;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Contracts\HttpClientInterface;
use NewsDesk\AI\Domain\Entity\Source;
use NewsDesk\AI\News\Adapters\Html\ArticleExtractor;
use NewsDesk\AI\News\Adapters\Html\HtmlDocument;
use NewsDesk\AI\News\Contracts\SourceAdapterInterface;
use NewsDesk\AI\News\Exception\SourceParseException;
use NewsDesk\AI\News\Value\RawNewsItem;
use NewsDesk\AI\Support\Http\HttpFetchException;
use NewsDesk\AI\Support\Time;

final class WebAdapter implements SourceAdapterInterface {

	public const DEFAULT_MAX_ARTICLES = 15;
	public const HARD_MAX_ARTICLES    = 40;

	/** Per-source settings understood by this adapter. */
	public const SETTINGS_KEYS = array(
		'list_item',       // CSS: container of one entry on the listing page
		'list_link',       // CSS (inside list_item, or global): <a> with the article URL
		'article_title',   // CSS on article page
		'article_content', // CSS on article page
		'article_date',    // CSS on article page (<time datetime> or text)
		'article_author',  // CSS on article page
		'url_pattern',     // regex (PCRE, no delimiters) an article URL must match
		'max_articles',    // int
		'allow_offsite',   // "1" to follow links to other hosts
	);

	/** @var HttpClientInterface */
	private $http;
	/** @var int */
	private $maxBytes;

	public function __construct( HttpClientInterface $http, int $maxBytes = 2097152 ) {
		$this->http     = $http;
		$this->maxBytes = $maxBytes;
	}

	public function type(): string {
		return 'web';
	}

	public function supports( Source $source ): bool {
		return 'web' === $source->type;
	}

	public function fetch( Source $source, array $opts = array() ): array {
		$settings = is_array( $source->settings ) ? $source->settings : array();
		$timeout  = isset( $opts['timeout'] ) ? (int) $opts['timeout'] : 20;
		$limit    = isset( $settings['max_articles'] ) ? (int) $settings['max_articles'] : self::DEFAULT_MAX_ARTICLES;
		if ( isset( $opts['max_items'] ) ) {
			$limit = min( $limit, (int) $opts['max_items'] );
		}
		$limit = max( 1, min( self::HARD_MAX_ARTICLES, $limit ) );

		$listing = $this->getHtml( $source->feedUrl, $timeout );
		$doc     = new HtmlDocument( $listing['body'], $listing['url'] );
		$links   = $this->collectLinks( $doc, $settings, $source->feedUrl );
		if ( ! $links ) {
			throw new SourceParseException( 'No article links found on listing page: ' . $source->feedUrl );
		}

		$items    = array();
		$failures = array();
		foreach ( array_slice( $links, 0, $limit ) as $link ) {
			try {
				$page = $this->getHtml( $link, $timeout );
				$art  = ArticleExtractor::extract( new HtmlDocument( $page['body'], $page['url'] ), $settings );
				if ( '' === $art['title'] ) {
					$failures[] = $link;
					continue;
				}
				$items[] = RawNewsItem::fromArray( array(
					'title'        => $art['title'],
					'link'         => $art['canonical'] ?: $page['url'],
					'guid'         => $art['canonical'] ?: $link,
					'description'  => $art['description'],
					'content'      => $art['content'],
					'author'       => $art['author'],
					'published_at' => Time::parseFeedDate( $art['published_at'] ),
					'extra'        => array(
						'image'       => $art['image'],
						'adapter'     => 'web',
						'fetched_url' => $link,
					),
				) );
			} catch ( \Throwable $e ) {
				$failures[] = $link;
			}
		}
		if ( ! $items && $failures ) {
			throw new SourceParseException( sprintf( 'All %d article fetches failed for %s', count( $failures ), $source->feedUrl ) );
		}
		return $items;
	}

	/* ------------------------------------------------------------------ */

	/**
	 * @return array{body:string,url:string}
	 */
	private function getHtml( string $url, int $timeout ): array {
		$response = $this->http->get( $url, array(
			'timeout'   => $timeout,
			'max_bytes' => $this->maxBytes,
			'headers'   => array( 'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.5' ),
		) );
		if ( ! $response->isOk() ) {
			throw new HttpFetchException( 'HTTP_' . $response->status, sprintf( 'Page responded with HTTP %d', $response->status ) );
		}
		$ct = strtolower( (string) $response->header( 'content-type' ) );
		if ( '' !== $ct && false === strpos( $ct, 'html' ) && false === strpos( $ct, 'xml' ) ) {
			throw new SourceParseException( 'Not an HTML document: ' . $ct );
		}
		return array( 'body' => $response->body, 'url' => $response->finalUrl ?: $url );
	}

	/**
	 * @param array<string,string> $settings
	 * @return string[] absolute, de-duplicated, same-host (unless allowed) URLs in page order.
	 */
	private function collectLinks( HtmlDocument $doc, array $settings, string $listingUrl ): array {
		$hrefs = array();

		if ( ! empty( $settings['list_item'] ) || ! empty( $settings['list_link'] ) ) {
			// Manual mode.
			if ( ! empty( $settings['list_item'] ) ) {
				foreach ( $doc->css( $settings['list_item'] ) as $item ) {
					$a = ! empty( $settings['list_link'] ) ? $doc->css( $settings['list_link'], $item ) : $doc->css( 'a[href]', $item );
					if ( $a ) {
						$hrefs[] = $a[0]->getAttribute( 'href' );
					}
				}
			} else {
				foreach ( $doc->css( $settings['list_link'] ) as $a ) {
					$hrefs[] = $a->getAttribute( 'href' );
				}
			}
		} else {
			// Auto mode, in decreasing confidence.
			foreach ( $doc->jsonLd() as $b ) {
				if ( isset( $b['itemListElement'] ) && is_array( $b['itemListElement'] ) ) {
					foreach ( $b['itemListElement'] as $el ) {
						$u = is_array( $el ) ? ( $el['url'] ?? ( $el['item']['url'] ?? ( $el['item']['@id'] ?? ( $el['item'] ?? '' ) ) ) ) : $el;
						if ( is_string( $u ) ) {
							$hrefs[] = $u;
						}
					}
				}
			}
			foreach ( $doc->xpathAll( '//article//a[@href] | //*[self::h1 or self::h2 or self::h3]//a[@href] | //*[self::h1 or self::h2 or self::h3]/parent::a[@href]' ) as $a ) {
				$hrefs[] = $a->getAttribute( 'href' );
			}
			if ( count( $hrefs ) < 3 ) {
				foreach ( $doc->xpathAll( '//a[@href]' ) as $a ) {
					$hrefs[] = $a->getAttribute( 'href' );
				}
			}
		}

		$host     = strtolower( (string) parse_url( $listingUrl, PHP_URL_HOST ) );
		$offsite  = ! empty( $settings['allow_offsite'] ) && '0' !== (string) $settings['allow_offsite'];
		$pattern  = ! empty( $settings['url_pattern'] ) ? '#' . str_replace( '#', '\#', $settings['url_pattern'] ) . '#u' : '';
		$seen     = array();
		$out      = array();
		$listNorm = rtrim( preg_replace( '/#.*$/', '', $listingUrl ), '/' );

		foreach ( $hrefs as $h ) {
			$abs = $doc->resolve( (string) $h );
			if ( '' === $abs ) {
				continue;
			}
			$abs = preg_replace( '/#.*$/', '', $abs );
			if ( rtrim( $abs, '/' ) === $listNorm ) {
				continue;
			}
			$h2 = strtolower( (string) parse_url( $abs, PHP_URL_HOST ) );
			if ( ! $offsite && $h2 !== $host && 'www.' . $h2 !== $host && $h2 !== 'www.' . $host ) {
				continue;
			}
			if ( '' !== $pattern && ! @preg_match( $pattern, $abs ) ) { // phpcs:ignore
				continue;
			}
			if ( empty( $settings['list_item'] ) && empty( $settings['list_link'] ) && '' === $pattern && $this->looksLikeNav( $abs ) ) {
				continue;
			}
			if ( isset( $seen[ $abs ] ) ) {
				continue;
			}
			$seen[ $abs ] = true;
			$out[]        = $abs;
		}
		return $out;
	}

	/**
	 * Auto-mode noise filter: category/tag/author/pagination/asset URLs.
	 */
	private function looksLikeNav( string $url ): bool {
		$path = strtolower( (string) parse_url( $url, PHP_URL_PATH ) );
		if ( '' === $path || '/' === $path ) {
			return true;
		}
		if ( preg_match( '#\.(jpe?g|png|gif|webp|svg|pdf|mp4|mp3|zip|css|js|xml|rss)$#', $path ) ) {
			return true;
		}
		if ( preg_match( '#/(tag|tags|category|categories|author|page|feed|wp-content|wp-json|search|login|register|cart|account)(/|$)#', $path ) ) {
			return true;
		}
		// Article URLs usually have a hyphenated slug or a numeric id; a single
		// bare word ("/reviews", "/science") is almost always a section page.
		$segs = array_values( array_filter( explode( '/', $path ) ) );
		$last = $segs ? end( $segs ) : '';
		if ( preg_match( '/\d/', $path ) || false !== strpos( $last, '-' ) ) {
			return false;
		}
		return count( $segs ) < 2 || strlen( $last ) < 6;
	}
}
