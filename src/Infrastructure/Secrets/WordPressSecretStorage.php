<?php
/**
 * Secrets in a dedicated option row — ENCRYPTED AT REST (§23).
 *
 * Values are sealed with libsodium (XSalsa20-Poly1305) when available, otherwise
 * AES-256-GCM via OpenSSL. The key is derived from the site's AUTH_KEY /
 * SECURE_AUTH_KEY salts, so a leaked database dump alone does not expose keys.
 * Legacy plaintext values (pre-1.0.2) are read transparently and re-sealed on
 * the next write. Values are never echoed to HTML or logs.
 *
 * @package NewsDesk\AI\Infrastructure\Secrets
 */

namespace NewsDesk\AI\Infrastructure\Secrets;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Contracts\SecretStorageInterface;

final class WordPressSecretStorage implements SecretStorageInterface {

	public const OPTION = 'newsdesk_secrets';

	private const PREFIX_SODIUM  = 'enc:s1:';
	private const PREFIX_OPENSSL = 'enc:o1:';

	public function get( string $key ): ?string {
		$all = get_option( self::OPTION, array() );
		if ( ! is_array( $all ) ) {
			return null;
		}
		$value = $all[ $key ] ?? null;
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}
		$plain = $this->unseal( $value );
		return ( null !== $plain && '' !== $plain ) ? $plain : null;
	}

	public function set( string $key, string $value ): bool {
		$all = (array) get_option( self::OPTION, array() );
		$all = $this->resealLegacy( $all );
		$all[ $key ] = $this->seal( $value );
		return update_option( self::OPTION, $all, false );
	}

	public function delete( string $key ): bool {
		$all = (array) get_option( self::OPTION, array() );
		if ( ! isset( $all[ $key ] ) ) {
			return true;
		}
		unset( $all[ $key ] );
		return update_option( self::OPTION, $all, false );
	}

	public function has( string $key ): bool {
		return null !== $this->get( $key );
	}

	public function name(): string {
		return 'wordpress';
	}

	/* ------------------------------------------------------------------ */
	/* Crypto                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Whether an encryption backend is available on this host.
	 */
	public static function isEncryptionAvailable(): bool {
		return function_exists( 'sodium_crypto_secretbox' )
			|| ( function_exists( 'openssl_encrypt' ) && in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true ) );
	}

	private function seal( string $plain ): string {
		$key = $this->deriveKey();
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$box   = sodium_crypto_secretbox( $plain, $nonce, $key );
			return self::PREFIX_SODIUM . base64_encode( $nonce . $box ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		}
		if ( function_exists( 'openssl_encrypt' ) && in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true ) ) {
			$iv  = random_bytes( 12 );
			$tag = '';
			$ct  = openssl_encrypt( $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
			if ( false !== $ct ) {
				return self::PREFIX_OPENSSL . base64_encode( $iv . $tag . $ct ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			}
		}
		// No crypto backend: store as-is (same behaviour as <=1.0.1). Admin is warned in Settings.
		return $plain;
	}

	private function unseal( string $stored ): ?string {
		if ( 0 === strpos( $stored, self::PREFIX_SODIUM ) ) {
			if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
				return null;
			}
			$raw = base64_decode( substr( $stored, strlen( self::PREFIX_SODIUM ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				return null;
			}
			$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$box   = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$plain = sodium_crypto_secretbox_open( $box, $nonce, $this->deriveKey() );
			return false === $plain ? null : $plain;
		}
		if ( 0 === strpos( $stored, self::PREFIX_OPENSSL ) ) {
			if ( ! function_exists( 'openssl_decrypt' ) ) {
				return null;
			}
			$raw = base64_decode( substr( $stored, strlen( self::PREFIX_OPENSSL ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			if ( false === $raw || strlen( $raw ) <= 28 ) {
				return null;
			}
			$iv    = substr( $raw, 0, 12 );
			$tag   = substr( $raw, 12, 16 );
			$ct    = substr( $raw, 28 );
			$plain = openssl_decrypt( $ct, 'aes-256-gcm', $this->deriveKey(), OPENSSL_RAW_DATA, $iv, $tag );
			return false === $plain ? null : $plain;
		}
		// Legacy plaintext (pre-1.0.2).
		return $stored;
	}

	/**
	 * Re-encrypt any legacy plaintext entries in-place (called on every write).
	 *
	 * @param array<string,mixed> $all
	 * @return array<string,mixed>
	 */
	private function resealLegacy( array $all ): array {
		foreach ( $all as $k => $v ) {
			if ( is_string( $v ) && '' !== $v && ! $this->isSealed( $v ) ) {
				$all[ $k ] = $this->seal( $v );
			}
		}
		return $all;
	}

	private function isSealed( string $v ): bool {
		return 0 === strpos( $v, self::PREFIX_SODIUM ) || 0 === strpos( $v, self::PREFIX_OPENSSL );
	}

	/**
	 * 32-byte key derived from WordPress salts (never stored anywhere).
	 */
	private function deriveKey(): string {
		$material = '';
		foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY' ) as $c ) {
			$material .= defined( $c ) ? (string) constant( $c ) : '';
		}
		if ( '' === $material ) {
			// Extremely unusual (salts missing from wp-config); fall back to a stable per-site value.
			$material = (string) get_option( 'siteurl', 'newsdesk' );
		}
		return hash_hmac( 'sha256', 'newsdesk-ai/secrets/v1', $material, true );
	}
}
