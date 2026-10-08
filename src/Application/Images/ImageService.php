<?php
/**
 * Phase 5 — ImageService (ARCHITECTURE.md §6.4): the optional image stage of the
 * content pipeline. §66/§33 discipline: EVERY failure mode inside is isolated
 * and recorded as a `failed` GeneratedImage row + `image_status=failed`
 * metadata; the job/article NEVER fails and never blocks on images.
 *
 * Chain: enabled? → prompt compose (§25) → provider (images/generations,
 * ARCHITECTURE.md §9.3) → §52-guarded download (SafeHttpClient: only http/https,
 * no private/metadata hosts, redirects re-validated, size cap) → magic-byte
 * validation (JPEG/PNG/WebP) → MediaLibrary import → GeneratedImage row.
 *
 * @package NewsDesk\AI\Application\Images
 */

namespace NewsDesk\AI\Application\Images;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Ai\AiGateway;
use NewsDesk\AI\Application\Contracts\GeneratedImageRepositoryInterface;
use NewsDesk\AI\Application\Contracts\HttpClientInterface;
use NewsDesk\AI\Application\Contracts\MediaLibraryInterface;
use NewsDesk\AI\Application\NewsroomSettings;
use NewsDesk\AI\Domain\Entity\GeneratedImage;
use NewsDesk\AI\Domain\Entity\Story;
use NewsDesk\AI\Logging\Contracts\LoggerInterface;
use NewsDesk\AI\Support\Http\HttpFetchException;
use NewsDesk\AI\Support\Time;

final class ImageService {

	/** Allowed image signatures: [magic-prefix, mime, extension]. */
	private const SIGNATURES = array(
		'jpeg' => array( "\xFF\xD8\xFF", 'image/jpeg', 'jpg' ),
		'png'  => array( "\x89PNG\r\n\x1a\n", 'image/png', 'png' ),
		'webp' => array( 'RIFF', 'image/webp', 'webp' ),
	);

	/** @var ImagePromptComposer */
	private $prompts;
	/** @var AiGateway */
	private $gateway;
	/** @var HttpClientInterface */
	private $http;
	/** @var MediaLibraryInterface */
	private $media;
	/** @var GeneratedImageRepositoryInterface */
	private $images;
	/** @var NewsroomSettings */
	private $settings;
	/** @var LoggerInterface */
	private $logger;

	public function __construct(
		ImagePromptComposer $prompts,
		AiGateway $gateway,
		HttpClientInterface $http,
		MediaLibraryInterface $media,
		GeneratedImageRepositoryInterface $images,
		NewsroomSettings $settings,
		LoggerInterface $logger
	) {
		$this->prompts  = $prompts;
		$this->gateway  = $gateway;
		$this->http     = $http;
		$this->media    = $media;
		$this->images   = $images;
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * @param Story $story
	 * @param array $plan ContentStrategy plan (title/angle/question)
	 * @return array{status: string, attachment_id?: int, error_code?: string, prompt_version?: string, provider?: string, model?: string}
	 */
	public function generate( Story $story, array $plan, int $jobId ): array {
		if ( ! $this->settings->imageGenerateEnabled() ) {
			return array( 'status' => 'skipped', 'error_code' => 'DISABLED' );
		}

		$title = (string) ( $plan['title'] ?? $story->headline ?? '' );
		$angle = (string) ( $plan['angle'] ?? '' );

		$composed = null;
		try {
			$composed = $this->prompts->compose( $story->langCode(), array( 'title' => $title, 'angle' => $angle ) );
			$response = $this->gateway->image( $composed['prompt'], $jobId, array( 'model' => $this->settings->imageModel() ) );
		} catch ( \Throwable $e ) {
			$code = method_exists( $e, 'codeName' ) ? $e->codeName() : 'UNEXPECTED_' . get_class( $e );
			$promptText = null !== $composed ? $composed['prompt'] : '';
			return $this->fail( $story, $jobId, $plan, $promptText, $code, '', null !== $composed ? $composed['prompt_version'] : '' );
		}

		if ( '' === $response->imageUrl ) {
			return $this->fail( $story, $jobId, $plan, $composed['prompt'], $response->errorCode ?: 'EMPTY_RESPONSE', $response->provider, $composed['prompt_version'] );
		}

		$binary = null;
		$mime   = '';
		try {
			// §52: SafeHttpClient validates scheme/host/DNS/redirects + caps size.
			$http = $this->http->get(
				$response->imageUrl,
				array(
					'timeout'   => (int) $this->settings->aiTimeout(),
					'max_bytes' => $this->settings->imageMaxBytes(),
					'max_redirects' => 3,
				)
			);
			if ( ! $http->isOk() ) {
				return $this->fail( $story, $jobId, $plan, $composed['prompt'], 'HTTP_' . $http->status, $response->provider, $composed['prompt_version'] );
			}
			$binary = (string) $http->body;
			if ( '' === $binary ) {
				return $this->fail( $story, $jobId, $plan, $composed['prompt'], 'EMPTY_DOWNLOAD', $response->provider, $composed['prompt_version'] );
			}
			if ( strlen( $binary ) > $this->settings->imageMaxBytes() ) {
				return $this->fail( $story, $jobId, $plan, $composed['prompt'], 'TOO_LARGE', $response->provider, $composed['prompt_version'] );
			}
			$mime = $this->detectMime( $binary );
			if ( '' === $mime ) {
				return $this->fail( $story, $jobId, $plan, $composed['prompt'], 'BAD_CONTENT_TYPE', $response->provider, $composed['prompt_version'] );
			}
			$declared = strtolower( (string) $http->header( 'content-type' ) );
			if ( '' !== $declared && false === strpos( $declared, 'image/' ) && false === strpos( $declared, 'octet-stream' ) ) {
				return $this->fail( $story, $jobId, $plan, $composed['prompt'], 'BAD_CONTENT_TYPE', $response->provider, $composed['prompt_version'] );
			}
		} catch ( HttpFetchException $e ) {
			return $this->fail( $story, $jobId, $plan, $composed['prompt'], $e->errorCode(), $response->provider, $composed['prompt_version'] );
		}

		$ext     = $this->extensionFor( $mime );
		$altText = $this->altText( $title );
		$imported = $this->media->import( 'story-' . $story->storyId . '-' . Time::now()->format( 'Ymd-His' ) . '.' . $ext, $mime, $binary, $altText );
		if ( empty( $imported['attachment_id'] ) ) {
			return $this->fail( $story, $jobId, $plan, $composed['prompt'], (string) ( $imported['error_code'] ?? 'IMPORT_FAILED' ), $response->provider, $composed['prompt_version'] );
		}

		$image = $this->row( $story, $jobId, $composed['prompt'], $composed['prompt_version'] );
		$image->provider     = $response->provider;
		$image->status       = GeneratedImage::STATUS_UPLOADED;
		$image->attachmentId = (int) $imported['attachment_id'];
		$image->mediaUrl     = $response->imageUrl;
		$image->sizeBytes    = strlen( $binary );
		$image->altText      = $altText;
		$image->createdAt    = Time::now();
		$id = $this->images->insert( $image );
		if ( $id > 0 ) {
			$this->images->updateResult( $image );
		}
		$this->logger->info( 'Story image ready', array( 'story_id' => $story->storyId, 'attachment_id' => $image->attachmentId, 'image_id' => $id ), 'image.stage', 'IMAGE_READY', $jobId );

		return array(
			'status'         => 'ready',
			'attachment_id'  => $image->attachmentId,
			'prompt_version' => $composed['prompt_version'],
			'provider'       => $response->provider,
			'model'          => $response->model,
		);
	}

	/** @return array{status: string, error_code: string, prompt_version: string, provider: string} */
	private function fail( Story $story, int $jobId, array $plan, string $prompt, string $code, string $provider, string $promptVersion ): array {
		$this->logger->warning( 'Story image failed — article continues (§66)', array( 'story_id' => $story->storyId, 'code' => $code ), 'image.stage', 'IMAGE_FAILED', $jobId );
		if ( ! $this->settings->imageGenerateEnabled() ) {
			return array( 'status' => 'skipped', 'error_code' => $code, 'prompt_version' => $promptVersion, 'provider' => $provider );
		}
		$image = $this->row( $story, $jobId, $prompt, $promptVersion );
		$image->provider  = $provider;
		$image->status    = GeneratedImage::STATUS_FAILED;
		$image->errorCode = substr( $code, 0, 100 );
		$image->createdAt = Time::now();
		$id = $this->images->insert( $image );
		if ( $id > 0 ) {
			$this->images->updateResult( $image );
		}
		return array( 'status' => 'failed', 'error_code' => $image->errorCode, 'prompt_version' => $promptVersion, 'provider' => $provider );
	}

	private function row( Story $story, int $jobId, string $prompt, string $promptVersion ): GeneratedImage {
		$image = new GeneratedImage();
		$image->storyId       = $story->storyId;
		$image->jobId         = $jobId;
		$image->promptVersion = $promptVersion;
		$image->prompt        = $prompt;
		return $image;
	}

	private function detectMime( string $binary ): string {
		foreach ( self::SIGNATURES as $name => $spec ) {
			if ( 0 === strpos( $binary, $spec[0] ) ) {
				if ( 'webp' === $name ) {
					// RIFF....WEBP at offset 8
					return strlen( $binary ) >= 12 && 'WEBP' === substr( $binary, 8, 4 ) ? $spec[1] : '';
				}
				return $spec[1];
			}
		}
		return '';
	}

	private function extensionFor( string $mime ): string {
		foreach ( self::SIGNATURES as $spec ) {
			if ( $spec[1] === $mime ) {
				return $spec[2];
			}
		}
		return 'jpg';
	}

	/** Accessibility text: story title only, capped, never raw source text. */
	private function altText( string $title ): string {
		$t = trim( preg_replace( '/[\r\n\t]+/', ' ', (string) $title ) );
		$t = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $t ) : strip_tags( $t );
		return mb_substr( $t, 0, 120, 'UTF-8' );
	}
}
