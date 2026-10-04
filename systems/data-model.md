# Data model

Four custom tables plus one option. Created with `dbDelta()` on activation,
upgraded lazily on admin or cron, dropped on uninstall.

All names below are prefixed with `{$wpdb->prefix}` at runtime by
`Db\Schema`. The literal names are `speedy_sensor_*`.

## Tables

### `{prefix}speedy_sensor_scans` — one row per scan run

| Column | Notes |
|---|---|
| `id` | PK |
| `started_at`, `finished_at` | UTC, `datetime` |
| `status` | `running` / `complete` / `failed` |
| `duration_ms` | wall clock across every resumed pass |
| `plugins_scanned`, `plugins_total` | progress |
| `cursor_index` | resume position; the row stays `running` between passes |
| `tables_scanned`, `issues_found` | written on completion |
| `error_code` | short machine code, never a message |

A run is `running` across as many cron passes as it takes. Only `complete` runs
are eligible for retention pruning.

### `{prefix}speedy_sensor_plugin_metrics` — one row per plugin per run

`plugin_file`, `plugin_name`, `version`, `is_active`, `is_must_use`,
`autoload_bytes`, `options_count`, `transients_count`, `table_bytes`,
`table_rows`, `score` (0–100), `findings` (JSON).

`findings` stores codes and values, never translated text, so a report written
today is still translatable later. Codes: `high_autoload`, `many_transients`,
`large_tables`, `many_options`, `inactive_plugin`, `must_use_plugin`.

### `{prefix}speedy_sensor_db_metrics` — key/value per run

`scan_id`, `metric_key`, `metric_value` (bigint).

Key/value rather than typed columns so a new measurement ships in a release
without a migration. Current keys are prefixed `db_`: `db_total_bytes`,
`db_table_count`, `db_option_count`, `db_autoload_bytes`,
`db_autoload_option_count`, `db_transient_count`, `db_expired_transient_count`,
`db_orphaned_transient_count`, `db_largest_table_bytes`, `db_largest_table_rows`.

### `{prefix}speedy_sensor_web_vitals` — cache of the Speedy report

`recorded_on`, `field` (mobile/desktop), `route`, `lcp_ms`, `cls`, `inp_ms`,
`ttfb_ms`, `updated_at`. Unique on `(recorded_on, field, route)`; writes use
`ON DUPLICATE KEY UPDATE`.

## Options

| Option | Purpose |
|---|---|
| `speedy_sensor_settings` | one array: credentials, intervals, feature flags |
| `speedy_sensor_db_version` | installed schema version |
| `speedy_sensor_vitals_last_refresh` | timestamp of last successful refresh |
| `speedy_sensor_scan_lock` | scan lock, `autoload = no` |

## Retention

Scan runs beyond `retain_scans` are pruned on completion along with their
metric rows. Web Vitals older than 90 days are deleted. Nothing else is
accumulated: there is one row per plugin per retained scan, not per request.