<?php
/**
 * Notification dispatcher — channel isolation + event gate + §23 (never logs
 * secrets; per-channel failure NEVER bubbles up).
 *
 * @package NewsDesk\AI\Application\Notifications
 */

namespace NewsDesk\AI\Application\Notifications;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Contracts\SecretStorageInterface;
use NewsDesk\AI\Application\NewsroomSettings;
use NewsDesk\AI\Logging\Contracts\LoggerInterface;

final class NotificationService {

	/** @var NotifierInterface[] */
	private $channels;
	/** @var NewsroomSettings */
	private $settings;
	/** @var SecretStorageInterface */
	private $secrets;
	/** @var LoggerInterface */
	private $logger;

	/**
	 * @param NotifierInterface[] $channels
	 */
	public function __construct( array $channels, NewsroomSettings $settings, SecretStorageInterface $secrets, LoggerInterface $logger ) {
		$this->channels = array_values( $channels );
		$this->settings = $settings;
		$this->secrets  = $secrets;
		$this->logger   = $logger;
	}

	/**
	 * Dispatch to every enabled+configured channel for this event.
	 *
	 * Never throws. Returns channel => true/false (channels skipped by
	 * config are simply absent from the result).
	 *
	 * @return array<string, bool>
	 */
	public function dispatch( NotificationMessage $message ): array {
		if ( ! $this->settings->notificationEnabled() ) {
			return array();
		}
		if ( ! in_array( $message->event, $this->settings->notificationEvents(), true ) ) {
			return array();
		}

		$results = array();
		foreach ( $this->channels as $channel ) {
			if ( ! in_array( $channel->id(), $this->settings->notificationChannels(), true ) ) {
				continue;
			}
			if ( ! $channel->isConfigured() ) {
				continue;
			}
			try {
				$ok = $channel->send( $message );
			} catch ( \Throwable $e ) {
				// §76: a notification channel must never take the pipeline down.
				$ok = false;
				$this->logger->warning(
					'Notification channel failed (non-fatal)',
					array( 'channel' => $channel->id(), 'event' => $message->event, 'error' => get_class( $e ) ),
					'notifications.dispatch',
					'NOTIFY_SEND_FAILED',
					$message->jobId
				);
			}
			$results[ $channel->id() ] = $ok;
			$this->logger->info(
				$ok ? 'Notification sent' : 'Notification send failed',
				array( 'channel' => $channel->id(), 'event' => $message->event ),
				'notifications.dispatch',
				$ok ? 'NOTIFY_SENT' : 'NOTIFY_SEND_FAILED',
				$message->jobId
			);
		}
		return $results;
	}

	/**
	 * @return string[] ids of configured channels (UI hints).
	 */
	public function configuredChannelIds(): array {
		$out = array();
		foreach ( $this->channels as $channel ) {
			if ( $channel->isConfigured() ) {
				$out[] = $channel->id();
			}
		}
		return $out;
	}
}
