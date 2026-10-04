# 0001 — Plugin scaffold and first end-to-end slice

Status: In Progress
Created: 2026-04-10
Completed: —

## Goal

Stand up the Speedy Sensor plugin as a working, admin-only WordPress plugin with the full
scaffolding for its five core responsibilities: inventorying other plugins, measuring database
footprint and attributing it to plugins, caching Web Vitals fetched from Speedy.site, and
submitting optimisation service requests to Speedy.site. The Speedy.site API contract is not
finished, so both API-backed features are built against a client seam that can absorb real
endpoints later without touching callers.

The plugin must cost the front end nothing. No assets, no queries and no hooks on public
requests; all measurement runs from WP-Cron under a lock and a time budget.

## Scope

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
- Per-request query profiling via `SAVEQUERIES`. Deferred — see Deviations.

## Approach

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

## Files touched

- `speedy-sensor.php` — plugin header, constants, autoloader, boot.
- `uninstall.php` — drop tables and options.
- `includes/Plugin.php` — container, wires subsystems, hook registration.
- `includes/Install/Activator.php`, `includes/Install/Uninstaller.php`
- `includes/Db/Schema.php`, `includes/Db/Repositories/*.php`
- `includes/Settings/Settings.php`, `includes/Settings/Defaults.php`
- `includes/Scanner/ScanRunner.php`, `Cron.php`, `PluginScanner.php`, `DatabaseScanner.php`, `Attribution.php`, `ScanLock.php`
- `includes/Integration/ApiClient.php`, `WebVitalsService.php`, `ServiceRequestService.php`
- `includes/Admin/*` — menu, screens, assets, notices.
- `includes/Rest/*` — controllers and routes.
- `assets/css/admin.css`, `assets/js/admin.js`
- `languages/speedy-sensor.pot`
- `composer.json`, `phpcs.xml.dist`, `phpunit.xml.dist`, `.gitignore`
- `systems/*.md`, `AGENTS.md`

## Verification

Populated on completion with the commands actually run and their real output.

## Outcome

Written on completion.