<?php
/**
 * Email channel — wp_mail behind MailerInterface (§76). Recipients are the
 * configured list (settings, not secrets). One failing recipient never
 * aborts the channel: failure of the whole mail call is reported as false.
 *
 * @package NewsDesk\AI\Infrastructure\Notification
 */

namespace NewsDesk\AI\Infrastructure\Notification;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Contracts\MailerInterface;
use NewsDesk\AI\Application\NewsroomSettings;
use NewsDesk\AI\Application\Notifications\NotificationMessage;
use NewsDesk\AI\Application\Notifications\NotifierInterface;

final class EmailNotifier implements NotifierInterface {

	/** @var MailerInterface */
	private $mailer;
	/** @var NewsroomSettings */
	private $settings;

	public function __construct( MailerInterface $mailer, NewsroomSettings $settings ) {
		$this->mailer   = $mailer;
		$this->settings = $settings;
	}

	public function id(): string {
		return 'email';
	}

	public function label(): string {
		return __( 'Email', 'newsdesk-ai' );
	}

	public function isConfigured(): bool {
		return array() !== $this->settings->notificationEmailRecipients();
	}

	public function send( NotificationMessage $message ): bool {
		$recipients = $this->settings->notificationEmailRecipients();
		if ( ! $recipients ) {
			return false;
		}
		$ok = true;
		foreach ( $recipients as $to ) {
			$ok = $this->mailer->send( $to, $message->title, $message->text ) && $ok;
		}
		return $ok;
	}
}
