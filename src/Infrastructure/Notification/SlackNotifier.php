<?php
/**
 * Slack Incoming Webhooks channel — official contract
 * (https://docs.slack.dev/messaging/sending-messages-using-incoming-webhooks/):
 *
 *   POST https://hooks.slack.com/services/...   (Content-Type: application/json)
 *   { "text": "..." } → 2xx on success.
 *
 * The webhook URL itself is the secret (§23: SecretStorage; Redaction strips
 * the trailing path segment from any log line that may contain it).
 *
 * @package NewsDesk\AI\Infrastructure\Notification
 */

namespace NewsDesk\AI\Infrastructure\Notification;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Contracts\HttpClientInterface;
use NewsDesk\AI\Application\Contracts\SecretStorageInterface;
use NewsDesk\AI\Application\Notifications\NotificationMessage;
use NewsDesk\AI\Application\Notifications\NotifierInterface;

final class SlackNotifier implements NotifierInterface {

	public const SECRET_URL = 'slack_webhook_url';
	public const TEXT_LIMIT = 39000; // Slack per-message limit (40000) minus margin.

	/** @var HttpClientInterface */
	private $http;
	/** @var SecretStorageInterface */
	private $secrets;

	public function __construct( HttpClientInterface $http, SecretStorageInterface $secrets ) {
		$this->http    = $http;
		$this->secrets = $secrets;
	}

	public function id(): string {
		return 'slack';
	}

	public function label(): string {
		return __( 'Slack', 'newsdesk-ai' );
	}

	public function isConfigured(): bool {
		return '' !== trim( (string) $this->secrets->get( self::SECRET_URL ) );
	}

	public function send( NotificationMessage $message ): bool {
		$url = trim( (string) $this->secrets->get( self::SECRET_URL ) );
		if ( '' === $url ) {
			return false;
		}
		$body = json_encode( array(
			'text' => mb_substr( $message->text, 0, self::TEXT_LIMIT ),
		), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $body ) {
			return false;
		}
		try {
			$response = $this->http->request( 'POST', $url, array(
				'timeout'       => 10,
				'max_bytes'     => 2048,
				'max_redirects' => 0,
				'headers'       => array( 'Content-Type' => 'application/json' ),
				'body'          => $body,
			) );
		} catch ( \Throwable $e ) {
			return false;
		}
		return $response->isOk();
	}
}
