#!/usr/bin/env bash
# Run the workflow's actual manifest logic in an isolated, offline fixture repo.
set -euo pipefail
repo_dir=$(cd "$(dirname "$0")/../.." && pwd)
fixture_dir=$(mktemp -d)
trap 'rm -rf "$fixture_dir"' EXIT

awk '
  /^      - name: Regenerate index.json manifest/ { inside=1; next }
  inside && /^          # Publish/ { exit }
  inside && /^          / { print substr($0, 11) }
' "$repo_dir/.github/workflows/ability-packs-release.yml" > "$fixture_dir/manifest.sh"
test -s "$fixture_dir/manifest.sh"
cd "$fixture_dir"
mkdir -p ability-packs/released-pack ability-packs/unreleased-pack
cp "$repo_dir/ability-packs/external.json" ability-packs/external.json
for slug in released-pack unreleased-pack; do
  cat > "ability-packs/$slug/$slug.php" <<'PHP'
<?php
/**
 * Plugin Name: Fixture Pack
 * Description: Fixture abilities.
 * Agent Connector Target: woocommerce
 */
PHP
done
git init -q
export GIT_AUTHOR_NAME='Joe Guilmette' GIT_AUTHOR_EMAIL='joe@soflyy.com'
export GIT_COMMITTER_NAME='Joe Guilmette' GIT_COMMITTER_EMAIL='joe@soflyy.com'
git -c user.name='Joe Guilmette' -c user.email='joe@soflyy.com' -c commit.gpgsign=false commit -q --allow-empty -m 'test(directory): prepare local manifest fixture'
git tag released-pack-v1.0.0
git tag released-pack-v1.2.0
GITHUB_REPOSITORY='fixture/local' bash manifest.sh > generation.log
jq -e --slurpfile external ability-packs/external.json '
  (.generated_at | test("^[0-9]{4}-.*Z$")) and
  (.entries | length == 3) and
  (.entries[0] | .ability_pack_slug == "released-pack" and .version == "1.2.0" and
    .target_plugin == "woocommerce" and .name == "Fixture Pack" and
    .download_url == "https://github.com/fixture/local/releases/download/released-pack-v1.2.0/released-pack.zip") and
  (.entries[1:] == $external[0]) and
  (all(.entries[]; .ability_pack_slug != "unreleased-pack")) and
  (all(.entries[1:][]; has("version") | not))
' index.json > /dev/null
grep -q 'no release yet for unreleased-pack' generation.log

# A local clone may have no release tags at all; external entries still publish.
git tag -d released-pack-v1.0.0 released-pack-v1.2.0 > /dev/null
GITHUB_REPOSITORY='fixture/local' bash manifest.sh > generation.log
jq -e --slurpfile external ability-packs/external.json '.entries == $external[0]' index.json > /dev/null
echo 'Manifest regression tests: latest release, omission, external merge, absent versions and zero release tags passed.'
