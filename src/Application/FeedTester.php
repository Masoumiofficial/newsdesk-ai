<?php
/**
 * "Test connection" for a source (B-9).
 *
 * v1.6.0 let an admin save a source and then wait for the next scheduled run
 * to discover the URL was wrong. This performs the real fetch + parse through
 * the same adapter the pipeline uses, reports what came back, and persists
 * nothing — so a broken feed is visible immediately and a test can never
 * pollute the inbox.
 *
 * @package NewsDesk\AI\Application
 */

namespace NewsDesk\AI\Application;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Domain\Entity\Source;
use NewsDesk\AI\Logging\Contracts\LoggerInterface;
use NewsDesk\AI\News\AdapterFactory;
use NewsDesk\AI\News\Exception\SourceParseException;
use NewsDesk\AI\News\Exception\UnsupportedSourceTypeException;
use NewsDesk\AI\Support\Http\HttpFetchException;

final class FeedTester {

	/** Items to pull during a test. Enough to prove the parse, cheap to fetch. */
	public const SAMPLE_SIZE = 5;

	/** @var AdapterFactory */
	private $adapters;
	/** @var NewsroomSettings */
	private $settings;
	/** @var LoggerInterface */
	private $logger;

	public function __construct( AdapterFactory $adapters, NewsroomSettings $settings, LoggerInterface $logger ) {
		$this->adapters = $adapters;
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * Fetch a few items from a source without saving anything.
	 *
	 * @return array{ok:bool, count:int, items:array<int,array{title:string,url:string,published:string}>, error:string, error_code:string, elapsed_ms:int}
	 */
	public function test( Source $source ): array {
		$result = array(
			'ok'         => false,
			'count'      => 0,
			'items'      => array(),
			'error'      => '',
			'error_code' => '',
			'elapsed_ms' => 0,
		);

		if ( '' === trim( (string) $source->url ) ) {
			$result['error']      = __( 'The source URL is empty.', 'newsdesk-ai' );
			$result['error_code'] = 'EMPTY_URL';
			return $result;
		}

		$started = microtime( true );
		try {
			$adapter = $this->adapters->make( $source->type );
			$raw     = $adapter->fetch(
				$source,
				array(
					'max_items' => self::SAMPLE_SIZE,
					'timeout'   => $this->settings->fetchTimeout(),
				)
			);

			$result['ok']    = true;
			$result['count'] = count( $raw );
			foreach ( array_slice( $raw, 0, self::SAMPLE_SIZE ) as $item ) {
				$result['items'][] = array(
					'title'     => (string) ( $item->title ?? '' ),
					'url'       => (string) ( $item->link ?? '' ),
					'published' => $item->publishedAt instanceof \DateTimeImmutable
						? $item->publishedAt->format( 'Y-m-d H:i' )
						: '',
				);
			}

			if ( 0 === $result['count'] ) {
				// Reachable and parseable, but empty — worth flagging, not an error.
				$result['error_code'] = 'EMPTY_FEED';
			}
		} catch ( HttpFetchException $e ) {
			$result['error']      = $e->getMessage();
			$result['error_code'] = $e->errorCode();
		} catch ( SourceParseException $e ) {
			$result['error']      = $e->getMessage();
			$result['error_code'] = $e->errorCode();
		} catch ( UnsupportedSourceTypeException $e ) {
			$result['error']      = $e->getMessage();
			$result['error_code'] = 'UNSUPPORTED_TYPE';
		} catch ( \Throwable $e ) {
			$result['error']      = $e->getMessage();
			$result['error_code'] = 'UNEXPECTED';
		}

		$result['elapsed_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );

		$this->logger->info(
			'Source connection test',
			array(
				'source_id'  => $source->id,
				'type'       => $source->type,
				'ok'         => $result['ok'],
				'count'      => $result['count'],
				'error_code' => $result['error_code'],
				'elapsed_ms' => $result['elapsed_ms'],
			),
			'news.source',
			'SOURCE_TESTED'
		);

		return $result;
	}
}
