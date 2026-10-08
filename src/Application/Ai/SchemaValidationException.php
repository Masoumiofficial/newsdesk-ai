<?php
/**
 * Raised when AI output violates the versioned JSON Schema (§19).
 *
 * @package NewsDesk\AI\Application\Ai
 */

namespace NewsDesk\AI\Application\Ai;

defined( 'ABSPATH' ) || exit;

final class SchemaValidationException extends \RuntimeException {

	/** @var string[] */
	private $errors;

	/**
	 * @param string[] $errors JSON-pointer style locations, e.g. /claims/0/claim_text
	 */
	public function __construct( array $errors ) {
		$this->errors = $errors;
		parent::__construct( 'Schema validation failed: ' . implode( '; ', array_slice( $errors, 0, 6 ) ) );
	}

	/**
	 * @return string[]
	 */
	public function errors(): array {
		return $this->errors;
	}
}
