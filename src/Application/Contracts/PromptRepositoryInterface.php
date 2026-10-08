<?php
/**
 * Prompt version storage (§24).
 *
 * @package NewsDesk\AI\Application\Contracts
 */

namespace NewsDesk\AI\Application\Contracts;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Domain\Entity\PromptVersion;

interface PromptRepositoryInterface {

	public function insert( PromptVersion $prompt ): int;

	public function findActive( string $promptId, string $language ): ?PromptVersion;

	/**
	 * @return PromptVersion[]
	 */
	public function listActive( string $promptId ): array;
}
