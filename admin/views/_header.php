<?php
/**
 * Shared page chrome: brand hero + section nav. Data: title, subtitle.
 *
 * @package NewsDesk\AI\Admin\Views
 */

defined( 'ABSPATH' ) || exit;
/** @var array $view */
$current = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'newsdesk-ai'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$nav = array(
	'newsdesk-ai' => array( 'dashboard', __( 'Dashboard', 'newsdesk-ai' ) ),
	'nd-drafts'           => array( 'edit-page', __( 'Review', 'newsdesk-ai' ) ),
	'nd-stories'          => array( 'networking', __( 'Stories', 'newsdesk-ai' ) ),
	'nd-sources'          => array( 'rss', __( 'Sources', 'newsdesk-ai' ) ),
	'nd-scheduler'        => array( 'clock', __( 'Schedule', 'newsdesk-ai' ) ),
	'nd-logs'             => array( 'list-view', __( 'Logs', 'newsdesk-ai' ) ),
	'nd-health'           => array( 'heart', __( 'Health', 'newsdesk-ai' ) ),
	'nd-settings'         => array( 'admin-generic', __( 'Settings', 'newsdesk-ai' ) ),
);
$pending = (int) apply_filters( 'newsdesk_newsroom_review_pending_count', 0 ); // phpcs:ignore
?>
<div class="nd-hero">
	<div class="nd-hero-row">
		<div class="nd-logo" aria-hidden="true">
			<svg viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
				<path d="M6 8h20v3H6zM6 14.5h20v3H6zM6 21h13v3H6z" fill="#fff" opacity=".95"/>
				<circle cx="24.5" cy="22.5" r="3.5" fill="#fff"/>
				<path d="M24.5 20.2v2.3l1.6 1.2" stroke="#5535CF" stroke-width="1.3" stroke-linecap="round"/>
			</svg>
		</div>
		<div>
			<h1><?php echo esc_html( $view['title'] ); ?></h1>
			<?php if ( '' !== $view['subtitle'] ) : ?><p class="nd-sub"><?php echo esc_html( $view['subtitle'] ); ?></p><?php endif; ?>
		</div>
		<span class="nd-ver"><?php esc_html_e( 'NewsDesk AI', 'newsdesk-ai' ); ?> · <b>v<?php echo esc_html( NEWSDESK_VERSION ); ?></b></span>
	</div>
	<nav class="nd-nav" aria-label="<?php esc_attr_e( 'Plugin sections', 'newsdesk-ai' ); ?>">
		<?php foreach ( $nav as $slug => $item ) : ?>
			<a href="<?php echo esc_url( add_query_arg( array( 'page' => $slug ), admin_url( 'admin.php' ) ) ); ?>" class="<?php echo $slug === $current ? 'is-active' : ''; ?>">
				<span class="dashicons dashicons-<?php echo esc_attr( $item[0] ); ?>"></span>
				<span class="nd-nav-label"><?php echo esc_html( $item[1] ); ?></span>
				<?php if ( 'nd-drafts' === $slug && $pending > 0 ) : ?><span class="nd-pill"><?php echo (int) $pending; ?></span><?php endif; ?>
			</a>
		<?php endforeach; ?>
	</nav>
</div>
<div class="nd-body">
