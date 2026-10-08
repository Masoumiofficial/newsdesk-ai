<?php
/**
 * §45 Settings.
 *
 * @package NewsDesk\AI\Admin\Page
 */

namespace NewsDesk\AI\Admin\Page;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Admin\AdminView;
use NewsDesk\AI\Application\NewsroomSettings;
use NewsDesk\AI\Support\Container;

final class SettingsPage {

	/** @var Container */
	private $container;

	public function __construct( Container $container ) {
		$this->container = $container;
	}

	public function render(): void {
		if ( ! current_user_can( \NewsDesk\AI\Admin\Menu::CAP ) ) {
			wp_die( esc_html__( 'Access denied.', 'newsdesk-ai' ) );
		}
		$settings = $this->container->get( NewsroomSettings::class );
		$secrets  = $this->container->get( \NewsDesk\AI\Application\Contracts\SecretStorageInterface::class );
		AdminView::render( 'settings', array(
			's'              => $settings,
			'encryption_available' => \NewsDesk\AI\Infrastructure\Secrets\WordPressSecretStorage::isEncryptionAvailable(),
			'secret_present' => array(
				'gapgpt' => $secrets->has( 'gapgpt_api_key' ),
				'openai' => $secrets->has( 'openai_api_key' ),
				'gemini' => $secrets->has( 'gemini_api_key' ),
				// Phase 8 — notification secrets (never echoed; only "configured" flags).
				'notification_webhook_url'    => $secrets->has( \NewsDesk\AI\Infrastructure\Notification\WebhookNotifier::SECRET_URL ),
				'notification_webhook_secret' => $secrets->has( \NewsDesk\AI\Infrastructure\Notification\WebhookNotifier::SECRET_SECRET ),
				'telegram_bot_token'          => $secrets->has( \NewsDesk\AI\Infrastructure\Notification\TelegramNotifier::SECRET_TOKEN ),
				'slack_webhook_url'           => $secrets->has( \NewsDesk\AI\Infrastructure\Notification\SlackNotifier::SECRET_URL ),
			),
		) );
	}
}
