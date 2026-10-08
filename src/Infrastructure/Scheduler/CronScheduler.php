<?php
/**
 * WP-Cron as TRIGGER ONLY (§38, §42): 15-min tick, slot evaluation, run-window metrics.
 *
 * @package NewsDesk\AI\Infrastructure\Scheduler
 */

namespace NewsDesk\AI\Infrastructure\Scheduler;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Contracts\JobQueueInterface;
use NewsDesk\AI\Application\Contracts\JobRepositoryInterface;
use NewsDesk\AI\Application\NewsroomSettings;
use NewsDesk\AI\Application\PipelineRunner;
use NewsDesk\AI\Application\SchedulerService;
use NewsDesk\AI\Domain\Entity\Job;
use NewsDesk\AI\Domain\Entity\Lock;
use NewsDesk\AI\Domain\JobStateMachine;
use NewsDesk\AI\Infrastructure\Locks\LockManager;
use NewsDesk\AI\Logging\Contracts\LoggerInterface;
use NewsDesk\AI\Support\Random;
use NewsDesk\AI\Support\Time;

final class CronScheduler {

	public const HOOK     = 'newsdesk_newsroom_tick';
	public const INTERVAL = 900; // 15 minutes
	public const STATE_OPTION = 'newsdesk_newsroom_schedule_state';

	/** @var SchedulerService */
	private $scheduler;
	/** @var JobRepositoryInterface */
	private $jobs;
	/** @var JobQueueInterface */
	private $queue;
	/** @var NewsroomSettings */
	private $settings;
	/** @var LockManager */
	private $locks;
	/** @var LoggerInterface */
	private $logger;

	public function __construct( SchedulerService $scheduler, JobRepositoryInterface $jobs, JobQueueInterface $queue, NewsroomSettings $settings, LockManager $locks, LoggerInterface $logger ) {
		$this->scheduler = $scheduler;
		$this->jobs      = $jobs;
		$this->queue     = $queue;
		$this->settings  = $settings;
		$this->locks     = $locks;
		$this->logger    = $logger;
	}

	public function register(): void {
		if ( function_exists( 'add_action' ) ) {
			add_action( self::HOOK, array( $this, 'maybeRun' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
			add_action( 'newsdesk_newsroom_job_finished', array( $this, 'onJobFinished' ), 10, 2 ); // phpcs:ignore
			add_filter( 'cron_schedules', array( $this, 'addInterval' ) ); // phpcs:ignore
		}
	}

	/**
	 * @param array<string, array> $schedules
	 * @return array<string, array>
	 */
	public function addInterval( array $schedules ): array {
		$schedules['nd_newsroom_15min'] = array(
			'interval' => self::INTERVAL,
			'display'  => __( 'Every 15 minutes (NewsDesk AI)', 'newsdesk-ai' ),
		);
		return $schedules;
	}

	public function scheduleTick(): bool {
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 60, 'nd_newsroom_15min', self::HOOK );
			return true;
		}
		return false;
	}

	public function unscheduleTick(): void {
		if ( function_exists( 'wp_next_scheduled' ) ) {
			$ts = wp_next_scheduled( self::HOOK );
			if ( $ts ) {
				wp_unschedule_event( $ts, self::HOOK );
			}
		}
	}

	/**
	 * Tick handler — evaluates slot due-ness; creates + enqueues at most one job.
	 */
	public function maybeRun(): void {
		$this->locks->clearStale();
		$state = $this->getState();
		$now   = Time::now();

		if ( empty( $state['next_slot_at'] ) ) {
			$this->storeState( $this->withNextSlot( $state, $now ) );
			return;
		}

		$nextSlot = Time::fromDb( $state['next_slot_at'] );
		if ( null === $nextSlot || $nextSlot > $now ) {
			return; // not due yet
		}

		// A run is already live → defer to the next slot (non-overlap guarantee).
		if ( $this->locks->isHeld( Lock::GLOBAL ) ) {
			$this->storeState( $this->withNextSlot( $state, $now ) );
			return;
		}

		$slotKey  = SchedulerService::slotKey( $nextSlot );
		$existing = $this->jobs->findByKey( 'pipeline-' . $slotKey );

		if ( null !== $existing ) {
			// Strict idempotency: a slot gets exactly one job row ever (terminal or not).
			// A failed job is retried via its own retry policy, never re-created here.
			$this->storeState( $this->withNextSlot( $state, $now ) );
			return;
		}

		$job                = new Job();
		$job->jobKey        = 'pipeline-' . $slotKey;
		$job->type          = Job::TYPE_PIPELINE;
		$job->status        = JobStateMachine::QUEUED;
		$job->stage         = JobStateMachine::QUEUED;
		$job->priority      = 10;
		$job->scheduledAt   = $nextSlot;
		$job->correlationId = Random::uuid4();
		$job->createdAt     = $now;
		$job->updatedAt     = $now;
		$job->payload       = array( 'slot' => $slotKey, 'scheduled_trigger' => true, 'stage_truncated' => true );

		$id = $this->jobs->insert( $job );
		if ( $id <= 0 ) {
			$this->logger->error( 'Could not create scheduled job', array( 'slot' => $slotKey ), 'scheduler.cron', 'JOB_CREATE_FAILED' );
			return;
		}
		$this->queue->enqueue( PipelineRunner::HOOK, array( $id ), 'nd-newsroom' );

		$history   = isset( $state['history'] ) ? (array) $state['history'] : array();
		$history[] = array(
			'slot'       => $slotKey,
			'scheduled'  => Time::toDb( $nextSlot ),
			'started'    => Time::toDb( $now ),
			'finished'   => null,
			'delay_sec'  => max( 0, $now->getTimestamp() - $nextSlot->getTimestamp() ),
			'job_id'     => $id,
			'status'     => 'queued',
		);
		$history = array_slice( $history, -20 );

		$state['next_slot_at'] = Time::toDb( $this->scheduler->computeNextSlot( $this->settings->scheduleTimes(), $now ) );
		$state['history']      = $history;
		$state['last_trigger_at'] = Time::toDb( $now );
		$this->storeState( $state );

		$this->logger->info( 'Scheduled job created', array( 'slot' => $slotKey, 'job_id' => $id ), 'scheduler.cron', 'SLOT_TRIGGERED', $id, $job->correlationId );
	}

	/**
	 * Called by PipelineRunner when a job reaches a terminal state.
	 */
	public function onJobFinished( int $jobId, string $status ): void {
		$state = $this->getState();
		if ( empty( $state['history'] ) ) {
			return;
		}
		foreach ( (array) $state['history'] as $i => $entry ) {
			if ( (int) ( $entry['job_id'] ?? 0 ) === $jobId ) {
				$state['history'][ $i ]['finished'] = Time::toDb( Time::now() );
				$state['history'][ $i ]['status']   = strtolower( $status );
				$this->storeState( $state );
				return;
			}
		}
	}

	public function getState(): array {
		$state = function_exists( 'get_option' ) ? get_option( self::STATE_OPTION, array() ) : array();
		return is_array( $state ) ? $state : array();
	}

	public function getNextRun(): ?\DateTimeImmutable {
		$state = $this->getState();
		return empty( $state['next_slot_at'] ) ? null : Time::fromDb( $state['next_slot_at'] );
	}

	public function getTimezoneName(): string {
		$tz = Time::wpTimezone();
		return $tz->getName();
	}

	private function withNextSlot( array $state, \DateTimeImmutable $now ): array {
		$state['next_slot_at'] = Time::toDb( $this->scheduler->computeNextSlot( $this->settings->scheduleTimes(), $now ) );
		return $state;
	}

	private function storeState( array $state ): void {
		if ( function_exists( 'update_option' ) ) {
			update_option( self::STATE_OPTION, $state, false );
		}
	}
}
