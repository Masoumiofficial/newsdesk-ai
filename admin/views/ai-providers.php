<?php
/**
 * A-11 — AI providers view.
 *
 * data: providers, has_configured, budget, fact_check
 *
 * @package NewsDesk\AI\Admin\Views
 */

defined( 'ABSPATH' ) || exit;
/** @var array $view */
?>
<div class="wrap nd-wrap">
	<?php \NewsDesk\AI\Admin\AdminView::header( __( 'AI providers', 'newsdesk-ai' ), __( 'Provider chain and budget usage', 'newsdesk-ai' ) ); ?>
	<?php include __DIR__ . '/_notice.php'; ?>

	<p class="description">
		<?php esc_html_e( 'The order below is the real fallback order: if the first provider fails with a retryable error, the next is tried. An authentication or budget error never triggers a fallback (§9.1).', 'newsdesk-ai' ); ?>
	</p>

	<?php if ( ! $view['has_configured'] ) : ?>
		<div class="notice notice-warning inline">
			<p><?php esc_html_e( 'No provider is configured. Until a valid key is saved, the content generation phase will not run and no draft will be created.', 'newsdesk-ai' ); ?></p>
		</div>
	<?php endif; ?>

	<table class="widefat striped nd-table">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Order', 'newsdesk-ai' ); ?></th>
				<th scope="col"><?php esc_html_e( 'ID', 'newsdesk-ai' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Role', 'newsdesk-ai' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'newsdesk-ai' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $view['providers'] as $nd_p ) : ?>
			<tr>
				<td><?php echo (int) $nd_p['order']; ?></td>
				<td><code><?php echo esc_html( $nd_p['id'] ); ?></code></td>
				<td><?php echo esc_html( $nd_p['role'] ); ?></td>
				<td>
					<?php if ( $nd_p['configured'] ) : ?>
						<span class="nd-badge nd-badge-ok"><?php esc_html_e( 'Configured', 'newsdesk-ai' ); ?></span>
					<?php else : ?>
						<span class="nd-badge nd-badge-off"><?php esc_html_e( 'Not configured', 'newsdesk-ai' ); ?></span>
					<?php endif; ?>
					<?php if ( '' !== $nd_p['error'] ) : ?>
						<div class="description"><?php echo esc_html( $nd_p['error'] ); ?></div>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		<?php if ( ! $view['providers'] ) : ?>
			<tr><td colspan="4"><?php esc_html_e( 'No provider is registered.', 'newsdesk-ai' ); ?></td></tr>
		<?php endif; ?>
		</tbody>
	</table>

	<h2><?php esc_html_e( 'Budget and limits', 'newsdesk-ai' ); ?></h2>
	<table class="widefat striped nd-table">
		<tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Maximum tokens per request', 'newsdesk-ai' ); ?></th>
				<td><?php echo (int) $view['budget']['max_tokens']; ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Token cap per job', 'newsdesk-ai' ); ?></th>
				<td>
					<?php if ( (int) $view['budget']['per_job'] > 0 ) : ?>
						<?php echo (int) $view['budget']['per_job']; ?>
					<?php else : ?>
						<?php esc_html_e( 'Disabled', 'newsdesk-ai' ); ?>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Response timeout (seconds)', 'newsdesk-ai' ); ?></th>
				<td><?php echo (int) $view['budget']['timeout']; ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'AI-assisted fact-checking', 'newsdesk-ai' ); ?></th>
				<td>
					<?php if ( $view['fact_check'] ) : ?>
						<?php esc_html_e( 'On — the verdict is recorded only; the rules still make the final call.', 'newsdesk-ai' ); ?>
					<?php else : ?>
						<?php esc_html_e( 'Disabled', 'newsdesk-ai' ); ?>
					<?php endif; ?>
				</td>
			</tr>
		</tbody>
	</table>

	<p class="description">
		<?php esc_html_e( 'API keys are never displayed on this page. Go to the Settings tab to change them.', 'newsdesk-ai' ); ?>
	</p>
</div>
