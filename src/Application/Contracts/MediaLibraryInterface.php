<?php
/**
 * Media import contract (Phase 5, §66). The AI pipeline NEVER writes to the
 * media library directly: it hands sanitized binary data over this boundary
 * (tests use an in-memory fake; production uses WordPress core functions).
 *
 * @package NewsDesk\AI\Application\Contracts
 */

namespace NewsDesk\AI\Application\Contracts;

defined( 'ABSPATH' ) || exit;

interface MediaLibraryInterface {

	/**
	 * Store binary image data as a WP attachment.
	 *
	 * @param string $fileName    safe file name (e.g. story-12.jpg) — never derived from user input
	 * @param string $mimeType    validated image MIME (jpeg|png|webp)
	 * @param string $binary      raw bytes (already size-capped by the caller)
	 * @param string $altText     accessibility text (generated, never raw source text)
	 * @return array{attachment_id: int, error_code?: string}  0 attachment id = failure
	 */
	public function import( string $fileName, string $mimeType, string $binary, string $altText = '' ): array;
}
