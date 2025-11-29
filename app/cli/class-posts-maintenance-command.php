<?php
/**
 * WP-CLI command for Posts Maintenance.
 *
 * @package WPMUDEV\PluginTest
 */

namespace WPMUDEV\PluginTest\App\CLI;

use WP_CLI;
use WP_CLI_Command;
use WP_CLI\Formatter;
use WPMUDEV\PluginTest\App\Services\Posts_Maintenance as Posts_Maintenance_Service;
use function WP_CLI\Utils\make_progress_bar;

// Abort if called directly.
defined( 'WPINC' ) || die;

/**
 * Manage Posts Maintenance scans.
 *
 * ## EXAMPLES
 *
 *     # Run a scan on default post types
 *     $ wp wpmudev posts-scan
 *
 *     # Dry run to preview what would be scanned
 *     $ wp wpmudev posts-scan --dry-run
 *
 *     # Scan specific post types with JSON output
 *     $ wp wpmudev posts-scan --post_types=post,page --format=json
 *
 * @package WPMUDEV\PluginTest
 */
class Posts_Maintenance_Command extends WP_CLI_Command {

	/**
	 * Registers the CLI command.
	 *
	 * @return void
	 */
	public static function register() {
		if ( class_exists( '\WP_CLI' ) ) {
			WP_CLI::add_command( 'wpmudev posts-scan', __CLASS__ );
		}
	}

	/**
	 * Scan public posts/pages and stamp the `wpmudev_test_last_scan` meta field.
	 *
	 * Processes all published posts of the specified types and updates their
	 * `wpmudev_test_last_scan` post meta with the current timestamp. Also
	 * collects site health metrics including content analysis.
	 *
	 * ## OPTIONS
	 *
	 * [--post_types=<types>]
	 * : Comma-separated list of public post types (e.g. `post,page,product`).
	 * Defaults to the same set used in the admin UI (filterable via
	 * `wpmudev_posts_scan_post_types`).
	 *
	 * [--batch_size=<number>]
	 * : Number of posts to process per batch (default 25). Must be between 1 and 200.
	 *
	 * [--dry-run]
	 * : Preview what would be scanned without actually processing posts.
	 * Shows post counts and types that would be affected.
	 *
	 * [--format=<format>]
	 * : Output format. Options: table, json, csv, yaml. Default: table.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * [--quiet]
	 * : Suppress progress output. Only show final result or errors.
	 * Useful for cron jobs and scripted usage.
	 *
	 * ## EXAMPLES
	 *
	 *     # Scan default post types (posts & pages)
	 *     $ wp wpmudev posts-scan
	 *     Success: Scan complete. Processed 150 of 150 posts.
	 *
	 *     # Preview what would be scanned (dry run)
	 *     $ wp wpmudev posts-scan --dry-run
	 *     Dry run: Would scan 150 posts across: post, page
	 *
	 *     # Scan custom post types with smaller batches
	 *     $ wp wpmudev posts-scan --post_types=product,docs --batch_size=10
	 *
	 *     # Get scan results as JSON (useful for scripting)
	 *     $ wp wpmudev posts-scan --format=json
	 *     {"status":"completed","processed":150,"total":150,"health_score":87.5}
	 *
	 *     # Quiet mode for cron jobs
	 *     $ wp wpmudev posts-scan --quiet
	 *
	 *     # Combine options
	 *     $ wp wpmudev posts-scan --post_types=post --dry-run --format=json
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Assoc args.
	 *
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		$post_types = array();
		if ( ! empty( $assoc_args['post_types'] ) ) {
			$post_types = array_map( 'sanitize_key', array_filter( array_map( 'trim', explode( ',', $assoc_args['post_types'] ) ) ) );
		}

		$batch_size = null;
		if ( ! empty( $assoc_args['batch_size'] ) ) {
			$batch_size = (int) $assoc_args['batch_size'];
			if ( $batch_size < 1 || $batch_size > 200 ) {
				WP_CLI::error( __( 'Batch size must be between 1 and 200.', 'wpmudev-plugin-test' ) );
			}
		}

		$dry_run = isset( $assoc_args['dry-run'] ) && $assoc_args['dry-run'];
		$format  = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
		$quiet   = isset( $assoc_args['quiet'] ) && $assoc_args['quiet'];

		$service = Posts_Maintenance_Service::instance();

		// Get post types to scan.
		$scan_post_types = ! empty( $post_types ) ? $post_types : $service->get_default_post_types();

		// Validate post types.
		$public_types   = get_post_types( array( 'public' => true ) );
		$scan_post_types = array_values( array_intersect( $scan_post_types, array_keys( $public_types ) ) );

		if ( empty( $scan_post_types ) ) {
			WP_CLI::error( __( 'No valid public post types were provided.', 'wpmudev-plugin-test' ) );
		}

		// Get post count for dry run or actual scan.
		$post_ids = get_posts(
			array(
				'post_type'           => $scan_post_types,
				'post_status'         => 'publish',
				'fields'              => 'ids',
				'posts_per_page'      => -1,
				'suppress_filters'    => true,
				'ignore_sticky_posts' => true,
			)
		);

		$total = count( $post_ids );

		// Handle dry run.
		if ( $dry_run ) {
			$this->handle_dry_run( $scan_post_types, $total, $format, $quiet );
			return;
		}

		// Apply batch size filter if specified.
		$filter = null;
		if ( $batch_size ) {
			$filter = function () use ( $batch_size ) {
				return $batch_size;
			};
			add_filter( 'wpmudev_posts_scan_batch_size', $filter );
		}

		$result = $service->start_job( $post_types, 'cli' );

		if ( $batch_size && $filter ) {
			remove_filter( 'wpmudev_posts_scan_batch_size', $filter );
		}

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$job   = $service->get_job();
		$total = $job ? (int) $job['total'] : 0;

		if ( 0 === $total ) {
			if ( 'json' === $format ) {
				WP_CLI::line( wp_json_encode( array( 'status' => 'no_posts', 'message' => 'No posts matched the supplied criteria.' ) ) );
			} else {
				WP_CLI::success( __( 'No posts matched the supplied criteria.', 'wpmudev-plugin-test' ) );
			}
			return;
		}

		if ( ! $quiet ) {
			WP_CLI::log(
				sprintf(
					/* translators: %1$s: comma-separated post types, %2$d: total posts */
					__( 'Scanning %2$d posts across: %1$s', 'wpmudev-plugin-test' ),
					implode( ', ', $job['post_types'] ),
					$total
				)
			);
		}

		$progress       = null;
		$progress_count = 0;

		if ( ! $quiet ) {
			$progress = make_progress_bar( __( 'Scanning posts', 'wpmudev-plugin-test' ), $total );
		}

		while ( $job && in_array( $job['status'], array( 'pending', 'running' ), true ) ) {
			$before = $job['processed'];
			$service->handle_process_event( $job['job_id'] );
			$job = $service->get_job();

			$current_processed = $job ? $job['processed'] : $total;
			$delta             = max( 0, $current_processed - $before );

			if ( ! $quiet && $progress ) {
				if ( $delta > 0 ) {
					$progress->tick( $delta );
					$progress_count += $delta;
				} elseif ( ! $job ) {
					$progress->tick( $total - $progress_count );
				}
			}
		}

		if ( ! $quiet && $progress ) {
			$progress->finish();
		}

		$summary = $service->get_last_run();

		// Output results based on format.
		$this->output_results( $summary, $format, $quiet );
	}

	/**
	 * Handle dry run output.
	 *
	 * @param array  $post_types Post types to scan.
	 * @param int    $total      Total posts count.
	 * @param string $format     Output format.
	 * @param bool   $quiet      Quiet mode.
	 *
	 * @return void
	 */
	private function handle_dry_run( array $post_types, int $total, string $format, bool $quiet ) {
		$data = array(
			'mode'       => 'dry_run',
			'post_types' => $post_types,
			'total'      => $total,
			'message'    => sprintf(
				/* translators: %1$d: total posts, %2$s: comma-separated post types */
				__( 'Would scan %1$d posts across: %2$s', 'wpmudev-plugin-test' ),
				$total,
				implode( ', ', $post_types )
			),
		);

		switch ( $format ) {
			case 'json':
				WP_CLI::line( wp_json_encode( $data ) );
				break;

			case 'csv':
				WP_CLI::line( 'mode,post_types,total' );
				WP_CLI::line( sprintf( 'dry_run,"%s",%d', implode( ',', $post_types ), $total ) );
				break;

			case 'yaml':
				WP_CLI::line( \Spyc::YAMLDump( $data ) );
				break;

			default: // table
				if ( ! $quiet ) {
					WP_CLI::log( '' );
					WP_CLI::log( WP_CLI::colorize( '%YDry Run Preview%n' ) );
					WP_CLI::log( str_repeat( '-', 50 ) );
					WP_CLI::log( sprintf( 'Post Types: %s', implode( ', ', $post_types ) ) );
					WP_CLI::log( sprintf( 'Total Posts: %d', $total ) );
					WP_CLI::log( '' );
					WP_CLI::log( WP_CLI::colorize( '%GNo changes were made.%n' ) );
				}
				break;
		}
	}

	/**
	 * Output scan results.
	 *
	 * @param array  $summary Scan summary data.
	 * @param string $format  Output format.
	 * @param bool   $quiet   Quiet mode.
	 *
	 * @return void
	 */
	private function output_results( ?array $summary, string $format, bool $quiet ) {
		if ( empty( $summary ) ) {
			if ( 'json' === $format ) {
				WP_CLI::line( wp_json_encode( array( 'status' => 'completed', 'message' => 'Scan complete.' ) ) );
			} else {
				WP_CLI::success( __( 'Scan complete.', 'wpmudev-plugin-test' ) );
			}
			return;
		}

		$data = array(
			'status'       => $summary['status'] ?? 'completed',
			'processed'    => $summary['processed'] ?? 0,
			'total'        => $summary['total'] ?? 0,
			'post_types'   => $summary['post_types'] ?? array(),
			'timestamp'    => $summary['timestamp'] ?? time(),
			'health_score' => $summary['health_score'] ?? null,
			'metrics'      => $summary['metrics'] ?? array(),
		);

		switch ( $format ) {
			case 'json':
				WP_CLI::line( wp_json_encode( $data ) );
				break;

			case 'csv':
				WP_CLI::line( 'status,processed,total,health_score,timestamp' );
				WP_CLI::line(
					sprintf(
						'%s,%d,%d,%s,%d',
						$data['status'],
						$data['processed'],
						$data['total'],
						$data['health_score'] ?? 'N/A',
						$data['timestamp']
					)
				);
				break;

			case 'yaml':
				WP_CLI::line( \Spyc::YAMLDump( $data ) );
				break;

			default: // table
				$date = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $data['timestamp'] );

				if ( ! $quiet ) {
					WP_CLI::log( '' );
					WP_CLI::log( WP_CLI::colorize( '%GScan Results%n' ) );
					WP_CLI::log( str_repeat( '-', 50 ) );
					WP_CLI::log( sprintf( 'Status: %s', $data['status'] ) );
					WP_CLI::log( sprintf( 'Processed: %d / %d posts', $data['processed'], $data['total'] ) );
					WP_CLI::log( sprintf( 'Completed: %s', $date ) );

					if ( ! empty( $data['health_score'] ) ) {
						WP_CLI::log( sprintf( 'Health Score: %.1f%%', $data['health_score'] ) );
					}

					if ( ! empty( $data['metrics'] ) ) {
						WP_CLI::log( '' );
						WP_CLI::log( WP_CLI::colorize( '%YMetrics:%n' ) );
						foreach ( $data['metrics'] as $key => $value ) {
							$label = ucwords( str_replace( '_', ' ', $key ) );
							WP_CLI::log( sprintf( '  %s: %s', $label, $value ) );
						}
					}

					WP_CLI::log( '' );
				}

				WP_CLI::success(
					sprintf(
						/* translators: %1$d: processed posts, %2$d: total, %3$s: date string */
						__( 'Scan complete. Processed %1$d of %2$d posts. Last run stored on %3$s.', 'wpmudev-plugin-test' ),
						$data['processed'],
						$data['total'],
						$date
					)
				);
				break;
		}
	}
}

