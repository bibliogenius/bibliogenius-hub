# BiblioGenius Hub - Deployment Makefile

# Load local environment variables if they exist
-include .env.local
export

# Configuration
REGISTRY := rg.fr-par.scw.cloud/bibliogenius-hub
IMAGE_NAME := hub
CONTAINER_ID := 5e740f84-f17f-4535-b9a2-9a6ee1c7bec3
PLATFORM := linux/amd64
HUB_URL := https://hub.bibliogenius.org

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
	@echo "  make test-relay   - Test relay endpoints (E2EE mailbox)"
	@echo "  make test-all     - Run all tests"
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
	@curl -s $(HUB_URL)/ | jq .name
	@echo "\n📍 Health:"
	@curl -s $(HUB_URL)/api/feedback/health
	@echo "\n📍 Peers:"
	@curl -s $(HUB_URL)/api/peers
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
		curl -sf -X POST $(HUB_URL)/api/relay/mailbox/$$UUID/messages \
			-H "Authorization: Bearer $$WT" \
			-H "Content-Type: application/octet-stream" \
			-d '{"test":"e2ee relay"}' | jq . && \
		echo "\n📥 3. Collect messages (read_token):" && \
		curl -sf $(HUB_URL)/api/relay/mailbox/$$UUID/messages \
			-H "Authorization: Bearer $$RT" | jq . && \
		echo "\n🔒 4. Reject bad token:" && \
		HTTP_CODE=$$(curl -s -o /dev/null -w "%{http_code}" \
			$(HUB_URL)/api/relay/mailbox/$$UUID/messages \
			-H "Authorization: Bearer bad_token") && \
		if [ "$$HTTP_CODE" = "401" ]; then echo "  401 Unauthorized ✅"; else echo "  Expected 401, got $$HTTP_CODE ❌"; exit 1; fi && \
		echo "\n🗑️  5. Ack (delete) message:" && \
		curl -sf -X DELETE $(HUB_URL)/api/relay/mailbox/$$UUID/messages/1 \
			-H "Authorization: Bearer $$RT" | jq . && \
		echo "\n📭 6. Verify empty after ack:" && \
		MSGS=$$(curl -sf $(HUB_URL)/api/relay/mailbox/$$UUID/messages \
			-H "Authorization: Bearer $$RT" | jq '.messages | length') && \
		if [ "$$MSGS" = "0" ]; then echo "  0 messages ✅"; else echo "  Expected 0, got $$MSGS ❌"; exit 1; fi && \
		echo "\n✅ All relay tests passed!"

# Run all tests
.PHONY: test-all
test-all: test test-relay

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
