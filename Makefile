# BiblioGenius Hub - Deployment Makefile

# Load local environment variables if they exist
-include .env.local
# Maintainer-specific Make overrides (deploy targets, etc.) — untracked,
# see Makefile.local.example.
-include Makefile.local
export

# Configuration
REGISTRY := rg.fr-par.scw.cloud/bibliogenius-hub
IMAGE_NAME := hub
CONTAINER_ID := 5e740f84-f17f-4535-b9a2-9a6ee1c7bec3
CONTAINER_DEV_ID := 04487027-9f53-4161-a960-d9f3b3eaa1f5
PLATFORM := linux/amd64
HUB_URL := https://hub.bibliogenius.org
HUB_DEV_URL := https://hub-dev.bibliogenius.org
VERSION := $(shell git describe --tags --always 2>/dev/null || echo "dev")

# Default target
.PHONY: help
help:
	@echo "BiblioGenius Hub Deployment Commands"
	@echo ""
	@echo "Local development (Docker stack on http://localhost:8082):"
	@echo "  make up           - Start the local stack (postgres + hub)"
	@echo "  make up-build     - Start and rebuild the hub image"
	@echo "  make down         - Stop the local stack (ARGS=-v wipes volumes)"
	@echo "  make logs         - Follow hub container logs"
	@echo "  make shell        - Shell into the hub container"
	@echo "  make phpunit      - Run PHPUnit (ARGS=<path> to scope)"
	@echo ""
	@echo "  make build        - Build Docker image for Scaleway"
	@echo "  make push         - Push image to Scaleway registry"
	@echo "  make deploy       - Deploy container to Scaleway"
	@echo "  make all          - Build, push, and deploy"
	@echo "  make login        - Login to Scaleway registry"
	@echo "  make status       - Check container status"
	@echo "  make test         - Test deployed endpoints"
	@echo "  make test-relay   - Test relay endpoints (E2EE mailbox)"
	@echo "  make test-all     - Run all tests"
	@echo ""
	@echo "Staging (hub-dev on Scaleway):"
	@echo "  make deploy-dev   - Build, push, deploy to staging"
	@echo "  make status-dev   - Check staging container status"
	@echo "  make test-dev     - Test staging endpoints"
	@echo ""
	@echo "WebSocket Sidecar (ADR-017):"
	@echo "  make deploy-sidecar-dev - Build, push, deploy sidecar to staging"
	@echo "  make deploy-sidecar     - Build, push, deploy sidecar to prod"
	@echo ""
	@echo "VPS Deployment (Scaleway DEV1-S + Secret Manager):"
	@echo "  make vps-setup        - Upload config files to VPS (run once)"
	@echo "  make vps-init-secrets - Provision secrets in Secret Manager (run once)"
	@echo "  make deploy-vps       - Build, push, deploy prod to VPS"
	@echo "  make deploy-vps-dev   - Build, push, deploy staging to VPS"
	@echo "  make vps-logs         - Tail production logs"
	@echo "  make vps-logs-dev     - Tail staging logs"
	@echo "  make vps-status       - Show container status and resource usage"
	@echo "  make vps-ssh          - SSH into VPS"
	@echo ""

# Login to Scaleway registry
.PHONY: login
login:
	@echo "Logging into Scaleway registry..."
	scw registry login

# Build for Scaleway (amd64)
.PHONY: build
build:
	@echo "Building image for $(PLATFORM)..."
	docker build --platform=$(PLATFORM) --build-arg APP_VERSION=$(VERSION) -t $(REGISTRY)/$(IMAGE_NAME):latest .

# Push to registry
.PHONY: push
push:
	@echo "Pushing to Scaleway registry..."
	docker push $(REGISTRY)/$(IMAGE_NAME):latest

# Deploy to Scaleway Serverless Containers
.PHONY: deploy
deploy:
	@echo "Deploying container..."
	scw container container deploy $(CONTAINER_ID)
	@echo "Waiting for deployment..."
	@sleep 30
	@$(MAKE) status

# Full deployment pipeline
.PHONY: all
all: build push deploy
	@echo "✅ Deployment complete!"

# Check container status
.PHONY: status
status:
	@scw container container get $(CONTAINER_ID) | grep -E "^Status|^DomainName|^ReadyAt"

# Test deployed endpoints
.PHONY: test
test:
	@echo "Testing endpoints..."
	@echo "\n📍 Root:"
	@curl -s $(HUB_URL)/ | jq .name
	@echo "\n📍 Health:"
	@curl -s $(HUB_URL)/api/feedback/health
	@echo "\n📍 Peers:"
	@curl -s $(HUB_URL)/api/discovery/peers
	@echo "\n✅ All endpoints tested"

# Test relay endpoints (E2EE blind mailbox)
.PHONY: test-relay
test-relay:
	@echo "Testing relay endpoints on $(HUB_URL)..."
	@echo "\n📬 1. Create mailbox:"
	@RELAY=$$(curl -sf -X POST $(HUB_URL)/api/relay/mailbox) && \
		echo "$$RELAY" | jq . && \
		UUID=$$(echo "$$RELAY" | jq -r .uuid) && \
		RT=$$(echo "$$RELAY" | jq -r .read_token) && \
		WT=$$(echo "$$RELAY" | jq -r .write_token) && \
		echo "\n📨 2. Deposit blob (write_token):" && \
		DEPOSIT=$$(curl -sf -X POST $(HUB_URL)/api/relay/mailbox/$$UUID/messages \
			-H "Authorization: Bearer $$WT" \
			-H "Content-Type: application/octet-stream" \
			-d '{"test":"e2ee relay"}') && \
		echo "$$DEPOSIT" | jq . && \
		MSG_ID=$$(echo "$$DEPOSIT" | jq -r .id) && \
		echo "\n📥 3. Collect messages (read_token):" && \
		curl -sf $(HUB_URL)/api/relay/mailbox/$$UUID/messages \
			-H "Authorization: Bearer $$RT" | jq . && \
		echo "\n🔒 4. Reject bad token:" && \
		HTTP_CODE=$$(curl -s -o /dev/null -w "%{http_code}" \
			$(HUB_URL)/api/relay/mailbox/$$UUID/messages \
			-H "Authorization: Bearer bad_token") && \
		if [ "$$HTTP_CODE" = "401" ]; then echo "  401 Unauthorized ✅"; else echo "  Expected 401, got $$HTTP_CODE ❌"; exit 1; fi && \
		echo "\n🗑️  5. Ack (delete) message:" && \
		curl -sf -X DELETE $(HUB_URL)/api/relay/mailbox/$$UUID/messages/$$MSG_ID \
			-H "Authorization: Bearer $$RT" | jq . && \
		echo "\n📭 6. Verify empty after ack:" && \
		MSGS=$$(curl -sf $(HUB_URL)/api/relay/mailbox/$$UUID/messages \
			-H "Authorization: Bearer $$RT" | jq '.messages | length') && \
		if [ "$$MSGS" = "0" ]; then echo "  0 messages ✅"; else echo "  Expected 0, got $$MSGS ❌"; exit 1; fi && \
		echo "\n✅ All relay tests passed!"

# Run all tests
.PHONY: test-all
test-all: test test-relay

# ---------------------------------------------------------------------------
# Staging (Scaleway hub-dev container)
# ---------------------------------------------------------------------------

# Deploy to staging (same image, different container)
.PHONY: deploy-dev
deploy-dev: build push
	@echo "Deploying to staging..."
	scw container container deploy $(CONTAINER_DEV_ID)
	@echo "Waiting for deployment..."
	@sleep 30
	@$(MAKE) status-dev

.PHONY: status-dev
status-dev:
	@scw container container get $(CONTAINER_DEV_ID) | grep -E "^Status|^DomainName|^ReadyAt"

.PHONY: test-dev
test-dev:
	@echo "Testing staging endpoints..."
	@echo "\n📍 Root:"
	@curl -s $(HUB_DEV_URL)/ | jq .name
	@echo "\n📍 Health:"
	@curl -s $(HUB_DEV_URL)/api/feedback/health
	@echo "\n✅ Staging OK"

# ---------------------------------------------------------------------------
# WebSocket Sidecar (ADR-017)
# ---------------------------------------------------------------------------

SIDECAR_IMAGE := ws-sidecar

.PHONY: build-sidecar
build-sidecar:
	@echo "Building ws-sidecar image for $(PLATFORM)..."
	docker build --platform=$(PLATFORM) -t $(REGISTRY)/$(SIDECAR_IMAGE):vps ws-sidecar/

.PHONY: push-sidecar
push-sidecar:
	@echo "Pushing ws-sidecar to Scaleway registry..."
	docker push $(REGISTRY)/$(SIDECAR_IMAGE):vps

.PHONY: deploy-sidecar-dev
deploy-sidecar-dev: build-sidecar push-sidecar
	@echo "Deploying ws-sidecar-staging on VPS..."
	$(VPS_SSH) "cd $(VPS_DIR) && docker compose pull ws-sidecar-staging && docker compose up -d ws-sidecar-staging"
	@echo "Waiting for sidecar to start..."
	@sleep 5
	@echo "Checking sidecar health..."
	@curl -sf https://hub-dev.bibliogenius.org/ws && echo " (upgrade required = OK)" || echo "Sidecar health check (direct):"
	@$(VPS_SSH) "curl -sf http://localhost:9092/health" && echo " OK" || echo " FAIL"

.PHONY: deploy-sidecar
deploy-sidecar: build-sidecar push-sidecar
	@echo "Deploying ws-sidecar-prod on VPS..."
	$(VPS_SSH) "cd $(VPS_DIR) && docker compose pull ws-sidecar-prod && docker compose up -d ws-sidecar-prod"
	@echo "Waiting for sidecar to start..."
	@sleep 5
	@$(VPS_SSH) "curl -sf http://localhost:9091/health" && echo " OK" || echo " FAIL"

# ---------------------------------------------------------------------------
# Local development
# ---------------------------------------------------------------------------

DEV_COMPOSE := docker compose -f docker-compose.dev.yml

# Start the local Docker stack (postgres + hub on http://localhost:8082).
.PHONY: up
up:
	$(DEV_COMPOSE) up -d

# Same as up, but rebuild the hub image first (use after Dockerfile.dev or
# dependency changes).
.PHONY: up-build
up-build:
	$(DEV_COMPOSE) up -d --build

# Stop the local stack (add ARGS=-v to also wipe the DB + vendor volumes).
.PHONY: down
down:
	$(DEV_COMPOSE) down $(ARGS)

# Follow the hub container logs.
.PHONY: logs
logs:
	$(DEV_COMPOSE) logs -f hub

# Open a shell inside the hub container.
.PHONY: shell
shell:
	$(DEV_COMPOSE) exec hub bash

# Run the PHPUnit suite inside the hub container (ARGS forwards extra args,
# e.g. make phpunit ARGS=tests/Unit/Repository).
.PHONY: phpunit
phpunit:
	$(DEV_COMPOSE) exec hub vendor/bin/phpunit $(ARGS)

.PHONY: dev
dev:
	symfony server:start

# ---------------------------------------------------------------------------
# VPS Deployment (Scaleway DEV1-S)
# ---------------------------------------------------------------------------

# Deploy targets are maintainer-specific. Override VPS_* in an untracked
# Makefile.local (see Makefile.local.example) for your own infra. The defaults
# below assume an SSH alias `hub-vps` defined in your ~/.ssh/config.
VPS_HOST ?= hub-vps
VPS_DIR  ?= /opt/hub
VPS_SSH  ?= ssh $(VPS_HOST)
VPS_SCP  ?= scp

.PHONY: build-vps
build-vps: build
	@echo "Tagging image as :vps..."
	docker tag $(REGISTRY)/$(IMAGE_NAME):latest $(REGISTRY)/$(IMAGE_NAME):vps

.PHONY: push-vps
push-vps:
	@echo "Pushing :vps tag to Scaleway registry..."
	docker push $(REGISTRY)/$(IMAGE_NAME):vps

.PHONY: deploy-vps
deploy-vps: build-vps push-vps
	@echo "Syncing infra files to VPS (compose + deploy script)..."
	$(VPS_SCP) docker-compose.vps.yml $(VPS_HOST):$(VPS_DIR)/docker-compose.vps.yml
	$(VPS_SCP) scripts/vps-deploy.sh $(VPS_HOST):$(VPS_DIR)/scripts/vps-deploy.sh
	$(VPS_SSH) "chmod +x $(VPS_DIR)/scripts/vps-deploy.sh"
	@echo "Deploying production to VPS (secrets from Scaleway Secret Manager)..."
	$(VPS_SSH) "bash $(VPS_DIR)/scripts/vps-deploy.sh prod"
	@$(MAKE) test

.PHONY: deploy-vps-dev
deploy-vps-dev: build-vps push-vps
	@echo "Syncing infra files to VPS (compose + deploy script)..."
	$(VPS_SCP) docker-compose.vps.yml $(VPS_HOST):$(VPS_DIR)/docker-compose.vps.yml
	$(VPS_SCP) scripts/vps-deploy.sh $(VPS_HOST):$(VPS_DIR)/scripts/vps-deploy.sh
	$(VPS_SSH) "chmod +x $(VPS_DIR)/scripts/vps-deploy.sh"
	@echo "Deploying staging to VPS (secrets from Scaleway Secret Manager)..."
	$(VPS_SSH) "bash $(VPS_DIR)/scripts/vps-deploy.sh staging"
	@$(MAKE) test-dev

# Provision secrets in Scaleway Secret Manager (run once)
.PHONY: vps-init-secrets
vps-init-secrets:
	@echo "Provisioning secrets in Scaleway Secret Manager..."
	bash scripts/vps-init-secrets.sh $(ENV)

# Upload deployment files to VPS (run once after VPS setup)
.PHONY: vps-setup
vps-setup:
	@echo "Uploading deployment files to VPS..."
	$(VPS_SSH) "mkdir -p $(VPS_DIR)/scripts"
	$(VPS_SCP) docker-compose.vps.yml $(VPS_HOST):$(VPS_DIR)/docker-compose.yml
	$(VPS_SCP) Caddyfile.vps $(VPS_HOST):/etc/caddy/Caddyfile
	$(VPS_SCP) Caddyfile.container.vps $(VPS_HOST):$(VPS_DIR)/Caddyfile.container.vps
	$(VPS_SCP) .env.prod.template $(VPS_HOST):$(VPS_DIR)/.env.prod.config
	$(VPS_SCP) .env.staging.template $(VPS_HOST):$(VPS_DIR)/.env.staging.config
	$(VPS_SCP) scripts/vps-deploy.sh $(VPS_HOST):$(VPS_DIR)/scripts/vps-deploy.sh
	$(VPS_SSH) "chmod +x $(VPS_DIR)/scripts/vps-deploy.sh"
	@echo "Files uploaded. Next: make vps-init-secrets ENV=prod"

# Bootstrap ADR-035 city dataset on the VPS host (idempotent).
# Builds every ISO 3166-1 alpha-2 country GeoNames publishes (~250 files,
# ~30 MB, several minutes on the first run). The yearly cron installed by
# the script auto-refreshes with --force. For ad-hoc small builds during
# dev, run the script directly with --default-set.
.PHONY: setup-vps-cities
setup-vps-cities:
	$(VPS_SCP) scripts/setup-cities.sh $(VPS_HOST):$(VPS_DIR)/scripts/setup-cities.sh
	$(VPS_SSH) "chmod +x $(VPS_DIR)/scripts/setup-cities.sh && bash $(VPS_DIR)/scripts/setup-cities.sh"

# Force regen of the entire city dataset, e.g. after a schema bump
# (ADR-036). Equivalent to setup-vps-cities + --force on the underlying
# build command. Touches all ~250 files; expect 5-10 min runtime.
.PHONY: setup-vps-cities-rebuild
setup-vps-cities-rebuild:
	$(VPS_SCP) scripts/setup-cities.sh $(VPS_HOST):$(VPS_DIR)/scripts/setup-cities.sh
	$(VPS_SSH) "chmod +x $(VPS_DIR)/scripts/setup-cities.sh && bash $(VPS_DIR)/scripts/setup-cities.sh --rebuild"

.PHONY: vps-logs
vps-logs:
	$(VPS_SSH) "cd $(VPS_DIR) && docker compose logs -f hub-prod --tail 100"

.PHONY: vps-logs-dev
vps-logs-dev:
	$(VPS_SSH) "cd $(VPS_DIR) && docker compose logs -f hub-staging --tail 100"

.PHONY: vps-status
vps-status:
	@echo "VPS container status:"
	@$(VPS_SSH) "cd $(VPS_DIR) && docker compose ps"
	@echo ""
	@echo "VPS resource usage:"
	@$(VPS_SSH) "docker stats --no-stream"

.PHONY: vps-ssh
vps-ssh:
	$(VPS_SSH)

# ---------------------------------------------------------------------------
# Scaleway Serverless (legacy, keep for rollback during VPS migration)
# ---------------------------------------------------------------------------

# Update environment variables for production
.PHONY: env-prod
env-prod:
	@echo "Updating production environment variables..."
	scw container container update $(CONTAINER_ID) \
		environment-variables.APP_ENV=prod \
		environment-variables.APP_DEBUG=0 \
		environment-variables.DATABASE_URL="$${DATABASE_URL}" \
		environment-variables.CORS_ALLOW_ORIGIN='^https?://(.*\.)?bibliogenius\.(org|app|fr)(:[0-9]+)?$$' \
		environment-variables.DEFAULT_URI="https://hub.bibliogenius.org" \
		environment-variables.JIRA_BASE_URL="$${JIRA_BASE_URL}" \
		environment-variables.JIRA_PROJECT_KEY="$${JIRA_PROJECT_KEY}" \
		environment-variables.JIRA_EMAIL="$${JIRA_EMAIL}" \
		environment-variables.JIRA_API_TOKEN="$${JIRA_API_TOKEN}" \
		environment-variables.APP_SECRET="$${APP_SECRET}" \
		environment-variables.HEALTH_TOKEN="$${HEALTH_TOKEN}"
	@echo "Environment updated. Run 'make deploy' to apply changes."
	@echo "⚠️  Make sure DATABASE_URL, APP_SECRET, HEALTH_TOKEN and JIRA_API_TOKEN are set in your shell!"
