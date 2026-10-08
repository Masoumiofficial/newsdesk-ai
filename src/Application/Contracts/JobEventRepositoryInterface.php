<?php
namespace NewsDesk\AI\Application\Contracts;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Domain\Entity\JobEvent;

interface JobEventRepositoryInterface {

	public function insert( JobEvent $event ): int;

	/**
	 * @return JobEvent[]
	 */
	public function findByJob( int $jobId ): array;

	public function nextSeq( int $jobId ): int;

	public function countSince( \DateTimeImmutable $since ): int;
}
