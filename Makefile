# vim: set tabstop=8 softtabstop=8 noexpandtab:
.PHONY: help
help: ## Displays this list of targets with descriptions
	@grep -E '^[a-zA-Z0-9_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[32m%-30s\033[0m %s\n", $$1, $$2}'

.PHONY: token
token: ## Refresh token
	EASE_LOGGER=console csas-access-token -t`csas-access-token -l | tail -n 1 | awk '{print $$2}'`  -o.env

buildimage:
	docker build -f Containerfile -t spojenet/csas-pohoda:latest .

buildx:
	docker buildx build -f Containerfile . --push --platform linux/arm/v7,linux/arm64/v8,linux/amd64 --tag spojenet/csas-pohoda:latest

drun:
	docker run --env-file .env spojenet/csas-pohoda:latest
