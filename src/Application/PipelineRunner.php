<?php
/**
 * State-machine driver for background jobs (§37–§41, §50).
 *
 * @package NewsDesk\AI\Application
 */

namespace NewsDesk\AI\Application;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Contracts\JobEventRepositoryInterface;
use NewsDesk\AI\Application\Contracts\JobQueueInterface;
use NewsDesk\AI\Application\Contracts\JobRepositoryInterface;
use NewsDesk\AI\Domain\Entity\Job;
use NewsDesk\AI\Domain\Entity\JobEvent;
use NewsDesk\AI\Domain\JobStateMachine;
use NewsDesk\AI\Infrastructure\Locks\LockManager;
use NewsDesk\AI\Logging\Contracts\LoggerInterface;
use NewsDesk\AI\Support\Random;
use NewsDesk\AI\Support\Time;
use NewsDesk\AI\Application\SchedulerService;

final class PipelineRunner {

	public const HOOK = 'newsdesk_newsroom_run_job';

	/** @var JobRepositoryInterface */
	private $jobs;
	/** @var JobEventRepositoryInterface */
	private $events;
	/** @var DiscoveryService */
	private $discovery;
	/** @var StoryEditorialService */
	private $editorial;
	/** @var \NewsDesk\AI\Application\Research\StoryResearchService */
	private $researchFlow;
	/** @var \NewsDesk\AI\Application\Content\StoryContentService */
	private $contentFlow;
	/** @var \NewsDesk\AI\Application\Contracts\StoryRepositoryInterface */
	private $stories;
	/** @var LockManager */
	private $locks;
	/** @var JobQueueInterface */
	private $queue;
	/** @var LoggerInterface */
	private $logger;
	/** @var \NewsDesk\AI\Application\Notifications\NotificationService|null */
	private $notifications;

	public function __construct(
		JobRepositoryInterface $jobs,
		JobEventRepositoryInterface $events,
		DiscoveryService $discovery,
		StoryEditorialService $editorial,
		\NewsDesk\AI\Application\Research\StoryResearchService $researchFlow,
		\NewsDesk\AI\Application\Content\StoryContentService $contentFlow,
		\NewsDesk\AI\Application\Contracts\StoryRepositoryInterface $stories,
		LockManager $locks,
		JobQueueInterface $queue,
		LoggerInterface $logger,
		?\NewsDesk\AI\Application\Notifications\NotificationService $notifications = null
	) {
		$this->jobs          = $jobs;
		$this->events        = $events;
		$this->discovery     = $discovery;
		$this->editorial     = $editorial;
		$this->researchFlow  = $researchFlow;
		$this->contentFlow   = $contentFlow;
		$this->stories       = $stories;
		$this->locks         = $locks;
		$this->queue         = $queue;
		$this->logger        = $logger;
		$this->notifications = $notifications;
	}

	/**
	 * Execute (or resume) a job. Idempotent: a non-QUEUED/RETRYING job is a no-op.
	 */
	public function runJob( int $jobId ): void {
		$job = $this->jobs->find( $jobId );
		if ( null === $job ) {
			$this->logger->error( 'Job not found', array( 'job_id' => $jobId ), 'pipeline.runner', 'JOB_NOT_FOUND' );
			return;
		}
		$correlation = $job->correlationId ?: Random::traceId();
		$owner       = 'worker-' . getmypid() . '-' . Random::traceId();

		if ( JobStateMachine::RETRYING === $job->status ) {
			$target = $job->retryTarget ?: JobStateMachine::DISCOVERING;
			if ( ! JobStateMachine::isRetryTarget( $target ) ) {
				$this->fail( $job, 'INVALID_RETRY_TARGET', 'Retry target not allowed: ' . $target, $correlation );
				return;
			}
			if ( ! $this->jobs->transition( $job->id, JobStateMachine::RETRYING, 'resume', $target, $target, array() ) ) {
				$this->logger->warning( 'Job was taken over by another worker', array( 'job_id' => $jobId ), 'pipeline.runner', 'STATE_CONFLICT', $jobId, $correlation );
				return;
			}
			$this->record( $job->id, 'resumed', JobStateMachine::RETRYING, $target, 'Retrying at stage ' . $target, $correlation );
			$job = $this->jobs->find( $jobId );
		}

		if ( null === $job || JobStateMachine::QUEUED !== $job->status ) {
			// "Run now" executes the job synchronously AND leaves it on the
			// queue, so the async worker arrives seconds later and finds it
			// already finished. That is the guard working, not a problem —
			// logging it as a WARNING trains operators to ignore warnings.
			// A job caught mid-flight by a second worker is a different story
			// and stays a warning.
			$finished = ( null !== $job && $job->isTerminal() );
			$context  = array( 'job_id' => $jobId, 'status' => $job ? $job->status : '?' );

			if ( $finished ) {
				$this->logger->info( 'Job already finished — duplicate dispatch ignored', $context, 'pipeline.runner', 'JOB_ALREADY_FINISHED', $jobId, $correlation );
			} else {
				$this->logger->warning( 'Job is not runnable (idempotent skip)', $context, 'pipeline.runner', 'JOB_NOT_RUNNABLE', $jobId, $correlation );
			}
			return;
		}

		// Locks: global + job scope.
		if ( ! $this->locks->acquire( \NewsDesk\AI\Domain\Entity\Lock::GLOBAL, $owner, $jobId ) ) {
			$this->record( $job->id, 'skipped_lock', JobStateMachine::QUEUED, JobStateMachine::COMPLETED, 'Global lock held by another run', $correlation );
			$this->jobs->transition( $job->id, JobStateMachine::QUEUED, 'complete', JobStateMachine::COMPLETED, JobStateMachine::COMPLETED, array( 'outcome' => 'SKIPPED_LOCK' ) );
			$this->jobs->touchFinished( $job->id, Time::now(), JobStateMachine::COMPLETED );
			return;
		}
		if ( ! $this->locks->acquire( $this->locks->jobLockName( $jobId ), $owner, $jobId ) ) {
			$this->locks->release( \NewsDesk\AI\Domain\Entity\Lock::GLOBAL, $owner );
			$this->record( $job->id, 'skipped_lock', JobStateMachine::QUEUED, JobStateMachine::COMPLETED, 'Job lock held', $correlation );
			$this->jobs->transition( $job->id, JobStateMachine::QUEUED, 'complete', JobStateMachine::COMPLETED, JobStateMachine::COMPLETED, array( 'outcome' => 'SKIPPED_LOCK' ) );
			$this->jobs->touchFinished( $job->id, Time::now(), JobStateMachine::COMPLETED );
			return;
		}

		try {
			$this->runPipeline( $job, $owner, $correlation );
		} catch ( \Throwable $e ) {
			$this->handleFailure( $job, $e, $owner, $correlation );
		} finally {
			$this->locks->release( $this->locks->jobLockName( $jobId ), $owner );
			$this->locks->release( \NewsDesk\AI\Domain\Entity\Lock::GLOBAL, $owner );
		}
	}

	private function runPipeline( Job $job, string $owner, string $correlation ): void {
		// Attempt bookkeeping.
		$this->jobs->bumpAttempts( $job->id, $job->attempts + 1, $job->retryTarget );
		$this->jobs->touchStarted( $job->id, Time::now() );
		if ( ! $this->jobs->transition( $job->id, JobStateMachine::QUEUED, 'start', JobStateMachine::DISCOVERING, JobStateMachine::DISCOVERING, array() ) ) {
			$this->logger->warning( 'Transition lost (concurrency)', array( 'job_id' => $job->id ), 'pipeline.runner', 'STATE_CONFLICT', $job->id, $correlation );
			return;
		}
		$this->record( $job->id, 'job_started', JobStateMachine::QUEUED, JobStateMachine::DISCOVERING, 'Discovery started', $correlation );

		$onlySource = (int) ( $job->payload['source_id'] ?? 0 );
		$force      = ! empty( $job->payload['force'] );
		$stats      = $this->discovery->run( $onlySource, $force );
		$this->locks->extend( \NewsDesk\AI\Domain\Entity\Lock::GLOBAL, $owner ); // heartbeat

		if ( ! $this->jobs->transition( $job->id, JobStateMachine::DISCOVERING, 'normalize', JobStateMachine::NORMALIZING, JobStateMachine::NORMALIZING, array_merge( $stats, array( 'outcome' => 'DISCOVERY_COMPLETE' ) ) ) ) {
			return;
		}
		$this->record( $job->id, 'discovery_complete', JobStateMachine::DISCOVERING, JobStateMachine::NORMALIZING, sprintf( 'New: %d · Duplicates: %d · Failed sources: %d', $stats['new_items'], $stats['duplicates'], count( $stats['sources_failed'] ) ), $correlation );

		// Phase 2a: dedup sweep (explicit stage; cheap signal already applied at insert).
		if ( ! $this->jobs->transition( $job->id, JobStateMachine::NORMALIZING, 'deduplicate', JobStateMachine::DEDUPLICATING, JobStateMachine::DEDUPLICATING, array( 'duplicates_found' => $stats['duplicates'] ) ) ) {
			return;
		}
		$this->record( $job->id, 'dedup_complete', JobStateMachine::NORMALIZING, JobStateMachine::DEDUPLICATING, sprintf( 'Duplicate signals: %d', $stats['duplicates'] ), $correlation );

		// Phase 2b: clustering + scoring (story ≠ article — §5).
		$windowKey = (string) ( $job->payload['slot'] ?? SchedulerService::slotKey( Time::now() ) );
		if ( ! $this->jobs->transition( $job->id, JobStateMachine::DEDUPLICATING, 'cluster', JobStateMachine::CLUSTERING, JobStateMachine::CLUSTERING, array() ) ) {
			return;
		}
		$editorial = $this->editorial->run( $job->id, $windowKey );
		$this->record( $job->id, 'clustering_complete', JobStateMachine::DEDUPLICATING, JobStateMachine::CLUSTERING, sprintf( 'Clusters formed: %d', $editorial['clustered'] ), $correlation );

		if ( ! $this->jobs->transition( $job->id, JobStateMachine::CLUSTERING, 'score', JobStateMachine::SCORING, JobStateMachine::SCORING, array( 'stories' => $editorial['stories_total'] ) ) ) {
			return;
		}
		$this->record( $job->id, 'scoring_complete', JobStateMachine::CLUSTERING, JobStateMachine::SCORING, sprintf( 'Scored stories: %d', $editorial['stories_total'] ), $correlation );

		// Phase 2c: editorial selection. NO_PUBLISHABLE_STORY_FOUND is a VALID result (§14).
		if ( ! $this->jobs->transition( $job->id, JobStateMachine::SCORING, 'select', JobStateMachine::SELECTING, JobStateMachine::SELECTING, array( 'selection_outcome' => $editorial['outcome'], 'selected_story_ids' => array_map( static function ( $st ) { return $st->storyId; }, $editorial['selected'] ) ) ) ) {
			return;
		}
		$this->record( $job->id, 'selection_complete', JobStateMachine::SCORING, JobStateMachine::SELECTING, sprintf( 'Selection outcome: %s · selected: %d', $editorial['outcome'], count( $editorial['selected'] ) ), $correlation );

		// Phase 3: RESEARCH → EVIDENCE → FACT CHECK for SELECTED stories only.
		$selected = $editorial['selected'];
		if ( ! $selected ) {
			if ( ! $this->jobs->transition( $job->id, JobStateMachine::SELECTING, 'complete', JobStateMachine::COMPLETED, JobStateMachine::COMPLETED, array( 'phase3' => 'SKIPPED_NO_SELECTION' ) ) ) {
				return;
			}
			$this->jobs->touchFinished( $job->id, Time::now(), JobStateMachine::COMPLETED );
			$this->record( $job->id, 'job_completed', JobStateMachine::SELECTING, JobStateMachine::COMPLETED, 'Job completed: NO_PUBLISHABLE_STORY_FOUND (Phase 3 skipped — nothing selected)', $correlation );
			$this->logger->info( 'Job completed', array( 'job_id' => $job->id, 'new_items' => $stats['new_items'], 'outcome' => $editorial['outcome'], 'selected' => 0, 'phase3' => 'SKIPPED_NO_SELECTION' ), 'pipeline.runner', 'JOB_COMPLETED', $job->id, $correlation );
			return;
		}

		if ( ! $this->jobs->transition( $job->id, JobStateMachine::SELECTING, 'research', JobStateMachine::RESEARCHING, JobStateMachine::RESEARCHING, array() ) ) {
			return;
		}
		$research = $this->researchFlow->run( $selected, $job->id );
		$this->record( $job->id, 'research_complete', JobStateMachine::SELECTING, JobStateMachine::RESEARCHING, sprintf( 'Researched stories: %d', $research['researched'] ), $correlation );

		if ( ! $this->jobs->transition( $job->id, JobStateMachine::RESEARCHING, 'extract', JobStateMachine::EVIDENCE_EXTRACTING, JobStateMachine::EVIDENCE_EXTRACTING, array( 'evidence_extracted' => $research['evidence'] ) ) ) {
			return;
		}
		$this->record( $job->id, 'evidence_complete', JobStateMachine::RESEARCHING, JobStateMachine::EVIDENCE_EXTRACTING, sprintf( 'Evidence claims extracted: %d', $research['claims'] ), $correlation );

		if ( ! $this->jobs->transition( $job->id, JobStateMachine::EVIDENCE_EXTRACTING, 'fact_check', JobStateMachine::FACT_CHECKING, JobStateMachine::FACT_CHECKING, array( 'verdicts' => $research['verdicts'] ) ) ) {
			return;
		}
		$this->record( $job->id, 'fact_check_complete', JobStateMachine::EVIDENCE_EXTRACTING, JobStateMachine::FACT_CHECKING, sprintf( 'Checked claims: %d', array_sum( $research['verdicts'] ) ), $correlation );

		// Phase 4: CONTENT (strategy → compose → validate → SEO/AEO/GEO →
		// links → quality gate → WP draft). §36: below gate after max revisions
		// → NEEDS_REVIEW (human). §73: drafts only, never publish.
		$eligible = array();
		foreach ( $selected as $st ) {
			$fresh = $this->stories->find( $st->storyId );
			if ( null === $fresh ) {
				continue;
			}
			if ( 'verified' === $fresh->factCheckStatus || 'partial' === $fresh->factCheckStatus ) {
				$eligible[] = $fresh;
			} else {
				$this->logger->warning(
					'Story not eligible for content',
					array( 'story_id' => $fresh->storyId, 'title' => mb_substr( (string) $fresh->canonicalTitle, 0, 80 ), 'fact_check_status' => $fresh->factCheckStatus, 'evidence' => $fresh->evidenceCount, 'verified' => $fresh->verifiedClaimCount, 'contradicted' => $fresh->contradictionCount ),
					'pipeline.runner',
					'STORY_NOT_ELIGIBLE',
					$job->id,
					$correlation
				);
			}
		}
		if ( ! $this->jobs->transition( $job->id, JobStateMachine::FACT_CHECKING, 'decide', JobStateMachine::DECIDING, JobStateMachine::DECIDING, array( 'eligible' => count( $eligible ), 'fact_checked' => count( $selected ) ) ) ) {
			return;
		}
		$this->record( $job->id, 'content_decided', JobStateMachine::FACT_CHECKING, JobStateMachine::DECIDING, sprintf( 'Stories eligible for content: %d / %d', count( $eligible ), count( $selected ) ), $correlation );

		if ( ! $eligible ) {
			if ( ! $this->jobs->transition( $job->id, JobStateMachine::DECIDING, 'complete', JobStateMachine::COMPLETED, JobStateMachine::COMPLETED, array( 'phase4' => 'SKIPPED_NO_ELIGIBLE_STORY' ) ) ) {
				return;
			}
			$this->jobs->touchFinished( $job->id, Time::now(), JobStateMachine::COMPLETED );
			$this->record( $job->id, 'job_completed', JobStateMachine::DECIDING, JobStateMachine::COMPLETED, 'Job completed: no story cleared the fact-check gate for content (Phase 4 skipped)', $correlation );
			$this->logger->warning( 'Job completed WITHOUT content: none of the selected stories passed fact-check (see STORY_NOT_ELIGIBLE above)', array( 'job_id' => $job->id, 'selected' => count( $selected ), 'phase4' => 'SKIPPED_NO_ELIGIBLE_STORY' ), 'pipeline.runner', 'JOB_COMPLETED', $job->id, $correlation );
			if ( function_exists( 'do_action' ) ) {
				do_action( 'newsdesk_newsroom_job_finished', $job->id, JobStateMachine::COMPLETED ); // phpcs:ignore
			}
			return;
		}

		if ( ! $this->jobs->transition( $job->id, JobStateMachine::DECIDING, 'generate', JobStateMachine::GENERATING, JobStateMachine::GENERATING, array( 'stories' => count( $eligible ) ) ) ) {
			return;
		}
		$content = $this->contentFlow->run( $eligible, $job->id ); // fresh story objects (fact-check state)
		$this->record( $job->id, 'content_generated', JobStateMachine::DECIDING, JobStateMachine::GENERATING, sprintf( 'Eligible: %d · generated versions: %d', $content['eligible'], $content['generated'] ), $correlation );

		if ( ! $this->jobs->transition( $job->id, JobStateMachine::GENERATING, 'validate', JobStateMachine::VALIDATING, JobStateMachine::VALIDATING, array( 'validated_versions' => $content['versions'] ) ) ) {
			return;
		}
		$this->record( $job->id, 'content_validated', JobStateMachine::GENERATING, JobStateMachine::VALIDATING, sprintf( 'Schema/grounding-validated versions: %d', $content['versions'] ), $correlation );

		if ( ! $this->jobs->transition( $job->id, JobStateMachine::VALIDATING, 'seo', JobStateMachine::SEO_PROCESSING, JobStateMachine::SEO_PROCESSING, array( 'seo_stories' => $content['generated'] ) ) ) {
			return;
		}
		$this->record( $job->id, 'seo_complete', JobStateMachine::VALIDATING, JobStateMachine::SEO_PROCESSING, sprintf( 'SEO/AEO/GEO packaged stories: %d', $content['generated'] ), $correlation );

		if ( ! $this->jobs->transition( $job->id, JobStateMachine::SEO_PROCESSING, 'links', JobStateMachine::LINK_ANALYSIS, JobStateMachine::LINK_ANALYSIS, array( 'linked_stories' => $content['drafted'] ) ) ) {
			return;
		}
		$this->record( $job->id, 'link_analysis_complete', JobStateMachine::SEO_PROCESSING, JobStateMachine::LINK_ANALYSIS, sprintf( 'Attribution + internal link suggestions: %d stories', $content['drafted'] ), $correlation );

		// Image queueing is Phase 5 — LINK_ANALYSIS goes straight to quality.
		if ( ! $this->jobs->transition( $job->id, JobStateMachine::LINK_ANALYSIS, 'quality', JobStateMachine::QUALITY_CHECK, JobStateMachine::QUALITY_CHECK, array( 'needs_review' => $content['needs_review'], 'blocked' => $content['blocked'] ) ) ) {
			return;
		}
		$this->record( $job->id, 'quality_check_complete', JobStateMachine::LINK_ANALYSIS, JobStateMachine::QUALITY_CHECK, sprintf( 'Needs review: %d · blocked: %d', $content['needs_review'], $content['blocked'] ), $correlation );

		if ( ! $this->jobs->transition( $job->id, JobStateMachine::QUALITY_CHECK, 'create_draft', JobStateMachine::DRAFT_CREATING, JobStateMachine::DRAFT_CREATING, array( 'post_ids' => $content['post_ids'] ) ) ) {
			return;
		}
		$this->record( $job->id, 'draft_creating', JobStateMachine::QUALITY_CHECK, JobStateMachine::DRAFT_CREATING, sprintf( 'Drafts created: %d', count( $content['post_ids'] ) ), $correlation );

		// Phase 8 — non-blocking editor alert (§76: a failing notification
		// channel must never affect the job outcome).
		if ( null !== $this->notifications && ( count( $content['post_ids'] ) > 0 || $content['needs_review'] > 0 ) ) {
			try {
				$reviewUrl = function_exists( 'admin_url' ) ? admin_url( 'admin.php?page=nd-drafts' ) : 'admin.php?page=nd-drafts'; // phpcs:ignore
				$this->notifications->dispatch(
					\NewsDesk\AI\Application\Notifications\NotificationMessage::contentReady(
						__( 'New content is ready for review', 'newsdesk-ai' ),
						sprintf(
							/* translators: 1: job id, 2: number of drafts ready, 3: number of stories below quality threshold, 4: review page URL */
							__( 'Job #%1$d: %2$d drafts ready for review and %3$d stories below the quality gate — publishing requires human approval (§73). Review page: %4$s', 'newsdesk-ai' ),
							(int) $job->id,
							count( $content['post_ids'] ),
							(int) $content['needs_review'],
							$reviewUrl
						),
						(int) $job->id
					)
				);
			} catch ( \Throwable $e ) {
				$this->logger->warning( 'content_ready notification failed (non-fatal)', array( 'error' => get_class( $e ) ), 'pipeline.runner', 'NOTIFY_CONTENT_READY_FAILED', $job->id, $correlation );
			}
		}

		if ( $content['needs_review'] > 0 ) {
			// §36: below-gate content after max revisions → human review required.
			if ( ! $this->jobs->transition( $job->id, JobStateMachine::DRAFT_CREATING, 'mark_needs_review', JobStateMachine::NEEDS_REVIEW, JobStateMachine::NEEDS_REVIEW, array( 'needs_review' => $content['needs_review'] ) ) ) {
				return;
			}
			$this->jobs->touchFinished( $job->id, Time::now(), JobStateMachine::NEEDS_REVIEW );
			$this->record( $job->id, 'job_needs_review', JobStateMachine::DRAFT_CREATING, JobStateMachine::NEEDS_REVIEW, sprintf( '%d story(ies) below the quality gate — human review required (§36)', $content['needs_review'] ), $correlation );
			$this->logger->warning( 'Job needs review', array( 'job_id' => $job->id, 'needs_review' => $content['needs_review'] ), 'pipeline.runner', 'JOB_NEEDS_REVIEW', $job->id, $correlation );
			if ( function_exists( 'do_action' ) ) {
				do_action( 'newsdesk_newsroom_job_finished', $job->id, JobStateMachine::NEEDS_REVIEW ); // phpcs:ignore
			}
			return;
		}

		if ( ! $this->jobs->transition( $job->id, JobStateMachine::DRAFT_CREATING, 'complete', JobStateMachine::COMPLETED, JobStateMachine::COMPLETED, array() ) ) {
			return;
		}
		$this->jobs->touchFinished( $job->id, Time::now(), JobStateMachine::COMPLETED );
		$this->record( $job->id, 'job_completed', JobStateMachine::DRAFT_CREATING, JobStateMachine::COMPLETED, sprintf( 'Job completed: %d draft(s) created (drafts only — §73)', count( $content['post_ids'] ) ), $correlation );
		$this->logger->info( 'Job completed', array( 'job_id' => $job->id, 'new_items' => $stats['new_items'], 'outcome' => $editorial['outcome'], 'selected' => count( $selected ), 'evidence' => $research['evidence'], 'verdicts' => $research['verdicts'], 'drafted' => $content['drafted'] ), 'pipeline.runner', 'JOB_COMPLETED', $job->id, $correlation );

		if ( function_exists( 'do_action' ) ) {
			do_action( 'newsdesk_newsroom_job_finished', $job->id, JobStateMachine::COMPLETED ); // phpcs:ignore
		}
	}

	private function handleFailure( Job $job, \Throwable $e, string $owner, string $correlation ): void {
		$this->logger->error(
			'Pipeline failure',
			array( 'job_id' => $job->id, 'error' => get_class( $e ), 'message' => $e->getMessage() ),
			'pipeline.runner',
			'JOB_FAILED',
			$job->id,
			$correlation
		);

		$stage = $job->status;

		if ( RetryPolicy::isRetryable( $e ) && $job->attempts < $job->maxAttempts ) {
			$attempts = $job->attempts + 1;
			$this->jobs->bumpAttempts( $job->id, $attempts, $stage );
			if ( $this->jobs->transition( $job->id, $job->status, 'retry', JobStateMachine::RETRYING, JobStateMachine::RETRYING, array() ) ) {
				$this->record( $job->id, 'retry_scheduled', $job->status, JobStateMachine::RETRYING, sprintf( 'Attempt %d/%d in %d s', $attempts, $job->maxAttempts, RetryPolicy::backoffSeconds( $attempts ) ), $correlation );
				$this->queue->enqueue( self::HOOK, array( $job->id ), 'nd-newsroom', RetryPolicy::backoffSeconds( $attempts ) );
				return;
			}
		}

		$this->fail( $job, 'FATAL', $e->getMessage(), $correlation );
	}

	private function fail( Job $job, string $code, string $message, string $correlation ): void {
		$this->jobs->recordError( $job->id, $code, $message );
		if ( $this->jobs->transition( $job->id, $job->status, 'fail', JobStateMachine::FAILED, JobStateMachine::FAILED, array() ) ) {
			$this->jobs->touchFinished( $job->id, Time::now(), JobStateMachine::FAILED );
			$this->record( $job->id, 'job_failed', $job->status, JobStateMachine::FAILED, $code, $correlation );
		}
	}

	private function record( int $jobId, string $event, ?string $from, ?string $to, string $message, string $correlation ): void {
		$evt           = new JobEvent();
		$evt->jobId    = $jobId;
		$evt->event    = $event;
		$evt->stateBefore = (string) $from;
		$evt->stateAfter  = (string) $to;
		$evt->message  = $message;
		$evt->correlationId = $correlation;
		$this->events->insert( $evt );
	}
}
