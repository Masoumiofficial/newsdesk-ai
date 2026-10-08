<?php
/**
 * A-7 — deterministic anti-AI filler detection.
 *
 * v1.6.0 listed four banned phrases inside the prompt text and hoped the model
 * would obey. Nothing verified the output, so a model that ignored the
 * instruction shipped straight into a draft. Asking an AI to police AI writing
 * is also circular: the same model that produced the cliché will happily rate
 * it as fine.
 *
 * This detector is a plain, auditable string matcher. It knows nothing about
 * the model, costs nothing to run, and gives the same verdict every time.
 *
 * @package NewsDesk\AI\Application\Content
 */

namespace NewsDesk\AI\Application\Content;

defined( 'ABSPATH' ) || exit;

final class FillerPhraseDetector {

	/**
	 * Persian AI-filler phrases, grouped by why they are a problem.
	 *
	 * Filterable so a site can add house-style bans without editing the plugin.
	 */
	public const PHRASES = array(
		// Empty openers that say nothing.
		'در دنیای امروز',
		'در دنیای امروزی',
		'در عصر حاضر',
		'در جهان امروز',
		'امروزه بیش از هر زمان دیگری',
		'در سال‌های اخیر شاهد',
		// Hype with no measurable content.
		'گامی مهم در مسیر',
		'گامی بزرگ در جهت',
		'تجربه‌ای بی‌نظیر',
		'تجربه‌ای منحصر به فرد',
		'انقلابی در',
		'انقلابی بزرگ',
		'نقطه عطفی در',
		'به طور چشمگیری',
		'به شکل چشمگیری',
		'فراتر از انتظار',
		// Filler connectives that pad length.
		'شایان ذکر است',
		'لازم به ذکر است',
		'قابل ذکر است',
		'بدون شک',
		'بی‌شک',
		'همان‌طور که می‌دانید',
		'همانطور که می‌دانید',
		'در نهایت می‌توان گفت',
		'در پایان می‌توان نتیجه گرفت',
		'به طور کلی می‌توان گفت',
		// Vague authority with no source.
		'کارشناسان معتقدند',
		'بسیاری بر این باورند',
		'گفته می‌شود که',
		'به نظر می‌رسد که احتمالاً',
	);

	/** A draft with this many filler hits is blocked outright. */
	public const CRITICAL_HITS = 5;
	/** Penalty applied per hit, in quality-score points. */
	public const PENALTY_PER_HIT = 3.0;
	/** Maximum penalty, so one bad paragraph cannot zero an otherwise good piece. */
	public const MAX_PENALTY = 20.0;

	/**
	 * Scan article text for filler.
	 *
	 * @param string $text plain text or HTML
	 * @return array{
	 *   hits:array<int,array{phrase:string,count:int}>, total:int,
	 *   penalty:float, critical:bool, phrases:string[]
	 * }
	 */
	public function scan( string $text ): array {
		$haystack = $this->normalize( $text );
		if ( '' === $haystack ) {
			return $this->empty();
		}

		$hits  = array();
		$total = 0;
		foreach ( $this->phrases() as $phrase ) {
			$needle = $this->normalize( $phrase );
			if ( '' === $needle ) {
				continue;
			}
			$count = substr_count( $haystack, $needle );
			if ( $count > 0 ) {
				$hits[] = array(
					'phrase' => $phrase,
					'count'  => $count,
				);
				$total += $count;
			}
		}

		$penalty = min( self::MAX_PENALTY, $total * self::PENALTY_PER_HIT );

		return array(
			'hits'     => $hits,
			'total'    => $total,
			'penalty'  => (float) $penalty,
			'critical' => $total >= self::CRITICAL_HITS,
			'phrases'  => array_column( $hits, 'phrase' ),
		);
	}

	/**
	 * Scan a structured article (title + sections + FAQ) in one pass.
	 *
	 * @param array<string, mixed> $article
	 */
	public function scanArticle( array $article ): array {
		return $this->scan( $this->flatten( $article ) );
	}

	/**
	 * The active phrase list.
	 *
	 * @return string[]
	 */
	public function phrases(): array {
		$list = self::PHRASES;
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'newsdesk_filler_phrases', $list );
			if ( is_array( $filtered ) && $filtered ) {
				$list = array_values( array_filter( array_map( 'strval', $filtered ) ) );
			}
		}
		return $list;
	}

	/** @param array<string, mixed> $article */
	private function flatten( array $article ): string {
		$parts = array();
		foreach ( array( 'title', 'lead', 'summary', 'excerpt' ) as $k ) {
			if ( ! empty( $article[ $k ] ) && is_scalar( $article[ $k ] ) ) {
				$parts[] = (string) $article[ $k ];
			}
		}
		foreach ( (array) ( $article['sections'] ?? array() ) as $section ) {
			if ( is_string( $section ) ) {
				$parts[] = $section;
				continue;
			}
			if ( is_array( $section ) ) {
				foreach ( array( 'heading', 'title', 'body', 'content', 'text' ) as $k ) {
					if ( ! empty( $section[ $k ] ) && is_scalar( $section[ $k ] ) ) {
						$parts[] = (string) $section[ $k ];
					}
				}
			}
		}
		foreach ( (array) ( $article['faq'] ?? array() ) as $qa ) {
			if ( is_array( $qa ) ) {
				foreach ( array( 'q', 'a', 'question', 'answer' ) as $k ) {
					if ( ! empty( $qa[ $k ] ) && is_scalar( $qa[ $k ] ) ) {
						$parts[] = (string) $qa[ $k ];
					}
				}
			}
		}
		return implode( "\n", $parts );
	}

	/**
	 * Fold the text so cosmetic differences cannot hide a match: HTML, the
	 * Arabic vs Persian forms of ی/ک, ZWNJ, and runs of whitespace.
	 */
	private function normalize( string $text ): string {
		$text = strip_tags( $text );
		$text = str_replace(
			array( "\u{064A}", "\u{0643}", "\u{200C}", "\u{200F}", "\u{200E}" ),
			array( 'ی', 'ک', ' ', '', '' ),
			$text
		);
		$text = preg_replace( '/\s+/u', ' ', $text ) ?? '';
		return trim( $text );
	}

	/** @return array{hits:array,total:int,penalty:float,critical:bool,phrases:string[]} */
	private function empty(): array {
		return array(
			'hits'     => array(),
			'total'    => 0,
			'penalty'  => 0.0,
			'critical' => false,
			'phrases'  => array(),
		);
	}
}
