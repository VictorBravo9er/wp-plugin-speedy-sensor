# Speedy Sensor — Agent Instructions

WordPress plugin. Applies to every coding agent working in this repo (Cline, Codex, Copilot, Cursor, Gemini CLI, Claude Code all read `AGENTS.md`).

## Project

- Plugin folder `speedy-sensor/`, bootstrap `speedy-sensor.php`, namespace `Speedy_Sensor\`, PSR-4 `includes/`.
- Global prefix `speedy_sensor_`: options, transients, hooks, meta keys, REST routes, tables, CSS classes.
- Layout: `admin/` settings + metaboxes, `public/` front-end hooks, `assets/`, `languages/` (`.pot` only, no compiled files), `tests/`.
- Stack: TBD — fill in PHP version, Composer deps, PHPCS / PHP-CS-Fixer config, and test runner once scaffolding lands.

## Commands

| Task | Command |
|---|---|
| Install | TBD |
| Lint | TBD |
| Format | TBD |
| Test | TBD |
| Build | TBD |

Nothing ships until its lint and test commands pass.

## Documentation — two layers

Two directories, two purposes. Do not mix them.

### `plans/` — per-change, transient
- One file per modification: `plans/NNNN-short-slug.md`.
- Created **before** the change. Edited **again when the change completes** — set status, record what actually shipped, note deviations.
- Archived or deleted once closed.
- Holds: goal, scope, approach, files touched, verification, outcome.

### `systems/` — whole-system, durable
- Describes how the plugin works, not what any one change did.
- Updated on **significant shifts** only: new subsystem, schema change, hook contract change, architectural decision, stack change.
- Routine additions do not belong here.
- Holds: architecture, data model, lifecycle/hooks, external integrations, invariants.

## Rules

**Change discipline**
- Read the file before editing it. Match its existing style.
- Smallest diff that solves the problem. No drive-by reformatting, no speculative abstractions.
- Refactors land separately from behavior changes.
- Public API, hook names, option keys, and meta keys are breaking surface — never rename without a deprecation path.
- No new runtime dependency without a stated reason.

**Correctness**
- Verify by reading or running. Never claim behavior you did not check.
- Trace the real path: hook order, `plugins_loaded` timing, conditional tag state, nonce lifetime, capability at call time.
- Handle failure cases, not just the happy path.

**WordPress**
- Escape on output (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`, `absint`). Raw echo of a variable is a bug, admin and REST included.
- Mutating requests: `wp_unslash` → nonce → `current_user_can` → sanitize → act. Order matters.
- Sanitize input by allowlist; reject unknown values instead of defaulting.
- SQL via `$wpdb->prepare()`. Table and column names cannot be parameterized — allowlist them.
- Custom tables via `dbDelta()` on activation, dropped on uninstall.
- i18n with a text domain; pass placeholders as arguments, never concatenate.
- Enqueue on a hook, not in a template. Clean deactivation. No `var_dump`, no notices.

**Security**
- Request data, shortcode and block attributes, and REST input are hostile.
- No `eval`, no `unserialize` on external input, no `extract()`, no user-built includes.
- Redirects use a destination allowlist.
- No secrets in the repo, including example config.

**Testing**
- Reproduce the bug before fixing it.
- Bug fixes ship with a regression test, or a stated reason one is impossible.
- Never report tests as passing that you did not run.

**Communication**
- Lead with files changed and commands run.
- Separate verified from unverified. State assumptions instead of filling gaps with guesses.
- No preamble, no restating the task, no filler.

**Boundaries**
- No installs, migrations, deploys, pushes, or history rewrites without explicit approval.
- Never weaken tests, linters, or CI to make something pass.
- Never invent file contents, API signatures, versions, or results.
