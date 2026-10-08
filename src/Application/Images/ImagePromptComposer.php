<?php
/**
 * Phase 5 — builds the final image-prompt text from the story/plan (data) and
 * the admin-editable `image.prompt` template (instruction). §25 applies:
 * every story-derived fragment is sanitized as DATA (stripped control chars,
 * injection signals quarantined, length capped); the safety constraints
 * (no text/logo/identifiable persons/brands) live in the fixed template
 * instruction part, so a hostile source can never remove them.
 *
 * §76 note (documented contract): BrandComposer from ARCHITECTURE.md was NOT
 * implemented — there is no written contract for brand-composition behavior
 * (which marks to draw, transparencies, overlay rules). Left as a TODO for
 * the phase that defines it; until then the strict "no logos/brands" rule in
 * the template is the conservative default.
 *
 * @package NewsDesk\AI\Application\Images
 */

namespace NewsDesk\AI\Application\Images;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Ai\Exception\NonRetryableProviderException;
use NewsDesk\AI\Application\Ai\PromptInjectionGuard;
use NewsDesk\AI\Application\Ai\PromptRegistry;
use NewsDesk\AI\Application\Ai\Prompts;

final class ImagePromptComposer {

	/** Per-fragment caps (data, §25). */
	private const MAX_TITLE = 160;
	private const MAX_ANGLE = 320;

	/** @var PromptRegistry */
	private $prompts;
	/** @var PromptInjectionGuard */
	private $guard;

	public function __construct( ?PromptRegistry $prompts = null, ?PromptInjectionGuard $guard = null ) {
		$this->prompts = $prompts ?: new PromptRegistry();
		$this->guard   = $guard ?: new PromptInjectionGuard();
	}

	/**
	 * @param string $language fa|en
	 * @param array  $vars     {title: string, angle: string}
	 * @return array{prompt: string, prompt_version: string}
	 * @throws NonRetryableProviderException PROMPT_MISSING
	 */
	public function compose( string $language, array $vars ): array {
		$resolved = $this->prompts->resolve( Prompts::ID_IMAGE, $language );
		if ( '' === $resolved['content'] ) {
			throw new NonRetryableProviderException( 'PROMPT_MISSING', 'Image prompt template missing: ' . Prompts::ID_IMAGE );
		}

		$title = $this->sanitizeData( (string) ( $vars['title'] ?? '' ), self::MAX_TITLE );
		$angle = $this->sanitizeData( (string) ( $vars['angle'] ?? '' ), self::MAX_ANGLE );

		return array(
			'prompt'         => Prompts::fill( $resolved['content'], array( 'title' => $title, 'angle' => $angle ) ),
			'prompt_version' => $resolved['prompt_id'] . '@v' . $resolved['version'],
		);
	}

	/** §25: story-derived text is DATA — never instructions. */
	private function sanitizeData( string $text, int $max ): string {
		$text = $this->guard->sanitize( $text );
		if ( mb_strlen( $text, 'UTF-8' ) > $max ) {
			$text = mb_substr( $text, 0, $max, 'UTF-8' );
			// cut at a word boundary to avoid a fractured instruction
			$pos = mb_strrpos( $text, ' ', 0, 'UTF-8' );
			if ( $pos !== false && $pos > $max * 0.6 ) {
				$text = mb_substr( $text, 0, $pos, 'UTF-8' );
			}
		}
		return trim( $text );
	}
}
