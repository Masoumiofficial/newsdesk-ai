<?php
/**
 * Deterministic grounding helpers (§16–17, §19): every number, date, quote or
 * stat in generated text must be traceable to an allowed claim's snippet — the
 * anti-fabrication core of the content phase.
 *
 * @package NewsDesk\AI\Application\Content
 */

namespace NewsDesk\AI\Application\Content;

defined( 'ABSPATH' ) || exit;

final class Grounding {

	/**
	 * Normalize whitespace for comparisons (also Persian/Arabic digit folding).
	 */
	public static function normalize( string $text ): string {
		$text = preg_replace( '/\s+/u', ' ', $text );
		return trim( (string) $text );
	}

	/**
	 * @return string[] e.g. ["23%", "1.5 مليون", "۱۲٪"]
	 */
	public static function extractStats( string $text ): array {
		$out = array();
		if ( preg_match_all( '/\d+(?:[.,]\d+)?\s*(?:%|٪|percent|درصد)/iu', $text, $m ) ) {
			foreach ( $m[0] as $s ) {
				$out[] = self::foldDigits( self::normalize( $s ) );
			}
		}
		if ( preg_match_all( '/\d+(?:[.,]\d+)?\s*(?:million|billion|thousand|میلیون|میلیارد|هزار)/iu', $text, $m ) ) {
			foreach ( $m[0] as $s ) {
				$out[] = self::foldDigits( self::normalize( $s ) );
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * @return string[]
	 */
	public static function extractDates( string $text ): array {
		$out = array();
		if ( preg_match_all( '~\b(?:19|20)\d{2}[-/.]\d{1,2}[-/.]\d{1,2}\b~u', $text, $m ) ) {
			foreach ( $m[0] as $d ) {
				$out[] = $d;
			}
		}
		if ( preg_match_all( '/\b(?:19|20)\d{2}\b/u', $text, $m ) ) {
			foreach ( $m[0] as $y ) {
				$out[] = $y;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * @return string[] quoted fragments, unquoted (strip «»", "", '')
	 */
	public static function extractQuotes( string $text ): array {
		$out = array();
		if ( preg_match_all( '/[«"“]([^»"”]{4,160})[»"”]/u', $text, $m ) ) {
			foreach ( $m[1] as $q ) {
				$out[] = self::normalize( $q );
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * True when $text (already whitespace-normalized) fully contains $snippet.
	 */
	/** Minimum consecutive words that count as verbatim reproduction (v1.3.3). */
	public const REPRODUCTION_RUN_WORDS = 9;

	/**
	 * v1.3.3: "reproduction" means a long verbatim run, not merely containing a
	 * short evidence span. A 12-character snippet such as a product name or a
	 * version number is legitimately repeated by any rewrite, so the old check
	 * rejected honest articles. We now require ≥ REPRODUCTION_RUN_WORDS shared
	 * consecutive words (and the snippet itself must be that long).
	 */
	public static function containsSnippet( string $text, string $snippet ): bool {
		$needle = self::normalize( $snippet );
		$t      = self::normalize( $text );
		if ( '' === $needle || '' === $t ) {
			return false;
		}
		$needleWords = preg_split( '/\s+/u', $needle );
		if ( ! $needleWords || count( $needleWords ) < self::REPRODUCTION_RUN_WORDS ) {
			return false; // too short to be meaningful reproduction
		}
		if ( false !== mb_strpos( $t, $needle ) ) {
			return true;
		}
		return self::longestSharedRun( $t, $needle ) >= self::REPRODUCTION_RUN_WORDS;
	}

	/**
	 * Largest verbatim run (words) shared between the paragraph and the corpus.
	 * Used by the originality scorer (§"no full-text reproduction").
	 */
	public static function longestSharedRun( string $a, string $b ): int {
		$wa = preg_split( '/\s+/u', self::normalize( $a ) );
		$wb = preg_split( '/\s+/u', self::normalize( $b ) );
		if ( ! $wa || ! $wb ) {
			return 0;
		}
		$longest = 0;
		$n       = count( $wa );
		for ( $i = 0; $i < $n; $i++ ) {
			for ( $j = 0; $j < count( $wb ); $j++ ) {
				$len = 0;
				while ( isset( $wa[ $i + $len ], $wb[ $j + $len ] ) && $wa[ $i + $len ] === $wb[ $j + $len ] ) {
					$len++;
				}
				if ( $len > $longest ) {
					$longest = $len;
				}
				if ( $longest >= 12 ) {
					return $longest;
				}
			}
		}
		return $longest;
	}

	/**
	 * Fold Persian/Arabic-Indic digits into Latin.
	 */
	public static function foldDigits( string $text ): string {
		$map = array(
			'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
			'۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
			'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
			'٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
		);
		return strtr( $text, $map );
	}
}
