# QA Testing Guide — Multi-Location Google Reviews Widget

## Role

You are a QA tester for a WordPress plugin called **Multi-Location Google Reviews Widget**. Your job is to validate that the plugin behaves correctly across all features, edge cases, and error conditions using the test scenarios in this document. For each test, evaluate whether the actual result matches the expected result and report pass, fail, or blocked (if a prerequisite is not met).

---

## 0. Test Environment

### WordPress Site

| | |
|---|---|
| **Site URL** | `https://mlgrw.local/` |
| **Admin URL** | `http://mlgrw.local/wp-login.php` |
| **Username** | `admin` |
| **Password** | `admin` |
| **Plugin settings page** | `http://mlgrw.local/wp-admin/options-general.php?page=mlgr-settings` |
| **Reviews CPT list** | `http://mlgrw.local/wp-admin/edit.php?post_type=mlgr_review` |
| **Linked Posts taxonomy** | `http://mlgrw.local/wp-admin/edit-tags.php?taxonomy=mlgr_linked_post&post_type=mlgr_review` |
| **WordPress Dashboard** | `http://mlgrw.local/wp-admin/` |

### Scraper API Server

| | |
|---|---|
| **API base URL** | `http://64.176.218.88:8000` |
| **API key** | `grs_51617870` (sent via `X-API-Key` header) |
| **Health check** | `http://64.176.218.88:8000/` |
| **Swagger UI** | `http://64.176.218.88:8000/docs` |

### Testing Tool — Playwright

Use **Playwright** (`playwright.dev`) for all browser automation. Playwright is already installed locally on the same machine running Claude Code.

**How to use Playwright in tests:**

- Use the Bash tool to write and run Playwright scripts via Node.js.
- Use `chromium` as the browser (default).
- The WordPress admin session must be established by navigating to `http://mlgrw.local/wp-login.php` and submitting the login form before accessing any admin page.
- After login, navigate directly to admin URLs listed above.
- Use `page.waitForSelector()` or `page.waitForURL()` to confirm navigation and page loads before asserting.
- Use `page.locator()` and `expect(locator).toBeVisible()` / `toHaveText()` for assertions.
- For notice messages (success/error), wait for the `.notice` element and check its text content.
- Screenshots can be taken at any point with `page.screenshot()` to document results.
- Prefer `page.goto()` with `{ waitUntil: 'networkidle' }` for admin page navigation to ensure all content has loaded.

**Example login helper (reuse across tests):**

```js
const { chromium } = require('playwright');

const browser = await chromium.launch();
const page = await browser.newPage();

await page.goto('http://mlgrw.local/wp-login.php');
await page.fill('#user_login', 'admin');
await page.fill('#user_pass', 'admin');
await page.click('#wp-submit');
await page.waitForURL('**/wp-admin/**');
```

**Cleanup:** Always call `await browser.close()` at the end of each test script.

---

## 1. Plugin Overview

**Plugin Name:** Multi-Location Google Reviews Widget  
**Version:** 2.0.0  
**WordPress menu location:** Settings → Multi-Location Reviews  
**CPT admin menu location:** Google Reviews (left sidebar, dashicon star)

This plugin fetches Google reviews from a self-hosted scraper REST API and stores them as WordPress custom post type (CPT) posts. Reviews are displayed on any page or post using two shortcodes. The admin interface allows managing business locations, assigning reviews to people or entities, and viewing sync error logs.

---

## 2. System Architecture

### 2.1 External Dependency — Scraper API

The plugin communicates with a self-hosted Python API server (`google-reviews-scraper-pro`) running on port 8000. This server scrapes Google Maps reviews using a headless Chrome browser and stores them in a local SQLite database.

**Default API base URL:** `http://64.176.218.88:8000`  
**Authentication:** Optional API key sent via `X-API-Key` HTTP header on every request. Without the key, protected endpoints return HTTP 401.  
**Health check endpoint:** `GET /` — returns 200 with no key required.  
**Protected endpoints:** `/scrape`, `/jobs/{id}`, `/places`, `/places/{id}`, `/reviews/{id}`

### 2.2 Database Tables (custom)

| Table | Purpose |
|---|---|
| `wp_mlgr_locations` | One row per business location. Stores Google Maps URL, display name, sync status, last sync time, average rating, total reviews. |
| `wp_mlgr_error_logs` | Structured error log rows from the scraper API. Auto-pruned after 30 days. |
| `wp_mlgr_reviews` | Legacy table from v1. Kept as a backup. Not actively written to in v2. |

### 2.3 WordPress Data

| Object | Purpose |
|---|---|
| `mlgr_review` CPT | Each synced Google review is stored as a WordPress post. `post_title` = reviewer name, `post_content` = review text. |
| `mlgr_linked_post` taxonomy | Tags reviews to any WP page or CPT post (e.g. a person's profile page). Term slugs are used in shortcode filtering. |

### 2.4 Post Meta Keys

| Meta Key | Value |
|---|---|
| `_mlgr_google_review_id` | Original Google review ID. Used for deduplication — prevents duplicate posts on re-sync. |
| `_mlgr_location_id` | Integer ID from `wp_mlgr_locations`. Links the review post to a location. |
| `_mlgr_rating` | Integer 1–5. Star rating of the review. |
| `_mlgr_author_photo` | URL string. Reviewer's profile photo from Google. |

### 2.5 WordPress Options

| Option Key | Default | Purpose |
|---|---|---|
| `mlgr_scraper_api_url` | `http://64.176.218.88:8000` | Base URL of the scraper API server. |
| `mlgr_scraper_api_key` | `''` (empty) | API key sent in `X-API-Key` header. |
| `mlgr_anonymize_reviewers` | `0` (false) | When enabled, replaces reviewer names with "Google User" and hides avatars on the frontend. |
| `mlgr_sync_frequency` | `monthly` | How often WordPress auto-resyncs all locations. Options: `daily`, `weekly`, `monthly`, `manual`. |
| `mlgr_sync_excluded_ratings` | `[]` | Array of integer star ratings (1–5) to skip during sync. Reviews with these ratings are not imported. |
| `mlgr_sync_error_log` | `{}` | Per-location most-recent error. Keyed by location ID string. |
| `mlgr_schema_version` | `2.0.0` | Tracks DB schema version for upgrade logic. |

---

## 3. Review Post Status Rules

During sync, each review's `post_status` is set automatically:

- **Rating ≥ 4 stars → `publish`** (visible on the frontend via shortcode)
- **Rating < 4 stars → `draft`** (hidden from the frontend shortcode, visible only in the WP admin)

---

## 4. Sync Flow

Adding a location or triggering a resync is asynchronous. The flow is:

1. A location row is inserted into `wp_mlgr_locations` with `sync_status = 'pending'`.
2. An Action Scheduler job is queued immediately.
3. When the job fires, the plugin calls `GET /places` on the scraper API to check if data already exists for that Google Maps URL.
   - **If found:** Reviews are imported directly — no new scrape is triggered. This is the fast path for URLs already in the scraper's database.
   - **If not found:** `POST /scrape` is called to trigger a new browser scrape. The scraper returns a `job_id`.
4. The plugin polls `GET /jobs/{job_id}` every 30 seconds until the status is `completed`.
5. Once complete, `GET /reviews/{place_id}?limit=1000&offset=0` is called. If the response contains exactly 1000 reviews, additional pages are fetched (`offset=1000`, `offset=2000`, etc.) until a page returns fewer than 1000 results.
6. All reviews are upserted as `mlgr_review` CPT posts. Existing posts (matched by `_mlgr_google_review_id`) are updated; new ones are inserted.
7. The location's `sync_status` is set to `completed`, `last_sync` is updated, and the shortcode cache is flushed.

**Force Resync** always triggers a fresh browser scrape regardless of whether the scraper already has the data.

**Periodic Resync** (via WP-Cron at the configured frequency) calls Force Resync for every location in the database.

---

## 5. Admin Interface

### 5.1 Settings Page Location

**Path:** WordPress Admin → Settings → Multi-Location Reviews  
**Capability required:** `manage_options` (Administrator role)  
**URL:** `wp-admin/options-general.php?page=mlgr-settings`

### 5.2 Tab Navigation

The settings page has five tabs:

| Tab Label | URL Param (`tab=`) | Purpose |
|---|---|---|
| Welcome | `welcome` | Quick-start guide and shortcode reference. Default tab. |
| Locations | `locations` | Add/manage/delete business locations. |
| Assign Reviews | `assign-reviews` | Search reviews and bulk-assign them to Linked Post terms. |
| Sync Logs | `sync-logs` | View and clear the 50 most recent scraper errors. |
| Settings | `settings` | Configure scraper URL, API key, sync frequency, and filters. |

### 5.3 Welcome Tab

Read-only documentation tab. Contains:
- Quick-start steps (numbered list)
- Explanation of the Linked Posts taxonomy and the `linked_to` shortcode parameter
- Full shortcode reference table for `[ml_google_reviews]` with all parameters and examples
- Full shortcode reference table for `[ml_google_rating]`
- Explanation of how reviews are stored

No forms or actions on this tab.

### 5.4 Settings Tab

**Form action:** `admin-post.php` with `action=mlgr_save_settings`  
**Nonce field:** `mlgr_settings_nonce` verifying `mlgr_save_settings`

**Fields:**

| Field | Type | Validation | Saved To |
|---|---|---|---|
| Scraper API URL | `url` | Stripped of trailing slashes. Falls back to `http://64.176.218.88:8000` if blank. | `mlgr_scraper_api_url` |
| Scraper API Key | `password` | Saved as-is via `sanitize_text_field`. Blank = no key sent. | `mlgr_scraper_api_key` |
| Anonymize Reviewers | `checkbox` | 1 if checked, 0 if not. | `mlgr_anonymize_reviewers` |
| Sync Frequency | `select` | Must be one of `daily`, `weekly`, `monthly`, `manual`. Invalid → defaults to `monthly`. | `mlgr_sync_frequency` |
| Exclude Ratings from Sync | `checkbox[]` (1–5) | Array of integers 1–5. | `mlgr_sync_excluded_ratings` |

**On save:** Rescheduling the WP-Cron sync event is triggered automatically.  
**Success redirect:** Returns to the Settings tab with a green "Settings saved." notice.

### 5.5 Locations Tab

#### Add Location Form

**Form action:** `admin-post.php` with `action=mlgr_add_location`  
**Nonce:** `mlgr_add_location_nonce` verifying `mlgr_add_location`

**Field:** Google Maps URL (`type="url"`, `required`)

**Server-side validation:**
- Blank URL → error notice, no insert
- URL does not contain `google.com/maps` or `maps.app.goo.gl` → error notice, no insert
- Duplicate URL (already exists in DB) → `wpdb->insert` fails due to unique key constraint → error notice

**On success:** Row inserted with `sync_status = 'pending'`. Action Scheduler job queued. Redirect to Locations tab with notice: *"Location added. Scraping will begin in the background — check back in a few minutes."*

#### Existing Locations Table

Columns: **ID**, **Google Maps URL**, **Name**, **Total Reviews**, **Sync Status**, **Last Sync**, **Last Error**, **Actions**

**Name column:** Displays the resolved business name from the scraper. Shows a red **"Errors: N"** badge if the location had errors in the last 24 hours AND the `sync_status` is NOT `completed`. The badge is suppressed when sync status is `completed`.

**Total Reviews column:** Count of `mlgr_review` CPT posts (status `publish` or `draft`) linked to the location via `_mlgr_location_id` post meta. Does NOT read from the legacy `wp_mlgr_reviews` table.

**Sync Status values:** `pending`, `active`, `completed`, `error`

**Last Error column:** Shows the timestamp and message of the most recent sync error stored in `mlgr_sync_error_log`. Shows `-` if no error.

#### Force Resync Button

**Form action:** `admin-post.php` with `action=mlgr_force_resync`  
**Nonce:** `mlgr_force_resync_{location_id}` (location-specific)

Sets location `sync_status = 'pending'`, clears stored sync error, schedules a fresh scrape job.  
**Success redirect:** Locations tab with notice: *"Fresh scrape scheduled. Reviews will update in the background."*

#### Delete Button

**Form action:** `admin-post.php` with `action=mlgr_delete_location`  
**Nonce:** `mlgr_delete_location_{location_id}` (location-specific)  
**Confirmation dialog:** JS `confirm()` fires before submit. Message: *"Delete this location and all N synced review(s)? This cannot be undone."* where N is the current Total Reviews count.

**On confirm:**
1. All `mlgr_review` CPT posts with `_mlgr_location_id = location_id` are permanently deleted (bypasses trash).
2. The `wp_mlgr_locations` row is deleted.
3. Any stored sync error for that location is cleared.
4. The shortcode transient cache is flushed.

**Success redirect:** Locations tab with notice: *"Location deleted along with N synced review(s)."*

### 5.6 Assign Reviews Tab

**Purpose:** Search the `mlgr_review` CPT by keyword and/or location, then bulk-assign selected reviews to a `mlgr_linked_post` taxonomy term.

**Search form:** `GET` request to `options-general.php`. Fields: keyword text input (`mlgr_s`), location dropdown (`mlgr_location`). No results shown until at least one filter is submitted.

**Results table columns:** Checkbox, Author, Rating (star display), Review Excerpt (first 20 words), Currently Assigned To (term slugs, or `—` if unassigned)

**"Select all" checkbox:** Header checkbox toggles all row checkboxes via JavaScript.

**Bulk assign form:** `POST` to `admin-post.php` with `action=mlgr_bulk_assign`. Nonce: `mlgr_bulk_assign_nonce`.

**Validation:**
- No term selected → error: *"Please select a linked post term."*
- Term slug not found in DB → error: *"The selected term does not exist."*
- No review checkboxes selected → error: *"No reviews were selected."*

**On success:** `wp_set_object_terms()` called with `append=true` (does not remove existing terms). Shortcode cache is flushed. Redirects to Assign Reviews tab preserving the current search and location filters. Notice: *"N review(s) assigned to 'Term Name'."*

**No terms exist warning:** If no `mlgr_linked_post` terms exist, a red warning and link to create one appears below the assign controls.

### 5.7 Sync Logs Tab

Shows up to 50 most recent rows from `wp_mlgr_error_logs`, ordered by timestamp descending.

**Columns:** Date, Location ID, Error Code, Message (endpoint URL shown on hover via `title` attribute)

**Clear Logs button:** Sends `POST` to `admin-post.php` with `action=mlgr_clear_logs`. JS confirm fires: *"Clear all sync logs?"* Truncates the entire `wp_mlgr_error_logs` table. Success notice: *"Sync logs cleared."*

---

## 6. Linked Posts Taxonomy

**Taxonomy slug:** `mlgr_linked_post`  
**Attached to CPT:** `mlgr_review`  
**Admin menu:** Google Reviews → Linked Posts

**Purpose:** Terms represent people or entities (e.g. an attorney, a doctor). Tagging reviews with a term allows the shortcode to display only that person's reviews.

**Term meta:** `mlgr_linked_post_id` — stores the WP post ID of the page or CPT post that represents this person. Optional. Shown on the edit screen as a link to the linked post.

**Term slug** is the value passed to the `linked_to` shortcode parameter.

---

## 7. Google Reviews CPT Admin List

**Menu:** Google Reviews (left sidebar)  
**Post type slug:** `mlgr_review`  
**Visibility:** Not publicly queryable. Admin-only UI. Not in REST API.

**Custom column — Rating:**
- Inserted after the Title column
- Displays filled (★) and empty (☆) stars based on `_mlgr_rating`
- Sortable (clicking the column header sorts by star count ascending or descending)

**Rating Meta Box:**
- Shown in the sidebar on the single review edit screen
- Read-only star display with "N out of 5" label

---

## 8. Shortcodes

### 8.1 `[ml_google_reviews]`

Renders a list of review cards. Output is cached in a WordPress transient for 1 hour per unique combination of parameters. Cache is flushed after every sync completion and every bulk-assign operation.

Only reviews with `post_status = 'publish'` are included (i.e. 4-star and 5-star reviews by default, unless the rating-based status rule was changed).

**Parameters:**

| Parameter | Type | Default | Validation / Behavior |
|---|---|---|---|
| `location_id` | integer | `0` | `0` = all locations. Any positive integer filters to reviews with that `_mlgr_location_id`. |
| `limit` | integer or `"all"` | `"all"` | `"all"`, `"0"`, `"-1"`, or empty = no limit. Positive integer = max reviews shown, capped at 1000. |
| `min_rating` | integer | `0` | Filters to reviews with `_mlgr_rating >= min_rating`. Clamped to 0–5. |
| `exclude_ratings` | string | `""` | Comma-separated list of star ratings to exclude. E.g. `"1,2,3"` shows only 4- and 5-star reviews. Invalid values and out-of-range ratings are silently ignored. |
| `max_chars` | integer | `150` | Truncates review text to this many characters (multibyte-safe). Minimum 60, maximum 1000. |
| `layout` | string | `"grid"` | One of `grid`, `slider`, `masonry`. Any other value defaults to `grid`. |
| `linked_to` | string | `""` | Term slug from `mlgr_linked_post` taxonomy. Filters to reviews tagged with this term. |

**Layout behaviors:**

- **`grid`** — CSS Grid, 3 columns on desktop, 2 on tablet (≤1100px), 1 on mobile (≤767px).
- **`masonry`** — CSS `column-count: 3`, 2 on tablet, 1 on mobile. Cards break naturally across columns.
- **`slider`** — Horizontal scroll with Prev/Next arrow buttons. Shows 3 cards per view on desktop, 2 on tablet, 1 on mobile. Arrow buttons are hidden when there is nothing to scroll (all cards fit in one view). Buttons are disabled when at the start or end of the scroll range. Card heights are equalized via JavaScript after images load.

**Review card contents:**
- Reviewer avatar (img with `loading="lazy"`) or initial-letter placeholder if no photo URL
- Reviewer name (or "Google User" if anonymized; "Anonymous" if name is blank)
- Review date (formatted `Y-m-d`)
- Star rating (SVG stars, filled = gold, empty = grey; aria-label on the container for accessibility)
- Blue verified badge icon
- Review text (truncated to `max_chars` with `…` if longer)
- "Read more" link (only shown when text is truncated AND a review URL can be constructed)

**Empty state:** If no reviews match the filters, a single styled block displays: *"No reviews found for the selected filters."*

### 8.2 `[ml_google_rating]`

Renders a plain-text average rating summary. No caching. Reads directly from `wp_mlgr_locations`.

**Parameters:**

| Parameter | Type | Default | Behavior |
|---|---|---|---|
| `location_id` | integer | `0` | `0` = uses the first location in the DB (lowest ID). Positive integer = that specific location. |

**Output format:** `<span class="mlgr-rating-text">4.7 / 5 based on 120 reviews</span>`

Uses "review" (singular) when total is exactly 1, "reviews" (plural) otherwise.

Returns empty string (no output, no error) if the location has no data or does not exist.

---

## 9. Dashboard Widget

**Widget title:** Multi-Location Reviews Summary  
**Visible to:** Users with `manage_options` capability only

**Displays:**
- **Total Reviews Managed:** Count of published `mlgr_review` posts
- **Latest Sync Status:** `sync_status` from the most recently synced location row
- **Latest Sync Time:** Formatted according to WP date/time settings. Hidden if no sync has occurred yet.

---

## 10. Error Handling

### Sync Errors
- Stored per-location in the `mlgr_sync_error_log` WordPress option (most recent error only per location)
- Also written as rows to `wp_mlgr_error_logs` table (full history)
- Location `sync_status` is set to `'error'` when a sync error is recorded
- Errors are cleared when the next successful sync completes or when Force Resync is triggered

### Error Badge
- Appears in the Name column of the Locations table
- Shown when: the location had errors in the last 24 hours AND `sync_status` is NOT `'completed'`
- Badge text: "Errors: N" where N is the count from `wp_mlgr_error_logs` in the last 24 hours
- Tooltip (via `title` attribute): "N sync error(s) in the last 24 hours."

### HTTP Error Responses
- 401 Unauthorized → means the API key is missing or wrong
- 422 Unprocessable Entity → means a request parameter is invalid (e.g. `limit` exceeds 1000)
- 404 on `/jobs/{id}` → job ID not found; recorded as error, sync stops

---

## 11. Test Scenarios

### Settings Tab

**TC-S01 — Save Scraper API URL**
- Steps: Go to Settings tab. Change Scraper API URL to a valid URL. Click Save Settings.
- Expected: Page redirects to Settings tab. Green notice "Settings saved." The URL field shows the saved value on reload.

**TC-S02 — Save API Key**
- Steps: Enter `grs_51617870` in the Scraper API Key field. Save.
- Expected: Notice "Settings saved." Field retains the value on reload.

**TC-S03 — API Key blank = no header sent**
- Steps: Clear the API Key field. Save. Trigger Force Resync.
- Expected: Scraper API returns 401. Sync Logs shows an error with code `http_401`.

**TC-S04 — Sync frequency options**
- Steps: Change Sync Frequency to each of the four options (Daily, Weekly, Monthly, Manual). Save each time.
- Expected: Value persists after save. When "Manual only" is selected, the WP-Cron scheduled event for `mlgr_daily_sync_locations` is removed.

**TC-S05 — Exclude Ratings**
- Steps: Check 1-star and 2-star under "Exclude Ratings from Sync." Save. Force Resync a location.
- Expected: After sync, no `mlgr_review` posts exist with `_mlgr_rating` of 1 or 2 for that location.

**TC-S06 — Anonymize Reviewers**
- Steps: Enable Anonymize Reviewers. Save. View a page with `[ml_google_reviews]`.
- Expected: All reviewer names display as "Google User". No avatar images appear (only initial placeholders or nothing).

**TC-S07 — Anonymize Reviewers off**
- Steps: Disable Anonymize Reviewers. Save. View the shortcode page.
- Expected: Real reviewer names and avatar images are shown.

---

### Locations Tab — Adding

**TC-L01 — Add valid Google Maps URL (scraper already has data)**
- Steps: Paste a URL already in the scraper's database. Click "Add Location & Start Scrape."
- Expected: Success notice. Location appears in the table with `sync_status = 'pending'`. Within seconds (no scrape needed), status changes to `completed`. Name column auto-populates. Total Reviews shows the correct count.

**TC-L02 — Add valid Google Maps URL (scraper does not have data)**
- Steps: Paste a URL not yet in the scraper. Click "Add Location & Start Scrape."
- Expected: Success notice. Location shows `sync_status = 'pending'` → `active` → `completed` after the scraper finishes (5–15 minutes).

**TC-L03 — Add duplicate URL**
- Steps: Add a URL that already exists in the Locations table.
- Expected: Error notice: "Unable to add location. This URL may already exist." No new row is created.

**TC-L04 — Add invalid URL**
- Steps: Enter `https://example.com` in the Google Maps URL field. Submit.
- Expected: Error notice: "Please enter a valid Google Maps URL (google.com/maps or maps.app.goo.gl)." No row is created.

**TC-L05 — Add blank URL**
- Steps: Submit the Add Location form with no URL entered.
- Expected: Browser HTML5 validation prevents submission (field is `required`). If bypassed, server returns error: "Google Maps URL is required."

**TC-L06 — Total Reviews count accuracy**
- Steps: After sync completes, note the Total Reviews value in the Locations table. Go to Google Reviews CPT list and count posts with that location's `_mlgr_location_id`.
- Expected: Both counts match.

---

### Locations Tab — Force Resync

**TC-FR01 — Force Resync triggers fresh scrape**
- Steps: Click Force Resync on a completed location.
- Expected: Success notice. `sync_status` changes to `pending` then back to `completed`. `last_sync` timestamp updates to the current time.

**TC-FR02 — No duplicate reviews after resync**
- Steps: Note the Total Reviews count before Force Resync. Wait for resync to complete.
- Expected: Total Reviews count remains the same (or increases if Google has new reviews). No duplicates are created.

**TC-FR03 — Error cleared on Force Resync**
- Steps: With a location in `error` status, click Force Resync.
- Expected: The Last Error column clears to `-` for that location after the new sync begins.

---

### Locations Tab — Delete

**TC-D01 — Confirmation dialog shows correct count**
- Steps: Click Delete on a location with a known review count.
- Expected: JS confirm dialog text includes the correct number, e.g. *"Delete this location and all 80 synced review(s)? This cannot be undone."*

**TC-D02 — Cancel delete**
- Steps: Click Delete, then cancel the confirmation dialog.
- Expected: Nothing changes. Location and reviews remain.

**TC-D03 — Confirm delete — location removed**
- Steps: Click Delete and confirm.
- Expected: Location row disappears from the table. Notice: *"Location deleted along with N synced review(s)."*

**TC-D04 — Confirm delete — reviews removed**
- Steps: After deleting a location, go to Google Reviews CPT list. Filter or search for that location's reviews.
- Expected: No `mlgr_review` posts remain with `_mlgr_location_id` matching the deleted location.

**TC-D05 — Shortcode after delete**
- Steps: Place `[ml_google_reviews location_id="X"]` on a page. Delete location X. View the page.
- Expected: Empty state renders: *"No reviews found for the selected filters."* No PHP errors or blank white screen.

---

### Sync Logs Tab

**TC-LOG01 — Errors appear after failed sync**
- Steps: Set the Scraper API URL to an unreachable address (e.g. `http://localhost:9999`). Trigger Force Resync. Check Sync Logs.
- Expected: At least one row appears with the correct Location ID, an error code (e.g. `request_error`), and a descriptive message.

**TC-LOG02 — Clear Logs**
- Steps: With at least one log row present, click "Clear Logs" and accept the confirmation.
- Expected: Table shows *"No sync errors logged."*

**TC-LOG03 — Error badge suppressed on completed**
- Steps: Cause a sync error (bad URL), then fix the URL and Force Resync to completion.
- Expected: After sync completes, the red error badge in the Name column is gone even though old log rows exist.

**TC-LOG04 — Error badge shown on error status**
- Steps: Cause a sync to fail so the location has `sync_status = 'error'`.
- Expected: Red "Errors: N" badge appears in the Name column with the correct count.

---

### Assign Reviews Tab

**TC-AR01 — Search by keyword**
- Steps: Enter a word that appears in at least one review's text. Click Search.
- Expected: Only reviews containing that word appear. "Found N review(s)." count is shown.

**TC-AR02 — Search returns no results**
- Steps: Enter a string that matches no review text (e.g. `zzznotexist`). Click Search.
- Expected: "Found 0 review(s)." Table shows *"No reviews found matching your search."*

**TC-AR03 — Filter by location**
- Steps: Select a specific location from the dropdown. Click Search.
- Expected: Only reviews from that location appear.

**TC-AR04 — Select all checkbox**
- Steps: After a search returns results, check the header checkbox.
- Expected: All row checkboxes become checked. Unchecking the header unchecks all.

**TC-AR05 — Successful bulk assign**
- Steps: Search for reviews. Select several. Choose a Linked Post term. Click "Assign Selected."
- Expected: Success notice: *"N review(s) assigned to 'Term Name'."* The search results (preserved from the previous query) show the assigned term in the "Currently Assigned To" column.

**TC-AR06 — Assign with no term selected**
- Steps: Select reviews but leave the term dropdown at the default blank option. Submit.
- Expected: Error notice: *"Please select a linked post term."* No assignments made.

**TC-AR07 — Assign with no reviews selected**
- Steps: Submit the assign form with no review checkboxes checked.
- Expected: Error notice: *"No reviews were selected."*

**TC-AR08 — No terms exist warning**
- Steps: Ensure no `mlgr_linked_post` terms exist. Open the Assign Reviews tab and run a search.
- Expected: Red warning message appears below the results: *"No linked post terms exist yet."* with a link to create one.

---

### Linked Posts Taxonomy

**TC-TX01 — Create a term with Linked Post ID**
- Steps: Go to Google Reviews → Linked Posts. Add a new term. Enter a WP post ID in the "Linked Post ID" field.
- Expected: Term is created. Edit screen shows "Linked to: [Post Title] (ID N)" with a link to the post editor.

**TC-TX02 — Create a term without Linked Post ID**
- Steps: Add a new term. Leave the Linked Post ID field blank.
- Expected: Term is created without error. No linked post is shown on the edit screen.

**TC-TX03 — Term slug as shortcode filter**
- Steps: Create a term with slug `jane-doe`. Assign it to several reviews. Place `[ml_google_reviews linked_to="jane-doe"]` on a page.
- Expected: Only reviews tagged with the `jane-doe` term are displayed.

---

### Shortcodes — `[ml_google_reviews]`

**TC-SC01 — Default render**
- Steps: Place `[ml_google_reviews]` on a page with published reviews in the database.
- Expected: Review cards appear in a 3-column grid. Each card shows reviewer name, date, stars, verified badge, and review text.

**TC-SC02 — `layout="slider"`**
- Steps: Place `[ml_google_reviews layout="slider"]`.
- Expected: Horizontal slider with Prev/Next arrow buttons. Buttons are hidden when all cards fit without scrolling.

**TC-SC03 — `layout="masonry"`**
- Steps: Place `[ml_google_reviews layout="masonry"]`.
- Expected: Cards arranged in 3 columns with variable height (no forced equal heights).

**TC-SC04 — `limit="5"`**
- Steps: Place `[ml_google_reviews limit="5"]` with more than 5 published reviews.
- Expected: Exactly 5 review cards are displayed.

**TC-SC05 — `min_rating="4"`**
- Steps: Place `[ml_google_reviews min_rating="4"]`. Ensure 1–3 star reviews exist as published posts.
- Expected: Only 4- and 5-star reviews appear.

**TC-SC06 — `exclude_ratings="1,2,3"`**
- Steps: Place `[ml_google_reviews exclude_ratings="1,2,3"]`.
- Expected: Same result as `min_rating="4"` — only 4 and 5 star reviews. Confirms the alternative filter parameter works.

**TC-SC07 — `max_chars="80"`**
- Steps: Place `[ml_google_reviews max_chars="80"]` with reviews longer than 80 characters.
- Expected: Review text is cut off at ~80 characters followed by `…`. Shorter reviews are not truncated. "Read more" link appears when truncated and a review URL exists.

**TC-SC08 — `linked_to` filter**
- Steps: Assign reviews to term `jane-doe`. Place `[ml_google_reviews linked_to="jane-doe"]`.
- Expected: Only reviews tagged with `jane-doe` appear.

**TC-SC09 — `location_id` filter**
- Steps: Have two locations with reviews. Place `[ml_google_reviews location_id="2"]`.
- Expected: Only reviews from location ID 2 appear.

**TC-SC10 — Combined parameters**
- Steps: Place `[ml_google_reviews linked_to="jane-doe" layout="grid" min_rating="4" limit="6" max_chars="200"]`.
- Expected: At most 6 reviews, all 4-star or above, all tagged `jane-doe`, text capped at 200 chars, in a 3-column grid.

**TC-SC11 — No matching reviews**
- Steps: Place `[ml_google_reviews min_rating="5" linked_to="nobody"]`.
- Expected: Empty state block renders: *"No reviews found for the selected filters."* No PHP warnings or blank output.

**TC-SC12 — Cache hit**
- Steps: Load the shortcode page. Note the page load time. Load it again immediately.
- Expected: Second load is faster (served from transient cache). No visible difference in output.

**TC-SC13 — Cache flush after sync**
- Steps: Load shortcode page. Force Resync the location. Wait for sync to complete. Reload the page.
- Expected: Fresh data appears (cache was flushed on sync completion).

**TC-SC14 — Cache flush after bulk assign**
- Steps: Load shortcode page with `linked_to` filter. Assign more reviews via Assign Reviews tab. Reload the page.
- Expected: Newly assigned reviews appear immediately (cache was flushed on assign).

**TC-SC15 — Anonymize Reviewers on frontend**
- Steps: Enable Anonymize Reviewers in Settings. Load shortcode page.
- Expected: All author names are "Google User". Avatar images are replaced by the initial placeholder. The initial shown is "G" (first letter of "Google User").

---

### Shortcode — `[ml_google_rating]`

**TC-RT01 — Default (no location_id)**
- Steps: Place `[ml_google_rating]` on a page.
- Expected: Renders text like *"4.7 / 5 based on 120 reviews"* wrapped in `<span class="mlgr-rating-text">`. Uses data from the first (lowest ID) location.

**TC-RT02 — Specific location**
- Steps: Place `[ml_google_rating location_id="2"]`.
- Expected: Shows average rating and review count specific to location 2.

**TC-RT03 — Singular "review"**
- Steps: Ensure a location has `total_reviews = 1`. Place `[ml_google_rating location_id="X"]`.
- Expected: Output reads *"N / 5 based on 1 review"* (singular, no "s").

**TC-RT04 — No locations in DB**
- Steps: Delete all locations. Place `[ml_google_rating]`.
- Expected: Shortcode outputs nothing (empty string). No error displayed on the page.

---

### CPT Admin List

**TC-CPT01 — Rating column visible**
- Steps: Go to Google Reviews in the WP admin sidebar.
- Expected: A "Rating" column appears between Title and other columns. Each row shows filled/empty stars and the numeric rating in parentheses.

**TC-CPT02 — Sort by rating ascending**
- Steps: Click the Rating column header once.
- Expected: Reviews sorted from lowest to highest star rating.

**TC-CPT03 — Sort by rating descending**
- Steps: Click the Rating column header a second time.
- Expected: Reviews sorted from highest to lowest star rating.

**TC-CPT04 — Rating meta box**
- Steps: Click to edit any `mlgr_review` post.
- Expected: A "Rating" meta box in the sidebar shows the star display (e.g. ★★★★☆) and the text "4 out of 5".

---

### Dashboard Widget

**TC-DW01 — Widget visible to admin**
- Steps: Log in as an Administrator. Go to the WordPress Dashboard.
- Expected: "Multi-Location Reviews Summary" widget is present.

**TC-DW02 — Correct review count**
- Steps: Note the "Total Reviews Managed" number in the widget. Go to Google Reviews CPT list and count published posts.
- Expected: Both numbers match.

**TC-DW03 — Widget hidden from non-admin**
- Steps: Log in as an Editor or lower role. Go to the Dashboard.
- Expected: The "Multi-Location Reviews Summary" widget is not visible.

---

## 12. Regression Checklist

After making any code change, verify these baseline behaviors still work:

- [ ] Plugin activates without fatal errors
- [ ] Settings page loads on all five tabs without errors
- [ ] Adding a location row inserts correctly and queues a sync
- [ ] Force Resync schedules a new Action Scheduler job
- [ ] Delete removes the location and all associated CPT posts
- [ ] `[ml_google_reviews]` renders without PHP errors on a page
- [ ] `[ml_google_rating]` renders without PHP errors on a page
- [ ] Sync Logs tab loads and Clear Logs works
- [ ] Assign Reviews search returns results and bulk assign works
- [ ] No JavaScript console errors on the frontend shortcode page or admin settings page
