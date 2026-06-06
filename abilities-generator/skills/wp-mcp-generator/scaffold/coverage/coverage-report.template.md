# Coverage report — {{TARGET_LABEL}} ({{TARGET_SLUG}} vX.Y.Z)

This report is a deliverable. It must account for **every** surface and task in
`inventory.json`: each row maps to a registered ability or an explicit exclusion with a
reason. An unexplained gap is treated as not-covered.

## Status summary

- Surfaces: N total — C covered, E excluded, ? open
- Tasks: N total — C covered, ? open
- Abilities registered: N — all passing acceptance? yes/no
- Discovery saturation: yes/no (rounds with nothing new: K)

## Task coverage

| Task (verb) | Ability(ies) | Implementation | Acceptance | Notes |
|-------------|--------------|----------------|-----------|-------|
| add-field-to-group | `{{VENDOR}}/add-field` | load-modify-save via plugin save path | ✅ UI+DB+REST | |

> Implementation column: `plugin call` / `load-modify-save` / `wp core` / `raw db` — and why.
> Acceptance column: which independent vantage points confirmed it (UI / DB / REST).

## Surface coverage

| Surface (kind:id) | Mapped to | Covered? | Reason if excluded |
|-------------------|-----------|----------|--------------------|
| rest_route:/wc/v3/products | `{{VENDOR}}/...` | ✅ | |
| admin_page:settings-advanced | — | ❌ excluded | purely presentational; no state |

## Known boundaries

- Features needing an external service unreachable in the sandbox: …
- Anything deliberately out of scope (multisite, etc.): …
