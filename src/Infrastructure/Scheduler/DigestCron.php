<?php
/**
 * Daily digest trigger (WP-Cron: trigger only, one 'daily' event; the actual
 * run is lock-guarded + once-per-day in DigestService).
 *
 * @package NewsDesk\AI\Infrastructure\Scheduler
 */

namespace NewsDesk\AI\Infrastructure\Scheduler;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\DigestService;
use NewsDesk\AI\Application\NewsroomSettings;
use NewsDesk\AI\Logging\Contracts\LoggerInterface;
use NewsDesk\AI\Support\Time;

final class DigestCron {

	public const HOOK = 'newsdesk_newsroom_digest';

	/** @var DigestService */
	private $digest;
	/** @var NewsroomSettings */
	private $settings;
	/** @var LoggerInterface */
	private $logger;

	public function __construct( DigestService $digest, NewsroomSettings $settings, LoggerInterface $logger ) {
		$this->digest   = $digest;
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	public function register(): void {
		if ( function_exists( 'add_action' ) ) {
			add_action( self::HOOK, array( $this, 'maybeRun' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		}
	}

	/**
	 * (Re)schedule the daily event at the configured time (WP timezone).
	 */
	public function schedule(): bool {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
			return false;
		}
		if ( wp_next_scheduled( self::HOOK ) ) {
			return false;
		}
		$when = $this->nextOccurrence( $this->settings->digestTime() );
		return (bool) wp_schedule_event( $when, 'daily', self::HOOK ); // phpcs:ignore WordPress.WP.CronInterval
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

	public function maybeRun(): void {
		if ( ! $this->settings->digestEnabled() ) {
			return;
		}
		$result = $this->digest->run();
		if ( empty( $result['ran'] ) ) {
			$this->logger->debug(
				'Digest skipped',
				array( 'reason' => $result['reason'] ),
				'digest.cron',
				'DIGEST_SKIPPED'
			);
		}
	}

	/**
	 * Next occurrence of HH:MM in the WP timezone (today if still ahead, else tomorrow).
	 */
	private function nextOccurrence( string $time ): int {
		$now  = new \DateTimeImmutable( 'now', Time::wpTimezone() );
		$next = $now->setTime( (int) substr( $time, 0, 2 ), (int) substr( $time, 3, 2 ), 0 );
		if ( $next <= $now ) {
			$next = $next->modify( '+1 day' );
		}
		return $next->getTimestamp();
	}
}
