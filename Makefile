# BiblioGenius Hub - Deployment Makefile

# Load local environment variables if they exist
-include .env.local
export

# Configuration
REGISTRY := rg.fr-par.scw.cloud/bibliogenius-hub
IMAGE_NAME := hub
CONTAINER_ID := 5e740f84-f17f-4535-b9a2-9a6ee1c7bec3
PLATFORM := linux/amd64

# Default target
.PHONY: help
help:
	@echo "BiblioGenius Hub Deployment Commands"
	@echo ""
	@echo "  make build        - Build Docker image for Scaleway"
	@echo "  make push         - Push image to Scaleway registry"
	@echo "  make deploy       - Deploy container to Scaleway"
	@echo "  make all          - Build, push, and deploy"
	@echo "  make login        - Login to Scaleway registry"
	@echo "  make status       - Check container status"
	@echo "  make test         - Test deployed endpoints"
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
	docker build --platform=$(PLATFORM) -t $(REGISTRY)/$(IMAGE_NAME):latest .

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
	@curl -s https://bibliogeniushubb2ozqvrz-hub.functions.fnc.fr-par.scw.cloud/ | jq .name
	@echo "\n📍 Health:"
	@curl -s https://bibliogeniushubb2ozqvrz-hub.functions.fnc.fr-par.scw.cloud/api/feedback/health
	@echo "\n📍 Peers:"
	@curl -s https://bibliogeniushubb2ozqvrz-hub.functions.fnc.fr-par.scw.cloud/api/peers
	@echo "\n✅ All endpoints tested"

# Local development
.PHONY: dev
dev:
	symfony server:start

# Update environment variables for production
.PHONY: env-prod
env-prod:
	@echo "Updating production environment variables..."
	scw container container update $(CONTAINER_ID) \
		environment-variables.APP_ENV=prod \
		environment-variables.APP_DEBUG=0 \
		environment-variables.DATABASE_URL="sqlite:////app/var/data.db" \
		environment-variables.CORS_ALLOW_ORIGIN='^https?://(.*\.)?bibliogenius\.(org|app|fr)(:[0-9]+)?$$' \
		environment-variables.DEFAULT_URI="https://hub.bibliogenius.org" \
		environment-variables.JIRA_BASE_URL="$${JIRA_BASE_URL}" \
		environment-variables.JIRA_PROJECT_KEY="$${JIRA_PROJECT_KEY}" \
		environment-variables.JIRA_EMAIL="$${JIRA_EMAIL}" \
		environment-variables.JIRA_API_TOKEN="$${JIRA_API_TOKEN}" \
		environment-variables.APP_SECRET="$${APP_SECRET}"
	@echo "Environment updated. Run 'make deploy' to apply changes."
	@echo "⚠️  Make sure JIRA_API_TOKEN and APP_SECRET are set in your shell!"
