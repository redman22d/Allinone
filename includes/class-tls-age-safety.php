<?php
/**
 * Centralized age classification, guardian authorization and child-safety policy.
 * Tutor LMS roles are deliberately unchanged; this is an additive policy layer.
 */
defined( 'ABSPATH' ) || exit;

final class TLS_Age_Safety {
    const CHILD_FREE_ENROLL_META = '_tls_child_free_self_enroll';
    const DOB_META = '_tls_birth_date_enc_v2';
    const LEGACY_DOB_META = '_tls_birth_date_enc';
    const AGE_BAND_META = '_tls_age_policy_band';
    const GUARDIAN_STATUS_META = '_tls_guardian_verification_status';
    const DOB_MIGRATION_CHECKED_META = '_tls_dob_migration_checked_130';
    const AUDIT_VERSION = '1.0.0';
    const CRON_HOOK = 'tls_age_safety_daily_sync';
    const FRONTEND_NOTICE_TRANSIENT = 'tls_age_safety_frontend_notice_';

    public static function init() {
        if ( get_option( 'tls_age_safety_db_version' ) !== self::AUDIT_VERSION ) {
            self::create_audit_table();
        }
        self::migrate_legacy_family_students();
        add_action( 'init', array( __CLASS__, 'schedule_daily_sync' ), 20 );
        add_action( self::CRON_HOOK, array( __CLASS__, 'daily_sync' ) );
        add_action( 'wp_login', array( __CLASS__, 'sync_on_login' ), 20, 2 );
        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
        add_action( 'admin_post_tls_age_safety_review', array( __CLASS__, 'handle_admin_review' ) );
        add_action( 'admin_post_tls_age_safety_migrate', array( __CLASS__, 'handle_admin_migration' ) );
        add_action( 'admin_post_tls_age_safety_revoke', array( __CLASS__, 'handle_admin_revoke' ) );
        add_action( 'admin_post_tls_age_safety_resolve_concern', array( __CLASS__, 'handle_admin_resolve_concern' ) );
        add_action( 'admin_notices', array( __CLASS__, 'admin_key_notice' ) );

        // Tutor LMS writes reviews, Q&A and lesson comments through AJAX/REST;
        // intercept at the server boundary rather than relying on hidden buttons.
        foreach ( array(
            'tutor_update_lesson_comment',
            'tutor_qna_update',
            'tutor_qna_single_action',
            'tutor_delete_lesson_comment',
        ) as $action ) {
            add_action( 'wp_ajax_' . $action, array( __CLASS__, 'deny_public_interaction_ajax' ), 0 );
        }
        add_filter( 'preprocess_comment', array( __CLASS__, 'deny_wordpress_comment' ), 1 );
        add_filter( 'comments_open', array( __CLASS__, 'filter_comments_open' ), 99, 2 );
        add_filter( 'get_comment_author', array( __CLASS__, 'filter_review_author_context' ), 10, 3 );
        add_filter( 'rest_pre_dispatch', array( __CLASS__, 'filter_rest_writes' ), 1, 3 );

        // BuddyPress is optional. When enabled, block private messages involving
        // restricted learner accounts until a dedicated safeguarded channel exists.
        add_action( 'messages_message_before_save', array( __CLASS__, 'guard_buddypress_message' ), 1, 1 );
        add_filter( 'bp_get_send_message_button', array( __CLASS__, 'filter_buddypress_message_button' ), 20, 1 );
        add_action( 'bp_activity_before_save', array( __CLASS__, 'guard_buddypress_activity' ), 1, 1 );
        add_action( 'template_redirect', array( __CLASS__, 'guard_buddypress_messages_screen' ), 1 );

        // Better Messages uses its own API, not BuddyPress message hooks. Restricted
        // learners may use only conversations that include their approved guardian;
        // tutors must be added by that guardian. Group chats remain unavailable to
        // restricted learners. Adult learner accounts keep their normal permissions.
        add_filter( 'better_messages_check_access_override', array( __CLASS__, 'filter_better_messages_thread_access' ), 20, 4 );
        add_filter( 'better_messages_can_send_message', array( __CLASS__, 'filter_better_messages_send_permission' ), 20, 3 );
        add_action( 'better_messages_before_message_send', array( __CLASS__, 'guard_better_messages_before_send' ), 20, 2 );
        add_action( 'better_messages_before_new_thread', array( __CLASS__, 'guard_better_messages_before_new_thread' ), 20, 2 );
        add_filter( 'better_messages_can_add_participant', array( __CLASS__, 'filter_better_messages_add_participant' ), 20, 4 );
        add_action( 'better_messages_before_add_participants', array( __CLASS__, 'guard_better_messages_before_add_participants' ), 20, 2 );
        add_filter( 'better_messages_can_invite', array( __CLASS__, 'filter_better_messages_invite' ), 20, 3 );
        add_filter( 'better_messages_chat_user_can_join', array( __CLASS__, 'filter_better_messages_chat_room_join' ), 20, 4 );
        add_filter( 'better_messages_has_access_to_group_chat', array( __CLASS__, 'filter_better_messages_group_chat_access' ), 20, 3 );

        // Restricted learner accounts may self-enrol in explicitly free courses only
        // when their approved guardian enabled the permission. Paid courses require
        // Tutor LMS Pro's native gift flow initiated by the guardian.
        add_action( 'tutor_before_enroll', array( __CLASS__, 'deny_restricted_enrollment' ), 0, 1 );
        add_filter( 'tutor_enroll_data', array( __CLASS__, 'filter_restricted_enrollment_data' ), 0, 1 );
        add_action( 'save_post_tutor_enrolled', array( __CLASS__, 'record_child_course_enrollment' ), 99, 3 );
        add_filter( 'tutor_can_purchase_course', array( __CLASS__, 'filter_course_purchase' ), 1, 2 );
        add_filter( 'tutor_can_gift_course', array( __CLASS__, 'filter_native_gift_button' ), 1, 2 );
        add_filter( 'tutor_dashboard_account_pages', array( __CLASS__, 'filter_tutor_account_pages' ), 1, 1 );
        add_filter( 'tutor_dashboard/nav_items', array( __CLASS__, 'filter_tutor_student_nav' ), 1, 1 );
        add_filter( 'tutor_student_dashboard_nav', array( __CLASS__, 'filter_tutor_student_nav' ), 1, 1 );
        add_filter( 'tutor_checkout_enable_pay_now_btn', array( __CLASS__, 'filter_checkout_button' ), 1, 2 );
        add_action( 'tutor_action_tutor_pay_now', array( __CLASS__, 'deny_direct_checkout_action' ), 0 );
        add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'filter_woocommerce_add_to_cart' ), 1, 5 );
        add_action( 'woocommerce_before_checkout_process', array( __CLASS__, 'deny_woocommerce_checkout' ), 0 );

        add_filter( 'body_class', array( __CLASS__, 'body_classes' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_safety_css' ), 40 );
    }

    public static function activate() {
        self::create_audit_table();
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
        }
    }

    public static function deactivate() {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
        }
    }

    public static function is_key_configured() {
        if ( ! defined( 'TLS_AGE_ENCRYPTION_KEY' ) || ! is_string( TLS_AGE_ENCRYPTION_KEY ) ) {
            return false;
        }
        return (bool) preg_match( '/^[a-f0-9]{64}$/i', trim( TLS_AGE_ENCRYPTION_KEY ) );
    }

    private static function key_from_hex( $value ) {
        if ( ! is_string( $value ) || ! preg_match( '/^[a-f0-9]{64}$/i', trim( $value ) ) ) {
            return false;
        }
        $key = hex2bin( trim( $value ) );
        return is_string( $key ) && 32 === strlen( $key ) ? $key : false;
    }

    private static function encryption_key() {
        if ( ! defined( 'TLS_AGE_ENCRYPTION_KEY' ) || ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'openssl_decrypt' ) ) {
            return false;
        }
        return self::key_from_hex( TLS_AGE_ENCRYPTION_KEY );
    }

    public static function encrypt_date( $date ) {
        $key = self::encryption_key();
        if ( false === $key || ! self::valid_date( $date ) ) {
            return false;
        }
        try {
            $iv = random_bytes( 12 );
        } catch ( Throwable $e ) {
            return false;
        }
        $tag = '';
        $cipher = openssl_encrypt( $date, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'tls-birth-date-v2', 16 );
        if ( false === $cipher || 16 !== strlen( $tag ) ) {
            return false;
        }
        return 'v2:' . base64_encode( $iv . $tag . $cipher );
    }

    private static function decrypt_v2_with_key( $stored, $key ) {
        if ( false === $key || ! is_string( $stored ) || 0 !== strpos( $stored, 'v2:' ) || ! function_exists( 'openssl_decrypt' ) ) {
            return '';
        }
        $raw = base64_decode( substr( $stored, 3 ), true );
        if ( false === $raw || strlen( $raw ) < 29 ) {
            return '';
        }
        $date = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ), 'tls-birth-date-v2' );
        return self::valid_date( $date ) ? $date : '';
    }

    private static function decrypt_v2( $stored ) {
        $current = self::decrypt_v2_with_key( $stored, self::encryption_key() );
        if ( $current ) {
            return $current;
        }
        if ( defined( 'TLS_AGE_ENCRYPTION_KEY_PREVIOUS' ) ) {
            return self::decrypt_v2_with_key( $stored, self::key_from_hex( TLS_AGE_ENCRYPTION_KEY_PREVIOUS ) );
        }
        return '';
    }

    /** Legacy decryption is used only during explicit migration to the new key. */
    private static function decrypt_legacy( $stored ) {
        if ( ! is_string( $stored ) || '' === $stored || ! function_exists( 'openssl_decrypt' ) ) {
            return '';
        }
        $raw = base64_decode( $stored, true );
        if ( false === $raw || strlen( $raw ) < 29 ) {
            return '';
        }
        $date = openssl_decrypt(
            substr( $raw, 28 ),
            'aes-256-gcm',
            hash( 'sha256', wp_salt( 'auth' ), true ),
            OPENSSL_RAW_DATA,
            substr( $raw, 0, 12 ),
            substr( $raw, 12, 16 ),
            'tls-birth-date'
        );
        return self::valid_date( $date ) ? $date : '';
    }

    private static function valid_date( $date ) {
        if ( ! is_string( $date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            return false;
        }
        $parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' ) );
        return $parsed && $parsed->format( 'Y-m-d' ) === $date && $parsed <= new DateTimeImmutable( 'today', function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' ) );
    }

    public static function store_birth_date( $user_id, $date ) {
        $user_id = absint( $user_id );
        $encrypted = self::encrypt_date( $date );
        if ( ! $user_id || false === $encrypted ) {
            return false;
        }
        update_user_meta( $user_id, self::DOB_META, $encrypted );
        $stored = get_user_meta( $user_id, self::DOB_META, true );
        if ( ! is_string( $stored ) || ! hash_equals( $encrypted, $stored ) || self::decrypt_v2( $stored ) !== $date ) {
            return false;
        }
        delete_user_meta( $user_id, self::LEGACY_DOB_META );
        self::audit( 'birth_date_updated', $user_id, array( 'age_band' => self::calculate_age_band( $date ) ) );
        return true;
    }

    public static function migrate_user_birth_date( $user_id ) {
        $user_id = absint( $user_id );
        if ( ! $user_id || ! self::is_key_configured() ) {
            return false;
        }
        $v2 = get_user_meta( $user_id, self::DOB_META, true );
        $current_date = self::decrypt_v2_with_key( $v2, self::encryption_key() );
        if ( $current_date ) {
            // A valid v2 value is authoritative; remove any stale legacy copy.
            delete_user_meta( $user_id, self::LEGACY_DOB_META );
            return false;
        }
        $date = self::decrypt_v2_with_key( $v2, defined( 'TLS_AGE_ENCRYPTION_KEY_PREVIOUS' ) ? self::key_from_hex( TLS_AGE_ENCRYPTION_KEY_PREVIOUS ) : false );
        if ( ! $date ) {
            $legacy = get_user_meta( $user_id, self::LEGACY_DOB_META, true );
            $date = self::decrypt_legacy( $legacy );
        }
        if ( ! $date ) {
            return false;
        }
        $encrypted = self::encrypt_date( $date );
        if ( false === $encrypted ) {
            return false;
        }
        update_user_meta( $user_id, self::DOB_META, $encrypted );
        $stored = get_user_meta( $user_id, self::DOB_META, true );
        if ( ! is_string( $stored ) || ! hash_equals( $encrypted, $stored ) || self::decrypt_v2( $stored ) !== $date ) {
            return false;
        }
        delete_user_meta( $user_id, self::LEGACY_DOB_META );
        self::audit( 'birth_date_encryption_migrated', $user_id, array( 'age_band' => self::calculate_age_band( $date ) ) );
        return true;
    }

    public static function birth_date( $user_id ) {
        $user_id = absint( $user_id );
        if ( ! $user_id || ! self::is_key_configured() ) {
            return '';
        }
        $stored = get_user_meta( $user_id, self::DOB_META, true );
        $date = self::decrypt_v2( $stored );
        if ( $date ) {
            return $date;
        }
        if ( get_user_meta( $user_id, self::LEGACY_DOB_META, true ) && self::migrate_user_birth_date( $user_id ) ) {
            return self::decrypt_v2( get_user_meta( $user_id, self::DOB_META, true ) );
        }
        return '';
    }

    public static function age_band( $user_id ) {
        $date = self::birth_date( $user_id );
        return $date ? self::calculate_age_band( $date ) : 'unknown';
    }

    private static function calculate_age_band( $date ) {
        if ( ! self::valid_date( $date ) ) {
            return 'unknown';
        }
        $zone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
        $dob = new DateTimeImmutable( $date, $zone );
        $today = new DateTimeImmutable( 'today', $zone );
        $age = $dob->diff( $today )->y;
        if ( $age < 18 ) {
            return 'child';
        }
        $majority = function_exists( 'apply_filters' ) ? max( 18, min( 25, absint( apply_filters( 'tls_age_of_majority_threshold', 19 ) ) ) ) : 19;
        return $age < $majority ? 'transition' : 'adult';
    }

    public static function is_tutor_student( $user_id ) {
        $user_id = absint( $user_id );
        if ( ! $user_id ) {
            return false;
        }
        if ( class_exists( '\TUTOR\User' ) && method_exists( '\TUTOR\User', 'is_student' ) ) {
            try {
                if ( \TUTOR\User::is_student( $user_id ) ) {
                    return true;
                }
            } catch ( Throwable $e ) {
                // Continue to the WordPress role and legacy student markers.
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

    public static function is_restricted_student( $user_id = 0 ) {
        $user_id = $user_id ? absint( $user_id ) : get_current_user_id();
        if ( ! $user_id || ! self::is_tutor_student( $user_id ) ) {
            return false;
        }
        return 'adult' !== self::age_band( $user_id );
    }

    public static function can_parent_view( $parent_id, $student_id ) {
        $parent_id = absint( $parent_id );
        $student_id = absint( $student_id );
        // Cron is reconciliation only. Never authorize from a stale cached age band.
        if ( $student_id ) self::sync_user( $student_id );
        if ( ! $parent_id || ! $student_id || (int) get_user_meta( $student_id, '_tls_guardian_user_id', true ) !== $parent_id || 'adult' !== self::age_band( $parent_id ) || '1' !== (string) get_user_meta( $parent_id, '_tls_guardian_adult_verified', true ) ) {
            return false;
        }
        if ( '1' !== (string) get_user_meta( $student_id, '_tls_parent_can_view_learning', true ) || ! get_user_meta( $student_id, '_tls_guardian_consent_at', true ) || (int) get_user_meta( $student_id, '_tls_guardian_consent_by', true ) !== $parent_id ) {
            return false;
        }
        $band = self::age_band( $student_id );
        $status = (string) get_user_meta( $student_id, self::GUARDIAN_STATUS_META, true );
        if ( in_array( $band, array( 'child', 'transition' ), true ) ) {
            return 'approved' === $status && '1' === (string) get_user_meta( $student_id, '_tls_guardian_verification_completed', true );
        }
        return 'adult' === $band && in_array( $status, array( 'student_consented', 'approved' ), true );
    }

    public static function can_parent_book( $parent_id, $student_id ) {
        $parent_id = absint( $parent_id );
        $student_id = absint( $student_id );
        // Age transitions and policy-threshold changes revoke access immediately.
        if ( $student_id ) self::sync_user( $student_id );
        if ( ! $parent_id || ! $student_id || 'adult' !== self::age_band( $parent_id ) || '1' !== (string) get_user_meta( $parent_id, '_tls_guardian_adult_verified', true ) || (int) get_user_meta( $student_id, '_tls_guardian_user_id', true ) !== $parent_id || '1' !== (string) get_user_meta( $student_id, '_tls_parent_can_book', true ) ) {
            return false;
        }
        if ( ! get_user_meta( $student_id, '_tls_guardian_consent_at', true ) || (int) get_user_meta( $student_id, '_tls_guardian_consent_by', true ) !== $parent_id ) {
            return false;
        }
        $band = self::age_band( $student_id );
        $status = (string) get_user_meta( $student_id, self::GUARDIAN_STATUS_META, true );
        if ( in_array( $band, array( 'child', 'transition' ), true ) ) {
            return 'approved' === $status && '1' === (string) get_user_meta( $student_id, '_tls_guardian_verification_completed', true ) && ( 'child' === $band || (bool) get_user_meta( $student_id, '_tls_parent_booking_consent_at', true ) );
        }
        if ( 'adult' === $band ) {
            return in_array( $status, array( 'student_consented', 'approved' ), true ) && (bool) get_user_meta( $student_id, '_tls_parent_booking_consent_at', true );
        }
        return false;
    }

    /**
     * Confirm the active, administrator-approved guardian relationship for a learner.
     * This is intentionally separate from the learner's free-course permission.
     */
    public static function is_approved_guardian_for_child( $parent_id, $student_id, $require_current_parent = false ) {
        $parent_id  = absint( $parent_id );
        $student_id = absint( $student_id );
        if ( $student_id ) {
            self::sync_user( $student_id );
        }
        if ( ! $parent_id || ! $student_id || ! self::is_tutor_student( $student_id ) ) {
            return false;
        }
        if ( $require_current_parent && get_current_user_id() !== $parent_id ) {
            return false;
        }
        if ( (int) get_user_meta( $student_id, '_tls_guardian_user_id', true ) !== $parent_id
            || 'adult' !== self::age_band( $parent_id )
            || '1' !== (string) get_user_meta( $parent_id, '_tls_guardian_adult_verified', true )
            || ! get_user_meta( $student_id, '_tls_guardian_consent_at', true )
            || (int) get_user_meta( $student_id, '_tls_guardian_consent_by', true ) !== $parent_id ) {
            return false;
        }

        $band   = self::age_band( $student_id );
        $status = (string) get_user_meta( $student_id, self::GUARDIAN_STATUS_META, true );
        if ( 'child' === $band ) {
            return 'approved' === $status
                && '1' === (string) get_user_meta( $student_id, '_tls_guardian_verification_completed', true );
        }

        // Transition/adult learners can retain a guardian relationship only after
        // their own consent has been renewed. These accounts are not eligible for
        // the parent-controlled under-18 free-course toggle.
        return in_array( $band, array( 'transition', 'adult' ), true )
            && in_array( $status, array( 'student_consented', 'approved' ), true );
    }

    /**
     * Validate an already-approved guardian relationship without running the
     * age-sync routine. Runtime permission checks must be read-only: sync_user()
     * is a migration/reconciliation routine and may revoke stale permissions.
     */
    private static function has_approved_guardian_link_read_only( $parent_id, $student_id ) {
        $parent_id  = absint( $parent_id );
        $student_id = absint( $student_id );
        if ( ! $parent_id || ! $student_id || ! self::is_tutor_student( $student_id )
            || 'child' !== self::age_band( $student_id )
            || (int) get_user_meta( $student_id, '_tls_guardian_user_id', true ) !== $parent_id
            || 'adult' !== self::age_band( $parent_id )
            || '1' !== (string) get_user_meta( $parent_id, '_tls_guardian_adult_verified', true )
            || 'approved' !== (string) get_user_meta( $student_id, self::GUARDIAN_STATUS_META, true )
            || '1' !== (string) get_user_meta( $student_id, '_tls_guardian_verification_completed', true )
            || ! get_user_meta( $student_id, '_tls_guardian_consent_at', true )
            || (int) get_user_meta( $student_id, '_tls_guardian_consent_by', true ) !== $parent_id ) {
            return false;
        }
        return true;
    }

    /** Whether an approved guardian may change the free-course setting for a minor. */
    public static function can_guardian_manage_free_enrollment( $parent_id, $student_id ) {
        $parent_id  = absint( $parent_id );
        $student_id = absint( $student_id );
        return $parent_id && $student_id && get_current_user_id() === $parent_id
            && self::has_approved_guardian_link_read_only( $parent_id, $student_id );
    }

    /** Save the parent-controlled free-course self-enrolment permission. */
    public static function set_child_free_enrollment_permission( $parent_id, $student_id, $enabled ) {
        $parent_id  = absint( $parent_id );
        $student_id = absint( $student_id );
        if ( ! $parent_id || ! $student_id || get_current_user_id() !== $parent_id
            || (int) get_user_meta( $student_id, '_tls_guardian_user_id', true ) !== $parent_id ) {
            return false;
        }

        if ( $enabled && ! self::can_guardian_manage_free_enrollment( $parent_id, $student_id ) ) {
            return false;
        }

        update_user_meta( $student_id, self::CHILD_FREE_ENROLL_META, $enabled ? '1' : '0' );
        self::audit( $enabled ? 'guardian_enabled_child_free_course_enrollment' : 'guardian_disabled_child_free_course_enrollment', $student_id, array(
            'guardian_id' => $parent_id,
            'learner_id'  => $student_id,
        ) );
        $stored_permission = (string) get_user_meta( $student_id, self::CHILD_FREE_ENROLL_META, true );
        return $enabled ? '1' === $stored_permission : '0' === $stored_permission;
    }

    /** Current permission state for display in parent and child dashboards. */
    public static function is_child_free_enrollment_enabled( $student_id ) {
        $student_id = absint( $student_id );
        $parent_id  = $student_id ? absint( get_user_meta( $student_id, '_tls_guardian_user_id', true ) ) : 0;
        return '1' === (string) get_user_meta( $student_id, self::CHILD_FREE_ENROLL_META, true )
            && self::has_approved_guardian_link_read_only( $parent_id, $student_id );
    }

    /** Tutor's canonical course price type. A discounted/zero cart total is still a paid course. */
    public static function course_price_type( $course_id ) {
        $course_id = absint( $course_id );
        if ( ! $course_id ) {
            return '';
        }
        if ( function_exists( 'tutor_utils' ) && is_callable( array( tutor_utils(), 'price_type' ) ) ) {
            try {
                $type = strtolower( (string) tutor_utils()->price_type( $course_id ) );
                if ( in_array( $type, array( 'free', 'paid' ), true ) ) {
                    return $type;
                }
            } catch ( Throwable $e ) {
                // Use Tutor's canonical course-price meta below.
            }
        }
        return strtolower( (string) get_post_meta( $course_id, '_tutor_course_price_type', true ) );
    }

    public static function is_explicitly_free_course( $course_id ) {
        $course_id   = absint( $course_id );
        $course_type = function_exists( 'tutor' ) && ! empty( tutor()->course_post_type ) ? tutor()->course_post_type : 'courses';
        $course      = $course_id ? get_post( $course_id ) : null;
        if ( ! $course || $course_type !== $course->post_type || 'publish' !== $course->post_status
            || 'free' !== self::course_price_type( $course_id ) || ! function_exists( 'tutor_utils' ) ) {
            return false;
        }
        // A Tutor add-on (for example, membership access) may make a nominally
        // free-priced course purchasable. Fail closed for those restricted courses.
        try {
            return ! tutor_utils()->is_course_purchasable( $course_id );
        } catch ( Throwable $e ) {
            return false;
        }
    }

    public static function is_explicitly_paid_course( $course_id ) {
        $course_id = absint( $course_id );
        $course_type = function_exists( 'tutor' ) && ! empty( tutor()->course_post_type ) ? tutor()->course_post_type : 'courses';
        $course = $course_id ? get_post( $course_id ) : null;
        return $course && $course_type === $course->post_type && 'publish' === $course->post_status
            && 'paid' === self::course_price_type( $course_id );
    }

    /**
     * Explain why a learner may or may not self-enrol in a Tutor free course.
     * The checks are intentionally read-only and share one code path with the
     * actual enrolment guards, parent dashboard and child dashboard.
     *
     * @return array{allowed:bool,reason:string,parent_id:int}
     */
    public static function child_free_enrollment_decision( $student_id, $course_id = 0 ) {
        $student_id = absint( $student_id );
        $course_id  = absint( $course_id );
        $parent_id  = $student_id ? absint( get_user_meta( $student_id, '_tls_guardian_user_id', true ) ) : 0;
        $decision   = array( 'allowed' => false, 'reason' => 'invalid_student', 'parent_id' => $parent_id );

        if ( ! $student_id || ! self::is_tutor_student( $student_id ) ) {
            return $decision;
        }
        if ( 'child' !== self::age_band( $student_id ) ) {
            $decision['reason'] = 'age_not_child';
            return $decision;
        }
        if ( ! self::has_approved_guardian_link_read_only( $parent_id, $student_id ) ) {
            $decision['reason'] = 'guardian_not_approved';
            return $decision;
        }
        if ( '1' !== (string) get_user_meta( $student_id, self::CHILD_FREE_ENROLL_META, true ) ) {
            $decision['reason'] = 'parent_permission_disabled';
            return $decision;
        }
        if ( ! $course_id ) {
            $decision['allowed'] = true;
            $decision['reason']  = 'allowed';
            return $decision;
        }

        $course = get_post( $course_id );
        $course_type = function_exists( 'tutor' ) && ! empty( tutor()->course_post_type ) ? tutor()->course_post_type : 'courses';
        if ( ! $course || $course_type !== $course->post_type || 'publish' !== $course->post_status ) {
            $decision['reason'] = 'invalid_course';
            return $decision;
        }
        if ( 'free' !== self::course_price_type( $course_id ) ) {
            $decision['reason'] = 'course_not_free';
            return $decision;
        }
        if ( ! function_exists( 'tutor_utils' ) ) {
            $decision['reason'] = 'tutor_unavailable';
            return $decision;
        }
        try {
            if ( tutor_utils()->is_course_purchasable( $course_id ) ) {
                $decision['reason'] = 'course_purchasable';
                return $decision;
            }
        } catch ( Throwable $e ) {
            $decision['reason'] = 'course_price_unavailable';
            return $decision;
        }

        $decision['allowed'] = true;
        $decision['reason']  = 'allowed';
        return $decision;
    }

    /** A restricted child may self-enrol only in a genuinely free course enabled by their guardian. */
    public static function can_child_self_enroll_free( $student_id, $course_id ) {
        $student_id = absint( $student_id );
        if ( ! $student_id || get_current_user_id() !== $student_id ) {
            return false;
        }
        $decision = self::child_free_enrollment_decision( $student_id, $course_id );
        return ! empty( $decision['allowed'] );
    }

    private static function child_free_enrollment_denial_message( $reason ) {
        $messages = array(
            'invalid_student'          => __( 'This account is not recognized as a child learner account. Please contact site support.', 'tutor-learning-suite' ),
            'age_not_child'            => __( 'Free-course self-enrolment permission is available only for verified child accounts under 18.', 'tutor-learning-suite' ),
            'guardian_not_approved'    => __( 'The linked parent/guardian relationship is not currently approved. Please ask the site administrator to review it.', 'tutor-learning-suite' ),
            'parent_permission_disabled' => __( 'Your parent/guardian has not enabled free-course self-enrolment for this account. Ask them to enable it in Family & Guardian.', 'tutor-learning-suite' ),
            'invalid_course'           => __( 'This course is not currently available for enrolment. Please return to the course page or contact support.', 'tutor-learning-suite' ),
            'course_not_free'          => __( 'Tutor LMS marks this course as paid. A parent/guardian must purchase it through Course Gifting.', 'tutor-learning-suite' ),
            'course_purchasable'       => __( 'This course has an additional purchase or access requirement and cannot use free self-enrolment.', 'tutor-learning-suite' ),
            'tutor_unavailable'        => __( 'Tutor LMS is not ready to process enrolment. Please contact site support.', 'tutor-learning-suite' ),
            'course_price_unavailable' => __( 'Tutor LMS could not verify this course price. Enrolment was stopped for safety.', 'tutor-learning-suite' ),
        );
        return $messages[ $reason ] ?? __( 'This course enrolment needs parent/guardian approval.', 'tutor-learning-suite' );
    }

    /**
     * Read the completed Tutor Pro gift currently being redeemed.
     * Pro 4.2.0 uses the tutor_pro_gift_enrollment AJAX action, a processed
     * tutor_scheduler row and a completed native/Commerce order.
     */
    private static function get_current_native_gift_payload( $student_id, $course_id ) {
        $student_id = absint( $student_id );
        $course_id  = absint( $course_id );
        if ( ! $student_id || ! $course_id || get_current_user_id() !== $student_id
            || ! function_exists( 'wp_doing_ajax' ) || ! wp_doing_ajax()
            || empty( $_POST['action'] ) || 'tutor_pro_gift_enrollment' !== sanitize_key( wp_unslash( (string) $_POST['action'] ) )
            || empty( $_POST['reference_id'] ) || absint( $_POST['course_id'] ?? 0 ) !== $course_id
            || ! class_exists( '\TutorPro\GiftCourse\GiftScheduler' )
            || ! class_exists( '\TutorPro\GiftCourse\GiftCourse' ) ) {
            return false;
        }

        $reference_id = sanitize_text_field( wp_unslash( (string) $_POST['reference_id'] ) );
        try {
            $scheduler_class = '\TutorPro\GiftCourse\GiftScheduler';
            $gift_class      = '\TutorPro\GiftCourse\GiftCourse';
            $scheduler       = new $scheduler_class();
            $gift_record     = $scheduler->get( array( 'reference_id' => $reference_id ) );
            if ( ! is_object( $gift_record ) || 'processed' !== (string) ( $gift_record->status ?? '' )
                || absint( $gift_record->scheduled_for ?? 0 ) !== $student_id ) {
                return false;
            }

            // Use Pro's own recipient/publication/status test, then independently
            // verify the course, purchaser, guardian link and completed order.
            if ( ! call_user_func( array( $gift_class, 'can_open_gift' ), $gift_record ) ) {
                return false;
            }
            $payload = json_decode( (string) ( $gift_record->payload ?? '' ) );
            $student = get_userdata( $student_id );
            if ( ! is_object( $payload ) || ! $student
                || absint( $payload->course_id ?? 0 ) !== $course_id
                || strtolower( (string) ( $payload->recipient_email ?? '' ) ) !== strtolower( (string) $student->user_email )
                || empty( $payload->purchaser_id )
                || ! self::is_explicitly_paid_course( $course_id )
                || ! self::is_approved_guardian_for_child( absint( $payload->purchaser_id ), $student_id, false ) ) {
                return false;
            }

            $proceeder = call_user_func( array( $gift_class, 'get_gift_proceeder' ) );
            if ( ! is_object( $proceeder ) || ! is_callable( array( $proceeder, 'get_order' ) ) ) {
                return false;
            }
            $order = $proceeder->get_order( absint( $payload->order_id ?? 0 ) );
            if ( ! is_object( $order ) || 'completed' !== (string) ( $order->order_status ?? '' ) ) {
                return false;
            }

            $payload->_tls_reference_id = $reference_id;
            return $payload;
        } catch ( Throwable $e ) {
            return false;
        }
    }

    public static function has_valid_native_gift_claim( $student_id, $course_id ) {
        if ( ! self::is_restricted_student( $student_id ) ) {
            return false;
        }
        return is_object( self::get_current_native_gift_payload( $student_id, $course_id ) );
    }

    /** Record attribution after Tutor creates a valid free self-enrolment or paid guardian gift claim. */
    public static function record_child_course_enrollment( $post_id, $post, $update ) {
        if ( $update || ! $post instanceof WP_Post || 'tutor_enrolled' !== $post->post_type ) {
            return;
        }
        $student_id = absint( $post->post_author );
        $course_id  = absint( $post->post_parent );
        if ( self::can_child_self_enroll_free( $student_id, $course_id ) ) {
            $parent_id = absint( get_user_meta( $student_id, '_tls_guardian_user_id', true ) );
            update_post_meta( $post_id, '_tls_enrollment_source', 'child_free_self_enrollment' );
            update_post_meta( $post_id, '_tls_guardian_enrollment_parent_id', $parent_id );
            self::audit( 'child_free_course_self_enrolled', $student_id, array( 'guardian_id' => $parent_id, 'post_id' => $course_id, 'learner_id' => $student_id, 'enrollment_id' => absint( $post_id ) ) );
            return;
        }

        $gift_payload = self::get_current_native_gift_payload( $student_id, $course_id );
        if ( ! is_object( $gift_payload ) ) {
            return;
        }
        $parent_id = absint( $gift_payload->purchaser_id );
        update_post_meta( $post_id, '_tls_enrollment_source', 'guardian_native_gift' );
        update_post_meta( $post_id, '_tls_guardian_enrollment_parent_id', $parent_id );
        update_post_meta( $post_id, '_tls_guardian_gift_reference_id', sanitize_text_field( $gift_payload->_tls_reference_id ) );
        self::audit( 'guardian_native_gift_claimed', $student_id, array( 'guardian_id' => $parent_id, 'post_id' => $course_id, 'learner_id' => $student_id, 'enrollment_id' => absint( $post_id ) ) );
    }

    public static function sync_user( $user_id ) {
        $user_id = absint( $user_id );
        if ( ! $user_id || ! self::is_tutor_student( $user_id ) ) {
            return false;
        }
        $new_band = self::age_band( $user_id );
        $old_band = (string) get_user_meta( $user_id, self::AGE_BAND_META, true );
        if ( 'unknown' === $new_band ) {
            update_user_meta( $user_id, '_tls_private_student_profile', '1' );
            update_user_meta( $user_id, '_tls_hide_public_contact', '1' );
            update_user_meta( $user_id, '_tls_parent_can_view_learning', '0' );
            update_user_meta( $user_id, '_tls_parent_can_book', '0' );
            update_user_meta( $user_id, '_tls_parent_can_enroll', '0' );
            update_user_meta( $user_id, self::CHILD_FREE_ENROLL_META, '0' );
            delete_user_meta( $user_id, '_tls_parent_booking_consent_at' );
            delete_user_meta( $user_id, '_tls_guardian_enroll_consent_at' );
            delete_user_meta( $user_id, '_tls_guardian_enroll_consent_by' );
            update_user_meta( $user_id, self::AGE_BAND_META, 'unknown' );
            return false;
        }
        // Any change between known age bands revokes delegated access. This also
        // handles a policy-threshold change that moves an account adult -> transition.
        $crossed_age_boundary = in_array( $old_band, array( 'child', 'transition', 'adult' ), true ) && $old_band !== $new_band;
        $crossed_legacy_child_boundary = '' === $old_band && '1' === (string) get_user_meta( $user_id, '_tls_minor_account', true ) && in_array( $new_band, array( 'transition', 'adult' ), true );
        $crossed_age_boundary = $crossed_age_boundary || $crossed_legacy_child_boundary;
        if ( $crossed_age_boundary ) {
            update_user_meta( $user_id, '_tls_parent_can_view_learning', '0' );
            update_user_meta( $user_id, '_tls_parent_can_book', '0' );
            update_user_meta( $user_id, '_tls_parent_can_enroll', '0' );
            update_user_meta( $user_id, self::CHILD_FREE_ENROLL_META, '0' );
            delete_user_meta( $user_id, '_tls_parent_booking_consent_at' );
            delete_user_meta( $user_id, '_tls_guardian_enroll_consent_at' );
            delete_user_meta( $user_id, '_tls_guardian_enroll_consent_by' );
            update_user_meta( $user_id, '_tls_adult_parent_consent_required', '1' );
            self::audit( 'age_transition_permissions_revoked', $user_id, array( 'from' => $old_band ? $old_band : 'legacy_child', 'to' => $new_band ) );
        }
        $guardian_id = absint( get_user_meta( $user_id, '_tls_guardian_user_id', true ) );
        $guardian_status = (string) get_user_meta( $user_id, self::GUARDIAN_STATUS_META, true );
        if ( $guardian_id ) {
            $parent_verified = '1' === (string) get_user_meta( $guardian_id, '_tls_guardian_adult_verified', true );
            $site_verified = $parent_verified && 'approved' === $guardian_status && '1' === (string) get_user_meta( $user_id, '_tls_guardian_verification_completed', true );
            if ( ! $site_verified && 'pending_review' !== $guardian_status ) {
                // Every family relationship requires a documented admin review.
                // Adult age is not inferred solely from a self-entered DOB.
                update_user_meta( $user_id, self::GUARDIAN_STATUS_META, 'pending_review' );
                $guardian_status = 'pending_review';
            }
            if ( ! $parent_verified ) {
                update_user_meta( $user_id, '_tls_parent_can_view_learning', '0' );
                update_user_meta( $user_id, '_tls_parent_can_book', '0' );
                update_user_meta( $user_id, '_tls_parent_can_enroll', '0' );
                update_user_meta( $user_id, self::CHILD_FREE_ENROLL_META, '0' );
                delete_user_meta( $user_id, '_tls_parent_booking_consent_at' );
                delete_user_meta( $user_id, '_tls_guardian_enroll_consent_at' );
                delete_user_meta( $user_id, '_tls_guardian_enroll_consent_by' );
            }
        }
        if ( in_array( $new_band, array( 'child', 'transition' ), true ) ) {
            update_user_meta( $user_id, '_tls_private_student_profile', '1' );
            update_user_meta( $user_id, '_tls_hide_public_contact', '1' );
        }
        if ( 'child' === $new_band ) {
            $verified = 'approved' === $guardian_status && '1' === (string) get_user_meta( $user_id, '_tls_guardian_verification_completed', true );
            if ( ! $guardian_id || ! $verified ) {
                update_user_meta( $user_id, '_tls_parent_can_view_learning', '0' );
                update_user_meta( $user_id, '_tls_parent_can_book', '0' );
                update_user_meta( $user_id, '_tls_parent_can_enroll', '0' );
                update_user_meta( $user_id, self::CHILD_FREE_ENROLL_META, '0' );
                delete_user_meta( $user_id, '_tls_guardian_enroll_consent_at' );
                delete_user_meta( $user_id, '_tls_guardian_enroll_consent_by' );
            }
            update_user_meta( $user_id, '_tls_minor_account', '1' );
        } else {
            update_user_meta( $user_id, '_tls_minor_account', '0' );
        }
        if ( $old_band && $old_band !== $new_band ) {
            self::audit( 'age_band_changed', $user_id, array( 'from' => $old_band, 'to' => $new_band ) );
        }
        update_user_meta( $user_id, self::AGE_BAND_META, $new_band );
        return $new_band !== $old_band;
    }

    public static function sync_on_login( $login, $user ) {
        if ( $user instanceof WP_User ) {
            self::migrate_user_birth_date( $user->ID );
            self::sync_user( $user->ID );
        }
    }

    private static function migrate_legacy_family_students() {
        if ( get_option( 'tls_age_safety_legacy_family_migrated_v2' ) ) {
            return;
        }
        global $wpdb;
        $cursor = absint( get_option( 'tls_age_safety_legacy_family_cursor_v2', 0 ) );
        // Keyset pagination is stable when users are deleted or updated between
        // requests; a fixed offset can silently skip records in that situation.
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT u.ID FROM {$wpdb->users} u
             INNER JOIN {$wpdb->usermeta} minor ON minor.user_id=u.ID AND minor.meta_key=%s AND minor.meta_value='1'
             INNER JOIN {$wpdb->usermeta} guardian ON guardian.user_id=u.ID AND guardian.meta_key=%s
             WHERE u.ID>%d ORDER BY u.ID ASC LIMIT 200",
            '_tls_minor_account', '_tls_guardian_user_id', $cursor
        ) );
        if ( empty( $ids ) ) {
            update_option( 'tls_age_safety_legacy_family_migrated_v2', 1, false );
            delete_option( 'tls_age_safety_legacy_family_cursor_v2' );
            return;
        }
        foreach ( $ids as $student_id ) {
            $student_id = absint( $student_id );
            if ( ! self::is_tutor_student( $student_id ) ) {
                update_user_meta( $student_id, '_is_tutor_student', 'yes' );
            }
            update_user_meta( $student_id, '_tls_private_student_profile', '1' );
            update_user_meta( $student_id, '_tls_hide_public_contact', '1' );
            update_user_meta( $student_id, '_tls_parent_can_view_learning', '0' );
            update_user_meta( $student_id, '_tls_parent_can_book', '0' );
            update_user_meta( $student_id, '_tls_parent_can_enroll', '0' );
            update_user_meta( $student_id, self::CHILD_FREE_ENROLL_META, '0' );
            delete_user_meta( $student_id, '_tls_guardian_enroll_consent_at' );
            delete_user_meta( $student_id, '_tls_guardian_enroll_consent_by' );
            if ( ! get_user_meta( $student_id, self::GUARDIAN_STATUS_META, true ) ) {
                update_user_meta( $student_id, self::GUARDIAN_STATUS_META, 'pending_review' );
            }
            self::audit( 'legacy_family_student_migrated', $student_id, array( 'age_band' => 'unknown' ) );
        }
        update_option( 'tls_age_safety_legacy_family_cursor_v2', max( array_map( 'absint', $ids ) ), false );
    }

    public static function schedule_daily_sync() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
        }
    }

    public static function daily_sync() {
        $offset = 0;
        do {
            $users = get_users( array(
                'meta_query' => array( 'relation' => 'OR', array( 'key' => '_is_tutor_student', 'value' => 'yes' ), array( 'key' => '_is_tutor_student', 'value' => '1' ) ),
                'fields' => 'ID',
                'number' => 200,
                'offset' => $offset,
                'orderby' => 'ID',
                'order' => 'ASC',
            ) );
            foreach ( $users as $user_id ) {
                self::migrate_user_birth_date( $user_id );
                self::sync_user( $user_id );
            }
            $count = count( $users );
            $offset += $count;
        } while ( 200 === $count );
    }

    public static function audit( $event, $subject_user_id, $details = array() ) {
        global $wpdb;
        $table = $wpdb->prefix . 'tls_age_safety_audit';
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists !== $table ) {
            return false;
        }
        $safe_details = array();
        // Event payloads are allowlisted; new callers cannot accidentally place
        // arbitrary metadata or free-text personal data into the audit table.
        $allowed_detail_keys = array( 'age_band', 'from', 'to', 'guardian_id', 'report_status', 'migrated_count', 'processed_count', 'has_more', 'action', 'post_id', 'route', 'method', 'recipient_count', 'component', 'result', 'reason', 'concern_preserved', 'progress', 'booking', 'session_id', 'tutor_id', 'learner_id', 'course_id', 'enrollment_id', 'enroll' );
        foreach ( (array) $details as $key => $value ) {
            $key = sanitize_key( $key );
            if ( ! in_array( $key, $allowed_detail_keys, true ) || ( ! is_scalar( $value ) && null !== $value ) ) {
                continue;
            }
            if ( is_string( $value ) ) {
                $value = substr( sanitize_text_field( $value ), 0, 120 );
            }
            $safe_details[ $key ] = $value;
        }
        return false !== $wpdb->insert(
            $table,
            array(
                'actor_id' => get_current_user_id(),
                'subject_user_id' => absint( $subject_user_id ),
                'event_key' => sanitize_key( $event ),
                'details_json' => wp_json_encode( $safe_details ),
                'created_at' => current_time( 'mysql', true ),
            ),
            array( '%d', '%d', '%s', '%s', '%s' )
        );
    }

    private static function create_audit_table() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $wpdb->prefix . 'tls_age_safety_audit';
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
            subject_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            event_key varchar(64) NOT NULL,
            details_json longtext NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY subject_user_id (subject_user_id),
            KEY event_key (event_key),
            KEY created_at (created_at)
        ) {$charset};";
        dbDelta( $sql );
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        $columns = $exists === $table ? (array) $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 ) : array();
        $required = array( 'id', 'actor_id', 'subject_user_id', 'event_key', 'details_json', 'created_at' );
        if ( $exists === $table && ! array_diff( $required, $columns ) ) {
            update_option( 'tls_age_safety_db_version', self::AUDIT_VERSION, false );
        } else {
            // Leave the schema version stale so the next request retries dbDelta().
            delete_option( 'tls_age_safety_db_version' );
        }
    }

    public static function admin_menu() {
        add_management_page( __( 'TLS Child Safety', 'tutor-learning-suite' ), __( 'TLS Child Safety', 'tutor-learning-suite' ), 'manage_options', 'tls-child-safety', array( __CLASS__, 'render_admin_page' ) );
    }

    public static function admin_key_notice() {
        if ( ! current_user_can( 'manage_options' ) || self::is_key_configured() ) {
            return;
        }
        echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Tutor Learning Suite child-safety encryption is not configured.', 'tutor-learning-suite' ) . '</strong> ' . esc_html__( 'Add TLS_AGE_ENCRYPTION_KEY as a 64-character hexadecimal secret in wp-config.php before collecting or migrating dates of birth. Age-sensitive actions fail closed until configured.', 'tutor-learning-suite' ) . ' <a href="' . esc_url( admin_url( 'tools.php?page=tls-child-safety' ) ) . '">' . esc_html__( 'Open Child Safety', 'tutor-learning-suite' ) . '</a></p></div>';
    }

    public static function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to manage child-safety settings.', 'tutor-learning-suite' ) );
        }
        $pending = get_users( array(
            'meta_key' => self::GUARDIAN_STATUS_META,
            'meta_value' => 'pending_review',
            'fields' => array( 'ID', 'display_name', 'user_email' ),
            'number' => 100,
        ) );
        echo '<div class="wrap"><h1>' . esc_html__( 'Tutor Learning Suite — Child Safety', 'tutor-learning-suite' ) . '</h1>';
        if ( isset( $_GET['tls_safety_notice'] ) ) {
            $notice = sanitize_key( wp_unslash( $_GET['tls_safety_notice'] ) );
            $message = 'migrated' === $notice ? sprintf( __( 'Migration batch processed %1$d account(s); %2$d date(s) were re-encrypted. %3$s', 'tutor-learning-suite' ), absint( $_GET['processed'] ?? 0 ), absint( $_GET['migrated'] ?? 0 ), ! empty( $_GET['has_more'] ) ? __( 'More accounts remain. Run the batch again.', 'tutor-learning-suite' ) : __( 'No unprocessed date records remain.', 'tutor-learning-suite' ) ) : ( 'approve' === $notice ? __( 'Guardian relationship approved. Permissions were applied according to the age category.', 'tutor-learning-suite' ) : ( 'reject' === $notice ? __( 'Guardian relationship rejected and permissions removed.', 'tutor-learning-suite' ) : ( 'revoked' === $notice ? __( 'The reported family link was ended. Educational records were preserved.', 'tutor-learning-suite' ) : ( 'concern_reviewed' === $notice ? __( 'The concern was marked reviewed and the family link remains active.', 'tutor-learning-suite' ) : __( 'The requested operation could not be completed.', 'tutor-learning-suite' ) ) ) ) );
            echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
        }
        if ( ! self::is_key_configured() ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Set TLS_AGE_ENCRYPTION_KEY in wp-config.php. Generate it with: openssl rand -hex 32', 'tutor-learning-suite' ) . '</p><p><code>define( \'TLS_AGE_ENCRYPTION_KEY\', \'PASTE_64_HEX_CHARACTERS_HERE\' );</code></p></div>';
        } else {
            echo '<div class="notice notice-success"><p>' . esc_html__( 'The separate age encryption key is configured.', 'tutor-learning-suite' ) . '</p></div>';
            echo '<p>' . esc_html__( 'Migration checks up to 5,000 account records per run. Repeat the batch until the notice confirms that no unprocessed date records remain. Accounts that cannot be decrypted stay age-unknown and restricted.', 'tutor-learning-suite' ) . '</p>' . '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'tls_age_safety_migrate', '_tls_nonce', true, false ) . '<input type="hidden" name="action" value="tls_age_safety_migrate"><p><button class="button button-secondary">' . esc_html__( 'Migrate legacy encrypted dates of birth', 'tutor-learning-suite' ) . '</button></p></form>';
        }
        echo '<h2>' . esc_html__( 'Guardian relationships awaiting review', 'tutor-learning-suite' ) . '</h2>';
        echo '<p>' . esc_html__( 'Review the relationship using your documented site procedure before approving it. This screen records an administrative review; it does not itself verify identity documents or replace legal advice.', 'tutor-learning-suite' ) . '</p>';
        if ( $pending ) {
            echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Student', 'tutor-learning-suite' ) . '</th><th>' . esc_html__( 'Guardian', 'tutor-learning-suite' ) . '</th><th>' . esc_html__( 'Age band', 'tutor-learning-suite' ) . '</th><th>' . esc_html__( 'Requested', 'tutor-learning-suite' ) . '</th><th>' . esc_html__( 'Actions', 'tutor-learning-suite' ) . '</th></tr></thead><tbody>';
            foreach ( $pending as $student ) {
                $parent_id = absint( get_user_meta( $student->ID, '_tls_guardian_user_id', true ) );
                $parent = $parent_id ? get_userdata( $parent_id ) : false;
                $band = self::age_band( $student->ID );
                $created = get_user_meta( $student->ID, '_tls_guardian_consent_at', true );
                echo '<tr><td>' . esc_html( $student->display_name . ' (#' . $student->ID . ')' ) . '</td><td>' . esc_html( $parent ? $parent->display_name . ' (#' . $parent_id . ')' : __( 'Missing guardian', 'tutor-learning-suite' ) ) . '</td><td>' . esc_html( ucfirst( $band ) ) . '</td><td>' . esc_html( $created ? $created : '—' ) . '</td><td>';
                self::admin_review_form( $student->ID, 'approve' );
                self::admin_review_form( $student->ID, 'reject' );
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>' . esc_html__( 'No guardian relationships are waiting for review.', 'tutor-learning-suite' ) . '</p>';
        }
        // Reports are kept separate from ordinary consent review. Only administrators
        // can see the report reason and end an active family relationship here.
        $reported_students = get_users( array(
            'meta_query' => array( array( 'key' => '_tls_guardian_link_concern', 'compare' => 'EXISTS' ) ),
            'fields' => array( 'ID', 'display_name', 'user_email' ),
            'number' => 200,
        ) );
        $open_reports = array();
        $closed_reports = array();
        foreach ( $reported_students as $reported_student ) {
            $report = get_user_meta( $reported_student->ID, '_tls_guardian_link_concern', true );
            $recorded_parent_id = is_array( $report ) ? absint( $report['parent_id'] ?? 0 ) : 0;
            $linked_parent_id = absint( get_user_meta( $reported_student->ID, '_tls_guardian_user_id', true ) );
            if ( is_array( $report ) && 'open' === ( $report['status'] ?? '' ) ) {
                $open_reports[] = array( 'student' => $reported_student, 'report' => $report, 'parent_id' => $linked_parent_id ?: $recorded_parent_id );
            } elseif ( is_array( $report ) && 'closed' === ( $report['status'] ?? '' ) ) {
                $closed_reports[] = array( 'student' => $reported_student, 'report' => $report, 'parent_id' => $linked_parent_id ?: $recorded_parent_id );
            }
        }
        echo '<h2>' . esc_html__( 'Family-link concerns reported by students', 'tutor-learning-suite' ) . '</h2>';
        echo '<p>' . esc_html__( 'Review each report privately. Contact the relevant accounts using your safeguarding procedure. Ending a link removes delegated access but does not delete learning or booking history.', 'tutor-learning-suite' ) . '</p>';
        if ( $open_reports ) {
            echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Student', 'tutor-learning-suite' ) . '</th><th>' . esc_html__( 'Parent/guardian', 'tutor-learning-suite' ) . '</th><th>' . esc_html__( 'Concern', 'tutor-learning-suite' ) . '</th><th>' . esc_html__( 'Reported', 'tutor-learning-suite' ) . '</th><th>' . esc_html__( 'Action', 'tutor-learning-suite' ) . '</th></tr></thead><tbody>';
            foreach ( $open_reports as $item ) {
                $student = $item['student']; $report = $item['report']; $parent = $item['parent_id'] ? get_userdata( $item['parent_id'] ) : false;
                echo '<tr><td>' . esc_html( $student->display_name . ' (#' . $student->ID . ')' ) . '</td><td>' . esc_html( $parent ? $parent->display_name . ' (#' . $item['parent_id'] . ')' : __( 'Missing account', 'tutor-learning-suite' ) ) . '</td><td style="max-width:420px;white-space:pre-wrap">' . esc_html( (string) ( $report['reason'] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $report['requested_at'] ?? '—' ) ) . '</td><td><form style="display:inline-block;margin:2px" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'tls_age_safety_resolve_' . (int) $student->ID, '_tls_nonce', true, false ) . '<input type="hidden" name="action" value="tls_age_safety_resolve_concern"><input type="hidden" name="student_id" value="' . (int) $student->ID . '"><button class="button">' . esc_html__( 'Review — keep link', 'tutor-learning-suite' ) . '</button></form><form style="display:inline-block;margin:2px" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'tls_age_safety_revoke_' . (int) $student->ID, '_tls_nonce', true, false ) . '<input type="hidden" name="action" value="tls_age_safety_revoke"><input type="hidden" name="student_id" value="' . (int) $student->ID . '"><button class="button button-secondary" onclick="return confirm(\'' . esc_js( __( 'End this parent/guardian link and remove delegated access?', 'tutor-learning-suite' ) ) . '\');">' . esc_html__( 'End family link', 'tutor-learning-suite' ) . '</button></form></td></tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>' . esc_html__( 'There are no open student family-link concerns.', 'tutor-learning-suite' ) . '</p>';
        }
        if ( $closed_reports ) {
            echo '<h3>' . esc_html__( 'Recent family-link concern history', 'tutor-learning-suite' ) . '</h3><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Student', 'tutor-learning-suite' ) . '</th><th>' . esc_html__( 'Parent/guardian', 'tutor-learning-suite' ) . '</th><th>' . esc_html__( 'Concern', 'tutor-learning-suite' ) . '</th><th>' . esc_html__( 'Outcome', 'tutor-learning-suite' ) . '</th><th>' . esc_html__( 'Reported', 'tutor-learning-suite' ) . '</th></tr></thead><tbody>';
            $outcomes = array(
                'reviewed_keep_link' => __( 'Administrator reviewed — link kept', 'tutor-learning-suite' ),
                'admin_revoked' => __( 'Administrator ended the link', 'tutor-learning-suite' ),
                'parent_unlinked' => __( 'Parent/guardian ended the link', 'tutor-learning-suite' ),
                'adult_student_unlinked' => __( 'Adult student ended the link', 'tutor-learning-suite' ),
            );
            foreach ( array_slice( $closed_reports, 0, 50 ) as $item ) {
                $student = $item['student']; $report = $item['report']; $parent = $item['parent_id'] ? get_userdata( $item['parent_id'] ) : false;
                $resolution = (string) ( $report['resolution'] ?? '' );
                $parent_label = $parent ? $parent->display_name . ' (#' . $item['parent_id'] . ')' : ( $item['parent_id'] ? sprintf( __( 'Account #%d', 'tutor-learning-suite' ), $item['parent_id'] ) : __( 'Not recorded', 'tutor-learning-suite' ) );
                $outcome_label = $outcomes[ $resolution ] ?? ( $resolution ? ucwords( str_replace( '_', ' ', $resolution ) ) : '—' );
                echo '<tr><td>' . esc_html( $student->display_name . ' (#' . $student->ID . ')' ) . '</td><td>' . esc_html( $parent_label ) . '</td><td style="max-width:420px;white-space:pre-wrap">' . esc_html( (string) ( $report['reason'] ?? '' ) ) . '</td><td>' . esc_html( $outcome_label ) . '</td><td>' . esc_html( (string) ( $report['requested_at'] ?? '—' ) ) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '<p><strong>' . esc_html__( 'Age groups:', 'tutor-learning-suite' ) . '</strong> ' . esc_html__( 'Child: under 18. Transition: 18 to the configured age-of-majority threshold (default 19). Adult: at or above that threshold. Unknown age is restricted by default.', 'tutor-learning-suite' ) . '</p>';
        global $wpdb;
        $audit_table = $wpdb->prefix . 'tls_age_safety_audit';
        $audit_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $audit_table ) );
        if ( $audit_exists === $audit_table ) {
            $events = $wpdb->get_results( "SELECT actor_id, subject_user_id, event_key, details_json, created_at FROM {$audit_table} ORDER BY id DESC LIMIT 30" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            echo '<h2>' . esc_html__( 'Recent safety audit events', 'tutor-learning-suite' ) . '</h2>';
            if ( $events ) {
                echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Time (UTC)', 'tutor-learning-suite' ) . '</th><th>' . esc_html__( 'Event', 'tutor-learning-suite' ) . '</th><th>' . esc_html__( 'Actor ID', 'tutor-learning-suite' ) . '</th><th>' . esc_html__( 'Account ID', 'tutor-learning-suite' ) . '</th><th>' . esc_html__( 'Details', 'tutor-learning-suite' ) . '</th></tr></thead><tbody>';
                foreach ( $events as $event ) {
                    echo '<tr><td>' . esc_html( $event->created_at ) . '</td><td>' . esc_html( $event->event_key ) . '</td><td>' . (int) $event->actor_id . '</td><td>' . (int) $event->subject_user_id . '</td><td><code>' . esc_html( (string) $event->details_json ) . '</code></td></tr>';
                }
                echo '</tbody></table>';
            } else {
                echo '<p>' . esc_html__( 'No safety audit events recorded yet.', 'tutor-learning-suite' ) . '</p>';
            }
        }
        echo '</div>';
    }

    private static function admin_review_form( $student_id, $decision ) {
        $label = 'approve' === $decision ? __( 'Approve', 'tutor-learning-suite' ) : __( 'Reject', 'tutor-learning-suite' );
        echo '<form style="display:inline-block;margin-right:6px" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'tls_age_safety_review_' . $student_id, '_tls_nonce', true, false ) . '<input type="hidden" name="action" value="tls_age_safety_review"><input type="hidden" name="student_id" value="' . (int) $student_id . '"><input type="hidden" name="decision" value="' . esc_attr( $decision ) . '">' . ( 'approve' === $decision ? '<label style="display:block;margin:4px 0;font-size:11px"><input type="checkbox" name="verification_completed" value="1" required> ' . esc_html__( 'I verified the adult guardian’s identity and authority to manage this student’s account using the site’s approved procedure.', 'tutor-learning-suite' ) . '</label>' : '' ) . '<button class="button ' . ( 'approve' === $decision ? 'button-primary' : '' ) . '">' . esc_html( $label ) . '</button></form>';
    }

    public static function handle_admin_review() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Not allowed.', 'tutor-learning-suite' ) );
        }
        $student_id = absint( $_POST['student_id'] ?? 0 );
        $decision = sanitize_key( wp_unslash( $_POST['decision'] ?? '' ) );
        check_admin_referer( 'tls_age_safety_review_' . $student_id, '_tls_nonce' );
        $parent_id = absint( get_user_meta( $student_id, '_tls_guardian_user_id', true ) );
        $band = self::age_band( $student_id );
        $consent_by = absint( get_user_meta( $student_id, '_tls_guardian_consent_by', true ) );
        $consent_at = (string) get_user_meta( $student_id, '_tls_guardian_consent_at', true );
        $base_valid = $student_id && self::is_tutor_student( $student_id ) && 'pending_review' === (string) get_user_meta( $student_id, self::GUARDIAN_STATUS_META, true ) && in_array( $decision, array( 'approve', 'reject' ), true );
        // A malformed/orphaned request may still be rejected by an administrator.
        // Approval requires an adult parent, matching student consent, and an
        // explicit administrator attestation that identity and authority were checked.
        $approval_valid = 'approve' !== $decision || ( $parent_id && 'adult' === self::age_band( $parent_id ) && $consent_by === $parent_id && '' !== $consent_at && 'unknown' !== $band && ! empty( $_POST['verification_completed'] ) );
        if ( ! $base_valid || ! $approval_valid ) {
            wp_safe_redirect( add_query_arg( 'tls_safety_notice', 'invalid', admin_url( 'tools.php?page=tls-child-safety' ) ) );
            exit;
        }
        if ( 'approve' === $decision ) {
            // This flag represents an administrator's documented check; it is
            // not a claim that software independently verified the documents.
            update_user_meta( $parent_id, '_tls_guardian_adult_verified', '1' );
            update_user_meta( $parent_id, '_tls_guardian_adult_verified_at', current_time( 'mysql', true ) );
            update_user_meta( $parent_id, '_tls_guardian_adult_verified_by', get_current_user_id() );
            update_user_meta( $student_id, self::GUARDIAN_STATUS_META, 'approved' );
            update_user_meta( $student_id, '_tls_guardian_reviewed_at', current_time( 'mysql', true ) );
            update_user_meta( $student_id, '_tls_guardian_reviewed_by', get_current_user_id() );
            update_user_meta( $student_id, '_tls_guardian_verification_completed', '1' );
            if ( 'child' === $band ) {
                update_user_meta( $student_id, '_tls_parent_can_view_learning', '1' );
                update_user_meta( $student_id, '_tls_parent_can_book', '1' );
            } else {
                // Adults and transition-age students must explicitly opt in themselves.
                update_user_meta( $student_id, '_tls_parent_can_view_learning', '0' );
                update_user_meta( $student_id, '_tls_parent_can_book', '0' );
            }
            self::audit( 'guardian_relationship_approved', $student_id, array( 'guardian_id' => $parent_id, 'age_band' => $band ) );
        } else {
            delete_user_meta( $student_id, '_tls_guardian_user_id' );
            delete_user_meta( $student_id, '_tls_guardian_consent_at' );
            delete_user_meta( $student_id, '_tls_guardian_consent_by' );
            delete_user_meta( $student_id, '_tls_guardian_verification_completed' );
            update_user_meta( $student_id, self::GUARDIAN_STATUS_META, 'rejected' );
            update_user_meta( $student_id, '_tls_parent_can_view_learning', '0' );
            update_user_meta( $student_id, '_tls_parent_can_book', '0' );
            update_user_meta( $student_id, self::CHILD_FREE_ENROLL_META, '0' );
            delete_user_meta( $student_id, '_tls_parent_booking_consent_at' );
            if ( $parent_id ) {
                $remaining = get_users( array( 'meta_key' => '_tls_guardian_user_id', 'meta_value' => $parent_id, 'fields' => 'ID', 'number' => 1 ) );
                if ( empty( $remaining ) ) {
                    update_user_meta( $parent_id, '_tls_is_guardian', '0' );
                }
            }
            self::audit( 'guardian_relationship_rejected', $student_id, array( 'guardian_id' => $parent_id ) );
        }
        wp_safe_redirect( add_query_arg( 'tls_safety_notice', $decision, admin_url( 'tools.php?page=tls-child-safety' ) ) );
        exit;
    }

    public static function handle_admin_resolve_concern() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Not allowed.', 'tutor-learning-suite' ) );
        }
        $student_id = absint( $_POST['student_id'] ?? 0 );
        check_admin_referer( 'tls_age_safety_resolve_' . $student_id, '_tls_nonce' );
        $parent_id = absint( get_user_meta( $student_id, '_tls_guardian_user_id', true ) );
        $report = get_user_meta( $student_id, '_tls_guardian_link_concern', true );
        if ( ! $student_id || ! $parent_id || ! is_array( $report ) || 'open' !== ( $report['status'] ?? '' ) ) {
            wp_safe_redirect( add_query_arg( 'tls_safety_notice', 'invalid', admin_url( 'tools.php?page=tls-child-safety' ) ) );
            exit;
        }
        $report['status'] = 'closed';
        $report['resolution'] = 'reviewed_keep_link';
        $report['resolved_at'] = current_time( 'mysql', true );
        $report['resolved_by'] = get_current_user_id();
        update_user_meta( $student_id, '_tls_guardian_link_concern', $report );
        self::audit( 'admin_reviewed_guardian_concern_link_retained', $student_id, array( 'guardian_id' => $parent_id ) );
        $student = get_userdata( $student_id );
        if ( $student && is_email( $student->user_email ) ) {
            wp_mail( $student->user_email, __( 'Family-link review update', 'tutor-learning-suite' ), __( 'The site administrator has reviewed your family-link concern. The family link remains active. You can contact site support privately if you need to discuss the decision or report a new concern.', 'tutor-learning-suite' ) );
        }
        wp_safe_redirect( add_query_arg( 'tls_safety_notice', 'concern_reviewed', admin_url( 'tools.php?page=tls-child-safety' ) ) );
        exit;
    }

    public static function handle_admin_revoke() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Not allowed.', 'tutor-learning-suite' ) );
        }
        $student_id = absint( $_POST['student_id'] ?? 0 );
        check_admin_referer( 'tls_age_safety_revoke_' . $student_id, '_tls_nonce' );
        $parent_id = absint( get_user_meta( $student_id, '_tls_guardian_user_id', true ) );
        $report = get_user_meta( $student_id, '_tls_guardian_link_concern', true );
        if ( ! $student_id || ! $parent_id || ! self::is_tutor_student( $student_id ) || ! is_array( $report ) || 'open' !== ( $report['status'] ?? '' ) ) {
            wp_safe_redirect( add_query_arg( 'tls_safety_notice', 'invalid', admin_url( 'tools.php?page=tls-child-safety' ) ) );
            exit;
        }
        foreach ( array( '_tls_guardian_user_id', '_tls_guardian_consent_at', '_tls_guardian_consent_by', '_tls_guardian_verification_completed', '_tls_adult_parent_consent_required', '_tls_parent_booking_consent_at', '_tls_parent_can_view_learning', '_tls_parent_can_book', '_tls_parent_can_enroll', '_tls_guardian_enroll_consent_at', '_tls_guardian_enroll_consent_by', self::CHILD_FREE_ENROLL_META ) as $key ) {
            delete_user_meta( $student_id, $key );
        }
        delete_user_meta( $student_id, self::GUARDIAN_STATUS_META );
        $report['status'] = 'closed';
        $report['resolution'] = 'admin_revoked';
        $report['resolved_at'] = current_time( 'mysql', true );
        $report['resolved_by'] = get_current_user_id();
        update_user_meta( $student_id, '_tls_guardian_link_concern', $report );
        $remaining = get_users( array( 'meta_key' => '_tls_guardian_user_id', 'meta_value' => $parent_id, 'fields' => 'ID', 'number' => 1 ) );
        if ( empty( $remaining ) ) {
            update_user_meta( $parent_id, '_tls_is_guardian', '0' );
        }
        self::audit( 'admin_revoked_guardian_link', $student_id, array( 'guardian_id' => $parent_id, 'report_status' => 'closed' ) );
        $student = get_userdata( $student_id );
        $parent = get_userdata( $parent_id );
        $message = __( 'A Ustudy family relationship was ended after an administrator review. Your accounts and educational records remain available. Contact the site administrator if you need more information.', 'tutor-learning-suite' );
        if ( $student && is_email( $student->user_email ) ) { wp_mail( $student->user_email, __( 'Family link update', 'tutor-learning-suite' ), $message ); }
        // Do not automatically notify the parent that a student submitted a safety
        // concern. The administrator decides whether/how to communicate separately.
        wp_safe_redirect( add_query_arg( 'tls_safety_notice', 'revoked', admin_url( 'tools.php?page=tls-child-safety' ) ) );
        exit;
    }

    public static function handle_admin_migration() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Not allowed.', 'tutor-learning-suite' ) );
        }
        check_admin_referer( 'tls_age_safety_migrate', '_tls_nonce' );
        if ( ! self::is_key_configured() ) {
            wp_safe_redirect( add_query_arg( 'tls_safety_notice', 'key_missing', admin_url( 'tools.php?page=tls-child-safety' ) ) );
            exit;
        }
        $unprocessed_query = array(
            'relation' => 'OR',
            array( 'relation' => 'AND', array( 'key' => self::LEGACY_DOB_META, 'compare' => 'EXISTS' ), array( 'key' => self::DOB_MIGRATION_CHECKED_META, 'compare' => 'NOT EXISTS' ) ),
            array( 'relation' => 'AND', array( 'key' => self::DOB_META, 'compare' => 'EXISTS' ), array( 'key' => self::DOB_MIGRATION_CHECKED_META, 'compare' => 'NOT EXISTS' ) ),
        );
        $users = get_users( array( 'meta_query' => $unprocessed_query, 'fields' => 'ID', 'number' => 5000, 'orderby' => 'ID', 'order' => 'ASC' ) );
        $migrated = 0;
        $processed = 0;
        foreach ( $users as $user_id ) {
            if ( self::migrate_user_birth_date( $user_id ) ) {
                $migrated++;
            }
            update_user_meta( $user_id, self::DOB_MIGRATION_CHECKED_META, '1' );
            self::sync_user( $user_id );
            $processed++;
        }
        $remaining = get_users( array( 'meta_query' => $unprocessed_query, 'fields' => 'ID', 'number' => 1 ) );
        self::audit( 'legacy_birth_date_migration_run', 0, array( 'migrated_count' => $migrated, 'processed_count' => $processed, 'has_more' => ! empty( $remaining ) ) );
        wp_safe_redirect( add_query_arg( array( 'tls_safety_notice' => 'migrated', 'migrated' => $migrated, 'processed' => $processed, 'has_more' => ! empty( $remaining ) ? 1 : 0 ), admin_url( 'tools.php?page=tls-child-safety' ) ) );
        exit;
    }

    /**
     * Present a friendly front-end notice for blocked actions while retaining
     * every server-side permission check. AJAX requests receive a JSON error;
     * normal form posts return to the referring page with a one-use notice.
     */
    private static function deny_with_frontend_notice( $message, $title = '', $fallback_url = '' ) {
        $payload = array(
            'message' => (string) $message,
            'title'   => $title ? (string) $title : __( 'This action needs a parent or guardian', 'tutor-learning-suite' ),
        );

        if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
            wp_send_json_error( $payload, 403 );
        }

        $user_id = get_current_user_id();
        if ( $user_id ) {
            set_transient( self::FRONTEND_NOTICE_TRANSIENT . absint( $user_id ), $payload, 90 );
        }

        $referer  = wp_get_referer();
        $fallback = $fallback_url ? wp_validate_redirect( $fallback_url, home_url( '/' ) ) : home_url( '/' );
        // When an enrolment/course context is known, always return to that exact
        // course instead of trusting a missing or theme-rewritten HTTP referer.
        $redirect = $fallback_url ? $fallback : ( $referer ? wp_validate_redirect( $referer, $fallback ) : $fallback );
        wp_safe_redirect( $redirect );
        exit;
    }

    public static function deny_public_interaction_ajax() {
        if ( self::is_restricted_student() ) {
            $user_id = get_current_user_id();
            $action  = isset( $_REQUEST['action'] ) && is_scalar( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['action'] ) ) : 'unknown';
            self::audit( 'restricted_public_interaction_blocked', $user_id, array( 'action' => $action ) );
            wp_send_json_error( array(
                'title'   => __( 'This action is not available for this account', 'tutor-learning-suite' ),
                'message' => __( 'Public reviews, comments and course discussions are disabled for this account. For concerns, contact site support using the contact method published on this website.', 'tutor-learning-suite' ),
            ), 403 );
        }
    }

    public static function deny_wordpress_comment( $commentdata ) {
        if ( self::is_restricted_student() ) {
            $user_id = get_current_user_id();
            $post_id = isset( $commentdata['comment_post_ID'] ) ? absint( $commentdata['comment_post_ID'] ) : 0;
            if ( class_exists( 'TLS_Feedback_Moderation' ) && TLS_Feedback_Moderation::can_child_submit_feedback( $user_id ) && TLS_Feedback_Moderation::is_public_comment_target( $post_id ) ) {
                return $commentdata;
            }
            self::audit( 'restricted_wordpress_comment_blocked', $user_id, array( 'post_id' => $post_id ) );
            self::deny_with_frontend_notice(
                __( 'This comment needs an approved parent or guardian relationship. Please ask your parent or guardian for help.', 'tutor-learning-suite' ),
                __( 'Commenting needs guardian approval' , 'tutor-learning-suite' )
            );
        }
        return $commentdata;
    }

    public static function filter_comments_open( $open, $post_id ) {
        if ( ! self::is_restricted_student() ) {
            return $open;
        }
        if ( class_exists( 'TLS_Feedback_Moderation' ) && TLS_Feedback_Moderation::can_child_submit_feedback( get_current_user_id() ) && TLS_Feedback_Moderation::is_public_comment_target( $post_id ) ) {
            return $open;
        }
        return false;
    }

    public static function filter_rest_writes( $result, $server, $request ) {
        if ( ! self::is_restricted_student() || ! is_object( $request ) || ! method_exists( $request, 'get_method' ) ) {
            return $result;
        }
        $route = method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';
        if ( preg_match( '#/(?:buddypress/v1|bp/v1)/(?:messages|activity)(?:/|$)#i', $route ) ) {
            self::audit( 'restricted_rest_community_blocked', get_current_user_id(), array( 'route' => $route, 'method' => strtoupper( $request->get_method() ) ) );
            return new WP_Error( 'tls_child_community_restricted', __( 'Community messaging and public activity are disabled for this account.', 'tutor-learning-suite' ), array( 'status' => 403 ) );
        }
        if ( 'GET' === strtoupper( $request->get_method() ) ) {
            return $result;
        }
        if ( preg_match( '#/wp/v2/comments(?:/|$)#i', $route ) && class_exists( 'TLS_Feedback_Moderation' ) ) {
            $post_id = method_exists( $request, 'get_param' ) ? absint( $request->get_param( 'post' ) ) : 0;
            if ( TLS_Feedback_Moderation::can_child_submit_feedback( get_current_user_id() ) && TLS_Feedback_Moderation::is_public_comment_target( $post_id ) ) {
                return $result; // preprocess_comment stores it pending guardian + admin review.
            }
        }
        if ( preg_match( '#/(?:reviews|comments)(?:/|$)#i', $route ) ) {
            self::audit( 'restricted_rest_interaction_blocked', get_current_user_id(), array( 'route' => $route, 'method' => strtoupper( $request->get_method() ) ) );
            return new WP_Error( 'tls_child_interaction_restricted', __( 'This review or comment needs an approved guardian relationship and will be moderated before publication.', 'tutor-learning-suite' ), array( 'status' => 403 ) );
        }
        return $result;
    }

    /** Remove payment history, billing and public review destinations from restricted student dashboards. */
    public static function filter_tutor_account_pages( $pages ) {
        if ( ! self::is_restricted_student() || ! is_array( $pages ) ) {
            return $pages;
        }
        unset( $pages['billing'], $pages['reviews'], $pages['purchase_history'] );
        return $pages;
    }

    /** Hide discussions from restricted students while preserving courses and learning. */
    public static function filter_tutor_student_nav( $items ) {
        if ( ! self::is_restricted_student() || ! is_array( $items ) ) {
            return $items;
        }
        unset( $items['discussions'], $items['reviews'], $items['billing'], $items['purchase_history'] );
        return $items;
    }

    /** Restricted learners cannot start a general gift purchase from their own account. */
    public static function filter_native_gift_button( $can_gift, $course_id = 0 ) {
        if ( self::is_restricted_student() ) {
            return false;
        }
        return $can_gift;
    }

    /** Restricted learners may self-enrol only in guardian-approved free courses or claim a verified native paid gift. */
    public static function deny_restricted_enrollment( $course_id = 0 ) {
        if ( ! self::is_restricted_student() ) {
            return;
        }
        $user_id   = get_current_user_id();
        $course_id = absint( $course_id );
        if ( self::can_child_self_enroll_free( $user_id, $course_id ) || self::has_valid_native_gift_claim( $user_id, $course_id ) ) {
            return;
        }
        $decision = self::child_free_enrollment_decision( $user_id, $course_id );
        $reason   = ! empty( $decision['reason'] ) ? sanitize_key( $decision['reason'] ) : 'unknown';
        self::audit( 'restricted_enrollment_blocked', $user_id, array( 'post_id' => $course_id, 'reason' => $reason, 'guardian_id' => absint( $decision['parent_id'] ?? 0 ) ) );
        $message = self::child_free_enrollment_denial_message( $reason );
        self::deny_with_frontend_notice( $message, __( 'Enrolment needs approval', 'tutor-learning-suite' ), $course_id ? get_permalink( $course_id ) : '' );
    }

    /** Also protect Tutor integrations that build an enrolment object before inserting it. */
    public static function filter_restricted_enrollment_data( $enrollment_data ) {
        if ( ! is_array( $enrollment_data ) || empty( $enrollment_data['post_type'] ) || 'tutor_enrolled' !== $enrollment_data['post_type'] ) {
            return $enrollment_data;
        }
        $student_id = absint( $enrollment_data['post_author'] ?? 0 );
        if ( ! $student_id || ! self::is_restricted_student( $student_id ) ) {
            return $enrollment_data;
        }
        $course_id = absint( $enrollment_data['post_parent'] ?? 0 );
        if ( ( get_current_user_id() === $student_id && self::can_child_self_enroll_free( $student_id, $course_id ) ) || self::has_valid_native_gift_claim( $student_id, $course_id ) ) {
            return $enrollment_data;
        }
        // Admins may assign a course after a manual safeguarding check. Tutors and
        // learner accounts cannot bypass the free-course or native-gift rules.
        if ( current_user_can( 'manage_options' ) && get_current_user_id() !== $student_id ) {
            return $enrollment_data;
        }
        $decision = self::child_free_enrollment_decision( $student_id, $course_id );
        $reason   = ! empty( $decision['reason'] ) ? sanitize_key( $decision['reason'] ) : 'unknown';
        self::audit( 'restricted_enrollment_record_blocked', $student_id, array( 'post_id' => $course_id, 'reason' => $reason, 'guardian_id' => absint( $decision['parent_id'] ?? 0 ) ) );
        $message = self::child_free_enrollment_denial_message( $reason );
        self::deny_with_frontend_notice( $message, __( 'Enrolment needs approval', 'tutor-learning-suite' ), $course_id ? get_permalink( $course_id ) : '' );
    }

    public static function filter_course_purchase( $can_buy, $course_id ) {
        if ( ! self::is_restricted_student() ) {
            return $can_buy;
        }
        if ( self::can_child_self_enroll_free( get_current_user_id(), absint( $course_id ) ) ) {
            return $can_buy;
        }
        return new WP_Error( 'tls_guardian_purchase_required', __( 'This account cannot buy paid courses. You may enrol in approved free courses; ask your parent or guardian to gift paid courses to you.', 'tutor-learning-suite' ) );
    }

    public static function filter_checkout_button( $enabled, $checkout_data = null ) {
        return self::is_restricted_student() ? false : $enabled;
    }

    public static function deny_direct_checkout_action() {
        if ( self::is_restricted_student() ) {
            self::deny_with_frontend_notice(
                __( 'Course enrolment and payments must be managed through a parent or legal guardian and approved by the site.', 'tutor-learning-suite' ),
                __( 'Ask a parent or guardian', 'tutor-learning-suite' )
            );
        }
    }

    public static function filter_woocommerce_add_to_cart( $passed, $product_id, $quantity = 1, $variation_id = 0, $variations = array() ) {
        if ( self::is_restricted_student() ) {
            self::deny_with_frontend_notice(
                __( 'This account cannot enrol itself or make course payments. Ask your parent or guardian to contact the site.', 'tutor-learning-suite' ),
                __( 'Payments need a parent or guardian', 'tutor-learning-suite' )
            );
            return false;
        }
        return $passed;
    }

    public static function deny_woocommerce_checkout() {
        if ( self::is_restricted_student() ) {
            self::deny_with_frontend_notice(
                __( 'This account cannot enrol itself or make course payments. Ask your parent or guardian to contact the site.', 'tutor-learning-suite' ),
                __( 'Payments need a parent or guardian', 'tutor-learning-suite' )
            );
        }
    }

    public static function filter_review_author_context( $author, $comment_id = 0, $comment = null ) {
        if ( $comment_id && 'learner' === get_comment_meta( $comment_id, '_tls_feedback_public_author', true ) ) {
            return __( 'Learner', 'tutor-learning-suite' );
        }
        $context = $comment_id ? get_comment_meta( $comment_id, '_tls_review_author_context', true ) : '';
        if ( 'guardian' === $context ) {
            $label = '(' . __( 'Parent/guardian', 'tutor-learning-suite' ) . ')';
            if ( false === strpos( (string) $author, $label ) ) {
                return trim( (string) $author ) . ' ' . $label;
            }
        }
        return $author;
    }

    /**
     * BuddyPress private messages are disabled for restricted learners and for
     * conversations addressed to restricted learners. This is intentionally
     * conservative until a tutor/guardian-mediated messaging channel is built.
     * BuddyPress passes the message object by reference to this action.
     */
    public static function guard_buddypress_message( $message ) {
        if ( ! is_object( $message ) ) {
            return;
        }
        $sender_id = isset( $message->sender_id ) ? absint( $message->sender_id ) : get_current_user_id();
        $blocked = self::is_restricted_student( $sender_id );
        $recipient_ids = array();
        if ( ! empty( $message->recipients ) && is_iterable( $message->recipients ) ) {
            foreach ( $message->recipients as $recipient ) {
                $recipient_id = is_object( $recipient ) && isset( $recipient->user_id ) ? absint( $recipient->user_id ) : ( is_numeric( $recipient ) ? absint( $recipient ) : 0 );
                if ( $recipient_id ) {
                    $recipient_ids[] = $recipient_id;
                    if ( self::is_restricted_student( $recipient_id ) ) {
                        $blocked = true;
                    }
                }
            }
        }
        if ( $blocked ) {
            self::audit( 'buddypress_message_blocked', $sender_id, array( 'recipient_count' => count( $recipient_ids ) ) );
            self::deny_with_frontend_notice(
                __( 'Private community messaging is unavailable for this account. Contact the tutor through an approved parent/guardian booking or the site support channel.', 'tutor-learning-suite' ),
                __( 'Messaging is unavailable', 'tutor-learning-suite' )
            );
        }
    }

    /** Normalize Better Messages recipient arrays containing IDs or participant objects. */
    private static function normalize_message_participant_ids( $participants ) {
        $ids = array();
        foreach ( (array) $participants as $key => $participant ) {
            if ( is_object( $participant ) && isset( $participant->user_id ) ) {
                $id = (int) $participant->user_id;
            } elseif ( is_array( $participant ) && isset( $participant['user_id'] ) ) {
                $id = (int) $participant['user_id'];
            } elseif ( is_numeric( $participant ) ) {
                $id = (int) $participant;
            } elseif ( is_numeric( $key ) ) {
                $id = (int) $key;
            } else {
                $id = 0;
            }
            if ( 0 !== $id ) {
                $ids[] = $id;
            }
        }
        return array_values( array_unique( $ids ) );
    }

    /** Fetch participant IDs through Better Messages' public API. */
    private static function better_messages_thread_participants( $thread_id ) {
        $thread_id = absint( $thread_id );
        if ( ! $thread_id || ! function_exists( 'Better_Messages' ) ) {
            return array();
        }
        try {
            $better_messages = Better_Messages();
            if ( ! is_object( $better_messages ) || ! isset( $better_messages->functions ) || ! is_object( $better_messages->functions ) || ! is_callable( array( $better_messages->functions, 'get_recipients_ids' ) ) ) {
                return array();
            }
            return self::normalize_message_participant_ids( $better_messages->functions->get_recipients_ids( $thread_id, false ) );
        } catch ( Throwable $e ) {
            return array();
        }
    }

    /** Whether a participant is an instructor or trusted site administrator. */
    private static function is_better_messages_staff_participant( $user_id ) {
        $user_id = (int) $user_id;
        // Better Messages uses negative IDs for guests and AI users. Never turn
        // those into positive WordPress IDs with absint() during authorization.
        if ( $user_id < 1 ) {
            return false;
        }
        if ( user_can( $user_id, 'manage_options' ) || user_can( $user_id, 'moderate_comments' ) ) {
            return true;
        }
        $user = get_userdata( $user_id );
        if ( $user instanceof WP_User && in_array( 'tutor_instructor', (array) $user->roles, true ) ) {
            return true;
        }
        if ( function_exists( 'tutor_utils' ) ) {
            try {
                $utils = tutor_utils();
                if ( is_object( $utils ) && is_callable( array( $utils, 'is_instructor' ) ) && $utils->is_instructor( $user_id, false ) ) {
                    return true;
                }
            } catch ( Throwable $e ) {
                // Role fallback above remains available.
            }
        }
        return false;
    }

    /**
     * Validate the complete participant set for a conversation involving a
     * restricted learner. One restricted learner per conversation is allowed.
     * The learner's approved guardian must be present from thread creation; any
     * additional adult must be an instructor or site moderator chosen by the guardian.
     */
    public static function better_messages_validate_participants( $participants ) {
        $participant_ids = self::normalize_message_participant_ids( $participants );
        if ( ! $participant_ids ) {
            return false;
        }

        $restricted_students = array();
        foreach ( $participant_ids as $participant_id ) {
            if ( $participant_id < 1 ) {
                // Better Messages guest/AI IDs are negative; they cannot be part
                // of a conversation that contains a restricted learner.
                return false;
            }
            if ( self::is_restricted_student( $participant_id ) ) {
                $restricted_students[] = $participant_id;
            }
        }

        if ( ! $restricted_students ) {
            return true;
        }
        if ( 1 !== count( $restricted_students ) ) {
            return false;
        }

        $student_id  = absint( $restricted_students[0] );
        $guardian_id = absint( get_user_meta( $student_id, '_tls_guardian_user_id', true ) );
        if ( ! $guardian_id || ! in_array( $guardian_id, $participant_ids, true ) || ! self::has_approved_guardian_link_read_only( $guardian_id, $student_id ) ) {
            // Keep a narrow support exception: a child may contact a site moderator
            // directly when no valid guardian relationship exists. No other adults
            // or learners may be included in that support conversation.
            $other_ids = array_values( array_diff( $participant_ids, array( $student_id ) ) );
            return 1 === count( $other_ids ) && self::is_better_messages_staff_participant( $other_ids[0] ) && user_can( $other_ids[0], 'manage_options' );
        }

        foreach ( $participant_ids as $participant_id ) {
            if ( $participant_id === $student_id || $participant_id === $guardian_id ) {
                continue;
            }
            if ( ! self::is_better_messages_staff_participant( $participant_id ) ) {
                return false;
            }
        }
        return true;
    }

    /** Deny reading unsupervised historical threads that contain a restricted learner. */
    public static function filter_better_messages_thread_access( $override, $thread_id, $user_id, $access_type ) {
        $user_id = (int) $user_id;
        $participants = self::better_messages_thread_participants( $thread_id );
        if ( ! $participants ) {
            return $user_id > 0 && self::is_restricted_student( $user_id ) ? false : $override;
        }
        $has_restricted_student = false;
        foreach ( $participants as $participant_id ) {
            if ( $participant_id > 0 && self::is_restricted_student( $participant_id ) ) {
                $has_restricted_student = true;
                break;
            }
        }
        if ( ! $has_restricted_student ) {
            return $override;
        }
        if ( $user_id < 1 || ! in_array( $user_id, $participants, true ) || ! self::better_messages_validate_participants( $participants ) ) {
            self::audit( 'better_messages_thread_access_blocked', $user_id > 0 ? $user_id : 0, array( 'thread_id' => absint( $thread_id ), 'access_type' => sanitize_key( $access_type ) ) );
            return false;
        }
        // Preserve Better Messages' normal participant check for valid supervised threads.
        return $override;
    }

    /** Filter replies in an existing Better Messages conversation. */
    public static function filter_better_messages_send_permission( $allowed, $user_id, $thread_id ) {
        if ( ! $allowed ) {
            return false;
        }
        $user_id = (int) $user_id;
        $participants = self::better_messages_thread_participants( $thread_id );
        if ( ! $participants ) {
            return $user_id > 0 && self::is_restricted_student( $user_id ) ? false : $allowed;
        }
        if ( $user_id < 1 ) {
            foreach ( $participants as $participant_id ) {
                if ( $participant_id > 0 && self::is_restricted_student( $participant_id ) ) {
                    return false;
                }
            }
            return $allowed;
        }
        if ( ! in_array( $user_id, $participants, true ) ) {
            return $allowed;
        }
        foreach ( $participants as $participant_id ) {
            if ( $participant_id > 0 && self::is_restricted_student( $participant_id ) ) {
                if ( ! self::better_messages_validate_participants( $participants ) ) {
                    global $bp_better_messages_restrict_send_message;
                    $bp_better_messages_restrict_send_message['tls_child_safety'] = __( 'This conversation must include the learner’s approved parent/guardian. Ask the parent to start a supervised conversation with the tutor.', 'tutor-learning-suite' );
                    return false;
                }
                break;
            }
        }
        return $allowed;
    }

    /** Server-side guard for Better Messages replies, including REST/AJAX sends. */
    public static function guard_better_messages_before_send( &$args, &$errors ) {
        $sender_id = (int) ( $args['sender_id'] ?? get_current_user_id() );
        $thread_id = absint( $args['thread_id'] ?? 0 );
        if ( ! $thread_id ) {
            return;
        }
        $participants = self::better_messages_thread_participants( $thread_id );
        if ( ! $participants ) {
            if ( $sender_id > 0 && self::is_restricted_student( $sender_id ) ) {
                $errors['tls_child_safety'] = __( 'This account can message only through an approved parent/guardian conversation.', 'tutor-learning-suite' );
            }
            return;
        }
        $contains_child = false;
        foreach ( $participants as $participant_id ) {
            if ( $participant_id > 0 && self::is_restricted_student( $participant_id ) ) {
                $contains_child = true;
                break;
            }
        }
        if ( ( $contains_child || ( $sender_id > 0 && self::is_restricted_student( $sender_id ) ) ) && ! self::better_messages_validate_participants( $participants ) ) {
            $errors['tls_child_safety'] = __( 'This conversation is not approved for a restricted learner. Include the learner’s approved parent/guardian and ask them to add the tutor if needed.', 'tutor-learning-suite' );
        }
    }

    /** Guard the initial participant list before Better Messages creates a thread. */
    public static function guard_better_messages_before_new_thread( &$args, &$errors ) {
        $sender_id = (int) ( $args['sender_id'] ?? get_current_user_id() );
        $recipients = self::normalize_message_participant_ids( $args['recipients'] ?? array() );
        $participants = array_values( array_unique( array_merge( array( $sender_id ), $recipients ) ) );
        $contains_child = $sender_id > 0 && self::is_restricted_student( $sender_id );
        if ( ! $contains_child ) {
            foreach ( $recipients as $recipient_id ) {
                if ( $recipient_id > 0 && self::is_restricted_student( $recipient_id ) ) {
                    $contains_child = true;
                    break;
                }
            }
        }
        if ( $contains_child && ! self::better_messages_validate_participants( $participants ) ) {
            $errors['tls_child_safety'] = __( 'To start a conversation with a restricted learner, include their approved parent/guardian. A tutor may join when the guardian includes them.', 'tutor-learning-suite' );
            self::audit( 'better_messages_new_thread_blocked', $sender_id, array( 'recipient_count' => count( $recipients ) ) );
        }
    }

    /** Do not let a learner join an existing conversation unless it is supervised already. */
    public static function filter_better_messages_add_participant( $allow, $thread_id, $user_id, $context = '' ) {
        if ( ! $allow ) {
            return false;
        }
        $existing = self::better_messages_thread_participants( $thread_id );
        if ( ! $existing ) {
            return ( (int) $user_id > 0 && self::is_restricted_student( (int) $user_id ) ) ? false : $allow;
        }
        $existing_has_child = false;
        foreach ( $existing as $participant_id ) {
            if ( $participant_id > 0 && self::is_restricted_student( $participant_id ) ) {
                $existing_has_child = true;
                break;
            }
        }
        // Never add the guardian after a child has already been chatting: doing
        // so would expose earlier messages that were sent without supervision.
        if ( $existing_has_child && ! self::better_messages_validate_participants( $existing ) ) {
            return false;
        }
        $incoming_user_id = (int) $user_id;
        if ( $existing_has_child && $incoming_user_id < 1 ) {
            return false;
        }
        $participants = array_values( array_unique( array_merge( $existing, array( $incoming_user_id ) ) ) );
        $contains_child = $existing_has_child || ( $incoming_user_id > 0 && self::is_restricted_student( $incoming_user_id ) );
        return $contains_child ? self::better_messages_validate_participants( $participants ) : $allow;
    }

    /** Enforce the same child-safety policy before a bulk participant update is committed. */
    public static function guard_better_messages_before_add_participants( &$args, &$errors ) {
        $thread_id = absint( $args['thread_id'] ?? 0 );
        $sender_id = (int) ( $args['sender_id'] ?? get_current_user_id() );
        $incoming  = self::normalize_message_participant_ids( $args['user_ids'] ?? array() );
        $existing  = self::better_messages_thread_participants( $thread_id );
        $has_child = false;
        foreach ( array_merge( $existing, $incoming, array( $sender_id ) ) as $participant_id ) {
            if ( $participant_id > 0 && self::is_restricted_student( $participant_id ) ) {
                $has_child = true;
                break;
            }
        }
        if ( ! $has_child ) {
            return;
        }
        $existing_has_child = false;
        foreach ( $existing as $participant_id ) {
            if ( $participant_id > 0 && self::is_restricted_student( $participant_id ) ) {
                $existing_has_child = true;
                break;
            }
        }
        // Do not retroactively expose messages from an unsupervised child thread
        // by adding the guardian after the conversation has already begun.
        if ( $existing_has_child && ! self::better_messages_validate_participants( $existing ) ) {
            $errors['tls_child_safety'] = __( 'This conversation started without the learner’s approved guardian and cannot be converted. Start a new supervised conversation instead.', 'tutor-learning-suite' );
            self::audit( 'better_messages_participant_add_blocked', $sender_id > 0 ? $sender_id : 0, array( 'recipient_count' => count( $incoming ) ) );
            return;
        }
        $candidate = array_values( array_unique( array_merge( $existing, $incoming ) ) );
        if ( $sender_id > 0 ) {
            $candidate[] = $sender_id;
        }
        if ( ! self::better_messages_validate_participants( $candidate ) ) {
            $errors['tls_child_safety'] = __( 'Participants cannot be added to this learner conversation unless the approved guardian is already included and the new participant is an authorized instructor or site moderator.', 'tutor-learning-suite' );
            self::audit( 'better_messages_participant_add_blocked', $sender_id > 0 ? $sender_id : 0, array( 'recipient_count' => count( $incoming ) ) );
        }
    }

    /** Only the guardian or a site moderator may invite others into a supervised child thread. */
    public static function filter_better_messages_invite( $can_invite, $user_id, $thread_id ) {
        $user_id = (int) $user_id;
        if ( $user_id < 1 || self::is_restricted_student( $user_id ) ) {
            return false;
        }
        $participants = self::better_messages_thread_participants( $thread_id );
        if ( ! $participants ) {
            return $can_invite;
        }
        $child_id = 0;
        foreach ( $participants as $participant_id ) {
            if ( $participant_id > 0 && self::is_restricted_student( $participant_id ) ) {
                $child_id = absint( $participant_id );
                break;
            }
        }
        if ( ! $child_id ) {
            return $can_invite;
        }
        $guardian_id = absint( get_user_meta( $child_id, '_tls_guardian_user_id', true ) );
        return $can_invite && ( $user_id === $guardian_id || self::is_better_messages_staff_participant( $user_id ) );
    }

    /** Restricted learners cannot join open group chats or chat rooms. */
    public static function filter_better_messages_chat_room_join( $has_access, $user_id, $chat_id, $thread_id ) {
        $user_id = (int) $user_id;
        return $user_id > 0 && self::is_restricted_student( $user_id ) ? false : $has_access;
    }

    public static function filter_better_messages_group_chat_access( $has_access, $thread_id, $user_id ) {
        $user_id = (int) $user_id;
        $participants = self::better_messages_thread_participants( $thread_id );
        $contains_child = false;
        foreach ( $participants as $participant_id ) {
            if ( $participant_id > 0 && self::is_restricted_student( $participant_id ) ) {
                $contains_child = true;
                break;
            }
        }
        if ( ! $contains_child ) {
            return $user_id > 0 && self::is_restricted_student( $user_id ) ? false : $has_access;
        }
        // Supervised group conversations (child + approved guardian + selected tutor)
        // are explicitly granted, but only to a current participant. Better Messages
        // defaults group-thread access to false until an integration grants it.
        if ( $user_id < 1 || ! in_array( $user_id, $participants, true ) ) {
            return false;
        }
        return self::better_messages_validate_participants( $participants ) ? true : false;
    }

    public static function guard_buddypress_activity( $activity ) {
        if ( ! is_object( $activity ) || empty( $activity->user_id ) || ! self::is_restricted_student( $activity->user_id ) ) {
            return;
        }
        self::audit( 'buddypress_activity_blocked', absint( $activity->user_id ), array( 'component' => isset( $activity->component ) ? sanitize_key( $activity->component ) : '' ) );
        self::deny_with_frontend_notice(
            __( 'Public community activity is disabled for this account. For concerns, contact site support using the contact method published on this website.', 'tutor-learning-suite' ),
            __( 'Community activity is unavailable', 'tutor-learning-suite' )
        );
    }

    public static function guard_buddypress_messages_screen() {
        if ( self::is_restricted_student() && function_exists( 'bp_is_messages_component' ) && bp_is_messages_component() ) {
            self::deny_with_frontend_notice(
                __( 'Private community messaging is unavailable for this account. Contact the tutor through an approved parent/guardian booking or the site support channel.', 'tutor-learning-suite' ),
                __( 'Messaging is unavailable', 'tutor-learning-suite' )
            );
        }
    }

    public static function filter_buddypress_message_button( $button ) {
        if ( self::is_restricted_student() ) {
            return '';
        }
        if ( function_exists( 'bp_displayed_user_id' ) && self::is_restricted_student( bp_displayed_user_id() ) ) {
            return '';
        }
        return $button;
    }

    public static function body_classes( $classes ) {
        if ( self::is_restricted_student() ) {
            $classes[] = 'tls-age-restricted';
            $classes[] = 'tls-age-' . sanitize_html_class( self::age_band( get_current_user_id() ) );
        }
        return $classes;
    }

    public static function enqueue_safety_css() {
        $user_id  = get_current_user_id();
        $restricted = self::is_restricted_student();
        $flash = $user_id ? get_transient( self::FRONTEND_NOTICE_TRANSIENT . $user_id ) : false;
        if ( false !== $flash && $user_id ) {
            delete_transient( self::FRONTEND_NOTICE_TRANSIENT . $user_id );
        }
        // A one-time notice can also belong to an adult guardian who attempted
        // an action on a child's behalf. Do not apply learner restrictions to them.
        if ( ! $restricted && false === $flash ) {
            return;
        }
        $feedback_allowed = $restricted && class_exists( 'TLS_Feedback_Moderation' ) && TLS_Feedback_Moderation::can_child_submit_feedback( $user_id );
        $css = ( $feedback_allowed ? '' : '.tls-age-restricted .write-course-review-link-btn,.tls-age-restricted .tutor-course-enrolled-review-wrap,.tls-age-restricted .tutor-write-review-form,.tls-age-restricted .tlms-ps-rating-cta,.tls-age-restricted .tutor-comment-form,.tls-age-restricted .tutor-qna-form,.tls-age-restricted .tutor-add-question,.tls-age-restricted .tutor-lesson-comment-form,.tls-age-restricted .tutor-write-comment,.tls-age-restricted .tutor-comment-textarea{display:none!important}' ) .
            '.tls-age-restricted .tls-action-restricted{cursor:not-allowed!important;opacity:.78}' .
            '.tls-age-notice-backdrop{position:fixed;inset:0;z-index:999999;display:grid;place-items:center;padding:20px;background:rgba(15,23,42,.58);backdrop-filter:blur(3px)}' .
            '.tls-age-notice-dialog{position:relative;width:min(100%,440px);padding:28px;border:1px solid #e5eaf2;border-radius:20px;background:#fff;color:#26344d;box-shadow:0 24px 70px rgba(10,20,40,.28);font:inherit;animation:tlsAgeNoticeIn .18s ease-out}' .
            '.tls-age-notice-icon{display:grid;width:46px;height:46px;margin-bottom:15px;place-items:center;border-radius:14px;background:#fff4df;color:#a86409;font-size:22px;font-weight:800}' .
            '.tls-age-notice-dialog h2{margin:0 0 8px;color:#1f2d45;font-size:20px;line-height:1.3}' .
            '.tls-age-notice-dialog p{margin:0;color:#5f6e85;font-size:14px;line-height:1.65}' .
            '.tls-age-notice-close{display:inline-flex;align-items:center;justify-content:center;min-height:42px;margin-top:22px;padding:10px 18px;border:0;border-radius:10px;background:#365bd6;color:#fff;font:inherit;font-size:14px;font-weight:700;cursor:pointer}' .
            '.tls-age-notice-close:focus-visible{outline:3px solid #9eb2ff;outline-offset:3px}' .
            '@keyframes tlsAgeNoticeIn{from{opacity:0;transform:translateY(8px) scale(.98)}to{opacity:1;transform:none}}' .
            '@media(prefers-reduced-motion:reduce){.tls-age-notice-dialog{animation:none}}';
        wp_register_style( 'tls-age-safety-inline', false, array(), defined( 'TLS_VERSION' ) ? TLS_VERSION : '1.3.26' );
        wp_enqueue_style( 'tls-age-safety-inline' );
        wp_add_inline_style( 'tls-age-safety-inline', $css );

        wp_enqueue_script( 'tls-age-safety-notices', TLS_URL . 'assets/js/age-safety-notices.js', array(), defined( 'TLS_VERSION' ) ? TLS_VERSION : '1.3.26', true );

        // The modal guard normally blocks every enrolment-looking control for a
        // restricted learner. Exempt only the native Tutor free-enrolment form
        // on this exact single-course page, and only after the server has checked
        // the guardian link, age band, course price type and saved permission.
        // Paid course checkout and all other pages remain blocked.
        $free_self_enrollment_course_id = 0;
        if ( $restricted && function_exists( 'is_singular' ) && function_exists( 'get_queried_object_id' ) ) {
            $course_post_type = function_exists( 'tutor' ) && ! empty( tutor()->course_post_type ) ? tutor()->course_post_type : 'courses';
            if ( is_singular( $course_post_type ) ) {
                $queried_course_id = absint( get_queried_object_id() );
                if ( $queried_course_id && self::can_child_self_enroll_free( $user_id, $queried_course_id ) ) {
                    $free_self_enrollment_course_id = $queried_course_id;
                }
            }
        }

        wp_localize_script( 'tls-age-safety-notices', 'TLSAgeSafetyUI', array(
            'restricted' => (bool) $restricted,
            'feedbackAllowed' => (bool) $feedback_allowed,
            'feedbackMessage' => __( 'Your feedback will first go to your parent or guardian for approval, then to the site administrator before it is published.', 'tutor-learning-suite' ),
            'freeSelfEnrollmentCourseId' => $free_self_enrollment_course_id,
            'title' => __( 'This action needs a parent or guardian', 'tutor-learning-suite' ),
            'message' => __( 'This account has learning-safety restrictions. Ask your parent or guardian to help with this action. You can continue studying courses already assigned to you.', 'tutor-learning-suite' ),
            'close' => __( 'Got it', 'tutor-learning-suite' ),
            'flash' => is_array( $flash ) ? $flash : null,
        ) );
    }
}
