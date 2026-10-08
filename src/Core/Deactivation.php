<?php
/**
 * Deactivation: remove cron trigger only. Data is kept (§58 default KEEP_DATA).
 *
 * @package NewsDesk\AI\Core
 */

namespace NewsDesk\AI\Core;

defined( 'ABSPATH' ) || exit;

final class Deactivation {

	public static function deactivate(): void {
		$c         = Plugin::container();
		$scheduler = $c->get( \NewsDesk\AI\Infrastructure\Scheduler\CronScheduler::class );
		$scheduler->unscheduleTick();

		// v2.0: the digest event was scheduled on activation but never removed
		// here, leaving an orphan WP-Cron entry that fired on a deactivated
		// plugin. Both daily events are now torn down.
		$c->get( \NewsDesk\AI\Infrastructure\Scheduler\DigestCron::class )->unschedule();
		$c->get( \NewsDesk\AI\Infrastructure\Scheduler\RetentionCron::class )->unschedule();
	}
}
