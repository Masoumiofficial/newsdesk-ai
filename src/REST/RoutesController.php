<?php
/**
 * REST routes (§54): /wp-json/nd-newsroom/v1/...
 *
 * @package NewsDesk\AI\REST
 */

namespace NewsDesk\AI\REST;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Support\Container;

final class RoutesController {

	const NS = 'nd-newsroom/v1';

	/** @var Container */
	private $container;

	public function __construct( Container $container ) {
		$this->container = $container;
	}

	public function register(): void {
		register_rest_route(
			self::NS,
			'/status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'status' ),
				'permission_callback' => array( $this, 'canRead' ),
				'args'                => array(),
			)
		);
		register_rest_route(
			self::NS,
			'/sources',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'sources' ),
				'permission_callback' => array( $this, 'canRead' ),
				'args'                => array(
					'page'     => array(
						'type'              => 'integer',
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 50,
						'maximum'           => 100,
						'sanitize_callback' => 'absint',
					),
					'status'   => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	public function canRead(): bool {
		return current_user_can( \NewsDesk\AI\Admin\Menu::CAP );
	}

	/**
	 * GET /status — health/observability snapshot.
	 */
	public function status( \WP_REST_Request $request ): \WP_REST_Response {
		$jobs    = $this->container->get( \NewsDesk\AI\Application\Contracts\JobRepositoryInterface::class );
		$sources = $this->container->get( \NewsDesk\AI\Application\Contracts\SourceRepositoryInterface::class );
		$items   = $this->container->get( \NewsDesk\AI\Application\Contracts\NewsItemRepositoryInterface::class );
		$stories = $this->container->get( \NewsDesk\AI\Application\Contracts\StoryRepositoryInterface::class );
		$sched   = $this->container->get( \NewsDesk\AI\Infrastructure\Scheduler\CronScheduler::class );
		$locks   = $this->container->get( \NewsDesk\AI\Infrastructure\Locks\LockManager::class );

		$nextRun = $sched->getNextRun();
		return rest_ensure_response( array(
			'plugin' => array(
				'version'      => NEWSDESK_VERSION,
				'db_version'   => get_option( 'newsdesk_db_version', '0' ),
				'auto_publish' => NEWSDESK_AUTO_PUBLISH,
			),
			'counts' => array(
				'sources'        => $sources->countAll(),
				'sources_active' => $sources->countActive(),
				'news_items'     => $items->countAll(),
				'duplicates'     => $items->countDuplicates(),
				'stories'        => $stories->countAll(),
				'stories_selected' => $stories->countByStatus( 'selected' ),
				'claims'         => $this->container->get( \NewsDesk\AI\Application\Contracts\ResearchRepositoryInterface::class )->countAllClaims(),
				'ai_usage_rows'  => $this->container->get( \NewsDesk\AI\Application\Contracts\AiUsageRepositoryInterface::class )->countAll(),
				'content_versions'  => $this->container->get( \NewsDesk\AI\Application\Contracts\ContentRepositoryInterface::class )->countAll(),
				'stories_drafted'   => $stories->countByContentStatus( 'drafted' ),
				'stories_review'    => $stories->countByContentStatus( 'needs_review' ),
				'images_generated'  => $this->container->get( \NewsDesk\AI\Application\Contracts\GeneratedImageRepositoryInterface::class )->countByStatus( 'uploaded' ),
				'images_failed'     => $this->container->get( \NewsDesk\AI\Application\Contracts\GeneratedImageRepositoryInterface::class )->countByStatus( 'failed' ),
				'jobs_completed' => $jobs->countByStatus( 'COMPLETED' ),
				'jobs_failed'    => $jobs->countByStatus( 'FAILED' ),
				'jobs_queued'    => $jobs->countByStatus( 'QUEUED' ),
			),
			'scheduler' => array(
				'timezone' => $sched->getTimezoneName(),
				'next_run' => $nextRun ? $nextRun->format( 'c' ) : null,
			),
			'lock' => array(
				'global_held' => $locks->isHeld( \NewsDesk\AI\Domain\Entity\Lock::GLOBAL ),
				'max_ttl'     => 1800,
			),
			'time' => time(),
		) );
	}

	/**
	 * GET /sources — paginated, validated, secret-free (§54).
	 */
	public function sources( \WP_REST_Request $request ): \WP_REST_Response {
		$page = max( 1, (int) $request->get_param( 'page' ) );
		$per  = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ) );
		$data = $this->container->get( \NewsDesk\AI\Application\Contracts\SourceRepositoryInterface::class )
			->paginate( array( 'status' => (string) $request->get_param( 'status' ) ), $page, $per );

		$out = array();
		foreach ( $data['items'] as $source ) {
			// Never expose settings (adapter config) and never secrets.
			$out[] = array(
				'id'              => $source->id,
				'name'            => $source->name,
				'type'            => $source->type,
				'url'             => $source->url,
				'feed_url'        => $source->feedUrl,
				'language'        => $source->language,
				'category'        => $source->category,
				'priority'        => $source->priority,
				'trust_score'     => $source->trustScore,
				'trust_override'  => $source->trustOverride,
				'active'          => $source->active,
				'status'          => $source->status,
				'last_success_at' => $source->lastSuccessAt ? $source->lastSuccessAt->format( 'c' ) : null,
				'last_error_at'   => $source->lastErrorAt ? $source->lastErrorAt->format( 'c' ) : null,
			);
		}
		return rest_ensure_response( array(
			'items'    => $out,
			'total'    => $data['total'],
			'page'     => $page,
			'per_page' => $per,
		) );
	}
}
