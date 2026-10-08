<?php
/**
 * ContentComposer (layer 13) — original article generation ON TOP of the
 * grounding map (§19: AI → structured JSON → schema → business rules → sanitize).
 *
 * The deterministic business rule (below) is the anti-fabrication wall:
 *  - every claim_id must exist in the allowed map (VERIFIED/PARTIALLY_VERIFIED);
 *  - every stat/date/quote in a paragraph must appear in the snippet of a
 *    claim referenced by that section;
 *  - banned (unverified/contradicted) content may never leak into the output;
 *  - verbatim snippets are reproduction and are rejected.
 *
 * @package NewsDesk\AI\Application\Content
 */

namespace NewsDesk\AI\Application\Content;

defined( 'ABSPATH' ) || exit;

use NewsDesk\AI\Application\Ai\AiGateway;
use NewsDesk\AI\Application\Ai\Prompts;
use NewsDesk\AI\Logging\Contracts\LoggerInterface;
use NewsDesk\AI\Support\Locale;

final class ContentComposer {

	/** @var AiGateway */
	private $gateway;
	/** @var LoggerInterface */
	private $logger;

	public function __construct( AiGateway $gateway, LoggerInterface $logger ) {
		$this->gateway = $gateway;
		$this->logger  = $logger;
	}

	/**
	 * @param array $plan    ContentStrategy::plan() output
	 * @param array $memory  ContentMemory::build() output
	 * @return array{data: array, provider: string, model: string, prompt_version: string, schema_version: string}
	 */
	public function compose( array $plan, array $memory, int $jobId, string $feedback = '' ): array {
		$allowedList = array();
		foreach ( $memory['allowed'] as $c ) {
			$allowedList[] = array(
				'claim_id' => $c['claim_id'],
				'type'     => $c['type'],
				'fact'     => $c['text'],
				'snippet'  => $c['snippet'],
				'url'      => $c['source_url'],
			);
		}

		$keyFacts = array();
		foreach ( (array) ( $plan['key_facts'] ?? array() ) as $i => $kf ) {
			$keyFacts[] = ( $i + 1 ) . '. ' . $kf;
		}
		$vars = array(
			'news_type'   => (string) ( $plan['news_type'] ?? 'update' ),
			'key_facts'   => $keyFacts ? implode( "\n", $keyFacts ) : '(none provided — derive from allowed facts)',
			'story_title' => (string) ( $plan['primary_entity'] ?? '' ),
			'angle'       => (string) ( $plan['angle'] ?? '' ),
			'question'    => (string) ( $plan['question'] ?? '' ),
			'outline'     => (string) ( $plan['outline'] ?? '' ),
			'content'     => json_encode( array( 'allowed_facts' => $allowedList, 'story_topics' => isset( $plan['topics'] ) ? $plan['topics'] : array() ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			'banned'      => json_encode( array_values( $memory['banned'] ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			'feedback'    => '' !== $feedback ? $feedback : 'none',
			'language_rule' => Prompts::languageRule( (string) ( $plan['lang'] ?? Locale::lang() ), (string) ( $plan['source_lang'] ?? $plan['lang'] ?? Locale::lang() ) ),
		);

		// §76-style explicit contract: business rule is pure & total.
		$rule = function ( array $data ) use ( $memory, $plan ): array {
			return self::businessRule( $data, $memory, $plan );
		};

		return $this->gateway->structured(
			Prompts::ID_CONTENT,
			(string) ( $plan['lang'] ?? Locale::lang() ),
			$vars,
			Prompts::SCHEMA_CONTENT,
			$jobId,
			'content',
			$rule
		);
	}

	/**
	 * Deterministic business rule. Returns error strings (empty = pass).
	 *
	 * @param array $data   AI output (already schema-validated)
	 * @param array $memory ContentMemory::build() output
	 * @param array $plan   ContentStrategy::plan() output
	 * @return string[]
	 */
	/** @var string plan text (title/angle/outline/topics) used as date-grounding corpus */
	private static $planText = '';

	public static function businessRule( array $data, array $memory, array $plan ): array {
		self::$planText = implode( ' ', array( (string) ( $plan['primary_entity'] ?? '' ), (string) ( $plan['angle'] ?? '' ), (string) ( $plan['outline'] ?? '' ), implode( ' ', (array) ( $plan['topics'] ?? array() ) ) ) );
		$errors   = array();
		$allowed  = $memory['allowed'];
		$allowedTexts = array();
		foreach ( $allowed as $id => $c ) {
			$allowedTexts[ $id ] = Grounding::normalize( (string) $c['text'] );
		}

		$fields = array(
			'title'            => (string) ( $data['title'] ?? '' ),
			'meta_description' => (string) ( $data['meta_description'] ?? '' ),
			'lead'             => (string) ( $data['lead'] ?? '' ),
		);
		if ( '' === $fields['title'] || '' === $fields['lead'] ) {
			$errors[] = 'title/lead must not be empty';
		}

		// §13-style: primary entity must be in the title (SEO).
		$primary = (string) ( $plan['primary_entity'] ?? '' );
		$lang    = (string) ( $plan['lang'] ?? Locale::lang() );
		$srcLang = (string) ( $plan['source_lang'] ?? $lang );
		if ( '' !== $primary && false === mb_stripos( $fields['title'], $primary ) && $lang === $srcLang ) {
			// Cross-language output may legitimately transliterate the entity
			// (WordPress → وردپرس); only enforce when languages match.
			$errors[] = 'TITLE_ENTITY: title must mention "' . $primary . '"';
		}

		// v1.5: output language contract — the article must actually be in the
		// requested language (models often "forget" and answer in English).
		$probe = $fields['title'] . ' ' . $fields['lead'];
		foreach ( (array) ( $data['sections'] ?? array() ) as $sec ) {
			$probe .= ' ' . implode( ' ', array_map( 'strval', (array) ( $sec['paragraphs'] ?? array() ) ) );
		}
		if ( ! self::isInLanguage( $probe, $lang ) ) {
			$errors[] = 'LANGUAGE_MISMATCH: the article must be written entirely in ' . $lang . ' (title, lead, all paragraphs). Rewrite every field in ' . $lang . '.';
		}

		// Banned claims must never leak into any output field.
		foreach ( $memory['banned'] as $bannedText ) {
			$bannedNorm = Grounding::normalize( (string) $bannedText );
			if ( mb_strlen( $bannedNorm ) < 12 ) {
				continue;
			}
			foreach ( $fields as $field => $value ) {
				if ( false !== mb_stripos( Grounding::normalize( $value ), $bannedNorm ) ) {
					$errors[] = 'BANNED_CLAIM_LEAK: ' . $field . ' quotes an unverified claim';
					break;
				}
			}
		}

		// No verbatim source reproduction in any field.
		foreach ( $fields as $field => $value ) {
			foreach ( $allowed as $c ) {
				if ( Grounding::containsSnippet( $value, (string) $c['snippet'] ) ) {
					$errors[] = 'REPRODUCTION: ' . $field . ' copies a source snippet verbatim';
					break;
				}
			}
		}

		// Sections.
		$sections = (array) ( $data['sections'] ?? array() );
		if ( count( $sections ) < 2 ) {
			$errors[] = 'SECTIONS_MIN: at least 2 sections required';
		}

		// v1.6 — editorial coverage rules (anti over-summarisation).
		foreach ( self::coverageErrors( $data, $memory, $plan ) as $e ) {
			$errors[] = $e;
		}
		foreach ( $sections as $i => $section ) {
			$ids = array_values( array_filter( array_map( 'strval', (array) ( $section['claim_ids'] ?? array() ) ) ) );
			if ( ! $ids ) {
				$errors[] = 'SECTION_' . $i . '_UNGROUNDED: no claim_ids';
				continue;
			}
			foreach ( $ids as $id ) {
				if ( ! isset( $allowed[ $id ] ) ) {
					$errors[] = 'SECTION_' . $i . '_UNKNOWN_CLAIM: ' . $id;
				}
			}
			foreach ( (array) ( $section['paragraphs'] ?? array() ) as $p ) {
				foreach ( $allowed as $c ) {
					if ( Grounding::containsSnippet( $p, (string) $c['snippet'] ) ) {
						$errors[] = 'SECTION_' . $i . '_REPRODUCTION';
						break;
					}
				}
				$errs = self::checkParagraphGrounding( $p, $ids, $allowed );
				foreach ( $errs as $e ) {
					$errors[] = 'SECTION_' . $i . '_' . $e;
				}
			}
		}

		// FAQ answers must be grounded too.
		foreach ( (array) ( $data['faq'] ?? array() ) as $i => $item ) {
			$ids = array_values( array_filter( array_map( 'strval', (array) ( $item['claim_ids'] ?? array() ) ) ) );
			if ( ! $ids ) {
				$errors[] = 'FAQ_' . $i . '_UNGROUNDED';
				continue;
			}
			$errs = self::checkParagraphGrounding( (string) $item['answer'], $ids, $allowed );
			foreach ( $errs as $e ) {
				$errors[] = 'FAQ_' . $i . '_' . $e;
			}
		}

		return array_values( array_unique( $errors ) );
	}

	/**
	 * v1.6 editorial coverage checks. Pure & deterministic. Returns errors that
	 * are fed back to the model as revision feedback.
	 *
	 *  - COVERAGE_LOW: the article cites too small a share of the allowed
	 *    facts when many are available (mechanical summary).
	 *  - PLATFORM_OMITTED: a platform/browser/OS named in the evidence is
	 *    missing from the article.
	 *  - PRIVACY_OMITTED: evidence talks about privacy/data handling but the
	 *    article never does.
	 *  - WHY_IT_MATTERS_MISSING: no analysis section at the end.
	 *  - NEWS_TYPE_MISMATCH: a release/new-product story titled as "features".
	 *  - FAQ_TOO_FEW: fewer than 3 FAQ items while ≥ 8 facts are available.
	 *
	 * @return string[]
	 */
	public static function coverageErrors( array $data, array $memory, array $plan ): array {
		$errors  = array();
		$allowed = (array) ( $memory['allowed'] ?? array() );
		$n       = count( $allowed );
		$sections = (array) ( $data['sections'] ?? array() );

		$body = (string) ( $data['title'] ?? '' ) . ' ' . (string) ( $data['lead'] ?? '' );
		$used = array();
		foreach ( $sections as $sec ) {
			$body .= ' ' . (string) ( $sec['heading'] ?? '' ) . ' ' . implode( ' ', array_map( 'strval', (array) ( $sec['paragraphs'] ?? array() ) ) );
			foreach ( (array) ( $sec['claim_ids'] ?? array() ) as $id ) {
				$used[ (string) $id ] = true;
			}
		}
		foreach ( (array) ( $data['faq'] ?? array() ) as $f ) {
			$body .= ' ' . (string) ( $f['question'] ?? '' ) . ' ' . (string) ( $f['answer'] ?? '' );
			foreach ( (array) ( $f['claim_ids'] ?? array() ) as $id ) {
				$used[ (string) $id ] = true;
			}
		}
		$bodyLow = mb_strtolower( $body );

		// 1) share of allowed facts actually used.
		if ( $n >= 6 ) {
			$usedAllowed = 0;
			foreach ( array_keys( $used ) as $id ) {
				if ( isset( $allowed[ $id ] ) ) {
					$usedAllowed++;
				}
			}
			$ratio = $usedAllowed / $n;
			$min   = $n >= 20 ? 0.5 : 0.6;
			if ( $ratio < $min ) {
				$missing = array();
				foreach ( $allowed as $id => $c ) {
					if ( ! isset( $used[ $id ] ) ) {
						$missing[] = mb_substr( (string) $c['text'], 0, 90 );
					}
					if ( count( $missing ) >= 8 ) {
						break;
					}
				}
				$errors[] = sprintf( 'COVERAGE_LOW: only %d of %d allowed facts are used (need ≥ %d%%). Uncovered facts include: %s', $usedAllowed, $n, (int) ( $min * 100 ), implode( ' | ', $missing ) );
			}
		}

		// 2) platforms named in evidence must appear in the article.
		$platforms = array( 'chrome', 'chromium', 'firefox', 'safari', 'edge', 'opera', 'brave', 'macos', 'windows', 'linux', 'android', 'ios', 'ipados', 'php 8', 'php 7', 'mysql', 'mariadb', 'nginx', 'apache', 'docker', 'wp-cli', 'multisite', 'gutenberg', 'classic editor', 'woocommerce' );
		$corpus = '';
		foreach ( $allowed as $c ) {
			$corpus .= ' ' . mb_strtolower( (string) $c['text'] . ' ' . (string) $c['snippet'] );
		}
		$omitted = array();
		foreach ( $platforms as $p ) {
			if ( false !== mb_strpos( $corpus, $p ) && false === mb_strpos( $bodyLow, $p ) && false === mb_strpos( $bodyLow, self::faAlias( $p ) ) ) {
				$omitted[] = $p;
			}
		}
		if ( $omitted ) {
			$errors[] = 'PLATFORM_OMITTED: evidence mentions ' . implode( ', ', $omitted ) . ' but the article does not — list every supported platform/browser/OS.';
		}

		// 3) privacy / data handling.
		$privacyRe = '/(privacy|personal data|telemetry|tracking|analytics|data (is|are) (stored|sent|collected)|does not (send|collect)|no data|local(ly)? stored|حریم خصوصی|داده(‌| )?(ها)? (ذخیره|ارسال)|ردیابی)/u';
		if ( preg_match( $privacyRe, $corpus ) && ! preg_match( $privacyRe, $bodyLow ) ) {
			$errors[] = 'PRIVACY_OMITTED: the evidence covers privacy/data handling but the article has no privacy/security section — add one grounded in those claims.';
		}

		// 4) why-it-matters analysis section.
		$hasAnalysis = false;
		foreach ( $sections as $sec ) {
			$h = mb_strtolower( (string) ( $sec['heading'] ?? '' ) );
			if ( 'analysis' === ( $sec['type'] ?? '' ) || false !== mb_strpos( $h, 'why' ) || false !== mb_strpos( $h, 'چرا' ) || false !== mb_strpos( $h, 'اهمیت' ) || false !== mb_strpos( $h, 'معنا' ) ) {
				$hasAnalysis = true;
				break;
			}
		}
		if ( ! $hasAnalysis && count( $sections ) >= 2 ) {
			$errors[] = 'WHY_IT_MATTERS_MISSING: add a final type=analysis section ("چرا این خبر مهم است؟" / "Why it matters") explaining impact for WordPress users, the problem solved and who benefits.';
		}

		// 5) news-type vs headline.
		$newsType = (string) ( $plan['news_type'] ?? '' );
		$title    = mb_strtolower( (string) ( $data['title'] ?? '' ) );
		if ( in_array( $newsType, array( 'release', 'new_product', 'official_announcement' ), true ) ) {
			$featureWords = '/(ویژگی(‌| )?های جدید|قابلیت(‌| )?های جدید|new features?|what\'?s new)/u';
			$releaseWords = '/(منتشر|معرفی|عرضه|در دسترس|رونمایی|released?|launch|introduc|now available|announc|unveil)/u';
			if ( preg_match( $featureWords, $title ) && ! preg_match( $releaseWords, $title ) ) {
				$errors[] = 'NEWS_TYPE_MISMATCH: this story is a ' . $newsType . ' — the headline must state the release/introduction itself (e.g. "… منتشر شد"), not merely "new features".';
			}
		}

		// 6) FAQ depth.
		$faqCount = count( (array) ( $data['faq'] ?? array() ) );
		if ( $n >= 8 && $faqCount < 3 ) {
			$errors[] = 'FAQ_TOO_FEW: provide 3–6 search-intent FAQ items answerable from the allowed facts (what is it, which platforms, key features, developer use, data handling, how to use).';
		}

		return $errors;
	}

	/** Persian aliases used when checking platform coverage. */
	private static function faAlias( string $p ): string {
		$map = array( 'chrome' => 'کروم', 'chromium' => 'کرومیوم', 'firefox' => 'فایرفاکس', 'safari' => 'سافاری', 'edge' => 'اج', 'windows' => 'ویندوز', 'macos' => 'مک', 'linux' => 'لینوکس', 'android' => 'اندروید', 'ios' => 'آی‌او‌اس', 'gutenberg' => 'گوتنبرگ', 'woocommerce' => 'ووکامرس', 'multisite' => 'چندسایته', 'docker' => 'داکر' );
		return $map[ $p ] ?? $p;
	}

	/**
	 * Script-based language check. Persian/Arabic: ≥60% of letters must be
	 * Arabic-script; English: ≥85% Latin. Brand names in Latin are tolerated.
	 */
	public static function isInLanguage( string $text, string $lang ): bool {
		$arabic = preg_match_all( '/\p{Arabic}/u', $text );
		$latin  = preg_match_all( '/\p{Latin}/u', $text );
		$total  = $arabic + $latin;
		if ( $total < 40 ) {
			return true; // too short to judge
		}
		if ( 'fa' === $lang || 'ar' === $lang ) {
			return $arabic / $total >= 0.6;
		}
		if ( 'en' === $lang ) {
			return $latin / $total >= 0.85;
		}
		return true;
	}

	/**
	 * @param string[] $sectionClaimIds
	 * @param array<string, array<string, mixed>> $allowed
	 * @return string[]
	 */
	public static function checkParagraphGrounding( string $paragraph, array $sectionClaimIds, array $allowed ): array {
		$errors = array();
		$stats  = Grounding::extractStats( $paragraph );
		$dates  = Grounding::extractDates( $paragraph );
		$quotes = Grounding::extractQuotes( $paragraph );

		if ( ! $stats && ! $dates && ! $quotes ) {
			return $errors;
		}

		$snippets = '';
		$types    = array();
		foreach ( $sectionClaimIds as $id ) {
			if ( isset( $allowed[ $id ] ) ) {
				$snippets .= ' ' . (string) $allowed[ $id ]['snippet'];
				$types[]   = (string) $allowed[ $id ]['type'];
			}
		}

		foreach ( $stats as $stat ) {
			$found = false;
			foreach ( $sectionClaimIds as $id ) {
				if ( isset( $allowed[ $id ] ) && 'stat' === $allowed[ $id ]['type'] && false !== mb_stripos( (string) $allowed[ $id ]['snippet'], $stat ) ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				$errors[] = 'STAT_UNGROUNDED(' . $stat . ')';
			}
		}
		// v1.3.3: a date is grounded if it appears in the section's claims
		// (snippet OR claim text) — or, for a bare year, anywhere in the allowed
		// corpus (years are part of product names, "WordPress 2023 survey", etc.).
		$corpus = '';
		foreach ( $allowed as $c ) {
			$corpus .= ' ' . (string) ( $c['snippet'] ?? '' ) . ' ' . (string) ( $c['text'] ?? '' );
		}
		$corpus .= ' ' . self::$planText;
		foreach ( $dates as $date ) {
			$found = false;
			foreach ( $sectionClaimIds as $id ) {
				if ( isset( $allowed[ $id ] ) && ( false !== mb_stripos( (string) $allowed[ $id ]['snippet'], $date ) || false !== mb_stripos( (string) ( $allowed[ $id ]['text'] ?? '' ), $date ) ) ) {
					$found = true;
					break;
				}
			}
			if ( ! $found && preg_match( '/^\d{4}$/', $date ) && false !== mb_strpos( $corpus, $date ) ) {
				$found = true;
			}
			if ( ! $found && preg_match( '/^\d{4}$/', $date ) && (int) $date === (int) gmdate( 'Y' ) ) {
				$found = true; // the current year is never an "invented" fact
			}
			if ( ! $found ) {
				$errors[] = 'DATE_UNGROUNDED(' . $date . ')';
			}
		}
		foreach ( $quotes as $quote ) {
			$found = false;
			foreach ( $sectionClaimIds as $id ) {
				if ( isset( $allowed[ $id ] ) && 'quote' === $allowed[ $id ]['type'] && false !== mb_stripos( Grounding::normalize( (string) $allowed[ $id ]['snippet'] ), $quote ) ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				$errors[] = 'QUOTE_UNGROUNDED(' . mb_substr( $quote, 0, 24 ) . '…)';
			}
		}
		return $errors;
	}
}
