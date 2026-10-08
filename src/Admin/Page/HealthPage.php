<?php
/**
 * v1.3 — System health / "why no output yet?" page.
 *
 * @package NewsDesk\AI\Admin\Page
 */

namespace NewsDesk\AI\Admin\Page;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Admin\AdminView;
use NewsDesk\AI\Admin\Menu;
use NewsDesk\AI\Application\HealthCheckService;
use NewsDesk\AI\Support\Container;

final class HealthPage {

	/** @var Container */
	private $container;

	public function __construct( Container $container ) {
		$this->container = $container;
	}

	public function render(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			wp_die( esc_html__( 'Access denied.', 'newsdesk-ai' ) );
		}
		$checks = $this->container->get( HealthCheckService::class )->run();
		$worst  = HealthCheckService::OK;
		foreach ( $checks as $c ) {
			if ( HealthCheckService::FAIL === $c['status'] ) {
				$worst = HealthCheckService::FAIL;
				break;
			}
			if ( HealthCheckService::WARN === $c['status'] ) {
				$worst = HealthCheckService::WARN;
			}
		}
		AdminView::render( 'health', array(
			'checks' => $checks,
			'worst'  => $worst,
			'job'    => isset( $_GET['job'] ) ? absint( $_GET['job'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		) );
	}
}
