<?php
/**
 * Yoast SEO adapter (§76).
 *
 * VERIFIED CONTRACT — Yoast stores these as plain post meta on the post:
 *   _yoast_wpseo_title      SEO title (supports Yoast's %%variable%% syntax)
 *   _yoast_wpseo_metadesc   meta description
 *   _yoast_wpseo_focuskw    focus keyphrase
 *   _yoast_wpseo_canonical  canonical URL
 *   _yoast_wpseo_meta-robots-noindex   '1' = noindex, '2' = index, '' = default
 *
 * The engine still computes the whole package; this adapter only mirrors it
 * into Yoast's fields so the Yoast metabox/preview shows what we generated.
 * We never overwrite a value an editor has already set by hand.
 *
 * @package NewsDesk\AI\Application\Seo
 */

namespace NewsDesk\AI\Application\Seo;

defined( 'ABSPATH' ) || exit;

final class YoastAdapter implements SeoAdapterInterface {

	/** Yoast's own free-plugin constant, defined in wp-seo.php. */
	private const DETECT_CONSTANT = 'WPSEO_VERSION';
	/** Fallback detection: the main plugin class. */
	private const DETECT_CLASS = 'WPSEO_Options';

	public function id(): string {
		return 'yoast';
	}

	public function isActive(): bool {
		return defined( self::DETECT_CONSTANT ) || class_exists( self::DETECT_CLASS );
	}

	public function apply( int $postId, array $seo ): void {
		if ( $postId <= 0 || ! $this->isActive() ) {
			return;
		}

		$map = array(
			'_yoast_wpseo_title'     => (string) ( $seo['title'] ?? '' ),
			'_yoast_wpseo_metadesc'  => (string) ( $seo['meta_description'] ?? '' ),
			'_yoast_wpseo_focuskw'   => (string) ( $seo['focus_keyword'] ?? '' ),
			'_yoast_wpseo_canonical' => (string) ( $seo['canonical'] ?? '' ),
		);

		foreach ( $map as $key => $value ) {
			if ( '' === $value ) {
				continue;
			}
			// Do not clobber a human edit.
			$existing = get_post_meta( $postId, $key, true );
			if ( '' !== (string) $existing ) {
				continue;
			}
			update_post_meta( $postId, $key, $value );
		}
	}
}
