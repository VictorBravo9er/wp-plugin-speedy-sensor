# Systems

Durable documentation of how the plugin works. Updated only on significant shifts.

## What belongs here

- New subsystem
- Data model or schema change
- Hook contract change — added, renamed, or removed filter/action
- Architectural decision
- Stack change — PHP version, build tooling, dependency added or removed

## What does not

Routine additions. A new settings field, a bug fix, a refactor that preserves behavior — none of these belong here. If a change does not shift the system, it belongs in `plans/` only.

## Layout

One file per subsystem. A top-level `overview.md` describes how the subsystems fit together and is the entry point.

```
overview.md            architecture, how the parts connect
bootstrap.md           plugin header, constants, autoloading, activation/deactivation
admin.md               settings screens, metaboxes, capability requirements
public.md              front-end hooks, shortcodes, blocks, enqueueing
data-model.md          tables, options, meta keys, schema versioning
integrations.md        external APIs, HTTP clients, auth
invariants.md          rules that must hold everywhere — naming, prefixing, escaping
```

## Update rule

When a change qualifies, edit the affected file in the same commit as the code. Add a line to the relevant section describing the new behavior — do not rewrite the whole document.

Keep each file short. Link to code paths rather than duplicating them; code that drifts from prose is worse than no prose.