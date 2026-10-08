<?php
/**
 * Seeds built-in prompt versions (v1) into nd_prompt_versions (§24) — idempotent.
 * Admin edits create NEW versions; seeds never overwrite anything.
 *
 * @package NewsDesk\AI\Infrastructure\Seeds
 */

namespace NewsDesk\AI\Infrastructure\Seeds;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Ai\Prompts;
use NewsDesk\AI\Application\Contracts\PromptRepositoryInterface;
use NewsDesk\AI\Domain\Entity\PromptVersion;
use NewsDesk\AI\Support\Time;

final class PromptSeeder {

	/** @var PromptRepositoryInterface */
	private $prompts;

	public function __construct( PromptRepositoryInterface $prompts ) {
		$this->prompts = $prompts;
	}

	/**
	 * @return int number of rows created
	 */
	public function seed(): int {
		$created = 0;
		$now     = Time::now();
		foreach ( array( Prompts::ID_RESEARCH, Prompts::ID_EVIDENCE, Prompts::ID_FACT_CHECK ) as $promptId ) {
			foreach ( array( 'fa', 'en' ) as $lang ) {
				$content = Prompts::builtInContent( $promptId, $lang );
				if ( null === $content ) {
					continue;
				}
				if ( null !== $this->prompts->findActive( $promptId, $lang ) ) {
					continue; // never overwrite admin-managed versions
				}
				$p            = new PromptVersion();
				$p->promptId  = $promptId;
				$p->version   = Prompts::VERSION;
				$p->type      = Prompts::typeOf( $promptId );
				$p->language  = $lang;
				$p->content   = $content;
				$p->active    = true;
				$p->createdAt = $now;
				$p->updatedAt = $now;
				if ( $this->prompts->insert( $p ) > 0 ) {
					$created++;
				}
			}
		}
		return $created;
	}
}
