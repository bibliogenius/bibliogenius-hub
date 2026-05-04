#!/usr/bin/env bash
#
# KAN-286 smoke test: SHA-256 -> BCrypt dual-read on /api/directory/recover.
#
# Drives both the hub HTTP API and the underlying Postgres to exercise:
#   1. Legacy verification: a profile whose recovery_code_hash is still in
#      the SHA-256 hex format must successfully recover with its original
#      plaintext code.
#   2. Progressive migration: after that successful recovery, the persisted
#      hash must be a BCrypt $2y$12$... blob (rotation subsumes the upgrade).
#   3. Single-use: replaying the legacy code after rotation must 401.
#   4. Wrong-code rejection: a clearly invalid code must 401.
#   5. End-to-end on the new format: the freshly issued BCrypt-protected
#      code must itself verify on a subsequent call.
#
# Why psql is required (unlike the other qa_*.sh scripts): there is no API
# endpoint that lets a caller plant an arbitrary recovery_code_hash, and we
# cannot validate criterion 2 without reading the column directly.
#
# The synthetic profile is is_listed=false (invisible in the directory) and
# is always removed by the EXIT trap, even on failure.
#
# Usage:
#   PSQL='ssh vps -- docker exec -i hub-postgres psql -U hub_dev_user -d hub_dev -t -A -X' \
#     HUB=https://hub-dev.bibliogenius.org \
#     ./scripts/qa_kan286_recovery_bcrypt.sh
#
# Optional env:
#   HUB        default https://hub-dev.bibliogenius.org
#   PSQL       REQUIRED — command that reads SQL on stdin and writes tuples
#              with no formatting and no header (psql flags: -t -A -X).
#   TEST_CODE  default TESTCODE1234 (must be 8-20 chars, content irrelevant
#              since verifyRecoveryCode hashes whatever string is provided).
#
# Exits non-zero on the first failed assertion.

set -euo pipefail

HUB="${HUB:-https://hub-dev.bibliogenius.org}"
TEST_CODE="${TEST_CODE:-TESTCODE1234}"
NOW="$(date +%s)"
NODE_ID="qa-kan286-$NOW-$$"
DISPLAY_NAME="QA KAN-286 dual-read"

pass() { printf '\033[32m✓\033[0m %s\n' "$1"; }
fail() { printf '\033[31m✗\033[0m %s\n' "$1" >&2; exit 1; }
info() { printf '\033[36m→\033[0m %s\n' "$1"; }

if [ -z "${PSQL:-}" ]; then
    fail "PSQL env var is not set. See header comment for examples."
fi
command -v curl >/dev/null   || fail "curl is required"
command -v openssl >/dev/null || fail "openssl is required"
command -v python3 >/dev/null || fail "python3 is required"

json_field() {
    # $1 = JSON blob, $2 = field name
    printf '%s' "$1" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get(\"$2\",\"\"))"
}

sha256_hex() {
    if command -v sha256sum >/dev/null; then
        printf '%s' "$1" | sha256sum | awk '{print $1}'
    else
        printf '%s' "$1" | shasum -a 256 | awk '{print $1}'
    fi
}

run_sql() {
    printf '%s\n' "$1" | $PSQL
}

cleanup() {
    info "cleanup: removing test profile $NODE_ID"
    run_sql "DELETE FROM library_profiles WHERE node_id = '$NODE_ID';" >/dev/null 2>&1 || true
}
trap cleanup EXIT

post_recover() {
    # $1 = recovery_code. Echoes HTTP status; body is in /tmp/qa_kan286.out.
    local code="$1"
    curl -sS -o /tmp/qa_kan286.out -w '%{http_code}' \
        -X POST "$HUB/api/directory/recover" \
        -H 'Content-Type: application/json' \
        -d "{\"node_id\":\"$NODE_ID\",\"recovery_code\":\"$code\"}"
}

info "target: $HUB"
info "node_id: $NODE_ID"

# 1. Plant a synthetic legacy profile ---------------------------------------
info "inserting legacy SHA-256 profile via psql"
SHA256_HEX=$(sha256_hex "$TEST_CODE")
WRITE_TOKEN=$(openssl rand -hex 32)

run_sql "INSERT INTO library_profiles (
    node_id, write_token, display_name, created_at, recovery_code_hash,
    requires_approval, accept_from, allow_borrowing, is_listed,
    book_count, view_count, hijack_attempts_total
) VALUES (
    '$NODE_ID', '$WRITE_TOKEN', '$DISPLAY_NAME', NOW(), '$SHA256_HEX',
    false, 'everyone', false, false, 0, 0, 0
);" >/dev/null

algo_before=$(run_sql "SELECT CASE
    WHEN recovery_code_hash LIKE '\$2y\$%' THEN 'bcrypt'
    WHEN recovery_code_hash ~ '^[0-9a-f]{64}\$' THEN 'sha256-legacy'
    ELSE 'unknown'
END FROM library_profiles WHERE node_id = '$NODE_ID';")
[ "$algo_before" = "sha256-legacy" ] || fail "expected sha256-legacy, got '$algo_before'"
pass "profile stored as sha256-legacy"

# 2. Recover with the correct legacy code -----------------------------------
info "POST /api/directory/recover with the legacy code"
http=$(post_recover "$TEST_CODE")
body=$(cat /tmp/qa_kan286.out)
[ "$http" = "200" ] || fail "expected 200, got $http — body: $body"

NEW_RECOVERY_CODE=$(json_field "$body" "recovery_code")
NEW_WRITE_TOKEN=$(json_field "$body" "write_token")
[ -n "$NEW_RECOVERY_CODE" ] || fail "no recovery_code in 200 response: $body"
[ -n "$NEW_WRITE_TOKEN" ]   || fail "no write_token in 200 response: $body"
pass "200 with new write_token + recovery_code"

# 3. Hash must now be BCrypt cost 12 ----------------------------------------
info "verifying DB now stores BCrypt cost 12"
algo_after=$(run_sql "SELECT CASE
    WHEN recovery_code_hash LIKE '\$2y\$12\$%' THEN 'bcrypt-12'
    WHEN recovery_code_hash LIKE '\$2y\$%'     THEN 'bcrypt-other-cost'
    WHEN recovery_code_hash ~ '^[0-9a-f]{64}\$' THEN 'still-sha256'
    ELSE 'unknown'
END FROM library_profiles WHERE node_id = '$NODE_ID';")
[ "$algo_after" = "bcrypt-12" ] || fail "expected bcrypt-12, got '$algo_after'"
pass "hash upgraded to bcrypt-12"

# 4. Replay of the old code is invalidated ----------------------------------
info "replaying the rotated-out legacy code"
http=$(post_recover "$TEST_CODE")
[ "$http" = "401" ] || fail "expected 401 on replay, got $http"
pass "401 — rotation invalidated the old code"

# 5. Wrong code is rejected -------------------------------------------------
info "POSTing a clearly wrong code"
http=$(post_recover "WRONGCODE999")
[ "$http" = "401" ] || fail "expected 401 on wrong code, got $http"
pass "401 — wrong code rejected"

# 6. The new BCrypt code itself verifies ------------------------------------
info "POSTing the freshly issued BCrypt-protected code"
http=$(post_recover "$NEW_RECOVERY_CODE")
[ "$http" = "200" ] || fail "expected 200 on new code, got $http"
pass "200 — BCrypt verification path works end-to-end"

rm -f /tmp/qa_kan286.out

printf '\n\033[32mPASS\033[0m — KAN-286 dual-read validated on %s\n' "$HUB"
