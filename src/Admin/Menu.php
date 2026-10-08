<?php
/**
 * Admin menu (§45): NewsDesk AI.
 *
 * @package NewsDesk\AI\Admin
 */

namespace NewsDesk\AI\Admin;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Admin\Page\DashboardPage;
use NewsDesk\AI\Admin\Page\DraftReviewPage;
use NewsDesk\AI\Admin\Page\LogsPage;
use NewsDesk\AI\Admin\Page\PlaceholderPage;
use NewsDesk\AI\Admin\Page\SchedulerPage;
use NewsDesk\AI\Admin\Page\StoriesPage;
use NewsDesk\AI\Admin\Page\SettingsPage;
use NewsDesk\AI\Admin\Page\SourcesPage;
use NewsDesk\AI\Application\NewsroomSettings;
use NewsDesk\AI\Support\Container;

final class Menu {

	public const CAP = 'manage_options';

	/** @var Container */
	private $container;
	/** @var string */
	private $cap;

	public function __construct( Container $container ) {
		$this->container = $container;
		$settings        = $container->get( NewsroomSettings::class );
		$this->cap       = apply_filters( 'newsdesk_newsroom_capability', (string) $settings->capability() ); // phpcs:ignore
		if ( ! current_user_can( $this->cap ) ) {
			$this->cap = self::CAP; // never widen below manage_options
		}
	}

	/** Load the design system only on our own screens. */
	public function enqueue( string $hook ): void {
		if ( false === strpos( $hook, 'newsdesk-ai' ) && false === strpos( $hook, 'nd-' ) ) {
			return;
		}
		wp_enqueue_style( 'dashicons' );
		wp_enqueue_style( 'nd-newsroom-admin', NEWSDESK_URL . 'admin/css/admin.css', array(), NEWSDESK_VERSION );
	}

	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) ); // phpcs:ignore
		add_filter( 'newsdesk_newsroom_review_pending_count', function ( $n ) { // phpcs:ignore
			try {
				return count( $this->container->get( \NewsDesk\AI\Application\Content\DraftReviewService::class )->pending() );
			} catch ( \Throwable $e ) {
				return (int) $n;
			}
		} );
		add_menu_page(
			__( 'NewsDesk AI', 'newsdesk-ai' ),
			__( 'NewsDesk AI', 'newsdesk-ai' ),
			$this->cap,
			'newsdesk-ai',
			array( $this->container->get( DashboardPage::class ), 'render' ),
			'dashicons-megaphone',
			26
		);
		$this->sub( 'newsdesk-ai', __( 'Dashboard', 'newsdesk-ai' ), DashboardPage::class );
		$this->sub( 'nd-news', __( 'News inbox', 'newsdesk-ai' ), \NewsDesk\AI\Admin\Page\NewsInboxPage::class );
		$this->sub( 'nd-stories', __( 'Stories', 'newsdesk-ai' ), StoriesPage::class );
		$this->sub( 'nd-drafts', __( 'Draft review', 'newsdesk-ai' ), \NewsDesk\AI\Admin\Page\DraftReviewPage::class );
		$this->sub( 'nd-sources', __( 'Sources', 'newsdesk-ai' ), SourcesPage::class );
		$this->sub( 'nd-ai', __( 'AI providers', 'newsdesk-ai' ), \NewsDesk\AI\Admin\Page\AiProvidersPage::class );
		$this->sub( 'nd-images', __( 'Images', 'newsdesk-ai' ), \NewsDesk\AI\Admin\Page\ImagesPage::class );
		$this->sub( 'nd-linking', __( 'Linking', 'newsdesk-ai' ), \NewsDesk\AI\Admin\Page\LinkingPage::class );
		$this->sub( 'nd-scheduler', __( 'Schedule', 'newsdesk-ai' ), SchedulerPage::class );
		$this->sub( 'nd-logs', __( 'Logs', 'newsdesk-ai' ), LogsPage::class );
		$this->sub( 'nd-health', __( 'System health', 'newsdesk-ai' ), \NewsDesk\AI\Admin\Page\HealthPage::class );
		$this->sub( 'nd-settings', __( 'Settings', 'newsdesk-ai' ), SettingsPage::class );
	}

	private function sub( string $slug, string $title, string $pageClass, array $args = array() ): void {
		if ( PlaceholderPage::class === $pageClass ) {
			// PlaceholderPage takes a plain args array, not container services.
			$page = new PlaceholderPage( $args );
		} else {
			$page = $this->container->get( $pageClass );
		}
		add_submenu_page(
			'newsdesk-ai',
			$title,
			$title,
			$this->cap,
			$slug,
			array( $page, 'render' )
		);
	}
}
