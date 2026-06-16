# Convenience wrapper around the Docker dev/test environment.
# Windows without `make`? Each target shows the raw command in DEV-README.md.

DC := docker compose
TOOL := $(DC) exec -T tooling

.DEFAULT_GOAL := help

.PHONY: help up down restart logs build shell wp install composer test-setup test lint fix stan check ps clean

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2}'

up: ## Start WordPress + DB + Adminer + tooling
	$(DC) up -d
	@echo "WordPress: http://localhost:8080   Adminer: http://localhost:8081"

down: ## Stop all containers
	$(DC) down

restart: ## Restart all containers
	$(DC) restart

logs: ## Tail logs
	$(DC) logs -f

build: ## (Re)build the tooling image
	$(DC) build tooling

ps: ## Show container status
	$(DC) ps

shell: ## Open a shell in the tooling container
	$(DC) exec tooling bash

wp: ## Run WP-CLI, e.g. make wp ARGS="plugin list"
	$(DC) run --rm wpcli wp $(ARGS)

composer: ## Install PHP dev dependencies (PHPCS, PHPStan, PHPUnit)
	$(TOOL) composer install

test-setup: ## Download the WordPress test library + create the test DB
	$(TOOL) bash dev/install-wp-tests.sh $${TEST_DB_NAME:-wordpress_test} root root db latest

test: ## Run the PHPUnit integration suite
	$(TOOL) vendor/bin/phpunit

lint: ## PHPCS (WordPress coding standards)
	$(TOOL) vendor/bin/phpcs

fix: ## PHPCBF (auto-fix what PHPCS can)
	$(TOOL) vendor/bin/phpcbf

stan: ## PHPStan static analysis
	$(TOOL) vendor/bin/phpstan analyse --memory-limit=1G

check: lint stan test ## Lint + static analysis + tests

clean: ## Stop and remove containers + volumes (DESTROYS the test DB/site)
	$(DC) down -v
