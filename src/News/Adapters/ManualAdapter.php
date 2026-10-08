<?php
/**
 * Manual entry adapter — contract defined; editor UI feeds RawNewsItem via admin (Phase 3+).
 *
 * @package NewsDesk\AI\News\Adapters
 */

namespace NewsDesk\AI\News\Adapters;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Domain\Entity\Source;

final class ManualAdapter extends StubAdapter {

	public const REQUIRED_CONTRACT = 'MANUAL: admin form persisting RawNewsItem (manual source has no feed_url)';

	public function type(): string {
		return 'manual';
	}

	public function supports( Source $source ): bool {
		return 'manual' === $source->type;
	}
}
