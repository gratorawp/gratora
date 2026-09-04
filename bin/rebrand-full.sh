#!/usr/bin/env bash
# FULL core rebrand: text domain, display name, namespace, constants, main file,
# REST namespaces, db/option prefixes, page slug, Strauss prefixes.
#
# PROTECTED (external, live in other repos - must survive verbatim):
#   @fundkit/ui        npm dep on fundkitorg/ui
#   fundkit/queryable  composer dep on fundkitorg/queryable
#   fundkitorg         the GitHub org
#
# Does NOT touch the nine add-on plugins. They call fundkit/v1 and import
# FundKit\ classes, so they break until renamed too.
#
#   bin/rebrand-full.sh onelo Onelo "Onelo Donation Platform"
#   bin/rebrand-full.sh onelo Onelo "Onelo Donation Platform" --apply
set -uo pipefail
SLUG="${1:-}"; BRAND="${2:-}"; TITLE="${3:-}"; APPLY="${4:-}"
[ -z "$SLUG" ] || [ -z "$BRAND" ] || [ -z "$TITLE" ] && { sed -n '2,16p' "$0" | sed 's/^#\{1,\} \{0,1\}//'; exit 1; }
printf '%s' "$SLUG" | grep -Eq '^[a-z0-9-]+$' || { echo "slug must be lowercase a-z0-9-"; exit 1; }
[ ${#SLUG} -ge 5 ] || { echo "slug must be >= 5 chars"; exit 1; }
# SLUG is the directory and text-domain form; it may contain hyphens.
# TOKEN is the bare lowercase form used for PHP constants, REST namespaces,
# option/hook prefixes and the admin page slug, so it must stay alphanumeric.
TOKEN=$(printf '%s' "$BRAND" | tr '[:upper:]' '[:lower:]')
printf '%s' "$TOKEN" | grep -Eq '^[a-z0-9]+$' || { echo "brand must be alphanumeric (it becomes a PHP constant prefix)"; exit 1; }
UPPER=$(printf '%s' "$TOKEN" | tr '[:lower:]' '[:upper:]')

cd "$(dirname "$0")/.." || exit 1
if [ "$APPLY" = "--apply" ]; then DRY=0; echo "== APPLYING FULL REBRAND =="; else DRY=1; echo "== DRY RUN (pass --apply) =="; fi
echo "   fundraising-toolkit -> $SLUG      (text domain)"
echo "   Fundraising Toolkit -> $BRAND     (display brand)"
echo "   FundKit / fundkit / FUNDKIT -> $BRAND / $TOKEN / $UPPER"
echo "   plugin title -> $TITLE"
echo

EX="--exclude-dir=node_modules --exclude-dir=vendor --exclude-dir=build --exclude-dir=dist --exclude-dir=.git --exclude-dir=.cache --exclude-dir=graphify-out --exclude-dir=.phpunit.cache --exclude-dir=.idea --exclude=rebrand-full.sh --exclude=rename-brand.sh"
hits() { grep -rlF $EX "$1" . 2>/dev/null; }
n()    { hits "$1" | wc -l | tr -d ' '; }

echo "will change:"
for t in "fundraising-toolkit" "Fundraising Toolkit" "FundKit" "fundkit" "FUNDKIT"; do
  printf "   %-22s %s files\n" "$t" "$(n "$t")"; done
echo "will be protected:"
for t in "@fundkit/ui" "fundkit/queryable" "fundkitorg"; do
  printf "   %-22s %s files\n" "$t" "$(n "$t")"; done

[ $DRY -eq 1 ] && { echo; echo "(nothing written)"; exit 0; }

sub() { # sub <find> <replace> - values pass via env so nothing is
        # interpolated into the regex; slashes in the operands are safe.
  REBRAND_FIND="$1" REBRAND_REPL="$2" \
    hits "$1" | tr '\n' '\0' | \
    REBRAND_FIND="$1" REBRAND_REPL="$2" xargs -0 -r \
      perl -pi -e 's/\Q$ENV{REBRAND_FIND}\E/$ENV{REBRAND_REPL}/g'
}

# 1. protect the external refs
sub '@fundkit/ui'       '@@KEEP_UIPKG@@'
sub 'fundkit/queryable' '@@KEEP_QBPKG@@'
sub 'fundkitorg'        '@@KEEP_ORG@@'

# 2. brand + domain
sub 'fundraising-toolkit' "$SLUG"
sub 'Fundraising Toolkit' "$BRAND"

# 3. everything remaining, three cases
sub 'FundKit' "$BRAND"
sub 'FUNDKIT' "$UPPER"
sub 'fundkit' "$TOKEN"

# 4. restore
sub '@@KEEP_UIPKG@@' '@fundkit/ui'
sub '@@KEEP_QBPKG@@' 'fundkit/queryable'
sub '@@KEEP_ORG@@'   'fundkitorg'

# 5. main file + headers + readme + pot
[ -f "${SLUG}.php" ] || { git mv fundkit.php "${SLUG}.php" 2>/dev/null || mv fundkit.php "${SLUG}.php"; }
perl -pi -e "s|^ \* Plugin Name: .*| * Plugin Name: ${TITLE}|" "${SLUG}.php"
perl -pi -e "s|^ \* Text Domain: .*| * Text Domain: ${SLUG}|"  "${SLUG}.php"
perl -pi -e "BEGIN{\$d=0} if(!\$d && /^=== .* ===$/){\$_=\"=== ${TITLE} ===\n\";\$d=1}" readme.txt
if [ -f "languages/fundraising-toolkit.pot" ]; then
  git mv "languages/fundraising-toolkit.pot" "languages/${SLUG}.pot" 2>/dev/null || mv "languages/fundraising-toolkit.pot" "languages/${SLUG}.pot"
fi

echo
echo "guards (must all be intact):"
printf "   @fundkit/ui        %s files\n" "$(n '@fundkit/ui')"
printf "   fundkit/queryable  %s files\n" "$(n 'fundkit/queryable')"
printf "   fundkitorg         %s files\n" "$(n 'fundkitorg')"
printf "   leftover sentinels %s files (must be 0)\n" "$(n '@@KEEP_')"
echo
echo "next: composer dump-autoload && npm run i18n && npm run build"
echo "      composer test && npm run test:js"
