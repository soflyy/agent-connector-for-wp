#!/usr/bin/env bash
#
# save-pack.sh — copy a generated ability pack out of the live WordPress install and
# into this repo's packs/ directory, so it can be committed.
#
#   ./scripts/save-pack.sh <target-slug>      # e.g. contact-form-7
#
# Looks for wp/wp-content/plugins/agent-connector-for-wp-ability-pack-<slug>.
# Override the plugins dir with WP_PLUGINS_DIR if your install differs.

set -euo pipefail

slug="${1:-}"
if [ -z "$slug" ]; then
	echo "usage: save-pack.sh <target-slug>   (e.g. contact-form-7)" >&2
	exit 2
fi

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGINS_DIR="${WP_PLUGINS_DIR:-$HOME/wp/wp-content/plugins}"
name="agent-connector-for-wp-ability-pack-${slug}"
src="$PLUGINS_DIR/$name"
dst="$REPO_DIR/packs/$name"

if [ ! -d "$src" ]; then
	echo "Pack not found: $src" >&2
	echo "(Is the slug right? Has the pack been generated yet?)" >&2
	exit 1
fi

rm -rf "$dst"
mkdir -p "$dst"
# Copy contents, skipping anything that shouldn't be versioned.
( cd "$src" && find . \
	-name node_modules -prune -o \
	-name vendor -prune -o \
	-name '.git' -prune -o \
	-type f -print ) | while IFS= read -r f; do
	mkdir -p "$dst/$(dirname "$f")"
	cp "$src/$f" "$dst/$f"
done

echo "Saved pack -> $dst"
echo "Review and commit it under packs/."
