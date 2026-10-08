<?php
/**
 * SEO adapter contract (layer 14, §76).
 *
 * The ENGINE always computes the SEO/AEO/GEO package (title, meta description,
 * slug, headings, JSON-LD, entities). An adapter only APPLIES that package to
 * the target plugin's fields. Adapters whose vendor contract is not verified
 * from official documentation MUST NOT guess meta keys — they stay inactive
 * (isActive() === false) with the required contract documented in the class.
 *
 * @package NewsDesk\AI\Application\Seo
 */

namespace NewsDesk\AI\Application\Seo;

defined( 'ABSPATH' ) || exit;

interface SeoAdapterInterface {

	/** Stable adapter id, e.g. 'native', 'rankmath', 'yoast', 'aioseo'. */
	public function id(): string;

	/** True only when the target plugin is present AND its contract is verified. */
	public function isActive(): bool;

	/**
	 * Apply the computed package to a post.
	 *
	 * @param int   $postId WordPress post ID.
	 * @param array $seo    Engine-computed package: title, meta_description,
	 *                      slug, focus_entities, jsonld, og_image, canonical.
	 */
	public function apply( int $postId, array $seo ): void;
}
