<?php
namespace NewsDesk\AI\Support\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Transport-level failure (timeout, connection, non-2xx after redirects, size overflow).
 */
final class HttpFetchException extends \RuntimeException {

	/** @var string */
	private $errorCode;

	public function __construct( string $errorCode, string $message ) {
		$this->errorCode = $errorCode;
		parent::__construct( $message );
	}

	public function errorCode(): string {
		return $this->errorCode;
	}
}
