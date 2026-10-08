<?php
/**
 * Secret redaction — runs on every log row and persisted JSON (§23, §49, §53).
 *
 * @package NewsDesk\AI\Logging
 */

namespace NewsDesk\AI\Logging;

defined( 'ABSPATH' ) || exit;

final class Redaction {

	/** @var string[] */
	private static $patterns = array(
		'/(api[_-]?key|secret|token|authorization|password|passwd|private[_-]?key|access[_-]?key)\s*["\']?\s*[:=]\s*["\']?([^"\'&\s,}]+)/i',
		'/(bearer\s+)[a-z0-9._\-]{12,}/i',
		'/sk[-_][a-z0-9]{8,}/i',
		'/xox[baprs]-[a-z0-9-]{10,}/i',
		'/gh[pousr]_[a-z0-9]{20,}/i',
		'/AIza[a-z0-9_-]{20,}/i',
		// Phase 8 — notification secrets: Telegram token lives in the API URL;
		// Slack incoming-webhook URL's trailing segment is the secret (§23).
		'/(https:\/\/api\.telegram\.org\/bot)[0-9]+:[a-z0-9_-]+/i',
		'/(https:\/\/hooks\.slack\.com\/services\/[a-z0-9]+\/[a-z0-9]+\/)[a-z0-9]+/i',
		'/(x-nd-signature\s*[:=]\s*)[^\s,]+/i',
	);

	/** @var string[] */
	private static $contextKeyPatterns = array(
		'api_key', 'apikey', 'api-key', 'secret', 'token', 'authorization', 'password', 'passwd',
		'access_key', 'private_key', 'client_secret', 'bearer',
	);

	/**
	 * Redact a plain string.
	 */
	public static function redact( string $value ): string {
		foreach ( self::$patterns as $pattern ) {
			$value = preg_replace( $pattern, '$1[REDACTED]', $value );
		}
		return $value;
	}

	/**
	 * Deep-redact a context array (keys + string values).
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	public static function redactValue( $value, string $key = '' ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				$out[ $k ] = self::redactValue( $v, (string) $k );
			}
			return $out;
		}
		if ( is_string( $value ) ) {
			if ( '' !== $key && in_array( self::normalizeKey( $key ), self::$contextKeyPatterns, true ) ) {
				return '[REDACTED]';
			}
			return self::redact( $value );
		}
		return $value;
	}

	/**
	 * Whether a context key looks like a secret carrier.
	 */
	public static function isSensitiveKey( string $key ): bool {
		return in_array( self::normalizeKey( $key ), self::$contextKeyPatterns, true );
	}

	private static function normalizeKey( string $key ): string {
		$key = strtolower( trim( $key ) );
		return str_replace( array( '-', '.' ), '_', $key );
	}
}
