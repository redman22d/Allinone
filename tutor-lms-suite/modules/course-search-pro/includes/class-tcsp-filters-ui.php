<?php
/**
 * Front-end filter/sort bar rendering + asset enqueue.
 *
 * @package Tutor_Course_Search_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TCSP_Filters_UI {

	/** @var TCSP_Settings */
	private $settings;

	/** @var TCSP_Query */
	private $query;

	public function __construct( TCSP_Settings $settings, TCSP_Query $query ) {
		$this->settings = $settings;
		$this->query    = $query;
	}

	public function register() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 20 );

		// Render the filter bar before the Tutor course loop on the archive.
		add_action( 'tutor_course/archive/before_loop', array( $this, 'render_bar' ) );
		// Fallbacks for themes/versions that expose different hooks.
		add_action( 'tutor_course/loop/before', array( $this, 'render_bar' ) );

		// Keep active filters/search on every pagination link.
		add_filter( 'paginate_links', array( $this, 'keep_filters_on_pagination' ) );

		// Shortcode escape hatch: [tcsp_filters]
		add_shortcode( 'tcsp_filters', array( $this, 'shortcode' ) );

		// Re-roll the sponsored carousel's course order whenever the course
		// loop is paginated over AJAX, so page 2+ gets a fresh weighted-random
		// draw instead of keeping page 1's carousel frozen in place.
		add_action( 'wp_ajax_tcsp_refresh_carousel', array( $this, 'ajax_refresh_carousel' ) );
		add_action( 'wp_ajax_nopriv_tcsp_refresh_carousel', array( $this, 'ajax_refresh_carousel' ) );
	}

	/**
	 * The filter/search query args currently active in the request.
	 *
	 * @return array
	 */
	private function active_args() {
		$scalar_keys = array( 's', 'tcsp_sort', 'tcsp_min_rating', 'tcsp_min_students', 'tcsp_verified', 'tcsp_price', 'tcsp_level', 'tcsp_category_logic', 'tcsp_tag_logic', 'tcsp_language_logic', 'tcsp_tutoring_logic' );
		$array_keys  = array( 'tcsp_category', 'tcsp_tag', 'tcsp_language', 'tcsp_tutoring_type', 'tcsp_wilaya', 'tcsp_municipality', 'tcsp_course_type', 'tcsp_tutoring_format' );
		$args        = array();

		foreach ( $scalar_keys as $k ) {
			if ( isset( $_GET[ $k ] ) && '' !== $_GET[ $k ] && ! is_array( $_GET[ $k ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$args[ $k ] = sanitize_text_field( wp_unslash( $_GET[ $k ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
		}

		// Multi-select filters (checkboxes) arrive as tcsp_category[]/tcsp_tag[].
		foreach ( $array_keys as $k ) {
			if ( ! empty( $_GET[ $k ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$raw_values = wp_unslash( $_GET[ $k ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$values = in_array( $k, array( 'tcsp_language', 'tcsp_tutoring_type' ), true )
					? TCSP_Query::sanitize_key_list( $raw_values )
					: TCSP_Query::sanitize_slug_list( $raw_values );
				if ( ! empty( $values ) ) {
					$args[ $k ] = $values;
				}
			}
		}

		return $args;
	}

	/**
	 * Append the active filters to a pagination URL so page 2+ keeps them.
	 *
	 * @param string $link Pagination link.
	 * @return string
	 */
	public function keep_filters_on_pagination( $link ) {
		if ( ! $this->is_course_context() ) {
			return $link;
		}
		$args = $this->active_args();
		if ( empty( $args ) ) {
			return $link;
		}
		// array_map( 'rawurlencode', ... ) can't be used directly here since
		// tcsp_category/tcsp_tag are arrays of values, not scalars.
		return add_query_arg( $this->rawurlencode_deep( $args ), $link );
	}

	/**
	 * rawurlencode() every scalar in a possibly-nested args array, leaving
	 * WP's add_query_arg() to build the tcsp_category[]=a&tcsp_category[]=b
	 * style query string for the array entries.
	 *
	 * @param array $args Query args (scalars and/or arrays of scalars).
	 * @return array
	 */
	private function rawurlencode_deep( $args ) {
		foreach ( $args as $key => $value ) {
			$args[ $key ] = is_array( $value ) ? array_map( 'rawurlencode', $value ) : rawurlencode( $value );
		}
		return $args;
	}

	/**
	 * Is this a page where the filter bar / suggestions should load?
	 */
	private function is_course_context() {
		return is_post_type_archive( TCSP_Plugin::COURSE_CPT )
			|| is_tax( 'course-category' )
			|| is_tax( 'course-tag' )
			|| ( is_search() );
	}

	public function enqueue() {
		// The sticker can be rendered by a Tutor course widget outside the
		// archive, so its small stylesheet is available site-wide. The larger
		// filter/autocomplete script remains archive-only.
		wp_enqueue_style(
			'tcsp-filters',
			TCSP_URL . 'assets/css/tcsp-filters.css',
			array(),
			TCSP_VERSION
		);

		if ( ! $this->is_course_context() ) {
			return;
		}

		wp_enqueue_script(
			'tcsp-filters',
			TCSP_URL . 'assets/js/tcsp-filters.js',
			array( 'jquery' ),
			TCSP_VERSION,
			true
		);

		wp_localize_script(
			'tcsp-filters',
			'tcspConfig',
			array(
				'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
				'suggestAction'      => TCSP_Suggest::ACTION,
				'nonce'              => wp_create_nonce( TCSP_Suggest::ACTION ),
				'refreshCarouselAction' => $this->settings->get( 'show_sponsored_carousel' ) ? 'tcsp_refresh_carousel' : '',
				'refreshCarouselNonce'  => wp_create_nonce( 'tcsp_refresh_carousel' ),
				// Tutor LMS's native wishlist endpoint expects Tutor's nonce key/action.
				'wishlistNonce'       => function_exists( 'tutor' ) ? wp_create_nonce( tutor()->nonce_action ) : '',
				'wishlistNonceKey'    => function_exists( 'tutor' ) ? tutor()->nonce : '_tutor_nonce',
				'wishlistAction'      => 'tutor_course_add_to_wishlist',
				'replaceSuggestions' => (int) $this->settings->get( 'replace_suggestions' ),
				'defaultSort'        => (string) $this->settings->get( 'default_sort' ),
				'communes'          => $this->commune_options_map(),
				'minChars'           => 2,
				'i18n'               => array(
					'searching'  => __( 'Searching…', 'tutor-course-search-pro' ),
					'noResults'  => __( 'No matching courses', 'tutor-course-search-pro' ),
					'promoted'   => __( 'Promoted', 'tutor-course-search-pro' ),
					'viewAll'    => __( 'View all results', 'tutor-course-search-pro' ),
				),
			)
		);

		// If replacing suggestions, tell Tutor's default search to stand down.
		if ( $this->settings->get( 'replace_suggestions' ) ) {
			wp_dequeue_script( 'tutor-course-search' );
			wp_dequeue_script( 'tutor-frontend-search' );
		}
	}

	private function profile_language_choices() {
		if ( class_exists( 'Tlms_Ipe_Plugin' ) && method_exists( 'Tlms_Ipe_Plugin', 'language_choices' ) ) { return Tlms_Ipe_Plugin::language_choices(); }
		return array( 'ar' => 'Arabic', 'en' => 'English', 'fr' => 'French', 'es' => 'Spanish', 'it' => 'Italian', 'ru' => 'Russian', 'zh' => 'Chinese (Mandarin)', 'yue' => 'Chinese (Cantonese)', 'tr' => 'Turkish' );
	}

	private function profile_tutoring_choices() {
		if ( class_exists( 'Tlms_Ipe_Plugin' ) && method_exists( 'Tlms_Ipe_Plugin', 'tutoring_type_choices' ) ) { return Tlms_Ipe_Plugin::tutoring_type_choices(); }
		return array( 'online' => 'Online', 'tutor_home' => "Offline at tutor's home", 'student_home' => "Offline at student's home" );
	}

	private function wilaya_options() {
		return class_exists( 'Tlms_Ipe_Locations' ) ? Tlms_Ipe_Locations::wilaya_options() : array();
	}

	private function commune_options_map() {
		// Use the exact location provider from Tutor LMS Instructor Profile Extra.
		// That plugin owns the canonical Algeria dataset used to save tutor Wilaya
		// and commune values. Do not fabricate a second location structure here.
		if ( class_exists( 'Tlms_Ipe_Locations' ) ) {
			$map = Tlms_Ipe_Locations::commune_options_map();
			return is_array( $map ) ? $map : array();
		}

		// Safe fallback when the profile plugin class has not been loaded yet.
		// Read the same canonical JSON shipped by that plugin. This keeps the
		// search filter functional even when plugin load order differs.
		$paths = array();
		if ( defined( 'WP_PLUGIN_DIR' ) ) {
			$paths[] = WP_PLUGIN_DIR . '/tutor-lms-instructor-profile-extra/assets/data/algeria-cities.json';
			$matches = glob( WP_PLUGIN_DIR . '/*/assets/data/algeria-cities.json' );
			if ( is_array( $matches ) ) {
				$paths = array_merge( $paths, $matches );
			}
		}

		foreach ( array_unique( $paths ) as $path ) {
			if ( ! is_readable( $path ) ) {
				continue;
			}
			$raw = json_decode( (string) file_get_contents( $path ), true );
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$map = array();
			foreach ( $raw as $row ) {
				if ( ! is_array( $row ) || empty( $row['wilaya_code'] ) || ! isset( $row['id'] ) ) {
					continue;
				}
				$wilaya = (string) $row['wilaya_code'];
				$id = (string) $row['id'];
				if ( ! isset( $map[ $wilaya ] ) ) {
					$map[ $wilaya ] = array();
				}
				$latin = isset( $row['commune_name_ascii'] ) ? (string) $row['commune_name_ascii'] : $id;
				$ar = isset( $row['commune_name'] ) ? (string) $row['commune_name'] : '';
				$label = trim( $latin );
				if ( $ar && $ar !== $latin ) {
					$label .= ' — ' . $ar;
				}
				$map[ $wilaya ][ $id ] = array(
					'label' => $label,
					'value' => $id,
				);
			}
			return $map;
		}

		return array();
	}

	/**
	 * The filter/sort bar markup.
	 */
	public function render_bar() {
		static $rendered = false;
		if ( $rendered || ! $this->is_course_context() ) {
			return;
		}
		$rendered = true;

		$s          = $this->settings;
		$get        = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$cur_price  = isset( $get['tcsp_price'] ) ? sanitize_key( $get['tcsp_price'] ) : '';
		$cur_level  = isset( $get['tcsp_level'] ) ? sanitize_key( $get['tcsp_level'] ) : '';
		$cur_cats   = ! empty( $get['tcsp_category'] ) ? TCSP_Query::sanitize_slug_list( $get['tcsp_category'] ) : array();
		$cur_tags   = ! empty( $get['tcsp_tag'] ) ? TCSP_Query::sanitize_slug_list( $get['tcsp_tag'] ) : array();
		$cur_rating = isset( $get['tcsp_min_rating'] ) ? (int) $get['tcsp_min_rating'] : 0;
		$cur_students = isset( $get['tcsp_min_students'] ) ? (int) $get['tcsp_min_students'] : 0;
		$cur_sort   = isset( $get['tcsp_sort'] ) ? sanitize_key( $get['tcsp_sort'] ) : (string) $s->get( 'default_sort' );
		$cur_search = isset( $get['s'] ) ? sanitize_text_field( $get['s'] ) : '';
		$cur_verified = isset( $get['tcsp_verified'] ) ? sanitize_key( $get['tcsp_verified'] ) : '';
		$cur_languages = ! empty( $get['tcsp_language'] ) ? TCSP_Query::sanitize_key_list( $get['tcsp_language'] ) : array();
		$cur_types = ! empty( $get['tcsp_tutoring_type'] ) ? TCSP_Query::sanitize_key_list( $get['tcsp_tutoring_type'] ) : array();
		$cur_wilaya = ! empty( $get['tcsp_wilaya'] ) ? TCSP_Query::sanitize_key_list( $get['tcsp_wilaya'] ) : array();
		$cur_municipality = ! empty( $get['tcsp_municipality'] ) ? TCSP_Query::sanitize_key_list( $get['tcsp_municipality'] ) : array();
		$cur_course_types = ! empty( $get['tcsp_course_type'] ) ? TCSP_Query::sanitize_key_list( $get['tcsp_course_type'] ) : array();
		$cur_tutoring_formats = ! empty( $get['tcsp_tutoring_format'] ) ? TCSP_Query::sanitize_key_list( $get['tcsp_tutoring_format'] ) : array();
		$cur_category_logic = ( isset( $get['tcsp_category_logic'] ) && 'or' === sanitize_key( $get['tcsp_category_logic'] ) ) ? 'or' : 'and';
		$cur_tag_logic      = ( isset( $get['tcsp_tag_logic'] ) && 'or' === sanitize_key( $get['tcsp_tag_logic'] ) ) ? 'or' : 'and';
		$cur_language_logic = ( isset( $get['tcsp_language_logic'] ) && 'or' === sanitize_key( $get['tcsp_language_logic'] ) ) ? 'or' : 'and';
		$cur_type_logic = ( isset( $get['tcsp_tutoring_logic'] ) && 'or' === sanitize_key( $get['tcsp_tutoring_logic'] ) ) ? 'or' : 'and';
		$cur_wilaya_logic = ( isset( $get['tcsp_wilaya_logic'] ) && 'and' === sanitize_key( $get['tcsp_wilaya_logic'] ) ) ? 'and' : 'or';
		$cur_municipality_logic = ( isset( $get['tcsp_municipality_logic'] ) && 'and' === sanitize_key( $get['tcsp_municipality_logic'] ) ) ? 'and' : 'or';
		$has_active = (bool) array_filter( array( $cur_price, $cur_level, $cur_cats, $cur_tags, $cur_rating, $cur_students, $cur_search, $cur_verified, $cur_languages, $cur_types, $cur_wilaya, $cur_municipality, $cur_course_types, $cur_tutoring_formats ) );

		$found_posts = $this->query->get_last_found_posts();
		?>
		<?php if ( $s->get( 'show_result_count' ) && null !== $found_posts ) : ?>
			<p class="tcsp-result-count">
				<?php
				$live_search = '' !== $cur_search ? $cur_search : $this->query->get_last_search_term();
				if ( '' !== $live_search ) {
					printf(
						/* translators: 1: number of results, 2: search term */
						esc_html( _n( '%1$s result for "%2$s"', '%1$s results for "%2$s"', $found_posts, 'tutor-course-search-pro' ) ),
						esc_html( number_format_i18n( $found_posts ) ),
						esc_html( $live_search )
					);
				} else {
					printf(
						/* translators: %s: number of results */
						esc_html( _n( '%s course found', '%s courses found', $found_posts, 'tutor-course-search-pro' ) ),
						esc_html( number_format_i18n( $found_posts ) )
					);
				}
				?>
			</p>
		<?php endif; ?>
		<form class="tcsp-filter-bar tcsp-sidebar-filter" method="get" role="search">
			<div class="tcsp-filter-row tcsp-filter-row-top">
				<div class="tcsp-search-wrap">
					<input type="search" name="s" class="tcsp-search-input" autocomplete="off"
						value="<?php echo esc_attr( $cur_search ); ?>"
						placeholder="<?php esc_attr_e( 'Search courses…', 'tutor-course-search-pro' ); ?>">
					<div class="tcsp-suggest-box" hidden></div>
				</div>

				<select name="tcsp_sort" class="tcsp-select tcsp-sort" aria-label="<?php esc_attr_e( 'Sort by', 'tutor-course-search-pro' ); ?>">
					<?php foreach ( $s->sort_options() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $cur_sort, $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>

				<?php if ( $s->get( 'enable_course_type_filter' ) ) : ?>
					<?php echo $this->render_choice_multiselect( 'course_type', __( 'Course type', 'tutor-course-search-pro' ), $cur_course_types, 'or', $this->course_type_choices(), false, false ); ?>
				<?php endif; ?>
				<?php if ( $s->get( 'enable_tutoring_format_filter' ) ) : ?>
					<div class="tcsp-course-format-subfilter<?php echo $cur_course_types && array_intersect( $cur_course_types, array( 'direct', 'hybrid' ) ) ? ' is-visible' : ''; ?>" data-tcsp-course-format-subfilter>
						<?php echo $this->render_choice_multiselect( 'tutoring_format', __( 'Tutoring format', 'tutor-course-search-pro' ), $cur_tutoring_formats, 'or', $this->tutoring_format_choices(), false, false ); ?>
					</div>
				<?php endif; ?>

				<?php if ( $s->get( 'enable_category_filter' ) ) : ?>
					<?php echo $this->render_multiselect( 'category', __( 'Category', 'tutor-course-search-pro' ), $cur_cats, $cur_category_logic, $this->categories(), 'course-category' ); ?>
				<?php endif; ?>
				<?php if ( $s->get( 'enable_tag_filter' ) ) : ?>
					<?php echo $this->render_multiselect( 'tag', __( 'Tag', 'tutor-course-search-pro' ), $cur_tags, $cur_tag_logic, $this->tags(), 'course-tag' ); ?>
				<?php endif; ?>
				<?php if ( $s->get( 'enable_rating_filter' ) ) : ?>
					<select name="tcsp_min_rating" class="tcsp-select" aria-label="<?php esc_attr_e( 'Minimum rating', 'tutor-course-search-pro' ); ?>"><option value="0"><?php esc_html_e( 'Any rating', 'tutor-course-search-pro' ); ?></option><?php for ( $r = 4; $r >= 1; $r-- ) : ?><option value="<?php echo esc_attr( $r ); ?>" <?php selected( $cur_rating, $r ); ?>><?php printf( esc_html__( '%d★ & up', 'tutor-course-search-pro' ), (int) $r ); ?></option><?php endfor; ?></select>
				<?php endif; ?>
			</div>

			<div class="tcsp-filter-row tcsp-filter-row-bottom">
				<?php if ( $s->get( 'enable_verified_filter' ) ) : ?><select name="tcsp_verified" class="tcsp-select tcsp-verified-filter" aria-label="<?php esc_attr_e( 'Verified instructor', 'tutor-course-search-pro' ); ?>"><option value=""><?php esc_html_e( 'All instructors', 'tutor-course-search-pro' ); ?></option><option value="verified" <?php selected( $cur_verified, 'verified' ); ?>><?php esc_html_e( 'Verified instructor', 'tutor-course-search-pro' ); ?></option></select><?php endif; ?>
				<?php if ( $s->get( 'enable_students_filter' ) ) : ?><select name="tcsp_min_students" class="tcsp-select tcsp-students-filter" aria-label="<?php esc_attr_e( 'Minimum students', 'tutor-course-search-pro' ); ?>"><option value="0"><?php esc_html_e( 'Any number of students', 'tutor-course-search-pro' ); ?></option><?php foreach ( array( 5, 10, 20, 50, 100, 500 ) as $threshold ) : ?><option value="<?php echo esc_attr( $threshold ); ?>" <?php selected( $cur_students, $threshold ); ?>><?php printf( esc_html__( '%s+ students', 'tutor-course-search-pro' ), esc_html( number_format_i18n( $threshold ) ) ); ?></option><?php endforeach; ?></select><?php endif; ?>

				<?php if ( $s->get( 'enable_spoken_language_filter' ) ) : ?>
					<?php echo $this->render_choice_multiselect( 'language', __( 'Languages', 'tutor-course-search-pro' ), $cur_languages, $cur_language_logic, $this->profile_language_choices() ); ?>
				<?php endif; ?>
				<?php if ( $s->get( 'enable_tutoring_type_filter' ) ) : ?>
					<?php echo $this->render_choice_multiselect( 'tutoring_type', __( 'Tutoring type', 'tutor-course-search-pro' ), $cur_types, $cur_type_logic, $this->profile_tutoring_choices(), true ); ?>
				<?php endif; ?>
				<?php if ( $s->get( 'enable_wilaya_filter' ) ) : ?>
					<?php echo $this->render_choice_multiselect( 'wilaya', __( 'Wilaya', 'tutor-course-search-pro' ), $cur_wilaya, $cur_wilaya_logic, $this->wilaya_filter_choices(), false, true ); ?>
				<?php endif; ?>
				<?php if ( $s->get( 'enable_municipality_filter' ) ) : ?>
					<?php echo $this->render_choice_multiselect( 'municipality', __( 'Municipality', 'tutor-course-search-pro' ), $cur_municipality, $cur_municipality_logic, $this->municipality_filter_choices(), true, true ); ?>
				<?php endif; ?>
				<?php if ( $s->get( 'enable_price_filter' ) ) : ?>
					<select name="tcsp_price" class="tcsp-select" aria-label="<?php esc_attr_e( 'Price', 'tutor-course-search-pro' ); ?>"><option value=""><?php esc_html_e( 'Any price', 'tutor-course-search-pro' ); ?></option><option value="free" <?php selected( $cur_price, 'free' ); ?>><?php esc_html_e( 'Free', 'tutor-course-search-pro' ); ?></option><option value="paid" <?php selected( $cur_price, 'paid' ); ?>><?php esc_html_e( 'Paid', 'tutor-course-search-pro' ); ?></option></select>
				<?php endif; ?>
				<?php if ( $s->get( 'enable_level_filter' ) ) : ?>
					<select name="tcsp_level" class="tcsp-select" aria-label="<?php esc_attr_e( 'Level', 'tutor-course-search-pro' ); ?>"><option value=""><?php esc_html_e( 'Any level', 'tutor-course-search-pro' ); ?></option><?php foreach ( $this->levels() as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $cur_level, $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select>
				<?php endif; ?>

				<button type="submit" class="tcsp-apply-btn"><?php esc_html_e( 'Apply', 'tutor-course-search-pro' ); ?></button>
				<?php if ( $has_active ) : ?>
					<a href="<?php echo esc_url( $this->reset_url() ); ?>" class="tcsp-reset-btn">
						<?php esc_html_e( 'Reset filters', 'tutor-course-search-pro' ); ?>
					</a>
				<?php endif; ?>
			</div>
		</form>
		<?php $this->render_sponsored_carousel(); ?>
		<div class="tcsp-normal-results-heading"><?php esc_html_e( 'Courses', 'tutor-course-search-pro' ); ?></div>
		<?php
	}

	private function render_choice_multiselect( $name, $label, $selected, $logic, $choices, $objects = false, $include_logic = true ) {
		$field = 'tcsp_' . $name;
		$logic_fields = array( 'language' => 'language_logic', 'tutoring_type' => 'tutoring_logic', 'wilaya' => 'wilaya_logic', 'municipality' => 'municipality_logic', 'course_type' => 'course_type_logic', 'tutoring_format' => 'tutoring_format_logic' );
		$logic_field = isset( $logic_fields[ $name ] ) ? 'tcsp_' . $logic_fields[ $name ] : 'tcsp_' . $name . '_logic';
		$html = '<div class="tcsp-multiselect tcsp-' . esc_attr( $name ) . '"><button type="button" class="tcsp-multiselect-toggle" aria-expanded="false">' . esc_html( $label );
		if ( $selected ) { $html .= '<span class="tcsp-multiselect-count">' . esc_html( count( $selected ) ) . '</span>'; }
		$html .= '</button><div class="tcsp-multiselect-panel" hidden>';
		if ( $include_logic ) {
			$html .= '<div class="tcsp-multiselect-logic"><label>' . esc_html__( 'Match', 'tutor-course-search-pro' ) . '</label><select name="' . esc_attr( $logic_field ) . '" class="tcsp-logic-select"><option value="and" ' . selected( $logic, 'and', false ) . '>' . esc_html__( 'ALL selected', 'tutor-course-search-pro' ) . '</option><option value="or" ' . selected( $logic, 'or', false ) . '>' . esc_html__( 'ANY selected', 'tutor-course-search-pro' ) . '</option></select></div>';
		}
		foreach ( $choices as $key => $value ) {
			// Some profile/extension filters may return richer choice arrays. Never
			// cast those arrays directly to string: PHP 8 emits "Array to string conversion".
			if ( is_array( $value ) ) {
				$choice_key = is_string( $key ) ? $key : ( isset( $value['value'] ) ? (string) $value['value'] : '' );
				$label_text = isset( $value['label'] ) ? (string) $value['label'] : ( isset( $value['name'] ) ? (string) $value['name'] : $choice_key );
			} else {
				$choice_key = is_string( $key ) ? $key : (string) $value;
				$label_text = (string) $value;
			}
			if ( '' === $choice_key ) {
				continue;
			}
			$wilaya_attr = $objects && is_array( $value ) && isset( $value['wilaya'] ) ? (string) $value['wilaya'] : '';
			$data_wilaya = '' !== $wilaya_attr ? ' data-wilaya="' . esc_attr( $wilaya_attr ) . '"' : '';
			$input_wilaya = '' !== $wilaya_attr ? ' data-wilaya="' . esc_attr( $wilaya_attr ) . '"' : '';
			$html .= '<label class="tcsp-multiselect-option"' . $data_wilaya . '><input type="checkbox" name="' . esc_attr( $field ) . '[]" value="' . esc_attr( $choice_key ) . '"' . $input_wilaya . ' ' . checked( in_array( $choice_key, (array) $selected, true ), true, false ) . '>' . esc_html( $label_text ) . '</label>';
		}
		$html .= '</div></div>';
		return $html;
	}

	private function render_multiselect( $name, $label, $selected, $logic, $terms, $taxonomy ) {
		$field = 'tcsp_' . $name; $logic_field = 'tcsp_' . $name . '_logic';
		$html = '<div class="tcsp-multiselect"><button type="button" class="tcsp-multiselect-toggle" aria-expanded="false">' . esc_html( $label ) . ( $selected ? '<span class="tcsp-multiselect-count">' . count( $selected ) . '</span>' : '' ) . '</button><div class="tcsp-multiselect-panel" hidden><div class="tcsp-multiselect-logic"><label>Match</label><select name="' . esc_attr( $logic_field ) . '" class="tcsp-logic-select"><option value="and" ' . selected( $logic, 'and', false ) . '>ALL selected</option><option value="or" ' . selected( $logic, 'or', false ) . '>ANY selected</option></select></div>';
		foreach ( $terms as $term ) { $html .= '<label class="tcsp-multiselect-option ' . ( $term->parent ? 'tcsp-multiselect-option--child' : '' ) . '" style="--tcsp-depth:' . esc_attr( $this->term_depth( $term ) ) . ';"><input type="checkbox" name="' . esc_attr( $field ) . '[]" value="' . esc_attr( $term->slug ) . '" ' . checked( in_array( $term->slug, $selected, true ), true, false ) . '>' . esc_html( $term->name ) . '<span class="tcsp-term-count">' . esc_html( number_format_i18n( $this->term_count( $taxonomy, $term ) ) ) . '</span></label>'; }
		return $html . '</div></div>';
	}


	private function wilaya_filter_choices() {
		$choices = array();
		foreach ( $this->wilaya_options() as $opt ) {
			$choices[ (string) $opt['value'] ] = $opt['label'];
		}
		return $choices;
	}

	private function municipality_filter_choices() {
		$choices = array();
		$map = $this->commune_options_map();

		if ( ! is_array( $map ) ) {
			return $choices;
		}

		// Tlms_Ipe_Locations has existed in a few revisions with slightly
		// different shapes for commune_options_map(). Normalize all supported
		// shapes here so the filter does not silently render an empty list.
		foreach ( $map as $wilaya_code => $options ) {
			// Also support a flat commune list where each row carries its own
			// Wilaya/code fields instead of being grouped by Wilaya.
			if ( is_array( $options ) && isset( $options['wilaya'] ) && ( isset( $options['value'] ) || isset( $options['code'] ) || isset( $options['id'] ) ) ) {
				$options = array( $options );
			}

			if ( ! is_array( $options ) ) {
				continue;
			}

			foreach ( $options as $opt_key => $opt ) {
				$value = '';
				$label = '';
				$option_wilaya = (string) $wilaya_code;

				if ( is_array( $opt ) ) {
					$value = isset( $opt['value'] ) ? (string) $opt['value'] : ( isset( $opt['code'] ) ? (string) $opt['code'] : ( isset( $opt['id'] ) ? (string) $opt['id'] : ( is_string( $opt_key ) ? $opt_key : '' ) ) );
					$label = isset( $opt['label'] ) ? (string) $opt['label'] : ( isset( $opt['name'] ) ? (string) $opt['name'] : ( isset( $opt['title'] ) ? (string) $opt['title'] : $value ) );
					if ( isset( $opt['wilaya'] ) ) {
						$option_wilaya = (string) $opt['wilaya'];
					} elseif ( isset( $opt['wilaya_code'] ) ) {
						$option_wilaya = (string) $opt['wilaya_code'];
					} elseif ( isset( $opt['parent'] ) ) {
						$option_wilaya = (string) $opt['parent'];
					}
				} else {
					$value = is_string( $opt_key ) ? $opt_key : (string) $opt;
					$label = (string) $opt;
				}

				if ( '' === $value || '' === $label ) {
					continue;
				}

				$choices[ $value ] = array( 'label' => $label, 'wilaya' => $option_wilaya );
			}
		}

		return $choices;
	}

	private function course_type_choices() {
		if ( function_exists( 'tcf_course_types' ) ) {
			return tcf_course_types();
		}
		return array(
			'prerecorded' => __( 'Prerecorded', 'tutor-course-search-pro' ),
			'direct' => __( 'Direct', 'tutor-course-search-pro' ),
			'hybrid' => __( 'Hybrid (Direct + Prerecorded)', 'tutor-course-search-pro' ),
		);
	}

	private function tutoring_format_choices() {
		if ( function_exists( 'tcf_course_formats' ) ) {
			return tcf_course_formats();
		}
		return array( 'group' => __( 'Group', 'tutor-course-search-pro' ), 'one_to_one' => __( '1v1', 'tutor-course-search-pro' ) );
	}

	/**
	 * "Reset filters" sends the visitor back to the current course
	 * archive / taxonomy / search page with all tcsp_* filters and
	 * search text stripped.
	 *
	 * @return string
	 */
	private function reset_url() {
		if ( is_tax( 'course-category' ) ) {
			$term = get_queried_object();
			return $term ? get_term_link( $term ) : get_post_type_archive_link( TCSP_Plugin::COURSE_CPT );
		}
		if ( is_tax( 'course-tag' ) ) {
			$term = get_queried_object();
			return $term ? get_term_link( $term ) : get_post_type_archive_link( TCSP_Plugin::COURSE_CPT );
		}
		if ( is_search() ) {
			return get_post_type_archive_link( TCSP_Plugin::COURSE_CPT );
		}
		$archive_link = get_post_type_archive_link( TCSP_Plugin::COURSE_CPT );
		return $archive_link ? $archive_link : home_url( '/' );
	}

	/**
	 * AJAX endpoint: re-render the sponsored carousel for the currently
	 * active filters/search, drawing a fresh weighted-random rotation. Used
	 * by the front-end script to update the carousel after Tutor paginates
	 * the course loop over AJAX, since that request never reloads the page
	 * (which is otherwise what re-rolls the rotation).
	 */
	public function ajax_refresh_carousel() {
		check_ajax_referer( 'tcsp_refresh_carousel', 'nonce' );

		ob_start();
		$this->render_sponsored_carousel();
		$html = ob_get_clean();

		wp_send_json_success( array( 'html' => $html ) );
	}

	private function render_sponsored_carousel() {
		// The carousel is intentionally independent of the normal course-page
		// number. It should remain visible on every pagination page as long as
		// at least one sponsored course still matches the active filters.
		$ids = $this->query->get_sponsored_carousel_ids();
		if ( empty( $ids ) ) { return; }
		$tiers = $this->settings->promotion_tiers();
		?>
		<section class="tcsp-sponsored-carousel" aria-label="<?php esc_attr_e( 'Sponsored courses', 'tutor-course-search-pro' ); ?>">
			<div class="tcsp-sponsored-header">
				<div><span class="tcsp-sponsored-kicker">★ <?php esc_html_e( 'Sponsored', 'tutor-course-search-pro' ); ?></span><h2><?php esc_html_e( 'Featured courses', 'tutor-course-search-pro' ); ?></h2></div>
				<div class="tcsp-carousel-controls" aria-label="<?php esc_attr_e( 'Sponsored course carousel controls', 'tutor-course-search-pro' ); ?>"><button type="button" class="tcsp-carousel-prev" aria-label="<?php esc_attr_e( 'Previous', 'tutor-course-search-pro' ); ?>">‹</button><button type="button" class="tcsp-carousel-next" aria-label="<?php esc_attr_e( 'Next', 'tutor-course-search-pro' ); ?>">›</button></div>
			</div>
			<div class="tcsp-sponsored-track" tabindex="0">
			<?php foreach ( $ids as $course_id ) :
				$tier = TCSP_Plugin::get_promotion_tier( $course_id );
				$tier_label = isset( $tiers[ $tier ]['label'] ) ? $tiers[ $tier ]['label'] : ucfirst( $tier );
				$thumb_id = get_post_thumbnail_id( $course_id );
				$thumb = $thumb_id ? wp_get_attachment_image( $thumb_id, 'large', false, array( 'class' => 'tcsp-sponsored-card-image', 'alt' => get_the_title( $course_id ), 'loading' => 'lazy', 'decoding' => 'async', 'sizes' => '(max-width: 600px) 82vw, (max-width: 900px) 45vw, 31.5vw' ) ) : '';
				$author_id = (int) get_post_field( 'post_author', $course_id );
				$author = get_the_author_meta( 'display_name', $author_id );
				$is_verified = class_exists( 'Tlms_Ipe_Plugin' ) ? Tlms_Ipe_Plugin::instance()->is_verified_instructor( $author_id ) : ( 'verified' === (string) get_user_meta( $author_id, '_tlms_ipe_verification_status', true ) );
				$rating = $this->carousel_rating( $course_id );
				$categories = get_the_terms( $course_id, 'course-category' );
				$category_names = array();
				if ( $categories && ! is_wp_error( $categories ) ) { foreach ( $categories as $cat ) { $category_names[] = $cat->name; } }
				$duration = '';
				if ( function_exists( 'tutor_utils' ) ) {
					try { $duration = (string) tutor_utils()->get_course_duration( $course_id, false ); } catch ( Throwable $e ) { $duration = ''; }
				}
				$is_wishlisted = false;
				if ( is_user_logged_in() && function_exists( 'tutor_utils' ) ) { $is_wishlisted = (bool) tutor_utils()->is_wishlisted( $course_id ); }
				$wishlist_class = ( $is_wishlisted ? 'has-wish-listed' : '' );
				$wishlist_state = $is_wishlisted ? __( 'Remove from wishlist', 'tutor-course-search-pro' ) : __( 'Add to wishlist', 'tutor-course-search-pro' );
				if ( ! is_user_logged_in() ) { $wishlist_class .= ' ' . apply_filters( 'tutor_popup_login_class', 'cart-required-login' ); }
			?>
				<article class="tcsp-sponsored-card tcsp-sponsored-card--<?php echo esc_attr( $tier ); ?>">
					<div class="tcsp-sponsored-card-media"><?php if ( $thumb ) : ?><?php echo $thumb; ?><?php else : ?><div class="tcsp-sponsored-card-placeholder" aria-hidden="true"></div><?php endif; ?><span class="tcsp-sponsored-card-badge">★ <?php esc_html_e( 'Sponsored', 'tutor-course-search-pro' ); ?> · <?php echo esc_html( $tier_label ); ?></span><?php $this->render_course_format_inline_sticker( $course_id ); ?><a href="#" class="<?php echo esc_attr( $wishlist_class ); ?> tcsp-sponsored-wishlist<?php echo $is_wishlisted ? ' is-active' : ''; ?>" data-course-id="<?php echo esc_attr( $course_id ); ?>" aria-label="<?php echo esc_attr( $wishlist_state ); ?>" aria-pressed="<?php echo $is_wishlisted ? 'true' : 'false'; ?>" title="<?php echo esc_attr( $wishlist_state ); ?>"><span class="<?php echo esc_attr( $is_wishlisted ? 'tutor-icon-bookmark-bold' : 'tutor-icon-bookmark-line' ); ?>" aria-hidden="true"></span></a></div>
					<div class="tcsp-sponsored-card-body"><h3><a href="<?php echo esc_url( get_permalink( $course_id ) ); ?>"><?php echo esc_html( get_the_title( $course_id ) ); ?></a></h3><?php if ( $author ) : ?><p class="tcsp-sponsored-card-author"><a href="<?php echo esc_url( $this->instructor_profile_url( $author_id ) ); ?>"><?php echo esc_html( $author ); ?></a><?php if ( $is_verified && $this->settings->get( 'show_verified_sticker' ) ) : ?> <span class="tcsp-verified-sticker">✓Verified Instructor</span><?php endif; ?></p><?php endif; ?>
						<?php if ( ! empty( $categories ) || $duration ) : ?><div class="tcsp-sponsored-card-details"><?php if ( ! empty( $categories ) && ! is_wp_error( $categories ) ) : ?><span class="tcsp-sponsored-detail"><span class="tutor-icon-folder" aria-hidden="true"></span><?php foreach ( array_slice( $categories, 0, 2 ) as $cat_i => $cat ) : ?><?php $cat_url = get_term_link( $cat, 'course-category' ); ?><?php if ( 0 < $cat_i ) : ?> · <?php endif; ?><?php if ( ! is_wp_error( $cat_url ) ) : ?><a href="<?php echo esc_url( $cat_url ); ?>"><?php echo esc_html( $cat->name ); ?></a><?php else : ?><?php echo esc_html( $cat->name ); ?><?php endif; ?><?php endforeach; ?></span><?php endif; ?><?php if ( $duration ) : ?><span class="tcsp-sponsored-detail"><span class="tutor-icon-clock-line" aria-hidden="true"></span><?php echo esc_html( $duration ); ?></span><?php endif; ?></div><?php endif; ?>
						<?php echo $this->course_price_markup( $course_id ); ?>
						<div class="tcsp-sponsored-card-meta"><?php if ( $rating > 0 ) : ?><span>★ <?php echo esc_html( number_format_i18n( $rating, 1 ) ); ?></span><?php endif; ?></div></div>
				</article>
			<?php endforeach; ?>
			</div>
		</section>
		<?php
	}

	private function render_course_format_inline_sticker( $course_id ) {
		$type = function_exists( 'tcf_get_course_type' ) ? tcf_get_course_type( $course_id ) : (string) get_post_meta( $course_id, '_tcf_course_type', true );
		if ( '' === $type ) { $type = 'prerecorded'; }
		$format = function_exists( 'tcf_get_course_format' ) ? tcf_get_course_format( $course_id ) : (string) get_post_meta( $course_id, '_tcf_course_format', true );
		$labels = array( 'prerecorded' => __( 'Prerecorded', 'tutor-course-search-pro' ), 'direct' => __( 'Direct Lessons', 'tutor-course-search-pro' ), 'hybrid' => __( 'Hybrid', 'tutor-course-search-pro' ) );
		$formats = array( 'group' => __( 'Group', 'tutor-course-search-pro' ), 'one_to_one' => __( '1v1', 'tutor-course-search-pro' ) );
		if ( ! isset( $labels[ $type ] ) ) { return; }
		?>
		<span class="tcsp-format-sticker tcsp-format-sticker--carousel tcsp-format-sticker--<?php echo esc_attr( $type ); ?>" aria-label="<?php echo esc_attr( $labels[ $type ] ); ?>">
			<span class="tcsp-format-type"><?php if ( 'direct' === $type ) : ?><span class="tcsp-format-dot" aria-hidden="true"></span><?php elseif ( 'hybrid' === $type ) : ?><span class="tcsp-format-hybrid-mark" aria-hidden="true">◐</span><?php else : ?><span class="tcsp-format-type-icon tutor-icon-video-camera" aria-hidden="true"></span><?php endif; ?><?php echo esc_html( $labels[ $type ] ); ?></span><?php if ( in_array( $type, array( 'direct', 'hybrid' ), true ) && isset( $formats[ $format ] ) ) : ?><span class="tcsp-format-separator" aria-hidden="true">·</span><span class="tcsp-format-delivery"><span class="<?php echo esc_attr( 'one_to_one' === $format ? 'tutor-icon-user-line' : 'tutor-icon-user-group' ); ?>" aria-hidden="true"></span><?php echo esc_html( $formats[ $format ] ); ?></span><?php endif; ?>
		</span>
		<?php
	}

	/**
	 * Build the Tutor LMS public instructor profile URL.
	 *
	 * Tutor's profile_url() uses /profile/{user_nicename}/?view=instructor
	 * for instructor cards; falling back to a site profile URL keeps the
	 * carousel usable on older Tutor versions.
	 *
	 * @param int $user_id Instructor user ID.
	 * @return string
	 */
	private function instructor_profile_url( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return home_url( '/' );
		}

		if ( function_exists( 'tutor_utils' ) ) {
			try {
				$url = tutor_utils()->profile_url( $user_id, true );
				if ( $url ) {
					return $url;
				}
			} catch ( Throwable $e ) {
				// Fall through to the compatibility URL below.
			}
		}

		$user = get_userdata( $user_id );
		if ( $user && ! empty( $user->user_nicename ) ) {
			return add_query_arg( 'view', 'instructor', trailingslashit( home_url( 'profile/' . $user->user_nicename ) ) );
		}

		return home_url( '/' );
	}

	private function carousel_rating( $course_id ) {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT AVG( CAST( cm.meta_value AS DECIMAL(10,2) ) ) FROM {$wpdb->comments} c INNER JOIN {$wpdb->commentmeta} cm ON cm.comment_id = c.comment_ID WHERE c.comment_post_ID = %d AND c.comment_type = %s AND cm.meta_key = %s", $course_id, TCSP_Plugin::RATING_COMMENT_TYPE, TCSP_Plugin::RATING_META_KEY ) );
		return $value ? (float) $value : 0.0;
	}

	private function course_price_markup( $course_id ) {
		$type = (string) get_post_meta( $course_id, TCSP_Plugin::PRICE_TYPE_META, true );
		if ( TCSP_Plugin::PRICE_TYPE_PAID !== $type ) { return '<div class="tcsp-sponsored-card-price"><strong>' . esc_html__( 'Free', 'tutor-course-search-pro' ) . '</strong></div>'; }
		$regular = (float) get_post_meta( $course_id, TCSP_Plugin::PRICE_META, true );
		$sale = (float) get_post_meta( $course_id, TCSP_Plugin::SALE_PRICE_META, true );
		$active_sale = $sale > 0 && $regular > 0 && $sale < $regular;
		$current = $active_sale ? $sale : $regular;
		$currency = (string) get_option( 'tutor_currency_symbol', '$' );
		if ( function_exists( 'tutor_utils' ) ) { $tu = tutor_utils(); if ( is_object( $tu ) && method_exists( $tu, 'currency_symbol' ) ) { $currency = (string) $tu->currency_symbol(); } }
		$fmt = function( $value ) use ( $currency ) { return esc_html( trim( $currency . number_format_i18n( $value, 2 ) ) ); };
		$html = '<div class="tcsp-sponsored-card-price"><strong>' . $fmt( $current ) . '</strong>';
		if ( $active_sale ) { $discount = round( ( 1 - ( $sale / $regular ) ) * 100 ); $html .= ' <del>' . $fmt( $regular ) . '</del> <span class="tcsp-discount">-' . esc_html( $discount ) . '%</span>'; }
		return $html . '</div>';
	}


	public function shortcode() {
		ob_start();
		$this->render_bar();
		return ob_get_clean();
	}

	private function levels() {
		return array(
			'beginner'     => __( 'Beginner', 'tutor-course-search-pro' ),
			'intermediate' => __( 'Intermediate', 'tutor-course-search-pro' ),
			'expert'       => __( 'Expert', 'tutor-course-search-pro' ),
			'all_levels'   => __( 'All levels', 'tutor-course-search-pro' ),
		);
	}

	private function categories() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'course-category',
				'hide_empty' => true,
				'number'     => 0,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);
		if ( is_wp_error( $terms ) ) {
			return array();
		}

		// Display parents before children while preserving alphabetical order
		// within each branch. Tutor's category taxonomy is hierarchical, but
		// get_terms() returns a flat list by default.
		$by_parent = array();
		foreach ( $terms as $term ) {
			$parent = (int) $term->parent;
			if ( ! isset( $by_parent[ $parent ] ) ) {
				$by_parent[ $parent ] = array();
			}
			$by_parent[ $parent ][] = $term;
		}
		$ordered = array();
		$walk    = function ( $parent ) use ( &$walk, &$ordered, $by_parent ) {
			if ( empty( $by_parent[ $parent ] ) ) {
				return;
			}
			foreach ( $by_parent[ $parent ] as $term ) {
				$ordered[] = $term;
				$walk( (int) $term->term_id );
			}
		};
		$walk( 0 );
		return count( $ordered ) === count( $terms ) ? $ordered : $terms;
	}

	private function tags() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'course-tag',
				'hide_empty' => true,
				'number'     => 0,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);
		return is_wp_error( $terms ) ? array() : $terms;
	}

	/**
	 * The count to display next to a category/tag checkbox: a filter-aware
	 * "what if I also selected this" count when enabled, falling back to the
	 * term's plain site-wide post count otherwise (either by admin choice, or
	 * if no active TCSP_Query has run yet to base a live count on).
	 *
	 * @param string  $taxonomy 'course-category' or 'course-tag'.
	 * @param WP_Term $term
	 * @return int
	 */
	private function term_count( $taxonomy, $term ) {
		if ( ! $this->settings->get( 'enable_live_term_counts' ) ) {
			return (int) $term->count;
		}
		return $this->query->what_if_term_count( $taxonomy, $term->slug );
	}

	/**
	 * Calculate a category's nesting depth for a readable filter tree.
	 */
	private function term_depth( $term ) {
		$depth  = 0;
		$parent = (int) $term->parent;
		while ( $parent && $depth < 20 ) {
			$ancestor = get_term( $parent, 'course-category' );
			if ( ! $ancestor || is_wp_error( $ancestor ) ) {
				break;
			}
			$depth++;
			$parent = (int) $ancestor->parent;
		}
		return $depth;
	}
}
