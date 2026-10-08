<?php
namespace NewsDesk\AI\Logging\Contracts;

defined( 'ABSPATH' ) || exit;

interface LoggerInterface {

	/**
	 * @param array  $context Extra data (redacted before persist, never secrets).
	 * @param string $component Component slug (e.g. 'news.rss').
	 * @param string $event Event slug.
	 * @param int|null $jobId
	 * @param string|null $correlationId
	 */
	public function debug( string $message, array $context = array(), string $component = '', string $event = '', ?int $jobId = null, ?string $correlationId = null ): void;

	public function info( string $message, array $context = array(), string $component = '', string $event = '', ?int $jobId = null, ?string $correlationId = null ): void;

	public function warning( string $message, array $context = array(), string $component = '', string $event = '', ?int $jobId = null, ?string $correlationId = null ): void;

	public function error( string $message, array $context = array(), string $component = '', string $event = '', ?int $jobId = null, ?string $correlationId = null ): void;

	public function critical( string $message, array $context = array(), string $component = '', string $event = '', ?int $jobId = null, ?string $correlationId = null ): void;

	/**
	 * Central log call (used by the helpers above).
	 */
	public function log( string $level, string $message, array $context = array(), string $component = '', string $event = '', ?int $jobId = null, ?string $correlationId = null ): void;
}
