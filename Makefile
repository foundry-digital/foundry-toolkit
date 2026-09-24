# Foundry Toolkit. `make check` is the only judge of done.
.PHONY: check lint analyse test zip release

VENDOR := vendor/bin

## check: coding standards, static analysis and the tests
check: vendor lint analyse test

vendor: composer.json composer.lock
	composer install --quiet
	@touch vendor

## lint: PHP_CodeSniffer, WordPress ruleset
lint: vendor
	$(VENDOR)/phpcs -q

## analyse: PHPStan level 8, PHP 7.4 semantics
analyse: vendor
	$(VENDOR)/phpstan analyse --no-progress --memory-limit=1G

## test: PHPUnit with Brain Monkey, no WordPress needed
test: vendor
	$(VENDOR)/phpunit --no-coverage

## zip: build build/foundry-toolkit.zip to upload in Plugins, Add New, Upload.
##      PUBLIC_KEY=<base64> bakes in a key, such as the fixture key for a test site.
zip:
	rm -rf build
	mkdir -p build/foundry-toolkit
	cp -R foundry-toolkit.php includes templates README.md build/foundry-toolkit/
	@if [ -n "$(PUBLIC_KEY)" ]; then \
		sed "s|'REPLACE_WITH_PUBLIC_KEY'|'$(PUBLIC_KEY)'|" foundry-toolkit.php > build/foundry-toolkit/foundry-toolkit.php; \
		grep -q "'$(PUBLIC_KEY)'" build/foundry-toolkit/foundry-toolkit.php; \
	fi
	cd build && zip -qr foundry-toolkit.zip foundry-toolkit
	@echo "built build/foundry-toolkit.zip"

## release: publish a signed release to GitHub (scripts/release.sh); make release VERSION=1.2.0
release:
	scripts/release.sh "$(VERSION)"
