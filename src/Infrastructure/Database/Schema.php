<?php
/**
 * Base schema installer (dbDelta) — §43.
 *
 * @package NewsDesk\AI\Infrastructure\Database
 */

namespace NewsDesk\AI\Infrastructure\Database;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Contracts\WpDbInterface;

final class Schema {

	/**
	 * Create all plugin tables via dbDelta. Idempotent.
	 *
	 * @throws \RuntimeException When not running inside WordPress.
	 */
	public static function install( WpDbInterface $db ): array {
		if ( ! function_exists( 'dbDelta' ) ) {
			$wpAdmin = ABSPATH . 'wp-admin/includes/upgrade.php'; // phpcs:ignore
			if ( is_readable( $wpAdmin ) ) {
				require_once $wpAdmin; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingCustomFunction
			}
		}
		if ( ! function_exists( 'dbDelta' ) ) {
			throw new \RuntimeException( 'Schema::install requires WordPress (dbDelta missing).' );
		}

		$created = array();
		foreach ( self::definitions( $db->prefix(), $db->charsetCollate() ) as $name => $sql ) {
			dbDelta( $sql ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.database_deltas_dbDelta
			$created[] = $name;
		}
		return $created;
	}

	/**
	 * Table definitions. NOTE: prefix-index migrations live in Migrations (dbDelta strips lengths).
	 *
	 * @return array<string, string>
	 */
	public static function definitions( string $prefix, string $charsetCollate ): array {
		$tables = array();

		$tables['sources'] = "CREATE TABLE {$prefix}newsdesk_sources (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL,
			type varchar(20) NOT NULL DEFAULT 'rss',
			url varchar(255) DEFAULT NULL,
			feed_url varchar(1000) DEFAULT NULL,
			language varchar(20) DEFAULT NULL,
			category varchar(50) DEFAULT NULL,
			priority int(11) NOT NULL DEFAULT 50,
			base_trust_score decimal(5,2) NOT NULL DEFAULT 50.00,
			trust_override tinyint(1) NOT NULL DEFAULT 0,
			trust_score decimal(5,2) NOT NULL DEFAULT 50.00,
			active tinyint(1) NOT NULL DEFAULT 1,
			status varchar(20) NOT NULL DEFAULT 'active',
			fetch_interval_min int(11) NOT NULL DEFAULT 240,
			last_fetch_at datetime DEFAULT NULL,
			last_success_at datetime DEFAULT NULL,
			last_error_at datetime DEFAULT NULL,
			last_error_message text,
			settings longtext,
			created_at datetime DEFAULT NULL,
			updated_at datetime DEFAULT NULL,
			source_type varchar(20) NOT NULL DEFAULT 'MEDIA',
			tier tinyint(1) NOT NULL DEFAULT 3,
			PRIMARY KEY  (id),
			KEY type_tier (source_type, tier),
			KEY active_priority (active, priority),
			KEY status (status),
			KEY type (type),
			KEY feed_url (feed_url(191))
		) $charsetCollate;";

		$tables['news_items'] = "CREATE TABLE {$prefix}newsdesk_news_items (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_id bigint(20) unsigned NOT NULL,
			guid varchar(191) NOT NULL DEFAULT '',
			canonical_url varchar(1000) NOT NULL,
			content_hash char(64) NOT NULL DEFAULT '',
			title text NOT NULL,
			normalized_title varchar(500) NOT NULL DEFAULT '',
			excerpt varchar(500) NOT NULL DEFAULT '',
			content_text longtext,
			author varchar(191) NOT NULL DEFAULT '',
			categories text,
			language varchar(20) NOT NULL DEFAULT '',
			published_at datetime DEFAULT NULL,
			fetched_at datetime DEFAULT NULL,
			created_at datetime DEFAULT NULL,
			last_seen_at datetime DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'new',
			dedup_confirmed_at datetime DEFAULT NULL,
			duplicate_of_id bigint(20) unsigned DEFAULT NULL,
			duplicate_level varchar(20) DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY source_guid (source_id, guid(191)),
			KEY canonical (canonical_url(191)),
			KEY hash (content_hash),
			KEY published (published_at),
			KEY status (status),
			KEY source_seen (source_id, last_seen_at),
			KEY duplicate_of (duplicate_of_id)
		) $charsetCollate;";

		$tables['stories'] = "CREATE TABLE {$prefix}newsdesk_stories (
			story_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			canonical_title varchar(500) NOT NULL,
			primary_source_id bigint(20) unsigned NOT NULL DEFAULT 0,
			secondary_sources text,
			first_published_at datetime DEFAULT NULL,
			last_updated_at datetime DEFAULT NULL,
			importance_score decimal(5,2) NOT NULL DEFAULT 0,
			confidence_score decimal(5,2) NOT NULL DEFAULT 0,
			cluster_key varchar(191) NOT NULL DEFAULT '',
			language varchar(20) NOT NULL DEFAULT 'en_US',
			item_count int(11) NOT NULL DEFAULT 0,
			source_count int(11) NOT NULL DEFAULT 0,
			topics text,
			item_ids text,
			trust_score decimal(5,2) NOT NULL DEFAULT 0,
			freshness_score decimal(5,2) NOT NULL DEFAULT 0,
			impact_score decimal(5,2) NOT NULL DEFAULT 0,
			editorial_score decimal(5,2) NOT NULL DEFAULT 0,
			seo_aeo_geo_score decimal(5,2) NOT NULL DEFAULT 0,
			window_key varchar(40) NOT NULL DEFAULT '',
			selected_at datetime DEFAULT NULL,
			selection_reason text,
			aeo_signals text,
			status varchar(20) NOT NULL DEFAULT 'candidate',
			editorial_decision varchar(40) DEFAULT NULL,
			editorial_strategy varchar(40) DEFAULT NULL,
			outcome varchar(40) DEFAULT NULL,
			existing_article_id bigint(20) unsigned DEFAULT NULL,
			run_job_id bigint(20) unsigned DEFAULT NULL,
			research_status varchar(20) NOT NULL DEFAULT '',
			fact_check_status varchar(30) NOT NULL DEFAULT '',
			evidence_count int(11) NOT NULL DEFAULT 0,
			verified_claim_count int(11) NOT NULL DEFAULT 0,
			contradiction_count int(11) NOT NULL DEFAULT 0,
			content_status varchar(20) NOT NULL DEFAULT '',
			is_security tinyint(1) NOT NULL DEFAULT 0,
			cve_ids text,
			cvss_score decimal(3,1) DEFAULT NULL,
			severity varchar(10) NOT NULL DEFAULT '',
			affected_versions text,
			fixed_versions text,
			exploited tinyint(1) NOT NULL DEFAULT 0,
			vendor_advisory varchar(500) NOT NULL DEFAULT '',
			created_at datetime DEFAULT NULL,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY  (story_id),
			KEY status_score (status, importance_score),
			KEY security (is_security, severity),
			KEY title (canonical_title(191)),
			KEY primary_src (primary_source_id),
			KEY created (created_at),
			KEY cluster_key (cluster_key),
			KEY window_lookup (window_key, status)
		) $charsetCollate;";

		$tables['story_sources'] = "CREATE TABLE {$prefix}newsdesk_story_sources (
			story_id bigint(20) unsigned NOT NULL,
			source_id bigint(20) unsigned NOT NULL,
			role varchar(10) NOT NULL DEFAULT 'secondary',
			first_seen_via_job_id bigint(20) unsigned DEFAULT NULL,
			added_at datetime DEFAULT NULL,
			PRIMARY KEY  (story_id, source_id),
			KEY by_source (source_id)
		) $charsetCollate;";

		$tables['jobs'] = "CREATE TABLE {$prefix}newsdesk_jobs (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_key varchar(191) NOT NULL,
			type varchar(30) NOT NULL DEFAULT 'pipeline',
			status varchar(30) NOT NULL DEFAULT 'QUEUED',
			stage varchar(30) NOT NULL DEFAULT 'QUEUED',
			state_payload longtext,
			priority int(11) NOT NULL DEFAULT 10,
			scheduled_at datetime DEFAULT NULL,
			started_at datetime DEFAULT NULL,
			finished_at datetime DEFAULT NULL,
			attempts int(11) NOT NULL DEFAULT 0,
			max_attempts int(11) NOT NULL DEFAULT 3,
			retry_target varchar(30) DEFAULT NULL,
			error_code varchar(100) DEFAULT NULL,
			error_message text,
			correlation_id char(36) NOT NULL DEFAULT '',
			created_at datetime DEFAULT NULL,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY job_key (job_key(191)),
			KEY status_created (status, created_at),
			KEY type (type)
		) $charsetCollate;";

		$tables['job_events'] = "CREATE TABLE {$prefix}newsdesk_job_events (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_id bigint(20) unsigned NOT NULL,
			seq int(11) NOT NULL DEFAULT 1,
			event varchar(60) NOT NULL DEFAULT '',
			state_before varchar(30) DEFAULT NULL,
			state_after varchar(30) DEFAULT NULL,
			message text,
			context text,
			correlation_id char(36) NOT NULL DEFAULT '',
			created_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY job_seq (job_id, seq),
			KEY created (created_at),
			KEY corr (correlation_id)
		) $charsetCollate;";

		$tables['research_claims'] = "CREATE TABLE {$prefix}newsdesk_research_claims (
			claim_id char(36) NOT NULL,
			story_id bigint(20) unsigned NOT NULL DEFAULT 0,
			job_id bigint(20) unsigned DEFAULT NULL,
			item_id bigint(20) unsigned DEFAULT NULL,
			claim_text text NOT NULL,
			claim_type varchar(30) NOT NULL DEFAULT 'fact',
			source_id bigint(20) unsigned DEFAULT NULL,
			source_url varchar(1000) DEFAULT NULL,
			support_snippet text,
			snippet_hash char(64) DEFAULT NULL,
			evidence_type varchar(40) DEFAULT NULL,
			published_at datetime DEFAULT NULL,
			retrieved_at datetime DEFAULT NULL,
			confidence decimal(4,3) NOT NULL DEFAULT 0,
			verification_status varchar(30) NOT NULL DEFAULT 'UNVERIFIED',
			verified_at datetime DEFAULT NULL,
			risk varchar(10) NOT NULL DEFAULT 'MEDIUM',
			action varchar(20) NOT NULL DEFAULT 'KEEP',
			reported_by varchar(10) NOT NULL DEFAULT 'rules',
			provider varchar(60) DEFAULT NULL,
			model varchar(120) DEFAULT NULL,
			created_at datetime DEFAULT NULL,
			PRIMARY KEY  (claim_id),
			KEY story (story_id),
			KEY job (job_id),
			KEY status (verification_status),
			KEY risk_action (risk, action),
			UNIQUE KEY story_snippet (story_id, snippet_hash)
		) $charsetCollate;";

		$tables['content_versions'] = "CREATE TABLE {$prefix}newsdesk_content_versions (
			version_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			story_id bigint(20) unsigned NOT NULL DEFAULT 0,
			job_id bigint(20) unsigned DEFAULT NULL,
			draft_post_id bigint(20) unsigned DEFAULT NULL,
			version_no int(11) NOT NULL DEFAULT 1,
			content_json longtext,
			content_hash char(40) DEFAULT NULL,
			provider varchar(60) DEFAULT NULL,
			model varchar(120) DEFAULT NULL,
			prompt_version varchar(80) DEFAULT NULL,
			quality_score decimal(5,2) DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'draft',
			error_code varchar(100) DEFAULT NULL,
			created_at datetime DEFAULT NULL,
			PRIMARY KEY  (version_id),
			KEY story (story_id, version_no),
			KEY job (job_id),
			KEY draft (draft_post_id)
		) $charsetCollate;";

		$tables['generated_images'] = "CREATE TABLE {$prefix}newsdesk_generated_images (
			image_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			story_id bigint(20) unsigned DEFAULT NULL,
			job_id bigint(20) unsigned DEFAULT NULL,
			provider varchar(60) DEFAULT NULL,
			provider_job_ref varchar(191) DEFAULT NULL,
			prompt text,
			prompt_version varchar(80) DEFAULT NULL,
			attachment_id bigint(20) unsigned DEFAULT NULL,
			media_url varchar(1000) DEFAULT NULL,
			width int(11) DEFAULT NULL,
			height int(11) DEFAULT NULL,
			size_bytes bigint(20) DEFAULT NULL,
			alt_text varchar(500) DEFAULT NULL,
			has_brand_composition tinyint(1) NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'queued',
			error_code varchar(100) DEFAULT NULL,
			created_at datetime DEFAULT NULL,
			PRIMARY KEY  (image_id),
			KEY story (story_id),
			KEY job (job_id),
			KEY status (status)
		) $charsetCollate;";

		$tables['research_packages'] = "CREATE TABLE {$prefix}newsdesk_research_packages (
			research_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			story_id bigint(20) unsigned NOT NULL DEFAULT 0,
			job_id bigint(20) unsigned DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			provider varchar(60) DEFAULT NULL,
			model varchar(120) DEFAULT NULL,
			prompt_version varchar(80) DEFAULT NULL,
			schema_version varchar(40) DEFAULT NULL,
			brief_json longtext,
			confidence decimal(4,3) NOT NULL DEFAULT 0,
			contradictions text,
			started_at datetime DEFAULT NULL,
			completed_at datetime DEFAULT NULL,
			error_code varchar(100) DEFAULT NULL,
			created_at datetime DEFAULT NULL,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY  (research_id),
			KEY story (story_id),
			KEY job (job_id),
			KEY status (status)
		) $charsetCollate;";

		$tables['fact_checks'] = "CREATE TABLE {$prefix}newsdesk_fact_checks (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			claim_id char(36) NOT NULL,
			story_id bigint(20) unsigned NOT NULL DEFAULT 0,
			job_id bigint(20) unsigned DEFAULT NULL,
			method varchar(30) NOT NULL DEFAULT 'cross_source',
			provider varchar(60) DEFAULT NULL,
			model varchar(120) DEFAULT NULL,
			verdict varchar(30) NOT NULL DEFAULT 'INSUFFICIENT',
			rationale text,
			checked_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY claim (claim_id),
			KEY story (story_id),
			KEY job (job_id)
		) $charsetCollate;";

		$tables['ai_usage'] = "CREATE TABLE {$prefix}newsdesk_ai_usage (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			provider varchar(60) NOT NULL DEFAULT '',
			model varchar(120) NOT NULL DEFAULT '',
			job_id bigint(20) unsigned DEFAULT NULL,
			request_id char(36) NOT NULL DEFAULT '',
			component varchar(60) DEFAULT NULL,
			input_usage int(11) NOT NULL DEFAULT 0,
			output_usage int(11) NOT NULL DEFAULT 0,
			estimated_cost decimal(12,6) NOT NULL DEFAULT 0,
			currency char(3) NOT NULL DEFAULT 'USD',
			created_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY job (job_id),
			KEY created (created_at, provider, model)
		) $charsetCollate;";

		$tables['prompt_versions'] = "CREATE TABLE {$prefix}newsdesk_prompt_versions (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			prompt_id varchar(80) NOT NULL,
			version int(11) NOT NULL DEFAULT 1,
			type varchar(20) NOT NULL DEFAULT 'writing',
			language varchar(10) NOT NULL DEFAULT 'fa',
			content longtext NOT NULL,
			provider_tested_on varchar(60) DEFAULT NULL,
			model_tested_on varchar(120) DEFAULT NULL,
			active tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime DEFAULT NULL,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY prompt (prompt_id, version),
			KEY active (prompt_id, active)
		) $charsetCollate;";

		$tables['internal_links'] = "CREATE TABLE {$prefix}newsdesk_internal_links (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			target_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			anchor varchar(191) DEFAULT NULL,
			placement varchar(20) DEFAULT NULL,
			confidence decimal(4,3) NOT NULL DEFAULT 0,
			reason text,
			status varchar(20) NOT NULL DEFAULT 'suggested',
			created_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY src (source_post_id),
			KEY tgt (target_post_id),
			KEY status (status)
		) $charsetCollate;";

		$tables['external_links'] = "CREATE TABLE {$prefix}newsdesk_external_links (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			claim_id char(36) NOT NULL DEFAULT '',
			source_id bigint(20) unsigned DEFAULT NULL,
			url varchar(1000) NOT NULL,
			anchor varchar(191) DEFAULT NULL,
			reason text,
			priority varchar(10) NOT NULL DEFAULT 'secondary',
			created_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY claim (claim_id),
			KEY source (source_id),
			KEY url (url(191))
		) $charsetCollate;";

		$tables['logs'] = "CREATE TABLE {$prefix}newsdesk_logs (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			timestamp datetime DEFAULT NULL,
			level varchar(10) NOT NULL DEFAULT 'info',
			component varchar(60) DEFAULT NULL,
			event varchar(80) DEFAULT NULL,
			job_id bigint(20) unsigned DEFAULT NULL,
			correlation_id char(36) NOT NULL DEFAULT '',
			message text,
			context text,
			created_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY level_time (level, timestamp),
			KEY job (job_id),
			KEY corr (correlation_id)
		) $charsetCollate;";

		$tables['locks'] = "CREATE TABLE {$prefix}newsdesk_locks (
			lock_id varchar(64) NOT NULL,
			owner varchar(64) NOT NULL,
			job_id bigint(20) unsigned DEFAULT NULL,
			acquired_at bigint(20) NOT NULL DEFAULT 0,
			expires_at bigint(20) NOT NULL DEFAULT 0,
			heartbeat_at bigint(20) NOT NULL DEFAULT 0,
			PRIMARY KEY  (lock_id),
			KEY expires (expires_at),
			KEY job (job_id)
		) $charsetCollate;";

		return $tables;
	}
}
