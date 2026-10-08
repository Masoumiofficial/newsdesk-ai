<?php
/**
 * A-9 / A-10 — the News Inbox.
 *
 * Everything the pipeline ingests lands here first. Until now this was a
 * placeholder, which meant an operator could never see WHY an item was
 * dropped as a duplicate or never became a story. The inbox shows the raw
 * items, their duplicate clusters, and lets an editor override the automatic
 * KEEP / MERGE / REJECT decision.
 *
 * Read-only by default; every mutation goes through AdminActions with a nonce
 * and a capability check.
 *
 * @package NewsDesk\AI\Admin\Page
 */

namespace NewsDesk\AI\Admin\Page;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Admin\AdminView;
use NewsDesk\AI\Admin\Menu;
use NewsDesk\AI\Application\Contracts\NewsItemRepositoryInterface;
use NewsDesk\AI\Application\Contracts\SourceRepositoryInterface;
use NewsDesk\AI\Support\Container;

final class NewsInboxPage {

	public const PER_PAGE = 25;

	/** @var NewsItemRepositoryInterface */
	private $items;
	/** @var SourceRepositoryInterface */
	private $sources;

	public function __construct( Container $container ) {
		$this->items   = $container->get( NewsItemRepositoryInterface::class );
		$this->sources = $container->get( SourceRepositoryInterface::class );
	}

	public function render(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			wp_die( esc_html__( 'Access denied.', 'newsdesk-ai' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended — read-only list filters.
		$status   = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$sourceId = isset( $_GET['source_id'] ) ? absint( $_GET['source_id'] ) : 0;
		$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$page     = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$cluster  = isset( $_GET['cluster_of'] ) ? absint( $_GET['cluster_of'] ) : 0;
		// phpcs:enable

		$filters = array(
			'status'    => $status,
			'source_id' => $sourceId,
			'search'    => $search,
		);

		// A-10: drilling into one cluster shows the duplicates of a winner.
		if ( $cluster > 0 ) {
			$filters['duplicate_of_id'] = $cluster;
			$filters['status']          = '';
		}

		$data = $this->items->paginate( $filters, $page, self::PER_PAGE );

		// Duplicate counts per shown item, so the cluster size is visible
		// without a second query per row on the template side.
		$clusters = array();
		foreach ( $data['items'] as $item ) {
			$clusters[ $item->id ] = $cluster > 0
				? 0
				: (int) $this->items->paginate( array( 'duplicate_of_id' => $item->id ), 1, 1 )['total'];
		}

		AdminView::render(
			'news-inbox',
			array(
				'items'        => $data['items'],
				'total'        => $data['total'],
				'page'         => $page,
				'per_page'     => self::PER_PAGE,
				'filters'      => array(
					'status'     => $status,
					'source_id'  => $sourceId,
					'search'     => $search,
					'cluster_of' => $cluster,
				),
				'clusters'     => $clusters,
				'source_names' => $this->sourceNames(),
				'counts'       => array(
					'all'        => $this->items->countAll(),
					'duplicates' => $this->items->countDuplicates(),
				),
				'statuses'     => array(
					'new'        => __( 'Fresh', 'newsdesk-ai' ),
					'normalized' => __( 'Normalized', 'newsdesk-ai' ),
					'duplicate'  => __( 'Duplicate', 'newsdesk-ai' ),
					'ignored'    => __( 'Ignored', 'newsdesk-ai' ),
				),
			)
		);
	}

	/** @return array<int,string> */
	private function sourceNames(): array {
		$names = array();
		foreach ( $this->sources->findAll() as $source ) {
			$names[ (int) $source->id ] = (string) $source->name;
		}
		return $names;
	}
}
