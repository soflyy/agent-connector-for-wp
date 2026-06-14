# Coverage report — Yoast SEO (wordpress-seo v27.8)

This report accounts for every surface and task in `inventory.json`: each maps to a
registered ability or an explicit exclusion with a reason.

## Status summary

- Surfaces: 15 total — 9 covered, 5 excluded (with reason), 1 informational (Yoast's own
  read-only abilities, complemented not duplicated).
- Tasks: 25 total — 25 covered.
- Abilities registered: 8 — all passing acceptance.
- Discovery saturation: yes. Code read (WPSEO_Meta, WPSEO_Taxonomy_Meta, WPSEO_Options +
  its three option classes, WPSEO_Replace_Vars, the indexable watchers) plus DB diffing and
  frontend rendering surfaced nothing new after the abilities were built.

## Abilities

| Ability | Verb | Annotations |
|---------|------|-------------|
| `yoast/find-content` | discover posts/terms + SEO summary | readonly |
| `yoast/get-content-seo` | read one post's/term's SEO | readonly |
| `yoast/update-content-seo` | change a delta of a post's/term's SEO | write, idempotent |
| `yoast/get-search-appearance` | read wpseo_titles (structured) | readonly |
| `yoast/get-social-settings` | read wpseo_social | readonly |
| `yoast/get-general-settings` | read curated wpseo | readonly |
| `yoast/update-settings` | write a delta across the three option groups | write, idempotent |
| `yoast/list-replacement-variables` | list %%vars%% | readonly |

## Task coverage

| Task (verb) | Ability(ies) | Implementation | Acceptance | Notes |
|-------------|--------------|----------------|-----------|-------|
| find-content | `yoast/find-content` | WP_Query / get_terms + WPSEO readers | ✅ MCP | post + term listings |
| get-content-seo | `yoast/get-content-seo` | WPSEO_Meta / WPSEO_Taxonomy_Meta readers | ✅ MCP+DB | |
| set-focus-keyphrase | `yoast/update-content-seo` | load-modify-save via plugin path | ✅ MCP+DB | |
| set-seo-title-and-description | `yoast/update-content-seo` | plugin save path | ✅ MCP+DB+frontend | `<title>` rendered with %%sep%%/%%sitename%% resolved |
| set-robots | `yoast/update-content-seo` | plugin save path | ✅ MCP+DB+frontend | frontend `robots: noindex, nofollow` confirmed; enum default/index/noindex |
| set-canonical | `yoast/update-content-seo` | plugin save path | ✅ MCP+DB | |
| set-cornerstone | `yoast/update-content-seo` | plugin save path | ✅ MCP+DB | posts and terms |
| set-social-overrides | `yoast/update-content-seo` | plugin save path | ✅ MCP+DB+frontend | og:title rendered |
| set-breadcrumb-title | `yoast/update-content-seo` | plugin save path | ✅ MCP+DB | |
| set-schema-types | `yoast/update-content-seo` | plugin save path | ✅ MCP+DB | posts only |
| set-primary-term | `yoast/update-content-seo` (`primary_terms`) | raw update_post_meta (Yoast stores it outside WPSEO_Meta) | ✅ MCP+DB | |
| read-search-appearance | `yoast/get-search-appearance` | WPSEO_Options::get_option | ✅ MCP | |
| set-title-and-metadesc-templates | `yoast/update-settings` | WPSEO_Options::save_option | ✅ MCP+DB+frontend | title-post, metadesc-page, etc. |
| set-title-separator | `yoast/update-settings` | WPSEO_Options::save_option | ✅ MCP+DB | sc-* key; invalid value rejected by Yoast validation |
| set-content-type-indexing | `yoast/update-settings` | WPSEO_Options::save_option | ✅ MCP+DB | noindex-tax-post_tag, etc. |
| set-schema-defaults | `yoast/update-settings` | WPSEO_Options::save_option | ✅ MCP+DB | schema-page-type-page |
| configure-breadcrumbs | `yoast/update-settings` | WPSEO_Options::save_option | ✅ MCP | breadcrumbs-* keys |
| set-site-representation | `yoast/update-settings` | WPSEO_Options::save_option | ✅ MCP+DB | company_or_person, company_name |
| read-social-settings | `yoast/get-social-settings` | WPSEO_Options::get_option | ✅ MCP | |
| set-social-settings | `yoast/update-settings` | WPSEO_Options::save_option | ✅ MCP+DB | facebook_site, og_default_image, twitter_card_type |
| read-general-settings | `yoast/get-general-settings` | WPSEO_Options::get_option | ✅ MCP | curated subset |
| toggle-features | `yoast/update-settings` | WPSEO_Options::save_option | ✅ MCP+DB | enable_xml_sitemap confirmed |
| set-webmaster-verification | `yoast/update-settings` | WPSEO_Options::save_option | ✅ via key routing | googleverify etc. (string keys) |
| configure-crawl-cleanup | `yoast/update-settings` | WPSEO_Options::save_option | ✅ via key routing | remove_*/deny_*_crawling |
| list-replacement-variables | `yoast/list-replacement-variables` | WPSEO_Replace_Vars | ✅ MCP | 42 variables returned |

> Acceptance: every write ability was driven through the live MCP server
> (`mcp-adapter-execute-ability`) and confirmed from independent vantage points — the raw
> DB (`wp_postmeta`, `wpseo_taxonomy_meta`, `wpseo_titles`/`wpseo`/`wpseo_social` options)
> and, where it produces output, the rendered frontend `<head>`.

## Surface coverage

| Surface (kind:id) | Mapped to | Covered? | Reason if excluded |
|-------------------|-----------|----------|--------------------|
| post_meta:metabox:post-seo | `yoast/get-content-seo`, `yoast/update-content-seo` | ✅ | |
| post_meta:primary-term | `yoast/update-content-seo` (`primary_terms`) | ✅ | |
| term_meta:metabox:term-seo | `yoast/get-content-seo`, `yoast/update-content-seo` | ✅ | |
| option:admin_page:search-appearance | `yoast/get-search-appearance`, `yoast/update-settings` | ✅ | |
| option:admin_page:social | `yoast/get-social-settings`, `yoast/update-settings` | ✅ | |
| option:admin_page:general-settings | `yoast/get-general-settings`, `yoast/update-settings` | ✅ | |
| other:api:options-facade | (used internally by all settings abilities) | ✅ | |
| other:api:replacement-vars | `yoast/list-replacement-variables` | ✅ | |
| table:indexable | (rebuilt automatically by the write abilities) | ✅ | posts rebuild on shutdown; terms force-rebuilt after write |
| other:abilities:yoast-core | — | ℹ️ informational | Yoast core already ships these read-only score abilities; this pack complements, does not duplicate |
| admin_page:tools:import-export | — | ❌ excluded | Bulk settings blob import/export. The same settings are individually addressable via `yoast/update-settings`; a blob importer adds little for an agent and risks clobbering. |
| admin_page:tools:file-editor | — | ❌ excluded | Edits robots.txt/.htaccess. The host plugin (Agent Connector) already exposes `file-read`/`file-write`; duplicating it here is redundant. |
| admin_page:tools:bulk-editor | — | ❌ excluded (covered by iteration) | Bulk title/description editing is achievable by iterating `yoast/find-content` → `yoast/update-content-seo`; no dedicated bulk verb. |
| admin_page:feature:redirects | — | ❌ excluded | Yoast Premium only; not present in the free plugin. |
| rest_route:feature:integrations | — | ❌ excluded | SEMrush/Wincher/AI/first-time-configuration/import need external services or Premium and are unreachable in the sandbox. The settings these toggle (e.g. `semrush_integration_active`) remain writable via `yoast/update-settings`. |

## Known boundaries

- **Premium-only** features (redirect manager, multiple keyphrases, internal-linking
  suggestion data) are absent from the free plugin and out of scope.
- **External-service integrations** (SEMrush, Wincher, MyYoast connection, AI generation,
  IndexNow pinging) need credentials/endpoints unreachable in the sandbox; their on/off
  toggles are still settable via `yoast/update-settings`.
- **Multisite** is out of scope (the `wpseo_ms` network option is not exposed).
- **Indexables:** the sandbox had never run Yoast's indexing, so `wp_yoast_indexable` is
  empty and Yoast renders from postmeta/options directly — which is why frontend acceptance
  passed without indexable rows. The write abilities still trigger Yoast's normal indexable
  rebuild (posts at request shutdown; terms via a forced `Indexable_Term_Watcher` rebuild,
  since the option write does not fire `edited_term`).
- **`yoast/update-settings` validation:** values are saved through Yoast's
  `sanitize_option_*` filter. A value Yoast rejects comes back in `applied` showing the
  value actually stored (which may be the previous/default), so the caller can detect a
  no-op. Truly unknown keys are returned in `rejected`.
