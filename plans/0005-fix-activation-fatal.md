# Plan 0005: Fix fatal on plugin activation

> **Status**: ◍ In Review / Verification
> **Priority**: `CRITICAL`
> **Target Subsystems**: `includes/autoload.php`, `tests/AutoloaderTest.php`
> **Created**: 2026-05-10
> **Ship Gate**: still closed. `composer test` has never run and no PHP binary exists on
>   this machine, so the regression test is written but unexecuted. See § 5.2.

Bug fix. One line of shipped runtime code is wrong; the fix restores every admin screen,
the scan pipeline and activation.

---

## 1. Problem Statement & Context

A staging tester uploaded `speedy-sensor-0.1.0.zip` and WordPress refused to activate it:

> Plugin could not be activated because it triggered a fatal error.

The cause is `includes/autoload.php` line 32:

```php
if ( false !== strpos( $relative, '.' ) || false !== strpos( $relative, '/' ) || false !== strpos( $relative, '\\' ) ) {
```

The third clause is the defect. `'\\'` in a single-quoted PHP string is one literal
backslash, so the guard rejected any name containing the PSR-4 namespace separator.
`$relative` is the class name with `Speedy_Sensor\` stripped, so it retains every inner
separator. Every class in a sub-namespace was silently unloaded:

| Class | `$relative` | Contains `\` | Outcome before fix |
| :--- | :--- | :--- | :--- |
| `Speedy_Sensor\Plugin` | `Plugin` | no | loaded |
| `Speedy_Sensor\Install\Activator` | `Install\Activator` | yes | **unloaded** |
| `Speedy_Sensor\Admin\Pages\Dashboard` | `Admin\Pages\Dashboard` | yes | **unloaded** |

25 of the 26 shipped classes never loaded. `register_activation_hook()` calls
`Activator::activate`, which cannot be autoloaded, so activation fatals with
*Class 'Speedy_Sensor\Install\Activator' not found*. Only `Plugin` loaded, which is
why the plugin file parsed and produced no earlier, more obvious error.

This is in `dev` at `846d5a7` and therefore in any ZIP built from it. The artifact in
`dist/` was verified byte-identical to that commit, so the tester installed exactly this
defect.

## 2. Scope

**In scope**

- Remove the backslash rejection from the autoloader guard.
- Keep traversal protection intact.
- Add a regression test that would have caught this.

**Out of scope**

- Version reconciliation between `0.1.0` and `0.0.1`, and the Release upload. Separate.
- Opening the ship gate.

## 3. Architecture & Design

### Step 3.1: Narrow the guard to genuine traversal characters

Drop the backslash clause, keep the dot and forward-slash checks. The guard's stated
purpose is stopping a name from escaping `includes/`. Climbing requires `..`, which
requires a dot, so rejecting `.` alone still blocks traversal. Backslashes are then
converted to `/` by `str_replace()` on the next line, which is the intended PSR-4
behaviour.

Rejected after the fix: any name containing `.` or `/`. The backslash is no longer a
rejection character, it is the separator.

### Step 3.2: Prove it against the real class list

Simulated the resolver over all 26 shipped class names, plus two adversarial names:
`Speedy_Sensor\Evil\..\..\x` and `Other_Namespace\Thing`. All 26 resolve to a file that
exists; traversal is rejected; a foreign namespace is ignored by the prefix check.

### Step 3.3: Regression test

`tests/AutoloaderTest.php` walks the real `includes/` tree, derives the fully qualified
class name of every file, and calls `class_exists()` so the registered autoloader is
actually exercised. Added a focused case for a nested class and for the activator, plus
a case asserting traversal is still refused.

## 4. Files Touched

| File | Change |
| :--- | :--- |
| `includes/autoload.php` | Removed the backslash clause from the traversal guard; comment corrected. |
| `tests/AutoloaderTest.php` | New. Four tests covering resolution, nesting, the activator and traversal. |

### 5.3 Verification Record

- Autoloader simulation over 26 class names + 2 adversarial names: all 26 resolve,
  traversal rejected, foreign namespace ignored.
- Hex dump of the original line 32 taken before editing, confirming `27 5C 5C 27`
  (`'\\'`) in the source, which is what made the clause match a real backslash.
- `git archive` comparison of the installed ZIP against `HEAD`: the ZIP in `dist/` is
  byte-identical to `846d5a7`, so the artifact carries exactly this defect.

## 6. Downstream Dependencies

- The staging tester must be sent a rebuilt ZIP. The currently built artifact is broken.
- Plans and system documents describing the plugin as "scaffolded end to end"
  understated the risk: no test had ever executed, which is how a one-line loader defect
  shipped. The autoloader is now covered.
- Plans `0001`–`0004` remain open on `composer test`.

## 7. Outcome & Deviations

### 7.1 What shipped

- `includes/autoload.php` guard corrected.
- `tests/AutoloaderTest.php` added.
- This plan.

### 7.2 Deviations

**Deviation — the regression test is unexecuted.** `AGENTS.md` requires reproducing the
bug before fixing it and shipping a regression test. The bug was reproduced by reading
and by simulating the resolver; it was not reproduced by running WordPress, because no
PHP runtime exists on this machine. The test is written and is expected to pass, but
that expectation is not a result.

**Deviation — no lint.** PHPCS could not run. The change is a deletion of one clause
from an `if` condition plus a comment rewrite, so style risk is low but unmeasured.

**Not claimed.** That the plugin now activates. That requires a rebuilt ZIP and a real
install attempt, neither performed.
## 5. Verification Plan

### 5.1 Static reasoning, performed

Every call site of the classes previously unloaded was read and confirmed to exist:
`get_resumable`, `complete`, `update`, `fail`, `get_ids_beyond`, `delete_by_ids`,
`insert_many`, `replace_for_scan`, `get_by_scan`, `delete_for_scans`, `delete_older_than`,
`table_inventory`, `inventory`, `measure`, `attribute`, `count_issues`, `install`,
`needs_upgrade`, `install_defaults`. No call site needed changing, because the defect was
in loading, not in the call graph.

### 5.2 Gates — NOT run

- `composer lint`: **not run.** No `php` on `PATH` and no `vendor/` present.
- `composer test`: **not run.** No PHP, no MySQL, no `WP_TESTS_DIR`.
- Activation on a real WordPress install: **not performed.**

The resolver simulation in § 3.2 was run in PowerShell, which reproduces the `strpos`
and `str_replace` semantics faithfully for these inputs. It is not a substitute for
executing PHP, and `AutoloaderTest` has not been executed.