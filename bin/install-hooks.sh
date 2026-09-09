#!/usr/bin/env bash
# Install each repo’s composer-test pre-push hook. Re-runs replace only this hook.
# GRATORA_EXTRA_REPOS adds colon-separated paths outside the plugins directory.
set -uo pipefail

CORE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGINS_DIR="$(dirname "$CORE_DIR")"

read -r -d '' HOOK <<'HOOKEOF'
#!/usr/bin/env bash
# Installed by gratora/bin/install-hooks.sh; runs this repo’s analysis and tests.
set -uo pipefail

if [ ! -x vendor/bin/phpunit ] && [ ! -x vendor/bin/phpstan ]; then
    echo "pre-push: composer install has not been run here, skipping checks."
    exit 0
fi

echo "pre-push: running composer test in $(basename "$PWD")..."
if out="$(composer test 2>&1)"; then
    echo "pre-push: green."
    exit 0
fi

echo
# Show the log tail when no known error pattern matches.
matched="$(printf '%s\n' "$out" | grep -E "^(FAILURES|ERRORS|Tests:|[0-9]+\)|.*Error:| *\[ERROR\])" | head -12)"
if [ -n "$matched" ]; then
    printf '%s\n' "$matched"
else
    printf '%s\n' "$out" | tail -12
fi
echo
echo "pre-push: refused. Fix the above, or push anyway with --no-verify."
exit 1
HOOKEOF

install_into() {
    local repo="$1" name
    name="$(basename "$repo")"
    [ -d "$repo/.git" ] || return 0

    local dir="$repo/.git/hooks"
    mkdir -p "$dir"
    if [ -f "$dir/pre-push" ] && ! grep -q "install-hooks.sh" "$dir/pre-push"; then
        printf '  %-30s left alone (a different pre-push hook is already there)\n' "$name"
        return 0
    fi
    printf '%s\n' "$HOOK" > "$dir/pre-push"
    chmod +x "$dir/pre-push"
    printf '  %-30s installed\n' "$name"
}

install_into "$CORE_DIR"
for d in "$PLUGINS_DIR"/gratora-*/; do
    install_into "${d%/}"
done
IFS=':' read -ra EXTRA <<< "${GRATORA_EXTRA_REPOS:-}"
for d in ${EXTRA[@]+"${EXTRA[@]}"}; do
    [ -n "$d" ] && [ -d "$d" ] && install_into "$d"
done

echo
echo "Hooks live in .git/hooks, which git does not track, so this has to be run"
echo "once per clone. Skip a single push with: git push --no-verify"
