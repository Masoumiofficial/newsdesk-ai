<?php
/**
 * Phase 6 — human review of machine drafts (§36, §73).
 *
 * The editor is the ONLY party that can publish: this service only records
 * the human decision on a content version (approved → 'reviewed' /
 * rejected → 'rejected'), keeps the WP post in 'draft' (§73), updates the
 * story's content_status and writes an audit log row. State guards: a
 * version can only leave 'needs_review' exactly once.
 *
 * @package NewsDesk\AI\Application\Content
 */

namespace NewsDesk\AI\Application\Content;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Contracts\ContentRepositoryInterface;
use NewsDesk\AI\Application\Contracts\StoryRepositoryInterface;
use NewsDesk\AI\Domain\Entity\ContentVersion;
use NewsDesk\AI\Logging\Contracts\LoggerInterface;

final class DraftReviewService {

	/** @var ContentRepositoryInterface */
	private $versions;
	/** @var StoryRepositoryInterface */
	private $stories;
	/** @var LoggerInterface */
	private $logger;

	/** @var \NewsDesk\AI\Application\Contracts\ResearchRepositoryInterface|null */
	private $research;

	public function __construct( ContentRepositoryInterface $versions, StoryRepositoryInterface $stories, LoggerInterface $logger, ?\NewsDesk\AI\Application\Contracts\ResearchRepositoryInterface $research = null ) {
		$this->research = $research;
		$this->versions = $versions;
		$this->stories  = $stories;
		$this->logger   = $logger;
	}

	/**
	 * Review queue: latest needs_review version per story (+ the story row).
	 * @return array<int, array{version: ContentVersion, story: \NewsDesk\AI\Domain\Entity\Story}>
	 */
	public function pending(): array {
		$out = array();
		foreach ( $this->versions->awaitingDecision() as $version ) {
			$story = $this->stories->find( $version->storyId );
			if ( null === $story ) {
				continue;
			}
			$out[] = array( 'version' => $version, 'story' => $story );
		}
		return $out;
	}

	/**
	 * Everything an editor needs to judge one version on a single screen:
	 * the article, the claim map (which evidence backs which section), and
	 * the quality-gate breakdown. Read-only.
	 *
	 * @return array{version: ContentVersion, story: \NewsDesk\AI\Domain\Entity\Story, claims: array<string, \NewsDesk\AI\Domain\Entity\EvidenceClaim>, used_claim_ids: string[], unknown_claim_ids: string[], versions: ContentVersion[]}|null
	 */
	public function preview( int $versionId ): ?array {
		$version = $this->versions->find( $versionId );
		if ( null === $version ) {
			return null;
		}
		$story = $this->stories->find( $version->storyId );
		if ( null === $story ) {
			return null;
		}
		$claims = array();
		if ( null !== $this->research ) {
			foreach ( $this->research->claimsForStory( $story->storyId ) as $c ) {
				$claims[ (string) $c->claimId ] = $c;
			}
		}
		$used = array();
		foreach ( (array) ( $version->content['sections'] ?? array() ) as $s ) {
			foreach ( (array) ( $s['claim_ids'] ?? array() ) as $id ) {
				$used[ (string) $id ] = true;
			}
		}
		foreach ( (array) ( $version->content['faq'] ?? array() ) as $f ) {
			foreach ( (array) ( $f['claim_ids'] ?? array() ) as $id ) {
				$used[ (string) $id ] = true;
			}
		}
		$usedIds = array_keys( $used );
		return array(
			'version'           => $version,
			'story'             => $story,
			'claims'            => $claims,
			'used_claim_ids'    => $usedIds,
			'unknown_claim_ids' => array_values( array_diff( $usedIds, array_keys( $claims ) ) ),
			'versions'          => $this->history( $story->storyId ),
		);
	}

	/** Version history of one story (any status), newest first. */
	public function history( int $storyId ): array {
		$versions = $this->versions->versionsForStory( $storyId );
		return array_reverse( $versions );
	}

	/**
	 * Human approval: version → reviewed, story → reviewed. The post REMAINS a
	 * draft — publishing is the editor's own WP action (§73).
	 * @return array{changed: bool, code: string}
	 */
	public function approve( int $versionId ): array {
		$version = $this->versions->find( $versionId );
		if ( null === $version ) {
			return array( 'changed' => false, 'code' => 'NOT_FOUND' );
		}
		if ( ! in_array( $version->status, array( ContentVersion::STATUS_NEEDS_REVIEW, ContentVersion::STATUS_APPROVED ), true ) ) {
			return array( 'changed' => false, 'code' => 'NOT_REVIEWABLE' );
		}
		if ( ! $this->versions->updateStatus( $versionId, ContentVersion::STATUS_REVIEWED, (float) $version->qualityScore ) ) {
			return array( 'changed' => false, 'code' => 'DB_FAILED' );
		}
		$this->stories->updateContentStatus( $version->storyId, 'reviewed' );
		$this->logger->info( 'Human approved version', array( 'story_id' => $version->storyId, 'version_id' => $versionId ), 'content.review', 'VERSION_APPROVED', $version->jobId );
		return array( 'changed' => true, 'code' => 'OK' );
	}

	/**
	 * Human rejection: version → rejected, story → rejected, reason audited
	 * (never used as AI input — the version is closed, a NEW cycle starts fresh).
	 * @return array{changed: bool, code: string}
	 */
	public function reject( int $versionId, string $reason ): array {
		$version = $this->versions->find( $versionId );
		if ( null === $version ) {
			return array( 'changed' => false, 'code' => 'NOT_FOUND' );
		}
		if ( ! in_array( $version->status, array( ContentVersion::STATUS_NEEDS_REVIEW, ContentVersion::STATUS_APPROVED ), true ) ) {
			return array( 'changed' => false, 'code' => 'NOT_REVIEWABLE' );
		}
		$safeReason = trim( strip_tags( (string) $reason ) );
		if ( mb_strlen( $safeReason, 'UTF-8' ) > 255 ) {
			$safeReason = mb_substr( $safeReason, 0, 255, 'UTF-8' );
		}
		if ( ! $this->versions->updateStatus( $versionId, ContentVersion::STATUS_REJECTED, (float) $version->qualityScore, '' !== $safeReason ? 'REJECTED:' . $safeReason : 'REJECTED' ) ) {
			return array( 'changed' => false, 'code' => 'DB_FAILED' );
		}
		$this->stories->updateContentStatus( $version->storyId, 'rejected' );
		$this->logger->warning( 'Human rejected version', array( 'story_id' => $version->storyId, 'version_id' => $versionId, 'reason' => $safeReason ), 'content.review', 'VERSION_REJECTED', $version->jobId );
		return array( 'changed' => true, 'code' => 'OK' );
	}
}
