#!/usr/bin/env bash
# Drive bin/publish-svn.sh and bin/verify-svn-tag.sh against a local repository.
# Run bash bin/test-publish-svn.sh. Nothing here touches the network or plugins.svn.wordpress.org.
#
# The fixture is a synthetic build tree with the same top level shape as the real one
# (vendor, build, src, languages, assets and six files beside them), because what is
# under test is the order the parts go up in and what trunk holds afterwards, neither of
# which changes with 21 MB behind it.

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."
ROOT="$(pwd)"

SLUG=gratora-donation-platform

WORK="$(mktemp -d "${TMPDIR:-/tmp}/publish-svn-test.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT

REPO="file://$WORK/repo"
PLUGIN="$REPO/$SLUG"

REAL_SVN="$(command -v svn)"

# An svn that loses one part: either its commit reports success and sends nothing, or its
# status cannot be read. Both are what the publishing script sees when a part goes missing
# for any reason. Only on PATH where a test asks for it.
mkdir -p "$WORK/shim"
cat > "$WORK/shim/svn" <<'SHIM'
#!/usr/bin/env bash
if [ -n "${SKIP_PATTERN:-}" ]; then
    prev=
    for arg in "$@"; do
        if [ "$prev" = "-m" ] && printf '%s' "$arg" | grep -qE "$SKIP_PATTERN"; then
            cat >/dev/null
            echo "Committed revision 0."
            exit 0
        fi
        prev="$arg"
    done
fi

if [ -n "${STATUS_FAIL_PATH:-}" ] && [ "$1" = "status" ]; then
    for arg in "$@"; do
        if [ "$arg" = "$STATUS_FAIL_PATH" ]; then
            cat >/dev/null
            echo "svn: E155007: '$arg' is not a working copy" >&2
            exit 1
        fi
    done
fi

exec "$REAL_SVN" "$@"
SHIM
chmod +x "$WORK/shim/svn"

PASSED=0

fail() { echo "::error::$1" >&2; exit 1; }

pass() { PASSED=$((PASSED + 1)); echo "ok: $1"; }

url_tree() {
    svn ls --recursive "$1" | LC_ALL=C sort
}

# svn log prints each message on its own lines, so an exact line match counts the
# transactions a given part went up in.
revisions_for() {
    svn log "$PLUGIN" | grep -cFx "$SLUG $1" || true
}

dir_tree() {
    ( cd "$1" && {
        find . -mindepth 1 -type d | sed -e 's|^\./||' -e 's|$|/|'
        find . -mindepth 1 ! -type d | sed -e 's|^\./||'
    } ) | LC_ALL=C sort
}

assert_same() {
    if [ "$2" != "$3" ]; then
        diff <(printf '%s\n' "$2") <(printf '%s\n' "$3") >&2 || true
        fail "$1"
    fi
    pass "$1"
}

assert_eq() {
    test "$2" = "$3" || fail "$1 (expected '$3', got '$2')"
    pass "$1"
}

publish() {
    SLUG="$SLUG" VERSION="$1" BUILD_DIR="$2" ASSETS_DIR="$3" SVN_URL="$REPO" \
        SVN_USERNAME=publish-test SVN_PASSWORD=not-a-real-password \
        bash "$ROOT/bin/publish-svn.sh"
}

verify() {
    SLUG="$SLUG" VERSION="$1" BUILD_DIR="$2" SVN_URL="$REPO" \
        SVN_USERNAME=publish-test SVN_PASSWORD=not-a-real-password \
        bash "$ROOT/bin/verify-svn-tag.sh"
}

# The shape bin/package.mjs produces: five directories and the files beside them.
make_build() {
    local dir="$1" version="$2"

    rm -rf "$dir"
    mkdir -p "$dir/vendor/composer" "$dir/vendor/vendor-prefixed/gratora/queryable" \
        "$dir/build" "$dir/src/Foundation" "$dir/languages" "$dir/assets/deactivation"

    printf 'Version: %s\n' "$version" > "$dir/gratora.php"
    printf 'Stable tag: %s\n' "$version" > "$dir/readme.txt"
    printf 'GPL\n' > "$dir/LICENSE"
    printf '= %s =\n' "$version" > "$dir/changelog.txt"
    printf '{"name":"gratorawp/gratora"}\n' > "$dir/composer.json"
    printf '<?php\n' > "$dir/uninstall.php"
    printf '<?php return [];\n' > "$dir/vendor/autoload.php"
    printf '{"dev":false}\n' > "$dir/vendor/composer/installed.json"
    printf '<?php class Model {}\n' > "$dir/vendor/vendor-prefixed/gratora/queryable/Model.php"
    printf 'admin bundle %s\n' "$version" > "$dir/build/admin.js"
    printf 'form bundle %s\n' "$version" > "$dir/build/donation-form.js"
    printf '<?php // Plugin %s\n' "$version" > "$dir/src/Foundation/Plugin.php"
    printf 'msgid ""\n' > "$dir/languages/gratora.pot"
    printf '.dialog{}\n' > "$dir/assets/deactivation/dialog.css"
}

make_artwork() {
    local dir="$1"

    rm -rf "$dir"
    mkdir -p "$dir"
    printf 'icon\n' > "$dir/icon-256x256.png"
    printf 'banner\n' > "$dir/banner-772x250.png"
    printf '<svg></svg>\n' > "$dir/icon.svg"
}

echo "building the fixture repository"
svnadmin create "$WORK/repo"
svn mkdir --parents -q -m "create the plugin layout" \
    "$PLUGIN" "$PLUGIN/trunk" "$PLUGIN/tags" "$PLUGIN/assets"

BUILD="$WORK/build"
ARTWORK="$WORK/artwork"


echo
echo "== a first release =="
make_build "$BUILD" 1.0.0
make_artwork "$ARTWORK"
publish 1.0.0 "$BUILD" "$ARTWORK"

assert_same "trunk holds the build" "$(url_tree "$PLUGIN/trunk")" "$(dir_tree "$BUILD")"
assert_same "assets holds the artwork" "$(url_tree "$PLUGIN/assets")" "$(dir_tree "$ARTWORK")"
assert_same "tags/1.0.0 is a complete copy" "$(url_tree "$PLUGIN/tags/1.0.0")" "$(dir_tree "$BUILD")"

# The reason the script exists: one transaction per part, never one for the tree.
for part in vendor build src languages assets; do
    assert_eq "trunk/$part went up on its own" "$(revisions_for "1.0.0: trunk/$part")" 1
done
assert_eq "the artwork went up on its own" "$(revisions_for "1.0.0: the listing artwork")" 1
assert_eq "the top level files went up on their own" "$(revisions_for "1.0.0: the rest of trunk")" 1

# A copy between two URLs happens inside the repository, so the tag revision changes one
# node and transmits no file data.
TAG_REV="$(svn info --show-item last-changed-revision "$PLUGIN/tags/1.0.0")"
CHANGED="$(svn log -v -q -r "$TAG_REV" "$PLUGIN" | sed -n 's/^   //p')"

assert_eq "the tag changed one node" "$(printf '%s\n' "$CHANGED" | wc -l | tr -d ' ')" 1
assert_eq "the tag was copied server side" \
    "$(printf '%s\n' "$CHANGED" | grep -c "^A /$SLUG/tags/1.0.0 (from /$SLUG/trunk:" || true)" 1


echo
echo "== an update that changes, adds and deletes =="
make_build "$BUILD" 1.0.1
printf '<?php // Plugin 1.0.1, rewritten\n' > "$BUILD/src/Foundation/Plugin.php"
# The same length as what make_build wrote, so a size comparison alone would miss it.
printf 'admin BUNDLE 1.0.1\n' > "$BUILD/build/admin.js"
printf 'msgid "Donate"\n' > "$BUILD/languages/gratora-de_DE.po"
rm "$BUILD/vendor/composer/installed.json"
publish 1.0.1 "$BUILD" "$ARTWORK"

assert_same "trunk matches the new build exactly" "$(url_tree "$PLUGIN/trunk")" "$(dir_tree "$BUILD")"
assert_eq "the deleted file is gone from trunk" \
    "$(url_tree "$PLUGIN/trunk" | grep -c 'vendor/composer/installed.json' || true)" 0
assert_eq "the added file reached trunk" \
    "$(svn cat "$PLUGIN/trunk/languages/gratora-de_DE.po")" 'msgid "Donate"'
assert_eq "the changed file reached trunk" \
    "$(svn cat "$PLUGIN/trunk/src/Foundation/Plugin.php")" '<?php // Plugin 1.0.1, rewritten'
assert_eq "a change that does not alter the size reached trunk" \
    "$(svn cat "$PLUGIN/trunk/build/admin.js")" 'admin BUNDLE 1.0.1'

assert_eq "the old tag still holds the old file" \
    "$(svn cat "$PLUGIN/tags/1.0.0/src/Foundation/Plugin.php")" '<?php // Plugin 1.0.0'
assert_eq "the old tag still holds the deleted file" \
    "$(url_tree "$PLUGIN/tags/1.0.0" | grep -c 'vendor/composer/installed.json' || true)" 1


echo
echo "== a rerun after a publish that stopped partway =="
make_build "$BUILD" 1.0.2

# What a dropped connection leaves behind: the artwork and one subtree landed, the rest
# never did.
HALF="$WORK/half"
svn checkout -q --depth immediates "$PLUGIN" "$HALF"
svn update -q --set-depth infinity "$HALF/trunk" "$HALF/assets"
rsync -rc "$BUILD/" "$HALF/trunk/" --delete
rsync -rc "$ARTWORK/" "$HALF/assets/" --delete
svn add -q --force "$HALF/trunk" "$HALF/assets"
svn status "$HALF" | sed -n 's/^![[:space:]]*//p' | while IFS= read -r p; do svn rm -q "$p@"; done
svn commit -q "$HALF/assets" -m "$SLUG 1.0.2: a partial publish, the artwork"
svn commit -q "$HALF/trunk/vendor" -m "$SLUG 1.0.2: a partial publish, vendor"
rm -rf "$HALF"

publish 1.0.2 "$BUILD" "$ARTWORK"

assert_same "the rerun finished trunk" "$(url_tree "$PLUGIN/trunk")" "$(dir_tree "$BUILD")"
assert_same "the rerun tagged a complete copy" "$(url_tree "$PLUGIN/tags/1.0.2")" "$(dir_tree "$BUILD")"
assert_eq "the rerun skipped the part already sent" "$(revisions_for "1.0.2: trunk/vendor")" 0


echo
echo "== a version that is already tagged =="
RETAG="$WORK/retag"
make_build "$RETAG" 1.0.1

BEFORE="$(svn log -q "$PLUGIN" | grep -c '^r')"
OUTPUT="$(publish 1.0.1 "$RETAG" "$ARTWORK" 2>&1)" && STATUS=0 || STATUS=$?

assert_eq "publishing over a tag is refused" "$STATUS" 1
assert_eq "the refusal says why" \
    "$(printf '%s\n' "$OUTPUT" | grep -c 'immutable in practice' || true)" 1
assert_eq "the refusal sent nothing" "$(svn log -q "$PLUGIN" | grep -c '^r')" "$BEFORE"


echo
echo "== a tag that does not match the build =="
MISMATCH="$WORK/mismatch"
rm -rf "$MISMATCH"
cp -R "$BUILD" "$MISMATCH"
printf 'msgid ""\n' > "$MISMATCH/languages/gratora-fr_FR.po"
rm "$MISMATCH/build/admin.js"

OUTPUT="$(verify 1.0.2 "$MISMATCH" 2>&1)" && STATUS=0 || STATUS=$?

assert_eq "a mismatched tag fails verification" "$STATUS" 1
assert_eq "the mismatch is annotated" \
    "$(printf '%s\n' "$OUTPUT" | grep -c '::error::tags/1.0.2 holds' || true)" 1
assert_eq "the mismatch names what the tag is missing" \
    "$(printf '%s\n' "$OUTPUT" | grep -c 'absent from tags/1.0.2: languages/gratora-fr_FR.po' || true)" 1
assert_eq "the mismatch names what the build is missing" \
    "$(printf '%s\n' "$OUTPUT" | grep -c 'absent from the build: build/admin.js' || true)" 1

OUTPUT="$(verify 9.9.9 "$BUILD" 2>&1)" && STATUS=0 || STATUS=$?
assert_eq "an unpublished tag fails verification" "$STATUS" 1
assert_eq "the unpublished tag is annotated" \
    "$(printf '%s\n' "$OUTPUT" | grep -c 'did not publish' || true)" 1


echo
echo "== a build that no longer carries one of the named parts =="
SHRUNK="$WORK/shrunk"
make_build "$SHRUNK" 1.0.3
rm -rf "$SHRUNK/languages"
publish 1.0.3 "$SHRUNK" "$ARTWORK"

assert_same "trunk matches the shorter build" "$(url_tree "$PLUGIN/trunk")" "$(dir_tree "$SHRUNK")"
assert_eq "the directory is gone from trunk" \
    "$(url_tree "$PLUGIN/trunk" | grep -c '^languages' || true)" 0


echo
echo "== a build carrying names svn ignores by default =="
IGNORED="$WORK/ignored"
make_build "$IGNORED" 1.0.4
printf 'object code\n' > "$IGNORED/src/legacy.o"
printf 'an editor backup\n' > "$IGNORED/src/notes~"

OUTPUT="$(publish 1.0.4 "$IGNORED" "$ARTWORK" 2>&1)" && STATUS=0 || STATUS=$?

assert_eq "a build carrying ignored names publishes" "$STATUS" 0
assert_eq "the ignored object file reached trunk" \
    "$(url_tree "$PLUGIN/trunk" | grep -cFx 'src/legacy.o' || true)" 1
assert_eq "the ignored backup file reached trunk" \
    "$(url_tree "$PLUGIN/trunk" | grep -cFx 'src/notes~' || true)" 1
assert_same "tags/1.0.4 carries them too" "$(url_tree "$PLUGIN/tags/1.0.4")" "$(dir_tree "$IGNORED")"


echo
echo "== a path that changes node kind between releases =="
KIND="$WORK/kind"
make_build "$KIND" 1.0.5
rm "$KIND/src/Foundation/Plugin.php"
mkdir "$KIND/src/Foundation/Plugin.php"
printf '<?php // Plugin 1.0.5, split up\n' > "$KIND/src/Foundation/Plugin.php/Boot.php"
rm -rf "$KIND/languages"
printf 'no translations yet\n' > "$KIND/languages"

OUTPUT="$(publish 1.0.5 "$KIND" "$ARTWORK" 2>&1)" && STATUS=0 || STATUS=$?

assert_eq "a file that becomes a directory publishes" "$STATUS" 0
assert_same "trunk matches the rebuilt shape" "$(url_tree "$PLUGIN/trunk")" "$(dir_tree "$KIND")"
assert_same "tags/1.0.5 matches the rebuilt shape" "$(url_tree "$PLUGIN/tags/1.0.5")" "$(dir_tree "$KIND")"

BACK="$WORK/back"
make_build "$BACK" 1.0.6
OUTPUT="$(publish 1.0.6 "$BACK" "$ARTWORK" 2>&1)" && STATUS=0 || STATUS=$?

assert_eq "a directory that becomes a file publishes" "$STATUS" 0
assert_same "trunk matches the restored shape" "$(url_tree "$PLUGIN/trunk")" "$(dir_tree "$BACK")"


echo
echo "== a trunk that does not match the build =="
DRIFT="$WORK/drift"
make_build "$DRIFT" 1.0.7
printf 'admin bundle 1.0.7, rebuilt\n' > "$DRIFT/build/admin.js"

# Skipping the two parts that carry build/ leaves the previous release's bytes under every
# path the new one declares, so the listing comparison alone cannot see it.
OUTPUT="$(
    export PATH="$WORK/shim:$PATH" REAL_SVN="$REAL_SVN" \
        SKIP_PATTERN='trunk/build$|the rest of trunk$'
    publish 1.0.7 "$DRIFT" "$ARTWORK" 2>&1
)" && STATUS=0 || STATUS=$?

assert_eq "a trunk that does not match the build is refused" "$STATUS" 1
assert_eq "the refusal reads trunk, not the tag" \
    "$(printf '%s\n' "$OUTPUT" | grep -c '::error::trunk lists' || true)" 1
assert_eq "the refusal names the file whose bytes differ" \
    "$(printf '%s\n' "$OUTPUT" | grep -c 'build/admin.js' || true)" 1
assert_eq "no tag was made" "$(svn ls "$PLUGIN/tags" | grep -cFx '1.0.7/' || true)" 0

# The whole point of catching it first: the version is still free to publish.
publish 1.0.7 "$DRIFT" "$ARTWORK"

assert_same "the refused version publishes on a rerun" \
    "$(url_tree "$PLUGIN/tags/1.0.7")" "$(dir_tree "$DRIFT")"
assert_eq "the rerun sent the part the first run skipped" \
    "$(svn cat "$PLUGIN/trunk/build/admin.js")" 'admin bundle 1.0.7, rebuilt'


echo
echo "== a part whose status cannot be read =="
BLIND="$WORK/blind"
make_build "$BLIND" 1.0.9
printf 'admin bundle 1.0.9, rebuilt\n' > "$BLIND/build/admin.js"

OUTPUT="$(
    export PATH="$WORK/shim:$PATH" REAL_SVN="$REAL_SVN" STATUS_FAIL_PATH=trunk/build
    publish 1.0.9 "$BLIND" "$ARTWORK" 2>&1
)" && STATUS=0 || STATUS=$?

assert_eq "a part whose status cannot be read stops the publish" "$STATUS" 1
assert_eq "the refusal names the part it could not read" \
    "$(printf '%s\n' "$OUTPUT" | grep -c '::error::svn cannot read the status of trunk/build' || true)" 1
assert_eq "the unreadable part made no tag" \
    "$(svn ls "$PLUGIN/tags" | grep -cFx '1.0.9/' || true)" 0


echo
echo "== an ASSETS_DIR that exists and is empty =="
NO_ARTWORK="$WORK/no-artwork"
rm -rf "$NO_ARTWORK"
mkdir -p "$NO_ARTWORK"

NEXT="$WORK/next"
make_build "$NEXT" 1.0.8

BEFORE="$(svn log -q "$PLUGIN" | grep -c '^r')"
ARTWORK_BEFORE="$(url_tree "$PLUGIN/assets")"
OUTPUT="$(publish 1.0.8 "$NEXT" "$NO_ARTWORK" 2>&1)" && STATUS=0 || STATUS=$?

assert_eq "an empty assets directory is refused" "$STATUS" 1
assert_eq "the refusal says what it would cost" \
    "$(printf '%s\n' "$OUTPUT" | grep -c 'would strip the listing artwork' || true)" 1
assert_eq "the refusal sent nothing" "$(svn log -q "$PLUGIN" | grep -c '^r')" "$BEFORE"
assert_same "the published artwork is untouched" "$(url_tree "$PLUGIN/assets")" "$ARTWORK_BEFORE"


echo
echo "== a VERSION the packaged readme does not declare =="
BEFORE="$(svn log -q "$PLUGIN" | grep -c '^r')"
OUTPUT="$(publish v1.0.8 "$NEXT" "$ARTWORK" 2>&1)" && STATUS=0 || STATUS=$?

assert_eq "a VERSION the readme does not declare is refused" "$STATUS" 1
assert_eq "the refusal names the Stable tag" \
    "$(printf '%s\n' "$OUTPUT" | grep -c 'is not the Stable tag in the packaged readme.txt' || true)" 1
assert_eq "no v prefixed tag was made" "$(svn ls "$PLUGIN/tags" | grep -c '^v' || true)" 0
assert_eq "the refusal sent nothing" "$(svn log -q "$PLUGIN" | grep -c '^r')" "$BEFORE"


echo
echo "== a tag whose paths match and whose bytes do not =="
TAMPERED="$WORK/tampered"
rm -rf "$TAMPERED"
cp -R "$DRIFT" "$TAMPERED"
# The same length as what was published, so a size comparison alone would miss it.
printf 'admin bundle 1.0.7, REBUILT\n' > "$TAMPERED/build/admin.js"

OUTPUT="$(verify 1.0.7 "$TAMPERED" 2>&1)" && STATUS=0 || STATUS=$?

assert_eq "a tag whose bytes differ fails verification" "$STATUS" 1
assert_eq "the content mismatch is annotated" \
    "$(printf '%s\n' "$OUTPUT" | grep -c '::error::tags/1.0.7 lists' || true)" 1
assert_eq "the content mismatch names the file" \
    "$(printf '%s\n' "$OUTPUT" | grep -c 'build/admin.js' || true)" 1


echo
echo "$PASSED assertions passed"
