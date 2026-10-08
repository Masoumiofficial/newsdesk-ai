<?php
/**
 * ExternalLinkManager (layer 16) — deterministic citation rows from the
 * grounding map. ONLY claims with VERIFIED status and a real source URL create
 * primary attributions; PARTIALLY_VERIFIED ones are secondary. Nothing guessed.
 *
 * @package NewsDesk\AI\Application\Linking
 */

namespace NewsDesk\AI\Application\Linking;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Contracts\LinkRepositoryInterface;
use NewsDesk\AI\Domain\Entity\EvidenceClaim;
use NewsDesk\AI\Domain\Entity\ExternalLink;
use NewsDesk\AI\Support\Time;

final class ExternalLinkManager {

	/** @var LinkRepositoryInterface */
	private $links;

	public function __construct( LinkRepositoryInterface $links ) {
		$this->links = $links;
	}

	/**
	 * @param array<string, array<string, mixed>> $allowed ContentMemory allowed map
	 * @return int rows created
	 */
	public function build( array $allowed, int $jobId ): int {
		$created = 0;
		$seen    = array();
		foreach ( $allowed as $claim ) {
			$url = (string) ( $claim['source_url'] ?? '' );
			if ( '' === $url || isset( $seen[ $url ] ) ) {
				continue;
			}
			if ( 0 !== strpos( $url, 'http://' ) && 0 !== strpos( $url, 'https://' ) ) {
				continue; // §52: http/https only
			}
			$seen[ $url ] = true;
			$link = new ExternalLink();
			$link->claimId   = (string) $claim['claim_id'];
			$link->sourceId  = (int) ( $claim['source_id'] ?? 0 );
			$link->url       = $url;
			$link->anchor    = (string) mb_substr( (string) ( $claim['text'] ?? '' ), 0, 80 );
			$link->reason    = 'citation';
			$link->priority  = EvidenceClaim::STATUS_VERIFIED === (string) ( $claim['verification_status'] ?? '' ) ? ExternalLink::PRIORITY_PRIMARY : ExternalLink::PRIORITY_SECONDARY;
			$link->createdAt = Time::now();
			if ( $this->links->insertExternal( $link ) > 0 ) {
				$created++;
			}
		}
		return $created;
	}
}
