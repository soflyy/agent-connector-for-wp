#!/usr/bin/env bash
#
# Symlink the plugins into a WordPress install for local development.
#
# This repo is a monorepo: the main plugin lives in plugin/ and its Default
# Abilities companion in universal-abilities-plugin/. WordPress expects each plugin
# under wp-content/plugins/, so for local development we symlink them there
# instead of copying. Edit files in the repo; WordPress sees them live.
#
# Usage:
#   bin/install.sh <WP_CONTENT_DIR>
#
# Example:
#   bin/install.sh /var/www/html/wp-content
#
set -euo pipefail

# Resolve the repo root from this script's location (bin/ -> repo root).
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

# Each entry is "<source-subdir>:<plugin-slug>". The main file is expected at
# <source-subdir>/<plugin-slug>.php.
PLUGINS=(
	"plugin:agent-connector-for-wp"
	"universal-abilities-plugin:universal-abilities-plugin"
)

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

for entry in "${PLUGINS[@]}"; do
	SRC_SUBDIR="${entry%%:*}"
	PLUGIN_SLUG="${entry##*:}"
	PLUGIN_SRC="${REPO_ROOT}/${SRC_SUBDIR}"

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
done

MAIN_SRC="${REPO_ROOT}/plugin"
if [ ! -d "${MAIN_SRC}/vendor" ]; then
	echo
	echo "Note: ${MAIN_SRC}/vendor is missing. Install dependencies with:"
	echo "  ( cd ${MAIN_SRC} && composer install --no-dev )"
fi

echo
echo "Next: activate the plugins, e.g."
echo "  wp plugin activate agent-connector-for-wp universal-abilities-plugin"
