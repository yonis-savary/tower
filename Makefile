.PHONY: dev dev-down build up down deploy exec launch-scheduler sdk

# Production reads docker-compose.yml only, never the development override
PROD = docker compose -f docker-compose.yml
DO = $(PROD) run --rm php-fpm php do
EXEC = docker compose exec php-fpm

# make exec <command> : the words after exec are the command, not targets. A command with options
# goes in quotes (make exec "php do migrate:rollback --steps=2"), make would read them as its own
ifeq (exec,$(firstword $(MAKECMDGOALS)))
COMMAND := $(wordlist 2,$(words $(MAKECMDGOALS)),$(MAKECMDGOALS))
$(eval .PHONY: $(subst :,\:,$(COMMAND)))
$(eval $(subst :,\:,$(COMMAND)):;@:)
.DEFAULT: ; @:
endif

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

# In the running php-fpm container, else in a one-off one (first install, stopped stack)
exec:
	@if [ -n "$$(docker compose ps -q --status running php-fpm 2>/dev/null)" ]; then \
		$(EXEC) $(or $(COMMAND),bash); \
	else \
		$(PROD) run --rm php-fpm $(or $(COMMAND),bash); \
	fi

launch-scheduler:
	$(EXEC) php do routine:launch

sdk:
	$(EXEC) php do make:openapi
	cd Frontend && npm run api:generate
