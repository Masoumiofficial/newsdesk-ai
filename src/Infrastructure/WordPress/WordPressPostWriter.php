<?php
/**
 * WordPress implementation of the post writer.
 *
 * §73: post_status is FORCED to 'draft' — a caller value of 'publish' is
 * overwritten unconditionally. There is no publish method on the interface.
 *
 * @package NewsDesk\AI\Infrastructure\WordPress
 */

namespace NewsDesk\AI\Infrastructure\WordPress;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Contracts\WpPostWriterInterface;

final class WordPressPostWriter implements WpPostWriterInterface {

	/** Meta key: the live post a revision draft targets. */
	public const META_REVISES = '_newsdesk_revises_post';
	/** Meta key: which editorial decision produced the revision. */
	public const META_KIND = '_newsdesk_revision_kind';
	/** Marker wrapped around appended correction notices. */
	public const CORRECTION_CLASS = 'nd-correction-notice';

	public function createDraft( array $post, array $meta ): int {
		return $this->insertDraft( $post, $meta );
	}

	public function createRevisionDraft( int $targetPostId, string $kind, array $post, array $meta ): int {
		if ( $targetPostId <= 0 ) {
			return 0;
		}
		// The link to the target is written by us, not by the caller, so a
		// malformed $meta can never detach a revision from what it revises.
		$meta[ self::META_REVISES ] = $targetPostId;
		$meta[ self::META_KIND ]    = strtoupper( $kind );

		return $this->insertDraft( $post, $meta );
	}

	public function appendCorrectionNotice( int $postId, string $notice ): bool {
		if ( $postId <= 0 || '' === trim( $notice ) ) {
			return false;
		}
		if ( ! function_exists( 'get_post' ) || ! function_exists( 'wp_update_post' ) ) {
			return false;
		}
		$post = get_post( $postId );
		if ( ! $post ) {
			return false;
		}

		$html = "\n\n<div class=\"" . self::CORRECTION_CLASS . '">'
			. wp_kses_post( $notice )
			. "</div>\n";

		// Appending only: the existing body is passed through untouched.
		$result = wp_update_post(
			array(
				'ID'           => $postId,
				'post_content' => (string) $post->post_content . $html,
			),
			true
		);
		if ( is_wp_error( $result ) || ! $result ) {
			return false;
		}
		if ( function_exists( 'update_post_meta' ) ) {
			update_post_meta( $postId, '_newsdesk_corrected_at', gmdate( 'Y-m-d H:i:s' ) );
		}
		return true;
	}

	public function isPublished( int $postId ): bool {
		if ( $postId <= 0 || ! function_exists( 'get_post_status' ) ) {
			return false;
		}
		return 'publish' === get_post_status( $postId );
	}

	/**
	 * @param array<string, mixed> $post
	 * @param array<string, mixed> $meta
	 */
	private function insertDraft( array $post, array $meta ): int {
		if ( ! function_exists( 'wp_insert_post' ) ) {
			return 0;
		}
		$post['post_status']  = 'draft'; // §73 — non-overridable.
		$post['post_type']    = isset( $post['post_type'] ) ? $post['post_type'] : 'post';
		$post['post_content'] = wp_kses_post( (string) ( $post['post_content'] ?? '' ) );
		$post['post_title']   = sanitize_text_field( (string) ( $post['post_title'] ?? '' ) );
		// A caller-supplied ID would turn an insert into an overwrite of a
		// live post — exactly what the drafts-only rule exists to prevent.
		unset( $post['ID'] );

		$postId = wp_insert_post( $post, true ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions
		if ( is_wp_error( $postId ) || ! $postId ) {
			return 0;
		}
		$postId = (int) $postId;
		foreach ( $meta as $key => $value ) {
			if ( is_array( $value ) || is_object( $value ) ) {
				$value = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			}
			update_post_meta( $postId, sanitize_key( (string) $key ), (string) $value );
		}
		return $postId;
	}
}
