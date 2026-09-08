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
		$this->builder  = new TCF_Course_Builder();
		$this->metabox  = new TCF_Admin_Metabox();
		$this->frontend = new TCF_Frontend();

		$this->builder->register();
		$this->metabox->register();
		$this->frontend->register();

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_fields' ) );
	}

	/**
	 * Load translations.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'tutor-course-format', false, dirname( plugin_basename( TCF_FILE ) ) . '/languages' );
	}

	/**
	 * Expose the values on the WP REST API for the course post type.
	 *
	 * @return void
	 */
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
					'description' => __( 'Course delivery format data.', 'tutor-course-format' ),
					'type'        => 'object',
					'context'     => array( 'view', 'edit' ),
				),
			)
		);
	}
}
