<?php
/**
 * IP allow/deny validation (§52): private/link-local/metadata ranges + DNS resolution check.
 *
 * @package NewsDesk\AI\Infrastructure\Http
 */

namespace NewsDesk\AI\Infrastructure\Http;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Support\Http\UrlValidationException;

final class IpValidator {

	/**
	 * Assert every resolved IP of a host is public. Throws UrlValidationException otherwise.
	 */
	public static function assertHostSafe( string $host ): void {
		if ( self::isIpLiteral( $host ) ) {
			if ( self::isBlockedIp( $host ) ) {
				throw new UrlValidationException( $host, 'IP_BLOCKED' );
			}
			return;
		}
		// Numeric/hex obfuscated IPs (e.g. 2130706433, 0x7f000001) are never legitimate hosts.
		if ( self::looksLikeObfuscatedIp( $host ) ) {
			throw new UrlValidationException( $host, 'IP_OBFUSCATED' );
		}
		$ips = self::resolve( $host );
		if ( empty( $ips ) ) {
			throw new UrlValidationException( $host, 'DNS_UNRESOLVABLE' );
		}
		foreach ( $ips as $ip ) {
			if ( self::isBlockedIp( $ip ) ) {
				throw new UrlValidationException( $host . ' -> ' . $ip, 'DNS_PRIVATE_IP' );
			}
		}
	}

	/**
	 * Is an IP literal in a blocked range?
	 */
	public static function isBlockedIp( string $ip ): bool {
		$ip = trim( $ip );
		if ( false !== strpos( $ip, ':' ) ) {
			return self::isBlockedIpv6( $ip );
		}
		return self::isBlockedIpv4( $ip );
	}

	/**
	 * DNS resolution → list of IPv4 + IPv6 addresses (empty = unresolvable).
	 *
	 * @return string[]
	 */
	public static function resolve( string $host ): array {
		$ips = array();
		$v4  = @gethostbynamel( $host ); // phpcs:ignore
		if ( is_array( $v4 ) ) {
			$ips = array_merge( $ips, $v4 );
		}
		if ( function_exists( 'dns_get_record' ) ) {
			$aaaa = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore
			if ( is_array( $aaaa ) ) {
				foreach ( $aaaa as $record ) {
					if ( isset( $record['ipv6'] ) ) {
						$ips[] = $record['ipv6'];
					}
				}
			}
		}
		return array_values( array_unique( $ips ) );
	}

	public static function isIpLiteral( string $host ): bool {
		return false !== filter_var( $host, FILTER_VALIDATE_IP );
	}

	private static function looksLikeObfuscatedIp( string $host ): bool {
		return (bool) preg_match( '/^(\d{1,12}|0x[0-9a-f]{1,10})$/i', $host );
	}

	private static function isBlockedIpv4( string $ip ): bool {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return true; // not a valid IPv4 literal → conservative block
		}
		$o = array_map( 'intval', explode( '.', $ip ) );
		$a = $o[0];
		$b = $o[1];
		$c = $o[2];

		if ( 0 === $a ) { return true; }                                    // 0.0.0.0/8
		if ( 10 === $a ) { return true; }                                   // 10/8
		if ( 100 === $a && $b >= 64 && $b <= 127 ) { return true; }         // 100.64/10 (CGNAT)
		if ( 127 === $a ) { return true; }                                  // 127/8
		if ( 169 === $a && 254 === $b ) { return true; }                    // 169.254/16 (link-local incl. metadata)
		if ( 172 === $a && $b >= 16 && $b <= 31 ) { return true; }          // 172.16/12
		if ( 192 === $a && 168 === $b ) { return true; }                    // 192.168/16
		if ( 192 === $a && 0 === $b && 0 === $c ) { return true; }          // 192.0.0/24
		if ( 192 === $a && 0 === $b && 2 === $c ) { return true; }          // 192.0.2/24 (TEST-NET)
		if ( 192 === $a && 88 === $b && 99 === $c ) { return true; }        // 192.88.99/24 (6to4 relay, deprecated)
		if ( 198 === $a && ( 18 === $b || 19 === $b ) ) { return true; }    // 198.18/15 (benchmark)
		if ( 198 === $a && 51 === $b && 100 === $c ) { return true; }       // 198.51.100/24 (TEST-NET)
		if ( 203 === $a && 0 === $b && 113 === $c ) { return true; }        // 203.0.113/24 (TEST-NET)
		if ( $a >= 224 && $a <= 239 ) { return true; }                      // 224/4 multicast
		if ( $a >= 240 ) { return true; }                                   // 240/4 reserved + broadcast
		return false;
	}

	private static function isBlockedIpv6( string $ip ): bool {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return true; // not a valid IPv6 literal → conservative block
		}
		$low = strtolower( $ip );
		$low = (string) strtok( $low, '%' ); // strip scope id

		// IPv4-mapped (::ffff:a.b.c.d)
		if ( 0 === strpos( $low, '::ffff:' ) ) {
			return self::isBlockedIp( substr( $low, 7 ) );
		}
		if ( in_array( $low, array( '::', '::1' ), true ) ) {
			return true;
		}
		$parts = array_values( array_filter( explode( ':', $low ), 'strlen' ) );
		$first = hexdec( $parts[0] ?? '0' );
		if ( ( $first & 0xfe00 ) === 0xfc00 ) { return true; } // fc00::/7 ULA
		if ( ( $first & 0xffc0 ) === 0xfe80 ) { return true; } // fe80::/10 link-local
		if ( ( $first & 0xffc0 ) === 0xfec0 ) { return true; } // fec0::/10 deprecated site-local
		if ( ( $first & 0xff00 ) === 0xff00 ) { return true; } // ff00::/8 multicast
		if ( 0 === strpos( $low, '2001:db8:' ) ) { return true; } // documentation
		return false;
	}
}
