<?php
/**
 * SQL_Importer file.
 *
 * @package wpcom-playground
 */

namespace WPCom\Playground;

use WP_Error;

/**
 * Import a SQL dump in current database.
 */
class SQL_Importer extends Backup_Import_Action {
	/**
	 * Import the dump file.
	 *
	 * @param string $sql_file_path The path of the SQL file.
	 * @param bool   $verbose       Whether to run the command in verbose mode.
	 *
	 * @return bool|WP_Error
	 */
	public static function import( string $sql_file_path, $verbose = false ) {
		// Bail if the file doesn't exist.
		if ( ! is_file( $sql_file_path ) || ! is_readable( $sql_file_path ) ) {
			return new WP_Error( 'sql-file-not-exists', __( 'SQL file not exists', 'wpcomsh' ) );
		}

		if ( ! self::is_mysql_executable_available() ) {
			return self::import_with_mysqli( $sql_file_path );
		}

		$host = DB_HOST;
		$port = '';
		if ( preg_match( '/^(.+):(\d+)$/', $host, $m ) ) {
			$host = $m[1];
			$port = $m[2];
		}

		$output  = null;
		$ret     = null;
		$command = sprintf(
			'mysql -u %s%s -h %s%s %s%s < %s',
			escapeshellarg( DB_USER ),
			DB_PASSWORD === '' ? '' : ' -p' . escapeshellarg( DB_PASSWORD ),
			escapeshellarg( $host ),
			$port === '' ? '' : ' --port=' . escapeshellarg( $port ),
			escapeshellarg( DB_NAME ),
			$verbose ? '' : ' 2>&1',
			escapeshellarg( $sql_file_path )
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( $command, $output, $ret );

		return $ret === 0 ? true : new WP_Error(
			'sql-import-failed',
			__( 'SQL import failed', 'wpcomsh' ),
			array(
				'output' => $output,
				'status' => $ret,
			)
		);
	}

	/**
	 * Check whether the mysql executable is available.
	 *
	 * @return bool Whether the mysql executable is available.
	 */
	private static function is_mysql_executable_available(): bool {
		$output = null;
		$ret    = null;

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( 'command -v mysql 2>/dev/null', $output, $ret );

		return 0 === $ret && ! empty( $output );
	}

	/**
	 * Import the dump file using the mysqli extension.
	 *
	 * @param string $sql_file_path The path of the SQL file.
	 *
	 * @return bool|WP_Error
	 */
	private static function import_with_mysqli( string $sql_file_path ) {
		if ( ! extension_loaded( 'mysqli' ) ) {
			return new WP_Error( 'sql-import-failed', __( 'SQL import failed', 'wpcomsh' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$sql = file_get_contents( $sql_file_path );

		if ( false === $sql ) {
			return new WP_Error( 'sql-import-failed', __( 'SQL import failed', 'wpcomsh' ) );
		}

		if ( '' === trim( $sql ) ) {
			return true;
		}

		$host = DB_HOST;
		$port = null;
		if ( preg_match( '/^(.+):(\d+)$/', $host, $m ) ) {
			$host = $m[1];
			$port = (int) $m[2];
		}

		// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_init
		$mysqli = mysqli_init();
		if ( false === $mysqli ) {
			return new WP_Error( 'sql-import-failed', __( 'SQL import failed', 'wpcomsh' ) );
		}

		$connected = $mysqli->real_connect( $host, DB_USER, DB_PASSWORD, DB_NAME, $port );
		if ( ! $connected ) {
			return new WP_Error(
				'sql-import-failed',
				__( 'SQL import failed', 'wpcomsh' ),
				array(
					// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_connect_error
					'error' => mysqli_connect_error(),
				)
			);
		}

		if ( defined( 'DB_CHARSET' ) && DB_CHARSET ) {
			$mysqli->set_charset( DB_CHARSET );
		}

		if ( ! $mysqli->multi_query( $sql ) ) {
			$error = $mysqli->error;
			$mysqli->close();

			return new WP_Error(
				'sql-import-failed',
				__( 'SQL import failed', 'wpcomsh' ),
				array(
					'error' => $error,
				)
			);
		}

		do {
			$result = $mysqli->store_result();
			if ( $result ) {
				$result->free();
			}

			if ( $mysqli->errno ) {
				$error = $mysqli->error;
				$mysqli->close();

				return new WP_Error(
					'sql-import-failed',
					__( 'SQL import failed', 'wpcomsh' ),
					array(
						'error' => $error,
					)
				);
			}
		} while ( $mysqli->more_results() && $mysqli->next_result() );

		if ( $mysqli->errno ) {
			$error = $mysqli->error;
			$mysqli->close();

			return new WP_Error(
				'sql-import-failed',
				__( 'SQL import failed', 'wpcomsh' ),
				array(
					'error' => $error,
				)
			);
		}

		$mysqli->close();

		return true;
	}
}
