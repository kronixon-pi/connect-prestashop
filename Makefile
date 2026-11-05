# Local work. PHP and the shops run in Docker; nothing but docker and bash is needed on the host.
#   make install            development tools into tools/vendor (composer in Docker)
#   make up PS=91           a shop with the module installed (178 | 81 | 82 | 90 | 91 | 92), left running
#   make test               PHPUnit on PHP 7.4 and 8.5
#   make check              lint + test + protocol (what module-ci runs, minus the shop)
#   make matrix             the five required PrestaShop versions, one after another
PS ?= 91
COMPOSE = docker compose -f docker/compose.$(PS).yml
PORT = $(shell sed -n 's/.*PS_PORT:-\([0-9]*\)}.*/\1/p' docker/compose.$(PS).yml | head -n 1)
RUN = docker run --rm -u $$(id -u):$$(id -g) -v "$$PWD:/app" -w /app
PHP74 = $(RUN) php:7.4-cli
PHP85 = $(RUN) php:8.5-cli
COMPOSER = $(RUN) -e COMPOSER_HOME=/tmp/composer composer:2
REQUIRED = 178 81 82 90 91

.PHONY: help install up down logs smoke matrix fixture bake test test-74 test-85 lint cs cs-fix compat stan protocol check

help:
	@sed -n 's/^#   //p' Makefile

install:
	$(COMPOSER) install --no-interaction --no-progress

up:
	scripts/ps-smoke.sh $(PS) --keep
	@echo "shop: http://localhost:$(PORT)/  admin: http://localhost:$(PORT)/admin-dev/  (make down PS=$(PS) to remove)"

down:
	$(COMPOSE) down -v --remove-orphans

logs:
	$(COMPOSE) logs --tail 100 ps

smoke:
	scripts/ps-smoke.sh $(PS)

matrix:
	@for ps in $(REQUIRED); do scripts/ps-smoke.sh $$ps || exit 1; done

fixture:
	$(COMPOSE) exec -T -u www-data -e PS_DOMAIN=localhost:$(PORT) ps php -d memory_limit=1G modules/kronixonconnect/tests/fixtures/generate.php </dev/null

bake:
	@test -n "$(TAG)" || { echo "usage: make bake TAG=9.1.5-8.4"; exit 2; }
	scripts/bake-fixture.sh $(TAG) ghcr.io/kronixon-pi/connect-ps-fixture:$(TAG)-$$(scripts/fixture-sha.sh)

test: test-74 test-85

test-74:
	$(PHP74) tools/vendor/bin/phpunit

test-85:
	$(PHP85) tools/vendor/bin/phpunit

lint: cs compat stan

cs:
	$(PHP74) tools/vendor/bin/php-cs-fixer check --diff --show-progress=none

cs-fix:
	$(PHP74) tools/vendor/bin/php-cs-fixer fix --show-progress=none

compat:
	$(PHP74) tools/vendor/bin/phpcs --standard=phpcs.xml.dist --report=summary

stan:
	$(PHP74) tools/vendor/bin/phpstan analyse --no-progress --memory-limit=1G

protocol:
	$(PHP85) tools/vendor/bin/phpunit --testsuite protocol

check: lint test
