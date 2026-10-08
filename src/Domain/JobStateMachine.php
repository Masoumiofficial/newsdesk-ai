<?php
/**
 * Job State Machine (§37). Pure, dependency-free, unit-tested.
 *
 * @package NewsDesk\AI\Domain
 */

namespace NewsDesk\AI\Domain;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Domain\Exception\InvalidTransitionException;

final class JobStateMachine {

	const QUEUED          = 'QUEUED';
	const DISCOVERING     = 'DISCOVERING';
	const NORMALIZING     = 'NORMALIZING';
	const DEDUPLICATING   = 'DEDUPLICATING';
	const CLUSTERING      = 'CLUSTERING';
	const SCORING         = 'SCORING';
	const SELECTING       = 'SELECTING';
	const RESEARCHING     = 'RESEARCHING';
	const EVIDENCE_EXTRACTING = 'EVIDENCE_EXTRACTING';
	const FACT_CHECKING   = 'FACT_CHECKING';
	const DECIDING        = 'DECIDING';
	const GENERATING      = 'GENERATING';
	const VALIDATING      = 'VALIDATING';
	const SEO_PROCESSING  = 'SEO_PROCESSING';
	const LINK_ANALYSIS   = 'LINK_ANALYSIS';
	const IMAGE_QUEUED    = 'IMAGE_QUEUED';
	const IMAGE_PROCESSING = 'IMAGE_PROCESSING';
	const QUALITY_CHECK   = 'QUALITY_CHECK';
	const DRAFT_CREATING  = 'DRAFT_CREATING';
	const COMPLETED       = 'COMPLETED';
	const NEEDS_REVIEW    = 'NEEDS_REVIEW';
	const RETRYING        = 'RETRYING';
	const FAILED          = 'FAILED';
	const REJECTED        = 'REJECTED';

	/**
	 * Transition table: from => [ event => [to, ...] ].
	 *
	 * @var array<string, array<string, string[]>>
	 */
	private const TABLE = array(
		self::QUEUED           => array( 'start' => array( self::DISCOVERING ), 'complete' => array( self::COMPLETED ), 'fail' => array( self::FAILED ), 'reject' => array( self::REJECTED ), 'retry' => array( self::RETRYING ), 'mark_needs_review' => array( self::NEEDS_REVIEW ) ),
		self::DISCOVERING      => array( 'normalize' => array( self::NORMALIZING ), 'fail' => array( self::FAILED ), 'retry' => array( self::RETRYING ) ),
		self::NORMALIZING      => array( 'deduplicate' => array( self::DEDUPLICATING ), 'complete' => array( self::COMPLETED ), 'fail' => array( self::FAILED ), 'retry' => array( self::RETRYING ) ),
		self::DEDUPLICATING    => array( 'cluster' => array( self::CLUSTERING ), 'fail' => array( self::FAILED ), 'retry' => array( self::RETRYING ) ),
		self::CLUSTERING       => array( 'score' => array( self::SCORING ), 'fail' => array( self::FAILED ), 'retry' => array( self::RETRYING ) ),
		self::SCORING          => array( 'select' => array( self::SELECTING ), 'fail' => array( self::FAILED ), 'retry' => array( self::RETRYING ) ),
		self::SELECTING        => array( 'research' => array( self::RESEARCHING ), 'complete' => array( self::COMPLETED ), 'reject' => array( self::REJECTED ), 'fail' => array( self::FAILED ), 'retry' => array( self::RETRYING ) ),
		self::RESEARCHING      => array( 'extract' => array( self::EVIDENCE_EXTRACTING ), 'fact_check' => array( self::FACT_CHECKING ), 'fail' => array( self::FAILED ), 'retry' => array( self::RETRYING ) ),
		self::EVIDENCE_EXTRACTING => array( 'fact_check' => array( self::FACT_CHECKING ), 'fail' => array( self::FAILED ), 'retry' => array( self::RETRYING ) ),
		self::FACT_CHECKING    => array( 'decide' => array( self::DECIDING ), 'complete' => array( self::COMPLETED ), 'mark_needs_review' => array( self::NEEDS_REVIEW ), 'fail' => array( self::FAILED ), 'retry' => array( self::RETRYING ) ),
		self::DECIDING         => array( 'generate' => array( self::GENERATING ), 'reject' => array( self::REJECTED ), 'complete' => array( self::COMPLETED ), 'fail' => array( self::FAILED ), 'retry' => array( self::RETRYING ) ),
		self::GENERATING       => array( 'validate' => array( self::VALIDATING ), 'fail' => array( self::FAILED ), 'retry' => array( self::RETRYING ) ),
		self::VALIDATING       => array( 'seo' => array( self::SEO_PROCESSING ), 'mark_needs_review' => array( self::NEEDS_REVIEW ), 'fail' => array( self::FAILED ), 'retry' => array( self::RETRYING ) ),
		self::SEO_PROCESSING   => array( 'links' => array( self::LINK_ANALYSIS ), 'fail' => array( self::FAILED ), 'retry' => array( self::RETRYING ) ),
		self::LINK_ANALYSIS    => array( 'queue_image' => array( self::IMAGE_QUEUED ), 'quality' => array( self::QUALITY_CHECK ), 'fail' => array( self::FAILED ), 'retry' => array( self::RETRYING ) ),
		self::IMAGE_QUEUED     => array( 'process_image' => array( self::IMAGE_PROCESSING ), 'quality' => array( self::QUALITY_CHECK ), 'fail' => array( self::FAILED ), 'retry' => array( self::RETRYING ) ),
		self::IMAGE_PROCESSING => array( 'quality' => array( self::QUALITY_CHECK ), 'fail' => array( self::FAILED ), 'retry' => array( self::RETRYING ) ),
		self::QUALITY_CHECK    => array( 'create_draft' => array( self::DRAFT_CREATING ), 'mark_needs_review' => array( self::NEEDS_REVIEW ), 'fail' => array( self::FAILED ), 'retry' => array( self::RETRYING ) ),
		self::DRAFT_CREATING   => array( 'complete' => array( self::COMPLETED ), 'mark_needs_review' => array( self::NEEDS_REVIEW ), 'fail' => array( self::FAILED ), 'retry' => array( self::RETRYING ) ),
		self::NEEDS_REVIEW     => array( 'retry' => array( self::RETRYING ), 'reject' => array( self::REJECTED ), 'fail' => array( self::FAILED ), 'complete' => array( self::COMPLETED ) ),
		self::RETRYING         => array( 'resume' => array( self::DISCOVERING, self::NORMALIZING, self::DEDUPLICATING, self::CLUSTERING, self::SCORING, self::SELECTING, self::RESEARCHING, self::EVIDENCE_EXTRACTING, self::FACT_CHECKING, self::DECIDING, self::GENERATING, self::VALIDATING, self::SEO_PROCESSING, self::LINK_ANALYSIS, self::IMAGE_QUEUED, self::IMAGE_PROCESSING, self::QUALITY_CHECK, self::DRAFT_CREATING ), 'fail' => array( self::FAILED ), 'reject' => array( self::REJECTED ) ),
	);

	/**
	 * All states.
	 *
	 * @return string[]
	 */
	public static function allStates(): array {
		return array(
			self::QUEUED, self::DISCOVERING, self::NORMALIZING, self::DEDUPLICATING,
			self::CLUSTERING, self::SCORING, self::SELECTING, self::RESEARCHING,
			self::EVIDENCE_EXTRACTING, self::FACT_CHECKING, self::DECIDING, self::GENERATING, self::VALIDATING,
			self::SEO_PROCESSING, self::LINK_ANALYSIS, self::IMAGE_QUEUED,
			self::IMAGE_PROCESSING, self::QUALITY_CHECK, self::DRAFT_CREATING,
			self::COMPLETED, self::NEEDS_REVIEW, self::RETRYING, self::FAILED, self::REJECTED,
		);
	}

	/**
	 * Terminal states.
	 *
	 * @return string[]
	 */
	public static function terminalStates(): array {
		return array( self::COMPLETED, self::FAILED, self::REJECTED );
	}

	/**
	 * Validate a state name.
	 */
	public static function isValidState( string $state ): bool {
		return in_array( $state, self::allStates(), true );
	}

	/**
	 * Events allowed from a state.
	 *
	 * @return string[]
	 */
	public static function allowedEvents( string $from ): array {
		if ( ! isset( self::TABLE[ $from ] ) ) {
			return array();
		}
		return array_keys( self::TABLE[ $from ] );
	}

	/**
	 * Targets of an event from a state.
	 *
	 * @return string[]
	 */
	public static function targets( string $from, string $event ): array {
		if ( ! isset( self::TABLE[ $from ][ $event ] ) ) {
			return array();
		}
		return self::TABLE[ $from ][ $event ];
	}

	/**
	 * Can the transition happen?
	 */
	public static function canTransition( string $from, string $event, string $to ): bool {
		return in_array( $to, self::targets( $from, $event ), true );
	}

	/**
	 * Assert the transition; throws InvalidTransitionException otherwise.
	 */
	public static function assertTransition( string $from, string $event, string $to ): void {
		if ( ! self::isValidState( $from ) || ! self::isValidState( $to ) || ! self::canTransition( $from, $event, $to ) ) {
			throw new InvalidTransitionException( $from, $event, $to );
		}
	}

	/**
	 * States allowed as retry targets (RETRYING --resume--> target).
	 *
	 * @return string[]
	 */
	public static function retryTargets(): array {
		return self::targets( self::RETRYING, 'resume' );
	}

	/**
	 * Is a state valid as a retry target?
	 */
	public static function isRetryTarget( string $state ): bool {
		return in_array( $state, self::retryTargets(), true );
	}
}
