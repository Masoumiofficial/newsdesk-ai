# Changelog — NewsDesk AI

All notable changes to this plugin are recorded here.
Format based on [Keep a Changelog](https://keepachangelog.com/),
versioning follows [SemVer](https://semver.org/).

---

## [1.0.1] — 2026-10-08

Patch release: one defect, and the guard that would have caught it.

### Fixed

- **PHP 7.4 fatal error in the date helper.** `Time::toDb()` called
  `DateTimeImmutable::createFromInterface()`, which PHP only added in 8.0, while
  the plugin declares 7.4 as its floor. On 7.4 every write that stored a date
  raised `Call to undefined method DateTimeImmutable::createFromInterface()`,
  and the syntax sweep in `tests/run.sh` could not see it — that sweep greps
  for post-7.4 *syntax*, not for post-7.4 *functions*. Dates are now converted
  through the Unix timestamp, which exists on 7.4, preserves the instant for
  `DateTime` and `DateTimeImmutable` alike, and leaves the caller's object
  untouched.

### Added

- `tests/test-u0-php-floor.php` — a static scan of the shipped tree for calls
  to functions and methods that 7.4 does not have, and for functions that 8.0
  removed. A call passes only when the file guards it first (`function_exists()`
  / `method_exists()`), so a polyfill keeps working while a reintroduced
  `createFromInterface()` fails the suite and names the file.
- Regression coverage for mutable, immutable and null inputs to `Time::toDb()`.

### Changed

- The suite figures quoted in `readme.txt` and the repository README were stale
  (21 files / 739 assertions). They now state what the suite is: 23 files and
  802 assertions.
- No schema change: `NEWSDESK_DB_VERSION` stays at `1.0.0`, so an update
  applies no database work.

---

## [1.0.0] — 2026-10-07

First public release.

### Pipeline

- Nineteen-phase pipeline from source discovery to WordPress draft.
- RSS, Atom and HTML source adapters. HTML extraction via JSON-LD → OpenGraph →
  heuristics, with optional per-source CSS selectors and URL filtering.
- Canonical-URL normalization (tracking parameters stripped), content hashing and a
  layered duplicate ladder with drillable duplicate clusters.
- Story clustering across sources, then eight-axis news scoring: freshness, source
  trust, impact, relevance, search value, actionability, uniqueness, search
  visibility.
- News window ladder: 24 hours, widening to 7 days, then an explicit `NO NEWS`
  outcome. The system is permitted to produce nothing.
- Editorial selection with configurable quality and trust gates and a per-window
  capacity limit. Every rejection is logged with a named reason, not just a count.
- Cannibalization engine comparing each story against already-published posts and
  deciding between `NEW`, `UPDATE`, `REWRITE`, `MERGE`, `CORRECT`, `REPLACE` and
  `NO ARTICLE`.

### Verification

- Claim extraction and research against independent sources; two or more
  independent sources yield `SUPPORTED`, a single source is marked as such.
- Fact-check verdicts: `VERIFIED`, `PARTIALLY_VERIFIED`, `UNVERIFIED`,
  `CONTRADICTED`, `REJECTED`.
- Claim risk assessment (`CRITICAL`, `HIGH`, `MEDIUM`, `LOW`) with a mandated action
  per claim: keep, attribute, mark uncertain, research further, or remove.
- Deterministic security-intelligence extraction — CVE identifiers, CVSS score,
  severity, affected versions, fixed versions, exploitation status. Pattern-based
  by design; never delegated to a model.
- Fifteen-axis article audit totalling 100 points with a pass mark of 70. Six axes
  are blocking: title present, body present, claims grounded, no fabricated
  references, sources present, no leftover placeholders. The audit runs **before**
  draft creation, so a critical failure means no draft is written at all.
- Filler-phrase detection with a graduated penalty and a hard critical threshold.
- Correction workflow: a contradiction against a published post stages a correction
  and appends a dated, marked correction notice. The only operation in the plugin
  that touches live content.

### Output

- Every write is forced to `post_status = draft`; a caller-supplied post ID is
  stripped. Revision-bearing decisions stage a separate draft linked to its target.
- Auto-publish is hard-coded off and is not exposed as a setting.
- Native SEO metadata, plus Yoast SEO and Rank Math integration through their
  documented meta keys, with no-clobber protection on values set by hand.
- Image planning by default — aspect, minimum width, alt text, caption, concepts,
  search terms, exclusions and a licensing note. Generation is opt-in.

### Platform

- OpenAI, Google Gemini, GapGPT and generic OpenAI-compatible providers behind one
  interface with ordered fallback. No bundled, simulated or mock provider is ever
  presented as real.
- API keys encrypted at rest with keys derived from WordPress salts; eleven
  redaction patterns applied to all logging; no secret reaches a log, a screen or
  an export.
- SSRF hardening: every redirect hop revalidated, private and link-local ranges
  refused, decimal and hexadecimal IP obfuscation detected, 2 MB response cap.
- Twenty-three-state job machine with global, job and story locks, retry with
  backoff, heartbeats and a 30-minute lock TTL.
- Thirteen admin screens, including a draft review with a per-section evidence map.
- Structured logging at INFO / WARNING / ERROR / CRITICAL with retention controls;
  `0` means keep forever and story-attached records are never pruned.
- Schema version tracked in an option and reconciled on `admin_init`, so database
  changes apply on automatic, bulk and FTP updates — not only on manual activation.
- Uninstall keeps all data by default. Deletion requires explicit opt-in beforehand.
- Interface in English, fully internationalised, with a Persian translation bundled.
