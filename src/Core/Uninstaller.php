<?php
/**
 * Uninstall: KEEP_DATA by default; DELETE_ALL_DATA only when explicitly chosen (§58).
 *
 * @package NewsDesk\AI\Core
 */

namespace NewsDesk\AI\Core;

defined( 'ABSPATH' ) || exit;

final class Uninstaller {

	/**
	 * Called from uninstall.php. Only touches data when the admin explicitly opted in.
	 */
	public static function run(): void {
		$settings = get_option( 'newsdesk_settings', array() );
		$mode     = is_array( $settings ) ? ( $settings['uninstall_mode'] ?? 'keep_data' ) : 'keep_data';

		if ( 'delete_all' !== $mode ) {
			return; // KEEP_DATA (default)
		}

		global $wpdb; // phpcs:ignore
		$prefix = $wpdb->prefix; // phpcs:ignore
		// Complete list — must mirror Schema::definitions() (staging drill caught
		// two surviving tables: newsdesk_fact_checks + newsdesk_research_packages).
		$tables = array(
			$prefix . 'newsdesk_logs',
			$prefix . 'newsdesk_job_events',
			$prefix . 'newsdesk_ai_usage',
			$prefix . 'newsdesk_external_links',
			$prefix . 'newsdesk_internal_links',
			$prefix . 'newsdesk_research_claims',
			$prefix . 'newsdesk_research_packages',
			$prefix . 'newsdesk_fact_checks',
			$prefix . 'newsdesk_generated_images',
			$prefix . 'newsdesk_content_versions',
			$prefix . 'newsdesk_story_sources',
			$prefix . 'newsdesk_stories',
			$prefix . 'newsdesk_news_items',
			$prefix . 'newsdesk_sources',
			$prefix . 'newsdesk_prompt_versions',
			$prefix . 'newsdesk_jobs',
			$prefix . 'newsdesk_locks',
		);
		foreach ( $tables as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		// Option names are read from the owning class so a rename can never
		// leave an orphan row behind (v2.0 fix: the schedule-state key was
		// hard-coded with the wrong prefix and survived "delete all data").
		$options = array(
			\NewsDesk\AI\Application\NewsroomSettings::OPTION,
			\NewsDesk\AI\Infrastructure\Secrets\WordPressSecretStorage::OPTION,
			\NewsDesk\AI\Infrastructure\Scheduler\CronScheduler::STATE_OPTION,
			\NewsDesk\AI\Application\DigestService::LAST_OPTION,
			\NewsDesk\AI\Application\Content\CorrectionService::LOG_OPTION,
			'newsdesk_db_version',
			'newsdesk_migrations_applied',
		);
		foreach ( array_unique( $options ) as $option ) {
			delete_option( $option );
		}

		// Remove every cron hook we own. wp_clear_scheduled_hook() removes all
		// occurrences, not just the next one, so a duplicated event (possible
		// after a crashed activation) cannot survive uninstall.
		$hooks = array(
			\NewsDesk\AI\Infrastructure\Scheduler\CronScheduler::HOOK,
			\NewsDesk\AI\Infrastructure\Scheduler\DigestCron::HOOK,
			\NewsDesk\AI\Infrastructure\Scheduler\RetentionCron::HOOK,
		);
		foreach ( $hooks as $hook ) {
			if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
				wp_clear_scheduled_hook( $hook );
				continue;
			}
			$ts = wp_next_scheduled( $hook );
			if ( $ts ) {
				wp_unschedule_event( $ts, $hook );
			}
		}
	}
}
