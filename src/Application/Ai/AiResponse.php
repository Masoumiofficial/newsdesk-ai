<?php
/**
 * Provider-agnostic response envelope (ARCHITECTURE.md §9.1).
 *
 * @package NewsDesk\AI\Application\Ai
 */

namespace NewsDesk\AI\Application\Ai;

defined( 'ABSPATH' ) || exit;

final class AiResponse {

	/** @var string */
	public $requestId = '';
	/** @var string */
	public $provider = '';
	/** @var string */
	public $model = '';
	/** @var string */
	public $content = '';
	/** @var array|null */
	public $structured;
	/** @var int */
	public $inputTokens = 0;
	/** @var int */
	public $outputTokens = 0;
	/** @var string  URL of a generated image (images/generations, ARCHITECTURE.md §9.3). */
	public $imageUrl = '';
	/** @var string */
	public $errorCode = '';
	/** @var bool */
	public $retryable = false;
	/** @var int */
	public $latencyMs = 0;

	public function isOk(): bool {
		return '' === $this->errorCode;
	}

	public static function failure( string $code, bool $retryable, string $provider = '', string $model = '' ): self {
		$r             = new self();
		$r->provider   = $provider;
		$r->model      = $model;
		$r->errorCode  = $code;
		$r->retryable  = $retryable;
		return $r;
	}
}
