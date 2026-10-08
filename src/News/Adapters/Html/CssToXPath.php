<?php
/**
 * Minimal CSS selector → XPath translator (no external deps).
 *
 * Supported: tag, .class, #id, [attr], [attr=val], [attr^=val], [attr$=val],
 * [attr*=val], descendant (space) and child (>) combinators, comma lists.
 * Anything else throws so misconfigured selectors fail loudly, never silently.
 *
 * @package NewsDesk\AI\News\Adapters\Html
 */

namespace NewsDesk\AI\News\Adapters\Html;

defined( 'ABSPATH' ) || exit;

final class CssToXPath {

	/**
	 * @throws \InvalidArgumentException on unsupported syntax.
	 */
	public static function convert( string $css ): string {
		$css = trim( $css );
		if ( '' === $css ) {
			throw new \InvalidArgumentException( 'Empty selector' );
		}
		$parts = array();
		foreach ( explode( ',', $css ) as $sel ) {
			$parts[] = self::convertOne( trim( $sel ) );
		}
		return implode( ' | ', $parts );
	}

	private static function convertOne( string $sel ): string {
		// Normalise combinators: "a > b" → tokens with '>' as its own token.
		$sel    = preg_replace( '/\s*>\s*/', ' > ', $sel );
		$tokens = preg_split( '/\s+/', trim( (string) $sel ) );
		$xpath  = '';
		$axis   = '//';
		foreach ( $tokens as $tok ) {
			if ( '>' === $tok ) {
				$axis = '/';
				continue;
			}
			$xpath .= $axis . self::compound( $tok );
			$axis   = '//';
		}
		return $xpath;
	}

	private static function compound( string $tok ): string {
		if ( ! preg_match( '/^([a-zA-Z][a-zA-Z0-9-]*|\*)?((?:[.#][a-zA-Z0-9_-]+|\[[^\]]+\])*)$/', $tok, $m ) ) {
			throw new \InvalidArgumentException( 'Unsupported selector token: ' . $tok );
		}
		$tag   = '' !== $m[1] ? strtolower( $m[1] ) : '*';
		$preds = array();
		if ( '' !== $m[2] ) {
			preg_match_all( '/[.#][a-zA-Z0-9_-]+|\[[^\]]+\]/', $m[2], $qs );
			foreach ( $qs[0] as $q ) {
				if ( '.' === $q[0] ) {
					$preds[] = "contains(concat(' ', normalize-space(@class), ' '), ' " . substr( $q, 1 ) . " ')";
				} elseif ( '#' === $q[0] ) {
					$preds[] = "@id='" . substr( $q, 1 ) . "'";
				} else {
					$inner = substr( $q, 1, -1 );
					if ( preg_match( '/^([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*([\^$*]?=)\s*["\']?([^"\']*)["\']?$/', $inner, $am ) ) {
						$attr = '@' . $am[1];
						$val  = self::literal( $am[3] );
						switch ( $am[2] ) {
							case '=':
								$preds[] = "$attr=$val";
								break;
							case '^=':
								$preds[] = "starts-with($attr, $val)";
								break;
							case '$=':
								$preds[] = "substring($attr, string-length($attr) - string-length($val) + 1) = $val";
								break;
							default:
								$preds[] = "contains($attr, $val)";
						}
					} elseif ( preg_match( '/^[a-zA-Z_:][-a-zA-Z0-9_:.]*$/', $inner ) ) {
						$preds[] = '@' . $inner;
					} else {
						throw new \InvalidArgumentException( 'Unsupported attribute selector: ' . $q );
					}
				}
			}
		}
		return $tag . ( $preds ? '[' . implode( ' and ', $preds ) . ']' : '' );
	}

	private static function literal( string $v ): string {
		if ( false === strpos( $v, "'" ) ) {
			return "'" . $v . "'";
		}
		if ( false === strpos( $v, '"' ) ) {
			return '"' . $v . '"';
		}
		return "concat('" . str_replace( "'", "',\"'\",'", $v ) . "')";
	}
}
