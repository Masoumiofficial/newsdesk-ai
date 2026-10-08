<?php
/**
 * Dashboard view — data: stats, recentJobs, globalLock, nextRun, history, tz, msg.
 *
 * @package NewsDesk\AI\Admin\Views
 */

defined( 'ABSPATH' ) || exit;
/** @var array $view */

$msgs = array(
	'job-created' => __( 'The job was created and queued. You can follow the result on the dashboard or in the logs.', 'newsdesk-ai' ),
	'job-exists'  => __( 'A similar job is already active (the duplicate was skipped).', 'newsdesk-ai' ),
	'retry-failed'=> __( 'Retry failed.', 'newsdesk-ai' ),
);
if ( ! empty( $view['msg'] ) && isset( $msgs[ $view['msg'] ] ) ) {
	\NewsDesk\AI\Admin\AdminView::notice( 'success', $msgs[ $view['msg'] ] );
}
?>
<div class="wrap nd-wrap">
	<?php \NewsDesk\AI\Admin\AdminView::header( __( 'Dashboard', 'newsdesk-ai' ), __( 'An AI newsroom for your site — quality over quantity. Nothing is published without human approval.', 'newsdesk-ai' ) ); ?>
	<?php include __DIR__ . '/_notice.php'; ?>

	<?php if ( ! empty( $view['health_problem'] ) ) : $hp = $view['health_problem']; ?>
		<div class="notice notice-<?php echo 'fail' === $hp['status'] ? 'error' : 'warning'; ?> inline" style="margin:12px 0">
			<p><strong><?php echo esc_html( $hp['title'] ); ?>:</strong> <?php echo esc_html( $hp['detail'] ); ?>
				<?php if ( '' !== $hp['fix'] ) : ?><br />➜ <?php echo esc_html( $hp['fix'] ); ?><?php endif; ?>
				<br /><a href="<?php echo esc_url( add_query_arg( array( 'page' => 'nd-health' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'System health and run now ←', 'newsdesk-ai' ); ?></a>
			</p>
		</div>
	<?php endif; ?>

	<div class="nd-actions">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block">
			<input type="hidden" name="action" value="newsdesk_newsroom_run_discovery" />
			<?php wp_nonce_field( 'newsdesk_newsroom_run_discovery' ); ?>
			<button type="submit" class="button"><?php esc_html_e( 'Run discovery', 'newsdesk-ai' ); ?></button>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block">
			<input type="hidden" name="action" value="newsdesk_newsroom_run_pipeline" />
			<?php wp_nonce_field( 'newsdesk_newsroom_run_pipeline' ); ?>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Run the full pipeline (background queue)', 'newsdesk-ai' ); ?></button>
		</form>
	</div>

	<div class="nd-cards">
		<div class="nd-card"><span class="nd-num"><?php echo (int) $view['stats']['sources']; ?></span><?php esc_html_e( 'Sources (active: ', 'newsdesk-ai' ); ?><?php echo (int) $view['stats']['sourcesActive']; ?>)</div>
		<div class="nd-card"><span class="nd-num"><?php echo (int) $view['stats']['items']; ?></span><?php esc_html_e( 'News item', 'newsdesk-ai' ); ?></div>
		<div class="nd-card"><span class="nd-num"><?php echo (int) $view['stats']['duplicates']; ?></span><?php esc_html_e( 'Duplicate', 'newsdesk-ai' ); ?></div>
		<div class="nd-card"><span class="nd-num"><?php echo (int) $view['stats']['stories']; ?></span><?php esc_html_e( 'Stories (selected: ', 'newsdesk-ai' ); ?><?php echo (int) $view['stats']['storiesSelected']; ?>)</div>
		<div class="nd-card"><span class="nd-num"><?php echo (int) $view['stats']['claims']; ?></span><?php esc_html_e( 'Evidence claim', 'newsdesk-ai' ); ?></div>
		<div class="nd-card"><span class="nd-num"><?php echo (int) $view['stats']['drafts']; ?></span><?php esc_html_e( 'Content draft', 'newsdesk-ai' ); ?></div>
		<div class="nd-card <?php echo (int) $view['stats']['review'] > 0 ? 'nd-card-warn' : ''; ?>"><span class="nd-num"><?php echo (int) $view['stats']['review']; ?></span><?php esc_html_e( 'Awaiting review (§36)', 'newsdesk-ai' ); ?></div>
		<div class="nd-card"><span class="nd-num"><?php echo (int) $view['stats']['images']; ?></span><?php esc_html_e( 'Images ready (failed: ', 'newsdesk-ai' ); ?><?php echo (int) $view['stats']['imagesFailed']; ?>)</div>
		<div class="nd-card"><span class="nd-num"><?php echo (int) $view['stats']['jobsQueued']; ?></span><?php esc_html_e( 'Queued', 'newsdesk-ai' ); ?></div>
		<div class="nd-card nd-card-ok"><span class="nd-num"><?php echo (int) $view['stats']['jobsDone']; ?></span><?php esc_html_e( 'Success', 'newsdesk-ai' ); ?></div>
		<div class="nd-card nd-card-alert"><span class="nd-num"><?php echo (int) $view['stats']['jobsFailed']; ?></span><?php esc_html_e( 'Failed', 'newsdesk-ai' ); ?></div>
	</div>

	<div class="nd-grid" style="margin-top:16px">
		<div class="nd-panel">
			<h2><?php esc_html_e( 'Schedule', 'newsdesk-ai' ); ?></h2>
			<p style="margin:0 0 8px"><?php esc_html_e( 'Next run:', 'newsdesk-ai' ); ?>
				<strong><?php echo $view['nextRun'] ? esc_html( $view['nextRun']->format( 'Y-m-d H:i' ) ) : esc_html__( '— (not calculated yet)', 'newsdesk-ai' ); ?></strong></p>
			<p style="margin:0 0 8px"><?php esc_html_e( 'Global lock:', 'newsdesk-ai' ); ?>
				<?php echo $view['globalLock'] ? \NewsDesk\AI\Admin\AdminView::badge( 'warn', __( 'Busy — waiting for another run to finish', 'newsdesk-ai' ) ) : \NewsDesk\AI\Admin\AdminView::badge( 'ok', __( 'Free', 'newsdesk-ai' ) ); // phpcs:ignore ?></p>
			<p style="margin:0" class="description"><?php esc_html_e( 'Site local time:', 'newsdesk-ai' ); ?> <code><?php echo esc_html( $view['tz'] ); ?></code></p>
		</div>
		<div class="nd-panel">
			<h2><?php esc_html_e( 'Quick links', 'newsdesk-ai' ); ?></h2>
			<div class="nd-actions" style="margin:0">
				<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'nd-drafts' ), admin_url( 'admin.php' ) ) ); ?>">📝 <?php esc_html_e( 'Review queue', 'newsdesk-ai' ); ?></a>
				<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'nd-health' ), admin_url( 'admin.php' ) ) ); ?>">❤️ <?php esc_html_e( 'System health', 'newsdesk-ai' ); ?></a>
				<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'nd-logs' ), admin_url( 'admin.php' ) ) ); ?>">📋 <?php esc_html_e( 'Logs', 'newsdesk-ai' ); ?></a>
				<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'nd-sources' ), admin_url( 'admin.php' ) ) ); ?>">📡 <?php esc_html_e( 'Sources', 'newsdesk-ai' ); ?></a>
			</div>
		</div>
	</div>

	<h2><?php esc_html_e( 'Recent jobs', 'newsdesk-ai' ); ?></h2>
	<div class="nd-table-wrap">
	<table class="widefat striped nd-responsive">
		<thead><tr>
			<th><?php esc_html_e( 'Job', 'newsdesk-ai' ); ?></th>
			<th><?php esc_html_e( 'Type', 'newsdesk-ai' ); ?></th>
			<th><?php esc_html_e( 'Status', 'newsdesk-ai' ); ?></th>
			<th><?php esc_html_e( 'Start', 'newsdesk-ai' ); ?></th>
			<th><?php esc_html_e( 'Finished', 'newsdesk-ai' ); ?></th>
			<th></th>
		</tr></thead>
		<tbody>
		<?php if ( empty( $view['recentJobs'] ) ) : ?>
			<tr><td colspan="6"><?php \NewsDesk\AI\Admin\AdminView::empty( __( 'No job has run yet', 'newsdesk-ai' ), __( 'Start with the “Run the full pipeline” button.', 'newsdesk-ai' ), '🚀' ); ?></td></tr>
		<?php else : ?>
			<?php foreach ( $view['recentJobs'] as $job ) : ?>
				<tr>
					<td data-label="<?php esc_attr_e( 'Job', 'newsdesk-ai' ); ?>">#<?php echo (int) $job->id; ?> <code><?php echo esc_html( $job->jobKey ); ?></code></td>
					<td data-label="<?php esc_attr_e( 'Type', 'newsdesk-ai' ); ?>"><?php echo esc_html( $job->type ); ?></td>
					<td data-label="<?php esc_attr_e( 'Status', 'newsdesk-ai' ); ?>"><?php echo \NewsDesk\AI\Admin\AdminView::badge( strtolower( (string) $job->status ) ); // phpcs:ignore ?></td>
					<td data-label="<?php esc_attr_e( 'Start', 'newsdesk-ai' ); ?>"><?php echo $job->startedAt ? esc_html( $job->startedAt->format( 'Y-m-d H:i:s' ) ) : '—'; ?></td>
					<td data-label="<?php esc_attr_e( 'Finished', 'newsdesk-ai' ); ?>"><?php echo $job->finishedAt ? esc_html( $job->finishedAt->format( 'Y-m-d H:i:s' ) ) : '—'; ?></td>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Retry this job? (a new job will be created)', 'newsdesk-ai' ) ); ?>');">
							<input type="hidden" name="action" value="newsdesk_newsroom_job_retry" />
							<input type="hidden" name="job_id" value="<?php echo (int) $job->id; ?>" />
							<?php wp_nonce_field( 'newsdesk_newsroom_job_retry' ); ?>
							<button class="button button-small" type="submit"><?php esc_html_e( 'Retry', 'newsdesk-ai' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>
	</table>
	</div>
<?php \NewsDesk\AI\Admin\AdminView::footer(); ?>
</div>
