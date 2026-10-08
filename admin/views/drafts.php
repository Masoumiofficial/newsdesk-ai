<?php
/**
 * Drafts review view (Phase 6, §36/§73) — data: pending, storyId, history.
 *
 * @package NewsDesk\AI\Admin\Views
 */

defined( 'ABSPATH' ) || exit;
/** @var array $view */
?>
<div class="wrap nd-wrap">
	<?php \NewsDesk\AI\Admin\AdminView::header( __( 'Draft review', 'newsdesk-ai' ), __( 'Generated articles awaiting your decision', 'newsdesk-ai' ) ); ?>
	<?php include __DIR__ . '/_notice.php'; ?>
	<p class="description">
		<?php esc_html_e( 'Every generated article waits here for your decision. “Passed the gate” means a WordPress draft exists and only needs human approval; “below the gate” means the quality score fell short and no draft was created. Approving only marks the version as publishable — the final publish always happens in WordPress itself, and AUTO PUBLISH is always off (§73).', 'newsdesk-ai' ); ?>
	</p>

	<?php if ( empty( $view['pending'] ) ) : ?>
		<div class="nd-panel"><?php \NewsDesk\AI\Admin\AdminView::empty( __( 'Nothing is awaiting review', 'newsdesk-ai' ), __( 'Once the pipeline has run, generated articles appear here.', 'newsdesk-ai' ), '✨' ); ?></div>
	<?php else : ?>
		<div class="nd-table-wrap"><table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Story', 'newsdesk-ai' ); ?></th>
					<th><?php esc_html_e( 'Version', 'newsdesk-ai' ); ?></th>
					<th><?php esc_html_e( 'Quality score', 'newsdesk-ai' ); ?></th>
					<th><?php esc_html_e( 'Quality gate', 'newsdesk-ai' ); ?></th>
					<th><?php esc_html_e( 'Create', 'newsdesk-ai' ); ?></th>
					<th><?php esc_html_e( 'Decision', 'newsdesk-ai' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $view['pending'] as $row ) :
				/** @var \NewsDesk\AI\Domain\Entity\ContentVersion $v */
				$v = $row['version'];
				/** @var \NewsDesk\AI\Domain\Entity\Story $st */
				$st = $row['story'];
				$postLink = $v->draftPostId > 0 ? get_edit_post_link( $v->draftPostId ) : '';
				?>
				<tr>
					<td>
						<?php echo esc_html( $st->canonicalTitle ); ?>
						<?php if ( '' !== $postLink ) : ?>
							<br /><a href="<?php echo esc_url( $postLink ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Edit the draft in WordPress', 'newsdesk-ai' ); ?></a>
						<?php endif; ?>
						<br /><a class="button button-small button-primary" style="margin-top:4px" href="<?php echo esc_url( add_query_arg( array( 'page' => 'nd-drafts', 'preview' => (int) $v->versionId ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Preview and review', 'newsdesk-ai' ); ?></a>
						<br /><a href="<?php echo esc_url( add_query_arg( array( 'page' => 'nd-drafts', 'story' => (int) $st->storyId ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Version history', 'newsdesk-ai' ); ?></a>
					</td>
					<td><?php echo (int) $v->versionNo; ?></td>
					<td><?php echo \NewsDesk\AI\Admin\AdminView::score( (float) $v->qualityScore ); // phpcs:ignore ?></td>
					<td>
						<?php if ( \NewsDesk\AI\Domain\Entity\ContentVersion::STATUS_APPROVED === $v->status ) : ?>
							<?php echo \NewsDesk\AI\Admin\AdminView::badge( 'ok', __( '✔ Passed the gate — draft created', 'newsdesk-ai' ) ); // phpcs:ignore ?>
						<?php else : ?>
							<?php echo \NewsDesk\AI\Admin\AdminView::badge( 'warn', __( '✖ Below the gate — needs review', 'newsdesk-ai' ) ); // phpcs:ignore ?>
						<?php endif; ?>
						<div class="description"><?php echo esc_html( $st->contentStatus ); ?></div>
					</td>
					<td>
						<?php echo esc_html( $v->createdAt ? $v->createdAt->format( 'Y-m-d H:i' ) : '—' ); ?>
						<div class="description"><?php echo esc_html( $v->errorCode ); ?></div>
					</td>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
							<input type="hidden" name="action" value="<?php echo esc_attr( \NewsDesk\AI\Admin\AdminActions::ACTION_DRAFT_APPROVE ); ?>" />
							<input type="hidden" name="version_id" value="<?php echo (int) $v->versionId; ?>" />
							<?php wp_nonce_field( \NewsDesk\AI\Admin\AdminActions::ACTION_DRAFT_APPROVE ); ?>
							<?php submit_button( __( 'Approve', 'newsdesk-ai' ), 'small', 'submit', false, array( 'class' => 'button button-secondary' ) ); ?>
						</form>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
							<input type="hidden" name="action" value="<?php echo esc_attr( \NewsDesk\AI\Admin\AdminActions::ACTION_DRAFT_REJECT ); ?>" />
							<input type="hidden" name="version_id" value="<?php echo (int) $v->versionId; ?>" />
							<input type="text" name="reason" placeholder="<?php esc_attr_e( 'Reason for rejection (optional)', 'newsdesk-ai' ); ?>" maxlength="255" />
							<?php wp_nonce_field( \NewsDesk\AI\Admin\AdminActions::ACTION_DRAFT_REJECT ); ?>
							<?php submit_button( __( 'Reject', 'newsdesk-ai' ), 'small', 'submit', false, array( 'class' => 'button button-secondary' ) ); ?>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table></div>
	<?php endif; ?>

	<?php if ( (int) $view['storyId'] > 0 ) : ?>
		<h2><?php esc_html_e( 'Version history', 'newsdesk-ai' ); ?></h2>
		<div class="nd-table-wrap"><table class="widefat striped">
			<thead><tr><th><?php esc_html_e( 'Version', 'newsdesk-ai' ); ?></th><th><?php esc_html_e( 'Status', 'newsdesk-ai' ); ?></th><th><?php esc_html_e( 'Score', 'newsdesk-ai' ); ?></th><th><?php esc_html_e( 'Error', 'newsdesk-ai' ); ?></th><th><?php esc_html_e( 'Time', 'newsdesk-ai' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $view['history'] as $h ) : ?>
				<tr>
					<td><a href="<?php echo esc_url( add_query_arg( array( 'page' => 'nd-drafts', 'preview' => (int) $h->versionId ), admin_url( 'admin.php' ) ) ); ?>"><?php echo (int) $h->versionNo; ?></a></td>
					<td><?php echo \NewsDesk\AI\Admin\AdminView::badge( (string) $h->status ); // phpcs:ignore ?></td>
					<td><?php echo \NewsDesk\AI\Admin\AdminView::score( (float) $h->qualityScore ); // phpcs:ignore ?></td>
					<td><?php echo esc_html( $h->errorCode ); ?></td>
					<td><?php echo esc_html( $h->createdAt ? $h->createdAt->format( 'Y-m-d H:i' ) : '—' ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table></div>
	<?php endif; ?>
<?php \NewsDesk\AI\Admin\AdminView::footer(); ?>
</div>
