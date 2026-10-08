<?php
/**
 * REST API adapter — contract defined; REQUIRED CONTRACT: vendor REST schema (Phase 3+).
 *
 * @package NewsDesk\AI\News\Adapters
 */

namespace NewsDesk\AI\News\Adapters;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Domain\Entity\Source;

final class RestAdapter extends StubAdapter {

	public const REQUIRED_CONTRACT = 'REST: endpoint per source.settings with documented JSON response schema';

	public function type(): string {
		return 'rest';
	}

	public function supports( Source $source ): bool {
		return 'rest' === $source->type;
	}
}
