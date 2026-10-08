<?php
/**
 * Shared page footer.
 *
 * @package NewsDesk\AI\Admin\Views
 */

defined( 'ABSPATH' ) || exit;
?>
	<div class="nd-footer">
		<span><b><?php esc_html_e( 'NewsDesk AI', 'newsdesk-ai' ); ?></b> v<?php echo esc_html( NEWSDESK_VERSION ); ?></span>
		<span><?php esc_html_e( 'Quality over quantity · nothing is published without human approval', 'newsdesk-ai' ); ?></span>
		<span><?php esc_html_e( 'Time zone:', 'newsdesk-ai' ); ?> <code><?php echo esc_html( wp_timezone_string() ); ?></code></span>
		<span>
			<?php
			printf(
				/* translators: %s: the vendor name, linked to the vendor site. */
				esc_html__( 'Built by %s', 'newsdesk-ai' ),
				'<a href="' . esc_url( \NewsDesk\AI\Support\Branding::VENDOR_URL ) . '" target="_blank" rel="noopener noreferrer">'
					/* translators: the vendor name, as it should appear in this language. */
					. esc_html__( 'EtehadWP', 'newsdesk-ai' )
					. '</a>'
			); // phpcs:ignore WordPress.Security.EscapeOutput
			?>
			·
			<a href="<?php echo esc_url( \NewsDesk\AI\Support\Branding::DOCS_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Documentation', 'newsdesk-ai' ); ?></a>
			·
			<a href="<?php echo esc_url( \NewsDesk\AI\Support\Branding::SUPPORT_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Support', 'newsdesk-ai' ); ?></a>
		</span>
	</div>
</div>
