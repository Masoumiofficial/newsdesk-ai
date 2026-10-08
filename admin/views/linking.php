<?php
/**
 * A-11 — Linking view. data: include_links, scan_pool, recent
 *
 * @package NewsDesk\AI\Admin\Views
 */

defined( 'ABSPATH' ) || exit;
/** @var array $view */
?>
<div class="wrap nd-wrap">
	<?php \NewsDesk\AI\Admin\AdminView::header( __( 'Linking', 'newsdesk-ai' ), __( 'Internal link suggestions and source citations', 'newsdesk-ai' ) ); ?>
	<?php include __DIR__ . '/_notice.php'; ?>

	<p class="description">
		<?php esc_html_e( 'Internal links are suggestions only and are never inserted into the body automatically. An external citation is recorded for every sourced claim so its origin stays traceable.', 'newsdesk-ai' ); ?>
	</p>

	<table class="widefat striped nd-table">
		<tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Suggest links during content generation', 'newsdesk-ai' ); ?></th>
				<td>
					<?php if ( $view['include_links'] ) : ?>
						<span class="nd-badge nd-badge-ok"><?php esc_html_e( 'Active', 'newsdesk-ai' ); ?></span>
					<?php else : ?>
						<span class="nd-badge nd-badge-off"><?php esc_html_e( 'Disabled', 'newsdesk-ai' ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Post scan pool size', 'newsdesk-ai' ); ?></th>
				<td><?php echo (int) $view['scan_pool']; ?></td>
			</tr>
		</tbody>
	</table>

	<h2><?php esc_html_e( 'Source citations in recent stories', 'newsdesk-ai' ); ?></h2>
	<table class="widefat striped nd-table">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Story', 'newsdesk-ai' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Citations', 'newsdesk-ai' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php if ( ! $view['recent'] ) : ?>
			<tr><td colspan="2"><?php esc_html_e( 'No stories have been recorded yet.', 'newsdesk-ai' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $view['recent'] as $nd_row ) : ?>
			<tr>
				<td>
					<strong><?php echo esc_html( $nd_row['title'] ); ?></strong>
					<div class="description">#<?php echo (int) $nd_row['story_id']; ?></div>
				</td>
				<td><?php echo (int) count( $nd_row['external'] ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</div>
