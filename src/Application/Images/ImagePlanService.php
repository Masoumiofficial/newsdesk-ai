<?php
/**
 * A-5 — the image PLAN.
 *
 * The spec is explicit that the image phase is plan-only: describe what the
 * illustration should be, and let a human make or choose it. No generation
 * API, no automatic upload, no editing.
 *
 * v1.6.0 shipped the opposite — a full generation pipeline, enabled by
 * default. That capability is not deleted (it works, and removing it would
 * take a feature away), but it is now OFF by default and strictly opt-in,
 * while this service always produces the plan the spec asks for.
 *
 * Everything here is deterministic and free: no provider call, no network,
 * no cost. A plan is always produced, so the editor is never left with
 * nothing when generation is off or fails.
 *
 * @package NewsDesk\AI\Application\Images
 */

namespace NewsDesk\AI\Application\Images;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Domain\Entity\Story;

final class ImagePlanService {

	/** Suggested aspect ratio for a news featured image. */
	public const ASPECT = '16:9';
	/** Recommended minimum width in pixels. */
	public const MIN_WIDTH = 1200;
	/** How many alternative concepts to offer. */
	public const CONCEPTS = 3;
	/** Cap on any single text field we emit. */
	private const MAX_TEXT = 300;

	/**
	 * Build the image plan for a story.
	 *
	 * @param Story                $story
	 * @param array<string, mixed> $article optional generated article (title/angle/sections)
	 * @return array{
	 *   status:string, aspect:string, min_width:int, alt_text:string,
	 *   caption:string, concepts:array<int,array{idea:string,composition:string}>,
	 *   search_terms:string[], avoid:string[], licence_note:string
	 * }
	 */
	public function plan( Story $story, array $article = array() ): array {
		$title = $this->text( (string) ( $article['title'] ?? $story->canonicalTitle ) );
		$angle = $this->text( (string) ( $article['angle'] ?? '' ) );
		$topic = '' !== $title ? $title : __( 'News', 'newsdesk-ai' );

		return array(
			'status'       => 'planned',
			'aspect'       => self::ASPECT,
			'min_width'    => self::MIN_WIDTH,
			'alt_text'     => $this->altText( $topic ),
			'caption'      => $this->caption( $topic, $angle ),
			'concepts'     => $this->concepts( $topic, $angle ),
			'search_terms' => $this->searchTerms( $topic ),
			'avoid'        => $this->avoid(),
			'licence_note' => __( 'Use only clearly licensed images, and record the source and licence in the caption.', 'newsdesk-ai' ),
		);
	}

	/** Accessibility-first alt text: describe, do not keyword-stuff. */
	private function altText( string $topic ): string {
		return $this->text( sprintf( /* translators: %s: story topic */ __( 'Featured image for: %s', 'newsdesk-ai' ), $topic ) );
	}

	private function caption( string $topic, string $angle ): string {
		$base = '' !== $angle ? $topic . ' — ' . $angle : $topic;
		return $this->text( $base );
	}

	/**
	 * Three distinct directions so the editor has a real choice rather than
	 * one prescriptive instruction.
	 *
	 * @return array<int,array{idea:string,composition:string}>
	 */
	private function concepts( string $topic, string $angle ): array {
		$subject = '' !== $angle ? $angle : $topic;
		return array(
			array(
				'idea'        => sprintf( /* translators: %s: subject */ __( 'Conceptual view of %s', 'newsdesk-ai' ), $subject ),
				'composition' => __( 'Subject in the left third, clear space on the right for a headline.', 'newsdesk-ai' ),
			),
			array(
				'idea'        => __( 'A close-up of the relevant interface or product', 'newsdesk-ai' ),
				'composition' => __( 'Landscape frame, clean low-noise background, focus on detail.', 'newsdesk-ai' ),
			),
			array(
				'idea'        => __( 'A contextual shot of the real environment in use', 'newsdesk-ai' ),
				'composition' => __( 'Natural light, no identifiable faces, shallow depth of field.', 'newsdesk-ai' ),
			),
		);
	}

	/** @return string[] */
	private function searchTerms( string $topic ): array {
		$words = preg_split( '/\s+/u', $topic ) ?: array();
		$terms = array();
		foreach ( $words as $w ) {
			$w = trim( (string) $w );
			if ( mb_strlen( $w, 'UTF-8' ) >= 3 ) {
				$terms[] = $w;
			}
			if ( count( $terms ) >= 6 ) {
				break;
			}
		}
		return array_values( array_unique( $terms ) );
	}

	/**
	 * Constraints that keep an editor out of legal and ethical trouble.
	 *
	 * @return string[]
	 */
	private function avoid(): array {
		return array(
			__( 'Faces of real, identifiable people', 'newsdesk-ai' ),
			__( 'Logos and trademarks', 'newsdesk-ai' ),
			__( 'Text or numbers inside the image', 'newsdesk-ai' ),
			__( 'Images without a clear licence or source', 'newsdesk-ai' ),
			__( 'Staged scenes that misrepresent the story', 'newsdesk-ai' ),
		);
	}

	private function text( string $s ): string {
		$s = trim( preg_replace( '/\s+/u', ' ', $s ) ?? '' );
		if ( mb_strlen( $s, 'UTF-8' ) > self::MAX_TEXT ) {
			$s = mb_substr( $s, 0, self::MAX_TEXT, 'UTF-8' );
		}
		return $s;
	}
}
