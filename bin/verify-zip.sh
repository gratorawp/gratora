#!/usr/bin/env bash
# Verify the extracted release tree used by deploy.yml.
# Run bash bin/verify-zip.sh after npm run package. Errors use GitHub annotations.

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

SLUG=gratora-donation-platform

ZIP="dist/$SLUG.zip"
OUT="dist/$SLUG"

fail() { echo "::error::$1" >&2; exit 1; }

test -f "$ZIP" || fail "no zip was produced"

rm -rf "$OUT"
unzip -q "$ZIP" -d dist


for f in gratora.php vendor/autoload.php vendor/woocommerce/action-scheduler/action-scheduler.php build; do
    test -e "$OUT/$f" || fail "$f missing from the zip; the plugin would fatal on activation"
done

# Check ignored local files against the packaged payload.
for leak in tests tests-e2e node_modules .git .npmrc graphify-out qa \
    phpunit.xml.dist phpunit-integration.xml.dist .wordpress-org languages/README.md
do
    test ! -e "$OUT/$leak" || fail "$leak is in the zip"
done

# Load both autoloaders as gratora.php does; production Composer installs remove Strauss’s
# autoload edit.
OUT="$OUT" php -r '
    $out = getenv("OUT");
    require "$out/vendor/autoload.php";
    require "$out/vendor/vendor-prefixed/autoload.php";
    $need = [
      "Gratora\\Vendor\\Queryable\\Model",
      "Gratora\\Vendor\\Dompdf\\Dompdf",
      "Gratora\\Foundation\\Plugin",
      "Gratora\\Receipts\\PdfBuilder",
    ];
    foreach ($need as $c) {
      if (! class_exists($c)) { fwrite(STDERR, "::error::$c does not resolve from the packaged tree\n"); exit(1); }
    }
    echo "runtime classes resolve\n";
'

# DejaVu provides Cyrillic and Greek glyphs missing from core PDF fonts.
test -f "$OUT/vendor/vendor-prefixed/dompdf/dompdf/lib/fonts/DejaVuSans.ttf" \
    || fail "DejaVu is missing, so non-Latin donor names would not render"

# Retain the vendor manifest required by Plugin Check.
test -f "$OUT/composer.json" || fail "composer.json is missing next to vendor/"

# Check autoload paths: Composer prepends its loader and could intercept another plugin’s
# classes.
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

# Retain assets enqueued directly without webpack.
for runtime in assets/deactivation/dialog.css assets/deactivation/dialog.js \
    assets/donate-button/modal.js assets/campaign-page/page.css
do
    test -f "$OUT/$runtime" || fail "$runtime is enqueued at runtime and is not in the zip"
done

# Keep the source-repository link required for compiled assets.
grep -q 'github.com/gratorawp/gratora' "$OUT/readme.txt" \
    || fail "readme.txt does not name the repository, so nothing says where build/ came from"


for src in assets/admin assets/_shared assets/donation-form assets/donor-portal \
    package.json webpack.config.js
do
    test ! -e "$OUT/$src" || fail "$src is in the zip; the repository is what answers for it"
done

# Reject shell scripts anywhere in the payload, including vendor packages.
SH=$(find "$OUT" -name '*.sh' -print -quit)
test -z "$SH" || fail "${SH#"$OUT"/} is a shell script; the directory refuses the upload"

# Exclude vendor CLI tooling from the plugin payload.
BIN=$(find "$OUT/vendor" -type d -name bin -print -quit 2>/dev/null)
test -z "$BIN" || fail "${BIN#"$OUT"/} ships a package's own tooling"

# Use find -print -quit; head can trigger SIGPIPE and abort under pipefail before reporting the
# file.
JUNK=$(find "$OUT" \( -name '.DS_Store' -o -name 'Thumbs.db' \) -print -quit)
test -z "$JUNK" || fail "${JUNK#"$OUT"/} is in the zip; hidden files are not permitted"

echo "zip verified"
