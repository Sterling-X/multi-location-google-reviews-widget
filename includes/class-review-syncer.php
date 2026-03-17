<?php
/**
 * Asynchronous review synchronization using Action Scheduler.
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
	 * Option key for storing per-location sync errors.
	 */
	const ERROR_LOG_OPTION = 'mlgr_sync_error_log';

	/**
	 * Maximum stored length for one error message.
	 */
	const MAX_ERROR_MESSAGE_LENGTH = 500;

	/**
	 * Delay before fetching the next paginated SerpApi page.
	 */
	const NEXT_PAGE_DELAY_SECONDS = 15;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::SYNC_ACTION, array( __CLASS__, 'process_sync' ), 10, 2 );
	}

	/**
	 * Start a location sync from page 1.
	 *
	 * @param int $location_id Location table ID.
	 * @return int|false
	 */
	public static function schedule_initial_sync( $location_id ) {
		return self::schedule_sync_action( $location_id, null, 0 );
	}

	/**
	 * Process one page of review synchronization.
	 *
	 * @param int               $location_id     Internal location ID.
	 * @param string|array|null $next_page_token Pagination cursor for next page.
	 * @return void
	 */
	public static function process_sync( $location_id, $next_page_token = null ) {
		global $wpdb;

		$location_id = absint( $location_id );
		if ( $location_id <= 0 ) {
			return;
		}

		$locations_table = $wpdb->prefix . 'mlgr_locations';
		$reviews_table   = $wpdb->prefix . 'mlgr_reviews';

		$location = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, google_place_id FROM {$locations_table} WHERE id = %d LIMIT 1",
				$location_id
			),
			ARRAY_A
		);

		if ( empty( $location['google_place_id'] ) ) {
			self::record_sync_error( $location_id, 'Location is missing a Google Place ID / Data ID.' );
			return;
		}

		self::update_location_status( $location_id, 'active' );
		self::clear_sync_error( $location_id );

		$cursor             = self::normalize_sync_cursor( $next_page_token );
		$request_location   = '' !== $cursor['location_ref'] ? $cursor['location_ref'] : (string) $location['google_place_id'];
		$request_page_token = $cursor['next_page_token'];
		$request_next_url   = $cursor['next_request_url'];

		$fetcher  = new SerpApi_Fetcher();
		$response = $fetcher->fetch_reviews( $request_location, $request_page_token, $location_id, $request_next_url );

		if ( ! empty( $response['error'] ) ) {
			self::record_sync_error(
				$location_id,
				(string) $response['error'],
				array(
					'stage'            => 'fetch_reviews',
					'location_ref'     => $request_location,
					'next_page_token'  => is_string( $request_page_token ) ? $request_page_token : '',
					'next_request_url' => is_string( $request_next_url ) ? $request_next_url : '',
				)
			);
			return;
		}

		if ( ! empty( $response['reviews'] ) && is_array( $response['reviews'] ) ) {
			foreach ( $response['reviews'] as $review ) {
				$saved = self::upsert_review( $reviews_table, $location_id, $review );
				if ( ! $saved ) {
					$db_error = is_string( $wpdb->last_error ) ? trim( $wpdb->last_error ) : '';
					$message  = '' !== $db_error
						? 'Failed saving a review row: ' . $db_error
						: 'Failed saving a review row.';

					self::record_sync_error(
						$location_id,
						$message,
						array(
							'stage' => 'upsert_review',
						)
					);
					return;
				}
			}
		}

		if ( ! empty( $response['next_page_token'] ) && is_string( $response['next_page_token'] ) ) {
			$next_cursor = array(
				'next_page_token' => (string) $response['next_page_token'],
				'location_ref'    => self::first_non_empty(
					isset( $response['next_location_ref'] ) && is_string( $response['next_location_ref'] ) ? $response['next_location_ref'] : '',
					isset( $response['request_location_ref'] ) && is_string( $response['request_location_ref'] ) ? $response['request_location_ref'] : '',
					$request_location
				),
				'next_request_url' => self::first_non_empty(
					isset( $response['next_request_url'] ) && is_string( $response['next_request_url'] ) ? $response['next_request_url'] : '',
					$request_next_url
				),
			);

			$scheduled = self::schedule_sync_action( $location_id, $next_cursor, self::NEXT_PAGE_DELAY_SECONDS );
			if ( false === $scheduled ) {
				self::record_sync_error(
					$location_id,
					'Failed to schedule the next sync page.',
					array(
						'stage'       => 'schedule_next_page',
						'next_cursor' => $next_cursor,
					)
				);
			}
			return;
		}

		self::mark_sync_completed( $location_id );
	}

	/**
	 * Schedule one sync job.
	 *
	 * @param int               $location_id     Internal location ID.
	 * @param string|array|null $next_page_token Pagination cursor.
	 * @param int               $delay_seconds   Delay before execution.
	 * @return int|false
	 */
	private static function schedule_sync_action( $location_id, $next_page_token, $delay_seconds ) {
		$location_id = absint( $location_id );
		if ( $location_id <= 0 ) {
			return false;
		}

		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			self::record_sync_error( $location_id, 'Action Scheduler is unavailable.' );
			return false;
		}

		$cursor = self::normalize_sync_cursor( $next_page_token );
		$args   = array(
			$location_id,
			( null !== $cursor['next_page_token'] || '' !== $cursor['location_ref'] || '' !== $cursor['next_request_url'] ) ? $cursor : null,
		);

		$action_id = as_schedule_single_action( time() + max( 0, absint( $delay_seconds ) ), self::SYNC_ACTION, $args, self::SYNC_GROUP );
		if ( false === $action_id ) {
			self::record_sync_error(
				$location_id,
				'Action Scheduler failed to queue sync action.',
				array(
					'stage'       => 'schedule_sync_action',
					'next_cursor' => $cursor,
				)
			);
		}

		return $action_id;
	}

	/**
	 * Normalize pagination cursor from string/array formats.
	 *
	 * @param mixed $cursor Raw cursor.
	 * @return array{next_page_token: string|null, location_ref: string, next_request_url: string}
	 */
	private static function normalize_sync_cursor( $cursor ) {
		$normalized = array(
			'next_page_token' => null,
			'location_ref'    => '',
			'next_request_url' => '',
		);

		if ( is_string( $cursor ) ) {
			$token = trim( $cursor );
			if ( '' !== $token ) {
				$normalized['next_page_token'] = $token;
			}
			return $normalized;
		}

		if ( ! is_array( $cursor ) ) {
			return $normalized;
		}

		$token = '';
		if ( isset( $cursor['next_page_token'] ) && is_scalar( $cursor['next_page_token'] ) ) {
			$token = trim( (string) $cursor['next_page_token'] );
		}

		if ( '' !== $token ) {
			$normalized['next_page_token'] = $token;
		}

		if ( isset( $cursor['location_ref'] ) && is_scalar( $cursor['location_ref'] ) ) {
			$location_ref = trim( (string) $cursor['location_ref'] );
			if ( '' !== $location_ref ) {
				$normalized['location_ref'] = $location_ref;
			}
		}

		if ( isset( $cursor['next_request_url'] ) && is_scalar( $cursor['next_request_url'] ) ) {
			$next_request_url = trim( (string) $cursor['next_request_url'] );
			if ( '' !== $next_request_url ) {
				$normalized['next_request_url'] = $next_request_url;
			}
		}

		return $normalized;
	}

	/**
	 * Insert or update one review row by unique google_review_id.
	 *
	 * @param string $reviews_table Reviews table.
	 * @param int    $location_id   Location ID.
	 * @param mixed  $review        Review payload.
	 * @return bool
	 */
	private static function upsert_review( $reviews_table, $location_id, $review ) {
		global $wpdb;

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

		$is_hidden = self::array_get( $review, array( 'is_hidden' ) );
		$is_hidden = ! empty( $is_hidden ) ? 1 : 0;

		$query_result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$reviews_table}
					(location_id, google_review_id, author_name, author_photo, rating, `text`, publish_date, is_hidden)
				VALUES (%d, %s, %s, %s, %d, %s, %s, %d)
				ON DUPLICATE KEY UPDATE
					location_id = VALUES(location_id),
					author_name = VALUES(author_name),
					author_photo = VALUES(author_photo),
					rating = VALUES(rating),
					`text` = VALUES(`text`),
					publish_date = VALUES(publish_date),
					is_hidden = VALUES(is_hidden)",
				$location_id,
				$google_review_id,
				$author_name,
				$author_photo,
				$rating,
				$text,
				$publish_date,
				$is_hidden
				)
		);

		return false !== $query_result;
	}

	/**
	 * Mark location sync as completed and store last sync datetime.
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
				'last_sync'    => current_time( 'mysql' ),
				'sync_status'  => 'completed',
			),
			array(
				'id' => absint( $location_id ),
			),
			array( '%s', '%s' ),
			array( '%d' )
		);

		self::clear_sync_error( $location_id );
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
			return array(
				'message' => '',
				'time'    => '',
				'context' => '',
			);
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
			array(
				'sync_status' => $status,
			),
			array(
				'id' => absint( $location_id ),
			),
			array( '%s' ),
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

		$errors               = self::get_error_log();
		$errors[ (string) $location_id ] = array(
			'message' => $error_message,
			'time'    => current_time( 'mysql' ),
			'context' => $context_message,
		);
		update_option( self::ERROR_LOG_OPTION, $errors, false );

		self::update_location_status( $location_id, 'error' );

		if ( function_exists( 'error_log' ) ) {
			$log_line = sprintf(
				'[MLGR] Location %d sync error: %s%s',
				$location_id,
				$error_message,
				'' !== $context_message ? ' | context=' . $context_message : ''
			);
			error_log( $log_line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Convert a value into a trimmed error string.
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
