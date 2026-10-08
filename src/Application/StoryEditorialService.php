<?php
/**
 * Phase 2 orchestrator: window load → cluster → score → persist → select.
 *
 * Owns the "story ≠ article" invariant (§5): one Story row per cluster_key, merged on
 * re-run. Selection output feeds Phase 3 (research) — it never creates a post.
 *
 * @package NewsDesk\AI\Application
 */

namespace NewsDesk\AI\Application;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Contracts\NewsItemRepositoryInterface;
use NewsDesk\AI\Application\Contracts\SourceRepositoryInterface;
use NewsDesk\AI\Application\Contracts\StoryRepositoryInterface;
use NewsDesk\AI\Application\Contracts\WpPostIndexInterface;
use NewsDesk\AI\Application\Scoring\CannibalizationEngine;
use NewsDesk\AI\Application\Scoring\EditorialSelector;
use NewsDesk\AI\Application\Scoring\NewsWindow;
use NewsDesk\AI\Application\Scoring\StoryClusterer;
use NewsDesk\AI\Application\Scoring\StoryScorer;
use NewsDesk\AI\Domain\Entity\Story;
use NewsDesk\AI\Logging\Contracts\LoggerInterface;
use NewsDesk\AI\Support\Time;

final class StoryEditorialService {

	/** @var NewsItemRepositoryInterface */
	private $items;
	/** @var SourceRepositoryInterface */
	private $sources;
	/** @var StoryRepositoryInterface */
	private $stories;
	/** @var StoryClusterer */
	private $clusterer;
	/** @var StoryScorer */
	private $scorer;
	/** @var EditorialSelector */
	private $selector;
	/** @var NewsroomSettings */
	private $settings;
	/** @var LoggerInterface */
	private $logger;
	/** @var CannibalizationEngine|null Optional: omit to keep legacy behaviour. */
	private $cannibalization;
	/** @var WpPostIndexInterface|null */
	private $index;

	/** How many published posts to compare a story against. */
	public const CANNIBALIZATION_SCAN = 200;

	public function __construct(
		NewsItemRepositoryInterface $items,
		SourceRepositoryInterface $sources,
		StoryRepositoryInterface $stories,
		StoryClusterer $clusterer,
		StoryScorer $scorer,
		EditorialSelector $selector,
		NewsroomSettings $settings,
		LoggerInterface $logger,
		?CannibalizationEngine $cannibalization = null,
		?WpPostIndexInterface $index = null
	) {
		$this->items     = $items;
		$this->sources   = $sources;
		$this->stories   = $stories;
		$this->clusterer = $clusterer;
		$this->scorer    = $scorer;
		$this->selector  = $selector;
		$this->settings  = $settings;
		$this->logger    = $logger;
		// Both are needed together: deciding against published posts requires
		// being able to read them.
		$this->cannibalization = ( null !== $cannibalization && null !== $index ) ? $cannibalization : null;
		$this->index           = $index;
	}

	/**
	 * Full Phase 2 editorial pass.
	 *
	 * @param int           $jobId   Owning pipeline job.
	 * @param string        $windowKey e.g. slot key of the triggering run.
	 * @return array{clustered: int, stories_total: int, selected: Story[], outcome: string, gates: array<int,string>}
	 */
	public function run( int $jobId, string $windowKey ): array {
		$now = Time::now();

		$sourceList = $this->sources->findAll();
		$sourceMap  = array();
		foreach ( $sourceList as $src ) {
			$sourceMap[ (int) $src->id ] = $src;
		}

		// v2.0 (A-1): walk the 24h → 7d → NO NEWS ladder instead of always
		// loading a flat 7-day window. A fresh story must not have to compete
		// with week-old material, and "no news" is a legitimate outcome.
		$window = NewsWindow::fromSettings( $this->settings );
		$rung   = $window->firstRung();
		$items  = array();
		$since  = $now;

		while ( $window->isSearchable( $rung ) ) {
			$since = $window->since( $rung, $now );
			$items = $this->items->findNormalizedSince( $since );
			$this->logger->debug(
				'Clustering window loaded',
				array(
					'items'  => count( $items ),
					'since'  => Time::toDb( $since ),
					'rung'   => $rung,
					'window' => $window->label( $rung ),
				),
				'news.stories',
				'WINDOW_LOADED',
				$jobId
			);
			if ( ! empty( $items ) ) {
				break;
			}
			$next = $window->nextRung( $rung );
			// Only a real widening is logged as one; running out of rungs is
			// reported once below as NO_NEWS, not as a second "widening".
			if ( $window->isSearchable( $next ) ) {
				$this->logger->info(
					'Window empty — widening the news window',
					array(
						'from' => $window->label( $rung ),
						'to'   => $window->label( $next ),
					),
					'news.stories',
					'WINDOW_WIDENED',
					$jobId
				);
			}
			$rung = $next;
		}

		if ( empty( $items ) ) {
			// Terminal rung: publish nothing rather than manufacture news.
			$this->logger->info(
				'No news in any window',
				array( 'checked' => $window->primaryHours() . 'h → ' . $window->fallbackDays() . 'd' ),
				'news.stories',
				'NO_NEWS',
				$jobId
			);
			return array(
				'clustered'     => 0,
				'stories_total' => 0,
				'selected'      => array(),
				'outcome'       => EditorialSelector::OUTCOME_NO_PUBLISHABLE_STORY,
				'gates'         => array(),
				'window_rung'   => NewsWindow::RUNG_NONE,
				'decisions'     => array(),
			);
		}

		$drafts = $this->clusterer->cluster( $items, $sourceMap );

		$saved = array();
		foreach ( $drafts as $draft ) {
			$existing = $this->stories->findByClusterKey( $draft->clusterKey );
			$story    = $existing ?? $draft;
			if ( null === $existing ) {
				$story->runJobId = $jobId;
				$story->windowKey = $windowKey;
				$story->createdAt = $now;
				$storyId = $this->stories->insert( $story );
				if ( $storyId <= 0 ) {
					continue; // concurrent insert of same cluster → picked up next run
				}
				$this->attachSources( $story, $windowKey );
			} else {
				$story = $this->mergeInto( $existing, $draft );
				$story->updatedAt = $now;
				$this->stories->update( $story );
			}
			$saved[] = $story;
		}

		// Score everything in the window (existing candidates included).
		foreach ( $saved as $story ) {
			$scored = $this->scorer->score( $story, $sourceMap, $now );
			$story  = $scored['story'];
			// Full signal breakdown (SEO/AEO/GEO) rides in aeo_signals for UI + draft metadata.
			$story->aeoSignals = array_merge( $story->aeoSignals, $scored['scores'] );
			$story->updatedAt = $now;
			$this->stories->update( $story );
		}

		// Selection: candidates for this window, ordered by composite.
		$candidates = $saved;
		usort( $candidates, static function ( $a, $b ) {
			return $b->importanceScore <=> $a->importanceScore;
		} );

		$recent = array();
		foreach ( $candidates as $c ) {
			$selectedBefore = $this->stories->findSelectedByClusterKeySince( $c->clusterKey, $now->modify( '-' . (int) $this->settings->fallbackWindowDays() . ' days' ) );
			if ( null !== $selectedBefore ) {
				$recent[] = $c->clusterKey;
			}
		}

		$decision = $this->selector->select(
			$candidates,
			array(
				'max_stories'         => (int) $this->settings->maxStoriesPerWindow(),
				'min_composite'       => (float) $this->settings->selectionQualityGate(),
				'min_trust'           => (float) $this->settings->selectionMinTrust(),
				'window_key'          => $windowKey,
				'recent_cluster_keys' => $recent,
			)
		);

		// v2.0 (A-2): decide NEW/UPDATE/REWRITE/MERGE/CORRECT/REPLACE/NO_ARTICLE
		// against what the site already published. v1.6.0 stored these columns
		// but never computed them, so every story was implicitly "NEW" and the
		// plugin could cannibalize its own keywords.
		$decisions = array();
		$finalSelected = array();
		$existingPosts = $this->cannibalization ? $this->index->existingPosts( self::CANNIBALIZATION_SCAN ) : array();

		foreach ( $decision['selected'] as $sel ) {
			$reason = $decision['reasons'][ $sel->storyId ] ?? '';

			if ( null !== $this->cannibalization ) {
				$verdict = $this->cannibalization->decide(
					$sel,
					$existingPosts,
					array(
						'now'            => $now,
						'contradictions' => $sel->contradictionCount,
					)
				);

				$sel->editorialDecision = $verdict['decision'];
				$sel->existingArticleId = (int) $verdict['existing_article_id'];
				$this->stories->update( $sel );

				$decisions[ $sel->storyId ] = $verdict;
				$reason                     = '' !== $reason
					? $reason . ' · ' . $verdict['decision'] . ': ' . $verdict['reason']
					: $verdict['decision'] . ': ' . $verdict['reason'];

				$this->logger->info(
					'Cannibalization decision',
					array(
						'story_id'            => $sel->storyId,
						'decision'            => $verdict['decision'],
						'existing_article_id' => $verdict['existing_article_id'],
						'overlap'             => $verdict['overlap'],
					),
					'news.stories',
					'EDITORIAL_DECISION',
					$jobId
				);

				// NO_ARTICLE means exactly that: do not select it for drafting.
				if ( CannibalizationEngine::blocksDraft( $verdict['decision'] ) ) {
					$decision['gates'][ $sel->storyId ] = 'CANNIBALIZATION_NO_ARTICLE';
					continue;
				}
			}

			$this->stories->markSelected( $sel->storyId, $now, $windowKey, $reason, $jobId );
			$finalSelected[] = $sel;

			// A-16: fires once per story that will actually be written.
			if ( function_exists( 'do_action' ) ) {
				do_action( 'newsdesk_news_selected', $sel, $decisions[ $sel->storyId ] ?? array(), $jobId );
			}
		}
		$decision['selected'] = $finalSelected;
		if ( empty( $finalSelected ) ) {
			$decision['outcome'] = EditorialSelector::OUTCOME_NO_PUBLISHABLE_STORY;
		}

		// A bare count answers "how many were rejected" but not "why", which is
		// the only question an operator staring at selected=0 actually has.
		// Breaking it down by reason turns the log line into a diagnosis:
		// BELOW_QUALITY_GATE x14 means thresholds, ALREADY_SELECTED_RECENTLY
		// x14 means the archive check, and those need opposite responses.
		$gateBreakdown = array();
		foreach ( $decision['gates'] as $gateReason ) {
			$gateBreakdown[ $gateReason ] = ( $gateBreakdown[ $gateReason ] ?? 0 ) + 1;
		}
		arsort( $gateBreakdown );

		$this->logger->info(
			'Editorial pass finished',
			array(
				'clustered'   => count( $drafts ),
				'selected'    => count( $decision['selected'] ),
				'outcome'     => $decision['outcome'],
				'gate_hits'   => count( $decision['gates'] ),
				'gate_reasons'=> $gateBreakdown,
				'window'      => $window->label( $rung ),
			),
			'news.stories',
			'EDITORIAL_DONE',
			$jobId
		);

		return array(
			'clustered'      => count( $drafts ),
			'stories_total'  => count( $saved ),
			'selected'       => $decision['selected'],
			'outcome'        => $decision['outcome'],
			'gates'          => $decision['gates'],
			'window_rung'    => $rung,
			'decisions'      => $decisions,
		);
	}

	private function attachSources( Story $story, string $windowKey ): void {
		$this->stories->attachSource( $story->storyId, $story->primarySourceId, 'primary' );
		foreach ( $story->secondarySources as $sid ) {
			$this->stories->attachSource( $story->storyId, (int) $sid, 'secondary' );
		}
	}

	/**
	 * Merge a re-clustered draft into an existing story: never duplicate the story,
	 * extend evidence (new items/sources), keep the earlier first_published_at.
	 */
	private function mergeInto( Story $existing, Story $draft ): Story {
		$existing->itemIds = array_values( array_unique( array_merge( $existing->itemIds, $draft->itemIds ) ) );
		$existing->itemCount = count( $existing->itemIds );
		$existing->secondarySources = array_values( array_unique( array_merge( $existing->secondarySources, $draft->secondarySources ) ) );
		$existing->sourceCount = count( array_unique( array_merge( array( $existing->primarySourceId ), $existing->secondarySources ) ) );
		$existing->topics = array_values( array_unique( array_merge( $existing->topics, $draft->topics ) ) );
		if ( null === $existing->firstPublishedAt || ( null !== $draft->firstPublishedAt && $draft->firstPublishedAt < $existing->firstPublishedAt ) ) {
			$existing->firstPublishedAt = $draft->firstPublishedAt;
		}
		if ( null === $existing->lastUpdatedAt || ( null !== $draft->lastUpdatedAt && $draft->lastUpdatedAt > $existing->lastUpdatedAt ) ) {
			$existing->lastUpdatedAt = $draft->lastUpdatedAt;
		}
		if ( null === $existing->canonicalTitle || '' === $existing->canonicalTitle ) {
			$existing->canonicalTitle = $draft->canonicalTitle;
		}
		return $existing;
	}
}
