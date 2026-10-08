<?php
/**
 * JSON feed adapter — contract defined; REQUIRED CONTRACT: JSON array of objects with
 * title/link/guid/published_at fields (schema TBD by vendor). Deferred to Phase 3+.
 *
 * @package NewsDesk\AI\News\Adapters
 */

namespace NewsDesk\AI\News\Adapters;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Domain\Entity\Source;

final class JsonAdapter extends StubAdapter {

	public const REQUIRED_CONTRACT = 'JSON: {"items":[{"title","link","guid","published_at","content"}]}';

	public function type(): string {
		return 'json';
	}

	public function supports( Source $source ): bool {
		return 'json' === $source->type;
	}
}
