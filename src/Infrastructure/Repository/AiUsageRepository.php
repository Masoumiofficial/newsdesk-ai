<?php
/**
 * AI usage ledger (§22) — append only.
 *
 * @package NewsDesk\AI\Infrastructure\Repository
 */

namespace NewsDesk\AI\Infrastructure\Repository;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Contracts\AiUsageRepositoryInterface;
use NewsDesk\AI\Application\Contracts\WpDbInterface;
use NewsDesk\AI\Domain\Entity\AiUsageRecord;
use NewsDesk\AI\Infrastructure\Database\TableNames;

final class AiUsageRepository implements AiUsageRepositoryInterface {

	/** @var WpDbInterface */
	private $db;
	/** @var TableNames */
	private $tables;

	public function __construct( WpDbInterface $db, TableNames $tables ) {
		$this->db     = $db;
		$this->tables = $tables;
	}

	public function record( AiUsageRecord $usage ): int {
		$row = $usage->toDbRow();
		$result = $this->db->insert(
			$this->tables->aiUsage(),
			$row,
			array( '%s','%s','%d','%s','%s','%d','%d','%f','%s','%s' )
		);
		if ( false === $result ) {
			return 0;
		}
		$usage->id = $this->db->insertId();
		return $usage->id;
	}

	public function tokensForJob( int $jobId ): array {
		$row = $this->db->getRow(
			$this->db->prepare(
				'SELECT SUM(input_usage) AS i, SUM(output_usage) AS o FROM ' . $this->tables->aiUsage() . ' WHERE job_id = %d',
				$jobId
			)
		);
		if ( ! is_array( $row ) ) {
			return array( 'input' => 0, 'output' => 0 );
		}
		return array(
			'input'  => (int) ( $row['i'] ?? 0 ),
			'output' => (int) ( $row['o'] ?? 0 ),
		);
	}

	public function countAll(): int {
		return (int) $this->db->getVar( 'SELECT COUNT(*) FROM ' . $this->tables->aiUsage() );
	}
}
