#!/usr/bin/env bash
#
# Build the Agent Connector plugin zip. Two builds, one version:
#
#   github — the GitHub Release asset: the full plugin, including the GitHub
#            self-updater (github-updater.php + yahnis-elsts/plugin-update-checker)
#            and the GitHub-only abilities under src/GitHub/.
#   org    — the wordpress.org build. The same plugin without what wordpress.org
#            doesn't allow:
#              - github-updater.php and the update-checker dependency;
#              - src/GitHub/: the shell, process, WP-CLI and PHP-eval
#                abilities;
#              - every PHP block between "// @github-build-start" and
#                "// @github-build-end" markers (the code that wires those in).
#            The build fails if anything GitHub-only is still referenced.
#
# Usage:
#   bin/build-plugin-zip.sh <github|org> [OUT_DIR]
#
# Produces OUT_DIR/<flavor>/<slug>/ (the staged plugin) and
# OUT_DIR/<flavor>/<slug>.zip. OUT_DIR defaults to dist/.
#
# The slug (the plugin's folder) differs by build:
#   github — agent-connector-for-wp, so existing installs and the self-updater
#            keep working;
#   org    — agent-connector, the wordpress.org slug (wordpress.org doesn't
#            allow "wp" in slugs). It matches the plugin's Text Domain.
#
# Neither build ships what's only for developers: plugin/README.md and
# plugin/docs/ (sites get readme.txt instead), or composer.json and
# composer.lock, which are removed once vendor/ is built.
#
set -euo pipefail

FLAVOR="${1:-}"
case "$FLAVOR" in
	github|org) ;;
	*) echo "Usage: bin/build-plugin-zip.sh <github|org> [OUT_DIR]" >&2; exit 1 ;;
esac

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT_DIR="${2:-$REPO_ROOT/dist}"
mkdir -p "$OUT_DIR"
OUT_DIR="$(cd "$OUT_DIR" && pwd)"
if [ "$FLAVOR" = "org" ]; then
	SLUG="agent-connector"
else
	SLUG="agent-connector-for-wp"
fi
STAGE="$OUT_DIR/$FLAVOR/$SLUG"

rm -rf "${OUT_DIR:?}/$FLAVOR"
mkdir -p "$STAGE"
( cd "$REPO_ROOT/plugin" && tar cf - --exclude='./dist' --exclude='./vendor' --exclude='./README.md' --exclude='./docs' --exclude='node_modules' . ) | ( cd "$STAGE" && tar xf - )

cd "$STAGE"
if [ "$FLAVOR" = "org" ]; then
	# 1. GitHub-only files: the self-updater and everything under src/GitHub/
	#    (the shell, process, WP-CLI and PHP-eval abilities).
	rm -f github-updater.php
	rm -rf src/GitHub

	# 2. GitHub-only blocks: every "// @github-build-start" … "// @github-build-end"
	#    span in a PHP file (markers may be indented). Each file must have
	#    balanced, non-nested markers.
	while IFS= read -r -d '' file; do
		grep -q '@github-build-' "$file" || continue
		if ! awk '
			/^[[:space:]]*\/\/ @github-build-start[[:space:]]*$/ { if (open) exit 1; open = 1; next }
			/^[[:space:]]*\/\/ @github-build-end[[:space:]]*$/   { if (!open) exit 1; open = 0; next }
			/@github-build-/ { exit 1 }
			END { if (open) exit 1 }
		' "$file"; then
			echo "Unbalanced or malformed @github-build markers in ${file#./}" >&2
			exit 1
		fi
		awk '
			/^[[:space:]]*\/\/ @github-build-start[[:space:]]*$/ { skip = 1; next }
			/^[[:space:]]*\/\/ @github-build-end[[:space:]]*$/   { skip = 0; next }
			!skip
		' "$file" > "$file.tmp"
		mv "$file.tmp" "$file"
	done < <(find . -name '*.php' -not -path './vendor/*' -not -path './admin/node_modules/*' -print0)

	# 3. The update checker dependency.
	composer remove yahnis-elsts/plugin-update-checker --update-no-dev --no-interaction --no-progress --quiet
	composer dump-autoload --optimize --no-dev --no-interaction --quiet

	# 4. Fail the build if anything GitHub-only is still referenced.
	leftovers="$(grep -rnE 'GitHub\\|github-updater|YahnisElsts|\b(shell_exec|proc_open|popen|passthru|eval)[[:space:]]*\(' --include='*.php' --exclude-dir=vendor --exclude-dir=node_modules . || true)"
	if [ -n "$leftovers" ]; then
		echo "GitHub-only code left in the wordpress.org build:" >&2
		echo "$leftovers" >&2
		exit 1
	fi
else
	composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress --quiet
fi

# vendor/ is built, so the Composer files have done their job.
rm -f composer.json composer.lock

cd "$OUT_DIR/$FLAVOR"
rm -f "$SLUG.zip"
zip -rq "$SLUG.zip" "$SLUG"
echo "$OUT_DIR/$FLAVOR/$SLUG.zip"
