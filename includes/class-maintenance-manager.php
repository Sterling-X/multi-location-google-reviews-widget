<?php
/**
 * Handles daily maintenance tasks and dashboard insights.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Maintenance_Manager {

	/**
	 * WP-Cron hook for daily location resync.
	 */
	const DAILY_SYNC_HOOK = 'mlgr_daily_sync_locations';

	/**
	 * Register runtime hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::DAILY_SYNC_HOOK, array( __CLASS__, 'run_daily_sync' ) );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'register_dashboard_widget' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule_daily_sync' ) );
	}

	/**
	 * Activation callback.
	 *
	 * @return void
	 */
	public static function activate() {
		self::maybe_schedule_daily_sync();
	}

	/**
	 * Deactivation callback.
	 *
	 * @return void
	 */
	public static function deactivate() {
		$timestamp = wp_next_scheduled( self::DAILY_SYNC_HOOK );

		while ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, self::DAILY_SYNC_HOOK );
			$timestamp = wp_next_scheduled( self::DAILY_SYNC_HOOK );
		}
	}

	/**
	 * Ensure the daily cron event exists.
	 *
	 * @return void
	 */
	public static function maybe_schedule_daily_sync() {
		if ( ! wp_next_scheduled( self::DAILY_SYNC_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'daily', self::DAILY_SYNC_HOOK );
		}
	}

	/**
	 * Daily job: queue a fresh sync for every location.
	 *
	 * @return void
	 */
	public static function run_daily_sync() {
		global $wpdb;

		$locations_table = $wpdb->prefix . 'mlgr_locations';
		if ( ! self::table_exists( $locations_table ) ) {
			return;
		}

		$location_ids = $wpdb->get_col( "SELECT id FROM {$locations_table}" );
		if ( empty( $location_ids ) || ! is_array( $location_ids ) ) {
			return;
		}

		foreach ( $location_ids as $location_id ) {
			Review_Syncer::schedule_initial_sync( absint( $location_id ) );
		}
	}

	/**
	 * Register dashboard widget.
	 *
	 * @return void
	 */
	public static function register_dashboard_widget() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'mlgr_dashboard_widget',
			'Multi-Location Reviews Summary',
			array( __CLASS__, 'render_dashboard_widget' )
		);
	}

	/**
	 * Render dashboard widget content.
	 *
	 * @return void
	 */
	public static function render_dashboard_widget() {
		global $wpdb;

		$locations_table = $wpdb->prefix . 'mlgr_locations';

		$total_reviews      = 0;
		$latest_sync_status = 'N/A';
		$latest_sync_time   = '';

		$counts        = wp_count_posts( CPT_Manager::POST_TYPE );
		$total_reviews = isset( $counts->publish ) ? (int) $counts->publish : 0;

		if ( self::table_exists( $locations_table ) ) {
			$latest = $wpdb->get_row(
				"SELECT sync_status, last_sync
				FROM {$locations_table}
				ORDER BY COALESCE(last_sync, '0000-00-00 00:00:00') DESC, id DESC
				LIMIT 1",
				ARRAY_A
			);

			if ( is_array( $latest ) && ! empty( $latest['sync_status'] ) ) {
				$latest_sync_status = (string) $latest['sync_status'];
			}

			if ( is_array( $latest ) && ! empty( $latest['last_sync'] ) ) {
				$latest_sync_time = mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (string) $latest['last_sync'] );
			}
		}
		?>
		<p><strong>Total Reviews Managed:</strong> <?php echo esc_html( (string) $total_reviews ); ?></p>
		<p><strong>Latest Sync Status:</strong> <?php echo esc_html( $latest_sync_status ); ?></p>
		<?php if ( '' !== $latest_sync_time ) : ?>
			<p><strong>Latest Sync Time:</strong> <?php echo esc_html( $latest_sync_time ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Check if a DB table exists.
	 *
	 * @param string $table_name Table name.
	 * @return bool
	 */
	private static function table_exists( $table_name ) {
		global $wpdb;

		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$table_name
			)
		);

		return $found === $table_name;
	}
}

