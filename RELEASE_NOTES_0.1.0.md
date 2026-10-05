# Speedy Sensor 0.1.0 — staging pre-release

Internal build for testing on a staging WordPress install. **Not a supported release.**

## Install

1. Download `speedy-sensor-0.1.0.zip` from the assets below.
2. In WordPress: **Plugins → Add New → Upload Plugin**, choose the ZIP, **Install Now**.
3. **Activate**, then open the **Speedy Sensor** menu.

Requires WordPress 6.0+ and PHP 7.4+. No Composer, no `vendor/` directory, no JavaScript —
upload and activate directly.

## What to test

| Screen | What to check |
| :--- | :--- |
| Settings | Save the form. Confirm "Settings saved." and that your values persist on reload. |
| Scan & Plugins | Click **Run scan now**. Expect "Scan complete", or "Scan paused after its time budget" on a plugin-heavy site — that is normal, it resumes on the next background run. |
| Database | Autoloaded options, total size, and the orphaned-transient count should all populate after a scan. |
| Overview | The health tiles and the escalation form render. |
| Deactivate / Reactivate | Your scan history survives (deactivation deliberately keeps data). |
| Delete | All four `wp_speedy_sensor_*` tables and the `speedy_sensor_settings` option are removed. |

The scanner runs on WP-Cron, so a scan may need a minute of real page traffic to advance. If
your staging host has `DISABLE_WP_CRON` set, use the **Run scan now** button instead.

## Known limitations — please read before filing bugs

**1. Web Vitals and optimisation requests will not work in this build.**

The two Speedy API endpoints are placeholders pending the published API contract, so
"Refresh from Speedy" and "Request optimisation from Speedy" will report that Speedy has no
record for this site. The plugin source says so explicitly in
`includes/Integration/ApiClient.php`. Nothing is wrong with your install.

**2. No API key configured yet.**

Until an API key and site key are entered on the Settings screen, the plugin will not contact
Speedy at all. The overview shows a "not connected" notice instead of Web Vitals data.

**3. If scans never produce results, check your database.**

The schema uses `DEFAULT '0000-00-00 00:00:00'` date defaults. On a MySQL 8 host running a
strict `sql_mode` with `NO_ZERO_DATE`, table creation can silently fail — the plugin appears
in the menu but the tables are never made. Confirm the tables exist:

```sql
SHOW TABLES LIKE 'wp_speedy_sensor%';
```

Expect four: `wp_speedy_sensor_scans`, `_plugin_metrics`, `_db_metrics`, `_web_vitals`
(adjust the prefix to match your install).

**4. Automated tests have not been run.**

The automated test suite has never been executed: the machine this was built on had no MySQL
server and no WordPress test library available. Static analysis (PHPCS, WordPress coding
standards) passes with zero violations, and PHP 7.4 compatibility is verified. Please treat
anything odd you find as a genuine finding.

## Provenance

Built from commit `7f373cb` on the `dev` branch — this is the build that includes the
coding-standards cleanup. The `main` branch is behind it.

The archive contains only the plugin: `speedy-sensor.php`, `uninstall.php`, `includes/`,
`assets/` and `languages/`. No tests, tooling configs or repository documentation ship to
the site.

## Uninstalling

Deleting the plugin from the Plugins screen drops all four tables and removes the settings
option. Deactivating instead keeps your data, so reactivation resumes where you left off.

## Reporting

Please include your WordPress version, PHP version, and the result of the `SHOW TABLES`
query above if a scan produces nothing.