<?php
/**
 * Notification value object (§76): pure data, no I/O, no secrets ever.
 *
 * @package NewsDesk\AI\Application\Notifications
 */

namespace NewsDesk\AI\Application\Notifications;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Support\Time;

final class NotificationMessage {

	public const EVENT_CONTENT_READY = 'content_ready';
	public const EVENT_DIGEST        = 'digest';

	/** @var string one of self::EVENT_* */
	public $event;
	/** @var string */
	public $title;
	/** @var string */
	public $text;
	/** @var int|null */
	public $storyId;
	/** @var int|null */
	public $jobId;
	/** @var string */
	public $createdAt;

	public function __construct( string $event, string $title, string $text, ?int $storyId = null, ?int $jobId = null, ?string $createdAt = null ) {
		$this->event     = $event;
		$this->title     = $title;
		$this->text      = $text;
		$this->storyId   = $storyId;
		$this->jobId     = $jobId;
		$this->createdAt = $createdAt ?: Time::now()->format( 'c' );
	}

	public static function contentReady( string $title, string $text, int $jobId ): self {
		return new self( self::EVENT_CONTENT_READY, $title, $text, null, $jobId );
	}

	public static function digest( string $title, string $text ): self {
		return new self( self::EVENT_DIGEST, $title, $text );
	}
}
