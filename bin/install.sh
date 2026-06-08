#!/usr/bin/env bash
#
# Symlink the plugin into a WordPress install for local development.
#
# This repo is a monorepo: the plugin lives in plugin/. WordPress expects the
# plugin under
# wp-content/plugins/, so for local development we symlink plugin/ there instead
# of copying it. Edit files in the repo; WordPress sees them live through the link.
#
# Usage:
#   bin/install.sh <WP_CONTENT_DIR>
#
# Example:
#   bin/install.sh /var/www/html/wp-content
#
set -euo pipefail

PLUGIN_SLUG="agent-connector-for-wp"

# Resolve the repo root from this script's location (bin/ -> repo root).
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
PLUGIN_SRC="${REPO_ROOT}/plugin"

usage() {
	echo "Usage: bin/install.sh <WP_CONTENT_DIR>" >&2
	echo "  e.g. bin/install.sh /var/www/html/wp-content" >&2
}

if [ "$#" -ne 1 ]; then
	echo "Error: exactly one argument (the path to wp-content) is required." >&2
	usage
	exit 1
fi

WP_CONTENT_DIR="$1"

if [ ! -d "${WP_CONTENT_DIR}" ]; then
	echo "Error: '${WP_CONTENT_DIR}' is not a directory." >&2
	exit 1
fi

if [ ! -d "${WP_CONTENT_DIR}/plugins" ]; then
	echo "Error: '${WP_CONTENT_DIR}/plugins' does not exist — is this really a wp-content directory?" >&2
	exit 1
fi

if [ ! -f "${PLUGIN_SRC}/${PLUGIN_SLUG}.php" ]; then
	echo "Error: plugin source not found at ${PLUGIN_SRC}/${PLUGIN_SLUG}.php" >&2
	exit 1
fi

LINK_PATH="${WP_CONTENT_DIR}/plugins/${PLUGIN_SLUG}"

# If something is already there, only replace a symlink (never a real directory).
if [ -L "${LINK_PATH}" ]; then
	rm "${LINK_PATH}"
elif [ -e "${LINK_PATH}" ]; then
	echo "Error: ${LINK_PATH} already exists and is not a symlink. Refusing to overwrite." >&2
	echo "Move or remove it, then re-run." >&2
	exit 1
fi

ln -s "${PLUGIN_SRC}" "${LINK_PATH}"
echo "Linked ${LINK_PATH} -> ${PLUGIN_SRC}"

if [ ! -d "${PLUGIN_SRC}/vendor" ]; then
	echo
	echo "Note: ${PLUGIN_SRC}/vendor is missing. Install dependencies with:"
	echo "  ( cd ${PLUGIN_SRC} && composer install --no-dev )"
fi

echo
echo "Next: activate the plugin, e.g."
echo "  wp plugin activate ${PLUGIN_SLUG}"
