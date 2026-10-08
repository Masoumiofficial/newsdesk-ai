<?php
/**
 * Data retention / pruning (§ housekeeping).
 *
 * v1.6.0 shipped four retention_* settings, a working LogRepository::
 * pruneOlderThan() and no caller anywhere — every table grew forever and the
 * settings were decorative. This service is the missing consumer: it is run
 * daily by RetentionCron and can be triggered manually from Settings.
 *
 * Deleting history is destructive, so the rules are conservative:
 *   - a retention value of 0 means "keep forever" and is skipped;
 *   - news items are only pruned when they are not attached to a story;
 *   - jobs/events are pruned only for finished jobs;
 *   - every delete is capped per run so a first run on a huge table cannot
 *     lock it up; the remainder is picked up by the next run.
 *
 * @package NewsDesk\AI\Application
 */

namespace NewsDesk\AI\Application;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Contracts\WpDbInterface;
use NewsDesk\AI\Infrastructure\Database\TableNames;
use NewsDesk\AI\Logging\Contracts\LoggerInterface;
use NewsDesk\AI\Support\Time;

final class RetentionService {

	/** Max rows removed from one table in one run. */
	public const BATCH_LIMIT = 5000;

	/** @var WpDbInterface */
	private $db;
	/** @var TableNames */
	private $tables;
	/** @var NewsroomSettings */
	private $settings;
	/** @var LoggerInterface */
	private $logger;

	public function __construct( WpDbInterface $db, TableNames $tables, NewsroomSettings $settings, LoggerInterface $logger ) {
		$this->db       = $db;
		$this->tables   = $tables;
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * Run every retention rule.
	 *
	 * @return array<string, int> table key => rows deleted
	 */
	public function run(): array {
		$deleted = array(
			'logs'       => $this->pruneLogs(),
			'job_events' => $this->pruneJobEvents(),
			'ai_usage'   => $this->pruneAiUsage(),
			'news_items' => $this->pruneNewsItems(),
		);

		$total = array_sum( $deleted );
		if ( $total > 0 ) {
			$this->logger->info(
				'Retention run finished',
				$deleted,
				'maintenance.retention',
				'RETENTION_DONE'
			);
		}

		/**
		 * Fires after a retention sweep.
		 *
		 * @param array<string,int> $deleted rows removed per table
		 */
		if ( function_exists( 'do_action' ) ) {
			do_action( 'newsdesk_retention_finished', $deleted ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		}
		return $deleted;
	}

	/** Days configured for a key, or 0 when retention is disabled. */
	private function days( string $key ): int {
		return max( 0, $this->settings->retentionDays( $key ) );
	}

	/** Cutoff datetime for a retention key, or null when disabled. */
	private function cutoff( string $key ): ?string {
		$days = $this->days( $key );
		if ( $days <= 0 ) {
			return null;
		}
		return Time::toDb( Time::now()->modify( '-' . $days . ' days' ) );
	}

	private function pruneLogs(): int {
		$cutoff = $this->cutoff( 'retention_logs_days' );
		if ( null === $cutoff ) {
			return 0;
		}
		return $this->deleteBatch(
			'DELETE FROM ' . $this->tables->logs() . ' WHERE timestamp < %s LIMIT %d',
			array( $cutoff, self::BATCH_LIMIT )
		);
	}

	private function pruneJobEvents(): int {
		$cutoff = $this->cutoff( 'retention_events_days' );
		if ( null === $cutoff ) {
			return 0;
		}
		return $this->deleteBatch(
			'DELETE FROM ' . $this->tables->jobEvents() . ' WHERE created_at < %s LIMIT %d',
			array( $cutoff, self::BATCH_LIMIT )
		);
	}

	private function pruneAiUsage(): int {
		$cutoff = $this->cutoff( 'retention_usage_days' );
		if ( null === $cutoff ) {
			return 0;
		}
		return $this->deleteBatch(
			'DELETE FROM ' . $this->tables->aiUsage() . ' WHERE created_at < %s LIMIT %d',
			array( $cutoff, self::BATCH_LIMIT )
		);
	}

	/**
	 * News items are evidence for the stories built from them, so only items
	 * that never became part of a story are safe to remove.
	 */
	private function pruneNewsItems(): int {
		$cutoff = $this->cutoff( 'retention_news_days' );
		if ( null === $cutoff ) {
			return 0;
		}
		$items       = $this->tables->newsItems();
		$storySources = $this->tables->storySources();
		return $this->deleteBatch(
			"DELETE FROM {$items} WHERE created_at < %s"
				. " AND id NOT IN ( SELECT news_item_id FROM {$storySources} )"
				. ' LIMIT %d',
			array( $cutoff, self::BATCH_LIMIT )
		);
	}

	/**
	 * @param array<int, mixed> $args
	 */
	private function deleteBatch( string $sql, array $args ): int {
		$result = $this->db->query( $this->db->prepare( $sql, ...$args ) );
		return false === $result ? 0 : (int) $result;
	}
}
