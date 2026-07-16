<?php
/**
 * Playground_Importer file.
 *
 * @package wpcom-playground
 */

namespace WPCom\Playground;

require_once __DIR__ . '/class-backup-importer.php';
require_once __DIR__ . '/class-backup-import-action.php';
require_once __DIR__ . '/steps/class-playground-clean-up.php';
require_once __DIR__ . '/steps/class-playground-db-importer.php';
require_once __DIR__ . '/steps/class-playground-site-integrity-check.php';
require_once __DIR__ . '/steps/class-sql-importer.php';
require_once __DIR__ . '/steps/class-sql-postprocessor.php';
require_once __DIR__ . '/utils/class-filerestorer.php';
require_once __DIR__ . '/utils/logger/class-filelogger.php';

use WPCom\Playground\Utils\FileRestorer;
use WPCom\Playground\Utils\Logger\FileLogger;

/**
 * Playground backup importer.
 *
 * This class provides a common interface for all backup importers.
 */
class Playground_Importer extends Backup_Importer {
	const SQLITE_DB_PATH = 'wp-content/database/.ht.sqlite';

	/**
	 * File logger
	 *
	 * @var FileLogger
	 */
	private FileLogger $logger;

	/**
	 * Constructor.
	 *
	 * @param string $zip_or_tar_file_path The path to the ZIP or TAR file to be imported.
	 * @param string $destination_path The path where the backup will be imported.
	 * @param string $tmp_prefix       The table prefix to use when importing the database.
	 */
	public function __construct( string $zip_or_tar_file_path, string $destination_path, string $tmp_prefix ) {
		parent::__construct( $zip_or_tar_file_path, $destination_path, $tmp_prefix );
		error_log( 'Initializing Playground_Importer with zip_or_tar_file_path: ' . $zip_or_tar_file_path . ', destination_path: ' . $destination_path . ', tmp_prefix: ' . $tmp_prefix );

		$this->logger = new FileLogger();
		$this->logger->check_and_clear_file();

		$this->tmp_database = $this->destination_path . 'database.sql';
	}

	/**
	 * Preprocess the backup before importing.
	 *
	 * @return bool|\WP_Error True on success, or a WP_Error on failure.
	 */
	public function preprocess( $dry_run = false ) {
		error_log( 'Preprocessing backup: ' . $this->zip_or_tar_file_path . ', ' . $this->destination_path );

		if ( $dry_run ) {
			return true;
		}

		$db_path = $this->get_source_sqlite_database_path();

		if ( $this->uses_sqlite_database() ) {
			return $this->validate_sqlite_database_file( $db_path );
		}

		$options  = array(
			'output_mode' => SQL_Generator::OUTPUT_TYPE_FILE,
			'output_file' => $this->tmp_database,
			'tmp_tables'  => true,
			'tmp_prefix'  => $this->tmp_prefix,
		);
		$importer = new Playground_DB_Importer();
		$results  = $importer->generate_sql( $db_path, $options );

		return is_wp_error( $results ) ? $results : true;
	}

	/**
	 * Process the files in the backup.
	 *
	 * @return bool|\WP_Error True on success, or a WP_Error on failure.
	 */
	public function process_files( $dry_run = false ) {
		$final_path = $this->get_site_installation_path();

		if ( $dry_run ) {
			return true;
		}

		error_log( 'Processing files from: ' . $this->destination_path . ', ' . $final_path );
		$file_restorer = new FileRestorer( $this->destination_path, $final_path, $this->logger );
		$queue_result  = $file_restorer->enqueue_files();

		if ( is_wp_error( $queue_result ) ) {
			return $queue_result;
		}

		$restore_result = $file_restorer->restore_files();

		if ( is_wp_error( $restore_result ) ) {
			return $restore_result;
		}

		return true;
	}

	/**
	 * Recreate the database from the backup.
	 *
	 * @return bool|\WP_Error True on success, or a WP_Error on failure.
	 */
	public function recreate_database( $dry_run = false ) {
		if ( $dry_run ) {
			return true;
		}

		if ( $this->uses_sqlite_database() ) {
			$source = $this->get_source_sqlite_database_path();
			$target = $this->get_target_sqlite_database_path();

			$valid_source = $this->validate_sqlite_database_file( $source );
			if ( is_wp_error( $valid_source ) ) {
				return $valid_source;
			}

			$target_directory = dirname( $target );
			if ( ! is_dir( $target_directory ) && ! wp_mkdir_p( $target_directory ) ) {
				return new \WP_Error( 'database-directory-create-failed', __( 'Could not create the SQLite database directory.', 'wpcomsh' ) );
			}

			if ( ! copy( $source, $target ) ) {
				return new \WP_Error( 'database-copy-failed', __( 'Could not copy the SQLite database.', 'wpcomsh' ) );
			}

			clearstatcache( true, $target );

			return true;
		}

		error_log( 'Recreating database from: ' . $this->tmp_database );

		return SQL_Importer::import( $this->tmp_database );
	}

	/**
	 * Postprocess the database after importing.
	 *
	 * @return bool|\WP_Error True on success, or a WP_Error on failure.
	 */
	public function postprocess_database( $dry_run = false ) {
		if ( $dry_run ) {
			return true;
		}

		// The SQLite fast path replaces the complete database and does not create
		// the temporary tables required by SQL_Postprocessor.
		if ( $this->uses_sqlite_database() ) {
			return true;
		}

		$processor = new SQL_Postprocessor( get_home_url(), get_site_url(), $this->tmp_prefix, false, $this->logger );

		return $processor->postprocess();
	}

	/**
	 * Clean up after the import.
	 *
	 * @return bool|\WP_Error True on success, or a WP_Error on failure.
	 */
	public function clean_up( $dry_run = false ) {
		error_log( 'Cleaning up after import: ' . $this->zip_or_tar_file_path . ', ' . $this->destination_path );

		if ( $dry_run ) {
			return true;
		}

		return Playground_Clean_Up::remove_tmp_files( $this->zip_or_tar_file_path, $this->destination_path );
	}

	/**
	 * Verify the integrity of the site after importing.
	 *
	 * @return bool always true for now
	 */
	public function verify_site_integrity( $dry_run = false ) {
		error_log( 'Verifying site integrity after import: ' . $this->destination_path );

		if ( $dry_run ) {
			return true;
		}

		$checker = new Playground_Site_Integrity_Check( $this->logger );
		return $checker->check();
	}

	/**
	 * Return whether the specified folder is a valid Playground backup.
	 *
	 * @param string $destination_path The path where the backup will be imported.
	 *
	 * @return bool True if the specified folder is a valid backup, false otherwise.
	 */
	public static function is_valid( $destination_path ): bool {
		return file_exists( trailingslashit( $destination_path ) . self::SQLITE_DB_PATH );
	}

	/**
	 * Get the current WordPress installation path.
	 *
	 * @return string WordPress installation path.
	 */
	private function get_site_installation_path(): string {
		return trailingslashit( ABSPATH );
	}

	/**
	 * Check whether WordPress is using the SQLite database drop-in.
	 *
	 * @return bool Whether the SQLite database drop-in is active.
	 */
	protected function uses_sqlite_database(): bool {
		return defined( 'SQLITE_DB_DROPIN_VERSION' );
	}

	/**
	 * Get the SQLite database path from the extracted backup.
	 *
	 * @return string Source SQLite database path.
	 */
	private function get_source_sqlite_database_path(): string {
		return $this->destination_path . self::SQLITE_DB_PATH;
	}

	/**
	 * Get the SQLite database path used by the current WordPress site.
	 *
	 * @return string Target SQLite database path.
	 */
	protected function get_target_sqlite_database_path(): string {
		if ( defined( 'FQDB' ) ) {
			$database_path = constant( 'FQDB' );

			if ( is_string( $database_path ) && '' !== $database_path ) {
				return $database_path;
			}
		}

		return $this->get_site_installation_path() . self::SQLITE_DB_PATH;
	}

	/**
	 * Validate an SQLite database file before using it.
	 *
	 * @param string $database_path SQLite database path.
	 *
	 * @return bool|\WP_Error True when the file can be used, or an error.
	 */
	private function validate_sqlite_database_file( string $database_path ) {
		if ( ! is_file( $database_path ) || ! is_readable( $database_path ) ) {
			return new \WP_Error( 'database-file-not-exists', __( 'Database file does not exist.', 'wpcomsh' ) );
		}

		if ( filesize( $database_path ) <= 0 ) {
			return new \WP_Error( 'database-file-empty', __( 'Database file is empty.', 'wpcomsh' ) );
		}

		return true;
	}
}
