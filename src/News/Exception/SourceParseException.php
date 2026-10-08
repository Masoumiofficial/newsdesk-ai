<?php
namespace NewsDesk\AI\News\Exception;

defined( 'ABSPATH' ) || exit;

class SourceParseException extends \RuntimeException {

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
