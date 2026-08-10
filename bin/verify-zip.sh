#!/usr/bin/env bash
#
# Read dist/dono.zip and refuse it if it would not install and run.
#
# Lives in a script rather than inline in a workflow because two workflows
# build the same artefact, and a check that exists in only one of them is a
# check that stops running the moment releases move to the other.
#
# Runs anywhere: `bash bin/verify-zip.sh` after `npm run package` says the same
# thing locally that it says on a runner. The ::error:: prefixes are GitHub
# annotations and are harmless plain text elsewhere.
#
# Leaves the extracted tree at dist/dono, which the publish step uploads, so
# what was verified and what ships are the same bytes.

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

ZIP=dist/dono.zip
OUT=dist/dono

fail() { echo "::error::$1" >&2; exit 1; }

test -f "$ZIP" || fail "no zip was produced"

SIZE=$(du -m "$ZIP" | cut -f1)
echo "artefact: ${SIZE} MB"
# Was 56 MB before the vendor test suites and unused mpdf fonts came out. A
# jump back means something is shipping that should not.
test "$SIZE" -lt 20 || fail "zip is ${SIZE} MB; expected well under 20"

rm -rf "$OUT"
unzip -q "$ZIP" -d dist

# Without any one of these the plugin fatals on activation, behind a blank
# admin screen that says nothing about why.
for f in \
    dono.php \
    vendor/autoload.php \
    vendor/vendor-prefixed/autoload.php \
    vendor/woocommerce/action-scheduler/action-scheduler.php \
    build
do
    test -e "$OUT/$f" || fail "$f missing from the zip; the plugin would fatal on activation"
done

# graphify-out reached a customer zip once: it is gitignored, so git never sees
# it and .distignore is the only thing that can stop it.
for leak in \
    tests tests-e2e node_modules .git .github .claude \
    CLAUDE.md phpunit.xml.dist phpunit-integration.xml.dist \
    .npmrc graphify-out qa bin composer.json composer.lock
do
    test ! -e "$OUT/$leak" || fail "$leak is in the zip"
done

# Resolved the way dono.php resolves them, which is BOTH autoloaders and not
# just composer's. Strauss works by editing vendor/autoload.php to pull in the
# prefixed one, and `composer install --no-dev` removes Strauss and regenerates
# that file without the edit. Requiring only vendor/autoload.php therefore
# fails against every real release build while the plugin itself is fine.
php -r '
    require "dist/dono/vendor/autoload.php";
    require "dist/dono/vendor/vendor-prefixed/autoload.php";
    $need = [
      "Dono\\Vendor\\Queryable\\Model",
      "Dono\\Vendor\\Mpdf\\Mpdf",
      "Dono\\Foundation\\Plugin",
      "Dono\\Receipts\\PdfBuilder",
    ];
    foreach ($need as $c) {
      if (! class_exists($c)) { fwrite(STDERR, "::error::$c does not resolve from the packaged tree\n"); exit(1); }
    }
    echo "runtime classes resolve\n";
'

# Receipts pick their faces out of the pruned font set.
test -f "$OUT/vendor/vendor-prefixed/mpdf/mpdf/ttfonts/DejaVuSansCondensed.ttf" \
    || fail "the fallback font was pruned away"

echo "zip verified"
