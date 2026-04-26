#!/usr/bin/env bash
# Idempotent setup for ADR-035 city files on the VPS host.
#
# Re-run safe: every section is no-op if already in place. Designed to
# run on the VPS (as root via the existing deploy SSH key), invoked by
# `make setup-vps-cities` from a workstation.
#
# Steps:
#   1. Ensure /var/lib/bibliogenius/cities exists with permissions that
#      let Caddy read (and the container, running as root via
#      `docker exec`, write).
#   2. Bootstrap the city dataset by running `app:build-cities --all`
#      inside hub-prod. Every ISO 3166-1 alpha-2 country GeoNames
#      publishes is built (~250 files, ~30 MB, several minutes initial).
#      A user opening the picker for any country gets a result; the
#      yearly cron auto-picks up new ISO codes.
#   3. Install a yearly crontab line that re-runs the build with
#      --all --force, so the dataset tracks the GeoNames refresh and
#      newly registered countries. Tagged with a marker so re-runs do
#      not duplicate the entry.
#   4. Sanity check: curl the public FR file and assert the long-cache
#      Cache-Control header is in place.
#
# Usage (on the VPS, or remotely via the Makefile target):
#   bash setup-cities.sh                 # all countries (default)
#   bash setup-cities.sh --default-set   # small dev set (FR/BE/CH/LU/CA)
#   bash setup-cities.sh --rebuild       # --force regen (e.g. ADR-036 schema bump)
#   bash setup-cities.sh --skip-bootstrap --skip-verify  # cron only

set -euo pipefail

CITIES_DIR=/var/lib/bibliogenius/cities
CONTAINER=hub-prod
HEALTH_URL="https://hub.bibliogenius.org/static/cities/FR.json.gz"
CRON_TAG="adr-035-build-cities"
CRON_LOG=/var/log/build-cities.log
# Yearly refresh: 1st of January at 04:00 UTC. GeoNames publishes
# updated country exports rolling through the year; once a year is
# enough for a directory picker that resolves city centroids only.
# `--all` re-discovers the live ISO 3166-1 alpha-2 set so a new
# country added by GeoNames is picked up automatically.
CRON_LINE="0 4 1 1 * docker exec ${CONTAINER} php bin/console app:build-cities --all --force >> ${CRON_LOG} 2>&1 # ${CRON_TAG}"

COUNTRIES_MODE=all
DO_MKDIR=1
DO_BOOTSTRAP=1
DO_CRON=1
DO_VERIFY=1
FORCE=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --default-set)     COUNTRIES_MODE=default ;;
    --rebuild)         FORCE=1 ;;
    --skip-mkdir)      DO_MKDIR=0 ;;
    --skip-bootstrap)  DO_BOOTSTRAP=0 ;;
    --skip-cron)       DO_CRON=0 ;;
    --skip-verify)     DO_VERIFY=0 ;;
    -h|--help)
      sed -n '2,/^set -euo/p' "$0" | sed 's/^# \{0,1\}//' | sed '$d'
      exit 0 ;;
    *)
      echo "Unknown option: $1" >&2
      exit 2 ;;
  esac
  shift
done

step() { echo; echo "==> $*"; }

if [[ $DO_MKDIR -eq 1 ]]; then
  step "Ensuring ${CITIES_DIR} exists"
  mkdir -p "$CITIES_DIR"
  chmod 0755 "$CITIES_DIR"
  ls -ld "$CITIES_DIR"
fi

if [[ $DO_BOOTSTRAP -eq 1 ]]; then
  step "Bootstrapping city dataset (mode: ${COUNTRIES_MODE}, force=${FORCE})"
  ARGS=()
  [[ "$COUNTRIES_MODE" == "all" ]] && ARGS+=(--all)
  [[ $FORCE -eq 1 ]] && ARGS+=(--force)
  # ~250 files, several minutes on first run; subsequent runs skip
  # already-built files unless --rebuild is passed (e.g. schema bump).
  docker exec "$CONTAINER" php bin/console app:build-cities "${ARGS[@]}"
fi

if [[ $DO_CRON -eq 1 ]]; then
  step "Installing yearly refresh cron (tag: ${CRON_TAG})"
  if crontab -l 2>/dev/null | grep -qF "$CRON_TAG"; then
    echo "    Cron entry already present, leaving as-is."
  else
    # crontab -l exits 1 when no crontab exists yet; `|| true` keeps the
    # subshell alive under `set -e` so `echo` still runs and crontab -
    # receives a single-line stdin to install.
    ( crontab -l 2>/dev/null || true; echo "$CRON_LINE" ) | crontab -
    echo "    Added: $CRON_LINE"
  fi
  echo "    Current crontab line(s) for build-cities:"
  crontab -l 2>/dev/null | grep -F "$CRON_TAG" || true
fi

if [[ $DO_VERIFY -eq 1 ]]; then
  step "Verifying ${HEALTH_URL}"
  # Caddy must serve the file with the long-cache header from
  # Caddyfile.container.vps; missing it usually means the @cities
  # block is shadowed by a later route or the file was not built.
  HEADERS=$(curl -fsSI "$HEALTH_URL")
  echo "$HEADERS"
  echo "$HEADERS" | grep -qi "cache-control: public, max-age=2592000" \
    || { echo "FAIL: missing or wrong Cache-Control header" >&2; exit 1; }
  echo "    OK"
fi

echo
echo "==> Done."
