<?php
/**
 * Fetch → normalize → store for one or all sources (per-source isolation §65).
 * NEVER creates WordPress posts (§3 acceptance).
 *
 * @package NewsDesk\AI\Application
 */

namespace NewsDesk\AI\Application;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Contracts\NewsItemRepositoryInterface;
use NewsDesk\AI\Application\Contracts\SourceRepositoryInterface;
use NewsDesk\AI\Domain\Entity\NewsItem;
use NewsDesk\AI\Domain\Entity\Source;
use NewsDesk\AI\Domain\Exception\DomainException;
use NewsDesk\AI\Logging\Contracts\LoggerInterface;
use NewsDesk\AI\News\AdapterFactory;
use NewsDesk\AI\News\Exception\SourceParseException;
use NewsDesk\AI\News\Exception\UnsupportedSourceTypeException;
use NewsDesk\AI\News\Normalizer\NewsItemNormalizer;
use NewsDesk\AI\Support\Time;

final class DiscoveryService {

	/** @var SourceRepositoryInterface */
	private $sources;
	/** @var NewsItemRepositoryInterface */
	private $items;
	/** @var AdapterFactory */
	private $adapters;
	/** @var NewsItemNormalizer */
	private $normalizer;
	/** @var LoggerInterface */
	private $logger;
	/** @var NewsroomSettings */
	private $settings;

	public function __construct(
		SourceRepositoryInterface $sources,
		NewsItemRepositoryInterface $items,
		AdapterFactory $adapters,
		NewsItemNormalizer $normalizer,
		LoggerInterface $logger,
		NewsroomSettings $settings
	) {
		$this->sources    = $sources;
		$this->items      = $items;
		$this->adapters   = $adapters;
		$this->normalizer = $normalizer;
		$this->logger     = $logger;
		$this->settings   = $settings;
	}

	/**
	 * @param int  $onlySourceId 0 = all active sources.
	 * @param bool $force        Ignore per-source cooldown (manual/test runs).
	 * @return array<string, mixed>
	 */
	public function run( int $onlySourceId = 0, bool $force = false ): array {
		$stats = array(
			'sources'       => 0,
			'sources_ok'    => 0,
			'sources_failed' => array(),
			'sources_skipped' => 0,
			'fetched'       => 0,
			'new_items'     => 0,
			'duplicates'    => 0,
			'invalid_items' => 0,
		);

		$sources = array();
		if ( $onlySourceId > 0 ) {
			$source = $this->sources->find( $onlySourceId );
			if ( null !== $source ) {
				$sources[] = $source;
			}
		} else {
			$sources = $this->sources->findActive();
		}

		$now = Time::now();
		foreach ( $sources as $source ) {
			$stats['sources']++;
			if ( ! $source->isFetchable() ) {
				$stats['sources_skipped']++;
				continue;
			}
			// cooldown
			if ( ! $force && null !== $source->lastFetchAt && null !== $source->lastSuccessAt ) {
				$interval = max( 15, $source->fetchIntervalMin ) * 60;
				if ( $source->lastSuccessAt->getTimestamp() + $interval > $now->getTimestamp() ) {
					$stats['sources_skipped']++;
					$this->logger->debug( 'Source skipped (cooldown)', array( 'source_id' => $source->id, 'name' => $source->name ), 'news.discovery', 'SOURCE_COOLDOWN' );
					continue;
				}
			}

			try {
				$adapter = $this->adapters->make( $source->type );
				$this->sources->markFetchStart( $source->id, $now );

				$rawItems = $adapter->fetch(
					$source,
					array(
						'max_items' => $this->settings->maxItemsPerSource(),
						'timeout'   => $this->settings->fetchTimeout(),
					)
				);
				$stats['fetched'] += count( $rawItems );

				foreach ( $rawItems as $raw ) {
					try {
						$item = $this->normalizer->normalize( $raw, $source );
					} catch ( DomainException $e ) {
						$stats['invalid_items']++;
						$this->logger->debug( 'Item skipped (invalid)', array( 'error' => $e->getMessage(), 'source_id' => $source->id ), 'news.discovery', 'ITEM_INVALID' );
						continue;
					}
					$item->status = NewsItem::STATUS_NORMALIZED;

					// v2.0: a duplicate is *persisted* as a duplicate row that
					// points at its winner, instead of being counted and
					// thrown away. This makes countDuplicates() truthful, keeps
					// the cluster auditable, and lets the Inbox show "seen on
					// N sources" — the evidence Phase 6 (KEEP/MERGE/REJECT)
					// and cross-source corroboration both need.
					$match = $this->items->findDuplicate( $item->guid, $item->canonicalUrl, $item->contentHash, $source->id );
					if ( null !== $match ) {
						$item->status         = NewsItem::STATUS_DUPLICATE;
						$item->duplicateOfId  = (int) $match['id'];
						$item->duplicateLevel = (string) $match['level'];
						$item->dedupConfirmedAt = Time::now();

						$inserted = $this->items->insert( $item );
						if ( $inserted > 0 ) {
							$stats['duplicates']++;
							$this->items->touchLastSeen( (int) $match['id'] );
							$this->logger->debug(
								'Duplicate recorded',
								array(
									'source_id'  => $source->id,
									'item_id'    => $inserted,
									'winner_id'  => (int) $match['id'],
									'level'      => (string) $match['level'],
								),
								'news.discovery',
								'ITEM_DUPLICATE'
							);
						}
						continue;
					}

					$inserted = $this->items->insert( $item );
					if ( $inserted > 0 ) {
						$stats['new_items']++;
					}
				}

				$this->sources->markSuccess( $source->id, Time::now() );
				$stats['sources_ok']++;
			} catch ( \Exception $e ) {
				$failed = array(
					'source_id' => $source->id,
					'name'      => $source->name,
					'type'      => $source->type,
					'code'      => $e instanceof SourceParseException || $e instanceof UnsupportedSourceTypeException ? 'SOURCE_' . $e->getCode() : $this->codeOf( $e ),
				);
				$stats['sources_failed'][] = $failed;
				$this->sources->markError( $source->id, Time::now(), $this->codeOf( $e ) . ': ' . $e->getMessage() );
				$this->logger->warning( 'Source failed this run', array( 'source_id' => $source->id, 'name' => $source->name, 'error_code' => $failed['code'] ), 'news.discovery', 'SOURCE_FAILED' );
			}
		}

		$this->logger->info( 'Discovery run finished', $stats, 'news.discovery', 'DISCOVERY_DONE' );
		return $stats;
	}

	private function codeOf( \Exception $e ): string {
		if ( $e instanceof \NewsDesk\AI\Support\Http\HttpFetchException ) {
			return $e->errorCode();
		}
		if ( $e instanceof SourceParseException ) {
			return $e->errorCode();
		}
		return 'UNEXPECTED';
	}
}
