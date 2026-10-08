<?php
/**
 * WordPress media library import (Phase 5, §66) — core functions only
 * (wp_upload_bits + media_handle_sideload), never direct file writes.
 * Outside a WP runtime (unit tests, CLI) it fails cleanly with MEDIA_UNAVAILABLE;
 * the image stage is optional so the job keeps going (§66, §33).
 *
 * @package NewsDesk\AI\Infrastructure\WordPress
 */

namespace NewsDesk\AI\Infrastructure\WordPress;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Contracts\MediaLibraryInterface;

final class WpMediaLibrary implements MediaLibraryInterface {

	public function import( string $fileName, string $mimeType, string $binary, string $altText = '' ): array {
		if ( ! function_exists( 'wp_upload_bits' ) || ! function_exists( 'media_handle_sideload' ) ) {
			return array( 'attachment_id' => 0, 'error_code' => 'MEDIA_UNAVAILABLE' );
		}
		$name = preg_replace( '/[^a-z0-9_\-.]/i', '-', $fileName );
		if ( '' === trim( $name ) ) {
			$name = 'nd-image-' . wp_generate_password( 8, false, false );
		}
		$upload = wp_upload_bits( $name, null, $binary );
		if ( ! empty( $upload['error'] ) || empty( $upload['file'] ) ) {
			return array( 'attachment_id' => 0, 'error_code' => 'UPLOAD_FAILED' );
		}
		// media_handle_sideload moves the tmp file into the library; post_id 0 =
		// unattached — the draft links it later via _thumbnail_id.
		$attachmentId = media_handle_sideload( $upload, 0, $altText );
		if ( is_wp_error( $attachmentId ) ) {
			@unlink( $upload['file'] ); // clean the stray upload on failure (no error surface)
			return array( 'attachment_id' => 0, 'error_code' => 'SIDELOAD_FAILED' );
		}
		return array( 'attachment_id' => (int) $attachmentId );
	}
}
