<?php
/**
 * Tutor LMS integration layer for UStudy/Tutor Learning Suite.
 * Keeps Tutor LMS as the canonical source for users, courses and enrollments.
 */
defined( 'ABSPATH' ) || exit;

final class TLS_Integration {
    const FAMILY_ROUTE = 'tls-family';
    const PRIVACY_ROUTE = 'tls-privacy';
    const GUARDIAN_META = '_tls_guardian_user_id';
    const PENDING_LINK_META = '_tls_parent_link_pending';
    const PRIVATE_STUDENT_META = '_tls_private_student_profile';
    const PARENT_PROGRESS_META = '_tls_parent_can_view_learning';
    const PARENT_BOOKING_META = '_tls_parent_can_book';
    const PARENT_ENROLL_META = '_tls_parent_can_enroll';
    const GUARDIAN_CONCERN_META = '_tls_guardian_link_concern';

    public static function init() {
        add_filter( 'template_include', array( __CLASS__, 'template_include' ), 120 );
        // Tutor LMS 4 uses a dedicated learner-nav filter; keep the legacy
        // filter too for sites still using the classic dashboard.
        add_filter( 'tutor_student_dashboard_nav', array( __CLASS__, 'student_dashboard_items' ), 40 );
        add_filter( 'tutor_dashboard/nav_items', array( __CLASS__, 'student_dashboard_items' ), 40 );
        add_filter( 'load_dashboard_template_part_from_other_location', array( __CLASS__, 'dashboard_template' ), 40 );
        add_action( 'template_redirect', array( __CLASS__, 'handle_family_and_privacy_posts' ), 1 );
        add_action( 'wp_ajax_tls_month_availability', array( __CLASS__, 'ajax_month_availability' ) );
        add_action( 'wp_ajax_nopriv_tls_month_availability', array( __CLASS__, 'ajax_month_availability' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 30 );
        add_filter( 'tutor_user_social_icons', array( __CLASS__, 'filter_contact_icons' ), 999 );
        // Make every place Tutor lists courses use the suite's search/filter bar and course card.
        add_filter( 'tutor_get_template_path', array( __CLASS__, 'override_tutor_template' ), 60, 2 );
        add_filter( 'pre_do_shortcode_tag', array( __CLASS__, 'intercept_tutor_course_shortcode' ), 10, 4 );
        add_filter( 'tcsp_is_course_context', array( __CLASS__, 'page_needs_catalog_assets' ) );
        add_shortcode( 'tutor_suite_courses', array( __CLASS__, 'course_search_shortcode' ) );
        add_shortcode( 'tutor_suite_tutor', array( __CLASS__, 'tutor_profile_shortcode' ) );
        add_shortcode( 'tutor_suite_tutor_calendar', array( __CLASS__, 'calendar_shortcode' ) );
        add_shortcode( 'tutor_suite_family', array( __CLASS__, 'family_shortcode' ) );
        add_shortcode( 'tutor_suite_privacy', array( __CLASS__, 'privacy_shortcode' ) );
    }

    public static function activate() {
        flush_rewrite_rules();
    }

    public static function student_dashboard_items( $items ) {
        if ( ! is_array( $items ) ) {
            $items = array();
        }
        // This filter is used for the learner dashboard navigation. Tutors who
        // also use a learner account can manage their own family/privacy settings
        // here without adding the page to the instructor dashboard menu.
        // Tutor 4 draws SVG icons by name; older versions use the icon font.
        $icon = ( defined( 'TUTOR_VERSION' ) && version_compare( TUTOR_VERSION, '4.0.0', '>=' ) ) ? 'settings-privacy' : 'tutor-icon-user-group';
        $items[ self::FAMILY_ROUTE ] = array(
            'title'       => __( 'Family & Privacy', 'tutor-learning-suite' ),
            'icon'        => $icon,
            'active_icon' => $icon,
        );
        unset( $items[ self::PRIVACY_ROUTE ] );
        return $items;
    }

    /** Retained as a no-op for themes or older extensions that call this method. */
    public static function instructor_dashboard_items( $items ) {
        return $items;
    }

    public static function dashboard_template( $location ) {
        $route = sanitize_key( get_query_var( 'tutor_dashboard_page' ) );
        if ( in_array( $route, array( self::FAMILY_ROUTE, self::PRIVACY_ROUTE ), true ) ) {
            // Keep the old privacy endpoint as an alias, but render the shared tabbed page.
            // Instructor accounts may open this route from their learner dashboard;
            // the item is not registered in Tutor's instructor-nav filter.
            $file = TLS_DIR . 'templates/dashboard/family.php';
            return file_exists( $file ) ? $file : $location;
        }
        return $location;
    }

    public static function dashboard_page( $page ) {
        $route = sanitize_key( get_query_var( 'tutor_dashboard_page' ) );
        $requested_tab = isset( $_GET['tls_tab'] ) ? sanitize_key( wp_unslash( $_GET['tls_tab'] ) ) : '';
        $active_tab = self::PRIVACY_ROUTE === $route || 'privacy' === $requested_tab || 'privacy' === $page ? 'privacy' : 'family';
        $base_url = self::dashboard_url( self::FAMILY_ROUTE );
        $family_url = add_query_arg( 'tls_tab', 'family', $base_url );
        $privacy_url = add_query_arg( 'tls_tab', 'privacy', $base_url );

        $html  = '<nav class="tls-lms-dashboard-tabs" aria-label="' . esc_attr__( 'Family and privacy settings', 'tutor-learning-suite' ) . '">';
        $html .= '<a class="' . ( 'family' === $active_tab ? 'is-active' : '' ) . '" href="' . esc_url( $family_url ) . '"' . ( 'family' === $active_tab ? ' aria-current="page"' : '' ) . '>' . self::card_icon( 'users' ) . '<span>' . esc_html__( 'Family & Guardian', 'tutor-learning-suite' ) . '</span></a>';
        $html .= '<a class="' . ( 'privacy' === $active_tab ? 'is-active' : '' ) . '" href="' . esc_url( $privacy_url ) . '"' . ( 'privacy' === $active_tab ? ' aria-current="page"' : '' ) . '>' . self::card_icon( 'shield' ) . '<span>' . esc_html__( 'Privacy & Security', 'tutor-learning-suite' ) . '</span></a>';
        $html .= '</nav>';
        $html .= 'privacy' === $active_tab ? self::render_privacy_page() : self::render_family_page();
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public static function template_include( $template ) {
        $username = sanitize_user( (string) get_query_var( 'tutor_profile_username' ) );
        if ( $username ) {
            $user = get_user_by( 'slug', $username );
            if ( $user && self::is_instructor( $user->ID ) ) {
                $layout = function_exists( 'tutor_utils' ) ? tutor_utils()->get_option( 'public_profile_layout', 'private' ) : 'private';
                if ( 'private' !== $layout ) {
                    $file = TLS_DIR . 'templates/public-instructor.php';
                    if ( file_exists( $file ) ) {
                        $GLOBALS['tls_public_instructor_id'] = (int) $user->ID;
                        return $file;
                    }
                }
            }
        }

        $course_type = function_exists( 'tutor' ) && ! empty( tutor()->course_post_type ) ? tutor()->course_post_type : 'courses';
        $archive_page_setting = function_exists( 'tutor_utils' ) ? tutor_utils()->get_option( 'course_archive_page', 0 ) : 0;
        $archive_page_id = is_numeric( $archive_page_setting ) && (int) $archive_page_setting > 0 ? (int) $archive_page_setting : 0;
        if ( is_post_type_archive( $course_type ) || is_tax( 'course-category' ) || ( $archive_page_id && is_page( $archive_page_id ) ) ) {
            $file = TLS_DIR . 'templates/course-archive.php';
            if ( file_exists( $file ) ) {
                return $file;
            }
        }
        return $template;
    }

    private static function is_instructor( $user_id ) {
        if ( function_exists( 'tutor_utils' ) ) {
            try {
                return (bool) tutor_utils()->is_instructor( absint( $user_id ), true );
            } catch ( Throwable $e ) {
                // Fall through to the role check for older Tutor builds.
            }
        }
        $user = get_userdata( absint( $user_id ) );
        return $user && ( in_array( 'tutor_instructor', (array) $user->roles, true ) || user_can( $user_id, 'manage_tutor_instructor' ) );
    }

    private static function is_guardian( $user_id ) {
        return '1' === (string) get_user_meta( absint( $user_id ), '_tls_is_guardian', true );
    }

    private static function is_student_user( $user_id ) {
        $user_id = absint( $user_id );
        if ( ! $user_id ) {
            return false;
        }
        if ( class_exists( '\\TUTOR\\User' ) && method_exists( '\\TUTOR\\User', 'is_student' ) ) {
            try {
                if ( \TUTOR\User::is_student( $user_id ) ) {
                    return true;
                }
            } catch ( Throwable $e ) {
                // Continue to the WordPress role and Tutor student markers.
            }
        }
        $user = get_userdata( $user_id );
        if ( $user instanceof WP_User && in_array( 'tutor_student', (array) $user->roles, true ) ) {
            return true;
        }
        $meta_keys = array( '_is_tutor_student' );
        if ( class_exists( '\\TUTOR\\User' ) && defined( '\\TUTOR\\User::TUTOR_STUDENT_META' ) ) {
            $meta_keys[] = constant( '\\TUTOR\\User::TUTOR_STUDENT_META' );
        }
        foreach ( array_unique( $meta_keys ) as $meta_key ) {
            if ( in_array( (string) get_user_meta( $user_id, $meta_key, true ), array( 'yes', '1' ), true ) ) {
                return true;
            }
        }
        return false;
    }

    /** The birth-date key is managed by TLS_Age_Safety and stored outside the database. */
    private static function encrypt_birth_date( $date ) {
        return class_exists( 'TLS_Age_Safety' ) ? TLS_Age_Safety::encrypt_date( $date ) : false;
    }

    public static function account_age_band( $user_id ) {
        return class_exists( 'TLS_Age_Safety' ) ? TLS_Age_Safety::age_band( absint( $user_id ) ) : 'unknown';
    }

    /** Backward-compatible status for older add-on code. */
    public static function account_age_status( $user_id ) {
        $band = self::account_age_band( $user_id );
        return in_array( $band, array( 'child', 'transition' ), true ) ? 'minor' : ( 'adult' === $band ? 'adult' : 'unknown' );
    }

    private static function adult_age_threshold() {
        return max( 18, min( 25, absint( apply_filters( 'tls_age_of_majority_threshold', 19 ) ) ) );
    }

    public static function sync_age_policy( $user_id ) {
        return class_exists( 'TLS_Age_Safety' ) ? TLS_Age_Safety::sync_user( absint( $user_id ) ) : false;
    }

    private static function profile_url( $user_id ) {
        if ( function_exists( 'tutor_utils' ) ) {
            try {
                $url = tutor_utils()->profile_url( absint( $user_id ), true );
                if ( $url ) {
                    return $url;
                }
            } catch ( Throwable $e ) {
                // Use the Tutor profile permalink fallback below.
            }
        }
        $user = get_userdata( absint( $user_id ) );
        return $user ? add_query_arg( 'view', 'instructor', home_url( '/profile/' . rawurlencode( $user->user_nicename ) . '/' ) ) : home_url( '/' );
    }

    /** Read an instructor profile meta field that may be stored as an array or JSON. */
    private static function profile_meta_array( $user_id, $key ) {
        $value = get_user_meta( absint( $user_id ), $key, true );
        if ( is_string( $value ) ) {
            $decoded = json_decode( $value, true );
            if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
                $value = $decoded;
            }
        }
        if ( ! is_array( $value ) ) {
            return '' !== trim( (string) $value ) ? array( sanitize_text_field( (string) $value ) ) : array();
        }
        return $value;
    }

    /** Build readable location labels from the canonical Algeria location data. */
    private static function profile_location_labels( $user_id ) {
        $wilayas  = self::profile_meta_array( $user_id, '_tlms_ipe_wilaya' );
        $communes = self::profile_meta_array( $user_id, '_tlms_ipe_commune' );
        $labels   = array();

        if ( class_exists( 'Tlms_Ipe_Locations' ) ) {
            foreach ( $wilayas as $code ) {
                if ( is_scalar( $code ) && '' !== (string) $code ) {
                    $label = Tlms_Ipe_Locations::get_wilaya_label( (string) $code );
                    if ( $label ) { $labels[] = $label; }
                }
            }
            foreach ( $communes as $commune_id ) {
                if ( is_scalar( $commune_id ) && '' !== (string) $commune_id ) {
                    $label = Tlms_Ipe_Locations::get_commune_label_by_id( (string) $commune_id );
                    if ( $label ) { $labels[] = $label; }
                }
            }
        } else {
            foreach ( array_merge( $wilayas, $communes ) as $value ) {
                if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) { $labels[] = sanitize_text_field( (string) $value ); }
            }
        }

        return array_values( array_unique( array_filter( $labels ) ) );
    }

    /** Resolve a stable icon key from Tutor's social/contact meta key and label. */
    private static function contact_icon_name( $meta_key, $label ) {
        $source = strtolower( (string) $meta_key . ' ' . (string) $label );
        if ( preg_match( '/(?:^|[\s_])x(?:$|[\s_])/', $source ) ) {
            return 'x';
        }
        $needles = array(
            'github'    => 'github',
            'x.com'     => 'x',
            'twitter'   => 'x',
            'whatsapp'  => 'whatsapp',
            'instagram' => 'instagram',
            'telegram'  => 'telegram',
            'youtube'   => 'youtube',
            'tiktok'    => 'tiktok',
            'facebook'  => 'facebook',
            'linkedin'  => 'linkedin',
            'email'     => 'email',
            'e-mail'    => 'email',
            'phone'     => 'phone',
            'mobile'    => 'phone',
            'website'   => 'website',
            'web'       => 'website',
        );
        foreach ( $needles as $needle => $icon ) {
            if ( false !== strpos( $source, $needle ) ) {
                return $icon;
            }
        }
        return 'link';
    }

    /** Self-contained, correctly-shaped contact SVGs; no theme icon font required. */
    private static function contact_icon_svg( $name ) {
        $paths = array(
            'x'         => '<path d="M18.9 2H22l-6.8 7.8L23.2 22h-6.3L12 14.8 5.7 22H2.6l7.3-8.4L1.8 2h6.5l4.5 6.6L18.9 2Zm-1.1 18h1.7L7.3 3.9H5.5L17.8 20Z" fill="currentColor" stroke="none"/>',
            'github'    => '<path d="M12 2.2a9.8 9.8 0 0 0-3.1 19.1c.5.1.7-.2.7-.5v-1.8c-2.8.6-3.4-1.2-3.4-1.2-.5-1.1-1.1-1.4-1.1-1.4-.9-.6.1-.6.1-.6 1 .1 1.5 1 1.5 1 .9 1.5 2.3 1.1 2.9.8.1-.7.4-1.1.6-1.4-2.2-.3-4.5-1.1-4.5-4.9 0-1.1.4-2 1-2.7-.1-.3-.4-1.3.1-2.7 0 0 .8-.3 2.7 1a9.2 9.2 0 0 1 4.9 0c1.9-1.3 2.7-1 2.7-1 .5 1.4.2 2.4.1 2.7.6.7 1 1.6 1 2.7 0 3.8-2.3 4.6-4.5 4.9.4.3.7.9.7 1.8v2.7c0 .3.2.6.7.5A9.8 9.8 0 0 0 12 2.2Z" fill="currentColor" stroke="none"/>',
            'instagram' => '<rect x="3.2" y="3.2" width="17.6" height="17.6" rx="5"/><circle cx="12" cy="12" r="4.1"/><circle cx="17.7" cy="6.5" r=".9" fill="currentColor" stroke="none"/>',
            'telegram'  => '<path d="M21.5 4.4 18.2 20c-.2 1-1 1.2-1.8.7l-5-3.7-2.4 2.3c-.3.3-.5.5-1 .5l.4-5.1 9.3-8.4c.4-.4-.1-.6-.6-.3L5.6 13.2.8 11.7c-1-.3-1-1 .2-1.5L19.7 3c.9-.3 2 .2 1.8 1.4Z" fill="currentColor" stroke="none"/>',
            'whatsapp'  => '<path d="M20.2 11.7a8.2 8.2 0 0 1-12.1 7.2L3 20l1.2-4.8a8.2 8.2 0 1 1 16-3.5Z"/><path d="M8.1 7.7c.2-.5.5-.5.8-.5h.6c.2 0 .4.1.5.4l.8 1.9c.1.3.1.5-.1.7l-.6.7c-.2.2-.2.4 0 .7.6 1 1.5 1.9 2.6 2.4.3.1.5.1.7-.1l.8-.9c.2-.2.4-.3.7-.2l1.8.9c.3.1.4.3.4.5 0 .3-.2 1.3-.8 1.8-.6.6-1.4.8-2.2.6-1.1-.3-2.5-.9-4-2.3-1.6-1.5-2.6-3.3-2.9-4.4-.3-1.1.2-1.9.9-2.2Z" fill="currentColor" stroke="none"/>',
            'youtube'   => '<rect x="2.5" y="5.2" width="19" height="13.6" rx="4"/><path d="m10 8.7 5.5 3.3-5.5 3.3z" fill="currentColor" stroke="none"/>',
            'tiktok'    => '<path d="M14 3v11.2a3.6 3.6 0 1 1-3-3.5"/><path d="M14 3c.5 3 2.1 4.8 5 5.2"/>',
            'facebook'  => '<path d="M13.7 21v-8h2.8l.4-3.1h-3.2V8c0-.9.3-1.5 1.6-1.5H17V3.7c-.3 0-1.3-.1-2.5-.1-2.5 0-4.2 1.5-4.2 4.3v2H7.5V13h2.8v8Z" fill="currentColor" stroke="none"/>',
            'linkedin'  => '<path d="M5 9v10M5 5.5v.1M10 19v-6c0-2 1.1-3.2 3-3.2s3 1.2 3 3.2v6M10 10v9"/><circle cx="5" cy="5.5" r="1" fill="currentColor" stroke="none"/>',
            'email'     => '<rect x="2.5" y="4.5" width="19" height="15" rx="2"/><path d="m3.5 6 8.5 7 8.5-7"/>',
            'phone'     => '<path d="M7 3.5 4.5 4.7c-.8.4-1.1 1.3-.8 2.1 2.2 6.1 7.4 11.3 13.5 13.5.8.3 1.7 0 2.1-.8l1.2-2.5-4.4-2.8-2 2c-2.7-1.2-4.9-3.4-6.1-6.1l2-2Z"/>',
            'website'   => '<circle cx="12" cy="12" r="9.2"/><path d="M2.9 12h18.2M12 2.8c2.4 2.5 3.6 5.6 3.6 9.2s-1.2 6.7-3.6 9.2c-2.4-2.5-3.6-5.6-3.6-9.2S9.6 5.3 12 2.8Z"/>',
            'link'      => '<path d="M10 13.8 8.4 15.4a3.4 3.4 0 0 1-4.8-4.8l3-3a3.4 3.4 0 0 1 4.8 0M14 10.2l1.6-1.6a3.4 3.4 0 0 1 4.8 4.8l-3 3a3.4 3.4 0 0 1-4.8 0M8.8 15.2l6.4-6.4"/>',
        );
        $name = isset( $paths[ $name ] ) ? $name : 'link';
        return '<svg class="tls-lms-contact-svg" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths[ $name ] . '</svg>';
    }

    /** Count distinct learners who completed a real private session with this tutor. */
    private static function private_learner_count( $tutor_id ) {
        global $wpdb;
        if ( ! class_exists( 'TLMS_Private_Sessions' ) || ! isset( $wpdb ) ) {
            return null;
        }
        $table = $wpdb->prefix . TLMS_Private_Sessions::TABLE_SESSIONS;
        $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
        if ( $found !== $table ) {
            return null;
        }
        $columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
        $columns = is_array( $columns ) ? $columns : array();
        $learner = in_array( 'learner_user_id', $columns, true )
            ? 'COALESCE(NULLIF(learner_user_id, 0), student_id)'
            : 'student_id';
        $exclude_tryouts = in_array( 'is_tryout', $columns, true ) ? ' AND COALESCE(is_tryout, 0) = 0' : '';
        $sql = "SELECT COUNT(DISTINCT {$learner}) FROM {$table} WHERE tutor_id = %d AND status = %s AND {$learner} IS NOT NULL AND {$learner} > 0{$exclude_tryouts}";
        return max( 0, (int) $wpdb->get_var( $wpdb->prepare( $sql, absint( $tutor_id ), 'completed' ) ) );
    }

    /** Public social/contact links from Tutor's profile fields; private contact stays hidden. */
    private static function public_contact_links( $user_id, $hide_contact = false ) {
        if ( $hide_contact || ! function_exists( 'tutor_utils' ) ) {
            return array();
        }
        $icons = tutor_utils()->tutor_user_social_icons();
        if ( ! is_array( $icons ) ) { return array(); }
        $links = array();
        foreach ( $icons as $meta_key => $definition ) {
            $value = get_user_meta( $user_id, $meta_key, true );
            if ( ! is_string( $value ) || '' === trim( $value ) || ! is_array( $definition ) ) { continue; }
            $label = ! empty( $definition['label'] ) ? sanitize_text_field( $definition['label'] ) : __( 'Contact', 'tutor-learning-suite' );
            $url   = '';
            if ( '_tutor_profile_contact_email' === $meta_key || false !== stripos( $meta_key, 'contact_email' ) ) {
                $email = sanitize_email( $value );
                if ( is_email( $email ) ) { $url = 'mailto:' . antispambot( $email ); }
            } elseif ( false !== stripos( $meta_key, 'whatsapp' ) && ! preg_match( '#^https?://#i', $value ) ) {
                $digits = preg_replace( '/\D+/', '', $value );
                if ( $digits ) { $url = 'https://wa.me/' . $digits; }
            } else {
                $url = esc_url_raw( $value, array( 'http', 'https' ) );
            }
            if ( '' === $url ) { continue; }
            $icon = self::contact_icon_name( $meta_key, $label );
            $links[] = array(
                'label' => $label,
                'url'   => $url,
                // Use suite-owned classes only. Tutor/theme social classes apply
                // masks and hide nested glyphs, which previously removed the
                // self-contained SVGs on this custom public profile.
                'classes' => 'tls-lms-contact-icon--' . sanitize_html_class( $icon ),
                'icon' => $icon,
            );
        }
        return $links;
    }

    /** Render one education or teaching-experience card on the public profile. */
    /** Tutor's private-session hourly rate, shown under "Book a session". */
    private static function private_hourly_rate_badge( $tutor_id ) {
        $settings = (array) get_user_meta( (int) $tutor_id, '_tlms_ps_private_settings', true );
        $rate = isset( $settings['hourly_rate'] ) ? (float) $settings['hourly_rate'] : 0.0;
        if ( $rate <= 0 ) return '';
        $ps = (array) get_option( 'tlms_ps_settings', array() );
        $currency = ! empty( $ps['currency'] ) ? (string) $ps['currency'] : 'DZD';
        return '<div class="tls-lms-hourly-rate" style="display:inline-flex;gap:8px;align-items:baseline;margin:4px 0 8px;padding:6px 12px;border-radius:999px;background:#eef4ff;color:#1e3a8a"><span style="font-size:12px;opacity:.8">' . esc_html__( 'Hourly rate', 'tutor-learning-suite' ) . '</span><strong>' . esc_html( number_format_i18n( $rate, 2 ) . ' ' . $currency ) . ' / ' . esc_html__( 'hour', 'tutor-learning-suite' ) . '</strong></div>';
    }

    private static function render_profile_records( $title, $rows, $kind ) {
        if ( empty( $rows ) || ! is_array( $rows ) ) { return ''; }
        $education = 'education' === $kind;
        $heading_icon = $education
            ? '<path d="M2.5 9 12 4l9.5 5-9.5 5-9.5-5Z"/><path d="M6.5 11.2v5.2c3.3 2.2 7.7 2.2 11 0v-5.2M21.5 9v6"/>'
            : '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M3 12h18M10 12v2h4v-2"/>';
        $html = '<section class="tls-lms-profile-records tls-lms-profile-records--' . ( $education ? 'education' : 'experience' ) . '"><h3><span class="tls-lms-record-heading-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $heading_icon . '</svg></span>' . esc_html( $title ) . '</h3><div class="tls-lms-profile-record-list">';
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) { continue; }
            if ( $education ) {
                $main = isset( $row['name'] ) ? sanitize_text_field( (string) $row['name'] ) : '';
                $place = isset( $row['place'] ) ? sanitize_text_field( (string) $row['place'] ) : '';
                $detail = isset( $row['date'] ) ? sanitize_text_field( (string) $row['date'] ) : '';
                if ( $detail && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $detail ) ) {
                    $detail = mysql2date( get_option( 'date_format' ), $detail );
                }
            } else {
                $main = isset( $row['job'] ) ? sanitize_text_field( (string) $row['job'] ) : '';
                $place = isset( $row['place'] ) ? sanitize_text_field( (string) $row['place'] ) : '';
                $years = isset( $row['years'] ) ? sanitize_text_field( (string) $row['years'] ) : '';
                $detail = $years ? sprintf( _n( '%s year of experience', '%s years of experience', absint( $years ), 'tutor-learning-suite' ), number_format_i18n( absint( $years ) ) ) : '';
            }
            if ( '' === $main && '' === $place && '' === $detail ) { continue; }
            $html .= '<article class="tls-lms-profile-record"><span class="tls-lms-record-mark" aria-hidden="true">' . ( $education ? '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2.5 9 12 4l9.5 5-9.5 5-9.5-5Z"/><path d="M6.5 11.2v5.2c3.3 2.2 7.7 2.2 11 0v-5.2"/></svg>' : '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M3 12h18"/></svg>' ) . '</span><div class="tls-lms-record-copy"><strong>' . esc_html( $main ?: $place ) . '</strong>';
            if ( $main && $place ) { $html .= '<span>' . esc_html( $place ) . '</span>'; }
            if ( $detail ) { $html .= '<small>' . esc_html( $detail ) . '</small>'; }
            $html .= '</div></article>';
        }
        $html .= '</div></section>';
        return $html;
    }

    public static function render_public_instructor( $user_id ) {
        $user_id = absint( $user_id );
        $user = get_userdata( $user_id );
        if ( ! $user || ! self::is_instructor( $user_id ) ) {
            return '<div class="tls-notice">' . esc_html__( 'Tutor profile not found.', 'tutor-learning-suite' ) . '</div>';
        }

        $verified = class_exists( 'Tlms_Ipe_Plugin' )
            ? (bool) Tlms_Ipe_Plugin::instance()->is_verified_instructor( $user_id )
            : ( 'verified' === (string) get_user_meta( $user_id, '_tlms_ipe_verification_status', true ) );
        $hide_contact = '1' === (string) get_user_meta( $user_id, '_tls_hide_public_contact', true );
        $title = get_user_meta( $user_id, '_tutor_profile_job_title', true );
        $bio = get_user_meta( $user_id, '_tutor_profile_bio', true );
        if ( ! is_string( $bio ) || '' === trim( $bio ) ) { $bio = get_user_meta( $user_id, 'description', true ); }

        $locations = self::profile_location_labels( $user_id );
        $types = array();
        $saved_types = self::profile_meta_array( $user_id, '_tlms_ipe_tutoring_types' );
        $type_choices = class_exists( 'Tlms_Ipe_Plugin' ) ? Tlms_Ipe_Plugin::tutoring_type_choices() : array(
            'online' => __( 'Online', 'tutor-learning-suite' ),
            'tutor_home' => __( "At tutor's home", 'tutor-learning-suite' ),
            'student_home' => __( "At student's home", 'tutor-learning-suite' ),
        );
        foreach ( $saved_types as $type ) {
            if ( isset( $type_choices[ $type ] ) ) { $types[] = $type_choices[ $type ]; }
        }

        $languages = array();
        $saved_languages = self::profile_meta_array( $user_id, '_tlms_ipe_spoken_languages' );
        $language_choices = class_exists( 'Tlms_Ipe_Plugin' ) ? Tlms_Ipe_Plugin::language_choices() : array();
        foreach ( $saved_languages as $language ) {
            if ( isset( $language_choices[ $language ] ) ) { $languages[] = $language_choices[ $language ]; }
        }

        $subjects = array();
        foreach ( self::profile_meta_array( $user_id, '_tlms_ipe_teach_categories' ) as $term_id ) {
            $term = get_term( absint( $term_id ), 'course-category' );
            if ( $term && ! is_wp_error( $term ) ) { $subjects[] = $term->name; }
        }
        $subjects = array_values( array_unique( $subjects ) );
        $education = self::profile_meta_array( $user_id, '_tlms_ipe_education' );
        $experience = self::profile_meta_array( $user_id, '_tlms_ipe_experience' );
        $contact_links = self::public_contact_links( $user_id, $hide_contact );

        $rating = function_exists( 'tutor_utils' ) ? tutor_utils()->get_instructor_ratings( $user_id ) : false;
        $rating_avg = is_object( $rating ) && isset( $rating->rating_avg ) ? (float) $rating->rating_avg : 0;
        $rating_count = is_object( $rating ) && isset( $rating->rating_count ) ? (int) $rating->rating_count : 0;
        $course_count = 0;
        $student_count = 0;
        if ( class_exists( '\Tutor\Models\CourseModel' ) ) {
            try { $course_count = (int) \Tutor\Models\CourseModel::get_course_count_by_instructor( $user_id ); } catch ( Throwable $e ) { $course_count = 0; }
        }
        if ( function_exists( 'tutor_utils' ) ) {
            try { $student_count = (int) tutor_utils()->get_total_students_by_instructor( $user_id ); } catch ( Throwable $e ) { $student_count = 0; }
        }
        $private_learner_count = self::private_learner_count( $user_id );
        $cover = function_exists( 'tutor_utils' ) ? tutor_utils()->get_cover_photo_url( $user_id ) : '';
        $cover_css = $cover ? 'background-image:linear-gradient(115deg,rgba(24,43,102,.94),rgba(53,83,176,.88)),url("' . esc_url_raw( $cover ) . '")' : '';
        $cover_style = $cover_css ? ' style="' . esc_attr( $cover_css ) . '"' : '';
        $accepts_private = class_exists( 'TLMS_Private_Sessions' ) && '1' === (string) get_user_meta( $user_id, '_tlms_ipe_accept_private_sessions', true );

        $html = '<main class="tls-lms-profile-layout"><div class="tls-lms-profile-content">';
        $html .= '<section class="tls-lms-profile-hero"' . $cover_style . '>';
        $html .= '<div class="tls-lms-profile-top"><div class="tls-lms-profile-avatar">' . get_avatar( $user_id, 120, '', $user->display_name ) . '</div><div class="tls-lms-profile-main">';
        $html .= '<div class="tls-lms-eyebrow">' . esc_html__( 'Tutor profile', 'tutor-learning-suite' ) . '</div><h1>' . esc_html( $user->display_name );
        if ( $verified ) { $html .= ' <span class="tls-verified" title="' . esc_attr__( 'Verified instructor', 'tutor-learning-suite' ) . '">✓ ' . esc_html__( 'Verified', 'tutor-learning-suite' ) . '</span>'; }
        $html .= '</h1>';
        if ( is_string( $title ) && '' !== trim( $title ) ) { $html .= '<p class="tls-lms-profile-title">' . esc_html( $title ) . '</p>'; }

        if ( $contact_links ) {
            $html .= '<nav class="tls-lms-contact-icons" aria-label="' . esc_attr__( 'Tutor contact links', 'tutor-learning-suite' ) . '">';
            foreach ( $contact_links as $link ) {
                $external = 0 === strpos( $link['url'], 'http://' ) || 0 === strpos( $link['url'], 'https://' );
                $class_attr = trim( 'tls-lms-contact-icon ' . ( $link['classes'] ?? '' ) );
                $html .= '<a class="' . esc_attr( $class_attr ) . '" href="' . esc_url( $link['url'] ) . '" aria-label="' . esc_attr( $link['label'] ) . '" title="' . esc_attr( $link['label'] ) . '"' . ( $external ? ' target="_blank" rel="noopener noreferrer nofollow"' : '' ) . '>';
                $html .= '<span class="tls-lms-contact-glyph" aria-hidden="true">' . self::contact_icon_svg( $link['icon'] ?? 'link' ) . '</span>';
                $html .= '<span class="screen-reader-text">' . esc_html( $link['label'] ) . '</span></a>';
            }
            $html .= '</nav>';
        }

        if ( is_string( $bio ) && '' !== trim( wp_strip_all_tags( $bio ) ) ) {
            $html .= '<div class="tls-lms-bio">' . wp_kses_post( wpautop( $bio ) ) . '</div>';
        }
        $html .= '<ul class="tls-lms-profile-stats">';
        $html .= '<li><strong>' . ( $rating_count ? esc_html( number_format_i18n( $rating_avg, 1 ) ) : '–' ) . '</strong><span>★ ' . esc_html( sprintf( _n( '%s review', '%s reviews', $rating_count, 'tutor-learning-suite' ), number_format_i18n( $rating_count ) ) ) . '</span></li>';
        $html .= '<li><strong>' . esc_html( number_format_i18n( $course_count ) ) . '</strong><span>' . esc_html__( 'Courses', 'tutor-learning-suite' ) . '</span></li>';
        $html .= '<li><strong>' . esc_html( number_format_i18n( $student_count ) ) . '</strong><span>' . esc_html__( 'Learners', 'tutor-learning-suite' ) . '</span></li>';
        if ( null !== $private_learner_count ) { $html .= '<li><strong>' . esc_html( number_format_i18n( $private_learner_count ) ) . '</strong><span>' . esc_html__( 'Private learners', 'tutor-learning-suite' ) . '</span></li>'; }
        $html .= '</ul></div></div>';

        $has_teaching_details = $locations || $types || $languages || $subjects;
        if ( $has_teaching_details ) {
            $summary_bits = array();
            if ( $locations ) { $summary_bits[] = sprintf( _n( '%s location', '%s locations', count( $locations ), 'tutor-learning-suite' ), number_format_i18n( count( $locations ) ) ); }
            if ( $subjects ) { $summary_bits[] = sprintf( _n( '%s subject', '%s subjects', count( $subjects ), 'tutor-learning-suite' ), number_format_i18n( count( $subjects ) ) ); }
            $html .= '<details class="tls-lms-profile-drawer"><summary><span><strong>' . esc_html__( 'Teaching details', 'tutor-learning-suite' ) . '</strong><small>' . esc_html( implode( ' · ', $summary_bits ) ) . '</small></span><span class="tls-lms-drawer-chevron" aria-hidden="true">⌄</span></summary><div class="tls-lms-profile-drawer-body">';
            if ( $locations ) { $html .= '<div class="tls-lms-drawer-detail"><strong>' . esc_html__( 'Locations', 'tutor-learning-suite' ) . '</strong><p>' . esc_html( implode( ', ', $locations ) ) . '</p></div>'; }
            if ( $subjects ) {
                $html .= '<div class="tls-lms-drawer-detail"><strong>' . esc_html__( 'Subjects', 'tutor-learning-suite' ) . '</strong><div class="tls-lms-profile-subject-chips">';
                foreach ( $subjects as $subject ) { $html .= '<span>' . esc_html( $subject ) . '</span>'; }
                $html .= '</div></div>';
            }
            if ( $languages ) { $html .= '<div class="tls-lms-drawer-detail"><strong>' . esc_html__( 'Languages', 'tutor-learning-suite' ) . '</strong><p>' . esc_html( implode( ', ', $languages ) ) . '</p></div>'; }
            if ( $types ) { $html .= '<div class="tls-lms-drawer-detail"><strong>' . esc_html__( 'Teaching methods', 'tutor-learning-suite' ) . '</strong><p>' . esc_html( implode( ' · ', $types ) ) . '</p></div>'; }
            $html .= '</div></details>';
        }
        if ( $education || $experience ) {
            $credential_summary = array();
            if ( $education ) { $credential_summary[] = sprintf( _n( '%s education record', '%s education records', count( $education ), 'tutor-learning-suite' ), number_format_i18n( count( $education ) ) ); }
            if ( $experience ) { $credential_summary[] = sprintf( _n( '%s experience record', '%s experience records', count( $experience ), 'tutor-learning-suite' ), number_format_i18n( count( $experience ) ) ); }
            $html .= '<details class="tls-lms-profile-drawer tls-lms-profile-drawer--credentials"><summary><span><strong>' . esc_html__( 'Education & experience', 'tutor-learning-suite' ) . '</strong><small>' . esc_html( implode( ' · ', $credential_summary ) ) . '</small></span><span class="tls-lms-drawer-chevron" aria-hidden="true">⌄</span></summary><div class="tls-lms-profile-drawer-body tls-lms-profile-drawer-body--credentials"><div class="tls-lms-profile-credentials">';
            $html .= self::render_profile_records( __( 'Education', 'tutor-learning-suite' ), $education, 'education' );
            $html .= self::render_profile_records( __( 'Teaching experience', 'tutor-learning-suite' ), $experience, 'experience' );
            $html .= '</div></div></details>';
        }
        $html .= '</section>';
        $html .= '<section class="tls-lms-section tls-lms-profile-courses"><div class="tls-lms-section-heading"><h2>' . esc_html__( 'Courses by this tutor', 'tutor-learning-suite' ) . '</h2></div>' . self::course_cards( array( 'author' => $user_id, 'limit' => 6 ) ) . '</section>';
        $html .= '</div>';

        if ( $accepts_private ) {
            $html .= '<aside class="tls-lms-profile-sidebar"><section class="tls-lms-booking-sidebar" id="tls-private-availability"><div class="tls-lms-booking-sidebar-head"><span>' . esc_html__( 'Private tutoring', 'tutor-learning-suite' ) . '</span><h2>' . esc_html__( 'Book a session', 'tutor-learning-suite' ) . '</h2>' . self::private_hourly_rate_badge( $user_id ) . '<p>' . esc_html__( 'Choose an available time and tell the tutor what you need. The tutor will confirm the final schedule and price.', 'tutor-learning-suite' ) . '</p></div>';
            $html .= self::calendar_markup( $user_id );
            $GLOBALS['tls_profile_booking_context'] = true;
            $html .= do_shortcode( '[tlms_private_booking tutor_id="' . $user_id . '"]' );
            unset( $GLOBALS['tls_profile_booking_context'] );
            $html .= '</section></aside>';
        }
        return $html . '</main>';
    }

    private static function meta_lines( $value ) {
        if ( is_string( $value ) ) {
            $decoded = json_decode( $value, true );
            if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
                $value = $decoded;
            }
        }
        if ( ! is_array( $value ) ) {
            $value = array( $value );
        }
        $lines = array();
        foreach ( $value as $item ) {
            if ( is_array( $item ) ) {
                $parts = array();
                foreach ( $item as $part ) {
                    if ( is_scalar( $part ) && '' !== trim( (string) $part ) ) {
                        $parts[] = sanitize_text_field( (string) $part );
                    }
                }
                $item = implode( ' · ', $parts );
            }
            if ( is_scalar( $item ) && '' !== trim( (string) $item ) ) {
                $lines[] = sanitize_text_field( (string) $item );
            }
        }
        return array_values( array_unique( $lines ) );
    }

    private static function render_profile_meta( $title, $items ) {
        if ( empty( $items ) ) {
            return '';
        }
        $html = '<section class="tls-lms-section"><h2>' . esc_html( $title ) . '</h2><ul class="tls-lms-chip-list">';
        foreach ( $items as $item ) {
            $html .= '<li>' . esc_html( $item ) . '</li>';
        }
        return $html . '</ul></section>';
    }

    /** URL of the course catalog (Tutor archive page or the course post-type archive). */
    private static function catalog_url() {
        if ( function_exists( 'tutor_utils' ) ) {
            $url = tutor_utils()->course_archive_page_url();
            if ( $url ) { return $url; }
        }
        $post_type = function_exists( 'tutor' ) && ! empty( tutor()->course_post_type ) ? tutor()->course_post_type : 'courses';
        $link = get_post_type_archive_link( $post_type );
        return $link ? $link : home_url( '/' );
    }

    /** Minimal search form, used only when the full [tcsp_filters] bar is unavailable. */
    private static function fallback_search_form() {
        $s = isset( $_GET['s'] ) && ! is_array( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        $cur = isset( $_GET['tcsp_category'] ) ? (array) wp_unslash( $_GET['tcsp_category'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification
        $cur = array_map( 'sanitize_title', $cur );
        $price = isset( $_GET['tcsp_price'] ) && ! is_array( $_GET['tcsp_price'] ) ? sanitize_key( wp_unslash( $_GET['tcsp_price'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        $html = '<form class="tls-lms-search" method="get" action="' . esc_url( self::catalog_url() ) . '" role="search"><label class="tls-lms-search-main"><span class="screen-reader-text">' . esc_html__( 'Search courses', 'tutor-learning-suite' ) . '</span><input type="search" name="s" value="' . esc_attr( $s ) . '" placeholder="' . esc_attr__( 'Search courses, subjects or tutors', 'tutor-learning-suite' ) . '"></label>';
        $terms = taxonomy_exists( 'course-category' ) ? get_terms( array( 'taxonomy' => 'course-category', 'hide_empty' => true, 'parent' => 0 ) ) : array();
        if ( $terms && ! is_wp_error( $terms ) ) {
            $html .= '<label><span class="screen-reader-text">' . esc_html__( 'Category', 'tutor-learning-suite' ) . '</span><select name="tcsp_category[]"><option value="">' . esc_html__( 'All categories', 'tutor-learning-suite' ) . '</option>';
            foreach ( $terms as $term ) { $html .= '<option value="' . esc_attr( $term->slug ) . '"' . selected( in_array( $term->slug, $cur, true ), true, false ) . '>' . esc_html( $term->name ) . '</option>'; }
            $html .= '</select></label>';
        }
        $html .= '<label><span class="screen-reader-text">' . esc_html__( 'Price', 'tutor-learning-suite' ) . '</span><select name="tcsp_price"><option value="">' . esc_html__( 'Any price', 'tutor-learning-suite' ) . '</option><option value="free"' . selected( $price, 'free', false ) . '>' . esc_html__( 'Free', 'tutor-learning-suite' ) . '</option><option value="paid"' . selected( $price, 'paid', false ) . '>' . esc_html__( 'Paid', 'tutor-learning-suite' ) . '</option></select></label>';
        return $html . '<button type="submit" class="tls-lms-button">' . esc_html__( 'Search', 'tutor-learning-suite' ) . '</button></form>';
    }

    /** Sponsored carousel pulled out of the filter bar so it can sit above the results, not in the sidebar. */
    private static $sponsored_html = '';

    private static function filter_panel() {
        $bar = shortcode_exists( 'tcsp_filters' ) ? trim( (string) do_shortcode( '[tcsp_filters]' ) ) : '';
        self::$sponsored_html = '';
        if ( '' !== $bar ) {
            if ( preg_match( '#<section class="tcsp-sponsored-carousel".*?</section>#s', $bar, $m ) ) {
                self::$sponsored_html = $m[0];
                $bar = str_replace( $m[0], '', $bar );
            }
            $bar = preg_replace( '#<div class="tcsp-normal-results-heading">.*?</div>#s', '', $bar );
        }
        return '<div class="tls-lms-filter-panel">' . ( '' !== $bar ? $bar : self::fallback_search_form() ) . '</div>';
    }

    /**
     * Search bar + filters + suite course cards + pagination for a course query.
     * $query null = the main query (archive template).
     */
    public static function render_catalog( $query = null, $opts = array() ) {
        $opts = wp_parse_args( $opts, array( 'header' => true, 'filters' => true, 'paged' => 0 ) );
        $is_main = null === $query;
        if ( $is_main ) { global $wp_query; $query = $wp_query; }
        $html = '<main class="tls-lms-catalog">';
        if ( $opts['header'] ) {
            $html .= '<header class="tls-lms-catalog-header"><div><div class="tls-lms-eyebrow">' . esc_html__( 'UStudy learning', 'tutor-learning-suite' ) . '</div><h1>' . esc_html__( 'Explore courses', 'tutor-learning-suite' ) . '</h1><p>' . esc_html__( 'Compare course content, teaching format, tutor credentials and price before you enroll.', 'tutor-learning-suite' ) . '</p></div></header>';
        }
        $html .= '<div class="tls-lms-catalog-body' . ( $opts['filters'] ? '' : ' is-plain' ) . '">';
        if ( $opts['filters'] ) { $html .= '<aside class="tls-lms-filter-side" aria-label="' . esc_attr__( 'Search and filters', 'tutor-learning-suite' ) . '">' . self::filter_panel() . '</aside>'; }
        $html .= '<section class="tls-lms-results">' . self::$sponsored_html;
        if ( $query instanceof WP_Query && ! empty( $query->posts ) ) {
            $total = (int) $query->found_posts;
            $html .= '<p class="tls-lms-result-count">' . esc_html( sprintf( _n( '%s course found', '%s courses found', $total, 'tutor-learning-suite' ), number_format_i18n( $total ) ) ) . '</p>';
            $html .= '<div class="tls-lms-course-grid">';
            foreach ( $query->posts as $post ) { $html .= self::course_card( $post->ID ); }
            $html .= '</div>';
            if ( $is_main ) {
                $html .= '<nav class="tls-lms-pagination" aria-label="' . esc_attr__( 'Course pagination', 'tutor-learning-suite' ) . '">' . get_the_posts_pagination( array( 'mid_size' => 2 ) ) . '</nav>';
            } elseif ( $query->max_num_pages > 1 ) {
                $links = paginate_links( array( 'base' => esc_url_raw( add_query_arg( 'current_page', '%#%' ) ), 'format' => '', 'current' => max( 1, (int) $opts['paged'] ), 'total' => (int) $query->max_num_pages, 'mid_size' => 2, 'type' => 'list' ) );
                $html .= '<nav class="tls-lms-pagination" aria-label="' . esc_attr__( 'Course pagination', 'tutor-learning-suite' ) . '">' . wp_kses_post( (string) $links ) . '</nav>';
            }
        } else {
            $html .= '<p class="tls-lms-empty">' . esc_html__( 'No courses match these filters yet.', 'tutor-learning-suite' ) . '</p>';
        }
        $html .= '</section></div>';
        return $html . '</main>';
    }

    public static function render_course_archive() {
        return self::render_catalog( null );
    }

    /** Catalog for shortcodes/pages: runs its own course query so TCSP filters still apply. */
    private static function render_catalog_shortcode( $atts ) {
        $post_type = function_exists( 'tutor' ) && ! empty( tutor()->course_post_type ) ? tutor()->course_post_type : 'courses';
        $default_limit = function_exists( 'tutor_utils' ) ? absint( tutor_utils()->get_option( 'courses_per_page', 12 ) ) : 12;
        $limit = ! empty( $atts['limit'] ) ? min( 48, max( 1, absint( $atts['limit'] ) ) ) : max( 1, $default_limit );
        $paged = 1;
        if ( isset( $_GET['current_page'] ) && ! is_array( $_GET['current_page'] ) ) { $paged = max( 1, absint( $_GET['current_page'] ) ); } // phpcs:ignore WordPress.Security.NonceVerification
        elseif ( get_query_var( 'paged' ) ) { $paged = max( 1, absint( get_query_var( 'paged' ) ) ); }
        $search = '';
        foreach ( array( 's', 'tls_q' ) as $key ) {
            if ( isset( $_GET[ $key ] ) && ! is_array( $_GET[ $key ] ) && '' === $search ) { $search = sanitize_text_field( wp_unslash( $_GET[ $key ] ) ); } // phpcs:ignore WordPress.Security.NonceVerification
        }
        $args = array(
            'post_type' => $post_type, 'post_status' => 'publish', 'posts_per_page' => $limit, 'paged' => $paged, 's' => $search,
            'meta_query' => array( array( 'relation' => 'OR', array( 'key' => '_tcsp_sponsorship_checkout_product', 'compare' => 'NOT EXISTS' ), array( 'key' => '_tcsp_sponsorship_checkout_product', 'value' => '1', 'compare' => '!=' ) ) ),
        );
        if ( ! empty( $atts['author'] ) ) { $args['author'] = absint( $atts['author'] ); }
        if ( ! empty( $atts['category'] ) && taxonomy_exists( 'course-category' ) ) {
            $args['tax_query'] = array( array( 'taxonomy' => 'course-category', 'field' => 'slug', 'terms' => array_map( 'sanitize_title', array_filter( array_map( 'trim', explode( ',', (string) $atts['category'] ) ) ) ) ) );
        }
        $query = new WP_Query( $args );
        $show_filters = ! isset( $atts['filters'] ) || ! in_array( strtolower( (string) $atts['filters'] ), array( 'no', 'false', '0', 'off' ), true );
        return self::render_catalog( $query, array( 'header' => false, 'filters' => $show_filters, 'paged' => $paged ) );
    }

    /** Tutor's own [tutor_course] shortcode (default card, no filters) is replaced by the suite catalog. */
    public static function intercept_tutor_course_shortcode( $return, $tag, $attr, $m ) {
        if ( 'tutor_course' !== $tag || is_admin() || isset( $_GET['elementor-preview'] ) || ( function_exists( 'wp_is_serving_rest_request' ) && wp_is_serving_rest_request() ) ) { return $return; } // phpcs:ignore WordPress.Security.NonceVerification
        return self::render_catalog_shortcode( is_array( $attr ) ? $attr : array() );
    }

    /** Tutor course cards rendered by Tutor itself (widgets, related courses, builders) use the suite card. */
    public static function override_tutor_template( $location, $template ) {
        $name = trim( str_replace( array( '\\', '.' ), '/', (string) $template ), '/' );
        if ( 'loop/course' === $name && ! is_admin() && ! get_query_var( 'tutor_dashboard_page' ) ) {
            $file = TLS_DIR . 'templates/loop-course.php';
            if ( file_exists( $file ) ) { return $file; }
        }
        return $location;
    }

    /** Load the filter script/styles on any page that shows the catalog through a shortcode. */
    public static function page_needs_catalog_assets( $current ) {
        if ( $current || ! is_singular() ) { return $current; }
        $post = get_post( get_queried_object_id() );
        if ( ! $post || ! is_string( $post->post_content ) ) { return $current; }
        foreach ( array( 'tutor_course', 'tutor_suite_courses' ) as $tag ) {
            if ( has_shortcode( $post->post_content, $tag ) ) { return true; }
        }
        return $current;
    }

    /** Plain grid of suite course cards (profile "Courses by this tutor"). */
    private static function course_cards( $args = array() ) {
        $post_type = function_exists( 'tutor' ) && ! empty( tutor()->course_post_type ) ? tutor()->course_post_type : 'courses';
        $limit = isset( $args['limit'] ) ? max( 1, absint( $args['limit'] ) ) : 12;
        $paged = isset( $_GET['tutor_courses_page'] ) && ! is_array( $_GET['tutor_courses_page'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            ? max( 1, absint( wp_unslash( $_GET['tutor_courses_page'] ) ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            : 1;
        $query = new WP_Query( array(
            'post_type' => $post_type,
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'author' => isset( $args['author'] ) ? absint( $args['author'] ) : 0,
            'paged' => $paged,
            'no_found_rows' => false,
            'ignore_sticky_posts' => true,
        ) );
        if ( ! $query->have_posts() ) {
            return '<p class="tls-lms-empty">' . esc_html__( 'No courses found.', 'tutor-learning-suite' ) . '</p>';
        }
        $html = '<div class="tls-lms-course-grid">';
        foreach ( $query->posts as $post ) { $html .= self::course_card( $post->ID ); }
        $html .= '</div>';
        if ( $query->max_num_pages > 1 ) {
            $base = add_query_arg( 'tutor_courses_page', 999999999, remove_query_arg( 'tutor_courses_page' ) );
            $base = str_replace( '999999999', '%#%', esc_url_raw( $base ) );
            $links = paginate_links( array(
                'base' => $base,
                'format' => '',
                'current' => $paged,
                'total' => (int) $query->max_num_pages,
                'type' => 'list',
                'prev_text' => esc_html__( 'Previous', 'tutor-learning-suite' ),
                'next_text' => esc_html__( 'Next', 'tutor-learning-suite' ),
            ) );
            if ( $links ) {
                $html .= '<nav class="tls-lms-profile-pagination" aria-label="' . esc_attr__( 'Tutor course pages', 'tutor-learning-suite' ) . '">' . wp_kses_post( $links ) . '</nav>';
            }
        }
        return $html;
    }

    private static function card_icon( $name ) {
        $p = array(
            'prerecorded' => '<circle cx="12" cy="12" r="9"/><path d="M10 8.5l5.5 3.5-5.5 3.5z" fill="currentColor"/>',
            'direct'      => '<circle cx="12" cy="12" r="2.2" fill="currentColor"/><path d="M7.8 7.8a6 6 0 000 8.4M16.2 7.8a6 6 0 010 8.4M4.9 4.9a10 10 0 000 14.2M19.1 4.9a10 10 0 010 14.2"/>',
            'hybrid'      => '<path d="M12 3l9 5-9 5-9-5z"/><path d="M3 13l9 5 9-5"/>',
            'group'       => '<circle cx="12" cy="7" r="3"/><circle cx="5.5" cy="10" r="2.3"/><circle cx="18.5" cy="10" r="2.3"/><path d="M6.5 20c0-3.3 2.5-5.5 5.5-5.5s5.5 2.2 5.5 5.5M1.5 18c0-2.3 1.7-4 4-4M22.5 18c0-2.3-1.7-4-4-4"/>',
            'one_to_one'  => '<circle cx="7" cy="8" r="3"/><circle cx="17" cy="8" r="3"/><path d="M2 20c0-3 2.2-5 5-5s5 2 5 5M12 20c0-3 2.2-5 5-5s5 2 5 5"/>',
            'beginner'     => '<rect x="4" y="14" width="4" height="6" rx="1" fill="currentColor"/><rect x="10" y="9" width="4" height="11" rx="1"/><rect x="16" y="4" width="4" height="16" rx="1"/>',
            'intermediate' => '<rect x="4" y="14" width="4" height="6" rx="1" fill="currentColor"/><rect x="10" y="9" width="4" height="11" rx="1" fill="currentColor"/><rect x="16" y="4" width="4" height="16" rx="1"/>',
            'expert'       => '<rect x="4" y="14" width="4" height="6" rx="1" fill="currentColor"/><rect x="10" y="9" width="4" height="11" rx="1" fill="currentColor"/><rect x="16" y="4" width="4" height="16" rx="1" fill="currentColor"/>',
            'all_levels'   => '<path d="m12 3 9 5-9 5-9-5 9-5z"/><path d="m3 12 9 5 9-5M3 16l9 5 9-5"/>',
            'shield'       => '<path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11z"/><path d="m9 12 2 2 4-4"/>',
            'book'         => '<path d="M4 5.5A2.5 2.5 0 016.5 3H20v17H6.5A2.5 2.5 0 014 17.5z"/><path d="M4 17.5A2.5 2.5 0 016.5 15H20M8 7h8M8 10h7"/>',
            'clipboard'    => '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V2h6v2M8 10h8M8 14h8M8 18h5"/>',
            'star'     => '<path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/>',
            'users'    => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.6 2.9-6 6.5-6s6.5 2.4 6.5 6M16 4.5a3.5 3.5 0 010 7M18 14.3c2 .7 3.5 2.6 3.5 5.7"/>',
            'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            'video'    => '<rect x="3" y="6" width="13" height="12" rx="2"/><path d="M16 10l5-3v10l-5-3"/>',
        );
        return '<svg class="tls-ico" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ( isset( $p[ $name ] ) ? $p[ $name ] : '' ) . '</svg>';
    }

    /** Icon-only badge; the text appears on hover/focus. */
    private static function icon_chip( $icon, $text, $kind ) {
        $tip = $kind . ': ' . $text;
        return '<span class="tls-chip tls-chip-icon tls-chip--' . esc_attr( sanitize_html_class( $icon ) ) . '" tabindex="0" data-tip="' . esc_attr( $tip ) . '" aria-label="' . esc_attr( $tip ) . '">' . self::card_icon( $icon ) . '</span>';
    }

    /** Icon + value stat; the full wording appears on hover/focus. */
    private static function icon_stat( $icon, $value_html, $tip, $class = '' ) {
        return '<li class="tls-stat' . ( $class ? ' ' . esc_attr( $class ) : '' ) . '" tabindex="0" data-tip="' . esc_attr( $tip ) . '" aria-label="' . esc_attr( wp_strip_all_tags( $tip ) ) . '">' . self::card_icon( $icon ) . '<span>' . $value_html . '</span></li>';
    }

    /** Round blue verification tick used on cards and profiles. */
    public static function verified_icon() {
        return '<span class="tls-verified-ico" title="' . esc_attr__( 'Verified tutor', 'tutor-learning-suite' ) . '"><svg viewBox="0 0 20 20" width="16" height="16" aria-hidden="true"><circle cx="10" cy="10" r="10" fill="#1d9bf0"/><path d="M5.5 10.3l3 3 6-6.3" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg><span class="screen-reader-text">' . esc_html__( 'Verified tutor', 'tutor-learning-suite' ) . '</span></span>';
    }

    /** The suite course card: type/format/level badges, tutor, rating, learners, description, categories and price. */
    public static function course_card( $course_id ) {
        $course_id = absint( $course_id );
        if ( ! $course_id || 'publish' !== get_post_status( $course_id ) ) { return ''; }

        $author_id = (int) get_post_field( 'post_author', $course_id );
        $author = get_userdata( $author_id );
        $verified = class_exists( 'Tlms_Ipe_Plugin' ) && method_exists( 'Tlms_Ipe_Plugin', 'instance' )
            ? (bool) Tlms_Ipe_Plugin::instance()->is_verified_instructor( $author_id )
            : ( 'verified' === (string) get_user_meta( $author_id, '_tlms_ipe_verification_status', true ) );

        $price = function_exists( 'tutor_utils' ) ? tutor_utils()->get_course_price( $course_id ) : '';
        $duration = function_exists( 'tutor_utils' ) ? tutor_utils()->get_course_duration( $course_id, false ) : '';
        $rating = function_exists( 'tutor_utils' ) ? tutor_utils()->get_course_rating( $course_id ) : false;
        $students = function_exists( 'tutor_utils' ) ? (int) tutor_utils()->count_enrolled_users_by_course( $course_id ) : 0;
        $level = function_exists( 'get_tutor_course_level' ) ? get_tutor_course_level( $course_id ) : '';
        $format_data = function_exists( 'tcf_get_course_format_data' ) ? tcf_get_course_format_data( $course_id ) : array();
        $course_type = ! empty( $format_data['type_label'] ) ? $format_data['type_label'] : '';
        $delivery = ! empty( $format_data['format_label'] ) ? $format_data['format_label'] : '';
        $sessions = ! empty( $format_data['sessions'] ) ? absint( $format_data['sessions'] ) : 0;

        $names = static function( $taxonomy ) use ( $course_id ) {
            if ( ! taxonomy_exists( $taxonomy ) ) { return array(); }
            $terms = get_the_terms( $course_id, $taxonomy );
            if ( ! $terms || is_wp_error( $terms ) ) { return array(); }
            return array_values( array_filter( array_map( 'sanitize_text_field', wp_list_pluck( $terms, 'name' ) ) ) );
        };
        $categories = $names( 'course-category' );
        $url = get_permalink( $course_id );
        $title = get_the_title( $course_id );

        $h = '<article class="tls-course-card tls-lms-course-card">';
        $h .= '<a class="tls-course-image" href="' . esc_url( $url ) . '" aria-label="' . esc_attr( sprintf( __( 'View course: %s', 'tutor-learning-suite' ), $title ) ) . '">';
        $h .= has_post_thumbnail( $course_id ) ? get_the_post_thumbnail( $course_id, 'medium_large', array( 'loading' => 'lazy', 'alt' => esc_attr( $title ) ) ) : '<span class="tls-course-noimg"></span>';
        $sticker = '';
        if ( class_exists( 'TCSP_Plugin' ) ) { ob_start(); TCSP_Plugin::instance()->render_sponsored_sticker( $course_id ); $sticker = trim( (string) ob_get_clean() ); }
        if ( $course_type || $delivery || $level ) {
            $h .= '<span class="tls-course-badges tls-lms-course-badges">';
            $level_key = sanitize_key( (string) get_post_meta( $course_id, '_tutor_course_level', true ) );
            if ( $course_type ) { $h .= self::icon_chip( ! empty( $format_data['type'] ) ? $format_data['type'] : 'prerecorded', $course_type, __( 'Course type', 'tutor-learning-suite' ) ); }
            if ( $delivery ) { $h .= self::icon_chip( ! empty( $format_data['format'] ) ? $format_data['format'] : 'group', $delivery, __( 'Format', 'tutor-learning-suite' ) ); }
            if ( $level ) { $h .= self::icon_chip( $level_key ? $level_key : 'all_levels', $level, __( 'Level', 'tutor-learning-suite' ) ); }
            $h .= '</span>';
        }
        $h .= $sticker;
        $h .= '</a><div class="tls-course-copy">';
        $h .= '<h3><a href="' . esc_url( $url ) . '">' . esc_html( $title ) . '</a></h3>';
        if ( $author ) {
            $h .= '<p class="tls-course-tutor"><a href="' . esc_url( self::profile_url( $author_id ) ) . '">' . get_avatar( $author_id, 24, '', $author->display_name, array( 'class' => 'tls-mini-avatar' ) ) . '<span>' . esc_html( $author->display_name ) . '</span></a>' . ( $verified ? self::verified_icon() : '' ) . '</p>';
        }
        $h .= '<ul class="tls-course-meta">';
        if ( is_object( $rating ) && ! empty( $rating->rating_count ) ) {
            $h .= self::icon_stat( 'star', number_format_i18n( (float) $rating->rating_avg, 1 ) . ' <small>(' . number_format_i18n( (int) $rating->rating_count ) . ')</small>', sprintf( __( 'Rated %1$s from %2$s reviews', 'tutor-learning-suite' ), number_format_i18n( (float) $rating->rating_avg, 1 ), number_format_i18n( (int) $rating->rating_count ) ), 'tls-rate' );
        } else {
            $h .= self::icon_stat( 'star', esc_html__( 'New', 'tutor-learning-suite' ), __( 'No reviews yet', 'tutor-learning-suite' ), 'tls-rate' );
        }
        if ( $students ) { $h .= self::icon_stat( 'users', esc_html( number_format_i18n( $students ) ), sprintf( _n( '%s enrolled learner', '%s enrolled learners', $students, 'tutor-learning-suite' ), number_format_i18n( $students ) ) ); }
        if ( $duration ) { $h .= self::icon_stat( 'clock', wp_kses_post( $duration ), __( 'Course duration', 'tutor-learning-suite' ) ); }
        if ( $sessions ) { $h .= self::icon_stat( 'video', esc_html( number_format_i18n( $sessions ) ), sprintf( _n( '%s live session', '%s live sessions', $sessions, 'tutor-learning-suite' ), $sessions ) ); }
        $h .= '</ul>';
        $description = get_the_excerpt( $course_id );
        if ( ! $description ) {
            $description = (string) get_post_field( 'post_content', $course_id );
        }
        $description = wp_trim_words( wp_strip_all_tags( strip_shortcodes( $description ) ), 22, '…' );
        if ( $description ) {
            $h .= '<p class="tls-course-description"><span class="tls-course-description-text">' . esc_html( $description ) . '</span><a class="tls-course-more" href="' . esc_url( $url ) . '">' . esc_html__( 'More', 'tutor-learning-suite' ) . '</a></p>';
        }
        if ( $categories ) {
            $h .= '<p class="tls-course-category" aria-label="' . esc_attr__( 'Course categories', 'tutor-learning-suite' ) . '">' . self::card_icon( 'book' ) . '<span>' . esc_html( implode( ' · ', array_slice( $categories, 0, 2 ) ) ) . '</span></p>';
        }
        $h .= '<footer><span class="tls-price">' . ( $price ? wp_kses_post( $price ) : esc_html__( 'Free', 'tutor-learning-suite' ) ) . '</span><a href="' . esc_url( $url ) . '">' . esc_html__( 'View course', 'tutor-learning-suite' ) . ' <span aria-hidden="true">→</span></a></footer>';
        return $h . '</div></article>';
    }

    private static function is_student_enrolled_in_course( $student_id, $course_id ) {
        $enrollments = get_posts( array(
            'post_type'      => 'tutor_enrolled',
            'post_status'    => array( 'completed', 'pending' ),
            'author'         => absint( $student_id ),
            'post_parent'    => absint( $course_id ),
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ) );
        return ! empty( $enrollments );
    }

    /** Return all learner accounts linked to this guardian; permission is checked separately. */
    private static function guardian_linked_children( $parent_id ) {
        return get_users( array(
            'meta_key'     => self::GUARDIAN_META,
            'meta_value'   => absint( $parent_id ),
            'fields'       => 'all',
            'number'       => 50,
            'orderby'      => 'display_name',
            'order'        => 'ASC',
        ) );
    }

    public static function course_search_shortcode( $atts ) {
        $atts = shortcode_atts( array( 'limit' => 12, 'filters' => 'yes', 'category' => '', 'author' => 0 ), $atts, 'tutor_suite_courses' );
        return self::render_catalog_shortcode( $atts );
    }

    public static function tutor_profile_shortcode( $atts ) {
        $atts = shortcode_atts( array( 'id' => 0 ), $atts, 'tutor_suite_tutor' );
        $id = absint( $atts['id'] );
        if ( ! $id && isset( $_GET['tutor_id'] ) ) {
            $id = absint( $_GET['tutor_id'] );
        }
        return self::render_public_instructor( $id );
    }

    public static function filter_contact_icons( $icons ) {
        if ( ! is_array( $icons ) ) {
            return $icons;
        }
        $username = sanitize_user( (string) get_query_var( 'tutor_profile_username' ) );
        $user = $username ? get_user_by( 'slug', $username ) : false;
        if ( $user && '1' === get_user_meta( $user->ID, '_tls_hide_public_contact', true ) ) {
            foreach ( array_keys( $icons ) as $key ) {
                if ( preg_match( '/(?:contact_email|whatsapp|telegram|phone|mobile|telephone)/i', (string) $key ) ) {
                    unset( $icons[ $key ] );
                }
            }
        }
        return $icons;
    }

    public static function render_family_page() {
        if ( ! is_user_logged_in() ) {
            return '<div class="tls-lms-panel"><p>' . esc_html__( 'Please sign in to manage family access.', 'tutor-learning-suite' ) . '</p></div>';
        }
        $user_id = get_current_user_id();
        $family_page = isset( $_GET['tls_family_page'] ) ? max( 1, absint( $_GET['tls_family_page'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $family_per_page = 20;
        $family_query = new WP_User_Query( array(
            'meta_key' => self::GUARDIAN_META,
            'meta_value' => $user_id,
            'fields' => array( 'ID', 'display_name' ),
            'number' => $family_per_page,
            'offset' => ( $family_page - 1 ) * $family_per_page,
            'orderby' => 'ID',
            'order' => 'ASC',
        ) );
        $children = $family_query->get_results();
        $family_total_pages = max( 1, (int) ceil( $family_query->get_total() / $family_per_page ) );
        foreach ( $children as $linked_child ) {
            self::sync_age_policy( $linked_child->ID );
            if ( 'unknown' === self::account_age_status( $linked_child->ID ) ) {
                // Legacy links without an encrypted DOB must not retain inherited access.
                update_user_meta( $linked_child->ID, self::PARENT_PROGRESS_META, '0' );
                update_user_meta( $linked_child->ID, self::PARENT_BOOKING_META, '0' );
                delete_user_meta( $linked_child->ID, '_tls_parent_booking_consent_at' );
            }
        }
        $pending = get_user_meta( $user_id, self::PENDING_LINK_META, true );
        $guardian_band = TLS_Age_Safety::age_band( $user_id );
        $guardian_ready = 'adult' === $guardian_band;
        $html = '<section class="tls-lms-panel"><h1>' . esc_html__( 'Family & Guardian', 'tutor-learning-suite' ) . '</h1><p>' . esc_html__( 'Link family accounts and manage approved sharing. New links require student account acceptance and administrator review where applicable.', 'tutor-learning-suite' ) . '</p>';
        if ( class_exists( 'TLS_Feedback_Moderation' ) ) {
            $html .= TLS_Feedback_Moderation::render_child_feedback_notices( $user_id );
        }
        if ( isset( $_GET['tls_feedback_notice'] ) ) {
            $feedback_notice = sanitize_key( wp_unslash( $_GET['tls_feedback_notice'] ) );
            $feedback_notices = array(
                'updated' => array( 'success', __( 'Your decision was saved. Approved feedback has been sent to the site administrator for final moderation.', 'tutor-learning-suite' ) ),
                'expired' => array( 'warning', __( 'This approval form expired. Refresh the page and try again.', 'tutor-learning-suite' ) ),
                'denied'  => array( 'warning', __( 'We could not verify that this feedback still belongs to your approved child account. Refresh the page; if the problem continues, contact the site administrator.', 'tutor-learning-suite' ) ),
            );
            if ( isset( $feedback_notices[ $feedback_notice ] ) ) {
                $html .= '<div class="tls-lms-' . esc_attr( $feedback_notices[ $feedback_notice ][0] ) . '" role="status">' . esc_html( $feedback_notices[ $feedback_notice ][1] ) . '</div>';
            }
        }
        if ( isset( $_GET['tls_notice'] ) ) {
            $notice_key = sanitize_key( wp_unslash( $_GET['tls_notice'] ) );
            $notices = array(
                'child_free_enrollment_enabled' => array( 'success', __( 'Free-course self-enrolment is now enabled for this child.', 'tutor-learning-suite' ) ),
                'child_free_enrollment_disabled' => array( 'success', __( 'Free-course self-enrolment is now disabled for this child.', 'tutor-learning-suite' ) ),
                'child_free_enrollment_denied' => array( 'warning', __( 'The permission could not be changed. Confirm the guardian link is approved and your guardian account is verified.', 'tutor-learning-suite' ) ),
            );
            if ( isset( $notices[ $notice_key ] ) ) {
                $html .= '<div class="tls-lms-' . esc_attr( $notices[ $notice_key ][0] ) . '" role="status">' . esc_html( $notices[ $notice_key ][1] ) . '</div>';
            }
        }
        $has_parent = absint( get_user_meta( $user_id, self::GUARDIAN_META, true ) );
        $has_incoming_request = is_array( $pending ) && ! empty( $pending['parent_id'] ) && ! empty( $pending['expires'] ) && (int) $pending['expires'] >= time();
        if ( ! $guardian_ready && ! $has_parent && ! $has_incoming_request ) {
            $html .= '<div class="tls-lms-warning"><p>' . esc_html__( 'To create or manage family links, the guardian account must meet the configured adult-age threshold. Age information is encrypted; UStudy does not independently verify identity or legal authority.', 'tutor-learning-suite' ) . '</p>';
            if ( 'unknown' === $guardian_band && ! self::is_student_user( $user_id ) ) {
                $html .= '<form method="post" class="tls-lms-form">' . wp_nonce_field( 'tls_family_action', 'tls_family_nonce', true, false ) . '<label>' . esc_html__( 'Your date of birth', 'tutor-learning-suite' ) . '<input type="date" name="guardian_birth_date" max="' . esc_attr( wp_date( 'Y-m-d' ) ) . '" required></label><button class="tls-lms-button" name="tls_save_guardian_birth_date" value="1">' . esc_html__( 'Save age information', 'tutor-learning-suite' ) . '</button></form>';
            } elseif ( self::is_student_user( $user_id ) && 'unknown' === $guardian_band ) {
                $html .= '<p>' . esc_html__( 'Enter your date of birth in Privacy & Security before using family-management features.', 'tutor-learning-suite' ) . '</p>';
            }
            $html .= '</div>';
        }
        if ( isset( $_GET['tls_parent_token'] ) && is_string( $_GET['tls_parent_token'] ) ) {
            $token = sanitize_text_field( wp_unslash( $_GET['tls_parent_token'] ) );
            $request = get_user_meta( $user_id, self::PENDING_LINK_META, true );
            if ( is_array( $request ) && ! empty( $request['token_hash'] ) && ! empty( $request['expires'] ) && (int) $request['expires'] >= time() && hash_equals( (string) $request['token_hash'], hash( 'sha256', $token ) ) ) {
                $parent = get_userdata( absint( $request['parent_id'] ) );
                $html .= '<div class="tls-lms-invitation"><strong>' . esc_html( sprintf( __( '%s requested to link as your parent/guardian.', 'tutor-learning-suite' ), $parent ? $parent->display_name : __( 'A parent/guardian', 'tutor-learning-suite' ) ) ) . '</strong><p>' . esc_html__( 'Review the request before accepting. Your date of birth is encrypted and used only to apply age-based permissions. You may request an administrator review if you believe this link is incorrect or unsafe.', 'tutor-learning-suite' ) . '</p><form method="post" class="tls-lms-form">' . wp_nonce_field( 'tls_family_action', 'tls_family_nonce', true, false ) . '<input type="hidden" name="tls_parent_token" value="' . esc_attr( $token ) . '"><label>' . esc_html__( 'Your date of birth', 'tutor-learning-suite' ) . '<input type="date" name="student_birth_date" max="' . esc_attr( wp_date( 'Y-m-d' ) ) . '" required></label><button class="tls-lms-button" name="tls_accept_parent_link" value="1">' . esc_html__( 'Accept family link', 'tutor-learning-suite' ) . '</button></form></div>';
            } else {
                $html .= '<div class="tls-lms-warning">' . esc_html__( 'This family invitation is invalid or expired.', 'tutor-learning-suite' ) . '</div>';
            }
        }

        if ( is_array( $pending ) && ! empty( $pending['parent_id'] ) && ! empty( $pending['expires'] ) && (int) $pending['expires'] >= time() && ! get_user_meta( $user_id, self::GUARDIAN_META, true ) ) {
            $parent = get_userdata( absint( $pending['parent_id'] ) );
            if ( $parent ) {
                $html .= '<div class="tls-lms-invitation"><strong>' . esc_html( sprintf( __( '%s has requested a family link.', 'tutor-learning-suite' ), $parent->display_name ) ) . '</strong><p>' . esc_html__( 'Use the secure link sent to your email to review and accept this request. You can reject it here.', 'tutor-learning-suite' ) . '</p><form method="post" class="tls-lms-inline-form">' . wp_nonce_field( 'tls_family_action', 'tls_family_nonce', true, false ) . '<button class="tls-lms-button tls-lms-button-muted" name="tls_reject_parent_link" value="1">' . esc_html__( 'Reject request', 'tutor-learning-suite' ) . '</button></form></div>';
            }
        }

        if ( $children ) {
            $html .= '<h2>' . esc_html__( 'Linked children', 'tutor-learning-suite' ) . '</h2><div class="tls-lms-family-list">';
            foreach ( $children as $child ) {
                $age_status = self::account_age_status( $child->ID );
                $progress_allowed = TLS_Age_Safety::can_parent_view( $user_id, $child->ID );
                $guardian_status = (string) get_user_meta( $child->ID, TLS_Age_Safety::GUARDIAN_STATUS_META, true );
                $free_enrollment_allowed = TLS_Age_Safety::is_child_free_enrollment_enabled( $child->ID );
                $can_manage_free_enrollment = TLS_Age_Safety::can_guardian_manage_free_enrollment( $user_id, $child->ID );
                $enrollment_state = $free_enrollment_allowed ? __( 'Free-course self-enrolment enabled', 'tutor-learning-suite' ) : __( 'Free-course self-enrolment disabled', 'tutor-learning-suite' );
                $family_state = 'unknown' === $age_status ? __( 'Age details required; access paused', 'tutor-learning-suite' ) : ( 'pending_review' === $guardian_status ? __( 'Awaiting administrator review', 'tutor-learning-suite' ) : ( $progress_allowed ? __( 'Progress sharing enabled', 'tutor-learning-suite' ) : __( 'Progress sharing disabled', 'tutor-learning-suite' ) ) );
                $html .= '<article class="tls-lms-family-child"><div><strong>' . esc_html( $child->display_name ) . '</strong><span>' . esc_html__( 'Linked student account', 'tutor-learning-suite' ) . '</span><small>' . esc_html( $family_state ) . '</small><small>' . esc_html( $enrollment_state ) . '</small></div><div class="tls-lms-family-child__actions">';
                if ( $can_manage_free_enrollment ) {
                    $html .= '<form method="post" class="tls-lms-child-free-enrollment">' . wp_nonce_field( 'tls_family_action', 'tls_family_nonce', true, false ) . wp_nonce_field( 'tls_child_free_enroll_' . absint( $child->ID ), 'tls_child_free_enroll_nonce', true, false ) . '<input type="hidden" name="child_id" value="' . absint( $child->ID ) . '"><label class="tls-lms-check"><input type="checkbox" name="tls_allow_child_free_enrollment" value="1" ' . checked( $free_enrollment_allowed, true, false ) . '> ' . esc_html__( 'Allow this child to enrol themselves in free courses', 'tutor-learning-suite' ) . '</label><button class="tls-lms-button" name="tls_save_child_free_enrollment" value="1">' . esc_html__( 'Save free-course permission', 'tutor-learning-suite' ) . '</button><p class="tls-lms-child-free-enrollment__note">' . esc_html__( 'Paid courses remain restricted and must be gifted to this child through Tutor LMS Course Gifting.', 'tutor-learning-suite' ) . '</p></form>';
                } else {
                    $html .= '<p class="tls-lms-muted">' . esc_html__( 'Free-course self-enrolment can be enabled after this under-18 learner has an administrator-approved guardian link and your guardian account is verified.', 'tutor-learning-suite' ) . '</p>';
                }
                $html .= '<form method="post">' . wp_nonce_field( 'tls_family_action', 'tls_family_nonce', true, false ) . '<input type="hidden" name="child_id" value="' . absint( $child->ID ) . '"><button class="tls-lms-button tls-lms-button-muted" name="tls_unlink_child" value="1">' . esc_html__( 'Unlink', 'tutor-learning-suite' ) . '</button></form></div></article>';
                if ( $progress_allowed ) {
                    // Read Tutor LMS's canonical enrollment rows directly. The learner is
                    // post_author and the course is post_parent in Tutor Core 4.2.1.
                    // A direct prepared query avoids theme/query filters and differences
                    // in how custom enrollment post statuses are registered in WP_Query.
                    $enrollments = self::guardian_enrolled_courses( $child->ID, 20 );
                    $html .= '<div class="tls-lms-family-progress"><h3>' . esc_html( sprintf( __( '%s — course activity', 'tutor-learning-suite' ), $child->display_name ) ) . '</h3><p class="tls-lms-muted">' . esc_html__( 'Showing the 20 most recent enrolled courses.', 'tutor-learning-suite' ) . '</p>';
                    if ( $enrollments ) {
                        $html .= '<ul>';
                        foreach ( $enrollments as $enrollment ) {
                            $course_id = absint( $enrollment->course_id );
                            if ( ! $course_id ) {
                                continue;
                            }
                            $course_progress = self::guardian_course_progress( $course_id, $child->ID );
                            $course_status = (string) $enrollment->course_status;
                            $enrollment_status = (string) $enrollment->enrollment_status;
                            $status_label = 'pending' === $enrollment_status
                                ? __( 'Pending', 'tutor-learning-suite' )
                                : __( 'Enrolled', 'tutor-learning-suite' );
                            $html .= '<li class="tls-lms-guardian-course"><div><a href="' . esc_url( get_permalink( $course_id ) ) . '">' . esc_html( $enrollment->course_title ) . '</a> <span>' . esc_html( $status_label ) . '</span></div>';
                            if ( null !== $course_progress ) {
                                // Build percentage labels by concatenation. TranslatePress may
                                // return a translated string with a single literal percent,
                                // which sprintf() would parse as an invalid format sequence.
                                $progress_percent = number_format_i18n( (int) $course_progress ) . '%';
                                $progress_aria = __( 'Course progress:', 'tutor-learning-suite' ) . ' ' . $progress_percent;
                                $progress_text = $progress_percent . ' ' . __( 'complete', 'tutor-learning-suite' );
                                $html .= '<div class="tls-lms-guardian-progress" role="group" aria-label="' . esc_attr( $progress_aria ) . '"><span>' . esc_html( $progress_text ) . '</span><div class="tls-lms-guardian-progress-track"><span style="width:' . (int) $course_progress . '%"></span></div></div>';
                            } else {
                                $html .= '<small class="tls-lms-muted">' . esc_html__( 'Progress is not available for this course.', 'tutor-learning-suite' ) . '</small>';
                            }
                            $html .= '</li>';
                        }
                        $html .= '</ul>';
                    } else {
                        $html .= '<p>' . esc_html__( 'No course enrollments are available to share yet.', 'tutor-learning-suite' ) . '</p>';
                    }
                    $quiz_attempts = self::guardian_quiz_attempts( $child->ID, 10 );
                    $html .= '<section class="tls-lms-guardian-assessments"><h4>' . self::card_icon( 'book' ) . esc_html__( 'Quiz results', 'tutor-learning-suite' ) . '</h4><p class="tls-lms-muted">' . esc_html__( 'Showing the latest 10 quiz attempts.', 'tutor-learning-suite' ) . '</p>';
                    if ( $quiz_attempts ) {
                        $html .= '<ul>';
                        foreach ( $quiz_attempts as $attempt ) {
                            $quiz_title = get_the_title( absint( $attempt->quiz_id ) );
                            $score = '';
                            if ( isset( $attempt->earned_marks, $attempt->total_marks ) && is_numeric( $attempt->earned_marks ) && is_numeric( $attempt->total_marks ) && (float) $attempt->total_marks > 0 ) {
                                $score = number_format_i18n( (float) $attempt->earned_marks, 2 ) . ' / ' . number_format_i18n( (float) $attempt->total_marks, 2 );
                            }
                            $result_value = isset( $attempt->result ) ? sanitize_key( (string) $attempt->result ) : '';
                            if ( ! $result_value && isset( $attempt->attempt_status ) ) {
                                $result_value = sanitize_key( (string) $attempt->attempt_status );
                            }
                            $result_label = $result_value ? ucwords( str_replace( array( '_', '-' ), ' ', $result_value ) ) : __( 'Submitted', 'tutor-learning-suite' );
                            $attempt_date = ! empty( $attempt->attempt_ended_at ) ? $attempt->attempt_ended_at : ( $attempt->attempt_started_at ?? '' );
                            $date_label = $attempt_date ? wp_date( get_option( 'date_format' ), strtotime( $attempt_date ) ) : '';
                            $html .= '<li><strong>' . esc_html( $quiz_title ? $quiz_title : __( 'Quiz', 'tutor-learning-suite' ) ) . '</strong><span>' . esc_html( trim( $score . ( $score ? ' · ' : '' ) . $result_label . ( $date_label ? ' · ' . $date_label : '' ) ) ) . '</span></li>';
                        }
                        $html .= '</ul>';
                    } else {
                        $html .= '<p>' . esc_html__( 'No quiz attempts are available to share yet.', 'tutor-learning-suite' ) . '</p>';
                    }
                    $html .= '</section>';

                    $assignment_attempts = self::guardian_assignment_attempts( $child->ID, 10 );
                    $html .= '<section class="tls-lms-guardian-assessments"><h4>' . self::card_icon( 'clipboard' ) . esc_html__( 'Assignment results', 'tutor-learning-suite' ) . '</h4><p class="tls-lms-muted">' . esc_html__( 'Showing the latest 10 assignment submissions.', 'tutor-learning-suite' ) . '</p>';
                    if ( $assignment_attempts ) {
                        $html .= '<ul>';
                        foreach ( $assignment_attempts as $assignment ) {
                            $meta = get_comment_meta( (int) $assignment->comment_ID );
                            $earned = '';
                            $total = '';
                            $pass_mark = '';
                            $result = '';
                            $read_grade_meta = static function( $values, $prefix = '' ) use ( &$read_grade_meta, &$earned, &$total, &$pass_mark, &$result ) {
                                foreach ( (array) $values as $key_part => $entry ) {
                                    $key = strtolower( trim( $prefix . '_' . (string) $key_part, '_' ) );
                                    if ( is_array( $entry ) ) {
                                        $read_grade_meta( $entry, $key );
                                        continue;
                                    }
                                    if ( ! is_scalar( $entry ) ) {
                                        continue;
                                    }
                                    if ( is_numeric( $entry ) && preg_match( '/(earned|obtained|awarded|score|grade)/', $key ) && ! preg_match( '/(total|max|pass|possible)/', $key ) ) {
                                        $earned = (string) $entry;
                                    } elseif ( is_numeric( $entry ) && preg_match( '/(pass).*mark|mark.*(pass)/', $key ) ) {
                                        $pass_mark = (string) $entry;
                                    } elseif ( is_numeric( $entry ) && preg_match( '/(total|max|full|possible).*mark|mark.*(total|max|full|possible)/', $key ) ) {
                                        $total = (string) $entry;
                                    } elseif ( preg_match( '/(result|status)/', $key ) ) {
                                        $result = sanitize_text_field( (string) $entry );
                                    }
                                }
                            };
                            $read_grade_meta( (array) $meta );
                            $grade = '';
                            if ( '' !== $earned && '' !== $total && (float) $total > 0 ) {
                                $grade = number_format_i18n( (float) $earned, 2 ) . ' / ' . number_format_i18n( (float) $total, 2 );
                            } elseif ( '' !== $earned ) {
                                $grade = sprintf( __( 'Score: %s', 'tutor-learning-suite' ), number_format_i18n( (float) $earned, 2 ) );
                            }
                            if ( ! $result && '' !== $earned && '' !== $pass_mark ) {
                                $result = (float) $earned >= (float) $pass_mark ? 'passed' : 'failed';
                            }
                            $status_label = $result ? ucwords( str_replace( array( '_', '-' ), ' ', $result ) ) : ( $grade ? __( 'Graded', 'tutor-learning-suite' ) : __( 'Submitted; awaiting grade', 'tutor-learning-suite' ) );
                            $pass_label = '' !== $pass_mark ? sprintf( __( 'Pass mark: %s', 'tutor-learning-suite' ), number_format_i18n( (float) $pass_mark, 2 ) ) : '';
                            $assignment_date = ! empty( $assignment->comment_date ) ? wp_date( get_option( 'date_format' ), strtotime( $assignment->comment_date ) ) : '';
                            $assignment_title = get_the_title( absint( $assignment->comment_post_ID ) );
                            $details = array_filter( array( $grade, $pass_label, $status_label, $assignment_date ) );
                            $html .= '<li><strong>' . esc_html( $assignment_title ? $assignment_title : __( 'Assignment', 'tutor-learning-suite' ) ) . '</strong><span>' . esc_html( implode( ' · ', $details ) ) . '</span></li>';
                        }
                        $html .= '</ul>';
                    } else {
                        $html .= '<p>' . esc_html__( 'No assignment submissions are available to share yet.', 'tutor-learning-suite' ) . '</p>';
                    }
                    $html .= '</section>';

                    global $wpdb;
                    $session_table = $wpdb->prefix . 'tlms_private_sessions';
                    $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $session_table ) );
                    if ( $table_exists === $session_table ) {
                        $sessions = $wpdb->get_results( $wpdb->prepare(
                            "SELECT subject, session_date, status FROM {$session_table} WHERE learner_user_id=%d OR (student_id=%d AND (learner_user_id IS NULL OR learner_user_id=0)) ORDER BY id DESC LIMIT 5",
                            (int) $child->ID,
                            (int) $child->ID
                        ) );
                        if ( $sessions ) {
                            $html .= '<h4>' . esc_html__( 'Private tutoring', 'tutor-learning-suite' ) . '</h4><ul>';
                            foreach ( $sessions as $session ) {
                                $session_date = ! empty( $session->session_date ) ? wp_date( get_option( 'date_format' ), strtotime( $session->session_date ) ) : __( 'Date to be agreed', 'tutor-learning-suite' );
                                $html .= '<li>' . esc_html( $session->subject . ' · ' . $session_date . ' · ' . ucwords( str_replace( '_', ' ', $session->status ) ) ) . '</li>';
                            }
                            $html .= '</ul>';
                        }
                    }
                    $html .= '</div>';
                }
            }
            $html .= '</div>';
            if ( class_exists( 'TLS_Feedback_Moderation' ) ) {
                $html .= TLS_Feedback_Moderation::render_parent_queue( $user_id, $children );
            }
            if ( $family_total_pages > 1 ) {
                $html .= '<nav class="tls-lms-family-pagination" aria-label="' . esc_attr__( 'Family list pages', 'tutor-learning-suite' ) . '">';
                for ( $page_number = 1; $page_number <= $family_total_pages; $page_number++ ) {
                    $page_url = add_query_arg( 'tls_family_page', $page_number );
                    $html .= '<a class="tls-lms-button ' . ( $page_number === $family_page ? 'is-active' : 'tls-lms-button-muted' ) . '" href="' . esc_url( $page_url ) . '"' . ( $page_number === $family_page ? ' aria-current="page"' : '' ) . '>' . (int) $page_number . '</a>';
                }
                $html .= '</nav>';
            }
        }

        if ( is_array( $pending ) && ! empty( $pending['expires'] ) && (int) $pending['expires'] >= time() && ! empty( $pending['parent_id'] ) ) {
            $html .= '<p class="tls-lms-muted">' . esc_html__( 'You have a pending family invitation. Review it above.', 'tutor-learning-suite' ) . '</p>';
        } elseif ( $guardian_ready && ! $has_incoming_request ) {
            $html .= '<h2>' . esc_html__( 'Link an existing student account', 'tutor-learning-suite' ) . '</h2><form method="post" class="tls-lms-form">' . wp_nonce_field( 'tls_family_action', 'tls_family_nonce', true, false ) . '<label>' . esc_html__( 'Student account email', 'tutor-learning-suite' ) . '<input type="email" name="student_email" required></label><label class="tls-lms-check"><input type="checkbox" name="guardian_attestation" value="1" required> ' . esc_html__( 'I am the student’s parent or legal guardian and request this family link.', 'tutor-learning-suite' ) . '</label><button class="tls-lms-button" name="tls_request_parent_link" value="1">' . esc_html__( 'Send link request', 'tutor-learning-suite' ) . '</button></form>';
            $html .= '<h2>' . esc_html__( 'Create a child account', 'tutor-learning-suite' ) . '</h2><form method="post" class="tls-lms-form">' . wp_nonce_field( 'tls_family_action', 'tls_family_nonce', true, false ) . '<label>' . esc_html__( 'Child name', 'tutor-learning-suite' ) . '<input name="child_name" required></label><label>' . esc_html__( 'Child email', 'tutor-learning-suite' ) . '<input type="email" name="child_email" required></label><label>' . esc_html__( 'Date of birth (used for age eligibility only)', 'tutor-learning-suite' ) . '<input type="date" name="child_birth_date" max="' . esc_attr( wp_date( 'Y-m-d' ) ) . '" required></label><label class="tls-lms-check"><input type="checkbox" name="guardian_attestation" value="1" required> ' . esc_html__( 'I am the parent or legal guardian and consent to creating this account.', 'tutor-learning-suite' ) . '</label><button class="tls-lms-button" name="tls_create_child" value="1">' . esc_html__( 'Create child account', 'tutor-learning-suite' ) . '</button></form>';
        }

        $parent_id = (int) get_user_meta( $user_id, self::GUARDIAN_META, true );
        if ( $parent_id ) {
            $parent = get_userdata( $parent_id );
            $html .= '<h2>' . esc_html__( 'Your linked parent/guardian', 'tutor-learning-suite' ) . '</h2>';
            if ( $parent ) {
                $html .= '<div class="tls-lms-family-list"><article><div><strong>' . esc_html( $parent->display_name ) . '</strong><span>' . esc_html__( 'Linked parent/guardian', 'tutor-learning-suite' ) . '</span><small>' . esc_html__( 'Linked parent/guardian', 'tutor-learning-suite' ) . '</small></div><span class="tls-lms-status-pill">' . esc_html__( 'Linked', 'tutor-learning-suite' ) . '</span></article></div>';
                $concern = get_user_meta( $user_id, self::GUARDIAN_CONCERN_META, true );
                $student_band = TLS_Age_Safety::age_band( $user_id );
                if ( 'adult' === $student_band ) {
                    $html .= '<form method="post" class="tls-lms-inline-form tls-lms-family-end-link">' . wp_nonce_field( 'tls_family_action', 'tls_family_nonce', true, false ) . '<button class="tls-lms-button tls-lms-button-muted" name="tls_unlink_own_parent" value="1" onclick="return confirm(\'' . esc_js( __( 'End this family link? The other account will lose delegated access, but your learning records will remain.', 'tutor-learning-suite' ) ) . '\');">' . esc_html__( 'End family link', 'tutor-learning-suite' ) . '</button></form>';
                } elseif ( is_array( $concern ) && 'open' === ( $concern['status'] ?? '' ) ) {
                    $html .= '<div class="tls-lms-warning">' . esc_html__( 'Your request for an administrator review has been submitted. The family link remains active while it is reviewed. The administrator can end it if appropriate.', 'tutor-learning-suite' ) . '</div>';
                } else {
                    if ( is_array( $concern ) && 'closed' === ( $concern['status'] ?? '' ) ) {
                        $resolved_text = 'admin_revoked' === ( $concern['resolution'] ?? '' ) ? __( 'The administrator ended this family link after reviewing your concern.', 'tutor-learning-suite' ) : __( 'The administrator reviewed your concern and kept the family link active.', 'tutor-learning-suite' );
                        $html .= '<div class="tls-lms-muted">' . esc_html( $resolved_text ) . '</div>';
                    }
                    $html .= '<details class="tls-lms-family-concern"><summary>' . esc_html__( 'Report a concern about this family link', 'tutor-learning-suite' ) . '</summary><p class="tls-lms-muted">' . esc_html__( 'Accounts under the configured adult-age threshold cannot end a guardian link themselves. You can ask the site safety administrator to review it. A report does not automatically remove the relationship.', 'tutor-learning-suite' ) . '</p><form method="post" class="tls-lms-form">' . wp_nonce_field( 'tls_family_action', 'tls_family_nonce', true, false ) . '<label>' . esc_html__( 'What should the administrator review?', 'tutor-learning-suite' ) . '<textarea name="guardian_concern_reason" rows="3" maxlength="1500" required></textarea></label><button class="tls-lms-button tls-lms-button-muted" name="tls_request_guardian_review" value="1">' . esc_html__( 'Request administrator review', 'tutor-learning-suite' ) . '</button></form></details>';
                }
            }
        }
        return $html . '</section>';
    }

    /**
     * Return a learner's completed Tutor LMS enrollment rows for guardian display.
     * Tutor's own helper first determines enrolled course IDs; the prepared posts
     * query then retrieves enrollment dates and course titles without WP_Query filters.
     */
    private static function guardian_enrolled_courses( $student_id, $limit = 20 ) {
        global $wpdb;
        $student_id = absint( $student_id );
        $limit      = max( 1, min( 50, absint( $limit ) ) );
        if ( ! $student_id || ! isset( $wpdb->posts ) ) {
            return array();
        }

        $course_post_type = 'courses';
        if ( function_exists( 'tutor' ) ) {
            $tutor = tutor();
            if ( is_object( $tutor ) && ! empty( $tutor->course_post_type ) ) {
                $course_post_type = sanitize_key( $tutor->course_post_type );
            }
        }

        // Ask Tutor LMS for the learner's completed enrollment IDs first. This is
        // the same canonical query Tutor uses for its learner dashboard and includes
        // bundled courses when the installed Tutor version supports them.
        $course_ids = array();
        if ( function_exists( 'tutor_utils' ) ) {
            try {
                $ids = tutor_utils()->get_enrolled_courses_ids_by_user( $student_id, true );
                if ( is_array( $ids ) ) {
                    $course_ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
                }
            } catch ( Throwable $e ) {
                $course_ids = array();
            }
        }

        // The ID list above is trusted only after integer normalization. The final
        // prepared query joins those IDs back to Tutor's authoritative enrollment
        // rows so the dashboard can show the actual enrollment date/status too.
        if ( $course_ids ) {
            $course_id_sql = implode( ',', $course_ids );
            $sql = $wpdb->prepare(
                "SELECT e.ID AS enrollment_id,
                        e.post_status AS enrollment_status,
                        e.post_date AS enrolled_at,
                        e.post_parent AS course_id,
                        c.post_title AS course_title,
                        c.post_status AS course_status
                 FROM {$wpdb->posts} e
                 INNER JOIN {$wpdb->posts} c ON c.ID = e.post_parent
                 WHERE e.post_type = %s
                   AND e.post_author = %d
                   AND e.post_status = %s
                   AND e.post_parent IN ({$course_id_sql})
                   AND c.post_type = %s
                   AND c.post_status IN (%s, %s)
                 ORDER BY e.post_date DESC, e.ID DESC
                 LIMIT %d",
                'tutor_enrolled',
                $student_id,
                'completed',
                $course_post_type,
                'publish',
                'private',
                $limit
            );
            $rows = $wpdb->get_results( $sql );
            if ( is_array( $rows ) && $rows ) {
                return $rows;
            }
        }

        // Compatibility fallback for installations without Tutor's public helper,
        // and for older records that may be pending while their order is processed.
        $fallback_sql = $wpdb->prepare(
            "SELECT e.ID AS enrollment_id,
                    e.post_status AS enrollment_status,
                    e.post_date AS enrolled_at,
                    e.post_parent AS course_id,
                    c.post_title AS course_title,
                    c.post_status AS course_status
             FROM {$wpdb->posts} e
             INNER JOIN {$wpdb->posts} c ON c.ID = e.post_parent
             WHERE e.post_type = %s
               AND e.post_author = %d
               AND e.post_status IN (%s, %s)
               AND e.post_parent > 0
               AND c.post_type = %s
               AND c.post_status IN (%s, %s)
             ORDER BY e.post_date DESC, e.ID DESC
             LIMIT %d",
            'tutor_enrolled',
            $student_id,
            'completed',
            'pending',
            $course_post_type,
            'publish',
            'private',
            $limit
        );
        $rows = $wpdb->get_results( $fallback_sql );
        return is_array( $rows ) ? $rows : array();
    }

    /** Read course completion through Tutor LMS so its own progress rules remain authoritative. */
    private static function guardian_course_progress( $course_id, $student_id ) {
        if ( ! function_exists( 'tutor_utils' ) ) {
            return null;
        }
        try {
            $utils = tutor_utils();
            if ( ! is_object( $utils ) || ! method_exists( $utils, 'get_course_completed_percent' ) ) {
                return null;
            }
            $progress = $utils->get_course_completed_percent( absint( $course_id ), absint( $student_id ), true );
            if ( is_array( $progress ) && isset( $progress['completed_percent'] ) && is_numeric( $progress['completed_percent'] ) ) {
                return max( 0, min( 100, (int) round( (float) $progress['completed_percent'] ) ) );
            }
            if ( is_numeric( $progress ) ) {
                return max( 0, min( 100, (int) round( (float) $progress ) ) );
            }
        } catch ( Throwable $e ) {
            return null;
        }
        return null;
    }

    /** Fetch only the learner's own Quiz Attempts rows; never expose answer payloads. */
    private static function guardian_quiz_attempts( $student_id, $limit = 10 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'tutor_quiz_attempts';
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists !== $table ) {
            return array();
        }
        $columns = (array) $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`", 0 );
        if ( ! in_array( 'user_id', $columns, true ) || ! in_array( 'quiz_id', $columns, true ) ) {
            return array();
        }
        $preferred = array( 'quiz_id', 'course_id', 'earned_marks', 'total_marks', 'result', 'attempt_status', 'attempt_started_at', 'attempt_ended_at' );
        $selected = array_values( array_intersect( $preferred, $columns ) );
        $sort_column = in_array( 'attempt_ended_at', $columns, true ) ? 'attempt_ended_at' : ( in_array( 'attempt_started_at', $columns, true ) ? 'attempt_started_at' : 'quiz_id' );
        if ( ! $selected ) {
            return array();
        }
        $sql = 'SELECT ' . implode( ', ', array_map( static function( $column ) { return '`' . esc_sql( $column ) . '`'; }, $selected ) ) . " FROM `{$table}` WHERE user_id = %d ORDER BY `" . esc_sql( $sort_column ) . '` DESC LIMIT ' . absint( $limit );
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, absint( $student_id ) ) );
        if ( ! is_array( $rows ) ) {
            return array();
        }
        return array_values( array_filter( $rows, static function( $row ) {
            return ! isset( $row->attempt_status ) || 'attempt_started' !== (string) $row->attempt_status;
        } ) );
    }

    /**
     * Assignment submissions are stored in WordPress comments by Tutor LMS.
     * This read-only lookup limits rows to assignment posts owned by the learner.
     */
    private static function guardian_assignment_attempts( $student_id, $limit = 10 ) {
        global $wpdb;
        $comments = $wpdb->comments;
        $posts = $wpdb->posts;
        $sql = "SELECT c.comment_ID, c.comment_post_ID, c.comment_date, c.comment_approved, c.comment_type
            FROM {$comments} c
            INNER JOIN {$posts} p ON p.ID = c.comment_post_ID
            WHERE c.user_id = %d
                AND p.post_type = %s
                AND c.comment_approved IN ('1', '0')
                AND (c.comment_type LIKE %s OR c.comment_type = '' OR c.comment_type = 'comment')
            ORDER BY c.comment_date_gmt DESC
            LIMIT " . absint( $limit );
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, absint( $student_id ), 'tutor_assignments', '%assignment%' ) );
        return is_array( $rows ) ? $rows : array();
    }

    private static function dashboard_url( $route ) {
        $is_privacy = self::PRIVACY_ROUTE === $route;
        $base_route = self::FAMILY_ROUTE;
        if ( function_exists( 'tutor_utils' ) ) {
            $url = tutor_utils()->get_tutor_dashboard_page_permalink( $base_route );
        } else {
            $url = add_query_arg( 'tutor_dashboard_page', $base_route, home_url( '/dashboard/' ) );
        }
        return $is_privacy ? add_query_arg( 'tls_tab', 'privacy', $url ) : $url;
    }

    public static function handle_family_and_privacy_posts() {
        if ( ! is_user_logged_in() || empty( $_POST['tls_family_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tls_family_nonce'] ) ), 'tls_family_action' ) ) {
            if ( is_user_logged_in() && ! empty( $_POST['tls_privacy_nonce'] ) ) {
                self::save_privacy_post();
            }
            return;
        }
        $user_id = get_current_user_id();
        if ( isset( $_POST['tls_save_child_free_enrollment'] ) ) {
            $child_id = absint( $_POST['child_id'] ?? 0 );
            $nonce = sanitize_text_field( wp_unslash( $_POST['tls_child_free_enroll_nonce'] ?? '' ) );
            if ( ! $child_id || ! wp_verify_nonce( $nonce, 'tls_child_free_enroll_' . $child_id ) ) {
                self::redirect_notice( self::FAMILY_ROUTE, 'child_free_enrollment_denied' );
            }
            $enabled = isset( $_POST['tls_allow_child_free_enrollment'] );
            if ( ! TLS_Age_Safety::set_child_free_enrollment_permission( $user_id, $child_id, $enabled ) ) {
                self::redirect_notice( self::FAMILY_ROUTE, 'child_free_enrollment_denied' );
            }
            self::redirect_notice( self::FAMILY_ROUTE, $enabled ? 'child_free_enrollment_enabled' : 'child_free_enrollment_disabled' );
        }
        if ( isset( $_POST['tls_save_guardian_birth_date'] ) ) {
            $birth = sanitize_text_field( wp_unslash( $_POST['guardian_birth_date'] ?? '' ) );
            if ( TLS_Age_Safety::store_birth_date( $user_id, $birth ) && 'adult' === TLS_Age_Safety::age_band( $user_id ) ) {
                TLS_Age_Safety::sync_user( $user_id );
                TLS_Age_Safety::audit( 'guardian_age_recorded', $user_id, array( 'result' => 'adult' ) );
                self::redirect_notice( self::FAMILY_ROUTE, 'guardian_age_saved' );
            }
            self::redirect_notice( self::FAMILY_ROUTE, 'guardian_age_not_eligible' );
        }
        if ( isset( $_POST['tls_accept_parent_link'] ) ) {
            $token = sanitize_text_field( wp_unslash( $_POST['tls_parent_token'] ?? '' ) );
            $birth = sanitize_text_field( wp_unslash( $_POST['student_birth_date'] ?? '' ) );
            $pending = get_user_meta( $user_id, self::PENDING_LINK_META, true );
            $valid_token = $token && is_array( $pending ) && ! empty( $pending['token_hash'] ) && ! empty( $pending['expires'] ) && (int) $pending['expires'] >= time() && hash_equals( (string) $pending['token_hash'], hash( 'sha256', $token ) ) && ! get_user_meta( $user_id, self::GUARDIAN_META, true );
            $parent_id = $valid_token ? absint( $pending['parent_id'] ) : 0;
            $parent_is_adult = $parent_id && 'adult' === TLS_Age_Safety::age_band( $parent_id );
            if ( $valid_token && $parent_is_adult && TLS_Age_Safety::store_birth_date( $user_id, $birth ) ) {
                $band = TLS_Age_Safety::age_band( $user_id );
                update_user_meta( $user_id, self::GUARDIAN_META, $parent_id );
                update_user_meta( $parent_id, '_tls_is_guardian', '1' );
                update_user_meta( $user_id, '_tls_guardian_consent_at', current_time( 'mysql', true ) );
                update_user_meta( $user_id, '_tls_guardian_consent_by', $parent_id );
                // All new links require an administrator review, including adult
                // students. Adults still control each sharing permission after approval.
                update_user_meta( $user_id, TLS_Age_Safety::GUARDIAN_STATUS_META, 'pending_review' );
                update_user_meta( $user_id, self::PARENT_PROGRESS_META, '0' );
                update_user_meta( $user_id, self::PARENT_BOOKING_META, '0' );
                update_user_meta( $user_id, self::PARENT_ENROLL_META, '0' );
                update_user_meta( $user_id, TLS_Age_Safety::CHILD_FREE_ENROLL_META, '0' );
                delete_user_meta( $user_id, '_tls_parent_booking_consent_at' );
                delete_user_meta( $user_id, '_tls_guardian_enroll_consent_at' );
                delete_user_meta( $user_id, '_tls_guardian_enroll_consent_by' );
                TLS_Age_Safety::sync_user( $user_id );
                delete_user_meta( $user_id, self::PENDING_LINK_META );
                TLS_Age_Safety::audit( 'student_accepted_guardian_link', $user_id, array( 'guardian_id' => $parent_id, 'age_band' => $band ) );
                self::redirect_notice( self::FAMILY_ROUTE, 'link_accepted' );
            }
            self::redirect_notice( self::FAMILY_ROUTE, $valid_token && $parent_is_adult ? 'birthdate_required' : 'invalid' );
        }
        if ( isset( $_POST['tls_request_parent_link'] ) ) {
            $email = sanitize_email( wp_unslash( $_POST['student_email'] ?? '' ) );
            if ( 'adult' !== TLS_Age_Safety::age_band( $user_id ) || empty( $_POST['guardian_attestation'] ) || ! is_email( $email ) ) {
                self::redirect_notice( self::FAMILY_ROUTE, 'invalid' );
            }

            // Limit invitation traffic per guardian and per normalized target.
            // The outward response is identical whether the address is registered,
            // eligible, already linked, or rate limited (account enumeration guard).
            $actor_key = 'tls_family_invite_actor_' . $user_id;
            $actor_count = (int) get_transient( $actor_key );
            if ( $actor_count < 5 ) {
                set_transient( $actor_key, $actor_count + 1, HOUR_IN_SECONDS );
                $target_key = 'tls_family_invite_target_' . hash( 'sha256', strtolower( $email ) );
                if ( false === get_transient( $target_key ) ) {
                    $student = get_user_by( 'email', $email );
                    if ( $student && (int) $student->ID !== $user_id && TLS_Age_Safety::is_tutor_student( $student->ID ) && ! get_user_meta( $student->ID, self::GUARDIAN_META, true ) ) {
                        $existing_invite = get_user_meta( $student->ID, self::PENDING_LINK_META, true );
                        $invite_active = is_array( $existing_invite ) && ! empty( $existing_invite['expires'] ) && (int) $existing_invite['expires'] >= time();
                        if ( ! $invite_active ) {
                            try {
                                $token = bin2hex( random_bytes( 32 ) );
                                update_user_meta( $student->ID, self::PENDING_LINK_META, array(
                                    'parent_id' => $user_id,
                                    'token_hash' => hash( 'sha256', $token ),
                                    'expires' => time() + DAY_IN_SECONDS * 7,
                                    'requested_at' => current_time( 'mysql', true ),
                                ) );
                                $accept_url = add_query_arg( 'tls_parent_token', rawurlencode( $token ), self::dashboard_url( self::FAMILY_ROUTE ) );
                                $login_url = wp_login_url( $accept_url );
                                $sent = wp_mail( $student->user_email, __( 'Family link request', 'tutor-learning-suite' ), sprintf( __( '%1$s has requested to link your account as a parent/guardian. Sign in and review the request here: %2$s The link expires in 7 days. If you do not recognize this request, ignore this email.', 'tutor-learning-suite' ), get_userdata( $user_id )->display_name, $login_url ) );
                                if ( $sent ) {
                                    set_transient( $target_key, 1, DAY_IN_SECONDS );
                                    TLS_Age_Safety::audit( 'guardian_link_requested', $student->ID, array( 'guardian_id' => $user_id ) );
                                } else {
                                    delete_user_meta( $student->ID, self::PENDING_LINK_META );
                                }
                            } catch ( Throwable $e ) {
                                if ( isset( $student->ID ) ) delete_user_meta( $student->ID, self::PENDING_LINK_META );
                            }
                        }
                    }
                }
            }
            self::redirect_notice( self::FAMILY_ROUTE, 'request_sent' );
        }
        if ( isset( $_POST['tls_create_child'] ) ) {
            $name = sanitize_text_field( wp_unslash( $_POST['child_name'] ?? '' ) );
            $email = sanitize_email( wp_unslash( $_POST['child_email'] ?? '' ) );
            $birth = sanitize_text_field( wp_unslash( $_POST['child_birth_date'] ?? '' ) );
            $dob = DateTimeImmutable::createFromFormat( '!Y-m-d', $birth, wp_timezone() );
            $today = new DateTimeImmutable( 'today', wp_timezone() );
            $valid_birth = $dob && $dob->format( 'Y-m-d' ) === $birth && $dob <= $today;
            $age = $valid_birth ? $dob->diff( $today )->y : 99;
            if ( 'adult' !== TLS_Age_Safety::age_band( $user_id ) || empty( $_POST['guardian_attestation'] ) || ! $name || ! is_email( $email ) || email_exists( $email ) || $age >= self::adult_age_threshold() || ! TLS_Age_Safety::is_key_configured() ) {
                self::redirect_notice( self::FAMILY_ROUTE, 'child_invalid' );
            }
            $username = sanitize_user( current( explode( '@', $email ) ), true );
            if ( username_exists( $username ) ) {
                $username .= wp_rand( 10, 99 );
            }
            $child_id = wp_insert_user( array(
                'user_login' => $username,
                'user_email' => $email,
                'display_name' => $name,
                'user_pass' => wp_generate_password( 28 ),
                'role' => 'subscriber',
            ) );
            if ( is_wp_error( $child_id ) ) {
                self::redirect_notice( self::FAMILY_ROUTE, 'child_error' );
            }
            if ( ! TLS_Age_Safety::store_birth_date( $child_id, $birth ) ) {
                require_once ABSPATH . 'wp-admin/includes/user.php';
                wp_delete_user( $child_id );
                self::redirect_notice( self::FAMILY_ROUTE, 'birth_encryption_unavailable' );
            }
            update_user_meta( $child_id, self::GUARDIAN_META, $user_id );
            // Store both markers: Tutor's configured key and the suite fallback.
            update_user_meta( $child_id, '_is_tutor_student', 'yes' );
            if ( class_exists( '\\TUTOR\\User' ) && defined( '\\TUTOR\\User::TUTOR_STUDENT_META' ) ) {
                update_user_meta( $child_id, constant( '\\TUTOR\\User::TUTOR_STUDENT_META' ), 'yes' );
            }
            update_user_meta( $user_id, '_tls_is_guardian', '1' );
            update_user_meta( $child_id, '_tls_minor_account', $age < 18 ? '1' : '0' );
            update_user_meta( $child_id, TLS_Age_Safety::GUARDIAN_STATUS_META, 'pending_review' );
            update_user_meta( $child_id, '_tls_guardian_consent_at', current_time( 'mysql', true ) );
            update_user_meta( $child_id, '_tls_guardian_consent_by', $user_id );
            update_user_meta( $child_id, self::PRIVATE_STUDENT_META, '1' );
            update_user_meta( $child_id, '_tls_hide_public_contact', '1' );
            update_user_meta( $child_id, self::PARENT_PROGRESS_META, '0' );
            update_user_meta( $child_id, self::PARENT_BOOKING_META, '0' );
            update_user_meta( $child_id, self::PARENT_ENROLL_META, '0' );
            update_user_meta( $child_id, TLS_Age_Safety::CHILD_FREE_ENROLL_META, '0' );
            TLS_Age_Safety::sync_user( $child_id );
            TLS_Age_Safety::audit( 'guardian_created_child_account', $child_id, array( 'guardian_id' => $user_id, 'age_band' => TLS_Age_Safety::age_band( $child_id ) ) );
            wp_new_user_notification( $child_id, null, 'user' );
            self::redirect_notice( self::FAMILY_ROUTE, 'child_created_pending_review' );
        }
        if ( isset( $_POST['tls_unlink_own_parent'] ) ) {
            $parent_id = absint( get_user_meta( $user_id, self::GUARDIAN_META, true ) );
            if ( $parent_id && self::is_student_user( $user_id ) && 'adult' === TLS_Age_Safety::age_band( $user_id ) ) {
                delete_user_meta( $user_id, self::GUARDIAN_META );
                delete_user_meta( $user_id, self::PARENT_PROGRESS_META );
                delete_user_meta( $user_id, self::PARENT_BOOKING_META );
                delete_user_meta( $user_id, self::PARENT_ENROLL_META );
                delete_user_meta( $user_id, TLS_Age_Safety::CHILD_FREE_ENROLL_META );
                delete_user_meta( $user_id, '_tls_guardian_enroll_consent_at' );
                delete_user_meta( $user_id, '_tls_guardian_enroll_consent_by' );
                delete_user_meta( $user_id, TLS_Age_Safety::GUARDIAN_STATUS_META );
                delete_user_meta( $user_id, '_tls_guardian_consent_at' );
                delete_user_meta( $user_id, '_tls_guardian_consent_by' );
                delete_user_meta( $user_id, '_tls_guardian_verification_completed' );
                delete_user_meta( $user_id, '_tls_adult_parent_consent_required' );
                delete_user_meta( $user_id, '_tls_parent_booking_consent_at' );
                $existing_concern = get_user_meta( $user_id, self::GUARDIAN_CONCERN_META, true );
                if ( is_array( $existing_concern ) && 'open' === ( $existing_concern['status'] ?? '' ) ) {
                    $existing_concern['status'] = 'closed';
                    $existing_concern['resolution'] = 'adult_student_unlinked';
                    $existing_concern['resolved_at'] = current_time( 'mysql', true );
                    $existing_concern['resolved_by'] = $user_id;
                    update_user_meta( $user_id, self::GUARDIAN_CONCERN_META, $existing_concern );
                }
                TLS_Age_Safety::audit( 'adult_student_unlinked_parent', $user_id, array( 'guardian_id' => $parent_id, 'concern_preserved' => is_array( $existing_concern ) ) );
                self::maybe_clear_guardian_flag( $parent_id );
                self::redirect_notice( self::FAMILY_ROUTE, 'unlinked' );
            }
            TLS_Age_Safety::audit( 'underage_student_unlink_denied', $user_id, array( 'guardian_id' => $parent_id ) );
            self::redirect_notice( self::FAMILY_ROUTE, 'unlink_not_allowed' );
        }
        if ( isset( $_POST['tls_unlink_child'] ) ) {
            $child_id = absint( $_POST['child_id'] ?? 0 );
            if ( $child_id && (int) get_user_meta( $child_id, self::GUARDIAN_META, true ) === $user_id ) {
                delete_user_meta( $child_id, self::GUARDIAN_META );
                delete_user_meta( $child_id, self::PARENT_PROGRESS_META );
                delete_user_meta( $child_id, self::PARENT_BOOKING_META );
                delete_user_meta( $child_id, self::PARENT_ENROLL_META );
                delete_user_meta( $child_id, TLS_Age_Safety::CHILD_FREE_ENROLL_META );
                delete_user_meta( $child_id, '_tls_guardian_enroll_consent_at' );
                delete_user_meta( $child_id, '_tls_guardian_enroll_consent_by' );
                delete_user_meta( $child_id, TLS_Age_Safety::GUARDIAN_STATUS_META );
                delete_user_meta( $child_id, '_tls_guardian_consent_at' );
                delete_user_meta( $child_id, '_tls_guardian_consent_by' );
                delete_user_meta( $child_id, '_tls_guardian_verification_completed' );
                delete_user_meta( $child_id, '_tls_adult_parent_consent_required' );
                delete_user_meta( $child_id, '_tls_parent_booking_consent_at' );
                $existing_concern = get_user_meta( $child_id, self::GUARDIAN_CONCERN_META, true );
                if ( is_array( $existing_concern ) && 'open' === ( $existing_concern['status'] ?? '' ) ) {
                    $existing_concern['status'] = 'closed';
                    $existing_concern['resolution'] = 'parent_unlinked';
                    $existing_concern['resolved_at'] = current_time( 'mysql', true );
                    $existing_concern['resolved_by'] = $user_id;
                    update_user_meta( $child_id, self::GUARDIAN_CONCERN_META, $existing_concern );
                }
                TLS_Age_Safety::audit( 'guardian_unlinked_child', $child_id, array( 'guardian_id' => $user_id, 'concern_preserved' => is_array( $existing_concern ) ) );
                self::maybe_clear_guardian_flag( $user_id );
            }
            self::redirect_notice( self::FAMILY_ROUTE, 'unlinked' );
        }
        if ( isset( $_POST['tls_request_guardian_review'] ) ) {
            $existing_concern = get_user_meta( $user_id, self::GUARDIAN_CONCERN_META, true );
            if ( is_array( $existing_concern ) && 'open' === ( $existing_concern['status'] ?? '' ) ) {
                self::redirect_notice( self::FAMILY_ROUTE, 'review_requested' );
            }
            $parent_id = absint( get_user_meta( $user_id, self::GUARDIAN_META, true ) );
            $reason = sanitize_textarea_field( wp_unslash( $_POST['guardian_concern_reason'] ?? '' ) );
            if ( ! $parent_id || ! self::is_student_user( $user_id ) || strlen( $reason ) < 8 ) {
                self::redirect_notice( self::FAMILY_ROUTE, 'review_request_invalid' );
            }
            update_user_meta( $user_id, self::GUARDIAN_CONCERN_META, array(
                'status' => 'open',
                'reason' => $reason,
                'requested_at' => current_time( 'mysql', true ),
                'requested_by' => $user_id,
                'parent_id' => $parent_id,
            ) );
            TLS_Age_Safety::audit( 'student_requested_guardian_link_review', $user_id, array( 'guardian_id' => $parent_id ) );
            $admin_email = sanitize_email( get_option( 'admin_email' ) );
            if ( is_email( $admin_email ) ) {
                $student = get_userdata( $user_id );
                $parent = get_userdata( $parent_id );
                wp_mail( $admin_email, __( 'Family link review requested', 'tutor-learning-suite' ), sprintf( __( 'A student account requested an administrator review of a family link. Student: %1$s (ID %2$d). Parent/guardian: %3$s (ID %4$d). Review the request in Tools → TLS Child Safety.', 'tutor-learning-suite' ), $student ? $student->display_name : 'Student', $user_id, $parent ? $parent->display_name : 'Parent/guardian', $parent_id ) );
            }
            self::redirect_notice( self::FAMILY_ROUTE, 'review_requested' );
        }
        if ( isset( $_POST['tls_reject_parent_link'] ) ) {
            $pending = get_user_meta( $user_id, self::PENDING_LINK_META, true );
            $rejected_parent_id = is_array( $pending ) ? absint( $pending['parent_id'] ?? 0 ) : 0;
            delete_user_meta( $user_id, self::PENDING_LINK_META );
            if ( $rejected_parent_id ) {
                TLS_Age_Safety::audit( 'student_rejected_guardian_link', $user_id, array( 'guardian_id' => $rejected_parent_id ) );
            }
            self::redirect_notice( self::FAMILY_ROUTE, 'rejected' );
        }
    }

    private static function maybe_clear_guardian_flag( $parent_id ) {
        $children = get_users( array( 'meta_key' => self::GUARDIAN_META, 'meta_value' => absint( $parent_id ), 'fields' => 'ID', 'number' => 1 ) );
        if ( empty( $children ) ) {
            update_user_meta( $parent_id, '_tls_is_guardian', '0' );
        }
    }

    private static function redirect_notice( $route, $notice ) {
        wp_safe_redirect( add_query_arg( 'tls_notice', sanitize_key( $notice ), self::dashboard_url( $route ) ) );
        exit;
    }

    public static function render_privacy_page() {
        if ( ! is_user_logged_in() ) {
            return '<div class="tls-lms-panel">' . esc_html__( 'Please sign in to manage privacy settings.', 'tutor-learning-suite' ) . '</div>';
        }
        $user_id = get_current_user_id();
        self::sync_age_policy( $user_id );
        $is_student = self::is_student_user( $user_id );
        $private = get_user_meta( $user_id, self::PRIVATE_STUDENT_META, true );
        if ( '' === $private ) {
            $private = '1';
            update_user_meta( $user_id, self::PRIVATE_STUDENT_META, '1' );
        }
        $hide_contact = get_user_meta( $user_id, '_tls_hide_public_contact', true );
        $progress = get_user_meta( $user_id, self::PARENT_PROGRESS_META, true );
        $booking = get_user_meta( $user_id, self::PARENT_BOOKING_META, true );
        $parent_id = (int) get_user_meta( $user_id, self::GUARDIAN_META, true );
        $band = $is_student ? TLS_Age_Safety::age_band( $user_id ) : 'unknown';
        $guardian_status = (string) get_user_meta( $user_id, TLS_Age_Safety::GUARDIAN_STATUS_META, true );
        $notice = '';
        if ( isset( $_GET['tls_error'] ) && 'birthdate_required' === sanitize_key( wp_unslash( $_GET['tls_error'] ) ) ) {
            $notice = '<div class="tls-lms-warning">' . esc_html__( 'Please enter a valid date of birth before changing family permissions.', 'tutor-learning-suite' ) . '</div>';
        }
        if ( isset( $_GET['tls_saved'] ) ) {
            $notice = '<div class="tls-lms-success">' . esc_html__( 'Privacy settings saved.', 'tutor-learning-suite' ) . '</div>';
        }
        $html = '<section class="tls-lms-panel"><h1>' . esc_html__( 'Privacy & Security', 'tutor-learning-suite' ) . '</h1><p>' . esc_html__( 'Manage account privacy and the information shared with a linked parent/guardian.', 'tutor-learning-suite' ) . '</p>' . $notice . '<form method="post" class="tls-lms-form">' . wp_nonce_field( 'tls_privacy_save', 'tls_privacy_nonce', true, false );
        if ( $is_student ) {
            $band_label = array( 'child' => __( 'Child account (under 18)', 'tutor-learning-suite' ), 'transition' => __( 'Transition account (18 to under the configured adult threshold)', 'tutor-learning-suite' ), 'adult' => __( 'Adult account', 'tutor-learning-suite' ), 'unknown' => __( 'Age not verified', 'tutor-learning-suite' ) );
            $html .= '<p class="tls-lms-muted"><strong>' . esc_html__( 'Account safety category:', 'tutor-learning-suite' ) . '</strong> ' . esc_html( $band_label[ $band ] ?? $band_label['unknown'] ) . '</p>';
            if ( 'unknown' === $band ) {
                $html .= '<label>' . esc_html__( 'Date of birth (encrypted and used only for age-based permissions)', 'tutor-learning-suite' ) . '<input type="date" name="student_birth_date" max="' . esc_attr( wp_date( 'Y-m-d' ) ) . '" required></label><p class="tls-lms-warning">' . esc_html__( 'Age-sensitive features remain restricted until your age can be determined. A separate TLS_AGE_ENCRYPTION_KEY must be configured by the site administrator.', 'tutor-learning-suite' ) . '</p>';
            }
            if ( 'adult' === $band ) {
                $html .= '<label class="tls-lms-check"><input type="checkbox" name="tls_private_profile" value="1" ' . checked( $private, '1', false ) . '> ' . esc_html__( 'Keep my student profile private. Untick to let other users see my public student profile.', 'tutor-learning-suite' ) . '</label>';
                $html .= '<label class="tls-lms-check"><input type="checkbox" name="tls_hide_public_contact" value="1" ' . checked( $hide_contact, '1', false ) . '> ' . esc_html__( 'Hide my public contact information.', 'tutor-learning-suite' ) . '</label>';
            } else {
                $html .= '<label class="tls-lms-check"><input type="checkbox" checked disabled> ' . esc_html__( 'Your student profile is private. This is locked for accounts under 18 and for accounts whose age is not yet verified.', 'tutor-learning-suite' ) . '</label>';
            }
            if ( 'child' === $band ) {
                $free_enrollment_state = TLS_Age_Safety::is_child_free_enrollment_enabled( $user_id )
                    ? __( 'Your parent/guardian has enabled self-enrolment in free courses.', 'tutor-learning-suite' )
                    : __( 'Self-enrolment in free courses is currently disabled by your parent/guardian. Ask them to change this in Family & Guardian.', 'tutor-learning-suite' );
                $html .= '<div class="tls-lms-info"><strong>' . esc_html__( 'Course enrolment', 'tutor-learning-suite' ) . '</strong><p>' . esc_html( $free_enrollment_state ) . '</p><p>' . esc_html__( 'Paid courses must be purchased by your parent/guardian through Tutor LMS Course Gifting and claimed from your own account.', 'tutor-learning-suite' ) . '</p></div>';
            }
            $html .= '<div class="tls-lms-warning"><strong>' . esc_html__( 'Public interactions', 'tutor-learning-suite' ) . '</strong><p>' . esc_html__( 'Public reviews, public comments and course discussion posts are disabled for child, transition and age-unverified accounts. You can continue lessons, quizzes and assignments. For concerns, contact site support using the contact method published on this website.', 'tutor-learning-suite' ) . '</p></div>';
        } elseif ( self::is_instructor( $user_id ) ) {
            $html .= '<label class="tls-lms-check"><input type="checkbox" name="tls_hide_public_contact" value="1" ' . checked( $hide_contact, '1', false ) . '> ' . esc_html__( 'Hide my public contact information.', 'tutor-learning-suite' ) . '</label>';
        } else {
            $html .= '<p>' . esc_html__( 'Manage children and sharing permissions in Family & Guardian. There are no additional public-profile settings for this account.', 'tutor-learning-suite' ) . '</p>';
        }
        if ( $parent_id && $is_student ) {
            $html .= '<h2>' . esc_html__( 'Parent/guardian sharing', 'tutor-learning-suite' ) . '</h2>';
            if ( in_array( $band, array( 'child', 'transition' ), true ) && 'approved' !== $guardian_status ) {
                $html .= '<p class="tls-lms-warning">' . esc_html__( 'Your parent/guardian relationship is awaiting administrator review. Parent access remains disabled until the review is complete.', 'tutor-learning-suite' ) . '</p>';
            } elseif ( in_array( $band, array( 'transition', 'adult' ), true ) ) {
                $html .= '<p class="tls-lms-muted">' . esc_html__( 'You are in the transition/adult category. Parent access is off by default and requires your own explicit consent. You can change the specific sharing permissions here. To report a concern about the relationship, use Family & Guardian and request an administrator review.', 'tutor-learning-suite' ) . '</p>';
            }
            $can_set = 'unknown' !== $band && ( ! in_array( $band, array( 'child', 'transition' ), true ) || 'approved' === $guardian_status );
            if ( $can_set ) {
                $html .= '<label class="tls-lms-check"><input type="checkbox" name="tls_parent_progress" value="1" ' . checked( $progress, '1', false ) . '> ' . esc_html__( 'Allow my parent/guardian to view my courses, course progress, quiz and assignment grades, and attendance.', 'tutor-learning-suite' ) . '</label><label class="tls-lms-check"><input type="checkbox" name="tls_parent_booking" value="1" ' . checked( $booking, '1', false ) . '> ' . esc_html__( 'Allow my parent/guardian to make private tutoring booking requests on my behalf.', 'tutor-learning-suite' ) . '</label>';
            }
        }
        $html .= '<button class="tls-lms-button" type="submit" name="tls_save_privacy" value="1">' . esc_html__( 'Save settings', 'tutor-learning-suite' ) . '</button></form></section>';
        return $html;
    }

    private static function save_privacy_post() {
        $user_id = get_current_user_id();
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tls_privacy_nonce'] ?? '' ) ), 'tls_privacy_save' ) ) {
            return;
        }
        $is_student = self::is_student_user( $user_id );
        if ( $is_student ) {
            $band = TLS_Age_Safety::age_band( $user_id );
            if ( 'unknown' === $band ) {
                $birth = sanitize_text_field( wp_unslash( $_POST['student_birth_date'] ?? '' ) );
                if ( ! TLS_Age_Safety::store_birth_date( $user_id, $birth ) ) {
                    wp_safe_redirect( add_query_arg( 'tls_error', 'birthdate_required', self::dashboard_url( self::PRIVACY_ROUTE ) ) );
                    exit;
                }
                TLS_Age_Safety::sync_user( $user_id );
                $band = TLS_Age_Safety::age_band( $user_id );
            }
            if ( 'adult' === $band ) {
                update_user_meta( $user_id, self::PRIVATE_STUDENT_META, isset( $_POST['tls_private_profile'] ) ? '1' : '0' );
                update_user_meta( $user_id, '_tls_hide_public_contact', isset( $_POST['tls_hide_public_contact'] ) ? '1' : '0' );
            } else {
                update_user_meta( $user_id, self::PRIVATE_STUDENT_META, '1' );
            }
            $parent_id = absint( get_user_meta( $user_id, self::GUARDIAN_META, true ) );
            $guardian_status = (string) get_user_meta( $user_id, TLS_Age_Safety::GUARDIAN_STATUS_META, true );
            $can_share = $parent_id && 'unknown' !== $band && ( 'child' === $band ? 'approved' === $guardian_status : in_array( $guardian_status, array( 'student_consented', 'approved' ), true ) );
            update_user_meta( $user_id, self::PARENT_PROGRESS_META, $can_share && isset( $_POST['tls_parent_progress'] ) ? '1' : '0' );
            update_user_meta( $user_id, self::PARENT_BOOKING_META, $can_share && isset( $_POST['tls_parent_booking'] ) ? '1' : '0' );
            if ( $can_share && in_array( $band, array( 'transition', 'adult' ), true ) && ( isset( $_POST['tls_parent_progress'] ) || isset( $_POST['tls_parent_booking'] ) ) ) {
                // Re-consent after the age transition must be a fresh student action.
                update_user_meta( $user_id, '_tls_guardian_consent_at', current_time( 'mysql', true ) );
                update_user_meta( $user_id, '_tls_guardian_consent_by', $parent_id );
                delete_user_meta( $user_id, '_tls_adult_parent_consent_required' );
            }
            if ( $can_share && in_array( $band, array( 'transition', 'adult' ), true ) && isset( $_POST['tls_parent_booking'] ) ) {
                update_user_meta( $user_id, '_tls_parent_booking_consent_at', current_time( 'mysql', true ) );
            } else {
                delete_user_meta( $user_id, '_tls_parent_booking_consent_at' );
            }
            if ( $parent_id ) {
                TLS_Age_Safety::audit( 'student_updated_parent_permissions', $user_id, array( 'guardian_id' => $parent_id, 'progress' => get_user_meta( $user_id, self::PARENT_PROGRESS_META, true ), 'booking' => get_user_meta( $user_id, self::PARENT_BOOKING_META, true ), 'enroll' => get_user_meta( $user_id, self::PARENT_ENROLL_META, true ), 'age_band' => $band ) );
            }
        } else {
            update_user_meta( $user_id, '_tls_hide_public_contact', isset( $_POST['tls_hide_public_contact'] ) ? '1' : '0' );
        }
        wp_safe_redirect( add_query_arg( 'tls_saved', '1', self::dashboard_url( self::PRIVACY_ROUTE ) ) );
        exit;
    }

    public static function family_shortcode() {
        return self::render_family_page();
    }

    public static function privacy_shortcode() {
        return self::render_privacy_page();
    }

    public static function enqueue_assets() {
        wp_enqueue_style( 'tls-suite', TLS_URL . 'assets/css/suite.css', array(), TLS_VERSION );
        wp_enqueue_style( 'tls-integration', TLS_URL . 'assets/css/integration.css', array( 'tls-suite' ), TLS_VERSION );
        if ( ! is_singular() || ! is_user_logged_in() || ! class_exists( '\TutorPro\GiftCourse\GiftCourse' ) || ! function_exists( 'tutor_utils' ) ) {
            return;
        }

        // Match Tutor Pro 4.2.0's InitGift::is_enabled() gating. The Pro class
        // may be autoloadable even when the gifting add-on is switched off.
        try {
            $monetization = (string) tutor_utils()->get_option( 'monetize_by' );
            $gift_enabled = tutor_utils()->get_option( 'enable_gift_course', false );
            if ( ! $gift_enabled || ! in_array( $monetization, array( 'tutor', 'wc' ), true ) ) {
                return;
            }
            if ( tutor_utils()->is_addon_enabled( 'subscription' ) && tutor_utils()->get_option( 'membership_only_mode', false ) ) {
                return;
            }
        } catch ( Throwable $e ) {
            return;
        }

        $course_id   = absint( get_queried_object_id() );
        $course_type = function_exists( 'tutor' ) && ! empty( tutor()->course_post_type ) ? tutor()->course_post_type : 'courses';
        if ( get_post_type( $course_id ) !== $course_type || ! TLS_Age_Safety::is_explicitly_paid_course( $course_id ) ) {
            return;
        }

        try {
            if ( ! call_user_func( array( '\TutorPro\GiftCourse\GiftCourse', 'can_gift_course' ), $course_id ) ) {
                return;
            }
        } catch ( Throwable $e ) {
            return;
        }

        $parent_id = get_current_user_id();
        if ( TLS_Age_Safety::is_restricted_student( $parent_id ) ) {
            return;
        }

        $recipients = array();
        foreach ( self::guardian_linked_children( $parent_id ) as $child ) {
            if ( ! $child instanceof WP_User
                || ! TLS_Age_Safety::is_approved_guardian_for_child( $parent_id, $child->ID, true )
                || self::is_student_enrolled_in_course( $child->ID, $course_id ) ) {
                continue;
            }
            $recipients[] = array(
                'name'  => $child->display_name,
                'email' => $child->user_email,
            );
        }

        if ( ! $recipients ) {
            return;
        }

        wp_enqueue_script( 'tls-guardian-course-gifting', TLS_URL . 'assets/js/guardian-course-gifting.js', array(), TLS_VERSION, true );
        wp_localize_script( 'tls-guardian-course-gifting', 'TLSGuardianGiftRecipients', array(
            'children' => array_values( $recipients ),
            'labels'   => array(
                'label'    => __( 'Who is this course for?', 'tutor-learning-suite' ),
                'choose'   => __( 'Choose a linked child', 'tutor-learning-suite' ),
                'other'    => __( 'Someone else (enter details in Tutor LMS form)', 'tutor-learning-suite' ),
                'hint'     => __( 'Choose a linked child to fill the native Tutor LMS gift form. Tutor LMS handles checkout, gift delivery and claiming.', 'tutor-learning-suite' ),
                'required' => __( 'Choose a linked child or select another recipient before continuing.', 'tutor-learning-suite' ),
                'invalid'  => __( 'The gift recipient must match the selected linked child.', 'tutor-learning-suite' ),
            ),
        ) );
    }

    public static function calendar_shortcode( $atts ) {
        $atts = shortcode_atts( array( 'tutor_id' => 0 ), $atts, 'tutor_suite_tutor_calendar' );
        $id = absint( $atts['tutor_id'] );
        if ( ! $id && isset( $_GET['tutor_id'] ) ) {
            $id = absint( $_GET['tutor_id'] );
        }
        return self::calendar_markup( $id );
    }

    /** Resolve the tutor's own calendar timezone, with an Algeria-safe fallback. */
    private static function calendar_timezone_string( $user_id ) {
        $user_id = absint( $user_id );
        $timezone = '';
        if ( $user_id && class_exists( 'TUTOR\User' ) && method_exists( 'TUTOR\User', 'get_user_timezone_string' ) ) {
            try {
                $timezone = (string) \TUTOR\User::get_user_timezone_string( $user_id );
            } catch ( Throwable $e ) {
                $timezone = '';
            }
        }
        $profile_timezone = $user_id ? (string) get_user_meta( $user_id, '_tutor_timezone', true ) : '';
        if ( '' !== $profile_timezone ) {
            $timezone = $profile_timezone;
        }
        if ( '' === $timezone ) {
            $timezone = 'Africa/Algiers';
        }
        try {
            new DateTimeZone( $timezone );
        } catch ( Exception $e ) {
            $timezone = 'Africa/Algiers';
        }
        return $timezone;
    }

    private static function calendar_markup( $tutor_id ) {
        if ( ! $tutor_id || ! self::is_instructor( $tutor_id ) ) {
            return '<p class="tls-lms-empty">' . esc_html__( 'Tutor availability is not available.', 'tutor-learning-suite' ) . '</p>';
        }
        if ( ! class_exists( 'TLMS_Private_Sessions' ) ) {
            return '<p class="tls-lms-empty">' . esc_html__( 'Private tutoring is not enabled for this tutor.', 'tutor-learning-suite' ) . '</p>';
        }
        wp_enqueue_script( 'tls-month-calendar', TLS_URL . 'assets/js/month-calendar.js', array(), TLS_VERSION, true );
        wp_localize_script( 'tls-month-calendar', 'TLSMonthCalendar', array(
            'ajax' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'tls_month_availability' ),
            'slotNonce' => wp_create_nonce( 'tlms_ps_nonce' ),
            'labels' => array(
                'loading' => __( 'Loading available times…', 'tutor-learning-suite' ),
                'empty' => __( 'No bookable times for this day. Choose another date.', 'tutor-learning-suite' ),
                'error' => __( 'Availability could not be loaded.', 'tutor-learning-suite' ),
                'select' => __( 'Select a day to see available times.', 'tutor-learning-suite' ),
                'picked' => __( 'Time selected. Review the request form below; the tutor confirms the final session.', 'tutor-learning-suite' ),
                'chooseSlot' => __( 'Choose a date and available time before sending the request.', 'tutor-learning-suite' ),
            ),
        ) );
        $saved_types = (array) get_user_meta( $tutor_id, '_tlms_ps_tutoring_options', true );
        $type_labels = array( 'online' => __( 'Online', 'tutor-learning-suite' ), 'tutor_home' => __( 'At tutor’s location', 'tutor-learning-suite' ), 'student_home' => __( 'At student’s location', 'tutor-learning-suite' ) );
        $duration_defaults = array( 'online' => array( 30, 60, 90, 120 ), 'tutor_home' => array( 60, 90, 120 ), 'student_home' => array( 60, 90, 120 ) );
        $saved_durations = (array) get_user_meta( $tutor_id, '_tlms_ps_durations', true );
        $enabled_types = array();
        $durations_by_type = array();
        $enabled_formats = array();
        foreach ( $type_labels as $type_key => $type_label ) {
            $default_enabled = in_array( $type_key, array( 'online', 'tutor_home' ), true );
            $one_to_one_key = 'one_to_one_' . $type_key;
            $allow_one = array_key_exists( $one_to_one_key, $saved_types ) ? ! empty( $saved_types[ $one_to_one_key ] ) : $default_enabled;
            $allow_group = ! empty( $saved_types[ 'group_' . $type_key ] );
            $is_enabled = $allow_one || $allow_group;
            if ( ! $is_enabled ) { continue; }
            if ( $allow_one ) { $enabled_formats['one_to_one'] = __( '1-to-1', 'tutor-learning-suite' ); }
            if ( $allow_group ) { $enabled_formats['group'] = __( 'Group format (manual arrangement)', 'tutor-learning-suite' ); }
            $enabled_types[ $type_key ] = $type_label;
            $saved = array_key_exists( $type_key, $saved_durations )
                ? array_values( array_intersect( array_map( 'absint', (array) $saved_durations[ $type_key ] ), array( 30, 45, 60, 90, 120 ) ) )
                : $duration_defaults[ $type_key ];
            $durations_by_type[ $type_key ] = $saved ? array_values( array_unique( $saved ) ) : $duration_defaults[ $type_key ];
        }
        $private_settings = (array) get_user_meta( $tutor_id, '_tlms_ps_private_settings', true );
        if ( ! empty( $private_settings['offer_tryout'] ) ) {
            $tryout_duration = absint( $private_settings['tryout_duration'] ?? 30 );
            if ( in_array( $tryout_duration, array( 15, 30, 45, 60, 90, 120 ), true ) ) {
                foreach ( $durations_by_type as $method => $method_durations ) {
                    if ( ! in_array( $tryout_duration, $method_durations, true ) ) { $durations_by_type[ $method ][] = $tryout_duration; sort( $durations_by_type[ $method ] ); }
                }
            }
        }
        if ( ! $enabled_types ) {
            return '<p class="tls-lms-empty">' . esc_html__( 'This tutor has not configured any private tutoring methods.', 'tutor-learning-suite' ) . '</p>';
        }
        $first_session_format = isset( $enabled_formats['one_to_one'] ) ? 'one_to_one' : 'group';
        $first_type = '';
        foreach ( $enabled_types as $method_key => $unused_method_label ) {
            $one_key = 'one_to_one_' . $method_key;
            $default_one = in_array( $method_key, array( 'online', 'tutor_home' ), true );
            $method_allows_one = array_key_exists( $one_key, $saved_types ) ? ! empty( $saved_types[ $one_key ] ) : $default_one;
            $method_allows_group = ! empty( $saved_types[ 'group_' . $method_key ] );
            if ( ( 'group' === $first_session_format && $method_allows_group ) || ( 'one_to_one' === $first_session_format && $method_allows_one ) ) { $first_type = $method_key; break; }
        }
        if ( ! $first_type ) { $first_type = key( $enabled_types ); }
        $first_duration = (int) reset( $durations_by_type[ $first_type ] );
        $calendar_timezone = self::calendar_timezone_string( $tutor_id );
        $calendar_now = new DateTimeImmutable( 'now', new DateTimeZone( $calendar_timezone ) );
        $date = $calendar_now->format( 'Y-m-d' );
        $html = '<div class="tls-month-calendar" data-tutor-id="' . (int) $tutor_id . '" data-today="' . esc_attr( $date ) . '" data-timezone="' . esc_attr( $calendar_timezone ) . '" data-durations="' . esc_attr( wp_json_encode( $durations_by_type ) ) . '">';
        $html .= '<div class="tls-calendar-controls">';
        if ( count( $enabled_formats ) > 1 ) {
            $html .= '<label>' . esc_html__( 'Session format', 'tutor-learning-suite' ) . '<select class="tls-calendar-session-format">';
            foreach ( $enabled_formats as $format_key => $format_label ) {
                $html .= '<option value="' . esc_attr( $format_key ) . '" ' . selected( $first_session_format, $format_key, false ) . '>' . esc_html( $format_label ) . '</option>';
            }
            $html .= '</select></label>';
        } else {
            $html .= '<input type="hidden" class="tls-calendar-session-format" value="' . esc_attr( $first_session_format ) . '">';
        }
        $html .= '<label>' . esc_html__( 'Teaching method', 'tutor-learning-suite' ) . '<select class="tls-calendar-type">';
        foreach ( $enabled_types as $type_key => $type_label ) {
            $one_key = 'one_to_one_' . $type_key;
            $default_one = in_array( $type_key, array( 'online', 'tutor_home' ), true );
            $allow_one = array_key_exists( $one_key, $saved_types ) ? ! empty( $saved_types[ $one_key ] ) : $default_one;
            $allow_group = ! empty( $saved_types[ 'group_' . $type_key ] );
            $html .= '<option value="' . esc_attr( $type_key ) . '" data-allow-one="' . ( $allow_one ? '1' : '0' ) . '" data-allow-group="' . ( $allow_group ? '1' : '0' ) . '">' . esc_html( $type_label ) . '</option>';
        }
        $html .= '</select></label><label>' . esc_html__( 'Session length', 'tutor-learning-suite' ) . '<select class="tls-calendar-duration">';
        foreach ( $durations_by_type[ $first_type ] as $minutes ) {
            $html .= '<option value="' . (int) $minutes . '">' . (int) $minutes . ' ' . esc_html__( 'min', 'tutor-learning-suite' ) . '</option>';
        }
        $html .= '</select></label></div><p class="tls-calendar-zone">' . sprintf( esc_html__( 'All times shown in %s (tutor local time).', 'tutor-learning-suite' ), '<strong>' . esc_html( $calendar_timezone ) . '</strong>' ) . '</p><p class="tls-calendar-request-note">' . esc_html__( 'Your selected time is a preference, not a reservation. Another learner may request it until the tutor sends a proposal.', 'tutor-learning-suite' ) . '</p><div class="tls-calendar-heading"><button type="button" class="tls-calendar-prev" aria-label="' . esc_attr__( 'Previous month', 'tutor-learning-suite' ) . '">‹</button><strong class="tls-calendar-month"></strong><button type="button" class="tls-calendar-next" aria-label="' . esc_attr__( 'Next month', 'tutor-learning-suite' ) . '">›</button></div><div class="tls-calendar-weekdays">';
        foreach ( array( __( 'Sun', 'tutor-learning-suite' ), __( 'Mon', 'tutor-learning-suite' ), __( 'Tue', 'tutor-learning-suite' ), __( 'Wed', 'tutor-learning-suite' ), __( 'Thu', 'tutor-learning-suite' ), __( 'Fri', 'tutor-learning-suite' ), __( 'Sat', 'tutor-learning-suite' ) ) as $day ) {
            $html .= '<span>' . esc_html( $day ) . '</span>';
        }
        $html .= '</div><div class="tls-calendar-days" role="group" aria-label="' . esc_attr__( 'Tutor available days', 'tutor-learning-suite' ) . '"></div><div class="tls-calendar-times"><p class="tls-calendar-status" aria-live="polite">' . esc_html__( 'Select a day to see available times.', 'tutor-learning-suite' ) . '</p><div class="tls-calendar-slots"></div><div class="tls-calendar-picked-slot" hidden aria-live="polite"><span>' . esc_html__( 'Preferred time (not reserved)', 'tutor-learning-suite' ) . '</span><strong class="tls-calendar-picked-value"></strong></div></div></div>';
        return $html;
    }

    public static function ajax_month_availability() {
        if ( function_exists( 'tls_rate_limited' ) && tls_rate_limited( 'month_avail', 90, 60 ) ) { wp_send_json_error( array( 'message' => 'Too many requests. Please wait a moment.' ), 429 ); }
        check_ajax_referer( 'tls_month_availability', 'nonce' );
        global $wpdb;
        $tutor_id = absint( $_POST['tutor_id'] ?? 0 );
        $year = absint( $_POST['year'] ?? 0 );
        $month = absint( $_POST['month'] ?? 0 );
        $tutor_timezone = self::calendar_timezone_string( $tutor_id );
        $tutor_today = new DateTimeImmutable( 'now', new DateTimeZone( $tutor_timezone ) );
        if ( ! self::is_instructor( $tutor_id ) || $year < (int) $tutor_today->format( 'Y' ) || $month < 1 || $month > 12 ) {
            wp_send_json_error( array( 'message' => __( 'Invalid availability request.', 'tutor-learning-suite' ) ), 400 );
        }
        $table = $wpdb->prefix . 'tlms_private_availability';
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists !== $table ) {
            wp_send_json_success( array( 'weekdays' => array() ) );
        }
        $weekdays = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT weekday FROM {$table} WHERE tutor_id=%d AND enabled=1", $tutor_id ) );
        wp_send_json_success( array( 'weekdays' => array_map( 'absint', (array) $weekdays ) ) );
    }

    public static function render_notice() {
        $notice = isset( $_GET['tls_notice'] ) ? sanitize_key( wp_unslash( $_GET['tls_notice'] ) ) : '';
        $map = array(
            'request_sent' => __( 'If this address belongs to an eligible student account, an invitation will be sent. For privacy, we do not disclose account status here.', 'tutor-learning-suite' ),
            'invalid' => __( 'Please enter a valid email and confirm your relationship.', 'tutor-learning-suite' ),
            'child_invalid' => __( 'The child details are incomplete, invalid, or do not meet the current account age rules.', 'tutor-learning-suite' ),
            'child_error' => __( 'The child account could not be created.', 'tutor-learning-suite' ),
            'child_created_pending_review' => __( 'Child account created. Parent access is pending administrator review.', 'tutor-learning-suite' ),
            'guardian_age_saved' => __( 'Guardian age information saved. You can now request or manage family links.', 'tutor-learning-suite' ),
            'guardian_age_not_eligible' => __( 'Family management requires the configured adult-age threshold.', 'tutor-learning-suite' ),
            'birth_encryption_unavailable' => __( 'The date of birth could not be stored securely. Ask the site administrator to configure the age-encryption key.', 'tutor-learning-suite' ),
            'birth_encryption_unavailable' => __( 'The birth date could not be securely stored. Ask the site administrator to configure the age-encryption key.', 'tutor-learning-suite' ),
            'unlinked' => __( 'Family link removed.', 'tutor-learning-suite' ),
            'review_requested' => __( 'Your request has been sent to the child-safety administrator. The link remains active until an administrator reviews it.', 'tutor-learning-suite' ),
            'review_request_invalid' => __( 'A linked student account and a short explanation are required for a review request.', 'tutor-learning-suite' ),
            'rejected' => __( 'Family link request rejected.', 'tutor-learning-suite' ),
            'link_accepted' => __( 'Family link request accepted. Sharing remains disabled until an administrator verifies the guardian relationship and approves it. Eligible sharing permissions must then be explicitly enabled.', 'tutor-learning-suite' ),
            'birthdate_required' => __( 'A valid date of birth is required before the family link can be accepted.', 'tutor-learning-suite' ),
        );
        return isset( $map[ $notice ] ) ? '<div class="tls-lms-success">' . esc_html( $map[ $notice ] ) . '</div>' : '';
    }
}
