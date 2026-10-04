# Speedy Sensor — Agent Instructions

WordPress plugin. Applies to every coding agent working in this repo (Cline, Codex, Copilot, Cursor, Gemini CLI, Claude Code all read `AGENTS.md`).

## Project

- The repo root **is** the plugin folder: `speedy-sensor.php` at root, PSR-4 `includes/` → `Speedy_Sensor\`, uninstall at `uninstall.php`.
- Global prefix `speedy_sensor_`: options, transients, hooks, meta keys, REST routes, tables, CSS classes.
- Layout: `includes/Admin/` settings + screens, `includes/Scanner/`, `includes/Integration/`, `includes/Db/`, `assets/css/`, `languages/` (`.pot` only, no compiled files), `tests/`, `bin/`.
- There is deliberately **no `public/` directory**. This plugin is admin-only and must not touch the front end. See `systems/invariants.md`.
- Stack: PHP `>= 7.4` at runtime, developed and linted on 8.5. **Zero runtime dependencies.** Composer is dev-only: PHPCS (WordPress + PHPCompatibilityWP) and PHPUnit 9.6 with Yoast polyfills. PSR-4 `Speedy_Sensor\` → `includes/`, `Speedy_Sensor\Tests\` → `tests/`.
- All admin mutations are `admin-post` form posts. No JavaScript ships, so there is no REST or AJAX surface yet.

## Commands

| Task | Command |
|---|---|
| Install | `composer install` |
| Lint | `composer lint` (phpcs) |
| Format | `composer lint:fix` (phpcbf) |
| Test | `composer test` (phpunit; needs `WP_TESTS_DIR` and a MySQL test database) |
| Build | None. No build step. `.gitattributes` `export-ignore` strips dev files from a dist archive |
| Translations | `composer pot` (`php bin/make-pot.php`, dependency free) |

Nothing ships until its lint and test commands pass.

## Documentation — two layers

Two directories, two purposes. Do not mix them.

### `plans/` — per-change, transient
- One file per modification: `plans/NNNN-short-slug.md`. `NNNN.x-…` is reserved for verification checkpoints of a prior plan.
- **Plan before code.** The file exists with § 1–6 filled *before* the first edit. A plan written afterwards is a changelog.
- Edited **again when the change completes** — status set, § 5.3 *Verification Record* filled with the commands actually run and their real output, § 7 *Outcome & Deviations* filled with what shipped and what deviated.
- Archived or deleted once closed, and removed from the tracker table.
- `plans/README.md` is the tracker of record: progress dashboard, dependency graph, master tracker table, legends, and the template every plan file must follow.
- Structure: `1. Problem Statement & Context`, `2. Scope`, `3. Architecture & Design` (implementation steps live here as `### Step 3.N`), `4. Files Touched`, `5. Verification Plan`, `6. Downstream Dependencies`, `7. Outcome & Deviations`.
- Sections 1–6 are written **before** the change. § 5.3 and § 7 are written **after** it.
- If a gate did not run, § 5.2 says so in those words. Never claim a check you did not perform.

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
