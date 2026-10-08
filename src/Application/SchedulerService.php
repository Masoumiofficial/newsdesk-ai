<?php
/**
 * Pure slot math (unit-testable): next scheduled slot in WP timezone (§42).
 *
 * @package NewsDesk\AI\Application
 */

namespace NewsDesk\AI\Application;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Support\Time;

final class SchedulerService {

	/**
	 * Next slot strictly after $now among configured times (today or tomorrow).
	 */
	public static function computeNextSlot( array $times, \DateTimeImmutable $nowLocal ): \DateTimeImmutable {
		$times = self::validateSlotTimes( $times );
		$nowH  = (int) $nowLocal->format( 'G' );
		$nowM  = (int) $nowLocal->format( 'i' );
		$nowMin = $nowH * 60 + $nowM;

		$candidates = array();
		foreach ( $times as $t ) {
			$min = self::slotToMinutes( $t );
			// Slot is due as soon as its minute has begun (tick granularity is 15 min;
			// duplicate triggers are de-duplicated by slot-key idempotency, not here).
			if ( $min >= $nowMin ) {
				$candidates[] = array( $min, $t );
			}
		}

		if ( empty( $candidates ) ) {
			// All slots passed today → tomorrow's first slot (times are sorted by validateSlotTimes).
			$day  = $nowLocal->modify( '+1 day' );
			$slot = $times[0] ?? '00:00';
			$min  = self::slotToMinutes( $slot );
		} else {
			usort( $candidates, static function ( $a, $b ) {
				return $a[0] <=> $b[0];
			} );
			$day  = $nowLocal;
			$min  = $candidates[0][0];
		}

		return $day->setTime( (int) floor( $min / 60 ), (int) ( $min % 60 ), 0 );
	}

	public static function slotKey( \DateTimeImmutable $dt ): string {
		return $dt->format( 'Y-m-d\TH:i' );
	}

	public static function validateSlotTimes( array $times ): array {
		$out = array();
		foreach ( $times as $t ) {
			if ( is_string( $t ) && preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $t ) ) {
				$out[] = $t;
			}
		}
		sort( $out );
		return $out ? $out : NewsroomSettings::DEFAULT_SLOTS;
	}

	private static function slotToMinutes( string $slot ): int {
		$parts = explode( ':', $slot );
		return (int) $parts[0] * 60 + (int) $parts[1];
	}
}
