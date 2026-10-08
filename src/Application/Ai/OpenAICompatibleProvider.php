<?php
/**
 * OpenAI wire-compatible provider base (ARCHITECTURE.md §9.3: GapGPT + OpenAI both
 * speak `POST {base}/chat/completions` with `Authorization: Bearer`).
 *
 * @package NewsDesk\AI\Application\Ai
 */

namespace NewsDesk\AI\Application\Ai;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Ai\Exception\NonRetryableProviderException;
use NewsDesk\AI\Application\Ai\Exception\ProviderException;
use NewsDesk\AI\Application\Ai\Exception\RetryableProviderException;
use NewsDesk\AI\Application\Contracts\AIProviderInterface;
use NewsDesk\AI\Application\Contracts\HttpClientInterface;
use NewsDesk\AI\Logging\Redaction;
use NewsDesk\AI\Support\Http\HttpFetchException;

abstract class OpenAICompatibleProvider implements AIProviderInterface {

	/** @var HttpClientInterface */
	protected $http;
	/** @var string */
	protected $baseUrl;
	/** @var string */
	protected $apiKey;
	/** @var string */
	protected $defaultModel;
	/** @var int */
	protected $timeout;

	public function __construct( HttpClientInterface $http, string $baseUrl, string $apiKey, string $defaultModel, int $timeout = 60 ) {
		$this->http         = $http;
		$this->baseUrl      = rtrim( $baseUrl, '/' ) . '/';
		$this->apiKey       = $apiKey;
		$this->defaultModel = $defaultModel;
		$this->timeout      = $timeout;
	}

	public function isConfigured(): bool {
		return '' !== trim( $this->apiKey );
	}

	public function chat( array $messages, array $opts = array() ): AiResponse {
		return $this->complete( $messages, $opts );
	}

	public function generateStructured( array $messages, array $opts = array() ): AiResponse {
		$opts['json'] = true;
		$response     = $this->complete( $messages, $opts );
		if ( $response->isOk() ) {
			$decoded = json_decode( $response->content, true );
			if ( ! is_array( $decoded ) ) {
				$response->errorCode = 'SCHEMA';
				$response->retryable = false;
				return $response;
			}
			$response->structured = $decoded;
		}
		return $response;
	}

	/**
	 * POST {base}/images/generations (ARCHITECTURE.md §9.3: model, prompt, size →
	 * data[0].url — the file is downloaded separately). n stays 1: one lead
	 * image per story (§66); the URL is re-validated by ImageService (§52).
	 */
	public function generateImage( string $prompt, array $opts = array() ): AiResponse {
		$model = isset( $opts['model'] ) && '' !== $opts['model'] ? $opts['model'] : $this->defaultModel;
		$size  = isset( $opts['size'] ) && '' !== $opts['size'] ? $opts['size'] : '1024x1024';
		$payload = array(
			'model'  => $model,
			'prompt' => $prompt,
			'n'      => 1,
			'size'   => $size,
		);
		try {
			$http = $this->http->request(
				'POST',
				$this->baseUrl . 'images/generations',
				array(
					'timeout'   => isset( $opts['timeout'] ) ? (int) $opts['timeout'] : $this->timeout,
					'max_bytes' => 1048576,
					'headers'   => array(
						'Authorization' => 'Bearer ' . $this->apiKey,
						'Content-Type'  => 'application/json',
					),
					'body'      => json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				)
			);
		} catch ( HttpFetchException $e ) {
			throw new RetryableProviderException( 'TRANSPORT', 'Image transport failure: ' . $e->errorCode() );
		}

		$response = new AiResponse();
		$response->provider = $this->id();
		$response->model    = $model;

		if ( ! $http->isOk() ) {
			$response->errorCode = $this->mapHttpError( $http->status );
			$response->retryable = $this->isRetryable( $response->errorCode );
			return $response;
		}
		$data = json_decode( $http->body, true );
		if ( ! is_array( $data ) || ! isset( $data['data'][0]['url'] ) ) {
			$response->errorCode = 'EMPTY_RESPONSE';
			$response->retryable = true;
			return $response;
		}
		$response->imageUrl = (string) $data['data'][0]['url'];
		return $response;
	}

	/**
	 * @param array $messages
	 * @param array $opts model, temperature, max_tokens, json, component
	 */
	private function complete( array $messages, array $opts ): AiResponse {
		$start = microtime( true );
		$model = isset( $opts['model'] ) && '' !== $opts['model'] ? $opts['model'] : $this->defaultModel;

		$payload = array(
			'model'    => $model,
			'messages' => $messages,
		);
		if ( isset( $opts['temperature'] ) ) {
			$payload['temperature'] = (float) $opts['temperature'];
		}
		if ( isset( $opts['max_tokens'] ) && (int) $opts['max_tokens'] > 0 ) {
			$payload['max_tokens'] = (int) $opts['max_tokens'];
		}
		if ( ! empty( $opts['json'] ) ) {
			$payload['response_format'] = array( 'type' => 'json_object' );
		}

		try {
			$http = $this->http->request(
				'POST',
				$this->baseUrl . 'chat/completions',
				array(
					'timeout'   => isset( $opts['timeout'] ) ? (int) $opts['timeout'] : $this->timeout,
					'max_bytes' => 4194304,
					'headers'   => array(
						'Authorization' => 'Bearer ' . $this->apiKey,
						'Content-Type'  => 'application/json',
					),
					'body'      => json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				)
			);
		} catch ( HttpFetchException $e ) {
			throw new RetryableProviderException( 'TRANSPORT', 'AI transport failure: ' . $e->errorCode() );
		}

		$response = new AiResponse();
		$response->provider = $this->id();
		$response->model    = $model;
		$response->latencyMs = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( ! $http->isOk() ) {
			$response->errorCode = $this->mapHttpError( $http->status );
			$response->retryable = $this->isRetryable( $response->errorCode );
			return $response;
		}

		$data = json_decode( $http->body, true );
		if ( ! is_array( $data ) ) {
			$response->errorCode = 'DECODE';
			$response->retryable = false;
			return $response;
		}
		$response->content = (string) ( $data['choices'][0]['message']['content'] ?? '' );
		if ( isset( $data['usage']['prompt_tokens'] ) ) {
			$response->inputTokens = (int) $data['usage']['prompt_tokens'];
		}
		if ( isset( $data['usage']['completion_tokens'] ) ) {
			$response->outputTokens = (int) $data['usage']['completion_tokens'];
		}
		if ( '' !== trim( $response->content ) || ! isset( $data['choices'][0]['message'] ) ) {
			return $response;
		}
		$response->errorCode = 'EMPTY_RESPONSE';
		$response->retryable = true;
		return $response;
	}

	protected function mapHttpError( int $status ): string {
		switch ( $status ) {
			case 400:
				return 'INVALID_REQUEST';
			case 401:
			case 403:
				return 'AUTH';
			case 404:
				return 'UNKNOWN_ENDPOINT';
			case 422:
				return 'SCHEMA';
			case 429:
				return 'RATE_LIMIT';
			default:
				return $status >= 500 && $status < 600 ? 'SERVER' : 'HTTP_' . $status;
		}
	}

	protected function isRetryable( string $code ): bool {
		return in_array( $code, array( 'RATE_LIMIT', 'SERVER', 'TRANSPORT', 'EMPTY_RESPONSE', 'HTTP_408', 'HTTP_409' ), true );
	}

	/** Error text is sanitized: never echo keys, truncate, redact. */
	protected function safeMessage( string $raw ): string {
		$raw = Redaction::redact( $raw );
		return function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $raw ) : strip_tags( $raw );
	}
}
