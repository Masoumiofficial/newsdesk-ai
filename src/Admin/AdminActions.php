<?php
/**
 * POST handlers for admin actions (§47) — all behind capability + nonce.
 *
 * @package NewsDesk\AI\Admin
 */

namespace NewsDesk\AI\Admin;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\ManualActions;
use NewsDesk\AI\Application\NewsroomSettings;
use NewsDesk\AI\Application\SourceService;
use NewsDesk\AI\Support\Container;

final class AdminActions {

	public const PREFIX = 'newsdesk_newsroom_';
	public const ACTION_DRAFT_APPROVE = 'newsdesk_newsroom_draft_approve';
	public const ACTION_DRAFT_REJECT = 'newsdesk_newsroom_draft_reject';

	/** @var Container */
	private $container;

	public function __construct( Container $container ) {
		$this->container = $container;
	}

	public function register(): void {
		add_action( 'admin_post_' . self::PREFIX . 'run_discovery', array( $this, 'onRunDiscovery' ) ); // phpcs:ignore
		add_action( 'admin_post_' . self::PREFIX . 'run_pipeline', array( $this, 'onRunPipeline' ) ); // phpcs:ignore
		add_action( 'admin_post_' . self::PREFIX . 'source_save', array( $this, 'onSourceSave' ) ); // phpcs:ignore
		add_action( 'admin_post_' . self::PREFIX . 'source_delete', array( $this, 'onSourceDelete' ) ); // phpcs:ignore
		add_action( 'admin_post_' . self::PREFIX . 'source_toggle', array( $this, 'onSourceToggle' ) ); // phpcs:ignore
		add_action( 'admin_post_' . self::PREFIX . 'settings_save', array( $this, 'onSettingsSave' ) ); // phpcs:ignore
		add_action( 'admin_post_' . self::PREFIX . 'job_retry', array( $this, 'onJobRetry' ) ); // phpcs:ignore
		add_action( 'admin_post_' . self::ACTION_DRAFT_APPROVE, array( $this, 'onDraftApprove' ) ); // phpcs:ignore
		add_action( 'admin_post_' . self::ACTION_DRAFT_REJECT, array( $this, 'onDraftReject' ) ); // phpcs:ignore
		add_action( 'admin_post_' . self::PREFIX . 'digest_run', array( $this, 'onDigestRun' ) ); // phpcs:ignore
		add_action( 'admin_post_' . self::PREFIX . 'provider_test', array( $this, 'onProviderTest' ) ); // phpcs:ignore
		add_action( 'admin_post_' . self::PREFIX . 'run_now', array( $this, 'onRunNow' ) ); // phpcs:ignore
		add_action( 'admin_post_' . self::PREFIX . 'source_test', array( $this, 'onSourceTest' ) ); // phpcs:ignore
		add_action( 'admin_post_' . self::PREFIX . 'logs_clear', array( $this, 'onLogsClear' ) ); // phpcs:ignore
	}

	public function onDraftApprove(): void {
		$this->guard( self::ACTION_DRAFT_APPROVE );
		$id     = isset( $_POST['version_id'] ) ? absint( wp_unslash( $_POST['version_id'] ) ) : 0; // phpcs:ignore
		$result = $this->container->get( \NewsDesk\AI\Application\Content\DraftReviewService::class )->approve( $id );
		$this->redirect( 'nd-drafts', array( 'nd_msg' => $result['changed'] ? 'draft-approved' : 'draft-action-' . strtolower( $result['code'] ) ) );
	}

	public function onDraftReject(): void {
		$this->guard( self::ACTION_DRAFT_REJECT );
		$id     = isset( $_POST['version_id'] ) ? absint( wp_unslash( $_POST['version_id'] ) ) : 0; // phpcs:ignore
		$reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : ''; // phpcs:ignore
		$result = $this->container->get( \NewsDesk\AI\Application\Content\DraftReviewService::class )->reject( $id, $reason );
		$this->redirect( 'nd-drafts', array( 'nd_msg' => $result['changed'] ? 'draft-rejected' : 'draft-action-' . strtolower( $result['code'] ) ) );
	}

	public function onRunDiscovery(): void {
		$this->guard( self::PREFIX . 'run_discovery' );
		$result = $this->container->get( ManualActions::class )->runDiscovery( null, false );
		$this->redirect( 'newsdesk-ai', array( 'nd_msg' => $result['created'] ? 'job-created' : 'job-exists' ) );
	}

	public function onRunPipeline(): void {
		$this->guard( self::PREFIX . 'run_pipeline' );
		$result = $this->container->get( ManualActions::class )->runDiscovery( null, true );
		$this->redirect( 'newsdesk-ai', array( 'nd_msg' => $result['created'] ? 'job-created' : 'job-exists' ) );
	}

	public function onSourceSave(): void {
		$this->guard( self::PREFIX . 'source_save' );
		$service = $this->container->get( SourceService::class );
		$id      = isset( $_POST['source_id'] ) ? absint( wp_unslash( $_POST['source_id'] ) ) : 0; // phpcs:ignore
		$input   = wp_unslash( $_POST ); // phpcs:ignore
		try {
			if ( $id > 0 ) {
				$service->update( $id, (array) $input );
				$msg = 'source-saved';
			} else {
				$service->create( (array) $input );
				$msg = 'source-created';
			}
		} catch ( \NewsDesk\AI\Application\Exception\InputValidationException $e ) {
			$this->redirect( 'nd-sources', array( 'nd_err' => rawurlencode( implode( ' ', $e->errors() ) ), 'edit' => $id ) );
			return;
		}
		$this->redirect( 'nd-sources', array( 'nd_msg' => $msg ) );
	}

	public function onSourceDelete(): void {
		$this->guard( self::PREFIX . 'source_delete' );
		$id = isset( $_POST['source_id'] ) ? absint( wp_unslash( $_POST['source_id'] ) ) : 0; // phpcs:ignore
		$service = $this->container->get( SourceService::class );
		$ok      = $id > 0 && $service->delete( $id );
		$this->redirect( 'nd-sources', array( 'nd_msg' => $ok ? 'source-deleted' : 'delete-failed' ) );
	}

	public function onSourceToggle(): void {
		$this->guard( self::PREFIX . 'source_toggle' );
		$id     = isset( $_POST['source_id'] ) ? absint( wp_unslash( $_POST['source_id'] ) ) : 0; // phpcs:ignore
		$active = ! empty( $_POST['active'] ); // phpcs:ignore
		$this->container->get( SourceService::class )->setActive( $id, $active );
		$this->redirect( 'nd-sources', array( 'nd_msg' => 'source-toggled' ) );
	}

	/**
	 * Save settings from raw input (extracted from the POST handler for
	 * testability). Never redirects; returns errors.
	 *
	 * @param array $input unslashed POST body.
	 * @return array{errors: array<string,string>, saved: bool}
	 */
	public function handleSettingsSave( array $input ): array {
		$result = NewsroomSettings::sanitize( $input );

		// Rebuild settings from sanitized data and persist.
		$fresh = new NewsroomSettings( $result['data'] );
		$fresh->save();

		// §23: AI keys go to SecretStorage ONLY — never to settings, logs, HTML.
		$secrets = $this->container->get( \NewsDesk\AI\Application\Contracts\SecretStorageInterface::class );

		// Explicit "clear" checkboxes (clear_secret[<key>]=1) — the only way to
		// remove a stored secret from the UI; an empty field still means "unchanged".
		$clear = isset( $input['clear_secret'] ) && is_array( $input['clear_secret'] ) ? $input['clear_secret'] : array();
		$allSecretKeys = array(
			'gapgpt_api_key',
			'openai_api_key',
			'gemini_api_key',
			\NewsDesk\AI\Infrastructure\Notification\WebhookNotifier::SECRET_URL,
			\NewsDesk\AI\Infrastructure\Notification\WebhookNotifier::SECRET_SECRET,
			\NewsDesk\AI\Infrastructure\Notification\SlackNotifier::SECRET_URL,
			\NewsDesk\AI\Infrastructure\Notification\TelegramNotifier::SECRET_TOKEN,
		);
		foreach ( $allSecretKeys as $key ) {
			if ( ! empty( $clear[ $key ] ) ) {
				$secrets->delete( $key );
				unset( $input[ $key ] ); // a cleared key wins over a value typed in the same submit.
			}
		}

		foreach ( array( 'gapgpt', 'openai', 'gemini' ) as $provider ) {
			$field = $provider . '_api_key';
			$value = isset( $input[ $field ] ) ? trim( (string) $input[ $field ] ) : '';
			if ( '' !== $value ) {
				$secrets->set( $field, sanitize_text_field( $value ) );
			}
		}

		// Phase 8 — notification secrets (§23): URLs are §52-validated at save
		// time (http/https + no private/local targets); tokens never echoed back.
		$urlInputs = array(
			\NewsDesk\AI\Infrastructure\Notification\WebhookNotifier::SECRET_URL => true,
			\NewsDesk\AI\Infrastructure\Notification\SlackNotifier::SECRET_URL  => true,
		);
		foreach ( $urlInputs as $key => $isUrl ) {
			$value = isset( $input[ $key ] ) ? trim( (string) $input[ $key ] ) : '';
			if ( '' !== $value ) {
				try {
					// §52: reject at SAVE time too — scheme/host syntax + IP/DNS
					// checks (private/link-local/metadata targets never stored).
					$parsed = \NewsDesk\AI\Infrastructure\Http\UrlValidator::validate( $value );
					\NewsDesk\AI\Infrastructure\Http\IpValidator::assertHostSafe( (string) $parsed['host'] );
					$secrets->set( $key, sanitize_text_field( $value ) );
				} catch ( \Throwable $e ) {
					$result['errors'][ $key ] = __( 'The webhook URL is invalid or not allowed (§52).', 'newsdesk-ai' );
				}
			}
		}
		foreach ( array(
			\NewsDesk\AI\Infrastructure\Notification\WebhookNotifier::SECRET_SECRET,
			\NewsDesk\AI\Infrastructure\Notification\TelegramNotifier::SECRET_TOKEN,
		) as $key ) {
			$value = isset( $input[ $key ] ) ? trim( (string) $input[ $key ] ) : '';
			if ( '' !== $value ) {
				$secrets->set( $key, sanitize_text_field( $value ) );
			}
		}

		// Re-arm cron tick + digest schedule (digest time may have changed).
		$scheduler = $this->container->get( \NewsDesk\AI\Infrastructure\Scheduler\CronScheduler::class );
		$scheduler->scheduleTick();
		$digestCron = $this->container->get( \NewsDesk\AI\Infrastructure\Scheduler\DigestCron::class );
		$digestCron->unschedule();
		$digestCron->schedule();

		return array( 'errors' => $result['errors'], 'saved' => empty( $result['errors'] ) );
	}

	public function onSettingsSave(): void {
		$this->guard( self::PREFIX . 'settings_save' );
		$input  = wp_unslash( $_POST ); // phpcs:ignore
		$result = $this->handleSettingsSave( (array) $input );

		$this->redirect( 'nd-settings', array( 'nd_msg' => $result['saved'] ? 'settings-saved' : 'settings-invalid' ) );
	}

	public function onDigestRun(): void {
		$this->guard( self::PREFIX . 'digest_run' );
		$result = $this->container->get( \NewsDesk\AI\Application\DigestService::class )->run( true );
		$this->redirect( 'nd-settings', array( 'nd_msg' => $result['ran'] ? 'digest-sent' : 'digest-skipped' ) );
	}

	/** v1.3 — one real round-trip to the first configured AI provider. */
	public function onProviderTest(): void {
		$this->guard( self::PREFIX . 'provider_test' );
		$r = $this->container->get( \NewsDesk\AI\Application\HealthCheckService::class )->testProvider();
		if ( $r['ok'] ) {
			$this->redirect( 'nd-health', array( 'nd_msg' => 'provider-ok', 'p' => rawurlencode( $r['provider'] . ' / ' . $r['model'] . ' / ' . $r['latency_ms'] . 'ms' ) ) );
		}
		$this->redirect( 'nd-health', array( 'nd_err' => rawurlencode( __( 'Provider test failed: ', 'newsdesk-ai' ) . $r['provider'] . ' — ' . $r['error'] ) ) );
	}

	/**
	 * v1.3 — "Run now": create a manual full-pipeline job and execute it in
	 * THIS request (bypasses WP-Cron / Action Scheduler entirely). For
	 * first-run diagnostics; scheduled runs still go through the queue.
	 */
	public function onRunNow(): void {
		$this->guard( self::PREFIX . 'run_now' );
		@set_time_limit( 600 ); // phpcs:ignore
		@ignore_user_abort( true ); // phpcs:ignore
		$result = $this->container->get( ManualActions::class )->runDiscovery( null, true );
		if ( ! $result['created'] || empty( $result['job_id'] ) ) {
			$this->redirect( 'nd-health', array( 'nd_msg' => 'job-exists' ) );
			return;
		}
		$jobId = (int) $result['job_id'];
		try {
			$this->container->get( \NewsDesk\AI\Application\PipelineRunner::class )->runJob( $jobId );
		} catch ( \Throwable $e ) {
			$this->redirect( 'nd-health', array( 'nd_err' => rawurlencode( 'Job #' . $jobId . ': ' . get_class( $e ) . ' — ' . mb_substr( $e->getMessage(), 0, 200 ) ) ) );
			return;
		}
		$this->redirect( 'nd-health', array( 'nd_msg' => 'run-now-done', 'job' => $jobId ) );
	}

	public function onJobRetry(): void {
		$this->guard( self::PREFIX . 'job_retry' );
		$id     = isset( $_POST['job_id'] ) ? absint( wp_unslash( $_POST['job_id'] ) ) : 0; // phpcs:ignore
		$result = $this->container->get( ManualActions::class )->retryJob( $id );
		$this->redirect( 'newsdesk-ai', array( 'nd_msg' => $result['created'] ? 'job-created' : 'retry-failed' ) );
	}

	/**
	 * B-9 — "Test connection": fetch a source live and report what came back.
	 * Nothing is persisted, so a misconfigured feed is caught at save time
	 * instead of silently failing on the next scheduled run.
	 */
	public function onSourceTest(): void {
		$this->guard( self::PREFIX . 'source_test' );
		$sourceId = isset( $_POST['source_id'] ) ? (int) $_POST['source_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( $sourceId <= 0 ) {
			$this->redirect( 'nd-sources', array( 'nd_err' => rawurlencode( __( 'Invalid source.', 'newsdesk-ai' ) ) ) );
			return;
		}

		$source = $this->container->get( \NewsDesk\AI\Application\Contracts\SourceRepositoryInterface::class )->find( $sourceId );
		if ( null === $source ) {
			$this->redirect( 'nd-sources', array( 'nd_err' => rawurlencode( __( 'Source not found.', 'newsdesk-ai' ) ) ) );
			return;
		}

		@set_time_limit( 120 ); // phpcs:ignore
		$result = $this->container->get( \NewsDesk\AI\Application\FeedTester::class )->test( $source );

		if ( ! $result['ok'] ) {
			$this->redirect(
				'nd-sources',
				array(
					'nd_err' => rawurlencode(
						sprintf(
							/* translators: 1: error code, 2: error message */
							__( 'Connection test failed (%1$s): %2$s', 'newsdesk-ai' ),
							$result['error_code'],
							mb_substr( $result['error'], 0, 200 )
						)
					),
				)
			);
			return;
		}

		$this->redirect(
			'nd-sources',
			array(
				'nd_msg'   => 'source-tested',
				'tested'    => $sourceId,
				'count'     => (int) $result['count'],
				'ms'        => (int) $result['elapsed_ms'],
				'sample'    => rawurlencode( (string) ( $result['items'][0]['title'] ?? '' ) ),
			)
		);
	}

	/**
	 * B-8 — "Clear logs": the log table could only ever grow from the UI.
	 * Honours an optional level filter so an admin can drop debug noise
	 * without losing the error history.
	 */
	public function onLogsClear(): void {
		$this->guard( self::PREFIX . 'logs_clear' );
		$level = isset( $_POST['level'] ) ? sanitize_key( wp_unslash( $_POST['level'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$deleted = $this->container->get( \NewsDesk\AI\Logging\Contracts\LogRepositoryInterface::class )->clear( $level );

		$this->container->get( \NewsDesk\AI\Logging\Contracts\LoggerInterface::class )->warning(
			'Logs cleared from admin',
			array(
				'level'   => '' !== $level ? $level : 'all',
				'deleted' => $deleted,
				'user_id' => function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0,
			),
			'admin.logs',
			'LOGS_CLEARED'
		);

		$this->redirect(
			'nd-logs',
			array(
				'nd_msg' => 'logs-cleared',
				'deleted' => $deleted,
			)
		);
	}

	private function guard( string $action ): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			wp_die( esc_html__( 'Access denied.', 'newsdesk-ai' ), 403 );
		}
		check_admin_referer( $action );
	}

	private function redirect( string $page, array $args ): void {
		$url = add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $url );
		exit;
	}
}
