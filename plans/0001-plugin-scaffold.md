# Plan 0001: Plugin scaffold and first end-to-end slice

> **Status**: ✅ DONE — ship gate **OPEN**
> **Priority**: `CRITICAL`
> **Target Subsystems**: `speedy-sensor.php`, `includes/{Install,Db,Settings,Scanner,Integration,Admin}/`, `assets/css/`, `tests/`, `systems/`
> **Created**: 2026-04-10
> **Completed**: 2026-04-10
> **Verification**: 🟡 Partial — `composer lint` now passes with zero violations and PHP 7.4
>   compatibility is proven, but `composer test` has still never run (no `mysqli`, no MySQL
>   server, no `WP_TESTS_DIR`). See § 5 and `0001.x-lint-and-test-gate.md`.

---

## 1. Problem Statement & Context

Stand up the Speedy Sensor plugin as a working, admin-only WordPress plugin with the full
scaffolding for its five core responsibilities: inventorying other plugins, measuring database
footprint and attributing it to plugins, caching Web Vitals fetched from Speedy.site, and
submitting optimisation service requests to Speedy.site. The Speedy.site API contract is not
finished, so both API-backed features are built against a client seam that can absorb real
endpoints later without touching callers.

The plugin must cost the front end nothing. No assets, no queries and no hooks on public
requests; all measurement runs from WP-Cron under a lock and a time budget.

## 2. Scope

In:
- Bootstrap, PSR-4 autoloader, activation/deactivation/uninstall, schema versioning.
- Custom tables for scan runs, plugin metrics, database metrics and cached Web Vitals.
- Settings layer (single option array, allowlist validation).
- Scanner subsystem: plugin inventory, database footprint scan, plugin attribution.
- Speedy.site HTTP client with auth, allowlisted base URL and normalised errors.
- Web Vitals fetch + 7-day series cache.
- Service request submission.
- Admin menu, screens, admin-only assets, capability checks, notices.
- REST controllers for the admin screens (all `manage_options`).
- Cron scheduling, atomic scan lock, time budget, batched resumable passes.
- Lint/test tooling config (Composer, PHPCS, PHPUnit) and `systems/` architecture docs.

Out:
- The real Speedy.site endpoint paths, request bodies and auth scheme. Seams only.
- Front-end output of any kind. Out of scope by decision, not by omission.
- Auto-remediation. The plugin detects and reports; it does not deactivate or delete anything.
- Per-request query profiling via `SAVEQUERIES`. Deferred — see § 6.2.

---

## 3. Architecture & Design

1. Repo root is the plugin folder: `speedy-sensor.php` at root, `includes/` PSR-4 `Speedy_Sensor\`.
2. Zero runtime dependencies. Composer is dev-only; the shipped plugin has no `vendor/`.
3. PHP 7.4 minimum at runtime for host reach, developed and linted on PHP 8.5.
4. Everything admin-only. No `public/` directory, no front-end enqueue, no public hooks.
5. Scanning is cron-driven and resumable: each pass takes a lock, works until its millisecond
   budget is spent, writes partial progress, and reschedules itself if work remains.
6. Data model: `scans` (run log), `plugin_metrics`, `db_metrics` (key/value so the metric set
   can grow without schema churn), `web_vitals` (one row per day bucket).
7. Attribution is inventory-based: each plugin's option namespace, autoload bytes, transient
   count and owned table size. Deterministic, cheap, and explainable to the site owner.

---

## 4. Files Touched

- `speedy-sensor.php` — plugin header, constants, PHP-version guard, activation hooks.
- `uninstall.php` — drop tables and options on delete.
- `includes/autoload.php` — PSR-4 `Speedy_Sensor\` → `includes/`, no Composer at runtime.
- `includes/Plugin.php` — container; wires subsystems at `plugins_loaded:5`.
- `includes/Install/Activator.php`, `includes/Install/Uninstaller.php`
- `includes/Db/Schema.php` — table names, dbDelta spec, version option.
- `includes/Db/Repositories/{Scan,PluginMetric,DbMetric,WebVital}Repository.php`
- `includes/Settings/Settings.php` — single option, allowlist validation.
- `includes/Scanner/{Cron,ScanLock,ScanRunner,PluginScanner,DatabaseScanner,Attribution}.php`
- `includes/Integration/{ApiClient,WebVitalsService,ServiceRequestService}.php`
- `includes/Admin/{AdminMenu,Actions,Notices,View}.php`
- `includes/Admin/Pages/{Dashboard,Scan,Database,SettingsPage}.php`
- `assets/css/admin.css` — admin screens only.
- `bin/make-pot.php` + `languages/speedy-sensor.pot` (147 strings).
- `composer.json`, `phpcs.xml.dist`, `phpunit.xml.dist`, `.gitignore`, `.gitattributes`
- `tests/{bootstrap,SettingsTest,AttributionTest,WebVitalsTest}.php`
- `systems/{overview,data-model,integrations,invariants}.md`, `AGENTS.md`

---

## 5. Verification Plan

Environment: PHP 8.5.10. **No Composer, no WP-CLI, no MySQL client** on this
machine, and `sudo` requires a password, so the dev toolchain could not be
installed.

### 5.1 Automated Verification — Run and Passing

```
$ php -l  (all 34 PHP files)
all clean (34 files)

$ php bin/make-pot.php
Wrote languages/speedy-sensor.pot with 147 strings.

$ # PSR-4 class/path consistency over includes/
all class names match their PSR-4 paths

$ # cross-reference: every class named by Plugin.php and the pages
25/25 resolve to an existing file
```

### 5.2 Written but NOT Run

- `composer test` (phpunit) — BLOCKED, never executed. Verified in the
  `0001.x-lint-and-test-gate.md` checkpoint: no WordPress test library, no
  `mysqli`/`pdo_mysql` extension and no MySQL server, all three of which need
  `sudo`. PHPUnit 9.6.37 itself is installed and runs.
- No WordPress install, so nothing has been exercised against a real site,
  a real `dbDelta()` run, a real cron tick or a real HTTP response.

### 5.3 Consequences to Be Honest About

- `composer lint` (phpcs) is no longer in this list: it was run in the
  `0001.x-lint-and-test-gate.md` checkpoint and exits 0 with zero violations,
  clearing all 305 findings. PHP 7.4 compatibility is therefore proven too —
  `PHPCompatibilityWP` reports nothing against `testVersion 7.4-`.
- `composer test` remains unrun, so the three suites (`SettingsTest`,
  `AttributionTest`, `WebVitalsTest`) have still never executed. The ship gate
  stays OPEN.
- The `.pot` is verified correct by inspection and by regenerating it, but it
  has not been round-tripped through `msgfmt`.

---

## 6. Outcome

### 6.1 Shipped

Shipped the admin-only plugin skeleton with all five core responsibilities
wired end to end behind the Speedy API seam: plugin inventory, database
footprint with per-plugin attribution, the Web Vitals pipeline with a seven day
cached series, and optimisation request submission.

### 6.2 Deviations from the plan

1. **No REST layer.** The plan listed REST controllers. Not built. With no
   JavaScript consumer there would be an authenticated HTTP surface that
   nothing calls, which is attack surface for no benefit. Every screen is a
   server-rendered `admin-post` form instead. Add REST when something real needs
   to read this data.
2. **No JavaScript at all.** The plan mentioned `assets/js/admin.js`. The
   seven day chart is inline SVG built server-side, so the plugin ships zero
   script tags. This is the strongest available answer to "must not bog down the
   website".
3. **No `Settings/Defaults.php`.** Defaults live in `Settings::defaults()` so
   the ranges used by sanitisation and the values used at runtime cannot drift.
4. **Plugin files sit at the repo root**, not in a `speedy-sensor/` subdirectory.
   The repo clones into a directory already named `wp-plugin-speedy-sensor`, so
   a nested folder would double the name.
5. **`SAVEQUERIES` profiling still deferred**, as planned. Attribution is
   inventory-based.

### 6.3 Bugs Found and Fixed During Self-Review

- `Settings::save()` silently reverted a rejected API URL to the default instead
  of reporting it, and silently accepted a blank one, which would have broken
  every outbound call. Both now raise `speedy_sensor_bad_api_url`.
- The settings form rendered the *masked* API key into the password input, so
  saving the form unchanged would have written `********1234` over the real key.
  The field now renders empty and the mask is help text.
- `Cron::schedule()` runs from the activation hook, which fires *after*
  `plugins_loaded`, so the `cron_schedules` filter was never registered on that
  request and `wp_schedule_event()` would have failed. The filter is now
  registered inside `schedule()` as well.
- A leftover `delete_metadata()` in the uninstaller removed a meta key the
  plugin never wrote.
- Unused `Cron` import in `WebVitalsService`.

### 6.4 Follow-ups, in the Order They Matter

1. Install Composer, run `composer install`, then `composer lint` and
   `composer test`. **This is the ship gate.**
2. Stand up WordPress with a MySQL database and exercise: activation `dbDelta`,
   a full cron scan on a site with many plugins, resume across passes, and one
   scan against the real Speedy API shape.
3. Replace the two placeholder endpoint paths and confirm the Web Vitals
   response shape against the published contract.
4. Delete this plan file once 1 and 2 are done and the work is merged.