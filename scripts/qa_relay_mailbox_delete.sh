#!/usr/bin/env bash
#
# Smoke test for RelayController::deleteMailbox.
#
# Covers the prod 500 regression where the controller called a
# non-existent deleteByUuid() on the repository. Creates an ephemeral
# mailbox, deletes it with the read_token, and asserts the hub returns
# 200 + the expected body. Safe to run against prod: the mailbox is
# created and torn down inside the same script, touches no peers.
#
# Usage:
#   ./scripts/qa_relay_mailbox_delete.sh                              # local dev
#   HUB=https://hub-dev.bibliogenius.org ./scripts/qa_relay_mailbox_delete.sh
#   HUB=https://hub.bibliogenius.org    ./scripts/qa_relay_mailbox_delete.sh
#
# Exits non-zero on the first failed assertion.

set -euo pipefail

HUB="${HUB:-http://localhost:8082}"

pass() { printf '\033[32m✓\033[0m %s\n' "$1"; }
fail() { printf '\033[31m✗\033[0m %s\n' "$1" >&2; exit 1; }
info() { printf '\033[36m→\033[0m %s\n' "$1"; }

require_json() {
    local value="$1" label="$2"
    [ -n "$value" ] && [ "$value" != "null" ] || fail "missing field: $label"
}

info "target: $HUB"

# 1. Create mailbox -------------------------------------------------------------
create_body=$(curl -sS -X POST "$HUB/api/relay/mailbox" -H 'Content-Length: 0')
uuid=$(printf '%s' "$create_body" | python3 -c 'import sys,json; print(json.load(sys.stdin).get("uuid",""))')
read_token=$(printf '%s' "$create_body" | python3 -c 'import sys,json; print(json.load(sys.stdin).get("read_token",""))')
write_token=$(printf '%s' "$create_body" | python3 -c 'import sys,json; print(json.load(sys.stdin).get("write_token",""))')
require_json "$uuid" "uuid"
require_json "$read_token" "read_token"
require_json "$write_token" "write_token"
pass "POST /api/relay/mailbox → uuid=$uuid"

# 2. Delete mailbox with valid read_token — this is the fix under test ----------
http_status=$(curl -sS -o /tmp/qa_delete.out -w '%{http_code}' \
    -X DELETE "$HUB/api/relay/mailbox/$uuid" \
    -H "Authorization: Bearer $read_token")
body=$(cat /tmp/qa_delete.out)

[ "$http_status" = "200" ] || fail "DELETE expected 200, got $http_status — body=$body"
echo "$body" | grep -q '"message"' || fail "DELETE body missing message field — body=$body"
pass "DELETE /api/relay/mailbox/{uuid} → 200 message=Mailbox deleted"

# 3. Follow-up GET must confirm the mailbox is gone ----------------------------
follow_status=$(curl -sS -o /dev/null -w '%{http_code}' \
    "$HUB/api/relay/mailbox/$uuid/verify" \
    -H "Authorization: Bearer $read_token")
[ "$follow_status" = "404" ] || fail "verify after delete expected 404, got $follow_status"
pass "verify after delete → 404 (mailbox actually removed)"

# 4. Re-delete with the same token must 404 (mailbox no longer exists) ----------
redelete_status=$(curl -sS -o /dev/null -w '%{http_code}' \
    -X DELETE "$HUB/api/relay/mailbox/$uuid" \
    -H "Authorization: Bearer $read_token")
[ "$redelete_status" = "404" ] || fail "re-delete expected 404, got $redelete_status"
pass "re-DELETE → 404 (no 500, no orphan)"

# 5. Delete with bad token must 401 (safety) ------------------------------------
unauth_body=$(curl -sS -X POST "$HUB/api/relay/mailbox" -H 'Content-Length: 0')
unauth_uuid=$(printf '%s' "$unauth_body" | python3 -c 'import sys,json; print(json.load(sys.stdin).get("uuid",""))')
require_json "$unauth_uuid" "unauth_uuid"

unauth_status=$(curl -sS -o /dev/null -w '%{http_code}' \
    -X DELETE "$HUB/api/relay/mailbox/$unauth_uuid" \
    -H 'Authorization: Bearer 00000000000000000000000000000000')
[ "$unauth_status" = "401" ] || fail "unauth delete expected 401, got $unauth_status"
pass "DELETE with invalid token → 401 (auth still enforced)"

# Cleanup the second mailbox so prod stays clean.
curl -sS -o /dev/null -X DELETE "$HUB/api/relay/mailbox/$unauth_uuid" \
    -H "Authorization: Bearer $(printf '%s' "$unauth_body" | python3 -c 'import sys,json; print(json.load(sys.stdin).get("read_token",""))')" \
    || info "cleanup of unauth_uuid best-effort — check hub_events if curious"

printf '\n\033[32mRelay mailbox delete OK on %s\033[0m\n' "$HUB"
