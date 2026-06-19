#!/usr/bin/env bash
#
# Host-side alerter for uncaught hub 500s.
#
# Why this exists: a CRITICAL (level 500) uncaught exception is written to the
# container's stdout/docker logs but NEVER reaches hub_events (the crash can
# precede HubEventLogger), so the admin dashboard shows nothing. On 2026-06-18
# a DBAL 4 TypeError in the relay deposit path 500'd in a loop for weeks before
# anyone noticed. This script closes that blind spot: it scans the last WINDOW
# of docker logs for `"level":500`, and on the first occurrence of a given error
# signature pushes one notification (Telegram, generic webhook, or stderr).
#
# Run it from cron on the VPS, e.g. every 5 minutes:
#   */5 * * * * WINDOW=6m /opt/bibliogenius-hub/scripts/alert_critical_logs.sh
# (Use a WINDOW slightly larger than the cron period so a slow tick can't drop
#  events between runs; duplicate detection makes the overlap harmless.)
#
# Notification channel (first match wins):
#   TELEGRAM_BOT_TOKEN + TELEGRAM_CHAT_ID  -> Telegram message
#   ALERT_WEBHOOK                          -> POST {"text": "..."} (ntfy/Discord/Slack)
#   (neither set)                          -> print to stderr; cron MAILTO mails it
#
# Tunables (env):
#   CONTAINER   container name                      (default: hub-prod)
#   WINDOW      docker logs --since value           (default: 6m)
#   THRESHOLD   min level-500 count to alert         (default: 1)
#   COOLDOWN    seconds to suppress a repeat alert
#               for the same signature              (default: 3600)
#   STATE_DIR   where cooldown stamps live          (default: /var/tmp/bg-hub-alert)
#
# Exit codes: 0 = no alert needed or alert sent; non-zero = a hard error
# (container missing, docker unavailable). Cron MAILTO surfaces those too.

set -euo pipefail

CONTAINER="${CONTAINER:-hub-prod}"
WINDOW="${WINDOW:-6m}"
THRESHOLD="${THRESHOLD:-1}"
COOLDOWN="${COOLDOWN:-3600}"
STATE_DIR="${STATE_DIR:-/var/tmp/bg-hub-alert}"

mkdir -p "$STATE_DIR"

# -- Collect the window --------------------------------------------------------
# 2>&1 because docker writes container stdout+stderr there; both can carry logs.
if ! logs="$(docker logs "$CONTAINER" --since "$WINDOW" 2>&1)"; then
    echo "alert_critical_logs: cannot read logs for container '$CONTAINER'" >&2
    exit 2
fi

# Count CRITICAL request-level failures. Monolog JSON emits "level":500.
count="$(printf '%s\n' "$logs" | grep -c '"level":500' || true)"

if [ "$count" -lt "$THRESHOLD" ]; then
    exit 0
fi

# -- Build a stable signature so an ongoing flood alerts once, not every tick ---
# Prefer the exception class+message; fall back to a generic key if absent.
sample="$(printf '%s\n' "$logs" \
    | grep '"level":500' \
    | grep -oE '"class":"[^"]+","message":"[^"]+"' \
    | head -n1 || true)"
[ -n "$sample" ] || sample="unclassified-500"

# md5sum on coreutils, md5 on BSD/macOS.
if command -v md5sum >/dev/null 2>&1; then
    sig="$(printf '%s' "$sample" | md5sum | cut -d' ' -f1)"
else
    sig="$(printf '%s' "$sample" | md5 -q)"
fi
stamp="$STATE_DIR/$sig"

# -- Cooldown: skip if we alerted on this signature recently --------------------
if [ -f "$stamp" ]; then
    last="$(cat "$stamp" 2>/dev/null || echo 0)"
    now="$(date +%s)"
    if [ $((now - last)) -lt "$COOLDOWN" ]; then
        exit 0
    fi
fi

# -- Compose the message -------------------------------------------------------
host="$(hostname)"
human_sample="$(printf '%s' "$sample" | sed 's/"class":"//; s/","message":"/: /; s/"$//')"
[ "$sample" = "unclassified-500" ] && human_sample="(see docker logs $CONTAINER)"

text="🔴 BiblioGenius hub: ${count} uncaught 500(s) in the last ${WINDOW} on ${host} (${CONTAINER}).
First error: ${human_sample}
Inspect: docker logs ${CONTAINER} --since ${WINDOW} | grep '\"level\":500'"

# -- Send (first configured channel wins) --------------------------------------
sent=0
if [ -n "${TELEGRAM_BOT_TOKEN:-}" ] && [ -n "${TELEGRAM_CHAT_ID:-}" ]; then
    if curl -fsS --max-time 10 \
        -d chat_id="${TELEGRAM_CHAT_ID}" \
        --data-urlencode "text=${text}" \
        "https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/sendMessage" >/dev/null; then
        sent=1
    else
        echo "alert_critical_logs: Telegram send failed" >&2
    fi
elif [ -n "${ALERT_WEBHOOK:-}" ]; then
    if curl -fsS --max-time 10 \
        -H 'Content-Type: application/json' \
        --data "$(printf '{"text":%s}' "$(printf '%s' "$text" | sed 's/\\/\\\\/g; s/"/\\"/g; s/$/\\n/' | tr -d '\n')")" \
        "$ALERT_WEBHOOK" >/dev/null; then
        sent=1
    else
        echo "alert_critical_logs: webhook send failed" >&2
    fi
fi

# Fallback: stderr so cron's MAILTO delivers it, even if a channel was
# configured but the send failed above.
if [ "$sent" -ne 1 ]; then
    printf '%s\n' "$text" >&2
fi

# Stamp only after we attempted delivery, so a transient channel outage does
# not silently swallow the cooldown window for a still-firing incident.
date +%s > "$stamp"
exit 0
