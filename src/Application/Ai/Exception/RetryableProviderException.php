<?php
/**
 * Timeout / 429 / 5xx / network — safe to retry or fall back (§21).
 *
 * @package NewsDesk\AI\Application\Ai\Exception
 */

namespace NewsDesk\AI\Application\Ai\Exception;

defined( 'ABSPATH' ) || exit;

final class RetryableProviderException extends ProviderException {
}
