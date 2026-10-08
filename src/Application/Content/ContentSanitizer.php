<?php
/**
 * ContentSanitizer (§19 last step before the renderer): plain text only,
 * normalized whitespace, bounded lengths, claim ids validated — the AI output
 * may never carry markup or oversized payloads into WordPress.
 *
 * @package NewsDesk\AI\Application\Content
 */

namespace NewsDesk\AI\Application\Content;

defined( 'ABSPATH' ) || exit;

final class ContentSanitizer {

	public const MAX_TITLE   = 110;
	public const MAX_META    = 170;
	public const MAX_LEAD    = 600;
	public const MAX_HEADING = 120;
	public const MAX_PARAS   = 6;
	public const MAX_FAQ     = 6;

	/**
	 * @param array $data AI output post-schema/business-rule
	 * @return array cleaned copy
	 */
	public function sanitize( array $data ): array {
		$out = array();
		foreach ( array( 'title', 'meta_description', 'lead' ) as $key ) {
			$out[ $key ] = mb_substr( $this->cleanText( (string) ( $data[ $key ] ?? '' ) ), 0, self::MAX_META );
		}
		$out['title'] = mb_substr( $out['title'], 0, self::MAX_TITLE );

		$out['sections'] = array();
		foreach ( array_slice( (array) ( $data['sections'] ?? array() ), 0, 10 ) as $section ) {
			$paras = array();
			foreach ( array_slice( (array) ( $section['paragraphs'] ?? array() ), 0, self::MAX_PARAS ) as $p ) {
				$clean = $this->cleanText( (string) $p );
				if ( '' !== $clean ) {
					$paras[] = mb_substr( $clean, 0, 1200 );
				}
			}
			if ( ! $paras ) {
				continue;
			}
			$out['sections'][] = array(
				'heading'   => mb_substr( $this->cleanText( (string) ( $section['heading'] ?? '' ) ), 0, self::MAX_HEADING ),
				'type'      => in_array( $section['type'] ?? '', array( 'context', 'analysis', 'quote', 'summary' ), true ) ? $section['type'] : 'context',
				'paragraphs'=> array_values( array_unique( $paras ) ),
				'claim_ids' => $this->cleanIds( (array) ( $section['claim_ids'] ?? array() ) ),
			);
		}

		$out['faq'] = array();
		foreach ( array_slice( (array) ( $data['faq'] ?? array() ), 0, self::MAX_FAQ ) as $item ) {
			$q = $this->cleanText( (string) ( $item['question'] ?? '' ) );
			$a = $this->cleanText( (string) ( $item['answer'] ?? '' ) );
			if ( '' === $q || '' === $a ) {
				continue;
			}
			$out['faq'][] = array(
				'question'  => mb_substr( $q, 0, 160 ),
				'answer'    => mb_substr( $a, 0, 500 ),
				'claim_ids' => $this->cleanIds( (array) ( $item['claim_ids'] ?? array() ) ),
			);
		}

		$out['citation_notes'] = array();
		foreach ( array_slice( (array) ( $data['citation_notes'] ?? array() ), 0, 40 ) as $note ) {
			$note = $this->cleanText( (string) $note );
			if ( '' !== $note ) {
				$out['citation_notes'][] = mb_substr( $note, 0, 300 );
			}
		}
		// v1.6 editorial/SEO fields.
		$nt = (string) ( $data['news_type'] ?? '' );
		$out['news_type'] = in_array( $nt, \NewsDesk\AI\Application\Ai\Prompts::NEWS_TYPES, true ) ? $nt : '';
		$out['focus_keyword'] = mb_substr( $this->cleanText( (string) ( $data['focus_keyword'] ?? '' ) ), 0, 80 );
		$out['secondary_keywords'] = array();
		foreach ( array_slice( (array) ( $data['secondary_keywords'] ?? array() ), 0, 8 ) as $kw ) {
			$kw = $this->cleanText( (string) $kw );
			if ( '' !== $kw ) {
				$out['secondary_keywords'][] = mb_substr( $kw, 0, 80 );
			}
		}
		$si = (string) ( $data['search_intent'] ?? '' );
		$out['search_intent'] = in_array( $si, array( 'informational', 'navigational', 'commercial', 'transactional' ), true ) ? $si : '';
		return $out;
	}

	/**
	 * Content fingerprint for provenance/audit (§31).
	 */
	public function hash( array $data ): string {
		$parts = array( $data['title'] ?? '', $data['lead'] ?? '' );
		foreach ( (array) ( $data['sections'] ?? array() ) as $s ) {
			$parts[] = $s['heading'] ?? '';
			foreach ( (array) ( $s['paragraphs'] ?? array() ) as $p ) {
				$parts[] = $p;
			}
		}
		return sha1( Grounding::normalize( implode( ' ', $parts ) ) );
	}

	/** Plain text only: strip any markup, normalize whitespace. */
	private function cleanText( string $text ): string {
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/[ \t]+/u', ' ', $text );
		$text = preg_replace( '/\s*\n+\s*/u', "\n", $text );
		return trim( (string) $text );
	}

	/** @return string[] claim ids matching the uuid-ish shape the research phase stores. */
	private function cleanIds( array $ids ): array {
		$out = array();
		foreach ( $ids as $id ) {
			$id = preg_replace( '/[^A-Za-z0-9\-]/', '', (string) $id );
			if ( '' !== $id && mb_strlen( $id ) <= 36 ) {
				$out[] = $id;
			}
		}
		return array_values( array_unique( $out ) );
	}
}
