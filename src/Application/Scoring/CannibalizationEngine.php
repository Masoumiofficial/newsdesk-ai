<?php
/**
 * Cannibalization decision (§ spec: NEW / UPDATE / REWRITE / MERGE / CORRECT /
 * REPLACE / NO ARTICLE).
 *
 * v1.6.0 shipped the storage for this and none of the logic: the columns
 * `stories.editorial_decision` and `stories.existing_article_id` existed and
 * round-tripped through the entity, but nothing ever computed a value, so
 * every story was implicitly treated as brand new. Publishing a second post
 * about a story the site already covered is exactly the keyword
 * cannibalization the spec asks us to prevent.
 *
 * This engine is deterministic and does no I/O: it receives the candidate
 * story plus the site's existing posts and returns a decision with a reason.
 * No AI is involved — the spec limits AI to summarization/claims/
 * classification/research/angle, and "should we republish?" is an editorial
 * rule, not a generation task.
 *
 * DECISION MEANINGS
 *   NEW        no meaningful overlap → write a new article.
 *   UPDATE     we covered it, the story moved on → add to the existing post.
 *   REWRITE    we covered it poorly/thinly and the story is now major.
 *   MERGE      several of our posts cover the same thing → consolidate.
 *   CORRECT    new evidence contradicts what we published → correction needed.
 *   REPLACE    our post is obsolete and the story is major → supersede it.
 *   NO_ARTICLE overlap is total and nothing changed → publish nothing.
 *
 * Every decision except NEW/NO_ARTICLE carries an existing_article_id, and
 * NONE of them performs the action: Phase "drafts only" still applies, an
 * admin approves everything (§73).
 *
 * @package NewsDesk\AI\Application\Scoring
 */

namespace NewsDesk\AI\Application\Scoring;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Domain\Entity\Story;

final class CannibalizationEngine {

	public const DECISION_NEW        = 'NEW';
	public const DECISION_UPDATE     = 'UPDATE';
	public const DECISION_REWRITE    = 'REWRITE';
	public const DECISION_MERGE      = 'MERGE';
	public const DECISION_CORRECT    = 'CORRECT';
	public const DECISION_REPLACE    = 'REPLACE';
	public const DECISION_NO_ARTICLE = 'NO_ARTICLE';

	/** Title-overlap ratio at/above which two titles are "the same story". */
	public const OVERLAP_STRONG = 0.70;
	/** Overlap ratio at/above which they are "related". */
	public const OVERLAP_RELATED = 0.45;
	/** Below this the story is simply new. */
	public const OVERLAP_NONE = 0.30;

	/** A post older than this (days) is a candidate for REPLACE, not UPDATE. */
	public const STALE_DAYS = 180;
	/** Composite score at/above which a story counts as "major". */
	public const MAJOR_SCORE = 80.0;

	/** @var StoryClusterer Reused purely for its title tokenizer. */
	private $clusterer;

	public function __construct( StoryClusterer $clusterer ) {
		$this->clusterer = $clusterer;
	}

	/**
	 * Decide what to do with $story given what the site already published.
	 *
	 * @param Story $story  Candidate story.
	 * @param array $posts  Existing posts: [{post_id, title, url, modified?}, …].
	 * @param array $opts   contradictions (int), now (DateTimeImmutable).
	 *
	 * @return array{decision:string, existing_article_id:int, reason:string, overlap:float, matches:array<int,array>}
	 */
	public function decide( Story $story, array $posts, array $opts = array() ): array {
		$now            = $opts['now'] ?? new \DateTimeImmutable( 'now' );
		$contradictions = (int) ( $opts['contradictions'] ?? $story->contradictionCount );

		$storyTokens = $this->tokensOf( $story->canonicalTitle );
		if ( empty( $storyTokens ) ) {
			// Nothing to compare — treat as new rather than guessing.
			return $this->result( self::DECISION_NEW, 0, 'story has no comparable title', 0.0, array() );
		}

		// Rank every existing post by title overlap.
		$matches = array();
		foreach ( $posts as $post ) {
			$postId = (int) ( $post['post_id'] ?? 0 );
			$title  = (string) ( $post['title'] ?? '' );
			if ( $postId <= 0 || '' === $title ) {
				continue;
			}
			$overlap = $this->overlap( $storyTokens, $this->tokensOf( $title ) );
			if ( $overlap < self::OVERLAP_NONE ) {
				continue;
			}
			$matches[] = array(
				'post_id'  => $postId,
				'title'    => $title,
				'overlap'  => $overlap,
				'age_days' => $this->ageDays( $post, $now ),
			);
		}

		if ( empty( $matches ) ) {
			return $this->result( self::DECISION_NEW, 0, 'no existing article covers this story', 0.0, array() );
		}

		usort(
			$matches,
			static function ( array $a, array $b ): int {
				if ( abs( $a['overlap'] - $b['overlap'] ) > 0.0001 ) {
					return $b['overlap'] <=> $a['overlap'];
				}
				return $a['post_id'] <=> $b['post_id'];
			}
		);

		$best    = $matches[0];
		$overlap = (float) $best['overlap'];
		$postId  = (int) $best['post_id'];
		$ageDays = $best['age_days'];
		$major   = $story->importanceScore >= self::MAJOR_SCORE;

		// --- CORRECT: evidence contradicts what we already published. This
		// outranks everything else; a wrong article is worse than a missing one.
		if ( $contradictions > 0 && $overlap >= self::OVERLAP_RELATED ) {
			return $this->result(
				self::DECISION_CORRECT,
				$postId,
				sprintf( '%d contradiction(s) found against published article #%d', $contradictions, $postId ),
				$overlap,
				$matches
			);
		}

		// --- MERGE: we have fragmented coverage of one story across posts.
		$strong = 0;
		foreach ( $matches as $m ) {
			if ( $m['overlap'] >= self::OVERLAP_STRONG ) {
				$strong++;
			}
		}
		if ( $strong >= 2 ) {
			return $this->result(
				self::DECISION_MERGE,
				$postId,
				sprintf( '%d existing articles cover this story and should be consolidated', $strong ),
				$overlap,
				$matches
			);
		}

		if ( $overlap >= self::OVERLAP_STRONG ) {
			// --- REPLACE: the existing coverage is stale and the story is major.
			if ( $major && null !== $ageDays && $ageDays >= self::STALE_DAYS ) {
				return $this->result(
					self::DECISION_REPLACE,
					$postId,
					sprintf( 'article #%d is %d days old and the story is major (%s/100)', $postId, (int) $ageDays, $story->importanceScore ),
					$overlap,
					$matches
				);
			}
			// --- NO_ARTICLE: same story, nothing new to say.
			if ( ! $major && $story->itemCount <= 1 ) {
				return $this->result(
					self::DECISION_NO_ARTICLE,
					$postId,
					sprintf( 'article #%d already covers this and no new development was found', $postId ),
					$overlap,
					$matches
				);
			}
			// --- UPDATE: we covered it, there is genuinely more to add.
			return $this->result(
				self::DECISION_UPDATE,
				$postId,
				sprintf( 'article #%d covers this story; %d new source item(s) to fold in', $postId, $story->itemCount ),
				$overlap,
				$matches
			);
		}

		// --- REWRITE: related but not the same, and this is a big story.
		if ( $major ) {
			return $this->result(
				self::DECISION_REWRITE,
				$postId,
				sprintf( 'related article #%d exists but the story is major (%s/100) and warrants its own piece', $postId, $story->importanceScore ),
				$overlap,
				$matches
			);
		}

		// Related but minor: a new piece would compete with our own post.
		return $this->result(
			self::DECISION_UPDATE,
			$postId,
			sprintf( 'related article #%d exists; extend it instead of competing with it', $postId ),
			$overlap,
			$matches
		);
	}

	/** Decisions that point at an existing post. */
	public static function requiresExistingArticle( string $decision ): bool {
		return in_array(
			$decision,
			array( self::DECISION_UPDATE, self::DECISION_REWRITE, self::DECISION_MERGE, self::DECISION_CORRECT, self::DECISION_REPLACE ),
			true
		);
	}

	/** Decisions that must NOT result in a new draft being created. */
	public static function blocksDraft( string $decision ): bool {
		return self::DECISION_NO_ARTICLE === $decision;
	}

	/** Every decision this engine can return. */
	public static function all(): array {
		return array(
			self::DECISION_NEW,
			self::DECISION_UPDATE,
			self::DECISION_REWRITE,
			self::DECISION_MERGE,
			self::DECISION_CORRECT,
			self::DECISION_REPLACE,
			self::DECISION_NO_ARTICLE,
		);
	}

	/* ------------------------------------------------------------ helpers */

	/** @return string[] */
	private function tokensOf( string $title ): array {
		return array_values( array_unique( $this->clusterer->titleTokens( $title ) ) );
	}

	/**
	 * Jaccard-style overlap weighted toward the story: what fraction of the
	 * story's distinctive words does the post title also use?
	 *
	 * @param string[] $a
	 * @param string[] $b
	 */
	private function overlap( array $a, array $b ): float {
		if ( empty( $a ) || empty( $b ) ) {
			return 0.0;
		}
		$shared = count( array_intersect( $a, $b ) );
		if ( 0 === $shared ) {
			return 0.0;
		}
		// Divide by the smaller set so a long post title does not dilute a
		// genuine match, but cap at 1.0.
		$denominator = max( 1, min( count( $a ), count( $b ) ) );
		return min( 1.0, $shared / $denominator );
	}

	/** @return float|null */
	private function ageDays( array $post, \DateTimeImmutable $now ) {
		$modified = $post['modified'] ?? ( $post['date'] ?? null );
		if ( $modified instanceof \DateTimeImmutable ) {
			return max( 0.0, ( $now->getTimestamp() - $modified->getTimestamp() ) / 86400 );
		}
		if ( is_string( $modified ) && '' !== $modified ) {
			try {
				$dt = new \DateTimeImmutable( $modified );
				return max( 0.0, ( $now->getTimestamp() - $dt->getTimestamp() ) / 86400 );
			} catch ( \Exception $e ) {
				return null;
			}
		}
		return null;
	}

	/**
	 * @param array<int,array> $matches
	 * @return array{decision:string, existing_article_id:int, reason:string, overlap:float, matches:array<int,array>}
	 */
	private function result( string $decision, int $postId, string $reason, float $overlap, array $matches ): array {
		return array(
			'decision'            => $decision,
			'existing_article_id' => $postId,
			'reason'              => $reason,
			'overlap'             => round( $overlap, 4 ),
			'matches'             => $matches,
		);
	}
}
