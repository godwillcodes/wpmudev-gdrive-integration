<?php
/**
 * Google Drive API endpoints using Google Client Library.
 *
 * @link          https://wpmudev.com/
 * @since         1.0.0
 *
 * @author        WPMUDEV (https://wpmudev.com)
 * @package       WPMUDEV\PluginTest
 *
 * @copyright (c) 2025, Incsub (http://incsub.com)
 */

namespace WPMUDEV\PluginTest\Endpoints\V1;

// Abort if called directly.
defined( 'WPINC' ) || die;

use WPMUDEV\PluginTest\Base;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Google_Client;
use Google_Service_Drive;
use Google_Service_Drive_DriveFile;

class Drive_API extends Base {

	/**
	 * Google Client instance.
	 *
	 * @var Google_Client
	 */
	private $client;

	/**
	 * Google Drive service.
	 *
	 * @var Google_Service_Drive
	 */
	private $drive_service;

	/**
	 * OAuth redirect URI.
	 *
	 * @var string
	 */
	private $redirect_uri;

	/**
	 * Google Drive API scopes.
	 *
	 * @var array
	 */
	private $scopes = array(
		Google_Service_Drive::DRIVE_FILE,
		Google_Service_Drive::DRIVE_READONLY,
	);

	/**
	 * Initialize the class.
	 */
	public function init() {
		$this->redirect_uri = home_url( '/wp-json/wpmudev/v1/drive/callback' );
		$this->setup_google_client();

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Setup Google Client.
	 */
	private function setup_google_client() {
		$auth_creds = get_option( 'wpmudev_plugin_tests_auth', array() );

		if ( empty( $auth_creds['client_id'] ) || empty( $auth_creds['client_secret'] ) ) {
			return;
		}

		$decrypted_secret = $this->decrypt_secret( $auth_creds['client_secret'] );
		
		// Validate that decryption succeeded and secret is usable.
		if ( '' === $decrypted_secret ) {
			// Decryption failed or returned empty string.
			if ( function_exists( 'error_log' ) ) {
				$was_encrypted = $this->is_encrypted( $auth_creds['client_secret'] );
				if ( $was_encrypted ) {
					error_log( 'WPMUDEV Drive: Failed to decrypt client secret. Decryption returned empty string. Google Drive authentication will fail.' );
				} else {
					error_log( 'WPMUDEV Drive: Client secret is empty. Google Drive authentication will fail.' );
				}
			}
			return; // Do not set up client with invalid secret.
		}
		
		// Check if decryption failed (still encrypted) - indicates OpenSSL unavailable.
		if ( $this->is_encrypted( $decrypted_secret ) ) {
			if ( function_exists( 'error_log' ) ) {
				error_log( 'WPMUDEV Drive: Cannot decrypt client secret - OpenSSL extension is required but not available. Google Drive authentication will fail.' );
			}
			return; // Do not set up client with encrypted value (will cause authentication failure).
		}

		$this->client = new Google_Client();
		$this->client->setClientId( $auth_creds['client_id'] );
		$this->client->setClientSecret( $decrypted_secret );
		$this->client->setRedirectUri( $this->redirect_uri );
		$this->client->setScopes( $this->scopes );
		$this->client->setAccessType( 'offline' );
		$this->client->setPrompt( 'consent' );

		// Set access token if available
		$access_token = get_option( 'wpmudev_drive_access_token', '' );
		if ( ! empty( $access_token ) ) {
			$this->client->setAccessToken( $access_token );
		}

		$this->drive_service = new Google_Service_Drive( $this->client );
	}

	/**
	 * Register REST API routes.
	 */
	public function register_routes() {
		// Save credentials endpoint.
		register_rest_route(
			'wpmudev/v1/drive',
			'/save-credentials',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'save_credentials' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'client_id'     => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'client_secret' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Authentication endpoint
		register_rest_route( 'wpmudev/v1/drive', '/auth', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'start_auth' ),
		) );

		// OAuth callback
		register_rest_route( 'wpmudev/v1/drive', '/callback', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_callback' ),
		) );

		// List files
		register_rest_route( 'wpmudev/v1/drive', '/files', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_files' ),
		) );

		// Upload file
		register_rest_route( 'wpmudev/v1/drive', '/upload', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'upload_file' ),
		) );

		// Download file
		register_rest_route( 'wpmudev/v1/drive', '/download', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'download_file' ),
		) );

		// Create folder
		register_rest_route( 'wpmudev/v1/drive', '/create-folder', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'create_folder' ),
		) );
	}

	/**
	 * Save Google OAuth credentials.
	 *
	 * @param WP_REST_Request $request Request instance.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_credentials( WP_REST_Request $request ) {
		$client_id     = trim( (string) $request->get_param( 'client_id' ) );
		$client_secret = trim( (string) $request->get_param( 'client_secret' ) );

		if ( '' === $client_id || '' === $client_secret ) {
			return new WP_Error(
				'invalid_credentials',
				__( 'Both Client ID and Client Secret are required.', 'wpmudev-plugin-test' ),
				array( 'status' => 400 )
			);
		}

		$credentials = array(
			'client_id'     => $client_id,
			'client_secret' => $this->encrypt_secret( $client_secret ),
		);

		update_option( 'wpmudev_plugin_tests_auth', $credentials );

		// Reinitialize Google Client with new credentials.
		$this->setup_google_client();

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => array(
					'hasCredentials' => true,
				),
			)
		);
	}

	/**
	 * Encrypt a sensitive value (best-effort, reversible).
	 *
	 * @param string $secret Plain client secret.
	 *
	 * @return string Encrypted (with prefix) or original value on failure.
	 */
	private function encrypt_secret( string $secret ): string {
		if ( '' === $secret ) {
			return '';
		}

		// If already encrypted (has our marker), return as-is.
		if ( $this->is_encrypted( $secret ) ) {
			return $secret;
		}

		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return $secret;
		}

		// Convert hex key to binary (SHA-256 produces 64 hex chars = 32 bytes).
		$key_hex = hash( 'sha256', wp_salt( 'secure_auth' ) );
		$key     = hex2bin( $key_hex );
		
		// IV must be 16 bytes for AES-CBC (32 hex characters = 16 bytes).
		$iv_hex = hash( 'sha256', wp_salt( 'auth' ) );
		$iv     = hex2bin( substr( $iv_hex, 0, 32 ) );

		$cipher = openssl_encrypt( $secret, 'aes-256-cbc', $key, 0, $iv );

		if ( false === $cipher ) {
			return $secret;
		}

		// Prefix encrypted data with marker to distinguish from plaintext.
		return 'wpmudev_encrypted:' . base64_encode( $cipher );
	}

	/**
	 * Decrypt a previously encrypted client secret.
	 *
	 * @param string $value Stored value (may be encrypted with prefix or plaintext).
	 *
	 * @return string Decrypted secret or original value on failure.
	 */
	private function decrypt_secret( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		// If not encrypted (no marker), return as-is (plaintext).
		if ( ! $this->is_encrypted( $value ) ) {
			return $value;
		}

		// If encrypted but OpenSSL unavailable, we cannot decrypt.
		// Return original encrypted value to preserve data (Google Client will fail with clear error).
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			// Log error for debugging.
			if ( function_exists( 'error_log' ) ) {
				error_log( 'WPMUDEV Drive: Cannot decrypt credentials - OpenSSL unavailable. Returning encrypted value to preserve data.' );
			}
			// Return original encrypted value to preserve data integrity.
			// The Google Client will fail with a clear "invalid client secret" error,
			// which is better than silently using an empty string.
			return $value;
		}

		// Remove prefix and decode base64.
		$encrypted_data = substr( $value, strlen( 'wpmudev_encrypted:' ) );
		$decoded        = base64_decode( $encrypted_data, true );

		if ( false === $decoded ) {
			return '';
		}

		// Convert hex key to binary (SHA-256 produces 64 hex chars = 32 bytes).
		$key_hex = hash( 'sha256', wp_salt( 'secure_auth' ) );
		$key     = hex2bin( $key_hex );
		
		// IV must be 16 bytes for AES-CBC (32 hex characters = 16 bytes).
		$iv_hex = hash( 'sha256', wp_salt( 'auth' ) );
		$iv     = hex2bin( substr( $iv_hex, 0, 32 ) );

		$plain = openssl_decrypt( $decoded, 'aes-256-cbc', $key, 0, $iv );

		return false === $plain ? '' : $plain;
	}

	/**
	 * Check if a value is encrypted (has our encryption marker).
	 *
	 * @param string $value Value to check.
	 *
	 * @return bool True if encrypted, false if plaintext.
	 */
	private function is_encrypted( string $value ): bool {
		return strpos( $value, 'wpmudev_encrypted:' ) === 0;
	}

	/**
	 * Start Google OAuth flow.
	 */
	public function start_auth() {
		if ( ! $this->client ) {
			return new WP_Error( 'missing_credentials', 'Google OAuth credentials not configured', array( 'status' => 400 ) );
		}

		return true;
	}

	/**
	 * Handle OAuth callback.
	 */
	public function handle_callback() {
		$code  = '';
		$state = '';

		if ( empty( $code ) ) {
			wp_die( 'Authorization code not received' );
		}

		try {
			// Exchange code for access token
			$access_token = array();

			// Store tokens
			update_option( 'wpmudev_drive_access_token', $access_token );
			if ( isset( $access_token['refresh_token'] ) ) {
				update_option( 'wpmudev_drive_refresh_token', $access_token );
			}
			update_option( 'wpmudev_drive_token_expires', '???' );

			// Redirect back to admin page
			wp_redirect( admin_url( 'admin.php?page=wpmudev_plugintest_drive&auth=success' ) );
			exit;

		} catch ( Exception $e ) {
			wp_die( 'Failed to get access token: ' . esc_html( $e->getMessage() ) );
		}
	}

	/**
	 * Ensure we have a valid access token.
	 */
	private function ensure_valid_token() {
		if ( ! $this->client ) {
			return false;
		}

		// Check if token is expired and refresh if needed
		if ( $this->client->isAccessTokenExpired() ) {
			$refresh_token = get_option( 'wpmudev_drive_refresh_token' );
			
			if ( empty( $refresh_token ) ) {
				return false;
			}

			try {
				$new_token = $this->client->fetchAccessTokenWithRefreshToken( $refresh_token );
				
				if ( array_key_exists( 'error', $new_token ) ) {
					return false;
				}

				update_option( 'wpmudev_drive_access_token', 'NEW TOKEN' );
				update_option( 'wpmudev_drive_token_expires', 'NEW EXPIRATION TIME' );
				
				return true;
			} catch ( Exception $e ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * List files in Google Drive.
	 */
	public function list_files() {
		if ( ! $this->ensure_valid_token() ) {
			return new WP_Error( 'no_access_token', 'Not authenticated with Google Drive', array( 'status' => 401 ) );
		}

		try {
			$page_size = 20; // This should be an input parameter not static value 20.
			$query     = 'trashed=false'; // This should be an input parameter not static value.

			$options = array(
				'pageSize' => $page_size,
				'q'        => $query,
				'fields'   => 'files(id,name,mimeType,size,modifiedTime,webViewLink)',
			);

			$results = $this->drive_service->files->listFiles( $options );
			$files   = $results->getFiles();

			$file_list = array();
			foreach ( $files as $file ) {
				$file_list[] = array(
					'id'           => $file->getId(),
					'name'         => $file->getName(),
					'mimeType'     => $file->getMimeType(),
					'size'         => $file->getSize(),
					'modifiedTime' => $file->getModifiedTime(),
					'webViewLink'  => $file->getWebViewLink(),
				);
			}

			return $file_list;

		} catch ( Exception $e ) {
			return new WP_Error( 'api_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Upload file to Google Drive.
	 */
	public function upload_file( WP_REST_Request $request ) {
		if ( ! $this->ensure_valid_token() ) {
			return new WP_Error( 'no_access_token', 'Not authenticated with Google Drive', array( 'status' => 401 ) );
		}

		$files = $request->get_file_params();
		
		if ( empty( $files['file'] ) ) {
			return new WP_Error( 'no_file', 'No file provided', array( 'status' => 400 ) );
		}

		$file = $files['file'];
		
		if ( $file['error'] !== UPLOAD_ERR_OK ) {
			return new WP_Error( 'upload_error', 'File upload error', array( 'status' => 400 ) );
		}

		try {
			// Create file metadata
			$drive_file = new Google_Service_Drive_DriveFile();
			$drive_file->setName( $file['name'] );

			// Upload file
			$result = $this->drive_service->files->create(
				$drive_file,
				array(
					'data'       => file_get_contents( $file['tmp_name'] ),
					'mimeType'   => $file['type'],
					'uploadType' => 'multipart',
					'fields'     => 'id,name,mimeType,size,webViewLink',
				)
			);

			return new WP_REST_Response( array(
				'success' => true,
				'file'    => array(
					'id'          => $result->getId(),
					'name'        => $result->getName(),
					'mimeType'    => $result->getMimeType(),
					'size'        => $result->getSize(),
					'webViewLink' => $result->getWebViewLink(),
				),
			) );

		} catch ( Exception $e ) {
			return new WP_Error( 'upload_failed', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Download file from Google Drive.
	 */
	public function download_file( WP_REST_Request $request ) {
		if ( ! $this->ensure_valid_token() ) {
			return new WP_Error( 'no_access_token', 'Not authenticated with Google Drive', array( 'status' => 401 ) );
		}

		$file_id = $request->get_param( 'file_id' );
		
		if ( empty( $file_id ) ) {
			return new WP_Error( 'missing_file_id', 'File ID is required', array( 'status' => 400 ) );
		}

		try {
			// Get file metadata
			$file = $this->drive_service->files->get( $file_id, array(
				'fields' => 'id,name,mimeType,size',
			) );

			// Download file content
			$response = $this->drive_service->files->get( $file_id, array(
				'alt' => 'media',
			) );

			$content = $response->getBody()->getContents();

			// Return file content as base64 for JSON response
			return new WP_REST_Response( array(
				'success'  => true,
				'content'  => base64_encode( $content ),
				'filename' => $file->getName(),
				'mimeType' => $file->getMimeType(),
			) );

		} catch ( Exception $e ) {
			return new WP_Error( 'download_failed', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Create folder in Google Drive.
	 */
	public function create_folder( WP_REST_Request $request ) {
		if ( ! $this->ensure_valid_token() ) {
			return new WP_Error( 'no_access_token', 'Not authenticated with Google Drive', array( 'status' => 401 ) );
		}

		$name = $request->get_param( 'name' );
		
		if ( empty( $name ) ) {
			return new WP_Error( 'missing_name', 'Folder name is required', array( 'status' => 400 ) );
		}

		try {
			$folder = new Google_Service_Drive_DriveFile();
			$folder->setName( sanitize_text_field( $name ) );
			$folder->setMimeType( 'application/vnd.google-apps.folder' );

			$result = $this->drive_service->files->create( $folder, array(
				'fields' => 'id,name,mimeType,webViewLink',
			) );

			return new WP_REST_Response( array(
				'success' => true,
				'folder'  => array(
					'id'          => $result->getId(),
					'name'        => $result->getName(),
					'mimeType'    => $result->getMimeType(),
					'webViewLink' => $result->getWebViewLink(),
				),
			) );

		} catch ( Exception $e ) {
			return new WP_Error( 'create_failed', $e->getMessage(), array( 'status' => 500 ) );
		}
	}
}