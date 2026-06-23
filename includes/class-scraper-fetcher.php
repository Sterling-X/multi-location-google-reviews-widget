<?php
/**
 * Scraper API fetcher — connects to the local google-reviews-scraper-pro REST API.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Scraper_Fetcher {

	/**
	 * Option key for the scraper API base URL.
	 */
	const API_URL_OPTION = 'mlgr_scraper_api_url';

	/**
	 * Option key for the scraper API key.
	 */
	const API_KEY_OPTION = 'mlgr_scraper_api_key';

	/**
	 * Default API URL (local server).
	 */
	const DEFAULT_API_URL = 'http://localhost:8000';

	/**
	 * Build request headers, injecting the API key when configured.
	 *
	 * @param array $extra Additional headers to merge in.
	 * @return array
	 */
	private static function get_headers( $extra = array() ) {
		$headers = array();
		$api_key = trim( (string) get_option( self::API_KEY_OPTION, '' ) );
		if ( '' !== $api_key ) {
			$headers['X-API-Key'] = $api_key;
		}
		return array_merge( $headers, $extra );
	}

	/**
	 * Get the configured API base URL (no trailing slash).
	 *
	 * @return string
	 */
	private static function get_api_url() {
		$url = (string) get_option( self::API_URL_OPTION, self::DEFAULT_API_URL );
		$url = rtrim( trim( $url ), '/' );
		return '' !== $url ? $url : self::DEFAULT_API_URL;
	}

	/**
	 * Trigger a new scrape job for a Google Maps URL.
	 *
	 * @param string $maps_url    Google Maps URL (maps.app.goo.gl or google.com/maps/place).
	 * @param int    $location_id Internal location ID for error logging.
	 * @return array{job_id: string|null, error: string|null}
	 */
	public function trigger_scrape( $maps_url, $location_id = 0 ) {
		$result = array( 'job_id' => null, 'error' => null );

		$maps_url = trim( (string) $maps_url );
		if ( '' === $maps_url ) {
			$result['error'] = 'Missing Google Maps URL.';
			self::log_error( $location_id, 'missing_url', $result['error'], '' );
			return $result;
		}

		// Avoid duplicate scraper jobs: if a running or pending job already exists
		// for this exact URL, reuse its job_id instead of triggering a new scrape.
		$existing_job_id = $this->find_active_job_for_url( $maps_url );
		if ( null !== $existing_job_id ) {
			$result['job_id'] = $existing_job_id;
			return $result;
		}

		$endpoint = self::get_api_url() . '/scrape';
		$body     = wp_json_encode( array(
			'url'         => $maps_url,
			'scrape_mode' => 'update',
		) );

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 15,
				'headers' => self::get_headers( array( 'Content-Type' => 'application/json' ) ),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			$result['error'] = $response->get_error_message();
			self::log_error( $location_id, 'request_error', $result['error'], $endpoint );
			return $result;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$payload     = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status_code < 200 || $status_code >= 300 ) {
			$message         = is_array( $payload ) && ! empty( $payload['detail'] ) ? (string) $payload['detail'] : 'Scraper API returned HTTP ' . $status_code . '.';
			$result['error'] = $message;
			self::log_error( $location_id, 'http_' . $status_code, $message, $endpoint );
			return $result;
		}

		if ( ! is_array( $payload ) || empty( $payload['job_id'] ) ) {
			$result['error'] = 'Scraper API response missing job_id.';
			self::log_error( $location_id, 'invalid_response', $result['error'], $endpoint );
			return $result;
		}

		$result['job_id'] = (string) $payload['job_id'];
		return $result;
	}

	/**
	 * Check whether an active (running or pending) scrape job already exists for a URL.
	 *
	 * Fetches GET /jobs?status=running and GET /jobs?status=pending, combines the
	 * results, and returns the job_id of the first match on the url field.
	 * Returns null when no match is found or either request fails.
	 *
	 * @param string $maps_url Exact Google Maps URL to look for.
	 * @return string|null Matching job_id, or null if none found.
	 */
	private function find_active_job_for_url( $maps_url ) {
		$jobs = array();

		foreach ( array( 'running', 'pending' ) as $status ) {
			$endpoint = self::get_api_url() . '/jobs?status=' . $status;
			$response = wp_remote_get( $endpoint, array( 'timeout' => 10, 'headers' => self::get_headers() ) );

			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				continue;
			}

			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_array( $data ) ) {
				$jobs = array_merge( $jobs, $data );
			}
		}

		foreach ( $jobs as $job ) {
			if ( ! is_array( $job ) ) {
				continue;
			}
			$job_url = isset( $job['url'] ) ? (string) $job['url'] : '';
			$job_id  = isset( $job['job_id'] ) ? (string) $job['job_id'] : '';
			if ( $job_url === $maps_url && '' !== $job_id ) {
				return $job_id;
			}
		}

		return null;
	}

	/**
	 * Poll the status of a running scrape job.
	 *
	 * @param string $job_id      Job identifier returned by trigger_scrape().
	 * @param int    $location_id Internal location ID for error logging.
	 * @return array{status: string|null, error: string|null}
	 */
	public function get_job_status( $job_id, $location_id = 0 ) {
		$result = array( 'status' => null, 'error' => null );

		$job_id = trim( (string) $job_id );
		if ( '' === $job_id ) {
			$result['error'] = 'Missing job ID.';
			return $result;
		}

		$endpoint = self::get_api_url() . '/jobs/' . rawurlencode( $job_id );
		$response = wp_remote_get( $endpoint, array( 'timeout' => 10, 'headers' => self::get_headers() ) );

		if ( is_wp_error( $response ) ) {
			$result['error'] = $response->get_error_message();
			self::log_error( $location_id, 'request_error', $result['error'], $endpoint );
			return $result;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$payload     = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 404 === $status_code ) {
			$result['error'] = 'Scrape job not found (job_id: ' . $job_id . ').';
			self::log_error( $location_id, 'job_not_found', $result['error'], $endpoint );
			return $result;
		}

		if ( 200 !== $status_code || ! is_array( $payload ) ) {
			$result['error'] = 'Failed to retrieve job status from scraper API (HTTP ' . $status_code . ').';
			self::log_error( $location_id, 'http_' . $status_code, $result['error'], $endpoint );
			return $result;
		}

		$result['status'] = isset( $payload['status'] ) ? (string) $payload['status'] : 'unknown';
		return $result;
	}

	/**
	 * Search the scraper's place list for an entry matching the given Google Maps URL.
	 *
	 * @param string $maps_url Google Maps URL to look up.
	 * @return array|null Place data array, or null if not found.
	 */
	public function find_place_by_url( $maps_url ) {
		$maps_url = trim( (string) $maps_url );
		if ( '' === $maps_url ) {
			return null;
		}

		$endpoint = self::get_api_url() . '/places';
		$response = wp_remote_get( $endpoint, array( 'timeout' => 10, 'headers' => self::get_headers() ) );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$places = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $places ) ) {
			return null;
		}

		foreach ( $places as $place ) {
			if ( ! is_array( $place ) ) {
				continue;
			}
			$original_url = isset( $place['original_url'] ) ? (string) $place['original_url'] : '';
			if ( $original_url === $maps_url ) {
				return $place;
			}
		}

		// Fallback for alias URLs: maps.app.goo.gl short links may be stored as
		// aliases of a canonical place rather than appearing in GET /places directly.
		// Derive the short code from the URL path and call GET /places/short:{code},
		// which resolves aliases and returns the canonical place data.
		$parsed     = wp_parse_url( $maps_url );
		$host       = isset( $parsed['host'] ) ? (string) $parsed['host'] : '';
		$short_code = ( 'maps.app.goo.gl' === $host && isset( $parsed['path'] ) )
			? trim( (string) $parsed['path'], '/' )
			: '';

		if ( '' !== $short_code ) {
			$alias_endpoint = self::get_api_url() . '/places/short:' . rawurlencode( $short_code );
			$alias_response = wp_remote_get( $alias_endpoint, array( 'timeout' => 10, 'headers' => self::get_headers() ) );

			if ( ! is_wp_error( $alias_response ) && 200 === (int) wp_remote_retrieve_response_code( $alias_response ) ) {
				$canonical = json_decode( wp_remote_retrieve_body( $alias_response ), true );
				if ( is_array( $canonical ) ) {
					return $canonical;
				}
			}
		}

		return null;
	}

	/**
	 * Fetch place metadata from the scraper API.
	 *
	 * @param string $place_id Scraper place_id.
	 * @return array|null Place data array, or null on failure.
	 */
	public function get_place_info( $place_id ) {
		$place_id = trim( (string) $place_id );
		if ( '' === $place_id ) {
			return null;
		}

		$endpoint = self::get_api_url() . '/places/' . rawurlencode( $place_id );
		$response = wp_remote_get( $endpoint, array( 'timeout' => 10, 'headers' => self::get_headers() ) );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$payload = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $payload ) ? $payload : null;
	}

	/**
	 * Fetch all reviews for a place from the scraper API.
	 *
	 * Reviews are normalized to the internal format that Review_Syncer expects.
	 *
	 * @param string $place_id    Scraper place_id (e.g. "0x8805190838557531:0").
	 * @param int    $location_id Internal location ID for error logging.
	 * @return array{
	 *     reviews: array,
	 *     average_rating: float|null,
	 *     total_reviews: int|null,
	 *     place_name: string,
	 *     error: string|null
	 * }
	 */
	public function fetch_reviews( $place_id, $location_id = 0 ) {
		$result = array(
			'reviews'        => array(),
			'average_rating' => null,
			'total_reviews'  => null,
			'place_name'     => '',
			'error'          => null,
		);

		$place_id = trim( (string) $place_id );
		if ( '' === $place_id ) {
			$result['error'] = 'Missing place_id.';
			return $result;
		}

		// Pull place name and rating summary.
		$place_info = $this->get_place_info( $place_id );
		if ( is_array( $place_info ) ) {
			$result['place_name'] = isset( $place_info['place_name'] ) ? (string) $place_info['place_name'] : '';
		}

		// Paginate through all reviews — the API enforces a hard max of 1000 per request.
		$page_size   = 1000;
		$offset      = 0;
		$raw_reviews = array();

		do {
			$endpoint = self::get_api_url() . '/reviews/' . rawurlencode( $place_id )
				. '?limit=' . $page_size . '&offset=' . $offset;

			$response = wp_remote_get( $endpoint, array( 'timeout' => 30, 'headers' => self::get_headers() ) );

			if ( is_wp_error( $response ) ) {
				$result['error'] = $response->get_error_message();
				self::log_error( $location_id, 'request_error', $result['error'], $endpoint );
				return $result;
			}

			$status_code = (int) wp_remote_retrieve_response_code( $response );
			$payload     = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( 200 !== $status_code || ! is_array( $payload ) ) {
				$result['error'] = 'Failed to fetch reviews from scraper API (HTTP ' . $status_code . ').';
				self::log_error( $location_id, 'http_' . $status_code, $result['error'], $endpoint );
				return $result;
			}

			// Capture total count from the first page (it's the same on every page).
			if ( 0 === $offset && isset( $payload['total'] ) && is_numeric( $payload['total'] ) ) {
				$result['total_reviews'] = (int) $payload['total'];
			}

			$page = isset( $payload['reviews'] ) && is_array( $payload['reviews'] ) ? $payload['reviews'] : array();
			$raw_reviews = array_merge( $raw_reviews, $page );

			$offset += $page_size;
		} while ( count( $page ) === $page_size );

		// Compute average rating from all collected reviews.
		if ( ! empty( $raw_reviews ) ) {
			$rating_sum   = 0.0;
			$rating_count = 0;
			foreach ( $raw_reviews as $r ) {
				if ( is_array( $r ) && isset( $r['rating'] ) && is_numeric( $r['rating'] ) ) {
					$rating_sum += (float) $r['rating'];
					++$rating_count;
				}
			}
			if ( $rating_count > 0 ) {
				$result['average_rating'] = round( $rating_sum / $rating_count, 1 );
			}
		}

		$result['reviews'] = array_map( array( $this, 'normalize_review' ), $raw_reviews );

		return $result;
	}

	/**
	 * Normalize a raw scraper API review into the internal plugin format.
	 *
	 * The returned array uses the same key paths that Review_Syncer::upsert_review()
	 * already reads via array_get(), so the syncer needs no changes.
	 *
	 * @param mixed $raw Raw review array from the scraper API.
	 * @return array
	 */
	private function normalize_review( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		// Extract English review text, falling back to the first available language.
		$review_text_map = isset( $raw['review_text'] ) && is_array( $raw['review_text'] ) ? $raw['review_text'] : array();
		$text            = '';
		if ( isset( $review_text_map['en'] ) && is_string( $review_text_map['en'] ) ) {
			$text = $review_text_map['en'];
		} elseif ( ! empty( $review_text_map ) ) {
			$first = reset( $review_text_map );
			if ( is_string( $first ) ) {
				$text = $first;
			}
		}

		return array(
			// Matches array_get( $review, ['review_id'] ) in upsert_review()
			'review_id' => isset( $raw['review_id'] ) ? (string) $raw['review_id'] : '',
			// Matches array_get( $review, ['user', 'name'] ) and ['user', 'thumbnail']
			'user'      => array(
				'name'      => isset( $raw['author'] ) ? (string) $raw['author'] : '',
				'thumbnail' => isset( $raw['profile_picture'] ) ? (string) $raw['profile_picture'] : '',
			),
			// Matches array_get( $review, ['rating'] )
			'rating'    => isset( $raw['rating'] ) ? (float) $raw['rating'] : 0,
			// Matches array_get( $review, ['snippet'] )
			'snippet'   => $text,
			// Matches array_get( $review, ['iso_date'] )
			'iso_date'  => isset( $raw['review_date'] ) ? (string) $raw['review_date'] : '',
		);
	}

	/**
	 * Log an error to the Logger if available.
	 *
	 * @param int    $location_id  Internal location ID.
	 * @param string $error_code   Short error code.
	 * @param string $error_message Human-readable message.
	 * @param string $endpoint_url  URL that was called.
	 * @return void
	 */
	private static function log_error( $location_id, $error_code, $error_message, $endpoint_url ) {
		if ( class_exists( 'Logger' ) ) {
			Logger::log_error( absint( $location_id ), $error_code, $error_message, $endpoint_url );
		}
	}
}
