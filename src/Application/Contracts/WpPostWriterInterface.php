<?php
/**
 * WordPress post creation boundary. §73: AUTO PUBLISH is OFF — no writer may
 * produce a non-draft post, and no writer may alter a LIVE post's content.
 *
 * v2.0 (A-8): the cannibalization engine can decide UPDATE / REWRITE / MERGE /
 * CORRECT / REPLACE, all of which target an article that already exists. The
 * contract therefore grew a second write path — but acting on those decisions
 * must NOT silently rewrite something the public is reading. So an update is
 * staged as a *revision draft*: a separate draft post that records which live
 * post it targets and why. An editor approves the swap by hand.
 *
 * @package NewsDesk\AI\Application\Contracts
 */

namespace NewsDesk\AI\Application\Contracts;

defined( 'ABSPATH' ) || exit;

interface WpPostWriterInterface {

	/**
	 * Create a DRAFT post with the given fields + post meta.
	 *
	 * @param array<string, mixed> $post post_title/post_name/post_content/post_excerpt/…
	 * @param array<string, mixed> $meta
	 * @return int post ID (0 on failure)
	 */
	public function createDraft( array $post, array $meta ): int;

	/**
	 * Stage a change to an EXISTING post as a draft awaiting approval.
	 *
	 * Deliberately does not touch $targetPostId. The returned post is a normal
	 * draft carrying `_newsdesk_revises_post` (the target) and `_newsdesk_revision_kind`
	 * (UPDATE/REWRITE/MERGE/CORRECT/REPLACE) so the admin can diff and apply it.
	 *
	 * @param int                  $targetPostId the live post this revises
	 * @param string               $kind         a CannibalizationEngine decision
	 * @param array<string, mixed> $post         draft fields
	 * @param array<string, mixed> $meta         post meta
	 * @return int revision draft post ID (0 on failure)
	 */
	public function createRevisionDraft( int $targetPostId, string $kind, array $post, array $meta ): int;

	/**
	 * Append a public correction notice to a live post.
	 *
	 * This is the ONE mutation of live content the spec allows, because a
	 * published falsehood is worse than an edit: §"correction log". It only
	 * appends a marked-up notice and never rewrites the body.
	 *
	 * @return bool true when the notice was appended
	 */
	public function appendCorrectionNotice( int $postId, string $notice ): bool;

	/** Whether a post exists and is currently published. */
	public function isPublished( int $postId ): bool;
}
