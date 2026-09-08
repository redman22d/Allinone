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
				'platinum' => array( 'label' => 'Premium', 'description' => 'Maximum sponsored exposure, highest carousel priority, most frequent rotation, and priority access to featured visibility.', 'weight' => 300, 'exposure' => 6, 'color' => '#7c3aed' ),
				'gold' => array( 'label' => 'Pro', 'description' => 'Higher carousel priority, more frequent rotation than Basic, and priority sponsored visibility.', 'weight' => 180, 'exposure' => 3, 'color' => '#b7791f' ),
				'featured' => array( 'label' => 'Basic', 'description' => 'Entry sponsored visibility in the relevant-course carousel with standard rotation and standard sponsored placement.', 'weight' => 80, 'exposure' => 1, 'color' => '#2563eb' ),
			),
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
		add_submenu_page( 'tutor', __( 'Course Search Pro', 'tutor-course-search-pro' ), __( 'Course Search Pro', 'tutor-course-search-pro' ), 'manage_options', 'tcsp-settings', array( $this, 'render_page' ) );
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
			'promotion_tiers' => $this->sanitize_promotion_tiers( isset( $in['promotion_tiers'] ) ? $in['promotion_tiers'] : array() ),
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
		);
		update_option( self::OPTION, $out ); $this->cache = null;
		add_settings_error( 'tcsp', 'saved', __( 'Settings saved.', 'tutor-course-search-pro' ), 'updated' );
	}

	public function promotion_tiers() {
		$saved = $this->all(); $defaults = $this->defaults()['promotion_tiers'];
		$tiers = isset( $saved['promotion_tiers'] ) && is_array( $saved['promotion_tiers'] ) ? $saved['promotion_tiers'] : array();
		return $this->sanitize_promotion_tiers( wp_parse_args( $tiers, $defaults ) );
	}

	private function sanitize_promotion_tiers( $raw ) {
		$defaults = $this->defaults()['promotion_tiers']; $out = array();
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
			);
		}
		return $out;
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		$s = $this->all(); settings_errors( 'tcsp' );
		?>
		<div class="wrap tcsp-settings-only">
			<h1><?php esc_html_e( 'Tutor Course Search Pro', 'tutor-course-search-pro' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Configure search, filtering, sorting, and sponsorship ranking. Course sponsorship assignments are managed separately under Sponsorship Manager.', 'tutor-course-search-pro' ); ?></p>
			<form method="post">
				<?php wp_nonce_field( self::NONCE, 'tcsp_nonce' ); ?>
				<h2 class="title"><?php esc_html_e( 'Sponsored Course Ranking', 'tutor-course-search-pro' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th><?php esc_html_e( 'Boost sponsored courses', 'tutor-course-search-pro' ); ?></th><td><label><input type="checkbox" name="promote_courses" value="1" <?php checked( $s['promote_courses'], 1 ); ?>> <?php esc_html_e( 'Use tier-weight sponsorship boosting only when the dedicated sponsored carousel is disabled.', 'tutor-course-search-pro' ); ?></label></td></tr>
					<tr><th><?php esc_html_e( 'Sponsorship tiers', 'tutor-course-search-pro' ); ?></th><td><div class="tcsp-tier-grid">
					<?php foreach ( $this->promotion_tiers() as $key => $tier ) : ?><div class="tcsp-tier-card" style="border-top-color:<?php echo esc_attr( $tier['color'] ); ?>"><div class="tcsp-tier-card-head"><strong><?php echo esc_html( $tier['label'] ); ?></strong><code><?php echo esc_html( $key ); ?></code></div><label><?php esc_html_e( 'Plan name', 'tutor-course-search-pro' ); ?><input type="text" name="promotion_tiers[<?php echo esc_attr( $key ); ?>][label]" value="<?php echo esc_attr( $tier['label'] ); ?>" maxlength="60"></label><label><?php esc_html_e( 'What the customer gets / plan features', 'tutor-course-search-pro' ); ?><textarea name="promotion_tiers[<?php echo esc_attr( $key ); ?>][description]" rows="4" maxlength="400" placeholder="e.g. Featured in sponsored carousel, higher exposure, priority placement…"><?php echo esc_textarea( isset( $tier['description'] ) ? $tier['description'] : '' ); ?></textarea><span class="description"><?php esc_html_e( 'Write the customer-facing benefits of this plan. This text is shown in the sponsorship controls as the plan explanation.', 'tutor-course-search-pro' ); ?></span></label><div class="tcsp-tier-card-fields"><label><?php esc_html_e( 'Priority weight', 'tutor-course-search-pro' ); ?><input type="number" min="0" max="1000" name="promotion_tiers[<?php echo esc_attr( $key ); ?>][weight]" value="<?php echo esc_attr( $tier['weight'] ); ?>"></label><label><?php esc_html_e( 'Exposure multiplier', 'tutor-course-search-pro' ); ?><input type="number" min="1" max="20" name="promotion_tiers[<?php echo esc_attr( $key ); ?>][exposure]" value="<?php echo esc_attr( isset( $tier['exposure'] ) ? $tier['exposure'] : 1 ); ?>"></label><label><?php esc_html_e( 'Badge color', 'tutor-course-search-pro' ); ?><input type="text" name="promotion_tiers[<?php echo esc_attr( $key ); ?>][color]" value="<?php echo esc_attr( $tier['color'] ); ?>" pattern="#[0-9a-fA-F]{6}"></label></div></div><?php endforeach; ?>
					</div><p class="description"><?php esc_html_e( 'Plan weight strictly orders different sponsorship plans in the carousel: a higher-weight plan is always considered before a lower-weight plan. Courses with the same weight are randomly rotated. Exposure multiplier describes the intended exposure advantage of the plan and is retained for compatibility with earlier configurations.', 'tutor-course-search-pro' ); ?></p></td></tr>
				<tr><th><?php esc_html_e( 'Verified Instructor sticker', 'tutor-course-search-pro' ); ?></th><td><label><input type="checkbox" name="show_verified_sticker" value="1" <?php checked( $s['show_verified_sticker'], 1 ); ?>> <?php esc_html_e( 'Show the ✓Verified Instructor sticker on course cards for verified instructors.', 'tutor-course-search-pro' ); ?></label></td></tr>
				<tr><th><?php esc_html_e( 'Maximum sponsorship influence', 'tutor-course-search-pro' ); ?></th><td><input type="number" min="0" max="250" name="sponsorship_boost_cap" value="<?php echo esc_attr( $s['sponsorship_boost_cap'] ); ?>" class="small-text"> <span class="description"><?php esc_html_e( 'Legacy relevance boost used only when the dedicated sponsored carousel is disabled.', 'tutor-course-search-pro' ); ?></span></td></tr>
				<tr><th><?php esc_html_e( 'Boost paid courses', 'tutor-course-search-pro' ); ?></th><td><label><input type="checkbox" name="promote_paid_courses" value="1" <?php checked( $s['promote_paid_courses'], 1 ); ?>> <?php esc_html_e( 'Give paid courses a secondary boost over free ones.', 'tutor-course-search-pro' ); ?></label><p><input type="number" min="0" max="1000" name="paid_course_weight" value="<?php echo esc_attr( $s['paid_course_weight'] ); ?>" class="small-text"> <span class="description"><?php esc_html_e( 'Maximum secondary boost for paid courses on relevance searches.', 'tutor-course-search-pro' ); ?></span></p></td></tr>
				</table>
				<h2 class="title"><?php esc_html_e( 'Archive Filters & Sorting', 'tutor-course-search-pro' ); ?></h2>
				<table class="form-table" role="presentation"><tr><th><?php esc_html_e( 'Enabled filters', 'tutor-course-search-pro' ); ?></th><td>
					<?php foreach ( array( 'enable_category_filter' => 'Category (multi-select)', 'enable_tag_filter' => 'Tag (multi-select)', 'enable_rating_filter' => 'Minimum rating', 'enable_verified_filter' => 'Verified instructor', 'enable_students_filter' => 'Minimum number of students', 'enable_spoken_language_filter' => 'Spoken languages (multi-select)', 'enable_tutoring_type_filter' => 'Private tutoring type (multi-select)', 'enable_wilaya_filter' => 'Wilaya', 'enable_municipality_filter' => 'Municipality', 'enable_course_type_filter' => 'Course type', 'enable_tutoring_format_filter' => 'Tutoring format (Group / 1v1)', 'enable_price_filter' => 'Price (Free / Paid)', 'enable_level_filter' => 'Difficulty level' ) as $key => $label ) : ?><label style="display:block"><input type="checkbox" name="<?php echo esc_attr( $key ); ?>" value="1" <?php checked( $s[ $key ], 1 ); ?>> <?php echo esc_html__( $label, 'tutor-course-search-pro' ); ?></label><?php endforeach; ?>
				</td></tr><tr><th><label for="tcsp_default_sort"><?php esc_html_e( 'Default sort', 'tutor-course-search-pro' ); ?></label></th><td><select id="tcsp_default_sort" name="default_sort"><?php foreach ( $this->sort_options() as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $s['default_sort'], $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></td></tr></table>
				<h2 class="title"><?php esc_html_e( 'Multilingual Search', 'tutor-course-search-pro' ); ?></h2>
				<table class="form-table" role="presentation"><tr><th><?php esc_html_e( 'Search across all languages', 'tutor-course-search-pro' ); ?></th><td><label><input type="checkbox" name="enable_multilingual_search" value="1" <?php checked( $s['enable_multilingual_search'], 1 ); ?>> <?php esc_html_e( 'When TranslatePress is active, also match a course if the search term appears in ANY of its translations (title, description, or category/tag names) — not only the site\'s default language.', 'tutor-course-search-pro' ); ?></label>
				<?php if ( ! TCSP_Multilingual::trp_active() ) : ?><p class="description"><?php esc_html_e( 'TranslatePress was not detected on this site — this option has no effect until it is active.', 'tutor-course-search-pro' ); ?></p><?php endif; ?>
				</td></tr></table>
				<h2 class="title"><?php esc_html_e( 'Sponsored Carousel', 'tutor-course-search-pro' ); ?></h2>
				<table class="form-table" role="presentation"><tr><th><?php esc_html_e( 'Show sponsored carousel', 'tutor-course-search-pro' ); ?></th><td><label><input type="checkbox" name="show_sponsored_carousel" value="1" <?php checked( $s['show_sponsored_carousel'], 1 ); ?>> <?php esc_html_e( 'Show matching active sponsored courses in a separate carousel above the normal course results.', 'tutor-course-search-pro' ); ?></label></td></tr><tr><th><label for="tcsp_sponsored_carousel_items"><?php esc_html_e( 'Maximum carousel courses', 'tutor-course-search-pro' ); ?></label></th><td><input type="number" min="3" max="12" id="tcsp_sponsored_carousel_items" name="sponsored_carousel_items" value="<?php echo esc_attr( $s['sponsored_carousel_items'] ); ?>" class="small-text"> <span class="description"><?php esc_html_e( 'The carousel only shows active sponsored courses that match the current search and filters. Empty slots are never shown.', 'tutor-course-search-pro' ); ?></span></td></tr></table>
				<h2 class="title"><?php esc_html_e( 'Result Counts', 'tutor-course-search-pro' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th><?php esc_html_e( 'Show result count', 'tutor-course-search-pro' ); ?></th><td><label><input type="checkbox" name="show_result_count" value="1" <?php checked( $s['show_result_count'], 1 ); ?>> <?php esc_html_e( 'Display "N courses found" above the results, reflecting the current search/filters.', 'tutor-course-search-pro' ); ?></label></td></tr>
					<tr><th><?php esc_html_e( 'Live category/tag counts', 'tutor-course-search-pro' ); ?></th><td><label><input type="checkbox" name="enable_live_term_counts" value="1" <?php checked( $s['enable_live_term_counts'], 1 ); ?>> <?php esc_html_e( 'Show, next to each category/tag checkbox, how many courses match if it were selected together with the other active filters — instead of that category/tag\'s total course count site-wide.', 'tutor-course-search-pro' ); ?></label>
					<p class="description"><?php esc_html_e( 'Recalculates on every filter change (one lightweight query per category/tag). Turn off on very large catalogs if the filter bar feels slow.', 'tutor-course-search-pro' ); ?></p>
					</td></tr>
				</table>
				<h2 class="title"><?php esc_html_e( 'Search Suggestions', 'tutor-course-search-pro' ); ?></h2>
				<table class="form-table" role="presentation"><tr><th><?php esc_html_e( 'Replace default suggestions', 'tutor-course-search-pro' ); ?></th><td><label><input type="checkbox" name="replace_suggestions" value="1" <?php checked( $s['replace_suggestions'], 1 ); ?>> <?php esc_html_e( 'Use the enhanced autocomplete.', 'tutor-course-search-pro' ); ?></label></td></tr><tr><th><label for="tcsp_suggest_limit"><?php esc_html_e( 'Suggestions shown', 'tutor-course-search-pro' ); ?></label></th><td><input type="number" min="1" max="20" id="tcsp_suggest_limit" name="suggest_limit" value="<?php echo esc_attr( $s['suggest_limit'] ); ?>" class="small-text"></td></tr></table>
				<?php submit_button( __( 'Save Changes', 'tutor-course-search-pro' ), 'primary', 'tcsp_settings_submit' ); ?>
			</form>
		</div>
		<style>.tcsp-settings-only .tcsp-tier-grid{display:flex;flex-wrap:wrap;gap:12px}.tcsp-settings-only .tcsp-tier-card{box-sizing:border-box;width:220px;padding:14px;border:1px solid #dcdcde;border-top:4px solid;border-radius:6px;background:#fff}.tcsp-settings-only .tcsp-tier-card>strong{display:block;font-size:15px}.tcsp-settings-only .tcsp-tier-card code{display:block;color:#646970;margin:4px 0 10px}.tcsp-settings-only .tcsp-tier-card label{display:block;margin-top:8px;font-size:12px;font-weight:600}.tcsp-settings-only .tcsp-tier-card input{display:block;width:100%;margin-top:3px}</style>
		<?php
	}

	public function sort_options() {
		return array(
			'relevance' => __( 'Relevance (recommended)', 'tutor-course-search-pro' ),
			'newest_first' => __( 'Newest first', 'tutor-course-search-pro' ),
			'oldest_first' => __( 'Oldest first', 'tutor-course-search-pro' ),
			'course_title_az' => __( 'Title A–Z', 'tutor-course-search-pro' ),
			'course_title_za' => __( 'Title Z–A', 'tutor-course-search-pro' ),
			'price_low_high' => __( 'Price: low to high', 'tutor-course-search-pro' ),
			'price_high_low' => __( 'Price: high to low', 'tutor-course-search-pro' ),
			'top_rated' => __( 'Top rated', 'tutor-course-search-pro' ),
			'most_students' => __( 'Most students', 'tutor-course-search-pro' ),
		);
	}
}
