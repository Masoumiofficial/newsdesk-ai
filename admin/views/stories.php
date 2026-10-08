<?php
/**
 * Stories view — data: stories, total, page, perPage, filters, sources_map, counts.
 *
 * @package NewsDesk\AI\Admin\Views
 */

defined( 'ABSPATH' ) || exit;
/** @var array $view */
?>
<div class="wrap nd-wrap">
	<?php \NewsDesk\AI\Admin\AdminView::header( __( 'Stories', 'newsdesk-ai' ), __( 'News clusters and SEO/AEO/GEO scores', 'newsdesk-ai' ) ); ?>
	<?php include __DIR__ . '/_notice.php'; ?>
	<p class="description">
		<?php esc_html_e( 'One story = one editorial unit gathering several sources. It never becomes more than one article (§5). Selection is an editorial decision; the output is not an article yet.', 'newsdesk-ai' ); ?>
	</p>

	<div class="nd-cards">
		<div class="nd-card"><span class="nd-num"><?php echo (int) $view['counts']['candidate']; ?></span><?php esc_html_e( 'Candidate', 'newsdesk-ai' ); ?></div>
		<div class="nd-card"><span class="nd-num"><?php echo (int) $view['counts']['selected']; ?></span><?php esc_html_e( 'Selected', 'newsdesk-ai' ); ?></div>
		<div class="nd-card"><span class="nd-num"><?php echo (int) $view['counts']['rejected']; ?></span><?php esc_html_e( 'Rejected', 'newsdesk-ai' ); ?></div>
		<div class="nd-card"><span class="nd-num"><?php echo (int) $view['counts']['expired']; ?></span><?php esc_html_e( 'Expired', 'newsdesk-ai' ); ?></div>
	</div>

	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="nd-filter">
		<input type="hidden" name="page" value="nd-stories" />
		<select name="status">
			<option value=""><?php esc_html_e( 'All statuses', 'newsdesk-ai' ); ?></option>
			<?php foreach ( array( 'candidate', 'selected', 'rejected', 'expired' ) as $st ) : ?>
				<option value="<?php echo esc_attr( $st ); ?>" <?php selected( $view['filters']['status'], $st ); ?>><?php echo esc_html( $st ); ?></option>
			<?php endforeach; ?>
		</select>
		<button class="button"><?php esc_html_e( 'Filter', 'newsdesk-ai' ); ?></button>
	</form>

	<div class="nd-table-wrap"><table class="widefat striped">
		<thead><tr>
			<th style="width:38%"><?php esc_html_e( 'Story title', 'newsdesk-ai' ); ?></th>
			<th><?php esc_html_e( 'Sources', 'newsdesk-ai' ); ?></th>
			<th><?php esc_html_e( 'Composite', 'newsdesk-ai' ); ?></th>
			<th><?php esc_html_e( 'SEO', 'newsdesk-ai' ); ?></th>
			<th><?php esc_html_e( 'AEO', 'newsdesk-ai' ); ?></th>
			<th><?php esc_html_e( 'GEO', 'newsdesk-ai' ); ?></th>
			<th><?php esc_html_e( 'Trust', 'newsdesk-ai' ); ?></th>
			<th><?php esc_html_e( 'Status', 'newsdesk-ai' ); ?></th>
		</tr></thead>
		<tbody>
		<?php if ( empty( $view['stories'] ) ) : ?>
			<tr><td colspan="8"><?php \NewsDesk\AI\Admin\AdminView::empty( __( 'No stories found', 'newsdesk-ai' ), __( 'Running the full pipeline is what builds the clusters.', 'newsdesk-ai' ), '🗞️' ); ?></td></tr>
		<?php else : ?>
			<?php foreach ( $view['stories'] as $story ) : ?>
				<tr>
					<td>
						<strong><?php echo esc_html( $story->canonicalTitle ); ?></strong>
						<?php if ( $story->selectionReason ) : ?>
							<div class="description" style="margin-top:4px"><?php echo esc_html( $story->selectionReason ); ?></div>
						<?php endif; ?>
						<?php if ( $story->topics ) : ?>
							<div class="description"><?php echo esc_html( implode( ' · ', $story->topics ) ); ?></div>
						<?php endif; ?>
					</td>
					<td><?php echo (int) $story->sourceCount; ?> (<?php echo (int) $story->itemCount; ?> item)</td>
					<td><?php echo \NewsDesk\AI\Admin\AdminView::score( (float) $story->importanceScore ); // phpcs:ignore ?></td>
					<?php
					$sig = is_array( $story->aeoSignals ) ? $story->aeoSignals : array();
					$seo = $sig['seo'] ?? $story->seoAeoGeoScore;
					$aeo = $sig['aeo'] ?? $story->seoAeoGeoScore;
					$geo = $sig['geo'] ?? $story->seoAeoGeoScore;
					?>
					<td><?php echo \NewsDesk\AI\Admin\AdminView::score( (float) $seo ); // phpcs:ignore ?></td>
					<td><?php echo \NewsDesk\AI\Admin\AdminView::score( (float) $aeo ); // phpcs:ignore ?></td>
					<td><?php echo \NewsDesk\AI\Admin\AdminView::score( (float) $geo ); // phpcs:ignore ?></td>
					<td><?php echo \NewsDesk\AI\Admin\AdminView::score( (float) $story->trustScore ); // phpcs:ignore ?></td>
					<td>
						<?php echo \NewsDesk\AI\Admin\AdminView::badge( (string) $story->status ); // phpcs:ignore ?>
						<?php if ( $story->researchStatus ) : ?>
							<div class="description">تحقیق: <?php echo esc_html( $story->researchStatus ); ?>
								· راستی‌آزمایی: <?php echo esc_html( $story->factCheckStatus ); ?>
								· شواهد: <?php echo (int) $story->evidenceCount; ?>
								· تأیید: <?php echo (int) $story->verifiedClaimCount; ?>
								· تناقض: <?php echo (int) $story->contradictionCount; ?></div>
							<?php if ( $story->contentStatus ) : ?><div class="description"><?php echo esc_html( 'محتوا: ' . $story->contentStatus ); ?></div><?php endif; ?>
						<?php endif; ?>
						<?php echo $story->windowKey ? ' <code>' . esc_html( $story->windowKey ) . '</code>' : ''; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>
	</table></div>

	<?php
	$pages = (int) ceil( $view['total'] / max( 1, $view['per_page'] ) );
	if ( $pages > 1 ) {
		echo '<p>';
		for ( $i = 1; $i <= $pages; $i++ ) {
			printf(
				'<a class="button button-small %s" href="%s">%d</a> ',
				$i === $view['page'] ? 'button-primary' : '',
				esc_url( add_query_arg( array( 'page' => 'nd-stories', 'paged' => $i ), admin_url( 'admin.php' ) ) ),
				(int) $i
			);
		}
		echo '</p>';
	}
	?>
<?php \NewsDesk\AI\Admin\AdminView::footer(); ?>
</div>
