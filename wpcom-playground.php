<?php
/**
 * WordPress.com Playground Importer plugin.
 *
 * @wordpress-plugin
 * Plugin Name:       WordPress.com Playground Importer
 * Description:       Import a Playground backup into a WordPress.com site.
 * Author:            Automattic
 * Author URI:        https://automattic.com
 * Version:           0.1.0
 * Requires at least: 7.0
 * Requires PHP:      8.3
 * Text Domain:       wpcom-playground
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package           wpcom-playground
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPCOM_PLAYGROUND_VERSION', '0.1.0' );

require_once __DIR__ . '/class-backup-import-manager.php';

/**
 * Instantiate the backup import manager as an early smoke test.
 *
 * This intentionally does not start an import. It only verifies that the class
 * can be loaded and constructed inside WordPress.
 */
function wpcom_playground_instantiate_backup_import_manager(): void {
	$GLOBALS['wpcom_playground_backup_import_manager'] = new \WPCom\Playground\Backup_Import_Manager(
		'',
		WP_CONTENT_DIR . '/wpcom-playground-import-smoke-test',
		array(
			'dry_run'       => true,
			'skip_clean_up' => true,
			'skip_unpack'   => true,
		)
	);
}
add_action( 'plugins_loaded', 'wpcom_playground_instantiate_backup_import_manager' );
