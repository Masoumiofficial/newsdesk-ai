<?php
/**
 * §42 Scheduler status.
 *
 * @package NewsDesk\AI\Admin\Page
 */

namespace NewsDesk\AI\Admin\Page;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Admin\AdminView;
use NewsDesk\AI\Application\NewsroomSettings;
use NewsDesk\AI\Infrastructure\Scheduler\CronScheduler;
use NewsDesk\AI\Support\Container;

final class SchedulerPage {

	/** @var Container */
	private $container;

	public function __construct( Container $container ) {
		$this->container = $container;
	}

	public function render(): void {
		if ( ! current_user_can( \NewsDesk\AI\Admin\Menu::CAP ) ) {
			wp_die( esc_html__( 'Access denied.', 'newsdesk-ai' ) );
		}
		$scheduler = $this->container->get( CronScheduler::class );
		$settings  = $this->container->get( NewsroomSettings::class );
		$state     = $scheduler->getState();
		AdminView::render( 'scheduler', array(
			'slots'     => $settings->scheduleTimes(),
			'nextRun'   => $scheduler->getNextRun(),
			'tz'        => $scheduler->getTimezoneName(),
			'history'   => array_reverse( isset( $state['history'] ) ? (array) $state['history'] : array() ),
			'tickHook'  => CronScheduler::HOOK,
			'interval'  => CronScheduler::INTERVAL,
		) );
	}
}
