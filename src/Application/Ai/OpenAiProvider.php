<?php
/**
 * OpenAI — official contract (platform.openai.com): https://api.openai.com/v1,
 * Bearer auth, POST /v1/chat/completions, gpt-* models (admin-configurable).
 *
 * @package NewsDesk\AI\Application\Ai
 */

namespace NewsDesk\AI\Application\Ai;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Contracts\HttpClientInterface;

final class OpenAiProvider extends OpenAICompatibleProvider {

	public const BASE_URL = 'https://api.openai.com/v1';

	public function id(): string {
		return 'openai';
	}

	public static function make( HttpClientInterface $http, string $apiKey, string $model, int $timeout = 60 ): self {
		return new self( $http, self::BASE_URL, $apiKey, $model, $timeout );
	}
}
