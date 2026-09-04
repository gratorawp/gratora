#!/usr/bin/env bash
# Screen candidate plugin names against the checks that actually matter.
#
#   bin/screen-names.sh Onelo Tavaro Kindio
#   printf '%s\n' Onelo Tavaro Kindio | bin/screen-names.sh
#
# Checks, in the order that has caught real collisions:
#   1. wp.org slug free + directory matches
#   2. live site on <name>.com / get<name>.com / try<name>.com /
#      <name>app.com / <name>.org / <name>.io / <name>.co
#      (this caught CauseKit, Tarvo, Causara, Grantify, Givvi)
#
# It does NOT catch country domains (.ch .fi .org.au) or companies with no
# reachable site. For anything that survives, still web-search:
#     "<name>" nonprofit        "<name>" charity donation
# That search caught GIVIT, Blackbaud Altru, Dorea and FundQuest.
set -uo pipefail
[ $# -gt 0 ] && NAMES="$*" || NAMES="$(cat)"

title() { curl -sL --max-time 5 "https://$1" 2>/dev/null | perl -0777 -ne \
  'exit 1 if length($_)<500; if(/<title[^>]*>(.*?)<\/title>/si){my $t=$1; $t=~s/\s+/ /g; $t=~s/^\s+|\s+$//g; print substr($t,0,50) if $t}'; }

for raw in $NAMES; do
  n=$(printf '%s' "$raw" | tr '[:upper:]' '[:lower:]' | tr -cd 'a-z0-9-')
  [ -z "$n" ] && continue
  code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 6 "https://wordpress.org/plugins/$n/")
  hits=$(curl -s --max-time 6 "https://api.wordpress.org/plugins/info/1.2/?action=query_plugins&request\[search\]=$n&request\[per_page\]=1" \
        | python3 -c "import sys,json;print(json.load(sys.stdin)['info']['results'])" 2>/dev/null || echo '?')
  hit=""
  for h in "$n.com" "get$n.com" "try$n.com" "${n}app.com" "$n.org" "$n.io" "$n.co" "$n.app" "$n.ai" "$n.dev" "$n.so"; do
    t=$(title "$h") || true
    case "$t" in
      ""|*"is for sale"*|*"For Sale"*|*HugeDomains*|*Spaceship*|*Sedo*|*"may be for sale"*|\
      *"Parked Domain"*|*"Welcome to your new website"*|*"Just a moment"*|*"Attention Required"*|\
      *"404"*|*"Coming Soon"*|*"Hier entsteht"*|*"under construction"*|*"Store unavailable"*|\
      *"Build incomplete"*|*"Index of /"*) continue;;
      *) hit="$h -> $t"; break;;
    esac
  done
  if [ ${#n} -lt 5 ]; then printf '%-14s SHORT  under 5 chars, wp.org refused one already\n' "$raw"
  elif [ "$code" = "200" ]; then printf '%-14s TAKEN  wp.org slug already exists\n' "$raw"
  elif [ -n "$hit" ]; then printf '%-14s TAKEN  %s\n' "$raw" "$hit"
  else printf '%-14s clean  (wp.org dir matches: %s) -- now web-search it\n' "$raw" "$hits"; fi
done
