<?php
/**
 * SerpApi reviews fetcher.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SerpApi_Fetcher {

	/**
	 * SerpApi endpoint for review retrieval.
	 */
	const ENDPOINT = 'https://serpapi.com/search';

	/**
	 * Option key where the SerpApi API key is stored.
	 */
	const API_KEY_OPTION = 'mlgr_serpapi_api_key';

	/**
	 * Fetch a page of reviews from SerpApi.
	 *
	 * @param string      $location_ref    Google Place ID or SerpApi Data ID.
	 * @param string|null $next_page_token Optional token for pagination.
	 * @param int         $location_id     Internal location ID for logging context.
	 * @param string|null $next_request_url Optional direct next page URL.
	 * @return array{
	 *     reviews: array,
	 *     average_rating: float|null,
	 *     total_reviews: int|null,
	 *     next_page_token: string|null,
	 *     next_location_ref: string|null,
	 *     next_request_url: string|null,
	 *     request_location_ref: string|null,
	 *     error: string|null
	 * }
	 */
	public function fetch_reviews( $location_ref, $next_page_token = null, $location_id = 0, $next_request_url = null ) {
		$result = array(
			'reviews'         => array(),
			'average_rating'  => null,
			'total_reviews'   => null,
			'next_page_token' => null,
			'next_location_ref' => null,
			'next_request_url' => null,
			'request_location_ref' => null,
			'error'           => null,
		);

		$location_id  = absint( $location_id );
		$location_ref = is_string( $location_ref ) ? trim( $location_ref ) : '';
		if ( '' === $location_ref ) {
			$result['error'] = 'Missing required location identifier.';
			self::log_fetch_error( $location_id, 'missing_location_identifier', $result['error'], '' );
			return $result;
		}

		$api_key = get_option( self::API_KEY_OPTION, '' );
		if ( ! is_string( $api_key ) || '' === trim( $api_key ) ) {
			$result['error'] = 'SerpApi API key is not configured.';
			self::log_fetch_error( $location_id, 'missing_api_key', $result['error'], '' );
			return $result;
		}

		$request_url = '';
		if ( is_string( $next_request_url ) && '' !== trim( $next_request_url ) ) {
			$request_url = $this->build_request_url_from_next_url( trim( $next_request_url ), trim( $api_key ) );
		}

		$request_location_ref = $location_ref;
		if ( '' === $request_url ) {
			$params = array(
				'engine'  => 'google_maps_reviews',
				'api_key' => trim( $api_key ),
			);
			$location_param = $this->resolve_location_param( $location_ref );
			if ( '' === $location_param['value'] ) {
				$result['error'] = 'Missing required location identifier.';
				self::log_fetch_error( $location_id, 'missing_location_identifier', $result['error'], '' );
				return $result;
			}

			$should_resolve_data_id = (
				'place_id' === $location_param['name'] &&
				( ! is_string( $next_page_token ) || '' === trim( $next_page_token ) )
			);

			if ( $should_resolve_data_id ) {
				$resolved_data_id = $this->resolve_data_id_from_place_id( $location_param['value'], trim( $api_key ) );
				if ( '' !== $resolved_data_id ) {
					$location_param = array(
						'name'  => 'data_id',
						'value' => $resolved_data_id,
					);
				}
			}

			$params[ $location_param['name'] ] = $location_param['value'];
			$request_location_ref              = $location_param['name'] . ':' . $location_param['value'];

			if ( is_string( $next_page_token ) && '' !== trim( $next_page_token ) ) {
				$params['next_page_token'] = trim( $next_page_token );
				$params['num']             = 20;
			}

			$request_url = add_query_arg( $params, self::ENDPOINT );
		}

		try {
			$response = wp_remote_get(
				$request_url,
				array(
					'timeout' => 30,
				)
			);
		} catch ( Exception $exception ) {
			$result['error'] = $exception->getMessage();
			self::log_fetch_error( $location_id, 'request_exception', $result['error'], $request_url );
			return $result;
		}

		if ( is_wp_error( $response ) ) {
			$result['error'] = $response->get_error_message();
			$error_code      = $response->get_error_code();
			$error_code      = is_scalar( $error_code ) && '' !== trim( (string) $error_code ) ? 'wp_remote_' . sanitize_key( (string) $error_code ) : 'wp_remote_error';
			self::log_fetch_error( $location_id, $error_code, $result['error'], $request_url );
			return $result;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$payload     = json_decode( $body, true );

		if ( 200 !== $status_code ) {
			$message = is_array( $payload ) && ! empty( $payload['error'] )
				? ( is_scalar( $payload['error'] ) ? (string) $payload['error'] : wp_json_encode( $payload['error'] ) )
				: 'SerpApi request failed with status ' . $status_code . '.';

			$result['error'] = is_string( $message ) ? $message : 'SerpApi request failed with status ' . $status_code . '.';
			self::log_fetch_error( $location_id, 'http_' . $status_code, $result['error'], $request_url );
			return $result;
		}

		if ( ! is_array( $payload ) ) {
			$result['error'] = 'Invalid JSON response from SerpApi.';
			self::log_fetch_error( $location_id, 'invalid_json', $result['error'], $request_url );
			return $result;
		}

		if ( ! empty( $payload['error'] ) ) {
			$message = is_scalar( $payload['error'] ) ? (string) $payload['error'] : wp_json_encode( $payload['error'] );
			$result['error'] = is_string( $message ) ? $message : 'Unknown SerpApi error.';
			self::log_fetch_error( $location_id, 'api_error', $result['error'], $request_url );
			return $result;
		}

		if ( ! empty( $payload['reviews'] ) && is_array( $payload['reviews'] ) ) {
			$result['reviews'] = $payload['reviews'];
		}

		$summary                 = $this->extract_location_summary( $payload );
		$result['average_rating'] = $summary['average_rating'];
		$result['total_reviews']  = $summary['total_reviews'];

		$pagination                  = $this->extract_pagination_data( $payload );
		$result['next_page_token']   = $pagination['next_page_token'];
		$result['next_location_ref'] = $pagination['next_location_ref'];
		$result['next_request_url']  = $pagination['next_request_url'];
		$result['request_location_ref'] = $request_location_ref;

		return $result;
	}

	/**
	 * Persist one fetch error row if logger class is loaded.
	 *
	 * @param int    $location_id   Internal location ID.
	 * @param string $error_code    Error code.
	 * @param string $error_message Error message.
	 * @param string $request_url   Endpoint URL.
	 * @return void
	 */
	private static function log_fetch_error( $location_id, $error_code, $error_message, $request_url ) {
		if ( class_exists( 'Logger' ) ) {
			Logger::log_error( $location_id, $error_code, $error_message, $request_url );
		}
	}

	/**
	 * Normalize the direct next-page URL provided by SerpApi.
	 *
	 * @param string $next_url Next page URL.
	 * @param string $api_key  API key.
	 * @return string
	 */
	private function build_request_url_from_next_url( $next_url, $api_key ) {
		$next_url = trim( (string) $next_url );
		if ( '' === $next_url ) {
			return '';
		}

		$sanitized = esc_url_raw( $next_url );
		if ( ! is_string( $sanitized ) || '' === $sanitized ) {
			return '';
		}

		$host = wp_parse_url( $sanitized, PHP_URL_HOST );
		if ( ! is_string( $host ) || false === stripos( $host, 'serpapi.com' ) ) {
			return '';
		}

		$api_key = trim( (string) $api_key );
		if ( '' !== $api_key ) {
			$encoded_api_key = rawurlencode( $api_key );
			$count           = 0;
			$sanitized       = preg_replace( '/([?&])api_key=[^&]*/', '$1api_key=' . $encoded_api_key, $sanitized, 1, $count );
			if ( ! is_string( $sanitized ) ) {
				return '';
			}

			if ( 0 === $count ) {
				$sanitized = add_query_arg( 'api_key', $api_key, $sanitized );
			}
		}

		return $sanitized;
	}

	/**
	 * Resolve SerpApi data_id from a Google Place ID.
	 *
	 * @param string $place_id Google place ID.
	 * @param string $api_key  SerpApi key.
	 * @return string
	 */
	private function resolve_data_id_from_place_id( $place_id, $api_key ) {
		$place_id = trim( (string) $place_id );
		$api_key  = trim( (string) $api_key );
		if ( '' === $place_id || '' === $api_key ) {
			return '';
		}

		$request_url = add_query_arg(
			array(
				'engine'   => 'google_maps',
				'place_id' => $place_id,
				'api_key'  => $api_key,
			),
			self::ENDPOINT
		);

		try {
			$response = wp_remote_get(
				$request_url,
				array(
					'timeout' => 30,
				)
			);
		} catch ( Exception $exception ) {
			return '';
		}

		if ( is_wp_error( $response ) ) {
			return '';
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			return '';
		}

		$payload = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $payload ) ) {
			return '';
		}

		$candidates = array(
			isset( $payload['place_results']['data_id'] ) ? $payload['place_results']['data_id'] : null,
			isset( $payload['local_results'][0]['data_id'] ) ? $payload['local_results'][0]['data_id'] : null,
			isset( $payload['knowledge_graph']['data_id'] ) ? $payload['knowledge_graph']['data_id'] : null,
			isset( $payload['search_parameters']['data_id'] ) ? $payload['search_parameters']['data_id'] : null,
		);

		foreach ( $candidates as $candidate ) {
			if ( is_scalar( $candidate ) ) {
				$value = trim( (string) $candidate );
				if ( '' !== $value ) {
					return $value;
				}
			}
		}

		return '';
	}

	/**
	 * Resolve request parameter name for a location reference.
	 *
	 * Place IDs are most common in the plugin UI, while Data IDs are still accepted.
	 *
	 * @param string $location_ref User-supplied location identifier.
	 * @return array{name: string, value: string}
	 */
	private function resolve_location_param( $location_ref ) {
		$location_ref = trim( (string) $location_ref );

		if ( 0 === stripos( $location_ref, 'place_id:' ) ) {
			return array(
				'name'  => 'place_id',
				'value' => trim( substr( $location_ref, strlen( 'place_id:' ) ) ),
			);
		}

		if ( 0 === stripos( $location_ref, 'data_id:' ) ) {
			return array(
				'name'  => 'data_id',
				'value' => trim( substr( $location_ref, strlen( 'data_id:' ) ) ),
			);
		}

		$looks_like_data_id = (bool) preg_match( '/^0x[0-9a-f]+:0x[0-9a-f]+$/i', $location_ref ) || false !== strpos( $location_ref, ':' );
		if ( $looks_like_data_id ) {
			return array(
				'name'  => 'data_id',
				'value' => $location_ref,
			);
		}

		return array(
			'name'  => 'place_id',
			'value' => $location_ref,
		);
	}

	/**
	 * Extract location-level rating summary from known payload fields.
	 *
	 * @param array $payload API response payload.
	 * @return array{average_rating: float|null, total_reviews: int|null}
	 */
	private function extract_location_summary( array $payload ) {
		$average_rating = null;
		$total_reviews  = null;

		$rating_candidates = array(
			self::array_get( $payload, array( 'place_info', 'rating' ) ),
			self::array_get( $payload, array( 'place_results', 'rating' ) ),
			self::array_get( $payload, array( 'knowledge_graph', 'rating' ) ),
		);

		foreach ( $rating_candidates as $candidate ) {
			$normalized = self::normalize_rating_value( $candidate );
			if ( null !== $normalized ) {
				$average_rating = $normalized;
				break;
			}
		}

		$count_candidates = array(
			self::array_get( $payload, array( 'place_info', 'reviews' ) ),
			self::array_get( $payload, array( 'place_results', 'reviews' ) ),
			self::array_get( $payload, array( 'knowledge_graph', 'reviews' ) ),
		);

		foreach ( $count_candidates as $candidate ) {
			$normalized = self::normalize_review_count( $candidate );
			if ( null !== $normalized ) {
				$total_reviews = $normalized;
				break;
			}
		}

		return array(
			'average_rating' => $average_rating,
			'total_reviews'  => $total_reviews,
		);
	}

	/**
	 * Extract pagination values from known SerpApi response locations.
	 *
	 * @param array $payload API response payload.
	 * @return array{next_page_token: string|null, next_location_ref: string|null, next_request_url: string|null}
	 */
	private function extract_pagination_data( array $payload ) {
		$pagination = array(
			'next_page_token'   => null,
			'next_location_ref' => null,
			'next_request_url'  => null,
		);

		if ( ! empty( $payload['next_page_token'] ) && is_string( $payload['next_page_token'] ) ) {
			$pagination['next_page_token'] = trim( $payload['next_page_token'] );
		}

		if (
			! empty( $payload['serpapi_pagination']['next_page_token'] ) &&
			is_string( $payload['serpapi_pagination']['next_page_token'] )
		) {
			$pagination['next_page_token'] = trim( $payload['serpapi_pagination']['next_page_token'] );
		}

		if ( ! empty( $payload['serpapi_pagination']['next'] ) && is_string( $payload['serpapi_pagination']['next'] ) ) {
			$next_url = trim( $payload['serpapi_pagination']['next'] );
			if ( '' !== $next_url ) {
				$pagination['next_request_url'] = $next_url;
			}

			if ( null === $pagination['next_page_token'] ) {
				$token = $this->extract_query_param_raw( $next_url, 'next_page_token' );
				if ( null !== $token && '' !== $token ) {
					$pagination['next_page_token'] = $token;
				}
			}

			if ( null === $pagination['next_location_ref'] ) {
				$data_id = $this->extract_query_param_raw( $next_url, 'data_id' );
				if ( null !== $data_id && '' !== $data_id ) {
					$pagination['next_location_ref'] = 'data_id:' . $data_id;
				}
			}

			if ( null === $pagination['next_location_ref'] ) {
				$place_id = $this->extract_query_param_raw( $next_url, 'place_id' );
				if ( null !== $place_id && '' !== $place_id ) {
					$pagination['next_location_ref'] = 'place_id:' . $place_id;
				}
			}
		}

		if ( null === $pagination['next_location_ref'] ) {
			$data_id = $this->extract_data_id_from_payload( $payload );
			if ( '' !== $data_id ) {
				$pagination['next_location_ref'] = 'data_id:' . $data_id;
			}
		}

		if ( '' === (string) $pagination['next_page_token'] ) {
			$pagination['next_page_token'] = null;
		}

		if ( '' === (string) $pagination['next_location_ref'] ) {
			$pagination['next_location_ref'] = null;
		}

		if ( '' === (string) $pagination['next_request_url'] ) {
			$pagination['next_request_url'] = null;
		}

		return $pagination;
	}

	/**
	 * Extract one query parameter preserving raw token characters.
	 *
	 * @param string $url   Source URL.
	 * @param string $param Query parameter key.
	 * @return string|null
	 */
	private function extract_query_param_raw( $url, $param ) {
		$query = wp_parse_url( $url, PHP_URL_QUERY );
		if ( ! is_string( $query ) || '' === $query ) {
			return null;
		}

		$parts = explode( '&', $query );
		foreach ( $parts as $part ) {
			if ( '' === $part ) {
				continue;
			}

			$pair = explode( '=', $part, 2 );
			$key  = rawurldecode( (string) $pair[0] );
			if ( $key !== $param ) {
				continue;
			}

			$value = isset( $pair[1] ) ? rawurldecode( (string) $pair[1] ) : '';
			$value = trim( $value );
			if ( '' === $value ) {
				return null;
			}

			return $value;
		}

		return null;
	}

	/**
	 * Resolve data_id from known payload locations.
	 *
	 * @param array $payload API response payload.
	 * @return string
	 */
	private function extract_data_id_from_payload( array $payload ) {
		$candidates = array(
			isset( $payload['place_info']['data_id'] ) ? $payload['place_info']['data_id'] : null,
			isset( $payload['place_results']['data_id'] ) ? $payload['place_results']['data_id'] : null,
			isset( $payload['search_parameters']['data_id'] ) ? $payload['search_parameters']['data_id'] : null,
			isset( $payload['data_id'] ) ? $payload['data_id'] : null,
		);

		foreach ( $candidates as $candidate ) {
			if ( is_scalar( $candidate ) ) {
				$value = trim( (string) $candidate );
				if ( '' !== $value ) {
					return $value;
				}
			}
		}

		return '';
	}

	/**
	 * Safe nested array getter.
	 *
	 * @param array $source Source array.
	 * @param array $path   Nested path.
	 * @return mixed|null
	 */
	private static function array_get( array $source, array $path ) {
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
	 * Normalize a rating value into a 1-decimal float within 0..5.
	 *
	 * @param mixed $value Raw rating.
	 * @return float|null
	 */
	private static function normalize_rating_value( $value ) {
		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$text = trim( (string) $value );
		if ( '' === $text ) {
			return null;
		}

		$text = str_replace( ',', '.', $text );
		if ( ! is_numeric( $text ) ) {
			return null;
		}

		$rating = (float) $text;
		$rating = max( 0.0, min( 5.0, $rating ) );

		return round( $rating, 1 );
	}

	/**
	 * Normalize review count to a non-negative integer.
	 *
	 * @param mixed $value Raw review count.
	 * @return int|null
	 */
	private static function normalize_review_count( $value ) {
		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$text = trim( (string) $value );
		if ( '' === $text ) {
			return null;
		}

		$text = preg_replace( '/[^0-9]/', '', $text );
		if ( ! is_string( $text ) || '' === $text || ! is_numeric( $text ) ) {
			return null;
		}

		return max( 0, (int) $text );
	}
}
