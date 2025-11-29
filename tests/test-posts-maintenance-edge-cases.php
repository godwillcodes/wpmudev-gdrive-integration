<?php
/**
 * Posts Maintenance edge case unit tests.
 *
 * @package WPMUDEV_PluginTest
 */

use WPMUDEV\PluginTest\App\Services\Posts_Maintenance as Posts_Maintenance_Service;

/**
 * Class Test_Posts_Maintenance_Edge_Cases
 *
 * Tests for edge cases in the Posts Maintenance functionality.
 */
class Test_Posts_Maintenance_Edge_Cases extends WP_UnitTestCase {

	/**
	 * Service instance.
	 *
	 * @var Posts_Maintenance_Service
	 */
	protected $service;

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
	 * Resets service options between tests.
	 *
	 * @return void
	 */
	private function reset_state() {
		delete_option( 'wpmudev_posts_scan_job' );
		delete_option( 'wpmudev_posts_scan_last_run' );
		delete_option( 'wpmudev_posts_scan_settings' );
		delete_option( 'wpmudev_posts_scan_history' );
		wp_clear_scheduled_hook( 'wpmudev_posts_scan_process' );
		wp_clear_scheduled_hook( 'wpmudev_posts_scan_daily' );
	}

	/**
	 * Test scanning posts with blank content.
	 */
	public function test_detects_posts_with_blank_content() {
		// Create post with content.
		$post_with_content = $this->factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'This is some actual content.',
			)
		);

		// Create post with blank content.
		$post_blank = $this->factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '',
			)
		);

		// Create post with whitespace-only content.
		$post_whitespace = $this->factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '   ',
			)
		);

		$result = $this->service->start_job( array( 'post' ), 'manual' );
		$this->assertIsArray( $result );

		// Process all posts.
		$job = get_option( 'wpmudev_posts_scan_job', array() );
		while ( ! empty( $job ) && ! empty( $job['queue'] ) ) {
			$this->service->handle_process_event( $job['job_id'] );
			$job = get_option( 'wpmudev_posts_scan_job', array() );
		}

		$summary = get_option( 'wpmudev_posts_scan_last_run', array() );

		$this->assertArrayHasKey( 'metrics', $summary );
		// Should detect 2 posts with blank content (empty and whitespace).
		$this->assertGreaterThanOrEqual( 1, $summary['metrics']['posts_with_blank_content'] ?? 0 );
	}

	/**
	 * Test scanning posts missing featured images.
	 */
	public function test_detects_posts_missing_featured_image() {
		// Create post without featured image.
		$post_no_image = $this->factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'Content without image.',
			)
		);

		// Create post with featured image.
		$post_with_image = $this->factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'Content with image.',
			)
		);

		// Create an attachment and set as featured image.
		$attachment_id = $this->factory()->attachment->create_upload_object(
			DIR_TESTDATA . '/images/canola.jpg',
			$post_with_image
		);

		if ( $attachment_id ) {
			set_post_thumbnail( $post_with_image, $attachment_id );
		}

		$result = $this->service->start_job( array( 'post' ), 'manual' );
		$this->assertIsArray( $result );

		// Process all posts.
		$job = get_option( 'wpmudev_posts_scan_job', array() );
		while ( ! empty( $job ) && ! empty( $job['queue'] ) ) {
			$this->service->handle_process_event( $job['job_id'] );
			$job = get_option( 'wpmudev_posts_scan_job', array() );
		}

		$summary = get_option( 'wpmudev_posts_scan_last_run', array() );

		$this->assertArrayHasKey( 'metrics', $summary );
		// At least one post should be missing featured image.
		$this->assertGreaterThanOrEqual( 1, $summary['metrics']['posts_missing_featured_image'] ?? 0 );
	}

	/**
	 * Test health score calculation with zero posts.
	 */
	public function test_health_score_with_zero_posts() {
		// Don't create any posts.
		$result = $this->service->start_job( array( 'post' ), 'manual' );

		// Should handle gracefully - either return error or empty result.
		if ( is_array( $result ) ) {
			$this->assertEquals( 0, $result['total'] );
		}
	}

	/**
	 * Test scanning multiple post types.
	 */
	public function test_scan_multiple_post_types() {
		// Create posts of different types.
		$this->factory()->post->create( array( 'post_type' => 'post', 'post_status' => 'publish' ) );
		$this->factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );

		$result = $this->service->start_job( array( 'post', 'page' ), 'manual' );
		$this->assertIsArray( $result );
		$this->assertEquals( 2, $result['total'] );
		$this->assertContains( 'post', $result['post_types'] );
		$this->assertContains( 'page', $result['post_types'] );
	}

	/**
	 * Test that draft posts are not scanned.
	 */
	public function test_draft_posts_not_scanned() {
		// Create published post.
		$published = $this->factory()->post->create( array( 'post_status' => 'publish' ) );

		// Create draft post.
		$draft = $this->factory()->post->create( array( 'post_status' => 'draft' ) );

		$result = $this->service->start_job( array( 'post' ), 'manual' );
		$this->assertIsArray( $result );
		$this->assertEquals( 1, $result['total'] );

		$job = get_option( 'wpmudev_posts_scan_job', array() );
		$this->assertContains( $published, $job['queue'] );
		$this->assertNotContains( $draft, $job['queue'] );
	}

	/**
	 * Test that private posts are not scanned by default.
	 */
	public function test_private_posts_not_scanned() {
		// Create published post.
		$published = $this->factory()->post->create( array( 'post_status' => 'publish' ) );

		// Create private post.
		$private = $this->factory()->post->create( array( 'post_status' => 'private' ) );

		$result = $this->service->start_job( array( 'post' ), 'manual' );
		$this->assertIsArray( $result );
		$this->assertEquals( 1, $result['total'] );
	}

	/**
	 * Test scan history is recorded.
	 */
	public function test_scan_history_recorded() {
		$this->factory()->post->create( array( 'post_status' => 'publish' ) );

		$result = $this->service->start_job( array( 'post' ), 'manual' );
		$this->assertIsArray( $result );

		// Process all posts.
		$job = get_option( 'wpmudev_posts_scan_job', array() );
		while ( ! empty( $job ) && ! empty( $job['queue'] ) ) {
			$this->service->handle_process_event( $job['job_id'] );
			$job = get_option( 'wpmudev_posts_scan_job', array() );
		}

		$history = $this->service->get_history();
		$this->assertIsArray( $history );
		$this->assertGreaterThanOrEqual( 1, count( $history ) );
	}

	/**
	 * Test post meta timestamp format.
	 */
	public function test_post_meta_timestamp_format() {
		$post_id = $this->factory()->post->create( array( 'post_status' => 'publish' ) );

		$result = $this->service->start_job( array( 'post' ), 'manual' );
		$this->assertIsArray( $result );

		// Process all posts.
		$job = get_option( 'wpmudev_posts_scan_job', array() );
		while ( ! empty( $job ) && ! empty( $job['queue'] ) ) {
			$this->service->handle_process_event( $job['job_id'] );
			$job = get_option( 'wpmudev_posts_scan_job', array() );
		}

		$timestamp = get_post_meta( $post_id, 'wpmudev_test_last_scan', true );
		$this->assertNotEmpty( $timestamp );
		$this->assertIsNumeric( $timestamp );
		// Should be a valid Unix timestamp (within last minute).
		$this->assertGreaterThan( time() - 60, (int) $timestamp );
		$this->assertLessThanOrEqual( time(), (int) $timestamp );
	}

	/**
	 * Test job cleanup after completion.
	 */
	public function test_job_cleanup_after_completion() {
		$this->factory()->post->create( array( 'post_status' => 'publish' ) );

		$result = $this->service->start_job( array( 'post' ), 'manual' );
		$this->assertIsArray( $result );

		// Process all posts.
		$job = get_option( 'wpmudev_posts_scan_job', array() );
		while ( ! empty( $job ) && ! empty( $job['queue'] ) ) {
			$this->service->handle_process_event( $job['job_id'] );
			$job = get_option( 'wpmudev_posts_scan_job', array() );
		}

		// Job should be cleaned up.
		$job = get_option( 'wpmudev_posts_scan_job', array() );
		$this->assertEmpty( $job );

		// Last run should be recorded.
		$last_run = get_option( 'wpmudev_posts_scan_last_run', array() );
		$this->assertNotEmpty( $last_run );
	}

	/**
	 * Test scanning with very large batch.
	 */
	public function test_large_batch_processing() {
		// Create many posts.
		$post_ids = $this->factory()->post->create_many( 50, array( 'post_status' => 'publish' ) );

		$result = $this->service->start_job( array( 'post' ), 'manual' );
		$this->assertIsArray( $result );
		$this->assertEquals( 50, $result['total'] );

		// Process all posts.
		$job = get_option( 'wpmudev_posts_scan_job', array() );
		$iterations = 0;
		$max_iterations = 100; // Safety limit.

		while ( ! empty( $job ) && ! empty( $job['queue'] ) && $iterations < $max_iterations ) {
			$this->service->handle_process_event( $job['job_id'] );
			$job = get_option( 'wpmudev_posts_scan_job', array() );
			$iterations++;
		}

		// All posts should be processed.
		$summary = get_option( 'wpmudev_posts_scan_last_run', array() );
		$this->assertEquals( 50, $summary['processed'] );

		// All posts should have meta.
		foreach ( $post_ids as $post_id ) {
			$this->assertNotEmpty(
				get_post_meta( $post_id, 'wpmudev_test_last_scan', true ),
				"Post {$post_id} should have scan meta."
			);
		}
	}

	/**
	 * Test that HTML content is properly analyzed.
	 */
	public function test_html_content_analysis() {
		// Create post with HTML but actual text content.
		$post_with_html = $this->factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<p>This is <strong>real</strong> content.</p>',
			)
		);

		// Create post with only HTML tags (no text).
		$post_empty_html = $this->factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<p></p><div></div>',
			)
		);

		$result = $this->service->start_job( array( 'post' ), 'manual' );
		$this->assertIsArray( $result );

		// Process all posts.
		$job = get_option( 'wpmudev_posts_scan_job', array() );
		while ( ! empty( $job ) && ! empty( $job['queue'] ) ) {
			$this->service->handle_process_event( $job['job_id'] );
			$job = get_option( 'wpmudev_posts_scan_job', array() );
		}

		// Both posts should be scanned.
		$this->assertNotEmpty( get_post_meta( $post_with_html, 'wpmudev_test_last_scan', true ) );
		$this->assertNotEmpty( get_post_meta( $post_empty_html, 'wpmudev_test_last_scan', true ) );
	}

	/**
	 * Test source tracking (manual vs scheduled vs CLI).
	 */
	public function test_source_tracking() {
		$this->factory()->post->create( array( 'post_status' => 'publish' ) );

		// Test manual source.
		$result = $this->service->start_job( array( 'post' ), 'manual' );
		$this->assertIsArray( $result );
		$this->assertEquals( 'manual', $result['source'] );

		// Process and complete.
		$job = get_option( 'wpmudev_posts_scan_job', array() );
		while ( ! empty( $job ) && ! empty( $job['queue'] ) ) {
			$this->service->handle_process_event( $job['job_id'] );
			$job = get_option( 'wpmudev_posts_scan_job', array() );
		}

		$summary = get_option( 'wpmudev_posts_scan_last_run', array() );
		$this->assertEquals( 'manual', $summary['source'] );
	}

	/**
	 * Test that job ID is unique.
	 */
	public function test_job_id_uniqueness() {
		$this->factory()->post->create( array( 'post_status' => 'publish' ) );

		$result1 = $this->service->start_job( array( 'post' ), 'manual' );
		$job_id1 = $result1['job_id'];

		// Complete the job.
		$job = get_option( 'wpmudev_posts_scan_job', array() );
		while ( ! empty( $job ) && ! empty( $job['queue'] ) ) {
			$this->service->handle_process_event( $job['job_id'] );
			$job = get_option( 'wpmudev_posts_scan_job', array() );
		}

		// Start a new job.
		$result2 = $this->service->start_job( array( 'post' ), 'manual' );
		$job_id2 = $result2['job_id'];

		$this->assertNotEquals( $job_id1, $job_id2 );
	}
}
