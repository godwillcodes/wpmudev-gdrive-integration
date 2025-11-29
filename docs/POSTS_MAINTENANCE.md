# Posts Maintenance Documentation

## Overview

The Posts Maintenance feature provides tools to scan, analyze, and maintain WordPress posts and pages. It includes an admin interface, background processing, scheduled tasks, and comprehensive metrics.

## Table of Contents

1. [Features](#features)
2. [Admin Interface](#admin-interface)
3. [Background Processing](#background-processing)
4. [Scheduling](#scheduling)
5. [Site Health Score](#site-health-score)
6. [REST API](#rest-api)
7. [Filters & Hooks](#filters--hooks)

---

## Features

- **Post Scanning**: Process all published posts/pages and update metadata
- **Background Processing**: Continue scanning even if user navigates away
- **Scheduled Scans**: Automatic daily execution
- **Health Metrics**: Content quality analysis and scoring
- **Scan History**: Track past scans with detailed results
- **Multi-Post Type Support**: Scan posts, pages, and custom post types

---

## Admin Interface

### Location

**WordPress Admin → Posts Maintenance**

### Pages

| Page | Menu | Description |
|------|------|-------------|
| Posts Maintenance | Main | Scan controls and current status |
| Scan History | Submenu | Historical scan records |

### Main Dashboard

The main dashboard displays:

1. **Overview Cards**
   - Total posts scanned
   - Site Health Score
   - Posts with blank content
   - Posts missing featured images

2. **Scan Controls**
   - Post type selection
   - Start scan button
   - Progress indicator

3. **Settings**
   - Schedule configuration
   - Post type defaults

### Starting a Scan

1. Navigate to **Posts Maintenance**
2. Select post types to scan (or use defaults)
3. Click **Scan Posts**
4. Monitor progress in real-time
5. View results when complete

---

## Real-Time Progress Updates

### Server-Sent Events (SSE)

The scan progress uses **Server-Sent Events (SSE)** for true real-time updates instead of traditional polling.

#### Why SSE?

| Approach | Update Frequency | Server Load | User Experience |
|----------|------------------|-------------|-----------------|
| **Polling** | Every 5 seconds | Higher (repeated requests) | Jumpy progress bar |
| **SSE** | Every 1 second | Lower (single connection) | Smooth, instant updates |
| **WebSockets** | Real-time | Requires extra setup | Overkill for this use case |

SSE was chosen because:
- **True real-time**: Updates arrive instantly as posts are processed
- **Low overhead**: Single HTTP connection stays open
- **WordPress compatible**: Works without additional server configuration
- **Graceful fallback**: Automatically falls back to polling if SSE fails

#### Architecture

```
┌─────────────┐     SSE Connection      ┌─────────────┐
│   Browser   │ ◄────────────────────── │   Server    │
│  (React)    │   Every 1 second        │   (PHP)     │
└─────────────┘                         └─────────────┘
       │                                       │
       │  If SSE fails, fallback to:           │
       │                                       │
       └──── Polling every 5 seconds ──────────┘
```

#### How It Works

1. **Scan starts** → React opens SSE connection to `/wpmudev/v1/posts-maintenance/stream`
2. **Server streams** → PHP sends progress updates every 1 second
3. **UI updates** → Progress bar animates smoothly with "Live" indicator
4. **Scan completes** → SSE connection closes automatically
5. **On error** → Falls back to 5-second polling

#### Live Indicator

When SSE is active, a green **"Live"** badge appears next to the progress title, indicating real-time updates are being received.

#### SSE Endpoint

```
GET /wp-json/wpmudev/v1/posts-maintenance/stream
```

Response format (text/event-stream):
```
event: progress
data: {"job":{"status":"running","processed":50,"total":100},"lastRun":null,"timestamp":1234567890}

event: progress
data: {"job":{"status":"running","processed":51,"total":100},"lastRun":null,"timestamp":1234567891}
```

---

## Background Processing

### How It Works

Scans run in the background using WordPress cron:

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│  Start Job  │────▶│  Schedule   │────▶│  Process    │
│             │     │  Cron Event │     │  Batch      │
└─────────────┘     └─────────────┘     └─────────────┘
                           │                   │
                           │                   ▼
                           │            ┌─────────────┐
                           │            │  More Posts │
                           │            │  to Process?│
                           │            └─────────────┘
                           │                   │
                           │         Yes ──────┴────── No
                           │          │                │
                           │          ▼                ▼
                           │   ┌─────────────┐  ┌─────────────┐
                           └──▶│  Schedule   │  │  Complete   │
                               │  Next Batch │  │  Job        │
                               └─────────────┘  └─────────────┘
```

### Batch Processing

Posts are processed in configurable batches:

```php
// Default batch size: 25 posts
$batch_size = apply_filters( 'wpmudev_posts_scan_batch_size', 25 );
```

Each batch:
1. Retrieves next N posts from queue
2. Updates `wpmudev_test_last_scan` meta for each
3. Collects metrics (blank content, missing images)
4. Schedules next batch if more posts remain

### Job State

Job state is stored in `wpmudev_posts_scan_job` option:

```php
[
    'job_id'     => 'scan_1234567890_abc123',
    'status'     => 'running',      // pending, running, completed
    'source'     => 'manual',       // manual, scheduled, cli
    'post_types' => ['post', 'page'],
    'queue'      => [1, 2, 3, ...], // Remaining post IDs
    'processed'  => 50,
    'total'      => 100,
    'started_at' => 1234567890,
    'metrics'    => [
        'posts_with_blank_content'     => 5,
        'posts_missing_featured_image' => 10,
        'published_posts'              => 85,
    ],
]
```

### Concurrent Job Prevention

Only one scan can run at a time:

```php
$existing_job = get_option( 'wpmudev_posts_scan_job' );
if ( ! empty( $existing_job ) && $existing_job['status'] !== 'completed' ) {
    return new WP_Error( 'wpmudev_scan_running', 'A scan is already in progress.' );
}
```

---

## Scheduling

### Daily Scheduled Scan

A daily scan can be configured to run automatically:

```php
// Schedule daily scan at specified time
wp_schedule_event( $timestamp, 'daily', 'wpmudev_posts_scan_daily' );
```

### Configuration

Settings are stored in `wpmudev_posts_scan_settings`:

```php
[
    'schedule_enabled' => true,
    'schedule_time'    => '03:00',  // 3 AM local time
    'default_post_types' => ['post', 'page'],
]
```

### Managing Schedule

```php
// Enable scheduled scan
$service->update_settings([
    'schedule_enabled' => true,
    'schedule_time'    => '03:00',
]);

// Disable scheduled scan
$service->update_settings([
    'schedule_enabled' => false,
]);
```

---

## Site Health Score

### Overview

The Site Health Score is a comprehensive metric evaluating content quality.

### Calculation Formula

```
Site Health Score = (
    Published Posts Ratio +
    Posts With Content Ratio +
    Posts With Featured Images Ratio
) / 3 × 100
```

### Individual Ratios

#### 1. Published Posts Ratio

```
published_posts / total_posts
```

Measures percentage of posts that are published vs drafts/private.

#### 2. Posts With Content Ratio

```
(total_posts - posts_with_blank_content) / total_posts
```

Measures percentage of posts with actual content (not blank).

**Blank Detection:**
- Strips HTML tags and whitespace
- Empty or whitespace-only = blank

#### 3. Posts With Featured Images Ratio

```
(total_posts - posts_missing_featured_image) / total_posts
```

Measures percentage of posts with featured images assigned.

### Example Calculation

Given:
- Total posts: 100
- Published posts: 85
- Posts with blank content: 5
- Posts missing featured images: 20

Ratios:
1. Published: 85/100 = 0.85 (85%)
2. With Content: 95/100 = 0.95 (95%)
3. With Images: 80/100 = 0.80 (80%)

**Site Health Score = (0.85 + 0.95 + 0.80) / 3 = 86.7%**

### Edge Cases

| Scenario | Behavior |
|----------|----------|
| Zero posts | Score defaults to 100% |
| All drafts | Published ratio = 0% |
| All blank | Content ratio = 0% |

---

## REST API

### Base URL

```
/wp-json/wpmudev/v1/posts-maintenance/
```

### Endpoints

#### Start Scan

```http
POST /wp-json/wpmudev/v1/posts-maintenance/start
```

**Request Body:**
```json
{
  "post_types": ["post", "page"]
}
```

**Response:**
```json
{
  "success": true,
  "job": {
    "job_id": "scan_1234567890_abc123",
    "status": "pending",
    "total": 100,
    "processed": 0
  }
}
```

---

#### Get Status

```http
GET /wp-json/wpmudev/v1/posts-maintenance/status
```

**Response:**
```json
{
  "job": {
    "job_id": "scan_1234567890_abc123",
    "status": "running",
    "total": 100,
    "processed": 45,
    "progress": 45
  },
  "last_run": {
    "timestamp": 1234567890,
    "processed": 100,
    "total": 100,
    "health_score": 86.7
  }
}
```

---

#### Get History

```http
GET /wp-json/wpmudev/v1/posts-maintenance/history
```

**Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `limit` | integer | 10 | Max records to return |

**Response:**
```json
{
  "history": [
    {
      "job_id": "scan_1234567890_abc123",
      "timestamp": 1234567890,
      "source": "manual",
      "processed": 100,
      "total": 100,
      "health_score": 86.7,
      "metrics": {...}
    }
  ]
}
```

---

#### Get/Update Settings

```http
GET /wp-json/wpmudev/v1/posts-maintenance/settings
POST /wp-json/wpmudev/v1/posts-maintenance/settings
```

**Request Body (POST):**
```json
{
  "schedule_enabled": true,
  "schedule_time": "03:00",
  "default_post_types": ["post", "page"]
}
```

---

## Filters & Hooks

### Filters

```php
// Customize default post types
add_filter( 'wpmudev_posts_scan_post_types', function( $types ) {
    $types[] = 'product'; // Add WooCommerce products
    return $types;
});

// Customize batch size (default: 25)
add_filter( 'wpmudev_posts_scan_batch_size', function( $size ) {
    return 50; // Process 50 posts per batch
});

// Customize post query args
add_filter( 'wpmudev_posts_scan_query_args', function( $args ) {
    $args['date_query'] = [
        ['after' => '1 year ago']
    ];
    return $args;
});
```

### Actions

```php
// Before scan starts
add_action( 'wpmudev_posts_scan_before_start', function( $job ) {
    // Custom logic before scan
});

// After each post is processed
add_action( 'wpmudev_posts_scan_post_processed', function( $post_id, $job ) {
    // Custom logic per post
}, 10, 2 );

// After scan completes
add_action( 'wpmudev_posts_scan_completed', function( $job ) {
    // Send notification, etc.
});

// Daily scheduled scan hook
add_action( 'wpmudev_posts_scan_daily', function() {
    // Triggered by WP-Cron daily
});
```

---

## Post Meta

### `wpmudev_test_last_scan`

Each scanned post receives this meta field:

```php
update_post_meta( $post_id, 'wpmudev_test_last_scan', time() );
```

**Value:** Unix timestamp of last scan

**Usage:**
```php
$last_scan = get_post_meta( $post_id, 'wpmudev_test_last_scan', true );
if ( $last_scan ) {
    echo 'Last scanned: ' . date( 'Y-m-d H:i:s', $last_scan );
}
```

---

## Troubleshooting

### Scan Not Progressing

1. Check WP-Cron is working: `wp cron event list`
2. Verify no PHP errors in debug log
3. Check `wpmudev_posts_scan_job` option for stuck state

### High Memory Usage

Reduce batch size:
```php
add_filter( 'wpmudev_posts_scan_batch_size', function() {
    return 10;
});
```

### Scan Taking Too Long

1. Increase batch size for faster processing
2. Limit post types being scanned
3. Check for slow post meta queries
