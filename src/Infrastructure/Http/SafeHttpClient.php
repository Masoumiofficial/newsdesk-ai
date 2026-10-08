<?php
/**
 * SSRF-guarded HTTP (§52). GET and POST share one validated path; only http/https,
 * no localhost/private/metadata targets, every redirect hop re-validated, response
 * size capped.
 *
 * @package NewsDesk\AI\Infrastructure\Http
 */

namespace NewsDesk\AI\Infrastructure\Http;

use NewsDesk\AI\Application\Contracts\HttpClientInterface;
use NewsDesk\AI\Support\Http\HttpFetchException;
use NewsDesk\AI\Support\Http\HttpResponse;
use NewsDesk\AI\Support\Branding;

final class SafeHttpClient implements HttpClientInterface {

	/** @var string */
	private $userAgent;
	/** @var int */
	private $defaultTimeout;
	/** @var int */
	private $defaultMaxBytes;

	public function __construct( string $userAgent = '', int $timeout = 30, int $maxBytes = 2097152 ) {
		// A default argument cannot call a function, and hard-coding the version
		// here is how it silently froze at 2.0.0 while the plugin moved on.
		if ( '' === $userAgent ) {
			$userAgent = Branding::userAgent();
		}
		$this->userAgent     = $userAgent;
		$this->defaultTimeout = $timeout;
		$this->defaultMaxBytes = $maxBytes;
	}

	public function get( string $url, array $opts = array() ): HttpResponse {
		return $this->request( 'GET', $url, $opts );
	}

	public function request( string $method, string $url, array $opts = array() ): HttpResponse {
		$method       = strtoupper( trim( $method ) );
		$timeout      = isset( $opts['timeout'] ) ? (int) $opts['timeout'] : $this->defaultTimeout;
		$maxBytes     = isset( $opts['max_bytes'] ) ? (int) $opts['max_bytes'] : $this->defaultMaxBytes;
		$maxRedirects = isset( $opts['max_redirects'] ) ? (int) $opts['max_redirects'] : 3;
		$headers      = isset( $opts['headers'] ) && is_array( $opts['headers'] ) ? $opts['headers'] : array();
		$body         = isset( $opts['body'] ) && is_string( $opts['body'] ) ? $opts['body'] : null;

		$current = $url;
		for ( $hop = 0; $hop <= $maxRedirects; $hop++ ) {
			UrlValidator::validate( $current );
			IpValidator::assertHostSafe( parse_url( $current, PHP_URL_HOST ) ?: '' );

			$response = $this->requestOnce( $current, $timeout, $maxBytes, $headers, $method, $body );
			// Non-GET requests never follow redirects (bodies are not replayed).
			if ( 'GET' !== $method || ! $response->isRedirect() ) {
				$response->finalUrl = $current;
				return $response;
			}
			$location = $response->header( 'location' );
			if ( null === $location ) {
				return $response;
			}
			$next = UrlValidator::resolveRedirect( $location, $current );
			if ( null === $next ) {
				throw new HttpFetchException( 'BAD_REDIRECT', 'Redirect location could not be resolved: ' . $location );
			}
			$current = $next;
		}
		throw new HttpFetchException( 'TOO_MANY_REDIRECTS', 'Redirect limit reached for ' . $url );
	}

	private function requestOnce( string $url, int $timeout, int $maxBytes, array $headers, string $method, ?string $body ): HttpResponse {
		$response = new HttpResponse();

		if ( function_exists( 'wp_remote_request' ) && defined( 'ABSPATH' ) ) {
			return $this->wpRequest( $url, $timeout, $maxBytes, $headers, $method, $body, $response );
		}
		if ( function_exists( 'curl_init' ) ) {
			return $this->curlRequest( $url, $timeout, $maxBytes, $headers, $method, $body, $response );
		}
		return $this->streamRequest( $url, $timeout, $maxBytes, $headers, $method, $body, $response );
	}

	private function wpRequest( string $url, int $timeout, int $maxBytes, array $headers, string $method, ?string $body, HttpResponse $r ): HttpResponse {
		$opts = array(
			'timeout'            => max( 5, $timeout ),
			'redirection'        => 0, // manual redirect loop (validated)
			'user-agent'         => $this->userAgent,
			'limit_response_size'=> $maxBytes,
			'method'             => $method,
		);
		if ( null !== $body ) {
			$opts['body'] = $body;
		}
		if ( ! empty( $headers ) ) {
			$opts['headers'] = $headers;
		}
		$raw = wp_remote_request( $url, $opts ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_request_wp_remote_request
		if ( is_wp_error( $raw ) ) {
			throw new HttpFetchException( 'TRANSPORT', $raw->get_error_message() );
		}
		$r->status  = (int) wp_remote_retrieve_response_code( $raw );
		$r->headers = (array) wp_remote_retrieve_headers( $raw );
		$respBody   = wp_remote_retrieve_body( $raw );
		$r->body    = strlen( $respBody ) > $maxBytes ? substr( $respBody, 0, $maxBytes ) : $respBody;
		return $r;
	}

	private function curlRequest( string $url, int $timeout, int $maxBytes, array $headers, string $method, ?string $body, HttpResponse $r ): HttpResponse {
		$ch = curl_init();
		$responseHeaders = array();
		$bodyLen = 0;

		$basic = array(
			CURLOPT_URL            => $url,
			CURLOPT_RETURNTRANSFER => false,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_CONNECTTIMEOUT => min( 10, max( 3, $timeout ) ),
			CURLOPT_TIMEOUT        => $timeout,
			CURLOPT_USERAGENT      => $this->userAgent,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_HEADERFUNCTION => static function ( $ch, $line ) use ( &$responseHeaders ) {
				$trimmed = trim( $line );
				if ( '' === $trimmed || 0 === strpos( $trimmed, 'HTTP/' ) ) {
					return strlen( $line );
				}
				$pos = strpos( $trimmed, ':' );
				if ( false !== $pos ) {
					$responseHeaders[ substr( $trimmed, 0, $pos ) ] = trim( substr( $trimmed, $pos + 1 ) );
				}
				return strlen( $line );
			},
			CURLOPT_WRITEFUNCTION  => static function ( $ch, $chunk ) use ( &$bodyLen, $maxBytes, &$r ) {
				$bodyLen += strlen( $chunk );
				if ( $bodyLen > $maxBytes ) {
					return -1; // abort transfer
				}
				$r->body .= $chunk;
				return strlen( $chunk );
			},
		);
		if ( null !== $body ) {
			$basic[ CURLOPT_POSTFIELDS ] = $body;
		}
		curl_setopt_array( $ch, $basic );
		$flat = array();
		foreach ( $headers as $k => $v ) {
			$flat[] = $k . ': ' . $v;
		}
		if ( $flat ) {
			curl_setopt( $ch, CURLOPT_HTTPHEADER, $flat );
		}

		$start  = microtime( true );
		$result = curl_exec( $ch );
		$errno  = curl_errno( $ch );
		$err    = curl_error( $ch );
		$info   = curl_getinfo( $ch );
		curl_close( $ch );

		$r->durationMs = (int) round( ( microtime( true ) - $start ) * 1000 );
		if ( false === $result ) {
			if ( -1 === $bodyLen || $bodyLen > $maxBytes ) {
				$r->body = substr( $r->body, 0, $maxBytes );
				$r->status = 200;
				return $r; // truncated but usable
			}
			throw new HttpFetchException( 'TRANSPORT', sprintf( 'cURL error %d: %s', $errno, $err ) );
		}
		$r->status  = (int) ( $info['http_code'] ?? 0 );
		$r->headers = $responseHeaders;
		return $r;
	}

	private function streamRequest( string $url, int $timeout, int $maxBytes, array $headers, string $method, ?string $body, HttpResponse $r ): HttpResponse {
		$flat = array();
		foreach ( $headers as $k => $v ) {
			$flat[] = $k . ': ' . $v;
		}
		$http = array(
			'method'          => $method,
			'timeout'         => $timeout,
			'follow_location' => 0,
			'ignore_errors'   => true,
			'user_agent'      => $this->userAgent,
			'header'          => implode( "\r\n", $flat ),
		);
		if ( null !== $body ) {
			$http['content'] = $body;
		}
		$context = stream_context_create(
			array(
				'http' => $http,
				'ssl'  => array( 'verify_peer' => true, 'verify_peer_name' => true ),
			)
		);
		$fp = @fopen( $url, 'rb', false, $context ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $fp ) {
			throw new HttpFetchException( 'TRANSPORT', 'Could not open URL stream: ' . $url );
		}
		$start = microtime( true );
		$meta  = stream_get_meta_data( $fp );
		$body  = stream_get_contents( $fp, $maxBytes + 1 );
		fclose( $fp );
		$r->durationMs = (int) round( ( microtime( true ) - $start ) * 1000 );
		if ( false === $body ) {
			throw new HttpFetchException( 'TRANSPORT', 'Stream read failed: ' . $url );
		}
		$r->body    = strlen( $body ) > $maxBytes ? substr( $body, 0, $maxBytes ) : $body;
		$r->status  = 0;
		if ( isset( $meta['wrapper_data'] ) && is_array( $meta['wrapper_data'] ) ) {
			foreach ( $meta['wrapper_data'] as $line ) {
				if ( preg_match( '/^HTTP\/\S+\s+(\d{3})/', (string) $line, $m ) ) {
					$r->status = (int) $m[1];
				} elseif ( false !== strpos( (string) $line, ':' ) ) {
					list( $k, $v )         = explode( ':', (string) $line, 2 );
					$r->headers[ trim( $k ) ] = trim( $v );
				}
			}
		}
		return $r;
	}
}
