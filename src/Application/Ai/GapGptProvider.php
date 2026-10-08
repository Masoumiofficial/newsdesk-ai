<?php
/**
 * GapGPT — OFFICIAL contract (gapgpt.app, verified 2026-08-30, ARCHITECTURE.md §9.3):
 * OpenAI-SDK-compatible · base https://api.gapgpt.app/v1 (alt CDN
 * https://api.gapapi.com/v1) · Bearer key · POST /v1/chat/completions.
 *
 * @package NewsDesk\AI\Application\Ai
 */

namespace NewsDesk\AI\Application\Ai;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Contracts\HttpClientInterface;

final class GapGptProvider extends OpenAICompatibleProvider {

	public const BASE_URL     = 'https://api.gapgpt.app/v1';
	public const ALT_BASE_URL = 'https://api.gapapi.com/v1';

	public function id(): string {
		return 'gapgpt';
	}

	public static function make( HttpClientInterface $http, string $apiKey, string $model, bool $useAltCdn = false, int $timeout = 60 ): self {
		return new self( $http, $useAltCdn ? self::ALT_BASE_URL : self::BASE_URL, $apiKey, $model, $timeout );
	}
}
