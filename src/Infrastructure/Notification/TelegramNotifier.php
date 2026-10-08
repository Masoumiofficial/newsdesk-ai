<?php
/**
 * Telegram Bot API channel — official contract (https://core.telegram.org/bots/api):
 *
 *   POST https://api.telegram.org/bot<TOKEN>/sendMessage   (Content-Type: application/json)
 *   { "chat_id": "...", "text": "...", "disable_web_page_preview": true }
 *   → 200 { "ok": true, ... } on success; 4xx {"ok":false,"description":...} on failure.
 *
 * The token is part of the URL (§23: never logged — Redaction strips it here
 * via the telegram URL pattern; SafeHttpClient never logs URLs either).
 *
 * @package NewsDesk\AI\Infrastructure\Notification
 */

namespace NewsDesk\AI\Infrastructure\Notification;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Contracts\HttpClientInterface;
use NewsDesk\AI\Application\Contracts\SecretStorageInterface;
use NewsDesk\AI\Application\NewsroomSettings;
use NewsDesk\AI\Application\Notifications\NotificationMessage;
use NewsDesk\AI\Application\Notifications\NotifierInterface;

final class TelegramNotifier implements NotifierInterface {

	public const SECRET_TOKEN = 'telegram_bot_token';
	public const ENDPOINT     = 'https://api.telegram.org/bot%s/sendMessage';
	public const TEXT_LIMIT   = 4000; // Telegram per-message limit (4096) minus margin.

	/** @var HttpClientInterface */
	private $http;
	/** @var SecretStorageInterface */
	private $secrets;
	/** @var NewsroomSettings */
	private $settings;

	public function __construct( HttpClientInterface $http, SecretStorageInterface $secrets, NewsroomSettings $settings ) {
		$this->http     = $http;
		$this->secrets  = $secrets;
		$this->settings = $settings;
	}

	public function id(): string {
		return 'telegram';
	}

	public function label(): string {
		return __( 'Telegram', 'newsdesk-ai' );
	}

	public function isConfigured(): bool {
		return '' !== (string) $this->secrets->get( self::SECRET_TOKEN ) && '' !== $this->settings->telegramChatId();
	}

	public function send( NotificationMessage $message ): bool {
		$token = (string) $this->secrets->get( self::SECRET_TOKEN );
		$chat  = $this->settings->telegramChatId();
		if ( '' === $token || '' === $chat ) {
			return false;
		}
		$body = json_encode( array(
			'chat_id'                  => $chat,
			'text'                     => mb_substr( $message->text, 0, self::TEXT_LIMIT ),
			'disable_web_page_preview' => true,
		), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $body ) {
			return false;
		}
		try {
			$response = $this->http->request( 'POST', sprintf( self::ENDPOINT, $token ), array(
				'timeout'       => 10,
				'max_bytes'     => 4096,
				'max_redirects' => 0,
				'headers'       => array( 'Content-Type' => 'application/json' ),
				'body'          => $body,
			) );
		} catch ( \Throwable $e ) {
			return false;
		}
		if ( ! $response->isOk() ) {
			return false;
		}
		$decoded = json_decode( $response->body, true );
		return is_array( $decoded ) && true === ( $decoded['ok'] ?? false );
	}
}
