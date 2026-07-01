# WordPress.com Importer

This is a proof-of-concept WordPress plugin that let you install a Playground backup into a WordPress.com site.

## Tests

Start wp-env and install the test dependency once:

```sh
wp-env start
wp-env run tests-cli composer install --working-dir=/var/www/html/wp-content/plugins/wpcom-playground --no-interaction
```

Run the imported WordPress tests:

```sh
wp-env run tests-cli composer test --working-dir=/var/www/html/wp-content/plugins/wpcom-playground
```
