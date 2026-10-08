<?php
/**
 * Native adapter — WordPress core only (no third-party contract needed):
 * stores the full package in our own meta keys and feeds <head> output through
 * standard WP hooks. Third-party SEO plugins are separate adapters (§76).
 *
 * @package NewsDesk\AI\Application\Seo
 */

namespace NewsDesk\AI\Application\Seo;

defined( 'ABSPATH' ) || exit;

final class NativeSeoAdapter implements SeoAdapterInterface {

	public const META_PREFIX = '_newsdesk_seo_';

	public function id(): string {
		return 'native';
	}

	public function isActive(): bool {
		return true; // shipped with the plugin, always available
	}

	public function apply( int $postId, array $seo ): void {
		// WordPress-native: title, meta description, canonical, og + JSON-LD.
		$fields = array(
			'title'            => (string) ( $seo['title'] ?? '' ),
			'meta_description' => (string) ( $seo['meta_description'] ?? '' ),
			'slug'             => (string) ( $seo['slug'] ?? '' ),
			'canonical'        => (string) ( $seo['canonical'] ?? '' ),
			'focus_entities'   => json_encode( (array) ( $seo['focus_entities'] ?? array() ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			'jsonld'           => json_encode( (array) ( $seo['jsonld'] ?? array() ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
		);
		foreach ( $fields as $key => $value ) {
			if ( '' !== $value ) {
				update_post_meta( $postId, self::META_PREFIX . $key, $value );
			}
		}
	}
}
