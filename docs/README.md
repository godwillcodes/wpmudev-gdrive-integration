# WPMUDEV Plugin Test Documentation

This directory contains comprehensive documentation for the WPMUDEV Plugin Test plugin.

## Documentation Index

| Document | Description |
|----------|-------------|
| [Package Optimization](./PACKAGE_OPTIMIZATION.md) | Build process and size reduction strategies |
| [Google Drive Integration](./GOOGLE_DRIVE_INTEGRATION.md) | OAuth flow, API endpoints, and React UI |
| [Posts Maintenance](./POSTS_MAINTENANCE.md) | Admin page, background processing, and scheduling |
| [WP-CLI Commands](./WP_CLI_COMMANDS.md) | Command-line interface documentation |
| [Dependency Management](./DEPENDENCY_MANAGEMENT.md) | Namespace isolation and conflict prevention |
| [Testing Guide](./TESTING_GUIDE.md) | Unit tests and testing procedures |
| [Security](./SECURITY.md) | Security measures and best practices |
| [Assumptions](./ASSUMPTIONS.md) | Design decisions and calculation methodologies |

## Quick Start

### Installation

1. Upload the plugin to `/wp-content/plugins/wpmudev-plugin-test/`
2. Run `composer install` in the plugin directory
3. Activate the plugin through the WordPress admin

### Building for Production

```bash
npm install
npm run build
```

This creates an optimized zip file in the `build/` directory.

### Running Tests

```bash
./vendor/bin/phpunit tests/
```

## Requirements

- PHP 7.4 or higher
- WordPress 5.8 or higher
- Composer 2.x
- Node.js 16.x or higher (for development)

## Support

For issues or questions, please refer to the individual documentation files or contact WPMUDEV support.
