<?php
/**
 * All in One SEO (AIOSEO) adapter — DETECTION ONLY, WRITES DISABLED (§76).
 *
 * WHY THIS ADAPTER DOES NOT WRITE
 * -------------------------------
 * AIOSEO 4.x does NOT store per-post SEO in post meta. It uses its own table,
 * {prefix}aioseo_posts, one row per post, with columns (title, description,
 * keywords, canonical_url, robots_*, og_*, twitter_*, schema, …) plus internal
 * bookkeeping. The legacy 3.x keys (_aioseop_title/_aioseop_description) are
 * only read by the 3.x→4.x importer and are ignored by current versions.
 *
 * Writing that table directly would mean reproducing AIOSEO's private schema
 * and migration behaviour, which the adapter contract explicitly forbids
 * ("adapters whose vendor contract is not verified MUST NOT guess"). AIOSEO
 * also exposes no public, documented, stable PHP API for setting a post's SEO
 * fields from another plugin.
 *
 * CONSEQUENCE: when AIOSEO is the active SEO plugin, the engine's package is
 * still written to our own _newsdesk_seo_* meta by NativeSeoAdapter, and the editor
 * can copy it into AIOSEO's metabox from the draft preview. isActive() returns
 * false so the resolver never routes to a no-op.
 *
 * TO IMPLEMENT LATER, VERIFY FIRST:
 *   - the documented public API (if AIOSEO adds one), or
 *   - the exact {prefix}aioseo_posts column contract for the supported range,
 *     including how AIOSEO invalidates its cache after an external write.
 *
 * @package NewsDesk\AI\Application\Seo
 */

namespace NewsDesk\AI\Application\Seo;

defined( 'ABSPATH' ) || exit;

final class AioseoAdapter implements SeoAdapterInterface {

	/** AIOSEO 4.x defines this in all-in-one-seo-pack.php. */
	private const DETECT_CONSTANT = 'AIOSEO_VERSION';

	public function id(): string {
		return 'aioseo';
	}

	/** True only when AIOSEO is installed (used for reporting, not routing). */
	public function isInstalled(): bool {
		return defined( self::DETECT_CONSTANT ) || function_exists( 'aioseo' );
	}

	/**
	 * Intentionally false: see the class docblock. Writing to AIOSEO's private
	 * table is not a verified contract, so this adapter never claims the post.
	 */
	public function isActive(): bool {
		return false;
	}

	public function apply( int $postId, array $seo ): void {
		// No-op by design. NativeSeoAdapter has already persisted the package.
	}
}
