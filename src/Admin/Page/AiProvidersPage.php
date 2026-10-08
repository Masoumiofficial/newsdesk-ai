<?php
/**
 * A-11 — AI providers page.
 *
 * Shows the real provider chain: which are configured, in what fallback
 * order, and what the budget looks like. Nothing here is simulated — a
 * provider is listed as available only if its own isConfigured() says so,
 * because a page that claims a provider works when it does not is worse than
 * no page at all.
 *
 * API keys are never rendered, not even masked from the stored value.
 *
 * @package NewsDesk\AI\Admin\Page
 */

namespace NewsDesk\AI\Admin\Page;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Admin\AdminView;
use NewsDesk\AI\Admin\Menu;
use NewsDesk\AI\Application\Ai\ProviderRegistry;
use NewsDesk\AI\Application\NewsroomSettings;
use NewsDesk\AI\Support\Container;

final class AiProvidersPage {

	/** @var ProviderRegistry */
	private $registry;
	/** @var NewsroomSettings */
	private $settings;

	public function __construct( Container $container ) {
		$this->registry = $container->get( ProviderRegistry::class );
		$this->settings = $container->get( NewsroomSettings::class );
	}

	public function render(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			wp_die( esc_html__( 'Access denied.', 'newsdesk-ai' ) );
		}

		$providers = array();
		$order     = 0;
		foreach ( $this->registry->ordered() as $provider ) {
			$order++;
			$configured  = false;
			$error       = '';
			try {
				$configured = $provider->isConfigured();
			} catch ( \Throwable $e ) {
				// A provider that throws while reporting its own state is
				// unavailable, and the operator should see why.
				$error = get_class( $e );
			}
			$providers[] = array(
				'order'      => $order,
				'id'         => $provider->id(),
				'configured' => $configured,
				'error'      => $error,
				'role'       => 1 === $order ? __( 'Primary', 'newsdesk-ai' ) : __( 'Fallback', 'newsdesk-ai' ),
			);
		}

		AdminView::render(
			'ai-providers',
			array(
				'providers'      => $providers,
				'has_configured' => $this->registry->hasConfigured(),
				'budget'         => array(
					'max_tokens'  => $this->settings->aiMaxTokens(),
					'per_job'     => $this->settings->aiBudgetPerJob(),
					'timeout'     => $this->settings->aiTimeout(),
				),
				'fact_check'     => $this->settings->aiFactCheckEnabled(),
			)
		);
	}
}
