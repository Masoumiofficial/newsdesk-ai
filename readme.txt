=== NewsDesk AI — Verified AI Newsroom for WordPress ===
Contributors: etehadwp
Tags: news, ai, fact-check, editorial, rss
Requires at least: 6.5
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An AI newsroom that refuses to publish what it cannot verify. Fact-checks every claim, audits every draft, and never auto-publishes.

== Description ==

Built by [EtehadWP](https://etehadwp.com/) — اتحاد وردپرس.

Most AI content plugins are built to produce as much text as possible, as fast as
possible. NewsDesk AI is built on the opposite premise: **the expensive mistake in
publishing is not slow output, it is wrong output.**

NewsDesk AI watches the sources you choose, works out what is genuinely new, checks
the factual claims against independent evidence, audits the finished article against
fifteen quality axes, and hands you a sourced **draft**. If the story does not clear
the bar, it tells you why and writes nothing.

It never publishes on its own. There is no setting to make it publish on its own.

= What makes it different =

* **It can decide not to write.** A relevance and trust gate runs before any AI cost is incurred. If nothing clears the bar in the last 24 hours, it widens to 7 days; if still nothing, it reports NO NEWS instead of inventing a story.
* **Every claim is fact-checked.** Claims are extracted, researched against independent sources, and labelled VERIFIED / PARTIALLY VERIFIED / UNVERIFIED / CONTRADICTED / REJECTED. Each one gets a risk level and an action — keep, attribute, mark uncertain, research further, or remove.
* **A 15-axis audit can block the draft.** Six axes are blocking: missing title, missing body, ungrounded claims, fabricated references, absent sources, leftover placeholders. A critical failure stops the draft from ever being created.
* **It knows what you already published.** Before writing, it compares the story against your existing posts and decides: NEW, UPDATE, REWRITE, MERGE, CORRECT, REPLACE — or NO ARTICLE. Cannibalising your own rankings is a decision, not an accident.
* **Corrections are first class.** When a new source contradicts a published post, NewsDesk AI stages a correction and appends a dated correction notice rather than quietly editing history.
* **Security reporting is handled properly.** CVE IDs, CVSS scores, severity, affected and fixed versions are extracted by deterministic pattern matching — never by an AI that might hallucinate a version number.

= How it works =

Discovery → normalization → deduplication → clustering → news scoring → editorial
selection → research → evidence → fact-check → claim risk → original drafting →
SEO/AEO/GEO → internal and external linking → quality gate → 15-axis audit →
image plan → WordPress **draft**.

Stories are scored on seven axes — freshness, source trust, impact, relevance,
search value, actionability and uniqueness — plus a search-visibility axis. Only
stories above your configured gate proceed.

= Sources =

RSS, Atom and arbitrary HTML pages. Web sources are parsed via JSON-LD, then
OpenGraph, then heuristics, with optional per-source CSS selectors when a site needs
help. Every fetch is SSRF-hardened: redirects are revalidated hop by hop, private and
link-local ranges are refused, decimal and hexadecimal IP obfuscation is detected,
and responses are capped at 2 MB.

Each source carries a type (PRIMARY, TECHNICAL, MEDIA, COMMUNITY, SECURITY) and a
trust tier from 1 to 4, which feeds directly into scoring and fact-check weighting.

= AI providers =

OpenAI, Google Gemini, GapGPT, and any OpenAI-compatible endpoint, behind one
interface with ordered fallback. You supply your own API keys; they are encrypted at
rest using your WordPress salts and are never logged, never rendered on screen, and
never included in exports. No provider is bundled, simulated or faked.

= SEO =

Writes its own meta, and detects Yoast SEO and Rank Math to populate their fields
using their documented meta keys — without ever overwriting a value you set by hand.

== Frequently Asked Questions ==

= Does it publish automatically? =
No. Auto-publish is hard-coded off and is not configurable. Every piece of output is
a draft awaiting a human editor.

= Will it invent facts? =
It is built specifically to avoid that. Claims are checked against independent
sources, fabricated references are a blocking audit failure, and unverifiable
statements are flagged, attributed or removed rather than published. No system can
promise perfection, which is exactly why a human approves every draft.

= Do I need an API key? =
Yes — one from OpenAI, Gemini, GapGPT, or any OpenAI-compatible provider. Discovery,
deduplication, scoring and editorial selection all run without AI; only research and
drafting call a provider, so you are not paying for stories that never clear the gate.

= Does it generate images? =
By default it produces an **image plan** — aspect ratio, minimum width, alt text,
caption, visual concepts, stock search terms and a licensing note. Actual generation
is opt-in.

= What happens when I uninstall? =
All data is kept by default. Deletion happens only if you explicitly enable it in
Settings before removing the plugin.

= Is it translated? =
The interface is English and fully translatable, with a Persian translation bundled.
Every string passes through WordPress i18n.

= Where is the test suite? =
The suite ships in the development repository, not in the release zip, to keep the
install lean. It is 23 files and 802 assertions, and it is what caught most of the
bugs listed in the changelog.

= Who makes this, and where do I get help? =
NewsDesk AI is built and maintained by [EtehadWP](https://etehadwp.com/) (اتحاد وردپرس).
Documentation lives at https://etehadwp.com/newsdesk-ai/docs/ and support requests go
to https://etehadwp.com/support/.

== Screenshots ==

1. Dashboard — pipeline status, recent runs and pending reviews at a glance.
2. News inbox — everything crawled, with duplicate clusters drillable.
3. Draft review — the article beside its evidence map, claim by claim.
4. Sources — per-source type, trust tier, health and last fetch.
5. Settings — gates, windows, schedule and provider configuration.

== Changelog ==

= 1.0.1 =
Patch release: a PHP 7.4 fatal error, and the guard that catches it next time.

* Fixed: the date helper called `DateTimeImmutable::createFromInterface()`, a
  PHP 8.0 method, while this plugin supports PHP 7.4. On 7.4 every write that
  stored a date failed with "Call to undefined method". Dates now convert
  through the Unix timestamp, which works on 7.4 and does not modify the
  object handed in.
* Added: a static PHP-floor guard in the test suite. Calling an API newer than
  7.4, or a function removed in PHP 8.0, now fails the build unless the file
  guards the call itself.
* Corrected: the test-suite figures quoted in this readme were stale.
* No database change — updating replaces files only.

= 1.0.0 =
First public release.

* Full discovery → draft pipeline across nineteen phases.
* RSS, Atom and HTML source adapters with SSRF-hardened fetching.
* Source typing and four-tier trust model feeding scoring and fact-checking.
* Eight-axis news scoring with configurable quality and trust gates.
* 24-hour to 7-day news window ladder with an explicit NO NEWS outcome.
* Cannibalization engine: NEW, UPDATE, REWRITE, MERGE, CORRECT, REPLACE, NO ARTICLE.
* Claim extraction, independent-source fact-checking and five-state verdicts.
* Claim risk assessment with per-claim keep / attribute / flag / remove actions.
* Deterministic security intelligence extraction (CVE, CVSS, affected and fixed versions).
* Fifteen-axis article audit with six blocking axes, run before draft creation.
* Correction workflow with dated, marked correction notices on published posts.
* OpenAI, Gemini, GapGPT and OpenAI-compatible providers with ordered fallback.
* Encrypted key storage, redacted logging, and no secrets in any output.
* Native SEO fields plus documented-key integration with Yoast SEO and Rank Math.
* Image planning by default, generation opt-in.
* Thirteen admin screens including a full draft review and evidence map.
* Twenty-three-state job machine with locks, retries, heartbeats and structured logs.
* Data retention controls; uninstall keeps all data unless you opt in to deletion.

== Upgrade Notice ==

= 1.0.1 =
Compatibility fix. On PHP 7.4 — the minimum this plugin supports — version
1.0.0 failed with "Call to undefined method" whenever it stored a date.
Updating replaces files only; no database change, no settings change.

= 1.0.0 =
First public release.
