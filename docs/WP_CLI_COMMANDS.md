# WP-CLI Commands Documentation

## Overview

This plugin provides WP-CLI commands for managing Posts Maintenance scans from the command line. These commands mirror the functionality available in the admin interface.

---

## Command: `wp wpmudev posts-scan`

Scan public posts/pages and stamp the `wpmudev_test_last_scan` meta field.

### Synopsis

```bash
wp wpmudev posts-scan [--post_types=<types>] [--batch_size=<number>] [--dry-run] [--format=<format>] [--quiet]
```

### Description

Processes all published posts of the specified types and updates their `wpmudev_test_last_scan` post meta with the current timestamp. Also collects site health metrics including content analysis.

### Options

#### `--post_types=<types>`

Comma-separated list of public post types to scan.

- **Default:** `post,page` (or value from `wpmudev_posts_scan_post_types` filter)
- **Example:** `--post_types=post,page,product`

#### `--batch_size=<number>`

Number of posts to process per batch.

- **Default:** 25
- **Range:** 1-200
- **Example:** `--batch_size=50`

#### `--dry-run`

Preview what would be scanned without actually processing posts. Shows post counts and types that would be affected.

- **Default:** false
- **Example:** `--dry-run`

#### `--format=<format>`

Output format for results.

- **Default:** `table`
- **Options:** `table`, `json`, `csv`, `yaml`
- **Example:** `--format=json`

#### `--quiet`

Suppress progress output. Only show final result or errors. Useful for cron jobs and scripted usage.

- **Default:** false
- **Example:** `--quiet`

---

## Examples

### Basic Scan

Scan default post types (posts & pages):

```bash
wp wpmudev posts-scan
```

**Output:**
```
Scanning 150 posts across: post, page
Scanning posts  100% [============================] 0:05 / 0:05

Scan Results
--------------------------------------------------
Status: completed
Processed: 150 / 150 posts
Completed: November 29, 2024 3:30 AM
Health Score: 86.7%

Metrics:
  Posts With Blank Content: 5
  Posts Missing Featured Image: 12
  Published Posts: 133

Success: Scan complete. Processed 150 of 150 posts. Last run stored on November 29, 2024 3:30 AM.
```

### Dry Run

Preview what would be scanned without making changes:

```bash
wp wpmudev posts-scan --dry-run
```

**Output:**
```
Dry Run Preview
--------------------------------------------------
Post Types: post, page
Total Posts: 150

No changes were made.
```

### Custom Post Types

Scan specific post types:

```bash
wp wpmudev posts-scan --post_types=product,docs
```

### Smaller Batches

Use smaller batches for memory-constrained environments:

```bash
wp wpmudev posts-scan --batch_size=10
```

### JSON Output

Get results as JSON for scripting:

```bash
wp wpmudev posts-scan --format=json
```

**Output:**
```json
{
  "status": "completed",
  "processed": 150,
  "total": 150,
  "post_types": ["post", "page"],
  "timestamp": 1701234567,
  "health_score": 86.7,
  "metrics": {
    "posts_with_blank_content": 5,
    "posts_missing_featured_image": 12,
    "published_posts": 133
  }
}
```

### CSV Output

Export results as CSV:

```bash
wp wpmudev posts-scan --format=csv
```

**Output:**
```csv
status,processed,total,health_score,timestamp
completed,150,150,86.7,1701234567
```

### Quiet Mode for Cron

Run silently for cron jobs:

```bash
wp wpmudev posts-scan --quiet
```

**Output:**
```
Success: Scan complete. Processed 150 of 150 posts. Last run stored on November 29, 2024 3:30 AM.
```

### Combined Options

Combine multiple options:

```bash
wp wpmudev posts-scan --post_types=post --batch_size=50 --format=json --quiet
```

### Dry Run with JSON

Preview in JSON format:

```bash
wp wpmudev posts-scan --dry-run --format=json
```

**Output:**
```json
{
  "mode": "dry_run",
  "post_types": ["post", "page"],
  "total": 150,
  "message": "Would scan 150 posts across: post, page"
}
```

---

## Exit Codes

| Code | Meaning |
|------|---------|
| 0 | Success |
| 1 | Error (invalid options, scan failed, etc.) |

---

## Integration Examples

### Cron Job

Add to system crontab for daily scans:

```bash
# Run daily at 3 AM
0 3 * * * cd /path/to/wordpress && wp wpmudev posts-scan --quiet >> /var/log/wp-scan.log 2>&1
```

### Shell Script

```bash
#!/bin/bash

# Run scan and capture result
RESULT=$(wp wpmudev posts-scan --format=json --quiet)

# Parse JSON result
STATUS=$(echo $RESULT | jq -r '.status')
HEALTH=$(echo $RESULT | jq -r '.health_score')

# Send notification if health score is low
if (( $(echo "$HEALTH < 70" | bc -l) )); then
    echo "Warning: Site health score is $HEALTH%" | mail -s "Low Health Score" admin@example.com
fi
```

### CI/CD Pipeline

```yaml
# GitHub Actions example
- name: Run Posts Maintenance Scan
  run: |
    wp wpmudev posts-scan --format=json > scan-results.json
    cat scan-results.json
```

### Multisite

Run on specific site:

```bash
wp wpmudev posts-scan --url=https://subsite.example.com
```

Run on all sites:

```bash
wp site list --field=url | xargs -I {} wp wpmudev posts-scan --url={}
```

---

## Troubleshooting

### "A scan is already in progress"

A previous scan didn't complete properly. Clear the stuck job:

```bash
wp option delete wpmudev_posts_scan_job
```

### "No valid public post types were provided"

The specified post types don't exist or aren't public:

```bash
# List available public post types
wp post-type list --public=1 --field=name
```

### "Batch size must be between 1 and 200"

Adjust the batch size to a valid range:

```bash
wp wpmudev posts-scan --batch_size=25
```

### Memory Issues

Reduce batch size for large sites:

```bash
wp wpmudev posts-scan --batch_size=5
```

Or increase PHP memory:

```bash
wp wpmudev posts-scan --batch_size=25 --allow-root
```

### Timeout Issues

For very large sites, run in smaller chunks:

```bash
# Scan posts only
wp wpmudev posts-scan --post_types=post

# Then scan pages
wp wpmudev posts-scan --post_types=page
```

---

## Related Commands

```bash
# Check if scan is running
wp option get wpmudev_posts_scan_job --format=json

# View last scan results
wp option get wpmudev_posts_scan_last_run --format=json

# View scan history
wp option get wpmudev_posts_scan_history --format=json

# Clear scan history
wp option delete wpmudev_posts_scan_history

# Check scheduled events
wp cron event list | grep wpmudev
```
