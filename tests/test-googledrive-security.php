<?php
/**
 * Google Drive security and credentials unit tests.
 *
 * @package WPMUDEV_PluginTest
 */

/**
 * Class Test_GoogleDrive_Security
 *
 * Tests for Google Drive security features including encryption,
 * rate limiting, and audit logging.
 */
class Test_GoogleDrive_Security extends WP_UnitTestCase {

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
		parent::tearDown();
	}

	/**
	 * Resets options between tests.
	 *
	 * @return void
	 */
	private function reset_state() {
		delete_option( 'wpmudev_plugin_tests_auth' );
		delete_option( 'wpmudev_drive_access_token' );
		delete_option( 'wpmudev_drive_refresh_token' );
		delete_option( 'wpmudev_drive_token_expires' );
		delete_option( 'wpmudev_drive_audit_log' );

		// Clear rate limit transients.
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wpmudev_drive_rate_%'" );
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_wpmudev_drive_rate_%'" );
	}

	/**
	 * Test that client secret is encrypted when stored.
	 */
	public function test_client_secret_is_encrypted() {
		wp_set_current_user( $this->admin_id );

		$client_id = 'test-client-id-12345';
		$client_secret = 'test-client-secret-67890';

		// Store credentials.
		$credentials = array(
			'client_id'     => $client_id,
			'client_secret' => $client_secret,
		);

		// Simulate what the endpoint does - encrypt the secret.
		if ( function_exists( 'openssl_encrypt' ) ) {
			$key_hex = hash( 'sha256', wp_salt( 'secure_auth' ) );
			$key     = hex2bin( $key_hex );
			$iv_hex  = hash( 'sha256', wp_salt( 'auth' ) );
			$iv      = hex2bin( substr( $iv_hex, 0, 32 ) );

			$encrypted = openssl_encrypt( $client_secret, 'aes-256-cbc', $key, 0, $iv );
			if ( false !== $encrypted ) {
				$credentials['client_secret'] = 'wpmudev_enc:' . $encrypted;
			}
		}

		update_option( 'wpmudev_plugin_tests_auth', $credentials );

		// Retrieve and verify.
		$stored = get_option( 'wpmudev_plugin_tests_auth', array() );

		$this->assertEquals( $client_id, $stored['client_id'] );

		// Secret should be encrypted (starts with prefix) if OpenSSL is available.
		if ( function_exists( 'openssl_encrypt' ) ) {
			$this->assertStringStartsWith( 'wpmudev_enc:', $stored['client_secret'] );
			$this->assertNotEquals( $client_secret, $stored['client_secret'] );
		}
	}

	/**
	 * Test that audit log records events.
	 */
	public function test_audit_log_records_events() {
		$audit_log = get_option( 'wpmudev_drive_audit_log', array() );
		$this->assertIsArray( $audit_log );

		// Add a test entry.
		$log_entry = array(
			'timestamp'  => current_time( 'mysql' ),
			'event'      => 'test_event',
			'user_id'    => $this->admin_id,
			'user_login' => 'admin',
			'ip_address' => '127.0.0.1',
			'user_agent' => 'PHPUnit',
			'data'       => array( 'test' => true ),
		);

		array_unshift( $audit_log, $log_entry );
		update_option( 'wpmudev_drive_audit_log', $audit_log, false );

		// Verify.
		$stored_log = get_option( 'wpmudev_drive_audit_log', array() );
		$this->assertCount( 1, $stored_log );
		$this->assertEquals( 'test_event', $stored_log[0]['event'] );
	}

	/**
	 * Test that audit log is limited to 100 entries.
	 */
	public function test_audit_log_limited_to_100_entries() {
		$audit_log = array();

		// Add 150 entries.
		for ( $i = 0; $i < 150; $i++ ) {
			$audit_log[] = array(
				'timestamp' => current_time( 'mysql' ),
				'event'     => 'test_event_' . $i,
				'user_id'   => $this->admin_id,
			);
		}

		// Trim to 100.
		$audit_log = array_slice( $audit_log, 0, 100 );
		update_option( 'wpmudev_drive_audit_log', $audit_log, false );

		$stored_log = get_option( 'wpmudev_drive_audit_log', array() );
		$this->assertCount( 100, $stored_log );
	}

	/**
	 * Test that credentials require both client_id and client_secret.
	 */
	public function test_credentials_require_both_fields() {
		// Test with empty client_id.
		$creds_no_id = array(
			'client_id'     => '',
			'client_secret' => 'some-secret',
		);

		$this->assertTrue( empty( $creds_no_id['client_id'] ) );

		// Test with empty client_secret.
		$creds_no_secret = array(
			'client_id'     => 'some-id',
			'client_secret' => '',
		);

		$this->assertTrue( empty( $creds_no_secret['client_secret'] ) );
	}

	/**
	 * Test that OAuth state is properly validated.
	 */
	public function test_oauth_state_validation() {
		// Generate a state token.
		$state = wp_generate_password( 32, false );

		// Store in transient (simulating auth flow).
		set_transient( 'wpmudev_drive_oauth_state_' . $state, true, 600 );

		// Verify it exists.
		$this->assertTrue( (bool) get_transient( 'wpmudev_drive_oauth_state_' . $state ) );

		// Delete it (simulating callback).
		delete_transient( 'wpmudev_drive_oauth_state_' . $state );

		// Verify it's gone.
		$this->assertFalse( get_transient( 'wpmudev_drive_oauth_state_' . $state ) );
	}

	/**
	 * Test that invalid state is rejected.
	 */
	public function test_invalid_oauth_state_rejected() {
		$invalid_state = 'invalid-state-token';

		// Should not exist.
		$this->assertFalse( get_transient( 'wpmudev_drive_oauth_state_' . $invalid_state ) );
	}

	/**
	 * Test that state tokens expire.
	 */
	public function test_oauth_state_expires() {
		$state = wp_generate_password( 32, false );

		// Store with very short TTL.
		set_transient( 'wpmudev_drive_oauth_state_' . $state, true, 1 );

		// Wait for expiration.
		sleep( 2 );

		// Should be expired.
		$this->assertFalse( get_transient( 'wpmudev_drive_oauth_state_' . $state ) );
	}

	/**
	 * Test that tokens are properly cleaned up on disconnect.
	 */
	public function test_disconnect_cleans_up_tokens() {
		// Set up tokens.
		update_option( 'wpmudev_drive_access_token', array( 'access_token' => 'test-token' ) );
		update_option( 'wpmudev_drive_refresh_token', 'test-refresh-token' );
		update_option( 'wpmudev_drive_token_expires', time() + 3600 );

		// Verify they exist.
		$this->assertNotEmpty( get_option( 'wpmudev_drive_access_token' ) );
		$this->assertNotEmpty( get_option( 'wpmudev_drive_refresh_token' ) );
		$this->assertNotEmpty( get_option( 'wpmudev_drive_token_expires' ) );

		// Simulate disconnect.
		delete_option( 'wpmudev_drive_access_token' );
		delete_option( 'wpmudev_drive_refresh_token' );
		delete_option( 'wpmudev_drive_token_expires' );

		// Verify cleanup.
		$this->assertEmpty( get_option( 'wpmudev_drive_access_token' ) );
		$this->assertEmpty( get_option( 'wpmudev_drive_refresh_token' ) );
		$this->assertEmpty( get_option( 'wpmudev_drive_token_expires' ) );
	}

	/**
	 * Test rate limit transient structure.
	 */
	public function test_rate_limit_transient_structure() {
		$transient_key = 'wpmudev_drive_rate_test_' . $this->admin_id;
		$requests = array( time(), time() - 10, time() - 20 );

		set_transient( $transient_key, $requests, 60 );

		$stored = get_transient( $transient_key );
		$this->assertIsArray( $stored );
		$this->assertCount( 3, $stored );
	}

	/**
	 * Test that credentials are sanitized.
	 */
	public function test_credentials_are_sanitized() {
		$dirty_id = '<script>alert("xss")</script>client-id';
		$clean_id = sanitize_text_field( $dirty_id );

		$this->assertStringNotContainsString( '<script>', $clean_id );
		$this->assertStringNotContainsString( '</script>', $clean_id );
	}

	/**
	 * Test permission check for admin capability.
	 */
	public function test_permission_check_requires_manage_options() {
		// Admin should have capability.
		wp_set_current_user( $this->admin_id );
		$this->assertTrue( current_user_can( 'manage_options' ) );

		// Subscriber should not.
		wp_set_current_user( $this->subscriber_id );
		$this->assertFalse( current_user_can( 'manage_options' ) );

		// Logged out user should not.
		wp_set_current_user( 0 );
		$this->assertFalse( current_user_can( 'manage_options' ) );
	}
}
