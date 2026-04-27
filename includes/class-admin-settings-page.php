<?php
/**
 * Admin settings and location management page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin_Settings_Page {

	/**
	 * Settings page slug.
	 */
	const PAGE_SLUG = 'mlgr-settings';

	/**
	 * Welcome tab slug.
	 */
	const TAB_WELCOME = 'welcome';

	/**
	 * Main locations tab slug.
	 */
	const TAB_LOCATIONS = 'locations';

	/**
	 * Sync logs tab slug.
	 */
	const TAB_SYNC_LOGS = 'sync-logs';

	/**
	 * Assign reviews tab slug.
	 */
	const TAB_ASSIGN = 'assign-reviews';

	/**
	 * Option key for reviewer anonymization.
	 */
	const ANONYMIZE_REVIEWERS_OPTION = 'mlgr_anonymize_reviewers';

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_mlgr_save_settings', array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'admin_post_mlgr_add_location', array( __CLASS__, 'handle_add_location' ) );
		add_action( 'admin_post_mlgr_force_resync', array( __CLASS__, 'handle_force_resync' ) );
		add_action( 'admin_post_mlgr_clear_logs', array( __CLASS__, 'handle_clear_logs' ) );
		add_action( 'admin_post_mlgr_bulk_assign', array( __CLASS__, 'handle_bulk_assign' ) );
	}

	/**
	 * Add settings page under WordPress Settings menu.
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_options_page(
			'Multi-Location Reviews',
			'Multi-Location Reviews',
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render settings and location management page.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$active_tab = self::get_active_tab();
		?>
		<div class="wrap">
			<h1>Multi-Location Google Reviews Widget</h1>
			<?php self::render_notice(); ?>
			<?php self::render_tabs( $active_tab ); ?>

			<?php if ( self::TAB_WELCOME === $active_tab ) : ?>
				<?php self::render_welcome_tab(); ?>
			<?php elseif ( self::TAB_SYNC_LOGS === $active_tab ) : ?>
				<?php self::render_sync_logs_tab(); ?>
			<?php elseif ( self::TAB_ASSIGN === $active_tab ) : ?>
				<?php self::render_assign_tab(); ?>
			<?php else : ?>
				<?php
				$api_key             = get_option( SerpApi_Fetcher::API_KEY_OPTION, '' );
				$anonymize_reviewers = (bool) get_option( self::ANONYMIZE_REVIEWERS_OPTION, false );
				$locations           = self::get_locations_with_counts();
				self::render_locations_tab( $api_key, $anonymize_reviewers, $locations );
				?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render top-level tabs.
	 *
	 * @param string $active_tab Current tab slug.
	 * @return void
	 */
	private static function render_tabs( $active_tab ) {
		$tabs = array(
			self::TAB_WELCOME   => 'Welcome',
			self::TAB_LOCATIONS => 'Locations',
			self::TAB_ASSIGN    => 'Assign Reviews',
			self::TAB_SYNC_LOGS => 'Sync Logs',
		);
		?>
		<h2 class="nav-tab-wrapper" style="margin-bottom: 18px;">
			<?php foreach ( $tabs as $tab_slug => $tab_label ) : ?>
				<?php
				$tab_url = add_query_arg(
					array(
						'page' => self::PAGE_SLUG,
						'tab'  => $tab_slug,
					),
					admin_url( 'options-general.php' )
				);
				$tab_class = $tab_slug === $active_tab ? 'nav-tab nav-tab-active' : 'nav-tab';
				?>
				<a href="<?php echo esc_url( $tab_url ); ?>" class="<?php echo esc_attr( $tab_class ); ?>">
					<?php echo esc_html( $tab_label ); ?>
				</a>
			<?php endforeach; ?>
		</h2>
		<?php
	}

	/**
	 * Render welcome/help tab content.
	 *
	 * @return void
	 */
	private static function render_welcome_tab() {
		$assign_url    = add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => self::TAB_ASSIGN ), admin_url( 'options-general.php' ) );
		$locations_url = add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => self::TAB_LOCATIONS ), admin_url( 'options-general.php' ) );
		$taxonomy_url  = admin_url( 'edit-tags.php?taxonomy=' . CPT_Manager::TAXONOMY . '&post_type=' . CPT_Manager::POST_TYPE );
		?>
		<h2>Welcome</h2>
		<p>
			This plugin syncs Google reviews from one or many business locations via SerpApi and displays them on any page using shortcodes.
			Reviews are stored as a WordPress custom post type (<code>mlgr_review</code>) so they can be tagged, filtered, and linked to any page or CPT post on your site.
		</p>

		<h3>Quick Start</h3>
		<ol>
			<li>Go to <a href="<?php echo esc_url( $locations_url ); ?>"><strong>Locations</strong></a> and enter your SerpApi key.</li>
			<li>Add one or more business locations using a Google Place ID or SerpApi Data ID.</li>
			<li>Wait for the background sync to complete, or click <strong>Force Resync</strong> for an immediate refresh.</li>
			<li>Place a shortcode on any page or post to display your reviews.</li>
		</ol>

		<h3 style="margin-top: 28px;">Linking Reviews to People or Entities</h3>
		<p>
			Reviews can be attached to any page or CPT post on your site (e.g. an attorney, a doctor, a real estate agent) using the
			<strong>Linked Posts</strong> taxonomy. This lets you display a specific person's reviews anywhere using the <code>linked_to</code> shortcode parameter.
		</p>
		<ol>
			<li>
				Go to <a href="<?php echo esc_url( $taxonomy_url ); ?>"><strong>Google Reviews &rarr; Linked Posts</strong></a> and create a term for each person or entity
				(e.g. name: <em>Jane Doe</em>, slug: <em>jane-doe</em>). Optionally enter the WP post ID of their page to create a link.
			</li>
			<li>
				Go to the <a href="<?php echo esc_url( $assign_url ); ?>"><strong>Assign Reviews</strong></a> tab, search for reviews mentioning that person,
				and bulk-assign them to the term you just created.
			</li>
			<li>
				Use the <code>linked_to</code> parameter in your shortcode to display only their reviews:
				<br /><br />
				<code>[ml_google_reviews linked_to="jane-doe"]</code>
			</li>
		</ol>

		<h3 style="margin-top: 28px;">Shortcodes</h3>
		<p>Two shortcodes are available: <code>[ml_google_reviews]</code> renders review cards and <code>[ml_google_rating]</code> renders a plain-text average rating summary.</p>

		<h4 style="margin-top: 18px;">1) Reviews Widget &mdash; <code>[ml_google_reviews]</code></h4>
		<table class="widefat striped">
			<thead>
				<tr>
					<th style="width: 180px;">Parameter</th>
					<th>Description</th>
					<th style="width: 340px;">Example</th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><code>linked_to</code></td>
					<td>Show only reviews tagged with this <strong>Linked Post</strong> term slug. Use this to display reviews for a specific person or entity.</td>
					<td><code>[ml_google_reviews linked_to="jane-doe"]</code></td>
				</tr>
				<tr>
					<td><code>location_id</code></td>
					<td>Filter by internal location ID. Use <code>0</code> (default) to show reviews from all locations.</td>
					<td><code>[ml_google_reviews location_id="3"]</code></td>
				</tr>
				<tr>
					<td><code>limit</code></td>
					<td>Maximum number of reviews to display. Use <code>all</code> (default) for no limit.</td>
					<td><code>[ml_google_reviews limit="9"]</code></td>
				</tr>
				<tr>
					<td><code>min_rating</code></td>
					<td>Minimum star rating to include. Accepts <code>0</code>&ndash;<code>5</code> (default <code>0</code>).</td>
					<td><code>[ml_google_reviews min_rating="4"]</code></td>
				</tr>
				<tr>
					<td><code>exclude_ratings</code></td>
					<td>Comma-separated list of star ratings to exclude. <code>1,2,3</code> shows only 4- and 5-star reviews.</td>
					<td><code>[ml_google_reviews exclude_ratings="1,2,3"]</code></td>
				</tr>
				<tr>
					<td><code>max_chars</code></td>
					<td>Character limit before review text is truncated. Minimum <code>60</code>, maximum <code>1000</code> (default <code>150</code>).</td>
					<td><code>[ml_google_reviews max_chars="200"]</code></td>
				</tr>
				<tr>
					<td><code>layout</code></td>
					<td>Display style: <code>grid</code> (default), <code>slider</code>, or <code>masonry</code>.</td>
					<td><code>[ml_google_reviews layout="masonry"]</code></td>
				</tr>
			</tbody>
		</table>

		<p style="margin-top: 12px;">
			<strong>Combined example:</strong><br />
			<code>[ml_google_reviews linked_to="jane-doe" layout="grid" min_rating="4" limit="6" max_chars="200"]</code>
		</p>

		<h4 style="margin-top: 26px;">2) Rating Summary &mdash; <code>[ml_google_rating]</code></h4>
		<p>Renders a plain-text average rating, e.g. <em>4.7 / 5 based on 120 reviews</em>.</p>
		<table class="widefat striped">
			<thead>
				<tr>
					<th style="width: 180px;">Parameter</th>
					<th>Description</th>
					<th style="width: 340px;">Example</th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><code>location_id</code></td>
					<td>Internal location ID. If omitted, the first location in the database is used.</td>
					<td><code>[ml_google_rating location_id="2"]</code></td>
				</tr>
			</tbody>
		</table>

		<p style="margin-top: 12px;">
			<code>[ml_google_rating]</code> &nbsp;&nbsp; <code>[ml_google_rating location_id="2"]</code>
		</p>

		<h3 style="margin-top: 28px;">How Reviews Are Stored</h3>
		<p>
			Synced reviews are stored as <code>mlgr_review</code> WordPress posts. Each review holds the author name, review text, star rating,
			reviewer photo, and the originating location as post meta. Business location operational data (sync status, average rating, total review count)
			is kept in the <code>wp_mlgr_locations</code> database table.
		</p>
		<p>
			The <strong>Linked Posts</strong> taxonomy (<code>mlgr_linked_post</code>) is used to tag reviews with any page or CPT post on your site.
			Term slugs are the values you pass to the <code>linked_to</code> shortcode parameter.
		</p>
		<?php
	}

	/**
	 * Render locations tab (settings + location list).
	 *
	 * @param string $api_key             SerpApi key.
	 * @param bool   $anonymize_reviewers Reviewer anonymization flag.
	 * @param array  $locations           Location rows.
	 * @return void
	 */
	private static function render_locations_tab( $api_key, $anonymize_reviewers, $locations ) {
		?>
		<style>
			.mlgr-error-badge {
				display: inline-block;
				margin-left: 8px;
				padding: 2px 8px;
				border-radius: 999px;
				font-size: 11px;
				font-weight: 600;
				color: #ffffff;
				background: #d63638;
				vertical-align: middle;
			}
		</style>

		<h2>SerpApi Settings</h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="mlgr_save_settings" />
			<?php wp_nonce_field( 'mlgr_save_settings', 'mlgr_settings_nonce' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="serpapi_key">serpapi_key</label>
					</th>
					<td>
						<input
							type="password"
							id="serpapi_key"
							name="serpapi_key"
							value="<?php echo esc_attr( is_string( $api_key ) ? $api_key : '' ); ?>"
							class="regular-text"
							autocomplete="new-password"
						/>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="anonymize_reviewers">Anonymize Reviewers</label>
					</th>
					<td>
						<label for="anonymize_reviewers">
							<input
								type="checkbox"
								id="anonymize_reviewers"
								name="anonymize_reviewers"
								value="1"
								<?php checked( $anonymize_reviewers ); ?>
							/>
							Display "Google User" instead of real reviewer names and hide avatars.
						</label>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Save Settings' ); ?>
		</form>

		<hr />

		<h2>Locations Management</h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="mlgr_add_location" />
			<?php wp_nonce_field( 'mlgr_add_location', 'mlgr_add_location_nonce' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="google_place_id">Add New Location (Google Place ID or SerpApi Data ID)</label>
					</th>
					<td>
						<input
							type="text"
							id="google_place_id"
							name="google_place_id"
							class="regular-text"
							required
						/>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Add Location' ); ?>
		</form>

		<h2>Existing Locations</h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<th>ID</th>
					<th>Location ID (Place/Data ID)</th>
					<th>Name</th>
					<th>Total Reviews</th>
					<th>Sync Status</th>
					<th>Last Sync</th>
					<th>Last Error</th>
					<th>Actions</th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $locations ) ) : ?>
					<tr>
						<td colspan="8">No locations found.</td>
					</tr>
				<?php else : ?>
					<?php foreach ( $locations as $location ) : ?>
						<?php
						$last_error         = isset( $location['last_error'] ) ? (string) $location['last_error'] : '';
						$last_error_time    = isset( $location['last_error_at'] ) ? (string) $location['last_error_at'] : '';
						$last_error_context = isset( $location['last_error_context'] ) ? (string) $location['last_error_context'] : '';
						$last_error_label   = '' !== $last_error_time ? $last_error_time . ' - ' . $last_error : $last_error;
						$recent_error_count = isset( $location['recent_error_count'] ) ? (int) $location['recent_error_count'] : 0;
						$has_recent_errors  = ! empty( $location['has_recent_errors'] );
						?>
						<tr>
							<td><?php echo esc_html( (string) $location['id'] ); ?></td>
							<td><code><?php echo esc_html( (string) $location['google_place_id'] ); ?></code></td>
							<td>
								<?php echo esc_html( (string) $location['name'] ); ?>
								<?php if ( $has_recent_errors ) : ?>
									<span class="mlgr-error-badge" title="<?php echo esc_attr( sprintf( '%d sync error(s) in the last 24 hours.', $recent_error_count ) ); ?>">
										<?php echo esc_html( sprintf( 'Errors: %d', $recent_error_count ) ); ?>
									</span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( (string) $location['review_count'] ); ?></td>
							<td><?php echo esc_html( (string) $location['sync_status'] ); ?></td>
							<td><?php echo esc_html( (string) ( $location['last_sync'] ? $location['last_sync'] : '-' ) ); ?></td>
							<td title="<?php echo esc_attr( $last_error_context ); ?>">
								<?php echo esc_html( '' !== $last_error_label ? $last_error_label : '-' ); ?>
							</td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="mlgr_force_resync" />
									<input type="hidden" name="location_id" value="<?php echo esc_attr( (string) $location['id'] ); ?>" />
									<?php wp_nonce_field( 'mlgr_force_resync_' . $location['id'], 'mlgr_force_resync_nonce' ); ?>
									<?php submit_button( 'Force Resync', 'secondary small', 'submit', false ); ?>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render sync log management tab.
	 *
	 * @return void
	 */
	private static function render_sync_logs_tab() {
		$rows = Logger::get_recent_logs( 50 );
		?>
		<h2>Sync Logs</h2>
		<p>Shows the latest 50 SerpApi sync errors.</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom: 12px;">
			<input type="hidden" name="action" value="mlgr_clear_logs" />
			<?php wp_nonce_field( 'mlgr_clear_logs', 'mlgr_clear_logs_nonce' ); ?>
			<?php submit_button( 'Clear Logs', 'delete', 'submit', false, array( 'onclick' => "return confirm('Clear all sync logs?');" ) ); ?>
		</form>

		<table class="widefat striped">
			<thead>
				<tr>
					<th>Date</th>
					<th>Location ID</th>
					<th>Error Code</th>
					<th>Message</th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr>
						<td colspan="4">No sync errors logged.</td>
					</tr>
				<?php else : ?>
					<?php foreach ( $rows as $row ) : ?>
						<?php
						$endpoint = isset( $row['endpoint_url'] ) ? (string) $row['endpoint_url'] : '';
						$message  = isset( $row['error_message'] ) ? (string) $row['error_message'] : '';
						?>
						<tr>
							<td><?php echo esc_html( isset( $row['timestamp'] ) ? (string) $row['timestamp'] : '-' ); ?></td>
							<td><?php echo esc_html( isset( $row['location_id'] ) ? (string) absint( $row['location_id'] ) : '0' ); ?></td>
							<td><code><?php echo esc_html( isset( $row['error_code'] ) ? (string) $row['error_code'] : '' ); ?></code></td>
							<td title="<?php echo esc_attr( $endpoint ); ?>"><?php echo esc_html( '' !== $message ? $message : '-' ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Save SerpApi settings.
	 *
	 * @return void
	 */
	public static function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized request.' );
		}

		check_admin_referer( 'mlgr_save_settings', 'mlgr_settings_nonce' );

		$api_key             = isset( $_POST['serpapi_key'] ) ? sanitize_text_field( wp_unslash( $_POST['serpapi_key'] ) ) : '';
		$anonymize_reviewers = isset( $_POST['anonymize_reviewers'] ) ? 1 : 0;

		update_option( SerpApi_Fetcher::API_KEY_OPTION, $api_key );
		update_option( self::ANONYMIZE_REVIEWERS_OPTION, $anonymize_reviewers );

		self::redirect_with_notice( 'Settings saved.', 'success' );
	}

	/**
	 * Add a location row and schedule first background sync.
	 *
	 * @return void
	 */
	public static function handle_add_location() {
		global $wpdb;

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized request.' );
		}

		check_admin_referer( 'mlgr_add_location', 'mlgr_add_location_nonce' );

		$place_id = isset( $_POST['google_place_id'] ) ? sanitize_text_field( wp_unslash( $_POST['google_place_id'] ) ) : '';
		$place_id = trim( $place_id );

		if ( '' === $place_id ) {
			self::redirect_with_notice( 'Location ID is required.', 'error' );
		}

		$locations_table = $wpdb->prefix . 'mlgr_locations';

		$inserted = $wpdb->insert(
			$locations_table,
			array(
				'google_place_id' => $place_id,
				'name'            => '',
				'photo_url'       => '',
				'sync_status'     => 'pending',
			),
			array( '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			self::redirect_with_notice( 'Unable to add location. It may already exist.', 'error' );
		}

		$location_id = (int) $wpdb->insert_id;
		$scheduled   = Review_Syncer::schedule_initial_sync( $location_id );

		if ( false === $scheduled ) {
			self::redirect_with_notice( 'Location added, but initial sync could not be scheduled.', 'error' );
		}

		self::redirect_with_notice( 'Location added and sync started.', 'success' );
	}

	/**
	 * Force resync for an existing location.
	 *
	 * @return void
	 */
	public static function handle_force_resync() {
		global $wpdb;

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized request.' );
		}

		$location_id = isset( $_POST['location_id'] ) ? absint( $_POST['location_id'] ) : 0;
		if ( $location_id <= 0 ) {
			self::redirect_with_notice( 'Invalid location ID.', 'error' );
		}

		check_admin_referer( 'mlgr_force_resync_' . $location_id, 'mlgr_force_resync_nonce' );

		$locations_table = $wpdb->prefix . 'mlgr_locations';
		$exists          = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$locations_table} WHERE id = %d LIMIT 1",
				$location_id
			)
		);

		if ( ! $exists ) {
			self::redirect_with_notice( 'Location not found.', 'error' );
		}

		$wpdb->update(
			$locations_table,
			array( 'sync_status' => 'pending' ),
			array( 'id' => $location_id ),
			array( '%s' ),
			array( '%d' )
		);
		Review_Syncer::clear_sync_error( $location_id );

		$scheduled = Review_Syncer::schedule_initial_sync( $location_id );
		if ( false === $scheduled ) {
			self::redirect_with_notice( 'Unable to schedule resync.', 'error' );
		}

		self::redirect_with_notice( 'Resync scheduled.', 'success' );
	}

	/**
	 * Clear all sync logs.
	 *
	 * @return void
	 */
	public static function handle_clear_logs() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized request.' );
		}

		check_admin_referer( 'mlgr_clear_logs', 'mlgr_clear_logs_nonce' );

		$cleared = Logger::clear_logs();
		if ( ! $cleared ) {
			self::redirect_with_notice( 'Unable to clear sync logs.', 'error', self::TAB_SYNC_LOGS );
		}

		self::redirect_with_notice( 'Sync logs cleared.', 'success', self::TAB_SYNC_LOGS );
	}

	/**
	 * Get locations with aggregated review counts.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function get_locations_with_counts() {
		global $wpdb;

		$locations_table = $wpdb->prefix . 'mlgr_locations';
		$reviews_table   = $wpdb->prefix . 'mlgr_reviews';

		$query = "SELECT
				l.id,
				l.google_place_id,
				l.name,
				l.last_sync,
				l.sync_status,
				COUNT(r.id) AS review_count
			FROM {$locations_table} l
			LEFT JOIN {$reviews_table} r
				ON r.location_id = l.id
			GROUP BY l.id, l.google_place_id, l.name, l.last_sync, l.sync_status
			ORDER BY l.id DESC";

		$rows = $wpdb->get_results( $query, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$error_log            = Review_Syncer::get_error_log();
		$recent_error_counts  = Logger::get_recent_error_counts_by_location( 24 );

		foreach ( $rows as &$row ) {
			$error_key                  = isset( $row['id'] ) ? (string) absint( $row['id'] ) : '';
			$location_id                = isset( $row['id'] ) ? absint( $row['id'] ) : 0;
			$row['last_error']          = '';
			$row['last_error_at']       = '';
			$row['last_error_context']  = '';
			$row['recent_error_count']  = ( $location_id > 0 && isset( $recent_error_counts[ $location_id ] ) ) ? (int) $recent_error_counts[ $location_id ] : 0;
			$row['has_recent_errors']   = $row['recent_error_count'] > 0 ? 1 : 0;

			if ( '' === $error_key ) {
				continue;
			}

			$entry = isset( $error_log[ $error_key ] ) && is_array( $error_log[ $error_key ] ) ? $error_log[ $error_key ] : array();
			if ( empty( $entry ) ) {
				continue;
			}

			$row['last_error']         = isset( $entry['message'] ) && is_string( $entry['message'] ) ? $entry['message'] : '';
			$row['last_error_at']      = isset( $entry['time'] ) && is_string( $entry['time'] ) ? $entry['time'] : '';
			$row['last_error_context'] = isset( $entry['context'] ) && is_string( $entry['context'] ) ? $entry['context'] : '';
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Resolve currently selected tab.
	 *
	 * @return string
	 */
	private static function get_active_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : self::TAB_WELCOME;
		if ( ! in_array( $tab, array( self::TAB_WELCOME, self::TAB_LOCATIONS, self::TAB_ASSIGN, self::TAB_SYNC_LOGS ), true ) ) {
			return self::TAB_WELCOME;
		}

		return $tab;
	}

	/**
	 * Render one-time admin notice from URL params.
	 *
	 * @return void
	 */
	private static function render_notice() {
		$notice      = isset( $_GET['mlgr_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['mlgr_notice'] ) ) : '';
		$notice_type = isset( $_GET['mlgr_notice_type'] ) ? sanitize_key( wp_unslash( $_GET['mlgr_notice_type'] ) ) : 'success';

		if ( '' === $notice ) {
			return;
		}

		$class = 'notice-success';
		if ( in_array( $notice_type, array( 'error', 'warning', 'info' ), true ) ) {
			$class = 'notice-' . $notice_type;
		}

		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $class ),
			esc_html( $notice )
		);
	}

	/**
	 * Render the Assign Reviews tab.
	 *
	 * @return void
	 */
	private static function render_assign_tab() {
		global $wpdb;

		$search_term     = isset( $_GET['mlgr_s'] ) ? sanitize_text_field( wp_unslash( $_GET['mlgr_s'] ) ) : '';
		$filter_location = isset( $_GET['mlgr_location'] ) ? absint( $_GET['mlgr_location'] ) : 0;

		$linked_post_terms = get_terms(
			array(
				'taxonomy'   => CPT_Manager::TAXONOMY,
				'hide_empty' => false,
			)
		);

		$locations_table = $wpdb->prefix . 'mlgr_locations';
		$locations       = $wpdb->get_results( "SELECT id, name, google_place_id FROM {$locations_table} ORDER BY id ASC", ARRAY_A );
		$locations       = is_array( $locations ) ? $locations : array();

		$query_args = array(
			'post_type'      => CPT_Manager::POST_TYPE,
			'post_status'    => array( 'publish', 'draft' ),
			'posts_per_page' => 50,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => false,
		);

		if ( '' !== $search_term ) {
			$query_args['s'] = $search_term;
		}

		if ( $filter_location > 0 ) {
			$query_args['meta_query'] = array(
				array(
					'key'   => CPT_Manager::META_LOCATION_ID,
					'value' => $filter_location,
					'type'  => 'NUMERIC',
				),
			);
		}

		$query = ( '' !== $search_term || $filter_location > 0 ) ? new WP_Query( $query_args ) : null;
		?>
		<h2>Assign Reviews</h2>
		<p>
			Search for reviews mentioning a specific person or topic, then assign them to a <strong>Linked Post</strong> term.
			The term slug is used in the <code>linked_to</code> shortcode parameter, e.g. <code>[ml_google_reviews linked_to="jane-doe"]</code>.
		</p>

		<form method="get" action="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
			<input type="hidden" name="tab" value="<?php echo esc_attr( self::TAB_ASSIGN ); ?>" />
			<div style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; margin-bottom:16px;">
				<div>
					<label for="mlgr_s" style="display:block; font-weight:600; margin-bottom:4px;">Search reviews</label>
					<input type="text" id="mlgr_s" name="mlgr_s" value="<?php echo esc_attr( $search_term ); ?>" placeholder="e.g. Jane Doe" class="regular-text" />
				</div>
				<div>
					<label for="mlgr_location" style="display:block; font-weight:600; margin-bottom:4px;">Location</label>
					<select id="mlgr_location" name="mlgr_location">
						<option value="0">All locations</option>
						<?php foreach ( $locations as $loc ) : ?>
							<option value="<?php echo esc_attr( (string) $loc['id'] ); ?>" <?php selected( $filter_location, (int) $loc['id'] ); ?>>
								<?php echo esc_html( '' !== $loc['name'] ? $loc['name'] : $loc['google_place_id'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
				<?php submit_button( 'Search', 'secondary', 'mlgr_search_submit', false ); ?>
			</div>
		</form>

		<?php if ( null !== $query ) : ?>
			<p style="margin-bottom:10px;">
				<?php echo esc_html( sprintf( 'Found %d review(s).', $query->found_posts ) ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="mlgr_bulk_assign" />
				<input type="hidden" name="mlgr_s" value="<?php echo esc_attr( $search_term ); ?>" />
				<input type="hidden" name="mlgr_location" value="<?php echo esc_attr( (string) $filter_location ); ?>" />
				<?php wp_nonce_field( 'mlgr_bulk_assign', 'mlgr_bulk_assign_nonce' ); ?>

				<table class="widefat striped">
					<thead>
						<tr>
							<th style="width:30px;">
								<input type="checkbox" id="mlgr-check-all" title="Select all" />
							</th>
							<th>Author</th>
							<th style="width:80px;">Rating</th>
							<th>Review Excerpt</th>
							<th>Currently Assigned To</th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $query->posts ) ) : ?>
							<tr>
								<td colspan="5">No reviews found matching your search.</td>
							</tr>
						<?php else : ?>
							<?php foreach ( $query->posts as $post ) :
								$rating         = (int) get_post_meta( $post->ID, CPT_Manager::META_RATING, true );
								$excerpt        = wp_trim_words( $post->post_content, 20, '...' );
								$assigned_terms = wp_get_object_terms( $post->ID, CPT_Manager::TAXONOMY, array( 'fields' => 'slugs' ) );
								$assigned_terms = is_array( $assigned_terms ) && ! is_wp_error( $assigned_terms ) ? $assigned_terms : array();
								$stars          = str_repeat( '★', $rating ) . str_repeat( '☆', max( 0, 5 - $rating ) );
							?>
								<tr>
									<td>
										<input type="checkbox" name="review_ids[]" value="<?php echo esc_attr( (string) $post->ID ); ?>" />
									</td>
									<td><?php echo esc_html( $post->post_title ); ?></td>
									<td style="color:#f5a623;" title="<?php echo esc_attr( (string) $rating . ' stars' ); ?>">
										<?php echo esc_html( $stars ); ?>
									</td>
									<td><?php echo esc_html( $excerpt ); ?></td>
									<td>
										<?php if ( ! empty( $assigned_terms ) ) : ?>
											<code><?php echo esc_html( implode( ', ', $assigned_terms ) ); ?></code>
										<?php else : ?>
											<span style="color:#999;">—</span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>

				<?php if ( ! empty( $query->posts ) ) : ?>
					<div style="margin-top:16px; display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
						<label for="mlgr_assign_term" style="font-weight:600;">Assign selected to:</label>
						<select id="mlgr_assign_term" name="term_slug">
							<option value="">— select a linked post term —</option>
							<?php if ( ! is_wp_error( $linked_post_terms ) && ! empty( $linked_post_terms ) ) : ?>
								<?php foreach ( $linked_post_terms as $term ) : ?>
									<option value="<?php echo esc_attr( $term->slug ); ?>">
										<?php echo esc_html( $term->name . ' (' . $term->slug . ')' ); ?>
									</option>
								<?php endforeach; ?>
							<?php endif; ?>
						</select>
						<?php submit_button( 'Assign Selected', 'primary', 'submit', false ); ?>
					</div>

					<?php if ( is_wp_error( $linked_post_terms ) || empty( $linked_post_terms ) ) : ?>
						<p style="color:#d63638; margin-top:8px;">
							No linked post terms exist yet.
							<a href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=' . CPT_Manager::TAXONOMY . '&post_type=' . CPT_Manager::POST_TYPE ) ); ?>">
								Create one first.
							</a>
						</p>
					<?php endif; ?>
				<?php endif; ?>
			</form>
		<?php endif; ?>

		<script>
		(function () {
			var checkAll = document.getElementById( 'mlgr-check-all' );
			if ( ! checkAll ) { return; }
			checkAll.addEventListener( 'change', function () {
				document.querySelectorAll( 'input[name="review_ids[]"]' ).forEach( function ( box ) {
					box.checked = checkAll.checked;
				} );
			} );
		}());
		</script>
		<?php
	}

	/**
	 * Handle bulk term assignment for selected reviews.
	 *
	 * @return void
	 */
	public static function handle_bulk_assign() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized request.' );
		}

		check_admin_referer( 'mlgr_bulk_assign', 'mlgr_bulk_assign_nonce' );

		$term_slug  = isset( $_POST['term_slug'] ) ? sanitize_key( wp_unslash( $_POST['term_slug'] ) ) : '';
		$review_ids = isset( $_POST['review_ids'] ) && is_array( $_POST['review_ids'] )
			? array_map( 'absint', $_POST['review_ids'] )
			: array();
		$search     = isset( $_POST['mlgr_s'] ) ? sanitize_text_field( wp_unslash( $_POST['mlgr_s'] ) ) : '';
		$location   = isset( $_POST['mlgr_location'] ) ? absint( $_POST['mlgr_location'] ) : 0;

		if ( '' === $term_slug ) {
			self::redirect_with_notice( 'Please select a linked post term.', 'error', self::TAB_ASSIGN );
		}

		$term = get_term_by( 'slug', $term_slug, CPT_Manager::TAXONOMY );
		if ( ! $term || is_wp_error( $term ) ) {
			self::redirect_with_notice( 'The selected term does not exist.', 'error', self::TAB_ASSIGN );
		}

		if ( empty( $review_ids ) ) {
			self::redirect_with_notice( 'No reviews were selected.', 'error', self::TAB_ASSIGN );
		}

		$count = 0;
		foreach ( $review_ids as $post_id ) {
			if ( $post_id <= 0 ) {
				continue;
			}
			$post = get_post( $post_id );
			if ( ! $post || CPT_Manager::POST_TYPE !== $post->post_type ) {
				continue;
			}
			$result = wp_set_object_terms( $post_id, $term_slug, CPT_Manager::TAXONOMY, true );
			if ( ! is_wp_error( $result ) ) {
				++$count;
			}
		}

		Review_Shortcode::flush_cache();

		$redirect_url = add_query_arg(
			array(
				'page'             => self::PAGE_SLUG,
				'tab'              => self::TAB_ASSIGN,
				'mlgr_notice'      => sprintf( '%d review(s) assigned to "%s".', $count, $term->name ),
				'mlgr_notice_type' => 'success',
				'mlgr_s'           => rawurlencode( $search ),
				'mlgr_location'    => $location,
			),
			admin_url( 'options-general.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Redirect back to settings page with a notice.
	 *
	 * @param string $message Notice message.
	 * @param string $type    success|error|warning|info.
	 * @param string $tab     Tab slug.
	 * @return void
	 */
	private static function redirect_with_notice( $message, $type, $tab = self::TAB_LOCATIONS ) {
		$tab = sanitize_key( $tab );
		if ( ! in_array( $tab, array( self::TAB_WELCOME, self::TAB_LOCATIONS, self::TAB_ASSIGN, self::TAB_SYNC_LOGS ), true ) ) {
			$tab = self::TAB_LOCATIONS;
		}

		$redirect_url = add_query_arg(
			array(
				'page'             => self::PAGE_SLUG,
				'tab'              => $tab,
				'mlgr_notice'      => $message,
				'mlgr_notice_type' => sanitize_key( $type ),
			),
			admin_url( 'options-general.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}
}
