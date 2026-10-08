<?php
/**
 * §47 manual triggers — enqueue only; no heavy work inside HTTP requests (§56).
 *
 * @package NewsDesk\AI\Application
 */

namespace NewsDesk\AI\Application;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Contracts\JobQueueInterface;
use NewsDesk\AI\Application\Contracts\JobRepositoryInterface;
use NewsDesk\AI\Domain\Entity\Job;
use NewsDesk\AI\Domain\JobStateMachine;
use NewsDesk\AI\Logging\Contracts\LoggerInterface;
use NewsDesk\AI\Support\Random;
use NewsDesk\AI\Support\Time;

final class ManualActions {

	/** @var JobRepositoryInterface */
	private $jobs;
	/** @var JobQueueInterface */
	private $queue;
	/** @var LoggerInterface */
	private $logger;

	public function __construct( JobRepositoryInterface $jobs, JobQueueInterface $queue, LoggerInterface $logger ) {
		$this->jobs   = $jobs;
		$this->queue  = $queue;
		$this->logger = $logger;
	}

	/**
	 * Create + enqueue a job. Idempotent per key (no duplicate runs, §39).
	 *
	 * @return array{job_id: int|null, created: bool, message: string}
	 */
	public function runDiscovery( ?int $sourceId = null, bool $fullPipeline = false ): array {
		$type = $fullPipeline ? Job::TYPE_MANUAL_PIPELINE : Job::TYPE_MANUAL_DISCOVERY;
		$key  = 'manual-' . $type . '-' . gmdate( 'Ymd-His' ) . ( $sourceId ? '-' . $sourceId : '' );

		$existing = $this->jobs->findByKey( $key );
		if ( null !== $existing && ! $existing->isTerminal() ) {
			return array( 'job_id' => $existing->id, 'created' => false, 'message' => 'active' );
		}

		$job                = new Job();
		$job->jobKey        = $key;
		$job->type          = $type;
		$job->status        = JobStateMachine::QUEUED;
		$job->stage         = JobStateMachine::QUEUED;
		$job->priority      = 10;
		$job->scheduledAt   = Time::now();
		$job->correlationId = Random::uuid4();
		$job->createdAt     = Time::now();
		$job->updatedAt     = Time::now();
		$job->payload       = array(
			'source_id'    => $sourceId ?: 0,
			'force'        => true,
			'manual'       => true,
			'stage_truncated' => true,
		);

		$id = $this->jobs->insert( $job );
		if ( $id > 0 ) {
			$this->queue->enqueue( PipelineRunner::HOOK, array( $id ), 'nd-newsroom' );
			$this->logger->info( 'Manual job enqueued', array( 'job_id' => $id, 'type' => $type ), 'app.manual', 'JOB_ENQUEUED', $id, $job->correlationId );
			return array( 'job_id' => $id, 'created' => true, 'message' => 'ok' );
		}
		return array( 'job_id' => null, 'created' => false, 'message' => 'insert-failed' );
	}

	/**
	 * Retry a failed/review job — creates a NEW job (state machine stays pristine).
	 */
	public function retryJob( int $jobId ): array {
		$job = $this->jobs->find( $jobId );
		if ( null === $job ) {
			return array( 'job_id' => null, 'created' => false, 'message' => 'not-found' );
		}
		$key = 'retry-' . $jobId . '-' . gmdate( 'Ymd-His' );
		$new = new Job();
		$new->jobKey        = $key;
		$new->type          = Job::TYPE_MANUAL_PIPELINE === $job->type || Job::TYPE_PIPELINE === $job->type ? Job::TYPE_MANUAL_PIPELINE : Job::TYPE_MANUAL_DISCOVERY;
		$new->status        = JobStateMachine::QUEUED;
		$new->stage         = JobStateMachine::QUEUED;
		$new->priority      = 20;
		$new->scheduledAt   = Time::now();
		$new->correlationId = Random::uuid4();
		$new->createdAt     = Time::now();
		$new->updatedAt     = Time::now();
		$new->payload       = array_merge( $job->payload, array( 'retry_of' => $jobId, 'force' => true ) );

		$id = $this->jobs->insert( $new );
		if ( $id > 0 ) {
			$this->queue->enqueue( PipelineRunner::HOOK, array( $id ), 'nd-newsroom' );
			return array( 'job_id' => $id, 'created' => true, 'message' => 'ok' );
		}
		return array( 'job_id' => null, 'created' => false, 'message' => 'insert-failed' );
	}
}
