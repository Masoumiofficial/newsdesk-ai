<?php
/**
 * Health check (v1.3): answers "why is nothing being produced?" in one screen.
 *
 * Every check returns status ok|warn|fail, a human title, detail, and a
 * concrete fix. Read-only, except `testProvider()` which performs one tiny
 * chat completion to prove the API key works.
 *
 * @package NewsDesk\AI\Application
 */

namespace NewsDesk\AI\Application;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Ai\ProviderRegistry;
use NewsDesk\AI\Application\Contracts\ContentRepositoryInterface;
use NewsDesk\AI\Application\Contracts\JobQueueInterface;
use NewsDesk\AI\Application\Contracts\JobRepositoryInterface;
use NewsDesk\AI\Application\Contracts\NewsItemRepositoryInterface;
use NewsDesk\AI\Application\Contracts\SourceRepositoryInterface;
use NewsDesk\AI\Application\Contracts\StoryRepositoryInterface;
use NewsDesk\AI\Domain\Entity\Source;
use NewsDesk\AI\Domain\JobStateMachine;
use NewsDesk\AI\Infrastructure\Scheduler\CronScheduler;
use NewsDesk\AI\Support\Time;

final class HealthCheckService {

	public const OK   = 'ok';
	public const WARN = 'warn';
	public const FAIL = 'fail';

	/** @var SourceRepositoryInterface */
	private $sources;
	/** @var NewsItemRepositoryInterface */
	private $items;
	/** @var StoryRepositoryInterface */
	private $stories;
	/** @var JobRepositoryInterface */
	private $jobs;
	/** @var ContentRepositoryInterface */
	private $versions;
	/** @var ProviderRegistry */
	private $providers;
	/** @var JobQueueInterface */
	private $queue;
	/** @var CronScheduler */
	private $cron;
	/** @var NewsroomSettings */
	private $settings;

	public function __construct(
		SourceRepositoryInterface $sources,
		NewsItemRepositoryInterface $items,
		StoryRepositoryInterface $stories,
		JobRepositoryInterface $jobs,
		ContentRepositoryInterface $versions,
		ProviderRegistry $providers,
		JobQueueInterface $queue,
		CronScheduler $cron,
		NewsroomSettings $settings
	) {
		$this->sources   = $sources;
		$this->items     = $items;
		$this->stories   = $stories;
		$this->jobs      = $jobs;
		$this->versions  = $versions;
		$this->providers = $providers;
		$this->queue     = $queue;
		$this->cron      = $cron;
		$this->settings  = $settings;
	}

	/**
	 * @return array<int, array{id:string,status:string,title:string,detail:string,fix:string}>
	 */
	public function run(): array {
		$checks = array();
		$checks[] = $this->checkPhp();
		$checks[] = $this->checkProviders();
		$checks[] = $this->checkSources();
		$checks[] = $this->checkItems();
		$checks[] = $this->checkCron();
		$checks[] = $this->checkQueue();
		$checks[] = $this->checkLastJob();
		$checks[] = $this->checkStories();
		$checks[] = $this->checkThresholds();
		$checks[] = $this->checkOutput();
		return $checks;
	}

	/**
	 * One real round-trip to the first configured provider. Returns
	 * array{ok:bool, provider:string, model:string, latency_ms:int, error:string}.
	 */
	public function testProvider(): array {
		$configured = $this->providers->configured();
		if ( ! $configured ) {
			return array( 'ok' => false, 'provider' => '', 'model' => '', 'latency_ms' => 0, 'error' => 'NO_PROVIDER_CONFIGURED' );
		}
		$p     = $configured[0];
		$start = microtime( true );
		try {
			$r = $p->chat(
				array(
					array( 'role' => 'system', 'content' => 'Reply with the single word: pong' ),
					array( 'role' => 'user', 'content' => 'ping' ),
				),
				array( 'max_tokens' => 5, 'temperature' => 0 )
			);
			return array(
				'ok'         => $r->isOk(),
				'provider'   => $p->id(),
				'model'      => $r->model,
				'latency_ms' => (int) round( ( microtime( true ) - $start ) * 1000 ),
				'error'      => $r->isOk() ? '' : $r->errorCode,
			);
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'provider' => $p->id(), 'model' => '', 'latency_ms' => (int) round( ( microtime( true ) - $start ) * 1000 ), 'error' => get_class( $e ) . ': ' . $e->getMessage() );
		}
	}

	/* ------------------------------------------------------------------ */

	private function checkPhp(): array {
		$limit = (int) ini_get( 'max_execution_time' );
		$mem   = ini_get( 'memory_limit' );
		$ext   = array();
		foreach ( array( 'curl', 'dom', 'simplexml', 'mbstring', 'json' ) as $e ) {
			if ( ! extension_loaded( $e ) ) {
				$ext[] = $e;
			}
		}
		if ( $ext ) {
			return $this->r( 'php', self::FAIL, __( 'PHP extensions', 'newsdesk-ai' ), sprintf( __( 'Required PHP extensions are missing: %s', 'newsdesk-ai' ), implode( ', ', $ext ) ), __( 'Ask your host to enable these extensions.', 'newsdesk-ai' ) );
		}
		if ( $limit > 0 && $limit < 120 ) {
			return $this->r( 'php', self::WARN, __( 'PHP execution limit', 'newsdesk-ai' ), sprintf( __( 'max_execution_time = %d seconds. A full run (research + fact-check + generation + image) usually takes 2 to 5 minutes and may be cut off mid-way.', 'newsdesk-ai' ), $limit ), __( 'Run it through Action Scheduler and WP-CLI cron, or raise max_execution_time to 300.', 'newsdesk-ai' ) );
		}
		return $this->r( 'php', self::OK, __( 'PHP environment', 'newsdesk-ai' ), sprintf( 'PHP %s · memory %s · max_execution_time %s', PHP_VERSION, $mem, $limit ?: '∞' ), '' );
	}

	private function checkProviders(): array {
		$conf = $this->providers->configured();
		if ( ! $conf ) {
			return $this->r( 'providers', self::FAIL, __( 'AI API key', 'newsdesk-ai' ), __( 'No provider has a key. Without one, nothing is generated — discovery still works, but the pipeline stops at RESEARCHING.', 'newsdesk-ai' ), __( 'Settings → API keys → enter and save a GapGPT or OpenAI key; it should then read “Set ✅”.', 'newsdesk-ai' ) );
		}
		$ids = array_map( static function ( $p ) { return $p->id(); }, $conf );
		return $this->r( 'providers', self::OK, __( 'AI API key', 'newsdesk-ai' ), sprintf( __( 'Providers ready: %s (press “Test connection” for a real check)', 'newsdesk-ai' ), implode( ', ', $ids ) ), '' );
	}

	private function checkSources(): array {
		$all    = $this->sources->findAll();
		$active = array_filter( $all, static function ( Source $s ) { return $s->isFetchable(); } );
		if ( ! $all ) {
			return $this->r( 'sources', self::FAIL, __( 'News sources', 'newsdesk-ai' ), __( 'No sources have been defined.', 'newsdesk-ai' ), __( 'Sources → Add source. Add at least three RSS feeds covering one subject area so stories can be corroborated across sources.', 'newsdesk-ai' ) );
		}
		if ( ! $active ) {
			return $this->r( 'sources', self::FAIL, __( 'News sources', 'newsdesk-ai' ), sprintf( __( '%d sources are defined but none are active or fetchable.', 'newsdesk-ai' ), count( $all ) ), __( 'On the Sources page press “Active” and make sure the feed URL is filled in.', 'newsdesk-ai' ) );
		}
		$errored = array();
		$never   = 0;
		foreach ( $active as $s ) {
			if ( null === $s->lastSuccessAt ) {
				$never++;
			}
			if ( null !== $s->lastErrorAt && ( null === $s->lastSuccessAt || $s->lastErrorAt > $s->lastSuccessAt ) ) {
				$errored[] = $s->name . ' (' . mb_substr( $s->lastErrorMessage, 0, 60 ) . ')';
			}
		}
		if ( count( $active ) < 2 ) {
			return $this->r( 'sources', self::WARN, __( 'News sources', 'newsdesk-ai' ), __( 'Only one source is active. Story scoring weights multi-source corroboration heavily, so a single source rarely reaches the selection threshold.', 'newsdesk-ai' ), __( 'Add at least two or three sources covering the same topic, or turn on quick-start mode.', 'newsdesk-ai' ) );
		}
		if ( $errored && count( $errored ) === count( $active ) ) {
			return $this->r( 'sources', self::FAIL, __( 'News sources', 'newsdesk-ai' ), __( 'The last fetch failed for every source: ', 'newsdesk-ai' ) . implode( '؛ ', $errored ), __( 'Open the feed URLs in a browser. If they load there but fail here, your host has probably blocked outbound HTTP, or the feed resolves to a private IP.', 'newsdesk-ai' ) );
		}
		$detail = sprintf( __( '%1$d of %2$d active', 'newsdesk-ai' ), count( $active ), count( $all ) );
		if ( $errored ) {
			return $this->r( 'sources', self::WARN, __( 'News sources', 'newsdesk-ai' ), $detail . ' — ' . __( 'With error: ', 'newsdesk-ai' ) . implode( '؛ ', $errored ), __( 'Review or disable the failing sources.', 'newsdesk-ai' ) );
		}
		if ( $never === count( $active ) ) {
			return $this->r( 'sources', self::WARN, __( 'News sources', 'newsdesk-ai' ), $detail . ' — ' . __( 'No fetch has happened yet.', 'newsdesk-ai' ), __( 'Press the “Run now” button further down this page.', 'newsdesk-ai' ) );
		}
		return $this->r( 'sources', self::OK, __( 'News sources', 'newsdesk-ai' ), $detail, '' );
	}

	private function checkItems(): array {
		$total = $this->items->countAll();
		$day   = $this->items->countSince( Time::now()->modify( '-24 hours' ) );
		if ( 0 === $total ) {
			return $this->r( 'items', self::WARN, __( 'Collected news', 'newsdesk-ai' ), __( 'No news has been fetched from any source yet.', 'newsdesk-ai' ), __( 'That means discovery has never run, or every source is failing — check the items above and below.', 'newsdesk-ai' ) );
		}
		return $this->r( 'items', self::OK, __( 'Collected news', 'newsdesk-ai' ), sprintf( __( '%1$d items in total, %2$d in the last 24 hours', 'newsdesk-ai' ), $total, $day ), '' );
	}

	private function checkCron(): array {
		$next = function_exists( 'wp_next_scheduled' ) ? wp_next_scheduled( CronScheduler::HOOK ) : false;
		$disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		if ( ! $next ) {
			return $this->r( 'cron', self::FAIL, __( 'Scheduler (WP-Cron)', 'newsdesk-ai' ), __( 'The plugin\'s 15-minute tick is not registered in WP-Cron; automatic runs will never start.', 'newsdesk-ai' ), __( 'Press “Save settings” once to re-register the tick, or deactivate and reactivate the plugin.', 'newsdesk-ai' ) );
		}
		$late = time() - (int) $next;
		if ( $late > 1800 ) {
			return $this->r( 'cron', self::FAIL, __( 'Scheduler (WP-Cron)', 'newsdesk-ai' ), sprintf( __( 'The tick was due %d minutes ago and did not fire. WP-Cron is effectively dead on this site (low traffic, or DISABLE_WP_CRON).', 'newsdesk-ai' ), (int) ( $late / 60 ) ), __( 'Create a real cron job on your server or in cPanel: every 5 minutes run `wget -q -O /dev/null https://SITE/wp-cron.php?doing_wp_cron` — or use “Run now” for the time being.', 'newsdesk-ai' ) );
		}
		$slot = $this->cron->getNextRun();
		return $this->r( 'cron', $disabled ? self::WARN : self::OK, __( 'Scheduler (WP-Cron)', 'newsdesk-ai' ), sprintf( __( 'Next tick: %1$s · Next pipeline slot: %2$s%3$s', 'newsdesk-ai' ), wp_date( 'H:i', (int) $next ), $slot ? wp_date( 'Y-m-d H:i', $slot->getTimestamp() ) : '—', $disabled ? ' · DISABLE_WP_CRON=true (مطمئن شوید cron سیستمی دارید)' : '' ), '' );
	}

	private function checkQueue(): array {
		if ( 'action-scheduler' === $this->queue->name() ) {
			return $this->r( 'queue', self::OK, __( 'Queue', 'newsdesk-ai' ), 'Action Scheduler', '' );
		}
		return $this->r( 'queue', self::WARN, __( 'Queue', 'newsdesk-ai' ), __( 'Action Scheduler is not installed; jobs fall back to single-event WP-Cron, which is slower, less reliable and depends on site traffic.', 'newsdesk-ai' ), __( 'Install the free Action Scheduler plugin (or WooCommerce).', 'newsdesk-ai' ) );
	}

	private function checkLastJob(): array {
		$recent = $this->jobs->findRecent( 1 );
		if ( ! $recent ) {
			return $this->r( 'job', self::WARN, __( 'Last run', 'newsdesk-ai' ), __( 'No job has been created yet — the pipeline has never run.', 'newsdesk-ai' ), __( 'Press the “Run now” button.', 'newsdesk-ai' ) );
		}
		$j     = $recent[0];
		$when  = $j->createdAt ? $j->createdAt->format( 'Y-m-d H:i' ) : '';
		$stuck = ! $j->isTerminal() && $j->createdAt && ( time() - $j->createdAt->getTimestamp() ) > 3600;
		if ( JobStateMachine::QUEUED === $j->status && $stuck ) {
			return $this->r( 'job', self::FAIL, __( 'Last run', 'newsdesk-ai' ), sprintf( __( 'Job #%1$d from %2$s has been queued since then and never started.', 'newsdesk-ai' ), $j->id, $when ), __( 'The queue (Action Scheduler / WP-Cron) is not working — check the “Queue” and “Scheduler” items, or press “Run now”.', 'newsdesk-ai' ) );
		}
		if ( $stuck ) {
			return $this->r( 'job', self::FAIL, __( 'Last run', 'newsdesk-ai' ), sprintf( __( 'Job #%1$d has been stuck at phase %2$s for over an hour — PHP most likely timed out mid-run.', 'newsdesk-ai' ), $j->id, $j->stage ), __( 'Raise max_execution_time, then press “Retry” on the dashboard.', 'newsdesk-ai' ) );
		}
		if ( JobStateMachine::FAILED === $j->status ) {
			return $this->r( 'job', self::FAIL, __( 'Last run', 'newsdesk-ai' ), sprintf( __( 'Job #%1$d failed at phase %2$s: %3$s — %4$s', 'newsdesk-ai' ), $j->id, $j->stage, $j->errorCode, mb_substr( $j->errorMessage, 0, 160 ) ), $this->fixForError( $j->errorCode ) );
		}
		$outcome = (string) ( $j->payload['outcome'] ?? $j->outcome() );
		if ( 'SKIPPED_NO_ELIGIBLE_STORY' === (string) ( $j->payload['phase4'] ?? '' ) ) {
			return $this->r( 'job', self::WARN, __( 'Last run', 'newsdesk-ai' ), sprintf( __( 'Job #%1$d (%2$s): stories were selected and researched, but none passed fact-checking (contradicted or unverified) → no content was generated.', 'newsdesk-ai' ), $j->id, $when ), __( 'Look for the STORY_NOT_ELIGIBLE event in the logs. It usually means your sources reported the same news in different words; add more sources in the same language and subject area.', 'newsdesk-ai' ) );
		}
		if ( JobStateMachine::NEEDS_REVIEW === $j->status && 0 === $this->versions->countAll() ) {
			return $this->r( 'job', self::FAIL, __( 'Last run', 'newsdesk-ai' ), sprintf( __( 'Job #%1$d (%2$s) finished as needs_review but saved no content version — the model\'s output was rejected on every attempt during generation (SCHEMA_FAIL / BUSINESS_RULE_FAIL), so there is nothing to review.', 'newsdesk-ai' ), $j->id, $when ), __( 'Open the SCHEMA_RETRY / SCHEMA_FAIL / STORY_NO_CONTENT events in the logs; the Details column names the exact field that failed. Usually the model is too weak or does not support JSON output — pick a stronger one, such as gpt-4o, claude-sonnet or gemini-1.5-pro.', 'newsdesk-ai' ) );
		}
		if ( 'NO_PUBLISHABLE_STORY_FOUND' === $outcome ) {
			return $this->r( 'job', self::WARN, __( 'Last run', 'newsdesk-ai' ), sprintf( __( 'Job #%1$d (%2$s) completed cleanly but returned NO_PUBLISHABLE_STORY_FOUND: no story cleared the selection thresholds.', 'newsdesk-ai' ), $j->id, $when ), __( 'This is deliberate (quality over quantity). To see a first result, turn on quick-start mode or add more sources on the same topic.', 'newsdesk-ai' ) );
		}
		return $this->r( 'job', $j->isTerminal() ? self::OK : self::WARN, __( 'Last run', 'newsdesk-ai' ), sprintf( '#%d · %s · %s · %s', $j->id, $j->status, $j->stage, $when ), '' );
	}

	private function checkStories(): array {
		$total = $this->stories->countAll();
		if ( 0 === $total ) {
			return $this->r( 'stories', self::WARN, __( 'Stories', 'newsdesk-ai' ), __( 'No stories have been clustered yet.', 'newsdesk-ai' ), __( 'If news has been collected but there are no stories, the full pipeline — not just discovery — has not run yet.', 'newsdesk-ai' ) );
		}
		$sel = $this->stories->countByStatus( 'selected' );
		return $this->r( 'stories', $sel > 0 ? self::OK : self::WARN, __( 'Stories', 'newsdesk-ai' ), sprintf( __( '%1$d stories, %2$d selected for generation', 'newsdesk-ai' ), $total, $sel ), $sel > 0 ? '' : __( 'Stories exist but none were selected → check your thresholds.', 'newsdesk-ai' ) );
	}

	private function checkThresholds(): array {
		$q  = $this->settings->selectionQualityGate();
		$t  = $this->settings->selectionMinTrust();
		$cq = $this->settings->contentQualityGate();
		$m  = $this->settings->maxStoriesPerWindow();
		$qs = $this->settings->quickStartEnabled();
		$detail = sprintf( __( 'Selection: score ≥%1$d and trust ≥%2$d · at most %3$d stories per run · content quality gate ≥%4$d', 'newsdesk-ai' ), $q, $t, $m, $cq );
		if ( $qs ) {
			return $this->r( 'thresholds', self::WARN, __( 'Thresholds', 'newsdesk-ai' ), __( 'Quick-start mode is on: ', 'newsdesk-ai' ) . $detail, __( 'Turn it off once you have seen your first drafts and added enough sources.', 'newsdesk-ai' ) );
		}
		return $this->r( 'thresholds', self::OK, __( 'Thresholds', 'newsdesk-ai' ), $detail, '' );
	}

	private function checkOutput(): array {
		$n = $this->versions->countAll();
		if ( 0 === $n ) {
			return $this->r( 'output', self::WARN, __( 'Output (drafts)', 'newsdesk-ai' ), __( 'No content version has been generated yet.', 'newsdesk-ai' ), __( 'Fix the first red or amber item above, then press “Run now”.', 'newsdesk-ai' ) );
		}
		return $this->r( 'output', self::OK, __( 'Output (drafts)', 'newsdesk-ai' ), sprintf( __( '%d content versions generated — see the “Draft review” page.', 'newsdesk-ai' ), $n ), '' );
	}

	private function fixForError( string $code ): string {
		$code = strtoupper( $code );
		if ( false !== strpos( $code, 'NO_PROVIDER' ) ) {
			return __( 'No API key entered.', 'newsdesk-ai' );
		}
		if ( false !== strpos( $code, 'AUTH' ) || false !== strpos( $code, '401' ) ) {
			return __( 'The API key is invalid.', 'newsdesk-ai' );
		}
		if ( false !== strpos( $code, 'BUDGET' ) ) {
			return __( 'The per-job token budget is exhausted; set it to zero (unlimited) or raise it in the AI settings.', 'newsdesk-ai' );
		}
		if ( false !== strpos( $code, 'RATE' ) || false !== strpos( $code, '429' ) ) {
			return __( 'Provider rate limit; try again in a few minutes.', 'newsdesk-ai' );
		}
		if ( false !== strpos( $code, 'LOCK' ) ) {
			return __( 'A previous run\'s lock was never released; wait 15 minutes or deactivate and reactivate the plugin.', 'newsdesk-ai' );
		}
		return __( 'See the logs filtered by job_id for details.', 'newsdesk-ai' );
	}

	private function r( string $id, string $status, string $title, string $detail, string $fix ): array {
		return compact( 'id', 'status', 'title', 'detail', 'fix' );
	}
}
