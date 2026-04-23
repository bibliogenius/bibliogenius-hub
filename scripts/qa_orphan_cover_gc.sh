#!/usr/bin/env bash
#
# QA script for ADR-033 catalog-driven GC for orphan covers.
#
# Reproduces the ticket scenario against a throwaway library profile
# and verifies that orphan covers are removed from hub storage when
# the corresponding book is no longer in the pushed catalog. Also
# exercises the 50% threshold guard and the empty-catalog guard, and
# runs the nightly `app:db:prune` command to validate the cron path.
#
# Prerequisites:
#   - `docker compose -f docker-compose.dev.yml up -d` (from hub repo root)
#   - Hub reachable at $HUB (default http://localhost:8082)
#   - python3 on host (tiny JSON parse)
#
# Usage:
#   ./scripts/qa_orphan_cover_gc.sh                         # local dev stack
#   HUB=https://hub-dev.bibliogenius.org ./scripts/qa_orphan_cover_gc.sh
#
# Exits non-zero at the first failed assertion.

set -euo pipefail

HUB="${HUB:-http://localhost:8082}"
COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.dev.yml}"
NODE_ID="qa-gc-$(date +%s)-$(openssl rand -hex 3)"

# Postgres assertions only work against the local dev stack. When
# HUB points to a remote (hub-dev, hub-prod), we run HTTP-only checks
# and skip the event-log counts. The functional assertions on GET
# status codes are enough to prove the GC ran correctly.
LOCAL_PG=0
if [[ "$HUB" == http://localhost:* || "$HUB" == http://127.0.0.1:* ]]; then
    LOCAL_PG=1
fi

# Minimal valid JPEG: SOI + EOI markers. The hub writes the raw bytes
# as-is and does not validate JPEG structure beyond the declared MIME.
JPEG_BYTES="\xff\xd8\xff\xd9"

# ── Output helpers ──────────────────────────────────────────────────

red()    { printf "\033[31m%s\033[0m\n" "$*" >&2; }
green()  { printf "\033[32m%s\033[0m\n" "$*"; }
blue()   { printf "\033[34m%s\033[0m\n" "$*"; }

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

BODY_FILE=$(mktemp)
HDR_FILE=$(mktemp)
JPEG_FILE=$(mktemp)
printf "$JPEG_BYTES" > "$JPEG_FILE"
trap 'rm -f "$BODY_FILE" "$HDR_FILE" "$JPEG_FILE"' EXIT

http() {
    curl -sS -D "$HDR_FILE" -o "$BODY_FILE" -w '%{http_code}' "$@"
}

cover_status() {
    # $1 = book_id. Echoes the HTTP code for GET /{nodeId}/covers/{bookId}
    curl -sS -o /dev/null -w '%{http_code}' \
        "$HUB/api/directory/$NODE_ID/covers/$1"
}

upload_cover() {
    # $1 = book_id. Uses the throwaway $TOKEN set after registration.
    http -X POST "$HUB/api/directory/$NODE_ID/covers/$1" \
        -H "Authorization: Bearer $TOKEN" \
        -H 'Content-Type: image/jpeg' \
        --data-binary "@$JPEG_FILE" >/dev/null
}

# ── Postgres helpers ────────────────────────────────────────────────

pg_query() {
    docker compose -f "$COMPOSE_FILE" exec -T postgres \
        psql -U hub -d hub_local -t -A -c "$1" | tr -d '\r\n'
}

# Count hub_events rows matching channel + message for our node.
count_gc_events() {
    # $1 = message. Matches on the 12-char node_id prefix that the
    # service logs (see logCoverGcEvent in DirectoryService).
    local prefix="${NODE_ID:0:12}"
    pg_query "SELECT COUNT(*) FROM hub_events
              WHERE channel='catalog_gc'
              AND message='$1'
              AND context::text LIKE '%$prefix%'"
}

# ── Preflight ───────────────────────────────────────────────────────

blue "== ADR-033 QA — catalog-driven orphan cover GC =="
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
    -d "{\"node_id\":\"$NODE_ID\",\"display_name\":\"QA GC $NODE_ID\",\"is_listed\":true,\"requires_approval\":false}")
assert_eq "register returns 201" "201" "$STATUS"

TOKEN=$(python3 -c 'import json,sys;print(json.load(open(sys.argv[1]))["write_token"])' "$BODY_FILE")
[[ -n "$TOKEN" ]] || { red "no write_token in response"; cat "$BODY_FILE"; exit 1; }
green "  write_token obtained (${#TOKEN} chars)"
echo

# ── Step 2: Upload 4 covers ─────────────────────────────────────────

blue "Step 2: Upload covers for book_ids 1, 2, 3, 99"
for id in 1 2 3 99; do
    upload_cover "$id"
done
for id in 1 2 3 99; do
    assert_eq "cover $id is GET 200 pre-GC" "200" "$(cover_status $id)"
done
echo

# ── Step 3: Push catalog without book_id 99 → orphan must be deleted ─

blue "Step 3: Push catalog with book_ids [1,2,3] only (ratio 1/4 = 25%, below 50% guard)"
CATALOG='[{"isbn":"9781","book_id":1,"title":"One"},{"isbn":"9782","book_id":2,"title":"Two"},{"isbn":"9783","book_id":3,"title":"Three"}]'
# Escape the JSON for embedding in the body.
CATALOG_ESC=$(python3 -c 'import json,sys;print(json.dumps(sys.argv[1]))' "$CATALOG")
BODY='{"isbn_payload":"[\"9781\",\"9782\",\"9783\"]","catalog_payload":'"$CATALOG_ESC"',"book_count":3}'
STATUS=$(http -X POST "$HUB/api/directory/catalog" \
    -H "Authorization: Bearer $TOKEN" \
    -H 'Content-Type: application/json' \
    -d "$BODY")
assert_eq "catalog push returns 200" "200" "$STATUS"

# The cover for book 99 must be gone.
assert_eq "cover 99 is GET 404 post-GC" "404" "$(cover_status 99)"
# Covers for books still in the catalog must remain.
for id in 1 2 3; do
    assert_eq "cover $id still GET 200 post-GC" "200" "$(cover_status $id)"
done

if (( LOCAL_PG )); then
    assert_eq "hub_events has 1 'deleted' entry for this node" "1" "$(count_gc_events 'deleted')"
fi
echo

# ── Step 4: Threshold guard blocks mass deletion ────────────────────

blue "Step 4: Re-upload many covers then push a tiny catalog (ratio > 50% → blocked)"
# Disk currently has covers 1,2,3. Add 10, 11, 12, 13, 14 → 8 files.
for id in 10 11 12 13 14; do
    upload_cover "$id"
done

# Catalog now references only book_id=1. Orphans = 7 out of 8 on disk
# = 87.5% → exceeds 50% threshold, all covers must be preserved.
SMALL_CATALOG='[{"isbn":"9781","book_id":1,"title":"One"}]'
SMALL_ESC=$(python3 -c 'import json,sys;print(json.dumps(sys.argv[1]))' "$SMALL_CATALOG")
BODY='{"isbn_payload":"[\"9781\"]","catalog_payload":'"$SMALL_ESC"',"book_count":1}'
STATUS=$(http -X POST "$HUB/api/directory/catalog" \
    -H "Authorization: Bearer $TOKEN" \
    -H 'Content-Type: application/json' \
    -d "$BODY")
assert_eq "catalog push returns 200 (catalog itself is accepted)" "200" "$STATUS"

# Every on-disk cover must still exist: threshold guard refused the sweep.
for id in 1 2 3 10 11 12 13 14; do
    assert_eq "cover $id preserved by threshold guard" "200" "$(cover_status $id)"
done

if (( LOCAL_PG )); then
    assert_eq "hub_events has 1 'skipped_threshold' entry" "1" "$(count_gc_events 'skipped_threshold')"
fi
echo

# ── Step 5: Empty catalog is treated as suspicious ──────────────────

blue "Step 5: Push catalog_payload = '[]' → skipped (empty catalog guard)"
BODY='{"isbn_payload":"[]","catalog_payload":"[]","book_count":0}'
STATUS=$(http -X POST "$HUB/api/directory/catalog" \
    -H "Authorization: Bearer $TOKEN" \
    -H 'Content-Type: application/json' \
    -d "$BODY")
assert_eq "empty catalog push returns 200" "200" "$STATUS"

# Covers on disk must be untouched.
for id in 1 2 3 10 11 12 13 14; do
    assert_eq "cover $id preserved on empty catalog" "200" "$(cover_status $id)"
done
if (( LOCAL_PG )); then
    assert_eq "hub_events has 1 'skipped_empty_catalog' entry" "1" "$(count_gc_events 'skipped_empty_catalog')"
fi
echo

# ── Step 6: Cron path (Option 3) ────────────────────────────────────

blue "Step 6: Restore a sane catalog, then run app:db:prune (Option 3)"
# Push a catalog that references books 1, 10, 11, 12, 13, 14 (6 of 8 on disk).
# Orphans would be 2,3 → 2/8 = 25% < 50% → allowed. But first we want
# the orphan_covers step of PruneCommand to do the job, so we push a
# catalog that reconciles cleanly with disk except for a couple
# orphans that the sweep can clean up.
LARGE_CATALOG='[{"isbn":"9781","book_id":1,"title":"One"},{"isbn":"9810","book_id":10,"title":"Ten"},{"isbn":"9811","book_id":11,"title":"Eleven"},{"isbn":"9812","book_id":12,"title":"Twelve"},{"isbn":"9813","book_id":13,"title":"Thirteen"},{"isbn":"9814","book_id":14,"title":"Fourteen"}]'
LARGE_ESC=$(python3 -c 'import json,sys;print(json.dumps(sys.argv[1]))' "$LARGE_CATALOG")
BODY='{"isbn_payload":"[\"9781\",\"9810\",\"9811\",\"9812\",\"9813\",\"9814\"]","catalog_payload":'"$LARGE_ESC"',"book_count":6}'
STATUS=$(http -X POST "$HUB/api/directory/catalog" \
    -H "Authorization: Bearer $TOKEN" \
    -H 'Content-Type: application/json' \
    -d "$BODY")
assert_eq "reconciliation push returns 200" "200" "$STATUS"

# The sync-path GC on this push already deletes 2 and 3 (ratio 2/8 = 25% OK),
# so by here disk should be 1,10,11,12,13,14. Verify.
for id in 2 3; do
    assert_eq "cover $id deleted by sync GC" "404" "$(cover_status $id)"
done
for id in 1 10 11 12 13 14; do
    assert_eq "cover $id preserved by sync GC" "200" "$(cover_status $id)"
done

if (( LOCAL_PG )); then
    # Option 3 cron path: requires local docker compose to invoke the
    # console command. Remote hubs are exercised indirectly via the
    # push-driven GC (Option 1) already covered in earlier steps.
    upload_cover "777"
    assert_eq "cover 777 uploaded (re-orphan)" "200" "$(cover_status 777)"

    blue "  Running app:db:prune inside hub container..."
    PRUNE_OUT=$(docker compose -f "$COMPOSE_FILE" exec -T hub php bin/console app:db:prune 2>&1)
    echo "$PRUNE_OUT" | grep -E "orphan_covers" || {
        red "prune output did not contain orphan_covers line"
        echo "$PRUNE_OUT"
        exit 1
    }
    green "  [PASS] app:db:prune ran and reported orphan_covers step"
    PASSED=$((PASSED + 1))

    sleep 1
    assert_eq "cover 777 deleted by cron GC" "404" "$(cover_status 777)"
else
    blue "  (skipped: remote hub, Option 3 cron path requires local container access)"
fi
echo

# ── Step 7: Cleanup ─────────────────────────────────────────────────

blue "Step 7: Cleanup (delete profile)"
STATUS=$(http -X DELETE "$HUB/api/directory/profile" -H "Authorization: Bearer $TOKEN")
assert_eq "delete profile returns 204" "204" "$STATUS"

if (( LOCAL_PG )); then
    EXISTS=$(pg_query "SELECT COUNT(*) FROM library_profiles WHERE node_id='$NODE_ID'")
    assert_eq "profile row gone from Postgres" "0" "$EXISTS"
fi
echo

green "✓ All $PASSED assertions passed"
