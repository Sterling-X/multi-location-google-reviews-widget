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
	 * Post meta key storing the reviewer's photo URL.
	 */
	const META_AUTHOR_PHOTO = '_mlgr_author_photo';

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
	 * Render read-only star rating inside the meta box.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public static function render_rating_meta_box( $post ) {
		$rating = (int) get_post_meta( $post->ID, self::META_RATING, true );
		$stars  = str_repeat( '★', $rating ) . str_repeat( '☆', max( 0, 5 - $rating ) );
		?>
		<p style="font-size:22px; color:#f5a623; margin:4px 0 2px;">
			<?php echo esc_html( $stars ); ?>
		</p>
		<p style="margin:0; color:#555;">
			<?php echo esc_html( $rating . ' out of 5' ); ?>
		</p>
		<?php
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
