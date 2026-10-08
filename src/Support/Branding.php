<?php
/**
 * Vendor and product identity, in one place.
 *
 * The product name is deliberately generic so the plugin can be sold to any
 * publisher; the vendor is the company that built it. Keeping both here means
 * a domain change is one edit, not a search across two hundred files -- which
 * is exactly how stale URLs ended up in user agents and headers before.
 *
 * @package NewsDesk\AI\Support
 */

declare(strict_types=1);

namespace NewsDesk\AI\Support;

defined( 'ABSPATH' ) || exit;

final class Branding {

	/** Product name. Not translated: it is a proper noun. */
	public const PRODUCT = 'NewsDesk AI';

	/** Vendor, in Latin script. The translatable form lives in the catalogue. */
	public const VENDOR = 'EtehadWP';

	/** Vendor home page. */
	public const VENDOR_URL = 'https://etehadwp.com/';

	/** Product page. */
	public const PRODUCT_URL = 'https://etehadwp.com/newsdesk-ai/';

	/** End-user documentation. */
	public const DOCS_URL = 'https://etehadwp.com/newsdesk-ai/docs/';

	/** Where a buyer reports a problem. */
	public const SUPPORT_URL = 'https://etehadwp.com/support/';

	/**
	 * User-Agent sent on every outbound request.
	 *
	 * Identifying the crawler and pointing at a page that explains it is basic
	 * good citizenship, and it is what gets you un-blocked when a publisher
	 * notices the traffic.
	 */
	public static function userAgent(): string {
		$version = defined( 'NEWSDESK_VERSION' ) ? NEWSDESK_VERSION : '1.0.1';
		return 'NewsDesk-AI/' . $version . ' (+' . rtrim( self::VENDOR_URL, '/' ) . ')';
	}
}
