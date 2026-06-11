<?php
/**
 * Registers the mlgr_review custom post type and mlgr_linked_post taxonomy.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CPT_Manager {

	/**
	 * Custom post type slug.
	 */
	const POST_TYPE = 'mlgr_review';

	/**
	 * Taxonomy slug for linking reviews to WP posts/pages.
	 */
	const TAXONOMY = 'mlgr_linked_post';

	/**
	 * Post meta key storing the original Google review ID (used for deduplication).
	 */
	const META_GOOGLE_REVIEW_ID = '_mlgr_google_review_id';

	/**
	 * Post meta key storing the internal mlgr_locations row ID.
	 */
	const META_LOCATION_ID = '_mlgr_location_id';

	/**
	 * Post meta key storing the star rating (1–5).
	 */
	const META_RATING = '_mlgr_rating';

	/**
	 * Post meta key storing the reviewer's photo URL (from Google, set during sync).
	 */
	const META_AUTHOR_PHOTO = '_mlgr_author_photo';

	/**
	 * Post meta key storing a local WP attachment ID for the reviewer's photo.
	 * Takes priority over META_AUTHOR_PHOTO when set. Used for manually added reviews.
	 */
	const META_AUTHOR_PHOTO_ID = '_mlgr_author_photo_id';

	/**
	 * Term meta key storing the WP post ID linked to a taxonomy term.
	 */
	const TERM_META_LINKED_POST_ID = 'mlgr_linked_post_id';

	/**
	 * Register all hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'init', array( __CLASS__, 'register_taxonomy' ) );
		add_action( 'mlgr_linked_post_add_form_fields',  array( __CLASS__, 'render_term_add_field' ) );
		add_action( 'mlgr_linked_post_edit_form_fields', array( __CLASS__, 'render_term_edit_field' ) );
		add_action( 'created_mlgr_linked_post', array( __CLASS__, 'save_term_meta' ) );
		add_action( 'edited_mlgr_linked_post',  array( __CLASS__, 'save_term_meta' ) );

		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns',        array( __CLASS__, 'add_rating_column' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column',   array( __CLASS__, 'render_rating_column' ), 10, 2 );
		add_filter( 'manage_edit-' . self::POST_TYPE . '_sortable_columns', array( __CLASS__, 'rating_sortable_column' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'sort_by_rating' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_rating_meta_box' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_author_photo_meta_box' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_rating_meta' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_author_photo_meta' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_author_photo_scripts' ) );
	}

	/**
	 * Register the mlgr_review custom post type.
	 *
	 * @return void
	 */
	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'               => 'Google Reviews',
					'singular_name'      => 'Google Review',
					'all_items'          => 'All Reviews',
					'edit_item'          => 'Edit Review',
					'search_items'       => 'Search Reviews',
					'not_found'          => 'No reviews found.',
					'not_found_in_trash' => 'No reviews found in Trash.',
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_rest'        => false,
				'menu_icon'           => 'dashicons-star-filled',
				'menu_position'       => 25,
				'supports'            => array( 'title', 'editor', 'custom-fields' ),
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'has_archive'         => false,
				'taxonomies'          => array( self::TAXONOMY ),
			)
		);
	}

	/**
	 * Register the mlgr_linked_post taxonomy.
	 *
	 * @return void
	 */
	public static function register_taxonomy() {
		register_taxonomy(
			self::TAXONOMY,
			self::POST_TYPE,
			array(
				'labels'            => array(
					'name'              => 'Linked Posts',
					'singular_name'     => 'Linked Post',
					'all_items'         => 'All Linked Posts',
					'edit_item'         => 'Edit Linked Post',
					'add_new_item'      => 'Add New Linked Post',
					'search_items'      => 'Search Linked Posts',
					'not_found'         => 'No linked posts found.',
					'menu_name'         => 'Linked Posts',
				),
				'public'            => false,
				'show_ui'           => true,
				'show_in_menu'      => true,
				'show_in_rest'      => false,
				'show_admin_column' => true,
				'hierarchical'      => false,
				'rewrite'           => false,
			)
		);
	}

	/**
	 * Render linked post ID field on the Add Term screen.
	 *
	 * @return void
	 */
	public static function render_term_add_field() {
		?>
		<div class="form-field">
			<label for="mlgr_linked_post_id">Linked Post ID</label>
			<input type="number" id="mlgr_linked_post_id" name="mlgr_linked_post_id" value="" min="1" />
			<p class="description">
				Optional. Enter the WP post ID of the page or CPT post this term represents (e.g. an attorney's page).
				The term slug is used in the <code>linked_to</code> shortcode parameter.
			</p>
		</div>
		<?php
	}

	/**
	 * Render linked post ID field on the Edit Term screen.
	 *
	 * @param WP_Term $term Current term object.
	 * @return void
	 */
	public static function render_term_edit_field( $term ) {
		$linked_post_id = absint( get_term_meta( $term->term_id, self::TERM_META_LINKED_POST_ID, true ) );
		$linked_post    = $linked_post_id > 0 ? get_post( $linked_post_id ) : null;
		?>
		<tr class="form-field">
			<th scope="row">
				<label for="mlgr_linked_post_id">Linked Post ID</label>
			</th>
			<td>
				<input
					type="number"
					id="mlgr_linked_post_id"
					name="mlgr_linked_post_id"
					value="<?php echo esc_attr( $linked_post_id > 0 ? (string) $linked_post_id : '' ); ?>"
					min="1"
				/>
				<?php if ( null !== $linked_post ) : ?>
					<p class="description">
						Linked to:
						<a href="<?php echo esc_url( (string) get_edit_post_link( $linked_post_id ) ); ?>" target="_blank">
							<?php echo esc_html( $linked_post->post_title ); ?>
						</a>
						(ID <?php echo esc_html( (string) $linked_post_id ); ?>)
					</p>
				<?php else : ?>
					<p class="description">
						Optional. Enter the WP post ID of the page or CPT post this term represents.
						The term slug is used in the <code>linked_to</code> shortcode parameter.
					</p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Save linked post ID as term meta after create or update.
	 *
	 * @param int $term_id Term ID.
	 * @return void
	 */
	public static function save_term_meta( $term_id ) {
		if ( ! isset( $_POST['mlgr_linked_post_id'] ) ) {
			return;
		}

		$post_id = absint( $_POST['mlgr_linked_post_id'] );

		if ( $post_id > 0 ) {
			update_term_meta( $term_id, self::TERM_META_LINKED_POST_ID, $post_id );
		} else {
			delete_term_meta( $term_id, self::TERM_META_LINKED_POST_ID );
		}
	}

	/**
	 * Insert a Rating column after the Title column in the review list table.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public static function add_rating_column( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['mlgr_rating'] = 'Rating';
			}
		}
		return $new;
	}

	/**
	 * Render star rating in the Rating column.
	 *
	 * @param string $column  Column slug.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public static function render_rating_column( $column, $post_id ) {
		if ( 'mlgr_rating' !== $column ) {
			return;
		}

		$rating = (int) get_post_meta( $post_id, self::META_RATING, true );
		$stars  = str_repeat( '★', $rating ) . str_repeat( '☆', max( 0, 5 - $rating ) );

		printf(
			'<span style="color:#f5a623;font-size:15px;" title="%s">%s</span> <span style="color:#888;font-size:11px;">(%d)</span>',
			esc_attr( $rating . ' out of 5' ),
			esc_html( $stars ),
			$rating
		);
	}

	/**
	 * Make the Rating column sortable.
	 *
	 * @param array $sortable Existing sortable columns.
	 * @return array
	 */
	public static function rating_sortable_column( $sortable ) {
		$sortable['mlgr_rating'] = 'mlgr_rating';
		return $sortable;
	}

	/**
	 * Apply meta_value_num ordering when sorting by rating.
	 *
	 * @param WP_Query $query Current query.
	 * @return void
	 */
	public static function sort_by_rating( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( self::POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}
		if ( 'mlgr_rating' === $query->get( 'orderby' ) ) {
			$query->set( 'meta_key', self::META_RATING );
			$query->set( 'orderby', 'meta_value_num' );
		}
	}

	/**
	 * Register the Rating meta box on the edit review screen.
	 *
	 * @return void
	 */
	public static function register_rating_meta_box() {
		add_meta_box(
			'mlgr_rating_meta_box',
			'Rating',
			array( __CLASS__, 'render_rating_meta_box' ),
			self::POST_TYPE,
			'side',
			'high'
		);
	}

	/**
	 * Render editable star rating picker inside the meta box.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public static function render_rating_meta_box( $post ) {
		wp_nonce_field( 'mlgr_save_rating', 'mlgr_rating_nonce' );

		$rating = (int) get_post_meta( $post->ID, self::META_RATING, true );
		$rating = max( 0, min( 5, $rating ) );
		?>
		<style>
			.mlgr-star-picker {
				display: flex;
				flex-direction: row-reverse;
				justify-content: flex-end;
				gap: 2px;
				margin: 6px 0 8px;
			}
			.mlgr-star-picker input[type="radio"] {
				display: none;
			}
			.mlgr-star-picker label {
				font-size: 28px;
				color: #c9c9c9;
				cursor: pointer;
				line-height: 1;
			}
			.mlgr-star-picker input[type="radio"]:checked ~ label,
			.mlgr-star-picker label:hover,
			.mlgr-star-picker label:hover ~ label {
				color: #f5a623;
			}
		</style>
		<div class="mlgr-star-picker" id="mlgr-star-picker">
			<?php for ( $star = 5; $star >= 1; $star-- ) : ?>
				<input
					type="radio"
					id="mlgr_star_<?php echo esc_attr( (string) $star ); ?>"
					name="mlgr_rating_value"
					value="<?php echo esc_attr( (string) $star ); ?>"
					<?php checked( $rating, $star ); ?>
				/>
				<label for="mlgr_star_<?php echo esc_attr( (string) $star ); ?>" title="<?php echo esc_attr( $star . ' star' . ( 1 === $star ? '' : 's' ) ); ?>">★</label>
			<?php endfor; ?>
		</div>
		<p style="margin:0; color:#555; font-size:12px;" id="mlgr-rating-label">
			<?php echo esc_html( $rating > 0 ? $rating . ' out of 5' : 'No rating set' ); ?>
		</p>
		<script>
		(function() {
			var picker = document.getElementById('mlgr-star-picker');
			var label  = document.getElementById('mlgr-rating-label');
			if (!picker || !label) { return; }
			picker.addEventListener('change', function(e) {
				if (e.target && e.target.name === 'mlgr_rating_value') {
					var val = parseInt(e.target.value, 10);
					label.textContent = val + ' out of 5';
				}
			});
		}());
		</script>
		<?php
	}

	/**
	 * Save the star rating on post save.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function save_rating_meta( $post_id ) {
		if ( ! isset( $_POST['mlgr_rating_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( wp_unslash( $_POST['mlgr_rating_nonce'] ), 'mlgr_save_rating' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$rating = isset( $_POST['mlgr_rating_value'] ) ? (int) $_POST['mlgr_rating_value'] : 0;
		$rating = max( 1, min( 5, $rating ) );

		update_post_meta( $post_id, self::META_RATING, $rating );
	}

	/**
	 * Enqueue WP media library scripts on the review edit screen.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function enqueue_author_photo_scripts( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || self::POST_TYPE !== $screen->post_type ) {
			return;
		}
		wp_enqueue_media();
	}

	/**
	 * Register the Reviewer Photo meta box on the edit review screen.
	 *
	 * @return void
	 */
	public static function register_author_photo_meta_box() {
		add_meta_box(
			'mlgr_author_photo_meta_box',
			'Reviewer Photo',
			array( __CLASS__, 'render_author_photo_meta_box' ),
			self::POST_TYPE,
			'side',
			'default'
		);
	}

	/**
	 * Render the Reviewer Photo meta box.
	 *
	 * Shows the current local photo (if set), a media library picker, and a remove
	 * button. For synced reviews the Google URL is shown as a read-only fallback.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public static function render_author_photo_meta_box( $post ) {
		wp_nonce_field( 'mlgr_save_author_photo', 'mlgr_author_photo_nonce' );

		$attachment_id = absint( get_post_meta( $post->ID, self::META_AUTHOR_PHOTO_ID, true ) );
		$google_url    = (string) get_post_meta( $post->ID, self::META_AUTHOR_PHOTO, true );
		$preview_url   = $attachment_id > 0 ? wp_get_attachment_image_url( $attachment_id, 'thumbnail' ) : false;
		?>
		<div id="mlgr-author-photo-wrap" style="margin-top:4px;">
			<?php if ( false !== $preview_url ) : ?>
				<img
					id="mlgr-photo-preview"
					src="<?php echo esc_url( $preview_url ); ?>"
					alt=""
					style="max-width:100%; height:auto; border-radius:50%; margin-bottom:8px; display:block;"
				/>
			<?php else : ?>
				<img
					id="mlgr-photo-preview"
					src=""
					alt=""
					style="max-width:100%; height:auto; border-radius:50%; margin-bottom:8px; display:none;"
				/>
			<?php endif; ?>

			<input
				type="hidden"
				id="mlgr_author_photo_id"
				name="mlgr_author_photo_id"
				value="<?php echo esc_attr( $attachment_id > 0 ? (string) $attachment_id : '' ); ?>"
			/>

			<p style="margin:0 0 4px;">
				<button type="button" id="mlgr-select-photo" class="button button-secondary" style="width:100%;">
					<?php echo esc_html( $attachment_id > 0 ? 'Change Photo' : 'Upload / Select Photo' ); ?>
				</button>
			</p>
			<?php if ( $attachment_id > 0 ) : ?>
				<p style="margin:0;">
					<a href="#" id="mlgr-remove-photo" style="color:#b32d2e; font-size:12px;">Remove photo</a>
				</p>
			<?php else : ?>
				<p style="margin:0; display:none;">
					<a href="#" id="mlgr-remove-photo" style="color:#b32d2e; font-size:12px;">Remove photo</a>
				</p>
			<?php endif; ?>

			<?php if ( '' !== $google_url && 0 === $attachment_id ) : ?>
				<p style="margin:8px 0 0; font-size:11px; color:#888;">
					Currently using Google profile photo. Upload a local image above to override it.
				</p>
			<?php endif; ?>
		</div>
		<script>
		(function() {
			var mediaUploader;
			var selectBtn  = document.getElementById('mlgr-select-photo');
			var removeLink = document.getElementById('mlgr-remove-photo');
			var preview    = document.getElementById('mlgr-photo-preview');
			var input      = document.getElementById('mlgr_author_photo_id');

			if (!selectBtn) { return; }

			selectBtn.addEventListener('click', function(e) {
				e.preventDefault();
				if (mediaUploader) {
					mediaUploader.open();
					return;
				}
				mediaUploader = wp.media({
					title:    'Select Reviewer Photo',
					button:   { text: 'Use this photo' },
					multiple: false,
					library:  { type: 'image' }
				});
				mediaUploader.on('select', function() {
					var attachment = mediaUploader.state().get('selection').first().toJSON();
					input.value          = attachment.id;
					preview.src          = attachment.url;
					preview.style.display = 'block';
					removeLink.parentElement.style.display = 'block';
					selectBtn.textContent = 'Change Photo';
				});
				mediaUploader.open();
			});

			if (removeLink) {
				removeLink.addEventListener('click', function(e) {
					e.preventDefault();
					input.value           = '';
					preview.src           = '';
					preview.style.display = 'none';
					removeLink.parentElement.style.display = 'none';
					selectBtn.textContent = 'Upload / Select Photo';
				});
			}
		}());
		</script>
		<?php
	}

	/**
	 * Save the reviewer photo attachment ID on post save.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function save_author_photo_meta( $post_id ) {
		if ( ! isset( $_POST['mlgr_author_photo_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( wp_unslash( $_POST['mlgr_author_photo_nonce'] ), 'mlgr_save_author_photo' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$attachment_id = isset( $_POST['mlgr_author_photo_id'] ) ? absint( $_POST['mlgr_author_photo_id'] ) : 0;

		if ( $attachment_id > 0 ) {
			update_post_meta( $post_id, self::META_AUTHOR_PHOTO_ID, $attachment_id );
		} else {
			delete_post_meta( $post_id, self::META_AUTHOR_PHOTO_ID );
		}
	}

	/**
	 * Find an existing mlgr_review post by its Google review ID.
	 *
	 * @param string $google_review_id Original Google review identifier.
	 * @return int Post ID, or 0 if not found.
	 */
	public static function get_review_post_by_google_id( $google_review_id ) {
		$google_review_id = sanitize_text_field( (string) $google_review_id );
		if ( '' === $google_review_id ) {
			return 0;
		}

		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => 1,
				'meta_key'       => self::META_GOOGLE_REVIEW_ID,
				'meta_value'     => $google_review_id,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		return ! empty( $posts ) ? (int) $posts[0] : 0;
	}
}
