<?php
/**
 * Chooses which SEO adapters receive the engine's package (§76).
 *
 * v1.6.0 built four adapters but registered only NativeSeoAdapter in the
 * container and never called apply() at all — DraftService instead hardcoded
 * Yoast + Rank Math meta keys into every draft, writing them even on sites
 * where neither plugin exists. This resolver replaces that: the native adapter
 * always runs (it is our own storage), and any third-party adapter runs only
 * when its plugin is actually present and its contract is verified.
 *
 * @package NewsDesk\AI\Application\Seo
 */

namespace NewsDesk\AI\Application\Seo;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Logging\Contracts\LoggerInterface;

final class SeoAdapterResolver {

	/** @var SeoAdapterInterface[] */
	private $adapters;
	/** @var LoggerInterface|null */
	private $logger;

	/**
	 * @param SeoAdapterInterface[] $adapters
	 */
	public function __construct( array $adapters, ?LoggerInterface $logger = null ) {
		$this->adapters = array();
		foreach ( $adapters as $adapter ) {
			if ( $adapter instanceof SeoAdapterInterface ) {
				$this->adapters[] = $adapter;
			}
		}
		$this->logger = $logger;
	}

	/**
	 * Adapters that will actually run, in order.
	 *
	 * @return SeoAdapterInterface[]
	 */
	public function active(): array {
		$active = array();
		foreach ( $this->adapters as $adapter ) {
			if ( $adapter->isActive() ) {
				$active[] = $adapter;
			}
		}

		/**
		 * Filter the SEO adapters that will receive the computed package.
		 *
		 * @param SeoAdapterInterface[] $active   Adapters about to run.
		 * @param SeoAdapterInterface[] $all      Every registered adapter.
		 */
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'newsdesk_seo_adapters', $active, $this->adapters );
			if ( is_array( $filtered ) ) {
				$active = array();
				foreach ( $filtered as $adapter ) {
					if ( $adapter instanceof SeoAdapterInterface ) {
						$active[] = $adapter;
					}
				}
			}
		}
		return $active;
	}

	/** Ids of every registered adapter, for the health check / settings UI. */
	public function ids(): array {
		$out = array();
		foreach ( $this->adapters as $adapter ) {
			$out[] = $adapter->id();
		}
		return $out;
	}

	/**
	 * Apply the package through every active adapter.
	 *
	 * An adapter that throws must never break draft creation, so each call is
	 * isolated and logged.
	 *
	 * @return string[] ids of the adapters that ran
	 */
	public function apply( int $postId, array $seo ): array {
		$ran = array();
		foreach ( $this->active() as $adapter ) {
			try {
				$adapter->apply( $postId, $seo );
				$ran[] = $adapter->id();
			} catch ( \Throwable $e ) {
				if ( $this->logger ) {
					$this->logger->warning(
						'SEO adapter failed',
						array(
							'adapter' => $adapter->id(),
							'post_id' => $postId,
							'error'   => $e->getMessage(),
						),
						'seo.adapter',
						'SEO_ADAPTER_FAILED'
					);
				}
			}
		}
		if ( $this->logger ) {
			$this->logger->debug(
				'SEO adapters applied',
				array(
					'post_id'  => $postId,
					'adapters' => $ran,
				),
				'seo.adapter',
				'SEO_ADAPTERS_APPLIED'
			);
		}
		return $ran;
	}
}
