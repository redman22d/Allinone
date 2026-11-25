<?php
/**
 * Child feedback moderation: learner submission -> guardian review -> administrator moderation.
 * Uses native WordPress/Tutor comment records so public ratings/comments remain compatible.
 */
defined( 'ABSPATH' ) || exit;

final class TLS_Feedback_Moderation {
    const STAGE_META = '_tls_feedback_stage';
    const GUARDIAN_META = '_tls_feedback_guardian_id';
    const CHILD_META = '_tls_feedback_child_id';
    const KIND_META = '_tls_feedback_kind';
    const PUBLIC_AUTHOR_META = '_tls_feedback_public_author';
    const PARENT_REVIEWED_AT_META = '_tls_feedback_parent_reviewed_at';
    const PARENT_REVIEWED_BY_META = '_tls_feedback_parent_reviewed_by';

    private static $qna_insert_pending = false;
    private static $native_rating_request = null;

    public static function init() {
        // Make administrator moderation the default for every native Tutor course review.
        add_filter( 'enable_course_review_moderation', array( __CLASS__, 'force_tutor_course_review_moderation' ), 99, 1 );
        // Let Tutor LMS own rating validation, persistence and AJAX responses.
        // We only gate restricted children before the handler and stage the
        // persisted native review after Tutor fires its completion action.
        add_action( 'wp_ajax_tutor_place_rating', array( __CLASS__, 'guard_child_course_rating_submission' ), 0 );
        // Tutor Core 4.2.1 fires tutor_after_rating_placed for a new rating,
        // but does not fire it when add_or_update_review() updates an existing
        // rating. Capture both paths before the write and stage the saved row
        // during shutdown, after Tutor has completed its INSERT/UPDATE.
        add_action( 'tutor_before_rating_placed', array( __CLASS__, 'capture_native_rating_request' ), 1, 0 );
        add_action( 'tutor_after_rating_placed', array( __CLASS__, 'stage_child_course_rating_after_insert' ), 20, 1 );
        add_action( 'wp_ajax_tutor_qna_create_update', array( __CLASS__, 'guard_child_qna_submission' ), 0 );
        add_action( 'wp_ajax_tutor_create_lesson_comment', array( __CLASS__, 'guard_child_lesson_comment_submission' ), 0 );
        add_action( 'wp_ajax_tutor_reply_lesson_comment', array( __CLASS__, 'guard_child_lesson_comment_submission' ), 0 );
        add_action( 'wp_ajax_tutor_change_review_status', array( __CLASS__, 'guard_tutor_admin_review_approval' ), 0 );
        add_filter( 'wp_update_comment_data', array( __CLASS__, 'guard_wordpress_admin_approval' ), 20, 3 );
        add_filter( 'preprocess_comment', array( __CLASS__, 'preprocess_child_comment' ), 0, 1 );
        add_action( 'wp_insert_comment', array( __CLASS__, 'after_wordpress_comment_insert' ), 99, 2 );
        add_filter( 'tutor_qna_insert_data', array( __CLASS__, 'filter_qna_insert_data' ), 20, 1 );
        add_filter( 'query', array( __CLASS__, 'filter_tutor_qna_visibility_query' ), 20, 1 );
        add_action( 'tutor_after_asked_question', array( __CLASS__, 'after_qna_insert' ), 20, 1 );
        add_action( 'transition_comment_status', array( __CLASS__, 'track_admin_moderation' ), 20, 3 );
        add_action( 'tutor_course_review_approved', array( __CLASS__, 'mark_tutor_review_published' ), 20, 1 );
        // Parent actions run on the authenticated front-end Tutor dashboard.
        // Some managed hosts/security plugins deny non-administrators access to
        // wp-admin/admin-post.php, even for valid admin_post actions.
        add_action( 'template_redirect', array( __CLASS__, 'maybe_handle_frontend_parent_decision' ), 0 );
        // Keep the authenticated admin-post endpoint as a compatibility fallback.
        add_action( 'admin_post_tls_parent_feedback_decision', array( __CLASS__, 'handle_parent_decision' ) );
        add_action( 'admin_menu', array( __CLASS__, 'register_admin_feedback_queue' ), 40 );
        add_action( 'admin_post_tls_admin_feedback_decision', array( __CLASS__, 'handle_admin_feedback_decision' ) );
    }

    /** Restricted children need an approved guardian before submitting public feedback. */
    public static function can_child_submit_feedback( $child_id ) {
        $child_id = absint( $child_id );
        if ( ! $child_id || ! class_exists( 'TLS_Age_Safety' ) || 'child' !== TLS_Age_Safety::age_band( $child_id ) || ! TLS_Age_Safety::is_tutor_student( $child_id ) ) {
            return false;
        }
        return self::approved_guardian_for_child( $child_id ) > 0;
    }

    /** Return the active approved guardian id without running age-sync side effects. */
    public static function approved_guardian_for_child( $child_id ) {
        $child_id = absint( $child_id );
        if ( ! $child_id || ! class_exists( 'TLS_Age_Safety' ) || 'child' !== TLS_Age_Safety::age_band( $child_id ) ) {
            return 0;
        }
        $parent_id = absint( get_user_meta( $child_id, '_tls_guardian_user_id', true ) );
        if ( ! $parent_id
            || 'adult' !== TLS_Age_Safety::age_band( $parent_id )
            || '1' !== (string) get_user_meta( $parent_id, '_tls_guardian_adult_verified', true )
            || 'approved' !== (string) get_user_meta( $child_id, TLS_Age_Safety::GUARDIAN_STATUS_META, true )
            || '1' !== (string) get_user_meta( $child_id, '_tls_guardian_verification_completed', true )
            || ! get_user_meta( $child_id, '_tls_guardian_consent_at', true )
            || $parent_id !== absint( get_user_meta( $child_id, '_tls_guardian_consent_by', true ) ) ) {
            return 0;
        }
        return $parent_id;
    }

    public static function is_public_comment_target( $post_id ) {
        return self::is_course_or_lesson_post( absint( $post_id ) );
    }

    /** Parent is authorized to moderate this child's feedback. */
    public static function can_parent_moderate_child( $parent_id, $child_id ) {
        $parent_id = absint( $parent_id );
        $child_id  = absint( $child_id );
        return $parent_id > 0 && $child_id > 0 && $parent_id === self::approved_guardian_for_child( $child_id ) && get_current_user_id() === $parent_id;
    }

    /** Tag a pending comment with the separate guardian-review stage. */
    public static function stage_comment( $comment_id, $child_id, $kind = 'course_comment', $comment_status = '0', $is_resubmission = false ) {
        $comment_id = absint( $comment_id );
        $child_id   = absint( $child_id );
        $parent_id  = self::approved_guardian_for_child( $child_id );
        $comment    = $comment_id ? get_comment( $comment_id ) : null;
        if ( ! $comment || ! $parent_id || (int) $comment->user_id !== $child_id ) {
            return false;
        }

        // Repeated Tutor hooks and recovery scans must not reset the stage or
        // send duplicate guardian notifications for an item already queued.
        $existing_stage = (string) get_comment_meta( $comment_id, self::STAGE_META, true );
        $existing_guardian = absint( get_comment_meta( $comment_id, self::GUARDIAN_META, true ) );
        if ( 'guardian_pending' === $existing_stage && $existing_guardian === $parent_id ) {
            // Keep the item pending even if a third-party moderation screen changed
            // the native comment status after it was first staged.
            global $wpdb;
            $wpdb->update(
                $wpdb->comments,
                array( 'comment_approved' => (string) $comment_status, 'comment_author' => __( 'Learner', 'tutor-learning-suite' ), 'comment_author_email' => '', 'comment_author_url' => '' ),
                array( 'comment_ID' => $comment_id ),
                array( '%s', '%s', '%s', '%s' ),
                array( '%d' )
            );
            return true;
        }
        if ( in_array( $existing_stage, array( 'admin_pending', 'published' ), true ) && ! $is_resubmission ) {
            return false;
        }

        global $wpdb;
        $wpdb->update(
            $wpdb->comments,
            array(
                'comment_approved'      => (string) $comment_status,
                'comment_author'        => __( 'Learner', 'tutor-learning-suite' ),
                'comment_author_email'  => '',
                'comment_author_url'    => '',
            ),
            array( 'comment_ID' => $comment_id ),
            array( '%s', '%s', '%s', '%s' ),
            array( '%d' )
        );

        update_comment_meta( $comment_id, self::STAGE_META, 'guardian_pending' );
        update_comment_meta( $comment_id, self::GUARDIAN_META, $parent_id );
        update_comment_meta( $comment_id, self::CHILD_META, $child_id );
        update_comment_meta( $comment_id, self::KIND_META, sanitize_key( $kind ) );
        update_comment_meta( $comment_id, self::PUBLIC_AUTHOR_META, 'learner' );
        update_comment_meta( $comment_id, '_tls_feedback_submitted_at', current_time( 'mysql', true ) );
        delete_comment_meta( $comment_id, self::PARENT_REVIEWED_AT_META );
        delete_comment_meta( $comment_id, self::PARENT_REVIEWED_BY_META );

        if ( class_exists( 'TLS_Age_Safety' ) ) {
            TLS_Age_Safety::audit( 'child_feedback_submitted_for_guardian_review', $child_id, array(
                'comment_id' => $comment_id,
                'guardian_id' => $parent_id,
                'kind' => sanitize_key( $kind ),
            ) );
        }
        $guardian = get_userdata( $parent_id );
        if ( $guardian && is_email( $guardian->user_email ) ) {
            $dashboard_url = function_exists( 'tutor_utils' ) ? tutor_utils()->get_tutor_dashboard_page_permalink( 'tls-family' ) : home_url( '/dashboard/' );
            wp_mail(
                $guardian->user_email,
                __( 'Your child submitted feedback for review', 'tutor-learning-suite' ),
                get_the_author_meta( 'display_name', $child_id ) . ' submitted new feedback (' . sanitize_key( $kind ) . '). Please review it in your Family & Privacy dashboard: ' . esc_url_raw( $dashboard_url )
            );
        }
        return true;
    }

    private static function is_course_or_lesson_post( $post_id ) {
        $post_type = get_post_type( absint( $post_id ) );
        $course_type = function_exists( 'tutor' ) && ! empty( tutor()->course_post_type ) ? tutor()->course_post_type : 'courses';
        return in_array( $post_type, array( $course_type, 'lesson' ), true );
    }


    /** Identify the feedback type before a legacy child review is re-staged. */
    private static function feedback_kind_for_comment( $comment ) {
        if ( ! $comment instanceof WP_Comment ) {
            $comment = get_comment( $comment );
        }
        if ( ! $comment ) {
            return '';
        }
        $comment_id = absint( $comment->comment_ID );
        $post_id = absint( $comment->comment_post_ID );
        if ( get_comment_meta( $comment_id, '_tlms_ps_private_rating', true ) ) {
            return 'private_session_review';
        }
        if ( 'tutor_course_rating' === (string) $comment->comment_type && self::is_course_or_lesson_post( $post_id ) && ! get_post_meta( $post_id, '_tlms_ps_rating_course', true ) ) {
            return 'course_review';
        }
        if ( 'tutor_q_and_a' === (string) $comment->comment_type && self::is_course_or_lesson_post( $post_id ) ) {
            return 'course_qna';
        }
        if ( in_array( (string) $comment->comment_type, array( '', 'comment' ), true ) && self::is_course_or_lesson_post( $post_id ) ) {
            return 'lesson' === get_post_type( $post_id ) ? 'lesson_comment' : 'course_comment';
        }
        return '';
    }

    /** A native admin-pending/published status is valid only after this guardian's recorded approval. */
    private static function guardian_review_is_complete( $comment_id, $child_id ) {
        $comment_id = absint( $comment_id );
        $child_id = absint( $child_id );
        $guardian_id = self::approved_guardian_for_child( $child_id );
        if ( ! $comment_id || ! $child_id || ! $guardian_id ) {
            return false;
        }
        return absint( get_comment_meta( $comment_id, self::GUARDIAN_META, true ) ) === $guardian_id
            && absint( get_comment_meta( $comment_id, self::CHILD_META, true ) ) === $child_id
            && absint( get_comment_meta( $comment_id, self::PARENT_REVIEWED_BY_META, true ) ) === $guardian_id
            && '' !== (string) get_comment_meta( $comment_id, self::PARENT_REVIEWED_AT_META, true );
    }

    /** Return a legacy child comment to the guardian queue if an admin action tries to skip it. */
    private static function restore_child_feedback_to_guardian( $comment ) {
        if ( ! $comment instanceof WP_Comment ) {
            $comment = get_comment( $comment );
        }
        if ( ! $comment || ! class_exists( 'TLS_Age_Safety' ) || ! TLS_Age_Safety::is_restricted_student( (int) $comment->user_id ) ) {
            return false;
        }
        $child_id = absint( $comment->user_id );
        $guardian_id = self::approved_guardian_for_child( $child_id );
        $kind = self::feedback_kind_for_comment( $comment );
        if ( $guardian_id && $kind ) {
            $pending_status = 'tutor_course_rating' === (string) $comment->comment_type ? 'hold' : '0';
            self::stage_comment( $comment->comment_ID, $child_id, $kind, $pending_status, true );
        } else {
            // Without an approved guardian route, keep the child's content private.
            global $wpdb;
            $wpdb->update(
                $wpdb->comments,
                array( 'comment_approved' => 'tutor_course_rating' === (string) $comment->comment_type ? 'hold' : '0' ),
                array( 'comment_ID' => absint( $comment->comment_ID ) ),
                array( '%s' ),
                array( '%d' )
            );
        }
        return true;
    }

    /**
     * Tutor's Options_V2/Utils passes option values through a same-name filter.
     * Keep native course reviews pending by default for administrator moderation.
     * Site owners can explicitly override this policy with the TLS filter.
     */
    public static function force_tutor_course_review_moderation( $current_value ) {
        return self::admin_moderation_enabled() ? true : $current_value;
    }

    private static function admin_moderation_enabled() {
        return (bool) apply_filters( 'tls_feedback_admin_moderation_enabled', true );
    }

    /**
     * Keep all normal course/lesson comments pending for administrator moderation.
     * Restricted-child posts that have an approved guardian are additionally
     * anonymized and moved into the guardian-first workflow after insert.
     */
    public static function preprocess_child_comment( $commentdata ) {
        $post_id = absint( $commentdata['comment_post_ID'] ?? 0 );
        if ( ! self::is_course_or_lesson_post( $post_id ) ) {
            return $commentdata;
        }

        if ( self::admin_moderation_enabled() ) {
            $commentdata['comment_approved'] = '0';
        }

        $user_id = get_current_user_id();
        if ( ! class_exists( 'TLS_Age_Safety' ) || ! TLS_Age_Safety::is_restricted_student( $user_id ) ) {
            return $commentdata;
        }
        if ( ! self::can_child_submit_feedback( $user_id ) ) {
            return $commentdata;
        }

        $commentdata['comment_approved']     = '0';
        $commentdata['comment_author']       = __( 'Learner', 'tutor-learning-suite' );
        $commentdata['comment_author_email'] = '';
        $commentdata['comment_author_url']   = '';
        return $commentdata;
    }

    public static function after_wordpress_comment_insert( $comment_id, $comment = null ) {
        $comment = $comment instanceof WP_Comment ? $comment : get_comment( $comment_id );
        $user_id = get_current_user_id();
        if ( ! $comment || ! $user_id || ! class_exists( 'TLS_Age_Safety' ) || ! TLS_Age_Safety::is_restricted_student( $user_id ) || ! self::can_child_submit_feedback( $user_id ) ) {
            return;
        }
        if ( ! self::is_course_or_lesson_post( $comment->comment_post_ID ) || ! in_array( (string) $comment->comment_type, array( '', 'comment' ), true ) ) {
            return;
        }
        $kind = 'lesson' === get_post_type( $comment->comment_post_ID ) ? 'lesson_comment' : 'course_comment';
        self::stage_comment( $comment_id, $user_id, $kind, '0' );
    }

    /** Tutor lesson comments use wp_insert_comment; this hook stages them above. */


    /** Stop direct Tutor lesson-comment inserts when a restricted child has no approval route. */
    public static function guard_child_lesson_comment_submission() {
        $user_id = get_current_user_id();
        if ( class_exists( 'TLS_Age_Safety' ) && TLS_Age_Safety::is_restricted_student( $user_id ) && ! self::can_child_submit_feedback( $user_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Lesson comments need an approved parent or guardian relationship. Ask your parent or guardian to review your account settings.', 'tutor-learning-suite' ) ), 403 );
        }
    }

    /** Stop Q&A creation when a restricted child has no approved guardian route. */
    public static function guard_child_qna_submission() {
        $user_id = get_current_user_id();
        if ( class_exists( 'TLS_Age_Safety' ) && TLS_Age_Safety::is_restricted_student( $user_id ) && ! self::can_child_submit_feedback( $user_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Course questions need an approved parent or guardian relationship. Ask your parent or guardian to review your account settings.', 'tutor-learning-suite' ) ), 403 );
        }
    }

    /** Prevent admins from bypassing the guardian stage through Tutor's native review panel. */
    public static function guard_tutor_admin_review_approval() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $review_id = absint( wp_unslash( $_POST['id'] ?? 0 ) );
        $status = isset( $_POST['status'] ) && is_scalar( $_POST['status'] ) ? sanitize_key( wp_unslash( (string) $_POST['status'] ) ) : '';
        $comment = $review_id ? get_comment( $review_id ) : null;
        if ( $comment && in_array( $status, array( 'approved', '1' ), true ) && class_exists( 'TLS_Age_Safety' ) && TLS_Age_Safety::is_restricted_student( (int) $comment->user_id ) && ! self::guardian_review_is_complete( $review_id, (int) $comment->user_id ) ) {
            self::restore_child_feedback_to_guardian( $comment );
            wp_send_json_error( array( 'message' => __( 'This learner feedback has been returned to the parent/guardian approval queue. It cannot be published until that approval is recorded.', 'tutor-learning-suite' ) ), 409 );
        }
        $stage = $review_id ? (string) get_comment_meta( $review_id, self::STAGE_META, true ) : '';
        $blocked_stages = array( 'guardian_pending', 'parent_changes_requested', 'parent_rejected', 'admin_rejected' );
        if ( $review_id && in_array( $status, array( 'approved', '1' ), true ) && in_array( $stage, $blocked_stages, true ) ) {
            wp_send_json_error( array( 'message' => __( 'This learner feedback has not completed the guardian review stage and cannot be published.', 'tutor-learning-suite' ) ), 409 );
        }
    }

    /** Prevent direct WordPress comment moderation from skipping the guardian stage. */
    public static function guard_wordpress_admin_approval( $data, $comment, $commentarr ) {
        $comment_id = is_object( $comment ) && isset( $comment->comment_ID ) ? absint( $comment->comment_ID ) : 0;
        $new_status = isset( $data['comment_approved'] ) ? (string) $data['comment_approved'] : '';
        if ( $comment_id && in_array( $new_status, array( '1', 'approved' ), true ) && class_exists( 'TLS_Age_Safety' ) && TLS_Age_Safety::is_restricted_student( (int) $comment->user_id ) && ! self::guardian_review_is_complete( $comment_id, (int) $comment->user_id ) ) {
            self::restore_child_feedback_to_guardian( $comment );
            $data['comment_approved'] = 'tutor_course_rating' === (string) $comment->comment_type ? 'hold' : '0';
            return $data;
        }
        $stage = $comment_id ? (string) get_comment_meta( $comment_id, self::STAGE_META, true ) : '';
        $blocked_stages = array( 'guardian_pending', 'parent_changes_requested', 'parent_rejected', 'admin_rejected' );
        if ( $comment_id && in_array( $new_status, array( '1', 'approved' ), true ) && in_array( $stage, $blocked_stages, true ) ) {
            $data['comment_approved'] = 'tutor_course_rating' === (string) $comment->comment_type ? 'hold' : '0';
        }
        return $data;
    }

    /**
     * Tutor 4.2.1 loads Q&A using direct SQL which ignores comment_approved.
     * Hide child submissions until the guardian stage is complete; once the
     * guardian approves, only site administrators can see them while admin
     * moderation is pending. This also hides replies to those questions.
     */
    public static function filter_tutor_qna_visibility_query( $sql ) {
        global $wpdb;
        if ( ! is_string( $sql ) || '' === $sql || false === strpos( $sql, 'tutor_q_and_a' ) ) {
            return $sql;
        }

        $comments_table = $wpdb->comments;
        $meta_table = $wpdb->commentmeta;
        $viewer_id = absint( get_current_user_id() );
        $is_admin = current_user_can( 'manage_options' );
        $hidden_stages = "'guardian_pending','admin_pending','parent_changes_requested','parent_rejected','admin_rejected'";
        $visible_to_author_stages = "'guardian_pending','admin_pending'";

        // Tutor 4.2.1 reads Q&A with direct SQL and does not check comment_approved.
        // Keep child submissions private until guardian review, then restrict the
        // admin-pending stage to the author and site administrators only.
        $visibility_for = static function ( $alias ) use ( $meta_table, $viewer_id, $is_admin, $hidden_stages, $visible_to_author_stages ) {
            $stage_exists = "EXISTS (SELECT 1 FROM {$meta_table} tls_qna_stage WHERE tls_qna_stage.comment_id = {$alias}.comment_ID AND tls_qna_stage.meta_key = '_tls_feedback_stage' AND tls_qna_stage.meta_value IN ({$hidden_stages}))";
            $author_pending = "({$alias}.user_id = {$viewer_id} AND EXISTS (SELECT 1 FROM {$meta_table} tls_qna_author_stage WHERE tls_qna_author_stage.comment_id = {$alias}.comment_ID AND tls_qna_author_stage.meta_key = '_tls_feedback_stage' AND tls_qna_author_stage.meta_value IN ({$visible_to_author_stages})))";
            $admin_pending = $is_admin ? "EXISTS (SELECT 1 FROM {$meta_table} tls_qna_admin_stage WHERE tls_qna_admin_stage.comment_id = {$alias}.comment_ID AND tls_qna_admin_stage.meta_key = '_tls_feedback_stage' AND tls_qna_admin_stage.meta_value = 'admin_pending')" : '0=1';
            return " AND (NOT {$stage_exists} OR {$author_pending} OR {$admin_pending}) ";
        };

        // Root question list/count queries in Tutor\\Utils::get_qa_questions().
        if ( false !== strpos( $sql, "FROM {$comments_table} _question" ) && false !== strpos( $sql, "_question.comment_type = 'tutor_q_and_a'" ) && false === strpos( $sql, '_tls_feedback_stage' ) ) {
            $clause = $visibility_for( '_question' );
            if ( preg_match( '/\\s+ORDER BY\\s+_question\\.comment_ID/i', $sql ) ) {
                $sql = preg_replace( '/\\s+ORDER BY\\s+_question\\.comment_ID/i', $clause . ' ORDER BY _question.comment_ID', $sql, 1 );
            } else {
                $semicolon = strrpos( $sql, ';' );
                $sql = false !== $semicolon ? substr( $sql, 0, $semicolon ) . $clause . substr( $sql, $semicolon ) : $sql . $clause;
            }
        }

        // Answer lists are loaded separately. Apply the same rule to the parent question.
        if ( false !== strpos( $sql, "FROM {$comments_table} _chat" ) && false !== strpos( $sql, "comment_type = 'tutor_q_and_a'" ) && false !== strpos( $sql, '_chat.comment_parent' ) && false === strpos( $sql, '_tls_feedback_stage' ) ) {
            $parent_visibility = $visibility_for( 'tls_qna_parent' );
            $clause = " AND EXISTS (SELECT 1 FROM {$comments_table} tls_qna_parent WHERE tls_qna_parent.comment_ID = _chat.comment_parent {$parent_visibility}) ";
            $sql = preg_replace( '/\\s+ORDER BY\\s+_chat\\.comment_ID/i', $clause . ' ORDER BY _chat.comment_ID', $sql, 1 );
        }

        // Single-answer lookup joins the root question as `question`.
        if ( false !== strpos( $sql, "FROM {$comments_table} answer" ) && false !== strpos( $sql, "answer.comment_type = 'tutor_q_and_a'" ) && false === strpos( $sql, '_tls_feedback_stage' ) ) {
            $clause = $visibility_for( 'question' );
            $semicolon = strrpos( $sql, ';' );
            $sql = false !== $semicolon ? substr( $sql, 0, $semicolon ) . $clause . substr( $sql, $semicolon ) : $sql . $clause;
        }

        return $sql;
    }

    /** Tutor Q&A writes directly to wpdb, so set a pending status before insert. */
    public static function filter_qna_insert_data( $data ) {
        $user_id = get_current_user_id();
        if ( ! is_array( $data ) || ! class_exists( 'TLS_Age_Safety' ) || ! TLS_Age_Safety::is_restricted_student( $user_id ) ) {
            return $data;
        }
        if ( ! self::can_child_submit_feedback( $user_id ) ) {
            return $data;
        }
        $data['comment_approved'] = '0';
        $data['comment_author']   = __( 'Learner', 'tutor-learning-suite' );
        self::$qna_insert_pending = true;
        return $data;
    }

    public static function after_qna_insert( $data ) {
        if ( ! self::$qna_insert_pending ) {
            return;
        }
        self::$qna_insert_pending = false;
        global $wpdb;
        $comment_id = isset( $wpdb->insert_id ) ? absint( $wpdb->insert_id ) : 0;
        $child_id   = get_current_user_id();
        if ( $comment_id && $child_id ) {
            self::stage_comment( $comment_id, $child_id, 'course_qna', '0' );
        }
    }

    /** Capture a restricted child's native Tutor review before insert/update. */
    public static function capture_native_rating_request() {
        $user_id = get_current_user_id();
        if ( ! $user_id || ! class_exists( 'TLS_Age_Safety' ) || ! TLS_Age_Safety::is_restricted_student( $user_id ) || ! self::can_child_submit_feedback( $user_id ) ) {
            return;
        }

        $course_id = isset( $_POST['course_id'] ) && is_scalar( $_POST['course_id'] ) ? absint( wp_unslash( $_POST['course_id'] ) ) : 0;
        if ( ! $course_id || ! self::is_course_or_lesson_post( $course_id ) || get_post_meta( $course_id, '_tlms_ps_rating_course', true ) ) {
            return;
        }

        self::$native_rating_request = array(
            'course_id' => $course_id,
            'user_id'   => absint( $user_id ),
        );
        add_action( 'shutdown', array( __CLASS__, 'stage_native_rating_after_request' ), 9999 );
    }

    /**
     * Stage the latest pending native rating after Tutor has completed its
     * request. This also covers edits/resubmissions, where Tutor Core 4.2.1
     * updates the existing row but does not emit tutor_after_rating_placed.
     */
    public static function stage_native_rating_after_request() {
        $request = self::$native_rating_request;
        self::$native_rating_request = null;
        if ( ! is_array( $request ) || empty( $request['course_id'] ) || empty( $request['user_id'] ) ) {
            return;
        }

        $course_id = absint( $request['course_id'] );
        $user_id   = absint( $request['user_id'] );
        if ( self::approved_guardian_for_child( $user_id ) < 1 ) {
            return;
        }

        global $wpdb;
        $comment_id = absint( $wpdb->get_var( $wpdb->prepare(
            "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_post_ID = %d AND user_id = %d AND comment_type = %s AND comment_approved NOT IN ('trash','spam') ORDER BY comment_ID DESC LIMIT 1",
            $course_id,
            $user_id,
            'tutor_course_rating'
        ) ) );
        if ( ! $comment_id || get_comment_meta( $comment_id, '_tlms_ps_private_rating', true ) || get_post_meta( $course_id, '_tlms_ps_rating_course', true ) ) {
            return;
        }

        $stage = (string) get_comment_meta( $comment_id, self::STAGE_META, true );
        $guardian = absint( get_comment_meta( $comment_id, self::GUARDIAN_META, true ) );
        if ( 'guardian_pending' === $stage && $guardian === self::approved_guardian_for_child( $user_id ) ) {
            return;
        }

        // A resubmitted/edited review is new feedback. It must pass through the
        // guardian queue again even if an older version was already admin-pending.
        self::stage_comment( $comment_id, $user_id, 'course_review', 'hold', true );
    }

    /** Recover child feedback saved by an earlier plugin version without stage metadata. */
    private static function repair_orphaned_child_feedback( $child_id, $parent_id ) {
        $child_id  = absint( $child_id );
        $parent_id = absint( $parent_id );
        if ( ! $child_id || ! $parent_id || self::approved_guardian_for_child( $child_id ) !== $parent_id ) {
            return;
        }

        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT c.comment_ID, c.comment_type, c.comment_post_ID, c.comment_approved FROM {$wpdb->comments} c WHERE c.user_id = %d AND c.comment_approved NOT IN ('trash','spam') ORDER BY c.comment_ID DESC LIMIT 150",
            $child_id
        ) );
        foreach ( (array) $rows as $row ) {
            $comment_id = absint( $row->comment_ID );
            $post_id    = absint( $row->comment_post_ID );
            $type       = (string) $row->comment_type;
            $kind       = '';
            $existing_stage = (string) get_comment_meta( $comment_id, self::STAGE_META, true );
            $existing_guardian = absint( get_comment_meta( $comment_id, self::GUARDIAN_META, true ) );
            $existing_child = absint( get_comment_meta( $comment_id, self::CHILD_META, true ) );

            // A prior release may have sent a child's review straight to the
            // administrator. Do not treat admin_pending/published as proof of
            // guardian approval unless this active guardian's decision is recorded.
            $guardian_stage_completed = self::guardian_review_is_complete( $comment_id, $child_id );

            // Preserve explicit rejection and edit-request decisions. A pending
            // or published item without a matching recorded guardian decision
            // is an orphan and must be restored to guardian_pending.
            if ( in_array( $existing_stage, array( 'parent_changes_requested', 'parent_rejected', 'admin_rejected' ), true ) ) {
                continue;
            }
            if ( in_array( $existing_stage, array( 'admin_pending', 'published' ), true ) && $guardian_stage_completed ) {
                continue;
            }
            if ( 'guardian_pending' === $existing_stage && $existing_guardian === $parent_id && $existing_child === $child_id ) {
                continue;
            }

            if ( get_comment_meta( $comment_id, '_tlms_ps_private_rating', true ) ) {
                $kind = 'private_session_review';
            } elseif ( 'tutor_course_rating' === $type && self::is_course_or_lesson_post( $post_id ) && ! get_post_meta( $post_id, '_tlms_ps_rating_course', true ) ) {
                $kind = 'course_review';
            } elseif ( 'tutor_q_and_a' === $type && self::is_course_or_lesson_post( $post_id ) ) {
                $kind = 'course_qna';
            } elseif ( in_array( $type, array( '', 'comment' ), true ) && self::is_course_or_lesson_post( $post_id ) ) {
                $kind = 'lesson' === get_post_type( $post_id ) ? 'lesson_comment' : 'course_comment';
            }

            if ( $kind ) {
                $pending_status = in_array( $type, array( 'tutor_course_rating' ), true ) ? 'hold' : '0';
                self::stage_comment( $comment_id, $child_id, $kind, $pending_status, true );
            }
        }
    }

    /**
     * Block restricted children who lack a valid guardian approval route, but
     * otherwise allow Tutor LMS Core to process the rating normally. This avoids
     * duplicating Tutor's nonce, enrolment, database and response handling.
     */
    public static function guard_child_course_rating_submission() {
        $user_id = get_current_user_id();
        if ( ! class_exists( 'TLS_Age_Safety' ) || ! TLS_Age_Safety::is_restricted_student( $user_id ) ) {
            return;
        }
        if ( ! self::can_child_submit_feedback( $user_id ) ) {
            wp_send_json_error(
                array( 'message' => __( 'Your course review needs an approved parent or guardian relationship. Please ask your parent or the site administrator to check your Family & Privacy settings.', 'tutor-learning-suite' ) ),
                403
            );
        }
    }

    /**
     * Stage a child-authored native Tutor review after Tutor has saved it.
     * Private-session reviews use the same Tutor action, but are already tagged
     * with their own metadata and are staged by the private-session handler.
     */
    public static function stage_child_course_rating_after_insert( $comment_id ) {
        $comment_id = absint( $comment_id );
        $user_id    = get_current_user_id();
        if ( ! $comment_id || ! $user_id || ! class_exists( 'TLS_Age_Safety' ) || ! TLS_Age_Safety::is_restricted_student( $user_id ) || ! self::can_child_submit_feedback( $user_id ) ) {
            return;
        }

        $comment = get_comment( $comment_id );
        if ( ! $comment || (int) $comment->user_id !== $user_id || 'tutor_course_rating' !== (string) $comment->comment_type ) {
            return;
        }
        // Do not restage the synthetic Tutor rating-course rows created by the
        // private-session module; those carry their own learner/session context.
        if ( get_comment_meta( $comment_id, '_tlms_ps_private_rating', true ) || get_post_meta( (int) $comment->comment_post_ID, '_tlms_ps_rating_course', true ) ) {
            return;
        }
        if ( ! self::is_course_or_lesson_post( (int) $comment->comment_post_ID ) ) {
            return;
        }

        self::stage_comment( $comment_id, $user_id, 'course_review', 'hold' );
    }

    /** Read the persisted moderation value; transition hook labels can normalize Tutor's custom 'hold' status. */
    private static function persisted_comment_status( $comment_id ) {
        global $wpdb;
        $status = $wpdb->get_var( $wpdb->prepare(
            "SELECT comment_approved FROM {$wpdb->comments} WHERE comment_ID = %d",
            absint( $comment_id )
        ) );
        return null === $status ? '' : (string) $status;
    }

    /** Persist a moderation value without firing transition hooks that can normalize Tutor's custom statuses. */
    private static function write_comment_status( $comment_id, $status ) {
        global $wpdb;
        $updated = $wpdb->update(
            $wpdb->comments,
            array( 'comment_approved' => (string) $status ),
            array( 'comment_ID' => absint( $comment_id ) ),
            array( '%s' ),
            array( '%d' )
        );
        if ( false === $updated ) {
            return false;
        }
        if ( function_exists( 'clean_comment_cache' ) ) {
            clean_comment_cache( absint( $comment_id ) );
        }
        return true;
    }

    /** Track native moderator decisions on comments/reviews. */
    public static function track_admin_moderation( $new_status, $old_status, $comment ) {
        if ( ! $comment instanceof WP_Comment ) {
            $comment = get_comment( $comment );
        }
        if ( ! $comment ) {
            return;
        }
        if ( in_array( (string) $new_status, array( 'approved', '1' ), true ) && class_exists( 'TLS_Age_Safety' ) && TLS_Age_Safety::is_restricted_student( (int) $comment->user_id ) && ! self::guardian_review_is_complete( $comment->comment_ID, (int) $comment->user_id ) ) {
            $stage = (string) get_comment_meta( $comment->comment_ID, self::STAGE_META, true );
            if ( ! in_array( $stage, array( 'parent_changes_requested', 'parent_rejected', 'admin_rejected' ), true ) ) {
                self::restore_child_feedback_to_guardian( $comment );
            } else {
                global $wpdb;
                $wpdb->update( $wpdb->comments, array( 'comment_approved' => 'tutor_course_rating' === (string) $comment->comment_type ? 'hold' : '0' ), array( 'comment_ID' => (int) $comment->comment_ID ), array( '%s' ), array( '%d' ) );
            }
            return;
        }
        if ( 'admin_pending' !== get_comment_meta( $comment->comment_ID, self::STAGE_META, true ) ) {
            return;
        }
        $stored_status = self::persisted_comment_status( $comment->comment_ID );
        // A parent approval is stored as 'hold' for Tutor ratings or '0' for
        // WordPress comments. Do not mistake a normalized transition label for
        // a real administrator publication decision.
        if ( in_array( (string) $new_status, array( 'approved', '1' ), true ) && in_array( $stored_status, array( 'approved', '1' ), true ) ) {
            update_comment_meta( $comment->comment_ID, self::STAGE_META, 'published' );
            update_comment_meta( $comment->comment_ID, '_tls_feedback_admin_approved_at', current_time( 'mysql', true ) );
            update_comment_meta( $comment->comment_ID, '_tls_feedback_admin_approved_by', get_current_user_id() );
        } elseif ( in_array( (string) $new_status, array( 'spam', 'trash' ), true ) && in_array( $stored_status, array( 'spam', 'trash' ), true ) ) {
            update_comment_meta( $comment->comment_ID, self::STAGE_META, 'admin_rejected' );
            update_comment_meta( $comment->comment_ID, '_tls_feedback_admin_rejected_at', current_time( 'mysql', true ) );
        }
    }

    public static function mark_tutor_review_published( $comment_id ) {
        $comment_id = absint( $comment_id );
        $comment = $comment_id ? get_comment( $comment_id ) : null;
        if ( ! $comment ) {
            return;
        }
        // Tutor emits this action after its own admin status update. Ignore any
        // accidental invocation while the stored row is still pending/held.
        if ( ! in_array( self::persisted_comment_status( $comment_id ), array( 'approved', '1' ), true ) ) {
            return;
        }
        if ( class_exists( 'TLS_Age_Safety' ) && TLS_Age_Safety::is_restricted_student( (int) $comment->user_id ) && ! self::guardian_review_is_complete( $comment_id, (int) $comment->user_id ) ) {
            $stage = (string) get_comment_meta( $comment_id, self::STAGE_META, true );
            if ( ! in_array( $stage, array( 'parent_changes_requested', 'parent_rejected', 'admin_rejected' ), true ) ) {
                self::restore_child_feedback_to_guardian( $comment );
            }
            return;
        }
        if ( 'admin_pending' === get_comment_meta( $comment_id, self::STAGE_META, true ) ) {
            update_comment_meta( $comment_id, self::STAGE_META, 'published' );
            update_comment_meta( $comment_id, '_tls_feedback_admin_approved_at', current_time( 'mysql', true ) );
            update_comment_meta( $comment_id, '_tls_feedback_admin_approved_by', get_current_user_id() );
        }
    }

    /** Show private parent edit requests to the learner without exposing other family records. */
    public static function render_child_feedback_notices( $child_id ) {
        $child_id = absint( $child_id );
        if ( ! $child_id || ! is_user_logged_in() || get_current_user_id() !== $child_id ) {
            return '';
        }
        $items = get_comments( array(
            'user_id' => $child_id,
            'status' => 'all',
            'number' => 50,
            'orderby' => 'comment_date_gmt',
            'order' => 'DESC',
            'meta_query' => array( array(
                'key' => self::STAGE_META,
                'value' => array( 'guardian_pending', 'admin_pending', 'parent_changes_requested', 'parent_rejected', 'admin_rejected' ),
                'compare' => 'IN',
            ) ),
        ) );
        if ( ! $items ) {
            return '';
        }
        $html = '<section class="tls-feedback-parent-queue tls-feedback-child-notices"><h2>' . esc_html__( 'My feedback status', 'tutor-learning-suite' ) . '</h2><p class="tls-lms-muted">' . esc_html__( 'Your reviews and comments are checked by your parent or guardian first, then by the site administrator before publication.', 'tutor-learning-suite' ) . '</p>';
        foreach ( $items as $comment ) {
            $kind  = (string) get_comment_meta( $comment->comment_ID, self::KIND_META, true );
            $stage = (string) get_comment_meta( $comment->comment_ID, self::STAGE_META, true );
            $note  = (string) get_comment_meta( $comment->comment_ID, '_tls_feedback_parent_note', true );
            if ( 'guardian_pending' === $stage ) {
                $status = __( 'Waiting for parent/guardian approval', 'tutor-learning-suite' );
            } elseif ( 'admin_pending' === $stage ) {
                $status = __( 'Parent approved · waiting for administrator', 'tutor-learning-suite' );
            } elseif ( 'parent_changes_requested' === $stage ) {
                $status = __( 'Changes requested by your parent/guardian', 'tutor-learning-suite' );
            } elseif ( 'parent_rejected' === $stage ) {
                $status = __( 'Not approved by your parent/guardian', 'tutor-learning-suite' );
            } else {
                $status = __( 'Not approved by the site administrator', 'tutor-learning-suite' );
            }
            $html .= '<article class="tls-feedback-card"><div class="tls-feedback-card__head"><strong>' . esc_html( self::kind_label( $kind ) ) . '</strong><span>' . esc_html( $status ) . '</span></div><p>' . esc_html( self::feedback_context_title( $comment, $kind ) ) . '</p>';
            if ( 'parent_changes_requested' === $stage ) {
                if ( $note ) {
                    $html .= '<div class="tls-feedback-card__content">' . wpautop( esc_html( $note ) ) . '</div>';
                }
                $html .= '<p>' . esc_html__( 'Please update your feedback on the course or session page and submit it again.', 'tutor-learning-suite' ) . '</p>';
            } elseif ( in_array( $stage, array( 'parent_rejected', 'admin_rejected' ), true ) ) {
                $html .= '<p>' . esc_html__( 'You may submit new feedback after reviewing the moderation decision.', 'tutor-learning-suite' ) . '</p>';
            } else {
                $html .= '<div class="tls-feedback-card__content">' . wpautop( esc_html( $comment->comment_content ) ) . '</div>';
            }
            $html .= '</article>';
        }
        return $html . '</section>';
    }

    /** Parent dashboard queue. Only active linked children and the designated guardian can see items. */
    public static function render_parent_queue( $parent_id, $children ) {
        $parent_id = absint( $parent_id );
        if ( ! $parent_id || ! is_array( $children ) || ! $children ) {
            return '';
        }
        $html = '<section class="tls-feedback-parent-queue"><h2>' . esc_html__( 'Children’s Feedback', 'tutor-learning-suite' ) . '</h2><p class="tls-lms-muted">' . esc_html__( 'Review your child’s course reviews, lesson comments, questions and private-tutor feedback. After you approve an item, it still waits for administrator moderation before it can appear publicly.', 'tutor-learning-suite' ) . '</p>';
        $has_any = false;
        foreach ( $children as $child ) {
            $child_id = absint( $child->ID );
            if ( ! self::can_parent_moderate_child( $parent_id, $child_id ) ) {
                continue;
            }

            // Recover any review/comment saved by a previous version before
            // reading the queue (including existing Tutor review edits).
            self::repair_orphaned_child_feedback( $child_id, $parent_id );

            global $wpdb;
            $comment_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT c.comment_ID FROM {$wpdb->comments} c
                 INNER JOIN {$wpdb->commentmeta} stage ON stage.comment_id = c.comment_ID AND stage.meta_key = %s AND stage.meta_value = 'guardian_pending'
                 INNER JOIN {$wpdb->commentmeta} guardian ON guardian.comment_id = c.comment_ID AND guardian.meta_key = %s AND guardian.meta_value = %s
                 INNER JOIN {$wpdb->commentmeta} child ON child.comment_id = c.comment_ID AND child.meta_key = %s AND child.meta_value = %s
                 WHERE c.user_id = %d ORDER BY c.comment_date_gmt DESC LIMIT 100",
                self::STAGE_META,
                self::GUARDIAN_META,
                (string) $parent_id,
                self::CHILD_META,
                (string) $child_id,
                $child_id
            ) );
            $items = array();
            foreach ( (array) $comment_ids as $comment_id ) {
                $item = get_comment( absint( $comment_id ) );
                if ( $item ) {
                    $items[] = $item;
                }
            }
            if ( ! $items ) {
                continue;
            }
            $has_any = true;
            $html .= '<div class="tls-feedback-child-queue"><h3>' . esc_html( __( 'Feedback from', 'tutor-learning-suite' ) . ' ' . $child->display_name ) . '</h3>';
            foreach ( $items as $comment ) {
                $kind = (string) get_comment_meta( $comment->comment_ID, self::KIND_META, true );
                $title = self::feedback_context_title( $comment, $kind );
                $rating = absint( get_comment_meta( $comment->comment_ID, 'tutor_rating', true ) );
                $html .= '<article class="tls-feedback-card"><div class="tls-feedback-card__head"><strong>' . esc_html( self::kind_label( $kind ) ) . '</strong><span>' . esc_html( $title ) . '</span></div>';
                if ( $rating ) {
                    $html .= '<div class="tls-feedback-stars" aria-label="' . esc_attr( (int) $rating . ' ' . __( 'out of 5 stars', 'tutor-learning-suite' ) ) . '">' . esc_html( str_repeat( '★', min( 5, $rating ) ) . str_repeat( '☆', max( 0, 5 - $rating ) ) ) . '</div>';
                }
                $html .= '<div class="tls-feedback-card__content">' . wpautop( esc_html( $comment->comment_content ) ) . '</div><small>' . esc_html( mysql2date( get_option( 'date_format' ), $comment->comment_date ) ) . '</small>';
                $html .= '<div class="tls-feedback-card__actions">';
                foreach ( array( 'approve' => __( 'Approve for admin review', 'tutor-learning-suite' ), 'reject' => __( 'Reject', 'tutor-learning-suite' ) ) as $decision => $label ) {
                    $html .= '<form method="post" action="' . esc_url( self::parent_feedback_action_url() ) . '">' . wp_nonce_field( 'tls_parent_feedback_' . (int) $comment->comment_ID, '_tls_feedback_nonce', true, false ) . '<input type="hidden" name="tls_parent_feedback_frontend" value="1"><input type="hidden" name="action" value="tls_parent_feedback_decision"><input type="hidden" name="comment_id" value="' . (int) $comment->comment_ID . '"><input type="hidden" name="decision" value="' . esc_attr( $decision ) . '"><button class="tls-lms-button ' . ( 'reject' === $decision ? 'tls-lms-button-danger' : '' ) . '">' . esc_html( $label ) . '</button></form>';
                }
                $html .= '</div></article>';
            }
            $html .= '</div>';
        }
        if ( ! $has_any ) {
            $html .= '<p class="tls-lms-empty">' . esc_html__( 'There are no feedback items waiting for your review.', 'tutor-learning-suite' ) . '</p>';
        }
        return $html . '</section>';
    }

    /** Add a dedicated, combined administrator queue for guardian-approved child feedback. */
    public static function register_admin_feedback_queue() {
        add_submenu_page(
            'tutor',
            __( 'Feedback Moderation', 'tutor-learning-suite' ),
            __( 'Feedback Moderation', 'tutor-learning-suite' ),
            'manage_options',
            'tls-feedback-moderation',
            array( __CLASS__, 'render_admin_feedback_queue' )
        );
    }

    /**
     * Recover child feedback that older releases accidentally published at
     * guardian approval. A valid guardian decision without an administrator
     * approval timestamp is not proof of final publication.
     */
    private static function recover_parent_published_feedback() {
        if ( ! class_exists( 'TLS_Age_Safety' ) ) {
            return;
        }
        global $wpdb;
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT c.comment_ID FROM {$wpdb->comments} c
             INNER JOIN {$wpdb->commentmeta} stage ON stage.comment_id = c.comment_ID AND stage.meta_key = %s AND stage.meta_value = 'published'
             INNER JOIN {$wpdb->commentmeta} parent_at ON parent_at.comment_id = c.comment_ID AND parent_at.meta_key = %s AND parent_at.meta_value <> ''
             LEFT JOIN {$wpdb->commentmeta} admin_at ON admin_at.comment_id = c.comment_ID AND admin_at.meta_key = %s
             WHERE c.comment_approved IN ('approved','1')
               AND (admin_at.comment_id IS NULL OR admin_at.meta_value = '' OR admin_at.meta_value <= DATE_ADD(parent_at.meta_value, INTERVAL 5 SECOND))
             ORDER BY c.comment_date_gmt DESC LIMIT 250",
            self::STAGE_META,
            self::PARENT_REVIEWED_AT_META,
            '_tls_feedback_admin_approved_at'
        ) );
        foreach ( (array) $ids as $id ) {
            $comment = get_comment( absint( $id ) );
            if ( ! $comment || ! TLS_Age_Safety::is_restricted_student( (int) $comment->user_id ) ) {
                continue;
            }
            $child_id = absint( $comment->user_id );
            if ( ! self::guardian_review_is_complete( $comment->comment_ID, $child_id ) ) {
                self::restore_child_feedback_to_guardian( $comment );
                continue;
            }
            $pending_status = 'tutor_course_rating' === (string) $comment->comment_type ? 'hold' : '0';
            if ( self::write_comment_status( $comment->comment_ID, $pending_status ) ) {
                update_comment_meta( $comment->comment_ID, self::STAGE_META, 'admin_pending' );
                update_comment_meta( $comment->comment_ID, '_tls_feedback_recovered_from_parent_publication_at', current_time( 'mysql', true ) );
                TLS_Age_Safety::audit( 'recovered_child_feedback_published_before_admin_moderation', $child_id, array( 'comment_id' => (int) $comment->comment_ID ) );
            }
        }
    }

    /** Render feedback that passed parent approval and still awaits an administrator. */
    public static function render_admin_feedback_queue() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to moderate feedback.', 'tutor-learning-suite' ) );
        }
        self::recover_parent_published_feedback();
        global $wpdb;
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT c.comment_ID FROM {$wpdb->comments} c
             INNER JOIN {$wpdb->commentmeta} stage ON stage.comment_id = c.comment_ID AND stage.meta_key = %s AND stage.meta_value = 'admin_pending'
             WHERE c.comment_approved IN ('hold','0')
             ORDER BY c.comment_date_gmt DESC LIMIT 250",
            self::STAGE_META
        ) );
        $items = array();
        foreach ( (array) $ids as $id ) {
            $comment = get_comment( absint( $id ) );
            if ( ! $comment ) {
                continue;
            }
            $child_id = absint( get_comment_meta( $comment->comment_ID, self::CHILD_META, true ) );
            if ( ! $child_id || (int) $comment->user_id !== $child_id || ! self::guardian_review_is_complete( $comment->comment_ID, $child_id ) ) {
                if ( class_exists( 'TLS_Age_Safety' ) && TLS_Age_Safety::is_restricted_student( (int) $comment->user_id ) ) {
                    self::restore_child_feedback_to_guardian( $comment );
                }
                continue;
            }
            $items[] = $comment;
        }
        echo '<div class="wrap"><h1>' . esc_html__( 'Feedback Moderation', 'tutor-learning-suite' ) . '</h1>';
        echo '<p>' . esc_html__( 'These reviews and comments have been approved by the child’s parent or guardian. They are not public until an administrator publishes them here.', 'tutor-learning-suite' ) . '</p>';
        if ( isset( $_GET['tls_feedback_admin_notice'] ) ) {
            $notice = sanitize_key( wp_unslash( $_GET['tls_feedback_admin_notice'] ) );
            if ( 'published' === $notice ) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Feedback published.', 'tutor-learning-suite' ) . '</p></div>';
            } elseif ( 'rejected' === $notice ) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Feedback rejected.', 'tutor-learning-suite' ) . '</p></div>';
            }
        }
        if ( ! $items ) {
            echo '<p>' . esc_html__( 'There is no parent-approved feedback waiting for administrator moderation.', 'tutor-learning-suite' ) . '</p></div>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Learner', 'tutor-learning-suite' ) . '</th><th>' . esc_html__( 'Type', 'tutor-learning-suite' ) . '</th><th>' . esc_html__( 'Course / Tutor', 'tutor-learning-suite' ) . '</th><th>' . esc_html__( 'Feedback', 'tutor-learning-suite' ) . '</th><th>' . esc_html__( 'Parent approved', 'tutor-learning-suite' ) . '</th><th>' . esc_html__( 'Action', 'tutor-learning-suite' ) . '</th></tr></thead><tbody>';
        foreach ( $items as $comment ) {
            $child_id = (int) $comment->user_id;
            $child = get_userdata( $child_id );
            $kind = (string) get_comment_meta( $comment->comment_ID, self::KIND_META, true );
            $guardian_id = absint( get_comment_meta( $comment->comment_ID, self::PARENT_REVIEWED_BY_META, true ) );
            $guardian = $guardian_id ? get_userdata( $guardian_id ) : false;
            $rating = absint( get_comment_meta( $comment->comment_ID, 'tutor_rating', true ) );
            echo '<tr><td>' . esc_html( $child ? $child->display_name : __( 'Learner', 'tutor-learning-suite' ) ) . '</td>';
            echo '<td>' . esc_html( self::kind_label( $kind ) ) . ( $rating ? '<br>' . esc_html( str_repeat( '★', min( 5, $rating ) ) . str_repeat( '☆', max( 0, 5 - $rating ) ) ) : '' ) . '</td>';
            echo '<td>' . esc_html( self::feedback_context_title( $comment, $kind ) ) . '</td>';
            echo '<td>' . wp_kses_post( wpautop( esc_html( $comment->comment_content ) ) ) . '</td>';
            echo '<td>' . esc_html( $guardian ? $guardian->display_name : __( 'Approved guardian', 'tutor-learning-suite' ) ) . '<br><small>' . esc_html( (string) get_comment_meta( $comment->comment_ID, self::PARENT_REVIEWED_AT_META, true ) ) . '</small></td><td>';
            foreach ( array( 'publish' => __( 'Publish', 'tutor-learning-suite' ), 'reject' => __( 'Reject', 'tutor-learning-suite' ) ) as $decision => $label ) {
                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 6px 6px 0">';
                echo wp_nonce_field( 'tls_admin_feedback_' . (int) $comment->comment_ID, '_tls_admin_feedback_nonce', true, false );
                echo '<input type="hidden" name="action" value="tls_admin_feedback_decision"><input type="hidden" name="comment_id" value="' . (int) $comment->comment_ID . '"><input type="hidden" name="decision" value="' . esc_attr( $decision ) . '">';
                echo '<button class="button ' . ( 'publish' === $decision ? 'button-primary' : '' ) . '">' . esc_html( $label ) . '</button></form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    /** Final administrator decision for feedback that has a verified guardian approval. */
    public static function handle_admin_feedback_decision() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to moderate feedback.', 'tutor-learning-suite' ) );
        }
        $comment_id = isset( $_POST['comment_id'] ) && is_scalar( $_POST['comment_id'] ) ? absint( wp_unslash( $_POST['comment_id'] ) ) : 0;
        $decision = isset( $_POST['decision'] ) && is_scalar( $_POST['decision'] ) ? sanitize_key( wp_unslash( (string) $_POST['decision'] ) ) : '';
        $nonce = isset( $_POST['_tls_admin_feedback_nonce'] ) && is_scalar( $_POST['_tls_admin_feedback_nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['_tls_admin_feedback_nonce'] ) ) : '';
        if ( ! $comment_id || ! wp_verify_nonce( $nonce, 'tls_admin_feedback_' . $comment_id ) ) {
            self::redirect_admin_feedback_decision( 'denied' );
        }
        $comment = get_comment( $comment_id );
        $child_id = $comment ? absint( get_comment_meta( $comment_id, self::CHILD_META, true ) ) : 0;
        $valid = $comment
            && 'admin_pending' === (string) get_comment_meta( $comment_id, self::STAGE_META, true )
            && $child_id > 0
            && (int) $comment->user_id === $child_id
            && self::guardian_review_is_complete( $comment_id, $child_id );
        if ( ! $valid || ! in_array( $decision, array( 'publish', 'reject' ), true ) ) {
            self::redirect_admin_feedback_decision( 'denied' );
        }
        if ( 'publish' === $decision ) {
            $status = 'tutor_course_rating' === (string) $comment->comment_type ? 'approved' : '1';
            if ( ! self::write_comment_status( $comment_id, $status ) ) {
                self::redirect_admin_feedback_decision( 'denied' );
            }
            update_comment_meta( $comment_id, self::STAGE_META, 'published' );
            update_comment_meta( $comment_id, '_tls_feedback_admin_approved_at', current_time( 'mysql', true ) );
            update_comment_meta( $comment_id, '_tls_feedback_admin_approved_by', get_current_user_id() );
            if ( 'tutor_course_rating' === (string) $comment->comment_type ) {
                do_action( 'tutor_course_review_approved', $comment_id );
            }
            if ( class_exists( 'TLS_Age_Safety' ) ) {
                TLS_Age_Safety::audit( 'administrator_published_guardian_approved_feedback', $child_id, array( 'comment_id' => $comment_id ) );
            }
            self::redirect_admin_feedback_decision( 'published' );
        }
        self::write_comment_status( $comment_id, 'trash' );
        update_comment_meta( $comment_id, self::STAGE_META, 'admin_rejected' );
        update_comment_meta( $comment_id, '_tls_feedback_admin_rejected_at', current_time( 'mysql', true ) );
        if ( class_exists( 'TLS_Age_Safety' ) ) {
            TLS_Age_Safety::audit( 'administrator_rejected_guardian_approved_feedback', $child_id, array( 'comment_id' => $comment_id ) );
        }
        self::redirect_admin_feedback_decision( 'rejected' );
    }

    private static function redirect_admin_feedback_decision( $notice ) {
        $url = add_query_arg( 'tls_feedback_admin_notice', sanitize_key( $notice ), admin_url( 'admin.php?page=tls-feedback-moderation' ) );
        wp_safe_redirect( $url );
        exit;
    }

    private static function kind_label( $kind ) {
        $labels = array(
            'course_review' => __( 'Course review', 'tutor-learning-suite' ),
            'course_comment' => __( 'Course comment', 'tutor-learning-suite' ),
            'lesson_comment' => __( 'Lesson comment', 'tutor-learning-suite' ),
            'course_qna' => __( 'Course question / reply', 'tutor-learning-suite' ),
            'private_session_review' => __( 'Private tutoring review', 'tutor-learning-suite' ),
        );
        return $labels[ $kind ] ?? __( 'Learner feedback', 'tutor-learning-suite' );
    }

    private static function feedback_context_title( $comment, $kind ) {
        if ( 'private_session_review' === $kind ) {
            $tutor_id = absint( get_comment_meta( $comment->comment_ID, '_tlms_ps_private_tutor_id', true ) );
            $tutor = $tutor_id ? get_userdata( $tutor_id ) : false;
            return $tutor ? __( 'Tutor:', 'tutor-learning-suite' ) . ' ' . $tutor->display_name : __( 'Private tutoring session', 'tutor-learning-suite' );
        }
        $title = get_the_title( (int) $comment->comment_post_ID );
        return $title ? $title : __( 'Learning activity', 'tutor-learning-suite' );
    }

    /** URL for authenticated guardian decisions on the front-end dashboard. */
    private static function parent_feedback_action_url() {
        if ( function_exists( 'tutor_utils' ) ) {
            $utils = tutor_utils();
            if ( is_object( $utils ) && method_exists( $utils, 'get_tutor_dashboard_page_permalink' ) ) {
                $url = $utils->get_tutor_dashboard_page_permalink( 'tls-family' );
                if ( is_string( $url ) && '' !== $url ) {
                    return $url;
                }
            }
        }
        return home_url( '/dashboard/' );
    }

    /** Handle the parent POST before Tutor renders the dashboard page. */
    public static function maybe_handle_frontend_parent_decision() {
        if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) )
            || empty( $_POST['tls_parent_feedback_frontend'] ) ) {
            return;
        }
        self::process_parent_decision();
    }

    /** Authenticated admin-post compatibility route. */
    public static function handle_parent_decision() {
        self::process_parent_decision();
    }

    /** Shared, capability-checked decision handler for both routes. */
    private static function process_parent_decision() {
        if ( ! is_user_logged_in() ) {
            wp_safe_redirect( wp_login_url( self::parent_feedback_action_url() ) );
            exit;
        }

        $comment_id = isset( $_POST['comment_id'] ) && is_scalar( $_POST['comment_id'] ) ? absint( wp_unslash( $_POST['comment_id'] ) ) : 0;
        $decision = isset( $_POST['decision'] ) && is_scalar( $_POST['decision'] ) ? sanitize_key( wp_unslash( (string) $_POST['decision'] ) ) : '';
        $nonce = isset( $_POST['_tls_feedback_nonce'] ) && is_scalar( $_POST['_tls_feedback_nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['_tls_feedback_nonce'] ) ) : '';
        if ( ! $comment_id || ! $nonce || ! wp_verify_nonce( $nonce, 'tls_parent_feedback_' . $comment_id ) ) {
            self::redirect_parent_decision( 'expired' );
        }

        $comment = get_comment( $comment_id );
        $parent_id = get_current_user_id();
        $child_id = $comment ? absint( get_comment_meta( $comment_id, self::CHILD_META, true ) ) : 0;
        $recorded_child_id = $comment ? absint( get_comment_meta( $comment_id, self::CHILD_META, true ) ) : 0;
        $recorded_guardian_id = $comment ? absint( get_comment_meta( $comment_id, self::GUARDIAN_META, true ) ) : 0;
        $valid = $comment
            && 'guardian_pending' === (string) get_comment_meta( $comment_id, self::STAGE_META, true )
            && $recorded_guardian_id === $parent_id
            && $recorded_child_id > 0
            && (int) $comment->user_id === $child_id
            && self::can_parent_moderate_child( $parent_id, $child_id );

        if ( ! $valid || ! in_array( $decision, array( 'approve', 'reject' ), true ) ) {
            if ( class_exists( 'TLS_Age_Safety' ) && $child_id ) {
                TLS_Age_Safety::audit( 'guardian_feedback_decision_denied', $child_id, array(
                    'guardian_id' => $parent_id,
                    'comment_id'  => $comment_id,
                    'reason'      => ! $valid ? 'authorization_mismatch' : 'invalid_decision',
                ) );
            }
            self::redirect_parent_decision( 'denied' );
        }

        if ( 'approve' === $decision ) {
            // Persist the moderation state first without running WordPress transition
            // callbacks. Tutor's own rating queue expects the literal custom status
            // 'hold'; normal comments use '0'. Parent approval is never publication.
            $pending_status = 'tutor_course_rating' === $comment->comment_type ? 'hold' : '0';
            if ( ! self::write_comment_status( $comment_id, $pending_status ) ) {
                self::redirect_parent_decision( 'denied' );
            }
            update_comment_meta( $comment_id, self::STAGE_META, 'admin_pending' );
            update_comment_meta( $comment_id, self::PARENT_REVIEWED_AT_META, current_time( 'mysql', true ) );
            update_comment_meta( $comment_id, self::PARENT_REVIEWED_BY_META, $parent_id );
            if ( function_exists( 'wp_notify_moderator' ) ) {
                wp_notify_moderator( $comment_id );
            }
            if ( class_exists( 'TLS_Age_Safety' ) ) {
                TLS_Age_Safety::audit( 'guardian_approved_child_feedback_for_admin_moderation', $child_id, array( 'guardian_id' => $parent_id, 'comment_id' => $comment_id ) );
            }
        } else {
            self::write_comment_status( $comment_id, 'trash' );
            update_comment_meta( $comment_id, self::STAGE_META, 'parent_rejected' );
            update_comment_meta( $comment_id, '_tls_feedback_parent_rejected_at', current_time( 'mysql', true ) );
            if ( class_exists( 'TLS_Age_Safety' ) ) {
                TLS_Age_Safety::audit( 'guardian_rejected_child_feedback', $child_id, array( 'guardian_id' => $parent_id, 'comment_id' => $comment_id ) );
            }
        }

        self::redirect_parent_decision( 'updated' );
    }

    /** Always return the parent to the feedback queue with a clear status. */
    private static function redirect_parent_decision( $notice ) {
        $allowed = array( 'updated', 'expired', 'denied' );
        if ( ! in_array( $notice, $allowed, true ) ) {
            $notice = 'denied';
        }
        $url = add_query_arg( 'tls_feedback_notice', $notice, self::parent_feedback_action_url() );
        wp_safe_redirect( $url );
        exit;
    }
}
