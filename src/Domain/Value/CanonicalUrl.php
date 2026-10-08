<?php
namespace NewsDesk\AI\Domain\Value;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Domain\Exception\DomainException;

/**
 * Immutable canonical URL (always produced by UrlCanonicalizer).
 */
final class CanonicalUrl {

	/** @var string */
	private $value;

	public function __construct( string $value ) {
		if ( '' === $value ) {
			throw new DomainException( 'Canonical URL must not be empty' );
		}
		$this->value = $value;
	}

	public function __toString(): string {
		return $this->value;
	}

	public function value(): string {
		return $this->value;
	}
}
