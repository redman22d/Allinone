<?php
/**
 * General settings for Tutor Course Search Pro.
 *
 * @package Tutor_Course_Search_Pro
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class TCSP_Settings {
	const OPTION = 'tcsp_settings';
	const NONCE  = 'tcsp_settings_save';
	private $cache = null;

	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 100 );
		add_action( 'admin_init', array( $this, 'maybe_save' ) );
	}

	public function defaults() {
		return array(
			'promote_courses' => 1,
			'promote_paid_courses' => 1,
			'promoted_weight' => 100,
			'paid_course_weight' => 10,
			'sponsorship_boost_cap' => 80,
			'promotion_tiers' => array(
				'platinum' => array( 'label' => 'Premium', 'description' => 'Maximum sponsored exposure, highest carousel priority and strongest course visibility.', 'weight' => 300, 'exposure' => 6, 'color' => '#7c3aed', 'price' => 0, 'duration' => 30, 'manager_id' => 0 ),
				'gold' => array( 'label' => 'Pro', 'description' => 'Higher sponsored priority and exposure for courses.', 'weight' => 180, 'exposure' => 3, 'color' => '#b7791f', 'price' => 0, 'duration' => 30, 'manager_id' => 0 ),
				'featured' => array( 'label' => 'Basic', 'description' => 'Standard sponsored visibility for courses.', 'weight' => 80, 'exposure' => 1, 'color' => '#2563eb', 'price' => 0, 'duration' => 30, 'manager_id' => 0 ),
			),
			'tutor_promotion_tiers' => array(
				'platinum' => array( 'label' => 'Premium', 'description' => 'Highest visibility in the sponsored tutor carousel.', 'weight' => 300, 'exposure' => 6, 'color' => '#7c3aed', 'price' => 0, 'duration' => 30 ),
				'gold' => array( 'label' => 'Pro', 'description' => 'Higher tutor carousel priority and exposure.', 'weight' => 180, 'exposure' => 3, 'color' => '#b7791f', 'price' => 0, 'duration' => 30 ),
				'featured' => array( 'label' => 'Basic', 'description' => 'Standard sponsored tutor visibility.', 'weight' => 80, 'exposure' => 1, 'color' => '#2563eb', 'price' => 0, 'duration' => 30 ),
			),
			'sponsorship_auto_activate' => 1,
			'sponsorship_notifications' => 1,
			'sponsorship_max_units' => 12,
			'sponsorship_unpaid_cancel_days' => 3,
			'enable_price_filter' => 1,
			'enable_level_filter' => 1,
			'enable_category_filter' => 1,
			'enable_tag_filter' => 1,
			'enable_rating_filter' => 1,
			'enable_students_filter' => 1,
			'enable_verified_filter' => 1,
			'enable_spoken_language_filter' => 1,
			'enable_tutoring_type_filter' => 1,
			'enable_wilaya_filter' => 1,
			'enable_municipality_filter' => 1,
			'enable_course_type_filter' => 1,
			'enable_tutoring_format_filter' => 1,
			'default_sort' => 'relevance',
			'replace_suggestions' => 1,
			'suggest_limit' => 8,
			'enable_multilingual_search' => 1,
			'enable_live_term_counts' => 1,
			'show_result_count' => 1,
			'show_sponsored_carousel' => 1,
			'show_verified_sticker' => 1,
			'sponsored_carousel_items' => 8,
			'enable_tutor_search' => 1,
			'tutor_page_slug' => 'tutors',
			'tutor_per_page' => 12,
			'tutor_carousel_items' => 8,
			'show_tutor_carousel' => 1,
			'show_tutor_result_count' => 1,
			'enable_tutor_rating_filter' => 1,
			'enable_tutor_students_filter' => 1,
			'enable_tutor_courses_filter' => 1,
			'enable_tutor_verified_filter' => 1,
			'enable_tutor_language_filter' => 1,
			'enable_tutor_tutoring_type_filter' => 1,
			'enable_tutor_wilaya_filter' => 1,
			'enable_tutor_municipality_filter' => 1,
			'enable_tutor_private_sessions_filter' => 1,
			'enable_tutor_teaching_category_filter' => 1,
			'enable_tutor_experience_filter' => 1,
			'enable_tutor_course_type_filter' => 1,
			'hidden_tutor_ids' => array(),
			'tutor_card_avatar' => 1,
			'tutor_card_rating' => 1,
			'tutor_card_reviews' => 1,
			'tutor_card_courses' => 1,
			'tutor_card_students' => 1,
			'tutor_card_experience' => 1,
			'tutor_card_location' => 1,
			'tutor_card_languages' => 1,
			'tutor_card_tutoring_types' => 1,
			'tutor_card_qualification' => 1,
			'tutor_card_verified' => 1,
			'tutor_card_bio' => 1,
			'tutor_card_job_title' => 1,
			'tutor_card_categories' => 1,
		);
	}

	public function all() {
		if ( null === $this->cache ) {
			$saved = get_option( self::OPTION, array() );
			$this->cache = wp_parse_args( is_array( $saved ) ? $saved : array(), $this->defaults() );
		}
		return $this->cache;
	}

	public function get( $key ) {
		$all = $this->all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public function add_menu() {
		add_submenu_page( 'tutor', __( 'Course Search Pro', 'tutor-learning-suite' ), __( 'Course Search Pro', 'tutor-learning-suite' ), 'manage_options', 'tcsp-settings', array( $this, 'render_page' ) );
	}

	public function maybe_save() {
		if ( ! isset( $_POST['tcsp_settings_submit'] ) || ! current_user_can( 'manage_options' ) ) return;
		$nonce = isset( $_POST['tcsp_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['tcsp_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE ) ) return;
		$in = wp_unslash( $_POST );
		$default_sort = isset( $in['default_sort'] ) && ! is_array( $in['default_sort'] ) ? sanitize_key( $in['default_sort'] ) : 'relevance';
		if ( ! array_key_exists( $default_sort, $this->sort_options() ) ) $default_sort = 'relevance';
		$out = array(
			'promote_courses' => isset( $in['promote_courses'] ) ? 1 : 0,
			'promote_paid_courses' => isset( $in['promote_paid_courses'] ) ? 1 : 0,
			'promoted_weight' => isset( $in['promoted_weight'] ) ? min( 1000, absint( $in['promoted_weight'] ) ) : 100,
			'paid_course_weight' => isset( $in['paid_course_weight'] ) ? min( 250, absint( $in['paid_course_weight'] ) ) : 10,
			'sponsorship_boost_cap' => isset( $in['sponsorship_boost_cap'] ) ? min( 250, max( 0, absint( $in['sponsorship_boost_cap'] ) ) ) : 80,
			'promotion_tiers' => isset( $in['promotion_tiers'] ) && is_array( $in['promotion_tiers'] ) ? $this->sanitize_promotion_tiers( $in['promotion_tiers'] ) : $this->promotion_tiers(),
			'tutor_promotion_tiers' => isset( $in['tutor_promotion_tiers'] ) && is_array( $in['tutor_promotion_tiers'] ) ? $this->sanitize_promotion_tiers( $in['tutor_promotion_tiers'], 'tutor' ) : $this->tutor_promotion_tiers(),
			'sponsorship_auto_activate' => isset( $in['sponsorship_auto_activate'] ) ? 1 : 0,
			'sponsorship_notifications' => isset( $in['sponsorship_notifications'] ) ? 1 : 0,
			'enable_price_filter' => isset( $in['enable_price_filter'] ) ? 1 : 0,
			'enable_level_filter' => isset( $in['enable_level_filter'] ) ? 1 : 0,
			'enable_category_filter' => isset( $in['enable_category_filter'] ) ? 1 : 0,
			'enable_tag_filter' => isset( $in['enable_tag_filter'] ) ? 1 : 0,
			'enable_rating_filter' => isset( $in['enable_rating_filter'] ) ? 1 : 0,
			'enable_students_filter' => isset( $in['enable_students_filter'] ) ? 1 : 0,
			'enable_verified_filter' => isset( $in['enable_verified_filter'] ) ? 1 : 0,
			'enable_spoken_language_filter' => isset( $in['enable_spoken_language_filter'] ) ? 1 : 0,
			'enable_tutoring_type_filter' => isset( $in['enable_tutoring_type_filter'] ) ? 1 : 0,
			'enable_wilaya_filter' => isset( $in['enable_wilaya_filter'] ) ? 1 : 0,
			'enable_municipality_filter' => isset( $in['enable_municipality_filter'] ) ? 1 : 0,
			'enable_course_type_filter' => isset( $in['enable_course_type_filter'] ) ? 1 : 0,
			'enable_tutoring_format_filter' => isset( $in['enable_tutoring_format_filter'] ) ? 1 : 0,
			'default_sort' => $default_sort,
			'replace_suggestions' => isset( $in['replace_suggestions'] ) ? 1 : 0,
			'suggest_limit' => isset( $in['suggest_limit'] ) ? max( 1, min( 20, absint( $in['suggest_limit'] ) ) ) : 8,
			'enable_multilingual_search' => isset( $in['enable_multilingual_search'] ) ? 1 : 0,
			'enable_live_term_counts' => isset( $in['enable_live_term_counts'] ) ? 1 : 0,
			'show_result_count' => isset( $in['show_result_count'] ) ? 1 : 0,
			'show_sponsored_carousel' => isset( $in['show_sponsored_carousel'] ) ? 1 : 0,
			'show_verified_sticker' => isset( $in['show_verified_sticker'] ) ? 1 : 0,
			'sponsored_carousel_items' => isset( $in['sponsored_carousel_items'] ) ? max( 3, min( 12, absint( $in['sponsored_carousel_items'] ) ) ) : 8,
			'enable_tutor_search' => isset( $in['enable_tutor_search'] ) ? 1 : 0,
			'tutor_page_slug' => isset( $in['tutor_page_slug'] ) && ! is_array( $in['tutor_page_slug'] ) ? sanitize_title( $in['tutor_page_slug'] ) : 'tutors',
			'tutor_per_page' => isset( $in['tutor_per_page'] ) ? max( 6, min( 48, absint( $in['tutor_per_page'] ) ) ) : 12,
			'tutor_carousel_items' => isset( $in['tutor_carousel_items'] ) ? max( 3, min( 12, absint( $in['tutor_carousel_items'] ) ) ) : 8,
			'show_tutor_carousel' => isset( $in['show_tutor_carousel'] ) ? 1 : 0,
			'show_tutor_result_count' => isset( $in['show_tutor_result_count'] ) ? 1 : 0,
			'tutor_card_verified' => isset( $in['tutor_card_verified'] ) ? 1 : 0,
			'tutor_card_bio' => isset( $in['tutor_card_bio'] ) ? 1 : 0,
			'tutor_card_job_title' => isset( $in['tutor_card_job_title'] ) ? 1 : 0,
			'tutor_card_categories' => isset( $in['tutor_card_categories'] ) ? 1 : 0,
			'tutor_card_qualification' => isset( $in['tutor_card_qualification'] ) ? 1 : 0,
			'tutor_card_tutoring_types' => isset( $in['tutor_card_tutoring_types'] ) ? 1 : 0,
			'tutor_card_languages' => isset( $in['tutor_card_languages'] ) ? 1 : 0,
			'tutor_card_location' => isset( $in['tutor_card_location'] ) ? 1 : 0,
			'tutor_card_experience' => isset( $in['tutor_card_experience'] ) ? 1 : 0,
			'tutor_card_students' => isset( $in['tutor_card_students'] ) ? 1 : 0,
			'tutor_card_courses' => isset( $in['tutor_card_courses'] ) ? 1 : 0,
			'tutor_card_reviews' => isset( $in['tutor_card_reviews'] ) ? 1 : 0,
			'tutor_card_rating' => isset( $in['tutor_card_rating'] ) ? 1 : 0,
			'tutor_card_avatar' => isset( $in['tutor_card_avatar'] ) ? 1 : 0,
			'enable_tutor_experience_filter' => isset( $in['enable_tutor_experience_filter'] ) ? 1 : 0,
			'enable_tutor_course_type_filter' => isset( $in['enable_tutor_course_type_filter'] ) ? 1 : 0,
			'hidden_tutor_ids' => $this->sanitize_tutor_ids( isset( $in['hidden_tutor_ids'] ) ? $in['hidden_tutor_ids'] : array() ),
			'enable_tutor_teaching_category_filter' => isset( $in['enable_tutor_teaching_category_filter'] ) ? 1 : 0,
			'enable_tutor_private_sessions_filter' => isset( $in['enable_tutor_private_sessions_filter'] ) ? 1 : 0,
			'enable_tutor_municipality_filter' => isset( $in['enable_tutor_municipality_filter'] ) ? 1 : 0,
			'enable_tutor_wilaya_filter' => isset( $in['enable_tutor_wilaya_filter'] ) ? 1 : 0,
			'enable_tutor_tutoring_type_filter' => isset( $in['enable_tutor_tutoring_type_filter'] ) ? 1 : 0,
			'enable_tutor_language_filter' => isset( $in['enable_tutor_language_filter'] ) ? 1 : 0,
			'enable_tutor_verified_filter' => isset( $in['enable_tutor_verified_filter'] ) ? 1 : 0,
			'enable_tutor_courses_filter' => isset( $in['enable_tutor_courses_filter'] ) ? 1 : 0,
			'enable_tutor_students_filter' => isset( $in['enable_tutor_students_filter'] ) ? 1 : 0,
			'enable_tutor_rating_filter' => isset( $in['enable_tutor_rating_filter'] ) ? 1 : 0,
		);
		update_option( self::OPTION, $out ); $this->cache = null;
		add_settings_error( 'tcsp', 'saved', __( 'Settings saved.', 'tutor-learning-suite' ), 'updated' );
	}

	public function update_sponsorship_plans( $course_raw, $tutor_raw ) {
		$all = $this->all();
		$current_course = isset( $all['promotion_tiers'] ) && is_array( $all['promotion_tiers'] ) ? $all['promotion_tiers'] : array();
		$current_tutor = isset( $all['tutor_promotion_tiers'] ) && is_array( $all['tutor_promotion_tiers'] ) ? $all['tutor_promotion_tiers'] : array();

		// Merge submitted fields into the existing plan definitions instead of
		// rebuilding missing fields from defaults. This prevents an unrelated save
		// (or an older/partial form) from silently resetting an existing manager,
		// price, duration, priority, exposure, colour, or description.
		$course_raw = is_array( $course_raw ) ? $course_raw : array();
		$tutor_raw  = is_array( $tutor_raw ) ? $tutor_raw : array();
		$merged_course = $current_course;
		$merged_tutor  = $current_tutor;
		foreach ( $course_raw as $key => $values ) {
			$key = sanitize_key( $key );
			if ( ! is_array( $values ) ) continue;
			$merged_course[ $key ] = array_merge( isset( $merged_course[ $key ] ) && is_array( $merged_course[ $key ] ) ? $merged_course[ $key ] : array(), $values );
		}
		foreach ( $tutor_raw as $key => $values ) {
			$key = sanitize_key( $key );
			if ( ! is_array( $values ) ) continue;
			$merged_tutor[ $key ] = array_merge( isset( $merged_tutor[ $key ] ) && is_array( $merged_tutor[ $key ] ) ? $merged_tutor[ $key ] : array(), $values );
		}

		$all['promotion_tiers'] = $this->sanitize_promotion_tiers( $merged_course, 'course' );
		$all['tutor_promotion_tiers'] = $this->sanitize_promotion_tiers( $merged_tutor, 'tutor' );
		update_option( self::OPTION, $all, false );
		$this->cache = $all;
	}

	public function promotion_tiers() {
		$saved = $this->all(); $defaults = $this->defaults()['promotion_tiers'];
		$tiers = isset( $saved['promotion_tiers'] ) && is_array( $saved['promotion_tiers'] ) ? $saved['promotion_tiers'] : array();
		return $this->sanitize_promotion_tiers( wp_parse_args( $tiers, $defaults ) );
	}

	public function tutor_promotion_tiers() {
		$saved = $this->all();
		$defaults = $this->defaults()['tutor_promotion_tiers'];
		$tiers = isset( $saved['tutor_promotion_tiers'] ) && is_array( $saved['tutor_promotion_tiers'] ) ? $saved['tutor_promotion_tiers'] : array();
		return $this->sanitize_promotion_tiers( wp_parse_args( $tiers, $defaults ), 'tutor' );
	}

	private function sanitize_promotion_tiers( $raw, $type = 'course' ) {
		$defaults = $this->defaults()[ 'tutor' === $type ? 'tutor_promotion_tiers' : 'promotion_tiers' ]; $out = array();
		foreach ( $defaults as $key => $default ) {
			$value = isset( $raw[ $key ] ) && is_array( $raw[ $key ] ) ? $raw[ $key ] : array();
			$label = isset( $value['label'] ) && ! is_array( $value['label'] ) ? sanitize_text_field( $value['label'] ) : $default['label'];
			$color = isset( $value['color'] ) && ! is_array( $value['color'] ) ? sanitize_text_field( $value['color'] ) : $default['color'];
			$description = isset( $value['description'] ) && ! is_array( $value['description'] ) ? sanitize_textarea_field( $value['description'] ) : ( isset( $default['description'] ) ? $default['description'] : '' );
			$out[ $key ] = array(
				'label' => '' !== $label ? $label : $default['label'],
				'description' => '' !== $description ? $description : ( isset( $default['description'] ) ? $default['description'] : '' ),
				'weight' => isset( $value['weight'] ) && ! is_array( $value['weight'] ) ? min( 1000, absint( $value['weight'] ) ) : $default['weight'],
				'exposure' => isset( $value['exposure'] ) && ! is_array( $value['exposure'] ) ? min( 20, max( 1, absint( $value['exposure'] ) ) ) : ( isset( $default['exposure'] ) ? $default['exposure'] : 1 ),
				'color' => preg_match( '/^#[0-9a-fA-F]{6}$/', $color ) ? $color : $default['color'],
				'price' => isset( $value['price'] ) && ! is_array( $value['price'] ) ? max( 0, (float) $value['price'] ) : (float) ( $default['price'] ?? 0 ),
				'duration' => isset( $value['duration'] ) && ! is_array( $value['duration'] ) ? max( 1, min( 3650, absint( $value['duration'] ) ) ) : (int) ( $default['duration'] ?? 30 ),
				'discount_3' => isset( $value['discount_3'] ) && ! is_array( $value['discount_3'] ) ? min( 100, max( 0, (float) $value['discount_3'] ) ) : 0,
				'discount_6' => isset( $value['discount_6'] ) && ! is_array( $value['discount_6'] ) ? min( 100, max( 0, (float) $value['discount_6'] ) ) : 0,
				'discount_12' => isset( $value['discount_12'] ) && ! is_array( $value['discount_12'] ) ? min( 100, max( 0, (float) $value['discount_12'] ) ) : 0,
			);
			if ( 'course' === $type ) {
				$out[ $key ]['manager_id'] = isset( $value['manager_id'] ) && ! is_array( $value['manager_id'] ) ? absint( $value['manager_id'] ) : absint( $default['manager_id'] ?? 0 );
			}
		}
		return $out;
	}

	/**
	 * Return administrator-selected tutors hidden from public Tutor/course search.
	 *
	 * @return int[]
	 */
	public function hidden_tutor_ids() {
		$value = $this->get( 'hidden_tutor_ids' );
		return $this->sanitize_tutor_ids( $value );
	}

	/**
	 * Sanitize selected tutor IDs and keep only real Tutor instructors.
	 *
	 * @param mixed $raw Raw submitted IDs.
	 * @return int[]
	 */
	private function sanitize_tutor_ids( $raw ) {
		$ids = is_array( $raw ) ? $raw : array();
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		$users = get_users( array(
			'include' => $ids,
			'role'    => 'tutor_instructor',
			'fields'  => 'ID',
			'number'  => count( $ids ),
		) );
		return array_values( array_unique( array_map( 'absint', (array) $users ) ) );
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		$s = $this->all(); settings_errors( 'tcsp' );
		?>
		<div class="wrap tcsp-settings-only">
			<h1><?php esc_html_e( 'Tutor Course Search Pro', 'tutor-learning-suite' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Configure search, filtering, sorting, and sponsorship ranking. Course sponsorship assignments are managed separately under Sponsorship Manager.', 'tutor-learning-suite' ); ?></p>
			<form method="post">
				<?php wp_nonce_field( self::NONCE, 'tcsp_nonce' ); ?>
				<h2 class="title"><?php esc_html_e( 'Sponsored Course Ranking', 'tutor-learning-suite' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th><?php esc_html_e( 'Boost sponsored courses', 'tutor-learning-suite' ); ?></th><td><label><input type="checkbox" name="promote_courses" value="1" <?php checked( $s['promote_courses'], 1 ); ?>> <?php esc_html_e( 'Use tier-weight sponsorship boosting only when the dedicated sponsored carousel is disabled.', 'tutor-learning-suite' ); ?></label></td></tr>
					<tr><th><?php esc_html_e( 'Sponsorship plans', 'tutor-learning-suite' ); ?></th><td><p class="description"><?php esc_html_e( 'Course and tutor sponsorship plans, including prices, are configured on Tutor LMS &gt; Sponsorship.', 'tutor-learning-suite' ); ?></p></td></tr>
				<tr><th><?php esc_html_e( 'Verified Instructor sticker', 'tutor-learning-suite' ); ?></th><td><label><input type="checkbox" name="show_verified_sticker" value="1" <?php checked( $s['show_verified_sticker'], 1 ); ?>> <?php esc_html_e( 'Show the ✓Verified Instructor sticker on course cards for verified instructors.', 'tutor-learning-suite' ); ?></label></td></tr>
				<tr><th><?php esc_html_e( 'Maximum sponsorship influence', 'tutor-learning-suite' ); ?></th><td><input type="number" min="0" max="250" name="sponsorship_boost_cap" value="<?php echo esc_attr( $s['sponsorship_boost_cap'] ); ?>" class="small-text"> <span class="description"><?php esc_html_e( 'Legacy relevance boost used only when the dedicated sponsored carousel is disabled.', 'tutor-learning-suite' ); ?></span></td></tr>
				<tr><th><?php esc_html_e( 'Boost paid courses', 'tutor-learning-suite' ); ?></th><td><label><input type="checkbox" name="promote_paid_courses" value="1" <?php checked( $s['promote_paid_courses'], 1 ); ?>> <?php esc_html_e( 'Give paid courses a secondary boost over free ones.', 'tutor-learning-suite' ); ?></label><p><input type="number" min="0" max="1000" name="paid_course_weight" value="<?php echo esc_attr( $s['paid_course_weight'] ); ?>" class="small-text"> <span class="description"><?php esc_html_e( 'Maximum secondary boost for paid courses on relevance searches.', 'tutor-learning-suite' ); ?></span></p></td></tr>
				</table>
				<h2 class="title"><?php esc_html_e( 'Search Visibility', 'tutor-learning-suite' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th><?php esc_html_e( 'Hide tutors from search', 'tutor-learning-suite' ); ?></th><td>
					<?php
					$hidden_tutors = $this->hidden_tutor_ids();
					$hidden_users = get_users( array( 'role' => 'tutor_instructor', 'number' => 0, 'orderby' => 'display_name', 'order' => 'ASC' ) );
					?>
					<select name="hidden_tutor_ids[]" multiple size="8" style="min-width:420px;max-width:100%;">
						<?php foreach ( (array) $hidden_users as $u ) : ?>
							<option value="<?php echo (int) $u->ID; ?>" <?php selected( in_array( (int) $u->ID, $hidden_tutors, true ), true ); ?>><?php echo esc_html( $u->display_name . ' (@' . $u->user_login . ')' ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Selected tutors are removed from the TCSP Tutors search page. All courses authored by a selected tutor are also hidden from TCSP course search, including the sponsored course carousel. Multi-instructor courses linked to a selected tutor are hidden as well.', 'tutor-learning-suite' ); ?></p>
					</td></tr>
				</table>
				<h2 class="title"><?php esc_html_e( 'Archive Filters & Sorting', 'tutor-learning-suite' ); ?></h2>
				<table class="form-table" role="presentation"><tr><th><?php esc_html_e( 'Enabled filters', 'tutor-learning-suite' ); ?></th><td>
					<?php foreach ( array( 'enable_category_filter' => 'Category (multi-select)', 'enable_tag_filter' => 'Tag (multi-select)', 'enable_rating_filter' => 'Minimum rating', 'enable_verified_filter' => 'Verified instructor', 'enable_students_filter' => 'Minimum number of students', 'enable_spoken_language_filter' => 'Spoken languages (multi-select)', 'enable_tutoring_type_filter' => 'Private tutoring type (multi-select)', 'enable_wilaya_filter' => 'Wilaya', 'enable_municipality_filter' => 'Municipality', 'enable_course_type_filter' => 'Course type', 'enable_tutoring_format_filter' => 'Tutoring format (Group / 1v1)', 'enable_price_filter' => 'Price (Free / Paid)', 'enable_level_filter' => 'Difficulty level' ) as $key => $label ) : ?><label style="display:block"><input type="checkbox" name="<?php echo esc_attr( $key ); ?>" value="1" <?php checked( $s[ $key ], 1 ); ?>> <?php echo esc_html__( $label, 'tutor-learning-suite' ); ?></label><?php endforeach; ?>
				</td></tr><tr><th><label for="tcsp_default_sort"><?php esc_html_e( 'Default sort', 'tutor-learning-suite' ); ?></label></th><td><select id="tcsp_default_sort" name="default_sort"><?php foreach ( $this->sort_options() as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $s['default_sort'], $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></td></tr></table>
				<h2 class="title"><?php esc_html_e( 'Multilingual Search', 'tutor-learning-suite' ); ?></h2>
				<table class="form-table" role="presentation"><tr><th><?php esc_html_e( 'Search across all languages', 'tutor-learning-suite' ); ?></th><td><label><input type="checkbox" name="enable_multilingual_search" value="1" <?php checked( $s['enable_multilingual_search'], 1 ); ?>> <?php esc_html_e( 'When TranslatePress is active, also match a course if the search term appears in ANY of its translations (title, description, or category/tag names) — not only the site\'s default language.', 'tutor-learning-suite' ); ?></label>
				<?php if ( ! TCSP_Multilingual::trp_active() ) : ?><p class="description"><?php esc_html_e( 'TranslatePress was not detected on this site — this option has no effect until it is active.', 'tutor-learning-suite' ); ?></p><?php endif; ?>
				</td></tr></table>
				<h2 class="title"><?php esc_html_e( 'Sponsored Carousel', 'tutor-learning-suite' ); ?></h2>
				<table class="form-table" role="presentation"><tr><th><?php esc_html_e( 'Show sponsored carousel', 'tutor-learning-suite' ); ?></th><td><label><input type="checkbox" name="show_sponsored_carousel" value="1" <?php checked( $s['show_sponsored_carousel'], 1 ); ?>> <?php esc_html_e( 'Show matching active sponsored courses in a separate carousel above the normal course results.', 'tutor-learning-suite' ); ?></label></td></tr><tr><th><label for="tcsp_sponsored_carousel_items"><?php esc_html_e( 'Maximum carousel courses', 'tutor-learning-suite' ); ?></label></th><td><input type="number" min="3" max="12" id="tcsp_sponsored_carousel_items" name="sponsored_carousel_items" value="<?php echo esc_attr( $s['sponsored_carousel_items'] ); ?>" class="small-text"> <span class="description"><?php esc_html_e( 'The carousel only shows active sponsored courses that match the current search and filters. Empty slots are never shown.', 'tutor-learning-suite' ); ?></span></td></tr></table>
<h2 class="title"><?php esc_html_e( 'Tutor Search Page', 'tutor-learning-suite' ); ?></h2>
<table class="form-table" role="presentation">
<tr><th><?php esc_html_e( 'Enable tutor search', 'tutor-learning-suite' ); ?></th><td><label><input type="checkbox" name="enable_tutor_search" value="1" <?php checked( $s['enable_tutor_search'], 1 ); ?>> <?php esc_html_e( 'Enable the dedicated tutor directory/search page. The default page uses the [tcsp_tutor_search] shortcode.', 'tutor-learning-suite' ); ?></label></td></tr>
<tr><th><label for="tcsp_tutor_page_slug"><?php esc_html_e( 'Tutor page slug', 'tutor-learning-suite' ); ?></label></th><td><input id="tcsp_tutor_page_slug" type="text" name="tutor_page_slug" value="<?php echo esc_attr( $s['tutor_page_slug'] ); ?>" class="regular-text"> <span class="description"><?php esc_html_e( 'Used when the plugin creates its default Tutors page.', 'tutor-learning-suite' ); ?></span></td></tr>
<tr><th><label for="tcsp_tutor_per_page"><?php esc_html_e( 'Tutors per page', 'tutor-learning-suite' ); ?></label></th><td><input id="tcsp_tutor_per_page" type="number" min="6" max="48" name="tutor_per_page" value="<?php echo esc_attr( $s['tutor_per_page'] ); ?>" class="small-text"></td></tr>
<tr><th><?php esc_html_e( 'Show tutor result count', 'tutor-learning-suite' ); ?></th><td><label><input type="checkbox" name="show_tutor_result_count" value="1" <?php checked( $s['show_tutor_result_count'], 1 ); ?>> <?php esc_html_e( 'Display the number of tutors matching the current search and filters. The count is calculated from the same result set shown below, without an extra counting query.', 'tutor-learning-suite' ); ?></label></td></tr>
			<tr><th><?php esc_html_e( 'Tutor filters', 'tutor-learning-suite' ); ?></th><td><?php foreach ( array( 'enable_tutor_rating_filter' => 'Minimum rating', 'enable_tutor_students_filter' => 'Minimum students', 'enable_tutor_courses_filter' => 'Minimum courses', 'enable_tutor_verified_filter' => 'Verified tutor', 'enable_tutor_language_filter' => 'Spoken language', 'enable_tutor_tutoring_type_filter' => 'Tutoring type', 'enable_tutor_wilaya_filter' => 'Wilaya', 'enable_tutor_municipality_filter' => 'Municipality', 'enable_tutor_private_sessions_filter' => 'Accept private sessions', 'enable_tutor_teaching_category_filter' => 'Teaching category', 'enable_tutor_course_type_filter' => 'Course type', 'enable_tutor_experience_filter' => 'Minimum experience' ) as $key => $label ) : ?><label style="display:block"><input type="checkbox" name="<?php echo esc_attr( $key ); ?>" value="1" <?php checked( $s[ $key ], 1 ); ?>> <?php echo esc_html( $label ); ?></label><?php endforeach; ?></td></tr>
<tr><th><?php esc_html_e( 'Tutor sorting', 'tutor-learning-suite' ); ?></th><td><p class="description"><?php esc_html_e( 'The tutor page supports relevance, highest rating, most students, most courses, most experience, and newest profile.', 'tutor-learning-suite' ); ?></p></td></tr>
</table>
<h2 class="title"><?php esc_html_e( 'Tutor Sponsored Carousel', 'tutor-learning-suite' ); ?></h2>
<table class="form-table" role="presentation"><tr><th><?php esc_html_e( 'Show sponsored tutors', 'tutor-learning-suite' ); ?></th><td><label><input type="checkbox" name="show_tutor_carousel" value="1" <?php checked( $s['show_tutor_carousel'], 1 ); ?>> <?php esc_html_e( 'Show active sponsored tutors above normal tutor results.', 'tutor-learning-suite' ); ?></label></td></tr><tr><th><label for="tcsp_tutor_carousel_items"><?php esc_html_e( 'Maximum sponsored tutors', 'tutor-learning-suite' ); ?></label></th><td><input id="tcsp_tutor_carousel_items" type="number" min="3" max="12" name="tutor_carousel_items" value="<?php echo esc_attr( $s['tutor_carousel_items'] ); ?>" class="small-text"></td></tr></table>
<h2 class="title"><?php esc_html_e( 'Tutor Sponsorship Plans', 'tutor-learning-suite' ); ?></h2>
<p class="description"><?php esc_html_e( 'Tutor profile sponsorship plans are configured on Tutor LMS &gt; Sponsorship.', 'tutor-learning-suite' ); ?></p>
<h2 class="title"><?php esc_html_e( 'Tutor Card Information', 'tutor-learning-suite' ); ?></h2>
<table class="form-table" role="presentation"><tr><th><?php esc_html_e( 'Fields shown on tutor cards', 'tutor-learning-suite' ); ?></th><td><?php foreach ( array( 'tutor_card_avatar' => 'Photo / avatar', 'tutor_card_rating' => 'Rating', 'tutor_card_reviews' => 'Review count', 'tutor_card_courses' => 'Course count', 'tutor_card_students' => 'Student count', 'tutor_card_experience' => 'Experience', 'tutor_card_languages' => 'Spoken languages', 'tutor_card_tutoring_types' => 'Tutoring types', 'tutor_card_qualification' => 'Qualification', 'tutor_card_verified' => 'Verified badge', 'tutor_card_bio' => 'Short bio', 'tutor_card_job_title' => 'Skill / Occupation', 'tutor_card_categories' => 'What I can teach (parent categories)' ) as $key => $label ) : ?><label style="display:block"><input type="checkbox" name="<?php echo esc_attr( $key ); ?>" value="1" <?php checked( $s[ $key ], 1 ); ?>> <?php echo esc_html( $label ); ?></label><?php endforeach; ?></td></tr></table>
				<h2 class="title"><?php esc_html_e( 'Result Counts', 'tutor-learning-suite' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th><?php esc_html_e( 'Show result count', 'tutor-learning-suite' ); ?></th><td><label><input type="checkbox" name="show_result_count" value="1" <?php checked( $s['show_result_count'], 1 ); ?>> <?php esc_html_e( 'Display "N courses found" above the results, reflecting the current search/filters.', 'tutor-learning-suite' ); ?></label></td></tr>
					<tr><th><?php esc_html_e( 'Live filter counts', 'tutor-learning-suite' ); ?></th><td><label><input type="checkbox" name="enable_live_term_counts" value="1" <?php checked( $s['enable_live_term_counts'], 1 ); ?>> <?php esc_html_e( 'Show, next to every filter option (category, tag, price, level, rating, students, verified instructor, language, tutoring type, wilaya, municipality, course type, and tutoring format), how many courses match if it were selected together with the other active filters — instead of a static site-wide total. Recalculates whenever a filter is applied.', 'tutor-learning-suite' ); ?></label>
					<p class="description"><?php esc_html_e( 'Recalculates on every filter change (one lightweight query per option shown). Turn off on very large catalogs if the filter bar feels slow.', 'tutor-learning-suite' ); ?></p>
					</td></tr>
				</table>
				<h2 class="title"><?php esc_html_e( 'Search Suggestions', 'tutor-learning-suite' ); ?></h2>
				<table class="form-table" role="presentation"><tr><th><?php esc_html_e( 'Replace default suggestions', 'tutor-learning-suite' ); ?></th><td><label><input type="checkbox" name="replace_suggestions" value="1" <?php checked( $s['replace_suggestions'], 1 ); ?>> <?php esc_html_e( 'Use the enhanced autocomplete.', 'tutor-learning-suite' ); ?></label></td></tr><tr><th><label for="tcsp_suggest_limit"><?php esc_html_e( 'Suggestions shown', 'tutor-learning-suite' ); ?></label></th><td><input type="number" min="1" max="20" id="tcsp_suggest_limit" name="suggest_limit" value="<?php echo esc_attr( $s['suggest_limit'] ); ?>" class="small-text"></td></tr></table>
				<?php submit_button( __( 'Save Changes', 'tutor-learning-suite' ), 'primary', 'tcsp_settings_submit' ); ?>
			</form>
		</div>
		<style>.tcsp-settings-only .tcsp-tier-grid{display:flex;flex-wrap:wrap;gap:12px}.tcsp-settings-only .tcsp-tier-card{box-sizing:border-box;width:220px;padding:14px;border:1px solid #dcdcde;border-top:4px solid;border-radius:6px;background:#fff}.tcsp-settings-only .tcsp-tier-card>strong{display:block;font-size:15px}.tcsp-settings-only .tcsp-tier-card code{display:block;color:#646970;margin:4px 0 10px}.tcsp-settings-only .tcsp-tier-card label{display:block;margin-top:8px;font-size:12px;font-weight:600}.tcsp-settings-only .tcsp-tier-card input{display:block;width:100%;margin-top:3px}</style>
		<?php
	}

	public function sort_options() {
		return array(
			'relevance' => __( 'Relevance (recommended)', 'tutor-learning-suite' ),
			'newest_first' => __( 'Newest first', 'tutor-learning-suite' ),
			'oldest_first' => __( 'Oldest first', 'tutor-learning-suite' ),
			'course_title_az' => __( 'Title A–Z', 'tutor-learning-suite' ),
			'course_title_za' => __( 'Title Z–A', 'tutor-learning-suite' ),
			'price_low_high' => __( 'Price: low to high', 'tutor-learning-suite' ),
			'price_high_low' => __( 'Price: high to low', 'tutor-learning-suite' ),
			'top_rated' => __( 'Top rated', 'tutor-learning-suite' ),
			'most_students' => __( 'Most students', 'tutor-learning-suite' ),
		);
	}
}
