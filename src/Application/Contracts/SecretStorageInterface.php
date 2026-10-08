<?php
namespace NewsDesk\AI\Application\Contracts;

defined( 'ABSPATH' ) || exit;

interface SecretStorageInterface {

	public function get( string $key ): ?string;

	public function set( string $key, string $value ): bool;

	public function delete( string $key ): bool;

	public function has( string $key ): bool;

	public function name(): string;
}
