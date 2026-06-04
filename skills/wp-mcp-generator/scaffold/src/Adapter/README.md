# `src/` — adapter & reimplemented logic

Everything an ability's `execute_callback` needs beyond a one-liner lives here. The
main plugin file `require_once`s every `src/**/*.php` at load.

This is where the **agent-ergonomics-over-code-reuse** principle gets paid for. The
plugin you're targeting may only expose coarse operations (e.g. "save the entire
document/structure"). Your abilities expose fine-grained verbs. The glue in between —
reading current state through the plugin's own loader, applying just the agent's delta,
and persisting through the plugin's own save path so its validation and hooks still run —
is the code you write here.

Keep it organized by concern (one class/file per data structure or document type). Keep
the *persistence* routed through the plugin wherever a save path exists; only reach for
raw `$wpdb` when there genuinely isn't one. Prove anything you reimplement by round-tripping
it through the real plugin (load your result back into its UI and confirm it renders).

There is no prescribed structure here on purpose — derive it from how the target plugin
actually loads and saves its data.
