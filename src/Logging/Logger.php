<?php
/**
 * Structured logger → newsdesk_logs (+ optional WP debug.log mirror).
 *
 * @package NewsDesk\AI\Logging
 */

namespace NewsDesk\AI\Logging;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Domain\Entity\LogEntry;
use NewsDesk\AI\Logging\Contracts\LoggerInterface;
use NewsDesk\AI\Logging\Contracts\LogRepositoryInterface;
use NewsDesk\AI\Support\Time;

final class Logger implements LoggerInterface {

	public const LEVELS = array(
		'debug'    => 10,
		'info'     => 20,
		'warning'  => 30,
		'error'    => 40,
		'critical' => 50,
	);

	/** @var LogRepositoryInterface */
	private $repo;
	/** @var string */
	private $threshold;

	public function __construct( LogRepositoryInterface $repo, string $threshold = 'info' ) {
		$this->repo      = $repo;
		$this->threshold = isset( self::LEVELS[ $threshold ] ) ? $threshold : 'info';
	}

	public function debug( string $message, array $context = array(), string $component = '', string $event = '', ?int $jobId = null, ?string $correlationId = null ): void {
		$this->log( 'debug', $message, $context, $component, $event, $jobId, $correlationId );
	}

	public function info( string $message, array $context = array(), string $component = '', string $event = '', ?int $jobId = null, ?string $correlationId = null ): void {
		$this->log( 'info', $message, $context, $component, $event, $jobId, $correlationId );
	}

	public function warning( string $message, array $context = array(), string $component = '', string $event = '', ?int $jobId = null, ?string $correlationId = null ): void {
		$this->log( 'warning', $message, $context, $component, $event, $jobId, $correlationId );
	}

	public function error( string $message, array $context = array(), string $component = '', string $event = '', ?int $jobId = null, ?string $correlationId = null ): void {
		$this->log( 'error', $message, $context, $component, $event, $jobId, $correlationId );
	}

	public function critical( string $message, array $context = array(), string $component = '', string $event = '', ?int $jobId = null, ?string $correlationId = null ): void {
		$this->log( 'critical', $message, $context, $component, $event, $jobId, $correlationId );
	}

	public function log( string $level, string $message, array $context = array(), string $component = '', string $event = '', ?int $jobId = null, ?string $correlationId = null ): void {
		if ( ! isset( self::LEVELS[ $level ] ) || self::LEVELS[ $level ] < self::LEVELS[ $this->threshold ] ) {
			return;
		}

		$entry            = new LogEntry();
		$entry->level     = $level;
		$entry->component = substr( $component, 0, 60 );
		$entry->event     = substr( $event, 0, 80 );
		$entry->jobId     = $jobId;
		$entry->correlationId = $correlationId ? substr( $correlationId, 0, 36 ) : '';
		// Never store secrets.
		$entry->message        = substr( Redaction::redact( $message ), 0, 4000 );
		$entry->context        = (array) Redaction::redactValue( $context );
		$entry->createdAt      = Time::now();

		try {
			$this->repo->insert( $entry );
		} catch ( \Exception $e ) {
			// Logging must never break the pipeline.
			error_log( sprintf( '[nd-newsroom] log write failed: %s', $e->getMessage() ) ); // phpcs:ignore
		}

		// Optional mirror for debugging outside wp-admin.
		if ( function_exists( 'do_action' ) ) {
			do_action( 'newsdesk_newsroom_log', $entry ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		}
		if ( self::LEVELS[ $level ] >= self::LEVELS['warning'] ) {
			error_log( sprintf( '[nd-newsroom][%s] %s', $level, $entry->message ) ); // phpcs:ignore
		}
	}
}
