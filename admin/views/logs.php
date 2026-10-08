<?php
/**
 * Logs view — data: items, total, page, perPage, filters.
 *
 * @package NewsDesk\AI\Admin\Views
 */

defined( 'ABSPATH' ) || exit;
/** @var array $view */
?>
<div class="wrap nd-wrap">
	<?php \NewsDesk\AI\Admin\AdminView::header( __( 'Logs', 'newsdesk-ai' ), __( 'Every phase event, in full detail', 'newsdesk-ai' ) ); ?>
	<?php include __DIR__ . '/_notice.php'; ?>
	<p class="description"><?php esc_html_e( 'API keys are never written to the logs (§49).', 'newsdesk-ai' ); ?></p>

	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="nd-filter">
		<input type="hidden" name="page" value="nd-logs" />
		<select name="level">
			<option value=""><?php esc_html_e( 'All levels', 'newsdesk-ai' ); ?></option>
			<?php foreach ( array_keys( \NewsDesk\AI\Logging\Logger::LEVELS ) as $lvl ) : ?>
				<option value="<?php echo esc_attr( $lvl ); ?>" <?php selected( $view['filters']['level'], $lvl ); ?>><?php echo esc_html( $lvl ); ?></option>
			<?php endforeach; ?>
		</select>
		<input type="number" name="job_id" placeholder="<?php esc_attr_e( 'job_id', 'newsdesk-ai' ); ?>" value="<?php echo $view['filters']['job_id'] ? (int) $view['filters']['job_id'] : ''; ?>" />
		<input type="text" name="component" placeholder="<?php esc_attr_e( 'component', 'newsdesk-ai' ); ?>" value="<?php echo esc_attr( $view['filters']['component'] ); ?>" />
		<button class="button"><?php esc_html_e( 'Filter', 'newsdesk-ai' ); ?></button>
	</form>

	<?php // v2.0 (B-8): the log table could only grow from the UI until now. ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nd-filter"
		onsubmit="return confirm('<?php echo esc_js( __( 'Clearing the logs cannot be undone. Continue?', 'newsdesk-ai' ) ); ?>');">
		<input type="hidden" name="action" value="newsdesk_newsroom_logs_clear" />
		<?php wp_nonce_field( 'newsdesk_newsroom_logs_clear' ); ?>
		<select name="level">
			<option value=""><?php esc_html_e( 'Clear all levels', 'newsdesk-ai' ); ?></option>
			<?php foreach ( array_keys( \NewsDesk\AI\Logging\Logger::LEVELS ) as $lvl ) : ?>
				<option value="<?php echo esc_attr( $lvl ); ?>">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: log level */
							__( 'Level %s only', 'newsdesk-ai' ),
							$lvl
						)
					);
					?>
				</option>
			<?php endforeach; ?>
		</select>
		<button class="button button-link-delete" type="submit"><?php esc_html_e( 'Clear logs', 'newsdesk-ai' ); ?></button>
	</form>

	<div class="nd-table-wrap"><table class="widefat striped">
		<thead><tr>
			<th><?php esc_html_e( 'Time', 'newsdesk-ai' ); ?></th>
			<th><?php esc_html_e( 'Level', 'newsdesk-ai' ); ?></th>
			<th><?php esc_html_e( 'Component', 'newsdesk-ai' ); ?></th>
			<th><?php esc_html_e( 'Event', 'newsdesk-ai' ); ?></th>
			<th><?php esc_html_e( 'Job / correlation', 'newsdesk-ai' ); ?></th>
			<th><?php esc_html_e( 'Message', 'newsdesk-ai' ); ?></th>
		</tr></thead>
		<tbody>
		<?php if ( empty( $view['items'] ) ) : ?>
			<tr><td colspan="6"><?php \NewsDesk\AI\Admin\AdminView::empty( __( 'No log entries found', 'newsdesk-ai' ), '', '📋' ); ?></td></tr>
		<?php else : ?>
			<?php foreach ( $view['items'] as $log ) : ?>
				<tr>
					<td><?php echo $log->createdAt ? esc_html( $log->createdAt->format( 'Y-m-d H:i:s' ) ) : '—'; ?></td>
					<td><?php echo \NewsDesk\AI\Admin\AdminView::badge( strtolower( (string) $log->level ), strtoupper( $log->level ) ); // phpcs:ignore ?></td>
					<td><?php echo esc_html( $log->component ); ?></td>
					<td><code><?php echo esc_html( $log->event ); ?></code></td>
					<td><?php echo $log->jobId ? '#' . (int) $log->jobId : '—'; ?> <code><?php echo esc_html( $log->correlationId ); ?></code></td>
					<td><?php echo esc_html( $log->message ); ?>
						<?php if ( ! empty( $log->context ) ) : ?>
							<details style="margin-top:4px"><summary><?php esc_html_e( 'Details', 'newsdesk-ai' ); ?></summary>
							<pre style="white-space:pre-wrap;direction:ltr;text-align:left;max-width:600px;overflow:auto"><?php echo esc_html( wp_json_encode( $log->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?></pre></details>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>
	</table></div>
<?php \NewsDesk\AI\Admin\AdminView::footer(); ?>
</div>
