<?php
/**
 * PlaygroundAdminPageTest file.
 *
 * @package wpcom-playground
 */

use WPCom\Playground\Playground_Admin_Page;

// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended

/**
 * Class PlaygroundAdminPageTest
 */
class PlaygroundAdminPageTest extends WP_UnitTestCase {
	/**
	 * Attachment IDs created by the test.
	 *
	 * @var int[]
	 */
	private array $attachment_ids = array();

	/**
	 * Temporary file created for the upload.
	 *
	 * @var string
	 */
	private string $tmp_file = '';

	/**
	 * Original $_FILES value.
	 *
	 * @var array
	 */
	private array $original_files = array();

	/**
	 * Original $_POST value.
	 *
	 * @var array
	 */
	private array $original_post = array();

	/**
	 * Original $_REQUEST value.
	 *
	 * @var array
	 */
	private array $original_request = array();

	/**
	 * Set up test globals.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->original_files   = $_FILES;
		$this->original_post    = $_POST;
		$this->original_request = $_REQUEST;
	}

	/**
	 * Clean up uploaded files and restore globals.
	 */
	public function tearDown(): void {
		remove_filter( 'wp_doing_ajax', '__return_true' );
		remove_filter( 'wp_die_ajax_handler', array( $this, 'get_wp_die_ajax_handler' ), 1 );

		foreach ( $this->attachment_ids as $attachment_id ) {
			wp_delete_attachment( $attachment_id, true );
		}

		if ( '' !== $this->tmp_file && file_exists( $this->tmp_file ) ) {
			wp_delete_file( $this->tmp_file );
		}

		$_FILES   = $this->original_files;
		$_POST    = $this->original_post;
		$_REQUEST = $this->original_request;

		parent::tearDown();
	}

	/**
	 * Uploading a Playground wp-content ZIP creates a private attachment.
	 */
	public function test_upload_wp_content_zip_creates_private_attachment(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', array( $this, 'get_wp_die_ajax_handler' ), 1 );

		$this->tmp_file = $this->create_zip_file();

		$nonce             = wp_create_nonce( 'wpcom_playground_import_wp_content' );
		$_POST['nonce']    = $nonce;
		$_REQUEST['nonce'] = $nonce;

		$_FILES['wp_content_zip'] = array(
			'name'     => 'playground-wp-content.zip',
			'type'     => 'application/zip',
			'tmp_name' => $this->tmp_file,
			'error'    => UPLOAD_ERR_OK,
			'size'     => filesize( $this->tmp_file ),
		);

		ob_start();

		try {
			Playground_Admin_Page::upload_wp_content_zip();
		} catch ( WPDieException $exception ) {
			unset( $exception );
		}

		$response = json_decode( ob_get_clean(), true );

		$this->assertTrue( $response['success'] );
		$this->assertArrayHasKey( 'attachmentId', $response['data'] );

		$attachment_id          = (int) $response['data']['attachmentId'];
		$this->attachment_ids[] = $attachment_id;

		$this->assertSame( 'private', get_post_status( $attachment_id ) );
	}

	/**
	 * Return a wp_die AJAX handler that lets the test continue.
	 *
	 * @return array AJAX die handler callback.
	 */
	public function get_wp_die_ajax_handler(): array {
		return array( $this, 'wp_die_ajax_handler' );
	}

	/**
	 * Throw instead of dying during AJAX responses.
	 *
	 * @param string $message Die message.
	 *
	 * @throws WPDieException Throws to stop the AJAX callback.
	 */
	public function wp_die_ajax_handler( $message ): void {
		throw new WPDieException( esc_html( $message ) );
	}

	/**
	 * Create a temporary ZIP file.
	 *
	 * @return string ZIP file path.
	 */
	private function create_zip_file(): string {
		$tmp_file = wp_tempnam( 'playground-wp-content.zip' );
		$zip      = new ZipArchive();

		$this->assertTrue( $zip->open( $tmp_file, ZipArchive::OVERWRITE ) );
		$this->assertTrue( $zip->addFromString( 'wp-content/test.txt', 'Playground content' ) );
		$this->assertTrue( $zip->close() );

		return $tmp_file;
	}
}
