# Integrations

## Speedy API

`Integration/ApiClient` is the only place the plugin makes an outbound request.
Everything else calls it.

**Current status: the API contract is not final.** Paths and payload shapes below
are placeholders and are marked as such in the code.

| Constant | Path | Used by |
|---|---|---|
| `ApiClient::PATH_WEB_VITALS` | `/v1/sites/web-vitals` | `WebVitalsService` |
| `ApiClient::PATH_SERVICE_REQUEST` | `/v1/sites/optimisation-requests` | `ServiceRequestService` |

Swapping in the real contract is a change to those two constants plus the
normaliser. No caller reads a raw response.

### Rules the client enforces

- **Base URL is allowlisted.** `Settings::sanitize_api_base_url()` accepts only
  `https` on `speedy.site` or a subdomain of it, with no userinfo, query or
  fragment. Without this, anyone able to write settings could point the site at
  an arbitrary host and use the plugin as a request proxy. The check is
  filterable through `speedy_sensor_allowed_api_host` for staging endpoints.
- **No redirects.** `redirection` is 0, so a 30x means the stored URL is stale
  and is reported as such rather than followed.
- **TLS always on.** `sslverify` is never disabled.
- **10 second timeout.** Cron must not hang on an upstream.
- **Credentials are opaque.** `api_key` and `site_key` are reduced to
  `[A-Za-z0-9._-]` on save.

### Authentication

`Authorization: Bearer {api_key}`. The site key is sent as a parameter, not in
a header. Neither value is ever rendered back into a form; the settings screen
shows a mask in help text and treats an empty key field as "unchanged".

### Error mapping

Upstream messages are never shown to site owners: they can echo identifiers and
are written for Speedy's own support tooling. `ApiClient::handle_response()`
maps status codes to a fixed set of codes, and `Admin/Notices` holds the text.

| Status | Code |
|---|---|
| 401, 403 | `speedy_sensor_auth_failed` |
| 404 | `speedy_sensor_not_found` |
| 429 | `speedy_sensor_rate_limited` |
| 30x | `speedy_sensor_moved` |
| other non-2xx | `speedy_sensor_api_error` |
| unparseable body | `speedy_sensor_invalid_response` |
| transport failure | `speedy_sensor_network_error` |
| no credentials | `speedy_sensor_not_linked` |

## What leaves the site

The optimisation request sends: site URL and site key, WordPress and PHP
versions, whether the site is multisite, the scan totals, the database metrics,
and the top five offending plugins with their scores and finding codes.

It does not send the API key, page content, post data, visitor data, or any
credential other than the site key the request is about.

## Response tolerance

`WebVitalsService::normalise()` accepts a `data` or `results` envelope or a
bare list, and reads `date`/`recorded_on`/`day`, `field`/`device`/`form_factor`,
`route`/`path`/`page`, `lcp`/`lcp_ms`, `inp`/`inp_ms`, `ttfb`/`ttfb_ms`. A row
without a valid `Y-m-d` date is dropped rather than bucketed arbitrarily. An
unexpected shape yields no rows and a readable error, never a fatal.

## Caching

Web Vitals are read from the local cache by the dashboard, so no admin page
load blocks on Speedy. Refresh is a self-rearming cron event on the configured
interval.

## Filters

| Filter | Purpose |
|---|---|
| `speedy_sensor_api_request_args` | adjust request arguments before send |
| `speedy_sensor_allowed_api_host` | allow an additional API host |
| `speedy_sensor_service_request_payload` | adjust the escalation payload |
| `speedy_sensor_db_metrics` | adjust collected database metrics |