<?php
/**
 * News window ladder (§ spec: 24h → 7d → NO NEWS).
 *
 * v1.6.0 had no ladder at all: StoryEditorialService loaded a flat
 * `fallback_window_days` (7d) window and `editorial_window_hours` was a dead
 * setting with no reader. That made every run behave like a 7-day sweep, so a
 * genuinely fresh story competed against week-old material on equal terms.
 *
 * The ladder is deliberately a pure decision object: it owns WHICH rung is in
 * play and WHY, but performs no I/O. StoryEditorialService asks it for a rung,
 * loads that window, and asks whether to descend when nothing publishable was
 * found. That keeps "no news is a valid outcome" testable without a database.
 *
 * @package NewsDesk\AI\Application\Scoring
 */

namespace NewsDesk\AI\Application\Scoring;

defined( 'ABSPATH' ) || exit;

final class NewsWindow {

	/** Primary rung: only genuinely fresh material. */
	public const RUNG_PRIMARY = 'PRIMARY';
	/** Fallback rung: widen to the catch-up window. */
	public const RUNG_FALLBACK = 'FALLBACK';
	/** Terminal rung: publish nothing. An expected, legitimate outcome. */
	public const RUNG_NONE = 'NO_NEWS';

	/** @var int Hours in the primary window. */
	private $primaryHours;
	/** @var int Days in the fallback window. */
	private $fallbackDays;

	public function __construct( int $primaryHours = 24, int $fallbackDays = 7 ) {
		// A zero/negative primary window would silently disable the ladder, and
		// a fallback narrower than the primary would make descending pointless.
		$this->primaryHours = max( 1, $primaryHours );
		$this->fallbackDays = max( 1, $fallbackDays );
	}

	/** Build from settings, so the ladder honours the admin's configuration. */
	public static function fromSettings( \NewsDesk\AI\Application\NewsroomSettings $settings ): self {
		return new self(
			(int) $settings->editorialWindowHours(),
			(int) $settings->fallbackWindowDays()
		);
	}

	/** The rung a run starts on. */
	public function firstRung(): string {
		return self::RUNG_PRIMARY;
	}

	/**
	 * The rung to try after $rung produced nothing publishable.
	 *
	 * PRIMARY → FALLBACK → NO_NEWS, and NO_NEWS is terminal so a caller loop
	 * can never spin.
	 */
	public function nextRung( string $rung ): string {
		switch ( $rung ) {
			case self::RUNG_PRIMARY:
				return self::RUNG_FALLBACK;
			case self::RUNG_FALLBACK:
			default:
				return self::RUNG_NONE;
		}
	}

	/** Is this rung one we can actually load items for? */
	public function isSearchable( string $rung ): bool {
		return self::RUNG_PRIMARY === $rung || self::RUNG_FALLBACK === $rung;
	}

	/**
	 * Cutoff datetime for a rung: items at or after this are in the window.
	 *
	 * @throws \InvalidArgumentException when the rung has no window.
	 */
	public function since( string $rung, \DateTimeImmutable $now ): \DateTimeImmutable {
		if ( self::RUNG_PRIMARY === $rung ) {
			return $now->modify( '-' . $this->primaryHours . ' hours' );
		}
		if ( self::RUNG_FALLBACK === $rung ) {
			return $now->modify( '-' . $this->fallbackDays . ' days' );
		}
		throw new \InvalidArgumentException( 'NO_NEWS has no window' );
	}

	/**
	 * Every searchable rung in order, as {rung, since} pairs.
	 *
	 * @return array<int, array{rung: string, since: \DateTimeImmutable}>
	 */
	public function ladder( \DateTimeImmutable $now ): array {
		$out  = array();
		$rung = $this->firstRung();
		while ( $this->isSearchable( $rung ) ) {
			$out[] = array(
				'rung'  => $rung,
				'since' => $this->since( $rung, $now ),
			);
			$rung = $this->nextRung( $rung );
		}
		return $out;
	}

	/** Human/log label for a rung, e.g. "24h" or "7d". */
	public function label( string $rung ): string {
		if ( self::RUNG_PRIMARY === $rung ) {
			return $this->primaryHours . 'h';
		}
		if ( self::RUNG_FALLBACK === $rung ) {
			return $this->fallbackDays . 'd';
		}
		return 'none';
	}

	public function primaryHours(): int {
		return $this->primaryHours;
	}

	public function fallbackDays(): int {
		return $this->fallbackDays;
	}
}
