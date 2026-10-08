<?php
/**
 * ContentMemory — the grounding map for one story (§16–17):
 *  - allowed: claims with VERIFIED / PARTIALLY_VERIFIED status (only these may
 *    be referenced by generated content; UNVERIFIED/CONTRADICTED never appear);
 *  - banned: everything else — the prompt forbids them explicitly.
 *
 * @package NewsDesk\AI\Application\Content
 */

namespace NewsDesk\AI\Application\Content;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Domain\Entity\EvidenceClaim;
use NewsDesk\AI\Domain\Entity\Story;

final class ContentMemory {

	/**
	 * @param EvidenceClaim[] $claims
	 * @return array{allowed: array<string, array<string, mixed>>, banned: string[], corpus: string, eligible: bool, reason: string}
	 */
	public static function build( Story $story, array $claims ): array {
		$allowed = array();
		$banned  = array();
		$corpus  = array();

		foreach ( $claims as $claim ) {
			if ( null === $claim->supportSnippet || '' === trim( (string) $claim->supportSnippet ) ) {
				continue;
			}
			$corpus[] = (string) $claim->supportSnippet;
			$isAllowed = EvidenceClaim::STATUS_VERIFIED === $claim->verificationStatus || EvidenceClaim::STATUS_PARTIALLY_VERIFIED === $claim->verificationStatus;
			if ( $isAllowed ) {
				$allowed[ $claim->claimId ] = array(
					'claim_id'   => $claim->claimId,
					'type'       => $claim->claimType,
					'text'       => $claim->claimText,
					'snippet'    => $claim->supportSnippet,
					'source_url' => $claim->sourceUrl,
					'source_id'  => $claim->sourceId,
					'reported_by'=> $claim->reportedBy,
					'verification_status' => $claim->verificationStatus,
				);
			} else {
				$banned[] = (string) $claim->claimText;
			}
		}

		$eligible = count( $allowed ) >= 1;
		return array(
			'allowed'  => $allowed,
			'banned'   => array_values( array_unique( $banned ) ),
			'corpus'   => implode( "\n", $corpus ),
			'eligible' => $eligible,
			'reason'   => $eligible ? '' : 'NO_VERIFIED_CLAIMS',
		);
	}
}
