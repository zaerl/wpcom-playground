<?php
/**
 * Backup_Import_Manager file.
 *
 * @package wpcom-playground
 */

namespace WPCom\Playground;

// Include the file extractor.
require_once __DIR__ . '/utils/class-fileextractor.php';

use WP_Error;

/**
 * Class Backup_Import_Manager
 *
 * This class is responsible for managing the import of backups.
 */
class Backup_Import_Manager {
	/**
	 * The path to the ZIP or TAR file to be imported.
	 *
	 * @var string
	 */
	protected $zip_or_tar_file_path;
	/**
	 * The path where the backup will be imported.
	 *
	 * @var string
	 */
	protected $destination_path;
	/**
	 * An array of actions that the importer needs to perform.
	 *
	 * @var array
	 */
	protected $importer_actions = array(
		'preprocess',
		'process_files',
		'recreate_database',
		'postprocess_database',
		'verify_site_integrity',
		'clean_up',
	);
	/**
	 * An array of options.
	 *
	 * @var array
	 */
	protected $options = array();

	/**
	 * An array of valid option keys.
	 *
	 * @var array
	 */
	protected $valid_option_keys = array(
		'actions',
		'bump_stats',
		'dry_run',
		'import_id',
		'skip_clean_up',
		'skip_unpack',
	);

	/**
	 * Importer type.
	 *
	 * @var string
	 */
	protected $importer_type = null;

	/**
	 * Constant representing the WordPress Playground importer type.
	 */
	const WORDPRESS_PLAYGROUND = 'wordpress_playground';

	/**
	 * The prefix to use for temporary databases.
	 */
	const TEMPORARY_DB_PREFIX = 'tmp_';

	/**
	 * Constant representing the success status.
	 */
	const SUCCESS = 'success';

	/**
	 * Constant representing the failed status.
	 */
	const FAILED = 'failed';

	/**
	 * Constant representing the cancelled status.
	 */
	const CANCELLED = 'cancelled';

	/**
	 * Backup import status option name.
	 *
	 * @var string
	 */
	public static $backup_import_status_option = 'backup_import_status';

	/**
	 * Option used to prevent concurrent resumable import requests.
	 *
	 * @var string
	 */
	private static $backup_import_lock_option = 'backup_import_status_lock';

	/**
	 * Number of seconds after which an abandoned resumable import lock expires.
	 *
	 * @var int
	 */
	private const IMPORT_LOCK_TTL = 300;

	/**
	 * Resumable import mode identifier.
	 *
	 * @var string
	 */
	public const RESUMABLE_MODE = 'resumable';

	/**
	 * Constructor for the Backup_Import_Manager class.
	 *
	 * This method initializes the $zip_or_tar_file_path and $destination_path properties.
	 *
	 * @param string $zip_or_tar_file_path The path to the ZIP or TAR file to be imported.
	 * @param string $destination_path The path where the backup will be imported.
	 * @param array  $options An array of options.
	 */
	public function __construct( $zip_or_tar_file_path, $destination_path, $options = array() ) {
		$this->zip_or_tar_file_path = $zip_or_tar_file_path;
		$this->destination_path     = trailingslashit( $destination_path );
		$this->options              = array_intersect_key( $options, array_flip( $this->valid_option_keys ) );
	}
	/**
	 * Import the backup.
	 *
	 * This method performs the following steps:
	 * 1. Extract the ZIP or TAR file to the destination path.
	 * 2. Determine the type of the importer based on the destination path.
	 * 3. Get an instance of the appropriate importer based on the type.
	 * 4. Call the importer's methods in the order specified in the $importer_actions array.
	 *
	 * @return bool|WP_Error True on success, or a WP_Error on failure.
	 */
	public function import() {
		$skip_clean_up = false;
		if ( isset( $this->options['skip_clean_up'] ) && is_bool( $this->options['skip_clean_up'] ) ) {
			$skip_clean_up = $this->options['skip_clean_up'];
		}

		$skip_unpack = false;
		if ( isset( $this->options['skip_unpack'] ) && is_bool( $this->options['skip_unpack'] ) ) {
			$skip_unpack = $this->options['skip_unpack'];
		}

		$bump_stats = true;
		if ( isset( $this->options['bump_stats'] ) && is_bool( $this->options['bump_stats'] ) ) {
			$bump_stats = $this->options['bump_stats'];
		}

		// Check if there are import process that's already running.
		$check_bail_result = $this->should_bail_out();

		if ( is_wp_error( $check_bail_result ) ) {
			// We don't update status to failed here, because we don't want to overwrite the status.

			if ( $bump_stats ) {
				$this->bump_import_stats( $check_bail_result->get_error_code() );
			}

			return $check_bail_result;
		}

		// Reset the import status before everything starts.
		self::delete_backup_import_status();

		// Unzip/untar the file.
		if ( ! $skip_unpack ) {
			$this->update_status( array( 'status' => 'unpack_file' ) );
			$result = Utils\FileExtractor::extract( $this->zip_or_tar_file_path, $this->destination_path );

			if ( is_wp_error( $result ) ) {
				$this->update_status( array( 'status' => self::FAILED ) );

				if ( $bump_stats ) {
					$this->bump_import_stats( $result->get_error_code() );
				}

				return $result;
			}
		}

		// Validate the type of the file.
		$importer_type = self::determine_importer_type( $this->destination_path );
		if ( is_wp_error( $importer_type ) ) {
			$this->update_status( array( 'status' => self::FAILED ) );

			if ( $bump_stats ) {
				$this->bump_import_stats( $importer_type->get_error_code() );
			}

			return $importer_type;
		}

		// Get the importer.
		$importer = self::get_importer( $importer_type, $this->zip_or_tar_file_path, $this->destination_path );
		if ( is_wp_error( $importer ) ) {
			$this->update_status( array( 'status' => self::FAILED ) );

			if ( $bump_stats ) {
				$this->bump_import_stats( $importer->get_error_code() );
			}

			return $importer;
		} else {
			$this->importer_type = $importer_type;
		}

		$execute_actions = isset( $this->options['actions'] ) && count( $this->options['actions'] ) ? $this->options['actions'] : $this->importer_actions;
		$dry_run         = isset( $this->options['dry_run'] ) && $this->options['dry_run'];

		if ( $skip_clean_up ) {
			foreach ( $execute_actions as $key => $action ) {
				// Remove the cleanup action if the user has specified to skip cleanup.
				if ( $action === 'clean_up' ) {
					unset( $execute_actions[ $key ] );
				}
			}
		}

		foreach ( $execute_actions as $action ) {
			if ( ! method_exists( $importer, $action ) ) {
				continue;
			}

			// Before calling the importer's method, let's check if the status is cancelled.
			$cancel_result = $this->is_import_cancelled();

			if ( true === $cancel_result ) {
				// Clear the status.
				self::delete_backup_import_status();

				if ( $bump_stats ) {
					$this->bump_import_stats( 'backup_import_cancelled' );
				}

				return new WP_Error( 'backup_import_cancelled', __( 'The backup import has been cancelled.', 'wpcomsh' ) );
			}

			$this->update_status( array( 'status' => $action ) );

			$result = $importer->$action( $dry_run );

			if ( is_wp_error( $result ) ) {
				$this->update_status( array( 'status' => self::FAILED ) );

				if ( $bump_stats ) {
					$this->bump_import_stats( $result->get_error_code() );
				}

				return $result;
			}
		}

		if ( $bump_stats ) {
			$this->bump_import_stats( 'success' );
		}

		return $this->update_status( array( 'status' => self::SUCCESS ) );
	}

	/**
	 * Execute the next step of a resumable backup import.
	 *
	 * Unlike import(), this method never executes more than one import step. The
	 * import identity, destination, and next step are persisted in the backup
	 * import status option so a later request can continue the same import.
	 *
	 * @return array|WP_Error Step result on success, or a WP_Error on failure.
	 */
	public function import_next_step() {
		$lock = $this->acquire_import_lock();

		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		try {
			$state = $this->get_or_create_resumable_state();

			if ( is_wp_error( $state ) ) {
				return $state;
			}

			if ( self::SUCCESS === $state['status'] ) {
				return $this->get_resumable_step_result( $state, null );
			}

			if ( self::CANCELLED === $state['status'] ) {
				return new WP_Error(
					'backup_import_cancelled',
					__( 'The backup import has been cancelled.', 'wpcomsh' ),
					array( 'status' => 409 )
				);
			}

			$step = isset( $state['next_step'] ) ? $state['next_step'] : null;

			if ( ! is_string( $step ) || '' === $step ) {
				return $this->fail_resumable_import(
					$state,
					new WP_Error( 'invalid_import_step', __( 'The next backup import step is invalid.', 'wpcomsh' ) )
				);
			}

			$state['status']     = $step;
			$state['phase']      = 'running';
			$state['updated_at'] = time();
			$this->replace_status( $state );

			$result = $this->execute_resumable_step( $step );

			if ( is_wp_error( $result ) ) {
				return $this->fail_resumable_import( $state, $result );
			}

			$completed_steps   = isset( $state['completed_steps'] ) && is_array( $state['completed_steps'] ) ? $state['completed_steps'] : array();
			$completed_steps[] = $step;
			$completed_steps   = array_values( array_unique( $completed_steps ) );
			$steps             = isset( $state['steps'] ) && is_array( $state['steps'] ) ? $state['steps'] : array();
			$next_index        = array_search( $step, $steps, true );
			$next_index        = false === $next_index ? count( $steps ) : $next_index + 1;
			$next_step         = isset( $steps[ $next_index ] ) ? $steps[ $next_index ] : null;

			$state['completed_steps'] = $completed_steps;
			$state['next_step']       = $next_step;
			$state['phase']           = null === $next_step ? 'completed' : 'pending';
			$state['status']          = null === $next_step ? self::SUCCESS : $next_step;
			$state['updated_at']      = time();
			unset( $state['error'], $state['failed_step'] );
			$this->replace_status( $state );

			if ( null === $next_step && $this->should_bump_stats() ) {
				$this->bump_import_stats( self::SUCCESS );
			}

			return $this->get_resumable_step_result( $state, $step );
		} finally {
			$this->release_import_lock( $lock );
		}
	}

	/**
	 * Get an existing resumable state or initialize a new one.
	 *
	 * @return array|WP_Error Resumable state or an error.
	 */
	private function get_or_create_resumable_state() {
		$state     = self::get_backup_import_status();
		$import_id = isset( $this->options['import_id'] ) ? (string) $this->options['import_id'] : '';

		if ( is_array( $state ) && isset( $state['mode'] ) && self::RESUMABLE_MODE === $state['mode'] ) {
			$state_import_id = isset( $state['import_id'] ) ? (string) $state['import_id'] : '';

			if ( $state_import_id === $import_id && '' !== $import_id ) {
				if ( isset( $state['destination'] ) && trailingslashit( $state['destination'] ) !== $this->destination_path ) {
					return new WP_Error( 'invalid_import_destination', __( 'The backup import destination does not match.', 'wpcomsh' ) );
				}
				if ( isset( $state['source'] ) && $state['source'] !== $this->zip_or_tar_file_path ) {
					return new WP_Error( 'invalid_import_source', __( 'The backup import source does not match.', 'wpcomsh' ) );
				}

				return $state;
			}

			if ( ! in_array( $state['status'], array( self::SUCCESS, self::FAILED, self::CANCELLED ), true ) ) {
				return new WP_Error( 'import_in_progress', __( 'An import is already running.', 'wpcomsh' ), array( 'status' => 409 ) );
			}
		}

		if ( is_array( $state ) && ( ! isset( $state['mode'] ) || self::RESUMABLE_MODE !== $state['mode'] ) ) {
			$active_statuses = array_merge( array( 'unpack_file' ), $this->importer_actions );

			if ( isset( $state['status'] ) && in_array( $state['status'], $active_statuses, true ) ) {
				return new WP_Error( 'import_in_progress', __( 'An import is already running.', 'wpcomsh' ), array( 'status' => 409 ) );
			}
		}

		if ( '' === $import_id ) {
			return new WP_Error( 'missing_import_id', __( 'A resumable backup import requires an import ID.', 'wpcomsh' ) );
		}

		$steps = $this->get_resumable_steps();

		if ( empty( $steps ) ) {
			return new WP_Error( 'no_import_steps', __( 'The backup import has no steps to execute.', 'wpcomsh' ) );
		}

		$state = array(
			'status'          => $steps[0],
			'mode'            => self::RESUMABLE_MODE,
			'phase'           => 'pending',
			'import_id'       => $import_id,
			'source'          => $this->zip_or_tar_file_path,
			'destination'     => untrailingslashit( $this->destination_path ),
			'steps'           => $steps,
			'completed_steps' => array(),
			'next_step'       => $steps[0],
			'started_at'      => time(),
			'updated_at'      => time(),
		);

		$this->replace_status( $state );

		return $state;
	}

	/**
	 * Get the ordered steps for a resumable import.
	 *
	 * @return string[] Import steps.
	 */
	private function get_resumable_steps(): array {
		$steps = isset( $this->options['actions'] ) && count( $this->options['actions'] ) ? $this->options['actions'] : $this->importer_actions;

		if ( ! empty( $this->options['skip_clean_up'] ) ) {
			$steps = array_values( array_diff( $steps, array( 'clean_up' ) ) );
		}

		if ( empty( $this->options['skip_unpack'] ) ) {
			array_unshift( $steps, 'unpack_file' );
		}

		return array_values( array_unique( $steps ) );
	}

	/**
	 * Execute one resumable import step.
	 *
	 * @param string $step Step name.
	 *
	 * @return bool|WP_Error True on success, or an error.
	 */
	private function execute_resumable_step( string $step ) {
		if ( 'unpack_file' === $step ) {
			return Utils\FileExtractor::extract( $this->zip_or_tar_file_path, $this->destination_path );
		}

		$importer_type = self::determine_importer_type( $this->destination_path );

		if ( is_wp_error( $importer_type ) ) {
			return $importer_type;
		}

		$importer = self::get_importer( $importer_type, $this->zip_or_tar_file_path, $this->destination_path );

		if ( is_wp_error( $importer ) ) {
			return $importer;
		}

		$this->importer_type = $importer_type;

		if ( ! method_exists( $importer, $step ) ) {
			return new WP_Error( 'invalid_import_step', __( 'The requested backup import step does not exist.', 'wpcomsh' ) );
		}

		$dry_run = isset( $this->options['dry_run'] ) && $this->options['dry_run'];

		return $importer->$step( $dry_run );
	}

	/**
	 * Mark a resumable import as failed.
	 *
	 * @param array    $state Current state.
	 * @param WP_Error $error Import error.
	 *
	 * @return WP_Error The original error.
	 */
	private function fail_resumable_import( array $state, WP_Error $error ): WP_Error {
		$state['status']      = self::FAILED;
		$state['phase']       = 'failed';
		$state['failed_step'] = isset( $state['next_step'] ) ? $state['next_step'] : null;
		$state['error']       = array(
			'code'    => $error->get_error_code(),
			'message' => $error->get_error_message(),
		);
		$state['updated_at']  = time();
		$this->replace_status( $state );

		if ( $this->should_bump_stats() ) {
			$this->bump_import_stats( $error->get_error_code() );
		}

		return $error;
	}

	/**
	 * Format a resumable step response.
	 *
	 * @param array       $state          Current state.
	 * @param string|null $completed_step Step completed by this request.
	 *
	 * @return array Step response.
	 */
	private function get_resumable_step_result( array $state, $completed_step ): array {
		return array(
			'success'       => true,
			'done'          => self::SUCCESS === $state['status'],
			'status'        => $state['status'],
			'completedStep' => $completed_step,
			'nextStep'      => isset( $state['next_step'] ) ? $state['next_step'] : null,
		);
	}

	/**
	 * Whether import statistics should be recorded.
	 *
	 * @return bool Whether statistics should be recorded.
	 */
	private function should_bump_stats(): bool {
		return ! isset( $this->options['bump_stats'] ) || true === $this->options['bump_stats'];
	}

	/**
	 * Replace the complete backup import status.
	 *
	 * @param array $state New status state.
	 *
	 * @return void
	 */
	private function replace_status( array $state ): void {
		update_option( self::$backup_import_status_option, $state );
		self::force_cache_unset();
	}

	/**
	 * Acquire the resumable import execution lock.
	 *
	 * @return string|WP_Error Lock token or an error.
	 */
	private function acquire_import_lock() {
		$token = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'backup-import-', true );
		$lock  = get_option( self::$backup_import_lock_option, null );

		if ( is_array( $lock ) && isset( $lock['created_at'] ) && (int) $lock['created_at'] < time() - self::IMPORT_LOCK_TTL ) {
			delete_option( self::$backup_import_lock_option );
			$lock = null;
		}

		$new_lock = array(
			'token'      => $token,
			'created_at' => time(),
		);

		if ( null !== $lock || ! add_option( self::$backup_import_lock_option, $new_lock, '', false ) ) {
			return new WP_Error(
				'import_step_in_progress',
				__( 'A backup import step is already running.', 'wpcomsh' ),
				array( 'status' => 409 )
			);
		}

		return $token;
	}

	/**
	 * Release the resumable import execution lock.
	 *
	 * @param string $token Lock token.
	 *
	 * @return void
	 */
	private function release_import_lock( string $token ): void {
		$lock = get_option( self::$backup_import_lock_option, null );

		if ( is_array( $lock ) && isset( $lock['token'] ) && hash_equals( $lock['token'], $token ) ) {
			delete_option( self::$backup_import_lock_option );
		}
	}

	/**
	 * Updates the deployment status option.
	 *
	 * @param array $content The contents to be merged to the existing option.
	 *
	 * @return bool
	 */
	private function update_status( array $content ): bool {
		$existing = \get_option( self::$backup_import_status_option, array() );
		$new      = array_merge( $existing, $content );

		\update_option( self::$backup_import_status_option, $new );
		self::force_cache_unset();

		return true;
	}

	/**
	 * Bump the import stats.
	 *
	 * @param string $status The status of the import.
	 *
	 * @return bool|WP_Error True on success, or a WP_Error on failure.
	 */
	private function bump_import_stats( string $status ) {
		if ( isset( $this->options['dry_run'] ) && $this->options['dry_run'] ) {
			return true;
		}

		do_action( 'wpcom_playground_backup_import_stats', $status, $this->importer_type );

		return true;
	}

	/**
	 * Determine the type of the importer based on the file in destination path.
	 *
	 * @param string $destination_path The path where the backup will be imported.
	 *
	 * @return string|WP_Error The type of the importer or a WP_Error if the type could not be determined.
	 */
	public static function determine_importer_type( $destination_path ) {
		require_once __DIR__ . '/class-playground-importer.php';

		if ( file_exists( $destination_path . Playground_Importer::SQLITE_DB_PATH ) ) {
			return self::WORDPRESS_PLAYGROUND;
		}

		return new WP_Error( 'unknown_importer_type', __( 'Could not determine importer type.', 'wpcomsh' ) );
	}

	/**
	 * Get an instance of the appropriate importer based on the type.
	 *
	 * @param string $type The type of the importer.
	 * @param string $zip_or_tar_file_path The path to the ZIP or TAR file to be imported.
	 * @param string $destination_path The path where the backup will be imported.
	 *
	 * @return Backup_Importer|WP_Error An instance of the appropriate importer or a WP_Error if the type is unknown.
	 */
	public static function get_importer( string $type, string $zip_or_tar_file_path, string $destination_path ) {
		switch ( $type ) {
			case self::WORDPRESS_PLAYGROUND:
				require_once __DIR__ . '/class-playground-importer.php';
				return new Playground_Importer( $zip_or_tar_file_path, $destination_path, self::TEMPORARY_DB_PREFIX );

			default:
				return new WP_Error( 'unknown_importer_type', __( 'Could not determine importer type.', 'wpcomsh' ) );
		}
	}

	/**
	 * Checks if an import process is already running.
	 *
	 * @return false|WP_Error Returns WP_Error if an import process is running, false otherwise.
	 */
	private function should_bail_out() {
		$additional_status_to_check = array( 'unpack_file' );
		$import_status              = self::get_backup_import_status();
		$import_in_progress         = false;

		if ( ! empty( $import_status ) ) {
			// Check if the status is one of other status.
			if ( in_array( $import_status['status'], $additional_status_to_check, true ) ) {
				$import_in_progress = true;
			}
			// Check if the status is one of the actions.
			if ( in_array( $import_status['status'], $this->importer_actions, true ) ) {
				$import_in_progress = true;
			}
		}

		if ( $import_in_progress ) {
			return new WP_Error( 'import_in_progress', __( 'An import is already running.', 'wpcomsh' ) );
		}
		return false;
	}

	/**
	 * Reset the import status.
	 *
	 * @return bool|WP_Error True on success, or a WP_Error on failure.
	 */
	public static function reset_import_status() {
		$backup_import_status = self::get_backup_import_status();

		if ( empty( $backup_import_status ) ) {
			return new WP_Error( 'no_backup_import_found', __( 'No backup import found.', 'wpcomsh' ) );
		}

		if ( $backup_import_status['status'] === self::SUCCESS || $backup_import_status['status'] === self::FAILED ) {
			// If it's a success or failed, we can delete the option directly.
			self::delete_backup_import_status();
		} else {
			// Otherwise we set the status to cancelled and update the option.
			$backup_import_status['status'] = self::CANCELLED;
			if ( isset( $backup_import_status['mode'] ) && self::RESUMABLE_MODE === $backup_import_status['mode'] ) {
				$backup_import_status['phase']      = 'cancelled';
				$backup_import_status['updated_at'] = time();
			}
			update_option(
				self::$backup_import_status_option,
				$backup_import_status,
			);
			self::force_cache_unset();
		}

		return true;
	}

	/**
	 * Deletes the backup import status option.
	 *
	 * @return void
	 */
	public static function delete_backup_import_status() {
		delete_option( self::$backup_import_status_option );
		self::force_cache_unset();
	}

	/**
	 * Checks if the import process has been cancelled.
	 *
	 * @return mixed Returns WP_Error if the import has been cancelled, false otherwise.
	 */
	public function is_import_cancelled() {
		$backup_import_status = self::get_backup_import_status();

		if ( empty( $backup_import_status ) ) {
			// The import status doesn't exist, so we should stop here.
			return new WP_Error( 'no_backup_import_found', __( 'No backup import found.', 'wpcomsh' ) );
		}

		if ( isset( $backup_import_status['status'] ) && $backup_import_status['status'] === self::CANCELLED ) {
			// The import has been cancelled, so we should stop here.
			return true;
		}

		return false;
	}
	/**
	 * Get the backup import status.
	 *
	 * @return array|null Returns the backup import status or null if it doesn't exist.
	 */
	public static function get_backup_import_status() {
		$backup_import_status = get_option( self::$backup_import_status_option, null );

		if ( is_array( $backup_import_status ) ) {
			return $backup_import_status;
		}

		return null;
	}
	/**
	 * Force unset the cache for the backup import status option.
	 *
	 * @return void
	 */
	public static function force_cache_unset() {
		$alloptions = wp_load_alloptions();

		if ( isset( $alloptions[ self::$backup_import_status_option ] ) ) {
			unset( $alloptions[ self::$backup_import_status_option ] );
			wp_cache_set( 'alloptions', $alloptions, 'options' );
		} else {
			wp_cache_delete( self::$backup_import_status_option, 'options' );
		}
	}
}
