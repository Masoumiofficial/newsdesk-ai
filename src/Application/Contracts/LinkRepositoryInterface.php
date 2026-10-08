<?php
/**
 * Internal link suggestions + external attributions (layers 15–16).
 *
 * @package NewsDesk\AI\Application\Contracts
 */

namespace NewsDesk\AI\Application\Contracts;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Domain\Entity\ExternalLink;
use NewsDesk\AI\Domain\Entity\InternalLinkSuggestion;

interface LinkRepositoryInterface {

	public function insertInternal( InternalLinkSuggestion $suggestion ): int;

	/** @return InternalLinkSuggestion[] */
	public function internalForTarget( int $targetPostId ): array;

	public function insertExternal( ExternalLink $link ): int;

	/** @return ExternalLink[] */
	public function externalForClaimIds( array $claimIds ): array;

	public function externalForStory( int $storyId ): array;
}
