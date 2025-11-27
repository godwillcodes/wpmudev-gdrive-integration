<?php
/**
 * Posts Maintenance unit tests.
 *
 * @package WPMUDEV_PluginTest
 */

use WPMUDEV\PluginTest\App\Services\Posts_Maintenance as Posts_Maintenance_Service;

/**
 * Class Test_Posts_Maintenance
 */
class Test_Posts_Maintenance extends WP_UnitTestCase {

	/**
	 * Service instance.
	 *
	 * @var Posts_Maintenance_Service
	 */
	protected $service;

	/**
	 * Optional override for default post types filter.
	 *
	 * @var array|null
	 */
	private $custom_post_types = null;

	/**
	 * Set up test case.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->service = Posts_Maintenance_Service::instance();
		$this->reset_state();
	}

	/**
	 * Tear down test case.
	 */
	public function tearDown(): void {
		$this->reset_state();
		parent::tearDown();
	}

	/**
	 * Resets service options/hooks between tests.
	 *
	 * @return void
	 */
	private function reset_state() {
		delete_option( 'wpmudev_posts_scan_job' );
		delete_option( 'wpmudev_posts_scan_last_run' );
		wp_clear_scheduled_hook( 'wpmudev_posts_scan_process' );
		wp_clear_scheduled_hook( 'wpmudev_posts_scan_daily' );
		$this->custom_post_types = null;
		remove_filter( 'wpmudev_posts_scan_post_types', array( $this, 'filter_default_post_types' ) );
		remove_filter( 'wpmudev_posts_scan_batch_size', array( $this, 'filter_batch_size' ) );
	}

	/**
	 * Filters default post types when requested.
	 *
	 * @param array $types Default types.
	 *
	 * @return array
	 */
	public function filter_default_post_types( $types ) {
		return is_array( $this->custom_post_types ) ? $this->custom_post_types : $types;
	}

	/**
	 * Filters batch size for CLI-style tests.
	 *
	 * @return int
	 */
	public function filter_batch_size() {
		return 1;
	}

	/**
	 * Ensures start_job initializes queue and persists metadata.
	 */
	public function test_start_job_initializes_queue() {
		$post_ids = $this->factory()->post->create_many( 3, array( 'post_status' => 'publish' ) );

		$result = $this->service->start_job( array( 'post' ), 'manual' );

		$this->assertIsArray( $result );
		$this->assertEquals( 3, $result['total'] );
		$this->assertEquals( 'pending', $result['status'] );

		$job = get_option( 'wpmudev_posts_scan_job', array() );

		$this->assertNotEmpty( $job );
		$this->assertEquals( $post_ids, $job['queue'] );
		$this->assertEquals( 0, $job['processed'] );
	}

	/**
	 * Ensures handle_process_event stamps post meta and completes job.
	 */
	public function test_handle_process_event_updates_meta() {
		$post_ids = $this->factory()->post->create_many( 5, array( 'post_status' => 'publish' ) );

		$result = $this->service->start_job( array( 'post' ), 'manual' );
		$this->assertIsArray( $result );

		$job = get_option( 'wpmudev_posts_scan_job', array() );

		while ( ! empty( $job ) && ! empty( $job['queue'] ) ) {
			$this->service->handle_process_event( $job['job_id'] );
			$job = get_option( 'wpmudev_posts_scan_job', array() );
		}

		$this->assertEmpty( $job );

		foreach ( $post_ids as $post_id ) {
			$this->assertNotEmpty(
				get_post_meta( $post_id, 'wpmudev_test_last_scan', true ),
				'Post meta should be stamped after scan.'
			);
		}

		$summary = get_option( 'wpmudev_posts_scan_last_run', array() );
		$this->assertEquals( 5, $summary['processed'] );
		$this->assertEquals( 5, $summary['total'] );
	}

	/**
	 * Ensures invalid post types are rejected.
	 */
	public function test_start_job_rejects_invalid_post_types() {
		$result = $this->service->start_job( array( 'foo_type' ), 'manual' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wpmudev_invalid_post_types', $result->get_error_code() );
	}

	/**
	 * Ensures default post types filter is honored when no post types passed.
	 */
	public function test_default_post_type_filter_is_honored() {
		$page_id = $this->factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );

		$this->custom_post_types = array( 'page' );
		add_filter( 'wpmudev_posts_scan_post_types', array( $this, 'filter_default_post_types' ) );

		$result = $this->service->start_job( array(), 'manual' );
		$this->assertIsArray( $result );
		$this->assertEquals( array( 'page' ), $result['post_types'] );
		$this->assertEquals( 1, $result['total'] );

		$job = get_option( 'wpmudev_posts_scan_job', array() );
		$this->assertEquals( array( $page_id ), $job['queue'] );
	}

	/**
	 * Ensures start_job prevents concurrent scans.
	 */
	public function test_prevents_concurrent_jobs() {
		$this->factory()->post->create( array( 'post_status' => 'publish' ) );

		$first = $this->service->start_job( array( 'post' ), 'manual' );
		$this->assertIsArray( $first );

		$second = $this->service->start_job( array( 'post' ), 'manual' );
		$this->assertInstanceOf( WP_Error::class, $second );
		$this->assertSame( 'wpmudev_scan_running', $second->get_error_code() );
	}

	/**
	 * Ensures batch size filters are honored (edge case).
	 */
	public function test_batch_size_filter_limits_processed_chunk() {
		$this->factory()->post->create_many( 2, array( 'post_status' => 'publish' ) );

		add_filter( 'wpmudev_posts_scan_batch_size', array( $this, 'filter_batch_size' ) );

		$result = $this->service->start_job( array( 'post' ), 'manual' );
		$this->assertIsArray( $result );

		$job = get_option( 'wpmudev_posts_scan_job', array() );
		$this->service->handle_process_event( $job['job_id'] );

		$updated = get_option( 'wpmudev_posts_scan_job', array() );
		$this->assertEquals( 1, $updated['processed'] );

		// Cleanup remainder.
		while ( $updated && ! empty( $updated['queue'] ) ) {
			$this->service->handle_process_event( $updated['job_id'] );
			$updated = get_option( 'wpmudev_posts_scan_job', array() );
		}
	}
}

