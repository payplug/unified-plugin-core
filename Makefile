IMAGE := unified-plugin-core-dev
DOCKER_RUN := docker run --rm -v $(CURDIR):/app -w /app -u "$$(id -u):$$(id -g)" -e COMPOSER_HOME=/tmp/composer $(IMAGE)

.PHONY: build install test stan cs-lint cs-fix quality shell

build:
	docker build -t $(IMAGE) .

install: build
	$(DOCKER_RUN) composer install

test: build
	$(DOCKER_RUN) composer test

stan: build
	$(DOCKER_RUN) composer stan

cs-lint: build
	$(DOCKER_RUN) composer cs-lint

cs-fix: build
	$(DOCKER_RUN) composer cs-fix

quality: cs-lint stan test

shell: build
	docker run --rm -it -v $(CURDIR):/app -w /app -u "$$(id -u):$$(id -g)" -e COMPOSER_HOME=/tmp/composer $(IMAGE) bash
