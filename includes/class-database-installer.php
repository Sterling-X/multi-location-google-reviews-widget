<?php
/**
 * Database installer for Multi-Location Google Reviews Widget.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Database_Installer {

	/**
	 * Internal schema version for upgrade tracking.
	 */
	const SCHEMA_VERSION = '2.0.0';

	/**
	 * Option key storing the last migrated schema version.
	 */
	const SCHEMA_VERSION_OPTION = 'mlgr_schema_version';

	/**
	 * Run plugin activation setup.
	 *
	 * @return void
	 */
	public static function activate() {
		self::create_tables();
		update_option( self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Run one-time schema upgrades for existing installations.
	 *
	 * @return void
	 */
	public static function maybe_upgrade_schema() {
		$installed_version = get_option( self::SCHEMA_VERSION_OPTION, '' );
		if ( self::SCHEMA_VERSION === (string) $installed_version ) {
			return;
		}

		self::create_tables();

		if ( version_compare( (string) $installed_version, '2.0.0', '<' ) ) {
			self::migrate_reviews_to_cpt();
		}

		Review_Shortcode::flush_cache();
		update_option( self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Migrate existing rows from the mlgr_reviews custom table into mlgr_review CPT posts.
	 *
	 * Idempotent: reviews already migrated (identified by _mlgr_google_review_id meta) are skipped.
	 * The source table is left intact as a backup.
	 *
	 * @return void
	 */
	private static function migrate_reviews_to_cpt() {
		global $wpdb;

		$reviews_table = $wpdb->prefix . 'mlgr_reviews';

		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $reviews_table ) );
		if ( $table_exists !== $reviews_table ) {
			return;
		}

		$rows = $wpdb->get_results( "SELECT * FROM {$reviews_table}", ARRAY_A );
		if ( empty( $rows ) || ! is_array( $rows ) ) {
			return;
		}

		wp_suspend_cache_invalidation( true );

		foreach ( $rows as $row ) {
			$google_review_id = isset( $row['google_review_id'] ) ? (string) $row['google_review_id'] : '';
			if ( '' === $google_review_id ) {
				continue;
			}

			if ( CPT_Manager::get_review_post_by_google_id( $google_review_id ) > 0 ) {
				continue;
			}

			$is_hidden   = ! empty( $row['is_hidden'] );
			$post_status = $is_hidden ? 'draft' : 'publish';
			$post_date   = ! empty( $row['publish_date'] ) ? (string) $row['publish_date'] : current_time( 'mysql' );

			$post_id = wp_insert_post(
				array(
					'post_type'    => CPT_Manager::POST_TYPE,
					'post_title'   => isset( $row['author_name'] ) ? (string) $row['author_name'] : '',
					'post_content' => isset( $row['text'] ) ? (string) $row['text'] : '',
					'post_status'  => $post_status,
					'post_date'    => $post_date,
				)
			);

			if ( is_wp_error( $post_id ) || ! $post_id ) {
				continue;
			}

			update_post_meta( $post_id, CPT_Manager::META_GOOGLE_REVIEW_ID, $google_review_id );
			update_post_meta( $post_id, CPT_Manager::META_LOCATION_ID, absint( $row['location_id'] ) );
			update_post_meta( $post_id, CPT_Manager::META_RATING, (int) $row['rating'] );
			update_post_meta( $post_id, CPT_Manager::META_AUTHOR_PHOTO, isset( $row['author_photo'] ) ? (string) $row['author_photo'] : '' );
		}

		wp_suspend_cache_invalidation( false );
	}

	/**
	 * Create or update required plugin tables.
	 *
	 * @return void
	 */
	private static function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$locations_table = $wpdb->prefix . 'mlgr_locations';
		$reviews_table   = $wpdb->prefix . 'mlgr_reviews';
		$error_logs_table = $wpdb->prefix . 'mlgr_error_logs';

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$locations_sql = "CREATE TABLE {$locations_table} (
			id INT UNSIGNED NOT NULL AUTO_INCREMENT,
			google_place_id VARCHAR(191) NOT NULL,
			name VARCHAR(255) NOT NULL DEFAULT '',
			photo_url VARCHAR(255) NULL,
			last_sync DATETIME NULL,
			sync_status ENUM('pending', 'active', 'completed', 'error') NOT NULL DEFAULT 'pending',
			average_rating DECIMAL(2,1) NULL,
			total_reviews INT UNSIGNED NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_google_place_id (google_place_id)
		) ENGINE=InnoDB {$charset_collate};";

		$reviews_sql = "CREATE TABLE {$reviews_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			location_id INT UNSIGNED NOT NULL,
			google_review_id VARCHAR(191) NOT NULL,
			author_name VARCHAR(191) NOT NULL DEFAULT '',
			author_photo VARCHAR(255) NULL,
			rating TINYINT UNSIGNED NOT NULL DEFAULT 0,
			text TEXT NULL,
			publish_date DATETIME NULL,
			is_hidden TINYINT(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_google_review_id (google_review_id),
			KEY idx_location_id (location_id),
			KEY idx_rating (rating)
		) ENGINE=InnoDB {$charset_collate};";

		$error_logs_sql = "CREATE TABLE {$error_logs_table} (
			id INT UNSIGNED NOT NULL AUTO_INCREMENT,
			location_id INT UNSIGNED NOT NULL DEFAULT 0,
			error_code VARCHAR(191) NOT NULL DEFAULT '',
			error_message TEXT NULL,
			endpoint_url VARCHAR(255) NULL,
			`timestamp` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_location_id (location_id),
			KEY idx_timestamp (`timestamp`)
		) ENGINE=InnoDB {$charset_collate};";

		dbDelta( $locations_sql );
		dbDelta( $reviews_sql );
		dbDelta( $error_logs_sql );

		self::maybe_add_location_summary_columns( $locations_table );
		self::maybe_add_foreign_key( $reviews_table, $locations_table );
	}

	/**
	 * Add location summary columns if they do not yet exist.
	 *
	 * @param string $locations_table Locations table name.
	 * @return void
	 */
	private static function maybe_add_location_summary_columns( $locations_table ) {
		global $wpdb;

		$columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$locations_table}`", 0 );
		if ( ! is_array( $columns ) || empty( $columns ) ) {
			return;
		}

		if ( ! in_array( 'average_rating', $columns, true ) ) {
			$wpdb->query( "ALTER TABLE `{$locations_table}` ADD COLUMN average_rating DECIMAL(2,1) NULL AFTER sync_status" );
		}

		if ( ! in_array( 'total_reviews', $columns, true ) ) {
			$wpdb->query( "ALTER TABLE `{$locations_table}` ADD COLUMN total_reviews INT UNSIGNED NULL AFTER average_rating" );
		}
	}

	/**
	 * Add the foreign key for reviews.location_id if it does not yet exist.
	 *
	 * @param string $reviews_table   Reviews table name.
	 * @param string $locations_table Locations table name.
	 * @return void
	 */
	private static function maybe_add_foreign_key( $reviews_table, $locations_table ) {
		global $wpdb;

		$constraint_name = 'fk_mlgr_reviews_location';

		$existing_fk = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT CONSTRAINT_NAME
				FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
				WHERE TABLE_SCHEMA = DATABASE()
					AND TABLE_NAME = %s
					AND COLUMN_NAME = 'location_id'
					AND REFERENCED_TABLE_NAME = %s
				LIMIT 1",
				$reviews_table,
				$locations_table
			)
		);

		if ( ! $existing_fk ) {
			$wpdb->query(
				"ALTER TABLE {$reviews_table}
				ADD CONSTRAINT {$constraint_name}
				FOREIGN KEY (location_id)
				REFERENCES {$locations_table}(id)
				ON DELETE CASCADE"
			);
		}
	}
}
