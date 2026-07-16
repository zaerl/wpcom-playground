# WordPress.com Importer

This is a proof-of-concept WordPress plugin that let you install a Playground backup into a WordPress.com site.

## Tests

Try it on [Playground](https://playground.wordpress.net/#{%22landingPage%22:%22/wp-admin/tools.php?page=wpcom-playground%22,%22steps%22:[{%22step%22:%22login%22,%22username%22:%22admin%22,%22password%22:%22password%22},{%22step%22:%22installPlugin%22,%22pluginData%22:{%22resource%22:%22git:directory%22,%22url%22:%22https://github.com/zaerl/wpcom-playground%22,%22ref%22:%22HEAD%22},%22options%22:{%22activate%22:true,%22targetFolderName%22:%22wpcom-playground%22},%22progress%22:{%22caption%22:%22Installing%20plugin%20from%20GitHub:%20zaerl/wpcom-playground%22}}],%22meta%22:{%22title%22:%223%20more%20steps%22,%22author%22:%22https://github.com/akirk/playground-step-library%22},%22$schema%22:%22https://playground.wordpress.net/blueprint-schema.json%22}). Make some changes to
the embedded WordPress at `/wp-admin/tools.php?page=wpcom-playground` and select "Import".
