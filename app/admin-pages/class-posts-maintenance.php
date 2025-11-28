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
		$hook = add_menu_page(
			__( 'Posts Maintenance', 'wpmudev-plugin-test' ),
			__( 'Posts Maintenance', 'wpmudev-plugin-test' ),
			'manage_options',
			$this->page_slug,
			array( $this, 'render_page' ),
			'dashicons-list-view',
			8
		);
		
		// Add submenu for Scan History
		add_submenu_page(
			$this->page_slug,
			__( 'Scan History', 'wpmudev-plugin-test' ),
			__( 'Scan History', 'wpmudev-plugin-test' ),
			'manage_options',
			$this->page_slug . '_history',
			array( $this, 'render_history_page' )
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
		
		// Enqueue history page assets if on history page
		if ( false !== strpos( $hook, $this->page_slug . '_history' ) ) {
			$this->enqueue_history_assets();
			return;
		}

		// Get asset file data
		$asset_file_path = WPMUDEV_PLUGINTEST_DIR . 'assets/js/posts-maintenance.min.asset.php';
		$dependencies = array( 'react', 'wp-element', 'wp-i18n', 'wp-components' );
		if ( file_exists( $asset_file_path ) ) {
			$asset_file = include $asset_file_path;
			$dependencies = isset( $asset_file['dependencies'] ) ? $asset_file['dependencies'] : $dependencies;
		}

		wp_register_script(
			'wpmudev-posts-maintenance',
			WPMUDEV_PLUGINTEST_ASSETS_URL . '/js/posts-maintenance.min.js',
			$dependencies,
			WPMUDEV_PLUGINTEST_VERSION,
			true
		);

		wp_register_style(
			'wpmudev-posts-maintenance',
			WPMUDEV_PLUGINTEST_ASSETS_URL . '/css/posts-maintenance.min.css',
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

		$settings = $service->get_settings();
		$next_scan = $service->get_next_scan_timestamp();

		wp_localize_script(
			'wpmudev-posts-maintenance',
			'wpmudevPostsMaintenance',
			array(
				'wrapperId' => $this->wrapper_id,
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'restBase'  => esc_url_raw( rest_url() ),
				'endpoints' => array(
					'start'    => 'wpmudev/v1/posts-maintenance/start',
					'status'   => 'wpmudev/v1/posts-maintenance/status',
					'settings' => 'wpmudev/v1/posts-maintenance/settings',
				),
				'postTypes' => $list,
				'job'        => $service->format_job_for_response(),
				'lastRun'    => $service->get_last_run(),
				'settings'   => $settings,
				'nextScan'   => $next_scan,
				'strings'    => array(
					'selectPostTypes' => __( 'Select at least one post type to scan.', 'wpmudev-plugin-test' ),
					'scanStarted'     => __( 'Scan started successfully.', 'wpmudev-plugin-test' ),
					'scanFailed'      => __( 'Failed to start scan.', 'wpmudev-plugin-test' ),
					'settingsSaved'   => __( 'Settings saved successfully.', 'wpmudev-plugin-test' ),
					'settingsError'   => __( 'Failed to save settings.', 'wpmudev-plugin-test' ),
				),
			)
		);

		wp_enqueue_script( 'wpmudev-posts-maintenance' );
		wp_enqueue_style( 'wpmudev-posts-maintenance' );
	}

	/**
	 * Enqueues assets for history page.
	 *
	 * @return void
	 */
	private function enqueue_history_assets() {
		$service = Posts_Maintenance_Service::instance();
		$history = $service->get_history();

		// Get asset file data
		$asset_file_path = WPMUDEV_PLUGINTEST_DIR . 'assets/js/posts-maintenance-history.min.asset.php';
		$dependencies = array( 'react', 'wp-element', 'wp-i18n', 'wp-components' );
		if ( file_exists( $asset_file_path ) ) {
			$asset_file = include $asset_file_path;
			$dependencies = isset( $asset_file['dependencies'] ) ? $asset_file['dependencies'] : $dependencies;
		}

		// Register and enqueue the CSS style for history page
		// Use posts-maintenance-history.min.css which matches the webpack entry point
		wp_register_style(
			'wpmudev-posts-maintenance-history',
			WPMUDEV_PLUGINTEST_ASSETS_URL . '/css/posts-maintenance-history.min.css',
			array(),
			WPMUDEV_PLUGINTEST_VERSION
		);

		wp_register_script(
			'wpmudev-posts-maintenance-history',
			WPMUDEV_PLUGINTEST_ASSETS_URL . '/js/posts-maintenance-history.min.js',
			$dependencies,
			WPMUDEV_PLUGINTEST_VERSION,
			true
		);

		wp_localize_script(
			'wpmudev-posts-maintenance-history',
			'wpmudevPostsMaintenanceHistory',
			array(
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'restBase'  => esc_url_raw( rest_url() ),
				'endpoints' => array(
					'get'    => 'wpmudev/v1/posts-maintenance/scan/',
					'delete' => 'wpmudev/v1/posts-maintenance/scan/',
				),
				'history'   => $history,
				'strings'   => array(
					'deleteConfirm' => __( 'Are you sure you want to delete this scan record?', 'wpmudev-plugin-test' ),
					'deleteSuccess' => __( 'Scan record deleted successfully.', 'wpmudev-plugin-test' ),
					'deleteError'   => __( 'Failed to delete scan record.', 'wpmudev-plugin-test' ),
					'loadError'     => __( 'Failed to load scan details.', 'wpmudev-plugin-test' ),
				),
			)
		);

		wp_enqueue_script( 'wpmudev-posts-maintenance-history' );
		wp_enqueue_style( 'wpmudev-posts-maintenance-history' );
	}

	/**
	 * Renders admin page markup.
	 *
	 * @return void
	 */
	public function render_page() {
		?>
		<div id="<?php echo esc_attr( $this->wrapper_id ); ?>"></div>
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

	/**
	 * Renders scan history page.
	 *
	 * @return void
	 */
	public function render_history_page() {
		?>
		<div id="wpmudev-posts-maintenance-history-app"></div>
		<?php
	}
}

