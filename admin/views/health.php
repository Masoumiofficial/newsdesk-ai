<?php
/**
 * Health view (v1.3) — data: checks, worst, job.
 *
 * @package NewsDesk\AI\Admin\Views
 */

defined( 'ABSPATH' ) || exit;
/** @var array $view */

$icon = array( 'ok' => '✅', 'warn' => '⚠️', 'fail' => '❌' );
$color = array( 'ok' => '#00a32a', 'warn' => '#dba617', 'fail' => '#d63638' );
$firstProblem = null;
foreach ( $view['checks'] as $c ) {
	if ( 'ok' !== $c['status'] ) {
		$firstProblem = $c;
		break;
	}
}
?>
<div class="wrap nd-wrap">
	<?php \NewsDesk\AI\Admin\AdminView::header( __( 'System health', 'newsdesk-ai' ), __( 'Find the first blocker and run the pipeline now', 'newsdesk-ai' ) ); ?>
	<?php include __DIR__ . '/_notice.php'; ?>

	<?php if ( $view['job'] > 0 ) : ?>
		<p><a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'nd-logs', 'job_id' => (int) $view['job'] ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( sprintf( __( 'Logs for job #%d', 'newsdesk-ai' ), (int) $view['job'] ) ); ?></a></p>
	<?php endif; ?>

	<?php if ( $firstProblem ) : ?>
		<div class="nd-panel nd-health-first <?php echo 'fail' === $firstProblem['status'] ? 'nd-health-fail' : 'nd-health-warn'; ?>">
			<div class="nd-hicon"><?php echo esc_html( $icon[ $firstProblem['status'] ] ?? '' ); ?></div>
			<div>
				<strong style="font-size:14px"><?php esc_html_e( 'First blocker:', 'newsdesk-ai' ); ?> <?php echo esc_html( $firstProblem['title'] ); ?></strong><br />
				<span style="line-height:1.8"><?php echo esc_html( $firstProblem['detail'] ); ?></span>
				<?php if ( '' !== $firstProblem['fix'] ) : ?><span class="nd-fix">➜ <?php echo esc_html( $firstProblem['fix'] ); ?></span><?php endif; ?>
			</div>
		</div>
	<?php else : ?>
		<div class="nd-panel nd-health-first nd-health-ok"><div class="nd-hicon">✅</div><div><strong style="font-size:14px"><?php esc_html_e( 'Everything is green.', 'newsdesk-ai' ); ?></strong><br /><span class="description"><?php esc_html_e( 'The pipeline is ready; the next run follows the schedule.', 'newsdesk-ai' ); ?></span></div></div>
	<?php endif; ?>

	<div class="nd-actions">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='<?php echo esc_js( __( 'Running… (allow up to 5 minutes)', 'newsdesk-ai' ) ); ?>';">
			<input type="hidden" name="action" value="newsdesk_newsroom_run_now" />
			<?php wp_nonce_field( 'newsdesk_newsroom_run_now' ); ?>
			<button type="submit" class="button button-primary"><?php esc_html_e( '▶ Run the whole pipeline now (immediately, no queue)', 'newsdesk-ai' ); ?></button>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block">
			<input type="hidden" name="action" value="newsdesk_newsroom_provider_test" />
			<?php wp_nonce_field( 'newsdesk_newsroom_provider_test' ); ?>
			<button type="submit" class="button"><?php esc_html_e( 'Test AI connection', 'newsdesk-ai' ); ?></button>
		</form>
		<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'nd-health' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Refresh', 'newsdesk-ai' ); ?></a>
	</div>
	<p class="description"><?php esc_html_e( '“Run now” executes the job inside this request, independently of WP-Cron and Action Scheduler; it exists for first setup and troubleshooting. If your browser times out the run continues in the background — reload after a few minutes.', 'newsdesk-ai' ); ?></p>

	<div class="nd-table-wrap"><table class="widefat striped nd-responsive">
		<thead><tr>
			<th style="width:40px"></th>
			<th style="width:190px"><?php esc_html_e( 'Review', 'newsdesk-ai' ); ?></th>
			<th><?php esc_html_e( 'Status', 'newsdesk-ai' ); ?></th>
			<th style="width:38%"><?php esc_html_e( 'Fix', 'newsdesk-ai' ); ?></th>
		</tr></thead>
		<tbody>
		<?php foreach ( $view['checks'] as $c ) : ?>
			<tr>
				<td style="font-size:18px;text-align:center"><?php echo esc_html( $icon[ $c['status'] ] ?? '' ); ?></td>
				<td><strong style="color:<?php echo esc_attr( $color[ $c['status'] ] ?? '#000' ); ?>"><?php echo esc_html( $c['title'] ); ?></strong></td>
				<td data-label="<?php esc_attr_e( 'Status', 'newsdesk-ai' ); ?>"><?php echo esc_html( $c['detail'] ); ?></td>
				<td data-label="<?php esc_attr_e( 'Fix', 'newsdesk-ai' ); ?>"><?php echo '' !== $c['fix'] ? esc_html( $c['fix'] ) : '—'; ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table></div>

	<h2 style="margin-top:28px"><?php esc_html_e( 'Ten-minute setup checklist', 'newsdesk-ai' ); ?></h2>
	<ol style="max-width:900px;line-height:1.9">
		<li><?php esc_html_e( 'Settings → enter an API key (GapGPT or OpenAI) → make “Test AI connection” go green.', 'newsdesk-ai' ); ?></li>
		<li><?php esc_html_e( 'Sources → add at least three RSS feeds on the same topic (three tech news sites, for example).', 'newsdesk-ai' ); ?></li>
		<li><?php esc_html_e( 'Settings → turn on “Quick-start mode”.', 'newsdesk-ai' ); ?></li>
		<li><?php esc_html_e( 'This page → “Run now” → wait 2 to 5 minutes.', 'newsdesk-ai' ); ?></li>
		<li><?php esc_html_e( 'Draft review → preview → approve → publish from the WordPress editor.', 'newsdesk-ai' ); ?></li>
		<li><?php esc_html_e( 'For automatic runs: install Action Scheduler and set up a real system cron for wp-cron.php.', 'newsdesk-ai' ); ?></li>
	</ol>
<?php \NewsDesk\AI\Admin\AdminView::footer(); ?>
</div>
