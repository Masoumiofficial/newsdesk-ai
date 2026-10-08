<?php
/**
 * Resolves the ACTIVE prompt version for a slug+language (§24):
 * DB version (admin-created, never edited) → built-in v1. Returns a value object.
 *
 * @package NewsDesk\AI\Application\Ai
 */

namespace NewsDesk\AI\Application\Ai;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Contracts\PromptRepositoryInterface;

final class PromptRegistry {

	/** @var PromptRepositoryInterface|null */
	private $repo;

	public function __construct( ?PromptRepositoryInterface $repo = null ) {
		$this->repo = $repo;
	}

	/**
	 * @return array{prompt_id: string, version: int, content: string}
	 */
	public function resolve( string $promptId, string $language ): array {
		$lang = in_array( $language, array( 'fa', 'en' ), true ) ? $language : 'fa';
		if ( null !== $this->repo ) {
			$active = $this->repo->findActive( $promptId, $lang );
			if ( null !== $active ) {
				return array(
					'prompt_id' => $active->promptId,
					'version'   => $active->version,
					'content'   => $active->content,
				);
			}
		}
		$builtin = Prompts::builtInContent( $promptId, $lang );
		return array(
			'prompt_id' => $promptId,
			'version'   => Prompts::VERSION,
			'content'   => null === $builtin ? '' : $builtin,
		);
	}
}
