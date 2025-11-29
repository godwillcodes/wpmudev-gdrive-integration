<?php
/**
 * PHP-Scoper configuration file.
 *
 * This configuration prefixes all vendor namespaces to prevent conflicts
 * with other WordPress plugins that may include different versions of
 * the same dependencies (e.g., Google API Client).
 *
 * @link https://github.com/humbug/php-scoper
 * @package WPMUDEV\PluginTest
 */

declare(strict_types=1);

use Isolated\Symfony\Component\Finder\Finder;

/**
 * Vendor prefix for all scoped dependencies.
 * All vendor classes will be prefixed with this namespace.
 */
$prefix = 'WPMUDEV\\PluginTest\\Vendor';

return [
    // The prefix to apply to all namespaced classes.
    'prefix' => $prefix,

    // Paths to scope (vendor directory).
    'finders' => [
        Finder::create()
            ->files()
            ->ignoreVCS(true)
            ->notName('/LICENSE|.*\\.md|.*\\.dist|Makefile/')
            ->exclude([
                'doc',
                'docs',
                'test',
                'tests',
                'Test',
                'Tests',
                'stub',
                'stubs',
                'Stub',
                'Stubs',
            ])
            ->in('vendor'),
        Finder::create()
            ->append([
                'composer.json',
            ]),
    ],

    // Files to exclude from scoping.
    'exclude-files' => [
        // WordPress files should not be scoped.
        'vendor/composer/installed.php',
    ],

    // Namespaces to exclude from scoping (WordPress core, etc.).
    'exclude-namespaces' => [
        // WordPress namespaces.
        'WP_CLI',
        'WP_CLI\\',

        // Our own namespace (don't scope ourselves).
        'WPMUDEV\\PluginTest',
        'WPMUDEV\\PluginTest\\',
    ],

    // Classes to exclude from scoping.
    'exclude-classes' => [
        // WordPress core classes.
        'WP_Error',
        'WP_REST_Request',
        'WP_REST_Response',
        'WP_REST_Server',
        'WP_Query',
        'WP_Post',
        'WP_User',
        'wpdb',
        'WP_UnitTestCase',
    ],

    // Functions to exclude from scoping.
    'exclude-functions' => [
        // WordPress core functions are global and should not be prefixed.
        // PHP-Scoper handles this automatically for most cases.
    ],

    // Constants to exclude from scoping.
    'exclude-constants' => [
        // WordPress constants.
        'ABSPATH',
        'WPINC',
        'WP_CONTENT_DIR',
        'WP_PLUGIN_DIR',
        'WPMUDEV_PLUGINTEST_DIR',
        'WPMUDEV_PLUGINTEST_VERSION',
        'WPMUDEV_PLUGINTEST_ASSETS_URL',
        'WPMUDEV_PLUGINTEST_SUI_VERSION',
    ],

    // Patchers to modify scoped code.
    'patchers' => [
        /**
         * Patcher for Google API Client autoloader.
         *
         * The Google API Client has its own autoloader that needs to be
         * updated to use the prefixed namespace.
         */
        static function (string $filePath, string $prefix, string $content): string {
            // Fix Google_Task_Composer class reference.
            if (strpos($filePath, 'composer.json') !== false) {
                $content = str_replace(
                    'Google_Task_Composer',
                    $prefix . '\\Google_Task_Composer',
                    $content
                );
            }

            return $content;
        },
    ],
];
