<?php
/**
 * A-11 — Images page.
 *
 * The spec makes the image phase PLAN-ONLY, so this page is mostly an honest
 * statement of that: it shows the planning rules the pipeline applies and
 * whether the optional generation extra is switched on. It deliberately does
 * not offer a "generate now" button — that would reintroduce the automatic
 * image creation the spec rules out.
 *
 * @package NewsDesk\AI\Admin\Page
 */

namespace NewsDesk\AI\Admin\Page;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Admin\AdminView;
use NewsDesk\AI\Admin\Menu;
use NewsDesk\AI\Application\Images\ImagePlanService;
use NewsDesk\AI\Application\NewsroomSettings;
use NewsDesk\AI\Support\Container;

final class ImagesPage {

	/** @var NewsroomSettings */
	private $settings;

	public function __construct( Container $container ) {
		$this->settings = $container->get( NewsroomSettings::class );
	}

	public function render(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			wp_die( esc_html__( 'Access denied.', 'newsdesk-ai' ) );
		}

		AdminView::render(
			'images',
			array(
				'generate_enabled' => $this->settings->imageGenerateEnabled(),
				'model'            => $this->settings->imageModel(),
				'size'             => $this->settings->imageSize(),
				'aspect'           => ImagePlanService::ASPECT,
				'min_width'        => ImagePlanService::MIN_WIDTH,
				'concepts'         => ImagePlanService::CONCEPTS,
			)
		);
	}
}
