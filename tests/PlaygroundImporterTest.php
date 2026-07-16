<?php
/**
 * PlaygroundImporterTest file.
 *
 * @package wpcomsh
 */

use Imports\Playground_Importer;

// These tests intentionally use local temporary files.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

/**
 * Class PlaygroundImporterTest
 */
class PlaygroundImporterTest extends WP_UnitTestCase {
	/**
	 * Temporary paths created by a test.
	 *
	 * @var string[]
	 */
	private array $temporary_paths = array();

	/**
	 * Remove temporary test folders.
	 */
	public function tearDown(): void {
		foreach ( $this->temporary_paths as $path ) {
			if ( is_dir( $path ) ) {
				Imports\Playground_Clean_Up::remove_folder( $path );
			}
		}

		parent::tearDown();
	}

	/**
	 * Open an empty path.
	 */
	public function test_error_open_an_empty_file() {
		$importer = new Playground_Importer( 'rand-file', sys_get_temp_dir(), 'test_' );
		$result   = $importer->preprocess();

		$this->assertWPError( $result );
		$this->assertEquals( 'database-file-not-exists', $result->get_error_code() );
	}

	/**
	 * Test a not existing path.
	 */
	public function test_error_not_valid_backup() {
		$this->assertFalse( Playground_Importer::is_valid( '.' ) );
	}

	/**
	 * Test that file restoration targets the current WordPress installation.
	 */
	public function test_site_installation_path_uses_wordpress_installation_path() {
		$importer = new Playground_Importer( 'rand-file', sys_get_temp_dir(), 'test_' );
		$method   = new ReflectionMethod( $importer, 'get_site_installation_path' );

		$method->setAccessible( true );

		$this->assertSame( trailingslashit( ABSPATH ), $method->invoke( $importer ) );
	}

	/**
	 * Test that SQLite sites skip SQL generation.
	 */
	public function test_preprocess_skips_sql_generation_for_sqlite_site() {
		$source_path = $this->create_source_database( 'sqlite-database' );
		$target_path = $this->create_temporary_path() . '/database/.ht.sqlite';
		$importer    = $this->create_sqlite_importer( $source_path, $target_path );

		$this->assertTrue( $importer->preprocess() );
		$this->assertFileDoesNotExist( trailingslashit( $source_path ) . 'database.sql' );
	}

	/**
	 * Test that SQLite sites replace the target database with the backup file.
	 */
	public function test_recreate_database_copies_sqlite_database() {
		$source_path = $this->create_source_database( 'source-database' );
		$target_path = $this->create_temporary_path() . '/database/.ht.sqlite';
		$importer    = $this->create_sqlite_importer( $source_path, $target_path );

		$this->assertTrue( $importer->recreate_database() );
		$this->assertFileExists( $target_path );
		$this->assertSame( 'source-database', file_get_contents( $target_path ) );
	}

	/**
	 * Test that SQLite sites do not run the temporary-table postprocessor.
	 */
	public function test_postprocess_database_skips_temporary_tables_for_sqlite_site() {
		$source_path = $this->create_source_database( 'sqlite-database' );
		$target_path = $this->create_temporary_path() . '/database/.ht.sqlite';
		$importer    = $this->create_sqlite_importer( $source_path, $target_path );

		$this->assertTrue( $importer->postprocess_database() );
	}

	/**
	 * Create an importer that simulates an SQLite-backed target site.
	 *
	 * @param string $source_path          Extracted backup path.
	 * @param string $target_database_path Target SQLite database path.
	 *
	 * @return Playground_Importer
	 */
	private function create_sqlite_importer( string $source_path, string $target_database_path ): Playground_Importer {
		$importer = $this->getMockBuilder( Playground_Importer::class )
			->setConstructorArgs( array( 'rand-file', $source_path, 'test_' ) )
			->onlyMethods( array( 'uses_sqlite_database', 'get_target_sqlite_database_path' ) )
			->getMock();

		$importer->method( 'uses_sqlite_database' )->willReturn( true );
		$importer->method( 'get_target_sqlite_database_path' )->willReturn( $target_database_path );

		return $importer;
	}

	/**
	 * Create an extracted backup containing a database file.
	 *
	 * @param string $contents Database file contents.
	 *
	 * @return string Extracted backup path.
	 */
	private function create_source_database( string $contents ): string {
		$source_path   = $this->create_temporary_path();
		$database_path = trailingslashit( $source_path ) . Playground_Importer::SQLITE_DB_PATH;

		wp_mkdir_p( dirname( $database_path ) );
		file_put_contents( $database_path, $contents );

		return $source_path;
	}

	/**
	 * Create a unique temporary folder path.
	 *
	 * @return string Temporary folder path.
	 */
	private function create_temporary_path(): string {
		$path                    = trailingslashit( sys_get_temp_dir() ) . 'wpcom-playground-' . wp_generate_uuid4();
		$this->temporary_paths[] = $path;

		return $path;
	}
}

// phpcs:enable
