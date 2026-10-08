<?php
/**
 * Stories admin page — Phase 2: cluster list with score cards and selection reasons.
 *
 * @package NewsDesk\AI\Admin\Page
 */

namespace NewsDesk\AI\Admin\Page;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Admin\AdminView;
use NewsDesk\AI\Admin\Menu;
use NewsDesk\AI\Application\Contracts\StoryRepositoryInterface;
use NewsDesk\AI\Support\Container;

final class StoriesPage {

	/** @var StoryRepositoryInterface */
	private $stories;

	public function __construct( Container $container ) {
		$this->stories = $container->get( StoryRepositoryInterface::class );
	}

	public function render(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			wp_die( esc_html__( 'Access denied.', 'newsdesk-ai' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended — read-only list filter.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$page   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable

		$data     = $this->stories->paginate( array( 'status' => $status ), $page, 25 );
		$sources  = array();
		if ( method_exists( $this->stories, 'storySources' ) ) {
			foreach ( $data['items'] as $story ) {
				$sources[ $story->storyId ] = $this->stories->storySources( $story->storyId );
			}
		}

		AdminView::render( 'stories', array(
			'stories'       => $data['items'],
			'total'         => $data['total'],
			'page'          => $page,
			'per_page'      => 25,
			'filters'       => array( 'status' => $status ),
			'sources_map'   => $sources,
			'counts'        => array(
				'candidate' => $this->stories->countByStatus( 'candidate' ),
				'selected'  => $this->stories->countByStatus( 'selected' ),
				'rejected'  => $this->stories->countByStatus( 'rejected' ),
				'expired'   => $this->stories->countByStatus( 'expired' ),
			),
		) );
	}
}
