#!/usr/bin/env bash
#
# Extract the built zip and refuse it if it would not install and run.
#
# The extraction is not incidental: deploy.yml publishes that same tree, so this is
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

# Same source of truth as the packager: the header, not the checkout's name.
SLUG=$(sed -n 's/^[[:space:]]*\*[[:space:]]*Text Domain:[[:space:]]*\([^[:space:]]*\).*/\1/p' dono.php | head -1)
test -n "$SLUG" || { echo "::error::no Text Domain in the plugin header" >&2; exit 1; }

ZIP="dist/$SLUG.zip"
OUT="dist/$SLUG"

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
# are invisible to git and present on disk, and nothing else looks at them
# before the packager copies the tree.
for leak in tests tests-e2e node_modules .git .npmrc graphify-out qa \
    phpunit.xml.dist phpunit-integration.xml.dist .wordpress-org languages/README.md
do
    test ! -e "$OUT/$leak" || fail "$leak is in the zip"
done

# Resolved the way dono.php resolves them, which is BOTH autoloaders and not
# just composer's. Strauss works by editing vendor/autoload.php to pull in the
# prefixed one, and `composer install --no-dev` removes Strauss and regenerates
# that file without the edit. Requiring only vendor/autoload.php therefore
# fails against every real release build while the plugin itself is fine.
OUT="$OUT" php -r '
    $out = getenv("OUT");
    require "$out/vendor/autoload.php";
    require "$out/vendor/vendor-prefixed/autoload.php";
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

# A vendor/ with no manifest is what Plugin Check flags, and the only thing in
# the payload that says where those packages came from.
test -f "$OUT/composer.json" || fail "composer.json is missing next to vendor/"

# An autoloader describing directories the zip does not carry still answers for
# their class names, and it answers first: composer registers with prepend, so
# the warnings land on sites whose other plugin owns the unprefixed namespace.
OUT="$OUT" php -r '
    $out = getenv("OUT");
    $orphans = 0;
    foreach (require "$out/vendor/composer/autoload_classmap.php" as $path) {
        if (! file_exists($path)) { $orphans++; }
    }
    foreach (require "$out/vendor/composer/autoload_psr4.php" as $dirs) {
        foreach ($dirs as $dir) { if (! is_dir($dir)) { $orphans++; } }
    }
    if ($orphans > 0) {
        fwrite(STDERR, "::error::the packaged autoloader maps $orphans paths the zip does not carry\n");
        exit(1);
    }
    echo "autoloader describes the packaged tree\n";
'

# assets/ is not all webpack input. These four are enqueued straight from it and
# never reach build/, so a rule taking the directory wholesale would strip the
# campaign page's styling and two dialogs without failing anything else here.
for runtime in assets/deactivation/dialog.css assets/deactivation/dialog.js \
    assets/donate-button/modal.js assets/campaign-page/page.css
do
    test -f "$OUT/$runtime" || fail "$runtime is enqueued at runtime and is not in the zip"
done

# Guideline 4: build/ is compiled, and the repository readme.txt names is where a
# reviewer is sent for the sources. That link is load-bearing rather than prose,
# so losing it is a build failure and not a copy edit.
grep -q 'github.com/dono-platform/dono' "$OUT/readme.txt" \
    || fail "readme.txt does not name the repository, so nothing says where build/ came from"

# The other side of the same bargain: the repository answers for these, so
# shipping them again is 2.4 MB every install pays for nothing.
for src in assets/admin assets/_shared assets/donation-form assets/donor-portal \
    package.json webpack.config.js
do
    test ! -e "$OUT/$src" || fail "$src is in the zip; the repository is what answers for it"
done

# Plugin Check calls a stray .DS_Store an error. This pins the .distignore rule
# rather than junk in general: it looks for the same two basenames that rule
# names, so any other stray (._foo, desktop.ini) passes both.
#
# -print -quit rather than `| head -1`: under pipefail, find losing the pipe once
# head has its line exits 141, which set -e turns into a silent abort before the
# ::error:: below can name the file.
JUNK=$(find "$OUT" \( -name '.DS_Store' -o -name 'Thumbs.db' \) -print -quit)
test -z "$JUNK" || fail "${JUNK#"$OUT"/} is in the zip; hidden files are not permitted"

echo "zip verified"
