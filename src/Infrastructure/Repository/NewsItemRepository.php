<?php
/**
 * NewsItem persistence + cheap dedup checks (§9 ladder steps 1–3).
 *
 * @package NewsDesk\AI\Infrastructure\Repository
 */

namespace NewsDesk\AI\Infrastructure\Repository;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Contracts\NewsItemRepositoryInterface;
use NewsDesk\AI\Application\Contracts\WpDbInterface;
use NewsDesk\AI\Domain\Entity\NewsItem;
use NewsDesk\AI\Infrastructure\Database\TableNames;
use NewsDesk\AI\Support\Time;

final class NewsItemRepository implements NewsItemRepositoryInterface {

	/** @var WpDbInterface */
	private $db;
	/** @var TableNames */
	private $tables;

	public function __construct( WpDbInterface $db, TableNames $tables ) {
		$this->db     = $db;
		$this->tables = $tables;
	}

	public function insert( NewsItem $item ): int {
		$row = $item->toDbRow();
		unset( $row['id'] );
		$result = $this->db->insert( $this->tables->newsItems(), $row, self::formats() );
		if ( false === $result ) {
			return 0;
		}
		$item->id = $this->db->insertId();
		return $item->id;
	}

	/**
	 * Resolve the *winning* row this candidate duplicates, if any.
	 *
	 * The ladder is evaluated most-specific first so the reported level is
	 * meaningful (guid > url > hash) rather than whatever MySQL happened to
	 * return from an OR. Returns array{id:int, level:string} or null.
	 *
	 * @return array|null
	 */
	public function findDuplicate( string $guid, string $canonical, string $hash, int $sourceId = 0 ) {
		$table  = $this->tables->newsItems();
		$ladder = array();
		if ( '' !== $guid ) {
			$ladder[] = $sourceId > 0
				? array( 'guid', 'guid = %s AND source_id = %d', array( $guid, $sourceId ) )
				: array( 'guid', 'guid = %s', array( $guid ) );
		}
		if ( '' !== $canonical ) {
			$ladder[] = array( 'url', 'canonical_url = %s', array( $canonical ) );
		}
		if ( '' !== $hash ) {
			$ladder[] = array( 'hash', 'content_hash = %s', array( $hash ) );
		}
		foreach ( $ladder as $rung ) {
			list( $level, $where, $args ) = $rung;
			// Never chain onto another duplicate: always return the winner.
			$sql = $this->db->prepare(
				"SELECT id, duplicate_of_id FROM {$table} WHERE {$where} ORDER BY id ASC LIMIT 1",
				...$args
			);
			$row = $this->db->getRow( $sql, ARRAY_A );
			if ( $row ) {
				$winner = (int) ( $row['duplicate_of_id'] ?? 0 );
				return array(
					'id'    => $winner > 0 ? $winner : (int) $row['id'],
					'level' => $level,
				);
			}
		}
		return null;
	}

	public function exists( string $guid, string $canonical, string $hash, int $sourceId = 0 ): bool {
		$conds = array();
		$args  = array();
		if ( '' !== $guid ) {
			if ( $sourceId > 0 ) {
				$conds[]  = '(guid = %s AND source_id = %d)';
				$args[]   = $guid;
				$args[]   = $sourceId;
			} else {
				$conds[] = 'guid = %s';
				$args[]  = $guid;
			}
		}
		if ( '' !== $canonical ) {
			$conds[] = 'canonical_url = %s';
			$args[]  = $canonical;
		}
		if ( '' !== $hash ) {
			$conds[] = 'content_hash = %s';
			$args[]  = $hash;
		}
		if ( empty( $conds ) ) {
			return false;
		}
		$sql = $this->db->prepare(
			'SELECT id FROM ' . $this->tables->newsItems() . ' WHERE ' . implode( ' OR ', $conds ) . ' LIMIT 1',
			...$args
		);
		return null !== $this->db->getVar( $sql );
	}

	/**
	 * Mark $itemId as a duplicate of $winnerId and stamp the confirmation time.
	 *
	 * This is the write that v1.6.0 never performed: STATUS_DUPLICATE was read
	 * by countDuplicates() but nothing ever set it.
	 */
	public function markDuplicate( int $itemId, int $winnerId, string $level ): bool {
		if ( $itemId <= 0 || $winnerId <= 0 || $itemId === $winnerId ) {
			return false;
		}
		$result = $this->db->update(
			$this->tables->newsItems(),
			array(
				'status'             => NewsItem::STATUS_DUPLICATE,
				'dedup_confirmed_at' => Time::toDb( Time::now() ),
				'duplicate_of_id'    => $winnerId,
				'duplicate_level'    => $level,
			),
			array( 'id' => $itemId ),
			array( '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);
		return false !== $result;
	}

	/**
	 * Refresh last_seen_at on the winner so re-publishing a story keeps the
	 * cluster warm instead of looking stale.
	 */
	public function touchLastSeen( int $itemId ): bool {
		if ( $itemId <= 0 ) {
			return false;
		}
		$result = $this->db->update(
			$this->tables->newsItems(),
			array( 'last_seen_at' => Time::toDb( Time::now() ) ),
			array( 'id' => $itemId ),
			array( '%s' ),
			array( '%d' )
		);
		return false !== $result;
	}

	public function countAll(): int {
		return (int) $this->db->getVar( 'SELECT COUNT(*) FROM ' . $this->tables->newsItems() );
	}

	public function countSince( \DateTimeImmutable $since ): int {
		return (int) $this->db->getVar(
			$this->db->prepare(
				'SELECT COUNT(*) FROM ' . $this->tables->newsItems() . ' WHERE created_at >= %s',
				Time::toDb( $since )
			)
		);
	}

	public function countDuplicates(): int {
		return (int) $this->db->getVar(
			$this->db->prepare(
				'SELECT COUNT(*) FROM ' . $this->tables->newsItems() . ' WHERE status = %s',
				NewsItem::STATUS_DUPLICATE
			)
		);
	}

	public function deleteBySource( int $sourceId ): int {
		$result = $this->db->delete( $this->tables->newsItems(), array( 'source_id' => $sourceId ), array( '%d' ) );
		return false === $result ? 0 : (int) $result;
	}

	public function paginate( array $filters, int $page, int $perPage ): array {
		$where  = array();
		$params = array();
		if ( ! empty( $filters['source_id'] ) ) {
			$where[]  = 'source_id = %d';
			$params[] = (int) $filters['source_id'];
		}
		if ( ! empty( $filters['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = $filters['status'];
		}
		if ( ! empty( $filters['search'] ) ) {
			$where[]  = 'title LIKE %s';
			$params[] = '%' . $filters['search'] . '%';
		}
		if ( ! empty( $filters['language'] ) ) {
			$where[]  = 'language = %s';
			$params[] = $filters['language'];
		}
		// "hide_duplicates" lets the Inbox show only winners while the
		// duplicates remain queryable for the cluster view.
		if ( ! empty( $filters['hide_duplicates'] ) ) {
			$where[]  = 'status <> %s';
			$params[] = NewsItem::STATUS_DUPLICATE;
		}
		if ( ! empty( $filters['duplicate_of_id'] ) ) {
			$where[]  = 'duplicate_of_id = %d';
			$params[] = (int) $filters['duplicate_of_id'];
		}
		$whereSql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';
		$table    = $this->tables->newsItems();

		// prepare() with zero placeholders is a _doing_it_wrong() notice on
		// WP 6.2+, so only prepare when there is something to bind.
		$countSql = "SELECT COUNT(*) FROM {$table} {$whereSql}";
		$total    = (int) $this->db->getVar( $params ? $this->db->prepare( $countSql, ...$params ) : $countSql );
		$offset   = max( 0, ( $page - 1 ) * $perPage );
		$sql      = $this->db->prepare(
			"SELECT * FROM {$table} {$whereSql} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
			...array_merge( $params, array( $perPage, $offset ) )
		);
		return array(
			'items' => $this->mapRows( $this->db->getResults( $sql ) ),
			'total' => $total,
		);
	}

	public function findRecent( int $limit ): array {
		$sql = $this->db->prepare(
			'SELECT * FROM ' . $this->tables->newsItems() . ' ORDER BY created_at DESC, id DESC LIMIT %d',
			$limit
		);
		return $this->mapRows( $this->db->getResults( $sql ) );
	}

	public function findByIds( array $ids ): array {
		$ids = array_values( array_filter( array_map( 'intval', $ids ), static function ( $id ) { return $id > 0; } ) );
		if ( empty( $ids ) ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql = 'SELECT * FROM ' . $this->tables->newsItems() . ' WHERE id IN (' . $placeholders . ') ORDER BY created_at ASC, id ASC';
		return $this->mapRows( $this->db->getResults( $this->db->prepare( $sql, ...$ids ) ) );
	}

	public function findNormalizedSince( \DateTimeImmutable $since ): array {
		$sql = $this->db->prepare(
			'SELECT * FROM ' . $this->tables->newsItems()
			. ' WHERE created_at >= %s AND status IN (%s, %s) ORDER BY created_at ASC, id ASC',
			Time::toDb( $since ),
			NewsItem::STATUS_NEW,
			NewsItem::STATUS_NORMALIZED
		);
		return $this->mapRows( $this->db->getResults( $sql ) );
	}

	/**
	 * @return NewsItem[]
	 */
	private function mapRows( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = NewsItem::fromDbRow( (array) $row );
		}
		return $out;
	}

	/**
	 * @return string[]
	 */
	private static function formats(): array {
		return array(
			'%d', // source_id
			'%s', // guid
			'%s', // canonical_url
			'%s', // content_hash
			'%s', // title
			'%s', // normalized_title
			'%s', // excerpt
			'%s', // content_text
			'%s', // author
			'%s', // categories
			'%s', // language
			'%s', // published_at
			'%s', // fetched_at
			'%s', // created_at
			'%s', // last_seen_at
			'%s', // status
			'%s', // dedup_confirmed_at
			'%d', // duplicate_of_id
			'%s', // duplicate_level
		);
	}
}
