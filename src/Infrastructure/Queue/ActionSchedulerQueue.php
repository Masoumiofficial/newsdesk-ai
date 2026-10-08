<?php
/**
 * Default queue backend: Action Scheduler (§38).
 *
 * @package NewsDesk\AI\Infrastructure\Queue
 */

namespace NewsDesk\AI\Infrastructure\Queue;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Contracts\JobQueueInterface;

final class ActionSchedulerQueue implements JobQueueInterface {

	public function enqueue( string $hook, array $args, string $group = 'nd-newsroom', ?int $delay = null ) {
		if ( null !== $delay && $delay > 0 && function_exists( 'as_schedule_single_action' ) ) {
			return as_schedule_single_action( time() + $delay, $hook, $args, $group );
		}
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			return as_enqueue_async_action( $hook, $args, $group );
		}
		return false;
	}

	public function isAvailable(): bool {
		return function_exists( 'as_enqueue_async_action' ) || class_exists( 'ActionScheduler' );
	}

	public function name(): string {
		return 'action-scheduler';
	}
}
