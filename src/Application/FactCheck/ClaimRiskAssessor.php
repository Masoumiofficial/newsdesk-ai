<?php
/**
 * A-4 — claim risk + handling action.
 *
 * The claims table stored a verification status and nothing about consequence.
 * "The release is on Tuesday" being wrong is embarrassing; "the flaw is
 * actively exploited" being wrong is harmful. Risk is what separates them, and
 * it decides what the pipeline does with a claim it could not verify.
 *
 * Deterministic by design: risk must be explainable to an editor and identical
 * on every run. No AI call.
 *
 * @package NewsDesk\AI\Application\FactCheck
 */

namespace NewsDesk\AI\Application\FactCheck;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Domain\Entity\EvidenceClaim;

final class ClaimRiskAssessor {

	/** Claim types that carry legal or safety consequence when wrong. */
	private const HIGH_RISK_TYPES = array(
		EvidenceClaim::TYPE_STAT,
		EvidenceClaim::TYPE_QUOTE,
	);

	/** Markers of a claim that can harm someone if wrong. */
	private const CRITICAL_MARKERS = array(
		'exploit', 'exploited', 'vulnerab', 'malware', 'ransomware', 'breach',
		'data leak', 'zero-day', 'zero day', 'cve-', 'attack', 'backdoor',
		'آسیب‌پذیر', 'آسیب پذیر', 'بدافزار', 'باج‌افزار', 'نشت داده', 'نفوذ', 'حمله',
	);

	/** Markers of legal / financial assertions. */
	private const HIGH_MARKERS = array(
		'lawsuit', 'court', 'illegal', 'fine', 'penalty', 'acquisition', 'acquired',
		'revenue', 'price', 'cost', 'fired', 'resigned', 'bankrupt',
		'شکایت', 'دادگاه', 'غیرقانونی', 'جریمه', 'خرید', 'درآمد', 'قیمت', 'ورشکست', 'استعفا',
	);

	/** Below this confidence an unverified claim is not safe to state plainly. */
	private const LOW_CONFIDENCE = 0.5;

	/**
	 * Assess one claim.
	 *
	 * @return array{risk:string, action:string, reason:string}
	 */
	public function assess( EvidenceClaim $claim ): array {
		$risk   = $this->risk( $claim );
		$status = strtoupper( (string) $claim->verificationStatus );
		$action = $this->action( $risk, $status, (float) $claim->confidence );

		return array(
			'risk'   => $risk,
			'action' => $action,
			'reason' => $this->reason( $risk, $status, $action ),
		);
	}

	/**
	 * Assess and apply in one step.
	 */
	public function apply( EvidenceClaim $claim ): EvidenceClaim {
		$verdict        = $this->assess( $claim );
		$claim->risk    = $verdict['risk'];
		$claim->action  = $verdict['action'];
		return $claim;
	}

	/**
	 * Risk markers for one level, lower-cased and filterable.
	 *
	 * @param string[] $defaults Shipped markers.
	 * @param string   $level    'critical' or 'high'.
	 * @return string[]
	 */
	private static function markers( array $defaults, string $level ): array {
		if ( ! function_exists( 'apply_filters' ) ) {
			return $defaults;
		}
		/**
		 * Filter the claim-risk markers for one risk level.
		 *
		 * @param string[] $defaults Shipped markers (English and Persian).
		 * @param string   $level    'critical' or 'high'.
		 */
		$list = apply_filters( 'newsdesk_claim_risk_markers', $defaults, $level );
		if ( ! is_array( $list ) || array() === $list ) {
			return $defaults;
		}
		$clean = array();
		foreach ( $list as $item ) {
			if ( is_string( $item ) && '' !== trim( $item ) ) {
				$clean[] = mb_strtolower( trim( $item ), 'UTF-8' );
			}
		}
		return array() !== $clean ? $clean : $defaults;
	}

	private function risk( EvidenceClaim $claim ): string {
		$text = mb_strtolower( $claim->claimText . ' ' . $claim->supportSnippet, 'UTF-8' );

		// The shipped markers cover English and Persian. A site publishing in
		// any other language would otherwise score every security claim as LOW,
		// so the lists are filterable rather than fixed.
		foreach ( self::markers( self::CRITICAL_MARKERS, 'critical' ) as $m ) {
			if ( false !== mb_strpos( $text, $m, 0, 'UTF-8' ) ) {
				return EvidenceClaim::RISK_CRITICAL;
			}
		}
		foreach ( self::markers( self::HIGH_MARKERS, 'high' ) as $m ) {
			if ( false !== mb_strpos( $text, $m, 0, 'UTF-8' ) ) {
				return EvidenceClaim::RISK_HIGH;
			}
		}
		// A number or a quotation attributed to someone is checkable and
		// therefore embarrassing to get wrong.
		if ( in_array( $claim->claimType, self::HIGH_RISK_TYPES, true ) ) {
			return EvidenceClaim::RISK_HIGH;
		}
		if ( EvidenceClaim::TYPE_VERSION === $claim->claimType || EvidenceClaim::TYPE_DATE === $claim->claimType ) {
			return EvidenceClaim::RISK_MEDIUM;
		}
		return EvidenceClaim::RISK_LOW;
	}

	private function action( string $risk, string $status, float $confidence ): string {
		// Verified is verified, whatever the risk.
		if ( EvidenceClaim::STATUS_VERIFIED === $status ) {
			return EvidenceClaim::ACTION_KEEP;
		}
		// Evidence says the opposite: never soften this into "some say".
		if ( EvidenceClaim::STATUS_CONTRADICTED === $status || EvidenceClaim::STATUS_REJECTED === $status ) {
			return EvidenceClaim::ACTION_REMOVE;
		}

		if ( EvidenceClaim::RISK_CRITICAL === $risk ) {
			// Unverified and harmful if wrong: it does not go in as fact.
			return EvidenceClaim::STATUS_PARTIALLY_VERIFIED === $status
				? EvidenceClaim::ACTION_ATTRIBUTE
				: EvidenceClaim::ACTION_REMOVE;
		}
		if ( EvidenceClaim::RISK_HIGH === $risk ) {
			if ( EvidenceClaim::STATUS_PARTIALLY_VERIFIED === $status ) {
				return EvidenceClaim::ACTION_ATTRIBUTE;
			}
			return $confidence < self::LOW_CONFIDENCE
				? EvidenceClaim::ACTION_RESEARCH_MORE
				: EvidenceClaim::ACTION_ATTRIBUTE;
		}
		if ( EvidenceClaim::RISK_MEDIUM === $risk ) {
			return $confidence < self::LOW_CONFIDENCE
				? EvidenceClaim::ACTION_MARK_UNCERTAIN
				: EvidenceClaim::ACTION_KEEP;
		}
		return EvidenceClaim::ACTION_KEEP;
	}

	private function reason( string $risk, string $status, string $action ): string {
		return sprintf( 'risk=%s status=%s → %s', $risk, '' !== $status ? $status : 'UNVERIFIED', $action );
	}
}
