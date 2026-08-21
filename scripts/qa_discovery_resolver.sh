#!/usr/bin/env bash
#
# Post-deploy smoke test AND source-drift sentinel for the external
# discovery resolver (ADR-060, both lanes).
#
# Why this exists beyond the unit suite: the drift monitoring of ADR-060
# section 3.5 measures resolution OUTCOMES, so it cannot see a resolution
# that succeeds with an empty payload. That is exactly the shape of the
# two blocking bugs found in the volet 1 recette (Inventaire redirects,
# Wikidata 'mul' labels): 175 green unit tests, and a feature that
# resolved nothing, or resolved with no author at all. The assertions
# below therefore check the QUALITY of a real resolution against the real
# sources, which no mock can do.
#
# Side effects: a cache MISS resolves against Wikidata and Inventaire and
# writes pooled rows, which consumes the hub-wide outbound budget. A fully
# cold run costs roughly 40 calls (about 20 for the series anchor, about 20
# for the author) against a budget of 60 per minute, so two cold runs back
# to back can exhaust it and turn later checks into 'unavailable'. On a warm
# pool the run is free. Nothing is deleted afterwards: the pooled cache is
# shared by design and the rows are the same ones any reader would have
# created.
#
# Usage:
#   ./scripts/qa_discovery_resolver.sh                                   # local dev
#   HUB=https://hub-dev.bibliogenius.org ./scripts/qa_discovery_resolver.sh
#   HUB=https://hub.bibliogenius.org     ./scripts/qa_discovery_resolver.sh
#   WITH_RATE_LIMIT=1 ./scripts/qa_discovery_resolver.sh   # also burns the per-IP bucket
#
# Exits non-zero on the first failed assertion.

set -euo pipefail

HUB="${HUB:-http://localhost:8082}"
WITH_RATE_LIMIT="${WITH_RATE_LIMIT:-0}"

# Anchors chosen because they resolve today and exercise a trap each.
ANCHOR_CAMUS='9782070360024'          # L'Etranger, Gallimard: resolves to wd:Q34670
ANCHOR_FLAUBERT='9782253010692'       # Madame Bovary: a DIFFERENT author, for the homonym case
ANCHOR_SERIES='9782070541270'         # Harry Potter volume 3 (FR), the volet 1 anchor
# Checksum-valid, plausible French prefix, and unknown to the sources: they
# answer 200 with zero entities, which is the 'unknown' path the resolver
# negative-caches. Do NOT replace it with an implausible prefix: Inventaire
# rejects those with a 400, which the resolver maps to 'unavailable' and
# never caches, so the assertion below would silently stop testing what it
# claims to test.
ANCHOR_NOWHERE='9782079999997'
OMNIBUS_PLEIADE='9782072886218'       # "Oeuvres", one edition claiming 17 works
OMNIBUS_TWO_WORKS='9782070117024'     # a Camus-and-Caligula volume

pass() { printf '\033[32m✓\033[0m %s\n' "$1"; }
fail() { printf '\033[31m✗\033[0m %s\n' "$1" >&2; exit 1; }
info() { printf '\033[36m→\033[0m %s\n' "$1"; }

# The response body lands in $RESP and the status in $HTTP_STATUS. Both are
# globals on purpose: reading the body through a command substitution would
# run the call in a subshell and silently leave $HTTP_STATUS at its previous
# value, which is a green-looking script asserting the wrong request.
RESP=$(mktemp)
trap 'rm -f "$RESP"' EXIT

post() {
    # post <path> <body>
    HTTP_STATUS=$(curl -sS -o "$RESP" -w '%{http_code}' \
        -X POST "$HUB$1" -H 'Content-Type: application/json' -d "$2")
}

expect_status() {
    # expect_status <path> <body> <expected code> <label>
    local path="$1" body="$2" expected="$3" label="$4"
    post "$path" "$body"
    [ "$HTTP_STATUS" = "$expected" ] \
        || fail "$label: expected $expected, got $HTTP_STATUS"
    pass "$label -> $expected"
}

jsonq() {
    # jsonq <json> <python expression over `d`>
    #
    # The expression is EVALUATED, so it must always be a literal written in
    # this file, or a literal interpolating only constants defined above.
    # Never pass anything derived from the hub's response into it: that
    # would hand a compromised hub code execution on the operator's machine.
    python3 -c 'import sys,json
d = json.load(sys.stdin)
print(eval(sys.argv[1]))' "$2" <<<"$1"
}

info "target: $HUB"

# 1. Input validation, before any cache or source access ------------------------
expect_status /api/discovery/series '{}' 400 'series without isbns'
expect_status /api/discovery/series '{"isbns":["9782070360025"]}' 400 'series with a bad checksum'
expect_status /api/discovery/series \
    '{"isbns":["9782070360024","9780747532699","9780306406157","9780441007318"]}' 400 'series with 4 anchors'
expect_status /api/discovery/series 'not json at all' 400 'series with an invalid body'
expect_status /api/discovery/author "{\"anchor_isbns\":[\"$ANCHOR_CAMUS\"]}" 400 'author without a name'
expect_status /api/discovery/author "{\"name\":\"   \",\"anchor_isbns\":[\"$ANCHOR_CAMUS\"]}" 400 'author with a blank name'
expect_status /api/discovery/author \
    "{\"name\":\"$(python3 -c 'print("a"*257)')\",\"anchor_isbns\":[\"$ANCHOR_CAMUS\"]}" 400 'author with a 257-char name'
expect_status /api/discovery/author '{"name":"Albert Camus"}' 400 'author without anchors'
expect_status /api/discovery/author \
    "{\"name\":\"Albert Camus\",\"anchor_isbns\":[\"$ANCHOR_CAMUS\"],\"langs\":[\"fr\",\"en\",\"es\",\"de\",\"it\",\"pt\",\"tr\",\"bg\",\"ja\"]}" \
    400 'author with 9 language codes'
expect_status /api/discovery/author \
    "{\"name\":\"$(python3 -c 'print("a"*200)')\",\"anchor_isbns\":[\"$ANCHOR_CAMUS\"],\"pad\":\"$(python3 -c 'print("x"*4200)')\"}" \
    413 'body over the 4096-byte cap'

# 2. Series lane still answers its envelope --------------------------------------
post /api/discovery/series "{\"isbns\":[\"$ANCHOR_SERIES\"],\"name\":\"Harry Potter\",\"langs\":[\"fr\"]}"
body=$(cat "$RESP")
[ "$HTTP_STATUS" = "200" ] || fail "series lookup: expected 200, got $HTTP_STATUS"
status=$(jsonq "$body" 'd["status"]')
case "$status" in
    resolved|ambiguous|unknown|unavailable) pass "series lookup -> $status" ;;
    *) fail "series lookup: unexpected status '$status'" ;;
esac

# 3. Author lane, nominal, with PAYLOAD QUALITY assertions -----------------------
cold_start=$(date +%s)
post /api/discovery/author \
    "{\"name\":\"Albert Camus\",\"anchor_isbns\":[\"$ANCHOR_CAMUS\"],\"langs\":[\"fr\",\"en\"]}"
body=$(cat "$RESP")
cold_elapsed=$(( $(date +%s) - cold_start ))
[ "$HTTP_STATUS" = "200" ] || fail "author lookup: expected 200, got $HTTP_STATUS"
[ "$(jsonq "$body" 'd["status"]')" = "resolved" ] \
    || fail "author lookup: expected resolved, got $(jsonq "$body" 'd["status"]') (source drift or budget exhausted)"
pass "author lookup -> resolved in ${cold_elapsed}s"

[ "$(jsonq "$body" 'd["author"]["label"]')" = "Albert Camus" ] \
    || fail "author label drifted: $(jsonq "$body" 'd["author"]["label"]')"
works=$(jsonq "$body" 'len(d["author"]["works"])')
[ "$works" -ge 5 ] || fail "bibliography too thin: $works works"
pass "author entity: label verified, $works works"

# The 'mul' trap: a resolution that succeeds with empty authors disables
# half the client precision membrane, and the drift sentinel cannot see it.
[ "$(jsonq "$body" 'sum(1 for w in d["author"]["works"][:5] if w["authors"])')" = "5" ] \
    || fail "works come back without authors: the title+author membrane is dead"
[ "$(jsonq "$body" 'sum(1 for w in d["author"]["works"][:5] if (w["title"] or "").strip())')" = "5" ] \
    || fail "works come back without a title"
[ "$(jsonq "$body" 'sum(1 for w in d["author"]["works"] if w.get("titles"))')" -ge 1 ] \
    || fail "no work carries alternate titles: translations will slip through the membrane"
[ "$(jsonq "$body" 'sum(len(w["editions"]) for w in d["author"]["works"])')" -ge 1 ] \
    || fail "no edition attached to any work: the edition stage is broken"
pass "payload quality: authors, titles, alternates and editions all present"

# The omnibus trap: a box set offered as one work makes the client import
# the complete works under a single novel's title.
for isbn in "$OMNIBUS_PLEIADE" "$OMNIBUS_TWO_WORKS"; do
    [ "$(jsonq "$body" "sum(1 for w in d['author']['works'] for e in w['editions'] if e['isbn'] == '$isbn')")" = "0" ] \
        || fail "omnibus $isbn offered as an edition of a single work"
done
pass "omnibus editions excluded"

# 4. Pooling: the same question again must be served from the cache -------------
warm_start=$(date +%s)
post /api/discovery/author \
    "{\"name\":\"Albert Camus\",\"anchor_isbns\":[\"$ANCHOR_CAMUS\"],\"langs\":[\"fr\",\"en\"]}"
warm_elapsed=$(( $(date +%s) - warm_start ))
[ "$warm_elapsed" -le 3 ] || fail "warm lookup took ${warm_elapsed}s: the pooled cache is not serving"
pass "pooled lookup served in ${warm_elapsed}s (cold was ${cold_elapsed}s)"

# 5. Reader-language titles ------------------------------------------------------
# Compared against each other rather than against a hardcoded label: the
# assertion survives a legitimate label edit but fails if the localized
# title path stops working.
post /api/discovery/author "{\"name\":\"Albert Camus\",\"anchor_isbns\":[\"$ANCHOR_CAMUS\"],\"langs\":[\"fr\"]}"
fr=$(cat "$RESP")
post /api/discovery/author "{\"name\":\"Albert Camus\",\"anchor_isbns\":[\"$ANCHOR_CAMUS\"],\"langs\":[\"en\"]}"
en=$(cat "$RESP")
fr_titles=$(jsonq "$fr" '[w["title"] for w in d["author"]["works"]]')
en_titles=$(jsonq "$en" '[w["title"] for w in d["author"]["works"]]')
[ "$fr_titles" != "$en_titles" ] \
    || fail "titles identical in fr and en: the reader-language title path is dead"
pass "titles follow the reader's language"

# 6. Homonymy: an anchor that resolves to someone else shows nothing -------------
post /api/discovery/author \
    "{\"name\":\"Albert Camus\",\"anchor_isbns\":[\"$ANCHOR_FLAUBERT\"],\"langs\":[\"fr\"]}"
body=$(cat "$RESP")
[ "$(jsonq "$body" 'd["status"]')" = "ambiguous" ] \
    || fail "homonym guard: expected ambiguous, got $(jsonq "$body" 'd["status"]')"
[ "$(jsonq "$body" '"author" in d')" = "False" ] \
    || fail "homonym guard: an author payload leaked into an ambiguous answer"
pass "homonym -> ambiguous, nothing shown"

# 7. An anchor the sources do not know converges on "show nothing" ---------------
post /api/discovery/author \
    "{\"name\":\"Nobody At All\",\"anchor_isbns\":[\"$ANCHOR_NOWHERE\"],\"langs\":[\"fr\"]}"
body=$(cat "$RESP")
status=$(jsonq "$body" 'd["status"]')
[ "$status" = "unknown" ] \
    || fail "unknown anchor: expected unknown (negative-cached), got $status"
[ "$(jsonq "$body" '"author" in d')" = "False" ] || fail "non-resolved answer carries an author payload"
pass "unknown anchor -> unknown, nothing shown"

# 8. Per-IP limiter, opt-in: it burns the bucket for a minute --------------------
if [ "$WITH_RATE_LIMIT" = "1" ]; then
    info "burning the discovery_anon bucket (30/min)..."
    code=''
    for _ in $(seq 1 31); do
        code=$(curl -sS -o /dev/null -w '%{http_code}' -X POST "$HUB/api/discovery/author" \
            -H 'Content-Type: application/json' -d '{"name":"x"}')
    done
    [ "$code" = "429" ] || fail "expected 429 after 31 requests, got $code"
    pass "per-IP limiter -> 429"
else
    info "per-IP limiter not exercised (WITH_RATE_LIMIT=1 to include it)"
fi

printf '\033[32m\nAll discovery resolver checks passed on %s\033[0m\n' "$HUB"
