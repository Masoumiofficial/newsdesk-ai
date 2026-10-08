<?php
/**
 * SeoProcessor (layer 14) — turns the article into the SEO/AEO/GEO package.
 *
 * The standing requirement ("best result for SEO, AEO and GEO — the best
 * choice for everyone") is embodied as a deterministic candidate race: several
 * title/meta variants are generated and scored on all three axes; the argmax
 * wins. No single axis can dominate: weights 50/30/20.
 *
 * @package NewsDesk\AI\Application\Seo
 */

namespace NewsDesk\AI\Application\Seo;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Domain\Entity\Story;
use NewsDesk\AI\Support\Slug;

final class SeoProcessor {

	/** Candidate weights: quality for everyone, not one engine. */
	public const WEIGHTS = array( 'seo' => 0.5, 'aeo' => 0.3, 'geo' => 0.2 );

	/**
	 * @param array $article sanitized article
	 * @param array $plan    ContentStrategy::plan()
	 * @param array $memory  ContentMemory::build()
	 * @param array $sources map source_id => Source
	 * @param array $site    {site_name, home_url, author_name}
	 * @return array package for the adapters + draft metadata
	 */
	public function process( Story $story, array $article, array $plan, array $memory, array $sources, array $site = array() ): array {
		$primary  = (string) ( $plan['primary_entity'] ?? '' );
		$question = (string) ( $plan['question'] ?? '' );
		$title    = $this->pickTitle( (string) ( $article['title'] ?? '' ), $primary, $question, (string) ( $plan['angle'] ?? '' ) );
		$meta     = $this->pickMeta( (string) ( $article['meta_description'] ?? '' ), $primary, $title );

		$slug    = Slug::fromTitle( $title );
		$homeUrl = rtrim( (string) ( $site['home_url'] ?? '' ), '/' );

		$entities = array( $primary );
		foreach ( (array) ( $story->topics ?? array() ) as $topic ) {
			if ( is_string( $topic ) && '' !== trim( $topic ) ) {
				$entities[] = trim( $topic );
			}
		}

		$headings = array();
		foreach ( (array) ( $article['sections'] ?? array() ) as $section ) {
			$headings[] = (string) ( $section['heading'] ?? '' );
		}

		$cited = array();
		foreach ( $memory['allowed'] as $claim ) {
			if ( ! empty( $claim['source_url'] ) && ! in_array( $claim['source_url'], $cited, true ) ) {
				$cited[] = (string) $claim['source_url'];
			}
		}

		$schema = new SchemaGenerator();
		$jsonld = $schema->generate( $story, $title, (string) ( $article['meta_description'] ?? '' ), $cited, $site, $slug, (string) ( $article['lang'] ?? '' ) );

		return array(
			'title'            => $title,
			'meta_description' => $meta,
			'slug'             => $slug,
			'canonical'        => '' !== $homeUrl ? $homeUrl . '/' . rawurlencode( $slug ) . '/' : '',
			'focus_entities'   => array_values( array_unique( array_filter( $entities ) ) ),
			'headings'         => $headings,
			'jsonld'           => $jsonld,
			'citation_urls'    => $cited,
			'scores'           => $this->axisScores( $title, $meta, $primary, $question, $jsonld, $cited, $story ),
		);
	}

	/** @return string */
	private function pickTitle( string $original, string $primary, string $question, string $angle ): string {
		$candidates = array(
			$original,
			$question,
			$angle,
			'' !== $primary ? $primary . ': ' . $original : $original,
		);
		$best = $original;
		$bestScore = -1.0;
		foreach ( $candidates as $cand ) {
			$cand = trim( $cand );
			if ( '' === $cand || mb_strlen( $cand ) > 80 ) {
				continue;
			}
			$score = $this->titleScore( $cand, $primary, $question );
			if ( $score > $bestScore ) {
				$bestScore = $score;
				$best      = $cand;
			}
		}
		return $best;
	}

	/** @return string */
	private function pickMeta( string $original, string $primary, string $title ): string {
		$candidates = array( $original, $title . ' — ' . ( '' !== $primary ? $primary : 'WordPress' ) );
		$best = $original;
		$bestScore = -1.0;
		foreach ( $candidates as $cand ) {
			$cand = trim( $cand );
			if ( '' === $cand || mb_strlen( $cand ) < 40 ) {
				continue;
			}
			$len  = mb_strlen( $cand );
			$score  = ( $len >= 120 && $len <= 165 ) ? 30 : ( $len >= 80 ? 15 : 5 );
			$score += false !== mb_stripos( $cand, $primary ) ? 10 : 0;
			if ( $score > $bestScore ) {
				$bestScore = $score;
				$best      = $cand;
			}
		}
		return mb_substr( $best, 0, 170 );
	}

	/** Candidate title score: SEO + AEO + GEO (weights applied). */
	private function titleScore( string $title, string $primary, string $question ): float {
		$len   = mb_strlen( $title );
		$seo   = 0.0;
		$aeo   = 0.0;
		$geo   = 0.0;

		if ( '' !== $primary && false !== mb_stripos( $title, $primary ) ) { $seo += 40; $geo += 30; }
		if ( $len >= 30 && $len <= 70 ) { $seo += 35; } elseif ( $len >= 20 ) { $seo += 18; }
		// Question-form titles only earn AEO points when long enough to also
		// satisfy SEO/GEO — one engine must not win at the cost of the others.
		if ( $len >= 30 && '' !== $question && false !== mb_stripos( $title, rtrim( $question, '?' . '؟' ) ) ) { $aeo += 45; }
		if ( preg_match( '/[؟?]$/u', $title ) ) { $aeo += 20; }
		$aeo += ( $len >= 35 && $len <= 65 ) ? 20 : 8;
		$geo += mb_strlen( $title ) >= 30 ? 25 : 10;
		return self::WEIGHTS['seo'] * $seo + self::WEIGHTS['aeo'] * $aeo + self::WEIGHTS['geo'] * $geo;
	}

	/** Axis scores for the audit metadata. */
	private function axisScores( string $title, string $meta, string $primary, string $question, array $jsonld, array $cited, Story $story ): array {
		$seo = 0.0; $aeo = 0.0; $geo = 0.0;
		$tLen = mb_strlen( $title ); $mLen = mb_strlen( $meta );
		if ( '' !== $primary && false !== mb_stripos( $title, $primary ) ) { $seo += 30; }
		if ( $tLen >= 30 && $tLen <= 70 ) { $seo += 20; }
		if ( $mLen >= 120 && $mLen <= 165 ) { $seo += 25; }
		$aeo = ( '' !== $question && false !== mb_stripos( $title, rtrim( $question, '?' . '؟' ) ) ) ? 60 : 30;
		$aeo += ( $tLen >= 35 && $tLen <= 65 ) ? 20 : 8;
		if ( ! empty( $jsonld['@type'] ) ) { $aeo += 20; }
		$geo  = min( 40.0, count( $cited ) * 10.0 );
		$geo += ( '' !== $primary && false !== mb_stripos( $title, $primary ) ) ? 25 : 5;
		if ( count( array_unique( array_map( 'intval', $story->allSourceIds() ?: array( 0 ) ) ) ) >= 2 ) { $geo += 35; }
		return array(
			'seo' => min( 100.0, $seo ),
			'aeo' => min( 100.0, $aeo ),
			'geo' => min( 100.0, $geo ),
		);
	}
}
