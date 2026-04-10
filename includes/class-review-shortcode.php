<?php
/**
 * Frontend reviews shortcode renderer.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Review_Shortcode {

	/**
	 * Shortcode tag.
	 */
	const SHORTCODE_TAG = 'ml_google_reviews';

	/**
	 * Location summary shortcode tag.
	 */
	const RATING_SHORTCODE_TAG = 'ml_google_rating';

	/**
	 * Transient cache TTL in seconds.
	 */
	const CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * Default max visible characters before truncation.
	 */
	const DEFAULT_MAX_CHARS = 150;

	/**
	 * Register shortcode hook.
	 *
	 * @return void
	 */
	public static function init() {
		add_shortcode( self::SHORTCODE_TAG, array( __CLASS__, 'render' ) );
		add_shortcode( self::RATING_SHORTCODE_TAG, array( __CLASS__, 'render_rating' ) );
	}

	/**
	 * Render shortcode output.
	 *
	 * @param array<string, mixed> $atts Shortcode attributes.
	 * @return string
	 */
	public static function render( $atts ) {
		global $wpdb;

		$defaults = array(
			'location_id' => 0,
			'limit'       => 'all',
			'min_rating'  => 0,
			'exclude_ratings' => '',
			'max_chars'   => self::DEFAULT_MAX_CHARS,
			'layout'      => 'grid',
		);
		$atts     = shortcode_atts( $defaults, (array) $atts, self::SHORTCODE_TAG );

		$location_id = absint( $atts['location_id'] );
		$limit       = self::normalize_limit( $atts['limit'] );
		$min_rating  = is_numeric( $atts['min_rating'] ) ? (int) $atts['min_rating'] : 0;
		$exclude_ratings = self::normalize_exclude_ratings( $atts['exclude_ratings'] );
		$max_chars   = self::normalize_max_chars( $atts['max_chars'] );
		$layout      = self::normalize_layout( $atts['layout'] );
		$cache_limit = null === $limit ? 'all' : $limit;

		$min_rating = max( 0, min( 5, $min_rating ) );
		$anonymize  = (bool) get_option( Admin_Settings_Page::ANONYMIZE_REVIEWERS_OPTION, false );

		$cache_key = 'mlgr_shortcode_' . md5(
			wp_json_encode(
				array(
					'template'    => 'grid-widget-v8',
					'location_id' => $location_id,
					'limit'       => $cache_limit,
					'min_rating'  => $min_rating,
					'exclude_ratings' => $exclude_ratings,
					'max_chars'   => $max_chars,
					'layout'      => $layout,
					'anonymize'   => $anonymize,
				)
			)
		);

		$cached_html = get_transient( $cache_key );
		if ( false !== $cached_html && is_string( $cached_html ) ) {
			return $cached_html;
		}

		$reviews_table     = $wpdb->prefix . 'mlgr_reviews';
		$locations_table   = $wpdb->prefix . 'mlgr_locations';
		$review_url_column = self::resolve_review_url_column( $reviews_table );

		$where_parts = array(
			'r.is_hidden = 0',
			'r.rating >= %d',
		);
		$params      = array( $min_rating );

		if ( ! empty( $exclude_ratings ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $exclude_ratings ), '%d' ) );
			$where_parts[] = "r.rating NOT IN ({$placeholders})";
			$params        = array_merge( $params, $exclude_ratings );
		}

		if ( $location_id > 0 ) {
			$where_parts[] = 'r.location_id = %d';
			$params[]      = $location_id;
		}

		$select_fields = array(
			'r.id',
			'r.google_review_id',
			'r.author_photo',
			'r.author_name',
			'r.rating',
			'r.`text`',
			'r.publish_date',
			'l.google_place_id',
		);

		if ( '' !== $review_url_column ) {
			$select_fields[] = 'r.`' . $review_url_column . '` AS google_review_url';
		}

		$sql = "SELECT
					" . implode(
			",
					",
			$select_fields
		) . "
			FROM {$reviews_table} r
			LEFT JOIN {$locations_table} l ON l.id = r.location_id
			WHERE " . implode( ' AND ', $where_parts ) . '
			ORDER BY r.publish_date DESC, r.id DESC';

		if ( null !== $limit ) {
			$sql      .= ' LIMIT %d';
			$params[] = $limit;
		}

		$prepared_query = $wpdb->prepare( $sql, $params );
		$reviews        = $wpdb->get_results( $prepared_query, ARRAY_A );
		$reviews        = is_array( $reviews ) ? $reviews : array();

		$html = self::build_markup( $reviews, $anonymize, $max_chars, $layout );

		set_transient( $cache_key, $html, self::CACHE_TTL );

		return $html;
	}

	/**
	 * Render overall rating summary shortcode output.
	 *
	 * @param array<string, mixed> $atts Shortcode attributes.
	 * @return string
	 */
	public static function render_rating( $atts ) {
		global $wpdb;

		$defaults = array(
			'location_id' => 0,
		);
		$atts     = shortcode_atts( $defaults, (array) $atts, self::RATING_SHORTCODE_TAG );

		$location_id     = absint( $atts['location_id'] );
		$locations_table = $wpdb->prefix . 'mlgr_locations';
		$row             = null;

		if ( $location_id > 0 ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT average_rating, total_reviews
					FROM {$locations_table}
					WHERE id = %d
					LIMIT 1",
					$location_id
				),
				ARRAY_A
			);
		} else {
			$row = $wpdb->get_row(
				"SELECT average_rating, total_reviews
				FROM {$locations_table}
				ORDER BY id ASC
				LIMIT 1",
				ARRAY_A
			);
		}

		if ( ! is_array( $row ) ) {
			return '';
		}

		$average_rating = isset( $row['average_rating'] ) && is_numeric( $row['average_rating'] )
			? max( 0.0, min( 5.0, (float) $row['average_rating'] ) )
			: null;
		$total_reviews = isset( $row['total_reviews'] ) && is_numeric( $row['total_reviews'] )
			? max( 0, (int) $row['total_reviews'] )
			: null;

		if ( null === $average_rating || null === $total_reviews ) {
			return '';
		}

		$rating_text  = number_format_i18n( $average_rating, 1 );
		$reviews_text = number_format_i18n( $total_reviews );
		$review_label = ( 1 === $total_reviews ) ? 'review' : 'reviews';

		return sprintf(
			'<span class="mlgr-rating-text">%s</span>',
			esc_html( $rating_text . ' / 5 based on ' . $reviews_text . ' ' . $review_label )
		);
	}

	/**
	 * Normalize shortcode limit value.
	 *
	 * Supported unlimited values: "all", "0", "-1", and empty.
	 *
	 * @param mixed $raw_limit Shortcode limit value.
	 * @return int|null
	 */
	private static function normalize_limit( $raw_limit ) {
		if ( null === $raw_limit ) {
			return null;
		}

		if ( is_numeric( $raw_limit ) ) {
			$limit = (int) $raw_limit;
			if ( $limit <= 0 ) {
				return null;
			}
			return min( $limit, 1000 );
		}

		$text = strtolower( trim( (string) $raw_limit ) );
		if ( '' === $text || 'all' === $text || '0' === $text || '-1' === $text ) {
			return null;
		}

		return null;
	}

	/**
	 * Normalize max chars attribute.
	 *
	 * @param mixed $raw_max_chars Raw max chars value.
	 * @return int
	 */
	private static function normalize_max_chars( $raw_max_chars ) {
		$max_chars = is_numeric( $raw_max_chars ) ? absint( $raw_max_chars ) : self::DEFAULT_MAX_CHARS;
		if ( $max_chars < 60 ) {
			$max_chars = 60;
		}
		if ( $max_chars > 1000 ) {
			$max_chars = 1000;
		}
		return $max_chars;
	}

	/**
	 * Normalize comma-separated exclude-ratings list.
	 *
	 * Example: "4,5" => array( 4, 5 ).
	 *
	 * @param mixed $raw_ratings Raw attribute value.
	 * @return array<int, int>
	 */
	private static function normalize_exclude_ratings( $raw_ratings ) {
		if ( is_array( $raw_ratings ) ) {
			$raw_ratings = implode( ',', $raw_ratings );
		}

		$raw_ratings = trim( (string) $raw_ratings );
		if ( '' === $raw_ratings ) {
			return array();
		}

		$parts   = explode( ',', $raw_ratings );
		$ratings = array();

		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( '' === $part || ! is_numeric( $part ) ) {
				continue;
			}

			$rating = (int) $part;
			if ( $rating < 1 || $rating > 5 ) {
				continue;
			}

			$ratings[] = $rating;
		}

		$ratings = array_values( array_unique( $ratings ) );
		sort( $ratings, SORT_NUMERIC );

		return $ratings;
	}

	/**
	 * Normalize layout attribute.
	 *
	 * @param mixed $raw_layout Raw layout value.
	 * @return string
	 */
	private static function normalize_layout( $raw_layout ) {
		$layout = strtolower( trim( (string) $raw_layout ) );
		return in_array( $layout, array( 'grid', 'slider', 'masonry' ), true ) ? $layout : 'grid';
	}

	/**
	 * Resolve one available review URL column name, if present.
	 *
	 * @param string $reviews_table Reviews table name.
	 * @return string
	 */
	private static function resolve_review_url_column( $reviews_table ) {
		global $wpdb;

		static $cache = array();

		if ( isset( $cache[ $reviews_table ] ) ) {
			return $cache[ $reviews_table ];
		}

		$safe_table = preg_replace( '/[^a-zA-Z0-9_]/', '', (string) $reviews_table );
		if ( ! is_string( $safe_table ) || '' === $safe_table ) {
			$cache[ $reviews_table ] = '';
			return '';
		}

		$columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$safe_table}`", 0 );
		if ( ! is_array( $columns ) || empty( $columns ) ) {
			$cache[ $reviews_table ] = '';
			return '';
		}

		$normalized = array();
		foreach ( $columns as $column_name ) {
			if ( is_string( $column_name ) && '' !== trim( $column_name ) ) {
				$normalized[] = strtolower( trim( $column_name ) );
			}
		}

		$candidates = array(
			'google_review_url',
			'review_url',
			'google_url',
			'url',
			'link',
		);

		foreach ( $candidates as $candidate ) {
			if ( in_array( $candidate, $normalized, true ) ) {
				$cache[ $reviews_table ] = $candidate;
				return $candidate;
			}
		}

		$cache[ $reviews_table ] = '';
		return '';
	}

	/**
	 * Build shortcode HTML output.
	 *
	 * @param array<int, array<string, mixed>> $reviews    List of reviews.
	 * @param bool                              $anonymize  Whether reviewer details are anonymized.
	 * @param int                               $max_chars  Max text chars before truncation.
	 * @param string                            $layout     Layout name.
	 * @return string
	 */
	private static function build_markup( $reviews, $anonymize, $max_chars, $layout ) {
		$slider_id     = '';

		if ( 'slider' === $layout ) {
			$slider_id = function_exists( 'wp_unique_id' )
				? wp_unique_id( 'mlgr-slider-' )
				: 'mlgr-slider-' . wp_rand( 1000, 999999 );
		}

		ob_start();
		?>
		<div class="mlgr-grid-widget">
			<style>
				.mlgr-grid-widget {
					max-width: 100%;
					padding: 26px;
					border-radius: 24px;
					box-sizing: border-box;
					font-family: "Poppins", "Helvetica Neue", Arial, sans-serif;
				}
				.mlgr-grid-brand {
					position: absolute;
					right: 0;
					top: 8px;
					display: inline-flex;
					align-items: center;
					gap: 8px;
					color: #263238;
					font-size: 18px;
					font-weight: 600;
					line-height: 1;
				}
				.mlgr-grid-brand-icon {
					width: 22px;
					height: 22px;
					border-radius: 50%;
					background: #16a264;
					display: inline-flex;
					align-items: center;
					justify-content: center;
					flex-shrink: 0;
				}
				.mlgr-grid-brand-icon svg {
					width: 12px;
					height: 12px;
				}
				.mlgr-grid-container {
					display: grid;
					grid-template-columns: repeat(3, 1fr);
					gap: 20px;
				}
				.mlgr-masonry-container {
					column-count: 3;
					column-gap: 20px;
				}
				.mlgr-masonry-container .mlgr-review-card {
					break-inside: avoid;
					margin: 0 0 20px;
					height: auto;
					display: block;
				}
				.mlgr-slider-container {
					position: relative;
					padding: 0 28px;
				}
				.mlgr-slider-viewport {
					overflow-x: auto;
					overflow-y: visible;
					scroll-behavior: smooth;
					scroll-snap-type: x mandatory;
					-ms-overflow-style: none;
					scrollbar-width: none;
				}
				.mlgr-slider-viewport::-webkit-scrollbar {
					display: none;
				}
				.mlgr-slider-track {
					display: flex;
					align-items: stretch;
					gap: 20px;
				}
				.mlgr-slider-track .mlgr-review-card {
					flex: 0 0 calc((100% - 40px) / 3);
					scroll-snap-align: start;
					height: auto;
					align-self: stretch;
				}
				.mlgr-slider-nav {
					position: absolute;
					top: 50%;
					transform: translateY(-50%);
					width: 50px;
					height: 50px;
					border-radius: 50%;
					border: 1px solid #dbdbdb;
					background: #f3f3f3;
					color: #60656b;
					display: inline-flex;
					align-items: center;
					justify-content: center;
					cursor: pointer;
					z-index: 2;
					box-shadow: 0 2px 6px rgba(15, 23, 42, 0.08);
				}
				.mlgr-slider-prev {
					left: 0;
				}
				.mlgr-slider-next {
					right: 0;
				}
				.mlgr-slider-nav svg {
					width: 22px;
					height: 22px;
				}
				.mlgr-slider-nav:disabled {
					opacity: 0.4;
					cursor: default;
				}
				.mlgr-slider-nav[hidden] {
					display: none;
				}
				.mlgr-review-card {
					background-color: #e7e7e7;
					border-radius: 18px;
					padding: 18px;
					display: flex;
					flex-direction: column;
					height: 100%;
					box-sizing: border-box;
				}
				.mlgr-review-header {
					display: flex;
					align-items: center;
					margin-bottom: 10px;
				}
				.mlgr-review-avatar {
					width: 42px;
					height: 42px;
					border-radius: 50%;
					object-fit: cover;
					margin-right: 12px;
					flex-shrink: 0;
				}
				.mlgr-review-avatar-placeholder {
					background: #d0d0d0;
					color: #ffffff;
					display: flex;
					align-items: center;
					justify-content: center;
					font-size: 14px;
					font-weight: 600;
				}
				.mlgr-review-meta {
					min-width: 0;
				}
				.mlgr-review-author {
					color: #0a0b0d;
					font-size: 20px;
					font-weight: 700;
					line-height: 1.1;
					overflow: hidden;
					text-overflow: ellipsis;
					white-space: nowrap;
				}
				.mlgr-review-date {
					color: #8b8f94;
					font-size: 14px;
					margin-top: 2px;
				}
				.mlgr-review-rating {
					display: inline-flex;
					align-items: center;
					gap: 10px;
					margin-bottom: 10px;
				}
				.mlgr-review-stars {
					display: inline-flex;
					align-items: center;
					gap: 2px;
				}
				.mlgr-review-stars svg {
					width: 20px;
					height: 20px;
					flex-shrink: 0;
				}
				.mlgr-review-stars svg.is-filled {
					fill: #fbbc04;
				}
				.mlgr-review-stars svg.is-empty {
					fill: #c9c9c9;
				}
				.mlgr-verified-badge {
					width: 18px;
					height: 18px;
					border-radius: 50%;
					background: #3b82f6;
					display: inline-flex;
					align-items: center;
					justify-content: center;
					flex-shrink: 0;
				}
				.mlgr-verified-badge svg {
					width: 10px;
					height: 10px;
				}
				.mlgr-review-text {
					flex-grow: 1;
					margin: 0;
					color: #121417;
					font-size: 17px;
					line-height: 1.45;
				}
				.mlgr-read-more {
					display: inline-block;
					margin-top: 10px;
					color: #7b7f85;
					font-size: 15px;
					text-decoration: none;
				}
				.mlgr-read-more:hover,
				.mlgr-read-more:focus {
					text-decoration: underline;
				}
				.mlgr-load-more-footer {
					text-align: center;
					margin-top: 24px;
				}
				.mlgr-load-more {
					display: inline-block;
					padding: 12px 30px;
					border-radius: 16px;
					background: #e0e0e0;
					color: #1f242a;
					text-decoration: none;
					font-size: 20px;
					font-weight: 500;
				}
				.mlgr-empty-state {
					background: #e7e7e7;
					border-radius: 16px;
					padding: 16px;
					color: #3b424a;
					text-align: center;
				}
				@media (max-width: 1100px) {
					.mlgr-grid-container {
						grid-template-columns: repeat(2, 1fr);
					}
					.mlgr-masonry-container {
						column-count: 2;
					}
					.mlgr-slider-track .mlgr-review-card {
						flex-basis: calc((100% - 20px) / 2);
					}
					.mlgr-grid-brand {
						position: static;
						justify-content: center;
						margin-top: 12px;
					}
				}
				@media (max-width: 767px) {
					.mlgr-grid-widget {
						padding: 18px;
						border-radius: 16px;
					}
					.mlgr-grid-container {
						grid-template-columns: 1fr;
					}
					.mlgr-masonry-container {
						column-count: 1;
					}
					.mlgr-slider-container {
						padding: 0 18px;
					}
					.mlgr-slider-track .mlgr-review-card {
						flex-basis: 100%;
					}
					.mlgr-slider-nav {
						width: 42px;
						height: 42px;
					}
					.mlgr-review-author {
						font-size: 18px;
					}
					.mlgr-review-text {
						font-size: 16px;
					}
					.mlgr-load-more {
						font-size: 18px;
					}
				}
			</style>

				<?php if ( empty( $reviews ) ) : ?>
					<div class="mlgr-empty-state">No reviews found for the selected filters.</div>
				<?php else : ?>
					<?php if ( 'slider' === $layout ) : ?>
					<div class="mlgr-slider-container" id="<?php echo esc_attr( $slider_id ); ?>">
						<button class="mlgr-slider-nav mlgr-slider-prev" type="button" aria-label="Previous reviews">
							<svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
								<path d="M14.5 5.5L8 12l6.5 6.5" stroke="currentColor" stroke-width="2.2" fill="none" stroke-linecap="round" stroke-linejoin="round"></path>
							</svg>
						</button>
						<div class="mlgr-slider-viewport">
								<div class="mlgr-slider-track">
									<?php echo self::render_review_cards_markup( $reviews, $anonymize, $max_chars, true, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								</div>
							</div>
						<button class="mlgr-slider-nav mlgr-slider-next" type="button" aria-label="Next reviews">
							<svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
								<path d="M9.5 5.5L16 12l-6.5 6.5" stroke="currentColor" stroke-width="2.2" fill="none" stroke-linecap="round" stroke-linejoin="round"></path>
							</svg>
						</button>
					</div>
					<script>
						(function() {
							var root = document.getElementById('<?php echo esc_js( $slider_id ); ?>');
							if (!root) {
								return;
							}

							var viewport = root.querySelector('.mlgr-slider-viewport');
							var track = root.querySelector('.mlgr-slider-track');
							var prevBtn = root.querySelector('.mlgr-slider-prev');
							var nextBtn = root.querySelector('.mlgr-slider-next');

							if (!viewport || !track || !prevBtn || !nextBtn) {
								return;
							}

							var getStep = function() {
								var firstCard = track.querySelector('.mlgr-review-card');
								if (!firstCard) {
									return viewport.clientWidth;
								}

								var trackStyles = window.getComputedStyle(track);
								var gap = parseFloat(trackStyles.columnGap || trackStyles.gap || '0');
								var cardWidth = firstCard.getBoundingClientRect().width;
								var cardsPerView = Math.max(1, Math.round((viewport.clientWidth + gap) / (cardWidth + gap)));

								return (cardWidth + gap) * cardsPerView;
							};

							var syncCardHeights = function() {
								var cards = track.querySelectorAll('.mlgr-review-card');
								var maxHeight = 0;
								var i = 0;

								for (i = 0; i < cards.length; i++) {
									cards[i].style.minHeight = '';
								}

								for (i = 0; i < cards.length; i++) {
									if (cards[i].offsetHeight > maxHeight) {
										maxHeight = cards[i].offsetHeight;
									}
								}

								if (maxHeight > 0) {
									for (i = 0; i < cards.length; i++) {
										cards[i].style.minHeight = maxHeight + 'px';
									}
								}
							};

							var updateNav = function() {
								var maxScroll = Math.max(0, viewport.scrollWidth - viewport.clientWidth);
								var canScroll = maxScroll > 4;

								if (!canScroll) {
									prevBtn.setAttribute('hidden', 'hidden');
									nextBtn.setAttribute('hidden', 'hidden');
									return;
								}

								prevBtn.removeAttribute('hidden');
								nextBtn.removeAttribute('hidden');
								prevBtn.disabled = viewport.scrollLeft <= 2;
								nextBtn.disabled = viewport.scrollLeft >= (maxScroll - 2);
							};

							prevBtn.addEventListener('click', function() {
								viewport.scrollBy({
									left: -getStep(),
									behavior: 'smooth'
								});
							});

							nextBtn.addEventListener('click', function() {
								viewport.scrollBy({
									left: getStep(),
									behavior: 'smooth'
								});
							});

							viewport.addEventListener('scroll', updateNav, { passive: true });
							window.addEventListener('resize', function() {
								syncCardHeights();
								updateNav();
							});

							var images = track.querySelectorAll('img');
							for (var i = 0; i < images.length; i++) {
								images[i].addEventListener('load', function() {
									syncCardHeights();
									updateNav();
								});
							}

							syncCardHeights();
							updateNav();
							setTimeout(function() {
								syncCardHeights();
								updateNav();
							}, 120);
						})();
					</script>
				<?php elseif ( 'masonry' === $layout ) : ?>
					<div class="mlgr-masonry-container">
						<?php echo self::render_review_cards_markup( $reviews, $anonymize, $max_chars, false, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
				<?php else : ?>
					<div class="mlgr-grid-container">
						<?php echo self::render_review_cards_markup( $reviews, $anonymize, $max_chars, true, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render full card list markup using the shared card structure.
	 *
	 * @param array<int, array<string, mixed>> $reviews   Review rows.
	 * @param bool                              $anonymize Whether reviewer details are anonymized.
	 * @param int                               $max_chars Max chars per review.
	 * @param bool                              $truncate_text Whether text should be truncated.
	 * @param bool                              $show_read_more Whether read-more link should be rendered.
	 * @return string
	 */
	private static function render_review_cards_markup( $reviews, $anonymize, $max_chars, $truncate_text = true, $show_read_more = true ) {
		ob_start();

		foreach ( $reviews as $review ) {
			if ( ! is_array( $review ) ) {
				continue;
			}

			echo self::render_review_card( $review, $anonymize, $max_chars, $truncate_text, $show_read_more ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		return (string) ob_get_clean();
	}

	/**
	 * Render one review card.
	 *
	 * @param array<string, mixed> $review    One review row.
	 * @param bool                 $anonymize Whether reviewer details are anonymized.
	 * @param int                  $max_chars Max chars per review.
	 * @param bool                 $truncate_text Whether text should be truncated.
	 * @param bool                 $show_read_more Whether read-more link should be rendered.
	 * @return string
	 */
	private static function render_review_card( $review, $anonymize, $max_chars, $truncate_text = true, $show_read_more = true ) {
		$author_name  = isset( $review['author_name'] ) ? (string) $review['author_name'] : '';
		$author_photo = isset( $review['author_photo'] ) ? (string) $review['author_photo'] : '';
		$rating_value = isset( $review['rating'] ) ? (int) $review['rating'] : 0;
		$rating_value = max( 0, min( 5, $rating_value ) );
		$review_text  = isset( $review['text'] ) ? (string) $review['text'] : '';
		$review_date  = isset( $review['publish_date'] ) ? (string) $review['publish_date'] : '';

		if ( $anonymize ) {
			$author_name  = 'Google User';
			$author_photo = '';
		} elseif ( '' === trim( $author_name ) ) {
			$author_name = 'Anonymous';
		}

		if ( '' !== $review_date ) {
			$review_date = mysql2date( 'Y-m-d', $review_date );
		}

		$truncated  = $truncate_text
			? self::truncate_review_text( $review_text, $max_chars )
			: array(
				'text'         => self::normalize_review_text( $review_text ),
				'is_truncated' => false,
			);
		$review_url = self::build_review_url( $review );

		ob_start();
		?>
		<div class="mlgr-review-card">
			<div class="mlgr-review-header">
				<?php if ( '' !== trim( $author_photo ) ) : ?>
					<img class="mlgr-review-avatar" src="<?php echo esc_url( $author_photo ); ?>" alt="<?php echo esc_attr( $author_name ); ?>" loading="lazy" decoding="async" />
				<?php else : ?>
					<span class="mlgr-review-avatar mlgr-review-avatar-placeholder" aria-hidden="true"><?php echo esc_html( self::get_author_initial( $author_name ) ); ?></span>
				<?php endif; ?>
				<div class="mlgr-review-meta">
					<div class="mlgr-review-author"><?php echo esc_html( $author_name ); ?></div>
					<?php if ( '' !== $review_date ) : ?>
						<div class="mlgr-review-date"><?php echo esc_html( $review_date ); ?></div>
					<?php endif; ?>
				</div>
			</div>

			<div class="mlgr-review-rating" aria-label="<?php echo esc_attr( $rating_value . ' out of 5 stars' ); ?>">
				<div class="mlgr-review-stars" aria-hidden="true">
					<?php for ( $star_index = 1; $star_index <= 5; $star_index++ ) : ?>
						<svg class="<?php echo esc_attr( $star_index <= $rating_value ? 'is-filled' : 'is-empty' ); ?>" viewBox="0 0 24 24" focusable="false">
							<path d="M12 2.5l2.87 5.81 6.42.93-4.64 4.52 1.1 6.39L12 17.14l-5.75 3.01 1.1-6.39L2.71 9.24l6.42-.93L12 2.5z"></path>
						</svg>
					<?php endfor; ?>
				</div>
				<span class="mlgr-verified-badge" aria-hidden="true">
					<svg viewBox="0 0 16 16" focusable="false">
						<path d="M3.5 8.5l2.5 2.5L12.5 4.5" stroke="#ffffff" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"></path>
					</svg>
				</span>
			</div>

			<p class="mlgr-review-text"><?php echo esc_html( $truncated['text'] ); ?></p>
			<?php if ( $show_read_more && ! empty( $truncated['is_truncated'] ) && '' !== $review_url ) : ?>
				<a href="<?php echo esc_url( $review_url ); ?>" target="_blank" rel="noopener noreferrer" class="mlgr-read-more">Read more</a>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Truncate review text to max chars with ellipsis.
	 *
	 * @param string $review_text Raw review text.
	 * @param int    $max_chars   Max chars.
	 * @return array{text: string, is_truncated: bool}
	 */
	private static function truncate_review_text( $review_text, $max_chars ) {
		$normalized = self::normalize_review_text( $review_text );
		$max_chars       = self::normalize_max_chars( $max_chars );

		if ( '' === $normalized ) {
			return array(
				'text'         => '',
				'is_truncated' => false,
			);
		}

		if ( self::str_length( $normalized ) <= $max_chars ) {
			return array(
				'text'         => $normalized,
				'is_truncated' => false,
			);
		}

		$snippet = self::str_sub( $normalized, 0, $max_chars );
		$snippet = rtrim( $snippet );

		return array(
			'text'         => $snippet . '...',
			'is_truncated' => true,
		);
	}

	/**
	 * Normalize review text by stripping tags and collapsing whitespace.
	 *
	 * @param string $review_text Raw review text.
	 * @return string
	 */
	private static function normalize_review_text( $review_text ) {
		$normalized_text = preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $review_text ) );
		return is_string( $normalized_text ) ? trim( $normalized_text ) : '';
	}

	/**
	 * Build review URL, prioritizing direct URL fields.
	 *
	 * @param array<string, mixed> $review Review row.
	 * @return string
	 */
	private static function build_review_url( $review ) {
		$direct_url_candidates = array(
			isset( $review['google_review_url'] ) ? (string) $review['google_review_url'] : '',
			isset( $review['review_url'] ) ? (string) $review['review_url'] : '',
			isset( $review['url'] ) ? (string) $review['url'] : '',
			isset( $review['link'] ) ? (string) $review['link'] : '',
		);

		foreach ( $direct_url_candidates as $candidate ) {
			$candidate = trim( $candidate );
			if ( '' === $candidate ) {
				continue;
			}

			$sanitized = esc_url_raw( $candidate );
			if ( '' !== $sanitized ) {
				return $sanitized;
			}
		}

		$place_ref = isset( $review['google_place_id'] ) ? trim( (string) $review['google_place_id'] ) : '';
		if ( '' !== $place_ref ) {
			if ( 0 === stripos( $place_ref, 'place_id:' ) ) {
				$place_ref = trim( substr( $place_ref, strlen( 'place_id:' ) ) );
			}

			if ( '' !== $place_ref && false === strpos( $place_ref, ':' ) ) {
				return 'https://www.google.com/maps/search/?api=1&query=Google&query_place_id=' . rawurlencode( $place_ref );
			}

			return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $place_ref );
		}

		$google_review_id = isset( $review['google_review_id'] ) ? trim( (string) $review['google_review_id'] ) : '';
		if ( '' !== $google_review_id ) {
			return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $google_review_id );
		}

		return '';
	}

	/**
	 * Get one avatar initial for placeholder display.
	 *
	 * @param string $author_name Reviewer name.
	 * @return string
	 */
	private static function get_author_initial( $author_name ) {
		$author_name = trim( (string) $author_name );
		if ( '' === $author_name ) {
			return '?';
		}

		$initial = function_exists( 'mb_substr' )
			? mb_substr( $author_name, 0, 1, 'UTF-8' )
			: substr( $author_name, 0, 1 );

		$initial = strtoupper( (string) $initial );

		return '' !== $initial ? $initial : '?';
	}

	/**
	 * String length helper with multibyte support.
	 *
	 * @param string $value Source string.
	 * @return int
	 */
	private static function str_length( $value ) {
		if ( function_exists( 'mb_strlen' ) ) {
			return (int) mb_strlen( $value, 'UTF-8' );
		}

		return (int) strlen( $value );
	}

	/**
	 * Substring helper with multibyte support.
	 *
	 * @param string $value  Source string.
	 * @param int    $start  Start offset.
	 * @param int    $length Length.
	 * @return string
	 */
	private static function str_sub( $value, $start, $length ) {
		if ( function_exists( 'mb_substr' ) ) {
			return (string) mb_substr( $value, $start, $length, 'UTF-8' );
		}

		return (string) substr( $value, $start, $length );
	}
}
