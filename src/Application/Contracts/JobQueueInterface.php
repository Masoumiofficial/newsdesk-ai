<?php
namespace NewsDesk\AI\Application\Contracts;

defined( 'ABSPATH' ) || exit;

interface JobQueueInterface {

	/**
	 * Enqueue an async job.
	 *
	 * @param string    $hook Hook name.
	 * @param array     $args Hook args.
	 * @param string    $group Queue group.
	 * @param int|null  $delay Seconds to delay (null = ASAP).
	 * @return int|false Scheduled action id / true-ish on success.
	 */
	public function enqueue( string $hook, array $args, string $group = 'nd-newsroom', ?int $delay = null );

	/**
	 * Is the preferred backend available?
	 */
	public function isAvailable(): bool;

	public function name(): string;
}
