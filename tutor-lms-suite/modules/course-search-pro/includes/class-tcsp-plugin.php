<?php
/**
 * Main bootstrap: wires up all modules and shared constants.
 *
 * @package Tutor_Course_Search_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TCSP_Plugin {

	/**
	 * Tutor LMS course custom post type.
	 */
	const COURSE_CPT = 'courses';

	/**
	 * Tutor LMS price-type meta + values (from Tutor\Models\CourseModel).
	 */
	const PRICE_TYPE_META = '_tutor_course_price_type';
	const PRICE_META      = 'tutor_course_price';
	const SALE_PRICE_META = 'tutor_course_sale_price';
	const PRICE_TYPE_PAID = 'paid';
	const PRICE_TYPE_FREE = 'free';

	/**
	 * Post meta values used for sponsored course assignments.
	 */
	const PROMOTED_META = '_tcsp_promoted_course';
	const PROMOTION_TIER_META = '_tcsp_promotion_tier';
	const PROMOTION_START_META = '_tcsp_promotion_start';
	const PROMOTION_END_META = '_tcsp_promotion_end';

	/**
	 * Comment type + rating meta keys used by Tutor LMS course reviews.
	 */
	const RATING_COMMENT_TYPE = 'tutor_course_rating';
	const RATING_META_KEY     = 'tutor_rating';

	/**
	 * Tutor LMS stores each enrollment as its own post (post_type =
	 * tutor_enrolled, post_parent = the course ID, post_author = the
	 * student). A confirmed/active enrollment has post_status 'completed'.
	 * If your Tutor LMS setup uses different enrollment statuses (e.g. you
	 * also want to count 'pending' or a custom LMS enrollment flow), adjust
	 * ENROLLMENT_STATUS accordingly.
	 */
	const ENROLLMENT_CPT    = 'tutor_enrolled';
	const ENROLLMENT_STATUS = 'completed';

	private static $instance = null;

	/** @var TCSP_Settings */
	public $settings;

	/** @var TCSP_Sponsorship_Manager */
	public $sponsorship_manager;

	/** @var TCSP_Query */
	public $query;

	/** @var TCSP_Filters_UI */
	public $filters_ui;

	/** @var TCSP_Suggest */
	public $suggest;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'admin_notices', array( $this, 'missing_tutor_notice' ) );
		add_action( 'plugins_loaded', array( $this, 'init' ), 20 );
	}

	public function init() {
		$this->settings   = new TCSP_Settings();
		$this->sponsorship_manager = new TCSP_Sponsorship_Manager( $this->settings );
		$this->query      = new TCSP_Query( $this->settings );
		$this->filters_ui = new TCSP_Filters_UI( $this->settings, $this->query );
		$this->suggest    = new TCSP_Suggest( $this->settings );

		$this->settings->register();
		$this->sponsorship_manager->register();
		add_action( 'wp_ajax_tcsp_toggle_wishlist', array( $this, 'ajax_toggle_wishlist' ) );

		if ( ! self::tutor_active() ) {
			return;
		}

		$this->query->register();
		$this->filters_ui->register();
		$this->suggest->register();
		$this->register_sponsored_card_hooks();
		$this->register_verified_card_hooks();
		$this->register_course_format_card_hooks();
	}

	/**
	 * Tutor exposes slightly different card hooks across its themes/templates.
	 * Register on the thumbnail and title boundaries so the sticker remains
	 * visible without requiring a theme override.
	 */

	/**
	 * Instant wishlist toggle for sponsored carousel cards. Tutor stores one
	 * course ID per usermeta row under _tutor_course_wishlist; using the same
	 * storage keeps the result compatible with Tutor's native wishlist page and
	 * tutor_utils()->is_wishlisted().
	 */
	public function ajax_toggle_wishlist() {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Please log in first.', 'tutor-course-search-pro' ) ), 401 );
		}
		check_ajax_referer( 'tcsp_wishlist', 'nonce' );

		$course_id = isset( $_POST['course_id'] ) ? absint( $_POST['course_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! $course_id || TCSP_Plugin::COURSE_CPT !== get_post_type( $course_id ) || 'publish' !== get_post_status( $course_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid course.', 'tutor-course-search-pro' ) ), 400 );
		}

		$user_id = get_current_user_id();
		$meta_key = '_tutor_course_wishlist';
		global $wpdb;
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT umeta_id FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s AND meta_value = %d LIMIT 1", $user_id, $meta_key, $course_id ) );
		if ( $exists ) {
			$wpdb->delete( $wpdb->usermeta, array( 'umeta_id' => (int) $exists ), array( '%d' ) );
			$wishlisted = false;
		} else {
			add_user_meta( $user_id, $meta_key, $course_id, false );
			$wishlisted = true;
		}

		wp_send_json_success( array( 'wishlisted' => $wishlisted ) );
	}

	private function register_sponsored_card_hooks() {
		$hooks = array(
			'tutor_course/loop/before_thumbnail',
			'tutor_course/loop/after_thumbnail',
			'tutor_course/loop/before_title',
			'tutor_course/loop/after_title',
		);
		foreach ( $hooks as $hook ) {
			add_action( $hook, array( $this, 'render_sponsored_sticker' ), 10, 1 );
		}
	}

	private function register_verified_card_hooks() {
		foreach ( array( 'tutor_course/loop/before_thumbnail', 'tutor_course/loop/after_thumbnail', 'tutor_course/loop/before_title', 'tutor_course/loop/after_title' ) as $hook ) {
			add_action( $hook, array( $this, 'render_verified_sticker' ), 12, 1 );
		}
	}

	private function register_course_format_card_hooks() {
		// Tutor's current course loop renders the thumbnail through
		// tutor_course_loop_thumbnail(), which exposes the thumbnail HTML through
		// the tutor_course_loop_thumbnail filter. Injecting the badge into that
		// HTML guarantees it is positioned relative to the actual image wrapper.
		add_filter( 'tutor_course_loop_thumbnail', array( $this, 'inject_course_format_sticker' ), 20, 2 );
	}

	public function inject_course_format_sticker( $html, $course_id = 0 ) {
		$course_id = absint( $course_id );
		if ( ! $course_id ) {
			$course_id = get_the_ID();
		}
		if ( ! $course_id || self::COURSE_CPT !== get_post_type( $course_id ) ) {
			return $html;
		}

		$sticker = $this->get_course_format_sticker_markup( $course_id );
		if ( ! $sticker ) {
			return $html;
		}

		// Inject immediately inside the thumbnail wrapper. This works with
		// Tutor's standard markup and remains relative to the featured image.
		$pattern = '/(<div\b[^>]*class=[\"\'][^\"\']*tutor-course-thumbnail[^\"\']*[\"\'][^>]*>)/i';
		$replaced = preg_replace( $pattern, '$1' . $sticker, $html, 1, $count );
		return $count ? $replaced : $html;
	}

	public function render_course_format_sticker( $course_id = 0 ) {
		// Backward-compatible public renderer for integrations that call the
		// method directly. Standard Tutor cards use inject_course_format_sticker().
		$course_id = absint( $course_id ?: get_the_ID() );
		$markup = $this->get_course_format_sticker_markup( $course_id );
		if ( $markup ) {
			echo $markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup is escaped in helper.
		}
	}

	private function get_course_format_sticker_markup( $course_id ) {
		$course_id = absint( $course_id );
		if ( ! $course_id || self::COURSE_CPT !== get_post_type( $course_id ) ) {
			return '';
		}

		$type = function_exists( 'tcf_get_course_type' ) ? tcf_get_course_type( $course_id ) : get_post_meta( $course_id, '_tcf_course_type', true );
		$type = is_string( $type ) ? sanitize_key( $type ) : '';
		if ( '' === $type ) {
			$type = 'prerecorded';
		}

		$format = function_exists( 'tcf_get_course_format' ) ? tcf_get_course_format( $course_id ) : get_post_meta( $course_id, '_tcf_course_format', true );
		$format = is_string( $format ) ? sanitize_key( $format ) : '';

		$labels = array(
			'prerecorded' => __( 'Prerecorded', 'tutor-course-search-pro' ),
			'direct'      => __( 'Direct Lessons', 'tutor-course-search-pro' ),
			'hybrid'      => __( 'Hybrid', 'tutor-course-search-pro' ),
		);
		$formats = array(
			'group'      => __( 'Group', 'tutor-course-search-pro' ),
			'one_to_one' => __( '1v1', 'tutor-course-search-pro' ),
		);
		if ( ! isset( $labels[ $type ] ) ) {
			return '';
		}

		$type_class = esc_attr( $type );
		$aria        = esc_attr( $labels[ $type ] );
		$markup      = '<span class="tcsp-format-sticker tcsp-format-sticker--normal tcsp-format-sticker--' . $type_class . '" aria-label="' . $aria . '">';
		$markup     .= '<span class="tcsp-format-type">';
		if ( 'direct' === $type ) {
			$markup .= '<span class="tcsp-format-dot" aria-hidden="true"></span>';
		} elseif ( 'hybrid' === $type ) {
			$markup .= '<span class="tcsp-format-hybrid-mark" aria-hidden="true">◐</span>';
		} else {
			$markup .= '<span class="tcsp-format-type-icon tutor-icon-video-camera" aria-hidden="true"></span>';
		}
		$markup .= '<span>' . esc_html( $labels[ $type ] ) . '</span></span>';

		if ( in_array( $type, array( 'direct', 'hybrid' ), true ) && isset( $formats[ $format ] ) ) {
			$icon = 'one_to_one' === $format ? 'tutor-icon-user-line' : 'tutor-icon-user-group';
			$markup .= '<span class="tcsp-format-separator" aria-hidden="true">·</span><span class="tcsp-format-delivery"><span class="' . esc_attr( $icon ) . '" aria-hidden="true"></span>' . esc_html( $formats[ $format ] ) . '</span>';
		}
		$markup .= '</span>';
		return $markup;
	}

	private function is_verified_instructor( $user_id ) {
		if ( class_exists( 'Tlms_Ipe_Plugin' ) ) { return Tlms_Ipe_Plugin::instance()->is_verified_instructor( $user_id ); }
		return 'verified' === (string) get_user_meta( absint( $user_id ), '_tlms_ipe_verification_status', true );
	}

	public function render_verified_sticker( $course_id = 0 ) {
		if ( is_object( $course_id ) && isset( $course_id->ID ) ) { $course_id = $course_id->ID; }
		$course_id = absint( $course_id );
		if ( ! $course_id || self::COURSE_CPT !== get_post_type( $course_id ) ) { $course_id = get_the_ID(); }
		if ( ! $course_id ) { return; }
		$author_id = (int) get_post_field( 'post_author', $course_id );
		if ( ! $this->settings->get( 'show_verified_sticker' ) ) { return; }
		if ( ! $this->is_verified_instructor( $author_id ) ) { return; }
		static $rendered = array();
		$key = 'verified_' . $course_id;
		if ( isset( $rendered[ $key ] ) ) { return; }
		$rendered[ $key ] = true;
		?>
		<span class="tcsp-verified-sticker" aria-label="<?php esc_attr_e( 'Verified Instructor', 'tutor-course-search-pro' ); ?>">✓Verified Instructor</span>
		<?php
	}


	public function load_textdomain() {
		load_plugin_textdomain( 'tutor-course-search-pro', false, dirname( plugin_basename( TCSP_FILE ) ) . '/languages' );
	}

	public static function tutor_active() {
		return function_exists( 'tutor' ) || function_exists( 'tutor_utils' ) || defined( 'TUTOR_VERSION' );
	}

	public function missing_tutor_notice() {
		if ( self::tutor_active() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		tls_suite_tutor_missing_notice( __( 'Course Search Pro', 'tutor-course-search-pro' ) );
	}

	/**
	 * Whether a course is paid.
	 *
	 * @param int $course_id Course post ID.
	 * @return bool
	 */
	public static function is_paid_course( $course_id ) {
		$type = get_post_meta( absint( $course_id ), self::PRICE_TYPE_META, true );
		return self::PRICE_TYPE_PAID === $type;
	}

	/**
	 * Whether an individual course is promoted (boosted on its own).
	 *
	 * @param int $course_id Course post ID.
	 * @return bool
	 */
	public static function is_promoted_course( $course_id ) {
		return '' !== self::get_promotion_tier( $course_id );
	}

	/**
	 * Return the assigned promotion tier key, if any.
	 */
	public static function get_promotion_tier( $course_id ) {
		$course_id = absint( $course_id );
		$tier      = sanitize_key( (string) get_post_meta( $course_id, self::PROMOTION_TIER_META, true ) );

		// Courses promoted by versions before the tier system remain sponsored
		// until an administrator re-saves the assignments.
		if ( '' === $tier && '1' === (string) get_post_meta( $course_id, self::PROMOTED_META, true ) ) {
			$tier = 'featured';
		}
		return $tier;
	}

	/**
	 * Whether a course's promotion is currently active based on its date range.
	 * If no start/end dates are set, the promotion is always active (if a tier is set).
	 *
	 * @param int $course_id Course post ID.
	 * @return bool
	 */
	public static function is_promotion_active( $course_id ) {
		$course_id = absint( $course_id );
		$tier      = self::get_promotion_tier( $course_id );
		if ( '' === $tier ) {
			return false;
		}
		$now   = current_time( 'timestamp' );
		$start = get_post_meta( $course_id, self::PROMOTION_START_META, true );
		$end   = get_post_meta( $course_id, self::PROMOTION_END_META, true );
		if ( '' !== $start ) {
			$start_ts = strtotime( $start . ' 00:00:00' );
			if ( $start_ts && $now < $start_ts ) {
				return false;
			}
		}
		if ( '' !== $end ) {
			$end_ts = strtotime( $end . ' 23:59:59' );
			if ( $end_ts && $now > $end_ts ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Get promotion start date for a course (Y-m-d or empty string).
	 */
	public static function get_promotion_start( $course_id ) {
		$course_id = absint( $course_id );
		$val       = get_post_meta( $course_id, self::PROMOTION_START_META, true );
		return is_string( $val ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $val ) ? $val : '';
	}

	/**
	 * Get promotion end date for a course (Y-m-d or empty string).
	 */
	public static function get_promotion_end( $course_id ) {
		$course_id = absint( $course_id );
		$val       = get_post_meta( $course_id, self::PROMOTION_END_META, true );
		return is_string( $val ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $val ) ? $val : '';
	}

	/**
	 * Render a sponsored badge inside Tutor course cards.
	 *
	 * @param int $course_id Optional course ID supplied by some Tutor hooks.
	 */
	public function render_sponsored_sticker( $course_id = 0 ) {
		if ( is_object( $course_id ) && isset( $course_id->ID ) ) {
			$course_id = $course_id->ID;
		}
		if ( is_array( $course_id ) ) {
			$course_id = 0;
		}
		$course_id = absint( $course_id );
		if ( ! $course_id || 'courses' !== get_post_type( $course_id ) ) {
			$course_id = get_the_ID();
		}
		$tier = self::get_promotion_tier( $course_id );
		if ( ! $tier || 'courses' !== get_post_type( $course_id ) || ! self::is_promotion_active( $course_id ) ) {
			return;
		}

		static $rendered = array();
		if ( isset( $rendered[ $course_id ] ) ) {
			return;
		}
		$rendered[ $course_id ] = true;

		$label = $tier;
		if ( isset( self::$instance->settings ) && self::$instance->settings instanceof TCSP_Settings ) {
			$tiers = self::$instance->settings->promotion_tiers();
			if ( isset( $tiers[ $tier ]['label'] ) ) {
				$label = $tiers[ $tier ]['label'];
			}
		}
		?>
		<span class="tcsp-sponsored-sticker tcsp-sponsored-sticker--<?php echo esc_attr( $tier ); ?>" aria-label="<?php esc_attr_e( 'Sponsored course', 'tutor-course-search-pro' ); ?>">
			<span class="tcsp-sponsored-icon" aria-hidden="true">★</span>
			<?php esc_html_e( 'Sponsored', 'tutor-course-search-pro' ); ?> · <?php echo esc_html( $label ); ?>
		</span>
		<?php
	}
}
