<?php
/**
 * WordPress post index (internal-link targets). Wraps get_posts/get_permalink —
 * core WP APIs, no third-party contract involved.
 *
 * @package NewsDesk\AI\Infrastructure\WordPress
 */

namespace NewsDesk\AI\Infrastructure\WordPress;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Contracts\WpPostIndexInterface;

final class WordPressPostIndex implements WpPostIndexInterface {

	public function existingPosts( int $limit = 100 ): array {
		if ( ! function_exists( 'get_posts' ) ) {
			return array();
		}
		$posts = get_posts( array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => max( 1, min( 200, $limit ) ),
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		) );
		$out = array();
		foreach ( (array) $posts as $post ) {
			$post = (array) $post;
			$id   = (int) ( $post['ID'] ?? 0 );
			if ( $id <= 0 ) {
				continue;
			}
			$out[] = array(
				'post_id' => $id,
				'title'   => (string) ( $post['post_title'] ?? '' ),
				'url'     => (string) get_permalink( $id ),
			);
		}
		return $out;
	}

	public function urlFor( int $postId ): string {
		if ( ! function_exists( 'get_permalink' ) || $postId <= 0 ) {
			return '';
		}
		return (string) get_permalink( $postId );
	}
}
