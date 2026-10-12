#!/usr/bin/env bash
#
# Build the Agent Connector plugin zip.
#
#   github — the GitHub release asset: everything, including legacy/ (the
#            GitHub self-updater + compatibility for older installs).
#   org    — the wordpress.org build: leaves out what plugin/.distignore lists
#            and drops the GitHub update checker dependency.
#
# Usage:
#   bin/build-plugin-zip.sh <github|org> [OUT_DIR]
#
# Produces OUT_DIR/<flavor>/agent-connector-for-wp/ (the staged plugin) and
# OUT_DIR/<flavor>/agent-connector-for-wp.zip. OUT_DIR defaults to dist/.
#
set -euo pipefail

FLAVOR="${1:-}"
case "$FLAVOR" in
	github|org) ;;
	*) echo "Usage: bin/build-plugin-zip.sh <github|org> [OUT_DIR]" >&2; exit 1 ;;
esac

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT_DIR="$(mkdir -p "${2:-$REPO_ROOT/dist}" && cd "${2:-$REPO_ROOT/dist}" && pwd)"
SLUG="agent-connector-for-wp"
STAGE="$OUT_DIR/$FLAVOR/$SLUG"

rm -rf "${OUT_DIR:?}/$FLAVOR"
mkdir -p "$STAGE"
( cd "$REPO_ROOT/plugin" && tar cf - --exclude='./dist' --exclude='./vendor' --exclude='node_modules' . ) | ( cd "$STAGE" && tar xf - )

cd "$STAGE"
if [ "$FLAVOR" = "org" ]; then
	while IFS= read -r pattern || [ -n "$pattern" ]; do
		case "$pattern" in ''|'#'*) continue ;; esac
		rm -rf "./${pattern#/}"
	done < "$REPO_ROOT/plugin/.distignore"
	composer remove yahnis-elsts/plugin-update-checker --update-no-dev --no-interaction --no-progress --quiet
	composer dump-autoload --optimize --no-dev --no-interaction --quiet
else
	composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress --quiet
fi

cd "$OUT_DIR/$FLAVOR"
rm -f "$SLUG.zip"
zip -rq "$SLUG.zip" "$SLUG"
echo "$OUT_DIR/$FLAVOR/$SLUG.zip"
