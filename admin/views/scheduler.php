<?php
/**
 * Scheduler view — data: slots, nextRun, tz, history, tickHook, interval.
 *
 * @package NewsDesk\AI\Admin\Views
 */

defined( 'ABSPATH' ) || exit;
/** @var array $view */
?>
<div class="wrap nd-wrap">
	<?php \NewsDesk\AI\Admin\AdminView::header( __( 'Schedule', 'newsdesk-ai' ), __( 'Slots, next run and run history', 'newsdesk-ai' ) ); ?>

	<div class="nd-panel"><table class="widefat" style="box-shadow:none;border:0">
		<tr><th style="width:220px"><?php esc_html_e( 'Site time zone', 'newsdesk-ai' ); ?></th><td><code><?php echo esc_html( $view['tz'] ); ?></code></td></tr>
		<tr><th><?php esc_html_e( 'Configured slots', 'newsdesk-ai' ); ?></th><td><?php echo esc_html( implode( ' · ', $view['slots'] ) ); ?></td></tr>
		<tr><th><?php esc_html_e( 'Next run', 'newsdesk-ai' ); ?></th><td><strong><?php echo $view['nextRun'] ? esc_html( $view['nextRun']->format( 'Y-m-d H:i' ) ) : '—'; ?></strong></td></tr>
		<tr><th><?php esc_html_e( 'Trigger hook', 'newsdesk-ai' ); ?></th><td><code><?php echo esc_html( $view['tickHook'] ); ?></code> — <?php echo (int) $view['interval']; ?> ثانیه (تغییر slot در تنظیمات «زمان‌بندی») </td></tr>
	</table></div>

	<p class="description">
		<?php esc_html_e( 'WP-Cron is only the trigger; the heavy work runs in the background through Action Scheduler (§38, §56). For a real system cron, use WP-CLI (phase 7) or the wp-cron.php script.', 'newsdesk-ai' ); ?>
	</p>

	<h2><?php esc_html_e( 'Run history — scheduled, actual start, finish and delay (§42)', 'newsdesk-ai' ); ?></h2>
	<div class="nd-table-wrap"><table class="widefat striped">
		<thead><tr>
			<th><?php esc_html_e( 'Slot', 'newsdesk-ai' ); ?></th>
			<th><?php esc_html_e( 'Scheduled time', 'newsdesk-ai' ); ?></th>
			<th><?php esc_html_e( 'Actual start', 'newsdesk-ai' ); ?></th>
			<th><?php esc_html_e( 'Finished', 'newsdesk-ai' ); ?></th>
			<th><?php esc_html_e( 'Delay (seconds)', 'newsdesk-ai' ); ?></th>
			<th><?php esc_html_e( 'Status', 'newsdesk-ai' ); ?></th>
			<th><?php esc_html_e( 'Job', 'newsdesk-ai' ); ?></th>
		</tr></thead>
		<tbody>
		<?php if ( empty( $view['history'] ) ) : ?>
			<tr><td colspan="7"><?php \NewsDesk\AI\Admin\AdminView::empty( __( 'No run has been recorded yet', 'newsdesk-ai' ), '', '⏱️' ); ?></td></tr>
		<?php else : ?>
			<?php foreach ( $view['history'] as $h ) : ?>
				<tr>
					<td><?php echo esc_html( $h['slot'] ?? '—' ); ?></td>
					<td><?php echo esc_html( $h['scheduled'] ?? '—' ); ?></td>
					<td><?php echo esc_html( $h['started'] ?? '—' ); ?></td>
					<td><?php echo esc_html( $h['finished'] ?? '—' ); ?></td>
					<td><?php echo isset( $h['delay_sec'] ) ? esc_html( number_format_i18n( $h['delay_sec'] ) ) : '—'; ?></td>
					<td><?php echo isset( $h['status'] ) ? \NewsDesk\AI\Admin\AdminView::badge( strtolower( (string) $h['status'] ) ) : '—'; // phpcs:ignore ?></td>
					<td><?php echo isset( $h['job_id'] ) ? '#' . (int) $h['job_id'] : '—'; ?></td>
				</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>
	</table></div>
<?php \NewsDesk\AI\Admin\AdminView::footer(); ?>
</div>
