<?php
/**
 * Plugin settings (single option row), typed getters, sanitized writes.
 *
 * @package NewsDesk\AI\Application
 */

namespace NewsDesk\AI\Application;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Support\Arr;
use NewsDesk\AI\Support\Locale;

final class NewsroomSettings {

	public const OPTION = 'newsdesk_settings';

	public const DEFAULT_SLOTS = array( '00:00', '04:00', '08:00', '12:00', '16:00', '20:00' );

	/** @var array<string, mixed> */
	private $data;

	/**
	 * @param array<string, mixed>|null $data Override data (tests).
	 */
	public function __construct( ?array $data = null ) {
		$this->data = null !== $data ? $data : $this->load();
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'schedule_times'          => self::DEFAULT_SLOTS,
			// Spec ladder rung 1 = 24h (v1.6.0 shipped 6 and never read it).
			'editorial_window_hours'  => 24,
			'fallback_window_days'    => 7,
			'fetch_timeout'           => 20,
			'max_response_kb'         => 2048,
			'max_items_per_source'    => 50,
			'log_level'               => 'info',
			'capability'              => 'manage_options',
			'secret_storage'          => 'wordpress',
			'default_category_id'     => 0,
			'default_author_id'       => 0,
			'uninstall_mode'          => 'keep_data',
			'retention_news_days'     => 60,
			'retention_logs_days'     => 90,
			'retention_events_days'   => 90,
			'retention_usage_days'    => 180,
			'language'                => Locale::locale(),
			'numbers_format'          => 'latin', // latin | persian
			'quick_start'             => false, // v1.3: relaxed gates for the first drafts
			'max_stories_per_window'  => 3,
			'selection_quality_gate'  => 60,
			'selection_min_trust'     => 40,
			// Phase 3 — AI (§18, §22): models are admin-overridable; the defaults
			// come from the vendor's published catalog (ARCHITECTURE.md).
			'ai_provider_order'       => 'gapgpt,openai,gemini',
			'ai_model_gapgpt'         => 'gpt-4o',
			'ai_model_openai'         => 'gpt-4o-mini',
			'ai_model_gemini'         => 'gemini-2.5-flash',
			'ai_temperature'          => 0.3,
			'ai_max_tokens'           => 2048,
			'ai_timeout'              => 60,
			'ai_budget_per_job'       => 0, // 0 = disabled
			'ai_currency'             => 'USD',
			'ai_gapgpt_alt_cdn'       => false,
			'ai_fact_check_enabled'   => true,
			// Phase 4 — content (§36): quality gate 90, max 2 revisions, then NEEDS_REVIEW.
			'content_quality_gate'    => 90,
			'content_max_revisions'   => 2,
			'content_include_links'   => true,
			'content_language'        => 'fa', // v1.5: fa|en|ar|source
			'content_generate_enabled'=> true,
			// Phase 5 — images (§66): optional stage; API failure never fails the job.
			// Default model/size are the vendor's official example values (ARCHITECTURE.md §9.3),
			// admin-overridable; the full model catalog is resolved from /v1/models (Phase 6).
			// A-5: the spec's image phase is PLAN-ONLY. Real generation stays
			// available but must be opted into; the plan is always produced.
			'image_generate_enabled'  => false,
			'image_model'             => 'gpt-image-2',
			'image_size'              => '1024x1024',
			// Phase 6 — cost estimation (§22): price table per model (per 1M tokens)
			// and per-image price. Admin-configurable, never guessed in code.
			'ai_price_table'          => '{}',
			'ai_price_image'          => 0.0,
			// Phase 8 — notifications & daily digest: channels/events are
			// admin-chosen; secrets (webhook URL/secret, Telegram token,
			// Slack URL) live in SecretStorage (§23) — never here; AUTO_PUBLISH
			// stays OFF (§73); digest is cron-triggered, non-blocking.
			'notification_enabled'    => false,
			'notification_channels'   => 'webhook,email,telegram,slack',
			'notification_events'     => 'content_ready,digest',
			'notification_email_recipients' => '',
			'telegram_chat_id'        => '',
			'digest_enabled'          => false,
			'digest_time'             => '08:00',
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function load(): array {
		$raw = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $raw ) ? $raw : array() );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function all(): array {
		return $this->data;
	}

	public function save(): bool {
		return update_option( self::OPTION, $this->data, false );
	}

	/**
	 * Sanitize + validate raw input.
	 *
	 * @return array{data: array<string, mixed>, errors: array<string, string>}
	 */
	public static function sanitize( array $input ): array {
		$errors = array();
		$out    = self::defaults();

		$times = array();
		foreach ( (array) ( $input['schedule_times'] ?? array() ) as $t ) {
			$t = sanitize_text_field( (string) $t );
			if ( preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $t ) ) {
				$times[] = $t;
			}
		}
		if ( empty( $times ) ) {
			$errors['schedule_times'] = __( 'At least one valid time (HH:MM) is required.', 'newsdesk-ai' );
		}
		$out['schedule_times'] = $times ? $times : self::DEFAULT_SLOTS;

		foreach ( array( 'editorial_window_hours', 'fallback_window_days', 'fetch_timeout', 'max_items_per_source' ) as $intKey ) {
			$out[ $intKey ] = isset( $input[ $intKey ] ) ? max( 1, absint( $input[ $intKey ] ) ) : self::defaults()[ $intKey ];
		}
		$out['max_response_kb'] = isset( $input['max_response_kb'] ) ? min( 10240, max( 64, absint( $input['max_response_kb'] ) ) ) : self::defaults()['max_response_kb'];

		$logLevel = sanitize_key( (string) ( $input['log_level'] ?? 'info' ) );
		$out['log_level'] = isset( \NewsDesk\AI\Logging\Logger::LEVELS[ $logLevel ] ) ? $logLevel : 'info';

		$secretType = sanitize_key( (string) ( $input['secret_storage'] ?? 'wordpress' ) );
		$out['secret_storage'] = in_array( $secretType, \NewsDesk\AI\Infrastructure\Secrets\SecretFactory::TYPES, true ) ? $secretType : 'wordpress';

		$out['default_category_id'] = isset( $input['default_category_id'] ) ? absint( $input['default_category_id'] ) : 0;
		$out['default_author_id']   = isset( $input['default_author_id'] ) ? absint( $input['default_author_id'] ) : 0;

		foreach ( array( 'retention_news_days', 'retention_logs_days', 'retention_events_days', 'retention_usage_days' ) as $rKey ) {
			$out[ $rKey ] = isset( $input[ $rKey ] ) ? max( 1, absint( $input[ $rKey ] ) ) : self::defaults()[ $rKey ];
		}

		$out['quick_start'] = ! empty( $input['quick_start'] );
		$out['max_stories_per_window'] = isset( $input['max_stories_per_window'] ) ? max( 1, min( 20, absint( $input['max_stories_per_window'] ) ) ) : 3;
		$out['selection_quality_gate'] = isset( $input['selection_quality_gate'] ) ? max( 0, min( 100, absint( $input['selection_quality_gate'] ) ) ) : 60;
		$out['selection_min_trust']    = isset( $input['selection_min_trust'] ) ? max( 0, min( 100, absint( $input['selection_min_trust'] ) ) ) : 40;
		$out['language'] = sanitize_text_field( (string) ( $input['language'] ?? Locale::locale() ) );

		// Phase 3 — AI (keys are NEVER stored here; they live in SecretStorage §23).
		$order = isset( $input['ai_provider_order'] ) ? sanitize_text_field( (string) $input['ai_provider_order'] ) : 'gapgpt,openai,gemini';
		$ids   = array();
		foreach ( explode( ',', $order ) as $id ) {
			$id = trim( strtolower( sanitize_key( $id ) ) );
			if ( in_array( $id, array( 'gapgpt', 'openai', 'gemini' ), true ) && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}
		$out['ai_provider_order'] = implode( ',', $ids );
		$out['ai_model_gapgpt']   = sanitize_text_field( (string) ( $input['ai_model_gapgpt'] ?? 'gpt-4o' ) );
		$out['ai_model_openai']   = sanitize_text_field( (string) ( $input['ai_model_openai'] ?? 'gpt-4o-mini' ) );
		$out['ai_model_gemini']   = sanitize_text_field( (string) ( $input['ai_model_gemini'] ?? 'gemini-2.5-flash' ) );
		$out['ai_temperature']    = isset( $input['ai_temperature'] ) ? max( 0.0, min( 2.0, (float) $input['ai_temperature'] ) ) : 0.3;
		$out['ai_max_tokens']     = isset( $input['ai_max_tokens'] ) ? max( 128, min( 8192, absint( $input['ai_max_tokens'] ) ) ) : 2048;
		$out['ai_timeout']        = isset( $input['ai_timeout'] ) ? max( 10, min( 300, absint( $input['ai_timeout'] ) ) ) : 60;
		$out['ai_budget_per_job'] = isset( $input['ai_budget_per_job'] ) ? max( 0, absint( $input['ai_budget_per_job'] ) ) : 0;
		$out['ai_currency']       = sanitize_text_field( (string) ( $input['ai_currency'] ?? 'USD' ) );
		$out['ai_gapgpt_alt_cdn'] = ! empty( $input['ai_gapgpt_alt_cdn'] );
		$out['ai_fact_check_enabled'] = ! empty( $input['ai_fact_check_enabled'] );
		$numbersFormat   = (string) ( $input['numbers_format'] ?? 'latin' );
		$out['content_quality_gate'] = isset( $input['content_quality_gate'] ) ? max( 0, min( 100, absint( $input['content_quality_gate'] ) ) ) : 90;
		$out['content_max_revisions'] = isset( $input['content_max_revisions'] ) ? max( 1, min( 5, absint( $input['content_max_revisions'] ) ) ) : 2;
		$out['content_include_links'] = ! empty( $input['content_include_links'] );
		$lang = sanitize_key( (string) ( $input['content_language'] ?? Locale::lang() ) );
		$out['content_language'] = in_array( $lang, array( 'fa', 'en', 'ar', 'source' ), true ) ? $lang : 'fa';
		$out['content_generate_enabled'] = ! empty( $input['content_generate_enabled'] );
		$out['image_generate_enabled'] = ! empty( $input['image_generate_enabled'] );
		$out['image_model'] = isset( $input['image_model'] ) ? sanitize_text_field( (string) $input['image_model'] ) : 'gpt-image-2';
		if ( '' === trim( $out['image_model'] ) ) {
			$out['image_model'] = 'gpt-image-2';
		}
		$size = isset( $input['image_size'] ) ? sanitize_text_field( (string) $input['image_size'] ) : '1024x1024';
		if ( preg_match( '/^\d{2,4}x\d{2,4}$/', $size ) ) {
			$out['image_size'] = $size;
		} else {
			$out['image_size'] = '1024x1024';
		}
		$out['image_max_bytes'] = isset( $input['image_max_bytes'] ) ? max( 262144, min( 20971520, absint( $input['image_max_bytes'] ) ) ) : 4194304;

		$table = isset( $input['ai_price_table'] ) ? (string) $input['ai_price_table'] : '{}';
		$decoded = json_decode( $table, true );
		if ( is_array( $decoded ) ) {
			$clean = array();
			foreach ( $decoded as $model => $price ) {
				$model = sanitize_text_field( (string) $model );
				$price = (float) $price;
				if ( '' !== $model && $price >= 0 && $price <= 100000 ) {
					$clean[ $model ] = round( $price, 6 );
				}
			}
			$out['ai_price_table'] = json_encode( $clean, JSON_UNESCAPED_UNICODE );
		} else {
			$out['ai_price_table'] = '{}';
		}
		$out['ai_price_image'] = isset( $input['ai_price_image'] ) ? max( 0.0, min( 1000.0, (float) $input['ai_price_image'] ) ) : 0.0;

		// Phase 8 — notifications & digest.
		$out['notification_enabled'] = ! empty( $input['notification_enabled'] );
		$out['digest_enabled']       = ! empty( $input['digest_enabled'] );
		$channelInput = $input['notification_channels'] ?? array();
		$channelList  = is_array( $channelInput ) ? $channelInput : explode( ',', (string) $channelInput );
		$channels = array();
		foreach ( $channelList as $ch ) {
			$ch = strtolower( sanitize_key( $ch ) );
			if ( in_array( $ch, array( 'webhook', 'email', 'telegram', 'slack' ), true ) && ! in_array( $ch, $channels, true ) ) {
				$channels[] = $ch;
			}
		}
		$out['notification_channels'] = implode( ',', $channels );
		$eventInput = $input['notification_events'] ?? array();
		$eventList  = is_array( $eventInput ) ? $eventInput : explode( ',', (string) $eventInput );
		$events = array();
		foreach ( $eventList as $ev ) {
			$ev = strtolower( sanitize_key( $ev ) );
			if ( in_array( $ev, array( 'content_ready', 'digest' ), true ) && ! in_array( $ev, $events, true ) ) {
				$events[] = $ev;
			}
		}
		$out['notification_events'] = implode( ',', $events );

		$mails = array();
		foreach ( explode( ',', (string) ( $input['notification_email_recipients'] ?? '' ) ) as $em ) {
			$em = strtolower( trim( sanitize_text_field( $em ) ) );
			$valid = function_exists( 'is_email' ) ? is_email( $em ) : (bool) preg_match( '/^[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}$/', $em );
			if ( $valid && ! in_array( $em, $mails, true ) ) {
				$mails[] = $em;
			}
		}
		$out['notification_email_recipients'] = implode( ',', $mails );

		$chat = trim( sanitize_text_field( (string) ( $input['telegram_chat_id'] ?? '' ) ) );
		$out['telegram_chat_id'] = preg_match( '/^(?:[0-9]{1,20}|@[a-zA-Z0-9_]{2,64}|[a-zA-Z0-9_]{2,64})$/', $chat ) ? $chat : '';

		$time = sanitize_text_field( (string) ( $input['digest_time'] ?? '08:00' ) );
		$out['digest_time'] = preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time ) ? $time : '08:00';

		$out['numbers_format'] = in_array( $numbersFormat, array( 'latin', 'persian' ), true ) ? $numbersFormat : 'latin';
		$out['uninstall_mode'] = 'delete_all' === ( $input['uninstall_mode'] ?? '' ) ? 'delete_all' : 'keep_data';

		// AUTO_PUBLISH is immutable: the setting is never accepted from input.
		return array( 'data' => $out, 'errors' => $errors );
	}

	public function scheduleTimes(): array {
		$times = $this->data['schedule_times'] ?? self::DEFAULT_SLOTS;
		$out   = array();
		foreach ( (array) $times as $t ) {
			if ( is_string( $t ) && preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $t ) ) {
				$out[] = $t;
			}
		}
		return $out ? $out : self::DEFAULT_SLOTS;
	}

	public function editorialWindowHours(): int {
		return (int) ( $this->data['editorial_window_hours'] ?? 24 );
	}

	public function fallbackWindowDays(): int {
		return (int) ( $this->data['fallback_window_days'] ?? 7 );
	}

	public function fetchTimeout(): int {
		return (int) ( $this->data['fetch_timeout'] ?? 20 );
	}

	public function maxResponseKb(): int {
		return (int) ( $this->data['max_response_kb'] ?? 2048 );
	}

	public function maxItemsPerSource(): int {
		return (int) ( $this->data['max_items_per_source'] ?? 50 );
	}

	public function logLevel(): string {
		return (string) ( $this->data['log_level'] ?? 'info' );
	}

	public function capability(): string {
		return (string) ( $this->data['capability'] ?? 'manage_options' );
	}

	public function secretStorageType(): string {
		return (string) ( $this->data['secret_storage'] ?? 'wordpress' );
	}

	public function defaultCategoryId(): int {
		return (int) ( $this->data['default_category_id'] ?? 0 );
	}

	public function defaultAuthorId(): int {
		return (int) ( $this->data['default_author_id'] ?? 0 );
	}

	public function uninstallMode(): string {
		return (string) ( $this->data['uninstall_mode'] ?? 'keep_data' );
	}

	public function retentionDays( string $key ): int {
		return (int) ( $this->data[ $key ] ?? self::defaults()[ $key ] ?? 90 );
	}

	public function language(): string {
		return (string) ( $this->data['language'] ?? Locale::locale() );
	}

	public function maxStoriesPerWindow(): int {
		return (int) ( $this->data['max_stories_per_window'] ?? 3 );
	}

	/**
	 * Quick-start mode (v1.3): relaxes selection + content gates so a fresh
	 * install with few sources can produce its first drafts. Publishing is
	 * still human-only; this only lowers what reaches the review queue.
	 */
	public function quickStartEnabled(): bool {
		return ! empty( $this->data['quick_start'] );
	}

	/** Quality gate (composite importance) under which nothing is selected (§36 spirit). */
	public function selectionQualityGate(): int {
		$v = (int) ( $this->data['selection_quality_gate'] ?? 60 );
		return $this->quickStartEnabled() ? min( $v, 35 ) : $v;
	}

	public function selectionMinTrust(): int {
		$v = (int) ( $this->data['selection_min_trust'] ?? 40 );
		return $this->quickStartEnabled() ? min( $v, 20 ) : $v;
	}

	/**
	 * @return string[] provider ids in priority order
	 */
	public function aiProviderOrder(): array {
		$raw = (string) ( $this->data['ai_provider_order'] ?? 'gapgpt,openai,gemini' );
		$out = array();
		foreach ( explode( ',', $raw ) as $id ) {
			$id = trim( $id );
			if ( '' !== $id ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	public function aiModelGapGpt(): string {
		return (string) ( $this->data['ai_model_gapgpt'] ?? 'gpt-4o' );
	}

	public function aiModelOpenAi(): string {
		return (string) ( $this->data['ai_model_openai'] ?? 'gpt-4o-mini' );
	}

	public function aiModelGemini(): string {
		return (string) ( $this->data['ai_model_gemini'] ?? 'gemini-2.5-flash' );
	}

	public function aiTemperature(): float {
		return (float) ( $this->data['ai_temperature'] ?? 0.3 );
	}

	public function aiMaxTokens(): int {
		return (int) ( $this->data['ai_max_tokens'] ?? 2048 );
	}

	public function aiTimeout(): int {
		return (int) ( $this->data['ai_timeout'] ?? 60 );
	}

	/** 0 = budget tracking disabled. */
	public function aiBudgetPerJob(): int {
		return (int) ( $this->data['ai_budget_per_job'] ?? 0 );
	}

	public function aiCurrency(): string {
		return (string) ( $this->data['ai_currency'] ?? 'USD' );
	}

	public function aiGapGptAltCdn(): bool {
		return ! empty( $this->data['ai_gapgpt_alt_cdn'] );
	}

	public function aiFactCheckEnabled(): bool {
		return ! empty( $this->data['ai_fact_check_enabled'] );
	}

	/**
	 * v1.5 — output language of generated articles: fa|en|ar, or 'source' to
	 * follow each story's source language. Default fa.
	 */
	public function contentLanguage(): string {
		$v = (string) ( $this->data['content_language'] ?? Locale::lang() );
		return in_array( $v, array( 'fa', 'en', 'ar', 'source' ), true ) ? $v : 'fa';
	}

	/** §36 — a version below this is revised (max $max_revisions) then NEEDS_REVIEW. */
	public function contentQualityGate(): int {
		$v = (int) ( $this->data['content_quality_gate'] ?? 90 );
		return $this->quickStartEnabled() ? min( $v, 70 ) : $v;
	}

	/** §36 — max AI revision attempts before the story is handed to a human. */
	public function contentMaxRevisions(): int {
		return (int) ( $this->data['content_max_revisions'] ?? 2 );
	}

	/** Layer 15–16 — include internal/external link work in the flow. */
	public function contentIncludeLinks(): bool {
		return ! empty( $this->data['content_include_links'] );
	}

	/** Master switch for the whole content phase (research-only mode). */
	public function contentGenerateEnabled(): bool {
		return ! empty( $this->data['content_generate_enabled'] );
	}

	public function imageGenerateEnabled(): bool {
		return ! empty( $this->data['image_generate_enabled'] );
	}

	public function imageModel(): string {
		return (string) ( $this->data['image_model'] ?? 'gpt-image-2' );
	}

	public function imageSize(): string {
		return (string) ( $this->data['image_size'] ?? '1024x1024' );
	}

	public function imageMaxBytes(): int {
		return max( 262144, (int) ( $this->data['image_max_bytes'] ?? 4194304 ) );
	}

	/** @return float  configured price per 1M tokens for a model (0 = unset) */
	public function priceForModel( string $model ): float {
		$table = json_decode( (string) ( $this->data['ai_price_table'] ?? '{}' ), true );
		if ( ! is_array( $table ) ) {
			return 0.0;
		}
		return isset( $table[ $model ] ) ? (float) $table[ $model ] : 0.0;
	}

	/** @return float  configured price per generated image (0 = unset) */
	public function pricePerImage(): float {
		return max( 0.0, (float) ( $this->data['ai_price_image'] ?? 0.0 ) );
	}

	/**
	 * §73 — AUTO PUBLISH is always OFF and never configurable.
	 */
	public function autoPublishEnabled(): bool {
		return false;
	}

	// Phase 8 — notifications & digest.

	public function notificationEnabled(): bool {
		return ! empty( $this->data['notification_enabled'] );
	}

	/** @return string[] */
	public function notificationChannels(): array {
		return self::csvList( (string) ( $this->data['notification_channels'] ?? '' ), array( 'webhook', 'email', 'telegram', 'slack' ) );
	}

	/** @return string[] */
	public function notificationEvents(): array {
		return self::csvList( (string) ( $this->data['notification_events'] ?? '' ), array( 'content_ready', 'digest' ) );
	}

	/** @return string[] */
	public function notificationEmailRecipients(): array {
		$out = array();
		foreach ( explode( ',', (string) ( $this->data['notification_email_recipients'] ?? '' ) ) as $em ) {
			$em = trim( $em );
			if ( '' !== $em ) {
				$out[] = $em;
			}
		}
		return $out;
	}

	public function telegramChatId(): string {
		return (string) ( $this->data['telegram_chat_id'] ?? '' );
	}

	public function digestEnabled(): bool {
		return ! empty( $this->data['digest_enabled'] );
	}

	public function digestTime(): string {
		$t = (string) ( $this->data['digest_time'] ?? '08:00' );
		return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $t ) ? $t : '08:00';
	}

	/**
	 * Whitelisted CSV → list.
	 *
	 * @param string[] $allowed
	 * @return string[]
	 */
	private static function csvList( string $csv, array $allowed ): array {
		$out = array();
		foreach ( explode( ',', $csv ) as $item ) {
			$item = strtolower( trim( $item ) );
			if ( in_array( $item, $allowed, true ) && ! in_array( $item, $out, true ) ) {
				$out[] = $item;
			}
		}
		return $out;
	}

	public function __get( string $key ) {
		return Arr::get( $this->data, $key, null );
	}
}
