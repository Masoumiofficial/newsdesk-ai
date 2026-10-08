<?php
/**
 * Activation: schema install + migrations + defaults + cron tick.
 *
 * @package NewsDesk\AI\Core
 */

namespace NewsDesk\AI\Core;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Contracts\WpDbInterface;
use NewsDesk\AI\Application\NewsroomSettings;
use NewsDesk\AI\Infrastructure\Database\Migrations;
use NewsDesk\AI\Infrastructure\Database\Schema;
use NewsDesk\AI\Infrastructure\Database\TableNames;
use NewsDesk\AI\Infrastructure\Seeds\PromptSeeder;

final class Activation {

	public static function activate(): void {
		global $wpdb; // phpcs:ignore
		$c      = Plugin::container();
		$db     = $c->get( WpDbInterface::class );
		$tables = $c->get( TableNames::class );

		// 1) Base schema (idempotent dbDelta).
		Schema::install( $db );

		// 2) Versioned migrations.
		$current = get_option( 'newsdesk_db_version', '1.0.0' );
		$applied = Migrations::applyPending( $db, $tables, $current );
		update_option( 'newsdesk_db_version', NEWSDESK_DB_VERSION, false );
		if ( $applied ) {
			// Merge: never lose applied-version history across re-activations.
			$history  = (array) get_option( 'newsdesk_migrations_applied', array() );
			$combined = array_values( array_unique( array_merge( $history, $applied ) ) );
			sort( $combined, SORT_STRING );
			update_option( 'newsdesk_migrations_applied', $combined, false );
		}
		// §77: never silent — a partially upgraded schema is logged, and the
		// failed versions are re-attempted on the next activation.
		if ( ! empty( Migrations::$lastFailures ) ) {
			$logger = $c->get( \NewsDesk\AI\Logging\Contracts\LoggerInterface::class );
			$logger->warning(
				'Migration statements failed — schema partially upgraded; versions will be retried on next activation',
				array( 'failures' => Migrations::$lastFailures ),
				'install.migrations',
				'MIGRATION_PARTIAL'
			);
		}

		// 3) Default settings (never overwrite an existing configuration).
		if ( false === get_option( NewsroomSettings::OPTION, false ) ) {
			add_option( NewsroomSettings::OPTION, NewsroomSettings::defaults(), '', false );
		}

		// 4) Cron triggers (15-min tick; slots evaluated on tick) + daily digest.
		$scheduler = $c->get( \NewsDesk\AI\Infrastructure\Scheduler\CronScheduler::class );
		$scheduler->register();
		$scheduler->scheduleTick();

		$digestCron = $c->get( \NewsDesk\AI\Infrastructure\Scheduler\DigestCron::class );
		$digestCron->register();
		$digestCron->schedule();

		// Daily retention sweep (v2.0): the retention_* settings are enforced.
		$retentionCron = $c->get( \NewsDesk\AI\Infrastructure\Scheduler\RetentionCron::class );
		$retentionCron->register();
		$retentionCron->schedule();

		// 4b) Seed built-in prompt versions (idempotent; never overwrites §24).

		$seeder = $c->get( \NewsDesk\AI\Infrastructure\Seeds\PromptSeeder::class );
		$seeder->seed();

		// 5) Clean stale action-scheduler entries on (re)activation is handled by AS itself.
	}
}
