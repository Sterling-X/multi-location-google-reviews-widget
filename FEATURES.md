# Multi-Location Google Reviews Widget — Feature Roadmap

A living document of planned improvements and new features for the plugin.

---

## Priority 1 — Features in Active Development

### 1. Additional Review Display Layouts

**Current state:** Three layouts exist — `grid`, `slider`, and `masonry` — all rendered with inline CSS and basic HTML structure. The slider has no JavaScript carousel behavior.

**Planned layouts:**

- **Carousel/Slider (proper)** — A functional JavaScript-powered carousel with prev/next arrows, dot indicators, autoplay, and touch/swipe support (e.g. using Swiper.js or a lightweight custom implementation).
- **List** — A single-column vertical list, suitable for sidebars or narrow containers. Each review is a full-width row with photo, name, stars, and text side by side.
- **Featured / Hero** — One large highlighted review displayed prominently, good for landing pages. Optionally cycle through reviews automatically.
- **Compact / Inline** — A minimal, text-only layout without cards. Useful for embedding reviews inside content blocks.
- **Testimonials Wall** — A CSS columns-based multi-column layout (similar to masonry but using CSS `column-count`) with no JavaScript dependency.
- **Badge / Trust Bar** — A horizontal strip displaying star rating + reviewer name in a compact row, suitable for site headers/footers.

**Implementation notes:**
- Layouts are resolved in `Review_Shortcode::normalize_layout()` — add new slugs to the allowed list there.
- Each layout should be a separate private render method in `class-review-shortcode.php`.
- Enqueue layout-specific CSS/JS conditionally (only when that layout is used) using `wp_enqueue_style` / `wp_enqueue_script` inside `render()`.
- Move all inline styles out of PHP into proper `.css` files under an `assets/css/` directory.

---

### 2. Place Search via SerpApi Google Maps API (Replace Manual ID Entry)

**Current state:** The admin must manually type a Google Place ID or SerpApi Data ID into a plain text field in the Locations tab. There is no validation or preview before adding.

**Planned improvement:** Replace the text input with a live search experience.

**How it should work:**
1. Admin types a business name (and optionally a city) into a search field in the Locations tab.
2. An AJAX request hits a new WordPress admin-ajax endpoint (`mlgr_search_places`).
3. The endpoint calls SerpApi's `google_maps` engine with `type=search` and the query string.
4. Results (name, address, Place ID, rating) are returned as JSON and displayed as a dropdown list below the input.
5. Admin clicks a result to select it — the Place ID is auto-filled into a hidden field.
6. Admin clicks "Add Location" to confirm.

**Key implementation points:**
- New AJAX handler: `wp_ajax_mlgr_search_places` in `Admin_Settings_Page`.
- New method in `SerpApi_Fetcher`: `search_places( $query )` using `engine=google_maps&type=search&q={query}`.
- Nonce-protect the AJAX endpoint.
- Add a small JS file (`assets/js/admin-place-search.js`) enqueued only on the plugin's settings page via `admin_enqueue_scripts`.
- Debounce the search input (300–500ms) to avoid excessive API calls.
- Show a loading indicator while the request is in flight.
- Gracefully handle errors (no API key set, API limit reached, no results).
- Display search result fields: business name, formatted address, and rating (if available).

---

### 3. Visual Shortcode Builder (No Manual Shortcode Writing)

**Current state:** Users must read the shortcode documentation in the Welcome tab and manually construct shortcode strings. Parameters like `exclude_ratings` are not intuitive.

**Planned improvement:** A new **Shortcode Builder** tab in the admin settings page where users configure all options visually and the plugin generates the shortcode automatically.

**Tab name:** `Shortcode Builder` (slug: `shortcode-builder`)

**UI sections:**

**Location**
- Dropdown: select a location (populated from `mlgr_locations` table) or "All Locations"

**Filters**
- Minimum rating: star-picker or number input (0–5)
- Exclude ratings: checkboxes for 1★ through 5★
- Limit: number input or "Show all" toggle

**Display**
- Layout: visual card/button picker showing a preview icon for each layout (grid, slider, masonry, list, etc.)
- Max characters: range slider with live character count preview

**Live Shortcode Output**
- A read-only `<code>` block that updates in real time as the user changes options
- A "Copy to Clipboard" button next to the output

**Implementation notes:**
- Add `TAB_SHORTCODE_BUILDER = 'shortcode-builder'` constant and register it in `render_tabs()` and `get_active_tab()`.
- Add `render_shortcode_builder_tab()` private method to `Admin_Settings_Page`.
- All shortcode generation logic lives in JavaScript (`assets/js/shortcode-builder.js`) — no page reload needed.
- The JS reads current form values and composes the shortcode string, omitting parameters that match their defaults (keep output clean).
- Enqueue the JS only on the plugin settings page.

---

## Priority 2 — UX and Admin Improvements

### 4. Delete Location

**Current state:** There is no way to remove a location from the admin UI. Locations can only be deleted directly from the database.

**Planned:** Add a "Delete" button in the Existing Locations table. Clicking it shows a confirmation dialog, then POSTs to a new `admin_post_mlgr_delete_location` handler which deletes the location row (reviews cascade-delete automatically via the FK constraint).

---

### 5. Per-Review Visibility Toggle (Show/Hide Individual Reviews)

**Current state:** The `mlgr_reviews` table has an `is_hidden` column, but there is no admin UI to toggle it. Hidden reviews are never shown on the frontend, but admins cannot hide specific reviews without direct DB access.

**Planned:** Add a Reviews management tab (or expand the Locations tab) where admins can:
- Browse all synced reviews, filterable by location and rating
- Toggle individual reviews hidden/visible with a checkbox or button
- Bulk-hide/show by rating or keyword

---

### 6. Review Counts and Average Rating Display in Admin

**Current state:** The Locations table shows "Total Reviews" (count from the local DB) but not the live average rating or a breakdown by star level.

**Planned:** Show `average_rating` alongside review count in the Locations table. Optionally add a small star distribution bar (e.g. "5★ ██████ 60%").

---

### 7. Sync Status Live Refresh

**Current state:** After clicking "Force Resync", the admin must manually refresh the page to see updated sync status. There is no feedback that the background job is running.

**Planned:** After triggering a resync, poll the sync status via AJAX every few seconds and update the status cell in the table without a full page reload. Show a spinner while status is `active` or `pending`.

---

### 8. Sync Frequency Control

**Current state:** Reviews sync once daily via WP-Cron (`mlgr_daily_sync_locations`). The interval is hardcoded.

**Planned:** Add a settings option to control sync frequency: Every 6 hours / Every 12 hours / Daily (default) / Weekly. Store in a `mlgr_sync_interval` option and register the cron schedule dynamically.

---

### 9. Per-Location Name Override

**Current state:** The location name is pulled from the SerpApi response and stored in the DB. There is no way to override it in the admin.

**Planned:** Add an editable "Display Name" field per location in the admin table (inline edit or a modal). Store as a separate `display_name` column in `mlgr_locations`. The shortcode uses `display_name` when available, falling back to the synced `name`.

---

## Priority 3 — Frontend and Display Improvements

### 10. Schema.org Structured Data Markup

**Current state:** Reviews are rendered as plain HTML with no structured data.

**Planned:** Output `ReviewSnippet` and `AggregateRating` JSON-LD structured data alongside the shortcode output. This can improve Google rich results (star ratings in search snippets).

---

### 11. "Read More" Expand Toggle

**Current state:** Long reviews are truncated at `max_chars` with no way for the visitor to read the full text.

**Planned:** Add a "Read more" link after truncated text that expands the card to show the full review inline (JS toggle, no page reload). Add a "Show less" link to collapse it again.

---

### 12. Separate CSS/JS Asset Files

**Current state:** All styles are inline `<style>` blocks inside PHP render methods. No JavaScript files exist.

**Planned:**
- Create `assets/css/frontend.css` for all frontend widget styles.
- Create `assets/css/admin.css` for admin-side styles.
- Create `assets/js/` for any frontend interactivity (slider, read-more toggle, etc.).
- Enqueue assets properly using `wp_enqueue_style` / `wp_enqueue_script` with version strings for cache busting.
- Use `wp_enqueue_style` only on pages/posts where the shortcode is actually used (check with `has_shortcode()`).

---

### 13. Shortcode for Individual Location Widget (with Location Header)

**Current state:** `[ml_google_rating]` shows a simple text summary. `[ml_google_reviews]` shows cards but no location branding.

**Planned:** A new `[ml_location_card]` shortcode (or parameter on existing shortcode) that shows a location header section — business name, photo, overall star rating, and total review count — above the review cards.

---

## Priority 4 — Technical / Under-the-Hood Improvements

### 14. WP-CLI Commands

Add WP-CLI support for common tasks:
- `wp mlgr sync` — trigger sync for all or a specific location
- `wp mlgr locations list` — list all locations with status
- `wp mlgr reviews count` — show review counts per location
- `wp mlgr logs clear` — clear error logs

---

### 15. Transient Cache Invalidation on Sync Complete

**Current state:** The shortcode uses 1-hour transient caching. After a sync completes, stale cached HTML may be served for up to 1 hour.

**Planned:** At the end of a successful sync (when `sync_status` is set to `completed`), delete all transient keys for that location so the frontend reflects fresh data immediately. Transient key patterns use `mlgr_shortcode_` prefix.

---

### 16. Settings Export / Import

Allow admins to export their plugin configuration (API key, location list, display settings) as a JSON file and re-import it on another site. Useful for staging-to-production workflows.

---

### 17. Error Notification Email

**Current state:** Sync errors are logged silently. Admins only see them if they check the Sync Logs tab.

**Planned:** Optional email notification when a location sync fails. Configurable recipient (default: admin email). Include error message, location name, and timestamp. Throttle to max one email per location per 24 hours to avoid spam.

---

## Notes

- All new admin-facing JavaScript should be enqueued via `admin_enqueue_scripts` with the `mlgr-settings` page hook suffix to avoid loading on every admin page.
- All new AJAX handlers must use nonces and `current_user_can('manage_options')` checks.
- Cache keys should be versioned (e.g. bump the `template` version string in `class-review-shortcode.php`) when rendering logic changes, to auto-invalidate stale cache on plugin update.
- New database columns should be added via the schema version migration system in `class-database-installer.php`.
