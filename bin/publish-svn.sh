#!/usr/bin/env bash
# Publish the extracted release tree to the WordPress.org plugin directory.
# Run bash bin/publish-svn.sh after bin/verify-zip.sh. Errors use GitHub annotations.
#
# The directory takes one commit at a time and finalises it server side. 1.0.0 went up
# as a single transaction of roughly 1850 nodes, trunk and the tag copy together, and
# wordpress.org dropped the connection on both attempts at "Committing transaction...".
# Everything here exists to send that tree in pieces and to make the tag without
# uploading it a second time.

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."
ROOT="$(pwd)"

SLUG="${SLUG:-gratora-donation-platform}"
VERSION="${VERSION:-}"
BUILD_DIR="${BUILD_DIR:-dist/$SLUG}"
ASSETS_DIR="${ASSETS_DIR:-.wordpress-org}"
SVN_USERNAME="${SVN_USERNAME:-}"
SVN_PASSWORD="${SVN_PASSWORD:-}"

# Every plugin shares one repository rooted here, so ^/trunk inside a working copy
# resolves to the root's own trunk and fails with E160013. Address the plugin by full URL.
SVN_URL="${SVN_URL:-https://plugins.svn.wordpress.org}"

# The subtrees that carry the weight. Each one goes up on its own so no transaction
# holds the whole plugin.
TRUNK_PARTS=(vendor build src languages assets)

fail() { echo "::error::$1" >&2; exit 1; }

command -v svn >/dev/null || fail "svn is not installed, so nothing can be published"
command -v rsync >/dev/null || fail "rsync is not installed, so trunk cannot be updated"

test -n "$VERSION" || fail "VERSION is not set, so there is no release to publish"
test -n "$SVN_USERNAME" || fail "SVN_USERNAME is not set"
test -n "$SVN_PASSWORD" || fail "SVN_PASSWORD is not set"
test -d "$BUILD_DIR" || fail "$BUILD_DIR does not exist; run npm run package first"
test -n "$(ls -A "$BUILD_DIR")" || fail "$BUILD_DIR is empty, and publishing it would empty trunk"
test -f "$BUILD_DIR/readme.txt" || fail "$BUILD_DIR/readme.txt is missing, so nothing declares which version this is"

# VERSION names the tag directory, and WordPress serves whichever tag Stable tag resolves
# to. A VERSION the payload does not declare leaves the directory on trunk with no tagged
# release behind it, so take the readme as the authority rather than the caller.
STABLE="$(sed -n 's/^Stable tag:[[:space:]]*//p' "$BUILD_DIR/readme.txt" | head -1 | tr -d ' \r')"
test "$VERSION" = "$STABLE" || fail "VERSION ($VERSION) is not the Stable tag in the packaged readme.txt ($STABLE), so the tag would not be the release"

BUILD_DIR="$(cd "$BUILD_DIR" && pwd)"

if [ -d "$ASSETS_DIR" ]; then
    test -n "$(ls -A "$ASSETS_DIR")" || fail "$ASSETS_DIR is empty, and publishing it would strip the listing artwork"
    ASSETS_DIR="$(cd "$ASSETS_DIR" && pwd)"
else
    echo "::warning::$ASSETS_DIR does not exist, so the listing keeps whatever artwork it already has"
    ASSETS_DIR=
fi

PLUGIN_URL="$SVN_URL/$SLUG"

# svn 1.10 and later read the password from stdin, which keeps it out of the argument
# list that --password leaves visible to everything else on the machine.
svn_() {
    printf '%s\n' "$SVN_PASSWORD" | svn "$@" \
        --username "$SVN_USERNAME" --password-from-stdin \
        --non-interactive --no-auth-cache \
        --config-option servers:global:http-timeout=300
}

# A log message that names an existing path is refused as a mistyped -F (E205005), so
# every message below is a sentence rather than the path it is sending.
commit() {
    local path="$1" depth="$2" what="$3" pending

    pending="$(svn_ status --quiet --depth "$depth" "$path")" \
        || fail "svn cannot read the status of $what, so there is no telling what would be sent"

    if [ -z "$pending" ]; then
        echo "nothing to send for $what"
        return
    fi

    echo "sending $what"
    svn_ commit --depth "$depth" -m "$SLUG $VERSION: $what" "$path"
}

verify_against() {
    SLUG="$SLUG" VERSION="$VERSION" BUILD_DIR="$BUILD_DIR" SVN_URL="$SVN_URL" \
        TARGET_URL="$1" SVN_USERNAME="$SVN_USERNAME" SVN_PASSWORD="$SVN_PASSWORD" \
        bash "$ROOT/bin/verify-svn-tag.sh"
}

if svn_ ls "$PLUGIN_URL/tags/$VERSION" >/dev/null 2>&1; then
    fail "tags/$VERSION is already published, and a published tag is immutable in practice; release a new version"
fi

SVN_DIR="$(mktemp -d "${TMPDIR:-/tmp}/svn-$SLUG.XXXXXX")"
trap 'rm -rf "$SVN_DIR"' EXIT

echo "checking out $PLUGIN_URL"
svn_ checkout --depth immediates "$PLUGIN_URL" "$SVN_DIR" >/dev/null
cd "$SVN_DIR"

# A file dropped from the build has to be dropped from trunk, and svn can only see that
# against a working copy that holds every path. At immediates depth the unfetched paths
# read as unversioned, and adding over them collides with what the repository already holds.
if [ -d trunk ]; then
    svn_ update --set-depth infinity trunk >/dev/null
else
    mkdir trunk
    svn_ add trunk >/dev/null
fi

rsync -rc "$BUILD_DIR/" trunk/ --delete

if [ -n "$ASSETS_DIR" ]; then
    if [ -d assets ]; then
        svn_ update --set-depth infinity assets >/dev/null
    else
        mkdir assets
        svn_ add assets >/dev/null
    fi

    rsync -rc "$ASSETS_DIR/" assets/ --delete
fi

# A path that changed node kind between releases obstructs the working copy, and any add
# at or below it dies with E145001 on every rerun. Unversioning it leaves the new kind on
# disk, which the add pass below then schedules as a replacement.
OBSTRUCTED="$(svn_ status | sed -n 's/^~[[:space:]]*//p')"
if [ -n "$OBSTRUCTED" ]; then
    printf '%s\n' "$OBSTRUCTED" | while IFS= read -r path; do
        svn_ rm --keep-local "$path@" >/dev/null
    done
fi

# --no-ignore, or svn silently skips every path matching its default global-ignores
# (*.o, *~, #*#, __pycache__ and the rest) and svn status --quiet hides the skip, so the
# part reports nothing to send and the file never goes up.
if [ -n "$ASSETS_DIR" ]; then
    svn_ add --force --no-ignore assets >/dev/null

    # Without a type the directory serves a screenshot as a download rather than an image.
    for ext in png jpg gif svg; do
        case "$ext" in
            png) mime=image/png ;;
            jpg) mime=image/jpeg ;;
            gif) mime=image/gif ;;
            svg) mime=image/svg+xml ;;
        esac

        if [ -n "$(find assets -maxdepth 1 -name "*.$ext" -print -quit)" ]; then
            svn_ propset svn:mime-type "$mime" assets/*."$ext" >/dev/null
        fi
    done
fi

svn_ add --force --no-ignore trunk >/dev/null

MISSING="$(svn_ status | sed -n 's/^![[:space:]]*//p')"
if [ -n "$MISSING" ]; then
    printf '%s\n' "$MISSING" | while IFS= read -r path; do
        # The trailing @ ends peg revision parsing, so a filename containing one resolves.
        svn_ rm "$path@" >/dev/null
    done
fi

if [ -n "$ASSETS_DIR" ]; then
    commit assets infinity "the listing artwork"
fi

# The directory node lands before anything inside it, and alone, so the first trunk
# transaction is one node rather than the tree.
commit trunk empty "create trunk"

for part in "${TRUNK_PARTS[@]}"; do
    commit "trunk/$part" infinity "trunk/$part"
done

# Whatever the named parts left behind: the top level files, and any directory the build
# no longer carries.
commit trunk infinity "the rest of trunk"

# Tagging is the one step with no way back: the guard above refuses to republish a version
# that already has a tag, so an incomplete tag burns the version number. Read trunk back
# while a rerun is still free, and only then copy it.
verify_against "$PLUGIN_URL/trunk"

# A copy between two URLs happens inside the repository and uploads nothing, which is the
# whole reason the tag is made here and not as a local svn cp committed with trunk.
echo "tagging $VERSION"
svn_ cp "$PLUGIN_URL/trunk" "$PLUGIN_URL/tags/$VERSION" -m "$SLUG $VERSION: tag the release"

cd "$ROOT"

verify_against "$PLUGIN_URL/tags/$VERSION"

echo "$SLUG $VERSION published"
