<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class OStudy_Ads_Utils {
    public static function default_settings() {
        return array(
            'enabled' => 1,
            'auto_render' => 1,
            'dashboard_path' => '/dashboard',
            'tutors_path' => '/tutors',
            'courses_path' => '/courses',
            'profile_path' => '/profile',
            'focus_mode_enabled' => 0,
            'focus_mode_side_enabled' => 0,
            'popup_delay' => 12,
            'popup_once_per_session' => 1,
            'popup_mobile' => 1,
            'hide_for_admins' => 1,
            'orders_enabled' => 1,
            'order_page_url' => '',
            'order_notify_email' => '',
            'order_accent_color' => '#4f46e5',
            'order_max_upload_mb' => 3,
            'payment_instructions' => '',
            'order_currency' => '',
            'order_prices' => array(),
            'order_discounts' => array( 30 => 0, 60 => 0, 90 => 0 ),
            'order_max_per_placement' => 0,
            'order_enforce_sizes' => 1,
            'order_reminders' => 1,
            'selector_home_top' => 'main, .site-main, #main, .content-area, .site-content',
            'selector_course_search_top' => '.course-archive-page > .tutor-row, .course-archive-page',
            'selector_tutor_search_top' => '.tutor-instructor-list-wrapper, .tutor-instructor-list, main, .site-main, #main, .content-area',
            'selector_instructor_profile_top' => '.tutor-user-public-profile .photo-area + .tutor-container',
            'selector_instructor_profile_side' => '.tutor-user-public-profile .tutor-user-profile-sidebar',
            'instructor_profile_side_position' => 'left',
            'selector_course_page_top' => '.tutor-course-details-page > .tutor-row, .tutor-course-details-page',
            'selector_dashboard_sidebar' => '.tutor-dashboard-sidebar',
            'selector_focus_mode_top' => '.tutor-course-single-page, .tutor-course-details-page, .tutor-course-details, main, .site-main, #main, .content-area',
            'selector_focus_mode_side' => '.tutor-single-course-sidebar, .tutor-course-single-sidebar, .tutor-single-course-sidebar-more, .tutor-course-sidebar',
        );
    }


    public static function placement_selectors() {
        $settings = OStudy_Ads_DB::get_settings();
        return array(
            'home_top' => array('selector' => $settings['selector_home_top'], 'method' => 'before'),
            'course_search_top' => array('selector' => $settings['selector_course_search_top'], 'method' => 'before'),
            'tutor_search_top' => array('selector' => $settings['selector_tutor_search_top'], 'method' => 'before'),
            'instructor_profile_top' => array('selector' => $settings['selector_instructor_profile_top'], 'method' => 'profile_top'),
            'instructor_profile_side' => array('selector' => $settings['selector_instructor_profile_side'], 'method' => 'profile_side', 'side' => $settings['instructor_profile_side_position']),
            'course_page_top' => array('selector' => $settings['selector_course_page_top'], 'method' => 'before'),
            'dashboard_sidebar' => array('selector' => $settings['selector_dashboard_sidebar'], 'method' => 'append'),
            'focus_mode_top' => array('selector' => $settings['selector_focus_mode_top'], 'method' => 'before'),
            'focus_mode_side' => array('selector' => $settings['selector_focus_mode_side'], 'method' => 'append'),
        );
    }

    public static function recommended_dimensions() {
        return array(
            'home_top' => array( 'desktop' => '1200 × 300 px', 'mobile' => '640 × 240 px' ),
            'course_search_top' => array( 'desktop' => '1200 × 250 px', 'mobile' => '640 × 220 px' ),
            'tutor_search_top' => array( 'desktop' => '1200 × 250 px', 'mobile' => '640 × 220 px' ),
            'instructor_profile_top' => array( 'desktop' => '1200 × 250 px', 'mobile' => '640 × 220 px' ),
            'instructor_profile_side' => array( 'desktop' => '248 × 600 px', 'mobile' => 'Not recommended' ),
            'course_page_top' => array( 'desktop' => '1200 × 250 px', 'mobile' => '640 × 220 px' ),
            'dashboard_sidebar' => array( 'desktop' => '300 × 250 px', 'mobile' => '336 × 280 px' ),
            'focus_mode_top' => array( 'desktop' => '970 × 120 px', 'mobile' => '640 × 160 px' ),
            'focus_mode_side' => array( 'desktop' => '300 × 600 px', 'mobile' => 'Not recommended' ),
            'home_popup' => array( 'desktop' => '600 × 500 px', 'mobile' => '360 × 500 px' ),
            'course_search_popup' => array( 'desktop' => '600 × 500 px', 'mobile' => '360 × 500 px' ),
            'tutor_search_popup' => array( 'desktop' => '600 × 500 px', 'mobile' => '360 × 500 px' ),
            'instructor_profile_popup' => array( 'desktop' => '600 × 500 px', 'mobile' => '360 × 500 px' ),
            'course_page_popup' => array( 'desktop' => '600 × 500 px', 'mobile' => '360 × 500 px' ),
            'dashboard_popup' => array( 'desktop' => '600 × 500 px', 'mobile' => '360 × 500 px' ),
            'focus_mode_popup' => array( 'desktop' => '600 × 500 px', 'mobile' => '360 × 500 px' ),
        );
    }

    public static function recommended_for_targets( $targets ) {
        $all = self::recommended_dimensions();
        foreach ( (array) $targets as $target ) {
            if ( isset( $all[ $target ] ) ) return $all[ $target ];
        }
        return array( 'desktop' => '1200 × 300 px', 'mobile' => '640 × 240 px' );
    }

    public static function display_status( $ad ) {
        if ( ! empty( $ad->status ) && 'paused' === $ad->status ) return 'Paused';
        $now = current_time( 'timestamp', true );
        if ( ! empty( $ad->start_at ) && $now < strtotime( $ad->start_at . ' UTC' ) ) return 'Scheduled';
        if ( ! empty( $ad->end_at ) && $now > strtotime( $ad->end_at . ' UTC' ) ) return 'Expired';
        return 'Active';
    }

    public static function is_frontend_ad_page() {
        if ( is_admin() ) return false;
        return in_array( self::page_key(), array( 'home', 'course_search', 'tutor_search', 'instructor_profile', 'course_page', 'dashboard' ), true );
    }

    public static function mysql_datetime_or_null( $value ) {
        $value = trim( str_replace( 'T', ' ', (string) $value ) );
        if ( '' === $value ) return null;

        try {
            $date = new DateTime( $value, wp_timezone() );
            return $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
        } catch ( Throwable $e ) {
            return null;
        }
    }

    public static function path_matches( $path, $configured ) {
        $configured = '/' . trim( (string) $configured, '/' );
        $path = '/' . trim( (string) $path, '/' );
        return ( $path === $configured || 0 === strpos( $path, $configured . '/' ) );
    }

    public static function request_path() {
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
        return (string) wp_parse_url( $request_uri, PHP_URL_PATH );
    }

    public static function current_device() {
        $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( (string) $_SERVER['HTTP_USER_AGENT'] ) : '';
        if ( preg_match( '/tablet|ipad|android(?!.*mobile)/i', $ua ) ) return 'tablet';
        if ( preg_match( '/mobile|iphone|ipod|blackberry|opera mini|windows phone/i', $ua ) ) return 'mobile';
        return 'desktop';
    }

    public static function user_role_match( $roles ) {
        if ( empty( $roles ) ) return true;

        $roles = array_values( array_filter( array_map( 'sanitize_key', (array) $roles ) ) );
        $user = wp_get_current_user();

        if ( ! $user || empty( $user->roles ) ) {
            return in_array( 'visitor', $roles, true );
        }

        // OStudy Ads exposes a friendly `tutor` audience label, while Tutor LMS 4.x
        // actually stores instructors under the WordPress role `tutor_instructor`.
        // Accept both so audience targeting works for real Tutor instructors.
        $user_roles = array_map( 'sanitize_key', (array) $user->roles );
        if ( in_array( 'tutor', $roles, true ) && in_array( 'tutor_instructor', $user_roles, true ) ) {
            return true;
        }

        return (bool) array_intersect( $user_roles, $roles );
    }

    public static function is_tutor_dashboard() {
        if ( function_exists( 'tutor_utils' ) ) {
            try {
                if ( method_exists( tutor_utils(), 'is_tutor_dashboard' ) && tutor_utils()->is_tutor_dashboard() ) return true;
            } catch ( Throwable $e ) {}
        }
        $settings = OStudy_Ads_DB::get_settings();
        return self::path_matches( self::request_path(), $settings['dashboard_path'] );
    }

    public static function is_course_archive() {
        if ( is_post_type_archive( 'courses' ) ) return true;
        if ( function_exists( 'tutor_utils' ) ) {
            try {
                if ( method_exists( tutor_utils(), 'is_course_archive' ) && tutor_utils()->is_course_archive() ) return true;
            } catch ( Throwable $e ) {}
        }
        $settings = OStudy_Ads_DB::get_settings();
        return self::path_matches( self::request_path(), $settings['courses_path'] );
    }

    public static function is_tutors_search() {
        $settings = OStudy_Ads_DB::get_settings();
        return self::path_matches( self::request_path(), $settings['tutors_path'] );
    }

    public static function is_instructor_profile() {
        $settings = OStudy_Ads_DB::get_settings();
        $path = self::request_path();
        if ( self::path_matches( $path, $settings['profile_path'] ) ) return true;
        return ( false !== strpos( strtolower( $path ), '/instructor/' ) || false !== strpos( strtolower( $path ), '/tutor-profile/' ) );
    }

    public static function is_single_course() {
        if ( is_singular( 'courses' ) ) return true;
        if ( function_exists( 'tutor_utils' ) ) {
            try {
                if ( method_exists( tutor_utils(), 'is_single_course' ) && tutor_utils()->is_single_course() ) return true;
            } catch ( Throwable $e ) {}
        }
        return false;
    }

    public static function is_focus_mode() {
        if ( ! self::is_single_course() && ! self::is_tutor_dashboard() ) return false;
        if ( isset( $_GET['tutor_course_focus_mode'] ) ) return ( 'on' === sanitize_key( wp_unslash( $_GET['tutor_course_focus_mode'] ) ) || '1' === sanitize_key( wp_unslash( $_GET['tutor_course_focus_mode'] ) ) );
        $uri = strtolower( self::request_path() );
        return ( false !== strpos( $uri, '/focus/' ) || false !== strpos( $uri, '/lesson/' ) && isset( $_GET['focus'] ) );
    }

    public static function page_key() {
        if ( self::is_tutors_search() ) return 'tutor_search';
        if ( self::is_course_archive() ) return 'course_search';
        if ( self::is_instructor_profile() ) return 'instructor_profile';
        if ( self::is_single_course() ) return 'course_page';
        if ( self::is_tutor_dashboard() ) return 'dashboard';
        if ( is_front_page() ) return 'home';
        return 'other';
    }

    public static function selected_categories() {
        $ids = array();
        if ( self::is_single_course() && is_singular() ) {
            $terms = get_the_terms( get_the_ID(), 'course-category' );
            if ( is_array( $terms ) ) $ids = wp_list_pluck( $terms, 'term_id' );
        }
        return array_map( 'absint', $ids );
    }

    /**
     * Return the instructor Wilaya codes attached to the current course author.
     * Tutor LMS Instructor Profile Extra stores these in _tlms_ipe_wilaya.
     */
    public static function current_course_wilayas() {
        if ( ! self::is_single_course() ) return array();
        $course_id = get_the_ID();
        if ( ! $course_id ) return array();
        $author_id = absint( get_post_field( 'post_author', $course_id ) );
        if ( ! $author_id ) return array();
        $value = get_user_meta( $author_id, '_tlms_ipe_wilaya', true );
        if ( ! is_array( $value ) ) $value = array( $value );
        $value = array_map( 'sanitize_text_field', $value );
        return array_values( array_unique( array_filter( $value, function( $v ) { return '' !== trim( (string) $v ); } ) ) );
    }

    public static function format_admin_datetime( $value ) {
        if ( empty( $value ) ) return '—';
        $timestamp = strtotime( $value . ' UTC' );
        return $timestamp ? wp_date( 'Y-m-d H:i', $timestamp, wp_timezone() ) : '—';
    }

    public static function json_array( $value ) {
        $decoded = json_decode( (string) $value, true );
        return is_array( $decoded ) ? $decoded : array();
    }
}
