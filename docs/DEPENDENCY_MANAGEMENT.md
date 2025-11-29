# Dependency Management Documentation

## Overview

This document describes the dependency management strategy implemented to prevent conflicts with other WordPress plugins and themes that may include the same third-party libraries.

---

## Problem Statement

WordPress plugins that include third-party libraries via Composer can conflict with other plugins that include different versions of the same libraries.

### Example Conflict Scenario

```
Plugin A: google/apiclient v2.12.0
Plugin B: google/apiclient v2.15.0
```

When both plugins are active:
1. PHP autoloader loads whichever version is registered first
2. The other plugin receives an incompatible version
3. Result: Fatal errors, unexpected behavior, or silent failures

### Why This Matters

- **Google API Client** is a common dependency
- Many plugins use it for Drive, Sheets, Analytics, etc.
- Version differences can cause method signature mismatches
- WordPress doesn't have built-in dependency isolation

---

## Solution: Namespace Prefixing with PHP-Scoper

We use [PHP-Scoper](https://github.com/humbug/php-scoper) to prefix all vendor namespaces with our plugin's namespace, ensuring complete isolation.

### How It Works

```
Before Prefixing:
├── vendor/
│   └── google/apiclient/
│       └── src/
│           └── Google/
│               └── Client.php  → namespace Google;

After Prefixing:
├── vendor-prefixed/
│   └── google/apiclient/
│       └── src/
│           └── Google/
│               └── Client.php  → namespace WPMUDEV\PluginTest\Vendor\Google;
```

### Prefix Used

```
WPMUDEV\PluginTest\Vendor\
```

### Class Mapping Examples

| Original Class | Prefixed Class |
|----------------|----------------|
| `Google_Client` | `WPMUDEV\PluginTest\Vendor\Google_Client` |
| `Google\Service\Drive` | `WPMUDEV\PluginTest\Vendor\Google\Service\Drive` |
| `GuzzleHttp\Client` | `WPMUDEV\PluginTest\Vendor\GuzzleHttp\Client` |
| `Psr\Log\LoggerInterface` | `WPMUDEV\PluginTest\Vendor\Psr\Log\LoggerInterface` |

---

## Configuration

### scoper.inc.php

The configuration file defines prefixing rules:

```php
<?php
return [
    // Prefix for all namespaced classes
    'prefix' => 'WPMUDEV\\PluginTest\\Vendor',

    // Files to process
    'finders' => [
        Finder::create()
            ->files()
            ->in('vendor'),
    ],

    // Namespaces to exclude from prefixing
    'exclude-namespaces' => [
        'WP_CLI',
        'WPMUDEV\\PluginTest',
    ],

    // Classes to exclude
    'exclude-classes' => [
        'WP_Error',
        'WP_REST_Request',
        'WP_REST_Response',
        'wpdb',
    ],

    // Constants to exclude
    'exclude-constants' => [
        'ABSPATH',
        'WPINC',
    ],
];
```

### Exclusions

The following are **NOT** prefixed:

| Category | Examples | Reason |
|----------|----------|--------|
| WordPress Core | `WP_Error`, `WP_Query` | Global WordPress classes |
| WP-CLI | `WP_CLI`, `WP_CLI_Command` | CLI framework classes |
| Our Plugin | `WPMUDEV\PluginTest\*` | Our own namespace |
| PHP Built-ins | `Exception`, `DateTime` | Native PHP classes |
| WordPress Constants | `ABSPATH`, `WPINC` | Global constants |

---

## Build Process

### Prerequisites

```bash
composer install  # Includes PHP-Scoper as dev dependency
```

### Running the Prefixer

```bash
composer run prefix-dependencies
```

This executes:
1. `php-scoper add-prefix --output-dir=vendor-prefixed --force`
2. `composer dump-autoload --working-dir=vendor-prefixed --classmap-authoritative`

### Output

Creates `vendor-prefixed/` directory with:
- All vendor files with prefixed namespaces
- Updated autoloader for prefixed classes
- Preserved directory structure

### Production Build Integration

The Gruntfile.js integrates prefixing into the build:

```javascript
// In Gruntfile.js build task
shell: {
    prefixDeps: {
        command: 'composer run prefix-dependencies'
    }
}
```

---

## Usage in Code

### Before (Without Prefixing)

```php
use Google_Client;
use Google_Service_Drive;

$client = new Google_Client();
$drive = new Google_Service_Drive($client);
```

### After (With Prefixing)

```php
use WPMUDEV\PluginTest\Vendor\Google_Client;
use WPMUDEV\PluginTest\Vendor\Google_Service_Drive;

$client = new Google_Client();
$drive = new Google_Service_Drive($client);
```

### Autoloading

The prefixed autoloader is loaded in the main plugin file:

```php
// Load prefixed vendor autoloader
if (file_exists(__DIR__ . '/vendor-prefixed/autoload.php')) {
    require_once __DIR__ . '/vendor-prefixed/autoload.php';
} else {
    // Fallback to regular vendor (development)
    require_once __DIR__ . '/vendor/autoload.php';
}
```

---

## Benefits

### 1. No Conflicts

Our Google API Client cannot conflict with other plugins' versions:

```php
// Our plugin uses:
WPMUDEV\PluginTest\Vendor\Google_Client  // v2.15.0

// Another plugin uses:
Google_Client  // v2.12.0

// Both work independently!
```

### 2. Version Independence

We can use any version without worrying about compatibility:

```json
{
    "require": {
        "google/apiclient": "^2.15"
    }
}
```

### 3. Predictable Behavior

Our code always uses our bundled dependencies, regardless of what other plugins load.

### 4. WordPress Ecosystem Friendly

Follows best practices recommended by WordPress plugin guidelines.

---

## Alternative Approaches Considered

| Approach | Pros | Cons | Decision |
|----------|------|------|----------|
| **PHP-Scoper** | Automated, comprehensive | Build step required | ✅ Selected |
| **Mozart** | Similar to Scoper | Less maintained | ❌ Rejected |
| **Strauss** | Newer, active | Less ecosystem support | ❌ Rejected |
| **Manual Prefixing** | No tools needed | Error-prone, tedious | ❌ Rejected |
| **WordPress HTTP API** | No dependencies | Requires rewrite | ❌ Rejected |

---

## Verification

### Check Prefixed Classes

```bash
# Verify Google classes are prefixed
grep -r "namespace WPMUDEV\\\\PluginTest\\\\Vendor\\\\Google" vendor-prefixed/
```

### Verify No Unprefixed Classes

```bash
# Should return no results
grep -r "^namespace Google\\\\" vendor-prefixed/ | grep -v "WPMUDEV"
```

### Test Isolation

```bash
# Install a conflicting plugin and verify both work
wp plugin activate other-google-plugin
wp plugin activate wpmudev-plugin-test

# Both should function correctly
```

---

## Maintenance

### Updating Dependencies

When updating Composer dependencies:

```bash
# 1. Update composer.json
composer update google/apiclient

# 2. Re-run prefixer
composer run prefix-dependencies

# 3. Test thoroughly
./vendor/bin/phpunit tests/
```

### Adding New Dependencies

1. Add to `composer.json`
2. Run `composer install`
3. Update `scoper.inc.php` if needed (exclusions)
4. Run `composer run prefix-dependencies`
5. Update code to use prefixed namespace

### Troubleshooting

#### "Class not found" errors

Check autoloader is loading prefixed version:
```php
var_dump(class_exists('WPMUDEV\PluginTest\Vendor\Google_Client'));
```

#### Conflicts still occurring

Verify `vendor-prefixed/` is being used, not `vendor/`:
```php
var_dump(get_class(new Google_Client()));
// Should show: WPMUDEV\PluginTest\Vendor\Google_Client
```

---

## File Structure

```
wpmudev-plugin-test/
├── vendor/                    # Development dependencies (not shipped)
├── vendor-prefixed/           # Prefixed dependencies (shipped)
│   ├── autoload.php          # Prefixed autoloader
│   ├── composer/             # Composer autoload files
│   └── google/               # Prefixed Google library
├── scoper.inc.php            # PHP-Scoper configuration
└── composer.json             # Dependency definitions
```

---

## Performance Considerations

| Aspect | Impact |
|--------|--------|
| File Size | ~20% larger due to longer namespaces |
| Build Time | +30 seconds for prefixing step |
| Runtime | No measurable difference |
| Memory | No measurable difference |

---

## References

- [PHP-Scoper Documentation](https://github.com/humbug/php-scoper)
- [WordPress Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/)
- [Composer Autoloading](https://getcomposer.org/doc/01-basic-usage.md#autoloading)
