<?php
/**
 * Contract-only adapters (§76): interface defined, implementation requires a documented contract.
 *
 * @package NewsDesk\AI\News\Adapters
 */

namespace NewsDesk\AI\News\Adapters;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Domain\Entity\Source;
use NewsDesk\AI\News\Contracts\SourceAdapterInterface;
use NewsDesk\AI\News\Exception\NotYetImplementedException;

/**
 * @phpstan-consistent-constructor
 */
abstract class StubAdapter implements SourceAdapterInterface {

	protected function notYet( Source $source ): NotYetImplementedException {
		return new NotYetImplementedException(
			sprintf(
				'Adapter %s for source type "%s" is a documented contract stub (see ARCHITECTURE.md). Required contract: %s',
				static::class,
				$source->type,
				static::REQUIRED_CONTRACT // phpcs:ignore
			)
		);
	}

	public function fetch( Source $source, array $opts = array() ): array {
		throw $this->notYet( $source );
	}
}
