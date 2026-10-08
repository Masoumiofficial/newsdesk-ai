<?php
/**
 * Extracts article metadata + body from an HTML page.
 *
 * Priority ladder (auto mode):
 *   1. JSON-LD  (NewsArticle / Article / BlogPosting)
 *   2. OpenGraph / article:* / standard <meta>
 *   3. Heuristics (<article>, <main>, <h1>, <time datetime>)
 * Any per-source CSS override ("article_*" settings) wins over all of the above.
 *
 * @package NewsDesk\AI\News\Adapters\Html
 */

namespace NewsDesk\AI\News\Adapters\Html;

defined( 'ABSPATH' ) || exit;

final class ArticleExtractor {

	private const ARTICLE_TYPES = array( 'newsarticle', 'article', 'blogposting', 'reportagenewsarticle', 'analysisnewsarticle' );

	/**
	 * @param array<string,string> $overrides keys: article_title, article_content, article_date, article_author
	 * @return array{title:string,content:string,description:string,author:string,published_at:string,image:string,canonical:string}
	 */
	public static function extract( HtmlDocument $doc, array $overrides = array() ): array {
		$r = array(
			'title'        => '',
			'content'      => '',
			'description'  => '',
			'author'       => '',
			'published_at' => '',
			'image'        => '',
			'canonical'    => '',
		);

		/* 1. JSON-LD */
		foreach ( $doc->jsonLd() as $b ) {
			$type = $b['@type'] ?? '';
			$type = is_array( $type ) ? implode( ',', $type ) : (string) $type;
			if ( ! self::matchesArticleType( $type ) ) {
				continue;
			}
			$r['title']        = self::str( $b['headline'] ?? ( $b['name'] ?? '' ) );
			$r['description']  = self::str( $b['description'] ?? '' );
			$r['content']      = self::str( $b['articleBody'] ?? '' );
			$r['published_at'] = self::str( $b['datePublished'] ?? '' );
			$r['image']        = self::firstUrl( $b['image'] ?? '' );
			$r['canonical']    = self::str( is_array( $b['mainEntityOfPage'] ?? null ) ? ( $b['mainEntityOfPage']['@id'] ?? '' ) : ( $b['mainEntityOfPage'] ?? ( $b['url'] ?? '' ) ) );
			$a                 = $b['author'] ?? '';
			if ( is_array( $a ) ) {
				$a = isset( $a[0] ) ? $a[0] : $a;
				$a = is_array( $a ) ? ( $a['name'] ?? '' ) : $a;
			}
			$r['author'] = self::str( $a );
			break;
		}

		/* 2. OpenGraph / meta */
		$r['title']        = $r['title'] ?: $doc->meta( 'og:title' ) ?: $doc->meta( 'twitter:title' );
		$r['description']  = $r['description'] ?: $doc->meta( 'og:description' ) ?: $doc->meta( 'description' );
		$r['published_at'] = $r['published_at'] ?: $doc->meta( 'article:published_time' ) ?: $doc->meta( 'datePublished' ) ?: $doc->meta( 'pubdate' ) ?: $doc->meta( 'date' );
		$r['author']       = $r['author'] ?: $doc->meta( 'article:author' ) ?: $doc->meta( 'author' );
		$r['image']        = $r['image'] ?: $doc->meta( 'og:image' ) ?: $doc->meta( 'twitter:image' );
		$r['canonical']    = $r['canonical'] ?: $doc->firstAttr( 'link[rel=canonical]', 'href' ) ?: $doc->meta( 'og:url' );

		/* 3. Heuristics */
		if ( '' === $r['title'] ) {
			$r['title'] = $doc->firstText( 'h1' ) ?: $doc->firstText( 'title' );
		}
		if ( '' === $r['published_at'] ) {
			$r['published_at'] = $doc->firstAttr( 'time[datetime]', 'datetime' ) ?: $doc->firstText( 'time' );
		}
		if ( '' === $r['content'] ) {
			$r['content'] = self::heuristicBody( $doc );
		}

		/* Overrides always win */
		if ( ! empty( $overrides['article_title'] ) ) {
			$t = $doc->firstText( $overrides['article_title'] );
			if ( '' !== $t ) {
				$r['title'] = $t;
			}
		}
		if ( ! empty( $overrides['article_content'] ) ) {
			$nodes = $doc->css( $overrides['article_content'] );
			if ( $nodes ) {
				$r['content'] = $doc->innerHtml( $nodes[0] );
			}
		}
		if ( ! empty( $overrides['article_date'] ) ) {
			$d = $doc->firstAttr( $overrides['article_date'], 'datetime' ) ?: $doc->firstText( $overrides['article_date'] );
			if ( '' !== $d ) {
				$r['published_at'] = $d;
			}
		}
		if ( ! empty( $overrides['article_author'] ) ) {
			$a = $doc->firstText( $overrides['article_author'] );
			if ( '' !== $a ) {
				$r['author'] = $a;
			}
		}

		$r['title']     = html_entity_decode( $r['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$r['image']     = $r['image'] ? $doc->resolve( $r['image'] ) : '';
		$r['canonical'] = $r['canonical'] ? $doc->resolve( $r['canonical'] ) : '';
		return $r;
	}

	/**
	 * Pick the densest text container. Candidates: <article>, [itemprop=articleBody],
	 * common body classes, <main>; fall back to the <div> with the most <p> text.
	 */
	private static function heuristicBody( HtmlDocument $doc ): string {
		$candidates = array(
			'[itemprop=articleBody]',
			'.entry-content',
			'.post-content',
			'.article-body',
			'.article-content',
			'.story-body',
			'.news-body',
			'.content-body',
			'.item-text',
			'.body',
			'article',
			'main',
		);
		$best     = null;
		$bestLen  = 0;
		foreach ( $candidates as $sel ) {
			foreach ( $doc->css( $sel ) as $n ) {
				$len = self::paragraphLength( $doc, $n );
				if ( $len > $bestLen ) {
					$best    = $n;
					$bestLen = $len;
				}
			}
			if ( $best && $bestLen > 400 ) {
				break; // good enough, respect priority order.
			}
		}
		if ( ! $best || $bestLen < 200 ) {
			// Generic: parent of the <p> cluster with most text.
			$scores = array();
			foreach ( $doc->xpathAll( '//p' ) as $p ) {
				$parent = $p->parentNode;
				if ( ! $parent instanceof \DOMElement ) {
					continue;
				}
				$id = spl_object_hash( $parent );
				if ( ! isset( $scores[ $id ] ) ) {
					$scores[ $id ] = array( 0, $parent );
				}
				$scores[ $id ][0] += mb_strlen( HtmlDocument::text( $p ) );
			}
			foreach ( $scores as $pair ) {
				if ( $pair[0] > $bestLen ) {
					$bestLen = $pair[0];
					$best    = $pair[1];
				}
			}
		}
		return $best ? $doc->innerHtml( $best ) : '';
	}

	private static function paragraphLength( HtmlDocument $doc, \DOMElement $n ): int {
		$len = 0;
		foreach ( $doc->xpathAll( './/p', $n ) as $p ) {
			$len += mb_strlen( HtmlDocument::text( $p ) );
		}
		return $len;
	}

	private static function matchesArticleType( string $type ): bool {
		foreach ( explode( ',', strtolower( $type ) ) as $t ) {
			if ( in_array( trim( $t ), self::ARTICLE_TYPES, true ) ) {
				return true;
			}
		}
		return false;
	}

	private static function str( $v ): string {
		if ( is_array( $v ) ) {
			$v = isset( $v['@value'] ) ? $v['@value'] : reset( $v );
		}
		return is_scalar( $v ) ? trim( (string) $v ) : '';
	}

	private static function firstUrl( $v ): string {
		if ( is_array( $v ) ) {
			if ( isset( $v['url'] ) ) {
				return (string) $v['url'];
			}
			$first = reset( $v );
			return is_array( $first ) ? (string) ( $first['url'] ?? '' ) : (string) $first;
		}
		return (string) $v;
	}
}
