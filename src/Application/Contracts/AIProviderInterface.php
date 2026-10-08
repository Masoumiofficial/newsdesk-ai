<?php
/**
 * Provider-neutral AI boundary (§18, ARCHITECTURE.md §9.1).
 *
 * Providers know NOTHING about story/research concepts: they see messages[]
 * and options only. All business logic lives in Application services.
 *
 * @package NewsDesk\AI\Application\Contracts
 */

namespace NewsDesk\AI\Application\Contracts;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Ai\AiResponse;

interface AIProviderInterface {

	/** Provider id: 'gapgpt' | 'openai' | 'gemini'. */
	public function id(): string;

	/** Secret present (no network, no throw). */
	public function isConfigured(): bool;

	/**
	 * Free-form chat completion.
	 *
	 * @param array $messages [['role'=>'system'|'user'|'assistant','content'=>string], ...]
	 * @param array $opts     model, temperature, max_tokens, response_format, component
	 * @throws \NewsDesk\AI\Application\Ai\Exception\ProviderException
	 */
	public function chat( array $messages, array $opts = array() ): AiResponse;

	/**
	 * Structured (JSON) generation — provider-specific JSON mode when available,
	 * JSON-in-prompt otherwise. The caller ALWAYS validates with JsonSchemaValidator.
	 */
	public function generateStructured( array $messages, array $opts = array() ): AiResponse;

	/**
	 * Image generation (Phase 5, §66). Providers without a documented image
	 * contract MUST return AiResponse::failure('NOT_SUPPORTED', false) — never
	 * invent an endpoint (ARCHITECTURE.md: only GapGPT/OpenAI-compatible
	 * POST /v1/images/generations is documented).
	 *
	 * @param string $prompt final prompt text (already §25-guarded by the caller)
	 * @param array  $opts   model, size, timeout, n
	 */
	public function generateImage( string $prompt, array $opts = array() ): AiResponse;
}
