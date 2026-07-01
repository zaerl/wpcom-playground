# AGENTS.md

This file provides guidance to AI coding agents when working with code in this repository.

## Project Overview

This is a proof-of-concept WordPress plugin that let you install a Playground backup into a WordPress.com site.

## Local Environment

This project is developed and tested through `wp-env`. The plugin is mounted at:

```text
/var/www/html/wp-content/plugins/wpcom-playground
```

Start the WordPress environment before running WordPress-aware commands:

```sh
wp-env start
```

Install Composer dependencies inside the wp-env test container:

```sh
wp-env run tests-cli composer install --working-dir=/var/www/html/wp-content/plugins/wpcom-playground --no-interaction
```

## Verification Commands

Run the imported WordPress PHPUnit suite:

```sh
wp-env run tests-cli composer test --working-dir=/var/www/html/wp-content/plugins/wpcom-playground
```

Run PHP coding standards checks:

```sh
wp-env run tests-cli composer lint:php --working-dir=/var/www/html/wp-content/plugins/wpcom-playground
```

Run a syntax check for all non-vendor PHP files:

```sh
find . -name '*.php' -not -path './vendor/*' -exec php -l {} \;
```

## Coding Standards

PHPCS is configured in `phpcs.xml.dist` with WordPress Coding Standards and PHPCompatibilityWP.

The Yoda condition sniff is intentionally disabled:

```xml
<exclude name="WordPress.PHP.YodaConditions"/>
```

Generated Composer dependencies live in `vendor/` and should stay untracked.

## Imported Tests

The tests were imported from the wpcomsh project. They still reference the old `Imports\...` namespace, which is bridged in `tests/bootstrap.php` with class aliases to the current `WPCom\Playground\...` classes.

SQLite test fixtures live under:

```text
tests/fixtures/valid/
tests/fixtures/invalid/
```

SQLite files are binary and are marked as such in `.gitattributes`.
