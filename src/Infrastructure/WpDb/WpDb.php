<?php
/**
 * $wpdb adapter (WpDbInterface). Single SQL choke point.
 *
 * @package NewsDesk\AI\Infrastructure\WpDb
 */

namespace NewsDesk\AI\Infrastructure\WpDb;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Contracts\WpDbInterface;

final class WpDb implements WpDbInterface {

	/** @var \wpdb */
	private $wpdb;

	/**
	 * @param object $wpdb The global $wpdb (type \wpdb when inside WP).
	 */
	public function __construct( $wpdb ) {
		$this->wpdb = $wpdb;
	}

	public function prepare( string $query, ...$args ): string {
		$prepared = call_user_func_array( array( $this->wpdb, 'prepare' ), array_merge( array( $query ), $args ) );
		return (string) $prepared;
	}

	public function query( string $sql ) {
		return $this->wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function getResults( string $sql, string $output = OBJECT ): array {
		// Repositories contract is associative arrays (mirrored by FakeWpDb).
		// On a REAL WordPress the default OBJECT output yields stdClass rows,
		// which broke every `$row['col']` accessor at runtime (found in the
		// staging drill — ResearchRepository::countsByStatus). Always normalize
		// to ARRAY_A: one contract, both worlds.
		$rows = (array) $this->wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out  = array();
		foreach ( $rows as $row ) {
			$out[] = is_array( $row ) ? $row : (array) $row;
		}
		return $out;
	}

	public function getRow( string $sql, string $output = OBJECT, $y = 0 ) {
		// Repositories contract assoc arrays; wpdb returns stdClass on OBJECT output.
		$row = $this->wpdb->get_row( $sql, $output, $y ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return null === $row ? null : (array) $row;
	}

	public function getVar( string $sql, $x = 0, $y = 0 ) {
		return $this->wpdb->get_var( $sql, $x, $y ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function insert( string $table, array $data, array $format = array() ) {
		$result = $this->wpdb->insert( $table, $data, $format ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return false === $result ? false : $result;
	}

	public function update( string $table, array $data, array $where, array $format = array(), array $where_format = array() ) {
		$result = $this->wpdb->update( $table, $data, $where, $format, $where_format ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return false === $result ? false : $result;
	}

	public function delete( string $table, array $where, array $where_format = array() ) {
		$result = $this->wpdb->delete( $table, $where, $where_format ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return false === $result ? false : $result;
	}

	public function insertId(): int {
		return (int) $this->wpdb->insert_id;
	}

	/** @var array<string, true> positive-only existence cache (per process). */
	private static $knownTables = array();

	public function tableExists( string $table ): bool {
		if ( isset( self::$knownTables[ $table ] ) ) {
			return true;
		}
		$found = $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( null !== $found && '' !== (string) $found ) {
			self::$knownTables[ $table ] = true;
			return true;
		}
		// Never cache a negative: the table may be created later in the same
		// request (e.g. first activation creates the schema after bootstrap).
		return false;
	}

	public function prefix(): string {
		return (string) $this->wpdb->prefix;
	}

	public function charsetCollate(): string {
		return (string) $this->wpdb->get_charset_collate();
	}
}
