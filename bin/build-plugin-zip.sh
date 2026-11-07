#!/usr/bin/env bash
#
# Build the Agent Connector plugin zip. Two builds, one version:
#
#   github — the GitHub Release asset. The full plugin, including
#            github-updater.php and the yahnis-elsts/plugin-update-checker
#            dependency, so GitHub installs keep updating from GitHub Releases.
#   org    — the wordpress.org build. Identical, except github-updater.php, the
#            block in the main file that loads it (between the @github-build
#            markers), and the update-checker dependency are removed. That is the ONLY
#            difference between the two.
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
OUT_DIR="${2:-$REPO_ROOT/dist}"
mkdir -p "$OUT_DIR"
OUT_DIR="$(cd "$OUT_DIR" && pwd)"
SLUG="agent-connector-for-wp"
STAGE="$OUT_DIR/$FLAVOR/$SLUG"

rm -rf "${OUT_DIR:?}/$FLAVOR"
mkdir -p "$STAGE"
( cd "$REPO_ROOT/plugin" && tar cf - --exclude='./dist' --exclude='./vendor' --exclude='node_modules' . ) | ( cd "$STAGE" && tar xf - )

cd "$STAGE"
if [ "$FLAVOR" = "org" ]; then
	rm -f github-updater.php
	# Cut the block that loads it (between the @github-build markers) so the
	# wordpress.org copy carries no trace of the GitHub updater.
	MAIN="$SLUG.php"
	if [ "$(grep -c '^// @github-build-start$' "$MAIN")" != 1 ] || [ "$(grep -c '^// @github-build-end$' "$MAIN")" != 1 ]; then
		echo "Expected exactly one @github-build-start/@github-build-end pair in $MAIN" >&2
		exit 1
	fi
	awk '/^\/\/ @github-build-start$/{skip=1; next} /^\/\/ @github-build-end$/{skip=0; next} !skip' "$MAIN" > "$MAIN.tmp"
	mv "$MAIN.tmp" "$MAIN"
	composer remove yahnis-elsts/plugin-update-checker --update-no-dev --no-interaction --no-progress --quiet
	composer dump-autoload --optimize --no-dev --no-interaction --quiet
else
	composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress --quiet
fi

cd "$OUT_DIR/$FLAVOR"
rm -f "$SLUG.zip"
zip -rq "$SLUG.zip" "$SLUG"
echo "$OUT_DIR/$FLAVOR/$SLUG.zip"
