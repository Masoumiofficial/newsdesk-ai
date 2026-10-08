# Architecture

## Layering

The codebase follows a ports-and-adapters layout. The dependency rule is one
way: **Domain ← Application ← Infrastructure**. Application code depends on
interfaces it declares itself; concrete WordPress and HTTP details live in
Infrastructure and are injected at the composition root.

```
src/
├── Core/            Bootstrap, container wiring, activation, autoloader
├── Domain/          Entities, value objects, state machine — no WordPress
├── Application/     Use cases, services, contracts (the actual pipeline)
├── Infrastructure/  wpdb repositories, HTTP, scheduler, providers
├── Admin/           Pages, actions, views
├── REST/            REST routes
├── Logging/         Logger, redaction
├── News/            Feed parsing and raw item shaping
└── Support/         Container, small shared helpers
```

`src/Core/Plugin.php` is the composition root and the only place that knows how
everything fits together. Nothing else constructs its own dependencies.

> **Note on namespaces.** The shipped namespace is `NewsDesk\AI`
> (three segments). Some specification text uses `NewsDesk\AI_Newsroom`. The
> shipped form is authoritative: renaming it would break every existing
> installation for no functional gain.

---

## Data model

17 tables, all prefixed `{$wpdb->prefix}nd_`:

| Table | Holds |
| --- | --- |
| `nd_sources` | Feeds, with type and tier |
| `nd_news_items` | Raw + normalised items, duplicate links |
| `nd_stories` | Clusters: scores, decision, security intel |
| `nd_story_sources` | Which items belong to which story |
| `nd_research_packages` | Per-story research bundle |
| `nd_research_claims` | Extracted claims, status, risk, action |
| `nd_fact_checks` | Every verification attempt and its verdict |
| `nd_content_versions` | Each generation attempt, score, status |
| `nd_generated_images` | Image plans and any generated result |
| `nd_internal_links` / `nd_external_links` | Link suggestions, attribution |
| `nd_jobs` / `nd_job_events` | Job records and their transitions |
| `nd_locks` | Concurrency control |
| `nd_logs` | Structured, redacted log lines |
| `nd_ai_usage` | Tokens and cost per call |
| `nd_prompt_versions` | Versioned prompts |

Two-tier by design: `news_items` is what arrived, `stories` is the editorial
unit. A story gathers many items; **one story never becomes more than one
article**.

### Migrations

Schema version lives in the `newsdesk_db_version` option and is
compared against `NEWSDESK_DB_VERSION` on `admin_init`. Migrations are
an ordered map in `Infrastructure/Database/Migrations.php`; applied versions
are recorded in `newsdesk_migrations_applied`.

`register_activation_hook()` fires **only** on manual activation — never on
automatic, bulk or FTP updates. Relying on it alone leaves sites with a new
codebase on an old schema, so the option-versus-constant check on `admin_init`
is the real upgrade path. Any new column requires a new migration entry **and**
a constant bump; a test enforces that the two agree.

---

## Pipeline stages

### Discovery and normalisation
Feeds are fetched through `SafeHttpClient`, which re-validates **every redirect
hop** against `IpValidator` (private ranges, loopback, link-local, plus decimal
and hex obfuscation and DNS names resolving to private addresses). Responses
are capped at 2 MB.

### Deduplication
Exact, canonical-URL and near-duplicate matching. Duplicates are **kept as
rows** and linked to the winner via `duplicate_of_id` / `duplicate_level`, so
the News Inbox can show why an item disappeared.

### Clustering and scoring
Related items are clustered by title-token overlap. `StoryScorer` produces the
axis scores and a composite, passed through the `newsdesk_news_score` filter
(clamped to 0–100 afterwards, so a badly behaved filter cannot bypass a gate).

### Window and cannibalisation
`NewsWindow`: 24h → 7d → `NO_NEWS`, descending only when a rung yields nothing.

`CannibalizationEngine` compares a candidate against the existing archive.
Overlap is shared title tokens divided by the **smaller** token set, so a short
headline is not unfairly diluted by a long one. Thresholds: strong 0.70,
related 0.45, none 0.30; stale after 180 days; major above score 80.

Precedence: `CORRECT → MERGE → [strong: REPLACE | NO_ARTICLE | UPDATE] →
REWRITE → UPDATE → NEW`. Only `NO_ARTICLE` blocks a draft outright.

### Research and fact-check
Claims are extracted, then checked against the gathered evidence. Two or more
independent sources ⇒ `SUPPORTED`; one ⇒ `SINGLE_SOURCE`. Rules always have the
final say; the optional AI cross-check is recorded for editors but never
overrides a rule.

`ClaimRiskAssessor` then assigns each claim a risk band and an action:

| Risk | Triggered by | Typical action |
| --- | --- | --- |
| `CRITICAL` | Exploits, breaches, malware, zero-days | `ATTRIBUTE` if partially verified, else `REMOVE` |
| `HIGH` | Legal/financial claims, statistics, quotes | `ATTRIBUTE`, or `RESEARCH_MORE` under 0.5 confidence |
| `MEDIUM` | Versions, dates | `MARK_UNCERTAIN` under 0.5 confidence, else `KEEP` |
| `LOW` | Ordinary description | `KEEP` |

Verified claims are kept; contradicted or rejected ones are removed rather than
hedged.

### Generation
`AiGateway` handles provider selection, retry, fallback (retryable errors
only), JSON-schema validation with a repair pass, prompt versioning, injection
defence and token accounting. Grounding is enforced: a shared run of 32+ words
with a source drives originality to zero.

### Quality gate, then audit
Two separate checks — see [README.md](README.md). The gate measures quality and
allows two revision loops; the audit blocks on safety. Both must pass.

### SEO/AEO/GEO and linking
The native adapter always writes `_newsdesk_seo_*` meta. Vendor adapters (Yoast,
Rank Math) run **only** when the plugin is detected and the meta keys have been
documented by the vendor, and they never overwrite a non-empty value an editor
has already set. AIOSEO 4.x stores per-post SEO in its own tables, so its
adapter stays inactive rather than guessing at a private schema.

### Image plan
Plan only: alt text, caption, three concepts, search terms, things to avoid, a
licence note, 16:9, minimum width 1200. Deterministic, free, no network. Real
generation is opt-in and never blocks a draft.

### Draft
Every write forces `post_status = draft` and strips any caller-supplied `ID`.
`UPDATE`/`REWRITE`/`MERGE`/`CORRECT`/`REPLACE` stage a revision draft carrying
`_newsdesk_revises_post` and `_newsdesk_revision_kind`. The single live mutation in the
whole plugin is `appendCorrectionNotice()`, which appends a visible correction
block to an already-published post and stamps `_newsdesk_corrected_at`.

---

## Jobs and concurrency

23-state machine, every transition recorded in `nd_job_events` with a
correlation ID. `LockManager` guards concurrent runs (TTL 1800s, heartbeat
300s). Queue backend is Action Scheduler when available, WP-Cron otherwise,
chosen by `QueueFactory`.

---

## Security posture

- **SSRF:** redirect-hop revalidation, private-range and obfuscation detection,
  2 MB response cap.
- **Admin:** all 15 `admin_post_` handlers pass through one `guard()` doing
  nonce + capability checks.
- **Secrets:** libsodium, or AES-256-GCM keyed from `AUTH_KEY`. Never logged,
  never rendered.
- **Logs:** redacted against 11 patterns before storage.
- **Prompt injection:** source text is fenced and instructed against; a
  canonical system prompt is prepended to every call.
- **Output:** everything escaped at render; 192 files carry `ABSPATH` guards.

---

## Internationalisation

Persian-first, RTL throughout. 524 translation calls against a 1878-line `.pot`.
Multi-language content (fa/en/ar) with WPML and Polylang awareness.

---

## Deliberate limitations

- **No auto-publish.** Not a missing feature.
- **`AIProviderInterface` is `id()/isConfigured()/chat()/generateStructured()/generateImage()`**,
  not the specification's `generate()/is_available()/get_name()`. Changing the
  contract would break all four shipped providers; it is deferred to its own
  phase rather than done halfway.
- **Stub adapters throw.** `json`, `rest` and `manual` source adapters raise
  `NotYetImplementedException` instead of pretending to work.
- **AIOSEO is not written to.** See above.
