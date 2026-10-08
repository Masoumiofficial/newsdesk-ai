<?php
/**
 * Secrets from environment variables: NEWSDESK_<UPPER_SNAKE_KEY>.
 *
 * @package NewsDesk\AI\Infrastructure\Secrets
 */

namespace NewsDesk\AI\Infrastructure\Secrets;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Contracts\SecretStorageInterface;

final class EnvironmentSecretStorage implements SecretStorageInterface {

	private const PREFIX = 'NEWSDESK_';

	public function get( string $key ): ?string {
		$env = self::PREFIX . strtoupper( str_replace( array( '-', '.' ), '_', $key ) );
		$value = getenv( $env );
		return ( false === $value || '' === $value ) ? null : (string) $value;
	}

	public function set( string $key, string $value ): bool {
		return false; // env is read-only at runtime
	}

	public function delete( string $key ): bool {
		return true; // no-op
	}

	public function has( string $key ): bool {
		return null !== $this->get( $key );
	}

	public function name(): string {
		return 'environment';
	}
}
