<?php
namespace NewsDesk\AI\Infrastructure\Secrets;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Contracts\SecretStorageInterface;

final class SecretFactory {

	public const TYPES = array( 'wordpress', 'environment' );

	public static function make( string $type ): SecretStorageInterface {
		switch ( $type ) {
			case 'environment':
				return new EnvironmentSecretStorage();
			case 'wordpress':
			default:
				return new WordPressSecretStorage();
		}
	}
}
