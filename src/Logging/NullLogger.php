<?php
namespace NewsDesk\AI\Logging;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Logging\Contracts\LoggerInterface;

/**
 * No-op logger (tests, disabled logging).
 */
final class NullLogger implements LoggerInterface {

	public function debug( string $message, array $context = array(), string $component = '', string $event = '', ?int $jobId = null, ?string $correlationId = null ): void {
	}

	public function info( string $message, array $context = array(), string $component = '', string $event = '', ?int $jobId = null, ?string $correlationId = null ): void {
	}

	public function warning( string $message, array $context = array(), string $component = '', string $event = '', ?int $jobId = null, ?string $correlationId = null ): void {
	}

	public function error( string $message, array $context = array(), string $component = '', string $event = '', ?int $jobId = null, ?string $correlationId = null ): void {
	}

	public function critical( string $message, array $context = array(), string $component = '', string $event = '', ?int $jobId = null, ?string $correlationId = null ): void {
	}

	public function log( string $level, string $message, array $context = array(), string $component = '', string $event = '', ?int $jobId = null, ?string $correlationId = null ): void {
	}
}
