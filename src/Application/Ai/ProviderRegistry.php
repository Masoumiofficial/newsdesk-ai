<?php
/**
 * Ordered provider chain (ARCHITECTURE.md §9.1): primary → fallback 1 → fallback 2.
 * Fallback happens ONLY for RetryableProviderException (never for auth/budget/schema).
 *
 * @package NewsDesk\AI\Application\Ai
 */

namespace NewsDesk\AI\Application\Ai;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Contracts\AIProviderInterface;

final class ProviderRegistry {

	/**
	 * @param AIProviderInterface[] $providers Ordered (id => provider)
	 */
	public function __construct( array $providers ) {
		$this->providers = $providers;
	}

	/** @var AIProviderInterface[] */
	private $providers;

	/**
	 * @return AIProviderInterface[]
	 */
	public function ordered(): array {
		return array_values( $this->providers );
	}

	public function configured(): array {
		$out = array();
		foreach ( $this->providers as $provider ) {
			if ( $provider->isConfigured() ) {
				$out[] = $provider;
			}
		}
		return $out;
	}

	public function hasConfigured(): bool {
		foreach ( $this->providers as $provider ) {
			if ( $provider->isConfigured() ) {
				return true;
			}
		}
		return false;
	}

	public function byId( string $id ): ?AIProviderInterface {
		foreach ( $this->providers as $provider ) {
			if ( $provider->id() === $id ) {
				return $provider;
			}
		}
		return null;
	}
}
