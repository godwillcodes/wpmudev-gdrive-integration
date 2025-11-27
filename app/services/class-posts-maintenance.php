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

		if ( empty( $ids ) ) {
			return new \WP_Error(
				'wpmudev_no_posts',
				__( 'No published posts were found for the selected post types.', 'wpmudev-plugin-test' )
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

		if ( empty( $job['queue'] ) ) {
			$this->complete_job( $job, true );
			return;
		}

		$job['status'] = 'running';

		$batch_size = apply_filters( 'wpmudev_posts_scan_batch_size', 25 );

		$batch = array_splice( $job['queue'], 0, $batch_size );

		$timestamp = current_time( 'timestamp' );

		foreach ( $batch as $post_id ) {
			update_post_meta( $post_id, 'wpmudev_test_last_scan', $timestamp );
		}

		$job['processed'] += count( $batch );
		$job['updated_at'] = time();

		update_option( self::OPTION_JOB, $job, false );

		if ( empty( $job['queue'] ) ) {
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

		update_option(
			self::OPTION_LAST_RUN,
			array(
				'timestamp'  => time(),
				'post_types' => $job['post_types'],
				'processed'  => $job['processed'],
				'total'      => $job['total'],
				'status'     => $job['status'],
			),
			false
		);

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

		return array(
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
	}
}

