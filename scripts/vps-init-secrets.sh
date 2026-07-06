#!/usr/bin/env bash
# Initialize secrets in Scaleway Secret Manager for BiblioGenius Hub
# Run this ONCE from your local machine to provision the secrets.
#
# Usage: ./scripts/vps-init-secrets.sh [prod|staging]
#
# You will be prompted for each secret value interactively.
# Secrets are stored at: hub/{ENV}/app-secret, hub/{ENV}/database-url, etc.
#
# Prerequisites:
#   - Scaleway CLI configured: scw init
#   - Secret Manager enabled in your Scaleway project

set -euo pipefail

ENV="${1:-prod}"
SECRET_PREFIX="hub-${ENV}"

echo "==> Initializing secrets for environment: ${ENV}"
echo "    Secret name prefix: ${SECRET_PREFIX}-"
echo ""

if ! command -v scw &> /dev/null; then
    echo "ERROR: Scaleway CLI (scw) not found. Install: https://github.com/scaleway/scaleway-cli"
    exit 1
fi

# ListSecrets requires project_id or organization_id since 2026-07-15,
# and the CLI does not fill it from the config for this command.
PROJECT_ID=$(scw config get default-project-id)
if [ -z "$PROJECT_ID" ]; then
    echo "ERROR: no default-project-id in scw config. Run: scw init"
    exit 1
fi

create_secret() {
    local name="$1"
    local description="$2"
    local full_name="${SECRET_PREFIX}-${name}"

    echo "--- ${full_name} ---"
    echo "    ${description}"

    # Get or create the secret, capture its ID
    local secret_id
    secret_id=$(scw secret secret list name="${full_name}" project-id="${PROJECT_ID}" -o json 2>/dev/null | jq -r '.[0].id // empty')

    if [ -n "$secret_id" ]; then
        echo "    Secret exists (${secret_id}). Will create new version..."
    else
        echo "    Creating secret..."
        secret_id=$(scw secret secret create name="${full_name}" description="${description}" -o json | jq -r .id)
        echo "    Created: ${secret_id}"
    fi

    # Read value securely (no echo)
    read -rsp "    Enter value: " value
    echo ""

    if [ -z "$value" ]; then
        echo "    SKIPPED (empty value)"
        return
    fi

    # Create version with the secret data (positional arg = secret-id)
    echo -n "$value" | scw secret version create "${secret_id}" data=-
    echo "    OK"
    echo ""
}

echo "You will be prompted for each secret value."
echo "Press Enter to skip a secret (e.g., if it already exists)."
echo ""

create_secret "app-secret" "Symfony APP_SECRET (hex, 32+ chars)"
create_secret "database-url" "PostgreSQL connection string (use VPC private IP, URL-encode special chars)"
create_secret "health-token" "Bearer token for /api/health/detailed endpoint"
create_secret "jira-api-token" "Jira API token for hub integration"

echo "==> All secrets initialized for ${ENV}"
echo ""
echo "Next steps:"
echo "  1. Verify: scw secret secret list project-id=${PROJECT_ID} | grep ${SECRET_PREFIX}"
echo "  2. Test access: scw secret version access-by-name secret-name=${SECRET_PREFIX}-health-token revision=latest raw=true"
echo "  3. Deploy: make deploy-vps (or deploy-vps-dev for staging)"
