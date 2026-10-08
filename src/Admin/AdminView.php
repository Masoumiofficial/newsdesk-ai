<?php
/**
 * Minimal template loader for admin views (escaping stays in the templates).
 *
 * @package NewsDesk\AI\Admin
 */

namespace NewsDesk\AI\Admin;

defined( 'ABSPATH' ) || exit;

final class AdminView {

	/**
	 * @param string $name template name (dashboard, sources, ...).
	 * @param array  $data Escaped-or-raw data; templates must escape.
	 */
	public static function render( string $name, array $data = array() ): void {
		$file = NEWSDESK_DIR . 'admin/views/' . basename( $name ) . '.php';
		if ( ! is_readable( $file ) ) {
			echo '<div class="error"><p>' . esc_html( sprintf( __( 'Template not found: %s', 'newsdesk-ai' ), $name ) ) . '</p></div>';
			return;
		}
		$view = $data;
		include $file; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingCustomFunction
	}

	/**
	 * Page chrome (hero + nav). Call at the top of every view instead of <h1>.
	 *
	 * @param string $title    Page title.
	 * @param string $subtitle Optional one-liner under the title.
	 */
	public static function header( string $title, string $subtitle = '' ): void {
		$view = array( 'title' => $title, 'subtitle' => $subtitle );
		include NEWSDESK_DIR . 'admin/views/_header.php'; // phpcs:ignore
	}

	public static function footer(): void {
		include NEWSDESK_DIR . 'admin/views/_footer.php'; // phpcs:ignore
	}

	/** Status pill. Unknown statuses get a neutral style. Returns escaped HTML. */
	public static function badge( string $status, string $label = '' ): string {
		$slug = strtolower( preg_replace( '/[^a-z0-9_]+/i', '_', $status ) );
		return '<span class="nd-badge nd-badge-' . esc_attr( $slug ) . '">' . esc_html( '' !== $label ? $label : $status ) . '</span>';
	}

	/** 0–100 score with a tiny bar. Returns escaped HTML. */
	public static function score( float $value, float $max = 100 ): string {
		$pct = $max > 0 ? max( 0, min( 100, $value / $max * 100 ) ) : 0;
		return '<span class="nd-score"><span class="nd-bar"><i style="width:' . (int) $pct . '%"></i></span><b>' . esc_html( number_format_i18n( $value, 1 ) ) . '</b></span>';
	}

	/** Friendly empty state. */
	public static function empty( string $title, string $hint = '', string $icon = '📭' ): void {
		echo '<div class="nd-empty"><div class="nd-empty-icon">' . esc_html( $icon ) . '</div><strong>' . esc_html( $title ) . '</strong>' . ( '' !== $hint ? '<span>' . esc_html( $hint ) . '</span>' : '' ) . '</div>';
	}

	public static function notice( string $type, string $message ): void {
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}
}
