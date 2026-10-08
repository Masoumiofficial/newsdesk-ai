<?php
/**
 * Thin DOMDocument wrapper: safe HTML loading (no external entities, no
 * network), CSS/XPath querying, URL resolution.
 *
 * @package NewsDesk\AI\News\Adapters\Html
 */

namespace NewsDesk\AI\News\Adapters\Html;

defined( 'ABSPATH' ) || exit;

final class HtmlDocument {

	/** @var \DOMDocument */
	private $dom;
	/** @var \DOMXPath */
	private $xpath;
	/** @var string */
	private $baseUrl;

	public function __construct( string $html, string $baseUrl ) {
		$this->dom = new \DOMDocument( '1.0', 'UTF-8' );
		$prev      = libxml_use_internal_errors( true );
		if ( PHP_VERSION_ID < 80000 && function_exists( 'libxml_disable_entity_loader' ) ) {
			libxml_disable_entity_loader( true ); // phpcs:ignore
		}
		// Force UTF-8 interpretation for pages without a meta charset.
		if ( false === stripos( $html, 'charset' ) ) {
			$html = '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $html;
		}
		$this->dom->loadHTML( $html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_COMPACT );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		$this->xpath   = new \DOMXPath( $this->dom );
		$this->baseUrl = $this->detectBase( $baseUrl );
	}

	public function baseUrl(): string {
		return $this->baseUrl;
	}

	/**
	 * @return \DOMElement[]
	 */
	public function css( string $selector, ?\DOMNode $ctx = null ): array {
		return $this->xpathAll( CssToXPath::convert( $selector ), $ctx );
	}

	/**
	 * @return \DOMElement[]
	 */
	public function xpathAll( string $expr, ?\DOMNode $ctx = null ): array {
		// Make selectors relative when a context node is given.
		if ( null !== $ctx && 0 === strpos( $expr, '//' ) ) {
			$expr = '.' . $expr;
		}
		$list = @$this->xpath->query( $expr, $ctx ); // phpcs:ignore
		$out  = array();
		if ( $list instanceof \DOMNodeList ) {
			foreach ( $list as $n ) {
				if ( $n instanceof \DOMElement ) {
					$out[] = $n;
				}
			}
		}
		return $out;
	}

	public function firstText( string $selector, ?\DOMNode $ctx = null ): string {
		$nodes = $this->css( $selector, $ctx );
		return $nodes ? self::text( $nodes[0] ) : '';
	}

	public function firstAttr( string $selector, string $attr, ?\DOMNode $ctx = null ): string {
		$nodes = $this->css( $selector, $ctx );
		return $nodes ? trim( $nodes[0]->getAttribute( $attr ) ) : '';
	}

	public function meta( string $nameOrProperty ): string {
		$q = sprintf(
			"//meta[translate(@property,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='%1\$s' or translate(@name,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='%1\$s']/@content",
			strtolower( $nameOrProperty )
		);
		$list = $this->xpath->query( $q );
		return $list && $list->length ? trim( (string) $list->item( 0 )->nodeValue ) : '';
	}

	/**
	 * @return array<int, array<string,mixed>> decoded JSON-LD blocks (flattened @graph).
	 */
	public function jsonLd(): array {
		$out  = array();
		$list = $this->xpath->query( "//script[translate(@type,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='application/ld+json']" );
		if ( ! $list ) {
			return $out;
		}
		foreach ( $list as $s ) {
			$data = json_decode( trim( (string) $s->textContent ), true );
			if ( ! is_array( $data ) ) {
				continue;
			}
			$blocks = isset( $data['@graph'] ) && is_array( $data['@graph'] ) ? $data['@graph'] : ( isset( $data[0] ) ? $data : array( $data ) );
			foreach ( $blocks as $b ) {
				if ( is_array( $b ) ) {
					$out[] = $b;
				}
			}
		}
		return $out;
	}

	public function resolve( string $href ): string {
		$href = trim( $href );
		if ( '' === $href || 0 === strpos( $href, '#' ) || 0 === stripos( $href, 'javascript:' ) || 0 === stripos( $href, 'mailto:' ) ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $href ) ) {
			return $href;
		}
		$b = parse_url( $this->baseUrl );
		if ( empty( $b['scheme'] ) || empty( $b['host'] ) ) {
			return '';
		}
		$origin = $b['scheme'] . '://' . $b['host'] . ( isset( $b['port'] ) ? ':' . $b['port'] : '' );
		if ( 0 === strpos( $href, '//' ) ) {
			return $b['scheme'] . ':' . $href;
		}
		if ( 0 === strpos( $href, '/' ) ) {
			return $origin . $href;
		}
		$path = isset( $b['path'] ) ? preg_replace( '#/[^/]*$#', '/', $b['path'] ) : '/';
		return self::normalizePath( $origin . $path . $href );
	}

	public static function text( \DOMNode $node ): string {
		$t = preg_replace( '/\s+/u', ' ', (string) $node->textContent );
		return trim( (string) $t );
	}

	/**
	 * innerHTML of a node with scripts/styles removed.
	 */
	public function innerHtml( \DOMElement $node ): string {
		foreach ( $this->xpathAll( './/script | .//style | .//noscript | .//iframe | .//form', $node ) as $junk ) {
			if ( $junk->parentNode ) {
				$junk->parentNode->removeChild( $junk );
			}
		}
		$html = '';
		foreach ( $node->childNodes as $c ) {
			$html .= $this->dom->saveHTML( $c );
		}
		return trim( $html );
	}

	private function detectBase( string $fallback ): string {
		$list = $this->xpath->query( '//base/@href' );
		if ( $list && $list->length ) {
			$h = trim( (string) $list->item( 0 )->nodeValue );
			if ( preg_match( '#^https?://#i', $h ) ) {
				return $h;
			}
		}
		return $fallback;
	}

	private static function normalizePath( string $url ): string {
		$p = parse_url( $url );
		if ( empty( $p['path'] ) ) {
			return $url;
		}
		$segs = array();
		foreach ( explode( '/', $p['path'] ) as $s ) {
			if ( '..' === $s ) {
				array_pop( $segs );
			} elseif ( '.' !== $s ) {
				$segs[] = $s;
			}
		}
		$path = implode( '/', $segs );
		return $p['scheme'] . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' ) . $path
			. ( isset( $p['query'] ) ? '?' . $p['query'] : '' );
	}
}
