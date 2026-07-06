<?php
/**
 * PlaygroundImporterTest file.
 *
 * @package wpcomsh
 */

use Imports\Playground_Importer;

/**
 * Class PlaygroundImporterTest
 */
class PlaygroundImporterTest extends WP_UnitTestCase {
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
}
