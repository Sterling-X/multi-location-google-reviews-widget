<?php
/**
 * Error logging helper for SerpApi sync failures.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Logger {

	/**
	 * WP-Cron hook used to prune old log rows.
	 */
	const PRUNE_HOOK = 'mlgr_prune_error_logs_daily';

	/**
	 * Maximum rows shown in admin log table.
	 */
	const DEFAULT_LOG_LIMIT = 50;

	/**
	 * Maximum stored error code length.
	 */
	const MAX_ERROR_CODE_LENGTH = 191;

	/**
	 * Maximum stored endpoint URL length.
	 */
	const MAX_ENDPOINT_URL_LENGTH = 255;

	/**
	 * Maximum stored error message length.
	 */
	const MAX_ERROR_MESSAGE_LENGTH = 10000;

	/**
	 * Register runtime hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_create_table' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule_prune' ) );
		add_action( self::PRUNE_HOOK, array( __CLASS__, 'prune_logs' ) );
	}

	/**
	 * Activation callback.
	 *
	 * @return void
	 */
	public static function activate() {
		self::maybe_create_table();
		self::maybe_schedule_prune();
	}

	/**
	 * Deactivation callback.
	 *
	 * @return void
	 */
	public static function deactivate() {
		$timestamp = wp_next_scheduled( self::PRUNE_HOOK );

		while ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, self::PRUNE_HOOK );
			$timestamp = wp_next_scheduled( self::PRUNE_HOOK );
		}
	}

	/**
	 * Insert one error log row.
	 *
	 * @param int    $location_id Location ID.
	 * @param string $code        Error code.
	 * @param string $message     Error message.
	 * @param string $url         Endpoint URL.
	 * @return bool
	 */
	public static function log_error( $location_id, $code, $message, $url ) {
		global $wpdb;

		$table = self::get_table_name();
		if ( ! self::table_exists( $table ) ) {
			return false;
		}

		$location_id   = absint( $location_id );
		$error_code    = self::sanitize_error_code( $code );
		$error_message = self::sanitize_error_message( $message );
		$endpoint_url  = self::sanitize_endpoint_url( $url );

		if ( '' === $error_code ) {
			$error_code = 'unknown_error';
		}

		if ( '' === $error_message ) {
			$error_message = 'Unknown synchronization error.';
		}

		$inserted = $wpdb->insert(
			$table,
			array(
				'location_id'   => $location_id,
				'error_code'    => $error_code,
				'error_message' => $error_message,
				'endpoint_url'  => $endpoint_url,
			),
			array( '%d', '%s', '%s', '%s' )
		);

		return false !== $inserted;
	}

	/**
	 * Return most recent log rows.
	 *
	 * @param int $limit Maximum rows.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_recent_logs( $limit = self::DEFAULT_LOG_LIMIT ) {
		global $wpdb;

		$table = self::get_table_name();
		if ( ! self::table_exists( $table ) ) {
			return array();
		}

		$limit = absint( $limit );
		if ( $limit <= 0 ) {
			$limit = self::DEFAULT_LOG_LIMIT;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, location_id, error_code, error_message, endpoint_url, `timestamp`
				FROM {$table}
				ORDER BY `timestamp` DESC, id DESC
				LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Remove all log rows.
	 *
	 * @return bool
	 */
	public static function clear_logs() {
		global $wpdb;

		$table = self::get_table_name();
		if ( ! self::table_exists( $table ) ) {
			return true;
		}

		$deleted = $wpdb->query( "DELETE FROM {$table}" );
		return false !== $deleted;
	}

	/**
	 * Get per-location error counts from the last N hours.
	 *
	 * @param int $hours Lookback window in hours.
	 * @return array<int, int>
	 */
	public static function get_recent_error_counts_by_location( $hours = 24 ) {
		global $wpdb;

		$table = self::get_table_name();
		if ( ! self::table_exists( $table ) ) {
			return array();
		}

		$hours = absint( $hours );
		if ( $hours <= 0 ) {
			$hours = 24;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT location_id, COUNT(*) AS total_errors
				FROM {$table}
				WHERE location_id > 0
					AND `timestamp` >= NOW() - INTERVAL %d HOUR
				GROUP BY location_id",
				$hours
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$counts = array();
		foreach ( $rows as $row ) {
			$location_id = isset( $row['location_id'] ) ? absint( $row['location_id'] ) : 0;
			if ( $location_id <= 0 ) {
				continue;
			}

			$counts[ $location_id ] = isset( $row['total_errors'] ) ? (int) $row['total_errors'] : 0;
		}

		return $counts;
	}

	/**
	 * Delete logs older than 30 days.
	 *
	 * @return void
	 */
	public static function prune_logs() {
		global $wpdb;

		$table = self::get_table_name();
		if ( ! self::table_exists( $table ) ) {
			return;
		}

		$wpdb->query( "DELETE FROM {$table} WHERE `timestamp` < NOW() - INTERVAL 30 DAY" );
	}

	/**
	 * Ensure daily prune cron exists.
	 *
	 * @return void
	 */
	public static function maybe_schedule_prune() {
		if ( ! wp_next_scheduled( self::PRUNE_HOOK ) ) {
			wp_schedule_event( time() + ( 5 * MINUTE_IN_SECONDS ), 'daily', self::PRUNE_HOOK );
		}
	}

	/**
	 * Ensure error log table exists after updates.
	 *
	 * @return void
	 */
	public static function maybe_create_table() {
		$table = self::get_table_name();
		if ( self::table_exists( $table ) ) {
			return;
		}

		if ( class_exists( 'Database_Installer' ) && is_callable( array( 'Database_Installer', 'activate' ) ) ) {
			Database_Installer::activate();
		}
	}

	/**
	 * Get full error log table name.
	 *
	 * @return string
	 */
	public static function get_table_name() {
		global $wpdb;

		return $wpdb->prefix . 'mlgr_error_logs';
	}

	/**
	 * Check if table exists.
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

	/**
	 * Normalize error code text.
	 *
	 * @param mixed $value Error code.
	 * @return string
	 */
	private static function sanitize_error_code( $value ) {
		if ( ! is_scalar( $value ) && null !== $value ) {
			return '';
		}

		$text = strtolower( trim( (string) $value ) );
		if ( '' === $text ) {
			return '';
		}

		$text = preg_replace( '/[^a-z0-9._-]/', '_', $text );
		$text = is_string( $text ) ? trim( $text, '_' ) : '';

		if ( '' === $text ) {
			return '';
		}

		if ( strlen( $text ) > self::MAX_ERROR_CODE_LENGTH ) {
			$text = substr( $text, 0, self::MAX_ERROR_CODE_LENGTH );
		}

		return $text;
	}

	/**
	 * Normalize error message text.
	 *
	 * @param mixed $value Error message.
	 * @return string
	 */
	private static function sanitize_error_message( $value ) {
		if ( ! is_scalar( $value ) && null !== $value ) {
			return '';
		}

		$text = trim( wp_strip_all_tags( (string) $value ) );
		if ( '' === $text ) {
			return '';
		}

		if ( strlen( $text ) > self::MAX_ERROR_MESSAGE_LENGTH ) {
			$text = substr( $text, 0, self::MAX_ERROR_MESSAGE_LENGTH );
		}

		return $text;
	}

	/**
	 * Normalize endpoint URL.
	 *
	 * @param mixed $value Endpoint URL.
	 * @return string
	 */
	private static function sanitize_endpoint_url( $value ) {
		if ( ! is_scalar( $value ) && null !== $value ) {
			return '';
		}

		$url = trim( (string) $value );
		if ( '' === $url ) {
			return '';
		}

		$sanitized = esc_url_raw( $url );
		if ( ! is_string( $sanitized ) || '' === $sanitized ) {
			$sanitized = $url;
		}

		if ( strlen( $sanitized ) > self::MAX_ENDPOINT_URL_LENGTH ) {
			$sanitized = substr( $sanitized, 0, self::MAX_ENDPOINT_URL_LENGTH );
		}

		return $sanitized;
	}
}
