<?php
/**
 * Auth (401/403), invalid prompt (400), schema (422), budget — never blind-fallback (§9.1).
 *
 * @package NewsDesk\AI\Application\Ai\Exception
 */

namespace NewsDesk\AI\Application\Ai\Exception;

defined( 'ABSPATH' ) || exit;

final class NonRetryableProviderException extends ProviderException {
}
