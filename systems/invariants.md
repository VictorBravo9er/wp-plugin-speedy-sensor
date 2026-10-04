# Invariants

Rules that must hold everywhere. A change that breaks one needs its own plan.

## Front end

1. No public hooks, no front-end enqueue, no shortcode, no block, no REST route
   reachable by a visitor.
2. No JavaScript is shipped at all. Every screen works with JS disabled.
3. No measurement runs during a page view. Scans and fetches are cron-only.
4. The stylesheet enqueues only on this plugin's own `admin_menu` hooks.
5. Reading `Db\Schema::VERSION_OPTION` happens on admin and cron only, because
   on a front-end request it would add a query to every page view.

## Naming

6. Global prefix `speedy_sensor_` for options, transients, hooks, REST routes,
   tables and CSS classes.
7. Classes are `Speedy_Sensor\` PSR-4 under `includes/`.
8. Text domain `speedy-sensor`. `.pot` only, no compiled files in the repo.

## Security

9. Mutating requests run `wp_unslash` → `wp_verify_nonce` → `current_user_can`
   → sanitise → act. That order is not negotiable.
10. Every screen requires `manage_options`, re-checked at render time, not only
    at registration.
11. Input is validated against an allowlist of known keys. Unknown keys are
    rejected with an error, never silently defaulted.
12. All SQL uses `$wpdb->prepare()` for values. Identifiers that cannot be
    parameterised are literals chosen by comparison, never interpolated from
    request input.
13. `wp_safe_redirect()` for every redirect, to a page slug that is a class
    constant.
14. The API key is never echoed, never logged, never returned by a read path.
15. No `eval`, no `unserialize()` on external data, no `extract()`, no
    user-built includes. The autoloader rejects class names containing a dot or
    a slash so a malformed name cannot traverse out of `includes/`.

## Data

16. Outbound requests only to an `https` `speedy.site` host, no redirects,
    TLS verification never disabled.
17. Upstream error bodies are never shown to users; status is mapped to a fixed
    code.
18. Findings are stored as codes and values, never translated text.
19. A scan is `running` until every plugin is measured, and its cursor is
    written in the same batch that writes its rows.
20. Two scans cannot write at once. The lock is `add_option()`, which fails
    atomically when the row exists.

## Behaviour

21. Scanning never blocks a user-visible action. A pass stops at its time
    budget and re-arms itself.
22. The plugin reports; it never deactivates, deletes or edits another plugin.
23. Deactivation is reversible and keeps data. Only uninstall destroys tables.
24. Failed operations degrade visibly: an error code and an admin notice, never
    a silent empty screen and never a PHP notice on the front end.