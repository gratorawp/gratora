#!/usr/bin/env bash
# Verify that a published path carries the tree that was built, paths and bytes both.
# Run bash bin/verify-svn-tag.sh after bin/publish-svn.sh. Errors use GitHub annotations.
# TARGET_URL chooses what is read and defaults to the tag; publishing reads trunk first,
# while the tag can still be made a second time.
#
# bin/verify-zip.sh reads the artefact. This reads the repository, which is the only
# thing users install from: a publish that stopped halfway leaves a tag short of the
# files it should hold and nothing else notices.

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

SLUG="${SLUG:-gratora-donation-platform}"
VERSION="${VERSION:-}"
BUILD_DIR="${BUILD_DIR:-dist/$SLUG}"
SVN_URL="${SVN_URL:-https://plugins.svn.wordpress.org}"
SVN_USERNAME="${SVN_USERNAME:-}"
SVN_PASSWORD="${SVN_PASSWORD:-}"

fail() { echo "::error::$1" >&2; exit 1; }

test -n "$VERSION" || fail "VERSION is not set, so there is no tag to read"
test -d "$BUILD_DIR" || fail "$BUILD_DIR does not exist, so there is nothing to compare the tag against"

TARGET_URL="${TARGET_URL:-$SVN_URL/$SLUG/tags/$VERSION}"
TARGET="${TARGET_URL#"$SVN_URL/$SLUG/"}"

svn_() {
    printf '%s\n' "$SVN_PASSWORD" | svn "$@" \
        --username "$SVN_USERNAME" --password-from-stdin \
        --non-interactive --no-auth-cache \
        --config-option servers:global:http-timeout=300
}

PUBLISHED="$(svn_ ls --recursive "$TARGET_URL")" \
    || fail "$TARGET cannot be listed, so the release did not publish"

# svn lists a directory with a trailing slash; match that rather than compare files alone,
# so an empty directory that never made it up is still a difference.
BUILT="$(cd "$BUILD_DIR" && {
    find . -mindepth 1 -type d | sed -e 's|^\./||' -e 's|$|/|'
    find . -mindepth 1 ! -type d | sed -e 's|^\./||'
})"

PUBLISHED="$(printf '%s\n' "$PUBLISHED" | LC_ALL=C sort)"
BUILT="$(printf '%s\n' "$BUILT" | LC_ALL=C sort)"

PUBLISHED_COUNT="$(printf '%s\n' "$PUBLISHED" | wc -l | tr -d ' ')"
BUILT_COUNT="$(printf '%s\n' "$BUILT" | wc -l | tr -d ' ')"

if [ "$PUBLISHED" != "$BUILT" ]; then
    diff <(printf '%s\n' "$BUILT") <(printf '%s\n' "$PUBLISHED") \
        | sed -n -e "s|^< |absent from $TARGET: |p" -e 's|^> |absent from the build: |p' \
        | awk 'NR <= 20' >&2 || true

    fail "$TARGET holds $PUBLISHED_COUNT entries and the build holds $BUILT_COUNT"
fi

# The paths agreeing proves nothing about what is behind them: a part that was skipped
# leaves the previous release's bytes at exactly the right names.
EXPORT_DIR="$(mktemp -d "${TMPDIR:-/tmp}/verify-$SLUG.XXXXXX")"
trap 'rm -rf "$EXPORT_DIR"' EXIT

svn_ export --quiet "$TARGET_URL" "$EXPORT_DIR/tree" \
    || fail "$TARGET cannot be exported, so its contents cannot be read back"

DIFFERENT="$(diff -r -q "$BUILD_DIR" "$EXPORT_DIR/tree" 2>&1 | awk 'NR <= 20')" || true
if [ -n "$DIFFERENT" ]; then
    printf '%s\n' "$DIFFERENT" \
        | sed -e "s|$BUILD_DIR|the build|g" -e "s|$EXPORT_DIR/tree|$TARGET|g" >&2

    fail "$TARGET lists the $BUILT_COUNT entries that were built, but their contents differ"
fi

echo "$TARGET matches the build: $BUILT_COUNT entries"
