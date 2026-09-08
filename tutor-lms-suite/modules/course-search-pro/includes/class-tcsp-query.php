<?php
/**
 * Query engine: applies archive filters, sorting, and marketplace-style
 * ranking (promoted courses first, then paid courses) to Tutor course queries.
 *
 * @package Tutor_Course_Search_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TCSP_Query {

	/** @var TCSP_Settings */
	private $settings;

	const ACTIVE_MARKER_QUERY_VAR = '_tcsp_active_query';

	/** @var bool Marks the query we are shaping so posts_clauses only touches it. */
	private $active = false;

	/** @var int Minimum average rating requested (0 = off) for the active query. */
	private $min_rating = 0;

	/** @var int Minimum enrolled-student count requested (0 = off) for the active query. */
	private $min_students = 0;

	/** @var string The tcsp_sort value driving the active query. */
	private $current_sort_value = 'relevance';

	/** @var int|null Result count of the most recent target query, for display in the filter bar. */
	private $last_found_posts = null;

	/** @var string Search term of the most recent target query. */
	private $last_search_term = '';

	/** @var array<string,int> Per-request memo of what_if_term_count() results, keyed by cache key. */
	private $term_count_memo = array();

	const PROFILE_WILAYA_META = '_tlms_ipe_wilaya';
	const PROFILE_COMMUNE_META = '_tlms_ipe_commune';
	const PROFILE_LANGUAGES_META = '_tlms_ipe_spoken_languages';
	const PROFILE_TUTORING_META = '_tlms_ipe_tutoring_types';
	const PROFILE_VERIFICATION_META = '_tlms_ipe_verification_status';

	public function __construct( TCSP_Settings $settings ) {
		$this->settings = $settings;
	}

	public function register() {
		// Very late priorities on purpose: TranslatePress registers its own
		// pre_get_posts / posts_clauses handling around requests that carry
		// its "trp-form-language" marker (added automatically to every GET
		// form on a translated page) to re-run/adjust the query for search
		// in that language. When that runs after us it can reset the
		// ordering (and even the result set) our filters just built. Running
		// as close to last as possible means our filtering/sorting is the
		// final word regardless of what other plugins do earlier.
		add_action( 'pre_get_posts', array( $this, 'apply_query' ), 9999 );
		add_filter( 'posts_clauses', array( $this, 'apply_clauses' ), 9999, 2 );

		// Belt-and-braces: SQL-level ORDER BY can still be undone downstream
		// of the query entirely — e.g. by a plugin's 'the_posts' filter that
		// re-sorts or re-scores the already-fetched array of post objects in
		// PHP (this is what TranslatePress's translated-search handling was
		// found to do here, keyed off that same 'trp-form-language' marker,
		// with no SQL-priority fix able to prevent it since it runs in a
		// completely separate step after the SQL has already executed).
		// Re-applying the exact sort the visitor picked, on the final array,
		// as the very last thing that touches it, means nothing later in the
		// request can silently undo it.
		add_filter( 'the_posts', array( $this, 'enforce_final_order' ), PHP_INT_MAX, 2 );
	}

	/**
	 * Re-apply the visitor's chosen sort directly on the final array of
	 * fetched posts, in PHP, so nothing running after WP_Query's SQL step
	 * (e.g. another plugin's 'the_posts' handling) can silently undo it.
	 * Only runs for the explicit, unambiguous sort choices — "Relevance"
	 * (the default) keeps whatever order the SQL/promotion scoring produced,
	 * since re-deriving that score here would just duplicate apply_clauses().
	 *
	 * @param WP_Post[] $posts
	 * @param WP_Query  $query
	 * @return WP_Post[]
	 */
	public function enforce_final_order( $posts, $query ) {
		if ( ! is_array( $posts ) || ! $this->is_target_query( $query ) ) {
			return $posts;
		}

		// Captured regardless of sort/emptiness so the filter bar can show an
		// accurate "N results" even for a zero-result search.
		$this->last_found_posts = (int) $query->found_posts;
		$this->last_search_term = (string) $query->get( 'tcsp_search_term' );

		if ( empty( $posts ) ) {
			return $posts;
		}

		$sort = $this->current_sort();
		if ( ! in_array( $sort, array( 'newest_first', 'oldest_first', 'course_title_az', 'course_title_za', 'price_low_high', 'price_high_low', 'top_rated', 'most_students' ), true ) ) {
			return $posts; // 'relevance': leave the SQL/promotion-scored order alone.
		}

		$ids = wp_list_pluck( $posts, 'ID' );
		if ( empty( $ids ) ) {
			return $posts;
		}

		$metric = null;
		$dir    = 'DESC';
		switch ( $sort ) {
			case 'newest_first':
				$metric = 'date';
				$dir    = 'DESC';
				break;
			case 'oldest_first':
				$metric = 'date';
				$dir    = 'ASC';
				break;
			case 'course_title_az':
				$metric = 'title';
				$dir    = 'ASC';
				break;
			case 'course_title_za':
				$metric = 'title';
				$dir    = 'DESC';
				break;
			case 'price_low_high':
				$metric = 'price';
				$dir    = 'ASC';
				break;
			case 'price_high_low':
				$metric = 'price';
				$dir    = 'DESC';
				break;
			case 'top_rated':
				$metric = 'rating';
				$dir    = 'DESC';
				break;
			case 'most_students':
				$metric = 'students';
				$dir    = 'DESC';
				break;
		}

		$values = $this->fetch_sort_values( $ids, $metric );

		usort(
			$posts,
			function ( $a, $b ) use ( $values, $dir ) {
				$va = isset( $values[ $a->ID ] ) ? $values[ $a->ID ] : 0;
				$vb = isset( $values[ $b->ID ] ) ? $values[ $b->ID ] : 0;

				if ( $va === $vb ) {
					// Same tiebreaker as the SQL path: newest ID first.
					return $b->ID <=> $a->ID;
				}

				$cmp = is_numeric( $va ) && is_numeric( $vb ) ? ( $va <=> $vb ) : strnatcasecmp( (string) $va, (string) $vb );
				return 'DESC' === $dir ? -$cmp : $cmp;
			}
		);

		return $posts;
	}

	/**
	 * Batch-fetch the raw sort value (price/rating/student-count/date/title)
	 * for a set of course IDs in as few queries as possible.
	 *
	 * @param int[]  $ids
	 * @param string $metric One of: date, title, price, rating, students.
	 * @return array<int, mixed> post ID => value
	 */
	private function fetch_sort_values( $ids, $metric ) {
		global $wpdb;

		$ids     = array_map( 'absint', $ids );
		$ids_csv = implode( ',', $ids );
		$values  = array();

		if ( 'date' === $metric || 'title' === $metric ) {
			$field = 'date' === $metric ? 'post_date' : 'post_title';
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $ids_csv is absint-filtered above.
			$rows = $wpdb->get_results( "SELECT ID, {$field} AS val FROM {$wpdb->posts} WHERE ID IN ({$ids_csv})" );
			foreach ( (array) $rows as $row ) {
				$values[ (int) $row->ID ] = $row->val;
			}
			return $values;
		}

		if ( 'price' === $metric ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT p.ID,
						CASE
							WHEN pt.meta_value = %s OR pt.meta_value IS NULL OR pt.meta_value = '' THEN 0
							ELSE COALESCE( NULLIF( CAST( sp.meta_value AS DECIMAL(10,2) ), 0 ), NULLIF( CAST( pr.meta_value AS DECIMAL(10,2) ), 0 ), 0 )
						END AS val
					 FROM {$wpdb->posts} p
					 LEFT JOIN {$wpdb->postmeta} pr ON pr.post_id = p.ID AND pr.meta_key = %s
					 LEFT JOIN {$wpdb->postmeta} sp ON sp.post_id = p.ID AND sp.meta_key = %s
					 LEFT JOIN {$wpdb->postmeta} pt ON pt.post_id = p.ID AND pt.meta_key = %s
					 WHERE p.ID IN ({$ids_csv})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $ids_csv is absint-filtered above.
					TCSP_Plugin::PRICE_TYPE_FREE,
					TCSP_Plugin::PRICE_META,
					TCSP_Plugin::SALE_PRICE_META,
					TCSP_Plugin::PRICE_TYPE_META
				)
			);
			foreach ( (array) $rows as $row ) {
				$values[ (int) $row->ID ] = (float) $row->val;
			}
			return $values;
		}

		if ( 'rating' === $metric ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT c.comment_post_ID AS ID, AVG( CAST( cm.meta_value AS DECIMAL(10,2) ) ) AS val
					 FROM {$wpdb->comments} c
					 INNER JOIN {$wpdb->commentmeta} cm ON cm.comment_id = c.comment_ID
					 WHERE c.comment_type = %s AND cm.meta_key = %s AND c.comment_post_ID IN ({$ids_csv})
					 GROUP BY c.comment_post_ID", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $ids_csv is absint-filtered above.
					TCSP_Plugin::RATING_COMMENT_TYPE,
					TCSP_Plugin::RATING_META_KEY
				)
			);
			foreach ( (array) $rows as $row ) {
				$values[ (int) $row->ID ] = (float) $row->val;
			}
			return $values;
		}

		if ( 'students' === $metric ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT post_parent AS ID, COUNT(*) AS val
					 FROM {$wpdb->posts}
					 WHERE post_type = %s AND post_status = %s AND post_parent IN ({$ids_csv})
					 GROUP BY post_parent", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $ids_csv is absint-filtered above.
					TCSP_Plugin::ENROLLMENT_CPT,
					TCSP_Plugin::ENROLLMENT_STATUS
				)
			);
			foreach ( (array) $rows as $row ) {
				$values[ (int) $row->ID ] = (int) $row->val;
			}
			return $values;
		}

		return $values;
	}

	/**
	 * Whether this query targets the Tutor course archive / search.
	 */
	private function is_target_query( $query ) {
		if ( $query instanceof WP_Query && $query->get( 'tcsp_skip' ) ) {
			return false;
		}

		// WordPress reports is_admin() = true during admin-ajax.php requests.
		// Tutor LMS uses AJAX for course pagination/filtering, so an unconditional
		// is_admin() guard makes page 2+ silently bypass TCSP. Only reject actual
		// wp-admin screens; allow frontend AJAX requests through.
		if ( ( is_admin() && ! wp_doing_ajax() ) || ! $query instanceof WP_Query ) {
			return false;
		}

		// Tutor LMS can rebuild the course loop in an AJAX request when the
		// visitor moves between archive pages. Those WP_Query objects are NOT
		// the main query, so the old main-query-only guard made page 1 work but
		// silently dropped our filters/sort on page 2+.
		//
		// Keep the normal frontend behavior limited to the main query, but also
		// allow frontend AJAX course queries when our filter state is present.
		if ( ! $query->is_main_query() ) {
			// Tutor can build the course-loop WP_Query as a secondary query on
			// the normal archive request as well as inside AJAX. If the current
			// request carries TCSP state, this is a query we must shape too.
			if ( ! $this->request_has_tcsp_state() ) {
				return false;
			}
		}
		$post_type = $query->get( 'post_type' );
		$is_course = ( TCSP_Plugin::COURSE_CPT === $post_type )
			|| ( is_array( $post_type ) && in_array( TCSP_Plugin::COURSE_CPT, $post_type, true ) );

		$is_course_archive = $query->is_post_type_archive( TCSP_Plugin::COURSE_CPT )
			|| $query->is_tax( 'course-category' )
			|| $query->is_tax( 'course-tag' );

		$is_course_search = $query->is_search() && $is_course;

		return $is_course || $is_course_archive || $is_course_search;
	}

	/**
	 * Check whether the current request carries this plugin's archive state.
	 *
	 * Tutor's AJAX pagination creates a fresh WP_Query instead of reusing the
	 * archive main query. We only opt that secondary query into TCSP when the
	 * request actually contains our filter/sort/search state, preventing
	 * unrelated AJAX queries from being modified.
	 */
	private function request_has_tcsp_state() {
		$sources = array( $_GET, $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		foreach ( $sources as $source ) {
			foreach ( array_keys( $source ) as $key ) {
				$key = (string) $key;
				// A secondary Tutor course-loop query must inherit TCSP state not
				// only when a tcsp_* filter exists, but also for a plain course
				// search / Tutor sort request. Otherwise page 2+ can rebuild the
				// query without our search constraints and show unrelated courses.
				if ( 0 === strpos( $key, 'tcsp_' ) || in_array( $key, array( 's', 'tutor_course_filter' ), true ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Read the current sort value from the request (falls back to default).
	 */
	public function current_sort() {
		if ( isset( $_GET['tcsp_sort'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return sanitize_key( wp_unslash( $_GET['tcsp_sort'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( isset( $_GET['tutor_course_filter'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return sanitize_key( wp_unslash( $_GET['tutor_course_filter'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		return (string) $this->settings->get( 'default_sort' );
	}

	/**
	 * Apply filters + base sorting to the main query.
	 */
	public function apply_query( $query ) {
		if ( ! $this->is_target_query( $query ) ) {
			return;
		}

		$query->set( self::ACTIVE_MARKER_QUERY_VAR, 1 );

		// Tutor's course shortcode/archive renderer can use current_page instead
		// of WordPress' paged query var. Mirror it when present so the filtered
		// query and Tutor's pagination point at the same result page.
		if ( isset( $_GET['current_page'] ) && ! is_array( $_GET['current_page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$current_page = max( 1, absint( $_GET['current_page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $current_page > 1 ) {
				$query->set( 'paged', $current_page );
			}
		}

		$this->active       = true;
		$this->min_rating   = 0;
		$this->min_students = 0;
		$this->current_sort_value = $this->current_sort();

		// NOTE: $query->get() returns '' (a scalar) for unset query vars, and
		// (array) '' produces array( 0 => '' ) rather than an empty array —
		// that stray scalar entry is what was corrupting tax_query/meta_query.
		// Always normalize through is_array() instead of blindly (array)-casting.
		$meta_query = $query->get( 'meta_query' );
		$meta_query = is_array( $meta_query ) ? $meta_query : array();
		$tax_query  = $query->get( 'tax_query' );
		$tax_query  = is_array( $tax_query ) ? $tax_query : array();

		// --- Price filter (free / paid) ---
		// Handled via LEFT JOIN in apply_clauses() so courses that never had
		// their price_type meta row inserted are treated as free (Tutor default)
		// instead of being silently dropped by WP's INNER JOIN meta_query.
		if ( $this->settings->get( 'enable_price_filter' ) && ! empty( $_GET['tcsp_price'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$price = sanitize_key( wp_unslash( $_GET['tcsp_price'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( in_array( $price, array( TCSP_Plugin::PRICE_TYPE_FREE, TCSP_Plugin::PRICE_TYPE_PAID ), true ) ) {
				$query->set( 'tcsp_filter_price', $price );
			}
		}

		// --- Difficulty level filter ---
		// Same LEFT JOIN treatment: explicit level selection still uses an
		// existence match (NULL rows are excluded), but we build the JOIN
		// ourselves so we can add an EXISTS-friendly WHERE and debug it.
		if ( $this->settings->get( 'enable_level_filter' ) && ! empty( $_GET['tcsp_level'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$level = sanitize_key( wp_unslash( $_GET['tcsp_level'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( '' !== $level ) {
				$query->set( 'tcsp_filter_level', $level );
			}
		}

		// --- Minimum rating filter (handled in SQL via avg-rating subquery) ---
		if ( $this->settings->get( 'enable_rating_filter' ) && ! empty( $_GET['tcsp_min_rating'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->min_rating = min( 5, max( 1, (int) $_GET['tcsp_min_rating'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		// --- Category filter --------------------------------------------------
		// A selected parent category matches its entire descendant tree. For
		// multiple selections the visitor can choose ALL (AND) or ANY (OR).
		if ( $this->settings->get( 'enable_category_filter' ) && ! empty( $_GET['tcsp_category'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$cats  = self::sanitize_slug_list( wp_unslash( $_GET['tcsp_category'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$logic = $this->request_logic( 'tcsp_category_logic' );
			if ( ! empty( $cats ) ) {
				$expanded_ids = self::expand_term_ids( $cats, 'course-category' );
				if ( ! empty( $expanded_ids ) ) {
					if ( 'or' === $logic ) {
						$tax_query[] = array(
							'taxonomy'         => 'course-category',
							'field'            => 'term_id',
							'terms'            => $expanded_ids,
							'operator'         => 'IN',
							'include_children' => false,
						);
					} else {
						// Build one IN clause per selected category. This gives true
						// AND semantics while each selected branch includes all of
						// its descendants.
						foreach ( $cats as $cat_slug ) {
							$branch_ids = self::expand_term_ids( array( $cat_slug ), 'course-category' );
							if ( ! empty( $branch_ids ) ) {
								$tax_query[] = array(
									'taxonomy'         => 'course-category',
									'field'            => 'term_id',
									'terms'            => $branch_ids,
									'operator'         => 'IN',
									'include_children' => false,
								);
							}
						}
					}
				}
			}
		}

		// --- Tag filter -------------------------------------------------------
		// Tags are flat, so ALL means every selected tag must be present and
		// ANY means at least one selected tag must be present.
		if ( $this->settings->get( 'enable_tag_filter' ) && ! empty( $_GET['tcsp_tag'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$tags  = self::sanitize_slug_list( wp_unslash( $_GET['tcsp_tag'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$logic = $this->request_logic( 'tcsp_tag_logic' );
			if ( ! empty( $tags ) ) {
				$term_ids = self::expand_term_ids( $tags, 'course-tag' );
				if ( ! empty( $term_ids ) ) {
					if ( 'or' === $logic ) {
						$tax_query[] = array(
							'taxonomy'         => 'course-tag',
							'field'            => 'term_id',
							'terms'            => $term_ids,
							'operator'         => 'IN',
							'include_children' => false,
						);
					} else {
						foreach ( $tags as $tag_slug ) {
							$single_ids = self::expand_term_ids( array( $tag_slug ), 'course-tag' );
							if ( ! empty( $single_ids ) ) {
								$tax_query[] = array(
									'taxonomy'         => 'course-tag',
									'field'            => 'term_id',
									'terms'            => $single_ids,
									'operator'         => 'IN',
									'include_children' => false,
								);
							}
						}
					}
				}
			}
		}

		// --- Instructor profile filters --------------------------------------
		if ( $this->settings->get( 'enable_verified_filter' ) && ! empty( $_GET['tcsp_verified'] ) && ! is_array( $_GET['tcsp_verified'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$verified = sanitize_key( wp_unslash( $_GET['tcsp_verified'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( 'verified' === $verified ) { $query->set( 'tcsp_filter_verified', 'verified' ); }
		}
		if ( $this->settings->get( 'enable_spoken_language_filter' ) && ! empty( $_GET['tcsp_language'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$langs = self::sanitize_key_list( wp_unslash( $_GET['tcsp_language'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $langs ) { $query->set( 'tcsp_filter_languages', $langs ); $query->set( 'tcsp_language_logic', $this->request_logic( 'tcsp_language_logic' ) ); }
		}
		if ( $this->settings->get( 'enable_tutoring_type_filter' ) && ! empty( $_GET['tcsp_tutoring_type'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$types = self::sanitize_key_list( wp_unslash( $_GET['tcsp_tutoring_type'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $types ) { $query->set( 'tcsp_filter_tutoring_types', $types ); $query->set( 'tcsp_tutoring_logic', $this->request_logic( 'tcsp_tutoring_logic' ) ); }
		}
		if ( $this->settings->get( 'enable_wilaya_filter' ) && ! empty( $_GET['tcsp_wilaya'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$wilayas = self::sanitize_key_list( wp_unslash( $_GET['tcsp_wilaya'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $wilayas ) { $query->set( 'tcsp_filter_wilaya', $wilayas ); $query->set( 'tcsp_wilaya_logic', $this->request_logic( 'tcsp_wilaya_logic' ) ); }
		}
		if ( $this->settings->get( 'enable_municipality_filter' ) && ! empty( $_GET['tcsp_municipality'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$municipalities = self::sanitize_key_list( wp_unslash( $_GET['tcsp_municipality'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $municipalities ) { $query->set( 'tcsp_filter_municipality', $municipalities ); $query->set( 'tcsp_municipality_logic', $this->request_logic( 'tcsp_municipality_logic' ) ); }
		}

		// Municipality hard restriction: Instructor Profile Extra stores location
		// selections as user meta arrays. Build the matching course-id set here
		// before WordPress executes the course query, so the municipality filter
		// remains effective even when another plugin/theme alters the SQL clauses.
		if ( $this->settings->get( 'enable_municipality_filter' ) && $query->get( 'tcsp_filter_municipality' ) ) {
			$municipality_values = (array) $query->get( 'tcsp_filter_municipality' );
			$municipality_logic  = $this->request_logic( 'tcsp_municipality_logic' );
			$matching_courses    = $this->get_courses_for_profile_location( self::PROFILE_COMMUNE_META, $municipality_values, $municipality_logic );
			$existing_post_in   = $query->get( 'post__in' );
			$existing_post_in   = is_array( $existing_post_in ) ? array_values( array_filter( array_map( 'absint', $existing_post_in ) ) ) : array();
			if ( $existing_post_in ) {
				$matching_courses = array_values( array_intersect( $existing_post_in, $matching_courses ) );
			}
			$query->set( 'post__in', $matching_courses ? $matching_courses : array( 0 ) );
		}
		if ( ! empty( $_GET['tcsp_course_type'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$course_types = self::sanitize_key_list( wp_unslash( $_GET['tcsp_course_type'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $course_types ) { $query->set( 'tcsp_filter_course_types', $course_types ); }
		}
		if ( ! empty( $_GET['tcsp_tutoring_format'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$formats = self::sanitize_key_list( wp_unslash( $_GET['tcsp_tutoring_format'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $formats ) { $query->set( 'tcsp_filter_tutoring_formats', $formats ); }
		}

		// --- Minimum student (enrollment) count filter ---
		if ( $this->settings->get( 'enable_students_filter' ) && ! empty( $_GET['tcsp_min_students'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->min_students = max( 0, (int) $_GET['tcsp_min_students'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		// --- Free-text search: title, description, category, tag, tutor ---
		// IMPORTANT: this branch intentionally does NOT touch tax_query. Adding
		// a tax_query clause here means core's WP_Tax_Query decides its own JOIN
		// aliases (which shift depending on how many other tax_query clauses —
		// e.g. the category/tag filters above — are already present), and any
		// hand-written SQL in apply_clauses() trying to strip/rewrite that JOIN
		// by regex breaks the moment the alias isn't what it expects (this is
		// what caused "search bar cat/tag returns nothing"). Instead we handle
		// the whole "text OR matching term" match ourselves in apply_clauses()
		// with our own uniquely-aliased JOINs, fully independent of tax_query.
		$raw_search = $query->get( 's' );
		$search     = is_array( $raw_search ) ? '' : trim( wp_strip_all_tags( (string) $raw_search ) );
		if ( '' !== $search ) {
			$term_ids = self::term_ids_matching( $search );

			// Cross-language search: also match courses/terms whose translated
			// (not default-language) text contains the term, so one search box
			// finds a course regardless of which language it matches in.
			if ( $this->settings->get( 'enable_multilingual_search' ) ) {
				$trp = TCSP_Multilingual::cross_language_matches( $search );
				if ( ! empty( $trp['term_ids'] ) ) {
					$term_ids = array_values( array_unique( array_merge( $term_ids, $trp['term_ids'] ) ) );
				}
				if ( ! empty( $trp['post_ids'] ) ) {
					$query->set( 'tcsp_trp_post_ids', $trp['post_ids'] );
				}
			}

			$query->set( 'tcsp_search_term', $search );
			$query->set( 'tcsp_term_ids', $term_ids );
			// Core's search WHERE only covers post fields. Clear the SQL
			// search var and build one complete OR index in apply_clauses().
			$query->set( 's', '' );
		}

		if ( count( $meta_query ) ) {
			$query->set( 'meta_query', $meta_query );
		}
		if ( count( $tax_query ) ) {
			$query->set( 'tax_query', $tax_query );
		}

		// Sponsored courses shown in the separate carousel are NOT excluded from
		// the normal results. They remain eligible for the organic result list and
		// consume normal pagination slots exactly like any other course. When the
		// carousel is enabled, sponsorship contributes zero boost to the normal
		// ranking; the normal list therefore stays purely organic/relevance-based.

		$this->apply_sort( $query, $this->current_sort() );
	}

	/**
	 * Normalize a $_GET value that may be a single slug or an array of slugs
	 * (multi-select checkboxes use tcsp_category[]/tcsp_tag[]) into a clean
	 * array of sanitized, unique slugs.
	 *
	 * @param mixed $raw Raw (already wp_unslash()'d) request value.
	 * @return string[]
	 */
	/**
	 * Read a requested AND/OR mode. Defaults to AND for backwards compatibility.
	 */
	private function request_logic( $key ) {
		$value = '';
		if ( isset( $_GET[ $key ] ) && ! is_array( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$value = strtolower( sanitize_key( wp_unslash( $_GET[ $key ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} elseif ( isset( $_POST[ $key ] ) && ! is_array( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$value = strtolower( sanitize_key( wp_unslash( $_POST[ $key ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		return in_array( $value, array( 'and', 'or' ), true ) ? $value : 'or';
	}

	/**
	 * Expand selected term slugs to their own term IDs plus every descendant.
	 *
	 * This is especially important for Tutor's hierarchical course-category
	 * taxonomy: a course assigned only to a child should still match its parent.
	 *
	 * @param string[] $slugs Selected term slugs.
	 * @param string   $taxonomy Taxonomy name.
	 * @return int[]
	 */
	public static function expand_term_ids( $slugs, $taxonomy ) {
		$ids = array();
		foreach ( self::sanitize_slug_list( $slugs ) as $slug ) {
			$term = get_term_by( 'slug', $slug, $taxonomy );
			if ( ! $term || is_wp_error( $term ) ) {
				continue;
			}
			$ids[] = (int) $term->term_id;
			$children = get_term_children( (int) $term->term_id, $taxonomy );
			if ( ! is_wp_error( $children ) ) {
				$ids = array_merge( $ids, array_map( 'intval', $children ) );
			}
		}
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		return $ids;
	}

	public static function sanitize_slug_list( $raw ) {
		$values = is_array( $raw ) ? $raw : array( $raw );
		$clean  = array();
		foreach ( $values as $value ) {
			if ( is_array( $value ) ) {
				continue; // Ignore unexpected nested arrays.
			}
			$slug = sanitize_title( (string) $value );
			if ( '' !== $slug ) {
				$clean[] = $slug;
			}
		}
		return array_values( array_unique( $clean ) );
	}

	public static function sanitize_key_list( $raw ) {
		$values = is_array( $raw ) ? $raw : array( $raw );
		$out = array();
		foreach ( $values as $value ) {
			if ( is_array( $value ) ) { continue; }
			$key = sanitize_key( (string) $value );
			if ( '' !== $key ) { $out[] = $key; }
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Normalize a user profile meta value into a clean list of scalar keys.
	 * Instructor Profile Extra stores spoken languages and tutoring types as
	 * WordPress array meta, but older/manual saves may leave a serialized array
	 * or a comma-separated string. Handle all three safely.
	 *
	 * @param mixed $raw Raw user-meta value.
	 * @return string[]
	 */
	private function normalize_profile_list_meta( $raw ) {
		if ( is_string( $raw ) && '' !== trim( $raw ) ) {
			$maybe = maybe_unserialize( $raw );
			if ( $maybe !== $raw ) {
				$raw = $maybe;
			} else {
				$raw = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
			}
		}

		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$out = array();
		foreach ( $raw as $value ) {
			if ( is_array( $value ) || is_object( $value ) ) {
				continue;
			}
			$value = sanitize_key( (string) $value );
			if ( '' !== $value ) {
				$out[] = $value;
			}
		}

		return array_values( array_unique( $out ) );
	}


	/**
	 * Return course IDs whose instructor profile location meta contains the
	 * requested value(s). Instructor Profile Extra saves these locations as
	 * normal WordPress array user meta, but older saves may be serialized or
	 * JSON. Normalize the stored value in PHP so the filter is deterministic.
	 *
	 * @param string   $meta_key Location user-meta key.
	 * @param string[] $values Requested location IDs/codes.
	 * @param string   $logic 'and' or 'or'.
	 * @return int[]
	 */
	private function get_courses_for_profile_location( $meta_key, $values, $logic = 'or' ) {
		global $wpdb;

		$values = self::sanitize_key_list( $values );
		if ( empty( $values ) ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s",
				$meta_key
			)
		);

		$matching_users = array();
		foreach ( (array) $rows as $row ) {
			$saved = $this->normalize_profile_list_meta( $row->meta_value );
			$hits  = array_values( array_intersect( $values, $saved ) );
			$match = 'and' === $logic
				? count( $hits ) === count( $values )
				: ! empty( $hits );
			if ( $match ) {
				$matching_users[] = (int) $row->user_id;
			}
		}

		$matching_users = array_values( array_unique( array_filter( $matching_users ) ) );
		if ( empty( $matching_users ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $matching_users ), '%d' ) );
		$course_ids   = array();

		// Primary course authors.
		$sql = $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND post_author IN ({$placeholders})",
			array_merge( array( TCSP_Plugin::COURSE_CPT ), $matching_users )
		);
		$course_ids = array_merge( $course_ids, array_map( 'absint', (array) $wpdb->get_col( $sql ) ) );

		// Tutor Multi Instructor ownership: Instructor Profile Extra location
		// belongs to the instructor user, while the course can be authored by
		// someone else. _tutor_instructor_course_id links that instructor to the
		// course ID, so include those courses as well.
		$course_meta_key = '_tutor_instructor_course_id';
		$sql = $wpdb->prepare(
			"SELECT DISTINCT CAST(meta_value AS UNSIGNED) FROM {$wpdb->usermeta} WHERE meta_key = %s AND user_id IN ({$placeholders})",
			array_merge( array( $course_meta_key ), $matching_users )
		);
		$linked_ids = array_map( 'absint', (array) $wpdb->get_col( $sql ) );
		if ( $linked_ids ) {
			$linked_placeholders = implode( ',', array_fill( 0, count( $linked_ids ), '%d' ) );
			$sql = $wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND ID IN ({$linked_placeholders})",
				array_merge( array( TCSP_Plugin::COURSE_CPT ), $linked_ids )
			);
			$course_ids = array_merge( $course_ids, array_map( 'absint', (array) $wpdb->get_col( $sql ) ) );
		}

		return array_values( array_unique( array_filter( $course_ids ) ) );
	}

	/**
	 * Translate a sort key into WP_Query orderby args.
	 */
	private function apply_sort( $query, $sort ) {
		switch ( $sort ) {
			case 'newest_first':
				$query->set( 'orderby', 'date' );
				$query->set( 'order', 'DESC' );
				break;
			case 'oldest_first':
				$query->set( 'orderby', 'date' );
				$query->set( 'order', 'ASC' );
				break;
			case 'course_title_az':
				$query->set( 'orderby', 'title' );
				$query->set( 'order', 'ASC' );
				break;
			case 'course_title_za':
				$query->set( 'orderby', 'title' );
				$query->set( 'order', 'DESC' );
				break;
			case 'price_low_high':
				// Deliberately NOT using orderby=meta_value_num + meta_key here:
				// WP builds that as an INNER JOIN on wp_postmeta for that key, so
				// any course with no price row at all (typically free courses)
				// gets silently dropped from the results. We sort via a LEFT JOIN
				// + COALESCE in apply_clauses() instead, so missing rows count as 0.
				$query->set( 'tcsp_order_by_price', 'ASC' );
				break;
			case 'price_high_low':
				$query->set( 'tcsp_order_by_price', 'DESC' );
				break;
			case 'top_rated':
				// Ordering by real average rating happens in the clauses filter.
				$query->set( 'tcsp_order_by_rating', 1 );
				break;
			case 'most_students':
				// Ordering by real enrollment count happens in the clauses filter.
				$query->set( 'tcsp_order_by_students', 1 );
				break;
			case 'relevance':
			default:
				break;
		}
	}

	/**
	 * Result count of the most recently executed target query (archive/search),
	 * for display in the filter bar. Null if no target query has run yet in
	 * this request (e.g. the filter bar renders before the loop and nothing
	 * has queried through this class).
	 *
	 * @return int|null
	 */
	public function get_last_found_posts() {
		return $this->last_found_posts;
	}

	/**
	 * Search term of the most recently executed target query, if any.
	 *
	 * @return string
	 */
	public function get_last_search_term() {
		return $this->last_search_term;
	}

	/**
	 * "What if" result count for a single taxonomy term: how many courses
	 * would match if the visitor's *other* active filters (search text,
	 * price, level, rating, students, and the other taxonomy) stayed as they
	 * are, and this specific term were the only one selected in its own
	 * taxonomy (course-category or course-tag). This mirrors common faceted
	 * search UX: each checkbox shows what selecting *it* would produce,
	 * independent of anything else already checked in that same group.
	 *
	 * Implementation note: rather than duplicate the considerable filter/sort
	 * SQL already built by apply_query()/apply_clauses(), this temporarily
	 * overrides $_GET for the one taxonomy being counted, runs a minimal
	 * WP_Query through the exact same hooks, reads found_posts, then restores
	 * $_GET exactly as it was — synchronously, before this method returns, so
	 * the real page-rendering query (whichever request runs it, before or
	 * after this call) never sees the temporary state.
	 *
	 * @param string $taxonomy 'course-category' or 'course-tag'.
	 * @param string $slug     The term's slug.
	 * @return int
	 */
	public function get_sponsored_carousel_ids() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		if ( ! $this->settings->get( 'show_sponsored_carousel' ) ) {
			return $cache = array();
		}

		$limit = max( 3, min( 12, (int) $this->settings->get( 'sponsored_carousel_items' ) ) );
		$ids = get_posts(
			array(
				'post_type'        => TCSP_Plugin::COURSE_CPT,
				'post_status'      => 'publish',
				'posts_per_page'   => 500,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'tcsp_skip'        => 1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'meta_query'       => array(
					array(
						'key'     => TCSP_Plugin::PROMOTION_TIER_META,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$search   = isset( $_GET['s'] ) && ! is_array( $_GET['s'] ) ? trim( wp_strip_all_tags( wp_unslash( $_GET['s'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$price    = isset( $_GET['tcsp_price'] ) && ! is_array( $_GET['tcsp_price'] ) ? sanitize_key( wp_unslash( $_GET['tcsp_price'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$level    = isset( $_GET['tcsp_level'] ) && ! is_array( $_GET['tcsp_level'] ) ? sanitize_key( wp_unslash( $_GET['tcsp_level'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$rating   = isset( $_GET['tcsp_min_rating'] ) && ! is_array( $_GET['tcsp_min_rating'] ) ? min( 5, max( 0, absint( $_GET['tcsp_min_rating'] ) ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$students = isset( $_GET['tcsp_min_students'] ) && ! is_array( $_GET['tcsp_min_students'] ) ? max( 0, absint( $_GET['tcsp_min_students'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$cats     = ! empty( $_GET['tcsp_category'] ) ? self::sanitize_slug_list( wp_unslash( $_GET['tcsp_category'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tags     = ! empty( $_GET['tcsp_tag'] ) ? self::sanitize_slug_list( wp_unslash( $_GET['tcsp_tag'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$verified = isset( $_GET['tcsp_verified'] ) && ! is_array( $_GET['tcsp_verified'] ) ? sanitize_key( wp_unslash( $_GET['tcsp_verified'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$langs = ! empty( $_GET['tcsp_language'] ) ? self::sanitize_key_list( wp_unslash( $_GET['tcsp_language'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$lang_logic = isset( $_GET['tcsp_language_logic'] ) && 'or' === sanitize_key( wp_unslash( $_GET['tcsp_language_logic'] ) ) ? 'or' : 'and'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$types = ! empty( $_GET['tcsp_tutoring_type'] ) ? self::sanitize_key_list( wp_unslash( $_GET['tcsp_tutoring_type'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$type_logic = isset( $_GET['tcsp_tutoring_logic'] ) && 'or' === sanitize_key( wp_unslash( $_GET['tcsp_tutoring_logic'] ) ) ? 'or' : 'and'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$wilaya = ! empty( $_GET['tcsp_wilaya'] ) ? self::sanitize_key_list( wp_unslash( $_GET['tcsp_wilaya'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$municipality = ! empty( $_GET['tcsp_municipality'] ) ? self::sanitize_key_list( wp_unslash( $_GET['tcsp_municipality'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$wilaya_logic = $this->request_logic( 'tcsp_wilaya_logic' );
		$municipality_logic = $this->request_logic( 'tcsp_municipality_logic' );
		$course_types = ! empty( $_GET['tcsp_course_type'] ) ? self::sanitize_key_list( wp_unslash( $_GET['tcsp_course_type'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$course_formats = ! empty( $_GET['tcsp_tutoring_format'] ) ? self::sanitize_key_list( wp_unslash( $_GET['tcsp_tutoring_format'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$cat_logic = isset( $_GET['tcsp_category_logic'] ) && 'or' === sanitize_key( wp_unslash( $_GET['tcsp_category_logic'] ) ) ? 'or' : 'and'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tag_logic = isset( $_GET['tcsp_tag_logic'] ) && 'or' === sanitize_key( wp_unslash( $_GET['tcsp_tag_logic'] ) ) ? 'or' : 'and'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$cross_ids = array();
		if ( '' !== $search && $this->settings->get( 'enable_multilingual_search' ) && class_exists( 'TCSP_Multilingual' ) ) {
			$trp = TCSP_Multilingual::cross_language_matches( $search );
			$cross_ids = ! empty( $trp['post_ids'] ) ? array_map( 'absint', $trp['post_ids'] ) : array();
		}

		$tiers      = $this->settings->promotion_tiers();
		$candidates = array();

		foreach ( $ids as $course_id ) {
			$course_id = absint( $course_id );
			if ( ! $course_id || ! TCSP_Plugin::is_promotion_active( $course_id ) ) {
				continue;
			}

			if ( $this->settings->get( 'enable_price_filter' ) && in_array( $price, array( 'free', 'paid' ), true ) ) {
				$is_paid = TCSP_Plugin::is_paid_course( $course_id );
				if ( ( 'paid' === $price && ! $is_paid ) || ( 'free' === $price && $is_paid ) ) {
					continue;
				}
			}

			if ( $this->settings->get( 'enable_level_filter' ) && '' !== $level && $level !== (string) get_post_meta( $course_id, '_tutor_course_level', true ) ) {
				continue;
			}

			if ( $this->settings->get( 'enable_category_filter' ) && ! empty( $cats ) ) {
				$branches = array();
				foreach ( $cats as $slug ) {
					$branches[] = self::expand_term_ids( array( $slug ), 'course-category' );
				}
				$ok = 'or' === $cat_logic ? false : true;
				foreach ( $branches as $branch ) {
					$has = ! empty( $branch ) && has_term( $branch, 'course-category', $course_id );
					if ( 'or' === $cat_logic ) {
						if ( $has ) { $ok = true; break; }
					} elseif ( ! $has ) {
						$ok = false; break;
					}
				}
				if ( ! $ok ) { continue; }
			}

			if ( $this->settings->get( 'enable_tag_filter' ) && ! empty( $tags ) ) {
				$ok = 'or' === $tag_logic ? false : true;
				foreach ( $tags as $slug ) {
					$term = get_term_by( 'slug', $slug, 'course-tag' );
					$has  = $term && has_term( (int) $term->term_id, 'course-tag', $course_id );
					if ( 'or' === $tag_logic ) {
						if ( $has ) { $ok = true; break; }
					} elseif ( ! $has ) {
						$ok = false; break;
					}
				}
				if ( ! $ok ) { continue; }
			}

			$author_id = (int) get_post_field( 'post_author', $course_id );
			if ( $this->settings->get( 'enable_verified_filter' ) && 'verified' === $verified ) {
				$status = (string) get_user_meta( $author_id, self::PROFILE_VERIFICATION_META, true );
				if ( 'verified' !== $status ) { continue; }
			}
			if ( $this->settings->get( 'enable_wilaya_filter' ) && $wilaya ) {
				$saved_wilayas = $this->normalize_profile_list_meta( get_user_meta( $author_id, self::PROFILE_WILAYA_META, true ) );
				$hits = array_values( array_intersect( $wilaya, $saved_wilayas ) );
				if ( 'and' === $wilaya_logic ) {
					if ( count( $hits ) !== count( $wilaya ) ) { continue; }
				} elseif ( empty( $hits ) ) {
					continue;
				}
			}
			if ( $this->settings->get( 'enable_municipality_filter' ) && $municipality ) {
				$saved_communes = $this->normalize_profile_list_meta( get_user_meta( $author_id, self::PROFILE_COMMUNE_META, true ) );
				$hits = array_values( array_intersect( $municipality, $saved_communes ) );
				if ( 'and' === $municipality_logic ) {
					if ( count( $hits ) !== count( $municipality ) ) { continue; }
				} elseif ( empty( $hits ) ) {
					continue;
				}
			}
			if ( $course_types || $course_formats ) { $course_type = function_exists( 'tcf_get_course_type' ) ? tcf_get_course_type( $course_id ) : (string) get_post_meta( $course_id, '_tcf_course_type', true ); if ( '' === $course_type ) { $course_type = 'prerecorded'; } if ( $course_types && ! in_array( $course_type, $course_types, true ) ) { continue; } if ( $course_formats ) { $course_format = function_exists( 'tcf_get_course_format' ) ? tcf_get_course_format( $course_id ) : (string) get_post_meta( $course_id, '_tcf_course_format', true ); if ( ! in_array( $course_format, $course_formats, true ) ) { continue; } } }
			if ( $this->settings->get( 'enable_spoken_language_filter' ) && $langs ) {
				$saved = $this->normalize_profile_list_meta( get_user_meta( $author_id, self::PROFILE_LANGUAGES_META, true ) );
				$hits = array_values( array_intersect( $langs, $saved ) );
				if ( ( 'and' === $lang_logic && count( $hits ) !== count( $langs ) ) || ( 'or' === $lang_logic && empty( $hits ) ) ) { continue; }
			}
			if ( $this->settings->get( 'enable_tutoring_type_filter' ) && $types ) {
				$saved = $this->normalize_profile_list_meta( get_user_meta( $author_id, self::PROFILE_TUTORING_META, true ) );
				$hits = array_values( array_intersect( $types, $saved ) );
				if ( ( 'and' === $type_logic && count( $hits ) !== count( $types ) ) || ( 'or' === $type_logic && empty( $hits ) ) ) { continue; }
			}

			$course_rating   = $this->get_carousel_rating( $course_id );
			$course_students = $this->get_carousel_students( $course_id );
			if ( $this->settings->get( 'enable_rating_filter' ) && $rating > 0 && $course_rating < $rating ) { continue; }
			if ( $this->settings->get( 'enable_students_filter' ) && $students > 0 && $course_students < $students ) { continue; }

			// A sponsored course must first be genuinely relevant to the current
			// search. Sponsorship only changes the probability of being featured.
			if ( '' !== $search ) {
				$relevance = (float) $this->get_carousel_search_score( $course_id, $search, $cross_ids );
				if ( $relevance <= 0 ) { continue; }
			} else {
				$relevance = min( 100, $course_rating * 18 ) + min( 40, log10( $course_students + 1 ) * 20 );
			}

			$tier_key = TCSP_Plugin::get_promotion_tier( $course_id );
			$tier     = isset( $tiers[ $tier_key ] ) ? $tiers[ $tier_key ] : array();
			$plan_weight = isset( $tier['weight'] ) ? max( 0, (float) $tier['weight'] ) : 0.0;
			$exposure    = isset( $tier['exposure'] ) ? max( 1, (float) $tier['exposure'] ) : 1.0;

			// Weighted-random rotation: every eligible course gets a non-zero
			// chance, while higher plans get materially better odds. Exposure
			// multiplies the plan's probability advantage. We deliberately do not
			// sort by rating/relevance here, so a lower plan can still win a draw.
			$selection_weight = ( 1.0 + min( 100.0, $plan_weight ) ) * min( 20.0, $exposure );
			$selection_weight = max( 1.0, $selection_weight );
			$random_u = ( wp_rand( 1, 1000000 ) / 1000001 );
			$random_key = -log( $random_u ) / $selection_weight;

			$candidates[] = array(
				'id'       => $course_id,
				'weight'   => $selection_weight,
				'random'   => $random_key,
				'relevance'=> $relevance,
			);
		}

		if ( empty( $candidates ) ) {
			return $cache = array();
		}

		// Sort weighted random keys ascending: smaller exponential draw wins; a
		// larger plan weight therefore has a higher chance of taking an early spot. Efraimidis-Spirakis style
		// weighted sampling without replacement: higher-weight plans have a
		// better chance of taking early positions, but every candidate remains
		// eligible and can eventually be selected. The same course therefore
		// cannot become permanently pinned to the first position.
		usort( $candidates, function ( $a, $b ) {
			if ( $a['random'] === $b['random'] ) {
				return $a['id'] <=> $b['id'];
			}
			return $a['random'] < $b['random'] ? -1 : 1;
		} );

		$out = array();
		foreach ( $candidates as $candidate ) {
			$out[] = (int) $candidate['id'];
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $cache = $out;
	}

	private function get_carousel_search_score( $course_id, $search, $cross_ids ) {
		$needle = function_exists( 'mb_strtolower' ) ? mb_strtolower( $search, 'UTF-8' ) : strtolower( $search );
		$score = 0;
		$fields = array(
			700 => get_the_title( $course_id ),
			180 => get_post_field( 'post_content', $course_id ),
			140 => get_post_field( 'post_excerpt', $course_id ),
			100 => get_the_author_meta( 'display_name', (int) get_post_field( 'post_author', $course_id ) ),
		);
		foreach ( $fields as $weight => $value ) {
			$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( wp_strip_all_tags( (string) $value ), 'UTF-8' ) : strtolower( wp_strip_all_tags( (string) $value ) );
			if ( '' !== $value && false !== strpos( $value, $needle ) ) { $score += $weight; }
		}
		foreach ( array_merge( (array) get_the_terms( $course_id, 'course-category' ), (array) get_the_terms( $course_id, 'course-tag' ) ) as $term ) {
			if ( $term instanceof WP_Term ) {
				$name = function_exists( 'mb_strtolower' ) ? mb_strtolower( $term->name, 'UTF-8' ) : strtolower( $term->name );
				if ( false !== strpos( $name, $needle ) ) { $score += 260; break; }
			}
		}
		if ( in_array( $course_id, $cross_ids, true ) ) { $score += 300; }
		return $score;
	}

	private function get_carousel_rating( $course_id ) {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT AVG( CAST( cm.meta_value AS DECIMAL(10,2) ) ) FROM {$wpdb->comments} c INNER JOIN {$wpdb->commentmeta} cm ON cm.comment_id = c.comment_ID WHERE c.comment_post_ID = %d AND c.comment_type = %s AND cm.meta_key = %s", $course_id, TCSP_Plugin::RATING_COMMENT_TYPE, TCSP_Plugin::RATING_META_KEY ) );
		return $value ? (float) $value : 0.0;
	}

	private function get_carousel_students( $course_id ) {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s AND post_parent = %d", TCSP_Plugin::ENROLLMENT_CPT, TCSP_Plugin::ENROLLMENT_STATUS, $course_id ) );
		return (int) $value;
	}

	public function what_if_term_count( $taxonomy, $slug ) {
		$get_key = ( 'course-tag' === $taxonomy ) ? 'tcsp_tag' : 'tcsp_category';

		$cache_key = $get_key . ':' . $slug . ':' . md5( wp_json_encode( $this->count_relevant_get_state( $get_key ) ) );
		if ( isset( $this->term_count_memo[ $cache_key ] ) ) {
			return $this->term_count_memo[ $cache_key ];
		}

		$cache_group   = 'tcsp';
		$transient_key = 'tcsp_wic_' . md5( $cache_key );
		$cached        = wp_cache_get( $transient_key, $cache_group );
		if ( false !== $cached ) {
			$this->term_count_memo[ $cache_key ] = (int) $cached;
			return (int) $cached;
		}

		$original_value             = isset( $_GET[ $get_key ] ) ? $_GET[ $get_key ] : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET[ $get_key ]           = array( $slug ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$original_logic             = isset( $_GET[ $get_key . '_logic' ] ) ? $_GET[ $get_key . '_logic' ] : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET[ $get_key . '_logic' ] = 'and'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// This test query is itself a recognized "target query" (by design,
		// so it gets the same filters/sort applied) — which means it will
		// also trigger enforce_final_order()'s found_posts/search-term
		// capture, below. Save and restore that state around the test query
		// so a term's what-if count can never clobber the real page's
		// displayed result count.
		$saved_found_posts = $this->last_found_posts;
		$saved_search_term = $this->last_search_term;

		$count = 0;
		try {
			$test = new WP_Query(
				array(
					'post_type'              => TCSP_Plugin::COURSE_CPT,
					'post_status'            => 'publish',
					'posts_per_page'         => 1,
					'no_found_rows'          => false,
					'fields'                 => 'ids',
					'ignore_sticky_posts'    => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);
			$count = (int) $test->found_posts;
		} finally {
			if ( null === $original_value ) {
				unset( $_GET[ $get_key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			} else {
				$_GET[ $get_key ] = $original_value; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
			if ( null === $original_logic ) {
				unset( $_GET[ $get_key . '_logic' ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			} else {
				$_GET[ $get_key . '_logic' ] = $original_logic; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
			$this->last_found_posts = $saved_found_posts;
			$this->last_search_term = $saved_search_term;
		}

		$this->term_count_memo[ $cache_key ] = $count;
		wp_cache_set( $transient_key, $count, $cache_group, 45 );

		return $count;
	}

	/**
	 * The subset of $_GET that affects what_if_term_count()'s result for a
	 * given taxonomy — everything EXCEPT that taxonomy's own current
	 * selection (since we override that ourselves per-term).
	 *
	 * @param string $exclude_get_key The taxonomy GET key to exclude (tcsp_category or tcsp_tag).
	 * @return array
	 */
	private function count_relevant_get_state( $exclude_get_key ) {
		$keys  = array( 's', 'tcsp_price', 'tcsp_level', 'tcsp_min_rating', 'tcsp_min_students', 'tcsp_category', 'tcsp_tag', 'tcsp_category_logic', 'tcsp_tag_logic', 'tcsp_verified', 'tcsp_language', 'tcsp_language_logic', 'tcsp_tutoring_type', 'tcsp_tutoring_logic', 'tcsp_wilaya', 'tcsp_municipality' );
		$state = array();
		foreach ( $keys as $key ) {
			if ( $key === $exclude_get_key || $key === $exclude_get_key . '_logic' ) {
				continue;
			}
			if ( isset( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$state[ $key ] = wp_unslash( $_GET[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
		}
		return $state;
	}

	/**
	 * Category/tag term IDs whose name or slug matches the search string.
	 *
	 * @param string $search Raw search string.
	 * @return int[]
	 */
	public static function term_ids_matching( $search ) {
		$search = trim( wp_strip_all_tags( (string) $search ) );
		if ( strlen( $search ) < 2 ) {
			return array();
		}
		$terms = get_terms(
			array(
				'taxonomy'   => array( 'course-category', 'course-tag' ),
				'hide_empty' => false,
				'search'     => $search, // matches name + slug, substring.
				'number'     => 200,
				'fields'     => 'ids',
			)
		);
		return is_wp_error( $terms ) ? array() : array_map( 'intval', $terms );
	}

	/**
	 * Single ORDER BY / WHERE mutator: OR-search across text + taxonomy,
	 * minimum-rating filter, top-rated sort, and promoted/paid ranking.
	 */
	public function apply_clauses( $clauses, $query ) {
		global $wpdb;

		$is_marked = ( 1 === (int) $query->get( self::ACTIVE_MARKER_QUERY_VAR ) );
		if ( ! $is_marked || ! $this->is_target_query( $query ) ) {
			return $clauses;
		}
		$this->active = false;

		$need_group = false;

		// ------------------------------------------------------------------
		// (A) Search across course name, description, categories, tags, and
		// tutor display name. Fully self-contained so it composes with any
		// category/tag filters already present in the query.
		// ------------------------------------------------------------------
		$term_ids = $query->get( 'tcsp_term_ids' );
		$search   = trim( wp_strip_all_tags( (string) $query->get( 'tcsp_search_term' ) ) );
		if ( '' !== $search ) {
			$like   = '%' . $wpdb->esc_like( $search ) . '%';
			$text = $wpdb->prepare(
				"( {$wpdb->posts}.post_title LIKE %s OR {$wpdb->posts}.post_content LIKE %s OR {$wpdb->posts}.post_excerpt LIKE %s OR tcsp_author.display_name LIKE %s )",
				$like,
				$like,
				$like,
				$like
			);
			$clauses['join'] .= " LEFT JOIN {$wpdb->users} tcsp_author ON tcsp_author.ID = {$wpdb->posts}.post_author ";
			$term_match = '0=1';
			if ( ! empty( $term_ids ) ) {
				$ids_csv = implode( ',', array_map( 'absint', (array) $term_ids ) );
				// Own uniquely-aliased JOINs keep this independent of WP's
				// aliases for explicit category/tag filters.
				$clauses['join'] .= " LEFT JOIN {$wpdb->term_relationships} tr_tcsp_search ON tr_tcsp_search.object_id = {$wpdb->posts}.ID ";
				$clauses['join'] .= " LEFT JOIN {$wpdb->term_taxonomy} tt_tcsp_search ON tt_tcsp_search.term_taxonomy_id = tr_tcsp_search.term_taxonomy_id AND tt_tcsp_search.term_id IN ({$ids_csv}) AND tt_tcsp_search.taxonomy IN ('course-category','course-tag') ";
				$term_match = 'tt_tcsp_search.term_id IS NOT NULL';
			}

			// Cross-language (TranslatePress) matches: courses whose *translated*
			// title/description/taxonomy text contains the term, resolved to IDs
			// ahead of time in apply_query() since that lookup needs its own
			// dictionary-table queries rather than a JOIN.
			$trp_match    = '0=1';
			$trp_post_ids = $query->get( 'tcsp_trp_post_ids' );
			if ( ! empty( $trp_post_ids ) ) {
				$trp_ids_csv = implode( ',', array_map( 'absint', (array) $trp_post_ids ) );
				$trp_match   = "{$wpdb->posts}.ID IN ({$trp_ids_csv})";
			}

			$clauses['where'] .= " AND ( {$text} OR {$term_match} OR {$trp_match} ) ";
			$need_group = true;
		}

		// ------------------------------------------------------------------
		// (B) Average-rating subquery, reused by the rating filter and sort.
		// ------------------------------------------------------------------
		$needs_rating = $this->min_rating > 0 || $query->get( 'tcsp_order_by_rating' );
		$avg_sql      = '';
		if ( $needs_rating ) {
			$avg_sql = $wpdb->prepare(
				"( SELECT AVG( CAST( cm.meta_value AS DECIMAL(10,2) ) )
				   FROM {$wpdb->comments} c
				   INNER JOIN {$wpdb->commentmeta} cm ON cm.comment_id = c.comment_ID
				   WHERE c.comment_post_ID = {$wpdb->posts}.ID
				     AND c.comment_type = %s
				     AND cm.meta_key = %s )",
				TCSP_Plugin::RATING_COMMENT_TYPE,
				TCSP_Plugin::RATING_META_KEY
			);

			if ( $this->min_rating > 0 ) {
				$clauses['where'] .= $wpdb->prepare( " AND COALESCE( {$avg_sql}, 0 ) >= %f ", (float) $this->min_rating );
			}
		}

		// ------------------------------------------------------------------
		// (B2) Price sort — LEFT JOIN so free courses (no price meta row) are
		// treated as price 0 instead of being excluded from the result set.
		// Ranks by the course's *effective* (currently-charged) price: the
		// sale price when one is set, falling back to the regular price
		// otherwise — so "Price: low to high/high to low" reflects what a
		// student would actually pay, not the pre-discount list price.
		//
		// KEY FIX: the effective price explicitly respects the price_type meta
		// value. If a course is flagged as "free", we treat it as $0 even if a
		// stale numeric price row still exists in postmeta (which commonly
		// happens after toggling a course between paid and free in Tutor LMS).
		// ------------------------------------------------------------------
		$price_dir = $query->get( 'tcsp_order_by_price' );
		$price_sql = '';
		if ( 'ASC' === $price_dir || 'DESC' === $price_dir ) {
			$clauses['join'] .= $wpdb->prepare(
				" LEFT JOIN {$wpdb->postmeta} tcsp_price_sort ON tcsp_price_sort.post_id = {$wpdb->posts}.ID AND tcsp_price_sort.meta_key = %s ",
				TCSP_Plugin::PRICE_META
			);
			$clauses['join'] .= $wpdb->prepare(
				" LEFT JOIN {$wpdb->postmeta} tcsp_sale_price_sort ON tcsp_sale_price_sort.post_id = {$wpdb->posts}.ID AND tcsp_sale_price_sort.meta_key = %s ",
				TCSP_Plugin::SALE_PRICE_META
			);
			$clauses['join'] .= $wpdb->prepare(
				" LEFT JOIN {$wpdb->postmeta} tcsp_price_type_sort ON tcsp_price_type_sort.post_id = {$wpdb->posts}.ID AND tcsp_price_type_sort.meta_key = %s ",
				TCSP_Plugin::PRICE_TYPE_META
			);
			$price_sql  = "( CASE
				WHEN tcsp_price_type_sort.meta_value = '" . esc_sql( TCSP_Plugin::PRICE_TYPE_FREE ) . "'
					OR tcsp_price_type_sort.meta_value IS NULL
					OR tcsp_price_type_sort.meta_value = ''
				THEN
					/* Explicitly or implicitly free: sort as $0. */
					0
				ELSE
					/* Paid: use sale price when set and non-zero, else regular price. */
					COALESCE(
						NULLIF( CAST( tcsp_sale_price_sort.meta_value AS DECIMAL(10,2) ), 0 ),
						NULLIF( CAST( tcsp_price_sort.meta_value AS DECIMAL(10,2) ), 0 ),
						0
					)
			END )";
			$need_group = true;
		}

		// ------------------------------------------------------------------
		// (B2b) Price-type (free/paid) filter via LEFT JOIN so courses that
		// never had the _tutor_course_price_type meta row inserted (common for
		// imported / legacy courses) are treated as 'free' rather than dropped.
		// This fixes the "Free filter returns nothing" footgun caused by WP core's
		// INNER JOIN meta_query pattern.
		// ------------------------------------------------------------------
		$price_filter = $query->get( 'tcsp_filter_price' );
		if ( TCSP_Plugin::PRICE_TYPE_FREE === $price_filter || TCSP_Plugin::PRICE_TYPE_PAID === $price_filter ) {
			$clauses['join'] .= $wpdb->prepare(
				" LEFT JOIN {$wpdb->postmeta} tcsp_price_type_f ON tcsp_price_type_f.post_id = {$wpdb->posts}.ID AND tcsp_price_type_f.meta_key = %s ",
				TCSP_Plugin::PRICE_TYPE_META
			);
			if ( TCSP_Plugin::PRICE_TYPE_FREE === $price_filter ) {
				// "Free" = either explicitly set to free OR meta missing (Tutor default
				// default is free). We also accept an empty string.
				$clauses['where'] .= " AND (
					tcsp_price_type_f.meta_value IS NULL
					OR tcsp_price_type_f.meta_value = ''
					OR tcsp_price_type_f.meta_value = '" . esc_sql( TCSP_Plugin::PRICE_TYPE_FREE ) . "'
				) ";
			} else {
				// "Paid" requires the row to exist and equal "paid".
				$clauses['where'] .= $wpdb->prepare(
					" AND tcsp_price_type_f.meta_value = %s ",
					TCSP_Plugin::PRICE_TYPE_PAID
				);
			}
			$need_group = true;
		}

		// ------------------------------------------------------------------
		// (B2c) Difficulty-level filter via LEFT JOIN.
		// When a level is selected we match exact value (NULL rows excluded because
		// picking a specific level without setting it) but we still LEFT JOIN so
		// that the query plan stays composable with the other LEFT JOINs above).
		// ------------------------------------------------------------------
		$level_filter = (string) $query->get( 'tcsp_filter_level' );
		if ( '' !== $level_filter ) {
			$clauses['join'] .= $wpdb->prepare(
				" LEFT JOIN {$wpdb->postmeta} tcsp_level_f ON tcsp_level_f.post_id = {$wpdb->posts}.ID AND tcsp_level_f.meta_key = %s ",
				'_tutor_course_level'
			);
			$clauses['where'] .= $wpdb->prepare(
				" AND tcsp_level_f.meta_value = %s ",
				$level_filter
			);
			$need_group = true;
		}

		// ------------------------------------------------------------------
		// (B2c) Tutor Course Format filters.
		// Course type and tutoring format are single-valued; selecting several
		// values therefore means ANY selected value.
		// ------------------------------------------------------------------
		$course_types = self::sanitize_key_list( (array) $query->get( 'tcsp_filter_course_types' ) );
		$course_formats = self::sanitize_key_list( (array) $query->get( 'tcsp_filter_tutoring_formats' ) );
		if ( $course_types ) {
			$type_meta = defined( 'TCF_META_TYPE' ) ? TCF_META_TYPE : '_tcf_course_type';
			$clauses['join'] .= $wpdb->prepare( " LEFT JOIN {$wpdb->postmeta} tcsp_tcf_type ON tcsp_tcf_type.post_id = {$wpdb->posts}.ID AND tcsp_tcf_type.meta_key = %s ", $type_meta );
			$conditions = array();
			foreach ( $course_types as $type ) {
				if ( 'prerecorded' === $type ) { $conditions[] = "( tcsp_tcf_type.meta_value = 'prerecorded' OR tcsp_tcf_type.meta_value IS NULL OR tcsp_tcf_type.meta_value = '' )"; }
				elseif ( in_array( $type, array( 'direct', 'hybrid' ), true ) ) { $conditions[] = $wpdb->prepare( 'tcsp_tcf_type.meta_value = %s', $type ); }
			}
			if ( $conditions ) { $clauses['where'] .= ' AND ( ' . implode( ' OR ', $conditions ) . ' ) '; $need_group = true; }
		}
		if ( $course_formats ) {
			$format_meta = defined( 'TCF_META_FORMAT' ) ? TCF_META_FORMAT : '_tcf_course_format';
			$clauses['join'] .= $wpdb->prepare( " LEFT JOIN {$wpdb->postmeta} tcsp_tcf_format ON tcsp_tcf_format.post_id = {$wpdb->posts}.ID AND tcsp_tcf_format.meta_key = %s ", $format_meta );
			$conditions = array();
			foreach ( $course_formats as $format ) {
				if ( in_array( $format, array( 'group', 'one_to_one' ), true ) ) { $conditions[] = $wpdb->prepare( 'tcsp_tcf_format.meta_value = %s', $format ); }
			}
			if ( $conditions ) { $clauses['where'] .= ' AND ( ' . implode( ' OR ', $conditions ) . ' ) '; $need_group = true; }
		}

		// ------------------------------------------------------------------
		// (B2d) Instructor profile filters.
		// Match the primary course author AND Tutor Multi Instructor owners.
		// Tutor stores multi-instructor course ownership in usermeta under
		// _tutor_instructor_course_id, so checking only posts.post_author can
		// incorrectly exclude valid courses.
		// ------------------------------------------------------------------

		$needs_profile_author = ( $query->get( 'tcsp_filter_verified' ) || $query->get( 'tcsp_filter_languages' ) || $query->get( 'tcsp_filter_tutoring_types' ) || $query->get( 'tcsp_filter_wilaya' ) || $query->get( 'tcsp_filter_municipality' ) );
		if ( $needs_profile_author ) {

			// Repeated profile-user subqueries are intentionally kept in EXISTS
			// form. This avoids duplicate course rows and keeps WP pagination/counts
			// correct when a course has several instructors.
			if ( 'verified' === $query->get( 'tcsp_filter_verified' ) ) {
				$clauses['where'] .= $wpdb->prepare(
					" AND EXISTS (SELECT 1 FROM {$wpdb->usermeta} tcsp_verified_um WHERE tcsp_verified_um.meta_key = %s AND LOWER(TRIM(tcsp_verified_um.meta_value)) = 'verified' AND ( tcsp_verified_um.user_id = {$wpdb->posts}.post_author OR EXISTS (SELECT 1 FROM {$wpdb->usermeta} tcsp_verified_course WHERE tcsp_verified_course.user_id = tcsp_verified_um.user_id AND tcsp_verified_course.meta_key = '_tutor_instructor_course_id' AND CAST(tcsp_verified_course.meta_value AS UNSIGNED) = {$wpdb->posts}.ID) )) ",
					self::PROFILE_VERIFICATION_META
				);
			}

			foreach ( array( 'wilaya' => self::PROFILE_WILAYA_META, 'municipality' => self::PROFILE_COMMUNE_META ) as $kind => $meta_key ) {
				$values = self::sanitize_key_list( (array) $query->get( 'tcsp_filter_' . $kind ) );
				if ( empty( $values ) ) { continue; }
				$logic = 'or' === $query->get( 'tcsp_' . $kind . '_logic' ) ? 'or' : 'and';
				$conditions = array();
				foreach ( $values as $value ) {
					$scalar_where = ctype_digit( $value )
						? $wpdb->prepare( 'CAST(TRIM(tcsp_loc_um.meta_value) AS UNSIGNED) = %d', absint( $value ) )
						: $wpdb->prepare( 'TRIM(tcsp_loc_um.meta_value) = %s', $value );
					$conditions[] = $wpdb->prepare(
						"EXISTS (SELECT 1 FROM {$wpdb->usermeta} tcsp_loc_um WHERE tcsp_loc_um.meta_key = %s AND ( {$scalar_where} OR tcsp_loc_um.meta_value LIKE %s OR tcsp_loc_um.meta_value LIKE %s OR FIND_IN_SET(%s, REPLACE(REPLACE(TRIM(tcsp_loc_um.meta_value), ' ', ''), CHAR(34), '')) > 0 ) AND ( tcsp_loc_um.user_id = {$wpdb->posts}.post_author OR EXISTS (SELECT 1 FROM {$wpdb->usermeta} tcsp_loc_course WHERE tcsp_loc_course.user_id = tcsp_loc_um.user_id AND tcsp_loc_course.meta_key = '_tutor_instructor_course_id' AND CAST(tcsp_loc_course.meta_value AS UNSIGNED) = {$wpdb->posts}.ID) ))",
						$meta_key,
						'%' . $wpdb->esc_like( 's:' . strlen( $value ) . ':"' . $value . '";' ) . '%',
						'%' . $wpdb->esc_like( '"' . $value . '"' ) . '%',
						$value
					);
				}
				if ( $conditions ) { $clauses['where'] .= ' AND ( ' . implode( 'or' === $logic ? ' OR ' : ' AND ', $conditions ) . ' ) '; }
			}

			foreach ( array( 'languages' => self::PROFILE_LANGUAGES_META, 'tutoring_types' => self::PROFILE_TUTORING_META ) as $kind => $meta_key ) {
				$values = (array) $query->get( 'tcsp_filter_' . $kind );
				if ( empty( $values ) ) { continue; }
				$logic = 'or' === $query->get( 'tcsp_' . ( 'languages' === $kind ? 'language' : 'tutoring' ) . '_logic' ) ? 'or' : 'and';
				$conditions = array();
				foreach ( $values as $value ) {
					$value = sanitize_key( $value );
					if ( '' === $value ) { continue; }
					$serialized_token = 's:' . strlen( $value ) . ':"' . $value . '";';
					$serialized_pattern = '%' . $wpdb->esc_like( $serialized_token ) . '%';
					$json_pattern = '%' . $wpdb->esc_like( '"' . $value . '"' ) . '%';
					$conditions[] = $wpdb->prepare(
						"EXISTS (SELECT 1 FROM {$wpdb->usermeta} tcsp_prof_um WHERE tcsp_prof_um.meta_key = %s AND ( tcsp_prof_um.meta_value = %s OR tcsp_prof_um.meta_value LIKE %s OR tcsp_prof_um.meta_value LIKE %s OR FIND_IN_SET(%s, REPLACE(REPLACE(TRIM(tcsp_prof_um.meta_value), ' ', ''), CHAR(34), '')) > 0 ) AND ( tcsp_prof_um.user_id = {$wpdb->posts}.post_author OR EXISTS (SELECT 1 FROM {$wpdb->usermeta} tcsp_prof_course WHERE tcsp_prof_course.user_id = tcsp_prof_um.user_id AND tcsp_prof_course.meta_key = '_tutor_instructor_course_id' AND CAST(tcsp_prof_course.meta_value AS UNSIGNED) = {$wpdb->posts}.ID) ))",
						$meta_key,
						$value,
						$serialized_pattern,
						$json_pattern,
						$value
					);
				}
				if ( $conditions ) {
					$clauses['where'] .= ' AND ( ' . implode( ( 'or' === $logic ? ' OR ' : ' AND ' ), $conditions ) . ' ) ';
				}
			}
		}

		// ------------------------------------------------------------------
		// (B3) Enrolled-student count — minimum filter and/or "Most students"
		// sort, both driven by a correlated subquery counting Tutor's
		// enrollment records for the course.
		// ------------------------------------------------------------------
		$needs_students = $this->min_students > 0 || $query->get( 'tcsp_order_by_students' );
		$students_sql   = '';
		if ( $needs_students ) {
			$students_sql = $wpdb->prepare(
				"( SELECT COUNT(*) FROM {$wpdb->posts} tcsp_e
				   WHERE tcsp_e.post_type = %s
				     AND tcsp_e.post_parent = {$wpdb->posts}.ID
				     AND tcsp_e.post_status = %s )",
				TCSP_Plugin::ENROLLMENT_CPT,
				TCSP_Plugin::ENROLLMENT_STATUS
			);

			if ( $this->min_students > 0 ) {
				$clauses['where'] .= $wpdb->prepare( " AND COALESCE( {$students_sql}, 0 ) >= %d ", $this->min_students );
			}
		}

		// ------------------------------------------------------------------
		// (C) Marketplace relevance + sponsorship boost.
		//
		// Relevance is the base score. Sponsorship can improve the position of
		// an already-eligible course, but its influence is normalized and capped
		// so a paid tier cannot turn a poor search match into the #1 result.
		// Explicit sorts (price, rating, students, date, title) never receive a
		// sponsorship boost; they remain true to the visitor's requested sort.
		// ------------------------------------------------------------------
		$is_relevance_sort = ( 'relevance' === $this->current_sort_value );
		$score_parts        = array();
		$promote_courses    = (int) $this->settings->get( 'promote_courses' ) && ! $this->settings->get( 'show_sponsored_carousel' );
		$promote_paid       = (int) $this->settings->get( 'promote_paid_courses' );

		if ( $is_relevance_sort ) {
			$boost_cap = min( 250, max( 0, (int) $this->settings->get( 'sponsorship_boost_cap' ) ) );
			$relevance_parts = array();
			if ( '' !== $search ) {
				$like      = '%' . $wpdb->esc_like( $search ) . '%';
				$exact     = $wpdb->prepare( "CASE WHEN {$wpdb->posts}.post_title = %s THEN 1000 ELSE 0 END", $search );
				$title     = $wpdb->prepare( "CASE WHEN {$wpdb->posts}.post_title LIKE %s THEN 650 ELSE 0 END", $like );
				$content   = $wpdb->prepare( "CASE WHEN {$wpdb->posts}.post_content LIKE %s THEN 220 ELSE 0 END", $like );
				$excerpt   = $wpdb->prepare( "CASE WHEN {$wpdb->posts}.post_excerpt LIKE %s THEN 180 ELSE 0 END", $like );
				$author    = $wpdb->prepare( "CASE WHEN tcsp_author.display_name LIKE %s THEN 120 ELSE 0 END", $like );
				$relevance_parts[] = $exact;
				$relevance_parts[] = $title;
				$relevance_parts[] = $content;
				$relevance_parts[] = $excerpt;
				$relevance_parts[] = $author;
				if ( ! empty( $term_ids ) ) {
					$relevance_parts[] = '( CASE WHEN tt_tcsp_search.term_id IS NOT NULL THEN 400 ELSE 0 END )';
				}
				if ( ! empty( $trp_post_ids ) ) {
					$relevance_parts[] = "( CASE WHEN {$wpdb->posts}.ID IN ({$trp_ids_csv}) THEN 300 ELSE 0 END )";
				}
				$score_parts[] = '( ' . implode( ' + ', $relevance_parts ) . ' )';
			} else {
				// Browse/filter pages have no text query. Use lightweight quality
				// signals as the organic base so sponsorship does not become an
				// automatic "all sponsors first" queue. Explicit Top Rated / Most
				// Students sorts still use their dedicated exact sort paths.
				$rating_base = $wpdb->prepare(
					"( SELECT AVG( CAST( cm_b.meta_value AS DECIMAL(10,2) ) )
					   FROM {$wpdb->comments} c_b
					   INNER JOIN {$wpdb->commentmeta} cm_b ON cm_b.comment_id = c_b.comment_ID
					   WHERE c_b.comment_post_ID = {$wpdb->posts}.ID
					     AND c_b.comment_type = %s
					     AND cm_b.meta_key = %s )",
					TCSP_Plugin::RATING_COMMENT_TYPE,
					TCSP_Plugin::RATING_META_KEY
				);
				$students_base = $wpdb->prepare(
					"( SELECT COUNT(*) FROM {$wpdb->posts} tcsp_b_enroll
					   WHERE tcsp_b_enroll.post_type = %s
					     AND tcsp_b_enroll.post_parent = {$wpdb->posts}.ID
					     AND tcsp_b_enroll.post_status = %s )",
					TCSP_Plugin::ENROLLMENT_CPT,
					TCSP_Plugin::ENROLLMENT_STATUS
				);
				$score_parts[] = '( COALESCE( ' . $rating_base . ', 0 ) * 20 + LOG10( COALESCE( ' . $students_base . ', 0 ) + 1 ) * 10 )';
			}

			if ( $promote_courses ) {
				$tiers = $this->settings->promotion_tiers();
				$max_weight = 0;
				foreach ( $tiers as $tier ) {
					$max_weight = max( $max_weight, (int) $tier['weight'] );
				}
				if ( $max_weight > 0 && $boost_cap > 0 && ! empty( $tiers ) ) {
					$clauses['join'] .= $wpdb->prepare(
						" LEFT JOIN {$wpdb->postmeta} tcsp_tier ON tcsp_tier.post_id = {$wpdb->posts}.ID AND tcsp_tier.meta_key = %s ",
						TCSP_Plugin::PROMOTION_TIER_META
					);
					$clauses['join'] .= $wpdb->prepare(
						" LEFT JOIN {$wpdb->postmeta} tcsp_legacy_pm ON tcsp_legacy_pm.post_id = {$wpdb->posts}.ID AND tcsp_legacy_pm.meta_key = %s ",
						TCSP_Plugin::PROMOTED_META
					);
					$clauses['join'] .= $wpdb->prepare(
						" LEFT JOIN {$wpdb->postmeta} tcsp_promo_start ON tcsp_promo_start.post_id = {$wpdb->posts}.ID AND tcsp_promo_start.meta_key = %s ",
						TCSP_Plugin::PROMOTION_START_META
					);
					$clauses['join'] .= $wpdb->prepare(
						" LEFT JOIN {$wpdb->postmeta} tcsp_promo_end ON tcsp_promo_end.post_id = {$wpdb->posts}.ID AND tcsp_promo_end.meta_key = %s ",
						TCSP_Plugin::PROMOTION_END_META
					);
					$today = current_time( 'Y-m-d' );
					foreach ( $tiers as $key => $tier ) {
						$raw_weight = (int) $tier['weight'];
						if ( $raw_weight < 1 ) {
							continue;
						}
						$boost = (int) round( ( $raw_weight / $max_weight ) * $boost_cap );
						if ( $boost < 1 ) {
							continue;
						}
						$legacy = 'featured' === $key
							? " OR ( tcsp_tier.meta_value IS NULL AND tcsp_legacy_pm.meta_value = '1' )"
							: '';
						$date_check = " AND (
							( tcsp_promo_start.meta_value IS NULL OR tcsp_promo_start.meta_value = '' OR DATE(tcsp_promo_start.meta_value) <= %s )
							AND
							( tcsp_promo_end.meta_value IS NULL OR tcsp_promo_end.meta_value = '' OR DATE(tcsp_promo_end.meta_value) >= %s )
						)";
					$score_parts[] = $wpdb->prepare(
						"( CASE WHEN ( tcsp_tier.meta_value = %s{$legacy} ){$date_check} THEN %d ELSE 0 END )",
						$key,
						$today,
						$today,
						$boost
					);
					}
					$need_group = true;
				}
			}

			if ( $promote_paid ) {
				$paid_weight = min( $boost_cap, max( 0, (int) $this->settings->get( 'paid_course_weight' ) ) );
				if ( $paid_weight > 0 ) {
					$clauses['join'] .= $wpdb->prepare(
						" LEFT JOIN {$wpdb->postmeta} tcsp_price ON tcsp_price.post_id = {$wpdb->posts}.ID AND tcsp_price.meta_key = %s ",
						TCSP_Plugin::PRICE_TYPE_META
					);
					$score_parts[] = $wpdb->prepare( '( CASE WHEN tcsp_price.meta_value = %s THEN %d ELSE 0 END )', TCSP_Plugin::PRICE_TYPE_PAID, $paid_weight );
					$need_group = true;
				}
			}
		}

		// ------------------------------------------------------------------
		// (D) Compose ORDER BY: promotion score first (relevance sort only),
		// then whichever explicit metric the visitor picked, then whatever
		// WP already built as the tiebreaker.
		// ------------------------------------------------------------------
		$order_prefix = array();
		if ( ! empty( $score_parts ) ) {
			$order_prefix[] = '( ' . implode( ' + ', $score_parts ) . ' ) DESC';
		}
		if ( $query->get( 'tcsp_order_by_rating' ) && $avg_sql ) {
			$order_prefix[] = 'COALESCE( ' . $avg_sql . ', 0 ) DESC';
		}
		if ( $price_sql ) {
			$order_prefix[] = $price_sql . ' ' . $price_dir;
		}
		if ( $query->get( 'tcsp_order_by_students' ) && $students_sql ) {
			$order_prefix[] = 'COALESCE( ' . $students_sql . ', 0 ) DESC';
		}

		if ( ! empty( $order_prefix ) ) {
			$existing           = trim( (string) $clauses['orderby'] );
			$clauses['orderby'] = implode( ', ', $order_prefix ) . ( '' !== $existing ? ', ' . $existing : '' );
		}

		$tiebreaker = "{$wpdb->posts}.ID DESC";
		$current_ob = trim( (string) $clauses['orderby'] );
		if ( '' === $current_ob ) {
			$clauses['orderby'] = $tiebreaker;
		} elseif ( false === strpos( $current_ob, "{$wpdb->posts}.ID" ) ) {
			$clauses['orderby'] = $current_ob . ', ' . $tiebreaker;
		}

		if ( $need_group && empty( $clauses['groupby'] ) ) {
			$clauses['groupby'] = "{$wpdb->posts}.ID";
		}

		return $clauses;
	}
}
