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
	 * Enqueues assets for history page.
	 *
	 * @return void
	 */
	private function enqueue_history_assets() {
		// Register and enqueue the CSS style for history page
		wp_register_style(
			'wpmudev-posts-maintenance',
			WPMUDEV_PLUGINTEST_ASSETS_URL . '/css/posts-maintenance.css',
			array(),
			WPMUDEV_PLUGINTEST_VERSION
		);

		wp_register_script(
			'wpmudev-posts-maintenance-history',
			WPMUDEV_PLUGINTEST_ASSETS_URL . '/js/posts-maintenance-history.js',
			array(),
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
				'strings'   => array(
					'deleteConfirm' => __( 'Are you sure you want to delete this scan record?', 'wpmudev-plugin-test' ),
					'deleteSuccess' => __( 'Scan record deleted successfully.', 'wpmudev-plugin-test' ),
					'deleteError'   => __( 'Failed to delete scan record.', 'wpmudev-plugin-test' ),
					'loadError'     => __( 'Failed to load scan details.', 'wpmudev-plugin-test' ),
				),
			)
		);

		wp_enqueue_script( 'wpmudev-posts-maintenance-history' );
		wp_enqueue_style( 'wpmudev-posts-maintenance' );
	}

	/**
	 * Renders admin page markup.
	 *
	 * @return void
	 */
	public function render_page() {
		?>
		<div class="sui-wrap wpmudev-posts-maintenance-wrap">
			<div class="sui-header">
				<h1 class="sui-header-title"><?php esc_html_e( 'Posts Maintenance', 'wpmudev-plugin-test' ); ?></h1>
			</div>

			<div class="wpmudev-posts-maintenance-grid">
				<!-- Summary Dashboard Pane -->
				<div class="sui-box wpmudev-posts-panel wpmudev-posts-panel--dashboard">
					<div class="sui-box-header">
						<h2 class="sui-box-title">
							<?php esc_html_e( 'Summary Dashboard', 'wpmudev-plugin-test' ); ?>
						</h2>
						<p class="sui-description">
							<?php esc_html_e( 'Site health overview and post statistics.', 'wpmudev-plugin-test' ); ?>
						</p>
					</div>
					<div class="sui-box-body">
						<div class="wpmudev-dashboard-content" id="<?php echo esc_attr( $this->wrapper_id ); ?>-dashboard">
							<p class="sui-description"><?php esc_html_e( 'Run a scan to see dashboard metrics.', 'wpmudev-plugin-test' ); ?></p>
						</div>
					</div>
				</div>

				<!-- Scan Configuration Pane -->
				<div class="sui-box wpmudev-posts-panel wpmudev-posts-panel--config">
					<div class="sui-box-header">
						<h2 class="sui-box-title">
							<?php esc_html_e( 'Scan Configuration', 'wpmudev-plugin-test' ); ?>
						</h2>
						<p class="sui-description">
							<?php esc_html_e( 'Select post types to scan and update maintenance timestamps.', 'wpmudev-plugin-test' ); ?>
						</p>
					</div>
					<div class="sui-box-body">
						<div class="sui-box-settings-row">
							<label class="sui-label"><?php esc_html_e( 'Post Types', 'wpmudev-plugin-test' ); ?></label>
							<div class="wpmudev-post-types-list" id="<?php echo esc_attr( $this->wrapper_id ); ?>-post-types"></div>
						</div>
					</div>
					<div class="sui-box-footer">
						<div class="sui-actions-right">
							<button type="button" class="sui-button sui-button-blue" id="<?php echo esc_attr( $this->wrapper_id ); ?>-start">
								<?php esc_html_e( 'Start Scan', 'wpmudev-plugin-test' ); ?>
							</button>
						</div>
					</div>
				</div>

				<!-- Scan Progress Pane -->
				<div class="sui-box wpmudev-posts-panel wpmudev-posts-panel--progress">
					<div class="sui-box-header">
						<h2 class="sui-box-title">
							<?php esc_html_e( 'Scan Progress', 'wpmudev-plugin-test' ); ?>
						</h2>
						<p class="sui-description">
							<?php esc_html_e( 'Monitor the current scan operation in real-time.', 'wpmudev-plugin-test' ); ?>
						</p>
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
						<div class="sui-actions-right">
							<button type="button" class="sui-button sui-button-ghost" id="<?php echo esc_attr( $this->wrapper_id ); ?>-refresh">
								<?php esc_html_e( 'Refresh Status', 'wpmudev-plugin-test' ); ?>
							</button>
						</div>
					</div>
				</div>

				<!-- Last Run Summary Pane -->
				<div class="sui-box wpmudev-posts-panel wpmudev-posts-panel--summary">
					<div class="sui-box-header">
						<h2 class="sui-box-title">
							<?php esc_html_e( 'Last Run Summary', 'wpmudev-plugin-test' ); ?>
						</h2>
						<p class="sui-description">
							<?php esc_html_e( 'View details from the most recent scan operation.', 'wpmudev-plugin-test' ); ?>
						</p>
					</div>
					<div class="sui-box-body">
						<div class="wpmudev-summary-content" id="<?php echo esc_attr( $this->wrapper_id ); ?>-last-run">
							<p class="sui-description"><?php esc_html_e( 'No previous scan recorded.', 'wpmudev-plugin-test' ); ?></p>
						</div>
					</div>
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

	/**
	 * Renders scan history page.
	 *
	 * @return void
	 */
	public function render_history_page() {
		$service = Posts_Maintenance_Service::instance();
		$history = $service->get_history();
		?>
		<div class="sui-wrap wpmudev-posts-maintenance-wrap">
			<div class="sui-header">
				<h1 class="sui-header-title"><?php esc_html_e( 'Scan History', 'wpmudev-plugin-test' ); ?></h1>
				<p class="sui-description"><?php esc_html_e( 'View and manage all previous scan records.', 'wpmudev-plugin-test' ); ?></p>
			</div>

			<div class="wpmudev-scan-history-container">
				<?php if ( empty( $history ) ) : ?>
					<div class="sui-box wpmudev-posts-panel">
						<div class="sui-box-body">
							<p class="sui-description"><?php esc_html_e( 'No scan history available. Run a scan to create records.', 'wpmudev-plugin-test' ); ?></p>
						</div>
					</div>
				<?php else : ?>
					<div class="wpmudev-scan-history-grid">
						<?php foreach ( $history as $record ) : ?>
							<?php
							$scan_id    = isset( $record['scan_id'] ) ? esc_attr( $record['scan_id'] ) : '';
							$timestamp  = isset( $record['timestamp'] ) ? (int) $record['timestamp'] : 0;
							$date       = $timestamp > 0 ? date_i18n( get_option( 'date_format' ), $timestamp ) : '';
							$time       = $timestamp > 0 ? date_i18n( get_option( 'time_format' ), $timestamp ) : '';
							$status     = isset( $record['status'] ) ? esc_html( ucfirst( $record['status'] ) ) : '';
							$total      = isset( $record['total'] ) ? (int) $record['total'] : 0;
							$processed  = isset( $record['processed'] ) ? (int) $record['processed'] : 0;
							$health     = isset( $record['health_score'] ) ? (float) $record['health_score'] : 0;
							$post_types = isset( $record['post_types'] ) && is_array( $record['post_types'] ) ? $record['post_types'] : array();
							$context    = isset( $record['context'] ) ? esc_html( ucfirst( $record['context'] ) ) : 'Manual';
							$metrics    = isset( $record['metrics'] ) ? $record['metrics'] : array();
							$broken_links = isset( $metrics['posts_with_broken_links'] ) ? (int) $metrics['posts_with_broken_links'] : 0;
							$blank_content = isset( $metrics['posts_with_blank_content'] ) ? (int) $metrics['posts_with_blank_content'] : 0;
							$missing_images = isset( $metrics['posts_missing_featured_image'] ) ? (int) $metrics['posts_missing_featured_image'] : 0;
							?>
							<div class="sui-box wpmudev-scan-record" data-scan-id="<?php echo esc_attr( $scan_id ); ?>">
								<div class="sui-box-header">
									<div class="wpmudev-scan-record-header">
										<div class="wpmudev-scan-record-date">
											<div class="wpmudev-scan-date-main"><?php echo esc_html( $date ); ?></div>
											<div class="wpmudev-scan-date-time"><?php echo esc_html( $time ); ?></div>
										</div>
										<div class="wpmudev-scan-record-health">
											<div class="wpmudev-scan-health-value"><?php echo esc_html( number_format( $health, 1 ) ); ?>%</div>
											<div class="wpmudev-scan-health-label"><?php esc_html_e( 'Health Score', 'wpmudev-plugin-test' ); ?></div>
										</div>
									</div>
								</div>
								<div class="sui-box-body">
									<div class="wpmudev-scan-record-content">
										<div class="wpmudev-scan-record-meta">
											<div class="wpmudev-scan-meta-item">
												<span class="wpmudev-scan-meta-label"><?php esc_html_e( 'Status', 'wpmudev-plugin-test' ); ?></span>
												<span class="wpmudev-scan-badge wpmudev-scan-badge-<?php echo esc_attr( strtolower( $status ) ); ?>">
													<?php echo esc_html( $status ); ?>
												</span>
											</div>
											<div class="wpmudev-scan-meta-item">
												<span class="wpmudev-scan-meta-label"><?php esc_html_e( 'Context', 'wpmudev-plugin-test' ); ?></span>
												<span class="wpmudev-scan-meta-value"><?php echo esc_html( $context ); ?></span>
											</div>
											<div class="wpmudev-scan-meta-item">
												<span class="wpmudev-scan-meta-label"><?php esc_html_e( 'Processed', 'wpmudev-plugin-test' ); ?></span>
												<span class="wpmudev-scan-meta-value"><?php echo esc_html( $processed ); ?> / <?php echo esc_html( $total ); ?></span>
											</div>
										</div>
										<?php if ( ! empty( $post_types ) ) : ?>
											<div class="wpmudev-scan-record-post-types">
												<span class="wpmudev-scan-post-types-label"><?php esc_html_e( 'Post Types', 'wpmudev-plugin-test' ); ?></span>
												<div class="wpmudev-scan-post-types-list">
													<?php foreach ( $post_types as $type ) : ?>
														<span class="wpmudev-scan-post-type-tag"><?php echo esc_html( $type ); ?></span>
													<?php endforeach; ?>
												</div>
											</div>
										<?php endif; ?>
										<?php if ( $broken_links > 0 || $blank_content > 0 || $missing_images > 0 ) : ?>
											<div class="wpmudev-scan-record-issues">
												<?php if ( $broken_links > 0 ) : ?>
													<div class="wpmudev-scan-issue-item">
														<span class="wpmudev-scan-issue-icon">⚠</span>
														<span class="wpmudev-scan-issue-text"><?php echo esc_html( $broken_links ); ?> <?php esc_html_e( 'broken links', 'wpmudev-plugin-test' ); ?></span>
													</div>
												<?php endif; ?>
												<?php if ( $blank_content > 0 ) : ?>
													<div class="wpmudev-scan-issue-item">
														<span class="wpmudev-scan-issue-icon">□</span>
														<span class="wpmudev-scan-issue-text"><?php echo esc_html( $blank_content ); ?> <?php esc_html_e( 'blank content', 'wpmudev-plugin-test' ); ?></span>
													</div>
												<?php endif; ?>
												<?php if ( $missing_images > 0 ) : ?>
													<div class="wpmudev-scan-issue-item">
														<span class="wpmudev-scan-issue-icon">🖼</span>
														<span class="wpmudev-scan-issue-text"><?php echo esc_html( $missing_images ); ?> <?php esc_html_e( 'missing images', 'wpmudev-plugin-test' ); ?></span>
													</div>
												<?php endif; ?>
											</div>
										<?php endif; ?>
									</div>
								</div>
								<div class="sui-box-footer">
									<div class="wpmudev-scan-record-actions">
										<button type="button" class="sui-button sui-button-ghost wpmudev-view-scan-details" data-scan-id="<?php echo esc_attr( $scan_id ); ?>">
											<?php esc_html_e( 'View Details', 'wpmudev-plugin-test' ); ?>
										</button>
										<button type="button" class="sui-button sui-button-ghost wpmudev-delete-scan" data-scan-id="<?php echo esc_attr( $scan_id ); ?>">
											<?php esc_html_e( 'Delete', 'wpmudev-plugin-test' ); ?>
										</button>
									</div>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>

			<!-- Modal for scan details -->
			<div class="sui-modal sui-modal-lg" id="wpmudev-scan-details-modal" aria-hidden="true">
				<div class="sui-modal-content" role="dialog">
					<div class="sui-box" role="document">
						<div class="sui-box-header">
							<h3 class="sui-box-title"><?php esc_html_e( 'Scan Details', 'wpmudev-plugin-test' ); ?></h3>
							<button class="sui-button-icon sui-button-float--right" data-modal-close>
								<span class="sui-icon-close" aria-hidden="true"></span>
							</button>
						</div>
						<div class="sui-box-body" id="wpmudev-scan-details-content">
							<p class="sui-description"><?php esc_html_e( 'Loading...', 'wpmudev-plugin-test' ); ?></p>
						</div>
						<div class="sui-box-footer">
							<button class="sui-button" data-modal-close><?php esc_html_e( 'Close', 'wpmudev-plugin-test' ); ?></button>
						</div>
					</div>
				</div>
			</div>

			<div class="sui-notice" id="wpmudev-scan-history-notice" aria-live="polite" style="display:none;"></div>
		</div>
		<?php
	}
}

