<?php
/**
 * Instructor-facing manual enrollment management.
 *
 * Adds an "Enroll Students" page to the Tutor LMS frontend instructor
 * dashboard, letting an instructor search for existing WordPress users and
 * enroll/remove them from their own published courses without the student
 * going through checkout.
 *
 * Ported from the "Tutor LMS Course Types & Tutor Manual Enrollment" plugin
 * and adapted to this plugin's naming conventions (tcf_ / TCF_) so both
 * feature sets live under a single plugin.
 *
 * @package TutorCourseFormat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class TCF_Manual_Enrollment
 */
class TCF_Manual_Enrollment {

	/**
	 * Dashboard page slug.
	 *
	 * @var string
	 */
	const PAGE = 'tcf-manual-enrollment';

	/**
	 * Nonce action used for the dashboard AJAX requests.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'tcf_manual_enrollment';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'tutor_dashboard/instructor_nav_items', array( $this, 'dashboard_nav' ), 20 );
		add_filter( 'load_dashboard_template_part_from_other_location', array( $this, 'dashboard_template_path' ) );
		add_action( 'tutor_dashboard/before/wrap', array( $this, 'enqueue_dashboard_nav_icon' ) );
		add_action( 'wp_ajax_tcf_search_students', array( $this, 'ajax_search_students' ) );
		add_action( 'wp_ajax_tcf_enroll_student', array( $this, 'ajax_enroll_student' ) );
		add_action( 'wp_ajax_tcf_remove_student', array( $this, 'ajax_remove_student' ) );
	}

	/**
	 * Is the given (or current) user an instructor/administrator?
	 *
	 * @param int $user_id User ID. Defaults to the current user.
	 * @return bool
	 */
	private function is_instructor( $user_id = 0 ) {
		$user = $user_id ? get_user_by( 'id', $user_id ) : wp_get_current_user();
		if ( ! $user || ! $user->exists() ) {
			return false;
		}

		if ( in_array( 'administrator', (array) $user->roles, true ) ) {
			return true;
		}

		$role = function_exists( 'tutor' ) && isset( tutor()->instructor_role ) ? tutor()->instructor_role : 'tutor_instructor';

		return in_array( $role, (array) $user->roles, true );
	}

	/**
	 * Add the "Enroll Students" entry to the instructor dashboard menu.
	 *
	 * @param array $links Existing nav links.
	 * @return array
	 */
	public function dashboard_nav( $links ) {
		if ( ! tcf_manual_enrollment_allowed() || ! $this->is_instructor() ) {
			return $links;
		}

		$links[ self::PAGE ] = array(
			'title'    => __( 'Enroll Students', 'tutor-learning-suite' ),
			'icon'     => 'tutor-icon-user-group',
			'auth_cap' => function_exists( 'tutor' ) && isset( tutor()->instructor_role ) ? tutor()->instructor_role : 'tutor_instructor',
		);

		return $links;
	}

	/**
	 * Tutor's `tutor_dashboard/instructor_nav_items` filter (used above in
	 * dashboard_nav()) does not render an icon for custom entries the way the
	 * general nav_items filter does — Themeum's own documented example for
	 * instructor items omits the 'icon' key entirely, and testing confirms the
	 * value we pass there is simply not output. So instead of depending on the
	 * 'icon' key, we inject a small icon element ourselves via JS, targeting
	 * the "Enroll Students" link directly, on every dashboard page load.
	 *
	 * @return void
	 */
	public function enqueue_dashboard_nav_icon() {
		if ( ! tcf_manual_enrollment_allowed() || ! $this->is_instructor() ) {
			return;
		}

		wp_enqueue_script( 'tcf-dashboard-nav-icon', TCF_URL . 'assets/js/tcf-dashboard-nav-icon.js', array(), TCF_VERSION, true );
		wp_localize_script( 'tcf-dashboard-nav-icon', 'tcfNavIcon', array( 'page' => self::PAGE ) );
	}

	/**
	 * Read the currently requested dashboard page/sub-page from every place
	 * Tutor LMS may expose it.
	 *
	 * @return string[] [ page, sub_page, get_param ]
	 */
	private function requested_dashboard_page() {
		$vars = $GLOBALS['wp_query']->query_vars ?? array();
		$page = isset( $vars['tutor_dashboard_page'] ) ? (string) $vars['tutor_dashboard_page'] : '';
		$sub  = isset( $vars['tutor_dashboard_sub_page'] ) ? (string) $vars['tutor_dashboard_sub_page'] : '';
		$get  = isset( $_GET['tutor-dashboard-page'] ) ? sanitize_key( wp_unslash( $_GET['tutor-dashboard-page'] ) ) : '';

		return array( trim( $page, '/' ), trim( $sub, '/' ), trim( $get, '/' ) );
	}

	/**
	 * Is the current request for our dashboard page?
	 *
	 * @return bool
	 */
	private function is_current_dashboard_page() {
		list( $page, $sub, $get ) = $this->requested_dashboard_page();

		return self::PAGE === $page || self::PAGE === $sub || self::PAGE === $get
			|| false !== strpos( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), '/' . self::PAGE );
	}

	/**
	 * Point Tutor at our custom dashboard template when our page is requested.
	 *
	 * @param string $path Default template path.
	 * @return string
	 */
	public function dashboard_template_path( $path ) {
		if ( ! $this->is_current_dashboard_page() ) {
			return $path;
		}

		$template = TCF_DIR . 'templates/dashboard/' . self::PAGE . '.php';

		return file_exists( $template ) ? $template : $path;
	}

	/**
	 * Can this user manage (enroll/remove students for) this course?
	 *
	 * @param int $course_id Course ID.
	 * @param int $user_id   User ID. Defaults to the current user.
	 * @return bool
	 */
	private function can_manage_course( $course_id, $user_id = 0 ) {
		$course_id = absint( $course_id );
		$user_id   = $user_id ? absint( $user_id ) : get_current_user_id();
		$course    = get_post( $course_id );

		if ( ! $course || tcf_course_post_type() !== $course->post_type || 'publish' !== $course->post_status ) {
			return false;
		}

		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		if ( (int) $course->post_author === $user_id ) {
			return true;
		}

		if ( class_exists( '\Tutor\Models\CourseModel' ) && method_exists( '\Tutor\Models\CourseModel', 'is_main_instructor' ) && \Tutor\Models\CourseModel::is_main_instructor( $course_id, $user_id ) ) {
			return true;
		}

		$ids = get_posts(
			array(
				'post_type'      => tcf_course_post_type(),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'post__in'       => array( $course_id ),
				'meta_query'     => array(
					array(
						'key'     => '_tutor_instructor_course_id',
						'value'   => $course_id,
						'compare' => '=',
					),
				),
			)
		);

		return ! empty( $ids ) && (int) $course->post_author === $user_id;
	}

	/**
	 * Published courses this user can manage.
	 *
	 * @param int $user_id User ID.
	 * @return WP_Post[]
	 */
	private function get_manageable_courses( $user_id ) {
		if ( class_exists( '\Tutor\Models\CourseModel' ) && method_exists( '\Tutor\Models\CourseModel', 'get_courses_by_instructor' ) ) {
			return (array) \Tutor\Models\CourseModel::get_courses_by_instructor( $user_id, array( 'publish' ), 0, PHP_INT_MAX, false );
		}

		return get_posts(
			array(
				'post_type'      => tcf_course_post_type(),
				'post_status'    => 'publish',
				'author'         => $user_id,
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
	}

	/**
	 * Active (non-cancelled) enrollment records for a course, one per student.
	 *
	 * @param int $course_id Course ID.
	 * @return array{record: WP_Post, user: WP_User}[]
	 */
	private function get_enrolled_records( $course_id ) {
		$records = get_posts(
			array(
				'post_type'      => 'tutor_enrolled',
				'post_parent'    => absint( $course_id ),
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'ASC',
			)
		);

		$out  = array();
		$seen = array();

		foreach ( $records as $record ) {
			if ( in_array( $record->post_status, array( 'cancel', 'canceled', 'cancelled', 'trash', 'auto-draft' ), true ) ) {
				continue;
			}

			$user = get_user_by( 'id', (int) $record->post_author );
			if ( ! $user || isset( $seen[ $user->ID ] ) ) {
				continue;
			}

			$seen[ $user->ID ] = true;

			if ( $this->is_instructor( $user->ID ) ) {
				continue;
			}

			$out[] = array(
				'record' => $record,
				'user'   => $user,
			);
		}

		return $out;
	}

	/**
	 * Shared guard for the dashboard AJAX endpoints: checks the user is an
	 * authorized instructor, verifies the nonce, and confirms the course can
	 * be managed by them.
	 *
	 * Sends a JSON error and terminates the request on any failure.
	 *
	 * @return int The validated course ID.
	 */
	private function validate_ajax_request() {
		if ( ! tcf_manual_enrollment_allowed() ) {
			wp_send_json_error( array( 'message' => __( 'Manual enrollment has been disabled by the site administrator.', 'tutor-learning-suite' ) ), 403 );
		}

		if ( ! is_user_logged_in() || ! $this->is_instructor() ) {
			wp_send_json_error( array( 'message' => __( 'You are not authorized.', 'tutor-learning-suite' ) ), 403 );
		}

		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Please reload the page.', 'tutor-learning-suite' ) ), 403 );
		}

		$course_id = isset( $_POST['course_id'] ) ? absint( $_POST['course_id'] ) : 0;
		if ( ! $this->can_manage_course( $course_id ) ) {
			wp_send_json_error( array( 'message' => __( 'This course is not available for management.', 'tutor-learning-suite' ) ), 403 );
		}

		return $course_id;
	}

	/** Return a learner email only when the account is in the adult age band. */
	public function safe_student_email( $user_id, $email ) {
		if ( class_exists( 'TLS_Age_Safety' ) && TLS_Age_Safety::is_restricted_student( $user_id ) ) {
			return __( 'Protected learner account', 'tutor-learning-suite' );
		}
		return sanitize_email( $email );
	}

	/**
	 * AJAX: search WordPress users to add as students.
	 *
	 * @return void
	 */
	public function ajax_search_students() {
		$course_id = $this->validate_ajax_request();
		$term      = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';

		if ( mb_strlen( $term ) < 2 ) {
			wp_send_json_success( array( 'results' => array() ) );
		}

		$enrolled = array();
		foreach ( $this->get_enrolled_records( $course_id ) as $row ) {
			$enrolled[ $row['user']->ID ] = true;
		}

		$role  = function_exists( 'tutor' ) && isset( tutor()->instructor_role ) ? tutor()->instructor_role : 'tutor_instructor';
		$users = new WP_User_Query(
			array(
				'number'         => 20,
				'orderby'        => 'display_name',
				'order'          => 'ASC',
				'search'         => '*' . $term . '*',
				'search_columns' => array( 'user_login', 'user_email' ),
				'role__not_in'   => array( 'administrator', $role ),
				'fields'         => array( 'ID', 'display_name', 'user_login', 'user_email' ),
			)
		);

		$results = array();
		foreach ( $users->get_results() as $user ) {
			if ( isset( $enrolled[ $user->ID ] ) || $this->is_instructor( $user->ID ) ) {
				continue;
			}

			$results[] = array(
				'id'           => (int) $user->ID,
				'display_name' => $user->display_name,
				'user_login'   => $user->user_login,
				'user_email'   => $this->safe_student_email( $user->ID, $user->user_email ),
			);
		}

		wp_send_json_success( array( 'results' => $results ) );
	}

	/**
	 * AJAX: enroll a student into a course.
	 *
	 * @return void
	 */
	public function ajax_enroll_student() {
		$course_id  = $this->validate_ajax_request();
		$student_id = isset( $_POST['student_id'] ) ? absint( $_POST['student_id'] ) : 0;
		$student    = $student_id ? get_user_by( 'id', $student_id ) : false;

		if ( ! $student || $this->is_instructor( $student_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Choose a valid student account.', 'tutor-learning-suite' ) ), 400 );
		}

		if ( function_exists( 'tutor_utils' ) && method_exists( tutor_utils(), 'is_enrolled' ) && tutor_utils()->is_enrolled( $course_id, $student_id ) ) {
			wp_send_json_error( array( 'message' => __( 'This student is already enrolled.', 'tutor-learning-suite' ) ), 400 );
		}

		// Manual instructor enrollment cannot bypass guardian-managed paid checkout.
		// Restricted/unknown-age learners may be manually added only to courses
		// explicitly marked free; an absent/unknown price type is treated as paid.
		if ( class_exists( 'TLS_Age_Safety' ) && TLS_Age_Safety::is_restricted_student( $student_id ) ) {
			$price_type = (string) get_post_meta( $course_id, '_tutor_course_price_type', true );
			if ( 'free' !== $price_type ) {
				wp_send_json_error( array( 'message' => __( 'This learner needs the guardian-managed enrollment process for paid courses. Tutors cannot manually bypass it.', 'tutor-learning-suite' ) ), 403 );
			}
		}

		/**
		 * We insert the 'tutor_enrolled' post ourselves (rather than calling
		 * tutor_utils()->do_enroll()) with $wp_error = true, so any real
		 * failure reason (invalid course parent, a blocking
		 * wp_insert_post_data filter, DB error, etc.) is reported back
		 * instead of being swallowed by a generic message.
		 */
		do_action( 'tutor_before_enroll', $course_id );

		$title = sprintf(
			/* translators: %s: date and time of enrollment. */
			__( 'Course Enrolled – %s', 'tutor-learning-suite' ),
			wp_date( get_option( 'date_format' ) . ' @ ' . get_option( 'time_format' ) )
		);

		$enroll_data = apply_filters(
			'tutor_enroll_data',
			array(
				'post_type'   => 'tutor_enrolled',
				'post_title'  => $title,
				'post_status' => 'completed',
				'post_author' => $student_id,
				'post_parent' => $course_id,
			)
		);
		// Manual enrollment must always start as completed, regardless of what
		// course-purchasability logic elsewhere may have set post_status to
		// (e.g. "pending" for a paid/native-ecommerce course).
		$enroll_data['post_status'] = 'completed';

		$enrolled_id = wp_insert_post( $enroll_data, true );

		if ( is_wp_error( $enrolled_id ) ) {
			wp_send_json_error(
				array(
					/* translators: %s: underlying WordPress error message. */
					'message' => sprintf( __( 'Tutor LMS could not complete the enrollment: %s', 'tutor-learning-suite' ), $enrolled_id->get_error_message() ),
				),
				500
			);
		}

		if ( ! $enrolled_id ) {
			wp_send_json_error( array( 'message' => __( 'Tutor LMS could not complete the enrollment.', 'tutor-learning-suite' ) ), 500 );
		}

		update_user_meta( $student_id, '_is_tutor_student', time() );
		do_action( 'tutor_after_enroll', $course_id, $enrolled_id );

		$this->log_event( $course_id, 'enrolled', (int) $student->ID );
		wp_send_json_success( array( 'message' => sprintf( __( '%s was enrolled successfully.', 'tutor-learning-suite' ), $student->display_name ) ) );
	}

	/**
	 * AJAX: remove (cancel) a student's enrollment.
	 *
	 * @return void
	 */
	/** Keep the last 200 manual enrollment changes per course (who did what, when). */
	private function log_event( $course_id, $action, $student_id ) {
		$log = get_post_meta( $course_id, '_tcf_manual_enrollment_log', true );
		$log = is_array( $log ) ? $log : array();
		$log[] = array( 'time' => time(), 'by' => get_current_user_id(), 'action' => $action, 'student' => (int) $student_id );
		update_post_meta( $course_id, '_tcf_manual_enrollment_log', array_slice( $log, -200 ) );
	}

	public function ajax_remove_student() {
		$course_id     = $this->validate_ajax_request();
		$enrollment_id = isset( $_POST['enrollment_id'] ) ? absint( $_POST['enrollment_id'] ) : 0;
		$record        = $enrollment_id ? get_post( $enrollment_id ) : false;

		if ( ! $record || 'tutor_enrolled' !== $record->post_type || (int) $record->post_parent !== $course_id || in_array( $record->post_status, array( 'cancelled', 'canceled', 'cancel' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Enrollment record not found.', 'tutor-learning-suite' ) ), 404 );
		}

		if ( ! wp_update_post( array( 'ID' => $enrollment_id, 'post_status' => 'cancelled' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not remove the student.', 'tutor-learning-suite' ) ), 500 );
		}

		do_action( 'tutor_enrollment/after/cancel', $enrollment_id );

		$student = get_user_by( 'id', (int) $record->post_author );
		$this->log_event( $course_id, 'removed', (int) $record->post_author );

		wp_send_json_success(
			array(
				'message' => $student
					? sprintf( __( '%s was removed from the course.', 'tutor-learning-suite' ), $student->display_name )
					: __( 'Student removed from the course.', 'tutor-learning-suite' ),
			)
		);
	}

	/**
	 * Get the end date for a student's enrollment.
	 *
	 * Tutor LMS stores course enrollment settings in `_tutor_course_settings`.
	 * The exact expiration representation can vary between Tutor LMS versions,
	 * so this method first honours an enrollment-level end date when one exists,
	 * then checks the course settings for an explicit end date or a numeric
	 * expiration duration. Lifetime/no-expiration enrollments are displayed as
	 * "Lifetime" rather than producing a PHP warning or fatal error.
	 *
	 * @param WP_Post $record    Tutor enrollment record.
	 * @param int     $course_id Course ID.
	 * @return string Formatted end date or Lifetime/—.
	 */
	private function get_enrollment_end_date( $record, $course_id ) {
		$date_format = get_option( 'date_format' );
		$course_id   = absint( $course_id );
		$enroll_id   = ( $record instanceof WP_Post ) ? absint( $record->ID ) : 0;

		// Some Tutor/pro integrations save the calculated end date directly on
		// the enrollment record. Honour it when available.
		$enrollment_date_meta_keys = array(
			'_tutor_enrollment_end_date',
			'_tutor_enrolment_end_date',
			'_tutor_enrolled_end_date',
			'_tutor_enrollment_expiry_date',
			'_tutor_enrolment_expiry_date',
		);

		foreach ( $enrollment_date_meta_keys as $meta_key ) {
			$value = $enroll_id ? get_post_meta( $enroll_id, $meta_key, true ) : '';
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				$timestamp = is_numeric( $value ) ? (int) $value : strtotime( (string) $value );
				if ( $timestamp ) {
					return wp_date( $date_format, $timestamp );
				}
			}
		}

		$settings = $course_id ? get_post_meta( $course_id, '_tutor_course_settings', true ) : array();
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		// If Tutor stores an absolute enrollment end date, use it directly.
		foreach ( array( 'enrollment_ends_at', 'enrollment_end_at', 'enrollment_end_date' ) as $key ) {
			$value = isset( $settings[ $key ] ) ? $settings[ $key ] : '';
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				$timestamp = is_numeric( $value ) ? (int) $value : strtotime( (string) $value );
				if ( $timestamp ) {
					return wp_date( $date_format, $timestamp );
				}
			}
		}

		// Try common duration field names used by Tutor/pro extensions. A zero
		// value means lifetime/no expiration, consistent with Tutor's documented
		// behaviour for enrollment expiration.
		$duration_keys = array(
			'enrollment_expiration_days',
			'enrollment_expiry_days',
			'enrollment_duration_days',
			'enrollment_period_days',
			'course_enrollment_expiration_days',
			'course_enrollment_expiry_days',
			'enrollment_expiration',
			'enrollment_expiry',
			'enrollment_duration',
		);

		foreach ( $duration_keys as $key ) {
			$value = isset( $settings[ $key ] ) ? $settings[ $key ] : null;
			if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
				continue;
			}

			$days = (int) $value;
			if ( $days <= 0 ) {
				return __( 'Lifetime', 'tutor-learning-suite' );
			}

			$start_timestamp = $enroll_id ? (int) get_post_time( 'U', true, $enroll_id ) : 0;
			if ( ! $start_timestamp && $record instanceof WP_Post ) {
				$start_timestamp = strtotime( $record->post_date_gmt ?: $record->post_date );
			}

			if ( $start_timestamp ) {
				$end_timestamp = strtotime( '+' . $days . ' days', $start_timestamp );
				if ( $end_timestamp ) {
					return esc_html( wp_date( $date_format, $end_timestamp ) );
				}
			}
		}

		return '—';
	}

	/**
	 * Small inline "person" icon shown before a student's name in the
	 * enrolled-students table.
	 *
	 * @return string
	 */
	private function name_icon_svg() {
		return '<span class="tcf-name-icon" aria-hidden="true"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 12c2.7 0 4.9-2.2 4.9-4.9S14.7 2.2 12 2.2 7.1 4.4 7.1 7.1 9.3 12 12 12Zm0 2.5c-3.3 0-9.8 1.6-9.8 4.9v2.4h19.6v-2.4c0-3.3-6.5-4.9-9.8-4.9Z" fill="currentColor"/></svg></span>';
	}

	/**
	 * Render the "Enroll Students" dashboard page.
	 *
	 * Called by templates/dashboard/tcf-manual-enrollment.php.
	 *
	 * @return void
	 */
	public function render_manual_enrollment() {
		if ( ! tcf_manual_enrollment_allowed() ) {
			echo '<div class="tutor-alert tutor-alert-warning">' . esc_html__( 'Manual enrollment has been disabled by the site administrator.', 'tutor-learning-suite' ) . '</div>';
			return;
		}

		if ( ! $this->is_instructor() ) {
			echo '<div class="tutor-alert tutor-alert-warning">' . esc_html__( 'You are not authorized to use this page.', 'tutor-learning-suite' ) . '</div>';
			return;
		}

		wp_enqueue_style( 'tcf-manual-enrollment', TCF_URL . 'assets/css/tcf-manual-enrollment.css', array(), TCF_VERSION );
		wp_enqueue_script( 'tcf-manual-enrollment', TCF_URL . 'assets/js/tcf-manual-enrollment.js', array(), TCF_VERSION, true );
		wp_localize_script(
			'tcf-manual-enrollment',
			'tcfEnrollment',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
			)
		);

		$user_id  = get_current_user_id();
		$courses  = $this->get_manageable_courses( $user_id );
		$selected = isset( $_GET['tcf_course'] ) ? absint( $_GET['tcf_course'] ) : 0;

		if ( $selected && ! $this->can_manage_course( $selected, $user_id ) ) {
			$selected = 0;
		}

		if ( ! $selected && ! empty( $courses ) ) {
			$selected = (int) $courses[0]->ID;
		}

		$enrolled = $selected ? $this->get_enrolled_records( $selected ) : array();
		?>
		<div class="tcf-manual-enrollment tcf-dashboard-page">
			<div class="tcf-dashboard-card">
				<h2><?php esc_html_e( 'Enroll Students', 'tutor-learning-suite' ); ?></h2>
				<p class="tcf-intro"><?php esc_html_e( 'Manage the students enrolled in your published courses.', 'tutor-learning-suite' ); ?></p>
				<div class="tcf-alert tcf-alert-success tcf-live-notice" style="display:none"></div>
				<?php if ( empty( $courses ) ) : ?>
					<p class="tcf-empty"><?php esc_html_e( 'You do not have any published courses yet.', 'tutor-learning-suite' ); ?></p>
				<?php else : ?>
					<div class="tcf-course-picker">
						<div class="tcf-field">
							<label for="tcf_enroll_course"><?php esc_html_e( 'Course', 'tutor-learning-suite' ); ?></label>
							<select id="tcf_enroll_course">
								<?php foreach ( $courses as $c ) : ?>
									<option value="<?php echo esc_attr( $c->ID ); ?>" <?php selected( $selected, $c->ID ); ?>><?php echo esc_html( $c->post_title ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
					</div>
					<div class="tcf-count tcf-enrolled-count"><?php echo esc_html( count( $enrolled ) ); ?> <?php esc_html_e( 'enrolled student(s)', 'tutor-learning-suite' ); ?></div>
					<div class="tcf-table-wrap">
						<?php if ( empty( $enrolled ) ) : ?>
							<p class="tcf-empty"><?php esc_html_e( 'No students are currently enrolled in this course.', 'tutor-learning-suite' ); ?></p>
						<?php else : ?>
							<table class="tcf-table">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Student', 'tutor-learning-suite' ); ?></th>
										<th><?php esc_html_e( 'Email', 'tutor-learning-suite' ); ?></th>
										<th><?php esc_html_e( 'Enrolled', 'tutor-learning-suite' ); ?></th>
										<th><?php esc_html_e( 'Enrollment End', 'tutor-learning-suite' ); ?></th>
										<th></th>
									</tr>
								</thead>
								<tbody class="tcf-enrolled-body">
								<?php foreach ( $enrolled as $row ) : $student = $row['user']; $record = $row['record']; ?>
									<tr>
										<td>
											<div class="tcf-student-cell">
												<img class="tcf-student-avatar" src="<?php echo esc_url( get_avatar_url( $student->ID, array( 'size' => 72 ) ) ); ?>" alt="">
												<div>
													<div class="tcf-student-name"><?php echo $this->name_icon_svg(); ?><?php echo esc_html( $student->display_name ); ?></div>
													<div class="tcf-student-username">@<?php echo esc_html( $student->user_login ); ?></div>
												</div>
											</div>
										</td>
										<td><?php echo esc_html( $this->safe_student_email( $student->ID, $student->user_email ) ); ?></td>
										<td><?php echo esc_html( get_date_from_gmt( $record->post_date_gmt ?: $record->post_date, get_option( 'date_format' ) ) ); ?></td>
										<td><?php echo esc_html( $this->get_enrollment_end_date( $record, $selected ) ); ?></td>
										<td><button type="button" class="tcf-remove" data-enrollment-id="<?php echo esc_attr( $record->ID ); ?>">Remove</button></td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</div>
			<?php if ( ! empty( $courses ) ) : ?>
				<div class="tcf-dashboard-card">
					<h3><?php esc_html_e( 'Add Student', 'tutor-learning-suite' ); ?></h3>
					<p class="tcf-intro"><?php esc_html_e( 'Search by username or email address, then enroll the student in the selected course.', 'tutor-learning-suite' ); ?></p>
					<div class="tcf-search-wrap">
						<input type="search" id="tcf_student_search" class="tcf-search-input" autocomplete="off" placeholder="Search username or email…">
						<div class="tcf-search-results" aria-live="polite"></div>
					</div>
					<p class="tcf-search-help"><?php esc_html_e( 'Type at least 2 characters.', 'tutor-learning-suite' ); ?></p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
