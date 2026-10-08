# Development

## Getting set up

```bash
git clone <repo> newsdesk-ai
cd newsdesk-ai
```

There are no runtime Composer dependencies. The plugin ships its own PSR-4
autoloader (`src/Core/Autoloader.php`), so it runs from a clean checkout.

`composer.json` exists for dev tooling (PHPCS) only.

---

## Targets

| | Version |
| --- | --- |
| Minimum PHP | **7.4** |
| Tested against | 8.4 |
| Minimum WordPress | 6.5 |

**The 7.4 floor is a hard constraint.** Linting on a modern PHP will happily
accept `match`, constructor property promotion, `enum`, `readonly`, `?->` and
named arguments — all of which are fatal errors on 7.4. Lint cannot catch this,
so the test runner greps for those constructs across `src/` and fails the build
if any appear. If you need one of them, you need to raise the floor
deliberately, not by accident.

---

## Tests

```bash
bash tests/run.sh
```

The runner does three things in order:

1. `php -l` every PHP file in the plugin.
2. Grep `src/` for PHP 8.0+ syntax (see above).
3. Execute every `tests/test-*.php`.

It exits non-zero if anything fails.

The suite is headless — it does **not** need a WordPress install.
`tests/bootstrap.php` defines the constants, stubs ~90 WordPress functions,
registers the plugin's own autoloader and loads the doubles.

### Writing a test

```php
require __DIR__ . '/bootstrap.php';

T::group( 'what behaviour is being pinned' );
T::eq( $expected, $actual, 'plain-language description' );
T::ok( $condition, 'description' );
T::contains( $needle, $haystack, 'description' );   // needle FIRST

exit( T::summary() );
```

Name the assertion after the behaviour, not the method. `'a critical failure
blocks the draft even at a high score'` tells you what broke; `'testAudit3'`
does not.

### Doubles

| Double | Use for |
| --- | --- |
| `FakeWpDb` | In-memory `WpDbInterface`. Real WHERE handling (`=`, `<>`, `>=`, `<=`, `>`, `<`, `IN`, `NOT IN (SELECT …)`), and primary keys parsed from the real `Schema.php`. |
| `FakeGlobalWpdb` | A full `global $wpdb`. **Required** whenever the production container or `Activation` is involved — the real adapter calls `get_charset_collate()`, which a bare `stdClass` does not have. Use `seedResults()` to return rows. |
| `FakePostIndex`, `FakeLinkRepo`, `FakePostWriter`, `FakeLogger` | Collaborators for services under test. |

Two harness bugs once produced false green runs: SELECT filtering ignored every
operator except `=`, and the primary key was hardcoded to `id` so `update()`
silently no-op'd on four tables **while reporting success**. Both are fixed, but
the lesson stands — **when a fake reports success and the assertion still
fails, suspect the fake.**

Beware `catch ( \Throwable )` in production code while debugging: it will
swallow the exception that explains your failure. Call the failing method
directly in a scratch script to see it.

---

## Coding standards

WordPress Coding Standards, configured in `phpcs.xml.dist`:

```bash
composer install
vendor/bin/phpcs
```

House rules on top:

- Every file starts with a docblock and `defined( 'ABSPATH' ) || exit;`.
- Escape at output: `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`.
- Every user-facing string is translated with the `newsdesk-ai` domain.
- Every `admin_post_` handler goes through `AdminActions::guard()`.
- Timestamps use `gmdate()`, never `date()`.
- Comments explain **why**. The code already says what.

---

## Hooks

### Filters

| Hook | Signature | Purpose |
| --- | --- | --- |
| `newsdesk_news_score` | `( float $score, Story $story, array $axes )` | Re-weight or override the editorial score. Clamped to 0–100 afterwards. |
| `newsdesk_filler_phrases` | `( string[] $phrases )` | Extend the Persian filler-phrase list. |
| `newsdesk_seo_adapters` | `( array $adapters )` | Add or remove SEO adapters. |
| `newsdesk_newsroom_capability` | `( string $cap )` | Change the capability required by the admin UI. |

### Actions

| Hook | Signature | Fires |
| --- | --- | --- |
| `newsdesk_news_selected` | `( Story $story, array $decision, int $jobId )` | Once per story chosen for writing. |
| `newsdesk_newsroom_job_finished` | `( int $jobId, array $summary )` | When a job reaches a terminal state. |
| `newsdesk_newsroom_log` | `( array $line )` | On every log write. |
| `newsdesk_retention_finished` | `( array $result )` | After the retention sweep. |

### Scheduled hooks

| Hook | Interval |
| --- | --- |
| `newsdesk_newsroom_tick` | 900s |
| `newsdesk_newsroom_retention` | daily |
| `newsdesk_newsroom_digest` | daily |

---

## Adding to the pipeline

**A new service.** Put the logic in `src/Application/`, depend on interfaces
from `Application/Contracts/`, register it in `Plugin::container()`, and add it
to `tests/test-u0-container.php` so a wiring mistake fails a test rather than a
production page load.

**A new column.** Add it to `Schema.php` **and** as a migration entry **and**
bump `NEWSDESK_DB_VERSION`. `tests/test-u1-upgrade-path.php` fails if
the constant and the newest migration disagree.

**A new admin page.** Add the page class under `src/Admin/Page/`, a view under
`admin/views/`, register it in the container list and wire it in `Menu.php`.
`tests/test-u2-admin-pages.php` renders every page and promotes PHP notices to
failures, which is how missing-property bugs in templates get caught.

---

## Releasing

1. Update `CHANGELOG.md`.
2. Bump the version in the plugin header, `NEWSDESK_VERSION` and
   `readme.txt` (`Stable tag`).
3. Bump `NEWSDESK_DB_VERSION` if the schema changed.
4. `bash tests/run.sh` — must be fully green.
5. Build the zip, extract it somewhere clean, and **run the suite again from
   the extracted copy**. A release that passes in the working tree but not in
   the archive is a release with a packaging bug.
