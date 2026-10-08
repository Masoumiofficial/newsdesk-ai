# Installation

## Requirements

| | Minimum |
| --- | --- |
| WordPress | 6.5 |
| PHP | 7.4 |
| MySQL / MariaDB | 5.7 / 10.3 |
| PHP extensions | `json`, `mbstring`, `curl` |
| Recommended | `sodium` (for stronger key encryption) |

The plugin creates 17 database tables and schedules one recurring event. On a
shared host with a hard `max_execution_time` under 30 seconds, use the Action
Scheduler path (detected automatically when Action Scheduler is present) rather
than WP-Cron.

---

## 1. Install

**From a zip:**

1. *Plugins → Add New → Upload Plugin*
2. Choose `newsdesk-ai-1.0.1.zip`
3. *Install Now*, then *Activate*

**Manually:**

```bash
cd wp-content/plugins
unzip newsdesk-ai-1.0.1.zip
wp plugin activate newsdesk-ai
```

Activation creates the tables and records the schema version in the
`newsdesk_db_version` option. On later upgrades the plugin compares
that option against its own constant on `admin_init` and migrates if they
differ — so updating by FTP or by an automatic update applies migrations
correctly, not just a manual re-activation.

---

## 2. Check system health

Go to **NewsDesk AI → System Health**.

Ten checks run there: PHP version, required extensions, database tables, cron
availability, write permissions, provider configuration, encryption backend,
HTTP egress, schedule state and lock health. Resolve anything red before going
further — a missing table will otherwise surface much later as a confusing
pipeline error.

---

## 3. Add sources

**منابع** (*Sources*) → add at least one RSS or Atom feed.

Each source has a **type** and a **tier**:

| Type | Meaning |
| --- | --- |
| `PRIMARY` | Official announcements (wordpress.org/news, vendor blogs) |
| `TECHNICAL` | Engineering write-ups, release notes |
| `MEDIA` | News outlets |
| `COMMUNITY` | Forums, community blogs |
| `SECURITY` | Advisories, CVE feeds |

| Tier | Trust ceiling | Use for |
| --- | --- | --- |
| 1 | 95 | Authoritative, first-party |
| 2 | 80 | Reputable, edited |
| 3 | 60 | General coverage (default) |
| 4 | 35 | Unverified, aggregators |

Tier feeds directly into the Trust axis of the editorial score, so tiering your
sources honestly matters more than adding many of them.

Use **آزمایش خوراک** (*Test feed*) to confirm a feed parses before saving. The
test fetches a sample and persists nothing. A reachable feed with no entries
reports `EMPTY_FEED` and `ok = true` — that is a valid state, not an error.

---

## 4. Configure an AI provider

**تأمین‌کننده‌های AI** (*AI Providers*) shows the provider chain and whether
each one is actually configured. Keys are entered under **تنظیمات**
(*Settings*).

Supported: OpenAI, Gemini, GapGPT, and any OpenAI wire-compatible endpoint.

The chain is ordered: primary → fallback 1 → fallback 2. Fallback happens
**only** on retryable errors (timeouts, 5xx, rate limits). Authentication,
budget and schema failures never fall through to another provider — that would
turn a configuration mistake into a silent cost.

Set a per-job token budget if you want a hard ceiling on spend.

---

## 5. Review the defaults

The shipped defaults are deliberately conservative:

| Setting | Default | Why |
| --- | --- | --- |
| Auto publish | **off** | There is no option to enable it |
| Image generation | **off** | The image phase plans; it does not generate |
| Editorial window | 24h | Falls back to 7d, then reports `NO NEWS` |
| Max stories per window | 3 | Quality over volume |
| Content quality gate | 90 | Below this, no draft |
| Selection quality gate | 60 | Below this, no story is selected |
| Minimum source trust | 40 | Below this, a source cannot carry a story alone |
| Delete data on uninstall | **off** | Uninstalling keeps your data |

---

## 6. First run

Run the pipeline **manually** once before enabling the schedule:

**زمان‌بندی** (*Scheduler*) → *Run now*.

Then check, in order:

1. **صندوق ورودی اخبار** (*News Inbox*) — did items arrive? Are duplicates
   being clustered under a winner?
2. **استوری‌ها** (*Stories*) — were clusters scored? What was selected, and for
   what stated reason?
3. **بازبینی پیش‌نویس‌ها** (*Draft Review*) — is there a draft, and does its
   audit panel look right?
4. **لاگ‌ها** (*Logs*) — any `WARNING` or `ERROR` entries?

An outcome of `NO_PUBLISHABLE_STORY_FOUND` on a quiet day is correct behaviour.

---

## 7. Enable the schedule

Once a manual run looks right, enable the recurring run in *Scheduler*. The
default tick is every 15 minutes; the pipeline itself decides whether there is
anything worth doing.

If WP-Cron is disabled on your site (`DISABLE_WP_CRON`), add a real cron entry:

```
*/15 * * * * cd /path/to/wordpress && wp cron event run --due-now >/dev/null 2>&1
```

---

## Upgrading

Upgrade normally through WordPress. Migrations run automatically on the next
admin page load after the files change. If a migration fails, an admin notice
explains the error and the upgrade is retried on the following request — the
plugin does not proceed with a half-migrated schema.

**Back up your database before a major-version upgrade.**

---

## Uninstalling

Deactivating stops all scheduled work and leaves data intact.

Deleting the plugin **keeps your data by default**. To remove everything,
enable *Delete all data on uninstall* in Settings **before** deleting. That
drops the 17 tables, the options, and the scheduled events.

---

## Troubleshooting

**No items are arriving.** Check the source's last error in *Sources*, then
test the feed. Outbound HTTP may be blocked; System Health reports this.

**Items arrive but no story is selected.** Expected when nothing clears the
gate. Check *Stories* for the scores and the stated rejection reason before
lowering any threshold.

**A draft is never created.** Look for `AUDIT_CRITICAL_FAIL` or
`QUALITY_BELOW_GATE` in the logs. The first names the exact blocking axis; the
second means the writing did not clear the gate within two revision loops.

**"Schema::install requires WordPress".** The plugin was loaded outside a
WordPress request. It cannot bootstrap standalone.

**Jobs stay stuck in one state.** A lock is held (TTL 1800s, heartbeat 300s).
System Health reports stale locks and can clear them.
