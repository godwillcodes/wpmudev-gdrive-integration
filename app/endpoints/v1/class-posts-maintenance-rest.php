<?php
/**
 * Posts maintenance REST endpoints.
 *
 * @package WPMUDEV\PluginTest
 */

namespace WPMUDEV\PluginTest\App\Endpoints\V1;

use WPMUDEV\PluginTest\Base;
use WPMUDEV\PluginTest\App\Services\Posts_Maintenance as Posts_Maintenance_Service;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

// Abort if called directly.
defined( 'WPINC' ) || die;

/**
 * Class Posts_Maintenance
 */
class Posts_Maintenance extends Base {

	/**
	 * Initialize REST routes.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers REST routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			'wpmudev/v1/posts-maintenance',
			'/start',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'start_scan' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'post_types' => array(
						'type'              => 'array',
						'required'          => false,
						'sanitize_callback' => array( $this, 'sanitize_post_types' ),
					),
				),
			)
		);

		register_rest_route(
			'wpmudev/v1/posts-maintenance',
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		register_rest_route(
			'wpmudev/v1/posts-maintenance',
			'/scan/(?P<scan_id>[a-f0-9\-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_scan' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'scan_id' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);

		register_rest_route(
			'wpmudev/v1/posts-maintenance',
			'/scan/(?P<scan_id>[a-f0-9\-]+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_scan' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'scan_id' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);

		register_rest_route(
			'wpmudev/v1/posts-maintenance',
			'/settings',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_settings' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		register_rest_route(
			'wpmudev/v1/posts-maintenance',
			'/settings',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'save_settings' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'auto_scan_enabled'    => array(
						'type'              => 'boolean',
						'required'          => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
					'scheduled_time'       => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => function ( $param ) {
							return preg_match( '/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/', $param );
						},
					),
					'scheduled_post_types'  => array(
						'type'              => 'array',
						'required'          => false,
						'sanitize_callback' => array( $this, 'sanitize_post_types' ),
					),
				),
			)
		);
	}

	/**
	 * Sanitizes post types array.
	 *
	 * @param mixed $value Value.
	 *
	 * @return array
	 */
	public function sanitize_post_types( $value ): array {
		if ( empty( $value ) || ! is_array( $value ) ) {
			return array();
		}

		return array_filter(
			array_map( 'sanitize_key', $value )
		);
	}

	/**
	 * Starts a scan job.
	 *
	 * @param WP_REST_Request $request Request instance.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function start_scan( WP_REST_Request $request ) {
		$post_types = $request->get_param( 'post_types' );

		$result = Posts_Maintenance_Service::instance()->start_job( is_array( $post_types ) ? $post_types : array(), 'manual' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'job'     => $result,
				'message' => __( 'Scan started successfully.', 'wpmudev-plugin-test' ),
			)
		);
	}

	/**
	 * Returns job status data for UI polling.
	 *
	 * @return WP_REST_Response
	 */
	public function get_status() {
		$service = Posts_Maintenance_Service::instance();

		return new WP_REST_Response(
			array(
				'job'        => $service->format_job_for_response(),
				'lastRun'    => $service->get_last_run(),
				'postTypes'  => $this->get_public_post_types(),
				'nextScan'   => $service->get_next_scan_timestamp(),
			)
		);
	}

	/**
	 * Returns public post types for UI filters.
	 *
	 * @return array
	 */
	private function get_public_post_types(): array {
		$post_types = get_post_types(
			array(
				'public' => true,
			),
			'objects'
		);

		$list = array();

		foreach ( $post_types as $slug => $object ) {
			$list[] = array(
				'slug'  => $slug,
				'label' => $object->labels->name,
			);
		}

		return $list;
	}

	/**
	 * Gets a specific scan record.
	 *
	 * @param WP_REST_Request $request Request instance.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_scan( WP_REST_Request $request ) {
		$scan_id = $request->get_param( 'scan_id' );
		$service = Posts_Maintenance_Service::instance();
		$record  = $service->get_scan_record( $scan_id );

		if ( null === $record ) {
			return new WP_Error(
				'wpmudev_scan_not_found',
				__( 'Scan record not found.', 'wpmudev-plugin-test' ),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response( $record );
	}

	/**
	 * Deletes a scan record.
	 *
	 * @param WP_REST_Request $request Request instance.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_scan( WP_REST_Request $request ) {
		$scan_id = $request->get_param( 'scan_id' );
		$service = Posts_Maintenance_Service::instance();
		$deleted = $service->delete_scan_record( $scan_id );

		if ( ! $deleted ) {
			return new WP_Error(
				'wpmudev_scan_delete_failed',
				__( 'Failed to delete scan record.', 'wpmudev-plugin-test' ),
				array( 'status' => 400 )
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Scan record deleted successfully.', 'wpmudev-plugin-test' ),
			)
		);
	}

	/**
	 * Gets settings.
	 *
	 * @return WP_REST_Response
	 */
	public function get_settings() {
		$service = Posts_Maintenance_Service::instance();
		$settings = $service->get_settings();
		$settings['next_scan'] = $service->get_next_scan_timestamp();

		return new WP_REST_Response( $settings );
	}

	/**
	 * Saves settings.
	 *
	 * @param WP_REST_Request $request Request instance.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_settings( WP_REST_Request $request ) {
		$service = Posts_Maintenance_Service::instance();
		
		$settings = array(
			'auto_scan_enabled'    => $request->get_param( 'auto_scan_enabled' ),
			'scheduled_time'       => $request->get_param( 'scheduled_time' ),
			'scheduled_post_types' => $request->get_param( 'scheduled_post_types' ),
		);

		$result = $service->save_settings( $settings );

		if ( ! $result ) {
			return new WP_Error(
				'wpmudev_settings_save_failed',
				__( 'Failed to save settings.', 'wpmudev-plugin-test' ),
				array( 'status' => 500 )
			);
		}

		$updated_settings = $service->get_settings();
		$updated_settings['next_scan'] = $service->get_next_scan_timestamp();

		return new WP_REST_Response(
			array(
				'success'  => true,
				'settings' => $updated_settings,
				'message'  => __( 'Settings saved successfully.', 'wpmudev-plugin-test' ),
			)
		);
	}
}

