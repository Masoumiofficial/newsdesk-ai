<?php
namespace NewsDesk\AI\Application\Contracts;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Support\Http\HttpResponse;

/**
 * Outbound HTTP with mandatory SSRF validation (§52).
 */
interface HttpClientInterface {

	/**
	 * @param array $opts timeout (s), max_bytes, headers, max_redirects, user_agent
	 * @throws \NewsDesk\AI\Support\Http\UrlValidationException on blocked/invalid URL
	 * @throws \NewsDesk\AI\Support\Http\HttpFetchException on transport failure
	 */
	public function get( string $url, array $opts = array() ): HttpResponse;

	/**
	 * Generic HTTP request (GET/POST/…). Same mandatory SSRF guard as get().
	 * Body is passed as `body` (string, json-encoded by the caller); `headers` map.
	 * Redirects are followed only for GET (each hop re-validated).
	 *
	 * @param string $method HTTP method (GET/POST)
	 * @param array  $opts   timeout, max_bytes, headers, body, max_redirects
	 * @throws \NewsDesk\AI\Support\Http\UrlValidationException
	 * @throws \NewsDesk\AI\Support\Http\HttpFetchException
	 */
	public function request( string $method, string $url, array $opts = array() ): HttpResponse;
}
