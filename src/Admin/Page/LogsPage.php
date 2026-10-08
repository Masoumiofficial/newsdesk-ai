<?php
/**
 * §49 Logs viewer (read-only, paginated).
 *
 * @package NewsDesk\AI\Admin\Page
 */

namespace NewsDesk\AI\Admin\Page;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Admin\AdminView;
use NewsDesk\AI\Logging\Contracts\LogRepositoryInterface;
use NewsDesk\AI\Support\Container;

final class LogsPage {

	/** @var Container */
	private $container;
	/** @var int */
	private $perPage = 50;

	public function __construct( Container $container ) {
		$this->container = $container;
	}

	public function render(): void {
		if ( ! current_user_can( \NewsDesk\AI\Admin\Menu::CAP ) ) {
			wp_die( esc_html__( 'Access denied.', 'newsdesk-ai' ) );
		}
		$filters = array(
			'level'     => isset( $_GET['level'] ) ? sanitize_key( wp_unslash( $_GET['level'] ) ) : '', // phpcs:ignore
			'component' => isset( $_GET['component'] ) ? sanitize_text_field( wp_unslash( $_GET['component'] ) ) : '', // phpcs:ignore
			'job_id'    => isset( $_GET['job_id'] ) ? absint( $_GET['job_id'] ) : 0, // phpcs:ignore
		);
		$page  = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 ); // phpcs:ignore
		$repo  = $this->container->get( LogRepositoryInterface::class );
		$data  = $repo->paginate( $filters, $page, $this->perPage );
		AdminView::render( 'logs', array(
			'items'   => $data['items'],
			'total'   => $data['total'],
			'page'    => $page,
			'perPage' => $this->perPage,
			'filters' => $filters,
		) );
	}
}
