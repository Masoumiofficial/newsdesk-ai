<?php
/**
 * DraftService (layer 19) — assembles the final WP draft from the approved
 * version: semantic HTML render (plain-text only, escaped), citation list from
 * verified claims, internal-link anchors, JSON-LD, and the FULL metadata set
 * (§31–32: job_id, story_id, sources, provider/model, scores, fact-check
 * status, prompt version, content hash). post_status stays 'draft' (§73) and
 * the writer enforces it again (defense in depth).
 *
 * @package NewsDesk\AI\Application\Content
 */

namespace NewsDesk\AI\Application\Content;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Contracts\LinkRepositoryInterface;
use NewsDesk\AI\Application\Contracts\WpPostIndexInterface;
use NewsDesk\AI\Application\Contracts\WpPostWriterInterface;
use NewsDesk\AI\Application\NewsroomSettings;
use NewsDesk\AI\Application\Scoring\CannibalizationEngine;
use NewsDesk\AI\Application\Seo\SeoAdapterResolver;
use NewsDesk\AI\Domain\Entity\Story;
use NewsDesk\AI\Logging\Contracts\LoggerInterface;

final class DraftService {

	/** @var WpPostWriterInterface */
	private $writer;
	/** @var WpPostIndexInterface */
	private $index;
	/** @var LinkRepositoryInterface */
	private $links;
	/** @var NewsroomSettings */
	private $settings;
	/** @var LoggerInterface */
	private $logger;
	/** @var SeoAdapterResolver|null Optional so existing callers keep working. */
	private $seoAdapters;

	public function __construct( WpPostWriterInterface $writer, WpPostIndexInterface $index, LinkRepositoryInterface $links, NewsroomSettings $settings, LoggerInterface $logger, ?SeoAdapterResolver $seoAdapters = null ) {
		$this->writer      = $writer;
		$this->index       = $index;
		$this->links       = $links;
		$this->settings    = $settings;
		$this->logger      = $logger;
		$this->seoAdapters = $seoAdapters;
	}

	/**
	 * @param array $article sanitized article
	 * @param array $seo     SeoProcessor package
	 * @param array $meta    engine metadata (quality breakdown, sources, …)
	 * @param array $links   external link rows (ExternalLink[])
	 * @param array $suggestions Internal link suggestions (InternalLinkSuggestion[])
	 * @param array $image      Phase 5 image result: {status, attachment_id?, error_code?, provider?, model?, prompt_version?}
	 * @return array{post_id: int, content: string, meta: array<string, string>}
	 */
	public function create( Story $story, array $article, array $seo, array $meta, array $links, array $suggestions, int $jobId, array $image = array() ): array {
		$html   = $this->render( $article, $seo, $links, $suggestions, $story );
		$wpMeta = $this->metadata( $story, $article, $seo, $meta, $links, $suggestions, $jobId, $image );

		$post = array(
			'post_title'   => (string) ( $seo['title'] ?? $article['title'] ?? '' ),
			'post_name'    => (string) ( $seo['slug'] ?? '' ),
			'post_excerpt' => (string) ( $seo['meta_description'] ?? '' ),
			'post_content' => $html,
			'post_type'    => 'post',
		);
		if ( $this->settings->defaultAuthorId() > 0 ) {
			$post['post_author'] = $this->settings->defaultAuthorId();
		}
		if ( $this->settings->defaultCategoryId() > 0 ) {
			$post['post_category'] = array( $this->settings->defaultCategoryId() );
		}

		$lang = (string) ( $article['lang'] ?? $this->settings->contentLanguage() );
		if ( 'source' === $lang ) {
			$lang = $story->langCode();
		}
		$wpMeta['nd_language'] = $lang;

		// v2.0 (A-8): act on the U-1 editorial decision. UPDATE/REWRITE/MERGE/
		// CORRECT/REPLACE all target an article that already exists, so they
		// are staged as a revision draft pointing at it. Still drafts-only:
		// nothing live is rewritten without an editor approving the swap.
		$decision = strtoupper( (string) $story->editorialDecision );
		$targetId = (int) $story->existingArticleId;
		$revises  = ( '' !== $decision
			&& CannibalizationEngine::requiresExistingArticle( $decision )
			&& $targetId > 0 );

		if ( $revises ) {
			// createRevisionDraft() writes the canonical protected keys
			// (_newsdesk_revises_post / _newsdesk_revision_kind). Setting an
			// unprefixed second copy here stored the same fact twice and put it
			// in the editor's Custom Fields box.
			$postId = $this->writer->createRevisionDraft( $targetId, $decision, $post, $wpMeta );
		} else {
			$postId = $this->writer->createDraft( $post, $wpMeta );
		}

		if ( $postId > 0 ) {
			$this->assignLanguage( $postId, $lang );
			$this->applySeoAdapters( $postId, $seo, $article );
			$this->logger->info(
				$revises ? 'Revision draft created' : 'Draft created',
				array(
					'story_id' => $story->storyId,
					'post_id'  => $postId,
					'revises'  => $revises ? $targetId : 0,
					'decision' => $revises ? $decision : CannibalizationEngine::DECISION_NEW,
				),
				'content.draft',
				$revises ? 'REVISION_DRAFT_CREATED' : 'DRAFT_CREATED',
				$jobId
			);
		} else {
			$this->logger->warning( 'Draft creation failed', array( 'story_id' => $story->storyId ), 'content.draft', 'DRAFT_FAILED', $jobId );
		}
		return array(
			'post_id'  => $postId,
			'content'  => $html,
			'meta'     => $wpMeta,
			'revises'  => $revises ? $targetId : 0,
			'decision' => $revises ? $decision : CannibalizationEngine::DECISION_NEW,
		);
	}

	/**
	 * v2.0: hand the computed package to every active SEO adapter.
	 *
	 * The engine owns the package; adapters only mirror it into whichever SEO
	 * plugin the site actually runs. Never fatal to draft creation.
	 */
	private function applySeoAdapters( int $postId, array $seo, array $article ): void {
		if ( null === $this->seoAdapters ) {
			return;
		}
		// The adapters expect the keyword fields alongside the SEO package.
		$package = $seo;
		if ( ! isset( $package['focus_keyword'] ) ) {
			$package['focus_keyword'] = (string) ( $article['focus_keyword'] ?? '' );
		}
		if ( ! isset( $package['secondary_keywords'] ) ) {
			$package['secondary_keywords'] = (array) ( $article['secondary_keywords'] ?? array() );
		}
		try {
			$this->seoAdapters->apply( $postId, $package );
		} catch ( \Throwable $e ) {
			$this->logger->warning(
				'SEO adapters could not be applied',
				array(
					'post_id' => $postId,
					'error'   => $e->getMessage(),
				),
				'content.draft',
				'SEO_APPLY_FAILED'
			);
		}
	}

	/**
	 * v1.5: register the draft's language with WPML or Polylang when present,
	 * so editors can attach EN/AR translations to it later. Never fatal.
	 */
	private function assignLanguage( int $postId, string $lang ): void {
		try {
			if ( function_exists( 'pll_set_post_language' ) ) {
				pll_set_post_language( $postId, $lang ); // phpcs:ignore
				return;
			}
			if ( function_exists( 'do_action' ) && defined( 'ICL_SITEPRESS_VERSION' ) ) {
				$trid = apply_filters( 'wpml_element_trid', null, $postId, 'post_post' ); // phpcs:ignore
				do_action( // phpcs:ignore
					'wpml_set_element_language_details',
					array(
						'element_id'           => $postId,
						'element_type'         => 'post_post',
						'trid'                 => $trid,
						'language_code'        => $lang,
						'source_language_code' => null,
					)
				);
			}
		} catch ( \Throwable $e ) {
			$this->logger->warning( 'Could not assign post language', array( 'post_id' => $postId, 'lang' => $lang, 'error' => $e->getMessage() ), 'content.draft', 'LANG_ASSIGN_FAILED', 0 );
		}
	}

	/* ------------------------------------------------------------ rendering */

	/** @param array $links ExternalLink[] @param array $suggestions InternalLinkSuggestion[] */
	public function render( array $article, array $seo, array $links, array $suggestions, Story $story ): string {
		$out = array();

		$out[] = '<p class="nd-lead">' . $this->esc( (string) ( $article['lead'] ?? '' ) ) . '</p>';

		$anchorMap = array();
		foreach ( $suggestions as $s ) {
			$url = $this->index->urlFor( (int) $s->targetPostId );
			if ( '' !== $url ) {
				$anchorMap[ mb_strtolower( (string) $s->anchor, 'UTF-8' ) ] = $url;
			}
		}

		// Shared across every paragraph so each target is linked once per article.
		$usedUrls = array();

		foreach ( (array) ( $article['sections'] ?? array() ) as $section ) {
			$out[] = '<h2>' . $this->esc( (string) ( $section['heading'] ?? '' ) ) . '</h2>';
			foreach ( (array) ( $section['paragraphs'] ?? array() ) as $p ) {
				$out[] = '<p>' . $this->linkify( $this->esc( (string) $p ), $anchorMap, $usedUrls ) . '</p>';
			}
		}

		// External attribution (layer 16) — verified sources only.
		if ( $links ) {
			$out[] = '<h2>' . $this->esc( 'fa' === $story->langCode() ? 'منابع' : 'Sources' ) . '</h2><ul class="nd-sources">';
			foreach ( $links as $link ) {
				$out[] = '<li><a href="' . $this->esc( (string) $link->url ) . '" rel="nofollow noreferrer">' . $this->esc( '' !== $link->anchor ? (string) $link->anchor : (string) $link->url ) . '</a></li>';
			}
			$out[] = '</ul>';
		}

		$faq = (array) ( $article['faq'] ?? array() );
		if ( $faq ) {
			$out[] = '<h2>' . $this->esc( 'fa' === $story->langCode() ? 'پرسش‌های پرتکرار' : 'FAQ' ) . '</h2>';
			foreach ( $faq as $item ) {
				$out[] = '<h3>' . $this->esc( (string) ( $item['question'] ?? '' ) ) . '</h3>';
				$out[] = '<p>' . $this->esc( (string) ( $item['answer'] ?? '' ) ) . '</p>';
			}
		}

		$jsonld = (array) ( $seo['jsonld'] ?? array() );
		if ( $jsonld ) {
			$out[] = '<script type="application/ld+json">' . $this->jsonLd( $jsonld ) . '</script>';
		}
		return implode( "\n", array_filter( $out ) );
	}

	/** @return array<string, string> */
	private function metadata( Story $story, array $article, array $seo, array $meta, array $links, array $suggestions, int $jobId, array $image = array() ): array {
		$sourceRows = array();
		foreach ( $links as $link ) {
			$sourceRows[] = array( 'url' => $link->url, 'priority' => $link->priority );
		}
		$internal = array();
		foreach ( $suggestions as $s ) {
			$internal[] = array( 'target_post_id' => (int) $s->targetPostId, 'anchor' => $s->anchor, 'confidence' => $s->confidence );
		}
		$seoMeta = array(
			'title'            => (string) ( $seo['title'] ?? '' ),
			'meta_description' => (string) ( $seo['meta_description'] ?? '' ),
			'slug'             => (string) ( $seo['slug'] ?? '' ),
			'focus_entities'   => (array) ( $seo['focus_entities'] ?? array() ),
			'scores'           => (array) ( $seo['scores'] ?? array() ),
			'jsonld'           => (array) ( $seo['jsonld'] ?? array() ),
		);

		$meta = array(
			'nd_meta_version'      => '1.0',
			'nd_job_id'            => (string) $jobId,
			'nd_story_id'          => (string) $story->storyId,
			'nd_source_count'      => (string) $story->sourceCount,
			'newsdesk_sources'           => json_encode( $sourceRows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			'nd_provider'          => (string) ( $meta['provider'] ?? '' ),
			'nd_model'             => (string) ( $meta['model'] ?? '' ),
			'nd_prompt_version'    => (string) ( $meta['prompt_version'] ?? '' ),
			'nd_schema_version'    => (string) ( $meta['schema_version'] ?? '' ),
			'nd_quality_score'     => (string) ( $meta['quality_score'] ?? 0 ),
			// v1.6 editorial/SEO fields (also mirrored to Yoast / Rank Math keys when present).
			'nd_news_type'         => (string) ( $article['news_type'] ?? '' ),
			'nd_focus_keyword'     => (string) ( $article['focus_keyword'] ?? '' ),
			'nd_secondary_keywords'=> implode( ', ', (array) ( $article['secondary_keywords'] ?? array() ) ),
			'nd_search_intent'     => (string) ( $article['search_intent'] ?? '' ),
			// v2.0: Yoast / Rank Math keys are no longer written unconditionally
			// here. They are applied by their adapters, and only when that
			// plugin is actually installed (see SeoAdapterResolver).
			'nd_quality_breakdown' => json_encode( (array) ( $meta['quality_breakdown'] ?? array() ), JSON_UNESCAPED_UNICODE ),
			'nd_fact_check_status' => (string) $story->factCheckStatus,
			'nd_research_status'   => (string) $story->researchStatus,
			'nd_evidence_count'    => (string) $story->evidenceCount,
			'nd_verified_claims'   => (string) $story->verifiedClaimCount,
			'nd_contradictions'    => (string) $story->contradictionCount,
			'nd_content_hash'      => (string) ( $meta['content_hash'] ?? '' ),
			'nd_seo'               => json_encode( $seoMeta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			'newsdesk_external_links'    => json_encode( $sourceRows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			'newsdesk_internal_links'    => json_encode( $internal, JSON_UNESCAPED_UNICODE ),
			'nd_auto_publish'      => 'OFF', // §73
			'nd_status'            => 'draft',
		);

		// Phase 5 — image block (§66): every outcome recorded; thumbnail only on ready.
		if ( isset( $image['status'] ) && '' !== $image['status'] ) {
			$meta['nd_image_status'] = (string) $image['status'];
			if ( ! empty( $image['error_code'] ) ) {
				$meta['nd_image_error'] = (string) $image['error_code'];
			}
			if ( ! empty( $image['attachment_id'] ) ) {
				$meta['nd_image_attachment_id'] = (string) (int) $image['attachment_id'];
				$meta['_thumbnail_id']           = (string) (int) $image['attachment_id'];
			}
			if ( isset( $image['provider'] ) && '' !== $image['provider'] ) {
				$meta['nd_image_provider'] = (string) $image['provider'];
			}
			if ( isset( $image['model'] ) && '' !== $image['model'] ) {
				$meta['nd_image_model'] = (string) $image['model'];
			}
			if ( isset( $image['prompt_version'] ) && '' !== $image['prompt_version'] ) {
				$meta['nd_image_prompt_version'] = (string) $image['prompt_version'];
			}
		}
		return $meta;
	}

	/* ------------------------------------------------------------- helpers */

	private function esc( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}

	/** Max internal links inserted into a single paragraph. */
	const MAX_LINKS_PER_PARAGRAPH = 2;

	/**
	 * Insert internal links into one already-escaped paragraph.
	 *
	 * v2.0 fixes three defects in this helper:
	 *  - B-5: it `break`ed after the first successful term, so an article could
	 *    never receive more than one link per paragraph no matter how many
	 *    suggestions the engine produced.
	 *  - Each target URL is now linked at most once per article ($usedUrls is
	 *    carried across paragraphs by reference), which is the SEO-correct
	 *    behaviour instead of repeating the same link in every paragraph.
	 *  - preg_replace() treated the replacement as a template, so a URL or
	 *    anchor containing "$1" or a backslash silently corrupted the output.
	 *    Replacement is now done positionally with mb_substr(), and matches
	 *    that land inside an existing tag/anchor are skipped so links can
	 *    never nest.
	 *
	 * @param array<string, string> $anchorMap term (lowercased) => url
	 * @param array<string, bool>   $usedUrls  urls already linked in this article
	 */
	private function linkify( string $escaped, array $anchorMap, array &$usedUrls = array() ): string {
		if ( ! $anchorMap ) {
			return $escaped;
		}
		// Longest anchor first: prefer the most specific phrase.
		$terms = array_keys( $anchorMap );
		usort(
			$terms,
			static function ( string $a, string $b ): int {
				return mb_strlen( $b, 'UTF-8' ) <=> mb_strlen( $a, 'UTF-8' );
			}
		);

		$inserted = 0;
		foreach ( $terms as $term ) {
			if ( $inserted >= self::MAX_LINKS_PER_PARAGRAPH ) {
				break;
			}
			$url = $anchorMap[ $term ];
			if ( '' === $term || mb_strlen( $term, 'UTF-8' ) < 3 || '' === $url ) {
				continue;
			}
			if ( isset( $usedUrls[ $url ] ) ) {
				continue; // Already linked earlier in this article.
			}
			$pos = $this->findLinkablePosition( $escaped, $term );
			if ( null === $pos ) {
				continue;
			}
			// Preserve the casing as it appears in the text.
			$matched = mb_substr( $escaped, $pos, mb_strlen( $term, 'UTF-8' ), 'UTF-8' );
			$link    = '<a href="' . $this->esc( $url ) . '">' . $matched . '</a>';
			$escaped = mb_substr( $escaped, 0, $pos, 'UTF-8' )
				. $link
				. mb_substr( $escaped, $pos + mb_strlen( $term, 'UTF-8' ), null, 'UTF-8' );

			$usedUrls[ $url ] = true;
			$inserted++;
		}
		return $escaped;
	}

	/**
	 * First offset of $term in $haystack that is safe to wrap in an anchor,
	 * i.e. not inside an HTML tag and not inside an existing <a>…</a>.
	 *
	 * @return int|null
	 */
	private function findLinkablePosition( string $haystack, string $term ) {
		$offset = 0;
		while ( true ) {
			$pos = mb_stripos( $haystack, $term, $offset, 'UTF-8' );
			if ( false === $pos ) {
				return null;
			}
			$before = mb_substr( $haystack, 0, $pos, 'UTF-8' );
			$inTag  = mb_strrpos( $before, '<', 0, 'UTF-8' ) !== false
				&& ( mb_strrpos( $before, '>', 0, 'UTF-8' ) === false
					|| mb_strrpos( $before, '<', 0, 'UTF-8' ) > mb_strrpos( $before, '>', 0, 'UTF-8' ) );
			$openA  = mb_substr_count( $before, '<a ' );
			$closeA = mb_substr_count( $before, '</a>' );
			if ( ! $inTag && $openA === $closeA ) {
				return (int) $pos;
			}
			$offset = (int) $pos + 1;
		}
	}

	private function jsonLd( array $jsonld ): string {
		$json = json_encode( $jsonld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return str_replace( '</', '<\\/', (string) $json );
	}
}
