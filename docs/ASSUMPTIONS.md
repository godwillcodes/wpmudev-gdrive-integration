# Assumptions and Calculation Metrics

## Posts Maintenance - Site Health Score Calculation

### Overview
The Site Health Score is a comprehensive metric that evaluates the overall health of a WordPress site based on post quality indicators. It is calculated as the average of three equally weighted ratios, each representing a different aspect of content quality.

### Calculation Formula

```
Site Health Score = (
    Published Posts Ratio +
    Posts With Content Ratio +
    Posts With Featured Images Ratio
) / 3
```

### Individual Ratios

#### 1. Published Posts Ratio
**Formula:** `published_posts / total_posts`

**Description:** Measures the percentage of posts that are published versus drafts or private posts.

**Calculation:**
- `published_posts`: Count of posts with status 'publish'
- `total_posts`: Total count of all posts (published + draft + private + other statuses)

**Range:** 0.0 to 1.0 (0% to 100%)

---

#### 2. Posts With Content Ratio
**Formula:** `(total_posts - posts_with_blank_content) / total_posts`

**Description:** Measures the percentage of posts that have actual content (not blank).

**Calculation:**
- `posts_with_blank_content`: Count of posts with empty or whitespace-only content
- `total_posts`: Total count of all posts scanned

**Content Detection:**
- Strips HTML tags and whitespace from post content
- Considers a post "blank" if the resulting text is empty or contains only whitespace
- Excludes posts that are intentionally minimal (e.g., image-only posts may still be considered valid)

**Range:** 0.0 to 1.0 (0% to 100%)

---

#### 3. Posts With Featured Images Ratio
**Formula:** `(total_posts - posts_missing_featured_image) / total_posts`

**Description:** Measures the percentage of posts that have a featured image assigned.

**Calculation:**
- `posts_missing_featured_image`: Count of posts without a featured image (no `_thumbnail_id` meta or invalid attachment)
- `total_posts`: Total count of all posts scanned

**Featured Image Detection:**
- Checks for `_thumbnail_id` post meta
- Validates that the attachment exists and is valid
- Only counts posts that explicitly have no featured image

**Range:** 0.0 to 1.0 (0% to 100%)

---

### Final Score Calculation

The Site Health Score is the arithmetic mean (average) of the three ratios above, multiplied by 100 to display as a percentage.

**Example Calculation:**

Given:
- Total posts: 100
- Published posts: 85
- Posts with blank content: 5
- Posts missing featured images: 20

Ratios:
1. Published Posts Ratio = 85 / 100 = 0.85 (85%)
2. Posts With Content Ratio = (100 - 5) / 100 = 0.95 (95%)
3. Posts With Featured Images Ratio = (100 - 20) / 100 = 0.80 (80%)

Site Health Score = (0.85 + 0.95 + 0.80) / 3 = 0.867 = **86.7%**

---

### Display Format

- **Score Range:** 0% to 100%
- **Display:** Percentage with one decimal place (e.g., "86.7%")
- **Visualization:** 
  - Pie chart showing published vs drafts/private posts
  - Count badges for blank content and missing featured images
  - Large, prominent display of the Site Health Score percentage

---

### Data Collection

All metrics are collected during the Posts Maintenance scan process:

1. **During Scan:**
   - Post status (published, draft, private, etc.)
   - Post content analysis (blank detection)
   - Featured image presence

2. **Storage:**
   - Metrics are stored in the scan job results
   - Last run summary includes all calculated metrics
   - Data persists until the next scan completes

3. **Update Frequency:**
   - Metrics are recalculated on each scan
   - Manual scans update immediately
   - Scheduled daily scans update automatically

---

### Edge Cases

1. **Zero Posts:** If `total_posts = 0`, all ratios default to 1.0 (100%), resulting in a perfect score. This is intentional to avoid division by zero.

2. **No Published Posts:** If `published_posts = 0` but `total_posts > 0`, the Published Posts Ratio is 0.0 (0%).

3. **All Posts Have Issues:** If all posts have blank content or missing images, the respective ratios are 0.0 (0%).

4. **Mixed Status Posts:** Only published posts are scanned by default, but the dashboard may show all post statuses for the Published Posts Ratio calculation.

---

### Notes

- All ratios are equally weighted in the final calculation
- The score is designed to be a quick health indicator, not a comprehensive audit
- Individual metrics (blank content, missing images) are displayed separately for detailed analysis
- The pie chart provides visual context for the published vs non-published post distribution

---

## Dependency Management & Namespace Isolation

### Problem Statement

WordPress plugins that include third-party libraries via Composer can conflict with other plugins that include different versions of the same libraries. For example, if two plugins both include the Google API Client library but at different versions, PHP will load whichever version is autoloaded first, potentially causing fatal errors or unexpected behavior in the other plugin.

### Solution: PHP-Scoper Namespace Prefixing

We use [PHP-Scoper](https://github.com/humbug/php-scoper) to prefix all vendor namespaces with our plugin's namespace, ensuring complete isolation from other plugins.

### Implementation

#### Prefix Configuration

All vendor classes are prefixed with:
```
WPMUDEV\PluginTest\Vendor\
```

For example:
- `Google_Client` becomes `WPMUDEV\PluginTest\Vendor\Google_Client`
- `Google\Service\Drive` becomes `WPMUDEV\PluginTest\Vendor\Google\Service\Drive`

#### Configuration File

The `scoper.inc.php` file defines:

1. **Prefix**: `WPMUDEV\PluginTest\Vendor`
2. **Excluded Namespaces**: WordPress core classes, WP-CLI, and our own namespace
3. **Excluded Classes**: WordPress core classes (`WP_Error`, `WP_REST_Request`, etc.)
4. **Excluded Constants**: WordPress and plugin constants

#### Build Process

To generate prefixed dependencies:

```bash
# Install dev dependencies (includes PHP-Scoper)
composer install

# Run the prefixing process
composer run prefix-dependencies
```

This creates a `vendor-prefixed/` directory with all dependencies properly namespaced.

#### Production Build

The Gruntfile.js build process:

1. Runs `composer prefix-dependencies` to create prefixed vendor
2. Copies `vendor-prefixed/` instead of `vendor/` to the release
3. Updates autoloader to use prefixed classes

### Benefits

1. **No Conflicts**: Our Google API Client cannot conflict with other plugins' versions
2. **Version Independence**: We can use any version without worrying about other plugins
3. **Predictable Behavior**: Our code always uses our bundled dependencies
4. **WordPress Ecosystem Friendly**: Follows best practices for plugin development

### Excluded from Prefixing

The following are intentionally NOT prefixed:

- **WordPress Core**: All WP_* classes and functions
- **WP-CLI**: Command-line interface classes
- **Our Plugin**: `WPMUDEV\PluginTest\*` namespace
- **PHP Built-ins**: Native PHP classes and functions

### Alternative Approaches Considered

1. **Mozart**: Similar tool but less actively maintained
2. **Strauss**: Newer alternative, but PHP-Scoper has better ecosystem support
3. **Manual Prefixing**: Too error-prone and maintenance-heavy
4. **WordPress HTTP API Wrapper**: Would require rewriting all Google API integration

### Verification

To verify namespace isolation is working:

```bash
# Check that Google classes are prefixed
grep -r "namespace WPMUDEV\\\\PluginTest\\\\Vendor\\\\Google" vendor-prefixed/

# Verify no unprefixed Google classes remain
grep -r "^namespace Google\\\\" vendor-prefixed/ | grep -v "WPMUDEV"
# Should return no results
```

### Notes

- PHP-Scoper requires PHP 7.4+ for development (runtime can be lower)
- The prefixed vendor directory is ~20% larger due to namespace changes
- Build time increases by ~30 seconds for the prefixing step
- Always test thoroughly after updating dependencies

