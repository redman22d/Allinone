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
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

		// Instructor verification.
		add_action( 'admin_menu', array( $this, 'register_verification_admin_menu' ) );
		add_action( 'wp_ajax_tlms_ipe_submit_verification', array( $this, 'handle_verification_submission' ) );
		add_action( 'admin_post_tlms_ipe_verification_action', array( $this, 'handle_admin_verification_action' ) );
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
			'label'        => __( 'Instagram', 'tutor-lms-ipe' ),
			'placeholder'  => 'https://instagram.com/username',
			'icon_classes' => 'tutor-icon-brand-instagram tlms-ipe-social-icon',
			'svg_icon'     => Icon::GLOBE,
			'pattern'      => '^https?:\/\/(www\.)?instagram\.com\/[A-Za-z0-9._]+\/?$',
		);
		$icons[ self::META_TELEGRAM ]  = array(
			'label'        => __( 'Telegram', 'tutor-lms-ipe' ),
			'placeholder'  => 'https://t.me/username',
			'icon_classes' => 'tutor-icon-brand-telegram tlms-ipe-social-icon',
			'svg_icon'     => Icon::GLOBE,
			'pattern'      => '^https?:\/\/(t\.me|telegram\.me)\/[A-Za-z0-9_]+\/?$',
		);
		$icons[ self::META_WHATSAPP ]  = array(
			'label'        => __( 'WhatsApp', 'tutor-lms-ipe' ),
			'placeholder'  => 'https://wa.me/213555123456',
			'icon_classes' => 'tutor-icon-brand-whatsapp tlms-ipe-social-icon',
			'svg_icon'     => Icon::ANDROID_PHONE,
			'pattern'      => '^(https?:\/\/(wa\.me\/|api\.whatsapp\.com\/send\?phone=).+|(\+|00)?[0-9 ]{8,20})$',
		);
		$icons[ self::META_TIKTOK ]    = array(
			'label'        => __( 'TikTok', 'tutor-lms-ipe' ),
			'placeholder'  => 'https://tiktok.com/@username',
			'icon_classes' => 'tutor-icon-brand-tiktok tlms-ipe-social-icon',
			'svg_icon'     => Icon::GLOBE,
			'pattern'      => '^https?:\/\/(www\.)?tiktok\.com\/@[A-Za-z0-9._]+\/?$',
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
			'ar' => __( 'Arabic', 'tutor-lms-ipe' ),
			'en' => __( 'English', 'tutor-lms-ipe' ),
			'fr' => __( 'French', 'tutor-lms-ipe' ),
			'es' => __( 'Spanish', 'tutor-lms-ipe' ),
			'it' => __( 'Italian', 'tutor-lms-ipe' ),
			'ru' => __( 'Russian', 'tutor-lms-ipe' ),
			'zh' => __( 'Chinese (Mandarin)', 'tutor-lms-ipe' ),
			'yue' => __( 'Chinese (Cantonese)', 'tutor-lms-ipe' ),
			'tr' => __( 'Turkish', 'tutor-lms-ipe' ),
			'hi' => __( 'Hindi', 'tutor-lms-ipe' ),
			'pt' => __( 'Portuguese', 'tutor-lms-ipe' ),
			'bn' => __( 'Bengali', 'tutor-lms-ipe' ),
			'ur' => __( 'Urdu', 'tutor-lms-ipe' ),
			'id' => __( 'Indonesian', 'tutor-lms-ipe' ),
			'de' => __( 'German', 'tutor-lms-ipe' ),
			'ja' => __( 'Japanese', 'tutor-lms-ipe' ),
			'pa' => __( 'Punjabi', 'tutor-lms-ipe' ),
			'jv' => __( 'Javanese', 'tutor-lms-ipe' ),
			'te' => __( 'Telugu', 'tutor-lms-ipe' ),
			'mr' => __( 'Marathi', 'tutor-lms-ipe' ),
			'vi' => __( 'Vietnamese', 'tutor-lms-ipe' ),
			'ko' => __( 'Korean', 'tutor-lms-ipe' ),
			'ta' => __( 'Tamil', 'tutor-lms-ipe' ),
			'fa' => __( 'Persian', 'tutor-lms-ipe' ),
			'gu' => __( 'Gujarati', 'tutor-lms-ipe' ),
			'kn' => __( 'Kannada', 'tutor-lms-ipe' ),
			'am' => __( 'Amharic', 'tutor-lms-ipe' ),
			'my' => __( 'Burmese', 'tutor-lms-ipe' ),
			'th' => __( 'Thai', 'tutor-lms-ipe' ),
			'yo' => __( 'Yoruba', 'tutor-lms-ipe' ),
			'ha' => __( 'Hausa', 'tutor-lms-ipe' ),
			'ig' => __( 'Igbo', 'tutor-lms-ipe' ),
			'pl' => __( 'Polish', 'tutor-lms-ipe' ),
			'uk' => __( 'Ukrainian', 'tutor-lms-ipe' ),
			'nl' => __( 'Dutch', 'tutor-lms-ipe' ),
			'ro' => __( 'Romanian', 'tutor-lms-ipe' ),
			'uz' => __( 'Uzbek', 'tutor-lms-ipe' ),
			'zu' => __( 'Zulu', 'tutor-lms-ipe' ),
			'he' => __( 'Hebrew', 'tutor-lms-ipe' ),
			'cs' => __( 'Czech', 'tutor-lms-ipe' ),
			'sv' => __( 'Swedish', 'tutor-lms-ipe' ),
			'el' => __( 'Greek', 'tutor-lms-ipe' ),
			'hu' => __( 'Hungarian', 'tutor-lms-ipe' ),
			'fi' => __( 'Finnish', 'tutor-lms-ipe' ),
			'no' => __( 'Norwegian', 'tutor-lms-ipe' ),
			'da' => __( 'Danish', 'tutor-lms-ipe' ),
			'sk' => __( 'Slovak', 'tutor-lms-ipe' ),
			'bg' => __( 'Bulgarian', 'tutor-lms-ipe' ),
			'hr' => __( 'Croatian', 'tutor-lms-ipe' ),
			'sr' => __( 'Serbian', 'tutor-lms-ipe' ),
		);
	}

	/**
	 * Tutoring type choices.
	 *
	 * @return array
	 */
	public static function tutoring_type_choices() {
		return array(
			'online'       => __( 'Online', 'tutor-lms-ipe' ),
			'tutor_home'   => __( "Offline at tutor's home", 'tutor-lms-ipe' ),
			'student_home' => __( "Offline at student's home", 'tutor-lms-ipe' ),
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
						<span class="tutor-label"><?php esc_html_e( 'I accept private session request', 'tutor-lms-ipe' ); ?></span>
					</label>
					<input type="hidden" id="tlms_ipe_accept_private_sessions_value" name="tlms_ipe_accept_private_sessions" value="<?php echo $accept_private ? '1' : '0'; ?>" x-bind="register('tlms_ipe_accept_private_sessions')" />
			</div>
			<div class="tlms-ipe-field tlms-ipe-private-type-section" data-private-session-options="1" <?php echo $accept_private ? '' : 'hidden'; ?>>
				<span class="tutor-label"><?php esc_html_e( 'Private tutoring type', 'tutor-lms-ipe' ); ?></span>
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
					<div class="tlms-ipe-section-head"><label class="tutor-label"><?php esc_html_e( 'Wilaya', 'tutor-lms-ipe' ); ?></label><span class="tlms-ipe-selection-count" data-count-for="tlms_ipe_wilaya">0</span></div>
					<div class="tlms-ipe-check-dropdown tlms-ipe-location-dropdown" data-dropdown="tlms_ipe_wilaya">
						<button type="button" class="tlms-ipe-dropdown-toggle" aria-expanded="false"><span class="tlms-ipe-dropdown-text"><?php esc_html_e( 'Select wilaya(s)', 'tutor-lms-ipe' ); ?></span><span class="tlms-ipe-chevron">⌄</span></button>
						<div class="tlms-ipe-dropdown-panel">
							<div class="tlms-ipe-dropdown-search"><input type="search" placeholder="<?php echo esc_attr__( 'Search wilaya…', 'tutor-lms-ipe' ); ?>" autocomplete="off"></div>
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
					<div class="tlms-ipe-section-head"><label class="tutor-label"><?php esc_html_e( 'Municipality', 'tutor-lms-ipe' ); ?></label><span class="tlms-ipe-selection-count" data-count-for="tlms_ipe_commune">0</span></div>
					<div class="tlms-ipe-check-dropdown tlms-ipe-location-dropdown" data-dropdown="tlms_ipe_commune">
						<button type="button" class="tlms-ipe-dropdown-toggle" aria-expanded="false"><span class="tlms-ipe-dropdown-text"><?php esc_html_e( 'Select municipality(ies)', 'tutor-lms-ipe' ); ?></span><span class="tlms-ipe-chevron">⌄</span></button>
						<div class="tlms-ipe-dropdown-panel">
							<div class="tlms-ipe-dropdown-search"><input type="search" placeholder="<?php echo esc_attr__( 'Search municipality…', 'tutor-lms-ipe' ); ?>" autocomplete="off"></div>
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
					<small class="tlms-ipe-location-help"><?php esc_html_e( 'Choose a wilaya first to narrow the municipality list.', 'tutor-lms-ipe' ); ?></small>
				</div>
			</div>
			</div>

			<div class="tutor-card tutor-card-rounded-2xl tlms-ipe-compact-card tlms-ipe-profile-card">
				<div class="tlms-ipe-section-head"><h6 class="tutor-h6"><?php esc_html_e( 'Education', 'tutor-lms-ipe' ); ?></h6><button type="button" class="tutor-btn tutor-btn-outline tutor-btn-sm tlms-ipe-add-row" data-target="education">+ <?php esc_html_e( 'Add qualification', 'tutor-lms-ipe' ); ?></button></div>
				<div id="tlms-ipe-education-list" class="tlms-ipe-repeat-list" data-items="<?php echo esc_attr( wp_json_encode( $this->get_array_meta( $user->ID, self::META_EDUCATION ) ) ); ?>"></div>
				<input type="hidden" name="tlms_ipe_education" id="tlms_ipe_education" value="<?php echo esc_attr( wp_json_encode( $this->get_array_meta( $user->ID, self::META_EDUCATION ) ) ); ?>" x-bind="register('tlms_ipe_education')" /><input type="hidden" name="tlms_ipe_education_dirty" id="tlms_ipe_education_dirty" value="0" />
			</div>

			<div class="tutor-card tutor-card-rounded-2xl tlms-ipe-compact-card tlms-ipe-profile-card">
				<div class="tlms-ipe-section-head"><h6 class="tutor-h6"><?php esc_html_e( 'Experience', 'tutor-lms-ipe' ); ?></h6><button type="button" class="tutor-btn tutor-btn-outline tutor-btn-sm tlms-ipe-add-row" data-target="experience">+ <?php esc_html_e( 'Add experience', 'tutor-lms-ipe' ); ?></button></div>
				<div id="tlms-ipe-experience-list" class="tlms-ipe-repeat-list" data-items="<?php echo esc_attr( wp_json_encode( $this->get_array_meta( $user->ID, self::META_EXPERIENCE ) ) ); ?>"></div>
				<input type="hidden" name="tlms_ipe_experience" id="tlms_ipe_experience" value="<?php echo esc_attr( wp_json_encode( $this->get_array_meta( $user->ID, self::META_EXPERIENCE ) ) ); ?>" x-bind="register('tlms_ipe_experience')" /><input type="hidden" name="tlms_ipe_experience_dirty" id="tlms_ipe_experience_dirty" value="0" />
			</div>

			<div class="tutor-card tutor-card-rounded-2xl tlms-ipe-compact-card tlms-ipe-profile-card">
				<div class="tlms-ipe-section-head"><h6 class="tutor-h6"><?php esc_html_e( 'What I can teach', 'tutor-lms-ipe' ); ?></h6><span class="tlms-ipe-selection-count" data-count-for="tlms_ipe_teach_categories">0</span></div>
				<div class="tlms-ipe-check-dropdown" data-dropdown="tlms_ipe_teach_categories">
					<button type="button" class="tlms-ipe-dropdown-toggle" aria-expanded="false"><span class="tlms-ipe-dropdown-text"><?php esc_html_e( 'Select subjects and categories', 'tutor-lms-ipe' ); ?></span><span class="tlms-ipe-chevron">⌄</span></button>
					<div class="tlms-ipe-dropdown-panel">
						<div class="tlms-ipe-dropdown-search"><input type="search" placeholder="<?php echo esc_attr__( 'Search categories…', 'tutor-lms-ipe' ); ?>" autocomplete="off"></div>
						<div class="tlms-ipe-dropdown-options"><?php $this->render_category_checkboxes( $saved_categories ); ?></div>
					</div>
				</div>
				<input type="hidden" name="tlms_ipe_teach_categories" id="tlms_ipe_teach_categories_value" value="<?php echo esc_attr( wp_json_encode( $saved_categories ) ); ?>" x-bind="register('tlms_ipe_teach_categories')" />
			</div>

			<div class="tutor-card tutor-card-rounded-2xl tlms-ipe-compact-card tlms-ipe-profile-card">
				<div class="tlms-ipe-section-head"><h6 class="tutor-h6"><?php esc_html_e( 'Spoken languages', 'tutor-lms-ipe' ); ?></h6><span class="tlms-ipe-selection-count" data-count-for="tlms_ipe_spoken_languages">0</span></div>
				<div class="tlms-ipe-check-dropdown" data-dropdown="tlms_ipe_spoken_languages">
					<button type="button" class="tlms-ipe-dropdown-toggle" aria-expanded="false"><span class="tlms-ipe-dropdown-text"><?php esc_html_e( 'Select spoken languages', 'tutor-lms-ipe' ); ?></span><span class="tlms-ipe-chevron">⌄</span></button>
					<div class="tlms-ipe-dropdown-panel">
						<div class="tlms-ipe-dropdown-search"><input type="search" placeholder="<?php echo esc_attr__( 'Search languages…', 'tutor-lms-ipe' ); ?>" autocomplete="off"></div>
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
			<th><label><?php esc_html_e( 'Private sessions', 'tutor-lms-ipe' ); ?></label></th>
			<td><label><input type="checkbox" name="tlms_ipe_accept_private_sessions" value="1" <?php checked( get_user_meta( $user_id, self::META_ACCEPT_PRIVATE, true ), 1 ); ?> /> <?php esc_html_e( 'I accept private session requests', 'tutor-lms-ipe' ); ?></label></td>
		</tr>
		<tr class="user-description-wrap">
			<th><label><?php esc_html_e( 'Private tutoring type', 'tutor-lms-ipe' ); ?></label></th>
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
			<th><label for="tlms_ipe_wilaya"><?php esc_html_e( 'Location', 'tutor-lms-ipe' ); ?></label></th>
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
				<p class="description"><?php esc_html_e( 'Hold Ctrl/Cmd to select multiple wilayas and municipalities.', 'tutor-lms-ipe' ); ?></p>
			</td>
		</tr>
		<tr class="user-description-wrap">
			<th><label><?php esc_html_e( 'Education', 'tutor-lms-ipe' ); ?></label></th>
			<td><textarea name="tlms_ipe_education" class="large-text" rows="4" placeholder="[{&quot;name&quot;:&quot;Degree&quot;,&quot;place&quot;:&quot;University&quot;,&quot;date&quot;:&quot;2024-06-01&quot;}]"><?php echo esc_textarea( wp_json_encode( $this->get_array_meta( $user_id, self::META_EDUCATION ) ) ); ?></textarea></td>
		</tr>
		<tr class="user-description-wrap">
			<th><label><?php esc_html_e( 'Experience', 'tutor-lms-ipe' ); ?></label></th>
			<td><textarea name="tlms_ipe_experience" class="large-text" rows="4" placeholder="[{&quot;job&quot;:&quot;Math teacher&quot;,&quot;place&quot;:&quot;School&quot;,&quot;years&quot;:5}]"><?php echo esc_textarea( wp_json_encode( $this->get_array_meta( $user_id, self::META_EXPERIENCE ) ) ); ?></textarea></td>
		</tr>
		<tr class="user-description-wrap">
			<th><label for="tlms_ipe_teach_categories"><?php esc_html_e( 'What can teach', 'tutor-lms-ipe' ); ?></label></th>
			<td><select name="tlms_ipe_teach_categories[]" id="tlms_ipe_teach_categories" class="regular-text" multiple size="8"><?php $this->render_category_options( $this->get_teach_categories( $user_id ) ); ?></select></td>
		</tr>
		<tr class="user-description-wrap">
			<th><label for="tlms_ipe_spoken_languages"><?php esc_html_e( 'Spoken languages', 'tutor-lms-ipe' ); ?></label></th>
			<td><select name="tlms_ipe_spoken_languages[]" id="tlms_ipe_spoken_languages" class="regular-text" multiple size="8"><?php foreach ( self::language_choices() as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( in_array( $value, $this->get_spoken_languages( $user_id ), true ) ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></td>
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

		if ( ! Input::has( 'tlms_ipe_wilaya' ) && ! isset( $_POST['tlms_ipe_accept_private_sessions'] ) && ! isset( $_POST['tlms_ipe_tutoring_types'] ) && ! isset( $_POST['tlms_ipe_education'] ) && ! isset( $_POST['tlms_ipe_experience'] ) && ! isset( $_POST['tlms_ipe_teach_categories'] ) && ! isset( $_POST['tlms_ipe_spoken_languages'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
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

		if ( ! Input::has( 'tlms_ipe_wilaya' ) && ! Input::has( 'tlms_ipe_commune' ) && ! isset( $_POST['tlms_ipe_accept_private_sessions'] ) && ! isset( $_POST['tlms_ipe_tutoring_types'] ) && ! isset( $_POST['tlms_ipe_education'] ) && ! isset( $_POST['tlms_ipe_experience'] ) && ! isset( $_POST['tlms_ipe_teach_categories'] ) && ! isset( $_POST['tlms_ipe_spoken_languages'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
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
			__( 'Instructor Verification', 'tutor-lms-ipe' ),
			__( 'Instructor Verification', 'tutor-lms-ipe' ),
			'manage_options',
			'tlms-ipe-verification',
			array( $this, 'render_verification_admin_page' )
		);

		if ( ! $page_hook ) {
			add_users_page(
				__( 'Instructor Verification', 'tutor-lms-ipe' ),
				__( 'Instructor Verification', 'tutor-lms-ipe' ),
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
		if ( self::VERIFICATION_STATUS_VERIFIED === $status ) {
			return;
		}

		$is_form = isset( $_GET['tlms_ipe_verify'] ) && '1' === (string) $_GET['tlms_ipe_verify']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$data = $this->get_verification_data( $user_id );
		?>
		<section class="tlms-ipe-verification-card">
			<div class="tlms-ipe-verification-copy">
				<div class="tlms-ipe-verification-mark" aria-hidden="true">✓</div>
				<div>
					<h3><?php esc_html_e( 'Get verified', 'tutor-lms-ipe' ); ?></h3>
					<p><?php esc_html_e( 'Apply for a verified instructor badge. Our team will review your information before adding the blue verification mark to your profile.', 'tutor-lms-ipe' ); ?></p>
				</div>
			</div>

			<?php if ( self::VERIFICATION_STATUS_PENDING === $status ) : ?>
				<div class="tlms-ipe-verification-status tlms-ipe-verification-pending">
					<strong><?php esc_html_e( 'Application pending', 'tutor-lms-ipe' ); ?></strong>
					<span><?php esc_html_e( 'Your verification application has been received and is waiting for admin review.', 'tutor-lms-ipe' ); ?></span>
				</div>
			<?php elseif ( self::VERIFICATION_STATUS_REJECTED === $status ) : ?>
				<div class="tlms-ipe-verification-status tlms-ipe-verification-rejected">
					<strong><?php esc_html_e( 'Application needs attention', 'tutor-lms-ipe' ); ?></strong>
					<span><?php esc_html_e( 'Your previous application was not approved. You can update the information below and submit again.', 'tutor-lms-ipe' ); ?></span>
				</div>
			<?php endif; ?>

			<?php if ( ! $is_form && self::VERIFICATION_STATUS_PENDING !== $status ) : ?>
				<a class="tutor-btn tutor-btn-primary tlms-ipe-verification-cta" href="<?php echo esc_url( add_query_arg( 'tlms_ipe_verify', '1' ) ); ?>"><?php esc_html_e( 'Apply for verification', 'tutor-lms-ipe' ); ?></a>
			<?php elseif ( $is_form && self::VERIFICATION_STATUS_PENDING !== $status ) : ?>
				<form class="tlms-ipe-verification-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
					<input type="hidden" name="action" value="tlms_ipe_submit_verification">
					<?php wp_nonce_field( 'tlms_ipe_submit_verification', '_tlms_ipe_nonce' ); ?>
					<div class="tlms-ipe-verification-grid">
						<label><span><?php esc_html_e( 'Legal full name', 'tutor-lms-ipe' ); ?></span><input name="legal_name" type="text" required maxlength="120" value="<?php echo esc_attr( $data['legal_name'] ?? trim( get_user_meta( $user_id, 'first_name', true ) . ' ' . get_user_meta( $user_id, 'last_name', true ) ) ); ?>"></label>
						<label><span><?php esc_html_e( 'Email', 'tutor-lms-ipe' ); ?></span><input name="email" type="email" required maxlength="190" value="<?php echo esc_attr( get_userdata( $user_id )->user_email ); ?>"></label>
						<label><span><?php esc_html_e( 'Phone', 'tutor-lms-ipe' ); ?></span><input name="phone" type="tel" required maxlength="40" value="<?php echo esc_attr( $data['phone'] ?? get_user_meta( $user_id, 'phone_number', true ) ); ?>"></label>
						<label><span><?php esc_html_e( 'ID type', 'tutor-lms-ipe' ); ?></span><select name="id_type" required><option value=""><?php esc_html_e( 'Select ID type', 'tutor-lms-ipe' ); ?></option><?php foreach ( array( 'national_id' => __( 'National ID', 'tutor-lms-ipe' ), 'passport' => __( 'Passport', 'tutor-lms-ipe' ), 'driver_license' => __( 'Driver license', 'tutor-lms-ipe' ) ) as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $data['id_type'] ?? '', $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
						<label><span><?php esc_html_e( 'ID number', 'tutor-lms-ipe' ); ?></span><input name="id_number" type="text" required maxlength="80" autocomplete="off" value="<?php echo esc_attr( $data['id_number'] ?? '' ); ?>"></label>
						<label><span><?php esc_html_e( 'ID image', 'tutor-lms-ipe' ); ?></span><input name="id_image" type="file" accept="image/jpeg,image/png,image/webp" required><small><?php esc_html_e( 'JPG, PNG or WebP, max 5 MB.', 'tutor-lms-ipe' ); ?></small></label>
						<label><span><?php esc_html_e( 'Wilaya', 'tutor-lms-ipe' ); ?></span><input name="wilaya" type="text" maxlength="80" value="<?php echo esc_attr( $data['wilaya'] ?? get_user_meta( $user_id, self::META_WILAYA, true ) ); ?>"></label>
						<label class="tlms-ipe-verification-full"><span><?php esc_html_e( 'Additional information', 'tutor-lms-ipe' ); ?></span><textarea name="notes" maxlength="1000" rows="4" placeholder="<?php echo esc_attr__( 'Anything that can help us verify your instructor profile…', 'tutor-lms-ipe' ); ?>"><?php echo esc_textarea( $data['notes'] ?? '' ); ?></textarea></label>
					</div>
					<div class="tlms-ipe-verification-form-actions"><a href="<?php echo esc_url( remove_query_arg( 'tlms_ipe_verify' ) ); ?>" class="tutor-btn tutor-btn-outline"><?php esc_html_e( 'Cancel', 'tutor-lms-ipe' ); ?></a><button type="submit" class="tutor-btn tutor-btn-primary"><?php esc_html_e( 'Submit application', 'tutor-lms-ipe' ); ?></button></div>
				</form>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Process instructor verification application.

	 * @return void
	 */
	public function handle_verification_submission() {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'You must be logged in to apply for instructor verification.', 'tutor-lms-ipe' ) );
		}
		$user_id = get_current_user_id();
		if ( ! $this->is_instructor_user( $user_id ) ) {
			wp_die( esc_html__( 'Only instructors can submit a verification application.', 'tutor-lms-ipe' ) );
		}
		if ( ! isset( $_POST['_tlms_ipe_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_tlms_ipe_nonce'] ) ), 'tlms_ipe_submit_verification' ) ) {
			wp_safe_redirect( add_query_arg( array( 'tlms_ipe_verify' => '1', 'tlms_ipe_verification_error' => '1' ), wp_get_referer() ?: home_url( '/' ) ) );
			exit;
		}

		$upload_id = 0;
		$clean = array(
			'legal_name' => sanitize_text_field( wp_unslash( $_POST['legal_name'] ?? '' ) ),
			'email'     => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
			'phone'     => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ),
			'id_type'   => sanitize_key( wp_unslash( $_POST['id_type'] ?? '' ) ),
			'id_number' => sanitize_text_field( wp_unslash( $_POST['id_number'] ?? '' ) ),
			'wilaya'    => sanitize_text_field( wp_unslash( $_POST['wilaya'] ?? '' ) ),
			'notes'     => sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ),
			'submitted_at' => current_time( 'mysql' ),
		);
		if ( isset( $_FILES['id_image'] ) && ! empty( $_FILES['id_image']['name'] ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$upload = wp_handle_upload( $_FILES['id_image'], array( 'test_form' => false, 'mimes' => array( 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' ) ) );
			if ( isset( $upload['error'] ) ) {
				wp_safe_redirect( add_query_arg( array( 'tlms_ipe_verify' => '1', 'tlms_ipe_verification_error' => '1' ), wp_get_referer() ?: home_url( '/' ) ) );
				exit;
			}
			$attachment = array( 'post_mime_type' => $upload['type'], 'post_title' => sanitize_file_name( pathinfo( $upload['file'], PATHINFO_FILENAME ) ), 'post_status' => 'private' );
			$upload_id = wp_insert_attachment( $attachment, $upload['file'], 0 );
			if ( ! $upload_id ) {
				wp_safe_redirect( add_query_arg( array( 'tlms_ipe_verify' => '1', 'tlms_ipe_verification_error' => '1' ), wp_get_referer() ?: home_url( '/' ) ) );
				exit;
			}
			wp_update_attachment_metadata( $upload_id, wp_generate_attachment_metadata( $upload_id, $upload['file'] ) );
		}

		if ( ! is_email( $clean['email'] ) || '' === $clean['legal_name'] || '' === $clean['phone'] || '' === $clean['id_type'] || '' === $clean['id_number'] || ! $upload_id ) {
			wp_safe_redirect( add_query_arg( array( 'tlms_ipe_verify' => '1', 'tlms_ipe_verification_error' => '1' ), wp_get_referer() ?: home_url( '/' ) ) );
			exit;
		}

		$clean['id_image_id'] = absint( $upload_id );
		update_user_meta( $user_id, self::META_VERIFICATION_DATA, $clean );
		update_user_meta( $user_id, self::META_VERIFICATION_STATUS, self::VERIFICATION_STATUS_PENDING );

		$recipient = sanitize_email( get_option( 'tlms_ipe_verification_recipient', get_option( 'admin_email' ) ) );
		if ( is_email( $recipient ) ) {
			$user = get_userdata( $user_id );
			$subject = sprintf( __( '[%s] New instructor verification application', 'tutor-lms-ipe' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
			$message = sprintf(
				"A new instructor verification application was submitted.

Instructor: %s
Email: %s
Phone: %s
ID type: %s
ID number: %s

Review it in WordPress Admin → Tutor LMS → Instructor Verification.",
				$clean['legal_name'],
				$clean['email'],
				$clean['phone'],
				$clean['id_type'],
				$clean['id_number']
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
			wp_die( esc_html__( 'You are not allowed to manage verification applications.', 'tutor-lms-ipe' ) );
		}
		check_admin_referer( 'tlms_ipe_verification_action', '_tlms_ipe_admin_nonce' );
		$user_id = absint( $_POST['user_id'] ?? 0 );
		$decision = sanitize_key( wp_unslash( $_POST['decision'] ?? '' ) );
		$this->apply_admin_verification_decision( $user_id, $decision );
		wp_safe_redirect( add_query_arg( 'tlms_ipe_updated', '1', admin_url( 'admin.php?page=tlms-ipe-verification' ) ) );
		exit;
	}

	/**
	 * Apply a verification decision and remove the uploaded ID image after review.
	 *
	 * @param int    $user_id  User id.
	 * @param string $decision Verification status.
	 * @return bool
	 */
	private function apply_admin_verification_decision( $user_id, $decision ) {
		$user_id = absint( $user_id );
		$allowed = array( self::VERIFICATION_STATUS_VERIFIED, self::VERIFICATION_STATUS_REJECTED, self::VERIFICATION_STATUS_PENDING, self::VERIFICATION_STATUS_NONE, self::VERIFICATION_STATUS_REVOKED );
		if ( ! $user_id || ! in_array( $decision, $allowed, true ) ) {
			return false;
		}

		$data = $this->get_verification_data( $user_id );
		if ( in_array( $decision, array( self::VERIFICATION_STATUS_VERIFIED, self::VERIFICATION_STATUS_REJECTED ), true ) && ! empty( $data['id_image_id'] ) ) {
			$attachment_id = absint( $data['id_image_id'] );
			if ( $attachment_id ) {
				wp_delete_attachment( $attachment_id, true );
			}
			$data['id_image_id'] = 0;
			$data['id_image_removed_at'] = current_time( 'mysql' );
			update_user_meta( $user_id, self::META_VERIFICATION_DATA, $data );
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

		if ( isset( $_POST['decision'], $_POST['user_id'], $_POST['_tlms_ipe_admin_nonce'] ) ) {
			check_admin_referer( 'tlms_ipe_verification_action', '_tlms_ipe_admin_nonce' );
			$this->apply_admin_verification_decision( absint( $_POST['user_id'] ), sanitize_key( wp_unslash( $_POST['decision'] ) ) );
		}

		if ( isset( $_POST['tlms_ipe_save_settings'] ) ) {
			check_admin_referer( 'tlms_ipe_save_settings', '_tlms_ipe_settings_nonce' );
			update_option( 'tlms_ipe_verification_recipient', sanitize_email( wp_unslash( $_POST['recipient'] ?? '' ) ) );
			update_option( 'tlms_ipe_verification_cta_enabled', isset( $_POST['verification_cta_enabled'] ) ? 1 : 0 );
		}

		$query = new WP_User_Query( array(
			'number' => 200,
			'orderby' => 'registered',
			'order' => 'DESC',
			'meta_query' => array( array(
				'key' => self::META_VERIFICATION_STATUS,
				'value' => array( self::VERIFICATION_STATUS_PENDING, self::VERIFICATION_STATUS_VERIFIED, self::VERIFICATION_STATUS_REJECTED, self::VERIFICATION_STATUS_REVOKED ),
				'compare' => 'IN',
			) ),
		) );
		$users = $query->get_results();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Instructor Verification', 'tutor-lms-ipe' ); ?></h1>
			<p><?php esc_html_e( 'Review verification applications and control which instructors receive the blue verified mark.', 'tutor-lms-ipe' ); ?></p>
			<form method="post" style="max-width:700px;margin:20px 0 30px;padding:20px;background:#fff;border:1px solid #dcdcde;">
				<h2 style="margin-top:0"><?php esc_html_e( 'Application notifications', 'tutor-lms-ipe' ); ?></h2>
				<?php wp_nonce_field( 'tlms_ipe_save_settings', '_tlms_ipe_settings_nonce' ); ?>
				<table class="form-table"><tr><th><label for="tlms-ipe-recipient"><?php esc_html_e( 'Receive applications at', 'tutor-lms-ipe' ); ?></label></th><td><input id="tlms-ipe-recipient" name="recipient" type="email" class="regular-text" value="<?php echo esc_attr( get_option( 'tlms_ipe_verification_recipient', get_option( 'admin_email' ) ) ); ?>" required><p class="description"><?php esc_html_e( 'A notification email is sent whenever an instructor submits a new application.', 'tutor-lms-ipe' ); ?></p></td></tr>
					<tr><th><?php esc_html_e( 'Tutor verification CTA', 'tutor-lms-ipe' ); ?></th><td><label><input type="checkbox" name="verification_cta_enabled" value="1" <?php checked( (bool) get_option( 'tlms_ipe_verification_cta_enabled', 1 ) ); ?>> <?php esc_html_e( 'Show the verification CTA to instructors', 'tutor-lms-ipe' ); ?></label><p class="description"><?php esc_html_e( 'Disable this to hide the Get verified / Apply for verification card.', 'tutor-lms-ipe' ); ?></p></td></tr>
				</table>
				<p><button name="tlms_ipe_save_settings" class="button button-primary"><?php esc_html_e( 'Save settings', 'tutor-lms-ipe' ); ?></button></p>
			</form>

			<h2><?php esc_html_e( 'Verification applications', 'tutor-lms-ipe' ); ?></h2>
			<?php if ( ! $users ) : ?><p><?php esc_html_e( 'No verification applications yet.', 'tutor-lms-ipe' ); ?></p><?php else : ?>
			<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Instructor', 'tutor-lms-ipe' ); ?></th><th><?php esc_html_e( 'Contact', 'tutor-lms-ipe' ); ?></th><th><?php esc_html_e( 'Identity', 'tutor-lms-ipe' ); ?></th><th><?php esc_html_e( 'Status', 'tutor-lms-ipe' ); ?></th><th><?php esc_html_e( 'Location', 'tutor-lms-ipe' ); ?></th><th><?php esc_html_e( 'Submitted', 'tutor-lms-ipe' ); ?></th><th><?php esc_html_e( 'Actions', 'tutor-lms-ipe' ); ?></th></tr></thead><tbody>
			<?php foreach ( $users as $user ) : $data = $this->get_verification_data( $user->ID ); $status = $this->get_verification_status( $user->ID ); ?><tr><td><strong><?php echo esc_html( $data['legal_name'] ?? $user->display_name ); ?></strong><br><span class="description">@<?php echo esc_html( $user->user_login ); ?></span><br><a href="<?php echo esc_url( get_edit_user_link( $user->ID ) ); ?>">#<?php echo esc_html( $user->ID ); ?></a></td><td><?php echo esc_html( $data['email'] ?? $user->user_email ); ?><br><?php echo esc_html( $data['phone'] ?? '' ); ?></td><td><?php echo esc_html( $data['id_type'] ?? '' ); ?><br><?php echo esc_html( $data['id_number'] ?? '' ); ?><?php if ( ! empty( $data['id_image_id'] ) ) : ?><br><a href="<?php echo esc_url( wp_get_attachment_url( absint( $data['id_image_id'] ) ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View ID image', 'tutor-lms-ipe' ); ?></a><?php elseif ( ! empty( $data['id_image_removed_at'] ) ) : ?><br><small><?php esc_html_e( 'ID image removed after review', 'tutor-lms-ipe' ); ?></small><?php endif; ?></td><td><?php echo esc_html( $data['wilaya'] ?? '' ); ?></td><td><?php echo esc_html( $status ); ?></td><td><?php echo esc_html( $data['submitted_at'] ?? '' ); ?></td><td><?php if ( self::VERIFICATION_STATUS_PENDING === $status ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:6px;"><?php wp_nonce_field( 'tlms_ipe_verification_action', '_tlms_ipe_admin_nonce' ); ?><input type="hidden" name="action" value="tlms_ipe_verification_action"><input type="hidden" name="user_id" value="<?php echo esc_attr( $user->ID ); ?>"><button class="button button-primary" name="decision" value="verified"><?php esc_html_e( 'Verify', 'tutor-lms-ipe' ); ?></button></form><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;"><?php wp_nonce_field( 'tlms_ipe_verification_action', '_tlms_ipe_admin_nonce' ); ?><input type="hidden" name="action" value="tlms_ipe_verification_action"><input type="hidden" name="user_id" value="<?php echo esc_attr( $user->ID ); ?>"><button class="button" name="decision" value="rejected"><?php esc_html_e( 'Reject', 'tutor-lms-ipe' ); ?></button></form><?php elseif ( self::VERIFICATION_STATUS_VERIFIED === $status ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;"><?php wp_nonce_field( 'tlms_ipe_verification_action', '_tlms_ipe_admin_nonce' ); ?><input type="hidden" name="action" value="tlms_ipe_verification_action"><input type="hidden" name="user_id" value="<?php echo esc_attr( $user->ID ); ?>"><button class="button" name="decision" value="revoked" onclick="return confirm('<?php echo esc_js( __( 'Revoke verification for this instructor?', 'tutor-lms-ipe' ) ); ?>');"><?php esc_html_e( 'Revoke verification', 'tutor-lms-ipe' ); ?></button></form><?php else : ?><span class="description"><?php echo esc_html( ucfirst( $status ) ); ?></span><?php endif; ?></td></tr><?php endforeach; ?>
			</tbody></table>
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
		$users = get_users( array(
			'number'     => 500,
			'fields'     => 'ID',
			'meta_query' => array(
				array(
					'key'   => self::META_VERIFICATION_STATUS,
					'value' => self::VERIFICATION_STATUS_VERIFIED,
				),
			),
		) );
		$payload = array();
		$public_profile_id = 0;
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
		if ( $profile_username && function_exists( 'tutor_utils' ) ) {
			$profile_user = tutor_utils()->get_user_by_login( $profile_username );
			if ( $profile_user && $this->is_instructor_user( $profile_user->ID ) ) {
				$public_profile_id = absint( $profile_user->ID );
			}
		}
		foreach ( $users as $verified_id ) {
			$verified_id = absint( $verified_id );
			if ( ! $verified_id || ! $this->is_instructor_user( $verified_id ) ) {
				continue;
			}
			$user_obj = get_userdata( $verified_id );
			if ( $user_obj ) {
				$payload[ (string) $verified_id ] = array(
					'login' => strtolower( (string) $user_obj->user_login ),
					'slug'  => strtolower( (string) $user_obj->user_nicename ),
				);
			}
		}
		if ( empty( $payload ) ) {
			return;
		}
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
				sticker.setAttribute('title','<?php echo esc_js( __( 'Verified instructor', 'tutor-lms-ipe' ) ); ?>');
				sticker.setAttribute('aria-label','<?php echo esc_js( __( 'Verified instructor', 'tutor-lms-ipe' ) ); ?>');
				sticker.innerHTML='<span aria-hidden="true">✓</span><?php echo esc_js( __( 'Verified Instructor', 'tutor-lms-ipe' ) ); ?>';
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
				if(!body) return;
				var profileId=resolvePublicProfileId();
				if(!profileId) return;
				var area=document.querySelector('.pp-area') || document.querySelector('.profile-name');
				if(!area || area.querySelector('.tlms-ipe-profile-verified-sticker')) return;
				var sticker=makeSticker();
				sticker.classList.add('tlms-ipe-profile-verified-sticker');
				// Show the badge inline, right beside the instructor display name.
				var scope=area.matches && area.matches('.profile-name') ? area : (area.querySelector('.profile-name') || area);
				var name=scope.querySelector('h1,h2,h3,.tutor-profile-name');
				if(name){
					name.appendChild(sticker);
					return;
				}
				// Fallbacks: never use .profile-rating-media (it is hidden on one
				// breakpoint via content-for-mobile/content-for-desktop).
				var stats=scope.querySelector('.tutor-profile-details,.tutor-user-profile-details,.tutor-profile-meta,.profile-meta,.profile-rating');
				if(stats){
					stats.insertBefore(sticker,stats.firstChild);
					return;
				}
				area.insertBefore(sticker,area.firstChild);
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
				<span><?php esc_html_e( 'Private tutoring & profile details', 'tutor-lms-ipe' ); ?></span>
				<span class="tlms-ipe-public-drawer-chevron" aria-hidden="true">⌄</span>
			</summary>
			<div class="tlms-ipe-public-drawer-body">
			<?php if ( $type_text || $wilaya ) : ?>
				<section class="tlms-ipe-public-section tlms-ipe-private-tutoring">
					<h4><?php esc_html_e( 'Private tutoring', 'tutor-lms-ipe' ); ?></h4>
					<div class="tlms-ipe-info-grid">
						<?php if ( $type_text ) : ?><div><span class="tlms-ipe-label"><?php esc_html_e( 'Teaching format', 'tutor-lms-ipe' ); ?></span><strong><?php echo esc_html( implode( ', ', $type_text ) ); ?></strong></div><?php endif; ?>
						<?php if ( $wilaya ) : ?>
						<div class="tlms-ipe-location-display">
							<span class="tlms-ipe-label"><?php esc_html_e( 'Location', 'tutor-lms-ipe' ); ?></span>
							<?php $location_lines = $this->get_location_summary_lines( $wilaya, $commune ); ?>
							<div class="tlms-ipe-location-lines<?php echo count( $location_lines ) > 3 ? ' tlms-ipe-location-collapsed' : ''; ?>">
								<?php foreach ( $location_lines as $location_line ) : ?><div class="tlms-ipe-location-line"><?php echo esc_html( $location_line ); ?></div><?php endforeach; ?>
							</div>
							<?php if ( count( $location_lines ) > 3 ) : ?><button type="button" class="tlms-ipe-location-more" aria-expanded="false"><?php esc_html_e( 'Show more', 'tutor-lms-ipe' ); ?></button><?php endif; ?>
						</div>
					<?php endif; ?>
					</div>
				</section>
			<?php endif; ?>
			<?php if ( $education ) : ?>
				<section class="tlms-ipe-public-section"><h4><?php esc_html_e( 'Education', 'tutor-lms-ipe' ); ?></h4><div class="tlms-ipe-timeline">
				<?php foreach ( $education as $row ) : ?><div class="tlms-ipe-timeline-row"><div><strong><?php echo esc_html( $row['name'] ?? '' ); ?></strong><span><?php echo esc_html( $row['place'] ?? '' ); ?></span></div><time><?php echo esc_html( $this->format_profile_date( $row['date'] ?? '' ) ); ?></time></div><?php endforeach; ?>
				</div></section>
			<?php endif; ?>
			<?php if ( $experience ) : ?>
				<section class="tlms-ipe-public-section"><h4><?php esc_html_e( 'Experience', 'tutor-lms-ipe' ); ?></h4><div class="tlms-ipe-timeline">
				<?php foreach ( $experience as $row ) : ?><div class="tlms-ipe-timeline-row"><div><strong><?php echo esc_html( $row['job'] ?? '' ); ?></strong><span><?php echo esc_html( $row['place'] ?? '' ); ?></span></div><time><?php echo esc_html( (string) ( $row['years'] ?? '' ) ); ?> <?php esc_html_e( 'years', 'tutor-lms-ipe' ); ?></time></div><?php endforeach; ?>
				</div></section>
			<?php endif; ?>
			<?php if ( $categories ) : ?><section class="tlms-ipe-public-section"><h4><?php esc_html_e( 'What I can teach', 'tutor-lms-ipe' ); ?></h4><div class="tlms-ipe-teach-list"><?php $this->render_public_teach_categories( $categories ); ?></div></section><?php endif; ?>
			<?php if ( $languages ) : ?><section class="tlms-ipe-public-section"><h4><?php esc_html_e( 'Spoken languages', 'tutor-lms-ipe' ); ?></h4><div class="tlms-ipe-tag-list"><?php foreach ( $languages as $code ) : if ( isset( $language_choices[ $code ] ) ) : ?><span class="tlms-ipe-tag"><?php echo esc_html( $language_choices[ $code ] ); ?></span><?php endif; endforeach; ?></div></section><?php endif; ?>
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
					<?php esc_html_e( 'Private tutoring type', 'tutor-lms-ipe' ); ?> :
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
					<?php esc_html_e( 'Location', 'tutor-lms-ipe' ); ?> :
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

	/** Render hierarchical Tutor course category options. */
	private function render_category_options( $selected = array(), $parent = 0, $depth = 0 ) {
		$terms = get_terms( array( 'taxonomy' => 'course-category', 'hide_empty' => false, 'parent' => $parent, 'orderby' => 'name', 'order' => 'ASC' ) );
		if ( is_wp_error( $terms ) ) {
			return;
		}
		foreach ( $terms as $term ) {
			$prefix = str_repeat( '— ', $depth );
			echo '<option value="' . esc_attr( $term->term_id ) . '" ' . selected( in_array( (int) $term->term_id, array_map( 'intval', $selected ), true ), true ) . '>' . esc_html( $prefix . $term->name ) . '</option>';
			$this->render_category_options( $selected, (int) $term->term_id, $depth + 1 );
		}
	}

	/** Render course categories as a searchable checkbox tree. */
	private function render_category_checkboxes( $selected = array(), $parent = 0, $depth = 0 ) {
		$terms = get_terms( array( 'taxonomy' => 'course-category', 'hide_empty' => false, 'parent' => $parent, 'orderby' => 'name', 'order' => 'ASC' ) );
		if ( is_wp_error( $terms ) ) {
			return;
		}
		foreach ( $terms as $term ) {
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
