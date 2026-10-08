<?php
/**
 * Append-only AI usage ledger (§22).
 *
 * @package NewsDesk\AI\Application\Contracts
 */

namespace NewsDesk\AI\Application\Contracts;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Domain\Entity\AiUsageRecord;

interface AiUsageRepositoryInterface {

	public function record( AiUsageRecord $usage ): int;

	/**
	 * @return array{input: int, output: int}
	 */
	public function tokensForJob( int $jobId ): array;

	public function countAll(): int;
}
