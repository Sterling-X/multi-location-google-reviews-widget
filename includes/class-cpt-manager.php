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
