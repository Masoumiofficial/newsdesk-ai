<?php
/**
 * Phase 4 orchestrator: per story → grounding memory → strategy → compose
 * (with §36 revision loop) → quality gate → SEO/AEO/GEO → links → WP draft.
 *
 * Never fails the job: every story is isolated; failures become
 * content_status=needs_review/blocked with an error code. Provisions:
 *  - only VERIFIED/PARTIALLY_VERIFIED claims enter the article (§17);
 *  - without an approved version NO WordPress post is created (§31 §73);
 *  - the draft/auto-publish path is writer-enforced (§73).
 *
 * @package NewsDesk\AI\Application\Content
 */

namespace NewsDesk\AI\Application\Content;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Ai\Exception\NonRetryableProviderException;
use NewsDesk\AI\Application\Ai\Exception\RetryableProviderException;
use NewsDesk\AI\Application\Contracts\ContentRepositoryInterface;
use NewsDesk\AI\Application\Contracts\LinkRepositoryInterface;
use NewsDesk\AI\Application\Contracts\ResearchRepositoryInterface;
use NewsDesk\AI\Application\Contracts\SourceRepositoryInterface;
use NewsDesk\AI\Application\Contracts\StoryRepositoryInterface;
use NewsDesk\AI\Application\Audit\ArticleAuditor;
use NewsDesk\AI\Application\Images\ImagePlanService;
use NewsDesk\AI\Application\Images\ImageService;
use NewsDesk\AI\Application\Linking\ExternalLinkManager;
use NewsDesk\AI\Application\Linking\InternalLinkEngine;
use NewsDesk\AI\Application\NewsroomSettings;
use NewsDesk\AI\Application\Seo\SeoProcessor;
use NewsDesk\AI\Domain\Entity\ContentVersion;
use NewsDesk\AI\Domain\Entity\Story;
use NewsDesk\AI\Logging\Contracts\LoggerInterface;
use NewsDesk\AI\Support\Time;
use NewsDesk\AI\Support\Locale;

final class StoryContentService {

	/** @var ContentRepositoryInterface */
	private $versions;
	/** @var ResearchRepositoryInterface */
	private $research;
	/** @var StoryRepositoryInterface */
	private $stories;
	/** @var SourceRepositoryInterface */
	private $sources;
	/** @var LinkRepositoryInterface */
	private $links;
	/** @var ContentStrategy */
	private $strategy;
	/** @var ContentComposer */
	private $composer;
	/** @var ContentSanitizer */
	private $sanitizer;
	/** @var QualityGate */
	private $gate;
	/** @var SeoProcessor */
	private $seo;
	/** @var ExternalLinkManager */
	private $external;
	/** @var InternalLinkEngine */
	private $internal;
	/** @var DraftService */
	private $drafts;
	/** @var NewsroomSettings */
	private $settings;
	/** @var LoggerInterface */
	private $logger;
	/** @var ImageService|null  Phase 5, §66 — optional opt-in generation. */
	private $images;
	/** @var ImagePlanService|null A-5 — the plan-only stage the spec asks for. */
	private $imagePlan;
	/** @var ArticleAuditor A-6 */
	private $auditor;

	public function __construct(
		ContentRepositoryInterface $versions,
		ResearchRepositoryInterface $research,
		StoryRepositoryInterface $stories,
		SourceRepositoryInterface $sources,
		LinkRepositoryInterface $links,
		ContentStrategy $strategy,
		ContentComposer $composer,
		ContentSanitizer $sanitizer,
		QualityGate $gate,
		SeoProcessor $seo,
		ExternalLinkManager $external,
		InternalLinkEngine $internal,
		DraftService $drafts,
		NewsroomSettings $settings,
		LoggerInterface $logger,
		?ImageService $images = null,
		?ImagePlanService $imagePlan = null,
		?ArticleAuditor $auditor = null
	) {
		$this->versions = $versions;
		$this->research = $research;
		$this->stories  = $stories;
		$this->sources  = $sources;
		$this->links    = $links;
		$this->strategy = $strategy;
		$this->composer = $composer;
		$this->sanitizer= $sanitizer;
		$this->gate     = $gate;
		$this->seo      = $seo;
		$this->external = $external;
		$this->internal = $internal;
		$this->drafts   = $drafts;
		$this->settings = $settings;
		$this->logger   = $logger;
		$this->images    = $images;
		$this->imagePlan = $imagePlan;
		$this->auditor   = $auditor ?: new ArticleAuditor();
	}

	/**
	 * @param Story[] $stories
	 * @return array{stories:int, eligible:int, generated:int, drafted:int, needs_review:int, blocked:int, revisions:int, post_ids:int[], versions:int}
	 */
	public function run( array $stories, int $jobId ): array {
		$summary = array(
			'stories'      => count( $stories ),
			'eligible'     => 0,
			'generated'    => 0,
			'drafted'      => 0,
			'needs_review' => 0,
			'blocked'      => 0,
			'revisions'    => 0,
			'post_ids'     => array(),
			'versions'     => 0,
		);

		$sourceList = $this->sources->findAll();
		$sourceMap  = array();
		foreach ( $sourceList as $src ) {
			$sourceMap[ (int) $src->id ] = $src;
		}

		foreach ( $stories as $story ) {
			try {
				$state = $this->runStory( $story, $jobId, $sourceMap );
				if ( ! empty( $state['eligible'] ) ) {
					$summary['eligible']++;
				}
				if ( 'drafted' === $state['bucket'] ) {
					$summary['generated']++;
					$summary['drafted']++;
					$summary['post_ids'][] = (int) $state['post_id'];
				} elseif ( 'needs_review' === $state['bucket'] ) {
					$summary['needs_review']++;
				} else {
					$summary['blocked']++;
				}
				$summary['revisions'] += $state['revisions'];
				$summary['versions']  += $state['versions'];
			} catch ( \Throwable $e ) {
				$this->logger->error( 'Content flow failed for story', array( 'story_id' => $story->storyId, 'error' => get_class( $e ) ), 'content.flow', 'CONTENT_ERROR', $jobId );
				$this->stories->updateContentStatus( $story->storyId, 'blocked' );
				$summary['blocked']++;
			}
		}
		return $summary;
	}

	/**
	 * @param array<int, object> $sourceMap
	 * @return array{bucket: string, revisions: int, versions: int, eligible: bool, post_id?: int}
	 */
	private function runStory( Story $story, int $jobId, array $sourceMap ): array {
		if ( ! $this->settings->contentGenerateEnabled() ) {
			$this->stories->updateContentStatus( $story->storyId, 'blocked' );
			return array( 'bucket' => 'blocked', 'revisions' => 0, 'versions' => 0, 'eligible' => false );
		}

		$claims  = $this->research->claimsForStory( $story->storyId );
		$memory  = ContentMemory::build( $story, $claims );
		$package = $this->research->findPackageByStory( $story->storyId );
		$plan    = $this->strategy->plan( $story, $package, $claims );

		if ( ! $memory['eligible'] || ! $plan['eligible'] ) {
			$this->stories->updateContentStatus( $story->storyId, 'blocked' );
			$this->logger->info( 'Story blocked from content: no grounded claims', array( 'story_id' => $story->storyId, 'reason' => $memory['reason'] ?: $plan['reason'] ), 'content.flow', 'CONTENT_BLOCKED', $jobId );
			return array( 'bucket' => 'blocked', 'revisions' => 0, 'versions' => 0, 'eligible' => false );
		}

		$eligible  = true;
		$maxTries  = $this->settings->contentMaxRevisions();
		$feedback  = '';
		$approved  = null;
		$lastError = '';
		$lastScore = 0.0;
		$revisions = 0;
		$versionId = 0;

		for ( $attempt = 1; $attempt <= $maxTries; $attempt++ ) {
			$revisions++;
			try {
				$result  = $this->composer->compose( $plan, $memory, $jobId, $feedback );
				$article = $this->sanitizer->sanitize( $result['data'] );
				$article['lang'] = (string) ( $plan['lang'] ?? Locale::lang() ); // v1.5 output language
				$quality = $this->gate->score( $article, $plan, $memory, $story );

				$v = new ContentVersion();
				$v->storyId       = $story->storyId;
				$v->jobId         = $jobId;
				$v->versionNo     = $this->versions->nextVersionNo( $story->storyId );
				$v->content       = $article;
				$v->contentHash   = $this->sanitizer->hash( $article );
				$v->provider      = $result['provider'];
				$v->model         = $result['model'];
				$v->promptVersion = $result['prompt_version'];
				$v->qualityScore  = $quality['score'];
				$v->status        = ContentVersion::STATUS_DRAFT;
				$v->createdAt     = Time::now();
				$versionId        = $this->versions->insert( $v );
				$lastScore        = $quality['score'];

				if ( $quality['passed'] ) {
					$approved = array( 'result' => $result, 'article' => $article, 'quality' => $quality, 'version_id' => $versionId );
					break;
				}
				$feedback = implode( '; ', $this->feedbackFrom( $quality ) );
				$this->logger->warning( 'Content below quality gate', array( 'story_id' => $story->storyId, 'attempt' => $attempt, 'score' => $quality['score'] ), 'content.flow', 'QUALITY_BELOW_GATE', $jobId );
			} catch ( RetryableProviderException $e ) {
				$lastError = $e->codeName();
			} catch ( NonRetryableProviderException $e ) {
				$lastError = $e->codeName();
				$feedback  = $e->getMessage();
				// v1.3.2: SCHEMA / BUSINESS_RULE failures now carry the concrete
				// violations in the message; feed them back once instead of giving up.
				if ( in_array( $lastError, array( 'SCHEMA', 'BUSINESS_RULE' ), true ) && $attempt < $maxTries ) {
					$this->logger->warning( 'Content rejected — retrying with violations as feedback', array( 'story_id' => $story->storyId, 'attempt' => $attempt, 'reason' => $lastError, 'detail' => mb_substr( $feedback, 0, 400 ) ), 'content.flow', 'CONTENT_RETRY', $jobId );
					continue;
				}
				break;
			} catch ( \Throwable $e ) {
				$lastError = 'UNEXPECTED_' . get_class( $e );
				break;
			}
		}

		if ( null === $approved ) {
			// Store what we have as needs_review; NO draft is created (§31).
			if ( $versionId > 0 ) {
				$this->versions->updateStatus( $versionId, ContentVersion::STATUS_NEEDS_REVIEW, $lastScore, '' !== $lastError ? $lastError : 'QUALITY_GATE' );
			} else {
				// v1.3.2: make the "nothing to review" case explicit in the logs.
				$this->logger->error( 'Story produced no usable content — nothing stored for review', array( 'story_id' => $story->storyId, 'attempts' => $revisions, 'last_error' => $lastError, 'detail' => mb_substr( (string) $feedback, 0, 400 ) ), 'content.flow', 'STORY_NO_CONTENT', $jobId );
			}
			$this->stories->updateContentStatus( $story->storyId, 'needs_review' );
			return array( 'bucket' => 'needs_review', 'revisions' => $revisions, 'versions' => $revisions > 0 ? 1 : 0, 'eligible' => true );
		}

		$result  = $approved['result'];
		$article = $approved['article'];
		$quality = $approved['quality'];

		$this->versions->updateStatus( $versionId, ContentVersion::STATUS_APPROVED, $quality['score'] );

		// SEO/AEO/GEO package (layer 14).
		$site    = $this->siteInfo();
		$seoPkg  = $this->seo->process( $story, $article, $plan, $memory, $sourceMap, $site );

		// External attribution (layer 16).
		$this->external->build( $memory['allowed'], $jobId );
		$external = $this->links->externalForStory( $story->storyId );

		// Internal suggestions (layer 15).
		$suggestions = array();
		if ( $this->settings->contentIncludeLinks() ) {
			$suggestions = $this->internal->suggest( $plan['primary_entity'], (array) $story->topics );
		}

		// Phase 5 — the image stage. A-5: the spec's phase is PLAN-ONLY, so the
		// plan is always produced (deterministic, free, no network) and the
		// editor always has direction. Real generation is an opt-in extra that
		// NEVER blocks the draft; every outcome lands in the draft meta.
		$image = array( 'status' => 'skipped', 'error_code' => 'NOT_RUN' );
		if ( null !== $this->imagePlan ) {
			try {
				$image = $this->imagePlan->plan( $story, $article );
			} catch ( \Throwable $e ) {
				$image = array( 'status' => 'failed', 'error_code' => 'PLAN_' . get_class( $e ) );
			}
		}
		if ( null !== $this->images && $this->settings->imageGenerateEnabled() ) {
			try {
				$generated = $this->images->generate( $story, $plan, $jobId );
				// Keep the plan alongside the generated result: if generation
				// failed, the editor still has something to act on.
				$image = array_merge( $image, $generated );
			} catch ( \Throwable $e ) {
				$image['generation_status']     = 'failed';
				$image['generation_error_code'] = 'UNEXPECTED_' . get_class( $e );
			}
		}

		// A-6 — the pre-publication audit. Separate from the quality gate: the
		// gate measures how good the piece is, the audit asks whether anything
		// here must never reach a reader. A critical failure blocks the draft
		// no matter how high the score (§ audit).
		$audit = $this->auditor->audit(
			$article,
			$story,
			array(
				'allowed_claim_ids' => array_keys( (array) ( $memory['allowed'] ?? array() ) ),
				'sources'           => array_values( (array) $sourceMap ),
				'language'          => $story->language,
				'focus_keyword'     => $seoPkg['focus_keyword'] ?? '',
			)
		);
		if ( ! empty( $audit['critical'] ) ) {
			$this->logger->error(
				'Audit blocked the draft',
				array( 'story_id' => $story->storyId, 'critical' => $audit['critical'], 'score' => $audit['score'] ),
				'content.flow',
				'AUDIT_CRITICAL_FAIL',
				$jobId
			);
			$this->versions->updateStatus( $versionId, ContentVersion::STATUS_NEEDS_REVIEW, $quality['score'], 'AUDIT_' . strtoupper( (string) $audit['critical'][0] ) );
			$this->stories->updateContentStatus( $story->storyId, 'needs_review' );
			return array( 'bucket' => 'needs_review', 'revisions' => $revisions, 'versions' => 1, 'eligible' => true, 'audit' => $audit );
		}

		$meta = array(
			'provider'         => $result['provider'],
			'model'            => $result['model'],
			'prompt_version'   => $result['prompt_version'],
			'schema_version'   => $result['schema_version'],
			'quality_score'    => $quality['score'],
			'quality_breakdown'=> $quality['breakdown'],
			'content_hash'     => $this->sanitizer->hash( $article ),
			'audit_score'      => $audit['score'],
			'audit_axes'       => $audit['axes'],
		);
		$draft = $this->drafts->create( $story, $article, $seoPkg, $meta, $external, $suggestions, $jobId, $image );

		if ( $draft['post_id'] > 0 ) {
			$this->versions->linkDraft( $versionId, $draft['post_id'] );
			$this->stories->updateContentStatus( $story->storyId, 'drafted' );
			return array( 'bucket' => 'drafted', 'revisions' => $revisions, 'versions' => 1, 'eligible' => true, 'post_id' => $draft['post_id'] );
		}

		$this->versions->updateStatus( $versionId, ContentVersion::STATUS_NEEDS_REVIEW, $quality['score'], 'DRAFT_FAILED' );
		$this->stories->updateContentStatus( $story->storyId, 'needs_review' );
		return array( 'bucket' => 'needs_review', 'revisions' => $revisions, 'versions' => 1, 'eligible' => true );
	}

	/** @return string[] short human feedback for the next revision prompt */
	private function feedbackFrom( array $quality ): array {
		$out = array();
		foreach ( $quality['checks'] as $check ) {
			if ( (float) $check['score'] < 0.8 * $this->gate->threshold() && $check['score'] < 100 ) {
				$out[] = sprintf( '%s (%s)', $check['key'], $check['note'] );
			}
		}
		return $out ? $out : array( 'improve clarity and structure' );
	}

	private function siteInfo(): array {
		$site = array( 'site_name' => '', 'home_url' => '', 'author_name' => '' );
		if ( function_exists( 'get_bloginfo' ) ) {
			$site['site_name'] = (string) get_bloginfo( 'name' );
			$site['home_url']  = (string) home_url( '/' );
		}
		return $site;
	}
}
