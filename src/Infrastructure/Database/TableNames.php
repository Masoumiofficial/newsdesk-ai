<?php
namespace NewsDesk\AI\Infrastructure\Database;

defined( 'ABSPATH' ) || exit;

final class TableNames {

	/** @var string */
	private $prefix;

	public function __construct( string $prefix ) {
		$this->prefix = $prefix;
	}

	public function sources(): string {
		return $this->prefix . 'newsdesk_sources';
	}

	public function newsItems(): string {
		return $this->prefix . 'newsdesk_news_items';
	}

	public function stories(): string {
		return $this->prefix . 'newsdesk_stories';
	}

	public function storySources(): string {
		return $this->prefix . 'newsdesk_story_sources';
	}

	public function jobs(): string {
		return $this->prefix . 'newsdesk_jobs';
	}

	public function jobEvents(): string {
		return $this->prefix . 'newsdesk_job_events';
	}

	public function researchPackages(): string {
		return $this->prefix . 'newsdesk_research_packages';
	}

	public function factChecks(): string {
		return $this->prefix . 'newsdesk_fact_checks';
	}

	public function researchClaims(): string {
		return $this->prefix . 'newsdesk_research_claims';
	}

	public function contentVersions(): string {
		return $this->prefix . 'newsdesk_content_versions';
	}

	public function generatedImages(): string {
		return $this->prefix . 'newsdesk_generated_images';
	}

	public function aiUsage(): string {
		return $this->prefix . 'newsdesk_ai_usage';
	}

	public function promptVersions(): string {
		return $this->prefix . 'newsdesk_prompt_versions';
	}

	public function internalLinks(): string {
		return $this->prefix . 'newsdesk_internal_links';
	}

	public function externalLinks(): string {
		return $this->prefix . 'newsdesk_external_links';
	}

	public function logs(): string {
		return $this->prefix . 'newsdesk_logs';
	}

	public function locks(): string {
		return $this->prefix . 'newsdesk_locks';
	}
}
