<?php
/**
 * Atom adapter (§6).
 *
 * @package NewsDesk\AI\News\Adapters
 */

namespace NewsDesk\AI\News\Adapters;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Contracts\HttpClientInterface;
use NewsDesk\AI\Domain\Entity\Source;
use NewsDesk\AI\News\Contracts\SourceAdapterInterface;
use NewsDesk\AI\News\Value\RawNewsItem;
use NewsDesk\AI\Support\Time;
use NewsDesk\AI\Support\Branding;

final class AtomAdapter implements SourceAdapterInterface {

	/** @var HttpClientInterface */
	private $http;
	/** @var int */
	private $maxBytes;

	public function __construct( HttpClientInterface $http, int $maxBytes = 2097152 ) {
		$this->http    = $http;
		$this->maxBytes = $maxBytes;
	}

	public function type(): string {
		return 'atom';
	}

	public function supports( Source $source ): bool {
		return 'atom' === $source->type;
	}

	public function fetch( Source $source, array $opts = array() ): array {
		$maxItems = isset( $opts['max_items'] ) ? (int) $opts['max_items'] : 50;
		$response = $this->http->get(
			$source->feedUrl,
			array(
				'timeout'    => isset( $opts['timeout'] ) ? (int) $opts['timeout'] : 20,
				'max_bytes'  => $this->maxBytes,
				'user_agent' => 'Mozilla/5.0 (compatible; ' . Branding::userAgent() . ')',
			)
		);
		if ( ! $response->isOk() ) {
			throw new \NewsDesk\AI\Support\Http\HttpFetchException(
				'HTTP_' . $response->status,
				sprintf( 'Feed responded with HTTP %d', $response->status )
			);
		}
		$raw = FeedParser::parse( $response->body, 'atom' );
		return $this->map( $raw, $maxItems );
	}

	/**
	 * @param array<int, array<string, mixed>> $raw
	 * @return RawNewsItem[]
	 */
	private function map( array $raw, int $maxItems ): array {
		$items = array();
		$seen  = 0;
		foreach ( $raw as $entry ) {
			if ( $seen >= $maxItems ) {
				break;
			}
			$entry['published_at'] = Time::parseFeedDate( (string) $entry['published_at'] );
			$items[] = RawNewsItem::fromArray( $entry );
			$seen++;
		}
		return $items;
	}
}
