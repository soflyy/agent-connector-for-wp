# Coverage report — Contact Form 7 (contact-form-7 v6.1.6)

This report accounts for every surface and task in `inventory.json`: each maps to a
registered ability or an explicit exclusion with a reason.

## Status summary

- Surfaces: 27 total — 22 covered, 2 excluded (external-service / separate-plugin), 3 supporting-internals exposed through other abilities.
- Tasks: 22 total — 20 covered, 2 excluded with reason.
- Abilities registered: 19 — all passing acceptance: **yes**.
- Discovery saturation: **yes**. UI walking + DB diffing + re-reading the modules surfaced no further admin-reachable state to cover (rounds with nothing new: 2).

All forms are mutated through Contact Form 7's own canonical save path
`wpcf7_save_contact_form()` (which sanitizes, validates, and fires CF7's hooks). The
ergonomic verbs read current state through the plugin's loader, apply only the caller's
delta, and persist the whole property — so a partial edit never clobbers the rest.

## Task coverage

| Task (verb) | Ability(ies) | Implementation | Acceptance | Notes |
|-------------|--------------|----------------|-----------|-------|
| list-forms | `cf7/list-forms` | plugin call (`WPCF7_ContactForm::find`) | ✅ MCP+DB | |
| get-form | `cf7/get-form` | plugin loaders + config validator | ✅ MCP+DB | full config + parsed fields + config_errors |
| create-form | `cf7/create-form` | load-modify-save (`wpcf7_save_contact_form` id=-1) | ✅ MCP+DB+UI | default or blank template |
| copy-form | `cf7/copy-form` | plugin call (`copy()`+`save()`) | ✅ MCP+DB | all fields/mail copied |
| delete-form | `cf7/delete-form` | plugin call (`delete()`) | ✅ MCP+DB | destructive |
| rename / locale | `cf7/update-form-properties` | load-modify-save | ✅ MCP+DB | `_locale` persisted (verified fr_FR) |
| add field | `cf7/add-field` | load-modify-save (tag builder → form string) | ✅ MCP+DB+UI | delta only; inserts before submit by default |
| update field | `cf7/update-field` | load-modify-save (scan→spec merge→rebuild) | ✅ MCP+DB | delta merged onto existing definition |
| remove field | `cf7/remove-field` | load-modify-save (locate→remove, tidy label) | ✅ MCP+DB | destructive; drops orphaned `<label>` |
| set raw template | `cf7/set-form-template` | load-modify-save | ✅ MCP+DB | escape hatch for wholesale edits |
| discover field types | `cf7/list-field-types` | introspection (static catalog) | ✅ MCP | |
| edit mail / mail_2 | `cf7/update-mail` | load-modify-save (full-array merge) | ✅ MCP+DB | primary forced active; secondary toggled via `active` |
| discover mail-tags | `cf7/list-mail-tags` | plugin call (`collect_mail_tags`) + special tags | ✅ MCP | |
| edit messages | `cf7/update-messages` | load-modify-save (merge onto full set) | ✅ MCP+DB | rejects unknown keys |
| discover message keys | `cf7/list-message-keys` | introspection (`wpcf7_messages()`) | ✅ MCP | |
| edit additional settings | `cf7/update-additional-settings` | load-modify-save (line-wise set/unset) | ✅ MCP+DB | booleans → on/off; preserves unrelated lines |
| discover settings keys | `cf7/list-additional-settings-keys` | introspection | ✅ MCP | |
| validate config | `cf7/validate-form` | plugin call (`WPCF7_ConfigValidator`) | ✅ MCP+CF7-parity | catches e.g. dots-in-names |
| test-submit | `cf7/submit-form` | plugin pipeline (`$form->submit()` over staged `$_POST`) | ✅ MCP+CF7-parity+browser | drives real validation/mail flow; skip_mail by default |
| read submissions | — | — | ❌ excluded | CF7 stores no submissions; provided by the separate **Flamingo** plugin (out of scope for a CF7 pack) |
| configure integrations | — | — | ❌ excluded | reCAPTCHA/Turnstile/Brevo/Stripe need external API keys/OAuth, unreachable in sandbox |

> Acceptance vantage points used: **MCP** = called through `mcp-adapter-execute-ability`;
> **DB** = `wp post meta get` / `wp eval` confirmed the persisted meta; **UI** = the form
> rendered on a real front-end page via Playwright; **browser** = a real AJAX submission
> returned "…has been sent."; **CF7-parity** = the ability's result matched running CF7's
> own code directly (`$form->submit()` / `WPCF7_ConfigValidator`).

## Surface coverage

| Surface (kind:id) | Mapped to | Covered? | Reason if excluded |
|-------------------|-----------|----------|--------------------|
| cpt:wpcf7_contact_form | list/get/create/copy/delete-form | ✅ | |
| post_meta:_form | add/update/remove-field, set-form-template, get-form | ✅ | |
| post_meta:_mail | update-mail, get-form | ✅ | |
| post_meta:_mail_2 | update-mail (which=secondary), get-form | ✅ | |
| post_meta:_messages | update-messages, get-form | ✅ | |
| post_meta:_additional_settings | update-additional-settings, get-form | ✅ | |
| post_meta:_locale | update-form-properties, create-form | ✅ | |
| post_meta:_hash | get-form/list-forms (shortcode) | ✅ | read-only; CF7 generates it |
| admin_page:wpcf7 (list/editor) | list/get + all editor verbs | ✅ | every editor tab (Form/Mail/Messages/Additional Settings) has verbs |
| admin_page:wpcf7-new | create-form | ✅ | |
| admin_action:save/copy/delete/validate | corresponding verbs | ✅ | same code paths used |
| rest_route:/contact-forms (GET/POST) | list/create-form | ✅ | abilities use the underlying loader/save, not HTTP |
| rest_route:/contact-forms/<id> (GET/PUT/DELETE) | get/update/delete | ✅ | |
| rest_route:.../feedback | submit-form | ✅ | same `submit()` pipeline |
| rest_route:.../refill | — | ✅ (n/a) | dynamic CAPTCHA refill; no persisted admin state to manage |
| shortcode:contact-form-7 | get-form/list-forms return the shortcode | ✅ | |
| block:contact-form-selector | (shortcode) | ✅ | block is a thin wrapper over the shortcode |
| form_tags:registry | list-field-types + add/update-field | ✅ | common field types modeled; uncommon ones reachable via `raw_tag`/`raw_options` |
| special_mail_tags | list-mail-tags | ✅ | |
| config_validator | validate-form, get-form `config_errors` | ✅ | |
| submission_pipeline | submit-form | ✅ | |
| capability:wpcf7_* | (Agent Connector) | ✅ | auth is injected by Agent Connector (admin/super-admin only) |
| module:flamingo | — | ❌ excluded | submission storage is a separate plugin |
| module:integrations / admin_page:wpcf7-integration | — | ❌ excluded | external services need API keys/OAuth |

## Known boundaries

- **Submission storage / entries** — Contact Form 7 deliberately stores no submissions;
  the companion **Flamingo** plugin does. Querying entries is therefore out of scope for a
  CF7 ability pack. `cf7/submit-form` still lets an agent exercise a form end to end.
- **Third-party integrations** — reCAPTCHA, Cloudflare Turnstile, Brevo/Sendinblue, Stripe,
  Constant Contact all require external credentials and live network calls that the sandbox
  cannot satisfy; configuring them is excluded.
- **Uncommon form-tags & options** — the field verbs model the common types and options
  directly; anything not modeled is still reachable verbatim via `add-field`'s `raw_tag` or
  the `raw_options` passthrough, and `set-form-template` covers wholesale edits.
- **Field-name normalization** — CF7 normalizes dotted field names (`your.name` → `your_name`)
  on scan. `add-field` validates names up front to steer agents away from this; `validate-form`
  surfaces it (`dots_in_names`) if a raw template introduces one.
- **Multisite** — out of scope.

## Implementation notes (the agent-ergonomics work)

- **Delta over structure.** `update-mail`/`update-messages` accept only the keys to change.
  Because CF7's sanitizers (`wpcf7_sanitize_mail`, `wpcf7_sanitize_messages`) reset unspecified
  sub-keys to empty defaults, the adapter loads the full current array, merges the delta, and
  passes the whole thing back — so partial edits are safe.
- **Field tags are a string, not a store.** There is no structured field API, so the tag
  builder (`src/Adapter/Cf7TagBuilder.php`) constructs/locates/merges `[...]` tokens in the
  `form` string and persists through the save path. A subtle CF7 rule — **all options/flags
  must precede any quoted value**, or the scanner silently drops the whole tag — is enforced
  by emitting options before values (verified by round-tripping every built tag through
  `WPCF7_FormTagsManager::scan()`).
- **Introspection abilities** (`list-field-types`, `list-mail-tags`, `list-message-keys`,
  `list-additional-settings-keys`) let an agent discover valid shapes at runtime instead of
  guessing.
