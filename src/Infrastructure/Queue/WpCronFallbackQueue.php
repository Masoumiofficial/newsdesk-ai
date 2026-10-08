<?php
/**
 * Fallback queue when Action Scheduler is absent (WARNING logged at selection time).
 *
 * @package NewsDesk\AI\Infrastructure\Queue
 */

namespace NewsDesk\AI\Infrastructure\Queue;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Contracts\JobQueueInterface;

final class WpCronFallbackQueue implements JobQueueInterface {

	public function enqueue( string $hook, array $args, string $group = 'nd-newsroom', ?int $delay = null ) {
		if ( ! function_exists( 'wp_schedule_single_event' ) ) {
			return false;
		}
		$when = null === $delay ? time() : time() + max( 0, $delay );
		return wp_schedule_single_event( $when, $hook, $args );
	}

	public function isAvailable(): bool {
		return function_exists( 'wp_schedule_single_event' );
	}

	public function name(): string {
		return 'wp-cron-fallback';
	}
}
