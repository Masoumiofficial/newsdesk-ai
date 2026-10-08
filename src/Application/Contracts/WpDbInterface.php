<?php
/**
 * Thin, testable abstraction over $wpdb (single SQL choke point).
 *
 * @package NewsDesk\AI\Application\Contracts
 */

namespace NewsDesk\AI\Application\Contracts;

defined( 'ABSPATH' ) || exit;

interface WpDbInterface {

	/**
	 * Prepare a query with placeholders (%s/%d/%f).
	 */
	public function prepare( string $query, ...$args ): string;

	/**
	 * Run a raw query. Returns affected/int rows or false on error.
	 *
	 * @return int|false
	 */
	public function query( string $sql );

	/**
	 * @return array<int, object|array>
	 */
	public function getResults( string $sql, string $output = OBJECT ): array;

	/**
	 * @return object|array|null
	 */
	public function getRow( string $sql, string $output = OBJECT, $y = 0 );

	/**
	 * @return string|null
	 */
	public function getVar( string $sql, $x = 0, $y = 0 );

	/**
	 * @return int|false
	 */
	public function insert( string $table, array $data, array $format = array() );

	/**
	 * @return int|false
	 */
	public function update( string $table, array $data, array $where, array $format = array(), array $where_format = array() );

	/**
	 * @return int|false
	 */
	public function delete( string $table, array $where, array $where_format = array() );

	/**
	 * True when the table exists in the current database.
	 *
	 * Used pre-activation: bootstrap logging must never hit a missing table
	 * (a $wpdb->insert on a missing table raises a WP database error on the
	 * very first activation — found in the staging drill). Implementations
	 * may cache only *positive* results so a table created later in the same
	 * request becomes visible.
	 */
	public function tableExists( string $table ): bool;

	/**
	 * Last insert id.
	 */
	public function insertId(): int;

	/**
	 * Site table prefix.
	 */
	public function prefix(): string;

	/**
	 * Charset/collate clause for CREATE TABLE.
	 */
	public function charsetCollate(): string;
}
