<?php
/**
 * Dispatch hub: prompt resolve → injection defense → budget gate → provider chain
 * (retryable-only fallback, ARCHITECTURE.md §9.1) → JSON schema validation (§19) →
 * business-rule validation → usage ledger (§22). Keys never enter logs (§23).
 *
 * @package NewsDesk\AI\Application\Ai
 */

namespace NewsDesk\AI\Application\Ai;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Ai\Exception\NonRetryableProviderException;
use NewsDesk\AI\Application\Ai\Exception\ProviderException;
use NewsDesk\AI\Application\Ai\Exception\RetryableProviderException;
use NewsDesk\AI\Application\Contracts\AiUsageRepositoryInterface;
use NewsDesk\AI\Application\Contracts\PromptRepositoryInterface;
use NewsDesk\AI\Application\NewsroomSettings;
use NewsDesk\AI\Domain\Entity\AiUsageRecord;
use NewsDesk\AI\Logging\Contracts\LoggerInterface;
use NewsDesk\AI\Support\Random;
use NewsDesk\AI\Support\Time;

final class AiGateway {

	/** @var ProviderRegistry */
	private $registry;
	/** @var AiUsageRepositoryInterface */
	private $usage;
	/** @var NewsroomSettings */
	private $settings;
	/** @var LoggerInterface */
	private $logger;
	/** @var PromptRegistry */
	private $prompts;
	/** @var PromptInjectionGuard */
	private $guard;
	/** @var JsonSchemaValidator */
	private $validator;

	public function __construct(
		ProviderRegistry $registry,
		AiUsageRepositoryInterface $usage,
		NewsroomSettings $settings,
		LoggerInterface $logger,
		?PromptRepositoryInterface $promptRepo = null
	) {
		$this->registry  = $registry;
		$this->usage     = $usage;
		$this->settings  = $settings;
		$this->logger    = $logger;
		$this->prompts   = new PromptRegistry( $promptRepo );
		$this->guard     = new PromptInjectionGuard();
		$this->validator = new JsonSchemaValidator();
	}

	/**
	 * Structured generation with the full §19 chain.
	 *
	 * @param string   $promptId   e.g. research.synthesis
	 * @param string   $language   fa|en
	 * @param array    $vars       {story_title, content, …}
	 * @param string   $schemaId   newsdesk.research.v1 | …
	 * @param int      $jobId
	 * @param string   $component  usage ledger component, e.g. research.synthesis
	 * @param callable|null $businessRule (array $data): ?array errors — null/[] = pass
	 * @return array{data: array, provider: string, model: string, prompt_version: string, schema_version: string, usage: array{input: int, output: int}}
	 * @throws NonRetryableProviderException BUDGET_EXCEEDED / SCHEMA / BUSINESS_RULE / AUTH / NO_PROVIDER_CONFIGURED
	 * @throws RetryableProviderException    ALL_PROVIDERS_FAILED
	 */
	public function structured( string $promptId, string $language, array $vars, string $schemaId, int $jobId, string $component, ?callable $businessRule = null ): array {
		$prompt = $this->prompts->resolve( $promptId, $language );
		if ( '' === $prompt['content'] ) {
			throw new NonRetryableProviderException( 'PROMPT_MISSING', 'Prompt template missing: ' . $promptId );
		}

		// §25: external content is DATA — wrapped, sanitized, capped.
		if ( isset( $vars['content'] ) ) {
			$vars['content'] = $this->guard->wrap( (string) $vars['content'] );
		}
		$schema = Prompts::schema( $schemaId );
		if ( empty( $schema ) ) {
			throw new NonRetryableProviderException( 'SCHEMA_MISSING', 'Unknown schema id: ' . $schemaId );
		}
		// v1.3.2: the model must SEE the schema it is asked to satisfy.
		$vars['schema'] = self::schemaForPrompt( $schema );
		$user = Prompts::fill( $prompt['content'], $vars );

		$messages = $this->guard->dataTreatedAsData( '', $user );

		$response = $this->dispatch( $messages, $jobId, $component, true, $schemaId );
		$data     = $response->structured;
		if ( ! is_array( $data ) ) {
			throw new NonRetryableProviderException( 'SCHEMA', 'Provider did not return JSON for ' . $schemaId );
		}
		$data = self::repair( $data, $schema );
		try {
			$this->validator->validate( $data, $schema );
		} catch ( SchemaValidationException $e ) {
			// v1.3.2: one guided retry — hand the exact violations back to the model.
			$this->logger->warning( 'AI output failed schema validation — retrying with errors', array( 'schema' => $schemaId, 'errors' => array_slice( $e->errors(), 0, 5 ) ), 'ai.gateway', 'SCHEMA_RETRY', $jobId );
			$fixMsg = $messages;
			$fixMsg[] = array( 'role' => 'assistant', 'content' => json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
			$fixMsg[] = array( 'role' => 'user', 'content' => "Your JSON violated the schema. Fix ONLY these problems and return the complete corrected JSON (same key names, no extra keys, no commentary):\n- " . implode( "\n- ", array_slice( $e->errors(), 0, 12 ) ) );
			$response = $this->dispatch( $fixMsg, $jobId, $component, true, $schemaId );
			$data     = is_array( $response->structured ) ? self::repair( $response->structured, $schema ) : array();
			try {
				$this->validator->validate( $data, $schema );
			} catch ( SchemaValidationException $e2 ) {
				$this->logger->warning( 'AI output failed schema validation', array( 'schema' => $schemaId, 'errors' => array_slice( $e2->errors(), 0, 5 ) ), 'ai.gateway', 'SCHEMA_FAIL', $jobId );
				throw new NonRetryableProviderException( 'SCHEMA', 'AI output violates ' . $schemaId . ': ' . implode( '; ', array_slice( $e2->errors(), 0, 3 ) ) );
			}
		}
		if ( null !== $businessRule ) {
			$errors = $businessRule( $data );
			if ( is_array( $errors ) && $errors ) {
				$this->logger->warning( 'AI output failed business rules', array( 'schema' => $schemaId, 'errors' => array_slice( $errors, 0, 3 ) ), 'ai.gateway', 'BUSINESS_RULE_FAIL', $jobId );
				throw new NonRetryableProviderException( 'BUSINESS_RULE', implode( '; ', $errors ) );
			}
		}

		return array(
			'data'           => $data,
			'provider'       => $response->provider,
			'model'          => $response->model,
			'prompt_version' => $prompt['prompt_id'] . '@v' . $prompt['version'],
			'schema_version' => $schemaId,
			'usage'          => array( 'input' => $response->inputTokens, 'output' => $response->outputTokens ),
		);
	}

	/**
	 * Free-form chat (no schema) — still injection-guarded and ledgered.
	 *
	 * @throws NonRetryableProviderException
	 * @throws RetryableProviderException
	 */
	public function chat( array $messages, int $jobId, string $component, array $opts = array() ): AiResponse {
		return $this->dispatch( $messages, $jobId, $component, false, '', $opts );
	}

	/**
	 * Provider chain with retryable-only fallback.
	 *
	 * @throws NonRetryableProviderException
	 * @throws RetryableProviderException
	 */
	/**
	 * Image generation (Phase 5, §66). Same chain discipline as dispatch():
	 * budget gate first, retryable-only fallback across configured providers.
	 * A provider without a documented image contract (Gemini REST) is skipped,
	 * not fatal. Usage is ledgered with 0 tokens (image jobs are not token
	 * priced in the vendor docs — see ARCHITECTURE.md §9.3).
	 *
	 * @throws NonRetryableProviderException BUDGET_EXCEEDED / NO_PROVIDER_CONFIGURED / NOT_SUPPORTED
	 * @throws RetryableProviderException    ALL_PROVIDERS_FAILED
	 */
	public function image( string $prompt, int $jobId, array $opts = array() ): AiResponse {
		$this->assertBudget( $jobId );

		$providers = $this->registry->configured();
		if ( ! $providers ) {
			throw new NonRetryableProviderException( 'NO_PROVIDER_CONFIGURED', 'No AI provider has an API key configured.' );
		}

		$opts = array_merge(
			array(
				'model'   => '',
				'size'    => $this->settings->imageSize(),
				'timeout' => (int) $this->settings->aiTimeout(),
			),
			$opts
		);

		$lastRetryable = null;
		foreach ( $providers as $provider ) {
			$opts['model'] = '' !== $opts['model'] ? $opts['model'] : $this->modelFor( $provider->id() );
			$opts['model'] = 'gapgpt' === $provider->id() ? $this->settings->imageModel() : $opts['model'];
			$start         = microtime( true );
			try {
				$response = $provider->generateImage( $prompt, $opts );
				$this->record( $provider, $response, $jobId, 'image.generate', $start );
				if ( '' === $response->errorCode ) {
					return $response;
				}
				if ( 'NOT_SUPPORTED' === $response->errorCode ) {
					continue; // provider has no documented image contract — try next
				}
				if ( $response->retryable ) {
					$lastRetryable = new RetryableProviderException( $response->errorCode );
					$this->logger->warning( 'Image attempt failed (retryable)', array( 'provider' => $response->provider, 'code' => $response->errorCode ), 'ai.gateway', 'IMAGE_RETRYABLE', $jobId );
					continue;
				}
				throw new NonRetryableProviderException( $response->errorCode, 'Image request failed: ' . $response->errorCode );
			} catch ( NonRetryableProviderException $e ) {
				if ( 'NOT_SUPPORTED' === $e->codeName() ) {
					continue;
				}
				$this->logger->error( 'Image attempt failed (non-retryable)', array( 'provider' => $provider->id(), 'code' => $e->codeName() ), 'ai.gateway', 'IMAGE_NON_RETRYABLE', $jobId );
				throw $e;
			} catch ( RetryableProviderException $e ) {
				$lastRetryable = $e;
				$this->logger->warning( 'Image transport/rate failure', array( 'provider' => $provider->id(), 'code' => $e->codeName() ), 'ai.gateway', 'IMAGE_RETRYABLE', $jobId );
			}
		}

		if ( null !== $lastRetryable ) {
			throw new RetryableProviderException( 'ALL_PROVIDERS_FAILED', $lastRetryable->codeName() );
		}
		throw new NonRetryableProviderException( 'NOT_SUPPORTED', 'No configured provider has a documented image contract.' );
	}

	/** §22: cost estimate = tokens/1e6 × model price; images = per-image price (0 when unset). */
	private function estimateCost( AiResponse $response, string $component ): float {
		if ( 'image.generate' === $component ) {
			return round( $this->settings->pricePerImage(), 6 );
		}
		$price = $this->settings->priceForModel( (string) $response->model );
		if ( $price <= 0 ) {
			return 0.0;
		}
		return round( ( (float) $response->inputTokens + (float) $response->outputTokens ) / 1000000 * $price, 6 );
	}

	private function assertBudget( int $jobId ): void {
		// Budget gate BEFORE any network call (§22).
		$budget = (int) $this->settings->aiBudgetPerJob();
		if ( $budget > 0 ) {
			$used = $this->usage->tokensForJob( $jobId );
			if ( ( $used['input'] + $used['output'] ) >= $budget ) {
				$this->logger->warning( 'AI budget exhausted for job', array( 'budget' => $budget, 'used' => $used ), 'ai.gateway', 'BUDGET_EXCEEDED', $jobId );
				throw new NonRetryableProviderException( 'BUDGET_EXCEEDED', 'Per-job AI budget reached.' );
			}
		}
	}

	private function dispatch( array $messages, int $jobId, string $component, bool $json, string $schemaId, array $extraOpts = array() ): AiResponse {
		$this->assertBudget( $jobId );

		$providers = $this->registry->configured();
		if ( ! $providers ) {
			throw new NonRetryableProviderException( 'NO_PROVIDER_CONFIGURED', 'No AI provider has an API key configured.' );
		}

		$opts = array(
			'model'        => '',
			'temperature'  => (float) $this->settings->aiTemperature(),
			'max_tokens'   => (int) $this->settings->aiMaxTokens(),
			'timeout'      => (int) $this->settings->aiTimeout(),
		);
		foreach ( $extraOpts as $k => $v ) {
			$opts[ $k ] = $v;
		}

		$lastRetryable = null;
		foreach ( $providers as $provider ) {
			$opts['model'] = $this->modelFor( $provider->id() );
			$start         = microtime( true );
			try {
				$response = $json
					? $provider->generateStructured( $messages, $opts )
					: $provider->chat( $messages, $opts );
				$this->record( $provider, $response, $jobId, $component, $start );
				if ( '' === $response->errorCode ) {
					return $response;
				}
				if ( $response->retryable ) {
					$lastRetryable = new RetryableProviderException( $response->errorCode );
					$this->logger->warning( 'AI attempt failed (retryable)', array( 'provider' => $response->provider, 'code' => $response->errorCode ), 'ai.gateway', 'AI_RETRYABLE', $jobId );
					continue;
				}
				// Auth / schema / budget / invalid request: never blind-fallback.
				throw new NonRetryableProviderException( $response->errorCode, 'AI request failed: ' . $response->errorCode );
			} catch ( NonRetryableProviderException $e ) {
				$this->logger->error( 'AI attempt failed (non-retryable)', array( 'provider' => $provider->id(), 'code' => $e->codeName() ), 'ai.gateway', 'AI_NON_RETRYABLE', $jobId );
				throw $e;
			} catch ( RetryableProviderException $e ) {
				$lastRetryable = $e;
				$this->logger->warning( 'AI transport/rate failure', array( 'provider' => $provider->id(), 'code' => $e->codeName() ), 'ai.gateway', 'AI_RETRYABLE', $jobId );
			}
		}

		if ( null !== $lastRetryable ) {
			throw new RetryableProviderException( 'ALL_PROVIDERS_FAILED', $lastRetryable->codeName() );
		}
		throw new RetryableProviderException( 'ALL_PROVIDERS_FAILED', 'No provider produced a response.' );
	}

	/**
	 * Compact, model-friendly rendering of a JSON schema (drops nothing the
	 * model needs: types, enums, required, length/item limits).
	 */
	public static function schemaForPrompt( array $schema ): string {
		return (string) json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * v1.3.2: deterministic repair of the most common LLM deviations BEFORE
	 * validation. Never invents content; only coerces shape:
	 *  - unwraps a single top-level wrapper ({"article": {...}})
	 *  - drops unknown keys where additionalProperties=false
	 *  - string → [string] for array fields; scalar arrays → strings for string fields
	 *  - trims strings over maxLength; drops empty strings from arrays
	 *  - lower-cases enum values, maps unknown enum → first allowed value
	 *  - casts numeric strings for number/integer fields
	 */
	public static function repair( $data, array $schema ) {
		$type = $schema['type'] ?? null;

		if ( 'object' === $type ) {
			if ( ! is_array( $data ) ) {
				return $data;
			}
			$props = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : array();
			// Unwrap {"wrapper": {...all expected keys...}}.
			if ( $props && 1 === count( $data ) ) {
				$only = reset( $data );
				if ( is_array( $only ) && count( array_intersect_key( $only, $props ) ) >= max( 1, (int) ( count( $props ) / 2 ) ) ) {
					$data = $only;
				}
			}
			$out = array();
			foreach ( $data as $k => $v ) {
				$key = (string) $k;
				if ( ! isset( $props[ $key ] ) ) {
					// tolerate case / separator variants: metaDescription → meta_description
					$alt = strtolower( preg_replace( '/(?<!^)[A-Z]/', '_$0', $key ) );
					$alt = str_replace( array( '-', ' ' ), '_', $alt );
					if ( isset( $props[ $alt ] ) && ! isset( $data[ $alt ] ) ) {
						$key = $alt;
					} elseif ( false === ( $schema['additionalProperties'] ?? true ) ) {
						continue;
					}
				}
				$out[ $key ] = isset( $props[ $key ] ) ? self::repair( $v, $props[ $key ] ) : $v;
			}
			return $out;
		}

		if ( 'array' === $type ) {
			if ( is_string( $data ) ) {
				$data = '' === trim( $data ) ? array() : array( $data );
			} elseif ( ! is_array( $data ) ) {
				return $data;
			} elseif ( array_keys( $data ) !== range( 0, count( $data ) - 1 ) ) {
				$data = array_values( $data ); // object used as list
			}
			$items = isset( $schema['items'] ) && is_array( $schema['items'] ) ? $schema['items'] : null;
			$out   = array();
			foreach ( $data as $v ) {
				$v = null !== $items ? self::repair( $v, $items ) : $v;
				if ( is_string( $v ) && '' === trim( $v ) ) {
					continue;
				}
				$out[] = $v;
			}
			if ( isset( $schema['maxItems'] ) && count( $out ) > (int) $schema['maxItems'] ) {
				$out = array_slice( $out, 0, (int) $schema['maxItems'] );
			}
			return $out;
		}

		if ( 'string' === $type ) {
			if ( is_array( $data ) ) {
				$flat = array_filter( $data, 'is_scalar' );
				$data = implode( ' ', array_map( 'strval', $flat ) );
			} elseif ( is_scalar( $data ) ) {
				$data = (string) $data;
			} else {
				return $data;
			}
			$data = trim( $data );
			if ( isset( $schema['enum'] ) && is_array( $schema['enum'] ) && ! in_array( $data, $schema['enum'], true ) ) {
				$lower = strtolower( $data );
				foreach ( $schema['enum'] as $e ) {
					if ( strtolower( (string) $e ) === $lower ) {
						return $e;
					}
				}
				return (string) reset( $schema['enum'] );
			}
			if ( isset( $schema['maxLength'] ) && mb_strlen( $data ) > (int) $schema['maxLength'] ) {
				$max  = (int) $schema['maxLength'];
				$cut  = mb_substr( $data, 0, $max );
				$last = max( mb_strrpos( $cut, ' ' ) ?: 0, mb_strrpos( $cut, '،' ) ?: 0, mb_strrpos( $cut, '.' ) ?: 0 );
				$data = $last > $max * 0.6 ? rtrim( mb_substr( $cut, 0, $last ), ' ,،.' ) : $cut;
			}
			return $data;
		}

		if ( 'number' === $type || 'integer' === $type ) {
			if ( is_string( $data ) && is_numeric( trim( $data ) ) ) {
				return 'integer' === $type ? (int) trim( $data ) : (float) trim( $data );
			}
			return $data;
		}

		return $data;
	}

	private function modelFor( string $providerId ): string {
		switch ( $providerId ) {
			case 'gapgpt':
				return $this->settings->aiModelGapGpt();
			case 'openai':
				return $this->settings->aiModelOpenAi();
			case 'gemini':
				return $this->settings->aiModelGemini();
		}
		return '';
	}

	private function record( $provider, AiResponse $response, int $jobId, string $component, float $start ): void {
		$usage              = new AiUsageRecord();
		$usage->provider    = $response->provider ?: $provider->id();
		$usage->model       = $response->model ?: '';
		$usage->jobId       = $jobId;
		$usage->requestId   = '' !== $response->requestId ? $response->requestId : Random::uuid4();
		$usage->component   = $component;
		$usage->inputTokens = $response->inputTokens;
		$usage->outputTokens = $response->outputTokens;
		$usage->estimatedCost = $this->estimateCost( $response, $component );
		$usage->currency    = $this->settings->aiCurrency();
		$usage->createdAt   = Time::now();
		$this->usage->record( $usage );
	}
}
