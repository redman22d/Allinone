<?php
/**
 * Main plugin class.
 *
 * @package TutorCourseFormat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class TCF_Plugin
 */
final class TCF_Plugin {

	/**
	 * Singleton.
	 *
	 * @var TCF_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Course builder integration.
	 *
	 * @var TCF_Course_Builder
	 */
	public $builder;

	/**
	 * wp-admin metabox integration.
	 *
	 * @var TCF_Admin_Metabox
	 */
	public $metabox;

	/**
	 * Front end integration.
	 *
	 * @var TCF_Frontend
	 */
	public $frontend;

	/**
	 * Instructor manual enrollment management.
	 *
	 * @var TCF_Manual_Enrollment
	 */
	public $manual_enrollment;

	/**
	 * Admin settings page.
	 *
	 * @var TCF_Settings
	 */
	public $settings;

	/**
	 * Get the singleton instance.
	 *
	 * @return TCF_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {}

	/**
	 * Register everything.
	 *
	 * @return void
	 */
	public function init() {
		$this->builder           = new TCF_Course_Builder();
		$this->metabox           = new TCF_Admin_Metabox();
		$this->frontend          = new TCF_Frontend();
		$this->manual_enrollment = new TCF_Manual_Enrollment();
		$this->settings          = new TCF_Settings();

		$this->builder->register();
		$this->metabox->register();
		$this->frontend->register();
		$this->manual_enrollment->register();
		$this->settings->register();

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_fields' ) );

		// Instructor category creation restriction.
		add_filter( 'pre_insert_term', array( $this, 'block_instructor_category_creation' ), 10, 3 );

		// Enforce the global assignment/quiz upload size after Tutor saves an
		// assessment. This covers frontend and wp-admin builders.
		add_action( 'save_post_tutor_assignments', array( $this, 'enforce_assessment_file_size' ), 999, 3 );
		add_action( 'save_post_tutor_quiz', array( $this, 'enforce_assessment_file_size' ), 999, 3 );

		// Also enforce the cap when Tutor prepares assessment details for the
		// React builder, so the instructor cannot save a larger value back.
		add_filter( 'tutor_assignment_details_response', array( $this, 'cap_assignment_details' ), 999, 2 );
		add_filter( 'tutor_quiz_details_response', array( $this, 'cap_quiz_details' ), 999, 2 );

		// Apply separate administrator-controlled limits to instructor image uploads.
		//
		// Two filters are needed: classic multipart ($_FILES) uploads go
		// through wp_handle_upload() and fire wp_handle_upload_prefilter,
		// but the REST media endpoint's raw-POST-data path (used by many
		// JS media widgets, e.g. Content-Disposition binary uploads) calls
		// wp_handle_sideload() instead, which only fires
		// wp_handle_sideload_prefilter. Hooking only the first filter lets
		// oversized images through untouched whenever the client uses the
		// second path.
		add_filter( 'wp_handle_upload_prefilter', array( $this, 'enforce_instructor_image_upload_limit' ), 999 );
		add_filter( 'wp_handle_sideload_prefilter', array( $this, 'enforce_instructor_image_upload_limit' ), 999 );
	}


	/**
	 * Reject oversized instructor image uploads according to their upload context.
	 *
	 * Runs before WordPress moves the file into uploads (hooked on both the
	 * regular upload and sideload prefilters, see register()), so no
	 * oversized image is retained on disk. Admin uploads and non-instructor
	 * uploads are left untouched.
	 *
	 * @param array $file Upload array.
	 * @return array
	 */
	public function enforce_instructor_image_upload_limit( $file ) {
		if ( ! tcf_is_instructor_user() || empty( $file['tmp_name'] ) ) {
			return $file;
		}

		// The classic $_FILES-derived array (wp_handle_upload_prefilter)
		// always carries 'size'. The REST raw-POST sideload array
		// (wp_handle_sideload_prefilter, see register()) does not set
		// 'size' at all, so fall back to reading it off disk; otherwise
		// every image on that path would silently skip the check.
		$size = isset( $file['size'] ) && is_numeric( $file['size'] ) ? (float) $file['size'] : 0.0;
		if ( $size <= 0 && is_readable( $file['tmp_name'] ) ) {
			$size = (float) filesize( $file['tmp_name'] );
		}
		if ( $size <= 0 ) {
			return $file;
		}

		$type = isset( $file['type'] ) ? strtolower( (string) $file['type'] ) : '';
		$extension = strtolower( pathinfo( isset( $file['name'] ) ? $file['name'] : '', PATHINFO_EXTENSION ) );
		$image_types = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/bmp', 'image/tiff' );
		$image_exts  = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'tif', 'tiff', 'heic', 'heif', 'svg' );

		// The explicit lists above cover the common cases, but new image
		// formats keep appearing (HEIC, AVIF variants, etc.) and a missed
		// one would silently skip the size check entirely. Fall back to
		// any MIME type starting with "image/" as a catch-all.
		$is_image = in_array( $type, $image_types, true )
			|| in_array( $extension, $image_exts, true )
			|| 0 === strpos( $type, 'image/' );

		if ( ! $is_image ) {
			return $file;
		}

		$context = $this->detect_instructor_image_context();

		/**
		 * Override the detected upload context ('featured', 'profile', or
		 * 'normal') for instructor image size limits.
		 *
		 * detect_instructor_image_context() infers context by guessing at
		 * $_REQUEST field/action names, since Tutor LMS doesn't expose a
		 * reliable, documented signal for "this upload is the course
		 * featured image" vs. "this is a profile photo". If that guess
		 * turns out wrong for your installed Tutor LMS version (verify with
		 * tests/browser-image-upload-probe.js), use this filter to correct
		 * it without patching plugin code — e.g. by inspecting $_REQUEST
		 * yourself and returning the right context.
		 *
		 * @param string $context Detected context: 'featured', 'profile', or 'normal'.
		 * @param array  $file    The upload array being checked.
		 */
		$context = apply_filters( 'tcf_instructor_image_upload_context', $context, $file );
		$limit   = tcf_image_size_limit_mb( $context );
		$bytes   = $limit * 1024 * 1024;

		if ( $size > $bytes ) {
			$labels = array(
				'featured' => __( 'course featured', 'tutor-learning-suite' ),
				'profile'  => __( 'profile', 'tutor-learning-suite' ),
				'normal'   => __( 'normal', 'tutor-learning-suite' ),
			);
			$label  = isset( $labels[ $context ] ) ? $labels[ $context ] : $labels['normal'];

			$file['error'] = sprintf(
				/* translators: 1: image type label, 2: maximum size in MB. */
				__( 'This %1$s image is too large. The maximum allowed size is %2$s MB.', 'tutor-learning-suite' ),
				$label,
				rtrim( rtrim( number_format( $limit, 2, '.', '' ), '0' ), '.' )
			);
		}

		return $file;
	}

	/**
	 * Determine whether the current instructor upload is featured, profile or normal.
	 *
	 * @return string
	 */
	private function detect_instructor_image_context() {
		$post_id = 0;
		foreach ( array( 'post_id', 'course_id', 'courseId', 'ID' ) as $key ) {
			if ( isset( $_REQUEST[ $key ] ) && is_scalar( $_REQUEST[ $key ] ) ) {
				$post_id = absint( wp_unslash( $_REQUEST[ $key ] ) );
				if ( $post_id ) {
					break;
				}
			}
		}

		if ( $post_id && post_type_exists( tcf_course_post_type() ) && tcf_course_post_type() === get_post_type( $post_id ) ) {
			$action = isset( $_REQUEST['action'] ) && is_scalar( $_REQUEST['action'] ) ? strtolower( (string) wp_unslash( $_REQUEST['action'] ) ) : '';
			$field  = isset( $_REQUEST['field'] ) && is_scalar( $_REQUEST['field'] ) ? strtolower( (string) wp_unslash( $_REQUEST['field'] ) ) : '';
			$name   = isset( $_REQUEST['name'] ) && is_scalar( $_REQUEST['name'] ) ? strtolower( (string) wp_unslash( $_REQUEST['name'] ) ) : '';
			$joined = $action . ' ' . $field . ' ' . $name;

			foreach ( array( 'featured', 'thumbnail', 'course_image', 'featured_image', 'course_thumbnail' ) as $marker ) {
				if ( false !== strpos( $joined, $marker ) ) {
					return 'featured';
				}
			}
		}

		$joined_request = '';
		foreach ( array( 'action', 'field', 'name', 'type', 'context', 'upload_type' ) as $key ) {
			if ( isset( $_REQUEST[ $key ] ) && is_scalar( $_REQUEST[ $key ] ) ) {
				$joined_request .= ' ' . strtolower( (string) wp_unslash( $_REQUEST[ $key ] ) );
			}
		}

		foreach ( array( 'profile', 'avatar', 'profile_image', 'profile_photo', 'profile_picture', 'instructor_profile' ) as $marker ) {
			if ( false !== strpos( $joined_request, $marker ) ) {
				return 'profile';
			}
		}

		return 'normal';
	}

	/**
	 * Load translations.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		// Translations load once for the whole suite (see tutor-learning-suite.php).
	}

	/**
	 * Expose the values on the WP REST API for the course post type.
	 *
	 * @return void
	 */

	/**
	 * Block creation of Tutor course categories by instructors.
	 *
	 * @param string|WP_Error $term    Term name or error.
	 * @param string          $taxonomy Taxonomy slug.
	 * @param array           $args     Term arguments.
	 * @return string|WP_Error
	 */
	public function block_instructor_category_creation( $term, $taxonomy, $args = array() ) {
		if ( ! tcf_instructor_category_add_hidden() || 'course-category' !== $taxonomy ) {
			return $term;
		}

		if ( ! tcf_is_instructor_user() ) {
			return $term;
		}

		return new WP_Error(
			'tcf_instructor_category_creation_blocked',
			__( 'Instructors cannot create new course categories. Please select an existing category.', 'tutor-learning-suite' )
		);
	}

	/**
	 * Cap assignment/quiz file-size settings in a details response.
	 *
	 * @param mixed $data Assessment details.
	 * @param int   $post_id Assessment ID.
	 * @return mixed
	 */
	public function cap_assignment_details( $data, $post_id = 0 ) {
		return $this->cap_assessment_payload( $data );
	}

	/**
	 * Cap quiz file-size settings in a details response.
	 *
	 * @param mixed $data Assessment details.
	 * @param int   $post_id Assessment ID.
	 * @return mixed
	 */
	public function cap_quiz_details( $data, $post_id = 0 ) {
		return $this->cap_assessment_payload( $data );
	}

	/**
	 * Walk a Tutor assessment payload and replace every
	 * upload_file_size_limit value with the site-wide cap.
	 *
	 * @param mixed $value Payload.
	 * @return mixed
	 */
	protected function cap_assessment_payload( $value ) {
		$cap = tcf_assessment_file_size_mb();

		if ( ! is_array( $value ) ) {
			return $value;
		}

		foreach ( $value as $key => $item ) {
			$normalized_key = strtolower( (string) $key );
			$normalized_key = preg_replace( '/[^a-z0-9_]/', '_', $normalized_key );

			if ( false !== strpos( $normalized_key, 'upload_file_size_limit' ) ) {
				$value[ $key ] = $cap;
				continue;
			}

			if ( is_array( $item ) ) {
				$value[ $key ] = $this->cap_assessment_payload( $item );
			}
		}

		return $value;
	}

	/**
	 * Enforce the global cap after Tutor saves an assessment.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @param bool    $update  Whether this is an update.
	 * @return void
	 */
	public function enforce_assessment_file_size( $post_id, $post = null, $update = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$post_id = (int) $post_id;

		if ( ! $post_id ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$all_meta = get_post_meta( $post_id );
		if ( ! is_array( $all_meta ) ) {
			return;
		}

		foreach ( $all_meta as $meta_key => $meta_values ) {
			if ( ! is_array( $meta_values ) ) {
				$meta_values = array( $meta_values );
			}

			$changed = false;
			$updated_values = array();

			foreach ( $meta_values as $meta_value ) {
				$unserialized = maybe_unserialize( $meta_value );
				$capped       = $this->cap_assessment_payload( $unserialized );

				if ( $capped !== $unserialized ) {
					$changed = true;
					$updated_values[] = $capped;
				} else {
					$updated_values[] = $meta_value;
				}
			}

			if ( $changed ) {
				if ( 1 === count( $updated_values ) ) {
					update_post_meta( $post_id, $meta_key, $updated_values[0] );
				} else {
					delete_post_meta( $post_id, $meta_key );
					foreach ( $updated_values as $updated_value ) {
						add_post_meta( $post_id, $meta_key, $updated_value );
					}
				}
			}
		}
	}

	public function register_rest_fields() {
		register_rest_field(
			tcf_course_post_type(),
			'course_format',
			array(
				'get_callback'    => static function ( $post ) {
					return tcf_get_course_format_data( (int) $post['id'] );
				},
				'update_callback' => static function ( $value, $post ) {
					if ( ! is_array( $value ) ) {
						return false;
					}

					tcf_save_course_format(
						(int) $post->ID,
						array(
							TCF_FIELD_TYPE     => $value['type'] ?? '',
							TCF_FIELD_FORMAT   => $value['format'] ?? '',
							TCF_FIELD_SESSIONS => $value['sessions'] ?? 0,
							TCF_FIELD_LENGTH   => $value['session_hours'] ?? 0,
						)
					);

					return true;
				},
				'schema'          => array(
					'description' => __( 'Course delivery format data.', 'tutor-learning-suite' ),
					'type'        => 'object',
					'context'     => array( 'view', 'edit' ),
				),
			)
		);
	}
}
