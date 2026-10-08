<?php
/**
 * Phase 3 orchestrator: RESEARCH → EVIDENCE → FACT CHECK for selected stories.
 *
 * Never fails the job: any provider/engine failure is contained per story and
 * recorded (research stays deterministic; claims remain rule-extracted). The
 * system result is still NO_PUBLISHABLE_STORY_FOUND when nothing was selected —
 * research only ever touches real, selected stories (§5, §14).
 *
 * @package NewsDesk\AI\Application\Research
 */

namespace NewsDesk\AI\Application\Research;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Contracts\NewsItemRepositoryInterface;
use NewsDesk\AI\Application\Contracts\ResearchRepositoryInterface;
use NewsDesk\AI\Application\Contracts\SourceRepositoryInterface;
use NewsDesk\AI\Application\Contracts\StoryRepositoryInterface;
use NewsDesk\AI\Application\Security\SecurityIntelExtractor;
use NewsDesk\AI\Domain\Entity\EvidenceClaim;
use NewsDesk\AI\Domain\Entity\ResearchPackage;
use NewsDesk\AI\Domain\Entity\Story;
use NewsDesk\AI\Logging\Contracts\LoggerInterface;

final class StoryResearchService {

	/** @var ResearchEngine */
	private $research;
	/** @var EvidenceExtractor */
	private $evidence;
	/** @var FactCheckEngine */
	private $factCheck;
	/** @var SecurityIntelExtractor A-3 */
	private $securityIntel;
	/** @var StoryRepositoryInterface */
	private $stories;
	/** @var NewsItemRepositoryInterface */
	private $items;
	/** @var SourceRepositoryInterface */
	private $sources;
	/** @var ResearchRepositoryInterface */
	private $researchRepo;
	/** @var LoggerInterface */
	private $logger;

	public function __construct(
		ResearchEngine $research,
		EvidenceExtractor $evidence,
		FactCheckEngine $factCheck,
		StoryRepositoryInterface $stories,
		NewsItemRepositoryInterface $items,
		SourceRepositoryInterface $sources,
		ResearchRepositoryInterface $researchRepo,
		LoggerInterface $logger,
		?SecurityIntelExtractor $securityIntel = null
	) {
		$this->securityIntel = $securityIntel ?: new SecurityIntelExtractor();
		$this->research    = $research;
		$this->evidence    = $evidence;
		$this->factCheck   = $factCheck;
		$this->stories     = $stories;
		$this->items       = $items;
		$this->sources     = $sources;
		$this->researchRepo = $researchRepo;
		$this->logger      = $logger;
	}

	/**
	 * @param Story[] $stories
	 * @return array{stories: int, researched: int, evidence: int, claims: int, verdicts: array<string,int>}
	 */
	public function run( array $stories, int $jobId ): array {
		$summary = array(
			'stories'    => count( $stories ),
			'researched' => 0,
			'evidence'   => 0,
			'claims'     => 0,
			'verdicts'   => array(),
		);

		$sourceList = $this->sources->findAll();
		$sourceMap  = array();
		foreach ( $sourceList as $src ) {
			$sourceMap[ (int) $src->id ] = $src;
		}

		foreach ( $stories as $story ) {
			$items = $this->items->findByIds( $story->itemIds );
			try {
				$package = $this->research->run( $story, $items, $sourceMap, $jobId );
				if ( ResearchPackage::STATUS_DONE === $package->status ) {
					$summary['researched']++;
				}
			} catch ( \Throwable $e ) {
				$this->logger->error( 'Research failed for story', array( 'story_id' => $story->storyId ), 'research.flow', 'RESEARCH_ERROR', $jobId );
			}

			try {
				$evidenceCounts = $this->evidence->run( $story, $items, $jobId );
				$summary['evidence'] += $evidenceCounts['extracted'];
				$summary['claims']  += $evidenceCounts['extracted'] + $evidenceCounts['duplicated'];
			} catch ( \Throwable $e ) {
				$this->logger->error( 'Evidence extraction failed for story', array( 'story_id' => $story->storyId ), 'research.flow', 'EVIDENCE_ERROR', $jobId );
			}

			try {
				$verdicts = $this->factCheck->run( $story, $items, $jobId );
				foreach ( $verdicts['verdicts'] as $verdict => $count ) {
					$summary['verdicts'][ $verdict ] = ( $summary['verdicts'][ $verdict ] ?? 0 ) + $count;
				}
			} catch ( \Throwable $e ) {
				$this->logger->error( 'Fact-check failed for story', array( 'story_id' => $story->storyId ), 'research.flow', 'FACT_CHECK_ERROR', $jobId );
			}

			// A-3: pull CVE/CVSS/affected/fixed out of the gathered text. Pattern
			// based, so a story either states these or it does not.
			try {
				$this->applySecurityIntel( $story, $items );
			} catch ( \Throwable $e ) {
				$this->logger->warning( 'Security intel extraction failed', array( 'story_id' => $story->storyId ), 'research.flow', 'SECURITY_INTEL_ERROR', $jobId );
			}

			$this->persistStats( $story->storyId );
		}

		return $summary;
	}

	/**
	 * A-3 — store security intelligence on the story.
	 *
	 * @param NewsItem[] $items
	 */
	private function applySecurityIntel( Story $story, array $items ): void {
		$text = (string) $story->summary;
		foreach ( $items as $item ) {
			$text .= ' ' . (string) $item->title . ' ' . (string) $item->excerpt . ' ' . (string) $item->contentText;
		}

		$intel = $this->securityIntel->extract( $text, (string) $story->title );
		if ( ! $intel['is_security'] ) {
			return;
		}

		$this->stories->updateSecurityIntel( $story->storyId, $intel );
	}

	private function persistStats( int $storyId ): void {
		$counts       = $this->researchRepo->countsByStatus( $storyId );
		$evidence     = array_sum( $counts );
		$verified     = (int) ( $counts[ EvidenceClaim::STATUS_VERIFIED ] ?? 0 );
		$contradicted = (int) ( $counts[ EvidenceClaim::STATUS_CONTRADICTED ] ?? 0 );
		$partially    = (int) ( $counts[ EvidenceClaim::STATUS_PARTIALLY_VERIFIED ] ?? 0 );

		$fcStatus = 'unverified';
		if ( $evidence > 0 ) {
			// v1.3.1: contradicted claims are BANNED from content individually
			// (ContentMemory); they only sink the whole story when they are the
			// majority or when nothing usable remains.
			$usable = $verified + $partially;
			if ( 0 === $usable ) {
				$fcStatus = $contradicted > 0 ? 'contradictions' : 'unverified';
			} elseif ( $contradicted > $usable ) {
				$fcStatus = 'contradictions';
			} elseif ( $verified === $evidence ) {
				$fcStatus = 'verified';
			} else {
				$fcStatus = 'partial';
			}
		}
		$this->stories->updateResearchStats( $storyId, 'done', $fcStatus, $evidence, $verified, $contradicted );
	}
}
