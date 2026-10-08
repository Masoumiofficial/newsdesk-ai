<?php
/**
 * One fact-check verdict against one claim (audit trail, §17).
 *
 * @package NewsDesk\AI\Domain\Entity
 */

namespace NewsDesk\AI\Domain\Entity;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Support\Time;

final class FactCheckRecord extends AbstractEntity {

	public const VERDICT_SUPPORTED     = 'SUPPORTED';
	public const VERDICT_CONTRADICTED  = 'CONTRADICTED';
	public const VERDICT_SINGLE_SOURCE = 'SINGLE_SOURCE';
	public const VERDICT_INSUFFICIENT  = 'INSUFFICIENT';

	/** @var int */
	public $id = 0;
	/** @var string */
	public $claimId = '';
	/** @var int */
	public $storyId = 0;
	/** @var int */
	public $jobId = 0;
	/** @var string */
	public $method = 'cross_source';
	/** @var string */
	public $provider = '';
	/** @var string */
	public $model = '';
	/** @var string */
	public $verdict = self::VERDICT_INSUFFICIENT;
	/** @var string */
	public $rationale = '';
	/** @var \DateTimeImmutable|null */
	public $checkedAt;

	public static function fromDbRow( array $row ): self {
		$f               = new self();
		$f->id           = self::intOr( $row['id'] ?? null, 0 );
		$f->claimId      = self::strOr( $row['claim_id'] ?? null );
		$f->storyId      = self::intOr( $row['story_id'] ?? null, 0 );
		$f->jobId        = self::intOr( $row['job_id'] ?? null, 0 );
		$f->method       = self::strOr( $row['method'] ?? null, 'cross_source' );
		$f->provider     = self::strOr( $row['provider'] ?? null );
		$f->model        = self::strOr( $row['model'] ?? null );
		$f->verdict      = self::strOr( $row['verdict'] ?? null, self::VERDICT_INSUFFICIENT );
		$f->rationale    = self::strOr( $row['rationale'] ?? null );
		$f->checkedAt    = Time::fromDb( $row['checked_at'] ?? null );
		return $f;
	}

	public function toDbRow(): array {
		return array(
			'claim_id'   => $this->claimId,
			'story_id'   => $this->storyId,
			'job_id'     => $this->jobId,
			'method'     => $this->method,
			'provider'   => $this->provider,
			'model'      => $this->model,
			'verdict'    => $this->verdict,
			'rationale'  => $this->rationale,
			'checked_at' => Time::toDb( $this->checkedAt ),
		);
	}
}
