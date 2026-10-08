<?php
/**
 * Composition root — wires the container and registers hooks.
 *
 * @package NewsDesk\AI\Core
 */

namespace NewsDesk\AI\Core;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Admin\AdminActions;
use NewsDesk\AI\Admin\Menu;
use NewsDesk\AI\Application\DiscoveryService;
use NewsDesk\AI\Application\StoryEditorialService;
use NewsDesk\AI\Application\ManualActions;
use NewsDesk\AI\Application\NewsroomSettings;
use NewsDesk\AI\Application\PipelineRunner;
use NewsDesk\AI\Application\SourceService;
use NewsDesk\AI\Infrastructure\Database\TableNames;
use NewsDesk\AI\Infrastructure\Locks\LockManager;
use NewsDesk\AI\Infrastructure\Queue\QueueFactory;
use NewsDesk\AI\Infrastructure\Scheduler\CronScheduler;
use NewsDesk\AI\Infrastructure\Scheduler\Handlers\NewsroomRunHandler;
use NewsDesk\AI\Logging\Logger;
use NewsDesk\AI\News\Canonical\UrlCanonicalizer;
use NewsDesk\AI\REST\RoutesController;
use NewsDesk\AI\Support\Container;
use NewsDesk\AI\Support\Time;
use NewsDesk\AI\Support\Branding;

final class Plugin {

	/** @var Container|null */
	private static $container;

	/**
	 * Called on plugins_loaded. Safe to call more than once.
	 */
	public static function boot(): void {
		if ( version_compare( PHP_VERSION, NEWSDESK_MIN_PHP, '<' ) ) {
			add_action( 'admin_notices', static function () {
				echo '<div class="notice notice-error"><p>' .
					/* translators: %s: minimum required PHP version */
					esc_html( sprintf( __( 'NewsDesk AI requires PHP %s or newer.', 'newsdesk-ai' ), NEWSDESK_MIN_PHP ) ) .
					'</p></div>';
			} );
			return;
		}

		if ( function_exists( 'load_plugin_textdomain' ) ) {
			load_plugin_textdomain( 'newsdesk-ai', false, dirname( plugin_basename( NEWSDESK_FILE ) ) . '/languages' );
		}

		$container = self::container();

		// Scheduler triggers (WP-Cron is trigger-only).
		$scheduler = $container->get( CronScheduler::class );
		$scheduler->register();

		// Daily digest trigger (Phase 8).
		$digestCron = $container->get( \NewsDesk\AI\Infrastructure\Scheduler\DigestCron::class );
		$digestCron->register();

		// Action Scheduler job body.
		$handler = $container->get( NewsroomRunHandler::class );
		add_action( PipelineRunner::HOOK, array( $handler, 'handle' ), 10, 1 ); // phpcs:ignore

		// REST.
		$routes = $container->get( RoutesController::class );
		add_action( 'rest_api_init', array( $routes, 'register' ) ); // phpcs:ignore

		// Admin.
		if ( is_admin() ) {
			// Schema catch-up for updates, where the activation hook never fires.
			add_action( 'admin_init', array( self::class, 'maybeUpgrade' ) ); // phpcs:ignore
			// A-17: register the option with the Settings API so core knows it
			// exists (REST/export/privacy tooling reads this registry). The
			// save path stays the hardened admin_post handler — it splits API
			// keys into encrypted SecretStorage, which register_setting's
			// generic option write cannot do.
			add_action( 'admin_init', array( self::class, 'registerSettings' ) ); // phpcs:ignore

			$menu = $container->get( Menu::class );
			add_action( 'admin_menu', array( $menu, 'register' ) ); // phpcs:ignore

			$actions = $container->get( AdminActions::class );
			$actions->register();
		}
	}

	public static function container(): Container {
		if ( null !== self::$container ) {
			return self::$container;
		}
		$c = new Container();

		// Settings (reads option lazily).
		$c->singleton( NewsroomSettings::class, static function () {
			return new NewsroomSettings();
		} );

		// Secrets (§23) — type from settings; keys never in settings/logs/HTML.
		$c->singleton( \NewsDesk\AI\Application\Contracts\SecretStorageInterface::class, static function ( Container $c ) {
			return \NewsDesk\AI\Infrastructure\Secrets\SecretFactory::make( $c->get( NewsroomSettings::class )->secretStorageType() );
		} );

		// WP DB abstraction.
		$c->singleton( \NewsDesk\AI\Application\Contracts\WpDbInterface::class, static function () {
			global $wpdb;
			return new \NewsDesk\AI\Infrastructure\WpDb\WpDb( $wpdb );
		} );
		$c->singleton( TableNames::class, static function ( Container $c ) {
			return new TableNames( $c->get( \NewsDesk\AI\Application\Contracts\WpDbInterface::class )->prefix() );
		} );

		// Logging.
		$c->singleton( \NewsDesk\AI\Logging\Contracts\LogRepositoryInterface::class, static function ( Container $c ) {
			return new \NewsDesk\AI\Infrastructure\Repository\LogRepository(
				$c->get( \NewsDesk\AI\Application\Contracts\WpDbInterface::class ),
				$c->get( TableNames::class )
			);
		} );
		$c->singleton( \NewsDesk\AI\Logging\Contracts\LoggerInterface::class, static function ( Container $c ) {
			return new Logger(
				$c->get( \NewsDesk\AI\Logging\Contracts\LogRepositoryInterface::class ),
				$c->get( NewsroomSettings::class )->logLevel()
			);
		} );

		// Repositories.
		$c->singleton( \NewsDesk\AI\Application\Contracts\SourceRepositoryInterface::class, static function ( Container $c ) {
			return new \NewsDesk\AI\Infrastructure\Repository\SourceRepository(
				$c->get( \NewsDesk\AI\Application\Contracts\WpDbInterface::class ),
				$c->get( TableNames::class )
			);
		} );
		$c->singleton( \NewsDesk\AI\Application\Contracts\NewsItemRepositoryInterface::class, static function ( Container $c ) {
			return new \NewsDesk\AI\Infrastructure\Repository\NewsItemRepository(
				$c->get( \NewsDesk\AI\Application\Contracts\WpDbInterface::class ),
				$c->get( TableNames::class )
			);
		} );
		$c->singleton( \NewsDesk\AI\Application\Contracts\JobRepositoryInterface::class, static function ( Container $c ) {
			return new \NewsDesk\AI\Infrastructure\Repository\JobRepository(
				$c->get( \NewsDesk\AI\Application\Contracts\WpDbInterface::class ),
				$c->get( TableNames::class )
			);
		} );
		$c->singleton( \NewsDesk\AI\Application\Contracts\JobEventRepositoryInterface::class, static function ( Container $c ) {
			return new \NewsDesk\AI\Infrastructure\Repository\JobEventRepository(
				$c->get( \NewsDesk\AI\Application\Contracts\WpDbInterface::class ),
				$c->get( TableNames::class )
			);
		} );
		$c->singleton( \NewsDesk\AI\Application\Contracts\LockRepositoryInterface::class, static function ( Container $c ) {
			return new \NewsDesk\AI\Infrastructure\Repository\LockRepository(
				$c->get( \NewsDesk\AI\Application\Contracts\WpDbInterface::class ),
				$c->get( TableNames::class )
			);
		} );
		$c->singleton( \NewsDesk\AI\Application\Contracts\StoryRepositoryInterface::class, static function ( Container $c ) {
			return new \NewsDesk\AI\Infrastructure\Repository\StoryRepository(
				$c->get( \NewsDesk\AI\Application\Contracts\WpDbInterface::class ),
				$c->get( TableNames::class )
			);
		} );
		$c->singleton( \NewsDesk\AI\Application\Contracts\ResearchRepositoryInterface::class, static function ( Container $c ) {
			return new \NewsDesk\AI\Infrastructure\Repository\ResearchRepository(
				$c->get( \NewsDesk\AI\Application\Contracts\WpDbInterface::class ),
				$c->get( TableNames::class )
			);
		} );
		$c->singleton( \NewsDesk\AI\Application\Contracts\AiUsageRepositoryInterface::class, static function ( Container $c ) {
			return new \NewsDesk\AI\Infrastructure\Repository\AiUsageRepository(
				$c->get( \NewsDesk\AI\Application\Contracts\WpDbInterface::class ),
				$c->get( TableNames::class )
			);
		} );
		$c->singleton( \NewsDesk\AI\Application\Contracts\PromptRepositoryInterface::class, static function ( Container $c ) {
			return new \NewsDesk\AI\Infrastructure\Repository\PromptRepository(
				$c->get( \NewsDesk\AI\Application\Contracts\WpDbInterface::class ),
				$c->get( TableNames::class )
			);
		} );

		// HTTP (always SSRF-guarded).
		$c->singleton( \NewsDesk\AI\Application\Contracts\HttpClientInterface::class, static function ( Container $c ) {
			$settings = $c->get( NewsroomSettings::class );
			return new \NewsDesk\AI\Infrastructure\Http\SafeHttpClient(
				Branding::userAgent(),
				$settings->fetchTimeout(),
				$settings->maxResponseKb() * 1024
			);
		} );

		// News.
		$c->singleton( \NewsDesk\AI\News\AdapterFactory::class, static function ( Container $c ) {
			$settings = $c->get( NewsroomSettings::class );
			return new \NewsDesk\AI\News\AdapterFactory(
				$c->get( \NewsDesk\AI\Application\Contracts\HttpClientInterface::class ),
				$settings->maxResponseKb() * 1024
			);
		} );
		$c->singleton( \NewsDesk\AI\News\Normalizer\NewsItemNormalizer::class, static function () {
			return new \NewsDesk\AI\News\Normalizer\NewsItemNormalizer();
		} );

		// Locks + queue.
		$c->singleton( LockManager::class, static function ( Container $c ) {
			return new LockManager(
				$c->get( \NewsDesk\AI\Application\Contracts\LockRepositoryInterface::class ),
				$c->get( \NewsDesk\AI\Logging\Contracts\LoggerInterface::class )
			);
		} );
		$c->singleton( \NewsDesk\AI\Application\Contracts\JobQueueInterface::class, static function ( Container $c ) {
			return QueueFactory::make( $c->get( \NewsDesk\AI\Logging\Contracts\LoggerInterface::class ) );
		} );

		// Application services.
		$c->singleton( DiscoveryService::class, static function ( Container $c ) {
			return new DiscoveryService(
				$c->get( \NewsDesk\AI\Application\Contracts\SourceRepositoryInterface::class ),
				$c->get( \NewsDesk\AI\Application\Contracts\NewsItemRepositoryInterface::class ),
				$c->get( \NewsDesk\AI\News\AdapterFactory::class ),
				$c->get( \NewsDesk\AI\News\Normalizer\NewsItemNormalizer::class ),
				$c->get( \NewsDesk\AI\Logging\Contracts\LoggerInterface::class ),
				$c->get( NewsroomSettings::class )
			);
		} );
		$c->singleton( \NewsDesk\AI\Application\Ai\ProviderRegistry::class, static function ( Container $c ) {
			$settings = $c->get( NewsroomSettings::class );
			$secrets  = $c->get( \NewsDesk\AI\Application\Contracts\SecretStorageInterface::class );
			$http     = $c->get( \NewsDesk\AI\Application\Contracts\HttpClientInterface::class );
			$timeout  = (int) $settings->aiTimeout();
			$providers = array();
			foreach ( $settings->aiProviderOrder() as $id ) {
				$key = $secrets->get( $id . '_api_key' );
				if ( null === $key ) {
					continue;
				}
				switch ( $id ) {
					case 'gapgpt':
						$providers[] = \NewsDesk\AI\Application\Ai\GapGptProvider::make( $http, $key, $settings->aiModelGapGpt(), $settings->aiGapGptAltCdn(), $timeout );
						break;
					case 'openai':
						$providers[] = \NewsDesk\AI\Application\Ai\OpenAiProvider::make( $http, $key, $settings->aiModelOpenAi(), $timeout );
						break;
					case 'gemini':
						$providers[] = new \NewsDesk\AI\Application\Ai\GeminiProvider( $http, $key, $settings->aiModelGemini(), $timeout );
						break;
				}
			}
			return new \NewsDesk\AI\Application\Ai\ProviderRegistry( $providers );
		} );
		$c->singleton( \NewsDesk\AI\Application\Ai\PromptRegistry::class, static function ( Container $c ) {
			return new \NewsDesk\AI\Application\Ai\PromptRegistry(
				$c->get( \NewsDesk\AI\Application\Contracts\PromptRepositoryInterface::class )
			);
		} );
		$c->singleton( \NewsDesk\AI\Application\Ai\AiGateway::class, static function ( Container $c ) {
			return new \NewsDesk\AI\Application\Ai\AiGateway(
				$c->get( \NewsDesk\AI\Application\Ai\ProviderRegistry::class ),
				$c->get( \NewsDesk\AI\Application\Contracts\AiUsageRepositoryInterface::class ),
				$c->get( NewsroomSettings::class ),
				$c->get( \NewsDesk\AI\Logging\Contracts\LoggerInterface::class ),
				$c->get( \NewsDesk\AI\Application\Contracts\PromptRepositoryInterface::class )
			);
		} );
		// Phase 5 — image stage (§66): optional, never blocks the job.
		$c->singleton( \NewsDesk\AI\Application\Contracts\GeneratedImageRepositoryInterface::class, static function ( Container $c ) {
			return new \NewsDesk\AI\Infrastructure\Repository\GeneratedImageRepository(
				$c->get( \NewsDesk\AI\Application\Contracts\WpDbInterface::class ),
				$c->get( TableNames::class )
			);
		} );
		$c->singleton( \NewsDesk\AI\Application\Contracts\MediaLibraryInterface::class, static function () {
			return new \NewsDesk\AI\Infrastructure\WordPress\WpMediaLibrary();
		} );
		$c->singleton( \NewsDesk\AI\Application\Images\ImagePromptComposer::class, static function ( Container $c ) {
			return new \NewsDesk\AI\Application\Images\ImagePromptComposer(
				$c->get( \NewsDesk\AI\Application\Ai\PromptRegistry::class ),
				new \NewsDesk\AI\Application\Ai\PromptInjectionGuard()
			);
		} );
		$c->singleton( \NewsDesk\AI\Application\Images\ImageService::class, static function ( Container $c ) {
			return new \NewsDesk\AI\Application\Images\ImageService(
				$c->get( \NewsDesk\AI\Application\Images\ImagePromptComposer::class ),
				$c->get( \NewsDesk\AI\Application\Ai\AiGateway::class ),
				$c->get( \NewsDesk\AI\Application\Contracts\HttpClientInterface::class ),
				$c->get( \NewsDesk\AI\Application\Contracts\MediaLibraryInterface::class ),
				$c->get( \NewsDesk\AI\Application\Contracts\GeneratedImageRepositoryInterface::class ),
				$c->get( NewsroomSettings::class ),
				$c->get( \NewsDesk\AI\Logging\Contracts\LoggerInterface::class )
			);
		} );
		$c->singleton( \NewsDesk\AI\Application\Research\ResearchEngine::class, static function ( Container $c ) {
			return new \NewsDesk\AI\Application\Research\ResearchEngine(
				$c->get( \NewsDesk\AI\Application\Ai\AiGateway::class ),
				$c->get( \NewsDesk\AI\Application\Contracts\ResearchRepositoryInterface::class ),
				$c->get( \NewsDesk\AI\Logging\Contracts\LoggerInterface::class )
			);
		} );
		$c->singleton( \NewsDesk\AI\Application\Research\EvidenceExtractor::class, static function ( Container $c ) {
			return new \NewsDesk\AI\Application\Research\EvidenceExtractor(
				$c->get( \NewsDesk\AI\Application\Ai\AiGateway::class ),
				$c->get( \NewsDesk\AI\Application\Contracts\ResearchRepositoryInterface::class ),
				$c->get( \NewsDesk\AI\Logging\Contracts\LoggerInterface::class )
			);
		} );
		$c->singleton( \NewsDesk\AI\Application\Research\FactCheckEngine::class, static function ( Container $c ) {
			return new \NewsDesk\AI\Application\Research\FactCheckEngine(
				$c->get( \NewsDesk\AI\Application\Contracts\ResearchRepositoryInterface::class ),
				$c->get( \NewsDesk\AI\Application\Ai\AiGateway::class ),
				$c->get( NewsroomSettings::class ),
				$c->get( \NewsDesk\AI\Logging\Contracts\LoggerInterface::class )
			);
		} );
		$c->singleton( \NewsDesk\AI\Infrastructure\Seeds\PromptSeeder::class, static function ( Container $c ) {
			return new \NewsDesk\AI\Infrastructure\Seeds\PromptSeeder(
				$c->get( \NewsDesk\AI\Application\Contracts\PromptRepositoryInterface::class )
			);
		} );
		$c->singleton( \NewsDesk\AI\Application\Research\StoryResearchService::class, static function ( Container $c ) {
			return new \NewsDesk\AI\Application\Research\StoryResearchService(
				$c->get( \NewsDesk\AI\Application\Research\ResearchEngine::class ),
				$c->get( \NewsDesk\AI\Application\Research\EvidenceExtractor::class ),
				$c->get( \NewsDesk\AI\Application\Research\FactCheckEngine::class ),
				$c->get( \NewsDesk\AI\Application\Contracts\StoryRepositoryInterface::class ),
				$c->get( \NewsDesk\AI\Application\Contracts\NewsItemRepositoryInterface::class ),
				$c->get( \NewsDesk\AI\Application\Contracts\SourceRepositoryInterface::class ),
				$c->get( \NewsDesk\AI\Application\Contracts\ResearchRepositoryInterface::class ),
				$c->get( \NewsDesk\AI\Logging\Contracts\LoggerInterface::class )
			);
		} );
		$c->singleton( \NewsDesk\AI\Application\Contracts\ContentRepositoryInterface::class, static function ( Container $c ) {
			return new \NewsDesk\AI\Infrastructure\Repository\ContentRepository(
				$c->get( \NewsDesk\AI\Application\Contracts\WpDbInterface::class ),
				$c->get( \NewsDesk\AI\Infrastructure\Database\TableNames::class )
			);
		} );
		$c->singleton( \NewsDesk\AI\Application\Contracts\LinkRepositoryInterface::class, static function ( Container $c ) {
			return new \NewsDesk\AI\Infrastructure\Repository\LinkRepository(
				$c->get( \NewsDesk\AI\Application\Contracts\WpDbInterface::class ),
				$c->get( \NewsDesk\AI\Infrastructure\Database\TableNames::class )
			);
		} );
		$c->singleton( \NewsDesk\AI\Application\Contracts\WpPostIndexInterface::class, static function () {
			return new \NewsDesk\AI\Infrastructure\WordPress\WordPressPostIndex();
		} );
		$c->singleton( \NewsDesk\AI\Application\Contracts\WpPostWriterInterface::class, static function () {
			return new \NewsDesk\AI\Infrastructure\WordPress\WordPressPostWriter();
		} );
		$c->singleton( \NewsDesk\AI\Application\Content\ContentStrategy::class, static function ( Container $c ) {
			return new \NewsDesk\AI\Application\Content\ContentStrategy( $c->get( NewsroomSettings::class )->contentLanguage() );
		} );
		$c->singleton( \NewsDesk\AI\Application\Content\ContentComposer::class, static function ( Container $c ) {
			return new \NewsDesk\AI\Application\Content\ContentComposer(
				$c->get( \NewsDesk\AI\Application\Ai\AiGateway::class ),
				$c->get( \NewsDesk\AI\Logging\Contracts\LoggerInterface::class )
			);
		} );
		$c->singleton( \NewsDesk\AI\Application\Content\ContentSanitizer::class, static function () {
			return new \NewsDesk\AI\Application\Content\ContentSanitizer();
		} );
		$c->singleton( \NewsDesk\AI\Application\Content\QualityGate::class, static function ( Container $c ) {
			return new \NewsDesk\AI\Application\Content\QualityGate( $c->get( NewsroomSettings::class ) );
		} );
		$c->singleton( \NewsDesk\AI\Application\Seo\SeoProcessor::class, static function () {
			return new \NewsDesk\AI\Application\Seo\SeoProcessor();
		} );
		$c->singleton( \NewsDesk\AI\Application\Seo\SeoAdapterInterface::class, static function () {
			return new \NewsDesk\AI\Application\Seo\NativeSeoAdapter();
		} );
		// v2.0: all four adapters are registered. The resolver runs the native
		// one always and third-party ones only when that plugin is installed,
		// replacing v1.6.0's hardcoded Yoast/Rank Math meta writes.
		$c->singleton( \NewsDesk\AI\Application\Seo\SeoAdapterResolver::class, static function ( Container $c ) {
			return new \NewsDesk\AI\Application\Seo\SeoAdapterResolver(
				array(
					$c->get( \NewsDesk\AI\Application\Seo\SeoAdapterInterface::class ),
					new \NewsDesk\AI\Application\Seo\YoastAdapter(),
					new \NewsDesk\AI\Application\Seo\RankMathAdapter(),
					new \NewsDesk\AI\Application\Seo\AioseoAdapter(),
				),
				$c->get( \NewsDesk\AI\Logging\Contracts\LoggerInterface::class )
			);
		} );
		$c->singleton( \NewsDesk\AI\Application\Linking\ExternalLinkManager::class, static function ( Container $c ) {
			return new \NewsDesk\AI\Application\Linking\ExternalLinkManager(
				$c->get( \NewsDesk\AI\Application\Contracts\LinkRepositoryInterface::class )
			);
		} );
		$c->singleton( \NewsDesk\AI\Application\Linking\InternalLinkEngine::class, static function ( Container $c ) {
			return new \NewsDesk\AI\Application\Linking\InternalLinkEngine(
				$c->get( \NewsDesk\AI\Application\Contracts\WpPostIndexInterface::class ),
				$c->get( \NewsDesk\AI\Application\Contracts\LinkRepositoryInterface::class )
			);
		} );
		// v2.0 (A-8): the correction path for already-published articles.
		$c->singleton( \NewsDesk\AI\Application\Content\CorrectionService::class, static function ( Container $c ) {
			return new \NewsDesk\AI\Application\Content\CorrectionService(
				$c->get( \NewsDesk\AI\Application\Contracts\WpPostWriterInterface::class ),
				$c->get( NewsroomSettings::class ),
				$c->get( \NewsDesk\AI\Logging\Contracts\LoggerInterface::class )
			);
		} );
		$c->singleton( \NewsDesk\AI\Application\Content\DraftService::class, static function ( Container $c ) {
			return new \NewsDesk\AI\Application\Content\DraftService(
				$c->get( \NewsDesk\AI\Application\Contracts\WpPostWriterInterface::class ),
				$c->get( \NewsDesk\AI\Application\Contracts\WpPostIndexInterface::class ),
				$c->get( \NewsDesk\AI\Application\Contracts\LinkRepositoryInterface::class ),
				$c->get( NewsroomSettings::class ),
				$c->get( \NewsDesk\AI\Logging\Contracts\LoggerInterface::class ),
				$c->get( \NewsDesk\AI\Application\Seo\SeoAdapterResolver::class )
			);
		} );
		$c->singleton( \NewsDesk\AI\Application\Content\DraftReviewService::class, static function ( Container $c ) {
			return new \NewsDesk\AI\Application\Content\DraftReviewService(
				$c->get( \NewsDesk\AI\Application\Contracts\ContentRepositoryInterface::class ),
				$c->get( \NewsDesk\AI\Application\Contracts\StoryRepositoryInterface::class ),
				$c->get( \NewsDesk\AI\Logging\Contracts\LoggerInterface::class ),
				$c->get( \NewsDesk\AI\Application\Contracts\ResearchRepositoryInterface::class )
			);
		} );
		$c->singleton( \NewsDesk\AI\Application\Content\StoryContentService::class, static function ( Container $c ) {
			return new \NewsDesk\AI\Application\Content\StoryContentService(
				$c->get( \NewsDesk\AI\Application\Contracts\ContentRepositoryInterface::class ),
				$c->get( \NewsDesk\AI\Application\Contracts\ResearchRepositoryInterface::class ),
				$c->get( \NewsDesk\AI\Application\Contracts\StoryRepositoryInterface::class ),
				$c->get( \NewsDesk\AI\Application\Contracts\SourceRepositoryInterface::class ),
				$c->get( \NewsDesk\AI\Application\Contracts\LinkRepositoryInterface::class ),
				$c->get( \NewsDesk\AI\Application\Content\ContentStrategy::class ),
				$c->get( \NewsDesk\AI\Application\Content\ContentComposer::class ),
				$c->get( \NewsDesk\AI\Application\Content\ContentSanitizer::class ),
				$c->get( \NewsDesk\AI\Application\Content\QualityGate::class ),
				$c->get( \NewsDesk\AI\Application\Seo\SeoProcessor::class ),
				$c->get( \NewsDesk\AI\Application\Linking\ExternalLinkManager::class ),
				$c->get( \NewsDesk\AI\Application\Linking\InternalLinkEngine::class ),
				$c->get( \NewsDesk\AI\Application\Content\DraftService::class ),
				$c->get( NewsroomSettings::class ),
				$c->get( \NewsDesk\AI\Logging\Contracts\LoggerInterface::class ),
				$c->get( \NewsDesk\AI\Application\Images\ImageService::class ),
				$c->get( \NewsDesk\AI\Application\Images\ImagePlanService::class ),
				$c->get( \NewsDesk\AI\Application\Audit\ArticleAuditor::class )
			);
		} );
		// v2.0 (A-3/A-4/A-6/A-7): deterministic analysis services, no AI, no I/O.
		$c->singleton( \NewsDesk\AI\Application\Content\FillerPhraseDetector::class, static function () {
			return new \NewsDesk\AI\Application\Content\FillerPhraseDetector();
		} );
		$c->singleton( \NewsDesk\AI\Application\FactCheck\ClaimRiskAssessor::class, static function () {
			return new \NewsDesk\AI\Application\FactCheck\ClaimRiskAssessor();
		} );
		$c->singleton( \NewsDesk\AI\Application\Security\SecurityIntelExtractor::class, static function () {
			return new \NewsDesk\AI\Application\Security\SecurityIntelExtractor();
		} );
		$c->singleton( \NewsDesk\AI\Application\Audit\ArticleAuditor::class, static function ( Container $c ) {
			return new \NewsDesk\AI\Application\Audit\ArticleAuditor(
				$c->get( \NewsDesk\AI\Application\Content\FillerPhraseDetector::class )
			);
		} );
		// A-5: plan-only image stage — deterministic, free, always runs.
		$c->singleton( \NewsDesk\AI\Application\Images\ImagePlanService::class, static function () {
			return new \NewsDesk\AI\Application\Images\ImagePlanService();
		} );
		$c->singleton( \NewsDesk\AI\Application\Scoring\StoryClusterer::class, static function () {
			return new \NewsDesk\AI\Application\Scoring\StoryClusterer();
		} );
		$c->singleton( \NewsDesk\AI\Application\Scoring\StoryScorer::class, static function () {
			return new \NewsDesk\AI\Application\Scoring\StoryScorer();
		} );
		$c->singleton( \NewsDesk\AI\Application\Scoring\EditorialSelector::class, static function () {
			return new \NewsDesk\AI\Application\Scoring\EditorialSelector();
		} );
		// v2.0 (A-2): the cannibalization decision the schema was always ready for.
		$c->singleton( \NewsDesk\AI\Application\Scoring\CannibalizationEngine::class, static function ( Container $c ) {
			return new \NewsDesk\AI\Application\Scoring\CannibalizationEngine(
				$c->get( \NewsDesk\AI\Application\Scoring\StoryClusterer::class )
			);
		} );
		$c->singleton( StoryEditorialService::class, static function ( Container $c ) {
			return new StoryEditorialService(
				$c->get( \NewsDesk\AI\Application\Contracts\NewsItemRepositoryInterface::class ),
				$c->get( \NewsDesk\AI\Application\Contracts\SourceRepositoryInterface::class ),
				$c->get( \NewsDesk\AI\Application\Contracts\StoryRepositoryInterface::class ),
				$c->get( \NewsDesk\AI\Application\Scoring\StoryClusterer::class ),
				$c->get( \NewsDesk\AI\Application\Scoring\StoryScorer::class ),
				$c->get( \NewsDesk\AI\Application\Scoring\EditorialSelector::class ),
				$c->get( NewsroomSettings::class ),
				$c->get( \NewsDesk\AI\Logging\Contracts\LoggerInterface::class ),
				$c->get( \NewsDesk\AI\Application\Scoring\CannibalizationEngine::class ),
				$c->get( \NewsDesk\AI\Application\Contracts\WpPostIndexInterface::class )
			);
		} );
		$c->singleton( PipelineRunner::class, static function ( Container $c ) {
			return new PipelineRunner(
				$c->get( \NewsDesk\AI\Application\Contracts\JobRepositoryInterface::class ),
				$c->get( \NewsDesk\AI\Application\Contracts\JobEventRepositoryInterface::class ),
				$c->get( DiscoveryService::class ),
				$c->get( StoryEditorialService::class ),
				$c->get( \NewsDesk\AI\Application\Research\StoryResearchService::class ),
				$c->get( \NewsDesk\AI\Application\Content\StoryContentService::class ),
				$c->get( \NewsDesk\AI\Application\Contracts\StoryRepositoryInterface::class ),
				$c->get( LockManager::class ),
				$c->get( \NewsDesk\AI\Application\Contracts\JobQueueInterface::class ),
				$c->get( \NewsDesk\AI\Logging\Contracts\LoggerInterface::class ),
				$c->get( \NewsDesk\AI\Application\Notifications\NotificationService::class )
			);
		} );
		$c->singleton( ManualActions::class, static function ( Container $c ) {
			return new ManualActions(
				$c->get( \NewsDesk\AI\Application\Contracts\JobRepositoryInterface::class ),
				$c->get( \NewsDesk\AI\Application\Contracts\JobQueueInterface::class ),
				$c->get( \NewsDesk\AI\Logging\Contracts\LoggerInterface::class )
			);
		} );
		$c->singleton( SourceService::class, static function ( Container $c ) {
			return new SourceService(
				$c->get( \NewsDesk\AI\Application\Contracts\SourceRepositoryInterface::class ),
				$c->get( \NewsDesk\AI\Application\Contracts\NewsItemRepositoryInterface::class ),
				$c->get( \NewsDesk\AI\Application\Contracts\StoryRepositoryInterface::class )
			);
		} );
		$c->singleton( CronScheduler::class, static function ( Container $c ) {
			return new CronScheduler(
				new \NewsDesk\AI\Application\SchedulerService(),
				$c->get( \NewsDesk\AI\Application\Contracts\JobRepositoryInterface::class ),
				$c->get( \NewsDesk\AI\Application\Contracts\JobQueueInterface::class ),
				$c->get( NewsroomSettings::class ),
				$c->get( LockManager::class ),
				$c->get( \NewsDesk\AI\Logging\Contracts\LoggerInterface::class )
			);
		} );
		$c->singleton( \NewsDesk\AI\Application\HealthCheckService::class, static function ( Container $c ) {
			return new \NewsDesk\AI\Application\HealthCheckService(
				$c->get( \NewsDesk\AI\Application\Contracts\SourceRepositoryInterface::class ),
				$c->get( \NewsDesk\AI\Application\Contracts\NewsItemRepositoryInterface::class ),
				$c->get( \NewsDesk\AI\Application\Contracts\StoryRepositoryInterface::class ),
				$c->get( \NewsDesk\AI\Application\Contracts\JobRepositoryInterface::class ),
				$c->get( \NewsDesk\AI\Application\Contracts\ContentRepositoryInterface::class ),
				$c->get( \NewsDesk\AI\Application\Ai\ProviderRegistry::class ),
				$c->get( \NewsDesk\AI\Application\Contracts\JobQueueInterface::class ),
				$c->get( CronScheduler::class ),
				$c->get( NewsroomSettings::class )
			);
		} );
		$c->singleton( NewsroomRunHandler::class, static function ( Container $c ) {
			return new NewsroomRunHandler( $c->get( PipelineRunner::class ) );
		} );

		// Phase 8 — notifications & daily digest.
		$c->singleton( \NewsDesk\AI\Application\Contracts\MailerInterface::class, static function () {
			return new \NewsDesk\AI\Infrastructure\Notification\WpMailer();
		} );
		$c->singleton( \NewsDesk\AI\Application\Notifications\NotificationService::class, static function ( Container $c ) {
			$http    = $c->get( \NewsDesk\AI\Application\Contracts\HttpClientInterface::class );
			$secrets = $c->get( \NewsDesk\AI\Application\Contracts\SecretStorageInterface::class );
			$settings = $c->get( NewsroomSettings::class );
			return new \NewsDesk\AI\Application\Notifications\NotificationService(
				array(
					new \NewsDesk\AI\Infrastructure\Notification\WebhookNotifier( $http, $secrets ),
					new \NewsDesk\AI\Infrastructure\Notification\EmailNotifier( $c->get( \NewsDesk\AI\Application\Contracts\MailerInterface::class ), $settings ),
					new \NewsDesk\AI\Infrastructure\Notification\TelegramNotifier( $http, $secrets, $settings ),
					new \NewsDesk\AI\Infrastructure\Notification\SlackNotifier( $http, $secrets ),
				),
				$settings,
				$secrets,
				$c->get( \NewsDesk\AI\Logging\Contracts\LoggerInterface::class )
			);
		} );
		$c->singleton( \NewsDesk\AI\Application\DigestService::class, static function ( Container $c ) {
			return new \NewsDesk\AI\Application\DigestService(
				$c->get( \NewsDesk\AI\Application\Contracts\WpDbInterface::class ),
				$c->get( TableNames::class ),
				$c->get( \NewsDesk\AI\Application\Notifications\NotificationService::class ),
				$c->get( NewsroomSettings::class ),
				$c->get( LockManager::class ),
				$c->get( \NewsDesk\AI\Logging\Contracts\LoggerInterface::class )
			);
		} );
		$c->singleton( \NewsDesk\AI\Infrastructure\Scheduler\DigestCron::class, static function ( Container $c ) {
			return new \NewsDesk\AI\Infrastructure\Scheduler\DigestCron(
				$c->get( \NewsDesk\AI\Application\DigestService::class ),
				$c->get( NewsroomSettings::class ),
				$c->get( \NewsDesk\AI\Logging\Contracts\LoggerInterface::class )
			);
		} );
		// v2.0 (B-9): live "test connection" for a source.
		$c->singleton( \NewsDesk\AI\Application\FeedTester::class, static function ( Container $c ) {
			return new \NewsDesk\AI\Application\FeedTester(
				$c->get( \NewsDesk\AI\News\AdapterFactory::class ),
				$c->get( NewsroomSettings::class ),
				$c->get( \NewsDesk\AI\Logging\Contracts\LoggerInterface::class )
			);
		} );
		// v2.0: the retention_* settings finally have a consumer.
		$c->singleton( \NewsDesk\AI\Application\RetentionService::class, static function ( Container $c ) {
			return new \NewsDesk\AI\Application\RetentionService(
				$c->get( \NewsDesk\AI\Application\Contracts\WpDbInterface::class ),
				$c->get( TableNames::class ),
				$c->get( NewsroomSettings::class ),
				$c->get( \NewsDesk\AI\Logging\Contracts\LoggerInterface::class )
			);
		} );
		$c->singleton( \NewsDesk\AI\Infrastructure\Scheduler\RetentionCron::class, static function ( Container $c ) {
			return new \NewsDesk\AI\Infrastructure\Scheduler\RetentionCron(
				$c->get( \NewsDesk\AI\Application\RetentionService::class ),
				$c->get( \NewsDesk\AI\Logging\Contracts\LoggerInterface::class )
			);
		} );

		// Admin + REST.
		// Admin pages are resolved by Menu::register() with the container — each
		// page takes the container itself (Menu.php resolves these; late binding
		// keeps DB access lazy inside render()).
		foreach ( array(
			\NewsDesk\AI\Admin\Page\DashboardPage::class,
			\NewsDesk\AI\Admin\Page\StoriesPage::class,
			\NewsDesk\AI\Admin\Page\SourcesPage::class,
			\NewsDesk\AI\Admin\Page\SchedulerPage::class,
			\NewsDesk\AI\Admin\Page\LogsPage::class,
			\NewsDesk\AI\Admin\Page\SettingsPage::class,
			\NewsDesk\AI\Admin\Page\DraftReviewPage::class,
			\NewsDesk\AI\Admin\Page\HealthPage::class,
			// v2.0 (A-9/A-11): the four pages that were placeholders.
			\NewsDesk\AI\Admin\Page\NewsInboxPage::class,
			\NewsDesk\AI\Admin\Page\AiProvidersPage::class,
			\NewsDesk\AI\Admin\Page\ImagesPage::class,
			\NewsDesk\AI\Admin\Page\LinkingPage::class,
		) as $pageClass ) {
			$c->singleton( $pageClass, static function ( Container $c ) use ( $pageClass ) {
				return new $pageClass( $c );
			} );
		}
		$c->singleton( Menu::class, static function ( Container $c ) {
			return new Menu( $c );
		} );
		$c->singleton( AdminActions::class, static function ( Container $c ) {
			return new AdminActions( $c );
		} );
		$c->singleton( RoutesController::class, static function ( Container $c ) {
			return new RoutesController( $c );
		} );

		self::$container = $c;
		return $c;
	}

	/**
	 * Run pending migrations after a plugin UPDATE.
	 *
	 * register_activation_hook() has not fired on updates since WP 3.1 — it
	 * only runs when an admin activates the plugin by hand. A site upgrading
	 * 1.6.0 → 2.0 therefore never reaches Activation::activate(), so the
	 * schema stays at the old version while the new code queries columns that
	 * do not exist yet (e.g. news_items.duplicate_of_id from migration 1.4.0).
	 *
	 * The reliable pattern is to compare the stored schema version against the
	 * constant on an admin request and migrate when they differ.
	 */
	/**
	 * A-17 — declare the settings option to WordPress.
	 *
	 * Deliberately NOT wired to an options.php form: the plugin's own handler
	 * performs nonce + capability checks and routes API keys to encrypted
	 * storage instead of the option row. Registering the option still gives
	 * core (and any privacy/export tooling) a correct picture of what we store.
	 */
	public static function registerSettings(): void {
		if ( ! function_exists( 'register_setting' ) ) {
			return;
		}
		register_setting(
			'newsdesk',
			\NewsDesk\AI\Application\NewsroomSettings::OPTION,
			array(
				'type'              => 'array',
				'description'       => __( 'NewsDesk AI settings', 'newsdesk-ai' ),
				'sanitize_callback' => array( \NewsDesk\AI\Application\NewsroomSettings::class, 'sanitize' ),
				'show_in_rest'      => false,
				'default'           => \NewsDesk\AI\Application\NewsroomSettings::defaults(),
			)
		);
	}

	public static function maybeUpgrade(): void {
		if ( get_option( 'newsdesk_db_version', '0' ) === NEWSDESK_DB_VERSION ) {
			return;
		}
		// A half-migrated schema must never take the site down: the notice is
		// the operator's signal, and the next admin request retries.
		try {
			Activation::activate();
		} catch ( \Throwable $e ) {
			add_action( 'admin_notices', static function () use ( $e ) {
				echo '<div class="notice notice-error"><p>' .
					esc_html__( 'NewsDesk AI: the database update failed.', 'newsdesk-ai' ) .
					' ' . esc_html( $e->getMessage() ) .
					'</p></div>';
			} );
		}
	}

	public static function activate(): void {
		Activation::activate();
	}

	public static function deactivate(): void {
		Deactivation::deactivate();
	}
}
