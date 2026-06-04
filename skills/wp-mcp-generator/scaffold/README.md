# Ability-pack scaffold

A ready-to-fill companion **ability pack** for Agent Connector for WP. Copy this whole
folder to `wp/wp-content/plugins/agent-connector-for-wp-ability-pack-<target>/`, then fill
it in following the `wp-mcp-generator` skill.

You write **no** auth/permission/domain-lock/audit code — Agent Connector injects all of it
for every ability registered here.

## Layout

```
agent-connector-for-wp-ability-pack-TARGET.php   main file — headers + bootstrap (RENAME + fill {{PLACEHOLDERS}})
bootstrap.php                                    ACFW_Pack registry (register categories + abilities). Don't edit.
abilities/                                        one file per group of abilities; calls ACFW_Pack::ability([...])
  example-abilities.php                           DELETE — shows an action-verb + an introspection ability
src/                                              adapter / reimplemented logic (load-modify-save helpers, schema)
  Adapter/README.md                               what goes here and why
coverage/
  inventory.schema.json                           shape of inventory.json (your structured understanding)
  inventory.json                                  CREATE — fill while reading code + exercising the plugin
  coverage-report.template.md                     copy to coverage-report.md — the coverage deliverable
tools/
  db-diff.sh                                       snapshot/diff the DB to see what each action changes
```

## Quick start

1. `cp -r` this folder into the plugins dir, rename the folder + main file to
   `agent-connector-for-wp-ability-pack-<target>`, replace every `{{PLACEHOLDER}}`.
2. Set `Agent Connector Target:` to the target plugin's main file (e.g. `woocommerce/woocommerce.php`).
3. Delete `abilities/example-abilities.php`; add real ability files.
4. `wp plugin activate agent-connector-for-wp-ability-pack-<target>`.
5. Verify they surfaced: `mcp-adapter-discover-abilities`, then call one with
   `mcp-adapter-execute-ability` — exactly as the agent will.

## Registration contract

Full arg reference (label, description, category, input_schema, output_schema,
execute_callback, annotations, summarize) lives in
`wp/wp-content/plugins/agent-connector-for-wp/docs/registering-abilities.md`.
Descriptions and input schemas are the product — write them precisely.
