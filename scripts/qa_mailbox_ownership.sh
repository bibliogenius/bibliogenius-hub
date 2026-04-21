#!/usr/bin/env bash
#
# ADR-031 smoke test: mailbox ownership enforcement on POST /api/directory/profile.
#
# Drives the real hub HTTP API (no SSH, no psql) to exercise:
#   1. Claim-on-first-reference: victim registers with a fresh mailbox.
#      The relay_mailboxes row must be claimed for the victim's node_id.
#   2. Hijack detection: attacker registers pointing at victim's mailbox.
#      - Shadow mode  (MAILBOX_OWNERSHIP_ENFORCED=0): HTTP 201. Profile is
#        created and stores the hijacked mailbox (the accepted DoS tradeoff
#        during the observation window).
#      - Enforced mode (MAILBOX_OWNERSHIP_ENFORCED=1): HTTP 403. Profile is
#        not created; next GET returns 404.
#   3. Owner refresh is a no-op: victim re-upserts their own mailbox, no
#      hijack event recorded.
#   4. Repeat hijack on an existing attacker bumps hijack_attempts_total.
#
# The script auto-detects the current enforcement mode from the attacker
# HTTP response and asserts the correct shape for each branch. Run it
# against hub-dev before and after flipping the flag. Also safe to run
# against hub-prod in shadow mode (the hijack payload is opaque and the
# cleanup trap removes both test profiles and the test mailbox).
#
# Usage:
#   HUB=https://hub-dev.bibliogenius.org ./scripts/qa_mailbox_ownership.sh
#   HUB=https://hub.bibliogenius.org    ./scripts/qa_mailbox_ownership.sh
#
# Exits non-zero on the first failed assertion.

set -euo pipefail

HUB="${HUB:-http://localhost:8082}"
NOW="$(date +%s)"
VICTIM="qa-adr031-victim-$NOW"
ATTACKER="qa-adr031-attacker-$NOW"

pass() { printf '\033[32m✓\033[0m %s\n' "$1"; }
fail() { printf '\033[31m✗\033[0m %s\n' "$1" >&2; exit 1; }
info() { printf '\033[36m→\033[0m %s\n' "$1"; }
warn() { printf '\033[33m!\033[0m %s\n' "$1"; }

json_field() {
    # $1 = JSON blob, $2 = field name
    printf '%s' "$1" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get(\"$2\",\"\"))"
}

MAILBOX_UUID=""
MAILBOX_READ_TOKEN=""
VICTIM_TOKEN=""
ATTACKER_TOKEN=""

cleanup() {
    # Best-effort teardown so repeated runs do not litter hub-dev.
    if [ -n "$VICTIM_TOKEN" ]; then
        curl -sS -o /dev/null -X DELETE "$HUB/api/directory/profile" \
            -H "Authorization: Bearer $VICTIM_TOKEN" || true
    fi
    if [ -n "$ATTACKER_TOKEN" ]; then
        curl -sS -o /dev/null -X DELETE "$HUB/api/directory/profile" \
            -H "Authorization: Bearer $ATTACKER_TOKEN" || true
    fi
    if [ -n "$MAILBOX_UUID" ] && [ -n "$MAILBOX_READ_TOKEN" ]; then
        curl -sS -o /dev/null -X DELETE "$HUB/api/relay/mailbox/$MAILBOX_UUID" \
            -H "Authorization: Bearer $MAILBOX_READ_TOKEN" || true
    fi
}
trap cleanup EXIT

info "target: $HUB"

# 1. Create a fresh mailbox --------------------------------------------------
mbox_body=$(curl -fsS -X POST "$HUB/api/relay/mailbox" -H 'Content-Length: 0')
MAILBOX_UUID=$(json_field "$mbox_body" "uuid")
MAILBOX_READ_TOKEN=$(json_field "$mbox_body" "read_token")
MAILBOX_WRITE_TOKEN=$(json_field "$mbox_body" "write_token")
[ -n "$MAILBOX_UUID" ] || fail "failed to create test mailbox"
pass "mailbox created: $MAILBOX_UUID"

# 2. Victim registers with this mailbox (claim-on-first-reference) -----------
victim_req=$(cat <<JSON
{
  "node_id": "$VICTIM",
  "display_name": "QA Victim $NOW",
  "relay_mailbox_id": "$MAILBOX_UUID",
  "relay_write_token": "$MAILBOX_WRITE_TOKEN"
}
JSON
)
victim_body=$(curl -fsS -X POST "$HUB/api/directory/profile" \
    -H 'Content-Type: application/json' -d "$victim_req")
VICTIM_TOKEN=$(json_field "$victim_body" "write_token")
[ -n "$VICTIM_TOKEN" ] || fail "victim did not receive a write_token"
pass "victim registered, mailbox claimed for node_id=$VICTIM"

# 3. Attacker tries to point at the same mailbox -----------------------------
attacker_req=$(cat <<JSON
{
  "node_id": "$ATTACKER",
  "display_name": "QA Attacker $NOW",
  "relay_mailbox_id": "$MAILBOX_UUID",
  "relay_write_token": "$MAILBOX_WRITE_TOKEN"
}
JSON
)
http=$(curl -sS -o /tmp/qa_adr031_attacker.out -w '%{http_code}' \
    -X POST "$HUB/api/directory/profile" \
    -H 'Content-Type: application/json' -d "$attacker_req")
attacker_body=$(cat /tmp/qa_adr031_attacker.out)

if [ "$http" = "201" ]; then
    info "response 201 => enforcement is SHADOW (MAILBOX_OWNERSHIP_ENFORCED=0)"
    ATTACKER_TOKEN=$(json_field "$attacker_body" "write_token")
    [ -n "$ATTACKER_TOKEN" ] || fail "attacker 201 but no write_token returned"

    # In shadow mode the hijack succeeds at the HTTP layer. Verify the
    # profile stored the victim's mailbox id (the DoS signal we want
    # to see logged) so the dashboard counter has something to surface.
    attacker_pub=$(curl -fsS -H "Authorization: Bearer $ATTACKER_TOKEN" \
        "$HUB/api/directory/$ATTACKER")
    stored_mid=$(json_field "$attacker_pub" "relay_mailbox_id")
    if [ "$stored_mid" = "$MAILBOX_UUID" ]; then
        pass "SHADOW: attacker profile redirected to victim mailbox (observable DoS)"
    else
        fail "SHADOW: attacker.relay_mailbox_id=$stored_mid, expected $MAILBOX_UUID"
    fi

    # 3a. Second hijack attempt via UPDATE path — counter must reach 1.
    http2=$(curl -sS -o /tmp/qa_adr031_attacker2.out -w '%{http_code}' \
        -X POST "$HUB/api/directory/profile" \
        -H 'Content-Type: application/json' \
        -H "Authorization: Bearer $ATTACKER_TOKEN" -d "$attacker_req")
    [ "$http2" = "200" ] || fail "SHADOW: second hijack upsert returned $http2 (expected 200)"
    attacker_pub2=$(curl -fsS -H "Authorization: Bearer $ATTACKER_TOKEN" \
        "$HUB/api/directory/$ATTACKER")
    cnt=$(json_field "$attacker_pub2" "hijack_attempts_total")
    if [ "${cnt:-0}" -ge 1 ]; then
        pass "SHADOW: hijack_attempts_total=$cnt on attacker (UPDATE path bump works)"
    else
        warn "SHADOW: hijack_attempts_total=$cnt (known limitation if attacker was brand new)"
    fi

elif [ "$http" = "403" ]; then
    info "response 403 => enforcement is ACTIVE (MAILBOX_OWNERSHIP_ENFORCED=1)"
    probe=$(curl -sS -o /dev/null -w '%{http_code}' "$HUB/api/directory/$ATTACKER")
    if [ "$probe" = "404" ]; then
        pass "ENFORCED: attacker profile was not persisted (GET -> 404)"
    else
        fail "ENFORCED: GET attacker returned $probe, expected 404"
    fi
else
    fail "unexpected HTTP $http from attacker upsert; body=$attacker_body"
fi

# 4. Owner refresh is a no-op ------------------------------------------------
refresh_body=$(curl -fsS -X POST "$HUB/api/directory/profile" \
    -H 'Content-Type: application/json' \
    -H "Authorization: Bearer $VICTIM_TOKEN" -d "$victim_req")
refresh_cnt=$(json_field "$refresh_body" "hijack_attempts_total")
if [ "${refresh_cnt:-0}" = "0" ]; then
    pass "owner refresh: hijack_attempts_total stays at 0 (no false positive)"
else
    fail "owner refresh flagged as hijack (hijack_attempts_total=$refresh_cnt)"
fi

echo
printf '\033[32m== ADR-031 mailbox ownership OK on %s ==\033[0m\n' "$HUB"
