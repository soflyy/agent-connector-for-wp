#!/usr/bin/env bash
#
# save-pack.sh — copy a generated ability pack out of the live WordPress install and
# into the monorepo's top-level ability-packs/ directory, so it can be committed.
#
#   ./scripts/save-pack.sh <target-slug>      # e.g. contact-form-7
#
# Looks for wp/wp-content/plugins/unofficial-abilities-for-<slug>.
# Override the plugins dir with WP_PLUGINS_DIR if your install differs.
#
# Generated packs live in the monorepo root's ability-packs/ (a sibling of this
# abilities-generator/ subproject), not inside the generator itself.

set -euo pipefail

slug="${1:-}"
if [ -z "$slug" ]; then
	echo "usage: save-pack.sh <target-slug>   (e.g. contact-form-7)" >&2
	exit 2
fi

# This script lives in abilities-generator/scripts/, so two levels up is the
# monorepo root, where the shared ability-packs/ directory lives.
GENERATOR_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MONOREPO_ROOT="$(cd "${GENERATOR_DIR}/.." && pwd)"
PLUGINS_DIR="${WP_PLUGINS_DIR:-$HOME/wp/wp-content/plugins}"
name="unofficial-abilities-for-${slug}"
src="$PLUGINS_DIR/$name"
dst="$MONOREPO_ROOT/ability-packs/$name"

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
echo "Review and commit it under ability-packs/."
