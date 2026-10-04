# Plans

One file per modification. Transient.

## Naming

`NNNN-short-slug.md` — four digits, incrementing, kebab-case. Never reuse a number.

```
0001-add-settings-page.md
0002-fix-activation-guard.md
```

## Lifecycle

1. **Before the change** — file created with status `Proposed`. Scope and approach written down.
2. **During** — status moves to `In Progress`. Approach may be revised; note why.
3. **On completion** — file edited again. Status set to `Done`, the *Verification* section filled with the commands actually run and their real output, and *Outcome* filled with what shipped plus any deviation from the original plan.
4. **Closed** — archived or deleted once the change is merged and no longer being revisited.

A plan file is not finished when the code is written. It is finished when the outcome and verification are recorded.

## Template

```markdown
# NNNN — Title

Status: Proposed | In Progress | Done | Dropped
Created: YYYY-MM-DD
Completed: YYYY-MM-DD

## Goal

One paragraph. What changes for whom.

## Scope

In:
- ...

Out:
- ...

## Approach

How it will be done. Concrete enough that a different agent could execute it.

## Files touched

- `path/to/file.php` — what changes and why

## Verification

Commands run and their actual results. Not "tests pass" — the command and the output.

## Outcome

Written after the change lands. What shipped, what was dropped, what deviated and why.
```