<?php
/**
 * A-6 — the pre-publication audit.
 *
 * The quality gate scores how good a draft is. The audit asks a different
 * question: is there anything here that must never reach a reader? They are
 * separate on purpose — a piece can score 85 and still carry an unattributed
 * quote or a fabricated claim ID.
 *
 * Every axis is deterministic and explains itself. Axes marked critical block
 * draft creation outright, regardless of the total score, because "mostly not
 * defamatory" is not a passing grade.
 *
 * @package NewsDesk\AI\Application\Audit
 */

namespace NewsDesk\AI\Application\Audit;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Content\FillerPhraseDetector;
use NewsDesk\AI\Domain\Entity\Story;

final class ArticleAuditor {

	/** Axis => [weight, is_critical]. Weights sum to 100. */
	public const AXES = array(
		'has_title'          => array( 5, true ),
		'title_length'       => array( 5, false ),
		'has_body'           => array( 10, true ),
		'body_length'        => array( 8, false ),
		'claims_grounded'    => array( 12, true ),
		'no_fabricated_refs' => array( 10, true ),
		'sources_present'    => array( 8, true ),
		'quotes_attributed'  => array( 7, false ),
		'no_ai_filler'       => array( 7, false ),
		'structure_sane'     => array( 6, false ),
		'seo_fields'         => array( 5, false ),
		'faq_present'        => array( 4, false ),
		'language_consistent'=> array( 5, false ),
		'no_placeholders'    => array( 5, true ),
		'security_complete'  => array( 3, false ),
	);

	/** Total below which the draft fails even with no critical hit. */
	public const PASS_MARK = 70.0;

	/** @var FillerPhraseDetector */
	private $filler;

	public function __construct( ?FillerPhraseDetector $filler = null ) {
		$this->filler = $filler ?: new FillerPhraseDetector();
	}

	/**
	 * @param array<string,mixed> $article
	 * @param array<string,mixed> $context allowed_claim_ids, sources, security, language
	 * @return array{
	 *   passed:bool, score:float, critical:string[],
	 *   axes:array<string,array{score:float,weight:int,critical:bool,note:string}>
	 * }
	 */
	public function audit( array $article, Story $story, array $context = array() ): array {
		$body     = $this->body( $article );
		$results  = array();

		$results['has_title']    = $this->boolAxis( 'has_title', '' !== trim( (string) ( $article['title'] ?? '' ) ), 'title present', 'NO TITLE' );
		$titleLen                = mb_strlen( trim( (string) ( $article['title'] ?? '' ) ), 'UTF-8' );
		$results['title_length'] = $this->boolAxis( 'title_length', $titleLen >= 20 && $titleLen <= 120, "title length {$titleLen}", "title length {$titleLen} outside 20-120" );

		$bodyLen                = mb_strlen( $body, 'UTF-8' );
		$results['has_body']    = $this->boolAxis( 'has_body', $bodyLen > 0, 'body present', 'EMPTY BODY' );
		$results['body_length'] = $this->boolAxis( 'body_length', $bodyLen >= 600, "body {$bodyLen} chars", "body only {$bodyLen} chars" );

		// Claim IDs used in the text must exist in the evidence set.
		$allowed  = array_map( 'strval', (array) ( $context['allowed_claim_ids'] ?? array() ) );
		$used     = $this->claimIds( $article );
		$unknown  = $allowed ? array_values( array_diff( $used, $allowed ) ) : array();
		$results['claims_grounded']    = $this->boolAxis( 'claims_grounded', ! $unknown, 'all claim refs known', 'unknown claim refs: ' . implode( ',', array_slice( $unknown, 0, 5 ) ) );
		$results['no_fabricated_refs'] = $this->boolAxis( 'no_fabricated_refs', ! $this->hasFakeUrls( $body ), 'no placeholder URLs', 'placeholder/example URLs present' );

		$sources = (array) ( $context['sources'] ?? array() );
		$results['sources_present'] = $this->boolAxis( 'sources_present', count( $sources ) > 0, count( $sources ) . ' source(s)', 'NO SOURCES' );

		$results['quotes_attributed'] = $this->boolAxis( 'quotes_attributed', $this->quotesAttributed( $body ), 'quotes attributed', 'a quotation has no attribution' );

		$fill                      = $this->filler->scanArticle( $article );
		$results['no_ai_filler']   = $this->boolAxis( 'no_ai_filler', ! $fill['critical'], $fill['total'] > 0 ? "filler={$fill['total']}" : 'clean', "AI filler x{$fill['total']}" );

		$sections                  = (array) ( $article['sections'] ?? array() );
		$results['structure_sane'] = $this->boolAxis( 'structure_sane', count( $sections ) >= 2, count( $sections ) . ' sections', 'fewer than 2 sections' );

		$seoOk                   = '' !== trim( (string) ( $article['focus_keyword'] ?? '' ) )
			|| '' !== trim( (string) ( $context['focus_keyword'] ?? '' ) );
		$results['seo_fields']   = $this->boolAxis( 'seo_fields', $seoOk, 'focus keyword set', 'no focus keyword' );
		$results['faq_present']  = $this->boolAxis( 'faq_present', count( (array) ( $article['faq'] ?? array() ) ) >= 3, 'FAQ present', 'fewer than 3 FAQ entries' );

		$results['language_consistent'] = $this->boolAxis( 'language_consistent', $this->languageOk( $body, (string) ( $context['language'] ?? $story->language ) ), 'language consistent', 'output script does not match target language' );

		$results['no_placeholders'] = $this->boolAxis( 'no_placeholders', ! $this->hasPlaceholders( $body . ' ' . (string) ( $article['title'] ?? '' ) ), 'no placeholders', 'TODO/lorem/XXX left in the text' );

		// Only meaningful for security stories; otherwise it passes.
		$sec                         = (array) ( $context['security'] ?? array() );
		$secOk                       = empty( $sec['is_security'] ) || ! empty( $sec['cve_ids'] ) || ! empty( $sec['fixed_versions'] );
		$results['security_complete'] = $this->boolAxis( 'security_complete', $secOk, 'security fields ok', 'security story without CVE or fixed version' );

		$score    = 0.0;
		$critical = array();
		foreach ( $results as $key => $axis ) {
			$score += $axis['score'];
			if ( $axis['critical'] && $axis['score'] <= 0.0 ) {
				$critical[] = $key;
			}
		}

		return array(
			'passed'   => ! $critical && $score >= self::PASS_MARK,
			'score'    => round( $score, 2 ),
			'critical' => $critical,
			'axes'     => $results,
		);
	}

	/** @return array{score:float,weight:int,critical:bool,note:string} */
	private function boolAxis( string $key, bool $ok, string $okNote, string $failNote ): array {
		list( $weight, $isCritical ) = self::AXES[ $key ];
		return array(
			'score'    => $ok ? (float) $weight : 0.0,
			'weight'   => $weight,
			'critical' => $isCritical,
			'note'     => $ok ? $okNote : $failNote,
		);
	}

	/** @param array<string,mixed> $article */
	private function body( array $article ): string {
		$parts = array();
		foreach ( (array) ( $article['sections'] ?? array() ) as $s ) {
			if ( is_string( $s ) ) {
				$parts[] = $s;
				continue;
			}
			foreach ( array( 'heading', 'body', 'content', 'text' ) as $k ) {
				if ( ! empty( $s[ $k ] ) && is_scalar( $s[ $k ] ) ) {
					$parts[] = (string) $s[ $k ];
				}
			}
			foreach ( (array) ( $s['paragraphs'] ?? array() ) as $p ) {
				if ( is_scalar( $p ) ) {
					$parts[] = (string) $p;
				}
			}
		}
		return trim( implode( "\n", $parts ) );
	}

	/** @return string[] */
	private function claimIds( array $article ): array {
		$ids = array();
		foreach ( (array) ( $article['sections'] ?? array() ) as $s ) {
			foreach ( (array) ( $s['claim_ids'] ?? array() ) as $id ) {
				$ids[] = (string) $id;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	private function hasFakeUrls( string $text ): bool {
		return (bool) preg_match( '#https?://(?:example\.(?:com|org|net)|test\.test|localhost|your-?site)#i', $text );
	}

	private function hasPlaceholders( string $text ): bool {
		return (bool) preg_match( '/\b(TODO|FIXME|lorem ipsum|XXX+|\[insert|placeholder)\b/i', $text );
	}

	/** A quotation mark pair with no attribution verb anywhere near it. */
	private function quotesAttributed( string $text ): bool {
		if ( ! preg_match_all( '/[«"]([^»"]{25,})[»"]/u', $text, $m, PREG_OFFSET_CAPTURE ) ) {
			return true; // no long quotes at all
		}
		foreach ( $m[0] as $hit ) {
			// PREG_OFFSET_CAPTURE returns BYTE offsets; mb_substr counts
			// CHARACTERS. On Persian text (2 bytes/char) mixing them puts the
			// window in the wrong place and the attribution is missed.
			$charOffset = mb_strlen( substr( $text, 0, (int) $hit[1] ), 'UTF-8' );
			$around     = mb_substr( $text, max( 0, $charOffset - 120 ), 320, 'UTF-8' );
			if ( ! preg_match( '/(گفت|اعلام|نوشت|افزود|به گفته|طبق|said|wrote|announced|according to|told)/iu', $around ) ) {
				return false;
			}
		}
		return true;
	}

	/** Persian output must actually be in Persian script. */
	private function languageOk( string $text, string $language ): bool {
		if ( '' === trim( $text ) ) {
			return true;
		}
		$isFa = ( 0 === strpos( (string) $language, 'fa' ) );
		if ( ! $isFa ) {
			return true;
		}
		$persian = preg_match_all( '/[\x{0600}-\x{06FF}]/u', $text );
		$latin   = preg_match_all( '/[A-Za-z]/u', $text );
		// Brand names in Latin script are fine; a wholly Latin body is not.
		return $persian > 0 && $persian >= $latin * 0.5;
	}
}
