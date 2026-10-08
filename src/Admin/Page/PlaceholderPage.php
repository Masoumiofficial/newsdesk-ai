<?php
/**
 * Honest placeholder for future-phase admin pages.
 *
 * @package NewsDesk\AI\Admin\Page
 */

namespace NewsDesk\AI\Admin\Page;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Admin\AdminView;

final class PlaceholderPage {

	/** @var array{0:string,1:string} */
	private $args;

	/**
	 * @param array{0:string,1:string} $args [title, phase note].
	 */
	public function __construct( array $args = array( '', '' ) ) {
		$this->args = $args;
	}

	public function render(): void {
		if ( ! current_user_can( \NewsDesk\AI\Admin\Menu::CAP ) ) {
			wp_die( esc_html__( 'Access denied.', 'newsdesk-ai' ) );
		}
		AdminView::render( 'placeholder', array(
			'title' => $this->args[0],
			'note'  => $this->args[1],
		) );
	}
}
