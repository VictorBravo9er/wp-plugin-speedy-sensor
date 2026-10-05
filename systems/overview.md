# Speedy Sensor — overview

Admin-only WordPress plugin. It measures what is costing a site performance from
the inside, reports it, and hands the findings to Speedy.

## The one rule everything follows

**Nothing runs during a page view.** A plugin that diagnoses slowness must not
be a source of it. Every hook registered outside `is_admin()` is either empty or
a cheap guard:

| Work | Trigger |
|---|---|
| Schema upgrade | `plugins_loaded` at priority 20, admin or cron only |
| Plugin and database scan | `speedy_sensor_run_scan` cron |
| Web Vitals refresh | `speedy_sensor_refresh_vitals` cron |
| Everything else | Admin screens, on demand |

There is no `public/` directory, no front-end enqueue, no shortcode, no block,
and no JavaScript at all. The stylesheet loads only on this plugin's own screens.

## Subsystems

```
speedy-sensor.php          header, constants, autoload, activation hooks
includes/
  Plugin.php               wires subsystems at plugins_loaded:5
  autoload.php             PSR-4 Speedy_Sensor\ -> includes/
  Db/
    Schema.php             table names, dbDelta spec, version option
    Repositories/          one class per table, the only code that writes SQL
  Settings/Settings.php    single option array, allowlist validation
  Scanner/
    Cron.php               schedules, resumable events
    ScanLock.php           atomic lock so two passes cannot collide
    ScanRunner.php         budgeted, resumable orchestration
    PluginScanner.php      inventory + per-plugin option measurement
    DatabaseScanner.php    site-wide footprint, one pass over the tables
    Attribution.php        scoring and findings
  Integration/
    ApiClient.php          the only outbound HTTP, allowlisted host
    WebVitalsService.php   fetch, normalise, cache
    ServiceRequestService.php  escalation payload
  Admin/
    AdminMenu.php          menus, admin-only enqueue
    Actions.php            admin-post handlers, nonce + capability gate
    Notices.php            result and contextual notices
    View.php               escaping, formatting, SVG sparkline
    Pages/                 Dashboard, Scan, Database, SettingsPage
```

## Request flow

1. `speedy-sensor.php` defines constants and registers `Plugin::boot`.
2. `Plugin::boot` constructs `Settings` once and hands it to each subsystem.
3. Admin requests additionally register the menu, screens and notices.
4. Admin mutations go to `admin-post.php`, where `Actions` verifies the nonce,
   then the capability, then hands unslashed input to `Settings::save`.
5. Scans never start from a page view. `Cron` fires `ScanRunner`, which takes
   the lock, works to a time budget, writes a cursor, and re-arms itself.

## Data flow for a scan

```
get_plugins() + get_mu_plugins()
        -> PluginScanner::inventory()      (slugs, names, active state)
per slug, two aggregate queries
        -> PluginScanner::measure()        (option count, autoload bytes, transients)
one pass over information_schema.TABLES
        -> DatabaseScanner::table_inventory()
inventory + measurement + tables
        -> Attribution::attribute()       (score, findings, owned table bytes)
        -> PluginMetricRepository::insert_many()
```

Table sizes are read once and matched to plugins in PHP. Reading
`information_schema` per plugin would be the most expensive thing this plugin
could do.

That inventory is read once per **completing** scan, not once per pass.
`ScanRunner::measure_plugins()` returns it alongside its cursor, and the
completing pass hands it to `DatabaseScanner::metrics()`, so a scan that resumes
on a later cron tick does not attribute plugin tables against sizes captured
before the gap.

## Admin surface

`manage_options` only. Four screens: Overview (vitals + health + escalation),
Scan & Plugins (the attribution table), Database (footprint and bloat), Settings.

Mutations are ordinary form posts, not `fetch`. The screens work with JavaScript
disabled, and there is no REST or AJAX surface to attack.