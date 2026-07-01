<?php
/**
 * Playground admin page file.
 *
 * @package wpcom-playground
 */

namespace WPCom\Playground;

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
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_' . self::UPLOAD_ACTION, array( __CLASS__, 'upload_wp_content_zip' ) );
		add_filter( 'script_loader_tag', array( __CLASS__, 'add_module_type_to_script' ), 10, 3 );
	}

	/**
	 * Add the Playground page under Tools.
	 *
	 * @return void
	 */
	public static function add_menu_page(): void {
		add_management_page(
			__( 'WordPress Playground', 'wpcom-playground' ),
			__( 'Playground', 'wpcom-playground' ),
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

		$plugin_url = plugin_dir_url( WPCOM_PLAYGROUND_PLUGIN_FILE );

		wp_enqueue_style(
			'wpcom-playground-admin',
			$plugin_url . 'assets/css/playground-admin.css',
			array(),
			WPCOM_PLAYGROUND_VERSION
		);

		wp_enqueue_script(
			'wpcom-playground-admin',
			$plugin_url . 'assets/js/playground-admin.js',
			array(),
			WPCOM_PLAYGROUND_VERSION,
			true
		);
		wp_script_add_data( 'wpcom-playground-admin', 'type', 'module' );
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
				'post_status'    => 'inherit',
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

		$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
		if ( ! empty( $metadata ) ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}

		wp_send_json_success(
			array(
				'attachmentId' => $attachment_id,
				'editUrl'      => get_edit_post_link( $attachment_id, 'raw' ),
				'url'          => wp_get_attachment_url( $attachment_id ),
			)
		);
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
		?>
		<div class="wrap wpcom-playground-admin">
			<h1><?php echo esc_html__( 'WordPress Playground', 'wpcom-playground' ); ?></h1>

			<div
				id="wpcom-playground-admin"
				class="wpcom-playground-admin__app"
				data-remote-url="<?php echo esc_url( self::PLAYGROUND_REMOTE_URL ); ?>"
				data-upload-action="<?php echo esc_attr( self::UPLOAD_ACTION ); ?>"
				data-upload-nonce="<?php echo esc_attr( wp_create_nonce( self::UPLOAD_ACTION ) ); ?>"
				data-upload-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
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
				></iframe>
			</div>
		</div>
		<?php
	}
}
