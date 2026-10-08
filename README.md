# NewsDesk AI

**A verified AI newsroom for WordPress.**

It discovers news from sources you approve, removes duplicates, clusters related
reports into a single story, scores that story against editorial criteria,
researches and fact-checks its claims, and then produces an original
draft with SEO/AEO/GEO metadata, source attribution and an image plan.

It stops at **draft**. Nothing is ever published automatically.

- **Version:** 1.0.1
- **Requires WordPress:** 6.5+
- **Requires PHP:** 7.4+
- **Text domain:** `newsdesk-ai`
- **License:** GPL-2.0-or-later

---

## What it is for

Most "AI content" plugins optimise for volume. This one is built around the
opposite premise: **one well-sourced article is worth more than twenty
paraphrases**, and an automated system that can publish on its own is a
liability, not a feature.

Three rules follow from that, and they are enforced in code rather than in
documentation:

1. **Drafts only.** Every write path forces `post_status = draft`. There is no
   setting that turns on automatic publishing, because the setting would be the
   vulnerability.
2. **Nothing is asserted without a source.** Claims are extracted, checked
   against the gathered evidence, and assigned a risk band. A claim that cannot
   be supported is removed from the article rather than softened.
3. **The system says when it does not know.** Unverified statements are marked
   uncertain or attributed, security stories without a CVE are flagged, and an
   empty result is reported as `NO NEWS` instead of being padded out.

---

## Pipeline

```
Sources → Fetch → Normalise → Deduplicate → Cluster → Score → Select
   → Research → Fact-check → Risk assessment → Security intel
   → Generate → Quality gate → Audit → SEO/AEO/GEO → Linking
   → Image plan → DRAFT (awaiting a human)
```

Each stage writes its own state, so a job that fails in the middle can be
inspected rather than guessed at. Job state is a 23-state machine
(`QUEUED → DISCOVERING → … → COMPLETED`, with `FAILED`, `RETRYING`,
`NEEDS_REVIEW` and `REJECTED` as terminal or recoverable branches).

### Editorial selection

A story is only written if it earns it:

| Axis | Weight |
| --- | --- |
| Freshness | 20 |
| Trust | 20 |
| Impact | 20 |
| Relevance | 15 |
| Search value | 10 |
| Actionability | 10 |
| Uniqueness | 5 |

A story is considered inside a **24-hour window** first. If nothing qualifies,
the window widens to **7 days**. If still nothing qualifies, the run ends with
`NO NEWS` — that is a correct outcome, not a failure.

Before anything is written, the system checks the existing archive and decides:
`NEW`, `UPDATE`, `REWRITE`, `MERGE`, `CORRECT`, `REPLACE` or `NO ARTICLE`.
Updates and corrections are staged as revision drafts against the original
post; they never silently overwrite published content.

---

## Quality control

Two independent checks stand between the model and a draft.

**The quality gate** scores the writing: factuality 35, coverage 15,
originality 15, structure 10, SEO 12, AEO/GEO 8, readability 5. Up to two
revision loops are allowed, after which the piece is parked as `NEEDS_REVIEW`.

**The audit** asks a different question — is there anything here that must
never reach a reader? Fifteen axes, weights summing to 100, five of them
blocking:

| Axis | Weight | Blocking |
| --- | --- | --- |
| `has_title` | 5 | ✔ |
| `title_length` | 5 | |
| `has_body` | 10 | ✔ |
| `body_length` | 8 | |
| `claims_grounded` | 12 | ✔ |
| `no_fabricated_refs` | 10 | ✔ |
| `sources_present` | 8 | ✔ |
| `quotes_attributed` | 7 | |
| `no_ai_filler` | 7 | |
| `structure_sane` | 6 | |
| `seo_fields` | 5 | |
| `faq_present` | 4 | |
| `language_consistent` | 5 | |
| `no_placeholders` | 5 | ✔ |
| `security_complete` | 3 | |

A blocking failure stops the draft **regardless of the score**. An article that
scores 92 but cites a claim ID that does not exist is not published.

There is also a Persian filler-phrase detector, because the giveaway of
machine-written Persian is a small set of stock openers. Five or more hits is a
critical failure.

---

## Security intelligence

Security stories carry structured fields extracted from the text by pattern,
never by a model: CVE IDs, CVSS base score, qualitative severity, affected and
fixed versions, and whether exploitation is being reported in the wild. A model
inventing a CVE number would be fabricating a security advisory, so it is not
allowed to try. When the text does not state a value, the field stays empty and
confidence is reported as `low`.

---

## Installation

See [INSTALL.md](INSTALL.md). In short: upload, activate, add at least one
source, add at least one AI provider key, then run the pipeline manually once
before enabling the schedule.

---

## Documentation

| File | Contents |
| --- | --- |
| [INSTALL.md](INSTALL.md) | Installation, configuration, first run |
| [ARCHITECTURE.md](ARCHITECTURE.md) | Layers, data model, pipeline internals |
| [DEVELOPMENT.md](DEVELOPMENT.md) | Local setup, tests, coding standards, hooks |
| [CHANGELOG.md](CHANGELOG.md) | Release history |

---

## Extending

```php
// Re-weight or override the editorial score.
add_filter( 'newsdesk_news_score', function ( $score, $story, $axes ) {
	return $story->isSecurity ? min( 100, $score + 10 ) : $score;
}, 10, 3 );

// React when a story is chosen for writing.
add_action( 'newsdesk_news_selected', function ( $story, $decision, $jobId ) {
	error_log( 'selected: ' . $story->title );
}, 10, 3 );

// Add site-specific filler phrases.
add_filter( 'newsdesk_filler_phrases', function ( array $phrases ) {
	$phrases[] = 'همان‌طور که می‌دانید';
	return $phrases;
} );
```

Full hook reference in [DEVELOPMENT.md](DEVELOPMENT.md).

---

## Data and privacy

- Article text is sent to the AI provider you configure. No other data leaves
  the site.
- API keys are encrypted at rest (libsodium when available, otherwise
  AES-256-GCM derived from `AUTH_KEY`) and are never written to logs or shown
  in the admin UI.
- Logs are redacted against 11 secret patterns before storage.
- Uninstalling **keeps your data** by default. Deletion is opt-in.

## License

GPL-2.0-or-later. See the plugin header for details.
