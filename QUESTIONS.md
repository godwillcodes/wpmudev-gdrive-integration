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

---

## 3. Backend: Credentials Storage Endpoint
Complete the REST API endpoint `/wp-json/wpmudev/v1/drive/save-credentials`:
- Implement proper request validation and sanitization
- Store credentials securely in WordPress options
- Return appropriate success/error responses
- Include proper authentication and permission checks

---

## 4. Backend: Google Drive Authentication
Implement the complete OAuth 2.0 authentication flow:
- Generate proper authorization URLs with required scopes
- Handle the OAuth callback securely
- Implement token storage and refresh functionality
- Ensure proper error handling throughout the flow

---

## 5. Backend: Files List API
Create the functionality to fetch and return Google Drive files:
- Connect to Google Drive API using stored credentials
- Return properly formatted file information
- Include pagination support
- Handle API errors gracefully

---

## 6. Backend: File Upload Implementation
Complete the file upload functionality to Google Drive:
- Handle multipart file uploads securely
- Validate file types and sizes
- Return upload progress/completion status
- Implement proper error handling and cleanup

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
