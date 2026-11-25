<?php
/**
 * Tutor LMS Course Builder integration.
 *
 * Tutor LMS 3.x/4.x renders the course editor (both the frontend dashboard
 * builder and wp-admin > Course Builder) as a React app. Third party fields are
 * injected through the `window.Tutor.CourseBuilder` slot registry and their
 * values travel through the normal course save/load pipeline:
 *
 *  - load: `tutor_course_details_response` filter -> builder form default values
 *  - save: registered field names are POSTed flat -> `save_post_{post_type}` hook
 *
 * @package TutorCourseFormat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class TCF_Course_Builder
 */
class TCF_Course_Builder {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		// Tutor\Course::enqueue_course_builder_assets() is hooked here at
		// priority 10 for both the wp-admin builder (admin.php?page=create-course)
		// and the frontend dashboard builder. Priority 20 guarantees the
		// `tutor-course-builder` handle is already registered when we enqueue.
		add_action( 'tutor_before_course_builder_load', array( $this, 'enqueue' ), 20 );

		// Feed saved values back into the builder form.
		add_filter( 'tutor_course_details_response', array( $this, 'add_fields_to_details_response' ), 20, 1 );

		// Persist values. Fired by wp_insert_post()/wp_update_post() inside
		// Tutor's ajax_create_course() / ajax_update_course() handlers.
		add_action( 'save_post_' . tcf_course_post_type(), array( $this, 'save' ), 20, 3 );
	}

	/**
	 * Enqueue the field registration script.
	 *
	 * @return void
	 */
	public function enqueue() {
		if ( wp_script_is( 'tcf-course-builder', 'enqueued' ) ) {
			return;
		}

		wp_enqueue_script(
			'tcf-course-builder',
			TCF_URL . 'assets/js/tcf-course-builder.js',
			array( 'tutor-course-builder' ),
			TCF_VERSION,
			true
		);

		wp_localize_script( 'tcf-course-builder', 'tcfCourseFormat', $this->get_js_config() );
	}

	/**
	 * Config passed to the browser.
	 *
	 * @return array
	 */
	public function get_js_config() {
		/**
		 * Which Basics slot the fields are injected into.
		 *
		 * Tutor LMS only exposes two slots for the Basics section:
		 * `after_description` and `after_settings`.
		 *
		 * `after_description` renders straight below the Course Description
		 * editor and above the Options panel, which is where these fields
		 * belong. `after_settings` would push them underneath Options instead.
		 *
		 * @param string $slot `after_description` or `after_settings`.
		 */
		$slot = apply_filters( 'tcf_builder_slot', 'after_description' );

		return array(
			'slot'                       => $slot,
			'requireLive'                => (bool) apply_filters( 'tcf_require_live_fields', false ),
			'liveTypes'                  => array_values( tcf_live_types() ),
			'fields'                     => $this->get_field_definitions(),
			'hideInstructorCategoryAdd'  => tcf_instructor_category_add_hidden() && tcf_is_instructor_user(),
			'assessmentFileSizeMb'       => tcf_assessment_file_size_mb(),
			// Only gate uploads client-side for instructors — admins are
			// exempt from the server-side limit (see
			// TCF_Plugin::enforce_instructor_image_upload_limit()), so the
			// browser check must match that exemption.
			'limitInstructorImages'      => tcf_is_instructor_user(),
			// The three admin-configured limits, keyed by the same
			// 'featured' / 'profile' / 'normal' contexts the server uses.
			// We can't reliably tell client-side which context a given
			// wp.media upload belongs to (same ambiguity noted on
			// detect_instructor_image_context()), so the browser check
			// uses the *largest* of the three as its threshold: it only
			// blocks a file that is guaranteed to be rejected no matter
			// which context applies, and always defers final, precise
			// enforcement to the server-side check.
			'imageSizeLimitMb'           => max(
				tcf_image_size_limit_mb( 'featured' ),
				tcf_image_size_limit_mb( 'profile' ),
				tcf_image_size_limit_mb( 'normal' )
			),
			'i18n'                       => array(
				'required'          => __( 'This field is required', 'tutor-learning-suite' ),
				/* translators: %s: maximum size in MB. */
				'imageTooLarge'     => __( 'This image is larger than the %s MB limit set by the site administrator and was not uploaded.', 'tutor-learning-suite' ),
			),
		);
	}

	/**
	 * Field definitions handed to Tutor.CourseBuilder.Basic.registerField().
	 *
	 * @return array
	 */
	public function get_field_definitions() {
		$type_options = array();
		foreach ( tcf_course_types() as $value => $label ) {
			$type_options[] = array(
				'value' => $value,
				'label' => $label,
			);
		}

		$format_options = array();
		foreach ( tcf_course_formats() as $value => $label ) {
			$format_options[] = array(
				'value' => $value,
				'label' => $label,
			);
		}

		$definitions = array(
			array(
				'name'     => TCF_FIELD_TYPE,
				'type'     => 'radio',
				'label'    => __( 'Course Type', 'tutor-learning-suite' ),
				'options'  => $type_options,
				'priority' => 10,
			),
			array(
				'name'        => TCF_FIELD_FORMAT,
				'type'        => 'select',
				'label'       => __( 'Delivery Format', 'tutor-learning-suite' ),
				'placeholder' => __( 'Select format', 'tutor-learning-suite' ),
				'options'     => $format_options,
				'helpText'    => __( 'Live classes are delivered to a group or one-to-one.', 'tutor-learning-suite' ),
				'priority'    => 20,
			),
			array(
				'name'        => TCF_FIELD_SESSIONS,
				'type'        => 'number',
				'label'       => __( 'Sessions Number', 'tutor-learning-suite' ),
				'placeholder' => __( 'e.g. 12', 'tutor-learning-suite' ),
				'helpText'    => __( 'Total number of live sessions. Whole numbers only.', 'tutor-learning-suite' ),
				'priority'    => 30,
			),
			array(
				'name'        => TCF_FIELD_LENGTH,
				'type'        => 'number',
				'label'       => __( 'Session Length (hours)', 'tutor-learning-suite' ),
				'placeholder' => __( 'e.g. 1.5', 'tutor-learning-suite' ),
				'helpText'    => __( 'Length of one session in hours. Decimals allowed, e.g. 1.5 = 1h 30m.', 'tutor-learning-suite' ),
				'priority'    => 40,
			),
		);

		/**
		 * Filter the field definitions before they are sent to the browser.
		 *
		 * Each entry follows the shape documented by Tutor LMS:
		 * name, type, label, placeholder, options, priority, rules, helpText.
		 *
		 * @param array $definitions
		 */
		return apply_filters( 'tcf_builder_fields', $definitions );
	}

	/**
	 * Add our values to the course details response so the builder loads them.
	 *
	 * @param array $data Course details.
	 * @return array
	 */
	public function add_fields_to_details_response( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		$course_id = isset( $data['ID'] ) ? (int) $data['ID'] : 0;
		if ( ! $course_id ) {
			return $data;
		}

		$info = tcf_get_course_format_data( $course_id );

		$data[ TCF_FIELD_TYPE ]     = $info['type'];
		$data[ TCF_FIELD_FORMAT ]   = '' !== $info['format'] ? $info['format'] : 'group';
		$data[ TCF_FIELD_SESSIONS ] = $info['sessions'] > 0 ? (string) $info['sessions'] : '';
		$data[ TCF_FIELD_LENGTH ]   = $info['session_hours'] > 0 ? (string) $info['session_hours'] : '';

		return $data;
	}

	/**
	 * Persist submitted values.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @param bool    $update  Whether this is an update.
	 * @return void
	 */
	public function save( $post_id, $post, $update ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( ! $this->should_save( $post_id ) ) {
			return;
		}

		$raw = array(
			TCF_FIELD_TYPE     => isset( $_POST[ TCF_FIELD_TYPE ] ) ? wp_unslash( $_POST[ TCF_FIELD_TYPE ] ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
			TCF_FIELD_FORMAT   => isset( $_POST[ TCF_FIELD_FORMAT ] ) ? wp_unslash( $_POST[ TCF_FIELD_FORMAT ] ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
			TCF_FIELD_SESSIONS => isset( $_POST[ TCF_FIELD_SESSIONS ] ) ? wp_unslash( $_POST[ TCF_FIELD_SESSIONS ] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Missing
			TCF_FIELD_LENGTH   => isset( $_POST[ TCF_FIELD_LENGTH ] ) ? wp_unslash( $_POST[ TCF_FIELD_LENGTH ] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Missing
		);

		tcf_save_course_format( (int) $post_id, $raw );
	}

	/**
	 * Guards for the save routine.
	 *
	 * The course builder request is already nonce-checked by Tutor LMS itself
	 * (tutor_utils()->check_nonce()); the classic metabox carries our own nonce.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	protected function should_save( $post_id ) {
		$post_id = (int) $post_id;

		if ( ! $post_id ) {
			return false;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return false;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return false;
		}

		$has_metabox_nonce = isset( $_POST['tcf_metabox_nonce'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$has_builder_field = isset( $_POST[ TCF_FIELD_TYPE ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		// Nothing from this plugin in the request: leave the stored data alone.
		if ( ! $has_metabox_nonce && ! $has_builder_field ) {
			return false;
		}

		if ( $has_metabox_nonce ) {
			$nonce = isset( $_POST['tcf_metabox_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['tcf_metabox_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! wp_verify_nonce( $nonce, 'tcf_save_course_format' ) ) {
				return false;
			}
		}

		if ( ! $this->user_can_edit( $post_id ) ) {
			return false;
		}

		/**
		 * Allow third parties to veto the save.
		 *
		 * @param bool $save
		 * @param int  $post_id
		 */
		return (bool) apply_filters( 'tcf_should_save', true, $post_id );
	}

	/**
	 * Can the current user edit this course?
	 *
	 * `current_user_can( 'edit_post', $id )` alone is not enough on the
	 * `courses` post type. It is registered with `map_meta_cap => true`, so for
	 * a *published* course WordPress also demands the post type's
	 * `edit_published_posts` capability - which maps to
	 * `edit_published_tutor_courses`. Tutor never grants that capability to the
	 * `tutor_instructor` role, so an instructor editing their own published
	 * course fails the check even though Tutor's own builder happily lets them
	 * save it. The result is a silently skipped meta write.
	 *
	 * Tutor authorises that same save with
	 * `tutor_utils()->can_user_edit_course()`, so accept either answer: never
	 * stricter than core, and exactly as permissive as the course builder.
	 *
	 * @param int $post_id Course ID.
	 * @return bool
	 */
	protected function user_can_edit( $post_id ) {
		if ( current_user_can( 'edit_post', $post_id ) ) {
			return true;
		}

		$user_id = get_current_user_id();

		if ( ! $user_id || ! function_exists( 'tutor_utils' ) ) {
			return false;
		}

		$utils = tutor_utils();

		if ( ! is_object( $utils ) || ! method_exists( $utils, 'can_user_edit_course' ) ) {
			return false;
		}

		return (bool) $utils->can_user_edit_course( $user_id, (int) $post_id );
	}
}
