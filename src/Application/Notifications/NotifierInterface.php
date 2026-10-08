<?php
/**
 * Outbound notification channel contract (§76).
 *
 * @package NewsDesk\AI\Application\Notifications
 */

namespace NewsDesk\AI\Application\Notifications;

defined( 'ABSPATH' ) || exit;

interface NotifierInterface {

	/**
	 * Stable channel id: webhook | email | telegram | slack.
	 */
	public function id(): string;

	/**
	 * Human label (translatable).
	 */
	public function label(): string;

	/**
	 * True when every requirement for this channel is present
	 * (URL/token/recipients configured).
	 */
	public function isConfigured(): bool;

	/**
	 * Deliver one message. MUST NOT throw: return false on any failure
	 * (transport, non-2xx, malformed response). The caller decides logging.
	 */
	public function send( NotificationMessage $message ): bool;
}
