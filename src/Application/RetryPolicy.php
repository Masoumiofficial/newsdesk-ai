<?php
/**
 * Retry policy (§41) — strict whitelist of retryable error classes.
 *
 * @package NewsDesk\AI\Application
 */

namespace NewsDesk\AI\Application;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Support\Http\HttpFetchException;

final class RetryPolicy {

	public const MAX_ATTEMPTS = 3;

	/**
	 * Backoff seconds for attempt (1-based): 1m / 5m / 25m.
	 */
	public static function backoffSeconds( int $attempt ): int {
		switch ( max( 1, $attempt ) ) {
			case 1:
				return 60;
			case 2:
				return 300;
			default:
				return 1500;
		}
	}

	/**
	 * Retryable error classes ONLY (timeout/network/5xx-class transport issues).
	 */
	public static function isRetryable( \Throwable $e ): bool {
		if ( $e instanceof HttpFetchException ) {
			return true;
		}
		return false;
	}
}
