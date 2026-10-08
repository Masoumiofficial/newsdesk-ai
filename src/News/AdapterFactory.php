<?php
/**
 * Source type → adapter registry (§6 Adapter pattern).
 *
 * @package NewsDesk\AI\News
 */

namespace NewsDesk\AI\News;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Contracts\HttpClientInterface;
use NewsDesk\AI\News\Adapters\AtomAdapter;
use NewsDesk\AI\News\Adapters\JsonAdapter;
use NewsDesk\AI\News\Adapters\ManualAdapter;
use NewsDesk\AI\News\Adapters\RestAdapter;
use NewsDesk\AI\News\Adapters\RssAdapter;
use NewsDesk\AI\News\Adapters\WebAdapter;
use NewsDesk\AI\News\Contracts\SourceAdapterInterface;
use NewsDesk\AI\News\Exception\UnsupportedSourceTypeException;

final class AdapterFactory {

	/** @var HttpClientInterface */
	private $http;
	/** @var array<string, callable> */
	private $registry = array();
	/** @var int */
	private $maxBytes;

	public function __construct( HttpClientInterface $http, int $maxBytes = 2097152 ) {
		$this->http     = $http;
		$this->maxBytes = $maxBytes;
		$this->registerDefaults();
	}

	private function registerDefaults(): void {
		$this->register( 'rss', function () {
			return new RssAdapter( $this->http, $this->maxBytes );
		} );
		$this->register( 'atom', function () {
			return new AtomAdapter( $this->http, $this->maxBytes );
		} );
		$this->register( 'json', function () {
			return new JsonAdapter();
		} );
		$this->register( 'rest', function () {
			return new RestAdapter();
		} );
		$this->register( 'web', function () {
			return new WebAdapter( $this->http, $this->maxBytes );
		} );
		$this->register( 'manual', function () {
			return new ManualAdapter();
		} );
	}

	/**
	 * Extension point for custom adapters.
	 */
	public function register( string $type, callable $factory ): void {
		$this->registry[ $type ] = $factory;
	}

	public function make( string $type ): SourceAdapterInterface {
		if ( ! isset( $this->registry[ $type ] ) ) {
			throw new UnsupportedSourceTypeException( 'Unsupported source type: ' . $type );
		}
		return call_user_func( $this->registry[ $type ] );
	}
}
