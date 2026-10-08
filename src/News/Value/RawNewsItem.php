<?php
/**
 * Untrusted raw payload as produced by an adapter. Nothing here is validated yet (§3).
 *
 * @package NewsDesk\AI\News\Value
 */

namespace NewsDesk\AI\News\Value;

defined( 'ABSPATH' ) || exit;

final class RawNewsItem {

	/** @var string */
	public $title = '';
	/** @var string */
	public $link = '';
	/** @var string */
	public $guid = '';
	/** @var string */
	public $description = '';
	/** @var string */
	public $content = '';
	/** @var string */
	public $author = '';
	/** @var array<int, string> */
	public $categories = array();
	/** @var \DateTimeImmutable|null */
	public $publishedAt;
	/** @var array<string, mixed> */
	public $extra = array();

	public static function fromArray( array $data ): self {
		$item              = new self();
		$item->title       = (string) ( $data['title'] ?? '' );
		$item->link        = (string) ( $data['link'] ?? '' );
		$item->guid        = (string) ( $data['guid'] ?? '' );
		$item->description = (string) ( $data['description'] ?? '' );
		$item->content     = (string) ( $data['content'] ?? '' );
		$item->author      = (string) ( $data['author'] ?? '' );
		$item->categories  = isset( $data['categories'] ) && is_array( $data['categories'] ) ? $data['categories'] : array();
		$item->publishedAt = isset( $data['published_at'] ) && $data['published_at'] instanceof \DateTimeImmutable ? $data['published_at'] : null;
		$item->extra       = isset( $data['extra'] ) && is_array( $data['extra'] ) ? $data['extra'] : array();
		return $item;
	}
}
