<?php
/**
 * BackupImportManagerTest file.
 *
 * @package wpcomsh
 */

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose

use Imports\Backup_Import_Manager;

/**
 * Class BackupImportManagerTest
 */
class BackupImportManagerTest extends WP_UnitTestCase {
	/**
	 * Clear values for each test
	 */
	public function tearDown(): void {
		delete_option( Backup_Import_Manager::$backup_import_status_option );
		delete_option( 'backup_import_status_lock' );

		parent::tearDown();
	}

	/**
	 * A resumable import executes only one step and persists the next step.
	 */
	public function test_resumable_import_executes_one_step(): void {
		$archive_path   = sys_get_temp_dir() . '/playground-resumable-' . wp_generate_password( 8, false ) . '.zip';
		$destination    = sys_get_temp_dir() . '/playground-resumable-' . wp_generate_password( 8, false );
		$archive        = new ZipArchive();
		$archive_opened = $archive->open( $archive_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );

		$this->assertTrue( $archive_opened );
		$archive->addFromString( 'wp-content/database/.ht.sqlite', 'sqlite-placeholder' );
		$archive->close();

		$manager = new Backup_Import_Manager(
			$archive_path,
			$destination,
			array(
				'bump_stats' => false,
				'dry_run'    => true,
				'import_id'  => 'test-import',
			)
		);
		$result  = $manager->import_next_step();
		$status  = Backup_Import_Manager::get_backup_import_status();

		$this->assertIsArray( $result );
		$this->assertFalse( $result['done'] );
		$this->assertSame( 'unpack_file', $result['completedStep'] );
		$this->assertSame( 'preprocess', $result['nextStep'] );
		$this->assertSame( 'preprocess', $status['status'] );
		$this->assertSame( 'pending', $status['phase'] );
		$this->assertSame( $archive_path, $status['source'] );
		$this->assertSame( array( 'unpack_file' ), $status['completed_steps'] );
		$this->assertFileExists( $destination . '/wp-content/database/.ht.sqlite' );

		$remaining_steps = array(
			'preprocess',
			'process_files',
			'recreate_database',
			'postprocess_database',
			'verify_site_integrity',
			'clean_up',
		);

		foreach ( $remaining_steps as $step ) {
			$result = $manager->import_next_step();
			$this->assertSame( $step, $result['completedStep'] );
		}

		$status = Backup_Import_Manager::get_backup_import_status();
		$this->assertTrue( $result['done'] );
		$this->assertNull( $result['nextStep'] );
		$this->assertSame( Backup_Import_Manager::SUCCESS, $status['status'] );
		$this->assertSame( 'completed', $status['phase'] );

		wp_delete_file( $archive_path );
		Imports\Playground_Clean_Up::remove_folder( $destination );
	}

	/**
	 * Open an empty path.
	 */
	public function test_error_open_an_empty_file_path() {
		$importer = new Backup_Import_Manager( '', sys_get_temp_dir(), array( 'bump_stats' => false ) );
		$result   = $importer->import();

		$this->assertWPError( $result );
		$this->assertEquals( 'file_not_exists', $result->get_error_code() );
	}

	/**
	 * Open a not existing path.
	 */
	public function test_error_open_a_not_existing_path() {
		$importer = new Backup_Import_Manager( uniqid() . '.tmp', sys_get_temp_dir(), array( 'bump_stats' => false ) );
		$result   = $importer->import();

		$this->assertWPError( $result );
		$this->assertEquals( 'file_not_exists', $result->get_error_code() );
	}

	/**
	 * Open a file with no valid extension.
	 */
	public function test_error_open_a_file_with_no_valid_extension() {
		$tmp_file  = tmpfile();
		$meta_data = stream_get_meta_data( $tmp_file );
		$tmp_path  = $meta_data['uri'];
		$importer  = new Backup_Import_Manager( $tmp_path, sys_get_temp_dir(), array( 'bump_stats' => false ) );
		$result    = $importer->import();

		$this->assertWPError( $result );
		$this->assertEquals( 'file_type_not_supported', $result->get_error_code() );

		fclose( $tmp_file );
	}

	/**
	 * Reset an import status.
	 */
	public function test_reset_import_status() {
		// Set the initial import status.
		update_option(
			Backup_Import_Manager::$backup_import_status_option,
			array(
				'status' => Backup_Import_Manager::SUCCESS,
			)
		);
		// Call the method we're testing.
		$result = Backup_Import_Manager::reset_import_status();

		// Assert that the method returned true, indicating success.
		$this->assertTrue( $result );

		// Assert that the import status option was deleted.
		$this->assertFalse( get_option( Backup_Import_Manager::$backup_import_status_option ) );
	}

	/**
	 * Reset an import status with no backup import.
	 */
	public function test_reset_import_status_with_no_backup_import() {
		// Ensure the import status option is empty.
		delete_option( Backup_Import_Manager::$backup_import_status_option );

		// Call the method we're testing.
		$result = Backup_Import_Manager::reset_import_status();

		// Assert that the method returned a WP_Error object with the 'no_backup_import_found' error code.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'no_backup_import_found', $result->get_error_code() );
	}

	/**
	 * Test if an import is cancelled.
	 */
	public function test_is_import_cancelled() {
		// Set the import status to 'cancelled'.
		update_option(
			Backup_Import_Manager::$backup_import_status_option,
			array(
				'status' => Backup_Import_Manager::CANCELLED,
			)
		);

		// Call the method we're testing.
		$importer = new Backup_Import_Manager( uniqid() . '.tmp', sys_get_temp_dir(), array( 'bump_stats' => false ) );
		$result   = $importer->is_import_cancelled();

		$this->assertTrue( $result );

		// Set the import status to one of the actions.
		update_option(
			Backup_Import_Manager::$backup_import_status_option,
			array(
				'status' => 'process_files',
			)
		);

		// Call the method we're testing.
		$result = $importer->is_import_cancelled();

		// Assert that the method returned false.
		$this->assertFalse( $result );
	}

	/**
	 * Test if an import is cancelled with no backup import.
	 */
	public function test_is_import_cancelled_with_no_backup_import() {
		// Ensure the import status option is empty.
		delete_option( Backup_Import_Manager::$backup_import_status_option );

		// Call the method we're testing.
		$importer = new Backup_Import_Manager( uniqid() . '.tmp', sys_get_temp_dir(), array( 'bump_stats' => false ) );
		$result   = $importer->is_import_cancelled();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'no_backup_import_found', $result->get_error_code() );
	}
}

// phpcs:enable
