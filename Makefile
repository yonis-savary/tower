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

# The previous release answers during the build and the migrations : keep migrations compatible with it
# The PHP services only stop for the cache tasks, so that no old process writes its caches back. nginx
# and mysql keep running
deploy: Storage
	git pull
	$(MAKE) build
	$(DO) migrate:migrate
	$(PROD) stop php-fpm scheduler websocket push
	$(DO) cube:dispatch --event PostDeployment
	$(DO) configuration:cache
	$(PROD) up -d

exec:
	$(EXEC) bash

launch-scheduler:
	$(EXEC) php do routine:launch

sdk:
	$(EXEC) php do make:openapi
	cd Frontend && npm run api:generate
