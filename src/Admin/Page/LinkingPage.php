<?php
/**
 * A-11 — Linking page.
 *
 * Internal suggestions and external attribution are generated during content
 * production, but until now there was nowhere to inspect the rules or confirm
 * that source attribution is actually being recorded. This page states the
 * active policy and shows the most recent external links the pipeline
 * attached to stories.
 *
 * @package NewsDesk\AI\Admin\Page
 */

namespace NewsDesk\AI\Admin\Page;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Admin\AdminView;
use NewsDesk\AI\Admin\Menu;
use NewsDesk\AI\Application\Contracts\StoryRepositoryInterface;
use NewsDesk\AI\Application\Contracts\LinkRepositoryInterface;
use NewsDesk\AI\Application\Linking\InternalLinkEngine;
use NewsDesk\AI\Application\NewsroomSettings;
use NewsDesk\AI\Support\Container;

final class LinkingPage {

	/** How many recent stories to show attribution for. */
	public const RECENT_STORIES = 10;

	/** @var LinkRepositoryInterface */
	private $links;
	/** @var StoryRepositoryInterface */
	private $stories;
	/** @var NewsroomSettings */
	private $settings;

	public function __construct( Container $container ) {
		$this->links    = $container->get( LinkRepositoryInterface::class );
		$this->stories  = $container->get( StoryRepositoryInterface::class );
		$this->settings = $container->get( NewsroomSettings::class );
	}

	public function render(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			wp_die( esc_html__( 'Access denied.', 'newsdesk-ai' ) );
		}

		$recent = array();
		try {
			$page = $this->stories->paginate( array(), 1, self::RECENT_STORIES );
			foreach ( $page['items'] as $story ) {
				$external = $this->links->externalForStory( (int) $story->storyId );
				$recent[] = array(
					'story_id' => (int) $story->storyId,
					'title'    => (string) $story->title,
					'external' => $external,
				);
			}
		} catch ( \Throwable $e ) {
			$recent = array();
		}

		AdminView::render(
			'linking',
			array(
				'include_links' => $this->settings->contentIncludeLinks(),
				'scan_pool'     => InternalLinkEngine::DEFAULT_SCAN_POOL,
				'recent'        => $recent,
			)
		);
	}
}
