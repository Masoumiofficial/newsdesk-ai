<?php
/**
 * Base AI provider exception.
 *
 * @package NewsDesk\AI\Application\Ai\Exception
 */

namespace NewsDesk\AI\Application\Ai\Exception;

defined( 'ABSPATH' ) || exit;

class ProviderException extends \RuntimeException {

	/** @var string */
	protected $providerCode;

	public function __construct( string $providerCode, string $message = '' ) {
		$this->providerCode = $providerCode;
		parent::__construct( '' !== $message ? $message : $providerCode );
	}

	public function codeName(): string {
		return $this->providerCode;
	}
}
