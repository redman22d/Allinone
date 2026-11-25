<?php
/**
 * Main plugin bootstrap.
 *
 * @package TutorLmsInstructorProfileExtra
 */

defined( 'ABSPATH' ) || exit;

use TUTOR\Icon;
use TUTOR\Input;
use TUTOR\User;
use Tutor\Components\InputField;
use Tutor\Components\Constants\InputType;

/**
 * Class Tlms_Ipe_Plugin
 */
class Tlms_Ipe_Plugin {

	const META_INSTAGRAM      = '_tutor_profile_instagram';
	const META_TELEGRAM       = '_tutor_profile_telegram';
	const META_WHATSAPP       = '_tutor_profile_whatsapp';
	const META_TIKTOK         = '_tutor_profile_tiktok';
	const META_YOUTUBE        = '_tutor_profile_youtube';
	const META_CONTACT_EMAIL  = '_tutor_profile_contact_email';
	const META_TUTORING_TYPES = '_tlms_ipe_tutoring_types';
	const META_ACCEPT_PRIVATE = '_tlms_ipe_accept_private_sessions';
	const META_WILAYA         = '_tlms_ipe_wilaya';
	const META_COMMUNE        = '_tlms_ipe_commune';
	const META_EDUCATION      = '_tlms_ipe_education';
	const META_EXPERIENCE     = '_tlms_ipe_experience';
	const META_TEACH_CATS     = '_tlms_ipe_teach_categories';
	const META_LANGUAGES      = '_tlms_ipe_spoken_languages';
	const META_VERIFICATION_STATUS = '_tlms_ipe_verification_status';
	const META_VERIFICATION_DATA   = '_tlms_ipe_verification_data';
	const META_VERIFICATION_ATTEMPTS = '_tlms_ipe_verification_attempts';
	const META_VERIFICATION_HISTORY = '_tlms_ipe_verification_history';
	const META_VERIFICATION_LOCKED = '_tlms_ipe_verification_locked';
	const META_VERIFICATION_ID_HASH = '_tlms_ipe_verification_id_hash';
	const META_VERIFICATION_LAST_SUBMISSION = '_tlms_ipe_verification_last_submission';

	const VERIFICATION_STATUS_NONE    = 'none';
	const VERIFICATION_STATUS_PENDING = 'pending';
	const VERIFICATION_STATUS_VERIFIED = 'verified';
	const VERIFICATION_STATUS_REJECTED = 'rejected';
	const VERIFICATION_STATUS_REVOKED  = 'revoked';

	/**
	 * Singleton.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return self
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
	private function __construct() {
		add_filter( 'tutor_user_social_icons', array( $this, 'register_social_icons' ) );
		add_filter( 'tutor_profile_default_values', array( $this, 'filter_profile_default_values' ), 10, 2 );

		add_action( 'tutor_profile_edit_input_after', array( $this, 'render_dashboard_fields' ) );
		add_action( 'tutor_profile_update_after', array( $this, 'save_profile_fields' ) );
		add_action( 'tutor_backend_profile_fields_after', array( $this, 'render_admin_fields' ) );
		add_action( 'profile_update', array( $this, 'save_admin_fields' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_admin_fields' ) );
		add_action( 'added_user_meta', array( $this, 'maybe_normalize_whatsapp_meta' ), 10, 4 );
		add_action( 'updated_user_meta', array( $this, 'maybe_normalize_whatsapp_meta' ), 10, 4 );

		add_action( 'tutor_load_template_after', array( $this, 'render_after_template' ), 10, 2 );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_front_assets' ) );
		add_filter( 'body_class', array( $this, 'add_public_profile_body_class' ) );
		add_filter( 'wp_get_attachment_url', array( $this, 'filter_private_verification_attachment_url' ), 10, 2 );
		add_filter( 'wp_get_attachment_image_src', array( $this, 'filter_private_verification_attachment_image_src' ), 10, 4 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

		// Instructor verification.
		add_action( 'admin_menu', array( $this, 'register_verification_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'migrate_legacy_verification_batch' ), 30 );
		add_action( 'wp_ajax_tlms_ipe_submit_verification', array( $this, 'handle_verification_submission' ) );
		add_action( 'admin_post_tlms_ipe_verification_action', array( $this, 'handle_admin_verification_action' ) );
		add_action( 'admin_post_tlms_ipe_verification_unlock', array( $this, 'handle_admin_verification_unlock' ) );
		add_action( 'admin_post_tlms_ipe_view_id_image', array( $this, 'handle_view_id_image' ) );
		add_action( 'tutor_load_template_before', array( $this, 'render_verification_dashboard_cta_before_template' ), 20, 2 );
		add_action( 'wp_footer', array( $this, 'render_verified_avatar_badge_script' ), 99 );
	}

	/**
	 * Extra social networks.
	 *
	 * @param array $icons Existing icons.
	 * @return array
	 */
	public function register_social_icons( $icons ) {
		$icons[ self::META_INSTAGRAM ] = array(
			'label'        => __( 'Instagram', 'tutor-learning-suite' ),
			'placeholder'  => 'https://instagram.com/username',
			'icon_classes' => 'tutor-icon-brand-instagram tlms-ipe-social-icon',
			'svg_icon'     => Icon::GLOBE,
			'pattern'      => '^https?:\/\/(www\.)?instagram\.com\/[A-Za-z0-9._]+\/?$',
		);
		$icons[ self::META_TELEGRAM ]  = array(
			'label'        => __( 'Telegram', 'tutor-learning-suite' ),
			'placeholder'  => 'https://t.me/username',
			'icon_classes' => 'tutor-icon-brand-telegram tlms-ipe-social-icon',
			'svg_icon'     => Icon::GLOBE,
			'pattern'      => '^https?:\/\/(t\.me|telegram\.me)\/[A-Za-z0-9_]+\/?$',
		);
		$icons[ self::META_WHATSAPP ]  = array(
			'label'        => __( 'WhatsApp', 'tutor-learning-suite' ),
			'placeholder'  => 'https://wa.me/213555123456',
			'icon_classes' => 'tutor-icon-brand-whatsapp tlms-ipe-social-icon',
			'svg_icon'     => Icon::ANDROID_PHONE,
			'pattern'      => '^(https?:\/\/(wa\.me\/|api\.whatsapp\.com\/send\?phone=).+|(\+|00)?[0-9 ]{8,20})$',
		);
		$icons[ self::META_YOUTUBE ]   = array(
			'label'        => __( 'YouTube', 'tutor-learning-suite' ),
			'placeholder'  => 'https://youtube.com/@username',
			'icon_classes' => 'tutor-icon-brand-youtube tlms-ipe-social-icon',
			'svg_icon'     => Icon::GLOBE,
			'pattern'      => '^https?:\/\/(www\.)?(youtube\.com|youtu\.be)\/.+$',
		);
		$icons[ self::META_CONTACT_EMAIL ] = array(
			'label'        => __( 'Contact Email', 'tutor-learning-suite' ),
			'placeholder'  => 'email@example.com',
			'icon_classes' => 'tutor-icon-line-email tlms-ipe-social-icon tlms-ipe-email-social-icon',
			'svg_icon'     => Icon::GLOBE,
			'pattern'      => '^[^\s@]+@[^\s@]+\.[^\s@]+$',
		);
		$icons[ self::META_TIKTOK ]    = array(
			'label'        => __( 'TikTok', 'tutor-learning-suite' ),
			'placeholder'  => 'https://tiktok.com/@username',
			'icon_classes' => 'tutor-icon-brand-tiktok tlms-ipe-social-icon',
			'svg_icon'     => Icon::GLOBE,
			'pattern'      => '^https?:\/\/(www\.)?tiktok\.com\/@[A-Za-z0-9._]+\/?$',
		);

		// Reuse Tutor's native Twitter field as X, avoiding duplicate profile inputs.
		$twitter_key = '';
		foreach ( $icons as $key => $definition ) {
			$label = isset( $definition['label'] ) ? strtolower( wp_strip_all_tags( (string) $definition['label'] ) ) : '';
			if ( false !== strpos( strtolower( (string) $key ), 'twitter' ) || 'twitter' === $label || 'x' === $label ) {
				$twitter_key = $key;
				break;
			}
		}
		if ( $twitter_key ) {
			$icons[ $twitter_key ]['label'] = __( 'X', 'tutor-learning-suite' );
			$icons[ $twitter_key ]['placeholder'] = 'https://x.com/username';
			$icons[ $twitter_key ]['icon_classes'] = 'tutor-icon-brand-x tlms-ipe-social-icon';
			$icons[ $twitter_key ]['svg_icon'] = Icon::GLOBE;
			$icons[ $twitter_key ]['pattern'] = '^https?:\/\/(www\.)?(x\.com|twitter\.com)\/[A-Za-z0-9_]+\/?$';
		} else {
			$icons['_tutor_profile_x'] = array(
				'label' => __( 'X', 'tutor-learning-suite' ),
				'placeholder' => 'https://x.com/username',
				'icon_classes' => 'tutor-icon-brand-x tlms-ipe-social-icon',
				'svg_icon' => Icon::GLOBE,
				'pattern' => '^https?:\/\/(www\.)?(x\.com|twitter\.com)\/[A-Za-z0-9_]+\/?$',
			);
		}
		$icons['_tutor_profile_github'] = array(
			'label' => __( 'GitHub', 'tutor-learning-suite' ),
			'placeholder' => 'https://github.com/username',
			'icon_classes' => 'tutor-icon-brand-github tlms-ipe-social-icon',
			'svg_icon' => Icon::GLOBE,
			'pattern' => '^https?:\/\/(www\.)?github\.com\/[A-Za-z0-9-]+\/?$',
		);

		// Tutor's native LinkedIn field is already present. Only improve its
		// suggested URL; do not replace or duplicate Tutor's field.
		foreach ( $icons as $key => $icon ) {
			$label = isset( $icon['label'] ) ? strtolower( wp_strip_all_tags( (string) $icon['label'] ) ) : '';
			if ( 'linkedin' === $label || false !== strpos( strtolower( (string) $key ), 'linkedin' ) ) {
				$icons[ $key ]['placeholder'] = 'https://linkedin.com/in/username';
			}
		}

		return $icons;
	}

	/**
	 * Tutoring type choices.
	 *
	 * @return array
	 */
	/**
	 * Top 50 commonly spoken world languages, with dialect labels consolidated.
	 *
	 * @return array
	 */
	public static function language_choices() {
		// Fixed global language list. Arabic is consolidated; regional Arabic varieties are not listed separately.
		return array(
			'ar' => __( 'Arabic', 'tutor-learning-suite' ),
			'en' => __( 'English', 'tutor-learning-suite' ),
			'fr' => __( 'French', 'tutor-learning-suite' ),
			'es' => __( 'Spanish', 'tutor-learning-suite' ),
			'it' => __( 'Italian', 'tutor-learning-suite' ),
			'ru' => __( 'Russian', 'tutor-learning-suite' ),
			'zh' => __( 'Chinese (Mandarin)', 'tutor-learning-suite' ),
			'yue' => __( 'Chinese (Cantonese)', 'tutor-learning-suite' ),
			'tr' => __( 'Turkish', 'tutor-learning-suite' ),
			'hi' => __( 'Hindi', 'tutor-learning-suite' ),
			'pt' => __( 'Portuguese', 'tutor-learning-suite' ),
			'bn' => __( 'Bengali', 'tutor-learning-suite' ),
			'ur' => __( 'Urdu', 'tutor-learning-suite' ),
			'id' => __( 'Indonesian', 'tutor-learning-suite' ),
			'de' => __( 'German', 'tutor-learning-suite' ),
			'ja' => __( 'Japanese', 'tutor-learning-suite' ),
			'pa' => __( 'Punjabi', 'tutor-learning-suite' ),
			'jv' => __( 'Javanese', 'tutor-learning-suite' ),
			'te' => __( 'Telugu', 'tutor-learning-suite' ),
			'mr' => __( 'Marathi', 'tutor-learning-suite' ),
			'vi' => __( 'Vietnamese', 'tutor-learning-suite' ),
			'ko' => __( 'Korean', 'tutor-learning-suite' ),
			'ta' => __( 'Tamil', 'tutor-learning-suite' ),
			'fa' => __( 'Persian', 'tutor-learning-suite' ),
			'gu' => __( 'Gujarati', 'tutor-learning-suite' ),
			'kn' => __( 'Kannada', 'tutor-learning-suite' ),
			'am' => __( 'Amharic', 'tutor-learning-suite' ),
			'my' => __( 'Burmese', 'tutor-learning-suite' ),
			'th' => __( 'Thai', 'tutor-learning-suite' ),
			'yo' => __( 'Yoruba', 'tutor-learning-suite' ),
			'ha' => __( 'Hausa', 'tutor-learning-suite' ),
			'ig' => __( 'Igbo', 'tutor-learning-suite' ),
			'pl' => __( 'Polish', 'tutor-learning-suite' ),
			'uk' => __( 'Ukrainian', 'tutor-learning-suite' ),
			'nl' => __( 'Dutch', 'tutor-learning-suite' ),
			'ro' => __( 'Romanian', 'tutor-learning-suite' ),
			'uz' => __( 'Uzbek', 'tutor-learning-suite' ),
			'zu' => __( 'Zulu', 'tutor-learning-suite' ),
			'he' => __( 'Hebrew', 'tutor-learning-suite' ),
			'cs' => __( 'Czech', 'tutor-learning-suite' ),
			'sv' => __( 'Swedish', 'tutor-learning-suite' ),
			'el' => __( 'Greek', 'tutor-learning-suite' ),
			'hu' => __( 'Hungarian', 'tutor-learning-suite' ),
			'fi' => __( 'Finnish', 'tutor-learning-suite' ),
			'no' => __( 'Norwegian', 'tutor-learning-suite' ),
			'da' => __( 'Danish', 'tutor-learning-suite' ),
			'sk' => __( 'Slovak', 'tutor-learning-suite' ),
			'bg' => __( 'Bulgarian', 'tutor-learning-suite' ),
			'hr' => __( 'Croatian', 'tutor-learning-suite' ),
			'sr' => __( 'Serbian', 'tutor-learning-suite' ),
		);
	}

	/**
	 * Tutoring type choices.
	 *
	 * @return array
	 */
	public static function tutoring_type_choices() {
		return array(
			'online'       => __( 'Online', 'tutor-learning-suite' ),
			'tutor_home'   => __( "Offline at tutor's home", 'tutor-learning-suite' ),
			'student_home' => __( "Offline at student's home", 'tutor-learning-suite' ),
		);
	}

	/**
	 * Inject extra fields into Tutor dashboard form defaults.
	 *
	 * @param array   $values Default values.
	 * @param WP_User $user   Current user.
	 * @return array
	 */
	public function filter_profile_default_values( $values, $user ) {
		if ( ! $this->is_instructor_user( $user->ID ) ) {
			return $values;
		}

		$values['tlms_ipe_tutoring_types'] = $this->get_tutoring_types( $user->ID );
		$values['tlms_ipe_accept_private_sessions'] = (int) get_user_meta( $user->ID, self::META_ACCEPT_PRIVATE, true );
		$values['tlms_ipe_wilaya']         = $this->get_location_values( $user->ID, self::META_WILAYA );
		$values['tlms_ipe_commune']        = $this->get_location_values( $user->ID, self::META_COMMUNE );
		$values['tlms_ipe_education']      = $this->get_array_meta( $user->ID, self::META_EDUCATION );
		$values['tlms_ipe_experience']     = $this->get_array_meta( $user->ID, self::META_EXPERIENCE );
		$values['tlms_ipe_teach_categories'] = $this->get_teach_categories( $user->ID );
		$values['tlms_ipe_spoken_languages'] = $this->get_spoken_languages( $user->ID );

		return $values;
	}

	/**
	 * Dashboard settings fields under bio.
	 *
	 * @param WP_User $user Current user.
	 * @return void
	 */
	public function render_dashboard_fields( $user ) {
		if ( ! $this->is_instructor_user( $user->ID ) ) { return; }
		$wilayas = $this->get_location_values( $user->ID, self::META_WILAYA );
		$communes = $this->get_location_values( $user->ID, self::META_COMMUNE );
		$saved_types = $this->get_tutoring_types( $user->ID );
		$accept_private = (bool) get_user_meta( $user->ID, self::META_ACCEPT_PRIVATE, true );
		$saved_categories = $this->get_teach_categories( $user->ID );
		$saved_languages = $this->get_spoken_languages( $user->ID );
		?>
		<div class="tutor-flex tutor-flex-column tutor-gap-3 tlms-ipe-dashboard-fields" data-tlms-ipe-tutor-only="1">
			<div class="tutor-card tutor-card-rounded-2xl tlms-ipe-compact-card">
				<div class="tlms-ipe-field tlms-ipe-private-session-control">
					<label class="tlms-ipe-accept-private-label">
						<input type="checkbox" id="tlms_ipe_accept_private_sessions" value="1" <?php checked( $accept_private ); ?> />
						<span class="tutor-label"><?php esc_html_e( 'I accept private session request', 'tutor-learning-suite' ); ?></span>
					</label>
					<input type="hidden" id="tlms_ipe_accept_private_sessions_value" name="tlms_ipe_accept_private_sessions" value="<?php echo $accept_private ? '1' : '0'; ?>" x-bind="register('tlms_ipe_accept_private_sessions')" />
			</div>
			<div class="tlms-ipe-field tlms-ipe-private-type-section" data-private-session-options="1" <?php echo $accept_private ? '' : 'hidden'; ?>>
				<span class="tutor-label"><?php esc_html_e( 'Private tutoring type', 'tutor-learning-suite' ); ?></span>
				<input type="hidden" id="tlms_ipe_tutoring_types_value" name="tlms_ipe_tutoring_types" value="<?php echo esc_attr( wp_json_encode( $saved_types ) ); ?>" x-bind="register('tlms_ipe_tutoring_types')" />
				<div class="tlms-ipe-choice-grid">
					<?php foreach ( self::tutoring_type_choices() as $value => $label ) : ?>
						<label class="tlms-ipe-choice-pill"><input type="checkbox" value="<?php echo esc_attr( $value ); ?>" <?php checked( in_array( $value, $saved_types, true ) ); ?>><span><?php echo esc_html( $label ); ?></span></label>
					<?php endforeach; ?>
				</div>
			</div>
			</div>
			<div class="tutor-card tutor-card-rounded-2xl tlms-ipe-compact-card">
			<div class="tutor-grid tutor-md-grid-cols-1 tutor-grid-cols-2 tutor-gap-3 tlms-ipe-location-grid">
				<div class="tlms-ipe-location-field">
					<div class="tlms-ipe-section-head"><label class="tutor-label"><?php esc_html_e( 'Wilaya', 'tutor-learning-suite' ); ?></label><span class="tlms-ipe-selection-count" data-count-for="tlms_ipe_wilaya">0</span></div>
					<div class="tlms-ipe-check-dropdown tlms-ipe-location-dropdown" data-dropdown="tlms_ipe_wilaya">
						<button type="button" class="tlms-ipe-dropdown-toggle" aria-expanded="false"><span class="tlms-ipe-dropdown-text"><?php esc_html_e( 'Select wilaya(s)', 'tutor-learning-suite' ); ?></span><span class="tlms-ipe-chevron">⌄</span></button>
						<div class="tlms-ipe-dropdown-panel">
							<div class="tlms-ipe-dropdown-search"><input type="search" placeholder="<?php echo esc_attr__( 'Search wilaya…', 'tutor-learning-suite' ); ?>" autocomplete="off"></div>
							<div class="tlms-ipe-dropdown-options">
								<?php foreach ( Tlms_Ipe_Locations::wilaya_options() as $option ) : ?>
									<label class="tlms-ipe-option"><input type="checkbox" value="<?php echo esc_attr( $option['value'] ); ?>" <?php checked( in_array( (string) $option['value'], $wilayas, true ) ); ?>><span><?php echo esc_html( $option['label'] ); ?></span></label>
								<?php endforeach; ?>
							</div>
						</div>
					</div>
					<input type="hidden" id="tlms_ipe_wilaya_value" name="tlms_ipe_wilaya" value="<?php echo esc_attr( wp_json_encode( $wilayas ) ); ?>" x-bind="register('tlms_ipe_wilaya')" />
				</div>
				<div class="tlms-ipe-location-field">
					<div class="tlms-ipe-section-head"><label class="tutor-label"><?php esc_html_e( 'Municipality', 'tutor-learning-suite' ); ?></label><span class="tlms-ipe-selection-count" data-count-for="tlms_ipe_commune">0</span></div>
					<div class="tlms-ipe-check-dropdown tlms-ipe-location-dropdown" data-dropdown="tlms_ipe_commune">
						<button type="button" class="tlms-ipe-dropdown-toggle" aria-expanded="false"><span class="tlms-ipe-dropdown-text"><?php esc_html_e( 'Select municipality(ies)', 'tutor-learning-suite' ); ?></span><span class="tlms-ipe-chevron">⌄</span></button>
						<div class="tlms-ipe-dropdown-panel">
							<div class="tlms-ipe-dropdown-search"><input type="search" placeholder="<?php echo esc_attr__( 'Search municipality…', 'tutor-learning-suite' ); ?>" autocomplete="off"></div>
							<div class="tlms-ipe-dropdown-options">
								<?php foreach ( Tlms_Ipe_Locations::commune_options_map() as $wilaya_code => $options ) : ?>
									<?php foreach ( $options as $option ) : ?>
										<label class="tlms-ipe-option" data-wilaya="<?php echo esc_attr( $wilaya_code ); ?>"><input type="checkbox" value="<?php echo esc_attr( $option['value'] ); ?>" <?php checked( in_array( (string) $option['value'], $communes, true ) ); ?>><span><?php echo esc_html( $option['label'] ); ?></span></label>
									<?php endforeach; ?>
								<?php endforeach; ?>
							</div>
						</div>
					</div>
					<input type="hidden" id="tlms_ipe_commune_value" name="tlms_ipe_commune" value="<?php echo esc_attr( wp_json_encode( $communes ) ); ?>" x-bind="register('tlms_ipe_commune')" />
					<small class="tlms-ipe-location-help"><?php esc_html_e( 'Choose a wilaya first to narrow the municipality list.', 'tutor-learning-suite' ); ?></small>
				</div>
			</div>
			</div>

			<div class="tutor-card tutor-card-rounded-2xl tlms-ipe-compact-card tlms-ipe-profile-card">
				<div class="tlms-ipe-section-head"><h6 class="tutor-h6"><?php esc_html_e( 'Education', 'tutor-learning-suite' ); ?></h6><button type="button" class="tutor-btn tutor-btn-outline tutor-btn-sm tlms-ipe-add-row" data-target="education">+ <?php esc_html_e( 'Add qualification', 'tutor-learning-suite' ); ?></button></div>
				<div id="tlms-ipe-education-list" class="tlms-ipe-repeat-list" data-items="<?php echo esc_attr( wp_json_encode( $this->get_array_meta( $user->ID, self::META_EDUCATION ) ) ); ?>"></div>
				<input type="hidden" name="tlms_ipe_education" id="tlms_ipe_education" value="<?php echo esc_attr( wp_json_encode( $this->get_array_meta( $user->ID, self::META_EDUCATION ) ) ); ?>" x-bind="register('tlms_ipe_education')" /><input type="hidden" name="tlms_ipe_education_dirty" id="tlms_ipe_education_dirty" value="0" />
			</div>

			<div class="tutor-card tutor-card-rounded-2xl tlms-ipe-compact-card tlms-ipe-profile-card">
				<div class="tlms-ipe-section-head"><h6 class="tutor-h6"><?php esc_html_e( 'Experience', 'tutor-learning-suite' ); ?></h6><button type="button" class="tutor-btn tutor-btn-outline tutor-btn-sm tlms-ipe-add-row" data-target="experience">+ <?php esc_html_e( 'Add experience', 'tutor-learning-suite' ); ?></button></div>
				<div id="tlms-ipe-experience-list" class="tlms-ipe-repeat-list" data-items="<?php echo esc_attr( wp_json_encode( $this->get_array_meta( $user->ID, self::META_EXPERIENCE ) ) ); ?>"></div>
				<input type="hidden" name="tlms_ipe_experience" id="tlms_ipe_experience" value="<?php echo esc_attr( wp_json_encode( $this->get_array_meta( $user->ID, self::META_EXPERIENCE ) ) ); ?>" x-bind="register('tlms_ipe_experience')" /><input type="hidden" name="tlms_ipe_experience_dirty" id="tlms_ipe_experience_dirty" value="0" />
			</div>

			<div class="tutor-card tutor-card-rounded-2xl tlms-ipe-compact-card tlms-ipe-profile-card">
				<div class="tlms-ipe-section-head"><h6 class="tutor-h6"><?php esc_html_e( 'What I can teach', 'tutor-learning-suite' ); ?></h6><span class="tlms-ipe-selection-count" data-count-for="tlms_ipe_teach_categories">0</span></div>
				<div class="tlms-ipe-check-dropdown" data-dropdown="tlms_ipe_teach_categories">
					<button type="button" class="tlms-ipe-dropdown-toggle" aria-expanded="false"><span class="tlms-ipe-dropdown-text"><?php esc_html_e( 'Select subjects and categories', 'tutor-learning-suite' ); ?></span><span class="tlms-ipe-chevron">⌄</span></button>
					<div class="tlms-ipe-dropdown-panel">
						<div class="tlms-ipe-dropdown-search"><input type="search" placeholder="<?php echo esc_attr__( 'Search categories…', 'tutor-learning-suite' ); ?>" autocomplete="off"></div>
						<div class="tlms-ipe-dropdown-options"><?php $this->render_category_checkboxes( $saved_categories ); ?></div>
					</div>
				</div>
				<input type="hidden" name="tlms_ipe_teach_categories" id="tlms_ipe_teach_categories_value" value="<?php echo esc_attr( wp_json_encode( $saved_categories ) ); ?>" x-bind="register('tlms_ipe_teach_categories')" />
			</div>

			<div class="tutor-card tutor-card-rounded-2xl tlms-ipe-compact-card tlms-ipe-profile-card">
				<div class="tlms-ipe-section-head"><h6 class="tutor-h6"><?php esc_html_e( 'Spoken languages', 'tutor-learning-suite' ); ?></h6><span class="tlms-ipe-selection-count" data-count-for="tlms_ipe_spoken_languages">0</span></div>
				<div class="tlms-ipe-check-dropdown" data-dropdown="tlms_ipe_spoken_languages">
					<button type="button" class="tlms-ipe-dropdown-toggle" aria-expanded="false"><span class="tlms-ipe-dropdown-text"><?php esc_html_e( 'Select spoken languages', 'tutor-learning-suite' ); ?></span><span class="tlms-ipe-chevron">⌄</span></button>
					<div class="tlms-ipe-dropdown-panel">
						<div class="tlms-ipe-dropdown-search"><input type="search" placeholder="<?php echo esc_attr__( 'Search languages…', 'tutor-learning-suite' ); ?>" autocomplete="off"></div>
						<div class="tlms-ipe-dropdown-options"><?php foreach ( self::language_choices() as $value => $label ) : ?><label class="tlms-ipe-option"><input type="checkbox" value="<?php echo esc_attr( $value ); ?>" <?php checked( in_array( $value, $saved_languages, true ) ); ?>><span><?php echo esc_html( $label ); ?></span></label><?php endforeach; ?></div>
					</div>
				</div>
				<input type="hidden" name="tlms_ipe_spoken_languages" id="tlms_ipe_spoken_languages_value" value="<?php echo esc_attr( wp_json_encode( $saved_languages ) ); ?>" x-bind="register('tlms_ipe_spoken_languages')" />
			</div>

		</div>
		<?php
	}

	/**
	 * WP admin profile fields.
	 *
	 * @return void
	 */
	public function render_admin_fields() {
		global $user_id;

		if ( ! $user_id || ! $this->is_instructor_user( $user_id ) ) {
			return;
		}

		$types           = $this->get_tutoring_types( $user_id );
		$wilayas         = $this->get_location_values( $user_id, self::META_WILAYA );
		$communes        = $this->get_location_values( $user_id, self::META_COMMUNE );
		$commune_map     = Tlms_Ipe_Locations::commune_options_map();
		?>
		<tr class="user-description-wrap">
			<th><label><?php esc_html_e( 'Private sessions', 'tutor-learning-suite' ); ?></label></th>
			<td><label><input type="checkbox" name="tlms_ipe_accept_private_sessions" value="1" <?php checked( get_user_meta( $user_id, self::META_ACCEPT_PRIVATE, true ), 1 ); ?> /> <?php esc_html_e( 'I accept private session requests', 'tutor-learning-suite' ); ?></label></td>
		</tr>
		<tr class="user-description-wrap">
			<th><label><?php esc_html_e( 'Private tutoring type', 'tutor-learning-suite' ); ?></label></th>
			<td>
				<?php foreach ( self::tutoring_type_choices() as $value => $label ) : ?>
					<label style="display:block;margin-bottom:6px;">
						<input type="checkbox" name="tlms_ipe_tutoring_types[]" value="<?php echo esc_attr( $value ); ?>" <?php checked( in_array( $value, $types, true ) ); ?> />
						<?php echo esc_html( $label ); ?>
					</label>
				<?php endforeach; ?>
			</td>
		</tr>
		<tr class="user-description-wrap">
			<th><label for="tlms_ipe_wilaya"><?php esc_html_e( 'Location', 'tutor-learning-suite' ); ?></label></th>
			<td>
				<select name="tlms_ipe_wilaya[]" id="tlms_ipe_wilaya" class="regular-text tlms-ipe-admin-wilaya" multiple size="6">
					<?php foreach ( Tlms_Ipe_Locations::wilaya_options() as $option ) : ?>
						<option value="<?php echo esc_attr( $option['value'] ); ?>" <?php selected( in_array( (string) $option['value'], $wilayas, true ) ); ?>>
							<?php echo esc_html( $option['label'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<p>
					<select name="tlms_ipe_commune[]" id="tlms_ipe_commune" class="regular-text tlms-ipe-admin-commune" multiple size="6">
						<?php foreach ( $commune_map as $wilaya_code => $options ) : ?>
							<?php foreach ( $options as $option ) : ?>
								<option value="<?php echo esc_attr( $option['value'] ); ?>" data-wilaya="<?php echo esc_attr( $wilaya_code ); ?>" <?php selected( in_array( (string) $option['value'], $communes, true ) ); ?>>
									<?php echo esc_html( $option['label'] ); ?>
								</option>
							<?php endforeach; ?>
						<?php endforeach; ?>
					</select>
				</p>
				<p class="description"><?php esc_html_e( 'Hold Ctrl/Cmd to select multiple wilayas and municipalities.', 'tutor-learning-suite' ); ?></p>
			</td>
		</tr>
		<tr class="user-description-wrap">
			<th><label><?php esc_html_e( 'Education', 'tutor-learning-suite' ); ?></label></th>
			<td><textarea name="tlms_ipe_education" class="large-text" rows="4" placeholder="[{&quot;name&quot;:&quot;Degree&quot;,&quot;place&quot;:&quot;University&quot;,&quot;date&quot;:&quot;2024-06-01&quot;}]"><?php echo esc_textarea( wp_json_encode( $this->get_array_meta( $user_id, self::META_EDUCATION ) ) ); ?></textarea></td>
		</tr>
		<tr class="user-description-wrap">
			<th><label><?php esc_html_e( 'Experience', 'tutor-learning-suite' ); ?></label></th>
			<td><textarea name="tlms_ipe_experience" class="large-text" rows="4" placeholder="[{&quot;job&quot;:&quot;Math teacher&quot;,&quot;place&quot;:&quot;School&quot;,&quot;years&quot;:5}]"><?php echo esc_textarea( wp_json_encode( $this->get_array_meta( $user_id, self::META_EXPERIENCE ) ) ); ?></textarea></td>
		</tr>
		<tr class="user-description-wrap">
			<th><label for="tlms_ipe_teach_categories"><?php esc_html_e( 'What can teach', 'tutor-learning-suite' ); ?></label></th>
			<td><select name="tlms_ipe_teach_categories[]" id="tlms_ipe_teach_categories" class="regular-text" multiple size="8"><?php $this->render_category_options( $this->get_teach_categories( $user_id ) ); ?></select></td>
		</tr>
		<tr class="user-description-wrap">
			<th><label for="tlms_ipe_spoken_languages"><?php esc_html_e( 'Spoken languages', 'tutor-learning-suite' ); ?></label></th>
			<td><select name="tlms_ipe_spoken_languages[]" id="tlms_ipe_spoken_languages" class="regular-text" multiple size="8"><?php foreach ( self::language_choices() as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( in_array( $value, $this->get_spoken_languages( $user_id ), true ) ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></td>
		</tr>
		<tr class="user-description-wrap">
			<th><label for="tlms_ipe_contact_email"><?php esc_html_e( 'Contact email', 'tutor-learning-suite' ); ?></label></th>
			<td><input type="email" name="tlms_ipe_contact_email" id="tlms_ipe_contact_email" class="regular-text" maxlength="190" value="<?php echo esc_attr( get_user_meta( $user_id, self::META_CONTACT_EMAIL, true ) ); ?>"><p class="description"><?php esc_html_e( 'Optional public contact email. Leave empty to keep the account email separate.', 'tutor-learning-suite' ); ?></p></td>
		</tr>
		<?php
	}

	/**
	 * Save extra fields from the frontend dashboard.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public function save_profile_fields( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id || get_current_user_id() !== $user_id ) {
			return;
		}

		if ( ! $this->is_instructor_user( $user_id ) ) {
			return;
		}

		if ( ! Input::has( 'tlms_ipe_wilaya' ) && ! isset( $_POST['tlms_ipe_accept_private_sessions'] ) && ! isset( $_POST['tlms_ipe_tutoring_types'] ) && ! isset( $_POST['tlms_ipe_education'] ) && ! isset( $_POST['tlms_ipe_experience'] ) && ! isset( $_POST['tlms_ipe_teach_categories'] ) && ! isset( $_POST['tlms_ipe_spoken_languages'] ) && ! isset( $_POST['tlms_ipe_contact_email'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		$this->persist_extra_fields( $user_id );
	}

	/**
	 * Save extra fields from WP admin.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public function save_admin_fields( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id || ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		if ( ! $this->is_instructor_user( $user_id ) ) {
			return;
		}

		if ( ! Input::has( 'tlms_ipe_wilaya' ) && ! Input::has( 'tlms_ipe_commune' ) && ! isset( $_POST['tlms_ipe_accept_private_sessions'] ) && ! isset( $_POST['tlms_ipe_tutoring_types'] ) && ! isset( $_POST['tlms_ipe_education'] ) && ! isset( $_POST['tlms_ipe_experience'] ) && ! isset( $_POST['tlms_ipe_teach_categories'] ) && ! isset( $_POST['tlms_ipe_spoken_languages'] ) && ! isset( $_POST['tlms_ipe_contact_email'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		$this->persist_extra_fields( $user_id );
	}

	/**
	 * Persist tutoring type and location.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	private function persist_extra_fields( $user_id ) {
		// Tutor LMS can submit this form partially. Only write a field when it was
		// actually submitted; never replace saved data with an empty value from
		// another profile section.
		if ( isset( $_POST['tlms_ipe_contact_email'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$contact_email = sanitize_email( wp_unslash( $_POST['tlms_ipe_contact_email'] ) );
			if ( '' === $contact_email ) {
				delete_user_meta( $user_id, self::META_CONTACT_EMAIL );
			} elseif ( is_email( $contact_email ) ) {
				update_user_meta( $user_id, self::META_CONTACT_EMAIL, $contact_email );
			}
		}

		if ( isset( $_POST['tlms_ipe_accept_private_sessions'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$accept = '1' === (string) wp_unslash( $_POST['tlms_ipe_accept_private_sessions'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			update_user_meta( $user_id, self::META_ACCEPT_PRIVATE, $accept ? 1 : 0 );
			if ( ! $accept ) {
				update_user_meta( $user_id, self::META_TUTORING_TYPES, array() );
			}
		}
		if ( isset( $_POST['tlms_ipe_tutoring_types'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			update_user_meta( $user_id, self::META_TUTORING_TYPES, $this->read_tutoring_types_from_request() );
		}

		// Education/experience have hidden JSON fields in the profile form. They
		// are marked dirty by JS only when the user edits that section. This
		// prevents Tutor LMS/native profile saves from accidentally wiping them.
		$education_present = isset( $_POST['tlms_ipe_education'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$experience_present = isset( $_POST['tlms_ipe_experience'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$education_dirty = ! empty( $_POST['tlms_ipe_education_dirty'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$experience_dirty = ! empty( $_POST['tlms_ipe_experience_dirty'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( $education_present && ( $education_dirty || $this->request_json_has_rows( 'tlms_ipe_education' ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			update_user_meta( $user_id, self::META_EDUCATION, $this->read_json_rows_from_request( 'tlms_ipe_education', array( 'name', 'place', 'date' ) ) );
		}
		if ( $experience_present && ( $experience_dirty || $this->request_json_has_rows( 'tlms_ipe_experience' ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			update_user_meta( $user_id, self::META_EXPERIENCE, $this->read_json_rows_from_request( 'tlms_ipe_experience', array( 'job', 'place', 'years' ) ) );
		}

		if ( Input::has( 'tlms_ipe_teach_categories' ) || isset( $_POST['tlms_ipe_teach_categories'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			update_user_meta( $user_id, self::META_TEACH_CATS, $this->read_term_ids_from_request( 'tlms_ipe_teach_categories' ) );
		}
		if ( Input::has( 'tlms_ipe_spoken_languages' ) || isset( $_POST['tlms_ipe_spoken_languages'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			update_user_meta( $user_id, self::META_LANGUAGES, $this->read_language_ids_from_request() );
		}

		// Location is also partial-safe: if neither location field was submitted,
		// keep the existing saved location untouched.
		$wilaya_present = Input::has( 'tlms_ipe_wilaya' ) || isset( $_POST['tlms_ipe_wilaya'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$commune_present = Input::has( 'tlms_ipe_commune' ) || isset( $_POST['tlms_ipe_commune'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! $wilaya_present && ! $commune_present ) {
			return;
		}

		$wilayas = $this->read_location_values_from_request( 'tlms_ipe_wilaya' );
		$communes = $this->read_location_values_from_request( 'tlms_ipe_commune' );
		$map = Tlms_Ipe_Locations::get_map();
		$valid_wilayas = array_values( array_unique( array_filter( $wilayas, function ( $code ) use ( $map ) { return isset( $map[ $code ] ); } ) ) );
		$valid_communes = array();
		foreach ( $map as $code => $wilaya_data ) {
			if ( ! in_array( (string) $code, $valid_wilayas, true ) ) { continue; }
			foreach ( $communes as $commune ) {
				if ( isset( $wilaya_data['communes'][ $commune ] ) ) { $valid_communes[] = (string) $commune; }
			}
		}
		$valid_communes = array_values( array_unique( $valid_communes ) );

		if ( $wilaya_present ) {
			if ( empty( $valid_wilayas ) ) { delete_user_meta( $user_id, self::META_WILAYA ); }
			else { update_user_meta( $user_id, self::META_WILAYA, $valid_wilayas ); }
		}
		if ( $commune_present ) {
			if ( empty( $valid_communes ) ) { delete_user_meta( $user_id, self::META_COMMUNE ); }
			else { update_user_meta( $user_id, self::META_COMMUNE, $valid_communes ); }
		}
	}

	/**
	 * Check whether a submitted JSON field contains at least one row.
	 */
	private function request_json_has_rows( $key ) {
		if ( ! isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return false;
		}
		$value = wp_unslash( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$data = json_decode( is_string( $value ) ? $value : '', true );
		return is_array( $data ) && ! empty( $data );
	}

	/**
	 * Normalize WhatsApp when the social meta is saved.
	 *
	 * @param int    $meta_id    Meta id.
	 * @param int    $user_id    User id.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 * @return void
	 */
	public function maybe_normalize_whatsapp_meta( $meta_id, $user_id, $meta_key, $meta_value ) {
		unset( $meta_id, $meta_value );
		if ( self::META_WHATSAPP !== $meta_key ) {
			return;
		}
		$this->normalize_whatsapp( (int) $user_id );
	}

	/**
	 * Convert a raw WhatsApp number to a wa.me URL.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	private function normalize_whatsapp( $user_id ) {
		$value = (string) get_user_meta( $user_id, self::META_WHATSAPP, true );
		if ( '' === $value ) {
			return;
		}

		if ( preg_match( '/^https?:\/\//i', $value ) ) {
			return;
		}

		$digits = preg_replace( '/\D+/', '', $value );
		if ( $digits ) {
			update_user_meta( $user_id, self::META_WHATSAPP, 'https://wa.me/' . $digits );
		}
	}

	/**
	 * Read tutoring types from POST.
	 *
	 * @return array
	 */
	private function read_tutoring_types_from_request() {
		$allowed = array_keys( self::tutoring_type_choices() );
		$raw     = array();

		if ( isset( $_POST['tlms_ipe_tutoring_types'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$raw = wp_unslash( $_POST['tlms_ipe_tutoring_types'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$raw = $decoded;
			} else {
				$raw = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
			}
		}

		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$clean = array();
		foreach ( $raw as $value ) {
			$value = sanitize_text_field( (string) $value );
			if ( in_array( $value, $allowed, true ) ) {
				$clean[] = $value;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Register the Instructor Verification admin page.
	 *
	 * @return void
	 */
	public function register_verification_admin_menu() {
		$page_hook = add_submenu_page(
			'tutor',
			__( 'Instructor Verification', 'tutor-learning-suite' ),
			__( 'Instructor Verification', 'tutor-learning-suite' ),
			'manage_options',
			'tlms-ipe-verification',
			array( $this, 'render_verification_admin_page' )
		);

		if ( ! $page_hook ) {
			add_users_page(
				__( 'Instructor Verification', 'tutor-learning-suite' ),
				__( 'Instructor Verification', 'tutor-learning-suite' ),
				'manage_options',
				'tlms-ipe-verification',
				array( $this, 'render_verification_admin_page' )
			);
		}
	}

	/**
	 * Whether verification is enabled and the user is verified.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public function is_verified_instructor( $user_id ) {
		$user_id = absint( $user_id );
		return $user_id && $this->is_instructor_user( $user_id ) && self::VERIFICATION_STATUS_VERIFIED === $this->get_verification_status( $user_id );
	}

	/**
	 * Get verification status.

	 * @param int $user_id User id.
	 * @return string
	 */
	private function get_verification_status( $user_id ) {
		$status  = (string) get_user_meta( absint( $user_id ), self::META_VERIFICATION_STATUS, true );
		$allowed = array( self::VERIFICATION_STATUS_NONE, self::VERIFICATION_STATUS_PENDING, self::VERIFICATION_STATUS_VERIFIED, self::VERIFICATION_STATUS_REJECTED, self::VERIFICATION_STATUS_REVOKED );
		return in_array( $status, $allowed, true ) ? $status : self::VERIFICATION_STATUS_NONE;
	}

	/**
	 * Get saved verification application data.

	 * @param int $user_id User id.
	 * @return array
	 */
	private function get_verification_data( $user_id ) {
		$data = get_user_meta( absint( $user_id ), self::META_VERIFICATION_DATA, true );
		return is_array( $data ) ? $data : array();
	}

	/** Get anti-fraud verification settings. */
	private function get_verification_protection_settings() {
		return array(
			'max_attempts' => max( 1, min( 20, absint( get_option( 'tlms_ipe_verification_max_attempts', 3 ) ) ) ),
			'cooldown_days' => min( 365, absint( get_option( 'tlms_ipe_verification_cooldown_days', 30 ) ) ),
			'block_duplicate' => (bool) get_option( 'tlms_ipe_verification_block_duplicate', 1 ),
			'rate_limit' => (bool) get_option( 'tlms_ipe_verification_rate_limit', 1 ),
		);
	}

	/** Normalize an ID number before hashing. */
	private function normalize_verification_id_number( $id_number ) {
		return preg_replace( '/[^A-Z0-9]/', '', strtoupper( trim( (string) $id_number ) ) );
	}

	/** Hash an ID number for duplicate detection. */
	private function get_verification_id_hash( $id_number ) {
		$normalized = $this->normalize_verification_id_number( $id_number );
		return $normalized ? hash_hmac( 'sha256', $normalized, wp_salt( 'auth' ) ) : '';
	}

	/** Get verification history. */
	private function get_verification_history( $user_id ) {
		$history = get_user_meta( absint( $user_id ), self::META_VERIFICATION_HISTORY, true );
		return is_array( $history ) ? array_values( $history ) : array();
	}

	/** Check whether this instructor is allowed to submit another application. */
	private function get_verification_submission_gate( $user_id ) {
		$user_id = absint( $user_id );
		$status = $this->get_verification_status( $user_id );
		if ( self::VERIFICATION_STATUS_PENDING === $status ) {
			return array( 'allowed' => false, 'reason' => __( 'Your verification application is already pending review.', 'tutor-learning-suite' ) );
		}
		if ( self::VERIFICATION_STATUS_VERIFIED === $status ) {
			return array( 'allowed' => false, 'reason' => __( 'Your instructor account is already verified.', 'tutor-learning-suite' ) );
		}
		if ( get_user_meta( $user_id, self::META_VERIFICATION_LOCKED, true ) ) {
			return array( 'allowed' => false, 'reason' => __( 'Verification submissions are locked for this account. Please contact an administrator.', 'tutor-learning-suite' ) );
		}
		$settings = $this->get_verification_protection_settings();
		$attempts = absint( get_user_meta( $user_id, self::META_VERIFICATION_ATTEMPTS, true ) );
		if ( $attempts >= $settings['max_attempts'] ) {
			update_user_meta( $user_id, self::META_VERIFICATION_LOCKED, 1 );
			return array( 'allowed' => false, 'reason' => __( 'The maximum number of verification attempts has been reached. Please contact an administrator.', 'tutor-learning-suite' ) );
		}
		if ( self::VERIFICATION_STATUS_REJECTED === $status && $settings['cooldown_days'] > 0 ) {
			$last_rejection = 0;
			foreach ( $this->get_verification_history( $user_id ) as $entry ) {
				if ( self::VERIFICATION_STATUS_REJECTED === ( $entry['status'] ?? '' ) && ! empty( $entry['decided_at'] ) ) {
					$time = strtotime( $entry['decided_at'] );
					if ( $time > $last_rejection ) { $last_rejection = $time; }
				}
			}
			if ( $last_rejection ) {
				$available_at = $last_rejection + DAY_IN_SECONDS * $settings['cooldown_days'];
				if ( time() < $available_at ) {
					return array( 'allowed' => false, 'reason' => sprintf( __( 'You can submit another verification application after %s.', 'tutor-learning-suite' ), wp_date( get_option( 'date_format' ), $available_at ) ) );
				}
			}
		}
		if ( $settings['rate_limit'] ) {
			$last_submission = absint( get_user_meta( $user_id, self::META_VERIFICATION_LAST_SUBMISSION, true ) );
			if ( $last_submission && ( time() - $last_submission ) < 60 ) {
				return array( 'allowed' => false, 'reason' => __( 'Please wait a moment before submitting another verification request.', 'tutor-learning-suite' ) );
			}
		}
		return array( 'allowed' => true, 'reason' => '' );
	}

	/**
	 * Validate the two identity-image uploads at submission time only.
	 * This must never run while rendering the dashboard CTA: no files are
	 * expected during a normal dashboard page view.
	 *
	 * @return string Empty when valid, otherwise a safe user-facing error.
	 */
	private function validate_verification_uploads() {
		$mimes = array( 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' );
		foreach ( array( 'front', 'back' ) as $side ) {
			$file_key = 'id_image_' . $side;
			$file = isset( $_FILES[ $file_key ] ) && is_array( $_FILES[ $file_key ] ) ? $_FILES[ $file_key ] : array();
			if ( empty( $file['tmp_name'] ) || empty( $file['name'] ) || (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK ) {
				return __( 'Please upload both the front and back of your identity document.', 'tutor-learning-suite' );
			}
			if ( (int) ( $file['size'] ?? 0 ) > 5 * 1024 * 1024 ) {
				return __( 'Each identity image must be no larger than 5 MB.', 'tutor-learning-suite' );
			}
			$checked = wp_check_filetype_and_ext( $file['tmp_name'], sanitize_file_name( $file['name'] ), $mimes );
			if ( empty( $checked['ext'] ) || empty( $checked['type'] ) || ! in_array( $checked['type'], array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
				return __( 'Identity images must be valid JPG, PNG or WebP files.', 'tutor-learning-suite' );
			}
		}
		return '';
	}

	/** Redirect to the referring dashboard page with a one-request form error. */
	private function redirect_verification_form_error( $message = '' ) {
		$user_id = get_current_user_id();
		if ( $user_id && is_string( $message ) && '' !== $message ) {
			set_transient( 'tlms_ipe_verification_error_' . absint( $user_id ), sanitize_text_field( $message ), 2 * MINUTE_IN_SECONDS );
		}
		$referer = wp_get_referer();
		$target  = $referer ? wp_validate_redirect( $referer, home_url( '/' ) ) : home_url( '/' );
		$target  = remove_query_arg( array( 'tlms_ipe_verify', 'tlms_ipe_verification_error' ), $target );
		wp_safe_redirect( add_query_arg( array( 'tlms_ipe_verify' => '1', 'tlms_ipe_verification_error' => '1' ), $target ) );
		exit;
	}

	/**
	 * Find another user who previously submitted the same identity number.
	 * The current user is always excluded so a legitimate reapplication with
	 * their own (unchanged) ID number is never flagged as a duplicate of
	 * themselves.
	 *
	 * @param string $id_hash        Hashed identity number.
	 * @param int    $exclude_user_id User id to exclude from the match (the current applicant).
	 * @return int
	 */
	private function find_duplicate_verification_id( $id_hash, $exclude_user_id = 0 ) {
		if ( ! $id_hash ) { return 0; }
		$args = array( 'number' => 2, 'fields' => 'ID', 'meta_key' => self::META_VERIFICATION_ID_HASH, 'meta_value' => $id_hash );
		$exclude_user_id = absint( $exclude_user_id );
		if ( $exclude_user_id ) {
			$args['exclude'] = array( $exclude_user_id );
		}
		$users = get_users( $args );
		return ! empty( $users[0] ) ? absint( $users[0] ) : 0;
	}

	/**
	 * Render the Home CTA / application form.

	 * @param string $dashboard_page_name Dashboard page name.
	 * @return void
	 */
	public function render_verification_dashboard_cta_before_template( $template, $variables ) {
		unset( $variables );
		if ( 'dashboard.instructor.home' !== $template ) {
			return;
		}
		$this->render_verification_dashboard_cta( 'home' );
	}

	public function render_verification_dashboard_cta( $dashboard_page_name ) {
		$user_id = get_current_user_id();
		if ( ! $user_id || ! $this->is_instructor_user( $user_id ) ) {
			return;
		}

		$page = strtolower( (string) $dashboard_page_name );
		if ( $page && false === strpos( $page, 'dashboard' ) && 'home' !== $page && 'index' !== $page ) {
			return;
		}

		if ( ! (bool) get_option( 'tlms_ipe_verification_cta_enabled', 1 ) ) {
			return;
		}

		$status = $this->get_verification_status( $user_id );
		if ( self::VERIFICATION_STATUS_VERIFIED === $status ) { return; }
		$gate = $this->get_verification_submission_gate( $user_id );

		$is_form = isset( $_GET['tlms_ipe_verify'] ) && '1' === (string) $_GET['tlms_ipe_verify']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$data = $this->get_verification_data( $user_id );
		?>
		<section class="tlms-ipe-verification-card">
			<?php
			$verification_error_message = get_transient( 'tlms_ipe_verification_error_' . absint( $user_id ) );
			if ( isset( $_GET['tlms_ipe_verification_error'] ) && '1' === (string) $_GET['tlms_ipe_verification_error'] ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				delete_transient( 'tlms_ipe_verification_error_' . absint( $user_id ) );
			?>
				<div class="tutor-alert tutor-warning" role="alert"><?php echo esc_html( $verification_error_message ? $verification_error_message : __( 'Your verification application could not be submitted. Check that your information and both identity images are valid, then try again.', 'tutor-learning-suite' ) ); ?></div>
			<?php endif; ?>
			<div class="tlms-ipe-verification-copy">
				<div class="tlms-ipe-verification-mark" aria-hidden="true">✓</div>
				<div>
					<h3><?php esc_html_e( 'Get verified', 'tutor-learning-suite' ); ?></h3>
					<p><?php esc_html_e( 'Apply for a verified instructor badge. Our team will review your information before adding the blue verification mark to your profile.', 'tutor-learning-suite' ); ?></p>
				</div>
			</div>

			<?php if ( self::VERIFICATION_STATUS_PENDING === $status ) : ?>
				<div class="tlms-ipe-verification-status tlms-ipe-verification-pending">
					<strong><?php esc_html_e( 'Application pending', 'tutor-learning-suite' ); ?></strong>
					<span><?php esc_html_e( 'Your verification application has been received and is waiting for admin review.', 'tutor-learning-suite' ); ?></span>
				</div>
			<?php elseif ( self::VERIFICATION_STATUS_REJECTED === $status ) : ?>
				<div class="tlms-ipe-verification-status tlms-ipe-verification-rejected">
					<strong><?php esc_html_e( 'Application needs attention', 'tutor-learning-suite' ); ?></strong>
					<span><?php esc_html_e( 'Your previous application was not approved. You can update the information below and submit again.', 'tutor-learning-suite' ); ?></span>
				</div>
			<?php endif; ?>

			<?php if ( ! $gate['allowed'] && ! in_array( $status, array( self::VERIFICATION_STATUS_PENDING, self::VERIFICATION_STATUS_REJECTED ), true ) ) : ?>
				<div class="tlms-ipe-verification-status tlms-ipe-verification-rejected"><strong><?php esc_html_e( 'Verification unavailable', 'tutor-learning-suite' ); ?></strong><span><?php echo esc_html( $gate['reason'] ); ?></span></div>
			<?php elseif ( ! $gate['allowed'] && self::VERIFICATION_STATUS_REJECTED === $status ) : ?>
				<div class="tlms-ipe-verification-status tlms-ipe-verification-rejected"><strong><?php esc_html_e( 'Reapplication unavailable', 'tutor-learning-suite' ); ?></strong><span><?php echo esc_html( $gate['reason'] ); ?></span></div>
			<?php endif; ?>

			<?php if ( ! $is_form && $gate['allowed'] ) : ?>
				<a class="tutor-btn tutor-btn-primary tlms-ipe-verification-cta" href="<?php echo esc_url( add_query_arg( 'tlms_ipe_verify', '1' ) ); ?>"><?php esc_html_e( 'Apply for verification', 'tutor-learning-suite' ); ?></a>
			<?php elseif ( $is_form && $gate['allowed'] ) : ?>
				<form class="tlms-ipe-verification-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
					<input type="hidden" name="action" value="tlms_ipe_submit_verification">
					<?php wp_nonce_field( 'tlms_ipe_submit_verification', '_tlms_ipe_nonce' ); ?>
					<div class="tlms-ipe-verification-grid">
						<label><span><?php esc_html_e( 'Legal full name', 'tutor-learning-suite' ); ?></span><input name="legal_name" type="text" required maxlength="120" value="<?php echo esc_attr( $data['legal_name'] ?? trim( get_user_meta( $user_id, 'first_name', true ) . ' ' . get_user_meta( $user_id, 'last_name', true ) ) ); ?>"></label>
						<label><span><?php esc_html_e( 'Email', 'tutor-learning-suite' ); ?></span><input name="email" type="email" required maxlength="190" value="<?php echo esc_attr( get_userdata( $user_id )->user_email ); ?>"></label>
						<label><span><?php esc_html_e( 'Phone', 'tutor-learning-suite' ); ?></span><input name="phone" type="tel" required maxlength="40" value="<?php echo esc_attr( $data['phone'] ?? get_user_meta( $user_id, 'phone_number', true ) ); ?>"></label>
						<label><span><?php esc_html_e( 'ID type', 'tutor-learning-suite' ); ?></span><select name="id_type" required><option value=""><?php esc_html_e( 'Select ID type', 'tutor-learning-suite' ); ?></option><?php foreach ( array( 'national_id' => __( 'National ID', 'tutor-learning-suite' ), 'passport' => __( 'Passport', 'tutor-learning-suite' ), 'driver_license' => __( 'Driver license', 'tutor-learning-suite' ) ) as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $data['id_type'] ?? '', $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
						<label><span><?php esc_html_e( 'ID number', 'tutor-learning-suite' ); ?></span><input name="id_number" type="text" required maxlength="80" autocomplete="off" value="<?php echo esc_attr( empty( $data['id_number_redacted_at'] ) ? ( $data['id_number'] ?? '' ) : '' ); ?>" placeholder="<?php echo esc_attr__( 'Enter the document number again if resubmitting', 'tutor-learning-suite' ); ?>"></label>
						<label><span><?php esc_html_e( 'ID front image', 'tutor-learning-suite' ); ?></span><input name="id_image_front" type="file" accept="image/jpeg,image/png,image/webp" required><small><?php esc_html_e( 'Front side — JPG, PNG or WebP, max 5 MB.', 'tutor-learning-suite' ); ?></small></label>
						<label><span><?php esc_html_e( 'ID back image', 'tutor-learning-suite' ); ?></span><input name="id_image_back" type="file" accept="image/jpeg,image/png,image/webp" required><small><?php esc_html_e( 'Back side — JPG, PNG or WebP, max 5 MB.', 'tutor-learning-suite' ); ?></small></label>
						<label class="tlms-ipe-verification-full"><span><?php esc_html_e( 'Address', 'tutor-learning-suite' ); ?></span><input name="address" type="text" required maxlength="255" value="<?php echo esc_attr( $data['address'] ?? '' ); ?>"></label>
						<label class="tlms-ipe-verification-full"><span><?php esc_html_e( 'Additional information', 'tutor-learning-suite' ); ?></span><textarea name="notes" maxlength="1000" rows="4" placeholder="<?php echo esc_attr__( 'Anything that can help us verify your instructor profile…', 'tutor-learning-suite' ); ?>"><?php echo esc_textarea( $data['notes'] ?? '' ); ?></textarea></label>
						<div class="tlms-ipe-verification-full tlms-ipe-verification-id-notice"><strong><?php esc_html_e( 'ID privacy notice:', 'tutor-learning-suite' ); ?></strong> <?php esc_html_e( 'Uploaded ID card/passport images are securely stored for review and permanently deleted after the verification decision is made. Your ID number is also redacted to its last few digits at that point.', 'tutor-learning-suite' ); ?></div>
					</div>
					<div class="tlms-ipe-verification-form-actions"><a href="<?php echo esc_url( remove_query_arg( array( 'tlms_ipe_verify', 'tlms_ipe_verification_error' ) ) ); ?>" class="tutor-btn tutor-btn-outline"><?php esc_html_e( 'Cancel', 'tutor-learning-suite' ); ?></a><button type="submit" class="tutor-btn tutor-btn-primary"><?php esc_html_e( 'Submit application', 'tutor-learning-suite' ); ?></button></div>
				</form>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Redirect verification ID uploads into a non-public, access-controlled
	 * subdirectory of the uploads folder instead of the normal (world
	 * readable) monthly uploads path, since these files are identity
	 * documents. Used as a one-shot 'upload_dir' filter.
	 *
	 * @param array $dirs Default upload dir info.
	 * @return array
	 */
    private function verification_storage_dir() {
        $root = defined( 'TLS_PRIVATE_STORAGE_DIR' ) && is_string( TLS_PRIVATE_STORAGE_DIR )
            ? rtrim( TLS_PRIVATE_STORAGE_DIR, '/\\' )
            : rtrim( dirname( rtrim( ABSPATH, '/\\' ) ), '/\\' ) . '/tls-private-data';
        $root = wp_normalize_path( $root );
        $web_root = realpath( ABSPATH );
        $document_root = ! empty( $_SERVER['DOCUMENT_ROOT'] ) ? realpath( (string) $_SERVER['DOCUMENT_ROOT'] ) : false;
        $root_real = realpath( $root );
        $root_check = $root_real ? wp_normalize_path( $root_real ) : trailingslashit( $root );
        if ( '' === $root || ( $web_root && 0 === stripos( trailingslashit( $root_check ), trailingslashit( wp_normalize_path( $web_root ) ) ) ) ) {
            return false;
        }
        // ABSPATH can be a subdirectory of the actual document root. Reject that
        // layout too; a path outside ABSPATH may still be publicly served.
        if ( $document_root && 0 === stripos( trailingslashit( $root_check ), trailingslashit( wp_normalize_path( $document_root ) ) ) ) {
            return false;
        }
        return trailingslashit( $root ) . 'instructor-verification';
    }

    /** Never generate a public URL for an identity-verification attachment. */
    private function is_private_verification_attachment( $attachment_id ) {
        $file = get_attached_file( absint( $attachment_id ) );
        if ( ! $file ) return false;
        $private = realpath( $this->verification_storage_dir() );
        $real = realpath( $file );
        if ( ! $private || ! $real ) return false;
        $private = trailingslashit( wp_normalize_path( $private ) );
        return 0 === strpos( wp_normalize_path( $real ), $private );
    }

    public function filter_private_verification_attachment_url( $url, $attachment_id ) {
        return $this->is_private_verification_attachment( $attachment_id ) ? '' : $url;
    }

    public function filter_private_verification_attachment_image_src( $image, $attachment_id, $size, $icon ) {
        return $this->is_private_verification_attachment( $attachment_id ) ? false : $image;
    }

    /** Redirect identity-document uploads outside the public web root. */
    public function filter_verification_upload_dir( $dirs ) {
        $dir = $this->verification_storage_dir();
        if ( ! $dir ) {
            return $dirs;
        }
        $dirs['basedir'] = dirname( $dir );
        $dirs['baseurl'] = '';
        $dirs['subdir']  = '';
        $dirs['path']    = $dir;
        $dirs['url']     = '';
        return $dirs;
    }

    /** Ensure private storage exists; .htaccess is extra protection, not the security boundary. */
    private function ensure_verification_upload_dir_protected() {
        $dir = $this->verification_storage_dir();
        if ( ! $dir || ! wp_mkdir_p( $dir ) || ! is_writable( $dir ) ) {
            return false;
        }
        $htaccess = $dir . '/.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            @file_put_contents( $htaccess, "Require all denied\n\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }
        $index = $dir . '/index.php';
        if ( ! file_exists( $index ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            @file_put_contents( $index, "<?php // Silence is golden.\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }
        return true;
    }

    /** Move an old public-upload verification attachment into private storage. */
    private function migrate_verification_attachment_to_private( $attachment_id ) {
        $attachment_id = absint( $attachment_id );
        if ( ! $attachment_id || ! $this->ensure_verification_upload_dir_protected() ) {
            return false;
        }
        $file = get_attached_file( $attachment_id );
        $real_file = $file ? realpath( $file ) : false;
        $uploads = wp_upload_dir();
        $legacy_dir = trailingslashit( wp_normalize_path( $uploads['basedir'] ) ) . 'tlms-ipe-verification/';
        $private_dir = trailingslashit( wp_normalize_path( $this->verification_storage_dir() ) );
        if ( ! $real_file ) {
            return false;
        }
        $real_file = wp_normalize_path( $real_file );
        if ( 0 === strpos( $real_file, $private_dir ) ) {
            return true;
        }
        if ( 0 !== strpos( $real_file, $legacy_dir ) || ! is_file( $real_file ) ) {
            return false;
        }

        $metadata = wp_get_attachment_metadata( $attachment_id );
        $filenames = array( basename( $real_file ) );
        if ( is_array( $metadata ) && ! empty( $metadata['sizes'] ) ) {
            foreach ( $metadata['sizes'] as $size ) {
                if ( ! empty( $size['file'] ) && basename( $size['file'] ) === $size['file'] ) {
                    $filenames[] = $size['file'];
                }
            }
        }
        $copied = array();
        foreach ( array_unique( $filenames ) as $filename ) {
            $source = dirname( $real_file ) . '/' . $filename;
            if ( ! is_file( $source ) ) {
                continue;
            }
            $target = $private_dir . $filename;
            if ( file_exists( $target ) && filesize( $target ) === filesize( $source ) ) {
                $copied[] = array( $source, $target, false );
                continue;
            }
            if ( ! @copy( $source, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                foreach ( $copied as $item ) { if ( $item[2] && file_exists( $item[1] ) ) wp_delete_file( $item[1] ); }
                return false;
            }
            @chmod( $target, 0640 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            $copied[] = array( $source, $target, true );
        }
        $target_file = $private_dir . basename( $real_file );
        update_post_meta( $attachment_id, '_wp_attached_file', $target_file );
        if ( wp_normalize_path( get_attached_file( $attachment_id ) ) !== $target_file || ! is_file( $target_file ) ) {
            foreach ( $copied as $item ) { if ( $item[2] && file_exists( $item[1] ) ) wp_delete_file( $item[1] ); }
            update_post_meta( $attachment_id, '_wp_attached_file', _wp_relative_upload_path( $real_file ) ?: $real_file );
            return false;
        }
        foreach ( $copied as $item ) {
            if ( is_file( $item[0] ) ) {
                wp_delete_file( $item[0] );
            }
        }
        return true;
    }

    /** Batch-migrate legacy ID images so the old public upload folder can be retired. */
    public function migrate_legacy_verification_batch() {
        // admin_init runs for admin-ajax.php too. Large private-file migrations
        // must not run during Tutor's dashboard/analytics AJAX requests.
        if ( ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) ||
            ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) ||
            ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
            return;
        }
        if ( get_option( 'tlms_ipe_private_storage_migrated' ) ) {
            return;
        }
        global $wpdb;
        $cursor = absint( get_option( 'tlms_ipe_private_storage_cursor', 0 ) );
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key=%s AND user_id>%d GROUP BY user_id ORDER BY user_id ASC LIMIT 40",
            self::META_VERIFICATION_DATA,
            $cursor
        ) );
        if ( empty( $ids ) ) {
            if ( 0 === (int) get_option( 'tlms_ipe_private_storage_failures', 0 ) ) {
                update_option( 'tlms_ipe_private_storage_migrated', 1, false );
                delete_option( 'tlms_ipe_private_storage_needs_review' );
                delete_option( 'tlms_ipe_private_storage_cursor' );
            } else {
                update_option( 'tlms_ipe_private_storage_needs_review', 1, false );
            }
            return;
        }
        $failed = (int) get_option( 'tlms_ipe_private_storage_failures', 0 );
        foreach ( $ids as $owner_id ) {
            $data = get_user_meta( absint( $owner_id ), self::META_VERIFICATION_DATA, true );
            if ( ! is_array( $data ) ) {
                continue;
            }
            // Retain only the final four characters after the HMAC duplicate
            // check has been recorded; reviewers inspect the private ID image.
            if ( ! empty( $data['id_number'] ) && empty( $data['id_number_redacted_at'] ) ) {
                $normalized = $this->normalize_verification_id_number( $data['id_number'] );
                $data['id_number'] = strlen( $normalized ) > 4 ? str_repeat( '*', strlen( $normalized ) - 4 ) . substr( $normalized, -4 ) : str_repeat( '*', strlen( $normalized ) );
                $data['id_number_redacted_at'] = current_time( 'mysql' );
                update_user_meta( absint( $owner_id ), self::META_VERIFICATION_DATA, $data );
            }
            foreach ( array( 'id_image_front_id', 'id_image_back_id', 'id_image_id' ) as $key ) {
                $attachment_id = absint( $data[ $key ] ?? 0 );
                if ( $attachment_id && ! $this->migrate_verification_attachment_to_private( $attachment_id ) ) {
                    $failed++;
                }
            }
        }
        update_option( 'tlms_ipe_private_storage_failures', $failed, false );
        update_option( 'tlms_ipe_private_storage_cursor', max( array_map( 'absint', $ids ) ), false );
        if ( count( $ids ) < 40 && 0 === $failed ) {
            update_option( 'tlms_ipe_private_storage_migrated', 1, false );
            delete_option( 'tlms_ipe_private_storage_needs_review' );
            delete_option( 'tlms_ipe_private_storage_cursor' );
        } elseif ( count( $ids ) < 40 ) {
            update_option( 'tlms_ipe_private_storage_needs_review', 1, false );
        }
    }

	/**
	 * Process instructor verification application.

	 * @return void
	 */
	public function handle_verification_submission() {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'You must be logged in to apply for instructor verification.', 'tutor-learning-suite' ) );
		}
		$user_id = get_current_user_id();
		if ( ! $this->is_instructor_user( $user_id ) ) {
			wp_die( esc_html__( 'Only instructors can submit a verification application.', 'tutor-learning-suite' ) );
		}
		if ( ! isset( $_POST['_tlms_ipe_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_tlms_ipe_nonce'] ) ), 'tlms_ipe_submit_verification' ) ) {
			$this->redirect_verification_form_error();
		}

		$gate = $this->get_verification_submission_gate( $user_id );
		if ( ! $gate['allowed'] ) { wp_die( esc_html( $gate['reason'] ) ); }
		$upload_error = $this->validate_verification_uploads();
		if ( '' !== $upload_error ) {
			$this->redirect_verification_form_error( $upload_error );
		}

		$upload_ids = array( 'front' => 0, 'back' => 0 );
		$clean = array(
			'legal_name' => sanitize_text_field( wp_unslash( $_POST['legal_name'] ?? '' ) ),
			'email'      => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
			'phone'      => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ),
			'id_type'    => sanitize_key( wp_unslash( $_POST['id_type'] ?? '' ) ),
			'id_number'  => sanitize_text_field( wp_unslash( $_POST['id_number'] ?? '' ) ),
			'address'    => sanitize_text_field( wp_unslash( $_POST['address'] ?? '' ) ),
			'notes'      => sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ),
			'submitted_at' => current_time( 'mysql' ),
		);

		// All verification fields except Additional information are mandatory.
		$valid_id_types = array( 'national_id', 'passport', 'driver_license' );
		if ( ! is_email( $clean['email'] ) || '' === $clean['legal_name'] || '' === $clean['phone'] || ! in_array( $clean['id_type'], $valid_id_types, true ) || '' === $clean['id_number'] || '' === $clean['address'] || empty( $_FILES['id_image_front']['name'] ) || empty( $_FILES['id_image_back']['name'] ) ) {
			$this->redirect_verification_form_error();
		}

		$settings = $this->get_verification_protection_settings();
		$id_hash = $this->get_verification_id_hash( $clean['id_number'] );
		$normalized_id_for_mask = $this->normalize_verification_id_number( $clean['id_number'] );
		$clean['id_number'] = strlen( $normalized_id_for_mask ) > 4 ? str_repeat( '*', strlen( $normalized_id_for_mask ) - 4 ) . substr( $normalized_id_for_mask, -4 ) : str_repeat( '*', strlen( $normalized_id_for_mask ) );
		$clean['id_number_redacted_at'] = current_time( 'mysql' );
		if ( $settings['block_duplicate'] && $id_hash && $this->find_duplicate_verification_id( $id_hash, $user_id ) ) {
			wp_die( esc_html__( 'This identity document has already been used for an instructor verification application. Please contact an administrator if this is your legitimate account.', 'tutor-learning-suite' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$mimes = array( 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' );
		if ( ! $this->ensure_verification_upload_dir_protected() ) {
			wp_die( esc_html__( 'Private verification storage is not writable or is configured inside the public web root. Ask the site administrator to configure TLS_PRIVATE_STORAGE_DIR.', 'tutor-learning-suite' ) );
		}
		$stored_paths = array();
		add_filter( 'upload_dir', array( $this, 'filter_verification_upload_dir' ) );
		foreach ( array( 'front', 'back' ) as $side ) {
			$file_key = 'id_image_' . $side;
			if ( ! isset( $_FILES[ $file_key ] ) || empty( $_FILES[ $file_key ]['name'] ) || ! empty( $_FILES[ $file_key ]['error'] ) ) {
				remove_filter( 'upload_dir', array( $this, 'filter_verification_upload_dir' ) );
				$this->redirect_verification_form_error();
			}
			if ( ! empty( $_FILES[ $file_key ]['size'] ) && (int) $_FILES[ $file_key ]['size'] > 5 * 1024 * 1024 ) {
				remove_filter( 'upload_dir', array( $this, 'filter_verification_upload_dir' ) );
				$this->redirect_verification_form_error();
			}
			$upload = wp_handle_upload( $_FILES[ $file_key ], array( 'test_form' => false, 'mimes' => $mimes ) );
			if ( isset( $upload['error'] ) ) {
				foreach ( $upload_ids as $existing_id ) { if ( $existing_id ) wp_delete_attachment( $existing_id, true ); }
				foreach ( $stored_paths as $existing_path ) { if ( is_file( $existing_path ) ) wp_delete_file( $existing_path ); }
				remove_filter( 'upload_dir', array( $this, 'filter_verification_upload_dir' ) );
				$this->redirect_verification_form_error();
			}
			$attachment = array( 'post_mime_type' => $upload['type'], 'post_title' => sanitize_file_name( pathinfo( $upload['file'], PATHINFO_FILENAME ) ), 'post_status' => 'private', 'post_author' => $user_id );
			$attachment_id = wp_insert_attachment( $attachment, $upload['file'], 0 );
			if ( ! $attachment_id ) {
				@unlink( $upload['file'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				foreach ( $upload_ids as $existing_id ) { if ( $existing_id ) wp_delete_attachment( $existing_id, true ); }
				foreach ( $stored_paths as $existing_path ) { if ( is_file( $existing_path ) ) wp_delete_file( $existing_path ); }
				remove_filter( 'upload_dir', array( $this, 'filter_verification_upload_dir' ) );
				$this->redirect_verification_form_error();
			}
			wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );
			$upload_ids[ $side ] = absint( $attachment_id );
			$stored_paths[ $side ] = $upload['file'];
		}
		remove_filter( 'upload_dir', array( $this, 'filter_verification_upload_dir' ) );
		// Store absolute paths because the private storage directory is outside
		// WordPress uploads; get_attached_file() must not prepend public uploads.
		foreach ( array( 'front', 'back' ) as $side ) {
			if ( $upload_ids[ $side ] && ! empty( $stored_paths[ $side ] ) ) {
				update_post_meta( $upload_ids[ $side ], '_wp_attached_file', $stored_paths[ $side ] );
			}
		}

		$clean['id_image_front_id'] = $upload_ids['front'];
		$clean['id_image_back_id']  = $upload_ids['back'];
		// Keep the legacy key empty/compatible so older admin integrations do not fail.
		$clean['id_image_id'] = 0;
		update_user_meta( $user_id, self::META_VERIFICATION_DATA, $clean );
		update_user_meta( $user_id, self::META_VERIFICATION_ID_HASH, $id_hash );
		$attempts = absint( get_user_meta( $user_id, self::META_VERIFICATION_ATTEMPTS, true ) ) + 1;
		update_user_meta( $user_id, self::META_VERIFICATION_ATTEMPTS, $attempts );
		update_user_meta( $user_id, self::META_VERIFICATION_LAST_SUBMISSION, time() );
		$history = $this->get_verification_history( $user_id );
		$history[] = array( 'attempt' => $attempts, 'submitted_at' => $clean['submitted_at'], 'status' => self::VERIFICATION_STATUS_PENDING, 'id_type' => $clean['id_type'], 'id_hash' => $id_hash );
		update_user_meta( $user_id, self::META_VERIFICATION_HISTORY, array_slice( $history, -20 ) );
		update_user_meta( $user_id, self::META_VERIFICATION_STATUS, self::VERIFICATION_STATUS_PENDING );

		$recipient = sanitize_email( get_option( 'tlms_ipe_verification_recipient', get_option( 'admin_email' ) ) );
		if ( is_email( $recipient ) ) {
			$user = get_userdata( $user_id );
			$subject = sprintf( __( '[%s] New instructor verification application', 'tutor-learning-suite' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
			// Deliberately does not include the ID number, phone, or address:
			// that's sensitive identity data and doesn't belong in plaintext
			// email. The admin reviews it on the (capability-gated) admin page.
			$message = sprintf(
				"A new instructor verification application was submitted.

Instructor: %s (%s)

Review it in WordPress Admin → Tutor LMS → Instructor Verification:
%s",
				$clean['legal_name'],
				$user ? $user->user_login : $clean['email'],
				admin_url( 'admin.php?page=tlms-ipe-verification' )
			);
			wp_mail( $recipient, $subject, $message );
		}

		$redirect = remove_query_arg( 'tlms_ipe_verify', wp_get_referer() ?: home_url( '/' ) );
		wp_safe_redirect( add_query_arg( 'tlms_ipe_verification_submitted', '1', $redirect ) );
		exit;
	}

	/**
	 * Admin approve/reject action.

	 * @return void
	 */
	public function handle_admin_verification_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage verification applications.', 'tutor-learning-suite' ) );
		}
		check_admin_referer( 'tlms_ipe_verification_action', '_tlms_ipe_admin_nonce' );
		$user_id = absint( $_POST['user_id'] ?? 0 );
		$decision = sanitize_key( wp_unslash( $_POST['decision'] ?? '' ) );
		$reason = sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) );
		$this->apply_admin_verification_decision( $user_id, $decision, $reason );
		wp_safe_redirect( add_query_arg( 'tlms_ipe_updated', '1', admin_url( 'admin.php?page=tlms-ipe-verification' ) ) );
		exit;
	}

	/**
	 * Stream an ID verification image to an authorized admin only. Replaces
	 * linking directly to the (otherwise world-readable) attachment URL,
	 * since these are identity documents.
	 *
	 * @return void
	 */
	public function handle_view_id_image() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to view verification documents.', 'tutor-learning-suite' ) );
		}
		check_admin_referer( 'tlms_ipe_view_id_image' );

		$attachment_id = absint( $_GET['attachment_id'] ?? 0 );
		$owner_id      = absint( $_GET['user_id'] ?? 0 );
		if ( ! $attachment_id || ! $owner_id ) {
			wp_die( esc_html__( 'Invalid document.', 'tutor-learning-suite' ) );
		}

		// Confirm this attachment is actually one of the current verification
		// images on file for the given user, rather than trusting the id blindly.
		$data  = $this->get_verification_data( $owner_id );
		$valid = array( absint( $data['id_image_front_id'] ?? 0 ), absint( $data['id_image_back_id'] ?? 0 ), absint( $data['id_image_id'] ?? 0 ) );
		if ( ! in_array( $attachment_id, array_filter( $valid ), true ) ) {
			wp_die( esc_html__( 'This document is no longer available.', 'tutor-learning-suite' ) );
		}

		$this->migrate_verification_attachment_to_private( $attachment_id );
		$file = get_attached_file( $attachment_id );
		// Defense in depth: ID documents must live outside the public web root.
		$expected_dir = trailingslashit( wp_normalize_path( $this->verification_storage_dir() ) );
		$real_file    = $file ? wp_normalize_path( realpath( $file ) ) : false;
		if ( ! $real_file || 0 !== strpos( $real_file, $expected_dir ) || ! file_exists( $real_file ) ) {
			wp_die( esc_html__( 'This document is no longer available.', 'tutor-learning-suite' ) );
		}

		$mime = get_post_mime_type( $attachment_id ) ?: 'application/octet-stream';
		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . filesize( $real_file ) );
		header( 'Content-Disposition: inline; filename="' . sanitize_file_name( basename( $real_file ) ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $real_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/** Build a nonce-protected admin URL to view a verification ID image, or empty string if the attachment no longer exists. */
	private function get_view_id_image_url( $attachment_id, $owner_id ) {
		$attachment_id = absint( $attachment_id );
		if ( ! $attachment_id ) {
			return '';
		}
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=tlms_ipe_view_id_image&attachment_id=' . $attachment_id . '&user_id=' . absint( $owner_id ) ),
			'tlms_ipe_view_id_image'
		);
	}

	/** Unlock an instructor's verification account. */
	public function handle_admin_verification_unlock() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'You are not allowed to manage verification applications.', 'tutor-learning-suite' ) ); }
		check_admin_referer( 'tlms_ipe_verification_unlock', '_tlms_ipe_unlock_nonce' );
		$user_id = absint( $_POST['user_id'] ?? 0 );
		delete_user_meta( $user_id, self::META_VERIFICATION_LOCKED );
		wp_safe_redirect( add_query_arg( 'tlms_ipe_updated', '1', admin_url( 'admin.php?page=tlms-ipe-verification' ) ) );
		exit;
	}

	/**
	 * Apply a verification decision and remove the uploaded ID image after review.
	 *
	 * @param int    $user_id  User id.
	 * @param string $decision Verification status.
	 * @param string $reason Rejection reason.
	 * @return bool
	 */
	private function apply_admin_verification_decision( $user_id, $decision, $reason = '' ) {
		$user_id = absint( $user_id );
		$allowed = array( self::VERIFICATION_STATUS_VERIFIED, self::VERIFICATION_STATUS_REJECTED, self::VERIFICATION_STATUS_PENDING, self::VERIFICATION_STATUS_NONE, self::VERIFICATION_STATUS_REVOKED );
		if ( ! $user_id || ! in_array( $decision, $allowed, true ) ) {
			return false;
		}

		$data = $this->get_verification_data( $user_id );
		if ( self::VERIFICATION_STATUS_REJECTED === $decision && '' === trim( $reason ) ) { $reason = __( 'Verification requirements were not met.', 'tutor-learning-suite' ); }
		if ( in_array( $decision, array( self::VERIFICATION_STATUS_VERIFIED, self::VERIFICATION_STATUS_REJECTED ), true ) ) {
			// New applications store separate front/back image attachment IDs.
			foreach ( array( 'id_image_front_id', 'id_image_back_id', 'id_image_id' ) as $image_key ) {
				if ( empty( $data[ $image_key ] ) ) {
					continue;
				}
				$attachment_id = absint( $data[ $image_key ] );
				if ( $attachment_id ) {
					wp_delete_attachment( $attachment_id, true );
				}
				$data[ $image_key ] = 0;
			}
			$data['id_image_removed_at'] = current_time( 'mysql' );
			if ( self::VERIFICATION_STATUS_REJECTED === $decision ) { $data['rejection_reason'] = $reason; }
			// Minimize retention of the raw identity number now that the
			// decision is made: keep only enough to help an admin recognize
			// which document was reviewed, not the full number. The dedupe
			// hash (a separate, non-reversible meta field) still fully
			// protects against reapplication with the same document.
			if ( ! empty( $data['id_number'] ) && empty( $data['id_number_redacted_at'] ) ) {
				$raw = (string) $data['id_number'];
				$data['id_number'] = ( strlen( $raw ) > 4 ) ? str_repeat( '*', max( 0, strlen( $raw ) - 4 ) ) . substr( $raw, -4 ) : str_repeat( '*', strlen( $raw ) );
				$data['id_number_redacted_at'] = current_time( 'mysql' );
			}
			update_user_meta( $user_id, self::META_VERIFICATION_DATA, $data );
			$history = $this->get_verification_history( $user_id );
			for ( $i = count( $history ) - 1; $i >= 0; $i-- ) {
				if ( self::VERIFICATION_STATUS_PENDING === ( $history[ $i ]['status'] ?? '' ) ) {
					$history[ $i ]['status'] = $decision;
					$history[ $i ]['decided_at'] = current_time( 'mysql' );
					if ( self::VERIFICATION_STATUS_REJECTED === $decision ) { $history[ $i ]['rejection_reason'] = $reason; }
					break;
				}
			}
			update_user_meta( $user_id, self::META_VERIFICATION_HISTORY, $history );
		}

		update_user_meta( $user_id, self::META_VERIFICATION_STATUS, $decision );
		return true;
	}

	/**
	 * Render verification applications in WP admin.

	 * @return void
	 */
	public function render_verification_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$recipient_error = false;
		if ( isset( $_POST['tlms_ipe_save_settings'] ) ) {
			check_admin_referer( 'tlms_ipe_save_settings', '_tlms_ipe_settings_nonce' );
			$recipient = sanitize_email( wp_unslash( $_POST['recipient'] ?? '' ) );
			if ( is_email( $recipient ) ) {
				update_option( 'tlms_ipe_verification_recipient', $recipient );
			} else {
				$recipient_error = true;
			}
			update_option( 'tlms_ipe_verification_cta_enabled', isset( $_POST['verification_cta_enabled'] ) ? 1 : 0 );
			update_option( 'tlms_ipe_verification_max_attempts', min( 20, max( 1, absint( $_POST['max_attempts'] ?? 3 ) ) ) );
			update_option( 'tlms_ipe_verification_cooldown_days', min( 365, absint( $_POST['cooldown_days'] ?? 30 ) ) );
			update_option( 'tlms_ipe_verification_block_duplicate', isset( $_POST['block_duplicate'] ) ? 1 : 0 );
			update_option( 'tlms_ipe_verification_rate_limit', isset( $_POST['rate_limit'] ) ? 1 : 0 );
		}

		$per_page     = 30;
		$current_page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$query        = new WP_User_Query( array(
			'number'  => $per_page,
			'offset'  => ( $current_page - 1 ) * $per_page,
			'orderby' => 'registered',
			'order'   => 'DESC',
			'meta_query' => array( array(
				'key' => self::META_VERIFICATION_STATUS,
				'value' => array( self::VERIFICATION_STATUS_PENDING, self::VERIFICATION_STATUS_VERIFIED, self::VERIFICATION_STATUS_REJECTED, self::VERIFICATION_STATUS_REVOKED ),
				'compare' => 'IN',
			) ),
		) );
		$users       = $query->get_results();
		$total_users = (int) $query->get_total();
		$total_pages = max( 1, (int) ceil( $total_users / $per_page ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Instructor Verification', 'tutor-learning-suite' ); ?></h1>
			<?php if ( $recipient_error ) : ?><div class="notice notice-error"><p><?php esc_html_e( 'The notification address was invalid. The previous address was kept.', 'tutor-learning-suite' ); ?></p></div><?php endif; ?>
			<p><?php esc_html_e( 'Review verification applications and control which instructors receive the blue verified mark.', 'tutor-learning-suite' ); ?></p>
			<form method="post" style="max-width:700px;margin:20px 0 30px;padding:20px;background:#fff;border:1px solid #dcdcde;">
				<h2 style="margin-top:0"><?php esc_html_e( 'Application notifications', 'tutor-learning-suite' ); ?></h2>
				<?php wp_nonce_field( 'tlms_ipe_save_settings', '_tlms_ipe_settings_nonce' ); ?>
				<table class="form-table"><tr><th><label for="tlms-ipe-recipient"><?php esc_html_e( 'Receive applications at', 'tutor-learning-suite' ); ?></label></th><td><input id="tlms-ipe-recipient" name="recipient" type="email" class="regular-text" value="<?php echo esc_attr( get_option( 'tlms_ipe_verification_recipient', get_option( 'admin_email' ) ) ); ?>" required><p class="description"><?php esc_html_e( 'A notification email is sent whenever an instructor submits a new application.', 'tutor-learning-suite' ); ?></p></td></tr>
					<tr><th><?php esc_html_e( 'Tutor verification CTA', 'tutor-learning-suite' ); ?></th><td><label><input type="checkbox" name="verification_cta_enabled" value="1" <?php checked( (bool) get_option( 'tlms_ipe_verification_cta_enabled', 1 ) ); ?>> <?php esc_html_e( 'Show the verification CTA to instructors', 'tutor-learning-suite' ); ?></label><p class="description"><?php esc_html_e( 'Disable this to hide the Get verified / Apply for verification card.', 'tutor-learning-suite' ); ?></p></td></tr>
				
					<tr><th><label for="tlms-ipe-max-attempts">Verification attempts</label></th><td><input id="tlms-ipe-max-attempts" name="max_attempts" type="number" min="1" max="20" class="small-text" value="<?php echo esc_attr( get_option( 'tlms_ipe_verification_max_attempts', 3 ) ); ?>"> <span class="description">Maximum lifetime attempts before an admin unlock is required.</span></td></tr>
					<tr><th><label for="tlms-ipe-cooldown">Reapply cooldown</label></th><td><input id="tlms-ipe-cooldown" name="cooldown_days" type="number" min="0" max="365" class="small-text" value="<?php echo esc_attr( get_option( 'tlms_ipe_verification_cooldown_days', 30 ) ); ?>"> days after rejection.</td></tr>
					<tr><th>Duplicate identity protection</th><td><label><input type="checkbox" name="block_duplicate" value="1" <?php checked( (bool) get_option( 'tlms_ipe_verification_block_duplicate', 1 ) ); ?>> Block an identity number already used in verification.</label></td></tr>
					<tr><th>Submission rate limit</th><td><label><input type="checkbox" name="rate_limit" value="1" <?php checked( (bool) get_option( 'tlms_ipe_verification_rate_limit', 1 ) ); ?>> Block rapid repeat submissions for 60 seconds.</label></td></tr>
</table>
				<p><button name="tlms_ipe_save_settings" class="button button-primary"><?php esc_html_e( 'Save settings', 'tutor-learning-suite' ); ?></button></p>
			</form>

			<h2><?php esc_html_e( 'Verification applications', 'tutor-learning-suite' ); ?></h2>
			<?php if ( ! $users ) : ?><p><?php esc_html_e( 'No verification applications yet.', 'tutor-learning-suite' ); ?></p><?php else : ?>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Instructor', 'tutor-learning-suite' ); ?></th>
					<th><?php esc_html_e( 'Contact', 'tutor-learning-suite' ); ?></th>
					<th><?php esc_html_e( 'Identity', 'tutor-learning-suite' ); ?></th>
					<th><?php esc_html_e( 'Address', 'tutor-learning-suite' ); ?></th>
					<th><?php esc_html_e( 'Additional information', 'tutor-learning-suite' ); ?></th>
					<th><?php esc_html_e( 'Status', 'tutor-learning-suite' ); ?></th>
					<th><?php esc_html_e( 'Submitted', 'tutor-learning-suite' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'tutor-learning-suite' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $users as $user ) : ?>
					<?php
					$data   = $this->get_verification_data( $user->ID );
					$status = $this->get_verification_status( $user->ID );
					$id_types = array(
						'national_id'   => __( 'National ID', 'tutor-learning-suite' ),
						'passport'      => __( 'Passport', 'tutor-learning-suite' ),
						'driver_license'=> __( 'Driver license', 'tutor-learning-suite' ),
					);
					$id_type = $id_types[ $data['id_type'] ?? '' ] ?? ( $data['id_type'] ?? '' );
					$front_id = ! empty( $data['id_image_front_id'] ) ? absint( $data['id_image_front_id'] ) : 0;
					$back_id  = ! empty( $data['id_image_back_id'] ) ? absint( $data['id_image_back_id'] ) : 0;
					$legacy_id = ! empty( $data['id_image_id'] ) ? absint( $data['id_image_id'] ) : 0;
					?>
					<tr>
						<td><strong><?php echo esc_html( $data['legal_name'] ?? $user->display_name ); ?></strong><br><span class="description">@<?php echo esc_html( $user->user_login ); ?></span><br><a href="<?php echo esc_url( get_edit_user_link( $user->ID ) ); ?>">#<?php echo esc_html( $user->ID ); ?></a></td>
						<td><?php echo esc_html( $data['email'] ?? $user->user_email ); ?><br><?php echo esc_html( $data['phone'] ?? '' ); ?></td>
						<td><strong><?php echo esc_html( $id_type ); ?></strong><br><?php echo esc_html( $data['id_number'] ?? '' ); ?><br><br>
							<?php $front_view_url = $front_id ? $this->get_view_id_image_url( $front_id, $user->ID ) : ''; ?>
							<?php if ( $front_view_url ) : ?><a href="<?php echo esc_url( $front_view_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View ID front', 'tutor-learning-suite' ); ?></a><?php else : ?><span class="description"><?php esc_html_e( 'ID front deleted after review', 'tutor-learning-suite' ); ?></span><?php endif; ?><br>
							<?php $back_view_url = $back_id ? $this->get_view_id_image_url( $back_id, $user->ID ) : ''; ?>
							<?php if ( $back_view_url ) : ?><a href="<?php echo esc_url( $back_view_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View ID back', 'tutor-learning-suite' ); ?></a><?php else : ?><span class="description"><?php esc_html_e( 'ID back deleted after review', 'tutor-learning-suite' ); ?></span><?php endif; ?>
							<?php $legacy_view_url = $legacy_id ? $this->get_view_id_image_url( $legacy_id, $user->ID ) : ''; ?>
							<?php if ( $legacy_view_url ) : ?><br><a href="<?php echo esc_url( $legacy_view_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View legacy ID image', 'tutor-learning-suite' ); ?></a><?php endif; ?>
						</td>
						<td><?php echo nl2br( esc_html( $data['address'] ?? $data['wilaya'] ?? '' ) ); ?></td>
						<td><?php if ( ! empty( $data['rejection_reason'] ) ) : ?><strong><?php esc_html_e( 'Rejection:', 'tutor-learning-suite' ); ?></strong> <?php echo esc_html( $data['rejection_reason'] ); ?><br><br><?php endif; ?><?php echo '' !== trim( (string) ( $data['notes'] ?? '' ) ) ? nl2br( esc_html( $data['notes'] ) ) : '<span class="description">' . esc_html__( 'None provided', 'tutor-learning-suite' ) . '</span>'; ?></td>
						<td><?php echo esc_html( ucfirst( $status ) ); ?></td>
						<td><?php echo esc_html( $data['submitted_at'] ?? '' ); ?><br><small><?php echo esc_html( sprintf( __( 'Attempt %d of %d', 'tutor-learning-suite' ), absint( get_user_meta( $user->ID, self::META_VERIFICATION_ATTEMPTS, true ) ), absint( get_option( 'tlms_ipe_verification_max_attempts', 3 ) ) ) ); ?></small><?php $history = $this->get_verification_history( $user->ID ); ?><?php if ( $history ) : ?><details style="margin-top:6px;"><summary><?php esc_html_e( 'History', 'tutor-learning-suite' ); ?></summary><ol style="margin-left:18px;">
							<?php foreach ( array_reverse( $history ) as $entry ) : ?><li><?php echo esc_html( ucfirst( $entry['status'] ?? 'unknown' ) ); ?> — <?php echo esc_html( $entry['submitted_at'] ?? '' ); ?><?php if ( ! empty( $entry['decided_at'] ) ) : ?> → <?php echo esc_html( $entry['decided_at'] ); ?><?php endif; ?><?php if ( ! empty( $entry['rejection_reason'] ) ) : ?><br><small><?php echo esc_html( $entry['rejection_reason'] ); ?></small><?php endif; ?></li><?php endforeach; ?>
							</ol></details><?php endif; ?><?php if ( ! empty( $data['id_image_removed_at'] ) ) : ?><br><small class="description"><?php echo esc_html( sprintf( __( 'ID images deleted: %s', 'tutor-learning-suite' ), $data['id_image_removed_at'] ) ); ?></small><?php endif; ?></td>
						<td>
							<?php if ( self::VERIFICATION_STATUS_PENDING === $status ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:6px;"><?php wp_nonce_field( 'tlms_ipe_verification_action', '_tlms_ipe_admin_nonce' ); ?><input type="hidden" name="action" value="tlms_ipe_verification_action"><input type="hidden" name="user_id" value="<?php echo esc_attr( $user->ID ); ?>"><button class="button button-primary" name="decision" value="verified"><?php esc_html_e( 'Verify', 'tutor-learning-suite' ); ?></button></form>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;"><?php wp_nonce_field( 'tlms_ipe_verification_action', '_tlms_ipe_admin_nonce' ); ?><input type="hidden" name="action" value="tlms_ipe_verification_action"><input type="hidden" name="user_id" value="<?php echo esc_attr( $user->ID ); ?>"><input name="reason" type="text" required placeholder="<?php echo esc_attr__( 'Rejection reason', 'tutor-learning-suite' ); ?>" style="max-width:180px;"><button class="button" name="decision" value="rejected"><?php esc_html_e( 'Reject', 'tutor-learning-suite' ); ?></button></form>
							<?php elseif ( self::VERIFICATION_STATUS_VERIFIED === $status ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;"><?php wp_nonce_field( 'tlms_ipe_verification_action', '_tlms_ipe_admin_nonce' ); ?><input type="hidden" name="action" value="tlms_ipe_verification_action"><input type="hidden" name="user_id" value="<?php echo esc_attr( $user->ID ); ?>"><button class="button" name="decision" value="revoked" onclick="return confirm('<?php echo esc_js( __( 'Revoke verification for this instructor?', 'tutor-learning-suite' ) ); ?>');"><?php esc_html_e( 'Revoke verification', 'tutor-learning-suite' ); ?></button></form>
							<?php else : ?>
								<span class="description"><?php echo esc_html( ucfirst( $status ) ); ?></span>
								<?php if ( get_user_meta( $user->ID, self::META_VERIFICATION_LOCKED, true ) ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:6px;"><input type="hidden" name="action" value="tlms_ipe_verification_unlock"><input type="hidden" name="user_id" value="<?php echo esc_attr( $user->ID ); ?>"><?php wp_nonce_field( 'tlms_ipe_verification_unlock', '_tlms_ipe_unlock_nonce' ); ?><button class="button" name="unlock" value="1"><?php esc_html_e( 'Unlock verification', 'tutor-learning-suite' ); ?></button></form>
								<?php endif; ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( $total_pages > 1 ) : ?>
				<p class="tablenav"><span class="displaying-num"><?php echo esc_html( sprintf( /* translators: %d: total number of applications */ _n( '%d application', '%d applications', $total_users, 'tutor-learning-suite' ), $total_users ) ); ?></span>
				<?php
				echo paginate_links( array( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					'base'      => add_query_arg( 'paged', '%#%' ),
					'format'    => '',
					'current'   => $current_page,
					'total'     => $total_pages,
					'prev_text' => __( '&laquo;', 'tutor-learning-suite' ),
					'next_text' => __( '&raquo;', 'tutor-learning-suite' ),
				) );
				?>
				</p>
			<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Avatar verification markers are intentionally disabled.
	 * The verification label is shown in the public profile .pp-area instead.
	 */
	public function filter_verified_avatar( $avatar ) {
		return $avatar;
	}

	/**
	 * Expose verified instructor IDs to the frontend so Tutor/theme avatar markup
	 * that does not pass through get_avatar() can still receive the badge.
	 *
	 * @return void
	 */
	public function render_verified_avatar_badge_script() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;

		// This badge only ever renders on a public Tutor instructor profile
		// page (it targets .pp-area / .profile-name markup). Bail out before
		// touching the database at all on every other front-end page load
		// (home page, posts, archives, dashboard, etc.).
		$profile_username = '';
		if ( isset( $GLOBALS['wp']->query_vars['tutor_profile_username'] ) ) {
			$profile_username = sanitize_user( (string) $GLOBALS['wp']->query_vars['tutor_profile_username'] );
		}
		if ( ! $profile_username ) {
			$profile_username = sanitize_user( (string) get_query_var( 'tutor_profile_username' ) );
		}
		if ( ! $profile_username && isset( $_GET['tutor_profile_username'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$profile_username = sanitize_user( (string) $_GET['tutor_profile_username'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( ! $profile_username || ! function_exists( 'tutor_utils' ) ) {
			return;
		}

		// Resolve and verify only the single profile being viewed instead of
		// loading every verified instructor on the site.
		$profile_user = tutor_utils()->get_user_by_login( $profile_username );
		if ( ! $profile_user || ! $this->is_instructor_user( $profile_user->ID ) ) {
			return;
		}
		if ( $this->get_verification_status( $profile_user->ID ) !== self::VERIFICATION_STATUS_VERIFIED ) {
			return;
		}

		$public_profile_id = absint( $profile_user->ID );
		$payload            = array(
			(string) $public_profile_id => array(
				'login' => strtolower( (string) $profile_user->user_login ),
				'slug'  => strtolower( (string) $profile_user->user_nicename ),
			),
		);
		?>
		<script id="tlms-ipe-verified-avatar-script">
		(function(){
			'use strict';
			var verified = <?php echo wp_json_encode( $payload ); ?>;
			var publicProfileId = <?php echo (int) $public_profile_id; ?>;
			var idSet = new Set(Object.keys(verified).map(String));
			var avatarBadgeClass = 'tlms-ipe-js-verified-avatar';
			var stickerClass = 'tlms-ipe-verified-sticker';
			function makeSticker(){
				var sticker=document.createElement('span');
				sticker.className=stickerClass;
				sticker.setAttribute('title','<?php echo esc_js( __( 'Verified instructor', 'tutor-learning-suite' ) ); ?>');
				sticker.setAttribute('aria-label','<?php echo esc_js( __( 'Verified instructor', 'tutor-learning-suite' ) ); ?>');
				sticker.innerHTML='<span aria-hidden="true">✓</span><?php echo esc_js( __( 'Verified Instructor', 'tutor-learning-suite' ) ); ?>';
				return sticker;
			}
			function badgeAvatar(img){
				// Verification check marks on tutor images were removed by design.
				return;
			}
			function resolvePublicProfileId(){
				if(publicProfileId && idSet.has(String(publicProfileId))) return String(publicProfileId);
				var path=(window.location.pathname||'').replace(/\/+$/,'');
				var parts=path.split('/').filter(Boolean);
				var candidate=parts.length ? parts[parts.length-1].toLowerCase() : '';
				if(!candidate) return '';
				for(var key in verified){
					if(verified[key].login===candidate || verified[key].slug===candidate) return String(key);
				}
				return '';
			}
			function badgePublicProfile(){
				var body=document.body;
				if(!body || !body.classList.contains('tlms-ipe-public-profile')) return;
				var profileId=resolvePublicProfileId();
				if(!profileId) return;
				// Scope badge insertion to Tutor's actual public-profile content.
				// Never use a document-wide .profile-name fallback: Tutor/theme
				// account avatar menus also use that class on Dashboard/Billing links.
				var area=document.querySelector('.pp-area');
				if(!area || area.querySelector('.tlms-ipe-profile-verified-sticker')) return;
				// Find only a real profile heading. Never place the badge in a
				// header/account/avatar menu (Dashboard, My Profile, Billing), even
				// if a theme nests that menu inside the public-profile wrapper.
				var candidates=area.querySelectorAll('.profile-name h1,.profile-name h2,.profile-name h3,.profile-name .tutor-profile-name,h1.tutor-profile-name,h2.tutor-profile-name,h3.tutor-profile-name');
				var name=null;
				for(var i=0;i<candidates.length;i++){
					var candidate=candidates[i];
					if(!candidate.textContent || !candidate.textContent.trim()) continue;
					if(candidate.closest('a,button,[role="menu"],[role="navigation"],[class*="menu"],[class*="dropdown"],[class*="avatar"],[class*="account"]')) continue;
					name=candidate;
					break;
				}
				if(!name) return;
				var sticker=makeSticker();
				sticker.classList.add('tlms-ipe-profile-verified-sticker');
				name.appendChild(sticker);
			}


			function scan(){
				badgePublicProfile();
			}
			function ready(){ scan(); new MutationObserver(scan).observe(document.body,{childList:true,subtree:true}); }
			if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',ready); else ready();
		})();
		</script>
		<?php
	}

	/**
	 * Render extra info under bio templates.
	 *
	 * @param string $template  Template name.
	 * @param array  $variables Template vars.
	 * @return void
	 */
	public function render_after_template( $template, $variables ) {
		unset( $variables );

		if ( 'profile.bio' === $template ) {
			$user_name = sanitize_text_field( get_query_var( 'tutor_profile_username' ) );
			$get_user  = tutor_utils()->get_user_by_login( $user_name );
			if ( $get_user && $this->is_instructor_user( $get_user->ID ) ) {
				$this->render_public_extra( $get_user->ID );
			}
			return;
		}

	}

	/**
	 * Turn saved wilaya code(s) and commune id(s) into one display string.
	 * Wilaya/commune are stored (and returned by get_location_values()) as
	 * arrays since both fields are multi-select; the old single-code lookup
	 * helpers in Tlms_Ipe_Locations can't be called with an array directly.
	 *
	 * @param array $wilayas  Wilaya codes.
	 * @param array $communes Commune ids.
	 * @return string
	 */
	private function get_location_summary_lines( $wilayas, $communes ) {
		$wilayas  = is_array( $wilayas ) ? $wilayas : array_filter( array( $wilayas ) );
		$communes = is_array( $communes ) ? $communes : array_filter( array( $communes ) );
		$map = Tlms_Ipe_Locations::get_map();
		$lines = array();

		foreach ( $wilayas as $code ) {
			$code = (string) $code;
			$wilaya_label = Tlms_Ipe_Locations::get_wilaya_label( $code );
			$municipality_labels = array();
			if ( isset( $map[ $code ]['communes'] ) ) {
				foreach ( $communes as $commune_id ) {
					$commune_id = (string) $commune_id;
					if ( isset( $map[ $code ]['communes'][ $commune_id ] ) ) {
						$label = Tlms_Ipe_Locations::get_commune_label( $code, $commune_id );
						if ( $label ) { $municipality_labels[] = $label; }
					}
				}
			}
			$municipality_labels = array_values( array_unique( $municipality_labels ) );
			$lines[] = $wilaya_label . ( $municipality_labels ? ' : ' . implode( ', ', $municipality_labels ) : '' );
		}
		return array_values( array_filter( $lines ) );
	}

	private function format_location_summary( $wilayas, $communes ) {
		return implode( ' ; ', $this->get_location_summary_lines( $wilayas, $communes ) );
	}

	/**
	 * Public profile extra block.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	private function render_public_extra( $user_id ) {
		$types     = $this->get_tutoring_types( $user_id );
		$wilaya    = $this->get_location_values( $user_id, self::META_WILAYA );
		$commune   = $this->get_location_values( $user_id, self::META_COMMUNE );
		$education = $this->get_array_meta( $user_id, self::META_EDUCATION );
		$experience = $this->get_array_meta( $user_id, self::META_EXPERIENCE );
		$languages = $this->get_spoken_languages( $user_id );
		$categories = $this->get_teach_categories( $user_id );

		if ( empty( $types ) && empty( $wilaya ) && empty( $commune ) && empty( $education ) && empty( $experience ) && empty( $languages ) && empty( $categories ) ) {
			return;
		}

		$labels = self::tutoring_type_choices();
		$type_text = array();
		foreach ( $types as $type ) {
			if ( isset( $labels[ $type ] ) ) {
				$type_text[] = $labels[ $type ];
			}
		}
		$language_choices = self::language_choices();
		?>
		<details class="tlms-ipe-public-extra" open>
			<summary class="tlms-ipe-public-drawer-toggle">
				<span><?php esc_html_e( 'Private tutoring & profile details', 'tutor-learning-suite' ); ?></span>
				<span class="tlms-ipe-public-drawer-chevron" aria-hidden="true">⌄</span>
			</summary>
			<div class="tlms-ipe-public-drawer-body">
			<?php if ( $type_text || $wilaya ) : ?>
				<section class="tlms-ipe-public-section tlms-ipe-private-tutoring">
					<h4><?php esc_html_e( 'Private tutoring', 'tutor-learning-suite' ); ?></h4>
					<div class="tlms-ipe-info-grid">
						<?php if ( $type_text ) : ?><div><span class="tlms-ipe-label"><?php esc_html_e( 'Teaching format', 'tutor-learning-suite' ); ?></span><strong><?php echo esc_html( implode( ', ', $type_text ) ); ?></strong></div><?php endif; ?>
						<?php if ( $wilaya ) : ?>
						<div class="tlms-ipe-location-display">
							<span class="tlms-ipe-label"><?php esc_html_e( 'Location', 'tutor-learning-suite' ); ?></span>
							<?php $location_lines = $this->get_location_summary_lines( $wilaya, $commune ); ?>
							<div class="tlms-ipe-location-lines<?php echo count( $location_lines ) > 3 ? ' tlms-ipe-location-collapsed' : ''; ?>">
								<?php foreach ( $location_lines as $location_line ) : ?><div class="tlms-ipe-location-line"><?php echo esc_html( $location_line ); ?></div><?php endforeach; ?>
							</div>
							<?php if ( count( $location_lines ) > 3 ) : ?><button type="button" class="tlms-ipe-location-more" aria-expanded="false"><?php esc_html_e( 'Show more', 'tutor-learning-suite' ); ?></button><?php endif; ?>
						</div>
					<?php endif; ?>
					</div>
				</section>
			<?php endif; ?>
			<?php if ( $education ) : ?>
				<section class="tlms-ipe-public-section"><h4><?php esc_html_e( 'Education', 'tutor-learning-suite' ); ?></h4><div class="tlms-ipe-timeline">
				<?php foreach ( $education as $row ) : ?><div class="tlms-ipe-timeline-row"><div><strong><?php echo esc_html( $row['name'] ?? '' ); ?></strong><span><?php echo esc_html( $row['place'] ?? '' ); ?></span></div><time><?php echo esc_html( $this->format_profile_date( $row['date'] ?? '' ) ); ?></time></div><?php endforeach; ?>
				</div></section>
			<?php endif; ?>
			<?php if ( $experience ) : ?>
				<section class="tlms-ipe-public-section"><h4><?php esc_html_e( 'Experience', 'tutor-learning-suite' ); ?></h4><div class="tlms-ipe-timeline">
				<?php foreach ( $experience as $row ) : ?><div class="tlms-ipe-timeline-row"><div><strong><?php echo esc_html( $row['job'] ?? '' ); ?></strong><span><?php echo esc_html( $row['place'] ?? '' ); ?></span></div><time><?php echo esc_html( (string) ( $row['years'] ?? '' ) ); ?> <?php esc_html_e( 'years', 'tutor-learning-suite' ); ?></time></div><?php endforeach; ?>
				</div></section>
			<?php endif; ?>
			<?php if ( $categories ) : ?><section class="tlms-ipe-public-section"><h4><?php esc_html_e( 'What I can teach', 'tutor-learning-suite' ); ?></h4><div class="tlms-ipe-teach-list"><?php $this->render_public_teach_categories( $categories ); ?></div></section><?php endif; ?>
			<?php if ( $languages ) : ?><section class="tlms-ipe-public-section"><h4><?php esc_html_e( 'Spoken languages', 'tutor-learning-suite' ); ?></h4><div class="tlms-ipe-tag-list"><?php foreach ( $languages as $code ) : if ( isset( $language_choices[ $code ] ) ) : ?><span class="tlms-ipe-tag"><?php echo esc_html( $language_choices[ $code ] ); ?></span><?php endif; endforeach; ?></div></section><?php endif; ?>
			</div>
		</details>
		<?php
	}

	/** Render public teachable categories with parent/child hierarchy. */
	private function render_public_teach_categories( $selected ) {
		$selected = array_values( array_unique( array_filter( array_map( 'absint', (array) $selected ) ) ) );
		if ( empty( $selected ) ) {
			return;
		}

		$terms = get_terms( array(
			'taxonomy'   => 'course-category',
			'include'    => $selected,
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
		) );
		if ( is_wp_error( $terms ) ) {
			return;
		}

		$parents = array();
		$standalone = array();
		foreach ( $terms as $term ) {
			$parent_id = (int) $term->parent;
			if ( $parent_id > 0 ) {
				$parents[ $parent_id ]['children'][] = $term;
			} else {
				$standalone[] = $term;
			}
		}

		// Keep parents in taxonomy/name order, but render every child beside its
		// parent only once: Parent: Child 1, Child 2.
		foreach ( $parents as $parent_id => $group ) {
			$parent = get_term( $parent_id, 'course-category' );
			$parent_name = ( $parent && ! is_wp_error( $parent ) ) ? $parent->name : '';
			$children = $group['children'];
			usort( $children, static function ( $a, $b ) { return strcasecmp( $a->name, $b->name ); } );
			?>
			<span class="tlms-ipe-teach-group">
				<strong class="tlms-ipe-teach-parent-name"><?php echo esc_html( $parent_name ); ?></strong><span class="tlms-ipe-teach-colon">:</span>
				<span class="tlms-ipe-teach-children">
					<?php foreach ( $children as $index => $child ) : ?><span class="tlms-ipe-teach-child-name"><?php echo esc_html( $child->name ); ?></span><?php if ( $index < count( $children ) - 1 ) : ?><span class="tlms-ipe-teach-separator">,</span><?php endif; ?><?php endforeach; ?>
				</span>
			</span>
			<?php
		}

		foreach ( $standalone as $term ) {
			// Parent already rendered as "Parent: Child 1, Child 2" — do not also
			// print it alone. Keep a parent-only row only when none of its children
			// were selected.
			if ( isset( $parents[ (int) $term->term_id ] ) ) {
				continue;
			}
			?>
			<span class="tlms-ipe-teach-group tlms-ipe-teach-group-standalone"><strong class="tlms-ipe-teach-parent-name"><?php echo esc_html( $term->name ); ?></strong></span>
			<?php
		}
	}

	private function format_profile_date( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		$timestamp = strtotime( $value );
		return $timestamp ? wp_date( get_option( 'date_format' ), $timestamp ) : $value;
	}

	/**
	 * Dashboard profile extra details.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	private function render_dashboard_extra( $user_id ) {
		$types   = $this->get_tutoring_types( $user_id );
		$wilaya  = $this->get_location_values( $user_id, self::META_WILAYA );
		$commune = $this->get_location_values( $user_id, self::META_COMMUNE );

		if ( empty( $types ) && empty( $wilaya ) ) {
			return;
		}

		$labels = self::tutoring_type_choices();
		?>
		<ul class="tutor-user-profile-details tlms-ipe-dashboard-extra">
			<?php if ( $types ) : ?>
				<li class="tutor-badge tutor-badge-disabled">
					<?php esc_html_e( 'Private tutoring type', 'tutor-learning-suite' ); ?> :
					<span>
						<?php
						$out = array();
						foreach ( $types as $type ) {
							if ( isset( $labels[ $type ] ) ) {
								$out[] = $labels[ $type ];
							}
						}
						echo esc_html( implode( ', ', $out ) );
						?>
					</span>
				</li>
			<?php endif; ?>
			<?php if ( $wilaya ) : ?>
				<li class="tutor-badge tutor-badge-disabled">
					<?php esc_html_e( 'Location', 'tutor-learning-suite' ); ?> :
					<span>
						<?php echo esc_html( $this->format_location_summary( $wilaya, $commune ) ); ?>
					</span>
				</li>
			<?php endif; ?>
		</ul>
		<?php
	}

	/**
	 * Add a stable body class on Tutor public profiles so CSS can reserve
	 * the social area while the frontend consolidates duplicate containers.
	 *
	 * @param array $classes Existing body classes.
	 * @return array
	 */
	public function add_public_profile_body_class( $classes ) {
		$on_profile = (bool) get_query_var( 'tutor_profile_username' );
		if ( ! $on_profile && isset( $_GET['tutor_profile_username'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$on_profile = true;
		}
		if ( $on_profile ) {
			$classes[] = 'tlms-ipe-public-profile';
		}
		return $classes;
	}

	/**
	 * Frontend assets.
	 *
	 * @return void
	 */
	public function enqueue_front_assets() {
		$on_dashboard = function_exists( 'tutor_utils' ) && tutor_utils()->is_tutor_frontend_dashboard();
		$on_profile   = (bool) get_query_var( 'tutor_profile_username' );

		if ( ! $on_profile && isset( $_GET['tutor_profile_username'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$on_profile = true;
		}

		if ( ! $on_dashboard && ! $on_profile ) {
			return;
		}

		wp_enqueue_style(
			'tlms-ipe-profile',
			TLMS_IPE_URL . 'assets/css/profile-extra.css',
			array(),
			TLMS_IPE_VERSION
		);

		// Icon alignment runs for learners and instructors on both the dashboard
		// social form and the public profile. Location/repeater widgets still
		// no-op unless the tutor-only markup is present.
		wp_enqueue_script(
			'tlms-ipe-profile',
			TLMS_IPE_URL . 'assets/js/profile-extra.js',
			array(),
			TLMS_IPE_VERSION,
			true
		);

		$localize = array(
			'communes' => array(),
		);
		if ( $on_dashboard && is_user_logged_in() && $this->is_instructor_user( get_current_user_id() ) ) {
			$localize['communes'] = Tlms_Ipe_Locations::commune_options_map();
		}
		wp_localize_script( 'tlms-ipe-profile', 'tlmsIpe', $localize );
	}

	/**
	 * Admin assets on user profile screens.
	 *
	 * @param string $hook Current hook.
	 * @return void
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( ! in_array( $hook, array( 'profile.php', 'user-edit.php' ), true ) ) {
			return;
		}

		wp_enqueue_script(
			'tlms-ipe-admin',
			TLMS_IPE_URL . 'assets/js/profile-extra.js',
			array(),
			TLMS_IPE_VERSION,
			true
		);
		wp_localize_script(
			'tlms-ipe-admin',
			'tlmsIpe',
			array(
				'communes' => Tlms_Ipe_Locations::commune_options_map(),
			)
		);
	}

	/**
	 * Fetch the full course-category tree once per request and group terms by
	 * parent id, so rendering a nested tree costs a single query instead of
	 * one get_terms() call per node (shared by the <select> and checkbox tree
	 * renderers below).
	 *
	 * @return array<int,array> Map of parent term_id => list of WP_Term.
	 */
	private function get_course_category_children_map() {
		static $map = null;
		if ( null !== $map ) {
			return $map;
		}
		$map = array();
		$terms = get_terms( array( 'taxonomy' => 'course-category', 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC' ) );
		if ( is_wp_error( $terms ) ) {
			return $map;
		}
		foreach ( $terms as $term ) {
			$map[ (int) $term->parent ][] = $term;
		}
		return $map;
	}

	/** Render hierarchical Tutor course category options. */
	private function render_category_options( $selected = array(), $parent = 0, $depth = 0 ) {
		$map = $this->get_course_category_children_map();
		if ( empty( $map[ $parent ] ) ) {
			return;
		}
		foreach ( $map[ $parent ] as $term ) {
			$prefix = str_repeat( '— ', $depth );
			echo '<option value="' . esc_attr( $term->term_id ) . '" ' . selected( in_array( (int) $term->term_id, array_map( 'intval', $selected ), true ), true ) . '>' . esc_html( $prefix . $term->name ) . '</option>';
			$this->render_category_options( $selected, (int) $term->term_id, $depth + 1 );
		}
	}

	/** Render course categories as a searchable checkbox tree. */
	private function render_category_checkboxes( $selected = array(), $parent = 0, $depth = 0 ) {
		$map = $this->get_course_category_children_map();
		if ( empty( $map[ $parent ] ) ) {
			return;
		}
		foreach ( $map[ $parent ] as $term ) {
			$term_id = (int) $term->term_id;
			$margin  = min( $depth * 18, 54 );
			?>
			<label class="tlms-ipe-option tlms-ipe-category-option" data-depth="<?php echo esc_attr( $depth ); ?>" style="--tlms-ipe-indent: <?php echo esc_attr( $margin ); ?>px;">
				<input type="checkbox" value="<?php echo esc_attr( $term_id ); ?>" <?php checked( in_array( $term_id, array_map( 'absint', $selected ), true ) ); ?>>
				<span><?php echo esc_html( $term->name ); ?></span>
			</label>
			<?php
			$this->render_category_checkboxes( $selected, $term_id, $depth + 1 );
		}
	}

	private function read_json_rows_from_request( $key, $fields ) {
		$raw = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( is_array( $raw ) ) {
			$data = $raw;
		} else {
			$data = json_decode( (string) $raw, true );
		}
		if ( ! is_array( $data ) ) {
			return array();
		}
		$out = array();
		foreach ( $data as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$item = array();
			$has_value = false;
			foreach ( $fields as $field ) {
				$value = isset( $row[ $field ] ) ? sanitize_text_field( (string) $row[ $field ] ) : '';
				$item[ $field ] = $value;
				if ( '' !== $value ) {
					$has_value = true;
				}
			}
			if ( $has_value ) {
				$out[] = $item;
			}
		}
		return array_slice( $out, 0, 50 );
	}

	private function read_term_ids_from_request( $key ) {
		$raw = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! is_array( $raw ) ) {
			$decoded = json_decode( (string) $raw, true );
			$raw = is_array( $decoded ) ? $decoded : array_filter( array_map( 'trim', explode( ',', (string) $raw ) ) );
		}
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $raw ) ) ) );
		$valid = array();
		foreach ( $ids as $id ) {
			$term = get_term( $id, 'course-category' );
			if ( $term && ! is_wp_error( $term ) ) {
				$valid[] = (int) $id;
			}
		}
		return array_slice( $valid, 0, 100 );
	}

	private function read_language_ids_from_request() {
		$raw = isset( $_POST['tlms_ipe_spoken_languages'] ) ? wp_unslash( $_POST['tlms_ipe_spoken_languages'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! is_array( $raw ) ) {
			$decoded = json_decode( (string) $raw, true );
			$raw = is_array( $decoded ) ? $decoded : array_filter( array_map( 'trim', explode( ',', (string) $raw ) ) );
		}
		$allowed = array_keys( self::language_choices() );
		$out = array();
		foreach ( $raw as $value ) {
			$value = sanitize_key( $value );
			if ( in_array( $value, $allowed, true ) ) {
				$out[] = $value;
			}
		}
		return array_values( array_unique( array_slice( $out, 0, 50 ) ) );
	}

	private function get_location_values( $user_id, $key ) {
		$value = get_user_meta( $user_id, $key, true );
		if ( is_array( $value ) ) { return array_values( array_filter( array_map( 'sanitize_text_field', $value ) ) ); }
		$value = trim( (string) $value );
		if ( '' === $value ) { return array(); }
		$decoded = json_decode( $value, true );
		if ( is_array( $decoded ) ) { return array_values( array_filter( array_map( 'sanitize_text_field', $decoded ) ) ); }
		return array( sanitize_text_field( $value ) );
	}

	private function read_location_values_from_request( $key ) {
		$raw = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( is_array( $raw ) ) { $values = $raw; }
		else { $decoded = json_decode( (string) $raw, true ); $values = is_array( $decoded ) ? $decoded : ( '' !== trim( (string) $raw ) ? array( trim( (string) $raw ) ) : array() ); }
		return array_slice( array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $values ) ) ) ), 0, 100 );
	}

	private function get_array_meta( $user_id, $key ) {
		$value = get_user_meta( $user_id, $key, true );
		return is_array( $value ) ? array_values( $value ) : array();
	}

	private function get_teach_categories( $user_id ) {
		$value = $this->get_array_meta( $user_id, self::META_TEACH_CATS );
		return array_values( array_filter( array_map( 'absint', $value ) ) );
	}

	private function get_spoken_languages( $user_id ) {
		$value = $this->get_array_meta( $user_id, self::META_LANGUAGES );
		$allowed = array_keys( self::language_choices() );
		return array_values( array_filter( array_map( 'sanitize_key', $value ), function ( $item ) use ( $allowed ) { return in_array( $item, $allowed, true ); } ) );
	}

	/**
	 * Whether the user is an instructor (approved or pending).
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	private function is_instructor_user( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return false;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}

		// Keep all private-tutoring fields strictly instructor-only. Tutor LMS uses
		// the tutor_instructor role for instructors; checking the WP role first
		// prevents Tutor's generic helpers from exposing these fields to students.
		if ( in_array( 'tutor_instructor', (array) $user->roles, true ) ) {
			return true;
		}

		// Administrators can manage instructor data in WP admin, but regular
		// students/subscribers must never receive the private profile fields.
		return user_can( $user_id, 'manage_tutor_instructor' );
	}

	/**
	 * Saved tutoring types.
	 *
	 * @param int $user_id User id.
	 * @return array
	 */
	private function get_tutoring_types( $user_id ) {
		$types = get_user_meta( $user_id, self::META_TUTORING_TYPES, true );
		return is_array( $types ) ? array_values( $types ) : array();
	}
}
