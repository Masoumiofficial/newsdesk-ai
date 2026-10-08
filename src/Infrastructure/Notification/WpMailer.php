<?php
/**
 * wp_mail implementation of MailerInterface (production path).
 *
 * @package NewsDesk\AI\Infrastructure\Notification
 */

namespace NewsDesk\AI\Infrastructure\Notification;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Contracts\MailerInterface;

final class WpMailer implements MailerInterface {

	public function send( string $to, string $subject, string $body ): bool {
		if ( ! function_exists( 'wp_mail' ) ) {
			return false;
		}
		return (bool) wp_mail( $to, $subject, $body ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail
	}
}
