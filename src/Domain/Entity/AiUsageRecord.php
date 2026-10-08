<?php
/**
 * Append-only AI usage row (§22, §53) — token counts, never prompts.
 *
 * @package NewsDesk\AI\Domain\Entity
 */

namespace NewsDesk\AI\Domain\Entity;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Support\Time;

final class AiUsageRecord extends AbstractEntity {

	/** @var int */
	public $id = 0;
	/** @var string */
	public $provider = '';
	/** @var string */
	public $model = '';
	/** @var int */
	public $jobId = 0;
	/** @var string */
	public $requestId = '';
	/** @var string */
	public $component = '';
	/** @var int */
	public $inputTokens = 0;
	/** @var int */
	public $outputTokens = 0;
	/** @var float */
	public $estimatedCost = 0.0;
	/** @var string */
	public $currency = 'USD';
	/** @var \DateTimeImmutable|null */
	public $createdAt;

	public static function fromDbRow( array $row ): self {
		$u                 = new self();
		$u->id             = self::intOr( $row['id'] ?? null, 0 );
		$u->provider       = self::strOr( $row['provider'] ?? null );
		$u->model          = self::strOr( $row['model'] ?? null );
		$u->jobId          = self::intOr( $row['job_id'] ?? null, 0 );
		$u->requestId      = self::strOr( $row['request_id'] ?? null );
		$u->component      = self::strOr( $row['component'] ?? null );
		$u->inputTokens    = self::intOr( $row['input_usage'] ?? null, 0 );
		$u->outputTokens   = self::intOr( $row['output_usage'] ?? null, 0 );
		$u->estimatedCost  = self::floatOr( $row['estimated_cost'] ?? null, 0.0 );
		$u->currency       = self::strOr( $row['currency'] ?? null, 'USD' );
		$u->createdAt      = Time::fromDb( $row['created_at'] ?? null );
		return $u;
	}

	public function toDbRow(): array {
		return array(
			'provider'       => $this->provider,
			'model'          => $this->model,
			'job_id'         => $this->jobId,
			'request_id'     => $this->requestId,
			'component'      => $this->component,
			'input_usage'    => $this->inputTokens,
			'output_usage'   => $this->outputTokens,
			'estimated_cost' => $this->estimatedCost,
			'currency'       => $this->currency,
			'created_at'     => Time::toDb( $this->createdAt ),
		);
	}
}
