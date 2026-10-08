<?php
/**
 * Tiny DI container (composition root usage only).
 *
 * @package NewsDesk\AI\Support
 */

namespace NewsDesk\AI\Support;

defined( 'ABSPATH' ) || exit;

final class Container {

	/** @var array<string, callable> */
	private $factories = array();

	/** @var array<string, bool> */
	private $shared = array();

	/** @var array<string, mixed> */
	private $instances = array();

	/**
	 * Bind a factory (shared by default).
	 *
	 * @param string   $id Service id (class name).
	 * @param callable $factory Factory (Container) => object.
	 */
	public function bind( string $id, callable $factory, bool $shared = true ): void {
		$this->factories[ $id ] = $factory;
		$this->shared[ $id ]    = $shared;
		unset( $this->instances[ $id ] );
	}

	/**
	 * Alias: shared binding.
	 */
	public function singleton( string $id, callable $factory ): void {
		$this->bind( $id, $factory, true );
	}

	/**
	 * Register a pre-built instance.
	 *
	 * @param mixed $object
	 */
	public function instance( string $id, $object ): void {
		$this->factories[ $id ] = static function () use ( $object ) {
			return $object;
		};
		$this->shared[ $id ]    = true;
		$this->instances[ $id ] = $object;
	}

	/**
	 * Has binding?
	 */
	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] );
	}

	/**
	 * Resolve.
	 *
	 * @return mixed
	 */
	public function get( string $id ) {
		if ( isset( $this->instances[ $id ] ) ) {
			return $this->instances[ $id ];
		}
		if ( ! isset( $this->factories[ $id ] ) ) {
			throw new \RuntimeException( sprintf( 'No binding for %s', $id ) );
		}
		$object = call_user_func( $this->factories[ $id ], $this );
		if ( ! empty( $this->shared[ $id ] ) ) {
			$this->instances[ $id ] = $object;
		}
		return $object;
	}

	/**
	 * Resolve or throw a readable error (dev aid).
	 *
	 * @return mixed
	 */
	public function make( string $id ) {
		return $this->get( $id );
	}
}
