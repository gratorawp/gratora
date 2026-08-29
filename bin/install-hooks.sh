#!/usr/bin/env bash
#
# Install a pre-push hook in core and every add-on beside it.
#
# The hook runs that repo's own `composer test`, which is the analysis and the
# suites. Five to eleven seconds for an add-on, forty for core.
#
# This exists because GitHub Actions is where these checks belong and cannot
# run yet: the org's Actions billing does not allow it. A hook is a weaker
# thing than CI, because `--no-verify` skips it and it only guards what leaves
# this machine, but it is the difference between checks that run and checks
# that someone has to remember.
#
# Re-run it any time; it overwrites its own hook and leaves any other alone.
#
# Repos outside this plugins directory can be added with GIVEFLOW_EXTRA_REPOS,
# a colon-separated list of paths.
#
set -uo pipefail

CORE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGINS_DIR="$(dirname "$CORE_DIR")"

read -r -d '' HOOK <<'HOOKEOF'
#!/usr/bin/env bash
#
# Installed by giveflow/bin/install-hooks.sh. Runs this repo's analysis and
# suites before anything leaves the machine.
#
# To push past it once:  git push --no-verify
#
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
printf '%s\n' "$out" | grep -E "^(FAILURES|ERRORS|Tests:|[0-9]+\)|.*Error:| *\[ERROR\])" | head -12
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
for d in "$PLUGINS_DIR"/giveflow-*/; do
    install_into "${d%/}"
done
IFS=':' read -ra EXTRA <<< "${GIVEFLOW_EXTRA_REPOS:-}"
for d in ${EXTRA[@]+"${EXTRA[@]}"}; do
    [ -n "$d" ] && [ -d "$d" ] && install_into "$d"
done

echo
echo "Hooks live in .git/hooks, which git does not track, so this has to be run"
echo "once per clone. Skip a single push with: git push --no-verify"
