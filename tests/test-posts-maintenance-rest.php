<?php
/**
 * Posts Maintenance REST API unit tests.
 *
 * @package WPMUDEV_PluginTest
 */

use WPMUDEV\PluginTest\App\Services\Posts_Maintenance as Posts_Maintenance_Service;

/**
 * Class Test_Posts_Maintenance_REST
 *
 * Tests for the Posts Maintenance REST API endpoints.
 */
class Test_Posts_Maintenance_REST extends WP_UnitTestCase {

	/**
	 * REST server instance.
	 *
	 * @var WP_REST_Server
	 */
	protected $server;

	/**
	 * Service instance.
	 *
	 * @var Posts_Maintenance_Service
	 */
	protected $service;

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Subscriber user ID.
	 *
	 * @var int
	 */
	protected $subscriber_id;

	/**
	 * Set up test case.
	 */
	public function setUp(): void {
		parent::setUp();

		// Initialize REST server.
		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$this->service = Posts_Maintenance_Service::instance();

		// Create test users.
		$this->admin_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		$this->subscriber_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->reset_state();
	}

	/**
	 * Tear down test case.
	 */
	public function tearDown(): void {
		$this->reset_state();
		wp_set_current_user( 0 );

		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tearDown();
	}

	/**
	 * Resets service options between tests.
	 *
	 * @return void
	 */
	private function reset_state() {
		delete_option( 'wpmudev_posts_scan_job' );
		delete_option( 'wpmudev_posts_scan_last_run' );
		delete_option( 'wpmudev_posts_scan_settings' );
		wp_clear_scheduled_hook( 'wpmudev_posts_scan_process' );
		wp_clear_scheduled_hook( 'wpmudev_posts_scan_daily' );
	}

	/**
	 * Test that unauthenticated users cannot start a scan.
	 */
	public function test_start_endpoint_requires_authentication() {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'POST', '/wpmudev/v1/posts-maintenance/start' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 401, $response->get_status() );
	}

	/**
	 * Test that subscribers cannot start a scan.
	 */
	public function test_start_endpoint_requires_admin_capability() {
		wp_set_current_user( $this->subscriber_id );

		$request = new WP_REST_Request( 'POST', '/wpmudev/v1/posts-maintenance/start' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * Test that admins can start a scan.
	 */
	public function test_start_endpoint_allows_admin() {
		wp_set_current_user( $this->admin_id );

		// Create some posts to scan.
		$this->factory()->post->create_many( 3, array( 'post_status' => 'publish' ) );

		$request = new WP_REST_Request( 'POST', '/wpmudev/v1/posts-maintenance/start' );
		$response = $this->server->dispatch( $request );

		// Should succeed or return job data.
		$this->assertContains( $response->get_status(), array( 200, 201 ) );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'success', $data );
	}

	/**
	 * Test that status endpoint returns job information.
	 */
	public function test_status_endpoint_returns_job_info() {
		wp_set_current_user( $this->admin_id );

		// Start a job first.
		$this->factory()->post->create_many( 2, array( 'post_status' => 'publish' ) );
		$this->service->start_job( array( 'post' ), 'manual' );

		$request = new WP_REST_Request( 'GET', '/wpmudev/v1/posts-maintenance/status' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'job', $data );
	}

	/**
	 * Test that status endpoint works without active job.
	 */
	public function test_status_endpoint_without_active_job() {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'GET', '/wpmudev/v1/posts-maintenance/status' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'job', $data );
		$this->assertNull( $data['job'] );
	}

	/**
	 * Test settings endpoint requires authentication.
	 */
	public function test_settings_endpoint_requires_auth() {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/wpmudev/v1/posts-maintenance/settings' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 401, $response->get_status() );
	}

	/**
	 * Test history endpoint returns scan history.
	 */
	public function test_history_endpoint_returns_data() {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'GET', '/wpmudev/v1/posts-maintenance/history' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
	}
}
