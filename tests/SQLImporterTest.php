<?php
/**
 * SQL Importer Test file.
 *
 * @package wpcomsh
 */

use Imports\SQL_Importer;

// These tests intentionally use temporary file handles instead of WP_Filesystem.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange

/**
 * Class SQLImporterTest.
 */
class SQLImporterTest extends WP_UnitTestCase {
	/**
	 * Temporary SQL file.
	 *
	 * @var resource
	 */
	private $tmp_sql_file;

	/**
	 * Temporary SQL file.
	 *
	 * @var string
	 */
	private $tmp_sql_path;

	/**
	 * Clear values for each test
	 *
	 * @return void
	 */
	public function tearDown(): void {
		if ( $this->tmp_sql_path !== null && file_exists( $this->tmp_sql_path ) ) {
			fclose( $this->tmp_sql_file );
		}

		parent::tearDown();
	}

	/**
	 * Open an empty path.
	 */
	public function test_error_open_an_empty_sql_file() {
		$result = SQL_Importer::import( sys_get_temp_dir() );

		$this->assertWPError( $result );
		$this->assertEquals( 'sql-file-not-exists', $result->get_error_code() );
	}

	/**
	 * Import an invalid SQL file.
	 */
	public function test_open_an_invalid_sql_file() {
		$this->generate_tmp_sql( 'not-valid' );
		$result = SQL_Importer::import( $this->tmp_sql_path );

		$this->assertWPError( $result );
		$this->assertEquals( 'sql-import-failed', $result->get_error_code() );
	}

	/**
	 * Import an empty SQL file.
	 */
	public function test_open_an_empty_sql_file() {
		$this->generate_tmp_sql();
		$result = SQL_Importer::import( $this->tmp_sql_path );

		$this->assertTrue( $result );
	}

	/**
	 * Import a SQL file using the mysqli fallback.
	 */
	public function test_import_with_mysqli_imports_sql_file() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'sql_importer_test';
		$this->generate_tmp_sql(
			"DROP TABLE IF EXISTS `$table_name`;
			CREATE TABLE `$table_name` (
				id bigint(20) NOT NULL,
				name varchar(20) NOT NULL,
				PRIMARY KEY  (id)
			);
			INSERT INTO `$table_name` (id, name) VALUES (1, 'imported');"
		);

		$method = new ReflectionMethod( SQL_Importer::class, 'import_with_mysqli' );
		$method->setAccessible( true );

		$result = $method->invoke( null, $this->tmp_sql_path );

		$this->assertTrue( $result );
		$this->assertSame( 'imported', $wpdb->get_var( "SELECT name FROM `$table_name` WHERE id = 1" ) );

		$wpdb->query( "DROP TABLE IF EXISTS `$table_name`" );
	}

	/**
	 * Generates a temporary SQL.
	 *
	 * @param mixed $data Data to write in the database.
	 */
	private function generate_tmp_sql( $data = null ) {
		$this->tmp_sql_file = tmpfile();
		$meta_data          = stream_get_meta_data( $this->tmp_sql_file );
		$this->tmp_sql_path = $meta_data['uri'];

		if ( $data !== null ) {
			fwrite( $this->tmp_sql_file, $data );
			fflush( $this->tmp_sql_file );
		}
	}
}
