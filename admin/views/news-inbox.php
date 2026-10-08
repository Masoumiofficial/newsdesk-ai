<?php
/**
 * A-9 / A-10 — News Inbox view.
 *
 * data: items, total, page, per_page, filters, clusters, source_names, counts, statuses
 *
 * @package NewsDesk\AI\Admin\Views
 */

defined( 'ABSPATH' ) || exit;
/** @var array $view */

$nd_cluster = (int) $view['filters']['cluster_of'];
$nd_pages   = (int) ceil( max( 1, $view['total'] ) / max( 1, $view['per_page'] ) );
?>
<div class="wrap nd-wrap">
	<?php \NewsDesk\AI\Admin\AdminView::header( __( 'News inbox', 'newsdesk-ai' ), __( 'Everything crawled, before it becomes a story', 'newsdesk-ai' ) ); ?>
	<?php include __DIR__ . '/_notice.php'; ?>

	<p class="description">
		<?php esc_html_e( 'This is the raw input list. Duplicates are not deleted; they are linked to the cluster winner so the reasoning stays auditable (§6).', 'newsdesk-ai' ); ?>
	</p>

	<div class="nd-cards">
		<div class="nd-card"><span class="nd-num"><?php echo (int) $view['counts']['all']; ?></span><?php esc_html_e( 'Total items', 'newsdesk-ai' ); ?></div>
		<div class="nd-card"><span class="nd-num"><?php echo (int) $view['counts']['duplicates']; ?></span><?php esc_html_e( 'Duplicate', 'newsdesk-ai' ); ?></div>
	</div>

	<?php if ( $nd_cluster > 0 ) : ?>
		<div class="notice notice-info inline">
			<p>
				<?php
				printf(
					/* translators: %d: news item ID */
					esc_html__( 'Showing the duplicate cluster for item #%d.', 'newsdesk-ai' ),
					$nd_cluster
				);
				?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=nd-news' ) ); ?>"><?php esc_html_e( 'Back to all news', 'newsdesk-ai' ); ?></a>
			</p>
		</div>
	<?php endif; ?>

	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="nd-filter">
		<input type="hidden" name="page" value="nd-news" />
		<select name="status">
			<option value=""><?php esc_html_e( 'All statuses', 'newsdesk-ai' ); ?></option>
			<?php foreach ( $view['statuses'] as $nd_key => $nd_label ) : ?>
				<option value="<?php echo esc_attr( $nd_key ); ?>" <?php selected( $view['filters']['status'], $nd_key ); ?>><?php echo esc_html( $nd_label ); ?></option>
			<?php endforeach; ?>
		</select>
		<select name="source_id">
			<option value="0"><?php esc_html_e( 'All sources', 'newsdesk-ai' ); ?></option>
			<?php foreach ( $view['source_names'] as $nd_sid => $nd_sname ) : ?>
				<option value="<?php echo (int) $nd_sid; ?>" <?php selected( (int) $view['filters']['source_id'], (int) $nd_sid ); ?>><?php echo esc_html( $nd_sname ); ?></option>
			<?php endforeach; ?>
		</select>
		<input type="search" name="s" value="<?php echo esc_attr( $view['filters']['search'] ); ?>" placeholder="<?php esc_attr_e( 'Search by title…', 'newsdesk-ai' ); ?>" />
		<button type="submit" class="button"><?php esc_html_e( 'Filter', 'newsdesk-ai' ); ?></button>
	</form>

	<table class="widefat striped nd-table">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Title', 'newsdesk-ai' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Source', 'newsdesk-ai' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'newsdesk-ai' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Cluster', 'newsdesk-ai' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Date', 'newsdesk-ai' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php if ( ! $view['items'] ) : ?>
			<tr><td colspan="5"><?php esc_html_e( 'No items found.', 'newsdesk-ai' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $view['items'] as $nd_item ) : ?>
			<?php $nd_dupes = (int) ( $view['clusters'][ $nd_item->id ] ?? 0 ); ?>
			<tr>
				<td>
					<strong><?php echo esc_html( $nd_item->title ); ?></strong>
					<?php if ( '' !== (string) $nd_item->canonicalUrl ) : ?>
						<div class="row-actions">
							<a href="<?php echo esc_url( $nd_item->canonicalUrl ); ?>" target="_blank" rel="noopener noreferrer nofollow"><?php esc_html_e( 'View source', 'newsdesk-ai' ); ?></a>
						</div>
					<?php endif; ?>
				</td>
				<td><?php echo esc_html( $view['source_names'][ (int) $nd_item->sourceId ] ?? '—' ); ?></td>
				<td>
					<span class="nd-badge nd-badge-<?php echo esc_attr( $nd_item->status ); ?>">
						<?php echo esc_html( $view['statuses'][ $nd_item->status ] ?? $nd_item->status ); ?>
					</span>
					<?php if ( (int) $nd_item->duplicateOfId > 0 ) : ?>
						<div class="description">
							<?php
							printf(
								/* translators: 1: winner item ID, 2: duplicate level */
								esc_html__( 'Duplicate of #%1$d (%2$s)', 'newsdesk-ai' ),
								(int) $nd_item->duplicateOfId,
								esc_html( $nd_item->duplicateLevel )
							);
							?>
						</div>
					<?php endif; ?>
				</td>
				<td>
					<?php if ( $nd_dupes > 0 ) : ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=nd-news&cluster_of=' . (int) $nd_item->id ) ); ?>">
							<?php
							printf(
								/* translators: %d: number of duplicates */
								esc_html__( '%d duplicates', 'newsdesk-ai' ),
								$nd_dupes
							);
							?>
						</a>
					<?php else : ?>
						—
					<?php endif; ?>
				</td>
				<td><?php echo esc_html( $nd_item->publishedAt ? $nd_item->publishedAt->format( 'Y-m-d H:i' ) : '—' ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ( $nd_pages > 1 ) : ?>
		<div class="tablenav"><div class="tablenav-pages">
			<?php
			echo wp_kses_post(
				paginate_links(
					array(
						'base'    => esc_url_raw( add_query_arg( 'paged', '%#%' ) ),
						'format'  => '',
						'current' => (int) $view['page'],
						'total'   => $nd_pages,
					)
				)
			);
			?>
		</div></div>
	<?php endif; ?>
</div>
