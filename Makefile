.PHONY: help up down install test pint pint-test lint build clean fresh setup check restart logs shell autoload

help: ## Show this help
	@echo 'Usage: make [target]'
	@echo ''
	@echo 'Targets:'
	@egrep '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-15s\033[0m %s\n", $$1, $$2}'

up: ## Start Docker containers
	docker compose up -d

down: ## Stop Docker containers
	docker compose down

restart: ## Restart Docker containers
	docker compose restart

logs: ## Follow container logs
	docker compose logs -f

shell: ## Open shell in app container
	docker compose exec app bash

install: up ## Install composer dependencies
	docker compose exec app composer install

test: ## Run tests
	docker compose exec app ./vendor/bin/pest

pint: ## Fix code style
	docker compose exec app ./vendor/bin/pint

pint-test: ## Check code style
	docker compose exec app ./vendor/bin/pint --test

lint: pint ## Alias for pint

autoload: ## Dump autoload
	docker compose exec app composer dump-autoload

clean: down ## Clean up containers and volumes
	docker compose down -v --remove-orphans

fresh: down up install ## Fresh environment setup
	@echo "Environment is ready!"

setup: fresh test ## Full setup with tests
	@echo "Setup complete!"

check: pint-test test ## Run all checks
	@echo "All checks passed!"
