<?php
/**
 * Playground admin page file.
 *
 * @package wpcom-playground
 */

namespace WPCom\Playground;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Adds a wp-admin screen that embeds WordPress Playground.
 */
class Playground_Admin_Page {
	/**
	 * Admin page slug.
	 */
	private const MENU_SLUG = 'wpcom-playground';

	/**
	 * Playground remote iframe URL.
	 */
	private const PLAYGROUND_REMOTE_URL = 'https://playground.wordpress.net/remote.html';

	/**
	 * AJAX action for importing a Playground wp-content archive.
	 */
	private const UPLOAD_ACTION = 'wpcom_playground_import_wp_content';

	/**
	 * REST API namespace.
	 */
	private const REST_NAMESPACE = 'wpcom-playground/v1';

	/**
	 * Tag used to identify uploaded Playground archives.
	 */
	private const PLAYGROUND_ARCHIVE_TAG = 'wpcom-playground-import';

	/**
	 * Tag label used to identify uploaded Playground archives.
	 */
	private const PLAYGROUND_ARCHIVE_TAG_NAME = 'WordPress Playground Import';

	/**
	 * Query argument used to show dashboard import notices.
	 */
	private const IMPORT_NOTICE_QUERY_ARG = 'wpcom_playground_import';

	/**
	 * Query argument used to show dashboard import notice details.
	 */
	private const IMPORT_NOTICE_MESSAGE_QUERY_ARG = 'wpcom_playground_import_message';

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register_attachment_taxonomies' ) );
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_import_notice' ) );
		add_action( 'wp_ajax_' . self::UPLOAD_ACTION, array( __CLASS__, 'upload_wp_content_zip' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WPCOM_PLAYGROUND_PLUGIN_FILE ), array( __CLASS__, 'add_plugin_action_links' ) );
		add_filter( 'script_loader_tag', array( __CLASS__, 'add_module_type_to_script' ), 10, 3 );
	}

	/**
	 * Add an importer link to the plugin row on the Plugins screen.
	 *
	 * @param string[] $links Existing plugin action links.
	 *
	 * @return string[] Plugin action links.
	 */
	public static function add_plugin_action_links( array $links ): array {
		$importer_link = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( admin_url( 'tools.php?page=' . self::MENU_SLUG ) ),
			esc_html__( 'Open importer', 'wpcom-playground' )
		);

		array_unshift( $links, $importer_link );

		return $links;
	}

	/**
	 * Register tags for Media Library attachments.
	 *
	 * @return void
	 */
	public static function register_attachment_taxonomies(): void {
		register_taxonomy_for_object_type( 'post_tag', 'attachment' );
	}

	/**
	 * Add the Playground page under Tools.
	 *
	 * @return void
	 */
	public static function add_menu_page(): void {
		add_management_page(
			__( 'WordPress Playground', 'wpcom-playground' ),
			__( 'Playground importer', 'wpcom-playground' ),
			'manage_options',
			self::MENU_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Enqueue page assets.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 *
	 * @return void
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		if ( 'tools_page_' . self::MENU_SLUG !== $hook_suffix ) {
			return;
		}

		$plugin_url   = plugin_dir_url( WPCOM_PLAYGROUND_PLUGIN_FILE );
		$plugin_path  = plugin_dir_path( WPCOM_PLAYGROUND_PLUGIN_FILE );
		$style_asset  = 'assets/css/playground-admin.css';
		$script_asset = 'assets/js/playground-admin.js';

		wp_enqueue_style(
			'wpcom-playground-admin',
			$plugin_url . $style_asset,
			array(),
			self::get_asset_version( $plugin_path . $style_asset )
		);

		wp_enqueue_script(
			'wpcom-playground-admin',
			$plugin_url . $script_asset,
			array(),
			self::get_asset_version( $plugin_path . $script_asset ),
			true
		);
		wp_script_add_data( 'wpcom-playground-admin', 'type', 'module' );
	}

	/**
	 * Get an asset version that changes when the file changes.
	 *
	 * @param string $asset_path Absolute asset path.
	 *
	 * @return string Asset version.
	 */
	private static function get_asset_version( string $asset_path ): string {
		$modified_time = file_exists( $asset_path ) ? filemtime( $asset_path ) : false;

		return $modified_time ? (string) $modified_time : WPCOM_PLAYGROUND_VERSION;
	}

	/**
	 * Get wp-content paths that should be excluded from Playground exports.
	 *
	 * @return string[] Relative wp-content paths to exclude.
	 */
	private static function get_wp_content_export_exclusions(): array {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$exclusions = array();

		foreach ( array_keys( wp_get_themes() ) as $stylesheet ) {
			$exclusions[] = 'themes/' . $stylesheet;
		}

		foreach ( array_keys( get_plugins() ) as $plugin_file ) {
			$plugin_file = wp_normalize_path( $plugin_file );
			$plugin_dir  = dirname( $plugin_file );

			if ( '.' === $plugin_dir ) {
				$exclusions[] = 'plugins/' . $plugin_file;
			} else {
				$exclusions[] = 'plugins/' . $plugin_dir;
			}
		}

		return array_values( array_unique( $exclusions ) );
	}

	/**
	 * Render the dashboard notice after a Playground import redirect.
	 *
	 * @return void
	 */
	public static function render_import_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only dashboard notice.
		$status = isset( $_GET[ self::IMPORT_NOTICE_QUERY_ARG ] )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only dashboard notice.
			? sanitize_key( wp_unslash( $_GET[ self::IMPORT_NOTICE_QUERY_ARG ] ) )
			: '';

		if ( ! current_user_can( 'manage_options' ) || '' === $status ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only dashboard notice.
		$detail = isset( $_GET[ self::IMPORT_NOTICE_MESSAGE_QUERY_ARG ] )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only dashboard notice.
			? sanitize_text_field( wp_unslash( $_GET[ self::IMPORT_NOTICE_MESSAGE_QUERY_ARG ] ) )
			: '';

		switch ( $status ) {
			case 'success':
				$type    = 'success';
				$message = __( 'The Playground import completed successfully.', 'wpcom-playground' );
				break;

			case 'cancelled':
				$type    = 'warning';
				$message = __( 'The Playground import was cancelled.', 'wpcom-playground' );
				break;

			case 'error':
				$type    = 'error';
				$message = $detail
					? sprintf(
						/* translators: %s: Import error message. */
						__( 'The Playground import failed: %s', 'wpcom-playground' ),
						$detail
					)
					: __( 'The Playground import failed.', 'wpcom-playground' );
				break;

			default:
				return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}

	/**
	 * Add the module script type to the Playground admin script tag.
	 *
	 * @param string $tag    Script tag HTML.
	 * @param string $handle Script handle.
	 * @param string $src    Script source URL.
	 *
	 * @return string Script tag HTML.
	 */
	public static function add_module_type_to_script( string $tag, string $handle, string $src ): string {
		if ( 'wpcom-playground-admin' !== $handle ) {
			return $tag;
		}

		return wp_get_script_tag(
			array(
				'type' => 'module',
				'src'  => $src,
				'id'   => $handle . '-js',
			)
		);
	}

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public static function register_rest_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/imports',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'start_backup_import' ),
				'permission_callback' => array( __CLASS__, 'can_import_playground_archive' ),
				'args'                => array(
					'attachmentId' => array(
						'description'       => __( 'Uploaded Playground archive attachment ID.', 'wpcom-playground' ),
						'type'              => 'integer',
						'required'          => true,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Check whether the current user can start Playground imports.
	 *
	 * @return bool Whether the current user can import Playground archives.
	 */
	public static function can_import_playground_archive(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Start a backup import from an uploaded Playground archive.
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response|WP_Error REST response or error.
	 */
	public static function start_backup_import( WP_REST_Request $request ) {
		$attachment_id = absint( $request->get_param( 'attachmentId' ) );
		$import_id     = (string) $attachment_id;
		$import_status = Backup_Import_Manager::get_backup_import_status();
		$is_resuming   = is_array( $import_status )
			&& isset( $import_status['mode'], $import_status['import_id'], $import_status['destination'] )
			&& Backup_Import_Manager::RESUMABLE_MODE === $import_status['mode']
			&& $import_id === (string) $import_status['import_id'];

		if ( $is_resuming && Backup_Import_Manager::SUCCESS === $import_status['status'] ) {
			return new WP_REST_Response(
				array(
					'attachmentId' => $attachment_id,
					'done'         => true,
					'nextStep'     => null,
					'success'      => true,
					'status'       => Backup_Import_Manager::SUCCESS,
				)
			);
		}

		$source = $is_resuming && isset( $import_status['source'] )
			? $import_status['source']
			: self::get_playground_archive_source_path( $attachment_id );

		if ( is_wp_error( $source ) ) {
			return $source;
		}

		if ( $is_resuming && ( ! is_string( $source ) || ! is_file( $source ) || ! is_readable( $source ) ) ) {
			return new WP_Error(
				'missing_playground_archive_file',
				__( 'The Playground archive file could not be found.', 'wpcom-playground' ),
				array( 'status' => 404 )
			);
		}

		$destination = $is_resuming ? $import_status['destination'] : '/tmp/' . wp_generate_password( 12, false );
		$dry_run     = false;

		/**
		 * Filters the backup import manager used to import an uploaded Playground archive.
		 *
		 * @param Backup_Import_Manager $manager       Backup import manager instance.
		 * @param string                $source        Uploaded archive path.
		 * @param string                $destination   Temporary extraction destination.
		 * @param int                   $attachment_id Uploaded archive attachment ID.
		 */
		$manager = apply_filters(
			'wpcom_playground_backup_import_manager',
			new Backup_Import_Manager(
				$source,
				$destination,
				array(
					'skip_clean_up' => false,
					'dry_run'       => $dry_run,
					'import_id'     => $import_id,
					'actions'       => array(),
					'skip_unpack'   => false,
				)
			),
			$source,
			$destination,
			$attachment_id
		);

		if ( ! is_object( $manager ) || ( ! method_exists( $manager, 'import_next_step' ) && ! method_exists( $manager, 'import' ) ) ) {
			return new WP_Error(
				'invalid_backup_import_manager',
				__( 'The backup import manager could not be initialized.', 'wpcom-playground' ),
				array( 'status' => 500 )
			);
		}

		// Retain compatibility with integrations that filter in a legacy manager.
		$result = method_exists( $manager, 'import_next_step' ) ? $manager->import_next_step() : $manager->import();
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$step_result = is_array( $result )
			? $result
			: array(
				'done'     => true,
				'nextStep' => null,
				'success'  => (bool) $result,
				'status'   => $result ? Backup_Import_Manager::SUCCESS : Backup_Import_Manager::FAILED,
			);

		return new WP_REST_Response(
			array_merge(
				$step_result,
				array(
					'attachmentId' => $attachment_id,
					'destination'  => $destination,
					'source'       => $source,
				)
			)
		);
	}

	/**
	 * Save an uploaded Playground wp-content ZIP in the Media Library.
	 *
	 * @return void
	 */
	public static function upload_wp_content_zip(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to import Playground content.', 'wpcom-playground' ),
				),
				403
			);
		}

		if ( ! check_ajax_referer( self::UPLOAD_ACTION, 'nonce', false ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'The import request could not be verified.', 'wpcom-playground' ),
				),
				403
			);
		}

		if ( empty( $_FILES['wp_content_zip'] ) || ! is_array( $_FILES['wp_content_zip'] ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'No Playground archive was uploaded.', 'wpcom-playground' ),
				),
				400
			);
		}

		$file = array(
			'name'     => isset( $_FILES['wp_content_zip']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['wp_content_zip']['name'] ) ) : 'playground-wp-content.zip',
			'type'     => isset( $_FILES['wp_content_zip']['type'] ) ? sanitize_mime_type( wp_unslash( $_FILES['wp_content_zip']['type'] ) ) : 'application/zip',
			'tmp_name' => isset( $_FILES['wp_content_zip']['tmp_name'] ) ? sanitize_text_field( wp_unslash( $_FILES['wp_content_zip']['tmp_name'] ) ) : '',
			'error'    => isset( $_FILES['wp_content_zip']['error'] ) ? absint( $_FILES['wp_content_zip']['error'] ) : UPLOAD_ERR_NO_FILE,
			'size'     => isset( $_FILES['wp_content_zip']['size'] ) ? absint( $_FILES['wp_content_zip']['size'] ) : 0,
		);
		$name = $file['name'];

		if ( UPLOAD_ERR_OK !== $file['error'] ) {
			wp_send_json_error(
				array(
					'message' => __( 'The Playground archive upload failed.', 'wpcom-playground' ),
				),
				400
			);
		}

		if ( 'zip' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			$name .= '.zip';
		}

		$file['name'] = $name;

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$upload = wp_handle_sideload(
			$file,
			array(
				'test_form' => false,
				'mimes'     => array(
					'zip' => 'application/zip',
				),
			)
		);

		if ( isset( $upload['error'] ) ) {
			wp_send_json_error(
				array(
					'message' => $upload['error'],
				),
				500
			);
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $upload['type'],
				'post_title'     => preg_replace( '/\.[^.]+$/', '', basename( $upload['file'] ) ),
				'post_content'   => '',
				'post_status'    => 'private',
			),
			$upload['file']
		);

		if ( is_wp_error( $attachment_id ) ) {
			wp_send_json_error(
				array(
					'message' => $attachment_id->get_error_message(),
				),
				500
			);
		}

		$tag_result = self::tag_playground_archive_attachment( $attachment_id );
		if ( is_wp_error( $tag_result ) ) {
			wp_delete_attachment( $attachment_id, true );
			wp_send_json_error(
				array(
					'message' => $tag_result->get_error_message(),
				),
				500
			);
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
		if ( ! empty( $metadata ) ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}

		wp_send_json_success(
			array(
				'attachmentId' => $attachment_id,
				'editUrl'      => get_edit_post_link( $attachment_id, 'raw' ),
				'tag'          => self::PLAYGROUND_ARCHIVE_TAG,
				'url'          => wp_get_attachment_url( $attachment_id ),
			)
		);
	}

	/**
	 * Tag an uploaded Playground archive attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 *
	 * @return array|WP_Error Assigned term taxonomy IDs or error.
	 */
	private static function tag_playground_archive_attachment( int $attachment_id ) {
		$term = term_exists( self::PLAYGROUND_ARCHIVE_TAG, 'post_tag' );

		if ( 0 === $term || null === $term ) {
			$term = wp_insert_term(
				self::PLAYGROUND_ARCHIVE_TAG_NAME,
				'post_tag',
				array(
					'slug' => self::PLAYGROUND_ARCHIVE_TAG,
				)
			);
		}

		if ( is_wp_error( $term ) ) {
			return $term;
		}

		$term_id = is_array( $term ) ? (int) $term['term_id'] : (int) $term;

		return wp_set_object_terms( $attachment_id, $term_id, 'post_tag', false );
	}

	/**
	 * Get and validate the source file path for an uploaded Playground archive.
	 *
	 * @param int $attachment_id Attachment ID.
	 *
	 * @return string|WP_Error Source file path or error.
	 */
	private static function get_playground_archive_source_path( int $attachment_id ) {
		if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
			return new WP_Error(
				'invalid_playground_archive',
				__( 'The requested Playground archive attachment does not exist.', 'wpcom-playground' ),
				array( 'status' => 404 )
			);
		}

		if ( ! has_term( self::PLAYGROUND_ARCHIVE_TAG, 'post_tag', $attachment_id ) ) {
			return new WP_Error(
				'invalid_playground_archive',
				__( 'The requested attachment is not a Playground archive upload.', 'wpcom-playground' ),
				array( 'status' => 400 )
			);
		}

		$source = get_attached_file( $attachment_id );
		if ( ! $source || ! file_exists( $source ) ) {
			return new WP_Error(
				'missing_playground_archive_file',
				__( 'The Playground archive file could not be found in the Media Library.', 'wpcom-playground' ),
				array( 'status' => 404 )
			);
		}

		return $source;
	}

	/**
	 * Render the Playground admin page.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wpcom-playground' ) );
		}

		$wp_content_export_exclusions = wp_json_encode( self::get_wp_content_export_exclusions() );
		$wp_content_export_exclusions = false === $wp_content_export_exclusions ? '[]' : $wp_content_export_exclusions;
		?>
		<div class="wrap wpcom-playground-admin">
			<div
				id="wpcom-playground-admin"
				class="wpcom-playground-admin__app"
				data-remote-url="<?php echo esc_url( self::PLAYGROUND_REMOTE_URL ); ?>"
				data-upload-action="<?php echo esc_attr( self::UPLOAD_ACTION ); ?>"
				data-upload-nonce="<?php echo esc_attr( wp_create_nonce( self::UPLOAD_ACTION ) ); ?>"
				data-upload-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
				data-import-url="<?php echo esc_url( rest_url( self::REST_NAMESPACE . '/imports' ) ); ?>"
				data-rest-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
				data-dashboard-url="<?php echo esc_url( admin_url() ); ?>"
				data-wp-content-export-exclusions="<?php echo esc_attr( $wp_content_export_exclusions ); ?>"
			>
				<div class="wpcom-playground-admin__toolbar">
					<p id="wpcom-playground-status" class="wpcom-playground-admin__status" role="status">
						<?php echo esc_html__( 'Starting WordPress Playground...', 'wpcom-playground' ); ?>
					</p>
					<button id="wpcom-playground-import" class="button button-primary" type="button" disabled>
						<?php echo esc_html__( 'Import', 'wpcom-playground' ); ?>
					</button>
					<a
						id="wpcom-playground-import-result"
						class="wpcom-playground-admin__result"
						href="#"
						hidden
					>
						<?php echo esc_html__( 'View upload', 'wpcom-playground' ); ?>
					</a>
				</div>

				<iframe
					id="wpcom-playground-iframe"
					class="wpcom-playground-admin__iframe"
					title="<?php echo esc_attr__( 'WordPress Playground preview', 'wpcom-playground' ); ?>"
					referrerpolicy="no-referrer"
				></iframe>

				<div
					id="wpcom-playground-import-loader"
					class="wpcom-playground-admin__loader"
					role="dialog"
					aria-modal="true"
					aria-labelledby="wpcom-playground-import-loader-title"
					aria-describedby="wpcom-playground-import-loader-message"
					hidden
				>
					<div class="wpcom-playground-admin__loader-panel">
						<span class="wpcom-playground-admin__loader-spinner" aria-hidden="true"></span>
						<h2 id="wpcom-playground-import-loader-title">
							<?php echo esc_html__( 'Importing Playground archive', 'wpcom-playground' ); ?>
						</h2>
						<p id="wpcom-playground-import-loader-message">
							<?php echo esc_html__( 'Preparing the archive...', 'wpcom-playground' ); ?>
						</p>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}
