<?php
/**
 * Read access to existing site posts (internal link targets, layer 15).
 * The engine only needs id/title/url; the WP implementation wraps get_posts.
 *
 * @package NewsDesk\AI\Application\Contracts
 */

namespace NewsDesk\AI\Application\Contracts;

defined( 'ABSPATH' ) || exit;

interface WpPostIndexInterface {

	/**
	 * Published posts the engine may link to (recent first).
	 *
	 * @return array<int, array{post_id: int, title: string, url: string}>
	 */
	public function existingPosts( int $limit = 100 ): array;

	/** Permalink for one post id ('' when unknown). */
	public function urlFor( int $postId ): string;
}
