.PHONY: dev dev-down build up down deploy exec launch-scheduler sdk

# Production reads docker-compose.yml only, never the development override
PROD = docker compose -f docker-compose.yml
DO = $(PROD) run --rm php-fpm php do
EXEC = docker compose exec php-fpm

# Docker would create a missing mounted directory as root, which the containers could not write in
Storage:
	mkdir -p Storage

dev: Storage
	docker compose up -d --build
	@echo "Backend : http://$$(docker compose port nginx 80)"
	cd Frontend && npm run dev

dev-down:
	docker compose down

build:
	$(PROD) build

up: Storage
	$(PROD) up -d

down:
	$(PROD) down

deploy: Storage
	git pull
	$(MAKE) build
	$(MAKE) down
	$(DO) migrate:migrate
	$(DO) cube:dispatch --event PostDeployment
	$(DO) configuration:cache
	$(MAKE) up

exec:
	$(EXEC) bash

launch-scheduler:
	$(EXEC) php do routine:launch

sdk:
	$(EXEC) php do make:openapi
	cd Frontend && npm run api:generate
