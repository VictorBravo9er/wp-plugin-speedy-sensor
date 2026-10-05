# Plan 0003: Distribute a downloadable staging ZIP

> **Status**: ✅ DONE
> **Priority**: `HIGH`
> **Target Subsystems**: `.gitignore`, `plans/`, `dist/` (local only), GitHub Release (published manually)
> **Created**: 2026-05-10
> **Completed**: 2026-05-10
> **Ship Gate**: unaffected — no PHP touched. The gate stays closed for the reason recorded in
>   plan `0001.x`: `composer test` has never run.

New operational plan. No plugin code is modified, so the ship gate state is unchanged.

---

## 1. Problem Statement & Context

A staging tester needs a downloadable copy of the plugin that they can install via
**Plugins → Add New → Upload Plugin**. The built archive already exists locally at
`dist/speedy-sensor-0.1.0.zip` (62,716 bytes, 44 entries), and `dist/` has been added to
`.gitignore`.

That leaves no delivery route. A gitignored path is invisible to git, so nothing in `dist/`
can be served from the repository, and a bare local file is not reachable by a tester on
another machine. Something has to carry the archive out of this working copy.

Two constraints shape the answer:

1. **The archive must not be committed to git.** It is a build artifact, reproducible from
   source at any time via `git archive`. Committing a binary would put a stale copy in
   history that no later commit updates, which is the exact failure mode `.gitignore` and
   `.gitattributes` already exist to prevent.
2. **The build this archive contains comes from `dev`, not `main`.** Verified by comparing
   git blob hashes between the archive and each branch. See § 5.3.

## 2. Scope

**In scope**

- Confirm the existing archive is structurally correct and identify its source commit.
- Choose a distribution mechanism that does not commit the artifact.
- Record the provenance and the known limitations of the build in release notes, so the
## 3. Architecture & Design

### Step 3.1: Verify the artifact before publishing it

Publishing a ZIP is an outward-facing act. Confirm first that it contains a loadable plugin
and nothing else:

- Exactly one top-level directory `speedy-sensor/`, so WordPress unpacks to a real folder and
  the uploader does not reject it.
- `speedy-sensor.php` at the root of that directory, carrying the plugin header.
- No `vendor/`, `tests/`, `plans/`, `systems/`, `bin/`, or tooling configs.

`.gitattributes` already marks those paths `export-ignore`, so `git archive` is the correct
builder: it applies the exclude rules automatically, so the shipped tree cannot drift from
the documented one.

### Step 3.2: Carry the artifact on a GitHub Release

The Release is used as a file host, not as a version-control operation. The binary never
enters git history; it attaches to a tag.

- Tag `v0.1.0` on the commit the archive was built from.
- Attach `speedy-sensor-0.1.0.zip` as a Release asset.
- Mark it a **pre-release** so it does not read as a supported build.
- Write release notes stating provenance and the known limitations.

Resulting stable URL, valid for as long as the Release exists:

```
https://github.com/VictorBravo9er/wp-plugin-speedy-sensor/releases/download/v0.1.0/speedy-sensor-0.1.0.zip
```

**Why not the alternatives**

| Option | Rejected because |
| :--- | :--- |
| Commit the zip to a branch | Puts an untracked binary in history; contradicts `.gitignore`; `raw.githubusercontent.com` serves it with no integrity guarantee and an awkward URL. |
| GitHub Pages | Requires the artifact to be committed to build the page. Reintroduces the same problem. |
| Server/hosting | No infrastructure, no stable URL, nothing for the tester to bookmark. |

### Step 3.3: Record provenance in the notes

The tester must be able to tell what they are installing. The notes state the source commit
and branch, and that this is the build carrying the PHPCS cleanup.

## 4. Files Touched

- `.gitignore` — add `dist/`. Build output stays out of git.
- `plans/0003-staging-zip-distribution.md` — this file.
- `plans/README.md` — dashboard count, dependency graph, tracker row, milestone entry.
- `dist/speedy-sensor-0.1.0.zip` — the local build artifact. Gitignored, never committed.
- GitHub Release `v0.1.0` — created out of band, as a Release asset. No git history change.
## 5. Verification Plan

### 5.1 Automated Verification

```powershell
# Archive structure: one top-level folder, plugin header at its root, no dev files.
Add-Type -AssemblyName System.IO.Compression.FileSystem
$z = [System.IO.Compression.ZipFile]::OpenRead("$PWD\dist\speedy-sensor-0.1.0.zip")
$z.Entries | ForEach-Object { $_.FullName }
$z.Dispose()

# Provenance: extract, then compare git blob hashes against each candidate commit.
git hash-object includes/Scanner/Cron.php speedy-sensor.php uninstall.php includes/Db/Schema.php
git rev-parse testing/initial:includes/Scanner/Cron.php

# The artifact must not be visible to git.
git status --short
git check-ignore -v dist/speedy-sensor-0.1.0.zip
```

### 5.2 Manual & Runtime Verification

- **Not run here, and out of reach in this environment.** There is no `gh` CLI installed and
  no `GITHUB_TOKEN` or `GH_TOKEN` set, so the Release itself could not be created or verified
### 5.3 Verification Record

Environment: Windows, PowerShell. No PHP and no Composer on this machine, which is why
`composer lint` and `composer test` were not run for this plan. That is consistent with
plan `0001.x`, where both ran on Linux and are already recorded.

**Run and passing — archive structure**

The archive holds 44 entries, every one under `speedy-sensor/`. Confirmed present:
`speedy-sensor/speedy-sensor.php`, `speedy-sensor/uninstall.php`,
`speedy-sensor/includes/autoload.php`, the four `includes/Admin/Pages/*` files, and
`speedy-sensor/languages/speedy-sensor.pot`. Confirmed absent: `vendor/`, `tests/`,
`plans/`, `systems/`, `bin/`, `composer.json`, `phpcs.xml.dist`, `phpunit.xml.dist`.
This satisfies Step 3.1.

**Run and passing — provenance, and a discrepancy worth recording**

The archive was **not** built from `main`. Blob hashes of the extracted files match
`testing/initial` / `dev` (`7f373cb`) exactly, and differ from `main` (`7366ea4`):

```
Cron.php          zip 3a42211  testing/initial 3a42211  OK    main 68d73c1  differs
speedy-sensor.php zip e0151c5  testing/initial e0151c5  OK
uninstall.php     zip 22c4376  testing/initial 22c4376  OK
Schema.php        zip d20463f  testing/initial d20463f  OK
```

`084ad4f` ("clear all 305 PHPCS findings") lives on `dev`, not on `main`, so `dev` is the
newer and lint-cleaner tree. The tester is therefore getting the better build, and `main` is
behind. This is correct but was not stated when the archive was produced, so it is recorded
here and in the release notes.

**Run and passing — the artifact stays out of git**

```
$ git check-ignore -v dist/speedy-sensor-0.1.0.zip
.gitignore:15:dist    dist/speedy-sensor-0.1.0.zip
```

`git status --short` reports only ` M .gitignore` and never lists the zip, confirming the
build output is invisible to git.

## 6. Downstream Dependencies

**Depends on**

- Nothing. This plan needs no ship-gate change and no code change.

**Blocks**

- Nothing in the repo. It unblocks a human tester.

**Carried forward — known limitations of this build.** These are properties of the code, not
of the packaging, and a tester must be told about them or they will read them as bugs:

1. **Web Vitals and optimisation requests will not work.**
   `includes/Integration/ApiClient.php` defines `PATH_WEB_VITALS` as `/v1/sites/web-vitals`
   and `PATH_SERVICE_REQUEST` as `/v1/sites/optimisation-requests`. The source calls these
   placeholders pending the published API contract. Both features return
   `speedy_sensor_not_found` until the real paths land. `Settings::is_linked()` gates them,
   so with no API key set the UI shows the "not connected" notice and makes no request at all.
2. **`composer test` has never run** — no `mysqli`, no MySQL server, no `WP_TESTS_DIR` on the
   machine where it was attempted. `composer lint` does pass, with zero violations.
3. **The `0000-00-00` date defaults are unproven against a real MySQL 8.**
   `includes/Db/Schema.php` uses `DEFAULT '0000-00-00 00:00:00'` on `started_at` and
   `finished_at`, and `DEFAULT '0000-00-00'` on `recorded_on`. On a strict host whose
   `sql_mode` includes `NO_ZERO_DATE`, `dbDelta()` can fail, and `Schema::install()` does not
   inspect `dbDelta()`'s return value, so the failure is silent. Symptom: the menu appears,
   but "Run scan" never produces results. Worth watching on first staging activation.
4. **Deferred: automated build on tag.** Building by hand is acceptable for a pre-release.
   Once releases become routine, a GitHub Actions workflow that runs `git archive` and
## 7. Outcome & Deviations

### 7.1 What shipped

- `.gitignore` gained `dist/`, so the build output is excluded from git. Verified by
  `git check-ignore -v` (§ 5.3).
- This plan and the `plans/README.md` updates.
- `dist/speedy-sensor-0.1.0.zip` built and structurally verified: 44 entries, single
  `speedy-sensor/` top-level folder, no development files.
- Provenance established and recorded: the artifact is commit `7f373cb` from `dev`.
- `RELEASE_NOTES_0.1.0.md` written, naming the source commit and the carried-forward
  limitations.

### 7.2 Deviations and operator steps

**Deviation — the Release was not created from this machine.** No `gh` CLI and no GitHub
token are available here, so per the boundaries in `AGENTS.md` the operator runs it:

```powershell
# 1. Push the tag for the commit the ZIP was built from.
git tag -a v0.1.0 7f373cb -m "Speedy Sensor 0.1.0 staging pre-release"
git push origin v0.1.0

# 2. Publish the pre-release and attach the artifact.
gh release create v0.1.0 dist\speedy-sensor-0.1.0.zip --prerelease --title "Speedy Sensor 0.1.0 (staging pre-release)" --notes-file RELEASE_NOTES_0.1.0.md
```

Then verify:

```powershell
gh release view v0.1.0 --json url,isPrerelease,assets
Invoke-WebRequest -Uri "https://github.com/VictorBravo9er/wp-plugin-speedy-sensor/releases/download/v0.1.0/speedy-sensor-0.1.0.zip" -OutFile verify.zip
(Get-FileHash verify.zip -Algorithm SHA256).Hash
```

Compare that SHA-256 with the local file to confirm the download is intact:

```powershell
(Get-FileHash dist\speedy-sensor-0.1.0.zip -Algorithm SHA256).Hash
```

**Deviation — CI build deferred**, per § 6 item 4. Not needed for a pre-release.

**Not claimed.** The Release URL is described as the outcome *once the operator runs the
command*. It has not been fetched or verified, and the ZIP has not been installed on a real
WordPress install from this machine.
   attaches the result would remove the manual step and the chance of publishing a stale
   artifact.

<!-- SPLIT-MARKER-8 -->
**Not run — Release creation**

No `gh` binary on `PATH`, and neither `GITHUB_TOKEN` nor `GH_TOKEN` is set. The Release could
not be created or its download URL fetched from this machine. The command is in § 7.2.

<!-- SPLIT-MARKER-7 -->
  from this machine. The publish command is provided in § 7 for the operator to run.
- Staging install of the ZIP is a tester-side action on a real WordPress install. It is the
  purpose of the release, not a gate on this change.

<!-- SPLIT-MARKER-6 -->

No file under `includes/`, `speedy-sensor.php` or `uninstall.php` is touched. The plugin
source is byte-identical to commit `7f373cb`.

<!-- SPLIT-MARKER-5 -->
  tester knows what the ZIP can and cannot do.
- Provide a reproducible one-command rebuild plus a one-command publish.

**Out of scope**

- Wiring the real Speedy API endpoints. `ApiClient::PATH_WEB_VITALS` and
  `PATH_SERVICE_REQUEST` remain placeholders; see § 6.
- Opening the ship gate. `composer test` still has never run.
- Promoting `dev` to `main`, or re-cutting the plugin version. This release is a
  **pre-release** and says so.
- Adding a CI workflow to build on every tag. Deferred; see § 6.

<!-- SPLIT-MARKER-3 -->