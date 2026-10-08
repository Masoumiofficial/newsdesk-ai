<?php
/**
 * A-3 — security intelligence extraction.
 *
 * Security news has structure other news does not: an identifier, a severity,
 * which versions are affected, which version fixes it, and whether it is being
 * exploited right now. The spec asks for those fields because they decide how
 * urgently a story must be handled — and because getting them wrong is the
 * most harmful mistake this plugin could make.
 *
 * Extraction is deliberately pattern-based, not AI: a CVE ID either appears in
 * the text or it does not. A model guessing "CVE-2026-1234" would be inventing
 * a security advisory, which is exactly the failure mode the spec forbids.
 *
 * @package NewsDesk\AI\Application\Security
 */

namespace NewsDesk\AI\Application\Security;

defined( 'ABSPATH' ) || exit;

final class SecurityIntelExtractor {

	/** CVSS v3 qualitative severity bands. */
	public const SEVERITY_NONE     = 'NONE';
	public const SEVERITY_LOW      = 'LOW';
	public const SEVERITY_MEDIUM   = 'MEDIUM';
	public const SEVERITY_HIGH     = 'HIGH';
	public const SEVERITY_CRITICAL = 'CRITICAL';

	/** Phrases meaning "attacks are happening now". */
	private const EXPLOITED_MARKERS = array(
		'actively exploited', 'exploited in the wild', 'under active attack',
		'in-the-wild exploitation', 'actively being exploited', 'zero-day',
		'zero day', 'در حال بهره‌برداری', 'اکسپلویت فعال', 'حملات فعال',
	);

	/**
	 * Extract what is verifiably present in the text.
	 *
	 * @return array{
	 *   is_security:bool, cve_ids:string[], cvss_score:float|null,
	 *   severity:string, affected_versions:string[], fixed_versions:string[],
	 *   exploited:bool, vendor_advisory:string, confidence:string
	 * }
	 */
	public function extract( string $text, string $title = '' ): array {
		$haystack = $title . "\n" . $text;
		$lower    = mb_strtolower( $haystack, 'UTF-8' );

		$cves  = $this->cveIds( $haystack );
		$cvss  = $this->cvssScore( $haystack );
		$fixed = $this->fixedVersions( $haystack );

		$isSecurity = ( $cves || null !== $cvss || $this->looksSecurity( $lower ) );

		return array(
			'is_security'       => $isSecurity,
			'cve_ids'           => $cves,
			'cvss_score'        => $cvss,
			'severity'          => $this->severity( $cvss, $lower ),
			'affected_versions' => $this->affectedVersions( $haystack ),
			'fixed_versions'    => $fixed,
			'exploited'         => $this->exploited( $lower ),
			'vendor_advisory'   => $this->advisoryUrl( $haystack ),
			// How much of this came from a hard identifier vs. loose wording.
			'confidence'        => $cves || null !== $cvss ? 'high' : ( $isSecurity ? 'low' : 'none' ),
		);
	}

	/** @return string[] */
	private function cveIds( string $text ): array {
		if ( ! preg_match_all( '/CVE-\d{4}-\d{4,7}/i', $text, $m ) ) {
			return array();
		}
		$ids = array_map( 'strtoupper', $m[0] );
		return array_values( array_unique( $ids ) );
	}

	/** CVSS base score, 0.0–10.0, or null when not stated. */
	private function cvssScore( string $text ): ?float {
		// "CVSS score of 9.8", "CVSS: 9.8", "CVSS v3.1 9.8", "9.8 CVSS"
		if ( preg_match( '/CVSS[^0-9]{0,20}(\d{1,2}(?:\.\d)?)/i', $text, $m ) ) {
			$v = (float) $m[1];
			if ( $v >= 0.0 && $v <= 10.0 ) {
				return $v;
			}
		}
		if ( preg_match( '/(\d{1,2}(?:\.\d)?)\s*(?:\/\s*10)?\s*CVSS/i', $text, $m ) ) {
			$v = (float) $m[1];
			if ( $v >= 0.0 && $v <= 10.0 ) {
				return $v;
			}
		}
		return null;
	}

	/**
	 * Severity from the score when present; otherwise from explicit wording.
	 * Never invented: an unscored advisory with no wording stays NONE.
	 */
	private function severity( ?float $cvss, string $lower ): string {
		if ( null !== $cvss ) {
			if ( $cvss >= 9.0 ) {
				return self::SEVERITY_CRITICAL;
			}
			if ( $cvss >= 7.0 ) {
				return self::SEVERITY_HIGH;
			}
			if ( $cvss >= 4.0 ) {
				return self::SEVERITY_MEDIUM;
			}
			if ( $cvss > 0.0 ) {
				return self::SEVERITY_LOW;
			}
			return self::SEVERITY_NONE;
		}
		foreach ( array(
			self::SEVERITY_CRITICAL => array( 'critical severity', 'critically severe', 'بحرانی' ),
			self::SEVERITY_HIGH     => array( 'high severity', 'severity: high', 'شدت بالا' ),
			self::SEVERITY_MEDIUM   => array( 'medium severity', 'moderate severity', 'شدت متوسط' ),
			self::SEVERITY_LOW      => array( 'low severity', 'شدت پایین' ),
		) as $level => $markers ) {
			foreach ( $markers as $m ) {
				if ( false !== mb_strpos( $lower, $m, 0, 'UTF-8' ) ) {
					return $level;
				}
			}
		}
		return self::SEVERITY_NONE;
	}

	/** @return string[] */
	private function affectedVersions( string $text ): array {
		$out = array();
		// "affects versions 1.2 through 1.9", "affected: < 6.4.2", "versions prior to 3.1"
		if ( preg_match_all( '/(?:affect(?:s|ed)?|vulnerable|impacted)[^.\n]{0,40}?((?:\d+\.){1,3}\d+(?:\s*(?:-|–|through|to|تا)\s*(?:\d+\.){1,3}\d+)?)/iu', $text, $m ) ) {
			$out = array_merge( $out, $m[1] );
		}
		if ( preg_match_all( '/(?:prior to|before|earlier than|قبل از|پیش از)\s*((?:\d+\.){1,3}\d+)/iu', $text, $m ) ) {
			foreach ( $m[1] as $v ) {
				$out[] = '< ' . $v;
			}
		}
		return $this->tidy( $out );
	}

	/** @return string[] */
	private function fixedVersions( string $text ): array {
		$out = array();
		if ( preg_match_all( '/(?:fixed in|patched in|resolved in|update to|upgrade to|رفع شده در|به‌روزرسانی به)\s*(?:version\s*)?((?:\d+\.){1,3}\d+)/iu', $text, $m ) ) {
			$out = array_merge( $out, $m[1] );
		}
		return $this->tidy( $out );
	}

	private function exploited( string $lower ): bool {
		foreach ( self::EXPLOITED_MARKERS as $m ) {
			if ( false !== mb_strpos( $lower, $m, 0, 'UTF-8' ) ) {
				return true;
			}
		}
		return false;
	}

	private function advisoryUrl( string $text ): string {
		if ( preg_match( '#https?://[^\s"\'<>]*(?:security|advisor|cve|vuln|bulletin)[^\s"\'<>]*#i', $text, $m ) ) {
			return $m[0];
		}
		return '';
	}

	private function looksSecurity( string $lower ): bool {
		$markers = array(
			'security release', 'security update', 'security fix', 'vulnerability',
			'security advisory', 'patch', 'exploit',
			'به‌روزرسانی امنیتی', 'وصله امنیتی', 'آسیب‌پذیری', 'رخنه امنیتی',
		);
		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filter the phrases that mark an item as security-related.
			 *
			 * Shipped list covers English and Persian; add your own language here.
			 *
			 * @param string[] $markers Lower-cased phrases.
			 */
			$filtered = apply_filters( 'newsdesk_security_markers', $markers );
			if ( is_array( $filtered ) && array() !== $filtered ) {
				$markers = $filtered;
			}
		}
		foreach ( $markers as $m ) {
			if ( false !== mb_strpos( $lower, $m, 0, 'UTF-8' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string[] $values
	 * @return string[]
	 */
	private function tidy( array $values ): array {
		$out = array();
		foreach ( $values as $v ) {
			$v = trim( preg_replace( '/\s+/u', ' ', (string) $v ) ?? '' );
			if ( '' !== $v ) {
				$out[] = $v;
			}
		}
		return array_values( array_unique( $out ) );
	}
}
