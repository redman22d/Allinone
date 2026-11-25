<?php
/**
 * Classic wp-admin metabox.
 *
 * Tutor LMS 4.x edits courses through its React builder, but the classic
 * post.php screen for the `courses` post type is still reachable. This metabox
 * gives the same fields there and writes to the exact same post meta.
 *
 * @package TutorCourseFormat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class TCF_Admin_Metabox
 */
class TCF_Admin_Metabox {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'add_meta_boxes', array( $this, 'add_metabox' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Add the metabox to the sidebar of the course edit screen.
	 *
	 * @return void
	 */
	public function add_metabox() {
		add_meta_box(
			'tcf-course-format',
			__( 'Course Format', 'tutor-learning-suite' ),
			array( $this, 'render' ),
			tcf_course_post_type(),
			'side',
			'default'
		);
	}

	/**
	 * Enqueue assets only where the metabox renders.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public function enqueue( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || tcf_course_post_type() !== $screen->post_type ) {
			return;
		}

		wp_enqueue_style( 'tcf-admin', TCF_URL . 'assets/css/tcf-admin.css', array(), TCF_VERSION );
		wp_enqueue_script( 'tcf-admin-metabox', TCF_URL . 'assets/js/tcf-admin-metabox.js', array(), TCF_VERSION, true );
	}

	/**
	 * Render the metabox.
	 *
	 * @param WP_Post $post Post object.
	 * @return void
	 */
	public function render( $post ) {
		wp_nonce_field( 'tcf_save_course_format', 'tcf_metabox_nonce' );

		$data   = tcf_get_course_format_data( $post->ID );
		$hidden = $data['has_live'] ? '' : ' hidden';
		?>
		<div class="tcf-metabox" data-live-types="<?php echo esc_attr( wp_json_encode( array_values( tcf_live_types() ) ) ); ?>">
			<p>
				<label for="tcf-course-type"><?php esc_html_e( 'Course Type', 'tutor-learning-suite' ); ?></label>
				<select id="tcf-course-type" name="<?php echo esc_attr( TCF_FIELD_TYPE ); ?>" class="widefat">
					<?php foreach ( tcf_course_types() as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $data['type'], $value ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</p>

			<div class="tcf-live-only"<?php echo esc_attr( $hidden ); ?>>
				<p>
					<label for="tcf-course-format"><?php esc_html_e( 'Delivery Format', 'tutor-learning-suite' ); ?></label>
					<select id="tcf-course-format" name="<?php echo esc_attr( TCF_FIELD_FORMAT ); ?>" class="widefat">
						<?php foreach ( tcf_course_formats() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $data['format'], $value ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</p>

				<p>
					<label for="tcf-session-count"><?php esc_html_e( 'Sessions Number', 'tutor-learning-suite' ); ?></label>
					<input
						type="number"
						id="tcf-session-count"
						name="<?php echo esc_attr( TCF_FIELD_SESSIONS ); ?>"
						class="widefat"
						min="1"
						step="1"
						value="<?php echo esc_attr( $data['sessions'] > 0 ? $data['sessions'] : '' ); ?>"
					/>
				</p>

				<p>
					<label for="tcf-session-length"><?php esc_html_e( 'Session Length (hours)', 'tutor-learning-suite' ); ?></label>
					<input
						type="number"
						id="tcf-session-length"
						name="<?php echo esc_attr( TCF_FIELD_LENGTH ); ?>"
						class="widefat"
						min="0.25"
						step="0.25"
						value="<?php echo esc_attr( $data['session_hours'] > 0 ? $data['session_hours'] : '' ); ?>"
					/>
					<span class="description"><?php esc_html_e( 'Decimals allowed, e.g. 1.5 = 1h 30m.', 'tutor-learning-suite' ); ?></span>
				</p>
			</div>
		</div>
		<?php
	}
}
