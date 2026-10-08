<?php
namespace NewsDesk\AI\Application\Exception;

defined( 'ABSPATH' ) || exit;

class InputValidationException extends \RuntimeException {

	/** @var array<string, string> */
	private $errors;

	public function __construct( array $errors, string $message = 'Validation failed' ) {
		$this->errors = $errors;
		parent::__construct( $message . ': ' . implode( ' | ', $errors ) );
	}

	/**
	 * @return array<string, string>
	 */
	public function errors(): array {
		return $this->errors;
	}
}
