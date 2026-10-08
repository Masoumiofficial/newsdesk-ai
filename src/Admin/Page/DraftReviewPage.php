<?php
/**
 * Phase 6 — human review queue for machine drafts (§36/§73).
 *
 * @package NewsDesk\AI\Admin\Page
 */

namespace NewsDesk\AI\Admin\Page;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Admin\AdminView;
use NewsDesk\AI\Admin\Menu;
use NewsDesk\AI\Application\Content\DraftReviewService;
use NewsDesk\AI\Support\Container;

final class DraftReviewPage {

	/** @var Container */
	private $container;

	public function __construct( Container $container ) {
		$this->container = $container;
	}

	public function render(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			wp_die( esc_html__( 'Access denied.', 'newsdesk-ai' ) );
		}
		$service = $this->container->get( DraftReviewService::class );
		$storyId   = isset( $_GET['story'] ) ? absint( $_GET['story'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$previewId = isset( $_GET['preview'] ) ? absint( $_GET['preview'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $previewId > 0 ) {
			$preview = $service->preview( $previewId );
			if ( null === $preview ) {
				wp_die( esc_html__( 'That version was not found.', 'newsdesk-ai' ), 404 );
			}
			AdminView::render( 'draft_preview', $preview );
			return;
		}

		AdminView::render( 'drafts', array(
			'pending' => $service->pending(),
			'storyId' => $storyId,
			'history' => $storyId > 0 ? $service->history( $storyId ) : array(),
		) );
	}
}
