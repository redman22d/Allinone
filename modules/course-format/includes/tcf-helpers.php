<?php
/**
 * Helper functions.
 *
 * @package TutorCourseFormat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Is Tutor LMS loaded?
 *
 * @return bool
 */
function tcf_is_tutor_active() {
	return defined( 'TUTOR_VERSION' ) && function_exists( 'tutor' );
}

/**
 * Course post type used by Tutor LMS.
 *
 * @return string
 */
function tcf_course_post_type() {
	if ( function_exists( 'tutor' ) && tutor()->course_post_type ) {
		return tutor()->course_post_type;
	}

	return 'courses';
}

/**
 * Course type options.
 *
 * @return array<string,string> value => label
 */
function tcf_course_types() {
	$types = array(
		'prerecorded' => __( 'Prerecorded', 'tutor-learning-suite' ),
		'direct'      => __( 'Direct', 'tutor-learning-suite' ),
		'hybrid'      => __( 'Hybrid (Prerecorded + Direct)', 'tutor-learning-suite' ),
	);

	/**
	 * Filter the available course types.
	 *
	 * @param array $types value => label.
	 */
	return apply_filters( 'tcf_course_types', $types );
}

/**
 * Delivery format options (only relevant for Direct / Hybrid).
 *
 * @return array<string,string> value => label
 */
function tcf_course_formats() {
	$formats = array(
		'group'      => __( 'Group study', 'tutor-learning-suite' ),
		'one_to_one' => __( '1v1 study', 'tutor-learning-suite' ),
	);

	/**
	 * Filter the available delivery formats.
	 *
	 * @param array $formats value => label.
	 */
	return apply_filters( 'tcf_course_formats', $formats );
}

/**
 * Course type wording used on the public course page.
 *
 * Deliberately separate from tcf_course_types(): those are the builder radio
 * labels, these are the short phrases shown to students next to the icon.
 *
 * @return array<string,string> value => label
 */
function tcf_display_type_labels() {
	$labels = array(
		'prerecorded' => __( 'Prerecorded lessons', 'tutor-learning-suite' ),
		'direct'      => __( 'Direct lessons', 'tutor-learning-suite' ),
		'hybrid'      => __( 'Direct lessons and prerecorded', 'tutor-learning-suite' ),
	);

	/**
	 * Filter the course type wording shown on the public course page.
	 *
	 * @param array $labels value => label.
	 */
	return apply_filters( 'tcf_display_type_labels', $labels );
}

/**
 * Public wording for one course type.
 *
 * @param string $type Course type value.
 * @return string
 */
function tcf_get_type_display_label( $type ) {
	$labels = tcf_display_type_labels();

	/**
	 * Filter the course type wording shown on the public course page.
	 *
	 * @param string $label Resolved label.
	 * @param string $type  Course type value.
	 */
	return apply_filters(
		'tcf_display_type_label',
		isset( $labels[ $type ] ) ? $labels[ $type ] : $type,
		$type
	);
}

/**
 * Types that carry live sessions.
 *
 * @return string[]
 */
function tcf_live_types() {	/**
	 * Filter which course types show the live-session fields.
	 *
	 * @param string[] $types
	 */
	return apply_filters( 'tcf_live_types', array( 'direct', 'hybrid' ) );
}

/**
 * Get the raw course type for a course.
 *
 * Falls back to "prerecorded" when nothing is stored.
 *
 * @param int|WP_Post|null $course_id Course ID. Defaults to the current post.
 * @return string
 */
function tcf_get_course_type( $course_id = null ) {
	$course_id = tcf_resolve_course_id( $course_id );
	if ( ! $course_id ) {
		return 'prerecorded';
	}

	$type = get_post_meta( $course_id, TCF_META_TYPE, true );
	$type = is_string( $type ) ? $type : '';

	if ( ! array_key_exists( $type, tcf_course_types() ) ) {
		$type = 'prerecorded';
	}

	return $type;
}

/**
 * Get the delivery format (group / one_to_one).
 *
 * @param int|WP_Post|null $course_id Course ID.
 * @return string Empty string when the course has no live sessions.
 */
function tcf_get_course_format( $course_id = null ) {
	$course_id = tcf_resolve_course_id( $course_id );
	if ( ! $course_id || ! tcf_course_has_live_sessions( $course_id ) ) {
		return '';
	}

	$format = get_post_meta( $course_id, TCF_META_FORMAT, true );
	$format = is_string( $format ) ? $format : '';

	if ( ! array_key_exists( $format, tcf_course_formats() ) ) {
		$format = 'group';
	}

	return $format;
}

/**
 * Get the number of sessions.
 *
 * @param int|WP_Post|null $course_id Course ID.
 * @return int 0 when not set.
 */
function tcf_get_session_count( $course_id = null ) {
	$course_id = tcf_resolve_course_id( $course_id );
	if ( ! $course_id || ! tcf_course_has_live_sessions( $course_id ) ) {
		return 0;
	}

	return (int) get_post_meta( $course_id, TCF_META_SESSIONS, true );
}

/**
 * Get the session length in hours.
 *
 * @param int|WP_Post|null $course_id Course ID.
 * @return float 0 when not set.
 */
function tcf_get_session_length( $course_id = null ) {
	$course_id = tcf_resolve_course_id( $course_id );
	if ( ! $course_id || ! tcf_course_has_live_sessions( $course_id ) ) {
		return 0.0;
	}

	return (float) get_post_meta( $course_id, TCF_META_LENGTH, true );
}

/**
 * Does this course run live sessions (Direct or Hybrid)?
 *
 * @param int|WP_Post|null $course_id Course ID.
 * @return bool
 */
function tcf_course_has_live_sessions( $course_id = null ) {
	return in_array( tcf_get_course_type( $course_id ), tcf_live_types(), true );
}

/**
 * All custom field values as a flat array, ready for REST / templates.
 *
 * @param int|WP_Post|null $course_id Course ID.
 * @return array
 */
function tcf_get_course_format_data( $course_id = null ) {
	$type    = tcf_get_course_type( $course_id );
	$live    = tcf_course_has_live_sessions( $course_id );
	$formats = tcf_course_formats();
	$types   = tcf_course_types();

	return array(
		'type'          => $type,
		'type_label'    => isset( $types[ $type ] ) ? $types[ $type ] : $type,
		'has_live'      => $live,
		'format'        => $live ? tcf_get_course_format( $course_id ) : '',
		'format_label'  => $live && tcf_get_course_format( $course_id ) ? $formats[ tcf_get_course_format( $course_id ) ] : '',
		'sessions'      => $live ? tcf_get_session_count( $course_id ) : 0,
		'session_hours' => $live ? tcf_get_session_length( $course_id ) : 0.0,
	);
}

/**
 * Human readable session length, e.g. "1.5" or "1.5 h".
 *
 * @param float $hours Hours.
 * @return string
 */
function tcf_format_hours( $hours ) {
	$hours = (float) $hours;
	if ( $hours <= 0 ) {
		return '';
	}

	// Trim trailing zeros: 2.00 -> 2, 1.50 -> 1.5.
	$formatted = rtrim( rtrim( number_format( $hours, 2, '.', '' ), '0' ), '.' );

	/**
	 * Filter how the session length is displayed.
	 *
	 * @param string $formatted Number without unit.
	 * @param float  $hours     Raw value.
	 */
	return apply_filters( 'tcf_format_hours', $formatted, $hours );
}

/**
 * Sanitize a course type value.
 *
 * @param mixed $value Raw value.
 * @return string
 */
function tcf_sanitize_course_type( $value ) {
	$value = is_scalar( $value ) ? trim( (string) $value ) : '';

	return array_key_exists( $value, tcf_course_types() ) ? $value : 'prerecorded';
}

/**
 * Sanitize a delivery format value.
 *
 * @param mixed $value Raw value.
 * @return string
 */
function tcf_sanitize_course_format( $value ) {
	$value = is_scalar( $value ) ? trim( (string) $value ) : '';

	return array_key_exists( $value, tcf_course_formats() ) ? $value : '';
}

/**
 * Sanitize the sessions number (natural number).
 *
 * @param mixed $value Raw value.
 * @return int
 */
function tcf_sanitize_session_count( $value ) {
	if ( ! is_scalar( $value ) ) {
		return 0;
	}

	$value = trim( (string) $value );
	if ( '' === $value || ! is_numeric( $value ) ) {
		return 0;
	}

	$count = (int) floor( (float) $value );

	/** This filter controls the maximum number of sessions accepted. */
	$max = (int) apply_filters( 'tcf_max_session_count', 999 );

	return max( 0, min( $count, $max ) );
}

/**
 * Sanitize the session length in hours.
 *
 * @param mixed $value Raw value.
 * @return float
 */
function tcf_sanitize_session_length( $value ) {
	if ( ! is_scalar( $value ) ) {
		return 0.0;
	}

	$value = trim( str_replace( ',', '.', (string) $value ) );
	if ( '' === $value || ! is_numeric( $value ) ) {
		return 0.0;
	}

	$hours = round( (float) $value, 2 );

	/** This filter controls the maximum session length in hours. */
	$max = (float) apply_filters( 'tcf_max_session_length', 24 );

	return max( 0, min( $hours, $max ) );
}

/**
 * Persist all four fields for a course.
 *
 * When the course type has no live sessions the dependent meta is removed so
 * stale data never leaks into the front end or the REST response.
 *
 * @param int   $course_id Course ID.
 * @param array $raw       Raw values keyed by TCF_FIELD_* constants.
 * @return array The sanitized values that were stored.
 */
function tcf_save_course_format( $course_id, array $raw ) {
	$course_id = (int) $course_id;
	if ( ! $course_id ) {
		return array();
	}

	$type   = tcf_sanitize_course_type( $raw[ TCF_FIELD_TYPE ] ?? '' );
	$live   = in_array( $type, tcf_live_types(), true );
	$format = $live ? tcf_sanitize_course_format( $raw[ TCF_FIELD_FORMAT ] ?? '' ) : '';
	$count  = $live ? tcf_sanitize_session_count( $raw[ TCF_FIELD_SESSIONS ] ?? 0 ) : 0;
	$length = $live ? tcf_sanitize_session_length( $raw[ TCF_FIELD_LENGTH ] ?? 0 ) : 0.0;

	// A live course without an explicit format defaults to "Group".
	if ( $live && '' === $format ) {
		$format = 'group';
	}

	update_post_meta( $course_id, TCF_META_TYPE, $type );

	if ( $live ) {
		update_post_meta( $course_id, TCF_META_FORMAT, $format );
		update_post_meta( $course_id, TCF_META_SESSIONS, $count );
		update_post_meta( $course_id, TCF_META_LENGTH, $length );
	} else {
		delete_post_meta( $course_id, TCF_META_FORMAT );
		delete_post_meta( $course_id, TCF_META_SESSIONS );
		delete_post_meta( $course_id, TCF_META_LENGTH );
	}

	$saved = array(
		'type'    => $type,
		'format'  => $format,
		'session' => $count,
		'length'  => $length,
	);

	/**
	 * Fires after the course format meta has been saved.
	 *
	 * @param int   $course_id Course ID.
	 * @param array $saved     Sanitized values.
	 * @param array $raw       Raw submitted values.
	 */
	do_action( 'tcf_course_format_saved', $course_id, $saved, $raw );

	return $saved;
}

/**
 * Resolve a course ID argument.
 *
 * @param int|WP_Post|null $course_id Course ID, post object or null.
 * @return int
 */
function tcf_resolve_course_id( $course_id = null ) {
	if ( null === $course_id ) {
		return (int) get_the_ID();
	}

	if ( $course_id instanceof WP_Post ) {
		return (int) $course_id->ID;
	}

	return (int) $course_id;
}

/**
 * Is instructor manual enrollment currently allowed?
 *
 * Controlled by the admin-only switch at Settings > Course Format. Defaults
 * to allowed so existing installs keep working after an update.
 *
 * @return bool
 */
function tcf_manual_enrollment_allowed() {
	$value = get_option( TCF_OPTION_ALLOW_MANUAL_ENROLLMENT, '1' );

	/**
	 * Filter whether instructors may manually enroll students.
	 *
	 * @param bool $allowed Whether the admin switch is enabled.
	 */
	return (bool) apply_filters( 'tcf_manual_enrollment_allowed', '1' === (string) $value );
}

/**
 * Should the custom fields render on the front end for this course?
 *
 * @param int $course_id Course ID.
 * @return bool
 */
function tcf_should_display( $course_id ) {
	$course_id = (int) $course_id;
	if ( ! $course_id ) {
		return false;
	}

	/**
	 * Filter whether the course format rows are displayed on the course page.
	 *
	 * @param bool $display
	 * @param int  $course_id
	 */
	return (bool) apply_filters( 'tcf_display_on_course_page', true, $course_id );
}


/**
 * Whether instructors are prevented from creating new course categories in
 * the Tutor course builder.
 *
 * @return bool
 */
function tcf_instructor_category_add_hidden() {
	$value = get_option( TCF_OPTION_HIDE_INSTRUCTOR_CATEGORY_ADD, '1' );

	return (bool) apply_filters(
		'tcf_instructor_category_add_hidden',
		'1' === (string) $value
	);
}

/**
 * Whether the current user is a Tutor instructor.
 *
 * @param int $user_id User ID. Defaults to the current user.
 * @return bool
 */
function tcf_is_instructor_user( $user_id = 0 ) {
	$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
	if ( ! $user_id ) {
		return false;
	}

	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return false;
	}

	$role = 'tutor_instructor';
	if ( function_exists( 'tutor' ) && isset( tutor()->instructor_role ) && tutor()->instructor_role ) {
		$role = (string) tutor()->instructor_role;
	}

	return in_array( $role, (array) $user->roles, true );
}

/**
 * Get the site-wide assignment/quiz submission file-size cap in MB.
 *
 * A positive integer/decimal is required. Default is 10 MB.
 *
 * @return float
 */
function tcf_assessment_file_size_mb() {
	$value = get_option( TCF_OPTION_ASSESSMENT_FILE_SIZE_MB, '10' );
	$value = is_numeric( $value ) ? (float) $value : 10.0;
	$value = max( 0.1, $value );

	return (float) apply_filters( 'tcf_assessment_file_size_mb', $value );
}

/**
 * Get an image upload size limit in MB.
 *
 * @param string $type featured|profile|normal.
 * @return float
 */
function tcf_image_size_limit_mb( $type = 'normal' ) {
	$map = array(
		'featured' => TCF_OPTION_IMAGE_FEATURED_SIZE_MB,
		'profile'  => TCF_OPTION_IMAGE_PROFILE_SIZE_MB,
		'normal'   => TCF_OPTION_IMAGE_NORMAL_SIZE_MB,
	);
	$defaults = array(
		'featured' => 5.0,
		'profile'  => 2.0,
		'normal'   => 3.0,
	);
	$type = isset( $map[ $type ] ) ? $type : 'normal';
	$value = get_option( $map[ $type ], (string) $defaults[ $type ] );
	$value = is_numeric( $value ) ? (float) $value : $defaults[ $type ];
	$value = max( 0.1, min( 2048.0, $value ) );
	return (float) apply_filters( 'tcf_image_size_limit_mb', $value, $type );
}
