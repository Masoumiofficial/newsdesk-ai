<?php
/**
 * SchemaGenerator — NewsArticle JSON-LD (schema.org). Output is plain array
 * data; the draft renderer embeds it (escaped) in the post.
 *
 * @package NewsDesk\AI\Application\Seo
 */

namespace NewsDesk\AI\Application\Seo;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Domain\Entity\Story;

final class SchemaGenerator {

	/**
	 * @param string[] $citedUrls
	 * @param array    $site  {site_name, home_url, author_name}
	 */
	public function generate( Story $story, string $headline, string $description, array $citedUrls, array $site = array(), string $slug = '', string $lang = '' ): array {
		$siteName   = (string) ( $site['site_name'] ?? '' );
		$homeUrl    = rtrim( (string) ( $site['home_url'] ?? '' ), '/' );
		$authorName = (string) ( $site['author_name'] ?? '' );

		$jsonld = array(
			'@context'      => 'https://schema.org',
			'@type'         => 'NewsArticle',
			'headline'      => $headline,
			'description'   => $description,
			'datePublished' => $story->firstPublishedAt ? $story->firstPublishedAt->format( 'c' ) : gmdate( 'c' ),
			'dateModified'  => $story->lastUpdatedAt ? $story->lastUpdatedAt->format( 'c' ) : gmdate( 'c' ),
			'inLanguage'    => '' !== $lang ? ( array( 'fa' => 'fa-IR', 'en' => 'en-US', 'ar' => 'ar' )[ $lang ] ?? $lang ) : $story->language,
			'author'        => array( '@type' => 'Organization', 'name' => '' !== $authorName ? $authorName : $siteName ),
			'publisher'     => array( '@type' => 'Organization', 'name' => $siteName ),
		);
		if ( '' !== $homeUrl ) {
			$jsonld['mainEntityOfPage'] = '' !== $slug ? $homeUrl . '/' . rawurlencode( $slug ) . '/' : $homeUrl;
		}
		if ( $citedUrls ) {
			$jsonld['citation'] = array_slice( $citedUrls, 0, 10 );
		}
		return $jsonld;
	}
}
