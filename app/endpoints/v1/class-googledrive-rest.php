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

		// Authentication endpoint.
		register_rest_route(
			'wpmudev/v1/drive',
			'/auth',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'start_auth' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		// OAuth callback.
		register_rest_route(
			'wpmudev/v1/drive',
			'/callback',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_callback' ),
				'permission_callback' => '__return_true', // Public endpoint for OAuth callback.
			)
		);

		// List files.
		register_rest_route(
			'wpmudev/v1/drive',
			'/files',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_files' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'page_size'  => array(
						'type'              => 'integer',
						'required'          => false,
						'default'           => 20,
						'sanitize_callback' => 'absint',
						'validate_callback' => function ( $param ) {
							return $param >= 1 && $param <= 1000;
						},
					),
					'page_token' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'query'      => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Upload file.
		register_rest_route(
			'wpmudev/v1/drive',
			'/upload',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'upload_file' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		// Download file.
		register_rest_route(
			'wpmudev/v1/drive',
			'/download',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'download_file' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		// Create folder.
		register_rest_route(
			'wpmudev/v1/drive',
			'/create-folder',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_folder' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
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
	 *
	 * @param WP_REST_Request $request Request instance.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function start_auth( WP_REST_Request $request ) {
		if ( ! $this->client ) {
			return new WP_Error(
				'missing_credentials',
				__( 'Google OAuth credentials are not configured. Please save your Client ID and Client Secret first.', 'wpmudev-plugin-test' ),
				array( 'status' => 400 )
			);
		}

		try {
			// Generate a random state token for CSRF protection.
			$state_token = wp_generate_password( 32, false );
			
			// Store state token in transient (expires in 10 minutes).
			// Include user ID to prevent cross-user attacks.
			$user_id = get_current_user_id();
			set_transient(
				'wpmudev_drive_oauth_state_' . $user_id,
				$state_token,
				600 // 10 minutes.
			);

			// Set state parameter on Google Client for CSRF protection.
			$this->client->setState( $state_token );

			// Generate the authorization URL.
			$auth_url = $this->client->createAuthUrl();

			return new WP_REST_Response(
				array(
					'success'  => true,
					'auth_url' => $auth_url,
				),
				200
			);
		} catch ( \Exception $e ) {
			return new WP_Error(
				'auth_url_error',
				sprintf(
					/* translators: %s: Error message */
					__( 'Failed to generate authorization URL: %s', 'wpmudev-plugin-test' ),
					$e->getMessage()
				),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Handle OAuth callback.
	 *
	 * @param WP_REST_Request $request Request instance.
	 *
	 * @return void
	 */
	public function handle_callback( WP_REST_Request $request ) {
		$code  = $request->get_param( 'code' );
		$error = $request->get_param( 'error' );
		$state = $request->get_param( 'state' );

		// Validate state parameter for CSRF protection.
		// Note: This is a public callback endpoint, so we validate state against stored transients.
		if ( empty( $state ) ) {
			// State parameter is required for security.
			wp_safe_redirect(
				admin_url(
					add_query_arg(
						array(
							'page'         => 'wpmudev_plugintest_drive',
							'auth'         => 'error',
							'error_message' => urlencode( __( 'State parameter missing. Security validation failed.', 'wpmudev-plugin-test' ) ),
						),
						'admin.php'
					)
				)
			);
			exit;
		}

		// Validate state format and check if it exists in stored transients.
		// State should be a 32-character random string generated by wp_generate_password().
		if ( ! preg_match( '/^[a-zA-Z0-9]{32}$/', $state ) ) {
			wp_safe_redirect(
				admin_url(
					add_query_arg(
						array(
							'page'         => 'wpmudev_plugintest_drive',
							'auth'         => 'error',
							'error_message' => urlencode( __( 'Invalid state parameter format. Possible CSRF attack detected.', 'wpmudev-plugin-test' ) ),
						),
						'admin.php'
					)
				)
			);
			exit;
		}

		// Check if state exists in any user's transient (state is user-specific).
		global $wpdb;
		// Escape the base pattern and append SQL wildcard outside of prepare().
		// Using %s in prepare() would escape the % as a literal character.
		$pattern = $wpdb->esc_like( '_transient_wpmudev_drive_oauth_state_' ) . '%';
		$transient_name = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} 
				WHERE option_name LIKE %s 
				AND option_value = %s
				LIMIT 1",
				$pattern,
				$state
			)
		);

		if ( empty( $transient_name ) ) {
			// State not found in any transient - possible CSRF attack or expired state.
			wp_safe_redirect(
				admin_url(
					add_query_arg(
						array(
							'page'         => 'wpmudev_plugintest_drive',
							'auth'         => 'error',
							'error_message' => urlencode( __( 'Invalid or expired state parameter. Please try authenticating again.', 'wpmudev-plugin-test' ) ),
						),
						'admin.php'
					)
				)
			);
			exit;
		}

		// Extract user ID from transient name and delete the used state token to prevent replay attacks.
		if ( preg_match( '/_transient_wpmudev_drive_oauth_state_(\d+)/', $transient_name, $matches ) ) {
			delete_transient( 'wpmudev_drive_oauth_state_' . $matches[1] );
		}

		// Check for OAuth errors.
		if ( ! empty( $error ) ) {
			$error_description = $request->get_param( 'error_description' );
			$error_message     = ! empty( $error_description ) ? $error_description : $error;
			wp_safe_redirect(
				admin_url(
					add_query_arg(
						array(
							'page'           => 'wpmudev_plugintest_drive',
							'auth'           => 'error',
							'error_message'   => urlencode( $error_message ),
						),
						'admin.php'
					)
				)
			);
			exit;
		}

		// Validate authorization code.
		if ( empty( $code ) ) {
			wp_safe_redirect(
				admin_url(
					add_query_arg(
						array(
							'page'         => 'wpmudev_plugintest_drive',
							'auth'         => 'error',
							'error_message' => urlencode( __( 'Authorization code not received from Google.', 'wpmudev-plugin-test' ) ),
						),
						'admin.php'
					)
				)
			);
			exit;
		}

		if ( ! $this->client ) {
			wp_safe_redirect(
				admin_url(
					add_query_arg(
						array(
							'page'         => 'wpmudev_plugintest_drive',
							'auth'         => 'error',
							'error_message' => urlencode( __( 'Google OAuth credentials are not configured.', 'wpmudev-plugin-test' ) ),
						),
						'admin.php'
					)
				)
			);
			exit;
		}

		try {
			// Exchange authorization code for access token.
			$access_token = $this->client->fetchAccessTokenWithAuthCode( $code );

			// Check for errors in token response.
			if ( array_key_exists( 'error', $access_token ) ) {
				$error_message = isset( $access_token['error_description'] )
					? $access_token['error_description']
					: $access_token['error'];
				wp_safe_redirect(
					admin_url(
						add_query_arg(
							array(
								'page'         => 'wpmudev_plugintest_drive',
								'auth'         => 'error',
								'error_message' => urlencode( $error_message ),
							),
							'admin.php'
						)
					)
				);
				exit;
			}

			// Store access token.
			$this->client->setAccessToken( $access_token );
			update_option( 'wpmudev_drive_access_token', $access_token );

			// Store refresh token if provided (for offline access).
			if ( isset( $access_token['refresh_token'] ) ) {
				update_option( 'wpmudev_drive_refresh_token', $access_token['refresh_token'] );
			}

			// Store token expiration time.
			// Prefer expires_in (seconds until expiration), fallback to expires (timestamp).
			if ( isset( $access_token['expires_in'] ) && is_numeric( $access_token['expires_in'] ) ) {
				$expires_at = time() + (int) $access_token['expires_in'];
				update_option( 'wpmudev_drive_token_expires', $expires_at );
			} elseif ( isset( $access_token['expires'] ) && is_numeric( $access_token['expires'] ) ) {
				// Google Client library stores expires as timestamp.
				$expires_at = (int) $access_token['expires'];
				update_option( 'wpmudev_drive_token_expires', $expires_at );
			}

			// Update drive service with new token.
			$this->drive_service = new Google_Service_Drive( $this->client );

			// Redirect back to admin page with success.
			wp_safe_redirect(
				admin_url(
					add_query_arg(
						array(
							'page' => 'wpmudev_plugintest_drive',
							'auth' => 'success',
						),
						'admin.php'
					)
				)
			);
			exit;

		} catch ( \Exception $e ) {
			wp_safe_redirect(
				admin_url(
					add_query_arg(
						array(
							'page'         => 'wpmudev_plugintest_drive',
							'auth'         => 'error',
							'error_message' => urlencode(
								sprintf(
									/* translators: %s: Error message */
									__( 'Failed to exchange authorization code: %s', 'wpmudev-plugin-test' ),
									$e->getMessage()
								)
							),
						),
						'admin.php'
					)
				)
			);
			exit;
		}
	}

	/**
	 * Ensure we have a valid access token.
	 *
	 * @return bool True if valid token exists, false otherwise.
	 */
	private function ensure_valid_token() {
		if ( ! $this->client ) {
			return false;
		}

		// Check if token is expired and refresh if needed.
		if ( $this->client->isAccessTokenExpired() ) {
			$refresh_token = get_option( 'wpmudev_drive_refresh_token' );

			if ( empty( $refresh_token ) ) {
				return false;
			}

			try {
				$new_token = $this->client->fetchAccessTokenWithRefreshToken( $refresh_token );

				if ( array_key_exists( 'error', $new_token ) ) {
					if ( function_exists( 'error_log' ) ) {
						error_log(
							sprintf(
								'WPMUDEV Drive: Token refresh failed: %s',
								isset( $new_token['error_description'] ) ? $new_token['error_description'] : $new_token['error']
							)
						);
					}
					return false;
				}

				// Store new access token.
				$this->client->setAccessToken( $new_token );
				update_option( 'wpmudev_drive_access_token', $new_token );

				// Update refresh token if provided (some providers issue new refresh tokens).
				if ( isset( $new_token['refresh_token'] ) ) {
					update_option( 'wpmudev_drive_refresh_token', $new_token['refresh_token'] );
				}

				// Update expiration time.
				// Prefer expires_in (seconds until expiration), fallback to expires (timestamp).
				if ( isset( $new_token['expires_in'] ) && is_numeric( $new_token['expires_in'] ) ) {
					$expires_at = time() + (int) $new_token['expires_in'];
					update_option( 'wpmudev_drive_token_expires', $expires_at );
				} elseif ( isset( $new_token['expires'] ) && is_numeric( $new_token['expires'] ) ) {
					// Google Client library stores expires as timestamp.
					$expires_at = (int) $new_token['expires'];
					update_option( 'wpmudev_drive_token_expires', $expires_at );
				}

				// Update drive service with new token.
				$this->drive_service = new Google_Service_Drive( $this->client );

				return true;
			} catch ( \Exception $e ) {
				if ( function_exists( 'error_log' ) ) {
					error_log( sprintf( 'WPMUDEV Drive: Token refresh exception: %s', $e->getMessage() ) );
				}
				return false;
			}
		}

		return true;
	}

	/**
	 * List files in Google Drive.
	 *
	 * @param WP_REST_Request $request Request instance.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_files( WP_REST_Request $request ) {
		if ( ! $this->ensure_valid_token() ) {
			return new WP_Error(
				'no_access_token',
				__( 'Not authenticated with Google Drive. Please authenticate first.', 'wpmudev-plugin-test' ),
				array( 'status' => 401 )
			);
		}

		try {
			// Get pagination parameters from request.
			$page_size = $request->get_param( 'page_size' );
			$page_token = $request->get_param( 'page_token' );
			$query = $request->get_param( 'query' );

			// Validate and set default page size (between 1 and 1000, default 10).
			if ( ! empty( $page_size ) ) {
				$page_size = absint( $page_size );
				if ( $page_size < 1 ) {
					$page_size = 1;
				} elseif ( $page_size > 1000 ) {
					$page_size = 1000;
				}
			} else {
				$page_size = 10; // Default page size.
			}

			// Default query: exclude trashed files.
			if ( empty( $query ) ) {
				$query = 'trashed=false';
			} else {
				// Sanitize query parameter.
				$query = sanitize_text_field( $query );
			}

			// Build options array for Google Drive API.
			$options = array(
				'pageSize' => $page_size,
				'q'        => $query,
				'fields'   => 'nextPageToken,files(id,name,mimeType,size,modifiedTime,webViewLink)',
			);

			// Add page token if provided (for pagination).
			if ( ! empty( $page_token ) ) {
				$page_token = sanitize_text_field( $page_token );
				$options['pageToken'] = $page_token;
			}

			// Fetch files from Google Drive API.
			$results = $this->drive_service->files->listFiles( $options );
			$files   = $results->getFiles();

			// Format file data for response.
			$file_list = array();
			if ( ! empty( $files ) ) {
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
			}

			// Get next page token if available.
			$next_page_token = $results->getNextPageToken();

			// Return structured response with pagination info.
			return new WP_REST_Response(
				array(
					'files'         => $file_list,
					'nextPageToken' => $next_page_token,
					'pageSize'      => $page_size,
					'hasMore'       => ! empty( $next_page_token ),
				),
				200
			);

		} catch ( \Google_Service_Exception $e ) {
			// Handle Google API specific errors.
			$error_message = $e->getMessage();
			$errors = $e->getErrors();
			
			if ( ! empty( $errors ) && is_array( $errors ) ) {
				$error_details = array();
				foreach ( $errors as $error ) {
					if ( isset( $error['message'] ) ) {
						$error_details[] = $error['message'];
					}
				}
				if ( ! empty( $error_details ) ) {
					$error_message = implode( ', ', $error_details );
				}
			}

			if ( function_exists( 'error_log' ) ) {
				error_log( sprintf( 'WPMUDEV Drive: Files list API error: %s', $error_message ) );
			}

			return new WP_Error(
				'api_error',
				sprintf(
					/* translators: %s: Error message */
					__( 'Failed to fetch files from Google Drive: %s', 'wpmudev-plugin-test' ),
					$error_message
				),
				array( 'status' => 500 )
			);

		} catch ( \Exception $e ) {
			// Handle general exceptions.
			if ( function_exists( 'error_log' ) ) {
				error_log( sprintf( 'WPMUDEV Drive: Files list exception: %s', $e->getMessage() ) );
			}

			return new WP_Error(
				'api_error',
				sprintf(
					/* translators: %s: Error message */
					__( 'An error occurred while fetching files: %s', 'wpmudev-plugin-test' ),
					$e->getMessage()
				),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Upload file to Google Drive.
	 *
	 * @param WP_REST_Request $request Request instance.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_file( WP_REST_Request $request ) {
		if ( ! $this->ensure_valid_token() ) {
			return new WP_Error(
				'no_access_token',
				__( 'Not authenticated with Google Drive. Please authenticate first.', 'wpmudev-plugin-test' ),
				array( 'status' => 401 )
			);
		}

		$files = $request->get_file_params();

		if ( empty( $files['file'] ) ) {
			return new WP_Error(
				'no_file',
				__( 'No file provided. Please choose a file to upload.', 'wpmudev-plugin-test' ),
				array( 'status' => 400 )
			);
		}

		$file = $files['file'];

		// Map PHP upload errors to human-readable messages.
		if ( UPLOAD_ERR_OK !== $file['error'] ) {
			$messages = array(
				UPLOAD_ERR_INI_SIZE   => __( 'The uploaded file exceeds the maximum size allowed by the server.', 'wpmudev-plugin-test' ),
				UPLOAD_ERR_FORM_SIZE  => __( 'The uploaded file exceeds the maximum size allowed by the form.', 'wpmudev-plugin-test' ),
				UPLOAD_ERR_PARTIAL    => __( 'The uploaded file was only partially uploaded. Please try again.', 'wpmudev-plugin-test' ),
				UPLOAD_ERR_NO_FILE    => __( 'No file was uploaded. Please select a file.', 'wpmudev-plugin-test' ),
				UPLOAD_ERR_NO_TMP_DIR => __( 'Missing a temporary folder on the server. Please contact the site administrator.', 'wpmudev-plugin-test' ),
				UPLOAD_ERR_CANT_WRITE => __( 'Failed to write file to disk. Please try again.', 'wpmudev-plugin-test' ),
				UPLOAD_ERR_EXTENSION  => __( 'A PHP extension stopped the upload.', 'wpmudev-plugin-test' ),
			);

			$error_message = isset( $messages[ $file['error'] ] ) ? $messages[ $file['error'] ] : __( 'An unknown upload error occurred.', 'wpmudev-plugin-test' );

			return new WP_Error(
				'upload_error',
				$error_message,
				array( 'status' => 400 )
			);
		}

		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error(
				'invalid_upload',
				__( 'Invalid uploaded file. Please try again.', 'wpmudev-plugin-test' ),
				array( 'status' => 400 )
			);
		}

		$max_size = apply_filters( 'wpmudev_drive_upload_max_size', 52428800 ); // 50 MB default.

		if ( ! empty( $file['size'] ) && $file['size'] > $max_size ) {
			return new WP_Error(
				'file_too_large',
				sprintf(
					/* translators: %s: Maximum size in MB */
					__( 'The file is too large. Maximum allowed size is %s MB.', 'wpmudev-plugin-test' ),
					number_format_i18n( $max_size / 1048576, 2 )
				),
				array( 'status' => 400 )
			);
		}

		$allowed_mimes = apply_filters(
			'wpmudev_drive_allowed_mime_types',
			array(
				'application/pdf',
				'application/zip',
				'application/json',
				'application/msword',
				'application/vnd.ms-excel',
				'application/vnd.ms-powerpoint',
				'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
				'application/vnd.openxmlformats-officedocument.presentationml.presentation',
				'application/vnd.google-apps.document',
				'application/vnd.google-apps.spreadsheet',
				'application/vnd.google-apps.presentation',
				'text/plain',
				'text/csv',
				'image/jpeg',
				'image/png',
				'image/gif',
				'image/webp',
			)
		);

		$filetype = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
		$mime     = isset( $filetype['type'] ) ? $filetype['type'] : '';

		if ( empty( $allowed_mimes ) ) {
			return new WP_Error(
				'invalid_file_type',
				__( 'File uploads are currently disabled. Please contact the site administrator.', 'wpmudev-plugin-test' ),
				array( 'status' => 400 )
			);
		}

		if ( empty( $mime ) || ! in_array( $mime, $allowed_mimes, true ) ) {
			return new WP_Error(
				'invalid_file_type',
				__( 'The selected file type is not allowed. Please upload a different file.', 'wpmudev-plugin-test' ),
				array( 'status' => 400 )
			);
		}

		$sanitized_name = sanitize_file_name( $file['name'] );

		try {
			$drive_file = new Google_Service_Drive_DriveFile();
			$drive_file->setName( $sanitized_name );

			$result = $this->drive_service->files->create(
				$drive_file,
				array(
					'data'       => file_get_contents( $file['tmp_name'] ),
					'mimeType'   => $mime,
					'uploadType' => 'multipart',
					'fields'     => 'id,name,mimeType,size,webViewLink',
				)
			);

			return new WP_REST_Response(
				array(
					'success' => true,
					'file'    => array(
						'id'          => $result->getId(),
						'name'        => $result->getName(),
						'mimeType'    => $result->getMimeType(),
						'size'        => $result->getSize(),
						'webViewLink' => $result->getWebViewLink(),
					),
				),
				200
			);

		} catch ( \Google_Service_Exception $e ) {
			$error_message = $e->getMessage();

			if ( function_exists( 'error_log' ) ) {
				error_log( sprintf( 'WPMUDEV Drive: Upload Google API error: %s', $error_message ) );
			}

			return new WP_Error(
				'upload_failed',
				__( 'Google Drive reported an error while uploading the file. Please try again later.', 'wpmudev-plugin-test' ),
				array( 'status' => 500 )
			);

		} catch ( \Exception $e ) {
			if ( function_exists( 'error_log' ) ) {
				error_log( sprintf( 'WPMUDEV Drive: Upload exception: %s', $e->getMessage() ) );
			}

			return new WP_Error(
				'upload_failed',
				__( 'An unexpected error occurred while uploading the file. Please try again later.', 'wpmudev-plugin-test' ),
				array( 'status' => 500 )
			);
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