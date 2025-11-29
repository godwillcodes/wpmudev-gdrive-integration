# Package Optimization

## Overview

This document describes the package optimization strategies implemented to reduce the final plugin zip file size while maintaining all required functionality.

## Problem Statement

The development environment contains large dependencies that should not be included in production:

| Component | Development Size | Purpose |
|-----------|-----------------|---------|
| `node_modules/` | **428 MB** | NPM packages for build tools |
| `vendor/` | **144 MB** | Composer packages (including dev) |
| `src/` | 184 KB | Source JSX/SCSS files |
| `tests/` | 52 KB | Unit test files |

**Total development footprint: ~572 MB**

Including these in the production build would result in:
- Extremely slow downloads and installations
- Unnecessary server storage usage
- Security exposure of development files
- Potential conflicts with other plugins

---

## Optimization Results

### Actual Size Comparison

| Component | Development | Production | Reduction |
|-----------|-------------|------------|-----------|
| node_modules | 428 MB | 0 MB (excluded) | **100%** |
| vendor (with dev) | 144 MB | ~31 MB (no-dev) | **78%** |
| Source files (src/) | 184 KB | 0 KB (excluded) | **100%** |
| Test files | 52 KB | 0 KB (excluded) | **100%** |
| **Final ZIP** | N/A | **~33 MB** | — |

### Compiled Asset Sizes

| File | Size | Description |
|------|------|-------------|
| `drivetestpage.min.js` | 127 KB | Google Drive React app |
| `posts-maintenance.min.js` | 25 KB | Posts Maintenance React app |
| `posts-maintenance-history.min.js` | 15 KB | Scan History React app |
| `drivetestpage.min.css` | 1.1 MB | Shared UI + custom styles |
| `posts-maintenance.min.css` | 20 KB | Posts Maintenance styles |
| `posts-maintenance-history.min.css` | 20 KB | History page styles |

*Note: The large CSS file includes the WPMUDEV Shared UI library.*

---

## Optimization Strategies

### 1. Selective File Inclusion (Gruntfile.js)

Only production-necessary files are copied to the build:

```javascript
const copyFiles = [
    'app/**',           // PHP application code
    'core/**',          // Core PHP classes  
    'assets/**',        // Compiled CSS/JS
    'languages/**',     // Translation files
    'uninstall.php',    // Cleanup on uninstall
    'wpmudev-plugin-test.php',  // Main plugin file
    'composer.json',    // For dependency installation
    'composer.lock',    // Lock file for reproducibility
    'changelog.txt',
    'README.md',
];
```

**Explicitly Excluded:**
- `node_modules/` — NPM packages (build-time only)
- `src/` — Source JSX/SCSS files (compiled versions in assets/)
- `tests/` — Unit tests (not needed in production)
- `docs/` — Documentation (optional exclusion)
- Config files: `Gruntfile.js`, `webpack.config.js`, `phpcs.ruleset.xml`, etc.
- Source maps: `*.map`

### 2. Production Composer Dependencies

The build runs Composer with production-only flags:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
```

| Flag | Effect |
|------|--------|
| `--no-dev` | Excludes phpcs, phpunit, php-scoper (~113 MB saved) |
| `--prefer-dist` | Downloads dist packages (no .git history) |
| `--optimize-autoloader` | Generates optimized class maps |
| `--no-interaction` | Non-interactive for CI/CD |

### 3. Google API Client Cleanup

The `composer.json` includes automatic cleanup:

```json
{
  "scripts": {
    "post-install-cmd": ["Google_Task_Composer::cleanup"],
    "post-update-cmd": ["Google_Task_Composer::cleanup"]
  }
}
```

This removes unused Google API service files (Calendar, Sheets, etc.), keeping only Drive API.

### 4. Webpack Production Build

JavaScript and CSS optimizations:

| Optimization | Tool | Effect |
|--------------|------|--------|
| Minification | TerserPlugin | Removes whitespace, shortens variables |
| Tree shaking | Webpack | Eliminates unused code |
| CSS extraction | MiniCssExtractPlugin | Separate CSS bundles |
| Comment removal | TerserPlugin | Strips all comments |

```javascript
// webpack.config.js
optimization: {
    minimize: true,
    minimizer: [
        new TerserPlugin({
            terserOptions: {
                format: { comments: false },
            },
            extractComments: false,
        }),
    ],
}
```

### 5. Pre-Build Cleanup

Stale assets are removed before each build:

```javascript
clean: {
    assets: {
        src: [
            'assets/css/drivetestpage*.css',
            'assets/js/drivetestpage*.js',
            'assets/css/posts-maintenance*.css',
            'assets/js/posts-maintenance*.js',
        ]
    }
}
```

---

## Build Process

### Development Build

```bash
npm run watch
```

Compiles assets with source maps for debugging.

### Production Build

```bash
npm run build
```

Executes the following sequence:

1. **`grunt preBuildClean`** — Remove stale compiled files
2. **`npm run compile`** — Webpack production build
3. **`npm run translate`** — Generate POT translation file
4. **`grunt build`** — Copy files, install prod deps, create ZIP

### Build Output

```
build/
└── wpmudev-plugin-test-1.0.0.zip  (33 MB, 26,876 files)
```

---

## Verification

### Check Build Contents

```bash
# List ZIP contents
unzip -l build/wpmudev-plugin-test-*.zip | head -50

# Verify no dev files included
unzip -l build/wpmudev-plugin-test-*.zip | grep -E "(node_modules|\.map|phpunit|/tests/)"
# Should return no results

# Check vendor is production-only
unzip -l build/wpmudev-plugin-test-*.zip | grep "vendor/" | wc -l
# Should be significantly fewer files than dev vendor
```

### Verify Excluded Files

```bash
# These should NOT appear in the ZIP:
unzip -l build/wpmudev-plugin-test-*.zip | grep -E "^.*\.(map|log|md)$"
unzip -l build/wpmudev-plugin-test-*.zip | grep "Gruntfile"
unzip -l build/wpmudev-plugin-test-*.zip | grep "webpack.config"
unzip -l build/wpmudev-plugin-test-*.zip | grep "phpcs.ruleset"
```

### Check Final Size

```bash
ls -lh build/wpmudev-plugin-test-*.zip
# Expected: ~33 MB (vs ~572 MB development)
```

---

## Configuration Files

| File | Purpose |
|------|---------|
| `Gruntfile.js` | Build task orchestration |
| `webpack.config.js` | Asset compilation and optimization |
| `composer.json` | PHP dependencies and scripts |
| `.distignore` | Distribution ignore patterns |

---

## Further Optimization Opportunities

### Current State
- ZIP size: **33 MB**
- Vendor: **~31 MB** (mostly Google API Client)

### Potential Improvements

1. **Namespace Prefixing with PHP-Scoper**
   - Already configured in `scoper.inc.php`
   - Run `composer run prefix-dependencies` to create isolated vendor
   - See [Dependency Management](./DEPENDENCY_MANAGEMENT.md)

2. **CSS Optimization**
   - `drivetestpage.min.css` is 1.1 MB (includes full Shared UI)
   - Consider: Import only needed Shared UI components
   - Potential savings: ~500 KB

3. **Selective Google API Services**
   - Current cleanup removes most unused services
   - Verify only Drive API files remain

4. **Image Optimization**
   - Compress any bundled images
   - Use WebP format where supported

---

## Troubleshooting

### Build Fails with "Out of Memory"

```bash
NODE_OPTIONS=--max_old_space_size=4096 npm run build
```

### Vendor Directory Still Large

Ensure cleanup script runs:
```bash
cd build/wpmudev-plugin-test
composer run-script post-install-cmd
```

### Source Maps in Production

Verify `webpack.config.js` doesn't have `devtool: 'source-map'` in production mode.

### ZIP Contains Unexpected Files

Check `Gruntfile.js` `copyFiles` array and ensure patterns are correct.
