<?php
/**
 * ContentStrategy (layer 12) — deterministic planning: angle, primary AEO
 * question, outline. The best angle wins per the SEO/AEO/GEO-first requirement:
 * the angle whose terms overlap the story's own signals (entities from the
 * research brief + topics from clustering) is preferred; ties fall to the
 * research brief's first angle.
 *
 * @package NewsDesk\AI\Application\Content
 */

namespace NewsDesk\AI\Application\Content;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Domain\Entity\EvidenceClaim;
use NewsDesk\AI\Domain\Entity\ResearchPackage;
use NewsDesk\AI\Domain\Entity\Story;
use NewsDesk\AI\Support\Locale;

final class ContentStrategy {

	/**
	 * @param EvidenceClaim[] $claims
	 * @return array{eligible: bool, reason: string, angle: string, question: string, outline: string, primary_entity: string, tone: string, lang: string}
	 */
	/** @var string fa|en|ar|source */
	private $targetLanguage;

	public function __construct( string $targetLanguage = '' ) {
		$targetLanguage = '' !== $targetLanguage ? $targetLanguage : Locale::lang();
		$this->targetLanguage = $targetLanguage;
	}

	public function plan( Story $story, ?ResearchPackage $package, array $claims ): array {
		$sourceLang = $story->langCode();
		// v1.5: the article language is an editorial setting, not an accident of
		// which feed the story came from. Persian by default.
		$lang = 'source' === $this->targetLanguage ? $sourceLang : $this->targetLanguage;

		$entities  = array();
		$angles    = array();
		$brief     = $package ? $package->brief : array();
		foreach ( (array) ( $brief['entities'] ?? array() ) as $e ) {
			if ( isset( $e['name'] ) && is_string( $e['name'] ) && '' !== $e['name'] ) {
				$entities[] = $e['name'];
			}
		}
		foreach ( (array) ( $brief['angles'] ?? array() ) as $a ) {
			if ( is_string( $a ) && '' !== trim( $a ) ) {
				$angles[] = trim( $a );
			}
		}

		$primary = $this->primaryEntity( $entities, $claims );
		$topics  = array_filter( array_map( 'strval', (array) $story->topics ) );
		if ( ! $angles && is_array( $brief['key_facts'] ?? null ) && $brief['key_facts'] ) {
			// v1.6: with no explicit angle, lead with the first key fact (usually the "what happened").
			$angles[] = (string) $brief['key_facts'][0];
		}

		// Angle selection: overlap with story signals first, then first brief
		// angle, then a deterministic fallback built from the primary entity.
		$angle = $this->pickAngle( $angles, $primary, $topics, $lang );
		$question = $this->question( $primary, $angle, $lang );
		$outline  = $this->outline( $primary, $claims, $lang );

		$newsType = (string) ( $brief['news_type'] ?? '' );
		if ( ! in_array( $newsType, \NewsDesk\AI\Application\Ai\Prompts::NEWS_TYPES, true ) ) {
			$newsType = $this->inferNewsType( $claims, $angles );
		}
		$keyFacts = array();
		foreach ( (array) ( $brief['key_facts'] ?? array() ) as $kf ) {
			if ( is_string( $kf ) && '' !== trim( $kf ) ) {
				$keyFacts[] = trim( $kf );
			}
		}
		$facets = is_array( $brief['facets'] ?? null ) ? $brief['facets'] : array();

		return array(
			'news_type'      => $newsType,
			'key_facts'      => array_slice( $keyFacts, 0, 15 ),
			'facets'         => $facets,
			'topics'         => array_values( $topics ),
			'eligible'       => $primary !== '' && $outline !== '',
			'reason'         => '' !== $primary ? '' : 'NO_PRIMARY_ENTITY',
			'angle'          => $angle,
			'question'       => $question,
			'outline'        => $outline,
			'primary_entity' => $primary,
			'tone'           => 'news_analysis',
			'lang'           => $lang,
			'source_lang'    => $sourceLang,
		);
	}

	/**
	 * v1.6: deterministic fallback when the research brief has no news_type.
	 * Release/announcement wording wins over feature wording (editorial rule §1/§9).
	 */
	private function inferNewsType( array $claims, array $angles ): string {
		$text = mb_strtolower( implode( ' ', $angles ) );
		foreach ( $claims as $c ) {
			$text .= ' ' . mb_strtolower( (string) $c->claimText ) . ' ' . mb_strtolower( (string) $c->supportSnippet );
		}
		$rules = array(
			'vulnerability'  => '/\b(cve-\d{4}|vulnerabilit|exploit|xss|sql injection|آسیب‌?پذیری)/u',
			'security'       => '/\b(security (release|update|fix)|patched|امنیتی)/u',
			'acquisition'    => '/\b(acquire[sd]?|acquisition|خرید(اری)?\b)/u',
			'partnership'    => '/\b(partner(ship|s with)|همکاری)/u',
			'event'          => '/\b(wordcamp|meetup|conference|summit|رویداد)/u',
			'release'        => '/\b(is now available|now available|has been released|released today|officially (released|launched|available)|منتشر شد|در دسترس (قرار گرفت|است))/u',
			'new_product'    => '/\b(introduc(es|ing)|launch(es|ed)|announc(es|ing) (the )?(new )?(plugin|extension|tool|app|service)|معرفی (کرد|شد))/u',
			'update'         => '/\b(version \d|v\d+\.\d|\d+\.\d+(\.\d+)? (release|update)|به‌?روزرسانی)/u',
			'new_feature'    => '/\b(new feature|adds? support|now supports|قابلیت جدید)/u',
			'developer_news' => '/\b(api|hook|filter|developer|توسعه‌?دهند)/u',
			'community_news' => '/\b(community|contributor|make wordpress|جامعه)/u',
		);
		foreach ( $rules as $type => $re ) {
			if ( preg_match( $re, $text ) ) {
				return $type;
			}
		}
		return 'update';
	}

	private function primaryEntity( array $entities, array $claims ): string {
		foreach ( $entities as $e ) {
			if ( '' !== trim( (string) $e ) ) {
				return (string) $e;
			}
		}
		// Deterministic fallback: the most confident version/name claim, with
		// version tokens (6.9, 21.1, …) preferred — they are compact & searchable.
		$best = '';
		$bestConf = -1.0;
		$versionToken = '';
		foreach ( $claims as $claim ) {
			if ( EvidenceClaim::STATUS_VERIFIED !== $claim->verificationStatus && EvidenceClaim::STATUS_PARTIALLY_VERIFIED !== $claim->verificationStatus ) {
				continue;
			}
			if ( $claim->claimType === EvidenceClaim::TYPE_VERSION && '' === $versionToken ) {
				if ( preg_match( '/\b\d{1,3}(?:\.\d{1,3}){1,3}\b/u', (string) $claim->claimText, $m ) ) {
					$versionToken = $m[0];
				}
			}
			if ( ( $claim->claimType === EvidenceClaim::TYPE_VERSION || $claim->claimType === EvidenceClaim::TYPE_NAME ) && $claim->confidence > $bestConf ) {
				$bestConf = $claim->confidence;
				$best     = trim( (string) preg_replace( '/[^\p{L}\p{N}\.\- ]/u', '', (string) $claim->claimText ) );
			}
		}
		if ( '' !== $versionToken ) {
			return $versionToken;
		}
		// Keep short, title-safe names only.
		return mb_strlen( $best ) <= 60 ? $best : '';
	}

	private function pickAngle( array $angles, string $primary, array $topics, string $lang ): string {
		if ( ! $angles && ! $topics && '' !== $primary ) {
			return 'fa' === $lang ? 'تحلیل تازه‌های ' . $primary . ' برای اکوسیستم وردپرس' : 'What ' . $primary . ' means for the WordPress ecosystem';
		}
		if ( ! $angles ) {
			return 'fa' === $lang ? 'تازه‌های اکوسیستم وردپرس' : 'WordPress ecosystem update';
		}
		$normTopics = array();
		foreach ( $topics as $t ) {
			$normTopics[] = mb_strtolower( trim( (string) $t ), 'UTF-8' );
		}
		$best = $angles[0];
		$bestScore = 0;
		foreach ( $angles as $angle ) {
			$score = 0;
			$low   = mb_strtolower( $angle, 'UTF-8' );
			foreach ( $normTopics as $t ) {
				if ( '' !== $t && false !== mb_strpos( $low, $t ) ) {
					$score += 2;
				}
			}
			if ( '' !== $primary && false !== mb_strpos( $low, mb_strtolower( $primary, 'UTF-8' ) ) ) {
				$score += 1;
			}
			if ( $score > $bestScore ) {
				$bestScore = $score;
				$best      = $angle;
			}
		}
		return $best;
	}

	private function question( string $primary, string $angle, string $lang ): string {
		if ( '' === $primary ) {
			return 'fa' === $lang ? 'تازه‌ترین خبر اکوسیستم وردپرس چیست؟' : 'What is the latest WordPress ecosystem news?';
		}
		return 'fa' === $lang ? 'در ' . $primary . ' چه چیز جدیدی وجود دارد؟' : 'What’s new in ' . $primary . '?';
	}

	/**
	 * Deterministic outline: group claims by type, one H2 per group with the
	 * claim ids that must cover it.
	 */
	private function outline( string $primary, array $claims, string $lang ): string {
		$groups = array();
		foreach ( $claims as $claim ) {
			if ( EvidenceClaim::STATUS_VERIFIED !== $claim->verificationStatus && EvidenceClaim::STATUS_PARTIALLY_VERIFIED !== $claim->verificationStatus ) {
				continue;
			}
			$groups[ $claim->claimType ][] = $claim->claimId;
		}
		if ( ! $groups ) {
			return '';
		}
		$labels = array(
			EvidenceClaim::TYPE_EVENT   => 'fa' === $lang ? 'چه خبر است' : 'What happened',
			EvidenceClaim::TYPE_VERSION => 'fa' === $lang ? 'تغییرات نسخه' : 'Version changes',
			EvidenceClaim::TYPE_STAT    => 'fa' === $lang ? 'اعداد و آمار' : 'Numbers & stats',
			EvidenceClaim::TYPE_DATE    => 'fa' === $lang ? 'زمان‌بندی' : 'Timeline',
			EvidenceClaim::TYPE_QUOTE   => 'fa' === $lang ? 'نقل‌قول‌های کلیدی' : 'Key quotes',
			EvidenceClaim::TYPE_NAME    => 'fa' === $lang ? 'افراد و نهادها' : 'People & organizations',
		);
		$lines = array();
		foreach ( $groups as $type => $ids ) {
			$label = $labels[ $type ] ?? ( 'fa' === $lang ? 'تحلیل' : 'Analysis' );
			$lines[] = sprintf( '%s | claims: %s', $label, implode( ', ', array_slice( $ids, 0, 5 ) ) );
		}
		$lines[] = sprintf( 'fa' === $lang ? 'جمع‌بندی و چشم‌انداز | claims:' : 'Summary & outlook | claims:' );
		return implode( "\n", $lines );
	}
}
