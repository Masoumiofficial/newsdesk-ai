<?php
/**
 * A-13 — the news category taxonomy.
 *
 * The spec names fifteen categories. They are not WordPress terms: they
 * classify the STORY for routing and scoring (a security advisory and an
 * opinion piece deserve different urgency), and only then may be mapped to a
 * site category.
 *
 * Classification is keyword-based and deterministic. A wrong guess here is
 * cheap and visible; an AI call would be neither.
 *
 * @package NewsDesk\AI\Domain
 */

namespace NewsDesk\AI\Domain;

defined( 'ABSPATH' ) || exit;

final class NewsCategory {

	public const CORE_RELEASE   = 'CORE_RELEASE';
	public const SECURITY       = 'SECURITY';
	public const PLUGIN_NEWS    = 'PLUGIN_NEWS';
	public const THEME_NEWS     = 'THEME_NEWS';
	public const GUTENBERG      = 'GUTENBERG';
	public const PERFORMANCE    = 'PERFORMANCE';
	public const ECOMMERCE      = 'ECOMMERCE';
	public const HOSTING        = 'HOSTING';
	public const COMMUNITY      = 'COMMUNITY';
	public const BUSINESS       = 'BUSINESS';
	public const TUTORIAL       = 'TUTORIAL';
	public const AI_TOOLS       = 'AI_TOOLS';
	public const ACCESSIBILITY  = 'ACCESSIBILITY';
	public const SEO_NEWS       = 'SEO_NEWS';
	public const OTHER          = 'OTHER';

	/** All fifteen, in routing priority order. */
	public const ALL = array(
		self::SECURITY,
		self::CORE_RELEASE,
		self::GUTENBERG,
		self::PLUGIN_NEWS,
		self::THEME_NEWS,
		self::ECOMMERCE,
		self::PERFORMANCE,
		self::ACCESSIBILITY,
		self::SEO_NEWS,
		self::AI_TOOLS,
		self::HOSTING,
		self::BUSINESS,
		self::COMMUNITY,
		self::TUTORIAL,
		self::OTHER,
	);

	/**
	 * Keyword markers per category, checked in ALL order so the most
	 * consequential classification wins a tie.
	 *
	 * @var array<string, string[]>
	 */
	private const MARKERS = array(
		self::SECURITY       => array( 'security', 'vulnerab', 'cve-', 'exploit', 'malware', 'patch', 'breach', 'امنیت', 'آسیب‌پذیر', 'بدافزار', 'رخنه' ),
		self::CORE_RELEASE   => array( 'wordpress 6', 'wordpress 7', 'core release', 'release candidate', 'beta 1', 'now available', 'انتشار وردپرس', 'نسخه جدید وردپرس' ),
		self::GUTENBERG      => array( 'gutenberg', 'block editor', 'full site editing', 'block theme', 'ویرایشگر بلوک', 'گوتنبرگ' ),
		self::PLUGIN_NEWS    => array( 'plugin', 'add-on', 'extension', 'افزونه' ),
		self::THEME_NEWS     => array( 'theme', 'template', 'قالب', 'پوسته' ),
		self::ECOMMERCE      => array( 'woocommerce', 'ecommerce', 'e-commerce', 'checkout', 'payment', 'فروشگاه', 'ووکامرس', 'پرداخت' ),
		self::PERFORMANCE    => array( 'performance', 'speed', 'core web vitals', 'caching', 'optimiz', 'سرعت', 'بهینه‌سازی', 'کش' ),
		self::ACCESSIBILITY  => array( 'accessibility', 'a11y', 'wcag', 'screen reader', 'دسترس‌پذیری' ),
		self::SEO_NEWS       => array( 'seo', 'search engine', 'google search', 'ranking', 'serp', 'سئو', 'موتور جستجو' ),
		self::AI_TOOLS       => array( ' ai ', 'artificial intelligence', 'machine learning', 'llm', 'chatgpt', 'copilot', 'هوش مصنوعی' ),
		self::HOSTING        => array( 'hosting', 'server', 'php 8', 'mysql', 'cdn', 'میزبانی', 'هاست', 'سرور' ),
		self::BUSINESS       => array( 'acquisition', 'funding', 'revenue', 'merger', 'hires', 'layoff', 'ipo', 'خرید شرکت', 'سرمایه‌گذاری', 'درآمد' ),
		self::COMMUNITY      => array( 'wordcamp', 'community', 'contributor', 'meetup', 'foundation', 'انجمن', 'رویداد', 'وردکمپ' ),
		self::TUTORIAL       => array( 'how to', 'tutorial', 'guide', 'step by step', 'آموزش', 'راهنما', 'چگونه' ),
	);

	/** Human labels for the admin UI. */
	public static function labels(): array {
		return array(
			self::SECURITY      => __( 'Security', 'newsdesk-ai' ),
			self::CORE_RELEASE  => __( 'Core release', 'newsdesk-ai' ),
			self::GUTENBERG     => __( 'Gutenberg', 'newsdesk-ai' ),
			self::PLUGIN_NEWS   => __( 'Plugins', 'newsdesk-ai' ),
			self::THEME_NEWS    => __( 'Templates', 'newsdesk-ai' ),
			self::ECOMMERCE     => __( 'eCommerce', 'newsdesk-ai' ),
			self::PERFORMANCE   => __( 'Performance', 'newsdesk-ai' ),
			self::ACCESSIBILITY => __( 'Availability', 'newsdesk-ai' ),
			self::SEO_NEWS      => __( 'SEO', 'newsdesk-ai' ),
			self::AI_TOOLS      => __( 'AI', 'newsdesk-ai' ),
			self::HOSTING       => __( 'Hosting', 'newsdesk-ai' ),
			self::BUSINESS      => __( 'Business', 'newsdesk-ai' ),
			self::COMMUNITY     => __( 'Community', 'newsdesk-ai' ),
			self::TUTORIAL      => __( 'Guide', 'newsdesk-ai' ),
			self::OTHER         => __( 'Other', 'newsdesk-ai' ),
		);
	}

	public static function isValid( string $category ): bool {
		return in_array( strtoupper( $category ), self::ALL, true );
	}

	/**
	 * Classify a story from its text. Returns OTHER when nothing matches —
	 * never a guess.
	 */
	public static function classify( string $title, string $body = '' ): string {
		$text = ' ' . mb_strtolower( $title . ' ' . $body, 'UTF-8' ) . ' ';
		foreach ( self::ALL as $category ) {
			foreach ( self::MARKERS[ $category ] ?? array() as $marker ) {
				if ( false !== mb_strpos( $text, $marker, 0, 'UTF-8' ) ) {
					return $category;
				}
			}
		}
		return self::OTHER;
	}
}
