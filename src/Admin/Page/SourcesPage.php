<?php
/**
 * §6/§47 Source management.
 *
 * @package NewsDesk\AI\Admin\Page
 */

namespace NewsDesk\AI\Admin\Page;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Admin\AdminView;
use NewsDesk\AI\Application\Contracts\SourceRepositoryInterface;
use NewsDesk\AI\Application\SourceService;
use NewsDesk\AI\Domain\Entity\Source;
use NewsDesk\AI\Support\Container;

final class SourcesPage {

	/** @var Container */
	private $container;
	/** @var int */
	private $perPage = 20;

	public function __construct( Container $container ) {
		$this->container = $container;
	}

	public function render(): void {
		if ( ! current_user_can( \NewsDesk\AI\Admin\Menu::CAP ) ) {
			wp_die( esc_html__( 'Access denied.', 'newsdesk-ai' ) );
		}

		$repo = $this->container->get( SourceRepositoryInterface::class );
		$edit = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0; // phpcs:ignore
		$page = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 ); // phpcs:ignore

		$filters = array(
			'search' => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '', // phpcs:ignore
			'type'   => isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '', // phpcs:ignore
			'status' => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '', // phpcs:ignore
		);

		$result = $repo->paginate( $filters, $page, $this->perPage );
		$form   = null;
		if ( $edit > 0 ) {
			$form = $repo->find( $edit );
		}
		$form = $form ?: new Source();

		$data = array(
			'sources' => $result['items'],
			'total'   => $result['total'],
			'page'    => $page,
			'perPage' => $this->perPage,
			'filters' => $filters,
			'form'    => $form,
			'editing' => $edit,
			'msg'     => isset( $_GET['nd_msg'] ) ? sanitize_key( wp_unslash( $_GET['nd_msg'] ) ) : '', // phpcs:ignore
			'err'     => isset( $_GET['nd_err'] ) ? sanitize_text_field( wp_unslash( $_GET['nd_err'] ) ) : '', // phpcs:ignore
			'categories' => SourceService::CATEGORIES,
			'types'      => Source::SUPPORTED_TYPES,
		);
		AdminView::render( 'sources', $data );
	}
}
