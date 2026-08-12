#!/usr/bin/env bash
#
# Extract dist/dono.zip and refuse it if it would not install and run.
#
# The extraction is not incidental: deploy.yml publishes dist/dono, so this is
# what produces the tree that ships, and the thing verified and the thing
# uploaded are the same bytes.
#
# Only checks bin/package.mjs cannot make. The packager already refuses to
# build with development dependencies present or the prefixed vendor missing;
# what it cannot tell you is whether the packaged tree actually loads.
#
# Runs anywhere: `bash bin/verify-zip.sh` after `npm run package` says the same
# thing locally that it says on a runner. The ::error:: prefixes are GitHub
# annotations and harmless plain text elsewhere.

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

ZIP=dist/dono.zip
OUT=dist/dono

fail() { echo "::error::$1" >&2; exit 1; }

test -f "$ZIP" || fail "no zip was produced"

rm -rf "$OUT"
unzip -q "$ZIP" -d dist

# Without any one of these the plugin fatals on activation, behind a blank
# admin screen that says nothing about why.
for f in dono.php vendor/autoload.php vendor/woocommerce/action-scheduler/action-scheduler.php build; do
    test -e "$OUT/$f" || fail "$f missing from the zip; the plugin would fatal on activation"
done

# .distignore is the only thing keeping these out: they are gitignored, so they
# are invisible to git and present on disk, which is how a customer zip came to
# carry 19 MB of generated notes about our own source.
for leak in tests tests-e2e node_modules .git .npmrc graphify-out qa \
    phpunit.xml.dist phpunit-integration.xml.dist .wordpress-org
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
      "Dono\\Vendor\\Dompdf\\Dompdf",
      "Dono\\Foundation\\Plugin",
      "Dono\\Receipts\\PdfBuilder",
    ];
    foreach ($need as $c) {
      if (! class_exists($c)) { fwrite(STDERR, "::error::$c does not resolve from the packaged tree\n"); exit(1); }
    }
    echo "runtime classes resolve\n";
'

# Receipts name DejaVu because the core PDF fonts carry no Cyrillic or Greek,
# and a donor whose name needs those gets question marks on a tax document.
test -f "$OUT/vendor/vendor-prefixed/dompdf/dompdf/lib/fonts/DejaVuSans.ttf" \
    || fail "DejaVu is missing, so non-Latin donor names would not render"

echo "zip verified"
