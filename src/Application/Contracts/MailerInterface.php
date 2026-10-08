<?php
/**
 * Outbound email contract (§76) — wp_mail behind an interface.
 *
 * @package NewsDesk\AI\Application\Contracts
 */

namespace NewsDesk\AI\Application\Contracts;

defined( 'ABSPATH' ) || exit;

interface MailerInterface {

	/**
	 * @return bool true when wp_mail accepted the message.
	 */
	public function send( string $to, string $subject, string $body ): bool;
}
