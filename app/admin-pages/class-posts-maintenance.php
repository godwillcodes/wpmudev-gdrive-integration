<?php
/**
 * Posts Maintenance admin page.
 *
 * @package WPMUDEV\PluginTest
 */

namespace WPMUDEV\PluginTest\App\Admin_Pages;

use WPMUDEV\PluginTest\Base;
use WPMUDEV\PluginTest\App\Services\Posts_Maintenance as Posts_Maintenance_Service;

// Abort if called directly.
defined( 'WPINC' ) || die;

/**
 * Class Posts_Maintenance
 */
class Posts_Maintenance extends Base {

	/**
	 * Menu slug.
	 *
	 * @var string
	 */
	private $page_slug = 'wpmudev_plugintest_posts_maintenance';

	/**
	 * Unique DOM wrapper ID.
	 *
	 * @var string
	 */
	private $wrapper_id = 'wpmudev-posts-maintenance-app';

	/**
	 * Initializes the admin page.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'admin_body_class', array( $this, 'admin_body_classes' ) );
	}

	/**
	 * Registers menu page.
	 *
	 * @return void
	 */
	public function register_admin_page() {
		add_menu_page(
			__( 'Posts Maintenance', 'wpmudev-plugin-test' ),
			__( 'Posts Maintenance', 'wpmudev-plugin-test' ),
			'manage_options',
			$this->page_slug,
			array( $this, 'render_page' ),
			'dashicons-list-view',
			8
		);
	}

	/**
	 * Enqueues assets.
	 *
	 * @param string $hook Current admin hook.
	 *
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, $this->page_slug ) ) {
			return;
		}

		wp_register_script(
			'wpmudev-posts-maintenance',
			WPMUDEV_PLUGINTEST_ASSETS_URL . '/js/posts-maintenance.js',
			array(),
			WPMUDEV_PLUGINTEST_VERSION,
			true
		);

		wp_register_style(
			'wpmudev-posts-maintenance',
			WPMUDEV_PLUGINTEST_ASSETS_URL . '/css/posts-maintenance.css',
			array(),
			WPMUDEV_PLUGINTEST_VERSION
		);

		$service    = Posts_Maintenance_Service::instance();
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		$list       = array();

		foreach ( $post_types as $slug => $object ) {
			$list[] = array(
				'slug'  => $slug,
				'label' => $object->labels->name,
			);
		}

		wp_localize_script(
			'wpmudev-posts-maintenance',
			'wpmudevPostsMaintenance',
			array(
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'restBase'  => esc_url_raw( rest_url() ),
				'endpoints' => array(
					'start'  => 'wpmudev/v1/posts-maintenance/start',
					'status' => 'wpmudev/v1/posts-maintenance/status',
				),
				'postTypes' => $list,
				'job'       => $service->format_job_for_response(),
				'lastRun'   => $service->get_last_run(),
				'strings'   => array(
					'selectPostTypes' => __( 'Select at least one post type to scan.', 'wpmudev-plugin-test' ),
					'scanStarted'     => __( 'Scan started successfully.', 'wpmudev-plugin-test' ),
					'scanFailed'      => __( 'Failed to start scan.', 'wpmudev-plugin-test' ),
				),
			)
		);

		wp_enqueue_script( 'wpmudev-posts-maintenance' );
		wp_enqueue_style( 'wpmudev-posts-maintenance' );
	}

	/**
	 * Renders admin page markup.
	 *
	 * @return void
	 */
	public function render_page() {
		?>
		<div class="sui-wrap">
			<div class="sui-header">
				<h1 class="sui-header-title"><?php esc_html_e( 'Posts Maintenance', 'wpmudev-plugin-test' ); ?></h1>
				<div class="sui-actions-right">
					<button type="button" class="sui-button sui-button-ghost" id="<?php echo esc_attr( $this->wrapper_id ); ?>-refresh">
						<span class="sui-icon-update" aria-hidden="true"></span> <?php esc_html_e( 'Refresh Status', 'wpmudev-plugin-test' ); ?>
					</button>
				</div>
			</div>

			<div class="sui-box">
				<div class="sui-box-header">
					<h2 class="sui-box-title"><?php esc_html_e( 'Scan Options', 'wpmudev-plugin-test' ); ?></h2>
				</div>
				<div class="sui-box-body">
					<p><?php esc_html_e( 'Scan your published posts and pages to stamp the latest maintenance timestamp. Choose which post types to include and launch the process in the background.', 'wpmudev-plugin-test' ); ?></p>

					<div class="sui-form-field">
						<label class="sui-label"><?php esc_html_e( 'Post types to scan', 'wpmudev-plugin-test' ); ?></label>
						<div class="wpmudev-post-types-list" id="<?php echo esc_attr( $this->wrapper_id ); ?>-post-types"></div>
					</div>

					<button type="button" class="sui-button sui-button-blue" id="<?php echo esc_attr( $this->wrapper_id ); ?>-start">
						<span class="sui-icon-play" aria-hidden="true"></span> <?php esc_html_e( 'Scan Posts', 'wpmudev-plugin-test' ); ?>
					</button>
				</div>
			</div>

			<div class="sui-box">
				<div class="sui-box-header">
					<h2 class="sui-box-title"><?php esc_html_e( 'Progress', 'wpmudev-plugin-test' ); ?></h2>
				</div>
				<div class="sui-box-body">
					<div class="wpmudev-progress-wrapper">
						<div class="wpmudev-progress-bar" id="<?php echo esc_attr( $this->wrapper_id ); ?>-progress-bar">
							<span></span>
						</div>
						<div class="wpmudev-progress-meta">
							<strong id="<?php echo esc_attr( $this->wrapper_id ); ?>-progress-text"><?php esc_html_e( 'No active scan.', 'wpmudev-plugin-test' ); ?></strong>
							<p id="<?php echo esc_attr( $this->wrapper_id ); ?>-counts" class="sui-description"></p>
						</div>
					</div>
				</div>
				<div class="sui-box-footer">
					<p class="sui-description" id="<?php echo esc_attr( $this->wrapper_id ); ?>-last-run"></p>
				</div>
			</div>

			<div class="sui-notice" id="<?php echo esc_attr( $this->wrapper_id ); ?>-notice" aria-live="polite" style="display:none;"></div>
		</div>
		<?php
	}

	/**
	 * Adds body class for SUI styling.
	 *
	 * @param string $classes Admin body classes.
	 *
	 * @return string
	 */
	public function admin_body_classes( $classes ) {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return $classes;
		}

		$current_screen = get_current_screen();

		if ( empty( $current_screen->id ) || false === strpos( $current_screen->id, $this->page_slug ) ) {
			return $classes;
		}

		return $classes . ' sui-' . str_replace( '.', '-', WPMUDEV_PLUGINTEST_SUI_VERSION ) . ' ';
	}
}

