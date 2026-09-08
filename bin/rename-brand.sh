#!/usr/bin/env bash
# Rename public branding while preserving internal identifiers and external dependencies.
# Usage: bin/rename-brand.sh slug "Display Name" Author [--apply]
set -uo pipefail

NEW_SLUG="${1:-}"; NEW_TITLE="${2:-}"; NEW_BRAND="${3:-}"; APPLY="${4:-}"
OLD_SLUG="onelo"
OLD_BRAND="Onelo"

usage() { sed -n '2,12p' "$0" | sed 's/^#\{1,\} \{0,1\}//'; exit 1; }
if [ -z "$NEW_SLUG" ] || [ -z "$NEW_TITLE" ] || [ -z "$NEW_BRAND" ]; then usage; fi
if ! printf '%s' "$NEW_SLUG" | grep -Eq '^[a-z0-9-]+$'; then
  echo "error: slug must be lowercase a-z 0-9 and hyphens"; exit 1; fi
if [ ${#NEW_SLUG} -lt 5 ]; then
  echo "error: slug must be >= 5 chars (wp.org refused a 4-char name)"; exit 1; fi

cd "$(dirname "$0")/.." || exit 1
if [ "$APPLY" = "--apply" ]; then DRY=0; echo "== APPLYING =="; else DRY=1; echo "== DRY RUN (pass --apply to write) =="; fi
echo "   text domain : $OLD_SLUG  ->  $NEW_SLUG"
echo "   brand name  : $OLD_BRAND  ->  $NEW_BRAND"
echo "   plugin title: $NEW_TITLE"
echo

EX='--exclude-dir=node_modules --exclude-dir=vendor --exclude-dir=build --exclude-dir=dist --exclude-dir=.git --exclude-dir=.cache --exclude-dir=graphify-out --exclude-dir=.phpunit.cache --exclude-dir=.idea --exclude=*.pot'
files() { grep -rl --binary-files=without-match $EX "$1" . 2>/dev/null; }
countfiles() { files "$1" | wc -l | tr -d ' '; }

ns_before=$(countfiles "FundKit")
slug_before=$(grep -c "ADMIN_SLUG = 'fundkit'" assets/admin/_shared/adminPages.js 2>/dev/null || echo 0)

echo "text domain '$OLD_SLUG' : $(countfiles "$OLD_SLUG") files"
echo "brand name  '$OLD_BRAND' : $(countfiles "$OLD_BRAND") files"

if [ $DRY -eq 1 ]; then echo; echo "(nothing written)"; exit 0; fi

files "$OLD_SLUG"  | tr '\n' '\0' | xargs -0 sed -i '' "s/${OLD_SLUG}/${NEW_SLUG}/g"
files "$OLD_BRAND" | tr '\n' '\0' | xargs -0 sed -i '' "s/${OLD_BRAND}/${NEW_BRAND}/g"

sed -i '' "s|^ \* Plugin Name: .*| * Plugin Name: ${NEW_TITLE}|" fundkit.php
sed -i '' "s|^ \* Text Domain: .*| * Text Domain: ${NEW_SLUG}|" fundkit.php
sed -i '' "1s|^=== .* ===$|=== ${NEW_TITLE} ===|" readme.txt

if [ -f "languages/${OLD_SLUG}.pot" ]; then
  git mv "languages/${OLD_SLUG}.pot" "languages/${NEW_SLUG}.pot" 2>/dev/null \
    || mv "languages/${OLD_SLUG}.pot" "languages/${NEW_SLUG}.pot"
fi

ns_after=$(countfiles "FundKit")
slug_after=$(grep -c "ADMIN_SLUG = 'fundkit'" assets/admin/_shared/adminPages.js 2>/dev/null || echo 0)

echo
if [ "$ns_before" = "$ns_after" ]; then s1=OK; else s1="CHANGED - INVESTIGATE"; fi
if [ "$slug_before" = "$slug_after" ]; then s2=OK; else s2="CHANGED - INVESTIGATE"; fi
echo "guard  FundKit namespace files : $ns_before -> $ns_after  [$s1]"
echo "guard  ADMIN_SLUG page slug    : $slug_before -> $slug_after  [$s2]"
echo
echo "then run: npm run i18n && npm run build && composer test && npm run test:js"
echo "by hand : fundkit.php Author line, readme.txt Contributors and Tags"
