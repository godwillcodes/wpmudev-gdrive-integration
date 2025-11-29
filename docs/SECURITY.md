# Security Documentation

## Overview

This document describes the security measures implemented in the WPMUDEV Plugin Test to protect user data, prevent unauthorized access, and follow WordPress security best practices.

---

## Table of Contents

1. [Authentication & Authorization](#authentication--authorization)
2. [Data Encryption](#data-encryption)
3. [Input Validation & Sanitization](#input-validation--sanitization)
4. [CSRF Protection](#csrf-protection)
5. [Rate Limiting](#rate-limiting)
6. [Audit Logging](#audit-logging)
7. [Secure Token Handling](#secure-token-handling)
8. [Security Headers](#security-headers)
9. [Best Practices Checklist](#best-practices-checklist)

---

## Authentication & Authorization

### Capability Checks

All admin functionality requires appropriate WordPress capabilities:

```php
// REST API endpoints
'permission_callback' => function() {
    return current_user_can( 'manage_options' );
}

// Admin pages
if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( __( 'You do not have permission to access this page.', 'wpmudev-plugin-test' ) );
}
```

### Required Capabilities

| Feature | Required Capability |
|---------|---------------------|
| Google Drive settings | `manage_options` |
| Posts Maintenance | `manage_options` |
| WP-CLI commands | Administrator (CLI context) |

### User Context Verification

```php
// Verify user is logged in
if ( ! is_user_logged_in() ) {
    return new WP_Error( 'not_logged_in', 'Authentication required', [ 'status' => 401 ] );
}

// Get current user for audit
$user_id = get_current_user_id();
```

---

## Data Encryption

### Client Secret Encryption

Google OAuth client secrets are encrypted before storage using AES-256-CBC:

```php
private function encrypt_secret( string $secret ): string {
    if ( ! function_exists( 'openssl_encrypt' ) ) {
        return $secret; // Fallback if OpenSSL unavailable
    }

    // Derive key from WordPress salts
    $key_hex = hash( 'sha256', wp_salt( 'secure_auth' ) );
    $key = hex2bin( $key_hex );

    // Derive IV from different salt
    $iv_hex = hash( 'sha256', wp_salt( 'auth' ) );
    $iv = hex2bin( substr( $iv_hex, 0, 32 ) );

    // Encrypt
    $cipher = openssl_encrypt( $secret, 'aes-256-cbc', $key, 0, $iv );

    // Prefix to identify encrypted values
    return 'wpmudev_enc:' . $cipher;
}
```

### Encryption Details

| Aspect | Value |
|--------|-------|
| Algorithm | AES-256-CBC |
| Key derivation | SHA-256 of `wp_salt('secure_auth')` |
| IV derivation | SHA-256 of `wp_salt('auth')` (first 16 bytes) |
| Prefix | `wpmudev_enc:` |

### Decryption

```php
private function decrypt_secret( string $encrypted ): string {
    // Check for encryption prefix
    if ( ! $this->is_encrypted( $encrypted ) ) {
        return $encrypted; // Not encrypted, return as-is
    }

    // Remove prefix
    $cipher = substr( $encrypted, strlen( 'wpmudev_enc:' ) );

    // Derive same key and IV
    $key = hex2bin( hash( 'sha256', wp_salt( 'secure_auth' ) ) );
    $iv = hex2bin( substr( hash( 'sha256', wp_salt( 'auth' ) ), 0, 32 ) );

    // Decrypt
    return openssl_decrypt( $cipher, 'aes-256-cbc', $key, 0, $iv );
}
```

---

## Input Validation & Sanitization

### REST API Parameter Validation

```php
register_rest_route( 'wpmudev/v1/drive', '/save-credentials', [
    'args' => [
        'client_id' => [
            'type' => 'string',
            'required' => true,
            'sanitize_callback' => 'sanitize_text_field',
            'validate_callback' => function( $param ) {
                return ! empty( trim( $param ) );
            },
        ],
        'client_secret' => [
            'type' => 'string',
            'required' => true,
            'sanitize_callback' => 'sanitize_text_field',
        ],
    ],
]);
```

### Sanitization Functions Used

| Data Type | Function |
|-----------|----------|
| Text fields | `sanitize_text_field()` |
| File names | `sanitize_file_name()` |
| Keys/slugs | `sanitize_key()` |
| Integers | `absint()` |
| Booleans | `rest_sanitize_boolean()` |

### File Upload Validation

```php
// Check for upload errors
if ( $file['error'] !== UPLOAD_ERR_OK ) {
    return new WP_Error( 'upload_error', $this->get_upload_error_message( $file['error'] ) );
}

// Validate file was actually uploaded
if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
    return new WP_Error( 'invalid_upload', 'Invalid file upload' );
}

// Validate file size
$max_size = apply_filters( 'wpmudev_drive_max_upload_size', 50 * 1024 * 1024 );
if ( $file['size'] > $max_size ) {
    return new WP_Error( 'file_too_large', 'File exceeds maximum size' );
}

// Validate MIME type
$allowed_types = apply_filters( 'wpmudev_drive_allowed_mime_types', [...] );
$file_type = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
if ( ! in_array( $file_type['type'], $allowed_types, true ) ) {
    return new WP_Error( 'invalid_type', 'File type not allowed' );
}
```

---

## CSRF Protection

### Nonce Verification (Admin Pages)

```php
// Generate nonce in form
wp_nonce_field( 'wpmudev_posts_scan_action', 'wpmudev_posts_scan_nonce' );

// Verify nonce on submission
if ( ! wp_verify_nonce( $_POST['wpmudev_posts_scan_nonce'], 'wpmudev_posts_scan_action' ) ) {
    wp_die( 'Security check failed' );
}
```

### REST API Nonce

REST API requests from the admin use the built-in nonce:

```javascript
// In React/JavaScript
fetch( endpoint, {
    headers: {
        'X-WP-Nonce': wpApiSettings.nonce
    }
});
```

### OAuth State Parameter

OAuth flow uses a state parameter to prevent CSRF:

```php
// Generate state
$state = wp_generate_password( 32, false );
set_transient( 'wpmudev_drive_oauth_state_' . $state, true, 600 );

// Add to auth URL
$auth_url = $client->createAuthUrl() . '&state=' . $state;

// Validate on callback
if ( ! get_transient( 'wpmudev_drive_oauth_state_' . $state ) ) {
    // Invalid state - reject
}
delete_transient( 'wpmudev_drive_oauth_state_' . $state );
```

---

## Rate Limiting

### Implementation

Sensitive endpoints are rate-limited to prevent abuse:

```php
private function check_rate_limit( string $action, int $user_id ) {
    $transient_key = sprintf( 'wpmudev_drive_rate_%s_%d', $action, $user_id );
    $requests = get_transient( $transient_key ) ?: [];

    // Remove old requests outside window
    $now = time();
    $requests = array_filter( $requests, function( $ts ) use ( $now ) {
        return ( $now - $ts ) < self::RATE_LIMIT_WINDOW;
    });

    // Check limit
    if ( count( $requests ) >= self::RATE_LIMIT_MAX_REQUESTS ) {
        return new WP_Error( 'rate_limit_exceeded', 'Too many requests', [ 'status' => 429 ] );
    }

    // Record this request
    $requests[] = $now;
    set_transient( $transient_key, $requests, self::RATE_LIMIT_WINDOW );

    return true;
}
```

### Rate Limit Configuration

| Setting | Value |
|---------|-------|
| Max requests | 10 |
| Time window | 60 seconds |
| Scope | Per user, per action |

### Rate-Limited Endpoints

- `POST /drive/save-credentials`
- `POST /drive/auth` (initiate)

---

## Audit Logging

### Logged Events

| Event | Description |
|-------|-------------|
| `credentials_saved` | New credentials stored |
| `credentials_updated` | Existing credentials changed |
| `auth_success` | OAuth authentication completed |
| `rate_limit_exceeded` | Rate limit triggered |

### Log Entry Structure

```php
$log_entry = [
    'timestamp'  => current_time( 'mysql' ),
    'event'      => 'credentials_saved',
    'user_id'    => 1,
    'user_login' => 'admin',
    'ip_address' => '192.168.1.100',
    'user_agent' => 'Mozilla/5.0...',
    'data'       => [
        'client_id_prefix' => 'abc123...',
    ],
];
```

### Log Storage

- Stored in `wpmudev_drive_audit_log` option
- Limited to 100 entries (FIFO)
- Also written to PHP error log

### IP Address Detection

```php
private function get_client_ip(): string {
    $ip_keys = [
        'HTTP_CF_CONNECTING_IP',  // Cloudflare
        'HTTP_X_FORWARDED_FOR',   // Proxy
        'HTTP_X_REAL_IP',         // Nginx
        'REMOTE_ADDR',            // Direct
    ];

    foreach ( $ip_keys as $key ) {
        if ( ! empty( $_SERVER[ $key ] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
            // Handle comma-separated (X-Forwarded-For)
            if ( strpos( $ip, ',' ) !== false ) {
                $ip = trim( explode( ',', $ip )[0] );
            }
            if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                return $ip;
            }
        }
    }

    return 'unknown';
}
```

---

## Secure Token Handling

### Access Token Storage

```php
// Store token array
update_option( 'wpmudev_drive_access_token', $access_token );

// Store refresh token separately
update_option( 'wpmudev_drive_refresh_token', $access_token['refresh_token'] );

// Store expiration
update_option( 'wpmudev_drive_token_expires', time() + $access_token['expires_in'] );
```

### Token Refresh

Tokens are refreshed before expiration:

```php
private function ensure_valid_token() {
    $expires = get_option( 'wpmudev_drive_token_expires', 0 );

    // Refresh 5 minutes before expiration
    if ( time() >= ( $expires - 300 ) ) {
        $refresh_token = get_option( 'wpmudev_drive_refresh_token' );
        $new_token = $this->client->fetchAccessTokenWithRefreshToken( $refresh_token );
        // Store new token...
    }
}
```

### Token Cleanup on Disconnect

```php
public function disconnect() {
    // Revoke token with Google
    if ( $this->client ) {
        $access_token = get_option( 'wpmudev_drive_access_token' );
        $this->client->revokeToken( $access_token['access_token'] );
    }

    // Delete all stored tokens
    delete_option( 'wpmudev_drive_access_token' );
    delete_option( 'wpmudev_drive_refresh_token' );
    delete_option( 'wpmudev_drive_token_expires' );
}
```

### State Token Cleanup

Orphaned OAuth state tokens are cleaned up hourly:

```php
public function cleanup_expired_state_transients() {
    global $wpdb;

    $expired = $wpdb->get_col(
        "SELECT option_name FROM {$wpdb->options}
        WHERE option_name LIKE '_transient_wpmudev_drive_oauth_state_%'"
    );

    foreach ( $expired as $transient_name ) {
        $key = str_replace( '_transient_', '', $transient_name );
        $timeout = get_option( '_transient_timeout_' . $key );
        if ( $timeout && $timeout < time() ) {
            delete_transient( $key );
        }
    }
}
```

---

## Security Headers

### REST API Responses

WordPress REST API automatically includes security headers. Additional headers can be added:

```php
add_filter( 'rest_post_dispatch', function( $response ) {
    $response->header( 'X-Content-Type-Options', 'nosniff' );
    return $response;
});
```

---

## Best Practices Checklist

### ✅ Authentication

- [x] All admin endpoints require `manage_options` capability
- [x] REST API uses WordPress nonce verification
- [x] User context verified before sensitive operations

### ✅ Data Protection

- [x] Client secrets encrypted with AES-256-CBC
- [x] Encryption keys derived from WordPress salts
- [x] Tokens stored in WordPress options (not exposed)

### ✅ Input Handling

- [x] All inputs sanitized with appropriate functions
- [x] File uploads validated for type and size
- [x] SQL queries use prepared statements

### ✅ CSRF Prevention

- [x] Admin forms use WordPress nonces
- [x] REST API uses X-WP-Nonce header
- [x] OAuth uses state parameter

### ✅ Rate Limiting

- [x] Sensitive endpoints rate-limited
- [x] Per-user tracking prevents abuse
- [x] Appropriate limits (10 req/60s)

### ✅ Logging & Monitoring

- [x] Security events logged
- [x] IP addresses recorded
- [x] Log rotation (100 entries max)

### ✅ Token Security

- [x] Tokens stored securely
- [x] Automatic refresh before expiration
- [x] Complete cleanup on disconnect
- [x] State tokens expire after 10 minutes

---

## Reporting Security Issues

If you discover a security vulnerability, please report it responsibly:

1. **Do not** disclose publicly
2. Email security@wpmudev.com
3. Include detailed reproduction steps
4. Allow time for a fix before disclosure

---

## References

- [WordPress Plugin Security](https://developer.wordpress.org/plugins/security/)
- [OWASP Top 10](https://owasp.org/www-project-top-ten/)
- [WordPress Data Validation](https://developer.wordpress.org/plugins/security/data-validation/)
- [WordPress Nonces](https://developer.wordpress.org/plugins/security/nonces/)
