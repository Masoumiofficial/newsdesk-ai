<?php
/**
 * Gemini — official REST contract (ai.google.dev, per ARCHITECTURE.md §9.4):
 * https://generativelanguage.googleapis.com/v1beta · `x-goog-api-key` header ·
 * POST /models/{model}:generateContent · parts text.
 *
 * @package NewsDesk\AI\Application\Ai
 */

namespace NewsDesk\AI\Application\Ai;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Ai\Exception\RetryableProviderException;
use NewsDesk\AI\Application\Contracts\AIProviderInterface;
use NewsDesk\AI\Application\Contracts\HttpClientInterface;
use NewsDesk\AI\Support\Http\HttpFetchException;
use NewsDesk\AI\Support\Http\HttpResponse;

final class GeminiProvider implements AIProviderInterface {

	public const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';

	/** @var HttpClientInterface */
	private $http;
	/** @var string */
	private $apiKey;
	/** @var string */
	private $defaultModel;
	/** @var int */
	private $timeout;

	public function __construct( HttpClientInterface $http, string $apiKey, string $defaultModel, int $timeout = 60 ) {
		$this->http         = $http;
		$this->apiKey       = $apiKey;
		$this->defaultModel = $defaultModel;
		$this->timeout      = $timeout;
	}

	public function id(): string {
		return 'gemini';
	}

	public function isConfigured(): bool {
		return '' !== trim( $this->apiKey );
	}

	public function chat( array $messages, array $opts = array() ): AiResponse {
		return $this->complete( $messages, $opts );
	}

	public function generateStructured( array $messages, array $opts = array() ): AiResponse {
		$opts['mime'] = 'application/json';
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

	private function complete( array $messages, array $opts ): AiResponse {
		$start = microtime( true );
		$model = isset( $opts['model'] ) && '' !== $opts['model'] ? $opts['model'] : $this->defaultModel;

		$contents    = array();
		$systemParts = array();
		foreach ( $messages as $message ) {
			$role    = (string) ( $message['role'] ?? 'user' );
			$content = (string) ( $message['content'] ?? '' );
			if ( 'system' === $role ) {
				$systemParts[] = array( 'text' => $content );
				continue;
			}
			$contents[] = array(
				'role'  => 'assistant' === $role ? 'model' : 'user',
				'parts' => array( array( 'text' => $content ) ),
			);
		}

		$config = array();
		if ( isset( $opts['temperature'] ) ) {
			$config['temperature'] = (float) $opts['temperature'];
		}
		if ( isset( $opts['max_tokens'] ) && (int) $opts['max_tokens'] > 0 ) {
			$config['maxOutputTokens'] = (int) $opts['max_tokens'];
		}
		if ( ! empty( $opts['mime'] ) ) {
			$config['responseMimeType'] = (string) $opts['mime'];
		}
		$payload = array(
			'contents'         => $contents,
			'generationConfig' => $config,
		);
		if ( $systemParts ) {
			$payload['systemInstruction'] = array( 'parts' => $systemParts );
		}

		$endpoint = self::BASE_URL . '/models/' . rawurlencode( $model ) . ':generateContent';
		try {
			$http = $this->http->request(
				'POST',
				$endpoint,
				array(
					'timeout'   => isset( $opts['timeout'] ) ? (int) $opts['timeout'] : $this->timeout,
					'max_bytes' => 4194304,
					'headers'   => array(
						'x-goog-api-key' => $this->apiKey,
						'Content-Type'   => 'application/json',
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
			$response->errorCode = $this->mapError( $http );
			$response->retryable = $this->isRetryable( $response->errorCode );
			return $response;
		}

		$data = json_decode( $http->body, true );
		if ( ! is_array( $data ) || empty( $data['candidates'][0]['content']['parts'][0]['text'] ?? null ) ) {
			$response->errorCode = 'EMPTY_RESPONSE';
			$response->retryable = true;
			return $response;
		}
		$response->content = (string) $data['candidates'][0]['content']['parts'][0]['text'];
		if ( isset( $data['usageMetadata']['promptTokenCount'] ) ) {
			$response->inputTokens = (int) $data['usageMetadata']['promptTokenCount'];
		}
		if ( isset( $data['usageMetadata']['candidatesTokenCount'] ) ) {
			$response->outputTokens = (int) $data['usageMetadata']['candidatesTokenCount'];
		}
		return $response;
	}

	private function mapError( HttpResponse $http ): string {
		$status = $http->status;
		$data   = json_decode( $http->body, true );
		$err    = is_array( $data ) ? strtoupper( (string) ( $data['error']['status'] ?? '' ) ) : '';
		switch ( $status ) {
			case 400:
				return 'INVALID_REQUEST';
			case 401:
			case 403:
				return 'AUTH';
			case 404:
				return 'UNKNOWN_ENDPOINT';
			case 429:
				return 'RATE_LIMIT';
			default:
				if ( '' !== $err && in_array( $err, array( 'INVALID_ARGUMENT', 'PERMISSION_DENIED', 'NOT_FOUND', 'RESOURCE_EXHAUSTED' ), true ) ) {
					return 'INVALID_REQUEST';
				}
				return $status >= 500 && $status < 600 ? 'SERVER' : 'HTTP_' . $status;
		}
	}

	private function isRetryable( string $code ): bool {
		return in_array( $code, array( 'RATE_LIMIT', 'SERVER', 'TRANSPORT', 'EMPTY_RESPONSE', 'HTTP_408', 'HTTP_409' ), true );
	}

	public function generateImage( string $prompt, array $opts = array() ): AiResponse {
		// §20: no documented image contract for the Gemini REST endpoint — never invent one.
		return AiResponse::failure( 'NOT_SUPPORTED', false, $this->id(), '' );
	}
}
