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
	 * Uploaded files created by the test.
	 *
	 * @var string[]
	 */
	private array $uploaded_files = array();

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

		foreach ( $this->uploaded_files as $uploaded_file ) {
			if ( file_exists( $uploaded_file ) ) {
				wp_delete_file( $uploaded_file );
			}
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
	 * The Plugins screen links to the Playground importer.
	 */
	public function test_plugin_action_links_include_importer_page(): void {
		$filter = 'plugin_action_links_' . plugin_basename( WPCOM_PLAYGROUND_PLUGIN_FILE );
		$links  = apply_filters( $filter, array( 'deactivate' => '<a href="#">Deactivate</a>' ) );

		$this->assertSame(
			'<a href="' . esc_url( admin_url( 'tools.php?page=wpcom-playground' ) ) . '">Open importer</a>',
			reset( $links )
		);
		$this->assertArrayHasKey( 'deactivate', $links );
	}

	/**
	 * Uploading a Playground wp-content ZIP creates a private tagged attachment.
	 */
	public function test_upload_wp_content_zip_creates_private_tagged_attachment(): void {
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
		$this->assertTrue( has_term( 'wpcom-playground-import', 'post_tag', $attachment_id ) );
		$this->assertSame( 'wpcom-playground-import', $response['data']['tag'] );
	}

	/**
	 * The REST import endpoint initializes the backup import manager from an uploaded attachment.
	 */
	public function test_rest_import_endpoint_initializes_backup_import_manager_from_attachment(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$attachment_id   = $this->create_tagged_upload_attachment();
		$source          = get_attached_file( $attachment_id );
		$manager_args    = array();
		$manager_options = array();
		$tracker         = (object) array(
			'import_called' => false,
		);
		$manager_filter  = function ( $manager, $manager_source, $manager_destination, $manager_attachment_id ) use ( &$manager_args, &$manager_options, $tracker ) {
			$options_property = new ReflectionProperty( $manager, 'options' );
			$options_property->setAccessible( true );
			$manager_options = $options_property->getValue( $manager );

			$manager_args = array(
				'attachment_id' => $manager_attachment_id,
				'destination'   => $manager_destination,
				'source'        => $manager_source,
			);

			return new class( $tracker ) {
				/**
				 * Import call tracker.
				 *
				 * @var stdClass
				 */
				private $tracker;

				/**
				 * Constructor.
				 *
				 * @param stdClass $tracker Import call tracker.
				 */
				public function __construct( stdClass $tracker ) {
					$this->tracker = $tracker;
				}

				/**
				 * Simulate a successful import.
				 *
				 * @return bool
				 */
				public function import(): bool {
					$this->tracker->import_called = true;

					return true;
				}
			};
		};

		add_filter( 'wpcom_playground_backup_import_manager', $manager_filter, 10, 4 );

		$request = new WP_REST_Request( 'POST', '/wpcom-playground/v1/imports' );
		$request->set_param( 'attachmentId', $attachment_id );

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		remove_filter( 'wpcom_playground_backup_import_manager', $manager_filter, 10 );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertTrue( $tracker->import_called );
		$this->assertSame( $attachment_id, $manager_args['attachment_id'] );
		$this->assertSame( $source, $manager_args['source'] );
		$this->assertSame(
			array(
				'skip_clean_up' => false,
				'dry_run'       => true,
				'actions'       => array(),
				'skip_unpack'   => false,
			),
			$manager_options
		);
		$this->assertMatchesRegularExpression( '#^/tmp/[A-Za-z0-9]{12}$#', $manager_args['destination'] );
		$this->assertSame( $manager_args['destination'], $data['destination'] );
		$this->assertSame( $source, $data['source'] );
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

	/**
	 * Create a tagged upload attachment.
	 *
	 * @return int Attachment ID.
	 */
	private function create_tagged_upload_attachment(): int {
		$upload = wp_upload_bits( 'playground-wp-content.zip', null, 'Playground content' );

		$this->assertFalse( $upload['error'] );

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'application/zip',
				'post_title'     => 'playground-wp-content',
				'post_status'    => 'private',
			),
			$upload['file']
		);

		$this->assertNotWPError( $attachment_id );

		$this->uploaded_files[]   = $upload['file'];
		$this->attachment_ids[]   = $attachment_id;
		$assigned_term_taxonomies = wp_set_object_terms( $attachment_id, 'wpcom-playground-import', 'post_tag', false );

		$this->assertNotWPError( $assigned_term_taxonomies );

		return $attachment_id;
	}
}
