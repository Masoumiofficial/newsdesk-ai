<?php
/**
 * Daily digest — read-only stats + one notification dispatch per day.
 *
 * Non-blocking by design (§66 rule applied to notifications):
 *   - DigestService::run() NEVER throws; failures are logged.
 *   - Lock 'daily_digest' prevents concurrent/duplicate runs.
 *   - Once-per-(UTC)-day guard (option), bypassable with $force (admin action).
 *
 * @package NewsDesk\AI\Application
 */

namespace NewsDesk\AI\Application;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Contracts\WpDbInterface;
use NewsDesk\AI\Application\Notifications\NotificationMessage;
use NewsDesk\AI\Application\Notifications\NotificationService;
use NewsDesk\AI\Infrastructure\Database\TableNames;
use NewsDesk\AI\Infrastructure\Locks\LockManager;
use NewsDesk\AI\Logging\Contracts\LoggerInterface;
use NewsDesk\AI\Support\Time;

final class DigestService {

	public const LOCK_ID    = 'daily_digest';
	public const LAST_OPTION = 'newsdesk_newsroom_digest_last';

	/** @var WpDbInterface */
	private $db;
	/** @var TableNames */
	private $tables;
	/** @var NotificationService */
	private $notifications;
	/** @var NewsroomSettings */
	private $settings;
	/** @var LockManager */
	private $locks;
	/** @var LoggerInterface */
	private $logger;

	public function __construct( WpDbInterface $db, TableNames $tables, NotificationService $notifications, NewsroomSettings $settings, LockManager $locks, LoggerInterface $logger ) {
		$this->db            = $db;
		$this->tables        = $tables;
		$this->notifications = $notifications;
		$this->settings      = $settings;
		$this->locks         = $locks;
		$this->logger        = $logger;
	}

	/**
	 * Build + dispatch the daily digest.
	 *
	 * @param bool $force bypass the once-per-day guard (admin manual run).
	 * @return array{ran: bool, reason: string, stats: array<string, int>|null, channels: array<string, bool>}
	 */
	public function run( bool $force = false ): array {
		if ( ! $this->settings->digestEnabled() && ! $force ) {
			return array( 'ran' => false, 'reason' => 'digest_disabled', 'stats' => null, 'channels' => array() );
		}
		if ( ! $force && $this->alreadyRanToday() ) {
			return array( 'ran' => false, 'reason' => 'already_ran_today', 'stats' => null, 'channels' => array() );
		}
		$owner = 'digest-' . Time::now()->format( 'Ymd-His' );
		if ( ! $this->locks->acquire( self::LOCK_ID, $owner ) ) {
			return array( 'ran' => false, 'reason' => 'lock_held', 'stats' => null, 'channels' => array() );
		}

		try {
			$stats  = $this->collectStats();
			$text   = $this->buildText( $stats );
			$title  = __( 'Newsroom daily digest', 'newsdesk-ai' );
			$result = $this->notifications->dispatch( NotificationMessage::digest( $title, $text ) );
			$this->markRanToday();
			$this->logger->info(
				'Daily digest dispatched',
				array( 'stats' => $stats, 'channels' => $result ),
				'digest.service',
				'DIGEST_DISPATCHED'
			);
			return array( 'ran' => true, 'reason' => '', 'stats' => $stats, 'channels' => $result );
		} catch ( \Throwable $e ) {
			$this->logger->error(
				'Digest failed (non-fatal)',
				array( 'error' => get_class( $e ) ),
				'digest.service',
				'DIGEST_FAILED'
			);
			return array( 'ran' => false, 'reason' => 'exception', 'stats' => null, 'channels' => array() );
		} finally {
			$this->locks->release( self::LOCK_ID, $owner );
		}
	}

	/**
	 * Read-only aggregates (plugin tables; prepared statements only).
	 *
	 * @return array<string, int>
	 */
	private function collectStats(): array {
		$dayAgo = Time::toDb( Time::now()->modify( '-24 hours' ) );

		$news24 = (int) $this->db->getVar(
			$this->db->prepare( 'SELECT COUNT(*) FROM ' . $this->tables->newsItems() . ' WHERE created_at >= %s', $dayAgo )
		);

		$statusCounts = array(
			'draft'       => 0,
			'needs_review' => 0,
			'approved'    => 0,
			'reviewed'    => 0,
			'rejected'    => 0,
		);
		$rows = $this->db->getResults(
			'SELECT content_status AS st, COUNT(*) AS n FROM ' . $this->tables->stories() . ' GROUP BY content_status'
		);
		foreach ( $rows as $row ) {
			$st = (string) ( $row['st'] ?? '' );
			if ( isset( $statusCounts[ $st ] ) ) {
				$statusCounts[ $st ] = (int) ( $row['n'] ?? 0 );
			}
		}

		$jobs = array( 'queued' => 0, 'running' => 0, 'completed' => 0, 'failed' => 0, 'needs_review' => 0 );
		$jobRows = $this->db->getResults(
			'SELECT status AS st, COUNT(*) AS n FROM ' . $this->tables->jobs() . ' GROUP BY status'
		);
		foreach ( $jobRows as $row ) {
			$st = strtolower( (string) ( $row['st'] ?? '' ) );
			if ( isset( $jobs[ $st ] ) ) {
				$jobs[ $st ] = (int) ( $row['n'] ?? 0 );
			}
		}

		return array_merge( $statusCounts, $jobs, array(
			'news_items_24h'   => $news24,
			'review_queue'     => $statusCounts['draft'] + $statusCounts['needs_review'],
			'reviewed_total'   => $statusCounts['reviewed'],
			'approved_total'   => $statusCounts['approved'],
		) );
	}

	/**
	 * @param array<string, int> $stats
	 */
	private function buildText( array $stats ): string {
		$url = function_exists( 'admin_url' )
			? admin_url( 'admin.php?page=nd-drafts' ) // phpcs:ignore
			: 'admin.php?page=nd-drafts';

		$lines   = array();
		$lines[] = sprintf( __( 'News items in the last 24 hours: %d', 'newsdesk-ai' ), $stats['news_items_24h'] );
		$lines[] = sprintf( __( 'In the review queue (draft / needs review): %d', 'newsdesk-ai' ), $stats['review_queue'] );
		$lines[] = sprintf( __( 'Approved for publishing: %d · Reviewed: %d', 'newsdesk-ai' ), $stats['approved_total'], $stats['reviewed_total'] );
		$lines[] = sprintf( __( 'Jobs: %d queued / %d running / %d complete / %d failed / %d needing review', 'newsdesk-ai' ), $stats['queued'], $stats['running'], $stats['completed'], $stats['failed'], $stats['needs_review'] );
		$lines[] = '';
		$lines[] = sprintf( __( 'Review page: %s', 'newsdesk-ai' ), $url );

		return implode( "\n", $lines );
	}

	private function alreadyRanToday(): bool {
		$last = function_exists( 'get_option' ) ? get_option( self::LAST_OPTION, array() ) : array();
		$date = is_array( $last ) ? (string) ( $last['date'] ?? '' ) : '';
		return '' !== $date && $date === gmdate( 'Y-m-d' );
	}

	private function markRanToday(): void {
		if ( function_exists( 'update_option' ) ) {
			update_option( self::LAST_OPTION, array(
				'date'  => gmdate( 'Y-m-d' ),
				'at'    => Time::toDb( Time::now() ),
			), false );
		}
	}
}
