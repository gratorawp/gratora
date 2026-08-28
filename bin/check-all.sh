#!/usr/bin/env bash
#
# Static analysis and tests across core and every add-on beside it.
#
# GitHub Actions is where this belongs, and each repo carries a workflow for
# it, but those only run once the org's Actions billing allows. Until then this
# is the thing that actually gets run, so it prints a summary rather than
# stopping at the first failure: knowing that three add-ons are red is worth
# more than knowing the first one is.
#
# Usage:
#   bin/check-all.sh              analysis and tests everywhere
#   bin/check-all.sh --analyse    analysis only, which takes seconds
#   bin/check-all.sh p2p events   only add-ons whose name contains one of these
#
set -uo pipefail

CORE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGINS_DIR="$(dirname "$CORE_DIR")"

TASK="test"
FILTERS=()
for arg in "$@"; do
    case "$arg" in
        --analyse|--analyze) TASK="analyse" ;;
        -h|--help) awk 'NR>2 { if ($0 !~ /^#/) exit; sub(/^# ?/, ""); print }' "${BASH_SOURCE[0]}"; exit 0 ;;
        *) FILTERS+=("$arg") ;;
    esac
done

wanted() {
    [ ${#FILTERS[@]} -eq 0 ] && return 0
    for f in "${FILTERS[@]}"; do
        case "$1" in *"$f"*) return 0 ;; esac
    done
    return 1
}

# Core first: an add-on suite boots it, so a broken core makes every add-on
# failure a lie.
REPOS=("$CORE_DIR")
for d in "$PLUGINS_DIR"/giveflow-*/; do
    [ -f "${d}composer.json" ] && REPOS+=("${d%/}")
done

# giveflow-hq lives in the other site, since it is not a GiveFlow add-on.
HQ="$HOME/Local Sites/getdono/app/public/wp-content/plugins/giveflow-hq"
[ -f "$HQ/composer.json" ] && REPOS+=("$HQ")

printf '%-32s %s\n' "REPO" "RESULT"
printf '%-32s %s\n' "--------------------------------" "------"

failed=()
skipped=()
for repo in "${REPOS[@]}"; do
    name="$(basename "$repo")"
    wanted "$name" || continue

    if [ ! -x "$repo/vendor/bin/phpunit" ] && [ ! -x "$repo/vendor/bin/phpstan" ]; then
        printf '%-32s %s\n' "$name" "skipped (composer install not run)"
        skipped+=("$name")
        continue
    fi

    out="$(cd "$repo" && composer "$TASK" 2>&1)"
    if [ $? -eq 0 ]; then
        tests="$(printf '%s' "$out" | grep -oE 'OK \([0-9]+ tests' | grep -oE '[0-9]+' | paste -sd+ - | bc 2>/dev/null)"
        printf '%-32s %s\n' "$name" "ok${tests:+ ($tests tests)}"
    else
        printf '%-32s %s\n' "$name" "FAILED"
        failed+=("$name")
        printf '%s\n' "$out" | grep -E "^(FAILURES|ERRORS|Tests:|[0-9]+\)|.*Error:)" | head -6 | sed 's/^/    /'
    fi
done

echo
if [ ${#skipped[@]} -gt 0 ]; then
    echo "Skipped, needing composer install: ${skipped[*]}"
fi
if [ ${#failed[@]} -gt 0 ]; then
    echo "Red: ${failed[*]}"
    exit 1
fi
echo "All green."
