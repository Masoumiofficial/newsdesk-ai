<?php
namespace NewsDesk\AI\Support\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Result of an outbound HTTP fetch (immutable-ish DTO).
 */
final class HttpResponse {

	/** @var int */
	public $status = 0;
	/** @var array<string, string> */
	public $headers = array();
	/** @var string */
	public $body = '';
	/** @var string */
	public $finalUrl = '';
	/** @var string|null */
	public $error;
	/** @var int */
	public $durationMs = 0;

	public function isOk(): bool {
		return $this->status >= 200 && $this->status < 300;
	}

	public function isRedirect(): bool {
		return $this->status >= 300 && $this->status < 400;
	}

	public function header( string $name ): ?string {
		foreach ( $this->headers as $key => $value ) {
			if ( 0 === strcasecmp( $key, $name ) ) {
				return $value;
			}
		}
		return null;
	}
}
