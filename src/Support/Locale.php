<?php
/**
 * The site's publishing language.
 *
 * Every default used to be hard-coded to Persian, which is correct for exactly
 * one installation and wrong for every other buyer. These helpers derive the
 * default from WordPress itself and fall back to English, so a fresh install
 * behaves like the site it was installed on.
 *
 * @package NewsDesk\AI\Support
 */

declare(strict_types=1);

namespace NewsDesk\AI\Support;

defined( 'ABSPATH' ) || exit;

final class Locale {

	/** Last-resort locale when WordPress is unavailable (CLI, tests). */
	public const FALLBACK_LOCALE = 'en_US';

	/** Last-resort two-letter language code. */
	public const FALLBACK_LANG = 'en';

	/**
	 * Full WordPress locale, e.g. "en_US", "fa_IR", "de_DE".
	 */
	public static function locale(): string {
		if ( function_exists( 'get_locale' ) ) {
			$locale = (string) get_locale();
			if ( '' !== $locale ) {
				return $locale;
			}
		}
		return self::FALLBACK_LOCALE;
	}

	/**
	 * Two-letter language code taken from the site locale, e.g. "en", "fa".
	 */
	public static function lang(): string {
		$lang = strtolower( substr( self::locale(), 0, 2 ) );
		return '' !== $lang ? $lang : self::FALLBACK_LANG;
	}

	/**
	 * True when the site language is written right-to-left.
	 */
	public static function isRtl(): bool {
		return in_array( self::lang(), array( 'fa', 'ar', 'he', 'ur', 'ps', 'sd', 'yi', 'dv', 'ckb' ), true );
	}
}
