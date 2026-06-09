<?php
/**
 * Asynchronous review synchronization using Action Scheduler.
 *
 * Sync flow:
 *   1. Initial add  (cursor = null, no force_scrape):
 *      Check if scraper already has the place → import directly.
 *      If not found → trigger scrape job → schedule job status checks.
 *   2. Force resync / scheduled sync (cursor has force_scrape = true):
 *      Always trigger a fresh scrape job → schedule job status checks.
 *   3. Job polling  (cursor has job_id):
 *      Poll GET /jobs/{job_id} every JOB_POLL_INTERVAL_SECONDS seconds.
 *      On completion → import all reviews from scraper → mark done.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Review_Syncer {

	/**
	 * Action Scheduler hook name.
	 */
	const SYNC_ACTION = 'mlgr_sync_location';

	/**
	 * Action Scheduler group.
	 */
	const SYNC_GROUP = 'mlgr';

	/**
	 * Option key for per-location sync errors.
	 */
	const ERROR_LOG_OPTION = 'mlgr_sync_error_log';

	/**
	 * Maximum stored length for one error message.
	 */
	const MAX_ERROR_MESSAGE_LENGTH = 500;

	/**
	 * Option key for star ratings excluded from sync.
	 */
	const EXCLUDED_RATINGS_OPTION = 'mlgr_sync_excluded_ratings';

	/**
	 * Seconds between scrape job status polls.
	 */
	const JOB_POLL_INTERVAL_SECONDS = 30;

	/**
	 * Register Action Scheduler hook.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::SYNC_ACTION, array( __CLASS__, 'process_sync' ), 10, 2 );
	}

	/**
	 * Schedule an initial sync for a newly added location.
	 *
	 * Checks whether the scraper already has this place and imports directly
	 * if found; otherwise triggers a new scrape job.
	 *
	 * @param int $location_id Location table ID.
	 * @return int|false
	 */
	public static function schedule_initial_sync( $location_id ) {
		return self::schedule_sync_action( $location_id, null, 0 );
	}

	/**
	 * Schedule a forced re-scrape for an existing location.
	 *
	 * Always triggers a fresh scrape regardless of cached scraper data.
	 *
	 * @param int $location_id Location table ID.
	 * @return int|false
	 */
	public static function schedule_resync( $location_id ) {
		return self::schedule_sync_action( $location_id, array( 'force_scrape' => true ), 0 );
	}

	/**
	 * Process one step of the location sync state machine.
	 *
	 * @param int               $location_id Internal location ID.
	 * @param string|array|null $cursor      Sync state cursor.
	 * @return void
	 */
	public static function process_sync( $location_id, $cursor = null ) {
		global $wpdb;

		$location_id = absint( $location_id );
		if ( $location_id <= 0 ) {
			return;
		}

		$locations_table = $wpdb->prefix . 'mlgr_locations';
		$location        = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, google_place_id, name FROM {$locations_table} WHERE id = %d LIMIT 1",
				$location_id
			),
			ARRAY_A
		);

		if ( empty( $location['google_place_id'] ) ) {
			self::record_sync_error( $location_id, 'Location is missing a Google Maps URL.' );
			return;
		}

		self::update_location_status( $location_id, 'active' );
		self::clear_sync_error( $location_id );

		$maps_url = (string) $location['google_place_id'];
		$fetcher  = new Scraper_Fetcher();
		$cursor   = self::normalize_sync_cursor( $cursor );

		// ── Phase: Job polling ────────────────────────────────────────────────
		if ( '' !== $cursor['job_id'] ) {
			$status_result = $fetcher->get_job_status( $cursor['job_id'], $location_id );

			if ( ! empty( $status_result['error'] ) ) {
				self::record_sync_error( $location_id, (string) $status_result['error'] );
				return;
			}

			$job_status = (string) $status_result['status'];

			if ( in_array( $job_status, array( 'pending', 'running' ), true ) ) {
				self::schedule_sync_action(
					$location_id,
					array( 'job_id' => $cursor['job_id'] ),
					self::JOB_POLL_INTERVAL_SECONDS
				);
				return;
			}

			if ( 'completed' !== $job_status ) {
				self::record_sync_error(
					$location_id,
					'Scrape job ended with unexpected status: ' . $job_status . '.',
					array( 'job_id' => $cursor['job_id'] )
				);
				return;
			}

			// Job complete — locate the place and import.
			$place = $fetcher->find_place_by_url( $maps_url );
			if ( ! is_array( $place ) || empty( $place['place_id'] ) ) {
				self::record_sync_error( $location_id, 'Scrape completed but place not found in scraper API.' );
				return;
			}

			self::import_place_reviews( $location_id, (string) $place['place_id'], $place, $fetcher );
			return;
		}

		// ── Phase: Initial add or force resync ───────────────────────────────
		if ( ! $cursor['force_scrape'] ) {
			// Check whether the scraper already has data for this URL (e.g. we
			// just ran the scraper manually before adding the location to WP).
			$place = $fetcher->find_place_by_url( $maps_url );
			if ( is_array( $place ) && ! empty( $place['place_id'] ) ) {
				self::import_place_reviews( $location_id, (string) $place['place_id'], $place, $fetcher );
				return;
			}
		}

		// Trigger a fresh scrape job.
		$scrape_result = $fetcher->trigger_scrape( $maps_url, $location_id );
		if ( ! empty( $scrape_result['error'] ) ) {
			self::record_sync_error( $location_id, (string) $scrape_result['error'] );
			return;
		}

		self::schedule_sync_action(
			$location_id,
			array( 'job_id' => $scrape_result['job_id'] ),
			self::JOB_POLL_INTERVAL_SECONDS
		);
	}

	/**
	 * Fetch reviews from the scraper and save them to WordPress.
	 *
	 * Also updates the location name and rating summary.
	 *
	 * @param int            $location_id Internal location ID.
	 * @param string         $place_id    Scraper place_id.
	 * @param array          $place       Place data from find_place_by_url().
	 * @param Scraper_Fetcher $fetcher    Fetcher instance.
	 * @return void
	 */
	private static function import_place_reviews( $location_id, $place_id, $place, $fetcher ) {
		// Update location display name from scraper.
		$place_name = isset( $place['place_name'] ) ? (string) $place['place_name'] : '';
		if ( '' !== $place_name ) {
			self::update_location_name( $location_id, $place_name );
		}

		$response = $fetcher->fetch_reviews( $place_id, $location_id );

		if ( ! empty( $response['error'] ) ) {
			self::record_sync_error( $location_id, (string) $response['error'] );
			return;
		}

		self::update_location_rating_summary(
			$location_id,
			isset( $response['average_rating'] ) ? $response['average_rating'] : null,
			isset( $response['total_reviews'] ) ? $response['total_reviews'] : null
		);

		if ( ! empty( $response['reviews'] ) && is_array( $response['reviews'] ) ) {
			wp_suspend_cache_invalidation( true );

			foreach ( $response['reviews'] as $review ) {
				$saved = self::upsert_review( $location_id, $review );
				if ( ! $saved ) {
					wp_suspend_cache_invalidation( false );
					self::record_sync_error(
						$location_id,
						'Failed saving a review post.',
						array( 'stage' => 'upsert_review' )
					);
					return;
				}
			}

			wp_suspend_cache_invalidation( false );
		}

		self::mark_sync_completed( $location_id );
	}

	/**
	 * Schedule one sync action.
	 *
	 * @param int               $location_id   Internal location ID.
	 * @param string|array|null $cursor        Sync state cursor.
	 * @param int               $delay_seconds Delay before execution.
	 * @return int|false
	 */
	private static function schedule_sync_action( $location_id, $cursor, $delay_seconds ) {
		$location_id = absint( $location_id );
		if ( $location_id <= 0 ) {
			return false;
		}

		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			self::record_sync_error( $location_id, 'Action Scheduler is unavailable.' );
			return false;
		}

		$normalized = self::normalize_sync_cursor( $cursor );
		$args       = array(
			$location_id,
			( '' !== $normalized['job_id'] || $normalized['force_scrape'] ) ? $normalized : null,
		);

		$action_id = as_schedule_single_action(
			time() + max( 0, absint( $delay_seconds ) ),
			self::SYNC_ACTION,
			$args,
			self::SYNC_GROUP
		);

		if ( false === $action_id ) {
			self::record_sync_error(
				$location_id,
				'Action Scheduler failed to queue sync action.',
				array( 'cursor' => $normalized )
			);
		}

		return $action_id;
	}

	/**
	 * Normalize a sync cursor from any format into a canonical array.
	 *
	 * @param mixed $cursor Raw cursor value.
	 * @return array{job_id: string, force_scrape: bool}
	 */
	private static function normalize_sync_cursor( $cursor ) {
		$normalized = array(
			'job_id'      => '',
			'force_scrape' => false,
		);

		if ( ! is_array( $cursor ) ) {
			return $normalized;
		}

		if ( isset( $cursor['job_id'] ) && is_scalar( $cursor['job_id'] ) ) {
			$job_id = trim( (string) $cursor['job_id'] );
			if ( '' !== $job_id ) {
				$normalized['job_id'] = $job_id;
			}
		}

		if ( ! empty( $cursor['force_scrape'] ) ) {
			$normalized['force_scrape'] = true;
		}

		return $normalized;
	}

	/**
	 * Insert or update one mlgr_review CPT post, deduplicated by google_review_id.
	 *
	 * @param int   $location_id Internal location ID.
	 * @param mixed $review      Normalized review array from Scraper_Fetcher.
	 * @return bool
	 */
	private static function upsert_review( $location_id, $review ) {
		if ( ! is_array( $review ) ) {
			return true;
		}

		$google_review_id = self::first_non_empty(
			self::array_get( $review, array( 'review_id' ) ),
			self::array_get( $review, array( 'id' ) )
		);

		if ( '' === $google_review_id ) {
			return true;
		}

		$author_name = self::first_non_empty(
			self::array_get( $review, array( 'user', 'name' ) ),
			self::array_get( $review, array( 'user', 'username' ) ),
			self::array_get( $review, array( 'name' ) )
		);

		$author_photo = self::first_non_empty(
			self::array_get( $review, array( 'user', 'thumbnail' ) ),
			self::array_get( $review, array( 'user', 'image' ) ),
			self::array_get( $review, array( 'author_photo' ) )
		);

		$rating = self::array_get( $review, array( 'rating' ) );
		$rating = is_numeric( $rating ) ? max( 0, min( 5, (int) $rating ) ) : 0;

		$excluded_ratings = (array) get_option( self::EXCLUDED_RATINGS_OPTION, array() );
		if ( ! empty( $excluded_ratings ) && in_array( $rating, array_map( 'intval', $excluded_ratings ), true ) ) {
			return true;
		}

		$text = self::first_non_empty(
			self::array_get( $review, array( 'snippet' ) ),
			self::array_get( $review, array( 'text' ) ),
			self::array_get( $review, array( 'extracted_snippet', 'original' ) )
		);

		$publish_date = self::normalize_datetime(
			self::first_non_empty(
				self::array_get( $review, array( 'iso_date' ) ),
				self::array_get( $review, array( 'date' ) ),
				self::array_get( $review, array( 'published_at' ) )
			)
		);

		$post_status = ( $rating >= 4 ) ? 'publish' : 'draft';
		$post_date   = null !== $publish_date ? $publish_date : current_time( 'mysql' );

		$existing_id = CPT_Manager::get_review_post_by_google_id( $google_review_id );

		$post_data = array(
			'post_type'    => CPT_Manager::POST_TYPE,
			'post_title'   => $author_name,
			'post_content' => (string) $text,
			'post_status'  => $post_status,
			'post_date'    => $post_date,
		);

		if ( $existing_id > 0 ) {
			$post_data['ID'] = $existing_id;
			$result          = wp_update_post( $post_data, true );
		} else {
			$result = wp_insert_post( $post_data, true );
		}

		if ( is_wp_error( $result ) || ! $result ) {
			return false;
		}

		$post_id = (int) $result;

		update_post_meta( $post_id, CPT_Manager::META_GOOGLE_REVIEW_ID, $google_review_id );
		update_post_meta( $post_id, CPT_Manager::META_LOCATION_ID, absint( $location_id ) );
		update_post_meta( $post_id, CPT_Manager::META_RATING, $rating );
		update_post_meta( $post_id, CPT_Manager::META_AUTHOR_PHOTO, $author_photo );

		return true;
	}

	/**
	 * Update the location display name after a successful scrape.
	 *
	 * @param int    $location_id Location ID.
	 * @param string $name        Resolved place name from scraper.
	 * @return void
	 */
	private static function update_location_name( $location_id, $name ) {
		global $wpdb;

		$name = trim( (string) $name );
		if ( '' === $name ) {
			return;
		}

		$locations_table = $wpdb->prefix . 'mlgr_locations';
		$wpdb->update(
			$locations_table,
			array( 'name' => $name ),
			array( 'id' => absint( $location_id ) ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Mark location sync as completed.
	 *
	 * @param int $location_id Location ID.
	 * @return void
	 */
	private static function mark_sync_completed( $location_id ) {
		global $wpdb;

		$locations_table = $wpdb->prefix . 'mlgr_locations';
		$wpdb->update(
			$locations_table,
			array(
				'last_sync'   => current_time( 'mysql' ),
				'sync_status' => 'completed',
			),
			array( 'id' => absint( $location_id ) ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		self::clear_sync_error( $location_id );
		Review_Shortcode::flush_cache();
	}

	/**
	 * Get all sync errors keyed by location ID.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function get_error_log() {
		$errors = get_option( self::ERROR_LOG_OPTION, array() );
		return is_array( $errors ) ? $errors : array();
	}

	/**
	 * Get last sync error for a location.
	 *
	 * @param int $location_id Location ID.
	 * @return array{message: string, time: string, context: string}
	 */
	public static function get_location_error( $location_id ) {
		$location_id = absint( $location_id );
		if ( $location_id <= 0 ) {
			return array( 'message' => '', 'time' => '', 'context' => '' );
		}

		$errors = self::get_error_log();
		$key    = (string) $location_id;
		$entry  = ( isset( $errors[ $key ] ) && is_array( $errors[ $key ] ) ) ? $errors[ $key ] : array();

		return array(
			'message' => isset( $entry['message'] ) && is_string( $entry['message'] ) ? $entry['message'] : '',
			'time'    => isset( $entry['time'] ) && is_string( $entry['time'] ) ? $entry['time'] : '',
			'context' => isset( $entry['context'] ) && is_string( $entry['context'] ) ? $entry['context'] : '',
		);
	}

	/**
	 * Clear the stored sync error for a location.
	 *
	 * @param int $location_id Location ID.
	 * @return void
	 */
	public static function clear_sync_error( $location_id ) {
		$location_id = absint( $location_id );
		if ( $location_id <= 0 ) {
			return;
		}

		$errors = self::get_error_log();
		$key    = (string) $location_id;

		if ( ! isset( $errors[ $key ] ) ) {
			return;
		}

		unset( $errors[ $key ] );

		if ( empty( $errors ) ) {
			delete_option( self::ERROR_LOG_OPTION );
			return;
		}

		update_option( self::ERROR_LOG_OPTION, $errors, false );
	}

	/**
	 * Update only the sync status field.
	 *
	 * @param int    $location_id Location ID.
	 * @param string $status      Status string.
	 * @return void
	 */
	private static function update_location_status( $location_id, $status ) {
		global $wpdb;

		$locations_table = $wpdb->prefix . 'mlgr_locations';
		$wpdb->update(
			$locations_table,
			array( 'sync_status' => $status ),
			array( 'id' => absint( $location_id ) ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Persist location-level average rating and total review count.
	 *
	 * @param int             $location_id    Location ID.
	 * @param float|int|null  $average_rating Average rating value.
	 * @param int|string|null $total_reviews  Total reviews value.
	 * @return void
	 */
	private static function update_location_rating_summary( $location_id, $average_rating, $total_reviews ) {
		global $wpdb;

		$location_id = absint( $location_id );
		if ( $location_id <= 0 ) {
			return;
		}

		$fields  = array();
		$formats = array();

		if ( is_numeric( $average_rating ) ) {
			$rating                  = max( 0.0, min( 5.0, (float) $average_rating ) );
			$fields['average_rating'] = round( $rating, 1 );
			$formats[]               = '%f';
		}

		if ( is_numeric( $total_reviews ) ) {
			$fields['total_reviews'] = max( 0, (int) $total_reviews );
			$formats[]              = '%d';
		}

		if ( empty( $fields ) ) {
			return;
		}

		$locations_table = $wpdb->prefix . 'mlgr_locations';
		$wpdb->update(
			$locations_table,
			$fields,
			array( 'id' => $location_id ),
			$formats,
			array( '%d' )
		);
	}

	/**
	 * Persist and emit one sync error for a location.
	 *
	 * @param int    $location_id Location ID.
	 * @param mixed  $message     Error message.
	 * @param array  $context     Optional debug context.
	 * @return void
	 */
	private static function record_sync_error( $location_id, $message, $context = array() ) {
		$location_id = absint( $location_id );
		if ( $location_id <= 0 ) {
			return;
		}

		$error_message = self::sanitize_error_text( $message );
		if ( '' === $error_message ) {
			$error_message = 'Unknown synchronization error.';
		}

		$context_message = '';
		if ( is_array( $context ) && ! empty( $context ) ) {
			$encoded         = wp_json_encode( $context );
			$context_message = self::sanitize_error_text( false !== $encoded ? $encoded : '' );
		}

		$errors                      = self::get_error_log();
		$errors[ (string) $location_id ] = array(
			'message' => $error_message,
			'time'    => current_time( 'mysql' ),
			'context' => $context_message,
		);
		update_option( self::ERROR_LOG_OPTION, $errors, false );

		self::update_location_status( $location_id, 'error' );

		if ( function_exists( 'error_log' ) ) {
			error_log( sprintf( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				'[MLGR] Location %d sync error: %s%s',
				$location_id,
				$error_message,
				'' !== $context_message ? ' | context=' . $context_message : ''
			) );
		}
	}

	/**
	 * Convert a value into a trimmed, capped error string.
	 *
	 * @param mixed $value Value to sanitize.
	 * @return string
	 */
	private static function sanitize_error_text( $value ) {
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
	 * Safe nested array getter.
	 *
	 * @param array $source Source array.
	 * @param array $path   Nested keys.
	 * @return mixed|null
	 */
	private static function array_get( $source, $path ) {
		if ( ! is_array( $source ) || ! is_array( $path ) ) {
			return null;
		}

		$value = $source;
		foreach ( $path as $segment ) {
			if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				return null;
			}
			$value = $value[ $segment ];
		}

		return $value;
	}

	/**
	 * Return first non-empty scalar value as string.
	 *
	 * @param mixed ...$values Candidate values.
	 * @return string
	 */
	private static function first_non_empty( ...$values ) {
		foreach ( $values as $value ) {
			if ( is_scalar( $value ) ) {
				$text = trim( (string) $value );
				if ( '' !== $text ) {
					return $text;
				}
			}
		}

		return '';
	}

	/**
	 * Normalize date input to MySQL datetime or null.
	 *
	 * @param mixed $value Date value.
	 * @return string|null
	 */
	private static function normalize_datetime( $value ) {
		if ( is_numeric( $value ) ) {
			$timestamp = (int) $value;
			if ( $timestamp > 0 ) {
				return gmdate( 'Y-m-d H:i:s', $timestamp );
			}
		}

		if ( is_scalar( $value ) ) {
			$timestamp = strtotime( (string) $value );
			if ( false !== $timestamp ) {
				return gmdate( 'Y-m-d H:i:s', $timestamp );
			}
		}

		return null;
	}
}
