<?php
/**
 * WP-CLI command for Posts Maintenance.
 *
 * @package WPMUDEV\PluginTest
 */

namespace WPMUDEV\PluginTest\App\CLI;

use WP_CLI;
use WP_CLI_Command;
use WPMUDEV\PluginTest\App\Services\Posts_Maintenance as Posts_Maintenance_Service;
use function WP_CLI\Utils\make_progress_bar;

// Abort if called directly.
defined( 'WPINC' ) || die;

/**
 * Class Posts_Maintenance_Command
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
	 * ## OPTIONS
	 *
	 * [--post_types=<types>]
	 * : Comma-separated list of public post types (e.g. `post,page,product`). Defaults to the same set used in the admin UI (filterable via `wpmudev_posts_scan_post_types`).
	 *
	 * [--batch_size=<number>]
	 * : Number of posts to process per batch (default 25). Must be between 1 and 200.
	 *
	 * ## EXAMPLES
	 *
	 *     # Scan default post types (posts & pages)
	 *     $ wp wpmudev posts-scan
	 *
	 *     # Scan custom post types with smaller batches
	 *     $ wp wpmudev posts-scan --post_types=product,docs --batch_size=10
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

		$filter = null;

		if ( $batch_size ) {
			$filter = function () use ( $batch_size ) {
				return $batch_size;
			};
			add_filter( 'wpmudev_posts_scan_batch_size', $filter );
		}

		$service = Posts_Maintenance_Service::instance();
		$result  = $service->start_job( $post_types, 'cli' );

		if ( $batch_size && $filter ) {
			remove_filter( 'wpmudev_posts_scan_batch_size', $filter );
		}

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$job   = $service->get_job();
		$total = $job ? (int) $job['total'] : 0;

		if ( 0 === $total ) {
			WP_CLI::success( __( 'No posts matched the supplied criteria.', 'wpmudev-plugin-test' ) );
			return;
		}

		WP_CLI::log(
			sprintf(
				/* translators: %1$s: comma-separated post types, %2$d: total posts */
				__( 'Scanning %2$d posts across: %1$s', 'wpmudev-plugin-test' ),
				implode( ', ', $job['post_types'] ),
				$total
			)
		);

		$progress       = make_progress_bar( __( 'Scanning posts', 'wpmudev-plugin-test' ), $total );
		$progress_count = 0;

		while ( $job && in_array( $job['status'], array( 'pending', 'running' ), true ) ) {
			$before = $job['processed'];
			$service->handle_process_event( $job['job_id'] );
			$job = $service->get_job();

			$current_processed = $job ? $job['processed'] : $total;
			$delta             = max( 0, $current_processed - $before );
			if ( $delta > 0 ) {
				$progress->tick( $delta );
				$progress_count += $delta;
			} elseif ( ! $job ) {
				$progress->tick( $total - $progress_count );
			}
		}

		$progress->finish();

		$summary = $service->get_last_run();

		if ( $summary ) {
			$date = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $summary['timestamp'] );
			WP_CLI::success(
				sprintf(
					/* translators: %1$d: processed posts, %2$d: total, %3$s: date string */
					__( 'Scan complete. Processed %1$d of %2$d posts. Last run stored on %3$s.', 'wpmudev-plugin-test' ),
					isset( $summary['processed'] ) ? (int) $summary['processed'] : 0,
					isset( $summary['total'] ) ? (int) $summary['total'] : 0,
					$date
				)
			);
		} else {
			WP_CLI::success( __( 'Scan complete.', 'wpmudev-plugin-test' ) );
		}
	}
}

