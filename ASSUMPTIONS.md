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

