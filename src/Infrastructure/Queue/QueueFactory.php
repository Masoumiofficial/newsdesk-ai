<?php
namespace NewsDesk\AI\Infrastructure\Queue;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Contracts\JobQueueInterface;
use NewsDesk\AI\Logging\Contracts\LoggerInterface;

final class QueueFactory {

	public static function make( LoggerInterface $logger ): JobQueueInterface {
		$as = new ActionSchedulerQueue();
		if ( $as->isAvailable() ) {
			return $as;
		}
		$logger->warning(
			'Action Scheduler is not available; falling back to WP-Cron single events. Install Action Scheduler for reliable background jobs.',
			array(),
			'queue.factory',
			'QUEUE_FALLBACK'
		);
		return new WpCronFallbackQueue();
	}
}
