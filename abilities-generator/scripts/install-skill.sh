#!/usr/bin/env bash
#
# install-skill.sh — make the wp-mcp-generator skill available to Claude Code in this
# environment by symlinking it into the skills directory. Because it's a symlink, edits
# you make to the skill in this repo take effect immediately (improve it, then commit).
#
#   ./scripts/install-skill.sh
#
# Override the target dir with CLAUDE_SKILLS_DIR if your install differs.

set -euo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SKILLS_DIR="${CLAUDE_SKILLS_DIR:-$HOME/.claude/skills}"

src="$REPO_DIR/skills/wp-mcp-generator"
dst="$SKILLS_DIR/wp-mcp-generator"

if [ ! -d "$src" ]; then
	echo "Skill source not found: $src" >&2
	exit 1
fi

mkdir -p "$SKILLS_DIR"

if [ -e "$dst" ] || [ -L "$dst" ]; then
	echo "Replacing existing $dst"
	rm -rf "$dst"
fi

ln -s "$src" "$dst"
echo "Linked $dst -> $src"
echo "The wp-mcp-generator skill is now available to Claude Code."
