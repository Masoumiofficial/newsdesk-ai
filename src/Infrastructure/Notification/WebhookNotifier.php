<?php
/**
 * Generic webhook channel — own contract, documented here:
 *
 *   POST <configured URL>            (https/http only, §52-validated)
 *   Content-Type: application/json
 *   X-NewsDesk-Event: <content_ready|digest>
 *   X-NewsDesk-Signature: t=<unix-ts>,v1=<hex hmac-sha256 over "<ts>.<raw body>"
 *                    with the configured shared secret
 *   Body: { "id","event","title","text","story_id","job_id","sent_at" }
 *
 * Receiver MUST verify the signature (replay window ≤ 5 min) and accept only
 * 2xx. The shared secret lives in SecretStorage — never in settings/logs.
 *
 * @package NewsDesk\AI\Infrastructure\Notification
 */

namespace NewsDesk\AI\Infrastructure\Notification;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Contracts\HttpClientInterface;
use NewsDesk\AI\Application\Contracts\SecretStorageInterface;
use NewsDesk\AI\Application\Notifications\NotificationMessage;
use NewsDesk\AI\Application\Notifications\NotifierInterface;

final class WebhookNotifier implements NotifierInterface {

	public const SECRET_URL    = 'notification_webhook_url';
	public const SECRET_SECRET = 'notification_webhook_secret';

	/** @var HttpClientInterface */
	private $http;
	/** @var SecretStorageInterface */
	private $secrets;

	public function __construct( HttpClientInterface $http, SecretStorageInterface $secrets ) {
		$this->http    = $http;
		$this->secrets = $secrets;
	}

	public function id(): string {
		return 'webhook';
	}

	public function label(): string {
		return __( 'Webhook', 'newsdesk-ai' );
	}

	public function isConfigured(): bool {
		$url = (string) $this->secrets->get( self::SECRET_URL );
		return '' !== $url && '' !== (string) $this->secrets->get( self::SECRET_SECRET );
	}

	public function send( NotificationMessage $message ): bool {
		$url = trim( (string) $this->secrets->get( self::SECRET_URL ) );
		if ( '' === $url ) {
			return false;
		}
		$payload = array(
			'id'       => bin2hex( random_bytes( 8 ) ),
			'event'    => $message->event,
			'title'    => $message->title,
			'text'     => $message->text,
			'story_id' => $message->storyId,
			'job_id'   => $message->jobId,
			'sent_at'  => $message->createdAt,
		);
		$body = json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $body ) {
			return false;
		}
		$ts   = (string) time();
		$sig  = hash_hmac( 'sha256', $ts . '.' . $body, (string) $this->secrets->get( self::SECRET_SECRET ) );

		try {
			$response = $this->http->request( 'POST', $url, array(
				'timeout'     => 10,
				'max_bytes'   => 2048,
				'max_redirects' => 0,
				'headers'     => array(
					'Content-Type'     => 'application/json',
					'X-NewsDesk-Event'      => $message->event,
					'X-NewsDesk-Signature'  => 't=' . $ts . ',v1=' . $sig,
				),
				'body'        => $body,
			) );
		} catch ( \Throwable $e ) {
			return false;
		}
		return $response->isOk();
	}
}
