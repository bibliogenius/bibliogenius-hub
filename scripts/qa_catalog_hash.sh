#!/usr/bin/env bash
#
# QA script for ADR-027 diff-based catalog push.
#
# Spins through the hub's catalog endpoints against a throwaway library
# profile and asserts every status code + header + DB side-effect the
# ticket promises. Intended to run against the local dev stack
# (docker-compose.dev.yml); override HUB to target another env.
#
# Prerequisites:
#   - `docker compose -f docker-compose.dev.yml up -d` (from repo root)
#   - Hub reachable at $HUB (default http://localhost:8082)
#   - python3 available on host (used for tiny JSON parse + escape)
#
# Usage:
#   ./scripts/qa_catalog_hash.sh               # against local dev stack
#   HUB=https://hub-dev.bibliogenius.org ./scripts/qa_catalog_hash.sh
#
# Exits non-zero at the first failed assertion.

set -euo pipefail

HUB="${HUB:-http://localhost:8082}"
COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.dev.yml}"
NODE_ID="qa-$(date +%s)-$(openssl rand -hex 3)"

HASH_A="a1b2c3d4e5f6789012345678901234567890123456789012345678901234abcd"
HASH_B="0000000000000000000000000000000000000000000000000000000000000001"

# ── Output helpers ──────────────────────────────────────────────────

red()    { printf "\033[31m%s\033[0m\n" "$*" >&2; }
green()  { printf "\033[32m%s\033[0m\n" "$*"; }
blue()   { printf "\033[34m%s\033[0m\n" "$*"; }
yellow() { printf "\033[33m%s\033[0m\n" "$*"; }

PASSED=0
assert_eq() {
    local label=$1 expected=$2 got=$3
    if [[ "$got" == "$expected" ]]; then
        green "  [PASS] $label  (got: $got)"
        PASSED=$((PASSED + 1))
    else
        red "  [FAIL] $label  expected: $expected  got: $got"
        exit 1
    fi
}

# ── HTTP helpers ────────────────────────────────────────────────────

# Writes response body to $BODY_FILE, headers to $HDR_FILE, echoes the
# HTTP status code on stdout.
BODY_FILE=$(mktemp)
HDR_FILE=$(mktemp)
trap 'rm -f "$BODY_FILE" "$HDR_FILE"' EXIT

http() {
    curl -sS -D "$HDR_FILE" -o "$BODY_FILE" -w '%{http_code}' "$@"
}

header() {
    # $1 = header name (case-insensitive). Echoes the raw value (no CR).
    grep -i "^$1:" "$HDR_FILE" | head -1 | sed -e 's/^[^:]*: *//' | tr -d '\r\n'
}

# ── Postgres helpers ────────────────────────────────────────────────

pg_query() {
    docker compose -f "$COMPOSE_FILE" exec -T postgres \
        psql -U hub -d hub_local -t -A -c "$1" | tr -d '\r\n'
}

# ── Preflight ───────────────────────────────────────────────────────

blue "== ADR-027 QA — diff-based catalog push =="
echo "Hub:  $HUB"
echo "Node: $NODE_ID"
echo

blue "Preflight: hub reachable?"
HEALTH=$(curl -sS -o /dev/null -w '%{http_code}' "$HUB/api/health" || echo '000')
if [[ "$HEALTH" != "200" ]]; then
    red "Hub health check returned $HEALTH. Is the stack up?"
    red "  docker compose -f $COMPOSE_FILE up -d"
    exit 2
fi
green "  hub healthy"
echo

# ── Step 1: Register ────────────────────────────────────────────────

blue "Step 1: Register a throwaway library profile"
STATUS=$(http -X POST "$HUB/api/directory/profile" \
    -H 'Content-Type: application/json' \
    -d "{\"node_id\":\"$NODE_ID\",\"display_name\":\"QA Lib $NODE_ID\",\"is_listed\":true,\"requires_approval\":false}")
assert_eq "register returns 201" "201" "$STATUS"

TOKEN=$(python3 -c 'import json,sys;print(json.load(open(sys.argv[1]))["write_token"])' "$BODY_FILE")
[[ -n "$TOKEN" ]] || { red "no write_token in response"; cat "$BODY_FILE"; exit 1; }
green "  write_token obtained (${#TOKEN} chars)"
echo

# ── Step 2: First push with hash_A ──────────────────────────────────

blue "Step 2: First push with catalog_hash = hash_A"
BODY_A='{"isbn_payload":"[\"9781\",\"9782\"]","catalog_payload":"[{\"isbn\":\"9781\",\"title\":\"Book One\"}]","book_count":2,"catalog_hash":"'"$HASH_A"'"}'
STATUS=$(http -X POST "$HUB/api/directory/catalog" \
    -H "Authorization: Bearer $TOKEN" \
    -H 'Content-Type: application/json' \
    -d "$BODY_A")
assert_eq "first push returns 200" "200" "$STATUS"
assert_eq "ETag echoes hash_A" "\"$HASH_A\"" "$(header ETag)"

STORED=$(pg_query "SELECT catalog_hash FROM cached_catalogs WHERE node_id='$NODE_ID'")
assert_eq "Postgres stores hash_A" "$HASH_A" "$STORED"
echo

# ── Step 3: Duplicate push with same hash ───────────────────────────

blue "Step 3: Duplicate push with same hash_A should 304 without rewrite"
# Capture updated_at BEFORE to confirm it still bumps (TTL refresh) even on 304.
UPDATED_BEFORE=$(pg_query "SELECT extract(epoch from updated_at) FROM cached_catalogs WHERE node_id='$NODE_ID'")
sleep 1  # ensure at least 1s passes for timestamp delta

STATUS=$(http -X POST "$HUB/api/directory/catalog" \
    -H "Authorization: Bearer $TOKEN" \
    -H 'Content-Type: application/json' \
    -d "$BODY_A")
assert_eq "same hash returns 304" "304" "$STATUS"
assert_eq "ETag on 304 echoes hash_A" "\"$HASH_A\"" "$(header ETag)"

UPDATED_AFTER=$(pg_query "SELECT extract(epoch from updated_at) FROM cached_catalogs WHERE node_id='$NODE_ID'")
if [[ $(python3 -c "print(1 if float('$UPDATED_AFTER') > float('$UPDATED_BEFORE') else 0)") == "1" ]]; then
    green "  [PASS] TTL bumped on 304 (updated_at went $UPDATED_BEFORE -> $UPDATED_AFTER)"
    PASSED=$((PASSED + 1))
else
    red "  [FAIL] updated_at should increase on 304 refresh"
    exit 1
fi

# Payload must NOT have been rewritten: read it back, must still equal the original.
PAYLOAD=$(pg_query "SELECT isbn_payload FROM cached_catalogs WHERE node_id='$NODE_ID'")
assert_eq "isbn_payload unchanged" "[\"9781\",\"9782\"]" "$PAYLOAD"
echo

# ── Step 4: Push with different hash ────────────────────────────────

blue "Step 4: Push with a different hash_B → 200 + payload rewritten"
BODY_B='{"isbn_payload":"[\"9781\",\"9782\",\"9783\"]","catalog_payload":"[{\"isbn\":\"9783\",\"title\":\"Three\"}]","book_count":3,"catalog_hash":"'"$HASH_B"'"}'
STATUS=$(http -X POST "$HUB/api/directory/catalog" \
    -H "Authorization: Bearer $TOKEN" \
    -H 'Content-Type: application/json' \
    -d "$BODY_B")
assert_eq "new hash returns 200" "200" "$STATUS"
assert_eq "ETag echoes hash_B" "\"$HASH_B\"" "$(header ETag)"

STORED=$(pg_query "SELECT catalog_hash FROM cached_catalogs WHERE node_id='$NODE_ID'")
assert_eq "Postgres hash now hash_B" "$HASH_B" "$STORED"

PAYLOAD=$(pg_query "SELECT isbn_payload FROM cached_catalogs WHERE node_id='$NODE_ID'")
assert_eq "isbn_payload rewritten" "[\"9781\",\"9782\",\"9783\"]" "$PAYLOAD"
echo

# ── Step 5: Invalid hash format ─────────────────────────────────────

blue "Step 5: Invalid catalog_hash format → 400"
BAD_BODY='{"isbn_payload":"[]","book_count":0,"catalog_hash":"NOT_A_HEX_DIGEST"}'
STATUS=$(http -X POST "$HUB/api/directory/catalog" \
    -H "Authorization: Bearer $TOKEN" \
    -H 'Content-Type: application/json' \
    -d "$BAD_BODY")
assert_eq "invalid hash rejected with 400" "400" "$STATUS"
echo

# ── Step 6: GET with If-None-Match ──────────────────────────────────

blue "Step 6: GET /catalog conditional behavior"
STATUS=$(http "$HUB/api/directory/$NODE_ID/catalog")
assert_eq "GET without If-None-Match → 200" "200" "$STATUS"
assert_eq "GET emits ETag = hash_B" "\"$HASH_B\"" "$(header ETag)"

STATUS=$(http -H "If-None-Match: \"$HASH_B\"" "$HUB/api/directory/$NODE_ID/catalog")
assert_eq "GET with matching If-None-Match → 304" "304" "$STATUS"
assert_eq "ETag on 304" "\"$HASH_B\"" "$(header ETag)"

STATUS=$(http -H "If-None-Match: \"deadbeef00000000000000000000000000000000000000000000000000000000\"" \
    "$HUB/api/directory/$NODE_ID/catalog")
assert_eq "GET with non-matching If-None-Match → 200" "200" "$STATUS"

STATUS=$(http -H 'If-None-Match: *' "$HUB/api/directory/$NODE_ID/catalog")
assert_eq "GET with If-None-Match: * wildcard → 304" "304" "$STATUS"
echo

# ── Step 7: HEAD method ─────────────────────────────────────────────

blue "Step 7: HEAD /catalog is accepted (conditional read shortcut)"
STATUS=$(curl -sS -I -o /dev/null -w '%{http_code}' "$HUB/api/directory/$NODE_ID/catalog")
assert_eq "HEAD returns 200" "200" "$STATUS"
echo

# ── Step 8: Backward compat (no catalog_hash in body) ───────────────

blue "Step 8: Legacy push WITHOUT catalog_hash still works"
LEGACY_BODY='{"isbn_payload":"[\"9999\"]","catalog_payload":"[{\"isbn\":\"9999\",\"title\":\"Legacy\"}]","book_count":1}'
STATUS=$(http -X POST "$HUB/api/directory/catalog" \
    -H "Authorization: Bearer $TOKEN" \
    -H 'Content-Type: application/json' \
    -d "$LEGACY_BODY")
assert_eq "legacy push (no hash) returns 200" "200" "$STATUS"

STORED=$(pg_query "SELECT COALESCE(catalog_hash,'NULL') FROM cached_catalogs WHERE node_id='$NODE_ID'")
assert_eq "stored hash cleared to NULL when client omits field" "NULL" "$STORED"
echo

# ── Step 9: Cleanup ─────────────────────────────────────────────────

blue "Step 9: Cleanup (delete profile)"
STATUS=$(http -X DELETE "$HUB/api/directory/profile" -H "Authorization: Bearer $TOKEN")
assert_eq "delete profile returns 204" "204" "$STATUS"

EXISTS=$(pg_query "SELECT COUNT(*) FROM library_profiles WHERE node_id='$NODE_ID'")
assert_eq "profile row gone from Postgres" "0" "$EXISTS"
echo

green "✓ All $PASSED assertions passed"
