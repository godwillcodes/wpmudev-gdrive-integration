# Google Drive Integration Documentation

## Overview

This document covers the Google Drive integration including OAuth 2.0 authentication, REST API endpoints, file operations, and the React admin interface.

## Table of Contents

1. [Setup Requirements](#setup-requirements)
2. [OAuth 2.0 Authentication Flow](#oauth-20-authentication-flow)
3. [REST API Endpoints](#rest-api-endpoints)
4. [React Admin Interface](#react-admin-interface)
5. [Security Measures](#security-measures)
6. [Error Handling](#error-handling)

---

## Setup Requirements

### Google Cloud Console Setup

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project or select existing
3. Enable the **Google Drive API**
4. Create OAuth 2.0 credentials:
   - Application type: **Web application**
   - Authorized redirect URI: `https://yoursite.com/wp-json/wpmudev/v1/drive/callback`

### Required Scopes

```php
Google_Service_Drive::DRIVE_FILE      // View and manage files created by this app
Google_Service_Drive::DRIVE_READONLY  // View files in Drive
```

---

## OAuth 2.0 Authentication Flow

### Flow Diagram

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│   Admin UI  │────▶│  WordPress  │────▶│   Google    │
│   (React)   │     │  REST API   │     │   OAuth     │
└─────────────┘     └─────────────┘     └─────────────┘
       │                   │                   │
       │ 1. Click Auth     │                   │
       │──────────────────▶│                   │
       │                   │ 2. Generate URL   │
       │                   │──────────────────▶│
       │                   │                   │
       │ 3. Redirect       │◀──────────────────│
       │◀──────────────────│                   │
       │                   │                   │
       │ 4. User Consents  │                   │
       │──────────────────────────────────────▶│
       │                   │                   │
       │                   │ 5. Callback       │
       │                   │◀──────────────────│
       │                   │                   │
       │                   │ 6. Exchange Code  │
       │                   │──────────────────▶│
       │                   │                   │
       │                   │ 7. Access Token   │
       │                   │◀──────────────────│
       │                   │                   │
       │ 8. Success        │                   │
       │◀──────────────────│                   │
└─────────────────────────────────────────────────────┘
```

### State Parameter (CSRF Protection)

A unique state token is generated for each authentication request:

```php
$state = wp_generate_password( 32, false );
set_transient( 'wpmudev_drive_oauth_state_' . $state, true, 600 ); // 10 min TTL
```

The callback validates this state before processing:

```php
if ( ! get_transient( 'wpmudev_drive_oauth_state_' . $state ) ) {
    // Invalid state - reject request
}
delete_transient( 'wpmudev_drive_oauth_state_' . $state );
```

### Token Storage

| Option Key | Content | Encrypted |
|------------|---------|-----------|
| `wpmudev_plugin_tests_auth` | Client ID & Secret | Secret only |
| `wpmudev_drive_access_token` | Access token array | No |
| `wpmudev_drive_refresh_token` | Refresh token | No |
| `wpmudev_drive_token_expires` | Expiration timestamp | No |

### Token Refresh

Tokens are automatically refreshed when expired:

```php
private function ensure_valid_token() {
    $expires = get_option( 'wpmudev_drive_token_expires', 0 );
    
    if ( time() >= ( $expires - 300 ) ) { // 5 min buffer
        $refresh_token = get_option( 'wpmudev_drive_refresh_token' );
        $this->client->fetchAccessTokenWithRefreshToken( $refresh_token );
        // Store new token...
    }
}
```

---

## REST API Endpoints

### Base URL

```
/wp-json/wpmudev/v1/drive/
```

### Authentication

All endpoints require `manage_options` capability (Administrator role).

### Endpoints

#### Save Credentials

```http
POST /wp-json/wpmudev/v1/drive/save-credentials
```

**Request Body:**
```json
{
  "client_id": "your-client-id.apps.googleusercontent.com",
  "client_secret": "your-client-secret"
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "hasCredentials": true
  }
}
```

**Rate Limit:** 10 requests per 60 seconds

---

#### Initiate Authentication

```http
GET /wp-json/wpmudev/v1/drive/auth
```

**Response:** Redirects to Google OAuth consent screen

---

#### OAuth Callback

```http
GET /wp-json/wpmudev/v1/drive/callback?code=...&state=...
```

**Response:** Redirects to admin page with success/error status

---

#### List Files

```http
GET /wp-json/wpmudev/v1/drive/files
```

**Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `page_size` | integer | 20 | Items per page (1-1000) |
| `page_token` | string | - | Pagination token |
| `query` | string | `trashed=false` | Google Drive query |
| `order_by` | string | `modifiedTime desc` | Sort order |
| `no_cache` | boolean | false | Bypass cache |

**Response:**
```json
{
  "files": [
    {
      "id": "1abc...",
      "name": "document.pdf",
      "mimeType": "application/pdf",
      "size": "1024000",
      "modifiedTime": "2024-01-15T10:30:00.000Z",
      "webViewLink": "https://drive.google.com/..."
    }
  ],
  "nextPageToken": "...",
  "pageSize": 20,
  "orderBy": "modifiedTime desc",
  "hasMore": true,
  "cached": false
}
```

**Caching:** Results are cached for 30 seconds (configurable via `wpmudev_drive_files_cache_ttl` filter).

---

#### Upload File

```http
POST /wp-json/wpmudev/v1/drive/upload
Content-Type: multipart/form-data
```

**Request:**
- `file`: The file to upload (multipart)

**Validation:**
- Max size: 50 MB (filterable via `wpmudev_drive_max_upload_size`)
- Allowed types: Configurable whitelist (filterable via `wpmudev_drive_allowed_mime_types`)

**Response:**
```json
{
  "success": true,
  "file": {
    "id": "1abc...",
    "name": "uploaded-file.pdf",
    "mimeType": "application/pdf",
    "webViewLink": "https://drive.google.com/..."
  }
}
```

---

#### Download File

```http
GET /wp-json/wpmudev/v1/drive/download?file_id=...
```

**Response:**
```json
{
  "success": true,
  "content": "base64-encoded-content",
  "filename": "document.pdf",
  "mimeType": "application/pdf"
}
```

---

#### Create Folder

```http
POST /wp-json/wpmudev/v1/drive/create-folder
```

**Request Body:**
```json
{
  "name": "New Folder"
}
```

**Response:**
```json
{
  "success": true,
  "folder": {
    "id": "1abc...",
    "name": "New Folder",
    "mimeType": "application/vnd.google-apps.folder",
    "webViewLink": "https://drive.google.com/..."
  }
}
```

---

#### Disconnect

```http
POST /wp-json/wpmudev/v1/drive/disconnect
```

**Response:**
```json
{
  "success": true,
  "message": "Successfully disconnected from Google Drive."
}
```

---

## React Admin Interface

### Location

```
src/googledrive-page/main.jsx
```

### State Management

The interface manages three primary states:

1. **No Credentials** - Show credentials form
2. **Has Credentials, Not Authenticated** - Show auth button
3. **Authenticated** - Show file browser and upload

```jsx
{!hasCredentials ? (
    <CredentialsForm />
) : !isAuthenticated ? (
    <AuthenticationPanel />
) : (
    <FileBrowser />
)}
```

### Internationalization

All user-facing text uses WordPress i18n:

```jsx
import { __ } from '@wordpress/i18n';

<Button>{__('Upload to Drive', 'wpmudev-plugin-test')}</Button>
```

### Components

| Component | Purpose |
|-----------|---------|
| `CredentialsForm` | Client ID/Secret input |
| `AuthenticationPanel` | OAuth initiation |
| `FileUpload` | Drag-and-drop upload |
| `FolderCreate` | New folder creation |
| `FileList` | File browser with pagination |

---

## Security Measures

### Credential Encryption

Client secrets are encrypted using AES-256-CBC:

```php
$key = hash( 'sha256', wp_salt( 'secure_auth' ) );
$iv  = substr( hash( 'sha256', wp_salt( 'auth' ) ), 0, 16 );
$encrypted = openssl_encrypt( $secret, 'aes-256-cbc', $key, 0, $iv );
```

### Rate Limiting

Sensitive endpoints are rate-limited:

- **Limit:** 10 requests per 60 seconds per user
- **Response:** HTTP 429 with retry information

### Audit Logging

Security events are logged:

```php
$this->log_audit_event( 'credentials_saved', $user_id, [
    'client_id_prefix' => substr( $client_id, 0, 10 ) . '...',
]);
```

Logged events:
- `credentials_saved` / `credentials_updated`
- `auth_success`
- `rate_limit_exceeded`

### Permission Checks

All endpoints verify `manage_options` capability:

```php
'permission_callback' => function() {
    return current_user_can( 'manage_options' );
}
```

---

## Error Handling

### Error Response Format

```json
{
  "code": "error_code",
  "message": "Human-readable error message",
  "data": {
    "status": 400
  }
}
```

### Common Error Codes

| Code | HTTP Status | Description |
|------|-------------|-------------|
| `invalid_credentials` | 400 | Missing or invalid credentials |
| `no_access_token` | 401 | Not authenticated |
| `rate_limit_exceeded` | 429 | Too many requests |
| `upload_failed` | 500 | File upload error |
| `download_failed` | 500 | File download error |

### Google API Errors

Google API errors are caught and formatted:

```php
catch ( \Google_Service_Exception $e ) {
    $errors = $e->getErrors();
    // Extract and return user-friendly message
}
```

---

## Filters & Hooks

### Filters

```php
// Customize max upload size (default: 50 MB)
add_filter( 'wpmudev_drive_max_upload_size', function( $size ) {
    return 100 * 1024 * 1024; // 100 MB
});

// Customize allowed MIME types
add_filter( 'wpmudev_drive_allowed_mime_types', function( $types ) {
    $types['psd'] = 'image/vnd.adobe.photoshop';
    return $types;
});

// Customize cache TTL (default: 30 seconds)
add_filter( 'wpmudev_drive_files_cache_ttl', function( $ttl ) {
    return 60; // 1 minute
});
```

### Actions

```php
// Cleanup transients hourly
add_action( 'wpmudev_drive_cleanup_transients', [ $this, 'cleanup_expired_state_transients' ] );
```
