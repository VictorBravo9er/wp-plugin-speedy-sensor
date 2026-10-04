# Plan 0002: Standardise the `plans/` Documentation Format

> **Status**: ✅ DONE & VERIFIED (link check, heading-structure check, `git diff --stat`, fence parity)
> **Priority**: `MEDIUM`
> **Target Subsystems**: `plans/` (convention), `AGENTS.md`
> **Created**: 2026-04-10
> **Completed**: 2026-04-10
> **Ship Gate**: N/A — documentation only. No PHP touched, `composer lint` / `composer test` unaffected.

---

## 1. Problem Statement & Context

`plans/README.md` defines a six-section template (`Goal`, `Scope`, `Approach`, `Files touched`,
`Verification`, `Outcome`). It is correct but loses structure that the reference workflow at
`/home/victor/antigravity/Teacher-Assistant-Workspace/plans/` tracks explicitly:

1. **No status frontmatter.** Status is a bare `Status: Done` line. A reader listing the directory
   cannot see state without opening every file, and cannot see *why* it is `Done` (verified how?)
   without reading further.
2. **No plan inventory.** There is no dashboard, no master tracker table and no dependency graph.
   With more than one plan open there is no answer to "what is pending, what blocks what, which plan
   is the current execution target" — the exact question a plan directory exists to answer.
3. **The problem is implicit.** The template starts at `Goal`. Why the change exists and what breaks
   without it has to be inferred from one paragraph, which is the first thing lost when an agent
   resumes a change a week later.
4. **Verification is not split by kind.** "Ran and passed", "written but NOT run" and "consequences
   to be honest about" all live under one heading, and a *pre-completion* plan has no guidance on
   what to write there before the commands exist.
5. **Deviations have no section.** Recording what actually shipped versus what was planned is
   mentioned in the template prose but is not a heading, so on completion it competes with the plan
   itself instead of clearly separating intent from result.
6. **Reference is inconsistent.** `plans/0001-plugin-scaffold.md` already carries the deviations,
   bugs-found and follow-ups material under ad-hoc `###` subheadings that exist in no template.

Reference conventions worth adopting, verified by reading those files: numbered `##` sections, a
`> **Status**:` blockquote carrying status plus verification provenance, `### Step N.N` sub-steps
under `## 3. Implementation Steps`, a `## Verification Plan` split into automated vs manual, a
downstream/dependency section, and a README with an emoji progress dashboard, a mermaid dependency
graph, a master tracker table, a milestone checklist and a status legend.

---

## 2. Scope

In:
- `plans/README.md` — tracker, legends, template.
- `plans/0001-plugin-scaffold.md` — heading relabel into the new structure.
- `AGENTS.md` — the `plans/` bullet only.

Out:
- `systems/` — nothing shifts in a subsystem, schema or hook contract, so its own
  "significant shifts only" rule says no update.
- Any PHP, JS, CSS or config. This plan must not change runtime behaviour or the open ship gate.
- Renaming any option key, hook, meta key, REST route or table. `plans/` filenames are not public
  surface, but the reference repo's `NN` scheme is still rejected; see § 3.2.
- Migrating the reference repo's other plans. Only its structure is borrowed.

## 3. Architecture & Design

```
                         plans/  (per-change, transient)
                                     │
        ┌────────────────────────────┴────────────────────────────┐
        │                                                         │
  README.md                                              NNNN-short-slug.md
  ───────────                                           ──────────────────
  Dashboard + tracker          index over the dir       # Plan NNNN: Title
        │                                                         │
        │  links every plan file ──────────────────────────────────┤
        ▼                                                         ▼
  Master tracker table                                   1. Problem Statement & Context
  Dependency graph (mermaid)                             2. Scope
  Milestones                                             3. Architecture & Design
  Status + verification legends                          4. Implementation Steps (Step N.N)
  Naming + lifecycle rules                               5. Verification Plan
  Template (fenced)                                      6. Downstream Dependencies
                                                          7. Outcome & Deviations  ← written at close
        │                                                         │
        └──────────────── plan-before-code rule ──────────────────┘
                  (the file exists before any edit)
```

Decision: keep this repo's four-digit `NNNN-short-slug.md` naming mandated by `AGENTS.md` instead of
the reference's `NN` / `NN.N` scheme. `NNNN.x-…` is allowed only for sub-numbered verification
checkpoints of a prior plan, matching the reference's `02.x-checkpoint-1.md` pattern without
disturbing the numbering width.

Decision: the mermaid graph and tracker table live only in `README.md`. Individual plan files stay
dependency-free so a closed plan can be archived without breaking a graph.

Decision: one canonical seven-section structure for every plan, so a reader who learns it once can
navigate any file. Implementation steps live as `### Step 3.N` under `§ 3. Architecture & Design`
rather than as their own top-level section. The reference splits them, but that produced a plan
whose `Step 3.N` numbers belonged to a section title no other plan had. Folding them in keeps
`Step N.N` citable while giving every plan identical section numbers.

---

### Step 3.1: Rewrite `plans/README.md`

- Add a `📊 Live Progress Dashboard` metrics table: total, done, in progress, pending, current
  execution target, ship gate, system readiness.
- Add a `Plan Directory & Dependency Graph` mermaid `flowchart TD`, one subgraph per milestone, plus
  an explicit "Ship Gate — not yet a plan" node so the open 0001 follow-up is visible in the graph.
- Add a `Master Implementation & Progress Tracker` table: Plan File (linked), Scope & Title,
  Priority, Status, Progress, Verification, Notes.
- Add a milestone checklist mirroring the tracker.
- Add `Status Legend` (⏳ 🚧 🔍 ✅ ❌) and `Verification Legend` (🟢 🟡 🔴 ⚪).
- Replace the template with the numbered structure, keeping sections 1–6 as the *plan* and section 7
  as the *aftermath*.
- Add a "Rules every plan obeys" section so the plan-before-code rule and the honesty rule sit where
  agents read first.

### Step 3.2: Backfill `plans/0001-plugin-scaffold.md`

Relabel headings only, preserving all existing content verbatim:

| Old | New |
| :--- | :--- |
| `# 0001 — Plugin scaffold…` | `# Plan 0001: Plugin scaffold…` + status blockquote |
| `## Goal` | `## 1. Problem Statement & Context` |
| `## Scope` | `## 2. Scope` |
| `## Approach` | `## 3. Architecture & Design` |
| `## Files touched` | `## 4. Files Touched` |
| `## Verification` | `## 5. Verification Plan` → `5.1` run and passing, `5.2` written but NOT run, `5.3` consequences |
| `## Outcome` | `## 6. Outcome` → `6.1` shipped, `6.2` deviations, `6.3` bugs, `6.4` follow-ups |

The existing ship-gate note moves into the status blockquote rather than being duplicated, and the
one in-body cross-reference (`see Deviations` → `see § 6.2`) is repointed at its renumbered heading.
§ 6.4 of 0001 is the follow-up list the README milestone checklist cites, so it must survive intact.

### Step 3.3: Update `AGENTS.md`

- Point the `plans/` bullet at the richer section list.
- State the plan-before-code rule explicitly and note that `plans/README.md` is the tracker of record.

## 4. Files Touched

- `plans/0002-plan-documentation-standard.md` — this file.
- `plans/README.md` — dashboard, graph, tracker, milestones, legends, new template, rules.
- `plans/0001-plugin-scaffold.md` — heading relabel only, content verbatim.
- `AGENTS.md` — `plans/` bullet refreshed.

## 5. Verification Plan

### 5.1 Automated Verification

```bash
# every plan file linked from README resolves
grep -o '(\./[0-9][0-9.]*[a-z0-9-]*\.md)' plans/README.md \
  | tr -d '()' | sed 's|^\./||' \
  | while read -r f; do [ -f "plans/$f" ] || echo "BROKEN LINK: plans/$f"; done

# no old-format headings survive in the backfilled plan
grep -n '^## \(Goal\|Scope\|Approach\|Files touched\|Verification\|Outcome\)$' \
  plans/0001-plugin-scaffold.md

# git confirms only the four intended files changed, no PHP touched
git --no-pager diff --stat
```

### 5.2 Not verified

Runtime gates are irrelevant here — documentation only, no PHP. What is *not* verified: nothing has
been rendered in GitHub's markdown view, and the mermaid block has not been parsed by a renderer,
only read for unescaped characters.

### 5.3 Verification Record

```
$ # every plan file linked from README resolves
$ grep -o '(\./[0-9][0-9.]*[a-z0-9-]*\.md)' plans/README.md | tr -d '()' \
    | sed 's|^\./||' | while read -r f; do [ -f "plans/$f" ] || echo "BROKEN LINK: plans/$f"; done
(no output — all links resolve)

$ # no old-format headings survive in the backfilled plan
$ grep -n '^## \(Goal\|Scope\|Approach\|Files touched\|Verification\|Outcome\)$' \
    plans/0001-plugin-scaffold.md
(no output — none remain)

$ # only the intended files changed, no PHP touched
$ git --no-pager diff --stat
 AGENTS.md                     |  31 +++---
 plans/0001-plugin-scaffold.md | 154 +++++++++++++++++++++++++++++-----
 plans/README.md               | 218 +++++++++++++++++++++++++++++++++++++-----
 3 files changed, 340 insertions(+), 63 deletions(-)

$ # fenced code blocks balanced in every touched file
plans/README.md: 6 fences
plans/0001-plugin-scaffold.md: 2 fences
plans/0002-plan-documentation-standard.md: 4 fences
AGENTS.md: 0 fences
```

`plans/0002-plan-documentation-standard.md` is new and therefore untracked, so it does not appear in
`diff --stat`. No `.php` file appears in any diff.

Heading structure confirmed on disk, and it now matches across files:

```
0001: 1 Problem | 2 Scope | 3 Architecture | 4 Files | 5 Verification | 6 Outcome
0002: 1 Problem | 2 Scope | 3 Architecture | 4 Files | 5 Verification | 6 Downstream | 7 Outcome
```

`0001` stops at § 6 because it was written before § 6 existed and has no dependencies to declare;
everything it does have is numbered identically to `0002`. README template lists all seven.

## 6. Downstream Dependencies

- **Blocks:** the next plan file, whichever it is. An agent opening `plans/README.md` before
  planning now gets the reference structure.
- **Independent of:** `systems/`. No subsystem, schema or hook contract moves, so `systems/` gets no
  update — per its own "significant shifts only" rule.
- **Not a substitute for:** 0001 follow-up 6.1, "install Composer and run `composer lint` /
  `composer test`". That gate stays open and is unrelated to documentation.

## 7. Outcome & Deviations

### 7.1 Shipped

`plans/` now matches the reference structure. README gained a progress dashboard, a mermaid
dependency graph with the ship gate visible as its own node, a master tracker table, a milestone
checklist, separate status and verification legends, a seven-rule "Rules Every Plan Obeys" block,
and a full fenced template. `0001` was relabelled into numbered sections with content verbatim, and
`AGENTS.md` points at the new structure and states the plan-before-code rule.

### 7.2 Deviations from the plan

1. **Implementation steps folded into § 3.** The plan described a separate top-level
   `## 3. Implementation Steps`, matching the reference. Writing it that way produced two different
   section maps in one directory — `0001` had `Architecture & Design` at § 3 with no steps section,
   `0002` had both at § 3 — and the same `Step 3.1` number would mean different things. Steps now
   live as `### Step 3.N` under `§ 3. Architecture & Design`, giving every plan identical numbering
   while keeping steps individually citable. Recorded in § 3.
2. **A `Scope` section was added to both files.** The original README template had `Scope`; the
   reference's does not. Dropping it would have lost the explicit Out-of-scope boundary that keeps a
   plan from quietly absorbing adjacent work. `0001`'s existing scope content is untouched — only
   renumbered from a bare `## Scope` to `## 2. Scope`.
3. **`0001` stops at § 6.** It has no `Downstream Dependencies` section, so its Outcome sits at § 6
   rather than § 7. Inventing a dependency section for a completed plan would have been fabrication.
   Its numbering matches `0002` for every section it does have.
4. **No plan archive directory.** Not created. With two plans, one of them open, `git mv` at close
   is ceremony; the README instructs removal from the tracker table instead.
5. **`NNNN.x` sub-numbering kept** for verification checkpoints, borrowing the reference's
   `02.x-checkpoint-1.md` idea within this repo's four-digit scheme.

### 7.3 Bugs Found and Fixed During Self-Review

- Two inserts landed at the wrong offset and silently scrambled document order: the "Rules Every
  Plan Obeys" block was written *inside* the closing sentence of the Verification Legend, and two
  `Decision:` paragraphs of `0002` § 2 were stranded after `## 7. Outcome & Deviations`. Both
  caught by reading the files back, both repaired.
- `0001` contained a live cross-reference, `Deferred — see Deviations`, pointing at a heading that
  no longer existed after relabelling. Repointed to `§ 6.2`.
- A reference written into `0002` Step 3.2 said "`§ 7.6` of 0001" for the follow-up list. 0001's
  follow-ups are § 6.4; the number was invented and is now correct.
- An early draft of the `AGENTS.md` bullet described the section list with a self-contradictory
  parenthetical about `4. Implementation Steps` vs `4. Files Touched`. Replaced with the single
  canonical list.

### 7.4 Follow-ups, in the order they matter

1. Create `plans/0003-lint-and-test-gate.md` and clear the open ship gate from 0001 § 6.4: install
   Composer, run `composer lint` and `composer test`, stand up WordPress + MySQL, exercise
   `dbDelta()`, a full cron scan, resume across passes, and one scan against the real Speedy API
   shape. Every other claim in this repo is downstream of that.
2. Delete `plans/0001-plugin-scaffold.md` and `plans/0002-plan-documentation-standard.md` once both
   are closed and merged, and drop their rows from the tracker table.
3. Render `plans/README.md` on GitHub once and confirm the mermaid diagram draws — checked by
   reading only, as § 5.2 states.