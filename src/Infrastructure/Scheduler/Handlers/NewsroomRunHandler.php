<?php
/**
 * Action Scheduler job body — thin wrapper over PipelineRunner.
 *
 * @package NewsDesk\AI\Infrastructure\Scheduler\Handlers
 */

namespace NewsDesk\AI\Infrastructure\Scheduler\Handlers;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\PipelineRunner;

final class NewsroomRunHandler {

	/** @var PipelineRunner */
	private $runner;

	public function __construct( PipelineRunner $runner ) {
		$this->runner = $runner;
	}

	/**
	 * @param mixed $jobId
	 */
	public function handle( $jobId ): void {
		$this->runner->runJob( (int) $jobId );
	}
}
