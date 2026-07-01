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
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
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
			>
				<div class="wpcom-playground-admin__toolbar">
					<p id="wpcom-playground-status" class="wpcom-playground-admin__status" role="status">
						<?php echo esc_html__( 'Starting WordPress Playground...', 'wpcom-playground' ); ?>
					</p>
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
