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
	 * Main locations tab slug.
	 */
	const TAB_LOCATIONS = 'locations';

	/**
	 * Sync logs tab slug.
	 */
	const TAB_SYNC_LOGS = 'sync-logs';

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

			<?php if ( self::TAB_SYNC_LOGS === $active_tab ) : ?>
				<?php self::render_sync_logs_tab(); ?>
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
			self::TAB_LOCATIONS => 'Locations',
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
				$tab_class = self::TAB_LOCATIONS === $tab_slug && '' === $active_tab ? 'nav-tab nav-tab-active' : 'nav-tab';
				if ( $tab_slug === $active_tab ) {
					$tab_class = 'nav-tab nav-tab-active';
				}
				?>
				<a href="<?php echo esc_url( $tab_url ); ?>" class="<?php echo esc_attr( $tab_class ); ?>">
					<?php echo esc_html( $tab_label ); ?>
				</a>
			<?php endforeach; ?>
		</h2>
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
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : self::TAB_LOCATIONS;
		if ( ! in_array( $tab, array( self::TAB_LOCATIONS, self::TAB_SYNC_LOGS ), true ) ) {
			return self::TAB_LOCATIONS;
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
	 * Redirect back to settings page with a notice.
	 *
	 * @param string $message Notice message.
	 * @param string $type    success|error|warning|info.
	 * @param string $tab     Tab slug.
	 * @return void
	 */
	private static function redirect_with_notice( $message, $type, $tab = self::TAB_LOCATIONS ) {
		$tab = sanitize_key( $tab );
		if ( ! in_array( $tab, array( self::TAB_LOCATIONS, self::TAB_SYNC_LOGS ), true ) ) {
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
