#!/usr/bin/env bash
#
# Smoke test for the deposit-404 aggregated counter (Version20260422120000).
#
# Fires three deposit attempts at a random, never-created mailbox UUID and
# asserts that:
#   - the hub replies 404 every time (no 500)
#   - exactly one row lands in deposit_404_log for that UUID with count=3
#   - no row is written to hub_events with message 'deposit to non-existent
#     mailbox' for that UUID (the whole point of the change)
#
# Usage:
#   ./scripts/qa_deposit_404_log.sh                              # local dev stack
#   HUB=https://hub-dev.bibliogenius.org ./scripts/qa_deposit_404_log.sh
#   HUB=https://hub.bibliogenius.org    ./scripts/qa_deposit_404_log.sh
#
# DB assertions run automatically when HUB targets localhost (they use
# `docker compose -f docker-compose.dev.yml exec postgres psql`). For remote
# targets the DB checks are skipped with a notice, since the script has no
# access to prod Postgres.
#
# Exits non-zero on the first failed assertion. Idempotent: each run uses a
# fresh UUID, so re-running does not pollute previous state.

set -euo pipefail

HUB="${HUB:-http://localhost:8082}"
COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.dev.yml}"

pass() { printf '\033[32m✓\033[0m %s\n' "$1"; }
fail() { printf '\033[31m✗\033[0m %s\n' "$1" >&2; exit 1; }
info() { printf '\033[36m→\033[0m %s\n' "$1"; }
skip() { printf '\033[33m⚠\033[0m %s\n' "$1"; }

# -- Helpers -------------------------------------------------------------------

random_uuid() {
    # RFC4122 v4 via /dev/urandom, no Python/uuidgen needed.
    local hex
    hex=$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')
    printf '%s-%s-4%s-%s%s-%s\n' \
        "${hex:0:8}" "${hex:8:4}" "${hex:13:3}" \
        "$(printf '%x' $(( 0x${hex:16:1} & 0x3 | 0x8 )))" "${hex:17:3}" \
        "${hex:20:12}"
}

psql_query() {
    docker compose -f "$COMPOSE_FILE" exec -T postgres \
        psql -U hub -d hub_local -tA -c "$1"
}

is_local_target() {
    [[ "$HUB" == http://localhost:* || "$HUB" == http://127.0.0.1:* ]]
}

# -- Preconditions -------------------------------------------------------------

info "target: $HUB"

if is_local_target; then
    if ! docker compose -f "$COMPOSE_FILE" ps --services --filter status=running 2>/dev/null | grep -q '^postgres$'; then
        fail "postgres container is not running — start it with: docker compose -f $COMPOSE_FILE up -d"
    fi
fi

# -- Test plan -----------------------------------------------------------------

UUID="$(random_uuid)"
BOGUS_TOKEN="$(head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n')"
info "ephemeral UUID: $UUID"

# 1. Three 404s for the same UUID -----------------------------------------------
for attempt in 1 2 3; do
    status=$(curl -sS -o /tmp/qa_dep404.out -w '%{http_code}' \
        -X POST "$HUB/api/relay/mailbox/$UUID/messages" \
        -H "Authorization: Bearer $BOGUS_TOKEN" \
        -H 'Content-Type: application/octet-stream' \
        --data-binary 'smoke-test-blob')
    [ "$status" = "404" ] || fail "attempt $attempt: expected 404, got $status — body=$(cat /tmp/qa_dep404.out)"
done
pass "3x POST to missing mailbox → 404 each time"

# 2. DB-level assertions (local dev only) ---------------------------------------

if ! is_local_target; then
    skip "remote target — skipping DB asserts. Run manually on the VPS:"
    skip "  psql -c \"SELECT * FROM deposit_404_log WHERE mailbox_uuid = '$UUID'\""
    skip "  psql -c \"SELECT * FROM hub_events WHERE context::text LIKE '%$UUID%'\""
    printf '\n\033[32mHTTP-only smoke OK on %s\033[0m\n' "$HUB"
    exit 0
fi

# 2a. Exactly one row in deposit_404_log for this UUID, with count=3
row_count=$(psql_query "SELECT COUNT(*) FROM deposit_404_log WHERE mailbox_uuid = '$UUID'")
[ "$row_count" = "1" ] || fail "expected 1 row in deposit_404_log, got $row_count"
pass "deposit_404_log has exactly 1 row for the test UUID (bucketed to current hour)"

hit_count=$(psql_query "SELECT count FROM deposit_404_log WHERE mailbox_uuid = '$UUID'")
[ "$hit_count" = "3" ] || fail "expected count=3, got $hit_count"
pass "deposit_404_log.count = 3 (upsert incremented correctly)"

# 2b. Sanity: first_seen <= last_seen, both within the last 60s.
timestamps_ok=$(psql_query "
    SELECT first_seen <= last_seen
       AND last_seen >= NOW() - INTERVAL '60 seconds'
      FROM deposit_404_log
     WHERE mailbox_uuid = '$UUID'")
[ "$timestamps_ok" = "t" ] || fail "first_seen/last_seen timestamps look wrong (got: $timestamps_ok)"
pass "first_seen/last_seen both recent and ordered"

# 2c. No rows in hub_events for this UUID — the whole point of the change.
hub_events_leak=$(psql_query "
    SELECT COUNT(*) FROM hub_events
     WHERE message = 'deposit to non-existent mailbox'
       AND context::text LIKE '%$UUID%'")
[ "$hub_events_leak" = "0" ] || fail "hub_events still receives deposit-404 rows for this UUID (count=$hub_events_leak)"
pass "hub_events received 0 deposit-404 rows for this UUID (flood stopped)"

# 2d. Dashboard counter (SUM(count) since -24h) is at least 3.
dashboard_sum=$(psql_query "SELECT COALESCE(SUM(count), 0) FROM deposit_404_log WHERE hour_bucket >= NOW() - INTERVAL '24 hours'")
[ "$dashboard_sum" -ge 3 ] || fail "24h dashboard tile would show $dashboard_sum, expected >= 3"
pass "dashboard SUM(count) over 24h = $dashboard_sum (tile stays accurate)"

# -- Cleanup --------------------------------------------------------------------

psql_query "DELETE FROM deposit_404_log WHERE mailbox_uuid = '$UUID'" >/dev/null
pass "cleanup: ephemeral row removed"

printf '\n\033[32mDeposit 404 aggregated counter OK on %s\033[0m\n' "$HUB"
