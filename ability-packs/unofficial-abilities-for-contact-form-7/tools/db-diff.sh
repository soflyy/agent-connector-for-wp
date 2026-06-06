#!/usr/bin/env bash
#
# db-diff.sh — see exactly what a plugin action changed in the database.
#
# The instrument that turns "did I find something new?" into an objective answer.
# Snapshot before an action, snapshot after, diff — the delta is the set of stores
# that action touches (catching option keys / meta / tables your code-read missed).
#
#   db-diff.sh snap <label> [table ...]   # capture a normalized snapshot
#   db-diff.sh diff <labelA> <labelB>     # show rows that changed between snapshots
#   db-diff.sh tables                     # list tables (to pick a focused set)
#
# With no table list, snapshots every table. That's complete but noisy (timestamps,
# sessions). Once you know the plugin's tables, pass them explicitly for clean signal,
# e.g.:  db-diff.sh snap before wp_options wp_postmeta wp_myplugin_items
#
# Snapshots live in $ACFW_DIFF_DIR (default /tmp/acfw-dbdiff).

set -euo pipefail

DIR="${ACFW_DIFF_DIR:-/tmp/acfw-dbdiff}"
mkdir -p "$DIR"

cmd="${1:-}"; shift || true

case "$cmd" in
	tables)
		wp db tables
		;;
	snap)
		label="${1:?usage: db-diff.sh snap <label> [table ...]}"; shift || true
		out="$DIR/$label.tsv"
		: > "$out"
		if [ "$#" -gt 0 ]; then
			tables=("$@")
		else
			mapfile -t tables < <(wp db tables)
		fi
		for t in "${tables[@]}"; do
			# One row per line, table-name-prefixed, so the corpus is stable & diffable.
			wp db query "SELECT * FROM \`$t\`" --skip-column-names --batch 2>/dev/null \
				| sed "s/^/${t}\t/" >> "$out" || true
		done
		LC_ALL=C sort -o "$out" "$out"
		echo "$out"
		;;
	diff)
		a="$DIR/${1:?usage: db-diff.sh diff <A> <B>}.tsv"
		b="$DIR/${2:?usage: db-diff.sh diff <A> <B>}.tsv"
		# '<' lines = only in A (removed/old), '>' lines = only in B (added/new).
		diff <(LC_ALL=C sort "$a") <(LC_ALL=C sort "$b") || true
		;;
	*)
		echo "usage: db-diff.sh {snap <label> [table ...] | diff <A> <B> | tables}" >&2
		exit 2
		;;
esac
