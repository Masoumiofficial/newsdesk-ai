<?php
/**
 * A-8 — the correction path.
 *
 * When fact-check finds that something we already published is contradicted by
 * the evidence, the cannibalization engine returns CORRECT. Everything else in
 * this plugin is drafts-only, but a published falsehood is the one case where
 * leaving the live page untouched is the worse option: readers are still being
 * shown the wrong claim.
 *
 * The compromise the spec asks for: never rewrite the body silently. This
 * service APPENDS a dated, visible correction notice and writes a correction
 * log entry. The rewritten article itself still arrives as a revision draft an
 * editor must approve.
 *
 * @package NewsDesk\AI\Application\Content
 */

namespace NewsDesk\AI\Application\Content;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Contracts\WpPostWriterInterface;
use NewsDesk\AI\Application\NewsroomSettings;
use NewsDesk\AI\Application\Scoring\CannibalizationEngine;
use NewsDesk\AI\Domain\Entity\Story;
use NewsDesk\AI\Logging\Contracts\LoggerInterface;

final class CorrectionService {

	/** Option holding the correction log (newest first). */
	public const LOG_OPTION = 'newsdesk_newsroom_corrections';
	/** How many correction entries to retain. */
	public const LOG_LIMIT = 200;

	/** @var WpPostWriterInterface */
	private $writer;
	/** @var NewsroomSettings */
	private $settings;
	/** @var LoggerInterface */
	private $logger;

	public function __construct( WpPostWriterInterface $writer, NewsroomSettings $settings, LoggerInterface $logger ) {
		$this->writer   = $writer;
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * Apply a correction for a story whose decision is CORRECT.
	 *
	 * @param Story    $story        must carry CORRECT + existingArticleId
	 * @param string[] $contradictions human-readable contradiction summaries
	 * @param int      $jobId
	 * @return array{applied:bool,post_id:int,reason:string}
	 */
	public function apply( Story $story, array $contradictions, int $jobId = 0 ): array {
		$postId   = (int) $story->existingArticleId;
		$decision = strtoupper( (string) $story->editorialDecision );

		if ( CannibalizationEngine::DECISION_CORRECT !== $decision ) {
			return $this->refuse( $postId, 'NOT_A_CORRECTION' );
		}
		if ( $postId <= 0 ) {
			return $this->refuse( $postId, 'NO_TARGET_ARTICLE' );
		}
		// Correcting a draft is meaningless: nobody has read it. The revision
		// draft path covers that case instead.
		if ( ! $this->writer->isPublished( $postId ) ) {
			return $this->refuse( $postId, 'TARGET_NOT_PUBLISHED' );
		}
		$notes = $this->cleanNotes( $contradictions );
		if ( ! $notes ) {
			// Never append an empty "we were wrong" box with no substance.
			return $this->refuse( $postId, 'NO_CONTRADICTION_DETAIL' );
		}

		$notice = $this->renderNotice( $notes );
		$ok     = $this->writer->appendCorrectionNotice( $postId, $notice );
		if ( ! $ok ) {
			return $this->refuse( $postId, 'WRITE_FAILED' );
		}

		$this->record(
			array(
				'post_id'  => $postId,
				'story_id' => (int) $story->storyId,
				'title'    => (string) $story->canonicalTitle,
				'notes'    => $notes,
				'at'       => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		$this->logger->warning(
			'Correction notice appended to a published article',
			array(
				'post_id'  => $postId,
				'story_id' => $story->storyId,
				'count'    => count( $notes ),
			),
			'content.correction',
			'CORRECTION_APPLIED',
			$jobId
		);

		return array(
			'applied' => true,
			'post_id' => $postId,
			'reason'  => 'APPLIED',
		);
	}

	/** The correction log, newest first. */
	public function log(): array {
		$rows = get_option( self::LOG_OPTION, array() );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param string[] $notes
	 */
	private function renderNotice( array $notes ): string {
		$heading = __( 'Correct', 'newsdesk-ai' );
		$date    = gmdate( 'Y-m-d' );
		$html    = '<p><strong>' . esc_html( $heading ) . ' — ' . esc_html( $date ) . '</strong></p><ul>';
		foreach ( $notes as $note ) {
			$html .= '<li>' . esc_html( $note ) . '</li>';
		}
		return $html . '</ul>';
	}

	/**
	 * @param string[] $contradictions
	 * @return string[]
	 */
	private function cleanNotes( array $contradictions ): array {
		$out = array();
		foreach ( $contradictions as $c ) {
			if ( ! is_scalar( $c ) ) {
				continue;
			}
			$text = trim( (string) $c );
			if ( '' === $text ) {
				continue;
			}
			$out[] = $text;
		}
		return array_values( array_unique( $out ) );
	}

	/** @param array<string,mixed> $entry */
	private function record( array $entry ): void {
		$rows = $this->log();
		array_unshift( $rows, $entry );
		if ( count( $rows ) > self::LOG_LIMIT ) {
			$rows = array_slice( $rows, 0, self::LOG_LIMIT );
		}
		update_option( self::LOG_OPTION, $rows, false );
	}

	/** @return array{applied:bool,post_id:int,reason:string} */
	private function refuse( int $postId, string $reason ): array {
		$this->logger->info(
			'Correction not applied',
			array(
				'post_id' => $postId,
				'reason'  => $reason,
			),
			'content.correction',
			'CORRECTION_SKIPPED'
		);
		return array(
			'applied' => false,
			'post_id' => $postId,
			'reason'  => $reason,
		);
	}
}
