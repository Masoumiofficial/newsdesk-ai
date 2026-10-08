<?php
/**
 * Adapter contract for source types (§6).
 *
 * @package NewsDesk\AI\News\Contracts
 */

namespace NewsDesk\AI\News\Contracts;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Domain\Entity\Source;
use NewsDesk\AI\News\Value\RawNewsItem;

interface SourceAdapterInterface {

	/**
	 * The source type this adapter handles.
	 */
	public function type(): string;

	public function supports( Source $source ): bool;

	/**
	 * Fetch and parse raw items (no normalization, no persistence).
	 *
	 * @param array $opts max_items, since
	 * @return RawNewsItem[]
	 * @throws \NewsDesk\AI\Support\Http\HttpFetchException on transport failure
	 * @throws \NewsDesk\AI\News\Exception\SourceParseException on feed parse failure
	 */
	public function fetch( Source $source, array $opts = array() ): array;
}
