<?php
namespace NewsDesk\AI\Support\Http;

defined( 'ABSPATH' ) || exit;

final class UrlValidationException extends \RuntimeException {

	/** @var string */
	private $reason;

	public function __construct( string $url, string $reason ) {
		$this->reason = $reason;
		parent::__construct( sprintf( 'URL rejected (%s): %s', $reason, $url ) );
	}

	public function reason(): string {
		return $this->reason;
	}
}
