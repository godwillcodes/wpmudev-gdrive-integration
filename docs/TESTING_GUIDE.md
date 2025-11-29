# Testing Guide

## Overview

This document covers the unit testing implementation for the WPMUDEV Plugin Test, including setup, running tests, and writing new tests.

---

## Table of Contents

1. [Test Environment Setup](#test-environment-setup)
2. [Running Tests](#running-tests)
3. [Test Structure](#test-structure)
4. [Test Files](#test-files)
5. [Writing New Tests](#writing-new-tests)
6. [Best Practices](#best-practices)
7. [Continuous Integration](#continuous-integration)

---

## Test Environment Setup

### Prerequisites

- PHP 7.4 or higher
- Composer
- MySQL/MariaDB
- WordPress test library

### Installing WordPress Test Library

```bash
# Run the install script
./bin/install-wp-tests.sh wordpress_test root '' localhost latest
```

Parameters:
1. `wordpress_test` - Test database name
2. `root` - Database user
3. `''` - Database password (empty)
4. `localhost` - Database host
5. `latest` - WordPress version

### Installing Dependencies

```bash
composer install
```

This installs:
- `yoast/phpunit-polyfills` - PHPUnit compatibility
- `wp-coding-standards/wpcs` - WordPress coding standards

---

## Running Tests

### Run All Tests

```bash
./vendor/bin/phpunit
```

### Run Specific Test File

```bash
./vendor/bin/phpunit tests/test-posts-maintenance.php
```

### Run Specific Test Method

```bash
./vendor/bin/phpunit --filter test_start_job_initializes_queue
```

### Run Tests with Coverage

```bash
./vendor/bin/phpunit --coverage-html coverage/
```

### Run Tests Verbosely

```bash
./vendor/bin/phpunit --verbose
```

---

## Test Structure

### Directory Layout

```
tests/
├── bootstrap.php                      # Test bootstrap
├── test-posts-maintenance.php         # Core functionality tests
├── test-posts-maintenance-rest.php    # REST API tests
├── test-posts-maintenance-edge-cases.php  # Edge case tests
├── test-googledrive-security.php      # Security tests
├── test-api-auth.php                  # API authentication tests
└── test-sample.php                    # Sample/template test
```

### Bootstrap File

`tests/bootstrap.php` initializes the test environment:

```php
<?php
// Load WordPress test library
$_tests_dir = getenv('WP_TESTS_DIR') ?: '/tmp/wordpress-tests-lib';
require_once "{$_tests_dir}/includes/functions.php";

// Load plugin
function _manually_load_plugin() {
    require dirname(__DIR__) . '/wpmudev-plugin-test.php';
}
tests_add_filter('muplugins_loaded', '_manually_load_plugin');

// Start test environment
require "{$_tests_dir}/includes/bootstrap.php";
```

---

## Test Files

### test-posts-maintenance.php

Core Posts Maintenance functionality:

| Test | Description |
|------|-------------|
| `test_start_job_initializes_queue` | Verifies job initialization |
| `test_handle_process_event_updates_meta` | Verifies meta updates |
| `test_start_job_rejects_invalid_post_types` | Validates post type input |
| `test_default_post_type_filter_is_honored` | Tests filter integration |
| `test_prevents_concurrent_jobs` | Ensures single job at a time |
| `test_batch_size_filter_limits_processed_chunk` | Tests batch processing |

### test-posts-maintenance-rest.php

REST API endpoint tests:

| Test | Description |
|------|-------------|
| `test_start_endpoint_requires_authentication` | Auth required |
| `test_start_endpoint_requires_admin_capability` | Admin only |
| `test_start_endpoint_allows_admin` | Admin can start |
| `test_status_endpoint_returns_job_info` | Status response |
| `test_status_endpoint_without_active_job` | Empty status |
| `test_settings_endpoint_requires_auth` | Settings auth |
| `test_history_endpoint_returns_data` | History response |

### test-posts-maintenance-edge-cases.php

Edge case coverage:

| Test | Description |
|------|-------------|
| `test_detects_posts_with_blank_content` | Blank content detection |
| `test_detects_posts_missing_featured_image` | Missing image detection |
| `test_health_score_with_zero_posts` | Zero posts handling |
| `test_scan_multiple_post_types` | Multi-type scanning |
| `test_draft_posts_not_scanned` | Draft exclusion |
| `test_private_posts_not_scanned` | Private exclusion |
| `test_scan_history_recorded` | History recording |
| `test_post_meta_timestamp_format` | Timestamp format |
| `test_job_cleanup_after_completion` | Job cleanup |
| `test_large_batch_processing` | Large dataset handling |
| `test_html_content_analysis` | HTML content parsing |
| `test_source_tracking` | Source identification |
| `test_job_id_uniqueness` | Unique job IDs |

### test-googledrive-security.php

Security feature tests:

| Test | Description |
|------|-------------|
| `test_client_secret_is_encrypted` | Encryption verification |
| `test_audit_log_records_events` | Audit logging |
| `test_audit_log_limited_to_100_entries` | Log size limit |
| `test_credentials_require_both_fields` | Input validation |
| `test_oauth_state_validation` | CSRF protection |
| `test_invalid_oauth_state_rejected` | Invalid state rejection |
| `test_oauth_state_expires` | State expiration |
| `test_disconnect_cleans_up_tokens` | Token cleanup |
| `test_rate_limit_transient_structure` | Rate limit storage |
| `test_credentials_are_sanitized` | Input sanitization |
| `test_permission_check_requires_manage_options` | Capability check |

---

## Writing New Tests

### Test Class Template

```php
<?php
/**
 * Test description.
 *
 * @package WPMUDEV_PluginTest
 */

use WPMUDEV\PluginTest\App\Services\Your_Service;

class Test_Your_Feature extends WP_UnitTestCase {

    protected $service;

    public function setUp(): void {
        parent::setUp();
        $this->service = Your_Service::instance();
        $this->reset_state();
    }

    public function tearDown(): void {
        $this->reset_state();
        parent::tearDown();
    }

    private function reset_state() {
        // Clean up options, transients, etc.
        delete_option('your_option');
    }

    public function test_your_feature_works() {
        // Arrange
        $input = 'test';

        // Act
        $result = $this->service->do_something($input);

        // Assert
        $this->assertEquals('expected', $result);
    }
}
```

### Using Factory Methods

```php
// Create posts
$post_id = $this->factory()->post->create([
    'post_status' => 'publish',
    'post_content' => 'Test content',
]);

// Create multiple posts
$post_ids = $this->factory()->post->create_many(5, [
    'post_status' => 'publish',
]);

// Create users
$admin_id = $this->factory()->user->create([
    'role' => 'administrator',
]);

// Create attachments
$attachment_id = $this->factory()->attachment->create_upload_object(
    DIR_TESTDATA . '/images/canola.jpg',
    $post_id
);
```

### Testing REST Endpoints

```php
public function test_endpoint() {
    // Set up REST server
    global $wp_rest_server;
    $this->server = $wp_rest_server = new WP_REST_Server();
    do_action('rest_api_init');

    // Authenticate
    wp_set_current_user($this->admin_id);

    // Make request
    $request = new WP_REST_Request('POST', '/wpmudev/v1/endpoint');
    $request->set_body_params(['key' => 'value']);

    // Get response
    $response = $this->server->dispatch($request);

    // Assert
    $this->assertEquals(200, $response->get_status());
    $data = $response->get_data();
    $this->assertTrue($data['success']);
}
```

### Testing with Filters

```php
public function test_filter_is_applied() {
    // Add filter
    add_filter('wpmudev_posts_scan_batch_size', function() {
        return 10;
    });

    // Run code that uses filter
    $result = $this->service->start_job(['post'], 'manual');

    // Assert filter was applied
    $this->assertEquals(10, $result['batch_size']);

    // Clean up
    remove_all_filters('wpmudev_posts_scan_batch_size');
}
```

### Testing Error Conditions

```php
public function test_returns_error_on_invalid_input() {
    $result = $this->service->start_job(['invalid_type'], 'manual');

    $this->assertInstanceOf(WP_Error::class, $result);
    $this->assertEquals('wpmudev_invalid_post_types', $result->get_error_code());
}
```

---

## Best Practices

### 1. Isolation

Each test should be independent:

```php
public function setUp(): void {
    parent::setUp();
    // Reset state before each test
    delete_option('wpmudev_posts_scan_job');
    wp_clear_scheduled_hook('wpmudev_posts_scan_process');
}
```

### 2. Arrange-Act-Assert Pattern

```php
public function test_example() {
    // Arrange - Set up test data
    $post_id = $this->factory()->post->create();

    // Act - Execute the code being tested
    $result = $this->service->process($post_id);

    // Assert - Verify the result
    $this->assertTrue($result);
}
```

### 3. Descriptive Test Names

```php
// Good
public function test_start_job_rejects_invalid_post_types() {}
public function test_scan_completes_with_zero_posts() {}

// Bad
public function test1() {}
public function testJob() {}
```

### 4. Test Edge Cases

```php
// Zero items
public function test_handles_empty_input() {}

// Maximum items
public function test_handles_large_dataset() {}

// Invalid input
public function test_rejects_malformed_data() {}

// Boundary conditions
public function test_batch_size_at_minimum() {}
public function test_batch_size_at_maximum() {}
```

### 5. Clean Up After Tests

```php
public function tearDown(): void {
    // Remove test data
    delete_option('test_option');
    
    // Clear hooks
    remove_all_filters('test_filter');
    
    // Reset user
    wp_set_current_user(0);
    
    parent::tearDown();
}
```

### 6. Use Data Providers for Multiple Cases

```php
/**
 * @dataProvider post_type_provider
 */
public function test_validates_post_types($input, $expected) {
    $result = $this->service->validate_post_types($input);
    $this->assertEquals($expected, $result);
}

public function post_type_provider() {
    return [
        'valid post' => [['post'], true],
        'valid page' => [['page'], true],
        'invalid type' => [['invalid'], false],
        'empty array' => [[], false],
    ];
}
```

---

## Continuous Integration

### GitHub Actions Example

```yaml
name: Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest

    services:
      mysql:
        image: mysql:5.7
        env:
          MYSQL_ROOT_PASSWORD: root
          MYSQL_DATABASE: wordpress_test
        ports:
          - 3306:3306

    steps:
      - uses: actions/checkout@v3

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '7.4'
          extensions: mysqli

      - name: Install dependencies
        run: composer install

      - name: Install WordPress test suite
        run: bash bin/install-wp-tests.sh wordpress_test root root 127.0.0.1 latest

      - name: Run tests
        run: ./vendor/bin/phpunit
```

### PHPUnit Configuration

`phpunit.xml.dist`:

```xml
<?xml version="1.0"?>
<phpunit
    bootstrap="tests/bootstrap.php"
    colors="true"
    convertErrorsToExceptions="true"
    convertNoticesToExceptions="true"
    convertWarningsToExceptions="true"
>
    <testsuites>
        <testsuite name="WPMUDEV Plugin Test">
            <directory suffix=".php">./tests/</directory>
        </testsuite>
    </testsuites>
    <filter>
        <whitelist>
            <directory suffix=".php">./app/</directory>
            <directory suffix=".php">./core/</directory>
        </whitelist>
    </filter>
</phpunit>
```

---

## Troubleshooting

### "WordPress test library not found"

Run the install script:
```bash
./bin/install-wp-tests.sh wordpress_test root '' localhost latest
```

### "Class WP_UnitTestCase not found"

Check `WP_TESTS_DIR` environment variable:
```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib
```

### Tests interfering with each other

Ensure proper cleanup in `tearDown()`:
```php
public function tearDown(): void {
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key = 'wpmudev_test_last_scan'");
    parent::tearDown();
}
```

### Database connection errors

Verify MySQL is running and credentials are correct:
```bash
mysql -u root -p -e "SHOW DATABASES;"
```
