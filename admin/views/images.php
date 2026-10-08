<?php
/**
 * A-11 — Images view. data: generate_enabled, model, size, aspect, min_width, concepts
 *
 * @package NewsDesk\AI\Admin\Views
 */

defined( 'ABSPATH' ) || exit;
/** @var array $view */
?>
<div class="wrap nd-wrap">
	<?php \NewsDesk\AI\Admin\AdminView::header( __( 'Images', 'newsdesk-ai' ), __( 'Image phase: plan only', 'newsdesk-ai' ) ); ?>
	<?php include __DIR__ . '/_notice.php'; ?>

	<p class="description">
		<?php esc_html_e( 'By design this phase is plan-only: for each draft the plugin proposes alt text, a caption, three visual concepts and stock search terms, and the final choice is the editor\'s. No image is generated or uploaded automatically.', 'newsdesk-ai' ); ?>
	</p>

	<table class="widefat striped nd-table">
		<tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Suggested aspect ratio', 'newsdesk-ai' ); ?></th>
				<td><code><?php echo esc_html( $view['aspect'] ); ?></code></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Minimum width', 'newsdesk-ai' ); ?></th>
				<td><?php echo (int) $view['min_width']; ?>px</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Number of visual concepts', 'newsdesk-ai' ); ?></th>
				<td><?php echo (int) $view['concepts']; ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Actually generate images', 'newsdesk-ai' ); ?></th>
				<td>
					<?php if ( $view['generate_enabled'] ) : ?>
						<span class="nd-badge nd-badge-ok"><?php esc_html_e( 'Active', 'newsdesk-ai' ); ?></span>
						<div class="description">
							<?php
							printf(
								/* translators: 1: model name, 2: image size */
								esc_html__( 'Model: %1$s — size: %2$s. A generation failure never blocks the draft.', 'newsdesk-ai' ),
								esc_html( $view['model'] ),
								esc_html( $view['size'] )
							);
							?>
						</div>
					<?php else : ?>
						<span class="nd-badge nd-badge-off"><?php esc_html_e( 'Disabled (default)', 'newsdesk-ai' ); ?></span>
						<div class="description"><?php esc_html_e( 'An image plan is still produced for every draft; only the call to the generation service is skipped.', 'newsdesk-ai' ); ?></div>
					<?php endif; ?>
				</td>
			</tr>
		</tbody>
	</table>
</div>
