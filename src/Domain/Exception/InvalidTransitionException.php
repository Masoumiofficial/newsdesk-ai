<?php
namespace NewsDesk\AI\Domain\Exception;

defined( 'ABSPATH' ) || exit;

/**
 * A state transition that is not allowed by the Job State Machine table.
 */
class InvalidTransitionException extends DomainException {

	/** @var string */
	private $fromState;
	/** @var string */
	private $event;
	/** @var string */
	private $toState;

	public function __construct( string $fromState, string $event, string $toState ) {
		$this->fromState = $fromState;
		$this->event     = $event;
		$this->toState   = $toState;
		parent::__construct(
			sprintf( 'Invalid transition: %s --%s--> %s', $fromState, $event, $toState )
		);
	}

	public function fromState(): string {
		return $this->fromState;
	}

	public function event(): string {
		return $this->event;
	}

	public function toState(): string {
		return $this->toState;
	}
}
