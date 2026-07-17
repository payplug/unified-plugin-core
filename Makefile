IMAGE := unified-plugin-core-dev
DOCKER_RUN := docker run --rm -v $(CURDIR):/app -w /app -u "$$(id -u):$$(id -g)" -e COMPOSER_HOME=/tmp/composer $(IMAGE)

IMAGE_PHP71_CHECK := unified-plugin-core-php71-check
DOCKER_RUN_PHP71_CHECK := docker run --rm -v $(CURDIR):/app -w /app -u "$$(id -u):$$(id -g)" $(IMAGE_PHP71_CHECK)

.PHONY: build install test test-integration coverage stan cs-lint cs-fix quality shell build-php71-check build-vendor-nodev verify-71

build:
	docker build -t $(IMAGE) .

install: build
	$(DOCKER_RUN) composer install

test: build
	$(DOCKER_RUN) composer test

test-integration: build
	$(DOCKER_RUN) composer test-integration

coverage: build
	$(DOCKER_RUN) composer test-coverage

stan: build
	$(DOCKER_RUN) composer stan

cs-lint: build
	$(DOCKER_RUN) composer cs-lint

cs-fix: build
	$(DOCKER_RUN) composer cs-fix

quality: cs-lint stan test

shell: build
	docker run --rm -it -v $(CURDIR):/app -w /app -u "$$(id -u):$$(id -g)" -e COMPOSER_HOME=/tmp/composer $(IMAGE) bash

build-php71-check:
	docker build -f Dockerfile.php71-check -t $(IMAGE_PHP71_CHECK) .

# Composer itself refuses to run below PHP 7.2.5, and this repo's own composer.json
# require.php (>=7.4) is the build-tooling floor, not the runtime floor — so there is
# no meaningful "composer install under PHP 7.1" for this project. What's actually
# useful: install a --no-dev vendor tree (what actually ships to merchants — dev
# tooling like phpunit/phpstan/cs-fixer never bundles into the plugin ZIP) into a
# separate vendor-nodev/ dir via COMPOSER_VENDOR_DIR, without touching the main dev
# vendor/, then boot it under a real PHP 7.1 interpreter. platform-check is disabled
# in composer.json (our own root floor is a build-tooling artifact, not a runtime
# one), so this only fails on a genuine syntax/behavior incompatibility.
build-vendor-nodev: build
	$(DOCKER_RUN) sh -c 'COMPOSER_VENDOR_DIR=vendor-nodev composer install --no-dev'

verify-71: build-vendor-nodev build-php71-check
	$(DOCKER_RUN_PHP71_CHECK) sh -c '\
		echo "Linting src/ and tests/ under PHP 7.1..." && \
		find src tests -name "*.php" -print0 | xargs -0 -n1 php -l && \
		echo "Booting vendor-nodev/autoload.php and exercising PhoneHelper/AmountHelper under PHP 7.1..." && \
		php scripts/verify-php71-smoke.php vendor-nodev/autoload.php'
