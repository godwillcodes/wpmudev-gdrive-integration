<?php
/**
 * Posts maintenance service - handles scans, cron, and job storage.
 *
 * @package WPMUDEV\PluginTest
 */

namespace WPMUDEV\PluginTest\App\Services;

use WP_Query;
use WPMUDEV\PluginTest\Base;

// Abort if called directly.
defined( 'WPINC' ) || die;

/**
 * Posts maintenance service class.
 */
class Posts_Maintenance extends Base {

	const OPTION_JOB      = 'wpmudev_posts_scan_job';
	const OPTION_LAST_RUN = 'wpmudev_posts_scan_last_run';
	const OPTION_HISTORY  = 'wpmudev_posts_scan_history';

	const CRON_HOOK_PROCESS = 'wpmudev_posts_scan_process';
	const CRON_HOOK_DAILY   = 'wpmudev_posts_scan_daily';

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( self::CRON_HOOK_PROCESS, array( $this, 'handle_process_event' ), 10, 1 );
		add_action( self::CRON_HOOK_DAILY, array( $this, 'handle_daily_event' ) );
		add_action( 'init', array( $this, 'maybe_schedule_daily_event' ) );
	}

	/**
	 * Returns default post types.
	 *
	 * @return array
	 */
	public function get_default_post_types(): array {
		$types = array( 'post', 'page' );

		return apply_filters( 'wpmudev_posts_scan_post_types', $types );
	}

	/**
	 * Start a new scan job.
	 *
	 * @param array  $post_types Post types to scan.
	 * @param string $context    Context string (manual|schedule).
	 *
	 * @return array|\WP_Error
	 */
	public function start_job( array $post_types = array(), string $context = 'manual' ) {
		$current_job = $this->get_job();

		if ( ! empty( $current_job ) && in_array( $current_job['status'], array( 'pending', 'running' ), true ) ) {
			return new \WP_Error(
				'wpmudev_scan_running',
				__( 'A scan is already running. Please wait for it to finish before starting a new one.', 'wpmudev-plugin-test' )
			);
		}

		$post_types = ! empty( $post_types ) ? array_map( 'sanitize_key', $post_types ) : $this->get_default_post_types();

		$public_types = get_post_types(
			array(
				'public' => true,
			)
		);

		$post_types = array_values( array_intersect( $post_types, array_keys( $public_types ) ) );

		if ( empty( $post_types ) ) {
			return new \WP_Error(
				'wpmudev_invalid_post_types',
				__( 'No valid public post types were provided.', 'wpmudev-plugin-test' )
			);
		}

		// Get published posts for processing
		$ids = get_posts(
			array(
				'post_type'           => $post_types,
				'post_status'         => 'publish',
				'fields'              => 'ids',
				'orderby'             => 'ID',
				'order'               => 'ASC',
				'posts_per_page'      => -1,
				'suppress_filters'    => true,
				'ignore_sticky_posts' => true,
			)
		);

		// Get all posts (all statuses) for metrics
		$all_post_ids = get_posts(
			array(
				'post_type'           => $post_types,
				'post_status'         => 'any',
				'fields'              => 'ids',
				'orderby'             => 'ID',
				'order'               => 'ASC',
				'posts_per_page'      => -1,
				'suppress_filters'    => true,
				'ignore_sticky_posts' => true,
			)
		);

		if ( empty( $ids ) && empty( $all_post_ids ) ) {
			return new \WP_Error(
				'wpmudev_no_posts',
				__( 'No posts were found for the selected post types.', 'wpmudev-plugin-test' )
			);
		}

		$job_id = wp_generate_uuid4();

		$job = array(
			'job_id'      => $job_id,
			'status'      => 'pending',
			'post_types'  => $post_types,
			'queue'       => $ids,
			'total'       => count( $ids ),
			'processed'   => 0,
			'started_at'  => time(),
			'updated_at'  => time(),
			'context'     => $context,
			'last_error'  => '',
			'metrics_queue' => $all_post_ids, // All posts for metrics collection
			'metrics'     => array(
				'total_posts'              => 0,
				'published_posts'           => 0,
				'draft_private_posts'      => 0,
				'posts_with_broken_links'   => 0,
				'posts_with_blank_content' => 0,
				'posts_missing_featured_image' => 0,
			),
		);

		update_option( self::OPTION_JOB, $job, false );

		$this->schedule_processing_event( $job_id, 5 );

		return $this->format_job_for_response( $job );
	}

	/**
	 * Returns the current job.
	 *
	 * @return array
	 */
	public function get_job(): array {
		$job = get_option( self::OPTION_JOB, array() );

		return is_array( $job ) ? $job : array();
	}

	/**
	 * Schedules a single processing event.
	 *
	 * @param string $job_id Job identifier.
	 * @param int    $delay  Delay in seconds.
	 *
	 * @return void
	 */
	private function schedule_processing_event( string $job_id, int $delay = 10 ) {
		wp_schedule_single_event( time() + $delay, self::CRON_HOOK_PROCESS, array( $job_id ) );
	}

	/**
	 * Processes the job queue via cron.
	 *
	 * @param string $job_id Job identifier.
	 *
	 * @return void
	 */
	public function handle_process_event( string $job_id ) {
		$job = $this->get_job();

		if ( empty( $job ) || $job_id !== $job['job_id'] ) {
			return;
		}

		$job['status'] = 'running';

		$batch_size = apply_filters( 'wpmudev_posts_scan_batch_size', 25 );

		// Process main queue (published posts for meta updates)
		if ( ! empty( $job['queue'] ) ) {
			$batch = array_splice( $job['queue'], 0, $batch_size );
			$timestamp = current_time( 'timestamp' );

			foreach ( $batch as $post_id ) {
				update_post_meta( $post_id, 'wpmudev_test_last_scan', $timestamp );
			}

			$job['processed'] += count( $batch );
		}

		// Collect metrics from metrics queue (all posts, all statuses)
		// This must run even if main queue is empty (e.g., only draft/private posts)
		if ( ! empty( $job['metrics_queue'] ) ) {
			$metrics_batch_size = min( $batch_size, count( $job['metrics_queue'] ) );
			$metrics_batch = array_splice( $job['metrics_queue'], 0, $metrics_batch_size );
			foreach ( $metrics_batch as $post_id ) {
				$this->collect_post_metrics( $post_id, $job['metrics'] );
			}
		}

		$job['updated_at'] = time();
		update_option( self::OPTION_JOB, $job, false );

		// Complete job only when both queues are empty
		if ( empty( $job['queue'] ) && empty( $job['metrics_queue'] ) ) {
			$this->complete_job( $job, true );
		} else {
			$this->schedule_processing_event( $job['job_id'], 10 );
		}
	}

	/**
	 * Completes job and stores last run summary.
	 *
	 * @param array $job    Job array.
	 * @param bool  $success Success flag.
	 *
	 * @return void
	 */
	private function complete_job( array $job, bool $success = true ) {
		$job['status']      = $success ? 'completed' : 'failed';
		$job['completed_at'] = time();

		update_option( self::OPTION_JOB, $job, false );

		// Calculate site health score
		$health_score = $this->calculate_site_health_score( $job['metrics'] );
		
		$scan_record = array(
			'scan_id'     => $job['job_id'],
			'timestamp'   => time(),
			'post_types'  => $job['post_types'],
			'processed'   => $job['processed'],
			'total'       => $job['total'],
			'status'      => $job['status'],
			'metrics'     => $job['metrics'],
			'health_score' => $health_score,
			'context'     => isset( $job['context'] ) ? $job['context'] : 'manual',
		);
		
		// Update last run
		update_option(
			self::OPTION_LAST_RUN,
			$scan_record,
			false
		);
		
		// Add to history
		$this->add_to_history( $scan_record );

		if ( $success ) {
			delete_option( self::OPTION_JOB );
		}
	}

	/**
	 * Handles scheduled daily scan.
	 *
	 * @return void
	 */
	public function handle_daily_event() {
		$job = $this->get_job();

		if ( ! empty( $job ) && in_array( $job['status'], array( 'pending', 'running' ), true ) ) {
			return;
		}

		$this->start_job( $this->get_default_post_types(), 'schedule' );
	}

	/**
	 * Ensures the daily cron event is scheduled.
	 *
	 * @return void
	 */
	public function maybe_schedule_daily_event() {
		if ( ! wp_next_scheduled( self::CRON_HOOK_DAILY ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::CRON_HOOK_DAILY );
		}
	}

	/**
	 * Returns last run summary.
	 *
	 * @return array
	 */
	public function get_last_run(): array {
		$summary = get_option( self::OPTION_LAST_RUN, array() );

		return is_array( $summary ) ? $summary : array();
	}

	/**
	 * Formats job for REST/JS responses.
	 *
	 * @param array $job Job array.
	 *
	 * @return array|null
	 */
	public function format_job_for_response( array $job = array() ) {
		if ( empty( $job ) ) {
			$job = $this->get_job();
		}

		if ( empty( $job ) ) {
			return null;
		}

		$total     = (int) $job['total'];
		$processed = (int) $job['processed'];
		$progress  = 0;

		if ( $total > 0 ) {
			$progress = min( 100, round( ( $processed / $total ) * 100 ) );
		}

		$response = array(
			'job_id'      => $job['job_id'],
			'status'      => $job['status'],
			'post_types'  => $job['post_types'],
			'total'       => $total,
			'processed'   => $processed,
			'progress'    => $progress,
			'started_at'  => isset( $job['started_at'] ) ? (int) $job['started_at'] : null,
			'updated_at'  => isset( $job['updated_at'] ) ? (int) $job['updated_at'] : null,
			'context'     => isset( $job['context'] ) ? $job['context'] : 'manual',
		);
		
		// Include metrics if available
		if ( isset( $job['metrics'] ) ) {
			$response['metrics'] = $job['metrics'];
			if ( isset( $job['metrics'] ) && $job['processed'] > 0 ) {
				$response['health_score'] = $this->calculate_site_health_score( $job['metrics'] );
			}
		}
		
		return $response;
	}

	/**
	 * Collects metrics for a single post.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $metrics Metrics array reference.
	 *
	 * @return void
	 */
	private function collect_post_metrics( int $post_id, array &$metrics ) {
		$post = get_post( $post_id );
		
		if ( ! $post ) {
			return;
		}

		$metrics['total_posts']++;

		// Check post status
		if ( 'publish' === $post->post_status ) {
			$metrics['published_posts']++;
		} else {
			$metrics['draft_private_posts']++;
		}

		// Check for blank content
		$content = strip_tags( $post->post_content );
		$content = trim( $content );
		if ( empty( $content ) ) {
			$metrics['posts_with_blank_content']++;
		}

		// Check for featured image
		$thumbnail_id = get_post_thumbnail_id( $post_id );
		if ( empty( $thumbnail_id ) || ! wp_attachment_is_image( $thumbnail_id ) ) {
			$metrics['posts_missing_featured_image']++;
		}

		// Check for broken internal links
		if ( $this->has_broken_internal_links( $post ) ) {
			$metrics['posts_with_broken_links']++;
		}
	}

	/**
	 * Checks if a post has broken internal links.
	 *
	 * @param \WP_Post $post Post object.
	 *
	 * @return bool
	 */
	private function has_broken_internal_links( \WP_Post $post ): bool {
		$content = $post->post_content;
		$site_url = home_url();
		$parsed_site = wp_parse_url( $site_url );
		$site_host = isset( $parsed_site['host'] ) ? $parsed_site['host'] : '';

		if ( empty( $site_host ) ) {
			return false;
		}

		// Find all links in content
		preg_match_all( '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $content, $matches );

		if ( empty( $matches[1] ) ) {
			return false;
		}

		foreach ( $matches[1] as $url ) {
			$parsed_url = wp_parse_url( $url );
			$url_host = isset( $parsed_url['host'] ) ? $parsed_url['host'] : '';

			// Only check internal links (same domain or relative)
			if ( ! empty( $url_host ) && $url_host !== $site_host ) {
				continue;
			}

			// Handle relative URLs
			if ( empty( $url_host ) || $url_host === $site_host ) {
				$path = isset( $parsed_url['path'] ) ? $parsed_url['path'] : $url;
				
				// Extract post/page slug or ID from URL
				$post_id = url_to_postid( $url );
				
				if ( $post_id > 0 ) {
					$linked_post = get_post( $post_id );
					if ( ! $linked_post || 'publish' !== $linked_post->post_status ) {
						return true; // Broken link found
					}
				} elseif ( ! empty( $path ) && '/' !== $path ) {
					// Try to find post by slug
					$page = get_page_by_path( trim( $path, '/' ) );
					if ( ! $page || 'publish' !== $page->post_status ) {
						return true; // Broken link found
					}
				}
			}
		}

		return false;
	}

	/**
	 * Calculates the Site Health Score.
	 *
	 * @param array $metrics Metrics array.
	 *
	 * @return float
	 */
	public function calculate_site_health_score( array $metrics ): float {
		$total = (int) $metrics['total_posts'];

		if ( $total === 0 ) {
			return 100.0; // Perfect score if no posts
		}

		// Published Posts Ratio
		$published_ratio = $total > 0 ? ( (int) $metrics['published_posts'] / $total ) : 1.0;

		// Posts Without Broken Links Ratio
		$no_broken_links_ratio = $total > 0 ? ( ( $total - (int) $metrics['posts_with_broken_links'] ) / $total ) : 1.0;

		// Posts With Content Ratio
		$with_content_ratio = $total > 0 ? ( ( $total - (int) $metrics['posts_with_blank_content'] ) / $total ) : 1.0;

		// Posts With Featured Images Ratio
		$with_featured_ratio = $total > 0 ? ( ( $total - (int) $metrics['posts_missing_featured_image'] ) / $total ) : 1.0;

		// Average of all four ratios
		$score = ( $published_ratio + $no_broken_links_ratio + $with_content_ratio + $with_featured_ratio ) / 4.0;

		return round( $score * 100, 1 );
	}

	/**
	 * Adds a scan record to history.
	 *
	 * @param array $scan_record Scan record data.
	 *
	 * @return void
	 */
	private function add_to_history( array $scan_record ) {
		$history = get_option( self::OPTION_HISTORY, array() );
		
		if ( ! is_array( $history ) ) {
			$history = array();
		}
		
		// Add new record at the beginning
		array_unshift( $history, $scan_record );
		
		// Limit history to last 100 scans
		$history = array_slice( $history, 0, 100 );
		
		update_option( self::OPTION_HISTORY, $history, false );
	}

	/**
	 * Returns scan history.
	 *
	 * @param int $limit Maximum number of records to return.
	 *
	 * @return array
	 */
	public function get_history( int $limit = 100 ): array {
		$history = get_option( self::OPTION_HISTORY, array() );
		
		if ( ! is_array( $history ) ) {
			return array();
		}
		
		return array_slice( $history, 0, $limit );
	}

	/**
	 * Gets a specific scan record by ID.
	 *
	 * @param string $scan_id Scan ID.
	 *
	 * @return array|null
	 */
	public function get_scan_record( string $scan_id ) {
		$history = $this->get_history();
		
		foreach ( $history as $record ) {
			if ( isset( $record['scan_id'] ) && $record['scan_id'] === $scan_id ) {
				return $record;
			}
		}
		
		return null;
	}

	/**
	 * Deletes a scan record from history.
	 *
	 * @param string $scan_id Scan ID.
	 *
	 * @return bool
	 */
	public function delete_scan_record( string $scan_id ): bool {
		$history = get_option( self::OPTION_HISTORY, array() );
		
		if ( ! is_array( $history ) ) {
			return false;
		}
		
		$updated = false;
		foreach ( $history as $key => $record ) {
			if ( isset( $record['scan_id'] ) && $record['scan_id'] === $scan_id ) {
				unset( $history[ $key ] );
				$updated = true;
				break;
			}
		}
		
		if ( $updated ) {
			// Re-index array
			$history = array_values( $history );
			update_option( self::OPTION_HISTORY, $history, false );
			
			// If deleted record was the last run, clear last run
			$last_run = get_option( self::OPTION_LAST_RUN, array() );
			if ( isset( $last_run['scan_id'] ) && $last_run['scan_id'] === $scan_id ) {
				delete_option( self::OPTION_LAST_RUN );
			}
		}
		
		return $updated;
	}
}

