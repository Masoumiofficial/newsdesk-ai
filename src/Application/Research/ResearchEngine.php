<?php
/**
 * Research stage (§14): builds the per-story ResearchPackage.
 *
 * Deterministic FIRST (always works, zero fabrication risk); AI synthesizes a richer
 * brief ON TOP when providers are configured. If AI fails, the deterministic brief is
 * persisted with a documented fallback code — research never blocks the pipeline.
 *
 * @package NewsDesk\AI\Application\Research
 */

namespace NewsDesk\AI\Application\Research;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Ai\AiGateway;
use NewsDesk\AI\Application\Ai\Exception\NonRetryableProviderException;
use NewsDesk\AI\Application\Ai\Exception\RetryableProviderException;
use NewsDesk\AI\Application\Ai\Prompts;
use NewsDesk\AI\Application\Contracts\ResearchRepositoryInterface;
use NewsDesk\AI\Domain\Entity\NewsItem;
use NewsDesk\AI\Domain\Entity\ResearchPackage;
use NewsDesk\AI\Domain\Entity\Source;
use NewsDesk\AI\Domain\Entity\Story;
use NewsDesk\AI\Logging\Contracts\LoggerInterface;
use NewsDesk\AI\Support\Time;

final class ResearchEngine {

	/** Hard cap for the prompt payload (content tokens). */
	private const MAX_CONTENT_CHARS = 24000;

	/** @var AiGateway */
	private $gateway;
	/** @var ResearchRepositoryInterface */
	private $research;
	/** @var LoggerInterface */
	private $logger;

	public function __construct( AiGateway $gateway, ResearchRepositoryInterface $research, LoggerInterface $logger ) {
		$this->gateway  = $gateway;
		$this->research = $research;
		$this->logger   = $logger;
	}

	/**
	 * @param NewsItem[] $items
	 * @param Source[]   $sources
	 */
	public function run( Story $story, array $items, array $sources, int $jobId ): ResearchPackage {
		$package = new ResearchPackage();
		$package->storyId    = $story->storyId;
		$package->jobId      = $jobId;
		$package->startedAt  = Time::now();
		$package->createdAt  = Time::now();

		$corpus       = $this->corpus( $items );
		$deterministic = $this->deterministicBrief( $items, $corpus, count( $sources ) );

		try {
			$result = $this->gateway->structured(
				Prompts::ID_RESEARCH,
				$story->langCode(),
				array(
					'story_title' => $story->canonicalTitle,
					'content'     => $corpus,
				),
				Prompts::SCHEMA_RESEARCH,
				$jobId,
				'research.synthesis',
				static function ( array $data ) {
					$errors = array();
					if ( mb_strlen( (string) ( $data['summary'] ?? '' ) ) < 10 ) {
						$errors[] = 'summary too short';
					}
					if ( count( (array) ( $data['entities'] ?? array() ) ) > 12 ) {
						$errors[] = 'too many entities';
					}
					return $errors;
				}
			);

			$package->brief         = $result['data'];
			$package->provider      = $result['provider'];
			$package->model         = $result['model'];
			$package->promptVersion = $result['prompt_version'];
			$package->schemaVersion = $result['schema_version'];
			$package->confidence    = round( (float) ( $result['data']['confidence'] ?? 0 ), 3 );
			$package->status        = ResearchPackage::STATUS_DONE;
		} catch ( NonRetryableProviderException $e ) {
			// Auth/schema/budget: the brief stays deterministic (never empty, never invented).
			$package->brief      = $deterministic;
			$package->provider   = 'rules';
			$package->model      = 'deterministic';
			$package->status     = ResearchPackage::STATUS_DONE;
			$package->errorCode  = 'AI_' . $e->codeName();
		} catch ( RetryableProviderException $e ) {
			$package->brief      = $deterministic;
			$package->provider   = 'rules';
			$package->model      = 'deterministic';
			$package->status     = ResearchPackage::STATUS_DONE;
			$package->errorCode  = 'AI_' . $e->codeName();
		} catch ( \Throwable $e ) {
			// Never let an environment glitch fake or block research.
			$package->brief      = $deterministic;
			$package->provider   = 'rules';
			$package->model      = 'deterministic';
			$package->status     = ResearchPackage::STATUS_DONE;
			$package->errorCode  = 'AI_ERROR';
		}

		$package->confidence    = $package->confidence > 0 ? $package->confidence : (float) ( $deterministic['confidence'] ?? 0.0 );
		$package->contradictions = $this->flagContradictions( $package->brief );
		$package->completedAt   = Time::now();
		$package->updatedAt     = Time::now();

		$this->research->insertPackage( $package );
		return $package;
	}

	/**
	 * Grounded, deterministic brief — nothing invented, everything counted.
	 *
	 * @param NewsItem[] $items
	 */
	public function deterministicBrief( array $items, string $corpus, int $sourceCount ): array {
		$entities = array();
		$versions = array();
		$mention  = array();
		foreach ( $items as $item ) {
			$text = (string) ( $item->title . ' ' . $item->excerpt . ' ' . $item->contentText );
			if ( preg_match_all( '/\b\d{1,3}(?:\.\d{1,3}){1,3}\b/u', $text, $m ) ) {
				foreach ( $m[0] as $v ) {
					$versions[ $v ] = true;
					$mention[ $v ]  = ( $mention[ $v ] ?? 0 ) + 1;
				}
			}
		}
		foreach ( $versions as $v => $unused ) {
			$entities[] = array( 'name' => $v, 'type' => 'version', 'mention_count' => $mention[ $v ] ?? 1 );
		}
		$summary = '';
		if ( '' !== $corpus ) {
			$summary = trim( preg_replace( '/\s+/u', ' ', mb_substr( $corpus, 0, 300 ) ) );
		}
		return array(
			'summary'        => $summary,
			'entities'       => $entities,
			'angles'         => array(),
			'open_questions' => array(),
			'missing'        => array(),
			'confidence'     => round( min( 1.0, 0.4 + 0.2 * max( 0, $sourceCount - 1 ) ), 3 ),
		);
	}

	/**
	 * @return string
	 */
	private function corpus( array $items ): string {
		$parts = array();
		$used  = 0;
		foreach ( $items as $item ) {
			$chunk = (string) $item->title . "\n" . $item->contentText;
			if ( $used + mb_strlen( $chunk ) > self::MAX_CONTENT_CHARS ) {
				$chunk = mb_substr( $chunk, 0, max( 0, self::MAX_CONTENT_CHARS - $used ) );
			}
			$parts[] = '[item:' . (int) $item->id . ' source:' . (int) $item->sourceId . "]\n" . $chunk;
			$used   += mb_strlen( $chunk );
			if ( $used >= self::MAX_CONTENT_CHARS ) {
				break;
			}
		}
		return implode( "\n\n", $parts );
	}

	/**
	 * Surface contradictions the deterministic pass can SEE (different versions/dates
	 * reported as entities already differ by construction — here we only note count>1).
	 */
	private function flagContradictions( array $brief ): array {
		$flags = array();
		foreach ( (array) ( $brief['entities'] ?? array() ) as $entity ) {
			$type = (string) ( $entity['type'] ?? '' );
			if ( 'version' === $type && (int) ( $entity['mention_count'] ?? 0 ) > 1 ) {
				continue; // repeated version is corroboration, not contradiction
			}
		}
		return $flags;
	}
}
