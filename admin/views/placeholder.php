<?php
/**
 * Honest placeholder for future phases.
 *
 * @package NewsDesk\AI\Admin\Views
 */

defined( 'ABSPATH' ) || exit;
/** @var array $view */
?>
<div class="wrap nd-wrap">
	<?php \NewsDesk\AI\Admin\AdminView::header( (string) $view['title'] ); ?>
	<div class="nd-panel"><p style="margin:0">
		<?php esc_html_e( 'This screen is implemented in a later phase:', 'newsdesk-ai' ); ?>
		<strong><?php echo esc_html( $view['note'] ); ?></strong>
	</p></div>
<?php \NewsDesk\AI\Admin\AdminView::footer(); ?>
</div>
