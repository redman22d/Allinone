<?php
/** Additional unified-suite features and presentation shortcodes. */
defined( 'ABSPATH' ) || exit;
final class TLS_Enhancements {
    const PARENT_META = '_tls_guardian_user_id';
    const PRIVACY_META = '_tls_hide_public_contact';
    public static function init() {
        add_action( 'init', array( __CLASS__, 'register_taxonomies' ), 8 );
        add_shortcode( 'tutor_suite_courses', array( __CLASS__, 'course_search' ) );
        add_shortcode( 'tutor_suite_tutor', array( __CLASS__, 'tutor_profile' ) );
        add_shortcode( 'tutor_suite_tutor_calendar', array( __CLASS__, 'tutor_calendar' ) );
        add_shortcode( 'tutor_suite_parent_portal', array( __CLASS__, 'parent_portal' ) );
        add_shortcode( 'tutor_suite_privacy_settings', array( __CLASS__, 'privacy_settings' ) );
        add_action( 'show_user_profile', array( __CLASS__, 'privacy_field' ) );
        add_action( 'edit_user_profile', array( __CLASS__, 'privacy_field' ) );
        add_action( 'personal_options_update', array( __CLASS__, 'save_privacy' ) );
        add_action( 'edit_user_profile_update', array( __CLASS__, 'save_privacy' ) );
        add_filter( 'tutor_user_social_icons', array( __CLASS__, 'filter_public_contact_icons' ), 999 );
        add_filter( 'tlms_ps_force_load_assets', array( __CLASS__, 'force_session_assets' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
    }
    public static function activate() { self::register_taxonomies(); flush_rewrite_rules(); }
    public static function register_taxonomies() {
        if ( ! post_type_exists( 'courses' ) && ! ( function_exists( 'tutor' ) && ! empty( tutor()->course_post_type ) ) ) { return; }
        $pt = function_exists( 'tutor' ) && ! empty( tutor()->course_post_type ) ? tutor()->course_post_type : 'courses';
        foreach ( array(
            'tutor_course_location' => array( 'Course locations', 'Location', true ),
            'tutor_course_type' => array( 'Course types', 'Course type', true ),
            'tutor_tutoring_type' => array( 'Tutoring types', 'Tutoring type', false ),
        ) as $tax => $labels ) {
            if ( taxonomy_exists( $tax ) ) { continue; }
            register_taxonomy( $tax, array( $pt ), array(
                'labels' => array( 'name' => $labels[0], 'singular_name' => $labels[1], 'menu_name' => $labels[0] ),
                'public' => true, 'hierarchical' => $labels[2], 'show_ui' => true, 'show_admin_column' => true,
                'show_in_rest' => true, 'query_var' => true, 'rewrite' => array( 'slug' => sanitize_title( $labels[0] ) ),
            ) );
        }
    }
    public static function assets() { wp_enqueue_style( 'tls-suite', TLS_URL . 'assets/css/suite.css', array(), TLS_VERSION ); }
    public static function force_session_assets( $force ) {
        if ( $force || ! is_singular() ) { return $force; }
        $post = get_post();
        if ( ! $post ) { return $force; }
        foreach ( array( 'tutor_suite_tutor', 'tutor_suite_tutor_calendar' ) as $tag ) {
            if ( has_shortcode( (string) $post->post_content, $tag ) ) { return true; }
        }
        return $force;
    }
    private static function is_guardian( $user_id = 0 ) { $id = $user_id ? absint( $user_id ) : get_current_user_id(); return $id && (bool) get_user_meta( $id, '_tls_is_guardian', true ); }
    public static function privacy_field( $user ) {
        if ( ! current_user_can( 'edit_user', $user->ID ) ) { return; }
        wp_nonce_field( 'tls_privacy_' . $user->ID, 'tls_privacy_nonce' );
        echo '<h2>' . esc_html__( 'Tutor Learning Suite privacy', 'tutor-learning-suite' ) . '</h2><table class="form-table"><tr><th><label for="tls_hide_contact">' . esc_html__( 'Hide public contact information', 'tutor-learning-suite' ) . '</label></th><td><label><input id="tls_hide_contact" type="checkbox" name="tls_hide_contact" value="1" ' . checked( get_user_meta( $user->ID, self::PRIVACY_META, true ), '1', false ) . '> ' . esc_html__( 'Hide contact email, phone, and messaging links on Tutor public profiles.', 'tutor-learning-suite' ) . '</label><p class="description">' . esc_html__( 'Private booking and checkout continue to use their existing authenticated flows.', 'tutor-learning-suite' ) . '</p></td></tr></table>';
    }
    public static function save_privacy( $user_id ) {
        if ( ! current_user_can( 'edit_user', $user_id ) || empty( $_POST['tls_privacy_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tls_privacy_nonce'] ) ), 'tls_privacy_' . $user_id ) ) { return; }
        update_user_meta( $user_id, self::PRIVACY_META, isset( $_POST['tls_hide_contact'] ) ? '1' : '0' );
    }
    public static function filter_public_contact_icons( $icons ) {
        if ( ! is_array( $icons ) ) { return $icons; }
        $username = sanitize_user( (string) get_query_var( 'tutor_profile_username' ) );
        if ( ! $username && isset( $_GET['tutor_profile_username'] ) ) { $username = sanitize_user( wp_unslash( $_GET['tutor_profile_username'] ) ); }
        $profile_user = $username ? get_user_by( 'slug', $username ) : false;
        if ( ! $profile_user || '1' !== get_user_meta( $profile_user->ID, self::PRIVACY_META, true ) ) { return $icons; }
        foreach ( array_keys( $icons ) as $key ) {
            if ( preg_match( '/(?:contact_email|whatsapp|telegram|phone|mobile|telephone)/i', (string) $key ) ) { unset( $icons[ $key ] ); }
        }
        return $icons;
    }
    public static function privacy_settings() {
        if ( ! is_user_logged_in() || ! self::is_instructor( get_current_user_id() ) ) { return '<p class="tls-notice">' . esc_html__( 'Sign in as a tutor to manage profile privacy.', 'tutor-learning-suite' ) . '</p>'; }
        $user_id = get_current_user_id();
        $message = '';
        if ( isset( $_POST['tls_save_privacy'] ) && isset( $_POST['tls_frontend_privacy_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tls_frontend_privacy_nonce'] ) ), 'tls_frontend_privacy_' . $user_id ) ) {
            update_user_meta( $user_id, self::PRIVACY_META, isset( $_POST['tls_hide_public_contact'] ) ? '1' : '0' );
            $message = '<p class="tls-success">' . esc_html__( 'Your public contact visibility setting has been saved.', 'tutor-learning-suite' ) . '</p>';
        }
        return '<section class="tls-parent"><h2>' . esc_html__( 'Public profile privacy', 'tutor-learning-suite' ) . '</h2>' . $message . '<form method="post">' . wp_nonce_field( 'tls_frontend_privacy_' . $user_id, 'tls_frontend_privacy_nonce', true, false ) . '<label><input type="checkbox" name="tls_hide_public_contact" value="1" ' . checked( get_user_meta( $user_id, self::PRIVACY_META, true ), '1', false ) . '> ' . esc_html__( 'Hide my contact email, phone, and messaging links on Tutor public profiles.', 'tutor-learning-suite' ) . '</label><p><button class="tls-button" type="submit" name="tls_save_privacy" value="1">' . esc_html__( 'Save privacy settings', 'tutor-learning-suite' ) . '</button></p></form></section>';
    }
    public static function tutor_profile( $atts ) {
        $a = shortcode_atts( array( 'id' => 0, 'show_courses' => 'yes', 'show_calendar' => 'yes' ), $atts, 'tutor_suite_tutor' );
        $id = absint( $a['id'] ); if ( ! $id && isset( $_GET['tutor_id'] ) ) { $id = absint( $_GET['tutor_id'] ); }
        if ( ! $id ) { return '<div class="tls-notice">' . esc_html__( 'Select a tutor to view their profile.', 'tutor-learning-suite' ) . '</div>'; }
        $user = get_userdata( $id ); if ( ! $user || ! self::is_instructor( $id ) ) { return ''; }
        $hide = '1' === get_user_meta( $id, self::PRIVACY_META, true );
        $avatar = get_avatar( $id, 120, '', $user->display_name, array( 'class' => 'tls-avatar' ) );
        $location = self::terms_for_user_courses( $id, 'tutor_course_location' );
        $html = '<article class="tls-profile"><header>' . $avatar . '<div><p class="tls-eyebrow">' . esc_html__( 'Tutor profile', 'tutor-learning-suite' ) . '</p><h1>' . esc_html( $user->display_name ) . '</h1>';
        if ( $location ) { $html .= '<p class="tls-location">' . esc_html( $location ) . '</p>'; }
        $bio = get_user_meta( $id, 'description', true ); if ( $bio ) { $html .= '<p>' . esc_html( wp_trim_words( wp_strip_all_tags( $bio ), 55 ) ) . '</p>'; }
        if ( ! $hide ) { $email = get_user_meta( $id, '_tutor_profile_contact_email', true ); if ( $email && is_email( $email ) ) { $html .= '<p><a href="mailto:' . esc_attr( antispambot( $email ) ) . '">' . esc_html( antispambot( $email ) ) . '</a></p>'; } }
        $html .= '</div></header><div class="tls-profile-actions"><a class="tls-button" href="#tls-private-calendar">' . esc_html__( 'Check availability', 'tutor-learning-suite' ) . '</a></div>';
        if ( 'yes' === $a['show_courses'] ) { $html .= self::course_grid( array( 'author' => $id, 'limit' => 6 ) ); }
        if ( 'yes' === $a['show_calendar'] ) { $html .= '<section id="tls-private-calendar"><h2>' . esc_html__( 'Private-session availability', 'tutor-learning-suite' ) . '</h2>' . self::tutor_calendar( array( 'tutor_id' => $id ) ) . '</section>'; }
        return $html . '</article>';
    }
    private static function is_instructor( $id ) { $u = get_userdata( $id ); return $u && ( in_array( 'tutor_instructor', (array) $u->roles, true ) || user_can( $id, 'manage_tutor_instructor' ) ); }
    private static function terms_for_user_courses( $id, $tax ) { $terms = wp_get_object_terms( get_posts( array( 'post_type' => function_exists( 'tutor' ) && ! empty( tutor()->course_post_type ) ? tutor()->course_post_type : 'courses', 'author' => $id, 'post_status' => 'publish', 'numberposts' => 30, 'fields' => 'ids' ) ), $tax, array( 'fields' => 'names' ) ); return is_wp_error( $terms ) ? '' : implode( ', ', array_unique( $terms ) ); }
    public static function tutor_calendar( $atts ) { $a = shortcode_atts( array( 'tutor_id' => 0 ), $atts, 'tutor_suite_tutor_calendar' ); $id = absint( $a['tutor_id'] ); if ( ! $id && isset( $_GET['tutor_id'] ) ) { $id = absint( $_GET['tutor_id'] ); }
        if ( ! $id ) { return '<p class="tls-notice">' . esc_html__( 'Tutor availability is selected from the tutor profile.', 'tutor-learning-suite' ) . '</p>'; }
        wp_enqueue_script( 'tls-public-availability', TLS_URL . 'assets/js/public-availability.js', array(), TLS_VERSION, true );
        wp_localize_script( 'tls-public-availability', 'TLSAvailability', array( 'ajax' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'tlms_ps_nonce' ), 'labels' => array( 'loading' => __( 'Checking availability…', 'tutor-learning-suite' ), 'empty' => __( 'No matching slots are available for this date.', 'tutor-learning-suite' ), 'failed' => __( 'Availability could not be loaded. Please try another date.', 'tutor-learning-suite' ), 'selected' => __( 'Preference copied to the request form. The tutor will confirm the final time.', 'tutor-learning-suite' ) ) ) );
        $date = wp_date( 'Y-m-d', current_time( 'timestamp' ) + DAY_IN_SECONDS );
        $picker = '<section class="tls-availability" data-tutor-id="' . esc_attr( $id ) . '"><h3>' . esc_html__( 'Check upcoming availability', 'tutor-learning-suite' ) . '</h3><div class="tls-availability-controls"><label>' . esc_html__( 'Date', 'tutor-learning-suite' ) . '<input type="date" class="tls-availability-date" min="' . esc_attr( wp_date( 'Y-m-d' ) ) . '" value="' . esc_attr( $date ) . '"></label><label>' . esc_html__( 'Session length', 'tutor-learning-suite' ) . '<select class="tls-availability-duration"><option value="30">30 min</option><option value="45">45 min</option><option value="60" selected>60 min</option><option value="90">90 min</option><option value="120">120 min</option></select></label><label>' . esc_html__( 'Tutoring method', 'tutor-learning-suite' ) . '<select class="tls-availability-type"><option value="online">' . esc_html__( 'Online', 'tutor-learning-suite' ) . '</option><option value="tutor_home">' . esc_html__( 'At tutor’s location', 'tutor-learning-suite' ) . '</option><option value="student_home">' . esc_html__( 'At student’s location', 'tutor-learning-suite' ) . '</option></select></label></div><p class="tls-availability-status" aria-live="polite">' . esc_html__( 'Choose a date to see times.', 'tutor-learning-suite' ) . '</p><div class="tls-availability-slots" role="group" aria-label="' . esc_attr__( 'Available time slots', 'tutor-learning-suite' ) . '"></div><p class="tls-availability-note">' . esc_html__( 'Times are shown in the tutor’s timezone. Selecting a time adds it as your preferred time; it is not reserved until the tutor accepts and confirms your request.', 'tutor-learning-suite' ) . '</p></section>';
        return $picker . do_shortcode( '[tlms_private_booking tutor_id="' . $id . '"]' );
    }
    public static function course_search( $atts ) { $a = shortcode_atts( array( 'limit' => 12, 'location' => '', 'course_type' => '', 'tutoring_type' => '' ), $atts, 'tutor_suite_courses' );
        $html = '<form class="tls-filter" method="get"><label>' . esc_html__( 'Search courses', 'tutor-learning-suite' ) . '<input name="tls_q" value="' . esc_attr( isset( $_GET['tls_q'] ) ? sanitize_text_field( wp_unslash( $_GET['tls_q'] ) ) : '' ) . '"></label>';
        foreach ( array( 'tutor_course_location' => 'Location', 'tutor_course_type' => 'Course type', 'tutor_tutoring_type' => 'Tutoring type' ) as $tax => $title ) { $html .= self::taxonomy_select( $tax, $title ); }
        $html .= '<button class="tls-button" type="submit">' . esc_html__( 'Find courses', 'tutor-learning-suite' ) . '</button></form>';
        $html .= self::course_grid( array( 'limit' => min( 36, max( 1, absint( $a['limit'] ) ) ), 'location' => $a['location'], 'course_type' => $a['course_type'], 'tutoring_type' => $a['tutoring_type'] ) ); return $html;
    }
    private static function taxonomy_select( $tax, $label ) { if ( ! taxonomy_exists( $tax ) ) { return ''; } $current = isset( $_GET[ $tax ] ) ? sanitize_title( wp_unslash( $_GET[ $tax ] ) ) : ''; $html = '<label>' . esc_html( $label ) . '<select name="' . esc_attr( $tax ) . '"><option value="">' . esc_html__( 'Any', 'tutor-learning-suite' ) . '</option>'; $terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false ) ); if ( ! is_wp_error( $terms ) ) { foreach ( $terms as $term ) { $html .= '<option value="' . esc_attr( $term->slug ) . '" ' . selected( $current, $term->slug, false ) . '>' . esc_html( $term->name ) . '</option>'; } } return $html . '</select></label>'; }
    private static function course_grid( $args = array() ) { $pt = function_exists( 'tutor' ) && ! empty( tutor()->course_post_type ) ? tutor()->course_post_type : 'courses'; $qargs = array( 'post_type' => $pt, 'post_status' => 'publish', 'posts_per_page' => isset( $args['limit'] ) ? absint( $args['limit'] ) : 12, 's' => isset( $_GET['tls_q'] ) ? sanitize_text_field( wp_unslash( $_GET['tls_q'] ) ) : '' ); if ( ! empty( $args['author'] ) ) { $qargs['author'] = absint( $args['author'] ); } $taxq = array(); foreach ( array( 'tutor_course_location' => 'location', 'tutor_course_type' => 'course_type', 'tutor_tutoring_type' => 'tutoring_type' ) as $tax => $key ) { $v = isset( $_GET[ $tax ] ) ? sanitize_title( wp_unslash( $_GET[ $tax ] ) ) : ( isset( $args[ $key ] ) ? sanitize_title( $args[ $key ] ) : '' ); if ( $v ) { $taxq[] = array( 'taxonomy' => $tax, 'field' => 'slug', 'terms' => $v ); } } if ( $taxq ) { $qargs['tax_query'] = $taxq; }
        $q = new WP_Query( $qargs ); if ( ! $q->have_posts() ) { return '<p class="tls-empty">' . esc_html__( 'No matching courses found.', 'tutor-learning-suite' ) . '</p>'; } $html = '<div class="tls-course-grid">'; while ( $q->have_posts() ) { $q->the_post(); $id = get_the_ID(); $author = get_userdata( (int) get_post_field( 'post_author', $id ) ); $price = function_exists( 'tutor_utils' ) ? tutor_utils()->get_course_price( $id ) : ''; $html .= '<article class="tls-course-card"><a class="tls-course-image" href="' . esc_url( get_permalink() ) . '">' . get_the_post_thumbnail( $id, 'medium_large' ) . '</a><div class="tls-course-copy"><p class="tls-eyebrow">' . esc_html( $author ? $author->display_name : '' ) . '</p><h3><a href="' . esc_url( get_permalink() ) . '">' . esc_html( get_the_title() ) . '</a></h3><p>' . esc_html( wp_trim_words( wp_strip_all_tags( get_the_excerpt() ), 20 ) ) . '</p><footer><span>' . wp_kses_post( $price ) . '</span><a href="' . esc_url( get_permalink() ) . '">' . esc_html__( 'View course', 'tutor-learning-suite' ) . '</a></footer></div></article>'; } wp_reset_postdata(); return $html . '</div>'; }
    public static function parent_portal() {
        // Keep the legacy shortcode as a compatibility alias, but use the
        // canonical family workflow so no duplicate account-creation path exists.
        return class_exists( 'TLS_Integration' ) ? TLS_Integration::render_family_page() : '<p class="tls-notice">' . esc_html__( 'Family services are unavailable until Tutor Learning Suite finishes loading.', 'tutor-learning-suite' ) . '</p>';
    }

}
