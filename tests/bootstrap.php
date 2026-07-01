<?php
/**
 * PHPUnit bootstrap for wp-env.
 *
 * @package wpcom-playground
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = '/wordpress-phpunit';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php." . PHP_EOL;
	exit( 1 );
}

define( 'WPCOM_PLAYGROUND_TESTS_PLUGIN_DIR', dirname( __DIR__ ) );

$polyfills = WPCOM_PLAYGROUND_TESTS_PLUGIN_DIR . '/vendor/yoast/phpunit-polyfills';
if ( file_exists( $polyfills ) && ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $polyfills );
}

require_once $_tests_dir . '/includes/functions.php';

/**
 * Require import-related plugin files in dependency order.
 *
 * @return void
 */
function wpcom_playground_tests_require_import_files(): void {
	$plugin_dir = WPCOM_PLAYGROUND_TESTS_PLUGIN_DIR;
	$files      = array(
		'class-backup-import-action.php',
		'class-backup-importer.php',
		'utils/logger/class-logger-interface.php',
		'utils/logger/class-filelogger.php',
		'utils/class-fileextractor.php',
		'utils/class-filerestorer.php',
		'steps/class-sql-generator.php',
		'steps/class-playground-db-importer.php',
		'steps/class-playground-clean-up.php',
		'steps/class-sql-importer.php',
		'steps/class-sql-postprocessor.php',
		'steps/class-playground-site-integrity-check.php',
		'class-playground-importer.php',
		'class-backup-import-manager.php',
	);

	foreach ( $files as $file ) {
		require_once $plugin_dir . '/' . $file;
	}
}

/**
 * Preserve the old Imports namespace expected by the imported tests.
 *
 * @return void
 */
function wpcom_playground_tests_alias_import_classes(): void {
	$aliases = array(
		'WPCom\\Playground\\Backup_Import_Action'      => 'Imports\\Backup_Import_Action',
		'WPCom\\Playground\\Backup_Importer'           => 'Imports\\Backup_Importer',
		'WPCom\\Playground\\Backup_Import_Manager'     => 'Imports\\Backup_Import_Manager',
		'WPCom\\Playground\\Playground_Importer'       => 'Imports\\Playground_Importer',
		'WPCom\\Playground\\Playground_DB_Importer'    => 'Imports\\Playground_DB_Importer',
		'WPCom\\Playground\\Playground_Clean_Up'       => 'Imports\\Playground_Clean_Up',
		'WPCom\\Playground\\Playground_Site_Integrity_Check' => 'Imports\\Playground_Site_Integrity_Check',
		'WPCom\\Playground\\SQL_Generator'             => 'Imports\\SQL_Generator',
		'WPCom\\Playground\\SQL_Importer'              => 'Imports\\SQL_Importer',
		'WPCom\\Playground\\SQL_Postprocessor'         => 'Imports\\SQL_Postprocessor',
		'WPCom\\Playground\\Utils\\FileExtractor'      => 'Imports\\Utils\\FileExtractor',
		'WPCom\\Playground\\Utils\\FileRestorer'       => 'Imports\\Utils\\FileRestorer',
		'WPCom\\Playground\\Utils\\LoggerInterface'    => 'Imports\\Utils\\LoggerInterface',
		'WPCom\\Playground\\Utils\\Logger\\FileLogger' => 'Imports\\Utils\\Logger\\FileLogger',
	);

	foreach ( $aliases as $current => $legacy ) {
		if ( ( class_exists( $current ) || interface_exists( $current ) ) && ! class_exists( $legacy, false ) && ! interface_exists( $legacy, false ) ) {
			class_alias( $current, $legacy );
		}
	}
}

/**
 * Manually load the plugin during WordPress test bootstrap.
 *
 * @return void
 */
function wpcom_playground_tests_load_plugin(): void {
	require_once WPCOM_PLAYGROUND_TESTS_PLUGIN_DIR . '/wpcom-playground.php';
	require_once WPCOM_PLAYGROUND_TESTS_PLUGIN_DIR . '/tests/stubs/class-manager.php';
	wpcom_playground_tests_require_import_files();
	wpcom_playground_tests_alias_import_classes();
}
tests_add_filter( 'muplugins_loaded', 'wpcom_playground_tests_load_plugin' );

require_once $_tests_dir . '/includes/bootstrap.php';

// phpcs:enable
