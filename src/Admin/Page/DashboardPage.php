<?php
/**
 * §46 Dashboard.
 *
 * @package NewsDesk\AI\Admin\Page
 */

namespace NewsDesk\AI\Admin\Page;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Admin\AdminView;
use NewsDesk\AI\Application\Contracts\JobEventRepositoryInterface;
use NewsDesk\AI\Application\Contracts\JobRepositoryInterface;
use NewsDesk\AI\Application\Contracts\NewsItemRepositoryInterface;
use NewsDesk\AI\Application\Contracts\SourceRepositoryInterface;
use NewsDesk\AI\Application\Contracts\ResearchRepositoryInterface;
use NewsDesk\AI\Application\Contracts\StoryRepositoryInterface;
use NewsDesk\AI\Infrastructure\Locks\LockManager;
use NewsDesk\AI\Infrastructure\Scheduler\CronScheduler;
use NewsDesk\AI\Support\Container;
use NewsDesk\AI\Support\Time;

final class DashboardPage {

	/** @var Container */
	private $container;

	public function __construct( Container $container ) {
		$this->container = $container;
	}

	public function render(): void {
		if ( ! current_user_can( \NewsDesk\AI\Admin\Menu::CAP ) ) {
			wp_die( esc_html__( 'Access denied.', 'newsdesk-ai' ) );
		}
		$stats = array(
			'sources'       => $this->container->get( SourceRepositoryInterface::class )->countAll(),
			'sourcesActive' => $this->container->get( SourceRepositoryInterface::class )->countActive(),
			'items'         => $this->container->get( NewsItemRepositoryInterface::class )->countAll(),
			'duplicates'    => $this->container->get( NewsItemRepositoryInterface::class )->countDuplicates(),
			'stories'       => $this->container->get( StoryRepositoryInterface::class )->countAll(),
			'storiesSelected' => $this->container->get( StoryRepositoryInterface::class )->countByStatus( 'selected' ),
			'claims'        => $this->container->get( ResearchRepositoryInterface::class )->countAllClaims(),
			'drafts'        => $this->container->get( StoryRepositoryInterface::class )->countByContentStatus( 'drafted' ),
			'review'        => $this->container->get( StoryRepositoryInterface::class )->countByContentStatus( 'needs_review' ),
			'images'        => $this->container->get( \NewsDesk\AI\Application\Contracts\GeneratedImageRepositoryInterface::class )->countByStatus( 'uploaded' ),
			'imagesFailed'  => $this->container->get( \NewsDesk\AI\Application\Contracts\GeneratedImageRepositoryInterface::class )->countByStatus( 'failed' ),
			'jobsQueued'    => $this->container->get( JobRepositoryInterface::class )->countByStatus( 'QUEUED' ),
			'jobsFailed'    => $this->container->get( JobRepositoryInterface::class )->countByStatus( 'FAILED' ),
			'jobsDone'      => $this->container->get( JobRepositoryInterface::class )->countByStatus( 'COMPLETED' ),
		);

		$recentJobs  = $this->container->get( JobRepositoryInterface::class )->findRecent( 8 );
		$locks       = $this->container->get( LockManager::class );
		$globalLock  = $locks->get( \NewsDesk\AI\Domain\Entity\Lock::GLOBAL );
		$scheduler   = $this->container->get( CronScheduler::class );
		$nextRun     = $scheduler->getNextRun();
		$history     = $scheduler->getState();
		$history     = isset( $history['history'] ) ? (array) $history['history'] : array();

		$data = array(
			'stats'      => $stats,
			'recentJobs' => $recentJobs,
			'globalLock' => $globalLock,
			'nextRun'    => $nextRun,
			'history'    => array_reverse( $history ),
			'tz'         => $scheduler->getTimezoneName(),
			'msg'        => isset( $_GET['nd_msg'] ) ? sanitize_key( wp_unslash( $_GET['nd_msg'] ) ) : '', // phpcs:ignore
		);
		// v1.3: surface the first blocking problem right on the dashboard.
		$data['health_problem'] = null;
		try {
			foreach ( $this->container->get( \NewsDesk\AI\Application\HealthCheckService::class )->run() as $check ) {
				if ( 'ok' !== $check['status'] ) {
					$data['health_problem'] = $check;
					break;
				}
			}
		} catch ( \Throwable $e ) { // never let diagnostics break the dashboard.
			$data['health_problem'] = null;
		}
		AdminView::render( 'dashboard', $data );
	}
}
