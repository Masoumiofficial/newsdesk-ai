<?php
/**
 * Prompt version storage (§24).
 *
 * @package NewsDesk\AI\Infrastructure\Repository
 */

namespace NewsDesk\AI\Infrastructure\Repository;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Contracts\PromptRepositoryInterface;
use NewsDesk\AI\Application\Contracts\WpDbInterface;
use NewsDesk\AI\Domain\Entity\PromptVersion;
use NewsDesk\AI\Infrastructure\Database\TableNames;

final class PromptRepository implements PromptRepositoryInterface {

	/** @var WpDbInterface */
	private $db;
	/** @var TableNames */
	private $tables;

	public function __construct( WpDbInterface $db, TableNames $tables ) {
		$this->db     = $db;
		$this->tables = $tables;
	}

	public function insert( PromptVersion $prompt ): int {
		$row = $prompt->toDbRow();
		unset( $row['id'] );
		$result = $this->db->insert( $this->tables->promptVersions(), $row, array( '%s','%d','%s','%s','%s','%s','%s','%d','%s','%s' ) );
		if ( false === $result ) {
			return 0;
		}
		$prompt->id = $this->db->insertId();
		return $prompt->id;
	}

	public function findActive( string $promptId, string $language ): ?PromptVersion {
		$row = $this->db->getRow(
			$this->db->prepare(
				'SELECT * FROM ' . $this->tables->promptVersions() . ' WHERE prompt_id = %s AND language = %s AND active = 1 ORDER BY version DESC LIMIT 1',
				$promptId,
				$language
			)
		);
		return ( is_array( $row ) && $row ) ? PromptVersion::fromDbRow( $row ) : null;
	}

	public function listActive( string $promptId ): array {
		$rows = $this->db->getResults(
			$this->db->prepare(
				'SELECT * FROM ' . $this->tables->promptVersions() . ' WHERE prompt_id = %s AND active = 1 ORDER BY language ASC, version DESC',
				$promptId
			)
		);
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = PromptVersion::fromDbRow( (array) $row );
		}
		return $out;
	}
}
