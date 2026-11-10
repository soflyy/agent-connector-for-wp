# Offline regression tests

From `plugin/`, run:

```sh
composer install
composer test
composer lint
```

Requires PHP 8.1+, Bash, Git and jq. No WordPress installation or network is
needed. The PHP tests load the actual directory, updater and installer classes
with small WordPress boundary doubles; warnings become failures. The manifest
test extracts and runs the workflow's Bash/jq manifest step before publishing in
an isolated temporary Git repository, with released and unreleased pack fixtures.
The fixture commit is local and removed at the end.

To verify admin changes too, run `npm ci` and `npm run build` in `plugin/admin/` (the built
assets are tracked).
