<?php
/**
 * Versioned migrations (applied AFTER dbDelta — prefix indexes etc.).
 *
 * Hardened for real-WP installs (§77):
 *  - statements are portability-normalized (no `ALTER … AFTER` — SQLite-safe);
 *  - existing columns/indexes are skipped via SHOW guards (idempotent on both
 *    MySQL and the official SQLite drop-in — no more "duplicate column" noise);
 *  - a failing statement is NEVER silent: it is recorded in $lastFailures and
 *    the version is NOT reported as applied (no fake success, §76).
 *
 * @package NewsDesk\AI\Infrastructure\Database
 */

namespace NewsDesk\AI\Infrastructure\Database;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Contracts\WpDbInterface;

final class Migrations {

	/** @var array<string, string[]> version => statements that failed (last run). */
	public static $lastFailures = array();

	/**
	 * Version => SQL statements.
	 *
	 * @var array<string, string[]>
	 */
	private const SET = array(
		// No migrations yet: 1.0.0 is the baseline schema, created in full by
		// Schema.php. Add an entry here the moment a shipped column changes,
		// and bump NEWSDESK_DB_VERSION to match — a test enforces the pair.
	);

	/**
	 * Apply pending migrations.
	 *
	 * @return string[] Versions actually applied (all statements OK). Versions
	 *                  with any failure are NOT reported as applied — they are
	 *                  retried on the next activation and listed in $lastFailures.
	 */
	/**
	 * All known migrations, version => statements.
	 *
	 * Exposed so the health check and the test suite can verify that
	 * NEWSDESK_DB_VERSION matches the highest registered migration —
	 * a mismatch silently skips an upgrade step on live sites.
	 *
	 * @return array<string, string[]>
	 */
	public static function all(): array {
		return self::SET;
	}

	/** Highest registered migration version. */
	public static function latestVersion(): string {
		$versions = array_keys( self::SET );
		usort( $versions, 'version_compare' );
		$last = end( $versions );
		return false === $last ? '1.0.0' : (string) $last;
	}

	public static function applyPending( WpDbInterface $db, TableNames $tables, string $currentVersion ): array {
		$applied        = array();
		self::$lastFailures = array();
		$set            = self::SET;
		// SET is declared in authoring order (1.0.1, 1.2.0, 1.3.0, 1.1.0…) which
		// is NOT version order — a fresh install from '0' would silently skip
		// earlier versions listed later. Sort by semver before iterating (§77).
		uksort( $set, static function ( $a, $b ) {
			return version_compare( $a, $b );
		} );
		foreach ( $set as $version => $statements ) {
			if ( 1 !== version_compare( $version, $currentVersion ) ) {
				continue;
			}
			$failed = array();
			foreach ( $statements as $statement ) {
				$sql = str_replace( '{PREFIX}', self::prefixOf( $tables ), self::portable( $statement ) );
				if ( self::isAlreadyApplied( $db, $sql ) ) {
					continue; // idempotent — column/index already there
				}
				if ( false === $db->query( $sql ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$failed[] = $statement;
				}
			}
			if ( $failed ) {
				self::$lastFailures[ $version ] = $failed;
			} else {
				$applied[] = $version;
			}
			$currentVersion = $version;
		}
		return $applied;
	}

	/**
	 * Column order is cosmetic — `ALTER TABLE … ADD COLUMN … AFTER x` is MySQL
	 * syntax that the official SQLite drop-in cannot translate. Plain ADD
	 * COLUMN is correct on both engines, so the AFTER clause is dropped here.
	 */
	private static function portable( string $sql ): string {
		return (string) preg_replace( '/\s+AFTER\s+[`\w]+$/i', '', $sql );
	}

	/**
	 * Skip already-existing columns/indexes (duplicate-column/index errors were
	 * the main source of noisy, non-fatal install errors on re-activation).
	 */
	private static function isAlreadyApplied( WpDbInterface $db, string $sql ): bool {
		if ( preg_match( '/^ALTER\s+TABLE\s+(\S+)\s+ADD\s+COLUMN\s+[`"]?(\w+)[`"]?/i', $sql, $m ) ) {
			return self::hasColumn( $db, $m[1], $m[2] );
		}
		if ( preg_match( '/^ALTER\s+TABLE\s+(\S+)\s+ADD\s+(?:UNIQUE\s+)?KEY\s+[`"]?(\w+)[`"]?/i', $sql, $m ) ) {
			return self::hasIndex( $db, $m[1], $m[2] );
		}
		return false;
	}

	private static function hasColumn( WpDbInterface $db, string $table, string $column ): bool {
		try {
			$rows = $db->getResults( "SHOW COLUMNS FROM {$table} LIKE '" . addslashes( $column ) . "'" ); // phpcs:ignore
		} catch ( \Throwable $e ) {
			return false; // guard unavailable → let the statement decide
		}
		return ! empty( $rows );
	}

	private static function hasIndex( WpDbInterface $db, string $table, string $index ): bool {
		try {
			$rows = $db->getResults( "SHOW INDEX FROM {$table}" ); // phpcs:ignore
		} catch ( \Throwable $e ) {
			return false;
		}
		foreach ( $rows as $row ) {
			$key = is_array( $row ) ? ( $row['Key_name'] ?? '' ) : ( $row->Key_name ?? '' );
			if ( $index === (string) $key ) {
				return true;
			}
		}
		return false;
	}

	private static function prefixOf( TableNames $tables ): string {
		$name = $tables->locks();
		return substr( $name, 0, -strlen( 'nd_locks' ) );
	}
}
