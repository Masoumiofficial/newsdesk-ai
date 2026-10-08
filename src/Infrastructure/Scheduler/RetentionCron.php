<?php
/**
 * Daily retention trigger.
 *
 * The retention_* settings had no runner in v1.6.0. This schedules one daily
 * WP-Cron event that calls RetentionService.
 *
 * @package NewsDesk\AI\Infrastructure\Scheduler
 */

namespace NewsDesk\AI\Infrastructure\Scheduler;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\RetentionService;
use NewsDesk\AI\Logging\Contracts\LoggerInterface;

final class RetentionCron {

	public const HOOK = 'newsdesk_newsroom_retention';

	/** @var RetentionService */
	private $retention;
	/** @var LoggerInterface */
	private $logger;

	public function __construct( RetentionService $retention, LoggerInterface $logger ) {
		$this->retention = $retention;
		$this->logger    = $logger;
	}

	public function register(): void {
		if ( function_exists( 'add_action' ) ) {
			add_action( self::HOOK, array( $this, 'run' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		}
	}

	public function schedule(): bool {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
			return false;
		}
		if ( wp_next_scheduled( self::HOOK ) ) {
			return false;
		}
		// Off-peak, and offset from the digest so the two never collide.
		return (bool) wp_schedule_event( time() + 3600, 'daily', self::HOOK ); // phpcs:ignore WordPress.WP.CronInterval
	}

	public function unschedule(): void {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_unschedule_event' ) ) {
			return;
		}
		$ts = wp_next_scheduled( self::HOOK );
		if ( $ts ) {
			wp_unschedule_event( (int) $ts, self::HOOK ); // phpcs:ignore WordPress.WP.CronInterval
		}
	}

	public function run(): void {
		try {
			$this->retention->run();
		} catch ( \Throwable $e ) {
			$this->logger->error(
				'Retention run failed',
				array( 'error' => $e->getMessage() ),
				'maintenance.retention',
				'RETENTION_FAILED'
			);
		}
	}
}
