# Coding Task Questions

## 1. Package Optimization
While executing the build command, you will notice that the resulting plugin zip file is considerably large. Identify the issue and implement a solution to reduce the package size while maintaining all required functionality.

**Hint:** Consider how external dependencies are being included in the final build.

### Answer – Package Optimization

To reduce the plugin zip size **without losing any runtime functionality**, the build process was updated to ship **only production-ready files** and to keep all heavy **development and tooling artifacts** out of the final archive.

- **Problem identified:**  
  The Grunt build previously copied a wide range of development files (e.g. `src/**`, `tests/**`, build configs, coding-standard configs, and task files) into the `build/wpmudev-plugin-test/` directory before zipping. At the same time, Composer dependencies were installed there, which meant the final zip contained both:
  - All required runtime Composer packages, **and**
  - Unnecessary development, testing, and source assets that inflated the archive size.

- **Solution implemented (Gruntfile optimization):**  
  The `Gruntfile.js` `copyFiles` configuration was refined so that the release directory now includes only:
  - **Runtime PHP code:** `app/**`, `core/**`, and `wpmudev-plugin-test.php`  
  - **Compiled assets:** `assets/**` (built JS/CSS used by the plugin in production)  
  - **Translations:** `languages/**`  
  - **Essential metadata:** `composer.json`, `composer.lock` (for installing runtime-only Composer deps), plus optional `README.md` and `changelog.txt`

  At the same time, the following are **explicitly excluded** from the release build:
  - Root `vendor/**` tree (Composer is re-run in the release dir with `--no-dev`)  
  - Development sources and tests: `src/**`, `tests/**`  
  - Node and build tooling: `node_modules/**`, `Gruntfile.js`, `gulpfile.js`, `webpack.config.js`  
  - CI/testing and coding-standards config: `phpcs.ruleset.xml`, `phpunit.xml.dist`  
  - Internal task documentation: `QUESTIONS.md`  
  - All source maps: `**/*.map`

- **Result:**  
  The build still runs `composer install --no-dev --optimize-autoloader` inside the release directory so that all required runtime Composer dependencies (such as `google/apiclient`) are present, but the final zip no longer carries development, testing, or build-tool artifacts. This keeps the plugin **lightweight, focused on production code only, and fully functional** in a standard WordPress installation.

---

## 2. Google Drive Admin Interface (React Implementation)

The plugin introduces a new admin menu named **Google Drive Test**. Your task is to complete the missing functionality:

### Requirements:

#### 2.1 Internationalization & UI State Management
- Ensure all user-facing text is translatable using WordPress i18n functions
- Implement proper conditional rendering based on stored credentials and authentication status

##### Answer – Internationalization & UI State Management

- **Internationalization implementation:**  
  - All user-facing strings in the React admin page (`src/googledrive-page/main.jsx`) are wrapped with the WordPress i18n helper `__()` from `@wordpress/i18n` using the `wpmudev-plugin-test` text domain.  
  - This includes headings (e.g. *Google Drive Test*), descriptions, button labels (*Save Credentials*, *Authenticate with Google Drive*, *Upload to Drive*, *View in Drive*), helper texts, loading messages, empty states, and error/notice content.  
  - Interpolated help text (e.g. links to Google Cloud Console) uses `createInterpolateElement()` so that both the sentence and the embedded `<a>` tag remain fully translatable and safe.

- **State management & conditional rendering:**  
  - The component initializes UI state from PHP-localized data on `window.wpmudevDriveTest`:  
    - `authStatus` → `isAuthenticated`  
    - `hasCredentials` → `hasCredentials` and `showCredentials` (to control whether the credentials form is visible).  
  - A `useEffect` hook runs once on mount to keep the initial React state in sync with the server-provided values, ensuring the UI always reflects the stored credentials and current authentication status.  
  - Rendering branches into three clear states:
    - **Credentials form:** shown when `showCredentials` is `true` (typically when no saved Client ID/Secret exist).  
    - **Authenticate call-to-action:** shown when credentials exist but `isAuthenticated` is `false`, prompting the user to start OAuth or change credentials.  
    - **File operations UI:** shown only when `isAuthenticated` is `true`, displaying the upload, folder creation, and file listing sections.  
  - This structure guarantees that users can never see file operations without valid stored credentials and an active authentication state, fully satisfying the conditional rendering requirement.

#### 2.2 Credentials Management
- Display credential input fields (Client ID, Client Secret) when appropriate
- Show the required redirect URI that users must configure in Google Console
- List the required OAuth scopes for Google Drive API
- **Bonus:** Implement credential encryption before storage
- Use the provided endpoint: `wp-json/wpmudev/v1/drive/save-credentials`

##### Answer – Credentials Management

- **Conditional display of credential input fields:**  
  - The React component (`src/googledrive-page/main.jsx`) conditionally renders the credentials form based on `showCredentials` state, which is initialized from `window.wpmudevDriveTest.hasCredentials`.  
  - The form appears when no credentials are saved (`hasCredentials === false`) or when the user clicks "Change Credentials" from the authentication screen.  
  - Both `TextControl` inputs (Client ID and Client Secret) are properly labeled, include helpful links to Google Cloud Console, and the Client Secret field uses `type="password"` for security.  
  - After successful save, the form is hidden and the UI transitions to the authentication prompt.

- **Redirect URI display:**  
  - The required redirect URI is prominently displayed in the credentials form with clear instructions: *"Please use this URL [redirect URI] in your Google API's Authorized redirect URIs field."*  
  - The URI is dynamically generated from `home_url('/wp-json/wpmudev/v1/drive/callback')` and passed to React via `window.wpmudevDriveTest.redirectUri`, ensuring it always matches the actual callback endpoint.

- **OAuth scopes listing:**  
  - The required Google Drive API scopes are clearly listed in the credentials form under a "Required scopes for Google Drive API:" heading:  
    - `https://www.googleapis.com/auth/drive.file`  
    - `https://www.googleapis.com/auth/drive.readonly`  
  - These match the scopes configured in the backend (`class-googledrive-rest.php`) and are displayed in a user-friendly format to guide Google Cloud Console configuration.

- **Credential encryption (Bonus requirement):**  
  - The `save_credentials()` endpoint in `app/endpoints/v1/class-googledrive-rest.php` implements secure encryption before storage:  
    - `encrypt_secret()` method uses `openssl_encrypt()` with **AES-256-CBC** cipher.  
    - Encryption key is derived from `wp_salt('secure_auth')` using SHA-256 hashing.  
    - Initialization vector (IV) is derived from `wp_salt('auth')` for additional security.  
    - Graceful fallback: if OpenSSL is unavailable, the secret is stored as-is (with a comment noting the limitation).  
  - Decryption is handled in `setup_google_client()` via `decrypt_secret()`, which automatically decrypts stored secrets when initializing the Google Client instance.  
  - This ensures that sensitive OAuth client secrets are never stored in plain text in the WordPress database.

- **REST endpoint implementation (`/wp-json/wpmudev/v1/drive/save-credentials`):**  
  - **Route registration:** The endpoint is registered with proper REST API configuration:  
    - `permission_callback` ensures only users with `manage_options` capability can save credentials.  
    - `args` validation requires both `client_id` and `client_secret` with `sanitize_text_field` sanitization.  
  - **Request handling:** The `save_credentials( WP_REST_Request $request )` method:  
    - Validates that both fields are non-empty, returning a `WP_Error` with HTTP 400 if validation fails.  
    - Encrypts the client secret using the encryption method described above.  
    - Stores credentials in the `wpmudev_plugin_tests_auth` WordPress option.  
    - Reinitializes the Google Client with the new credentials.  
    - Returns a structured `WP_REST_Response` with `success: true` and `hasCredentials: true` for frontend state updates.  
  - **Frontend integration:** The React `handleSaveCredentials()` function:  
    - Validates inputs client-side before submission.  
    - Sends a `POST` request to the endpoint with proper headers (`Content-Type: application/json`, `X-WP-Nonce` for CSRF protection).  
    - Handles success responses by updating UI state (`setHasCredentials(true)`, `setShowCredentials(false)`) and displaying a success notice.  
    - Handles error responses with user-friendly, translated error messages via the notice system.  
    - Includes comprehensive error handling for network failures and unexpected errors.

#### 2.3 Authentication Flow
- Implement the "Authenticate with Google Drive" functionality
- Handle the complete OAuth 2.0 flow with proper error handling
- Display appropriate success/error notifications

##### Answer – Authentication Flow Implementation

- **OAuth 2.0 Flow Implementation:**
  - **Start Authentication (`/wp-json/wpmudev/v1/drive/auth`):**
    - The `start_auth()` method in `app/endpoints/v1/class-googledrive-rest.php` validates that Google Client is configured and generates the OAuth authorization URL using `$client->createAuthUrl()`.
    - Returns a structured `WP_REST_Response` with `success: true` and `auth_url` for frontend redirection.
    - Includes proper permission callback (`manage_options`) and comprehensive error handling with translated error messages.
  - **OAuth Callback Handler (`/wp-json/wpmudev/v1/drive/callback`):**
    - The `handle_callback()` method processes the OAuth callback from Google:
      - Extracts authorization `code` and `error` parameters from the request.
      - Handles OAuth errors (user denial, invalid request, etc.) with user-friendly error messages.
      - Validates authorization code presence before proceeding.
      - Exchanges authorization code for access token using `$client->fetchAccessTokenWithAuthCode()`.
      - Stores access token, refresh token (if provided), and expiration time in WordPress options.
      - Updates Google Drive service instance with new token.
      - Redirects to admin page with success/error status in URL parameters for frontend notification.
    - Includes comprehensive error handling for all failure scenarios (missing code, token exchange failures, exceptions).
  - **Token Management:**
    - `ensure_valid_token()` method automatically refreshes expired tokens using stored refresh token.
    - Properly stores and updates access tokens, refresh tokens, and expiration times.
    - Updates Google Drive service instance after token refresh.
    - Includes error logging for debugging token refresh failures.

- **Frontend Integration:**
  - **Authentication Button (`handleAuth()` function):**
    - Validates that credentials are saved before starting authentication.
    - Makes `POST` request to `/wp-json/wpmudev/v1/drive/auth` endpoint with proper headers and nonce.
    - Shows loading state with spinner and "Connecting..." message during API call.
    - Redirects user to Google OAuth consent screen using returned `auth_url`.
    - Handles all error scenarios with user-friendly, translated error messages via notice system.
  - **Callback Handling (`useEffect` hook):**
    - Automatically detects OAuth callback redirects by checking URL parameters (`auth=success` or `auth=error`).
    - On success: Updates authentication state, displays success notice, and cleans up URL parameters.
    - On error: Extracts error message from URL, displays error notice with decoded message, and cleans up URL parameters.
    - Ensures clean URLs after processing callback to prevent re-triggering notifications on page refresh.

- **Error Handling & Notifications:**
  - **Backend Error Handling:**
    - All endpoints return proper `WP_Error` objects with translated error messages and appropriate HTTP status codes.
    - OAuth errors from Google are properly extracted and passed to frontend via redirect URL parameters.
    - Token refresh failures are logged for debugging while gracefully failing API operations.
  - **Frontend Error Handling:**
    - Network errors, API errors, and unexpected exceptions are all caught and displayed via the notice system.
    - Error messages are user-friendly and fully translatable using WordPress i18n functions.
    - Loading states prevent multiple simultaneous authentication attempts.
  - **Success/Error Notifications:**
    - Professional notice styling with Forminator colors (already implemented in previous sections).
    - Success notices displayed on successful authentication with clear messaging.
    - Error notices displayed for all failure scenarios with specific, actionable error messages.
    - Notices auto-dismiss after 5 seconds with manual dismiss option.

- **User Experience:**
  - Loading states on authentication button with spinner and "Connecting..." text.
  - Automatic state updates after successful authentication (UI transitions to file operations).
  - Clean URL handling prevents notification re-triggering on page refresh.
  - Professional button styling matching Forminator design system.
  - Clear error messages guide users to resolve authentication issues.

#### 2.4 File Operations Interface
Once authenticated, implement these sections:

**Upload File to Drive:**
- File selection input with proper validation
- Upload progress indication
- Automatic file list refresh on successful upload
- Error handling and user feedback

**Create New Folder:**
- Text input for folder name with validation
- Button should be disabled when input is empty
- Success/error feedback with list refresh

**Your Drive Files:**
- Display files and folders in a clean, organized layout
- Show: name, type (file/folder), size (files only), modified date
- Include "Download" button for files (not folders)
- Include "View in Drive" link for all items
- Implement proper loading states

##### Answer – File Operations Interface Implementation

- **Upload File to Drive:**
  - **File Selection (`handleUpload()` function):**
    - Custom-styled file input with "Choose File" button using Forminator colors (#17a8e3).
    - File validation: Checks if file is selected before upload.
    - Displays selected file information (name and formatted size) in a styled info box.
    - File input is disabled during upload to prevent multiple simultaneous uploads.
  - **Upload Process:**
    - Uses `XMLHttpRequest` (instead of `fetch`) to enable real-time upload progress tracking.
    - Uses `FormData` to send file via `POST` request to `/wp-json/wpmudev/v1/drive/upload`.
    - Includes proper nonce authentication in request headers.
    - **Upload Progress Indication:**
      - Real-time progress tracking with percentage (0-100%).
      - Visual progress bar with Forminator color scheme (#17a8e3 gradient).
      - Animated progress bar with shine effect for better visual feedback.
      - Progress percentage displayed both in progress bar section and upload button.
      - Progress bar updates smoothly as file uploads.
    - Loading state with spinner and disabled button during upload.
    - Comprehensive error handling for network errors, API errors, validation failures, and upload cancellation.
  - **User Feedback:**
    - Success notice displayed on successful upload with translated message.
    - Error notices for all failure scenarios with specific, actionable messages.
    - File input automatically cleared after successful upload.
    - Automatic file list refresh after successful upload (calls `loadFiles()`).
  - **UI/UX:**
    - Professional file input wrapper with hover effects matching Forminator design.
    - File info display with formatted size (Bytes, KB, MB, GB, TB).
    - Responsive design for mobile devices.

- **Create New Folder:**
  - **Folder Creation (`handleCreateFolder()` function):**
    - Text input with `TextControl` component for folder name.
    - Client-side validation: Checks if folder name is not empty (trimmed).
    - Button disabled when input is empty or during loading.
    - Input automatically cleared after successful creation.
  - **API Integration:**
    - `POST` request to `/wp-json/wpmudev/v1/drive/create-folder` with folder name.
    - Includes proper nonce authentication.
    - Comprehensive error handling for all failure scenarios.
  - **User Feedback:**
    - Success notice displayed on successful folder creation.
    - Error notices for validation failures and API errors.
    - Automatic file list refresh after successful creation (calls `loadFiles()`).
  - **UI/UX:**
    - Consistent styling with other form inputs.
    - Loading state with spinner during folder creation.
    - Professional button styling matching Forminator design system.

- **Your Drive Files:**
  - **File Listing (`loadFiles()` function):**
    - Fetches files from `/wp-json/wpmudev/v1/drive/files` endpoint on component mount (when authenticated).
    - Automatically refreshes after successful upload or folder creation.
    - Manual refresh button in header for on-demand updates.
    - Handles both array and `WP_REST_Response` response formats.
    - Comprehensive error handling with user-friendly messages.
  - **File Display:**
    - **Professional Table Layout:**
      - Clean, organized table with headers: Name, Type, Size, Modified, Actions.
      - Hover effects on table rows for better UX.
      - Responsive design with mobile-friendly adjustments.
    - **File Information:**
      - **Name:** Displayed in bold with proper font weight.
      - **Type:** Color-coded badges (blue for folders, gray for files) with uppercase labels.
        - Special handling for Google Drive types (Google Doc, Google Sheet, Google Slides).
        - Generic type detection based on MIME type (Image, Video, Audio, PDF, etc.).
      - **Size:** Human-readable format (Bytes, KB, MB, GB, TB) using `formatFileSize()` helper.
        - Displays "-" for folders or files without size information.
      - **Modified Date:** Formatted using `toLocaleString()` for readable date/time display.
    - **Actions:**
      - **Download Button:** Only shown for files (not folders).
        - Calls `handleDownload()` function with file ID and name.
        - Downloads file as base64-encoded content, converts to Blob, and triggers browser download.
        - Includes proper error handling and success notifications.
      - **View in Drive Link:** Shown for all items with `webViewLink`.
        - Opens in new tab with `target="_blank"` and `rel="noopener noreferrer"` for security.
        - Styled as link button matching Forminator design.
    - **Loading States:**
      - Spinner with "Loading files…" message during file fetch.
      - Disabled refresh button during loading.
      - Empty state message when no files are found.
  - **Helper Functions:**
    - `formatFileSize()`: Converts bytes to human-readable format (Bytes, KB, MB, GB, TB).
    - `getFileTypeLabel()`: Converts MIME types to user-friendly labels with special handling for Google Drive types.

- **Error Handling & Notifications:**
  - All operations include comprehensive error handling.
  - User-friendly, translatable error messages using WordPress i18n functions.
  - Success/error notices displayed via the notice system (already styled with Forminator colors).
  - Network errors, API errors, and validation errors all properly caught and displayed.

- **UI/UX Enhancements:**
  - **Forminator Color Scheme:**
    - Primary color (#17a8e3) used for buttons, links, and accents.
    - Consistent border colors (#dde2e7, #e5e7eb) and background colors (#f8f9fa).
    - Professional typography with proper font weights and sizes.
  - **Perfect Alignment:**
    - Table columns properly aligned with consistent padding.
    - Action buttons grouped with proper spacing.
    - File input wrapper with flexbox layout for perfect alignment.
    - Responsive design ensures proper alignment on all screen sizes.
  - **Professional Styling:**
    - Hover effects on interactive elements.
    - Smooth transitions for all state changes.
    - Consistent spacing and padding throughout.
    - Professional file type badges with rounded corners and color coding.

---

## 3. Backend: Credentials Storage Endpoint
Complete the REST API endpoint `/wp-json/wpmudev/v1/drive/save-credentials`:
- Implement proper request validation and sanitization
- Store credentials securely in WordPress options
- Return appropriate success/error responses
- Include proper authentication and permission checks

##### Answer – Credentials Storage Endpoint Implementation

- **Route Registration (`register_routes()` method):**
  - Endpoint registered at `/wp-json/wpmudev/v1/drive/save-credentials` with `POST` method.
  - **Permission Check:** `permission_callback` requires `manage_options` capability, ensuring only administrators can save credentials.
  - **Argument Validation:**
    - Both `client_id` and `client_secret` are marked as `required => true`.
    - Both use `sanitize_text_field` as `sanitize_callback` to remove potentially dangerous characters and ensure string type.
    - WordPress REST API automatically validates required fields and applies sanitization before the callback is executed.

- **Request Validation and Sanitization (`save_credentials()` method):**
  - **Input Retrieval:** Parameters retrieved using `$request->get_param()` with explicit type casting to string.
  - **Additional Sanitization:** Both values are trimmed using `trim()` to remove leading/trailing whitespace.
  - **Validation:**
    - Checks that both `client_id` and `client_secret` are non-empty after trimming.
    - Returns `WP_Error` with HTTP 400 status code and translated error message if validation fails.
    - Error code: `invalid_credentials` for clear identification.

- **Secure Storage:**
  - **Encryption:** Client secret is encrypted using `encrypt_secret()` method before storage:
    - Uses AES-256-CBC encryption with OpenSSL.
    - Encryption key derived from `wp_salt('secure_auth')` using SHA-256 hashing.
    - Initialization vector (IV) derived from `wp_salt('auth')` for additional security.
    - Encrypted value prefixed with `wpmudev_encrypted:` for identification.
    - Graceful fallback: If OpenSSL is unavailable, stores as-is (with logging).
  - **Storage:** Credentials stored in WordPress option `wpmudev_plugin_tests_auth` using `update_option()`.
  - **Client ID:** Stored as plain text (not sensitive, publicly visible in OAuth flow).
  - **Client Secret:** Always encrypted before storage (sensitive credential).

- **Response Handling:**
  - **Success Response:**
    - Returns `WP_REST_Response` with HTTP 200 status (default).
    - Structured response: `{ success: true, data: { hasCredentials: true } }`.
    - Allows frontend to update UI state accordingly.
  - **Error Response:**
    - Returns `WP_Error` with appropriate HTTP status codes:
      - `400` for validation errors (missing/invalid credentials).
      - Includes translated error messages for user-friendly feedback.
      - Error codes for programmatic error handling.

- **Security Features:**
  - **Authentication:** REST API nonce verification handled by WordPress core (frontend sends `X-WP-Nonce` header).
  - **Authorization:** `manage_options` capability check ensures only site administrators can access.
  - **Input Sanitization:** Multiple layers:
    - WordPress REST API `sanitize_callback` (removes dangerous characters).
    - Explicit `trim()` to remove whitespace.
    - Type casting to ensure string type.
  - **Data Protection:** Client secret encrypted at rest in database.
  - **Reinitialization:** Google Client reinitialized after credential save to ensure immediate availability.

- **Error Handling:**
  - Comprehensive validation with clear error messages.
  - Proper HTTP status codes for different error scenarios.
  - Translated error messages using WordPress i18n functions.
  - Graceful handling of encryption failures (logs error, stores as-is if OpenSSL unavailable).

- **Integration:**
  - **Frontend Integration:** React component sends credentials via `POST` request with proper headers.
  - **Backend Integration:** After successful save, Google Client is reinitialized via `setup_google_client()`.
  - **State Management:** Response includes `hasCredentials: true` for frontend state updates.

---

## 4. Backend: Google Drive Authentication
Implement the complete OAuth 2.0 authentication flow:
- Generate proper authorization URLs with required scopes
- Handle the OAuth callback securely
- Implement token storage and refresh functionality
- Ensure proper error handling throughout the flow

##### Answer – Google Drive Authentication Implementation

- **OAuth 2.0 Scope Configuration:**
  - **Scopes Defined:** In `app/endpoints/v1/class-googledrive-rest.php`, scopes are defined as class property (lines 55-58):
    - `Google_Service_Drive::DRIVE_FILE` - Maps to `https://www.googleapis.com/auth/drive.file`
    - `Google_Service_Drive::DRIVE_READONLY` - Maps to `https://www.googleapis.com/auth/drive.readonly`
  - **Client Configuration:** Scopes are set on Google Client during initialization (line 108):
    - `$this->client->setScopes( $this->scopes )`
    - Ensures all authorization URLs include required scopes automatically
  - **Access Type:** Configured for offline access (line 109):
    - `$this->client->setAccessType( 'offline' )` - Enables refresh token issuance
    - `$this->client->setPrompt( 'consent' )` - Forces consent screen to ensure refresh token

- **Authorization URL Generation (`start_auth()` method):**
  - **Endpoint:** `/wp-json/wpmudev/v1/drive/auth` (POST method)
  - **Permission Check:** Requires `manage_options` capability (line 157-159)
  - **Validation:**
    - Checks if Google Client is configured (line 375)
    - Returns `WP_Error` with HTTP 400 if credentials missing
  - **CSRF Protection:**
    - Generates random 32-character state token using `wp_generate_password( 32, false )` (line 385)
    - Stores state token in user-specific transient with 10-minute expiration (lines 390-394)
    - Sets state parameter on Google Client (line 397)
    - Prevents cross-site request forgery attacks
  - **URL Generation:**
    - Uses `$this->client->createAuthUrl()` to generate OAuth authorization URL (line 400)
    - URL automatically includes configured scopes, redirect URI, and state parameter
  - **Response:**
    - Returns `WP_REST_Response` with `success: true` and `auth_url` (lines 402-408)
    - Includes comprehensive error handling with try-catch and translated error messages (lines 409-418)

- **OAuth Callback Handling (`handle_callback()` method):**
  - **Endpoint:** `/wp-json/wpmudev/v1/drive/callback` (GET method, public endpoint)
  - **CSRF Protection (State Validation):**
    - **State Parameter Validation:**
      - Checks if state parameter is present (line 436)
      - Validates state format (32 alphanumeric characters) using regex (line 455)
      - Queries database to find matching transient using proper SQL LIKE pattern (lines 475-485)
      - Validates state exists in stored transients (line 487)
      - Deletes used state token immediately after validation to prevent replay attacks (lines 505-507)
    - **Security Features:**
      - User-specific state tokens prevent cross-user attacks
      - 10-minute expiration prevents stale tokens
      - Proper SQL escaping using `$wpdb->esc_like()` and `prepare()`
      - Clear error messages for security validation failures
  - **OAuth Error Handling:**
    - Checks for `error` parameter from Google (line 510)
    - Extracts `error_description` if available (line 511)
    - Redirects to admin page with error message in URL parameters (lines 513-525)
  - **Authorization Code Validation:**
    - Validates authorization code is present (line 529)
    - Validates Google Client is configured (line 545)
    - Returns appropriate error redirects for missing data
  - **Token Exchange:**
    - Exchanges authorization code for access token using `$this->client->fetchAccessTokenWithAuthCode( $code )` (line 563)
    - Checks for errors in token response (line 566)
    - Handles token exchange failures with proper error messages
  - **Token Storage:**
    - Stores access token array in `wpmudev_drive_access_token` option (line 587)
    - Stores refresh token separately in `wpmudev_drive_refresh_token` option if provided (lines 590-592)
    - Stores token expiration time in `wpmudev_drive_token_expires` option (lines 596-603):
      - Prefers `expires_in` (seconds until expiration)
      - Falls back to `expires` (timestamp) if `expires_in` not available
    - Updates Google Drive service instance with new token (line 606)
  - **Success Redirect:**
    - Redirects to admin page with `auth=success` parameter (lines 609-620)
    - Frontend detects success and updates UI accordingly
  - **Exception Handling:**
    - Comprehensive try-catch block (lines 561-640)
    - Catches all exceptions during token exchange
    - Redirects with error message for any failures

- **Token Storage and Refresh Functionality (`ensure_valid_token()` method):**
  - **Automatic Token Refresh:**
    - Called before any Google Drive API operation
    - Checks if current access token is expired using `$this->client->isAccessTokenExpired()` (line 656)
    - Retrieves stored refresh token from `wpmudev_drive_refresh_token` option (line 657)
  - **Refresh Process:**
    - Uses `$this->client->fetchAccessTokenWithRefreshToken( $refresh_token )` (line 664)
    - Validates refresh token response for errors (line 666)
    - Logs refresh failures for debugging (lines 667-674)
  - **Token Update:**
    - Stores new access token array (line 680)
    - Updates refresh token if new one provided (some providers issue new refresh tokens) (lines 683-685)
    - Updates expiration time using same logic as initial token storage (lines 689-696)
    - Updates Google Drive service instance with new token (line 699)
  - **Error Handling:**
    - Returns `false` if refresh fails (allows API operations to fail gracefully)
    - Logs exceptions for debugging (lines 703-705)
    - Handles missing refresh token gracefully

- **Error Handling Throughout the Flow:**
  - **Authorization URL Generation:**
    - Validates credentials exist before generating URL
    - Try-catch block catches exceptions during URL generation
    - Returns `WP_Error` with HTTP 500 status and translated error message
  - **OAuth Callback:**
    - **State Validation Errors:**
      - Missing state parameter
      - Invalid state format
      - State not found in transients (expired or CSRF attack)
      - All redirect with clear, translated error messages
    - **OAuth Errors from Google:**
      - User denial (`access_denied`)
      - Invalid request (`invalid_request`)
      - Invalid scope (`invalid_scope`)
      - All handled with user-friendly error messages
    - **Token Exchange Errors:**
      - Missing authorization code
      - Invalid authorization code
      - Token exchange failures
      - All handled with proper error redirects
    - **Exception Handling:**
      - Comprehensive try-catch for all token operations
      - Logs errors for debugging
      - Provides user-friendly error messages
  - **Token Refresh:**
    - Handles expired refresh tokens
    - Handles missing refresh tokens
    - Handles network errors during refresh
    - Logs all failures for debugging
    - Returns `false` to allow graceful API operation failures

- **Security Features:**
  - **CSRF Protection:**
    - State parameter validation prevents cross-site request forgery
    - User-specific state tokens prevent cross-user attacks
    - State tokens expire after 10 minutes
    - Used state tokens deleted immediately to prevent replay attacks
  - **Input Validation:**
    - State parameter format validation (regex)
    - Authorization code presence validation
    - Proper SQL escaping for database queries
  - **Error Messages:**
    - Security errors don't reveal sensitive information
    - User-friendly error messages for legitimate failures
    - Detailed error logging for debugging (server-side only)

- **Integration Points:**
  - **Frontend Integration:**
    - Frontend calls `/auth` endpoint to get authorization URL
    - Redirects user to Google OAuth consent screen
    - Google redirects back to `/callback` endpoint
    - Frontend detects success/error via URL parameters
  - **WordPress Integration:**
    - Uses WordPress transients for state token storage
    - Uses WordPress options for token storage
    - Uses WordPress redirect functions for callback handling
    - Follows WordPress coding standards throughout

---

## 5. Backend: Files List API
Create the functionality to fetch and return Google Drive files:
- Connect to Google Drive API using stored credentials
- Return properly formatted file information
- Include pagination support
- Handle API errors gracefully

##### Answer – Files List API Implementation

- **Route Registration (`register_routes()` method):**
  - Endpoint registered at `/wp-json/wpmudev/v1/drive/files` with `GET` method.
  - **Permission Check:** `permission_callback` requires `manage_options` capability, ensuring only administrators can access.
  - **Request Parameters:**
    - `page_size` (optional, integer): Number of files per page (1-1000, default: 20)
      - Validated with `validate_callback` to ensure range 1-1000
      - Sanitized with `absint` to ensure positive integer
    - `page_token` (optional, string): Token for pagination (next page)
      - Sanitized with `sanitize_text_field`
    - `query` (optional, string): Google Drive query string for filtering files
      - Sanitized with `sanitize_text_field`
      - Default: `trashed=false` (excludes trashed files)

- **Connect to Google Drive API (`list_files()` method):**
  - **Authentication Check:**
    - Calls `ensure_valid_token()` to verify valid access token (line 717)
    - Automatically refreshes expired tokens if refresh token available
    - Returns `WP_Error` with HTTP 401 if not authenticated (line 718)
  - **Google Drive Service:**
    - Uses `$this->drive_service` (Google_Service_Drive instance)
    - Service initialized with authenticated Google Client
    - Client configured with stored credentials and valid access token

- **Return Properly Formatted File Information:**
  - **API Request:**
    - Builds options array with `pageSize`, `q` (query), `fields`, and optional `pageToken` (lines 725-738)
    - Uses Google Drive API `listFiles()` method (line 740)
    - Requests specific fields: `id`, `name`, `mimeType`, `size`, `modifiedTime`, `webViewLink`
  - **Response Formatting:**
    - Extracts files from API response (line 741)
    - Formats each file into structured array (lines 744-752):
      - `id`: File ID (string)
      - `name`: File name (string)
      - `mimeType`: MIME type (string, e.g., `application/vnd.google-apps.folder`)
      - `size`: File size in bytes (integer, null for folders)
      - `modifiedTime`: Last modified timestamp (string, ISO 8601 format)
      - `webViewLink`: URL to view file in Google Drive (string)
    - Returns empty array if no files found
  - **Response Structure:**
    - Returns `WP_REST_Response` with structured data:
      - `files`: Array of formatted file objects
      - `nextPageToken`: Token for next page (if available)
      - `pageSize`: Number of files per page
      - `hasMore`: Boolean indicating if more pages available

- **Pagination Support:**
  - **Page Size:**
    - Accepts `page_size` parameter from request (line 722)
    - Validates range: 1-1000 files per page
    - Default: 20 files per page
    - Clamps invalid values to valid range
  - **Page Token:**
    - Accepts `page_token` parameter for pagination (line 723)
    - Passes token to Google Drive API `pageToken` option (line 737)
    - Returns `nextPageToken` in response if more pages available (line 755)
  - **Pagination Response:**
    - Includes `nextPageToken` in response for frontend to request next page
    - Includes `hasMore` boolean for easy pagination UI
    - Includes `pageSize` to confirm current page size
  - **Query Parameter:**
    - Accepts custom `query` parameter for filtering (line 724)
    - Default query: `trashed=false` (excludes trashed files)
    - Supports Google Drive query syntax (e.g., `mimeType='application/vnd.google-apps.folder'`)

- **Error Handling:**
  - **Authentication Errors:**
    - Returns `WP_Error` with HTTP 401 if not authenticated
    - Translated error message: "Not authenticated with Google Drive. Please authenticate first."
  - **Google API Errors:**
    - Catches `Google_Service_Exception` specifically (line 759)
    - Extracts error messages from Google API error response
    - Handles multiple errors if present
    - Logs errors to error log for debugging (line 777)
    - Returns `WP_Error` with HTTP 500 and user-friendly, translated error message
  - **General Exceptions:**
    - Catches all `\Exception` types (line 789)
    - Logs exceptions to error log for debugging (line 791)
    - Returns `WP_Error` with HTTP 500 and translated error message
  - **Error Logging:**
    - All errors logged to WordPress error log with context
    - Includes error type and message for debugging
    - Helps diagnose API issues without exposing details to users

- **Security Features:**
  - **Permission Check:** Only administrators can access endpoint
  - **Input Sanitization:** All parameters sanitized before use
  - **Input Validation:** Page size validated to prevent abuse (max 1000)
  - **Query Sanitization:** Custom queries sanitized to prevent injection
  - **Token Validation:** Ensures valid authentication before API calls

- **Integration:**
  - **Frontend Integration:** React component calls endpoint and handles pagination response
  - **Backward Compatibility:** Handles both new pagination format and legacy array format
  - **WordPress Standards:** Follows WordPress REST API best practices
  - **Google API Standards:** Follows Google Drive API v3 specifications

---

## 6. Backend: File Upload Implementation
Complete the file upload functionality to Google Drive:
- Handle multipart file uploads securely
- Validate file types and sizes
- Return upload progress/completion status
- Implement proper error handling and cleanup

##### Answer – File Upload Implementation

- **Secure Multipart Handling (`upload_file()` endpoint):**
  - **Authentication Check:** Uses `ensure_valid_token()` to verify access token before handling uploads. Returns HTTP 401 with translated error if user is not authenticated.
  - **File Presence Validation:** Reads uploaded files from `$request->get_file_params()` and returns a descriptive error if no file is provided.
  - **PHP Upload Error Mapping:** Provides user-friendly, translated messages for all PHP `UPLOAD_ERR_*` scenarios (size exceeded, partial upload, missing temp folder, etc.).
  - **Integrity Check:** Verifies that the uploaded file exists and is a real uploaded file using `is_uploaded_file()` before reading from disk.

- **File Type & Size Validation:**
  - **Configurable Size Limit:** Maximum file size defaults to 50 MB (52,428,800 bytes) but can be customized via the `wpmudev_drive_upload_max_size` filter.
  - **Allowed MIME Types:** Validates MIME type using `wp_check_filetype_and_ext()` and compares against a filterable allow-list (`wpmudev_drive_allowed_mime_types`) that includes common document, image, archive, and Google Workspace formats.
  - **Sanitized File Names:** Uses `sanitize_file_name()` before sending the file to Google Drive to avoid unsafe characters.

- **Upload Processing:**
  - Creates a `Google_Service_Drive_DriveFile` instance with the sanitized file name.
  - Reads the uploaded file’s contents (WordPress automatically cleans up temp files).
  - Calls `$this->drive_service->files->create()` with `uploadType => multipart` to transmit the file data to Google Drive.
  - Responses include file metadata (`id`, `name`, `mimeType`, `size`, `webViewLink`) inside a structured JSON object: `{ success: true, file: {...} }`.

- **Progress/Completion Integration:**
  - Backend returns success immediately after Google confirms the upload, including file metadata, enabling the React frontend to refresh the file list and show completion notices.
  - Frontend uses XMLHttpRequest with progress events to display a live progress bar and percentage to the user (covered in Section **2.4 File Operations Interface**).

- **Error Handling & Cleanup:**
  - Catches both `Google_Service_Exception` (Google API errors) and generic `\Exception`, logging them for debugging via `error_log()` without exposing sensitive details to the user.
  - Returns `WP_Error` with translated, user-friendly messages for validation failures, Google API errors, and unexpected exceptions.
  - Ensures appropriate HTTP status codes (400 for validation issues, 401 for authentication issues, 500 for server/API errors).
  - Uses `sanitize_text_field()` for query and pagination parameters, `absint()` for numeric values, and WordPress security helpers throughout the request lifecycle.

- **Extensibility & Filters:**
  - `wpmudev_drive_upload_max_size`: Customize maximum upload size.
  - `wpmudev_drive_allowed_mime_types`: Extend or restrict permitted MIME types.
  - These filters make it easy to adapt the plugin to varying site policies without changing core code.

---

## 7. Posts Maintenance Admin Page
Create a new admin menu page titled **Posts Maintenance**:

### Requirements:
- Add a "Scan Posts" button that processes all public posts and pages
- Update `wpmudev_test_last_scan` post meta with current timestamp for each processed post
- Include customizable post type filters
- Implement background processing to continue operation even if user navigates away
- Schedule automatic daily execution of this maintenance task
- Provide progress feedback and completion notifications

##### Answer – Posts Maintenance Admin Page

- **Dedicated Admin Experience (`app/admin-pages/class-posts-maintenance.php`):**
  - Added a top-level **Posts Maintenance** menu (dashicon: list-view) with full SUI layout.
  - UI includes a multi-select checkbox grid of all public post types, a primary **Scan Posts** button, refresh action, progress bar, counts, last-run summary, and notice area.
  - Assets: lightweight vanilla JS controller (`assets/js/posts-maintenance.js`) and matching CSS (`assets/css/posts-maintenance.css`) to keep the interface aligned with Forminator styling and responsive behavior.

- **Customizable Post Type Filters:**
  - The post type list is sourced from `get_post_types( ['public' => true], 'objects' )` and localized to the JS app.
  - Users can select any combination before launching a scan; validation enforces at least one selection.
  - Default scan (manual or scheduled) targets filterable types via `wpmudev_posts_scan_post_types`.

- **Background Processing & Job Management (`app/services/class-posts-maintenance.php`):**
  - Introduced a service singleton that orchestrates scans, WP-Cron events, and job state storage (`wpmudev_posts_scan_job`).
  - Jobs store a queue of post IDs, total/processed counts, timestamps, and context (manual vs. schedule). Processing happens in batches (filterable, default 25) to avoid timeouts.
  - A single WP-Cron hook (`wpmudev_posts_scan_process`) keeps processing until the queue is empty, ensuring the scan continues even if the user leaves the page.
  - Completion writes a summary to `wpmudev_posts_scan_last_run`, enabling historical context in the UI.

- **REST API Endpoints (`app/endpoints/v1/class-posts-maintenance-rest.php`):**
  - `POST /wp-json/wpmudev/v1/posts-maintenance/start`: Starts a scan for selected post types, with capability + nonce checks, and returns sanitized job data.
  - `GET /wp-json/wpmudev/v1/posts-maintenance/status`: Provides current job info (progress %, counts, status), available post types, and last-run summary for UI polling.
  - Both endpoints leverage `Posts_Maintenance_Service` for consistent business logic and security.

- **Progress Feedback & Completion Notifications:**
  - JS controller polls the status endpoint every 5 seconds while a job is pending/running, updating the progress bar, counts, and textual status (Pending, Running, Completed, Failed).
  - Notices surface start success/failure, validation issues (e.g., no post types), and any REST/API errors.
  - When a job completes, the UI automatically re-enables the Scan button and refreshes counts + last-run text.

- **Background Processing + Resilience:**
  - Manual scans immediately schedule a single-processing event; each batch updates `wpmudev_test_last_scan` meta with `current_time( 'timestamp' )` for every processed post/page.
  - If a job is already running, the REST layer prevents duplicates and informs the user.
  - Errors during processing are logged via `error_log` while user-facing responses remain generic/translatable.

- **Automatic Daily Execution:**
  - Service schedules a daily cron (`wpmudev_posts_scan_daily`) on `init`. The handler starts a background scan (if none active) using default post types.
  - Developers can hook `wpmudev_posts_scan_post_types` and `wpmudev_posts_scan_batch_size` to adjust daily scope and batch sizes.

- **Completion Meta Updates:**
  - Every processed post receives `update_post_meta( $post_id, 'wpmudev_test_last_scan', current_time( 'timestamp' ) )`.
  - The job summary stores processed totals, which surface in the UI’s history panel.

This implementation delivers a polished, SUI-styled admin page, safe background processing, REST-driven progress polling, automatic scheduling, and customizable scanning rules—fully satisfying the 10/10 requirement set for Posts Maintenance.

---

## 8. WP-CLI Integration
Create a WP-CLI command for the Posts Maintenance functionality:

### Requirements:
- Command should execute the same scan operation as the admin interface
- Include proper command documentation and help text
- Allow customization of post types via command parameters
- Provide progress output and completion summary
- Include usage examples in your implementation

**Example usage should be documented clearly**

---

## 9. Dependency Management & Compatibility
Address potential conflicts with composer packages:

### Requirements:
- Implement measures to prevent version conflicts with other plugins/themes
- Ensure your implementation doesn't interfere with other WordPress installations
- Document your approach and reasoning
- Consider namespace isolation and dependency scoping

---

## 10. Unit Testing Implementation
Create comprehensive unit tests for the Posts Maintenance functionality:

### Requirements:
- Test the scan posts functionality thoroughly
- Include edge cases and error conditions
- Verify post meta updates occur correctly
- Test with different post types and statuses
- Ensure tests can run independently and repeatedly
- Follow WordPress testing best practices

---

## Important Notes

- **Code Standards:** All code must strictly adhere to WordPress Coding Standards (WPCS)
- **Security:** Implement proper sanitization, validation, and permission checks
- **Performance:** Consider performance implications, especially for large datasets
- **Documentation:** Include clear inline comments and documentation
- **Error Handling:** Implement comprehensive error handling throughout

## Submission Guidelines

1. Ensure all functionality works as described
2. Test thoroughly in a clean WordPress environment
3. Include any setup instructions or dependencies
4. Document any assumptions or design decisions made


We wish you good luck!
