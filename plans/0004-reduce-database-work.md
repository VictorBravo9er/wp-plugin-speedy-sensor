# Plan 0004: Reduce redundant database work

> **Status**: ◍ In Review / Verification
> **Priority**: `HIGH`
> **Target Subsystems**: `includes/Db/Repositories/`, `includes/Scanner/`, `includes/Admin/Pages/`, `tests/`, `systems/overview.md`
> **Created**: 2026-05-10
> **Ship Gate**: unaffected by intent, but **not** verified — `composer lint` and `composer test`
>   have still not been run from this machine, which has no PHP or Composer. The code is written
>   and the plan sits at ◍ pending those gates. See § 5.2.

Performance plan. No user-visible behaviour changes and no schema changes are intended. Every
optimisation here is either removing a query that returns a value already in hand, or removing a
query whose result was already computed earlier in the same request.

---

## 1. Problem Statement & Context

The plugin is admin-only and does no measurement during a page view, which is the important
property and is not at risk. The problem is narrower: **within a single admin request, the same
data is sometimes read more than once, and one scan reads the most expensive table in the
database twice.**

### 1.1 What a single Overview load costs today

Traced from `Admin\Pages\Dashboard::render()` plus `Admin\Notices::render_contextual()`, which
both run on the same request:

| # | Query | Source | Redundant? |
| :-- | :--- | :--- | :--- |
| 1 | `web_vitals` series, 7 days | `WebVitalRepository::get_series()` | no |
| 2 | `web_vitals` latest, `LIMIT 1` | `WebVitalRepository::get_latest()` | **yes** — see § 1.2 |
| 3 | `speedy_sensor_vitals_last_refresh` | `WebVitalsService::last_refresh()` | no |
| 4 | `scans` latest `complete` | `ScanRepository::get_latest()` in `Dashboard` | no |
| 5 | `db_metrics` for that scan | `DbMetricRepository::get_by_scan()` | no |
| 6 | `scans` latest `complete` | `ScanRepository::get_latest()` in `Notices` | **yes** — exact repeat of #4 |
| 7 | `speedy_sensor_settings` | `Settings::all()` | no |
| 8 | `speedy_sensor_db_version` | `Schema::needs_upgrade()` via `Cron::maybe_upgrade_schema()` | no |

Eight queries. Two are redundant. Queries 3, 7 and 8 are `get_option()` calls, which populate
the WP object cache, so the 29 `new Settings()` construction sites in the codebase collapse to a
single read each per request. Memoising `Settings` further would save nothing.

### 1.2 Why `get_latest()` on `web_vitals` is redundant

`get_series()` returns rows `ORDER BY recorded_on ASC`, so when the series is non-empty its
**final row is the latest entry** for that `(field, route)`. `get_latest()` runs
`WHERE field=? AND route=? ORDER BY recorded_on DESC LIMIT 1` and returns the same row.

The series is windowed (`recorded_on >= since`, seven days) while `get_latest()` is not. So the
two agree **only when the series is non-empty**. When it is empty, the newest cached row may sit
outside the window and `get_latest()` must still run. Any optimisation must preserve that
fallback, or the dashboard would show "no data" while a stale row exists in the table.

### 1.3 The larger waste: `information_schema` read twice per scan

`ScanRunner` reads `DatabaseScanner::table_inventory()` in `measure_plugins()` (`ScanRunner.php`
line 176) and then again inside `finish()` → `DatabaseScanner::metrics()` (line 228).

`table_inventory()` is `SELECT ... FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?`. This
is the single most expensive query the plugin issues, and it is issued twice per completed scan
for data that is already in memory. Removing the second read is a larger saving than both admin
dedupes combined.

### 1.4 What is deliberately *not* in scope

- **No persistent cache.** No transient, no object-cache wrapper. See § 3.4.
- **No schema change**, so no `SPEEDY_SENSOR_DB_VERSION` bump and no `dbDelta()` re-run. See § 6.3.
- **No front-end change.** Invariant 5 in `systems/invariants.md` is untouched.

<!-- SPLIT-MARKER-4 -->
## 2. Scope

**In scope**

- Step 3.1 — remove the duplicate `scans` read within one admin request.
- Step 3.2 — derive the latest Web Vitals row from the series, with a guarded fallback.
- Step 3.3 — pass the already-read table inventory from the scan pass into `metrics()`.
- Unit coverage for each of the three, in `tests/`.

**Out of scope**

- Adding a transient or object cache (§ 3.4 explains why not).
- Index changes on `web_vitals` (§ 6.3).
- Merging `speedy_sensor_db_version` into the settings option.
- Autoloading `speedy_sensor_settings` (§ 6.4).
- Any change to `PluginScanner::measure()` or the query bodies inside `DatabaseScanner::metrics()`.
  Their cost is real but it is cron-only and already budgeted (§ 6.5).

## 3. Architecture & Design

All three steps are **request-scoped**: a memo lives only as long as the PHP request that created
it, or is handed directly to a callee. No state survives a request, so there is no staleness
window and nothing to invalidate.

### Step 3.1: Request-scoped memo for `ScanRepository::get_latest()`

`get_latest($status)` is called on the same request by both `Dashboard::render()` and
`Notices::render_contextual()`, with the same argument, and each constructs its own
`ScanRepository`. An instance property would not help, so the memo is **static**, keyed by status.

```php
private static $latest_cache = array();

public function get_latest( $status = self::STATUS_COMPLETE ) {
    if ( array_key_exists( $status, self::$latest_cache ) ) {
        return self::$latest_cache[ $status ];
    }
    // ... existing query unchanged ...
    return self::$latest_cache[ $status ] = $row ? $this->cast_row( $row ) : null;
}
```

`array_key_exists` rather than `isset`, because a cached `null` is a meaningful answer
("no completed scan") and must not trigger a re-query.

**Invalidation.** The memo must be dropped by any method that changes what
`get_latest($status)` would return, otherwise a read-after-write in the same request returns a
stale row. Those are `start()`, `update()`, `complete()`, `fail()` and `delete_by_ids()`. Each
clears the whole memo, because `update()` can move a row from `running` to `complete`, changing
which status key it belongs under.

**Why this is safe.** The dangerous case would be a long-lived process. This is per-request PHP:
a fresh request starts with an empty static. `ScanRunner::run()` calls `get_resumable()`, then
`start()`, `update()` and `complete()` within one cron request — all of which invalidate — so a
resumable scan is still picked up correctly on the next pass. No existing test couples to
`get_latest()` internals, and the other call sites in `Scan`, `Database` and
`ServiceRequestService` are either unaffected or benefit.

### Step 3.2: Derive latest from the series, with fallback

In `Dashboard::render()`, only call `latest()` when the series cannot answer:

```php
$series = $vitals->series( WebVitalsService::WINDOW_DAYS, 'mobile', '/' );

// The series is ordered oldest first, so its last row is the latest entry.
// An empty series means the newest row predates the window, so ask directly.
$latest = ! empty( $series )
    ? end( $series )
    : $vitals->latest( 'mobile', '/' );
```

The two shapes match. `get_series()` maps every row through `prepare_row()`, and `get_latest()`
returns `prepare_row()` output for a single row, so `Dashboard` receives an identical array
either way and the renderer needs no change.

`WebVitalsService::series()` and `latest()` stay exactly as they are. Both remain correct and
publicly callable; this removes a redundant call at one call site only. That is deliberate —
leaving the repository methods intact keeps every other caller working.

### Step 3.3: Pass the table inventory through `finish()`

`metrics()` gains an optional parameter. Defaulting to `null` and reading the inventory itself
keeps the existing no-argument call valid, so no caller or test breaks.

```php
public function metrics( $inventory = null ) {
    if ( ! is_array( $inventory ) ) {
        $inventory = $this->table_inventory();
    }
    // ... unchanged ...
}
```

`ScanRunner::measure_plugins()` already computes `$tables` but discards it at the end of its own
scope, because `finish()` is a separate method. The inventory is therefore returned alongside
that method's progress array, and `run()` forwards it to `finish()` **only on the completing
pass**.

This is a correctness point, not tidiness. A scan row carries its cursor across requests, so a
**resumable** scan cannot assume the inventory read on the first pass is still accurate. On an
earlier, budget-exhausted pass the scan is left resumable and `finish()` is never called, so
nothing stale is ever forwarded. A scan that resumes 120 seconds later re-reads
`information_schema` rather than attributing plugin tables against sizes captured before the gap.

### Step 3.4: Why no persistent cache

A transient around the dashboard payload would remove roughly five queries. It was rejected:

- **Invalidation is genuinely hard.** A scan completing, or vitals refreshing, changes the payload
  from cron, outside the request. Correct invalidation needs hooks on
  `speedy_sensor_settings_saved`, scan `complete()` and vitals `upsert()`, plus the
  `speedy_sensor_loaded` seam. Miss one and the dashboard shows stale figures — a silently wrong
  answer rather than a slow one, and a direct collision with invariant 24 in
  `systems/invariants.md`.
- **There is no meaningful latency to hide.** `scans` holds roughly `retain_scans` (default 12)
  rows plus any in-progress run; `db_metrics` is about ten rows per scan. Both are indexed.
- **It adds a stateful component to a plugin whose premise is that it adds nothing to a page
  view**, and whose stated failure mode is "degrade visibly" rather than "serve something stale".

All three chosen changes are structural, so they hold whether or not an object cache is present.

<!-- SPLIT-MARKER-5 -->
## 4. Files Touched

- `includes/Db/Repositories/ScanRepository.php` — static memo, `array_key_exists` guard, and memo
  clearing in `start()`, `update()`, `complete()`, `fail()` and `delete_by_ids()`.
- `includes/Admin/Pages/Dashboard.php` — derive `$latest` from `$series`, guarded fallback.
- `includes/Scanner/DatabaseScanner.php` — `metrics( $inventory = null )` optional parameter.
- `includes/Scanner/ScanRunner.php` — return the inventory from `measure_plugins()`, forward it in
  `run()` to `finish( $scan, $tables )`.
- `tests/` — new coverage for each of the three steps, described in § 5.1.
- `plans/README.md` — dashboard count, tracker row, milestone entry.
- `systems/overview.md` — **edited in the same commit**, because § 3.3 changes the documented
  behaviour of the scan pass (one `information_schema` read per completing pass, not per pass).

No change to `speedy-sensor.php`, `uninstall.php`, `Schema.php`, or any other file.

## 5. Verification Plan

### 5.1 Automated Verification

These are the gates that make this plan `Done`. **None of them can currently run**: this machine
has no PHP and no Composer, and `composer test` still has no MySQL server or `WP_TESTS_DIR`. They
must be run on a machine that can satisfy them.

```bash
composer install
composer lint                    # MUST exit 0, zero violations
composer test                    # MUST exit 0, including the new tests
composer pot                     # .pot regenerates; no new translatable strings expected
```

New tests to write. Each must fail before the change and pass after:

1. `ScanRepository::get_latest()` called twice with the same status issues **one** query. Measured
   via the `$wpdb->num_queries` delta around the two calls.
2. `get_latest( 'complete' )` then `get_latest( 'running' )` are cached **separately**, and both
   return the correct row for their own status.
3. A cached `null` (no scan present) does not re-query on the second call.
4. After `start()`, `complete()`, `fail()` or `delete_by_ids()`, a subsequent `get_latest()`
   reflects the write — proving the memo was cleared.
5. A scan resuming from a cursor does **not** receive a stale inventory: because `finish()` is only
   called on completion, `metrics()` receives `null` and reads the inventory for itself.
6. `Dashboard` calls `latest()` when the series is empty, and does not when it is not.

Tests 1 and 6 are the regression guards for the actual saving. Without them, either change could
be silently reverted and the plan would still appear satisfied.

### 5.2 Manual & Runtime Verification

- **Query counts before and after, on a real install.** Not run here; requires staging plus a
  `SAVEQUERIES` probe or a query-debugging plugin. Expected: Overview 8 → 6 queries, and one fewer
  `information_schema` read per completed scan.
- **`composer test` has never run on any machine to date.** This plan cannot be marked `Done`
  without it. Steps 3.1 and 3.3 introduce caching behaviour that only a real test run exercises;
  lint alone is not sufficient evidence for this particular change, because lint cannot see
## 6. Downstream Dependencies

**Depends on**

- Nothing. Plan 0003 (staging ZIP) is independent and already published-ready.

**Blocks**

- Nothing. This is an internal optimisation.

**Carried forward — findings recorded while planning, not acted on here**

1. **`DatabaseScanner::count_orphaned_transients()` is the heaviest query in the codebase.** It
   self-`LEFT JOIN`s `wp_options` against itself, matching
   `CONCAT('_transient_timeout_', SUBSTRING(t.option_name, 11))` across every transient row. On a
   site with tens of thousands of transients this is seconds, not milliseconds. It runs once per
   scan from `metrics()`, so it never touches a page view — but it is the first suspect if a
   staging site reports cron timeouts or a scan that never completes. Worth measuring, and a
   candidate for its own plan.

2. **`PluginScanner::measure()` runs 2 queries per plugin** (option aggregate, transient count),
   so a 60-plugin site costs 120 queries per full scan. This is by design — the class docblock
   explains that a query log would need `SAVEQUERIES` on a live request — and `ScanRunner` bounds
   it to `BUDGET_MS` with a batch size of 25. Not a defect; recorded so it is not mistaken for one.

3. **`web_vitals.get_latest()` has no usable index.** The query is
   `WHERE field=? AND route=? ORDER BY recorded_on DESC LIMIT 1`, but `recorded_on` is the
   *leading* column of `UNIQUE KEY bucket (recorded_on, field, route)`, so it is unconstrained
   here and MySQL falls back to `KEY route` plus a filesort. A composite
   `KEY (field, route, recorded_on)` would make it a pure index lookup. **Deferred** because it
   needs a `SPEEDY_SENSOR_DB_VERSION` bump and a `dbDelta()` re-run, and `dbDelta()` on real MySQL
   is exactly the untested path carrying the `0000-00-00` / `NO_ZERO_DATE` risk flagged in the
   0.1.0 release notes. Doing it without a live database to verify against would be reckless.

4. **Do not autoload `speedy_sensor_settings`.** It would save its one query per admin request,
   but `install_defaults()` deliberately stores it non-autoloaded (`add_option( ..., false )`), and
   autoloading would place the API key in memory on every front-end request. Invariant 5 exists
   precisely to keep per-request work off page views; one query is not worth trading that for.

5. **The `Settings::all()` docblock overstates its guarantee.** It claims reading settings "must
   never cost a query", but the instance memo only holds within one request. The saving is real
   and comes from the WP object cache, not from `$this->cache`. Worth rewording in passing so the
   next reader does not add redundant memoisation that buys nothing.

6. **`systems/overview.md` still describes one `information_schema` read per pass.** After Step 3.3
   that is inaccurate, which is why that file is listed in § 4 to be edited in the same commit.

## 7. Outcome & Deviations

### 7.1 What shipped

**Step 3.1 — request-scoped memo.** `ScanRepository` gained `private static $latest_memo`, keyed
by status, plus a private `flush_memo()`. `get_latest()` returns from the memo via
`array_key_exists` and otherwise stores the result, including a `null`. Four flush sites:
`start()` (line 78), `update()` (line 166) and `delete_by_ids()` (line 265). `complete()` and
`fail()` delegate to `update()`, so they inherit the flush without their own call.

**Step 3.2 — derive latest from the series.** `Dashboard::render()` now selects
`end( $series )` when the series is non-empty and only falls back to `$vitals->latest()` when it
is empty. `WebVitalsService` and `WebVitalRepository` are untouched, so every other caller keeps
working.

**Step 3.3 — inventory forwarded.** `DatabaseScanner::metrics( $inventory = null )` accepts a
pre-read inventory, guarded by `is_array()` so `null` and any non-array still trigger its own
read. `ScanRunner::measure_plugins()` returns `'tables' => $tables` in its progress array, and
`run()` forwards it to `finish( $scan, $progress['tables'] )` **only on the completing pass**. A
budget-exhausted pass returns early and never carries an inventory forward.

**Tests.** Three new files, 15 tests total: `ScanRepositoryTest` (8), `DatabaseScannerTest` (4),
`DashboardDataTest` (3). These cover the six cases in § 5.1 — including the two regression guards
that would fail if the memo or the fallback were silently reverted.

### 7.2 Bug found and fixed during the change

`delete_by_ids()` originally placed its `flush_memo()` call *after* the `if ( empty( $ids ) )`
early return. An empty id list therefore deleted nothing **and** left a stale memo behind. Caught
by reading the finished code rather than by a failing test, because the test fixture happened to
pass a non-empty list at the time. The flush was moved above the early return, and the test fixture
now calls `delete_by_ids( array() )` to reset the static memo between tests. This is the case
§ 5.1 predicted would be missed by lint.

### 7.3 Deviations

- **Deferred, as planned:** the `web_vitals` composite index (§ 6.3) and any persistent cache
  (§ 3.4). Both reasons unchanged.
- **`systems/overview.md` not yet updated.** § 4 lists it for the same commit, because Step 3.3
  changes the documented behaviour of the scan pass. It still describes one `information_schema`
  read per pass, which is now one read per *completing* pass. This is outstanding and must land
  with the code rather than being forgotten.
- **`Settings::all()` docblock not reworded** (§ 6.5). Cosmetic, carried forward.
  staleness.
- No new front-end query cost is expected, and none may be introduced — invariant 5.

<!-- SPLIT-MARKER-6 -->