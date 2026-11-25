<?php
/**
 * Admin settings: Settings > Course Format.
 *
 * Holds administrator controls that lets an administrator allow or block
 * the instructor-facing manual enrollment feature (see
 * TCF_Manual_Enrollment) site-wide, without needing to deactivate the whole
 * plugin.
 *
 * @package TutorCourseFormat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class TCF_Settings
 */
class TCF_Settings {

	/**
	 * Settings API option group.
	 *
	 * @var string
	 */
	const OPTION_GROUP = 'tcf_settings';

	/**
	 * Settings page slug (under Settings > Course Format).
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'tcf-settings';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( TCF_FILE ), array( $this, 'add_settings_link' ) );
		add_action( 'update_option_' . TCF_OPTION_ASSESSMENT_FILE_SIZE_MB, array( $this, 'apply_file_size_limit_to_existing_assessments' ), 10, 3 );
		add_action( 'update_option_' . TCF_OPTION_IMAGE_FEATURED_SIZE_MB, array( $this, 'normalize_updated_image_limit' ), 10, 3 );
		add_action( 'update_option_' . TCF_OPTION_IMAGE_PROFILE_SIZE_MB, array( $this, 'normalize_updated_image_limit' ), 10, 3 );
		add_action( 'update_option_' . TCF_OPTION_IMAGE_NORMAL_SIZE_MB, array( $this, 'normalize_updated_image_limit' ), 10, 3 );
	}

	/**
	 * Add the "Course Format" page under Settings.
	 *
	 * @return void
	 */
	public function add_settings_page() {
		add_options_page(
			__( 'Tutor Course Format Settings', 'tutor-learning-suite' ),
			__( 'Course Format', 'tutor-learning-suite' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register the setting, section, and field via the Settings API.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			TCF_OPTION_ALLOW_MANUAL_ENROLLMENT,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_allow_value' ),
				'default'           => '1',
			)
		);

		register_setting(
			self::OPTION_GROUP,
			TCF_OPTION_HIDE_INSTRUCTOR_CATEGORY_ADD,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_allow_value' ),
				'default'           => '1',
			)
		);

		register_setting(
			self::OPTION_GROUP,
			TCF_OPTION_ASSESSMENT_FILE_SIZE_MB,
			array(
				'type'              => 'number',
				'sanitize_callback' => array( $this, 'sanitize_file_size_value' ),
				'default'           => 10,
			)
		);

		register_setting( self::OPTION_GROUP, TCF_OPTION_IMAGE_FEATURED_SIZE_MB, array( 'type' => 'number', 'sanitize_callback' => array( $this, 'sanitize_image_featured_size_value' ), 'default' => 5 ) );
		register_setting( self::OPTION_GROUP, TCF_OPTION_IMAGE_PROFILE_SIZE_MB, array( 'type' => 'number', 'sanitize_callback' => array( $this, 'sanitize_image_profile_size_value' ), 'default' => 2 ) );
		register_setting( self::OPTION_GROUP, TCF_OPTION_IMAGE_NORMAL_SIZE_MB, array( 'type' => 'number', 'sanitize_callback' => array( $this, 'sanitize_image_size_value' ), 'default' => 3 ) );

		add_settings_section(
			'tcf_manual_enrollment_section',
			__( 'Manual Enrollment', 'tutor-learning-suite' ),
			array( $this, 'render_section_intro' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'tcf_allow_manual_enrollment',
			__( 'Instructor Manual Enrollment', 'tutor-learning-suite' ),
			array( $this, 'render_allow_field' ),
			self::PAGE_SLUG,
			'tcf_manual_enrollment_section'
		);

		add_settings_section(
			'tcf_instructor_restrictions_section',
			__( 'Instructor Course Builder Restrictions', 'tutor-learning-suite' ),
			array( $this, 'render_restrictions_intro' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'tcf_hide_instructor_category_add',
			__( 'Course Categories', 'tutor-learning-suite' ),
			array( $this, 'render_hide_category_field' ),
			self::PAGE_SLUG,
			'tcf_instructor_restrictions_section'
		);

		add_settings_field(
			'tcf_assessment_file_size_mb',
			__( 'Assignment & Quiz Maximum File Size', 'tutor-learning-suite' ),
			array( $this, 'render_file_size_field' ),
			self::PAGE_SLUG,
			'tcf_instructor_restrictions_section'
		);

		add_settings_field( 'tcf_image_featured_size_mb', __( 'Course Featured Image Maximum Size', 'tutor-learning-suite' ), array( $this, 'render_image_limit_field' ), self::PAGE_SLUG, 'tcf_instructor_restrictions_section', array( 'option' => TCF_OPTION_IMAGE_FEATURED_SIZE_MB, 'id' => 'tcf-image-featured-size-mb', 'default' => 5, 'description' => __( 'Maximum image size for the course featured/thumbnail image uploaded by instructors.', 'tutor-learning-suite' ) ) );
		add_settings_field( 'tcf_image_profile_size_mb', __( 'Instructor Profile Image Maximum Size', 'tutor-learning-suite' ), array( $this, 'render_image_limit_field' ), self::PAGE_SLUG, 'tcf_instructor_restrictions_section', array( 'option' => TCF_OPTION_IMAGE_PROFILE_SIZE_MB, 'id' => 'tcf-image-profile-size-mb', 'default' => 2, 'description' => __( 'Maximum image size for instructor profile/avatar images uploaded by instructors.', 'tutor-learning-suite' ) ) );
		add_settings_field( 'tcf_image_normal_size_mb', __( 'Normal Instructor Image Maximum Size', 'tutor-learning-suite' ), array( $this, 'render_image_limit_field' ), self::PAGE_SLUG, 'tcf_instructor_restrictions_section', array( 'option' => TCF_OPTION_IMAGE_NORMAL_SIZE_MB, 'id' => 'tcf-image-normal-size-mb', 'default' => 3, 'description' => __( 'Maximum image size for other instructor image uploads (for example normal course/content images).', 'tutor-learning-suite' ) ) );
	}

	/**
	 * Normalize the checkbox value to '1' or '0'.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return string
	 */
	public function sanitize_allow_value( $value ) {
		return '1' === (string) $value ? '1' : '0';
	}

	/**
	 * Section intro text.
	 *
	 * @return void
	 */
	public function render_section_intro() {
		echo '<p>' . esc_html__( 'Control whether instructors can manually enroll students into their own published courses from the frontend dashboard.', 'tutor-learning-suite' ) . '</p>';
	}

	/**
	 * Render the allow/disallow checkbox.
	 *
	 * @return void
	 */
	public function render_allow_field() {
		$allowed = tcf_manual_enrollment_allowed();
		?>
		<label for="tcf-allow-manual-enrollment">
			<input
				type="checkbox"
				id="tcf-allow-manual-enrollment"
				name="<?php echo esc_attr( TCF_OPTION_ALLOW_MANUAL_ENROLLMENT ); ?>"
				value="1"
				<?php checked( $allowed ); ?>
			/>
			<?php esc_html_e( 'Allow instructors to manually enroll students', 'tutor-learning-suite' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'When unchecked, the "Enroll Students" dashboard page is hidden from the instructor menu and its actions (search, enroll, remove) are blocked for everyone.', 'tutor-learning-suite' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */

	/**
	 * Sanitize the global assessment file-size value.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return float
	 */
	public function sanitize_file_size_value( $value ) {
		$value = is_numeric( $value ) ? (float) $value : 10.0;

		return max( 0.1, min( 2048.0, $value ) );
	}

	/**
	 * Render restriction section intro.
	 *
	 * @return void
	 */
	public function render_restrictions_intro() {
		echo '<p>' . esc_html__( 'Control instructor access to course categories and assessment file-size settings from one place.', 'tutor-learning-suite' ) . '</p>';
	}

	/**
	 * Render category restriction checkbox.
	 *
	 * @return void
	 */
	public function render_hide_category_field() {
		$hidden = tcf_instructor_category_add_hidden();
		?>
		<label for="tcf-hide-instructor-category-add">
			<input
				type="checkbox"
				id="tcf-hide-instructor-category-add"
				name="<?php echo esc_attr( TCF_OPTION_HIDE_INSTRUCTOR_CATEGORY_ADD ); ?>"
				value="1"
				<?php checked( $hidden ); ?>
			/>
			<?php esc_html_e( 'Hide "Add Category" for instructors in the course builder', 'tutor-learning-suite' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'Instructors can still select categories created by an administrator. Category creation is also blocked server-side for instructors.', 'tutor-learning-suite' ); ?>
		</p>
		<?php
	}

	/**
	 * Render global assessment file-size field.
	 *
	 * @return void
	 */
	public function render_file_size_field() {
		$value = tcf_assessment_file_size_mb();
		?>
		<input
			type="number"
			id="tcf-assessment-file-size-mb"
			name="<?php echo esc_attr( TCF_OPTION_ASSESSMENT_FILE_SIZE_MB ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			min="0.1"
			max="2048"
			step="0.1"
			class="small-text"
		/>
		<span><?php esc_html_e( 'MB', 'tutor-learning-suite' ); ?></span>
		<p class="description">
			<?php esc_html_e( 'This single limit applies to student submission files for every Tutor LMS Assignment and Quiz. Instructors cannot increase it per assessment.', 'tutor-learning-suite' ); ?>
		</p>
		<?php
	}


	/**
	 * Apply a newly saved global file-size cap to existing Tutor
	 * assignments and quizzes immediately.
	 *
	 * @param mixed $old_value Previous option value.
	 * @param mixed $value     New option value.
	 * @param string $option   Option name.
	 * @return void
	 */
	public function apply_file_size_limit_to_existing_assessments( $old_value, $value, $option ) {
		$limit = is_numeric( $value ) ? (float) $value : tcf_assessment_file_size_mb();
		$limit = max( 0.1, min( 2048.0, $limit ) );

		foreach ( array( 'tutor_assignments', 'tutor_quiz' ) as $post_type ) {
			$ids = get_posts(
				array(
					'post_type'      => $post_type,
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);

			foreach ( $ids as $post_id ) {
				$all_meta = get_post_meta( (int) $post_id );
				if ( ! is_array( $all_meta ) ) {
					continue;
				}

				foreach ( $all_meta as $meta_key => $meta_values ) {
					$meta_values = is_array( $meta_values ) ? $meta_values : array( $meta_values );
					foreach ( $meta_values as $meta_value ) {
						$data = maybe_unserialize( $meta_value );
						$capped = $this->cap_nested_file_size_values( $data, $limit );

						if ( $capped !== $data ) {
							update_post_meta( (int) $post_id, $meta_key, $capped );
							break;
						}
					}
				}
			}
		}
	}

	/**
	 * Recursively replace Tutor's assessment upload_file_size_limit keys.
	 *
	 * Kept local to the settings class so changing the global setting does not
	 * depend on the order of Tutor's save hooks.
	 *
	 * @param mixed $value Assessment metadata.
	 * @param float $limit Global MB cap.
	 * @return mixed
	 */
	private function cap_nested_file_size_values( $value, $limit ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		foreach ( $value as $key => $item ) {
			$normalized = strtolower( preg_replace( '/[^a-z0-9_]/', '_', (string) $key ) );

			if ( false !== strpos( $normalized, 'upload_file_size_limit' ) ) {
				$value[ $key ] = $limit;
				continue;
			}

			if ( is_array( $item ) ) {
				$value[ $key ] = $this->cap_nested_file_size_values( $item, $limit );
			}
		}

		return $value;
	}


	/**
	 * Sanitize image size settings.
	 *
	 * Register_setting()'s sanitize_callback only ever receives the raw
	 * value, not the option name, so on invalid input this used to fall
	 * back to the "normal" field's default (3.0) even for the featured
	 * and profile fields. sanitize_image_featured_size_value() and
	 * sanitize_image_profile_size_value() are thin wrappers that pass
	 * their field's own fallback through $default.
	 *
	 * @param mixed $value   Raw value.
	 * @param float $default Fallback used when $value is not numeric.
	 * @return float
	 */
	public function sanitize_image_size_value( $value, $default = 3.0 ) {
		$value = is_numeric( $value ) ? (float) $value : (float) $default;
		return max( 0.1, min( 2048.0, $value ) );
	}

	/**
	 * Sanitize the featured-image size setting (falls back to 5.0).
	 *
	 * @param mixed $value Raw value.
	 * @return float
	 */
	public function sanitize_image_featured_size_value( $value ) {
		return $this->sanitize_image_size_value( $value, 5.0 );
	}

	/**
	 * Sanitize the profile-image size setting (falls back to 2.0).
	 *
	 * @param mixed $value Raw value.
	 * @return float
	 */
	public function sanitize_image_profile_size_value( $value ) {
		return $this->sanitize_image_size_value( $value, 2.0 );
	}

	/**
	 * Keep values valid if another component updates the option directly.
	 *
	 * @param mixed $old_value Old value.
	 * @param mixed $value New value.
	 * @param string $option Option name.
	 * @return void
	 */
	public function normalize_updated_image_limit( $old_value, $value, $option ) {
		$defaults   = array(
			TCF_OPTION_IMAGE_FEATURED_SIZE_MB => 5.0,
			TCF_OPTION_IMAGE_PROFILE_SIZE_MB  => 2.0,
			TCF_OPTION_IMAGE_NORMAL_SIZE_MB   => 3.0,
		);
		$default    = isset( $defaults[ $option ] ) ? $defaults[ $option ] : 3.0;
		$normalized = $this->sanitize_image_size_value( $value, $default );
		if ( (string) $value !== (string) $normalized ) {
			remove_action( 'update_option_' . $option, array( $this, 'normalize_updated_image_limit' ), 10 );
			update_option( $option, $normalized );
			add_action( 'update_option_' . $option, array( $this, 'normalize_updated_image_limit' ), 10, 3 );
		}
	}

	/**
	 * Render an image-size limit field.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public function render_image_limit_field( $args ) {
		$option = isset( $args['option'] ) ? $args['option'] : TCF_OPTION_IMAGE_NORMAL_SIZE_MB;
		$id = isset( $args['id'] ) ? $args['id'] : sanitize_key( $option );
		$default = isset( $args['default'] ) ? (float) $args['default'] : 3.0;
		$value = get_option( $option, $default );
		$value = $this->sanitize_image_size_value( $value );
		?>
		<input type="number" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $option ); ?>" value="<?php echo esc_attr( $value ); ?>" min="0.1" max="2048" step="0.1" class="small-text" />
		<span><?php esc_html_e( 'MB', 'tutor-learning-suite' ); ?></span>
		<p class="description"><?php echo esc_html( $args['description'] ?? '' ); ?></p>
		<?php
	}


	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Tutor LMS Course Format', 'tutor-learning-suite' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Add a "Settings" link on the Plugins screen row.
	 *
	 * @param string[] $links Existing action links.
	 * @return string[]
	 */
	public function add_settings_link( $links ) {
		$url  = admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
		$link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'tutor-learning-suite' ) . '</a>';

		array_unshift( $links, $link );

		return $links;
	}
}
