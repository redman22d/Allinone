<?php
/**
 * Plugin Name: Tutor LMS Private Sessions
 * Description: Private tutoring requests with tutor-approved proposals and direct-to-tutor offline payment declarations.
 * Version: 1.6.6
 * Author: Custom
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Text Domain: tutor-learning-suite
 * License: GPLv2 or later
 */

defined('ABSPATH') || exit;

define('TLMS_PS_VERSION', '1.6.6');
define('TLMS_PS_FILE', __FILE__);
define('TLMS_PS_PATH', plugin_dir_path(__FILE__));
define('TLMS_PS_URL', plugin_dir_url(__FILE__));
define('TLMS_PS_DB_VERSION', '25');

final class TLMS_Private_Sessions {
    const TABLE_SESSIONS = 'tlms_private_sessions';
    const TABLE_AVAIL = 'tlms_private_availability';
    const META_SUBJECT_PRICES = '_tlms_ps_subject_prices';
    const META_DURATION_FACTORS = '_tlms_ps_duration_factors';
    const OPTION_BOOKING_PAGE = 'tlms_ps_booking_page_id';
    const OPTION_SETTINGS = 'tlms_ps_settings';
    const NONCE = 'tlms_ps_nonce';
    const REQUEST_CREATED_HOOK = 'tlms_ps_process_request_created';
    const CRON_HOOK = 'tlms_ps_maintenance';

    private static $instance = null;
    private $wpdb;

    public static function instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        add_action('plugins_loaded', [$this, 'boot'], 20);
    }

    public function boot() {
        if (!function_exists('tutor') && !defined('TUTOR_VERSION')) {
            add_action('admin_notices', function () {
                if (current_user_can('activate_plugins')) {
                    echo '<div class="notice notice-warning"><p>' . esc_html__('Tutor LMS Private Sessions requires Tutor LMS to be active.', 'tutor-learning-suite') . '</p></div>';
                }
            });
            return;
        }
        add_filter('tutor_dashboard/instructor_nav_items', [$this, 'dashboard_nav'], 20);
        // Tutor LMS 4 has a dedicated learner navigation pipeline. Retain the
        // legacy hook for Tutor LMS 3.x/classic dashboard installations.
        add_filter('tutor_student_dashboard_nav', [$this, 'student_dashboard_nav'], 20);
        add_filter('tutor_dashboard/nav_items', [$this, 'student_dashboard_nav'], 20);
        add_filter('load_dashboard_template_part_from_other_location', [$this, 'load_dashboard_template_part'], 20);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets'], 1);
        add_action('wp_ajax_tlms_ps_slots', [$this, 'ajax_slots']);
        add_action('wp_ajax_nopriv_tlms_ps_slots', [$this, 'ajax_slots']);
        add_action('wp_ajax_tlms_ps_book', [$this, 'ajax_book']);
        add_action('wp_ajax_tlms_ps_create_request', [$this, 'ajax_create_request']);
        add_action(self::REQUEST_CREATED_HOOK, [$this, 'process_request_created'], 10, 3);
        add_action('wp_ajax_tlms_ps_send_proposal', [$this, 'ajax_send_proposal']);
        add_action('wp_ajax_tlms_ps_decline_proposal', [$this, 'ajax_decline_proposal']);
        add_action('wp_ajax_tlms_ps_resend_request', [$this, 'ajax_resend_request']);
        add_action('wp_ajax_tlms_ps_review_session', [$this, 'ajax_review_session']);
        add_action('wp_ajax_tlms_ps_tryout_decision', [$this, 'ajax_tryout_decision']);
        add_action('wp_ajax_tlms_ps_bulk_review_sessions', [$this, 'ajax_bulk_review_sessions']);
        add_action('wp_ajax_tlms_ps_cancel_session', [$this, 'ajax_cancel_session']);
        add_action('wp_ajax_tlms_ps_accept_proposal', [$this, 'ajax_add_to_cart']);
        // Legacy action remains as a compatibility alias; it no longer adds anything to a cart.
        add_action('wp_ajax_tlms_ps_add_to_cart', [$this, 'ajax_add_to_cart']);
        add_action('wp_ajax_tlms_ps_declare_payment', [$this, 'ajax_declare_payment']);
        add_action('wp_ajax_tlms_ps_confirm_payment', [$this, 'ajax_confirm_payment']);
        add_action('tutor_order_placed', [$this, 'on_tutor_order_placed'], 20, 1);
        add_action('tutor_order_payment_status_changed', [$this, 'on_tutor_payment_status_changed'], 20, 3);
        add_filter('pre_get_posts', [$this, 'hide_internal_courses'], 9999);
        add_filter('posts_clauses', [$this, 'hide_internal_courses_from_sql'], 9999, 2);
        add_filter('template_redirect', [$this, 'protect_rating_course'], 1);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_notices', [$this, 'render_private_reviews_in_tutor_reports']);
        add_action('wp_ajax_tlms_ps_set_rating_status', [$this, 'ajax_set_rating_status']);
        add_action('admin_post_tlms_ps_save_availability', [$this, 'save_availability']);
        add_action('admin_post_tlms_ps_save_pricing', [$this, 'save_pricing']);
        add_action('admin_post_tlms_ps_update_session', [$this, 'update_session']);
        add_action('wp_ajax_tlms_ps_save_availability', [$this, 'ajax_save_availability']);
        add_action('wp_ajax_tlms_ps_save_pricing', [$this, 'ajax_save_pricing']);
        add_action('wp_ajax_tlms_ps_save_tutor_settings', [$this, 'ajax_save_tutor_settings']);
        add_action('wp_ajax_tlms_ps_update_session', [$this, 'ajax_update_session']);
        add_action('wp_ajax_tlms_ps_get_tutor_rating', [$this, 'ajax_get_tutor_rating']);
        add_action('wp_ajax_tlms_ps_submit_tutor_rating', [$this, 'ajax_submit_tutor_rating']);
        add_action('wp_ajax_tlms_ps_run_maintenance', [$this, 'ajax_run_maintenance']);
        add_action('admin_post_tlms_ps_save_settings', [$this, 'save_settings']);
        add_action(self::CRON_HOOK, [$this, 'run_maintenance']);
        add_filter('rest_post_dispatch', [$this, 'inject_private_sessions_into_calendar_rest'], 20, 3);
        add_filter('tutor_calendar_events', [$this, 'filter_native_calendar_events'], 20, 2);
        add_filter('tutor_calendar_data', [$this, 'filter_native_calendar_events'], 20, 2);
        add_filter('tutor_localize_data', [$this, 'add_private_calendar_localized_data'], 20, 1);
        add_filter('rest_pre_echo_response', [$this, 'inject_private_sessions_into_calendar_rest'], 20, 3);
        add_action('rest_api_init', [$this, 'register_calendar_rest_route']);
        add_shortcode('tlms_private_booking', [$this, 'shortcode_booking']);
        add_shortcode('tlms_private_sessions', [$this, 'shortcode_dashboard']);
        add_action('wp_footer', [$this, 'inject_public_profile_button'], 30);
        $this->ensure_dashboard_template();
        $this->maybe_upgrade();
        $this->ensure_schema();
        $this->ensure_request_columns();
        if (!wp_next_scheduled(self::CRON_HOOK)) wp_schedule_event(time() + 300, 'hourly', self::CRON_HOOK);
    }

    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $sessions = $wpdb->prefix . self::TABLE_SESSIONS;
        $avail = $wpdb->prefix . self::TABLE_AVAIL;
        $sql1 = "CREATE TABLE {$sessions} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            tutor_id bigint(20) unsigned NOT NULL,
            student_id bigint(20) unsigned NULL,
            learner_user_id bigint(20) unsigned NULL,
            session_date date NULL,
            start_time time NULL,
            end_time time NULL,
            session_timezone varchar(64) NULL,
            tutoring_type varchar(30) NOT NULL DEFAULT 'online',
            session_type varchar(20) NOT NULL DEFAULT 'one_to_one',
            duration_minutes smallint(5) unsigned NOT NULL DEFAULT 60,
            subject varchar(190) NOT NULL DEFAULT '',
            teach_category_id bigint(20) unsigned NULL,
            student_name varchar(190) NULL,
            student_email varchar(190) NULL,
            student_phone varchar(60) NULL,
            requested_hours decimal(5,2) NULL,
            preferred_time varchar(190) NULL,
            requested_session_date date NULL,
            requested_start_time time NULL,
            requested_end_time time NULL,
            requested_timezone varchar(64) NULL,
            requested_duration_minutes smallint(5) unsigned NULL,
            proposal_message text NULL,
            proposal_sent_at datetime NULL,
            tutor_responded_at datetime NULL,
            payment_offer_sent_at datetime NULL,
            student_response text NULL,
            final_access_sent_at datetime NULL,
            reminder_sent_at datetime NULL,
            resubmitted_from_id bigint(20) unsigned NULL,
            notes text NULL,
            goal text NULL,
            is_tryout tinyint(1) unsigned NOT NULL DEFAULT 0,
            terms_accepted tinyint(1) unsigned NOT NULL DEFAULT 0,
            cancellation_accepted tinyint(1) unsigned NOT NULL DEFAULT 0,
            terms_accepted_at datetime NULL,
            cancellation_accepted_at datetime NULL,
            terms_policy_snapshot longtext NULL,
            cancellation_policy_snapshot longtext NULL,
            terms_policy_hash char(64) NULL,
            cancellation_policy_hash char(64) NULL,
            price decimal(18,2) NOT NULL DEFAULT 0,
            currency varchar(12) NOT NULL DEFAULT 'USD',
            status varchar(30) NOT NULL DEFAULT 'pending',
            meeting_url text NULL,
            meeting_details text NULL,
            order_id bigint(20) unsigned NULL,
            native_course_id bigint(20) unsigned NULL,
            request_token varchar(64) NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY tutor_date (tutor_id, session_date),
            KEY student_date (student_id, session_date),
            KEY status_date (status, session_date),
            UNIQUE KEY request_token (request_token)
        ) {$charset};";
        $sql2 = "CREATE TABLE {$avail} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            tutor_id bigint(20) unsigned NOT NULL,
            weekday tinyint(1) unsigned NOT NULL,
            period varchar(12) NOT NULL DEFAULT 'morning',
            start_time time NOT NULL,
            end_time time NOT NULL,
            enabled tinyint(1) unsigned NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            KEY tutor_weekday (tutor_id, weekday)
        ) {$charset};";
        dbDelta($sql1);
        dbDelta($sql2);
        add_option(self::OPTION_SETTINGS, [
            'enabled' => 1,
            'require_payment' => 1,
            'require_tutor_approval' => 1,
            'require_admin_approval' => 1,
            'currency' => 'DZD',
            'slot_step' => 30,
            'min_notice_hours' => 2,
            'cancel_hours' => 24,
            'default_duration' => 60,
            'default_price' => 0,
            'allow_private_ratings' => 1,
            'tutor_response_days' => 3,
            'proposal_response_days' => 3,
            'payment_timeout_days' => 2,
            'temp_course_cleanup_days' => 7,
        ]);
        $page = get_page_by_path('private-booking');
        if (!$page) {
            $page_id = wp_insert_post([
                'post_title' => 'Private Booking',
                'post_name' => 'private-booking',
                'post_content' => '[tlms_private_booking]',
                'post_status' => 'publish',
                'post_type' => 'page',
            ]);
            update_option(self::OPTION_BOOKING_PAGE, (int) $page_id);
        } else {
            update_option(self::OPTION_BOOKING_PAGE, (int) $page->ID);
        }
        update_option('tlms_ps_db_version', TLMS_PS_DB_VERSION);
        if (!wp_next_scheduled(self::CRON_HOOK)) wp_schedule_event(time() + 300, 'hourly', self::CRON_HOOK);
        if (class_exists('TLMS_Private_Sessions')) { self::instance()->ensure_dashboard_template(); }
    }

    public function settings() {
        $defaults = [
            'enabled' => 1,
            'require_payment' => 1,
            'require_tutor_approval' => 1,
            'require_admin_approval' => 1,
            'currency' => 'DZD',
            'slot_step' => 30,
            'min_notice_hours' => 2,
            'cancel_hours' => 24,
            'default_duration' => 60,
            'default_price' => 0,
            'allow_private_ratings' => 1,
            'tutor_response_days' => 3,
            'proposal_response_days' => 3,
            'payment_timeout_days' => 2,
            'temp_course_cleanup_days' => 7,
            'reminder_hours_before' => 24,
            'offer_tryout' => 0,
            'tryout_limit_one_per_student' => 1,
            'tryout_duration' => 30,
            'tryout_price' => 0,
            'terms' => '',
            'cancellation_policy' => '',
        ];
        return wp_parse_args((array) get_option(self::OPTION_SETTINGS, []), $defaults);
    }

    public function is_instructor($user_id) {
        $user = get_userdata(absint($user_id));
        if (!$user) return false;
        return in_array('tutor_instructor', (array) $user->roles, true) || user_can($user_id, 'manage_tutor_instructor');
    }

    private function dashboard_menu_icon() {
        // Tutor LMS 4.x sidebar renders SVG icon names. Older versions use the legacy icon font.
        if (defined('TUTOR_VERSION') && version_compare(TUTOR_VERSION, '4.0.0', '>=')) {
            if (class_exists('\TUTOR\Icon') && defined('\TUTOR\Icon::CALENDAR_CHECK')) {
                return \TUTOR\Icon::CALENDAR_CHECK;
            }
            return 'calendar-check';
        }
        return 'calendar-line';
    }

    /** Requests waiting for this tutor: new requests to answer plus payments to confirm. */
    private function tutor_action_count($tutor_id){
        $tutor_id=absint($tutor_id); if(!$tutor_id || !$this->is_instructor($tutor_id)) return 0;
        static $cache=[]; if(isset($cache[$tutor_id])) return $cache[$tutor_id];
        return $cache[$tutor_id]=(int)$this->wpdb->get_var($this->wpdb->prepare("SELECT COUNT(*) FROM {$this->table(self::TABLE_SESSIONS)} WHERE tutor_id=%d AND (status IN ('pending_tutor','pending_admin') OR (status='approved' AND payment_declared_at IS NOT NULL))",$tutor_id));
    }

    /** Proposals waiting for this learner's answer or payment. */
    private function student_action_count($student_id){
        $student_id=absint($student_id); if(!$student_id) return 0;
        static $cache=[]; if(isset($cache[$student_id])) return $cache[$student_id];
        return $cache[$student_id]=(int)$this->wpdb->get_var($this->wpdb->prepare("SELECT COUNT(*) FROM {$this->table(self::TABLE_SESSIONS)} WHERE student_id=%d AND (status='proposal_sent' OR (status='approved' AND payment_declared_at IS NULL))",$student_id));
    }

    public function dashboard_nav($items) {
        $icon = $this->dashboard_menu_icon();
        $todo = $this->tutor_action_count(get_current_user_id());
        $items['private-sessions'] = [
            'title'       => $todo ? sprintf(__('Private Sessions (%d)', 'tutor-learning-suite'), $todo) : __('Private Sessions', 'tutor-learning-suite'),
            'icon'        => $icon,
            'active_icon' => $icon,
            'url'         => $this->dashboard_url('private-sessions'),
            'auth_cap'    => function_exists('tutor') ? tutor()->instructor_role : 'manage_tutor_instructor',
        ];
        return $items;
    }

    public function student_dashboard_nav($items) {
        if (!is_array($items)) $items = [];
        $uid = get_current_user_id();
        if (!$uid) return $items;
        // Instructors can also be learners, so expose a separate learner-facing page.
        $icon = $this->dashboard_menu_icon();
        $todo = $this->student_action_count($uid);
        $items['my-private-sessions'] = [
            'title'       => $todo ? sprintf(__('My Private Sessions (%d)', 'tutor-learning-suite'), $todo) : __('My Private Sessions', 'tutor-learning-suite'),
            'icon'        => $icon,
            'active_icon' => $icon,
            'url'         => $this->dashboard_url('my-private-sessions'),
        ];
        return $items;
    }

    public function load_dashboard_template_part($location) {
        if (!in_array(get_query_var('tutor_dashboard_page'), ['private-sessions','my-private-sessions'], true)) return $location;
        $file = TLMS_PS_PATH . 'templates/dashboard/private-sessions.php';
        return file_exists($file) ? $file : $location;
    }

    private function dashboard_url($route) {
        $page_id = 0;
        if (function_exists('tutor_utils')) {
            $page_id = absint(tutor_utils()->get_option('tutor_dashboard_page_id'));
            if (!$page_id) {
                $pages = (array) tutor_utils()->get_option('tutor_pages');
                $page_id = absint($pages['dashboard'] ?? 0);
            }
        }
        $base = $page_id ? get_permalink($page_id) : home_url('/dashboard/');
        return add_query_arg('tutor_dashboard_page', sanitize_key($route), $base);
    }

    public function register_calendar_rest_route() {
        register_rest_route('tlms-private-sessions/v1', '/calendar', [
            'methods'  => WP_REST_Server::READABLE,
            'permission_callback' => function (WP_REST_Request $request) {
                return $this->calendar_rest_permission($request);
            },
            'callback' => function (WP_REST_Request $request) {
                // permission_callback is the security boundary; resolve the same
                // target only after that ownership check has passed.
                $user_id = $this->calendar_user_from_request($request);
                return rest_ensure_response($this->get_calendar_private_session_events($user_id));
            },
        ]);
    }

    public function enqueue_assets() {
        if (!$this->settings()['enabled']) return;
        wp_register_style('tlms-ps', TLMS_PS_URL . 'assets/private-sessions.css', [], TLMS_PS_VERSION);
        wp_register_script('tlms-ps', TLMS_PS_URL . 'assets/private-sessions.js', ['jquery'], TLMS_PS_VERSION, true);
        wp_localize_script('tlms-ps', 'TLMSPS', [
            'ajax' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE),
            'bookingPage' => get_permalink((int) get_option(self::OPTION_BOOKING_PAGE)),
        ]);
        // The booking modal and dashboard forms this script/stylesheet power
        // only ever appear on the tutor profile page, the Private Sessions
        // dashboard tabs, or a page/post carrying one of this plugin's
        // shortcodes, so skip the ~57KB payload everywhere else on the site
        // (home page, blog posts, unrelated pages, etc). Sites with unusual
        // template setups that don't trip these checks can force-load via
        // the tlms_ps_force_load_assets filter.
        if (!$this->current_request_needs_assets()) return;
        wp_enqueue_style('tlms-ps');
        wp_enqueue_script('tlms-ps');

        // Tutor LMS 4.x Calendar fetches its events from admin-ajax.php
        // (action=get_calendar_materials). Bridge the private-session records into
        // that exact response shape so the native React calendar renders them.
        if ($this->is_tutor_calendar_page()) {
            $events = $this->get_calendar_private_session_events(get_current_user_id());
            wp_register_script(
                'tlms-ps-calendar',
                TLMS_PS_URL . 'assets/private-sessions-calendar.js',
                [],
                TLMS_PS_VERSION,
                true
            );
            wp_localize_script('tlms-ps-calendar', 'TLMSPSCalendar', [
                'events' => $events,
                'dashboardUrl' => $this->dashboard_url('my-private-sessions'),
                'label' => __('Private session', 'tutor-learning-suite'),
                'title' => __('Private tutoring session', 'tutor-learning-suite'),
                'ajaxUrl' => admin_url('admin-ajax.php'),
            ]);
            wp_enqueue_script('tlms-ps-calendar');
            // No DOM overlay: Tutor LMS owns the calendar rendering.
        }
    }

    private function is_tutor_calendar_page() {
        // Modern Tutor LMS uses a real path such as /dashboard/calendar/ with
        // optional query args (for example ?tab=calendar). Older dashboard
        // builds may expose tutor_dashboard_page as a query/rewrite var.
        $page = isset($_GET['tutor_dashboard_page']) ? sanitize_key(wp_unslash($_GET['tutor_dashboard_page'])) : '';
        if (!$page) {
            $page = sanitize_key((string) get_query_var('tutor_dashboard_page'));
        }
        if (in_array($page, ['calendar', 'tutor-calendar'], true)) {
            return true;
        }

        $request_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
        $path = (string) wp_parse_url($request_uri, PHP_URL_PATH);
        $path = '/' . ltrim(untrailingslashit($path), '/');
        return (bool) preg_match('#/dashboard/calendar$#i', $path);
    }

    private function current_request_needs_assets() {
        if (apply_filters('tlms_ps_force_load_assets', false)) return true;

        // Public tutor profile page (booking modal is injected in wp_footer
        // for these; see inject_public_profile_button()).
        $profile_username = isset($GLOBALS['wp']->query_vars['tutor_profile_username'])
            ? sanitize_user((string) $GLOBALS['wp']->query_vars['tutor_profile_username'])
            : sanitize_user((string) get_query_var('tutor_profile_username'));
        if ($profile_username) return true;

        // Tutor dashboard: Private Sessions (tutor) / My Private Sessions
        // (instructor-as-learner) / Calendar tabs all render this plugin's
        // markup and rely on this script for their AJAX forms.
        $dash_page = isset($_GET['tutor_dashboard_page']) ? sanitize_key(wp_unslash($_GET['tutor_dashboard_page'])) : '';
        if (!$dash_page) $dash_page = sanitize_key((string) get_query_var('tutor_dashboard_page'));
        if (in_array($dash_page, ['private-sessions', 'my-private-sessions', 'calendar', 'tutor-calendar'], true)) return true;
        $request_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
        $path = '/' . ltrim(untrailingslashit((string) wp_parse_url($request_uri, PHP_URL_PATH)), '/');
        if (preg_match('#/dashboard/(private-sessions|my-private-sessions|calendar)$#i', $path)) return true;

        // Any page/post embedding one of the plugin's shortcodes directly.
        if (is_singular()) {
            $post = get_post();
            if ($post && (has_shortcode((string) $post->post_content, 'tlms_private_booking') || has_shortcode((string) $post->post_content, 'tlms_private_sessions'))) {
                return true;
            }
        }

        // The dedicated public booking page configured in settings.
        $booking_page_id = (int) get_option(self::OPTION_BOOKING_PAGE);
        if ($booking_page_id && is_page($booking_page_id)) return true;

        return false;
    }

    private function ensure_dashboard_template() {
        if (!function_exists('get_stylesheet_directory')) return;
        $dir = trailingslashit(get_stylesheet_directory()) . 'tutor/dashboard';
        $file = $dir . '/private-sessions.php';
        if (file_exists($file)) return;
        if (!wp_mkdir_p($dir)) return;
        $code = "<?php\ndefined('ABSPATH') || exit;\nif (class_exists('TLMS_Private_Sessions') && is_user_logged_in()) { echo TLMS_Private_Sessions::instance()->render_dashboard(get_current_user_id()); }\n";
        @file_put_contents($file, $code);
    }

    public function admin_menu() {
        add_submenu_page('tutor', __('Private Sessions', 'tutor-learning-suite'), __('Private Sessions', 'tutor-learning-suite'), 'manage_options', 'tlms-private-sessions', [$this, 'admin_page']);
        add_submenu_page('tutor', __('Private Tutor Reviews', 'tutor-learning-suite'), __('Private Tutor Reviews', 'tutor-learning-suite'), 'manage_options', 'tlms-private-sessions-reviews', [$this, 'private_reviews_admin_page']);
    }

    public function private_reviews_admin_page() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Permission denied.', 'tutor-learning-suite'));
        $rows = $this->get_private_rating_review_rows('', 200);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Private Tutor Reviews', 'tutor-learning-suite'); ?></h1>
            <p><?php esc_html_e('Private-session ratings use Tutor LMS native course-review records. Reviews remain unpublished until an administrator approves them.', 'tutor-learning-suite'); ?></p>
            <table class="widefat striped">
                <thead><tr><th><?php esc_html_e('Student', 'tutor-learning-suite'); ?></th><th><?php esc_html_e('Tutor', 'tutor-learning-suite'); ?></th><th><?php esc_html_e('Rating', 'tutor-learning-suite'); ?></th><th><?php esc_html_e('Review', 'tutor-learning-suite'); ?></th><th><?php esc_html_e('Date', 'tutor-learning-suite'); ?></th><th><?php esc_html_e('Status', 'tutor-learning-suite'); ?></th><th><?php esc_html_e('Action', 'tutor-learning-suite'); ?></th></tr></thead>
                <tbody>
                <?php if (!$rows): ?><tr><td colspan="7"><?php esc_html_e('No private tutor reviews found.', 'tutor-learning-suite'); ?></td></tr><?php else: foreach ($rows as $r): ?>
                    <tr>
                        <td><?php echo esc_html($r['student']); ?></td>
                        <td><?php echo esc_html($r['tutor']); ?></td>
                        <td><?php echo esc_html(str_repeat('★', (int)$r['rating']) . str_repeat('☆', 5-(int)$r['rating'])); ?></td>
                        <td><?php echo esc_html($r['review'] !== '' ? $r['review'] : '—'); ?></td>
                        <td><?php echo esc_html($r['date']); ?></td>
                        <td><?php echo esc_html($this->private_feedback_stage_label($r)); ?></td>
                        <td><?php if (in_array($r['feedback_stage'], ['guardian_pending','parent_changes_requested','parent_rejected','admin_rejected'], true)): ?><button type="button" class="button" disabled><?php esc_html_e('Not ready to publish','tutor-learning-suite'); ?></button><?php elseif (!$r['approved']): ?><button type="button" class="button button-primary tlms-ps-private-review-status" data-id="<?php echo (int)$r['comment_id']; ?>" data-status="1"><?php esc_html_e('Publish','tutor-learning-suite'); ?></button><?php else: ?><button type="button" class="button tlms-ps-private-review-status" data-id="<?php echo (int)$r['comment_id']; ?>" data-status="0"><?php esc_html_e('Unpublish','tutor-learning-suite'); ?></button><?php endif; ?> <button type="button" class="button button-link-delete tlms-ps-private-review-status" data-id="<?php echo (int)$r['comment_id']; ?>" data-status="trash"><?php esc_html_e('Delete','tutor-learning-suite'); ?></button></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <script>(function(){document.addEventListener('click',function(e){var b=e.target.closest('.tlms-ps-private-review-status');if(!b)return;if(b.dataset.status==='trash'&&!confirm('<?php echo esc_js(__('Delete this review permanently?','tutor-learning-suite'));?>'))return;var fd=new FormData();fd.append('action','tlms_ps_set_rating_status');fd.append('nonce','<?php echo esc_js(wp_create_nonce(self::NONCE));?>');fd.append('comment_id',b.dataset.id);fd.append('status',b.dataset.status);fetch('<?php echo esc_url_raw(admin_url('admin-ajax.php'));?>',{method:'POST',credentials:'same-origin',body:fd}).then(function(r){return r.json();}).then(function(r){if(r.success)location.reload();else alert((r.data&&r.data.message)||'<?php echo esc_js(__('Could not update the review.','tutor-learning-suite'));?>');}).catch(function(){alert('<?php echo esc_js(__('Could not update the review.','tutor-learning-suite'));?>');});});})();</script>
        <?php
    }

    public function render_private_reviews_in_tutor_reports() {
        if ( ! current_user_can('manage_options') ) return;
        if ( ! is_admin() ) return;
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $sub  = isset($_GET['sub_page']) ? sanitize_key(wp_unslash($_GET['sub_page'])) : (isset($_GET['subpage']) ? sanitize_key(wp_unslash($_GET['subpage'])) : '');
        if ($page !== 'tutor_report' || !in_array($sub, ['reviews','review',''], true)) return;
        if ($sub === '') {
            $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
            if ($tab !== 'reviews') return;
        }
        $rows = $this->get_private_rating_review_rows('', 50);
        if (!$rows) return;
        ?>
        <div class="notice" style="padding:0;border:0;background:transparent;box-shadow:none;margin:14px 0 18px 0">
            <div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;overflow:hidden">
                <div style="padding:14px 16px;border-bottom:1px solid #dcdcde;display:flex;justify-content:space-between;align-items:center;gap:12px">
                    <div><strong style="font-size:16px"><?php esc_html_e('Private Tutor Reviews', 'tutor-learning-suite'); ?></strong><div style="margin-top:4px;color:#646970"><?php esc_html_e('These ratings use Tutor LMS native course-review records and are waiting for admin moderation.', 'tutor-learning-suite'); ?></div></div>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=tlms-private-sessions-reviews')); ?>"><?php esc_html_e('Open full review list', 'tutor-learning-suite'); ?></a>
                </div>
                <table class="widefat striped" style="border:0;margin:0">
                    <thead><tr><th><?php esc_html_e('Student', 'tutor-learning-suite'); ?></th><th><?php esc_html_e('Tutor', 'tutor-learning-suite'); ?></th><th><?php esc_html_e('Rating', 'tutor-learning-suite'); ?></th><th><?php esc_html_e('Review', 'tutor-learning-suite'); ?></th><th><?php esc_html_e('Status', 'tutor-learning-suite'); ?></th><th><?php esc_html_e('Action', 'tutor-learning-suite'); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?php echo esc_html($r['student']); ?></td>
                            <td><?php echo esc_html($r['tutor']); ?></td>
                            <td><?php echo esc_html(str_repeat('★', (int)$r['rating']) . str_repeat('☆', 5-(int)$r['rating'])); ?></td>
                            <td><?php echo esc_html($r['review'] !== '' ? $r['review'] : '—'); ?></td>
                        <td><?php echo esc_html($this->private_feedback_stage_label($r)); ?></td>
                        <td><?php if (in_array($r['feedback_stage'], ['guardian_pending','parent_changes_requested','parent_rejected','admin_rejected'], true)): ?><button type="button" class="button" disabled><?php esc_html_e('Not ready to publish','tutor-learning-suite'); ?></button><?php elseif (!$r['approved']): ?><button type="button" class="button button-primary tlms-ps-private-review-status" data-id="<?php echo (int)$r['comment_id']; ?>" data-status="1"><?php esc_html_e('Publish','tutor-learning-suite'); ?></button><?php else: ?><button type="button" class="button tlms-ps-private-review-status" data-id="<?php echo (int)$r['comment_id']; ?>" data-status="0"><?php esc_html_e('Unpublish','tutor-learning-suite'); ?></button><?php endif; ?> <button type="button" class="button button-link-delete tlms-ps-private-review-status" data-id="<?php echo (int)$r['comment_id']; ?>" data-status="trash"><?php esc_html_e('Delete','tutor-learning-suite'); ?></button></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <script>(function(){if(window.TLMSPSPrivateReviewReportBound)return;window.TLMSPSPrivateReviewReportBound=true;document.addEventListener('click',function(e){var b=e.target.closest('.tlms-ps-private-review-status');if(!b)return;if(b.dataset.status==='trash'&&!confirm('<?php echo esc_js(__('Delete this review permanently?','tutor-learning-suite'));?>'))return;var fd=new FormData();fd.append('action','tlms_ps_set_rating_status');fd.append('nonce','<?php echo esc_js(wp_create_nonce(self::NONCE));?>');fd.append('comment_id',b.dataset.id);fd.append('status',b.dataset.status);fetch('<?php echo esc_url_raw(admin_url('admin-ajax.php'));?>',{method:'POST',credentials:'same-origin',body:fd}).then(function(r){return r.json();}).then(function(r){if(r.success)location.reload();else alert((r.data&&r.data.message)||'<?php echo esc_js(__('Could not update the review.','tutor-learning-suite'));?>');}).catch(function(){alert('<?php echo esc_js(__('Could not update the review.','tutor-learning-suite'));?>');});});})();</script>
        <?php
    }

    public function ajax_set_rating_status(){
        check_ajax_referer(self::NONCE,'nonce');
        if(!current_user_can('manage_options')) wp_send_json_error(['message'=>__('Permission denied.','tutor-learning-suite')],403);
        $comment_id=absint($_POST['comment_id']??0);
        $status=sanitize_key(wp_unslash($_POST['status']??''));
        if(!$comment_id || !in_array($status,['0','1','trash'],true)) wp_send_json_error(['message'=>__('Invalid review status.','tutor-learning-suite')],400);
        $comment=get_comment($comment_id);
        if(!$comment || $comment->comment_type!=='tutor_course_rating' || !get_post_meta((int)$comment->comment_post_ID,'_tlms_ps_rating_course',true)) wp_send_json_error(['message'=>__('This is not a private tutor review.','tutor-learning-suite')],404);
        $feedback_stage = class_exists('TLS_Feedback_Moderation') ? (string) get_comment_meta($comment_id, TLS_Feedback_Moderation::STAGE_META, true) : '';
        if ($status === '1' && in_array($feedback_stage, ['guardian_pending','parent_changes_requested','parent_rejected','admin_rejected'], true)) {
            wp_send_json_error(['message'=>__('This learner review has not completed the required parent/guardian review stage and cannot be published.','tutor-learning-suite')],409);
        }
        if($status==='trash'){
            wp_delete_comment($comment_id,true);
        }else{
            // Tutor LMS's native review engine uses 'approved' for published
            // ratings. Keep pending reviews at 0 so the student sees them as
            // under review, then switch to the native literal on approval.
            $native_status = $status==='1' ? 'approved' : '0';
            $ok=$this->wpdb->update($this->wpdb->comments,['comment_approved'=>$native_status],['comment_ID'=>$comment_id],['%s'],['%d']);
            if($ok===false) wp_send_json_error(['message'=>__('Could not update the review.','tutor-learning-suite')],500);
            if($status==='1') do_action('tutor_course_review_approved',$comment_id);
        }
        wp_send_json_success(['message'=>__('Review updated.','tutor-learning-suite')]);
    }

    private function private_feedback_stage_label($row){
        $stage = isset($row['feedback_stage']) ? (string) $row['feedback_stage'] : '';
        if ('guardian_pending' === $stage) return __('Awaiting parent/guardian approval','tutor-learning-suite');
        if ('parent_changes_requested' === $stage) return __('Returned to learner for changes','tutor-learning-suite');
        if ('parent_rejected' === $stage) return __('Not approved by parent/guardian','tutor-learning-suite');
        if ('admin_rejected' === $stage) return __('Rejected by administrator','tutor-learning-suite');
        if (!empty($row['approved'])) return __('Published','tutor-learning-suite');
        if ('admin_pending' === $stage) return __('Awaiting administrator approval','tutor-learning-suite');
        return __('Under review','tutor-learning-suite');
    }

    private function get_private_rating_review_rows($status='', $limit=100){
        global $wpdb;
        $limit=max(1,min(200,absint($limit)));
        $where="c.comment_type='tutor_course_rating' AND pm.meta_key='_tlms_ps_rating_course'";
        $params=[];
        if($status!=='') { $where.=$status==='pending' ? " AND c.comment_approved='0'" : ($status==='published' ? " AND c.comment_approved='approved'" : ''); }
        $sql="SELECT c.comment_ID,c.comment_content,c.comment_approved,c.user_id,c.comment_post_ID,c.comment_date, p.post_author, cm.meta_value AS tutor_rating, stage.meta_value AS feedback_stage FROM {$wpdb->comments} c INNER JOIN {$wpdb->postmeta} pm ON pm.post_id=c.comment_post_ID AND pm.meta_key='_tlms_ps_rating_course' LEFT JOIN {$wpdb->posts} p ON p.ID=c.comment_post_ID LEFT JOIN {$wpdb->commentmeta} cm ON cm.comment_id=c.comment_ID AND cm.meta_key='tutor_rating' LEFT JOIN {$wpdb->commentmeta} stage ON stage.comment_id=c.comment_ID AND stage.meta_key='_tls_feedback_stage' WHERE {$where} ORDER BY c.comment_date_gmt DESC LIMIT %d";
        $raw=$wpdb->get_results($wpdb->prepare($sql,$limit));
        $out=[];
        foreach((array)$raw as $r){ $student=get_userdata((int)$r->user_id); $tutor=get_userdata((int)$r->post_author); $out[]=['comment_id'=>(int)$r->comment_ID,'student'=>$student?$student->display_name:(string)$r->user_id,'tutor'=>$tutor?$tutor->display_name:(string)$r->post_author,'rating'=>max(1,min(5,(int)$r->tutor_rating)),'review'=>(string)$r->comment_content,'approved'=>in_array((string)$r->comment_approved,['1','approved'],true),'feedback_stage'=>(string)($r->feedback_stage??''),'course_id'=>(int)$r->comment_post_ID,'date'=>(string)$r->comment_date]; }
        return $out;
    }

    public function admin_page() {
        if (!current_user_can('manage_options')) return;
        $s = $this->settings();
        $rows = $this->wpdb->get_results("SELECT * FROM {$this->table(self::TABLE_SESSIONS)} ORDER BY session_date DESC, start_time DESC LIMIT 100");
        ?>
        <div class="wrap tlms-ps-admin">
            <h1><?php esc_html_e('Tutor LMS Private Sessions', 'tutor-learning-suite'); ?></h1>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('tlms_ps_save_settings', '_tlms_ps_settings_nonce'); ?>
                <input type="hidden" name="action" value="tlms_ps_save_settings">
                <table class="form-table">
                    <tr><th><?php esc_html_e('Enable private sessions', 'tutor-learning-suite'); ?></th><td><label><input type="checkbox" name="enabled" value="1" <?php checked($s['enabled'],1); ?>> <?php esc_html_e('Allow tutors to accept private bookings', 'tutor-learning-suite'); ?></label></td></tr>
                    <tr><th><?php esc_html_e('Require payment', 'tutor-learning-suite'); ?></th><td><label><input type="checkbox" name="require_payment" value="1" <?php checked($s['require_payment'],1); ?>> <?php esc_html_e('Keep paid bookings pending until payment/confirmation is completed.', 'tutor-learning-suite'); ?></label></td></tr>
                    <tr><th><?php esc_html_e('Require tutor approval', 'tutor-learning-suite'); ?></th><td><label><input type="checkbox" name="require_tutor_approval" value="1" <?php checked($s['require_tutor_approval'],1); ?>> <?php esc_html_e('Tutor must approve a booking request before payment.', 'tutor-learning-suite'); ?></label></td></tr>
                    <tr><th><?php esc_html_e('Require admin approval', 'tutor-learning-suite'); ?></th><td><label><input type="checkbox" name="require_admin_approval" value="1" <?php checked($s['require_admin_approval'],1); ?>> <?php esc_html_e('Admin must approve a booking before payment.', 'tutor-learning-suite'); ?></label></td></tr>
                    <tr><th><?php esc_html_e('Currency', 'tutor-learning-suite'); ?></th><td><input class="regular-text" name="currency" value="<?php echo esc_attr($s['currency']); ?>"></td></tr>
                    <tr><th><?php esc_html_e('Slot step (minutes)', 'tutor-learning-suite'); ?></th><td><input type="number" min="5" step="5" name="slot_step" value="<?php echo esc_attr($s['slot_step']); ?>"></td></tr>
                    <tr><th><?php esc_html_e('Minimum notice (hours)', 'tutor-learning-suite'); ?></th><td><input type="number" min="0" step="1" name="min_notice_hours" value="<?php echo esc_attr($s['min_notice_hours']); ?>"></td></tr>
                    <tr><th><?php esc_html_e('Cancellation window (hours)', 'tutor-learning-suite'); ?></th><td><input type="number" min="0" step="1" name="cancel_hours" value="<?php echo esc_attr($s['cancel_hours']); ?>"></td></tr>
                    <tr><th><?php esc_html_e('Default duration', 'tutor-learning-suite'); ?></th><td><input type="number" min="15" step="15" name="default_duration" value="<?php echo esc_attr($s['default_duration']); ?>"></td></tr>
                    <tr><th><?php esc_html_e('Default price', 'tutor-learning-suite'); ?></th><td><input type="number" min="0" step="0.01" name="default_price" value="<?php echo esc_attr($s['default_price']); ?>"></td></tr>
                    <tr><th><?php esc_html_e('Allow private-session tutor ratings', 'tutor-learning-suite'); ?></th><td><label><input type="checkbox" name="allow_private_ratings" value="1" <?php checked($s['allow_private_ratings'],1); ?>> <?php esc_html_e('Allow a tutor review only after a private session is marked completed.', 'tutor-learning-suite'); ?></label></td></tr>
                    <tr><th><?php esc_html_e('Tutor response timeout (days)', 'tutor-learning-suite'); ?></th><td><input type="number" min="1" max="365" step="1" name="tutor_response_days" value="<?php echo esc_attr($s['tutor_response_days']); ?>"></td></tr>
                    <tr><th><?php esc_html_e('Student proposal response timeout (days)', 'tutor-learning-suite'); ?></th><td><input type="number" min="1" max="365" step="1" name="proposal_response_days" value="<?php echo esc_attr($s['proposal_response_days']); ?>"></td></tr>
                    <tr><th><?php esc_html_e('Payment timeout (days)', 'tutor-learning-suite'); ?></th><td><input type="number" min="1" max="365" step="1" name="payment_timeout_days" value="<?php echo esc_attr($s['payment_timeout_days']); ?>"></td></tr>
                    <tr><th><?php esc_html_e('Legacy temporary course cleanup (days)', 'tutor-learning-suite'); ?></th><td><input type="number" min="1" max="3650" step="1" name="temp_course_cleanup_days" value="<?php echo esc_attr($s['temp_course_cleanup_days']); ?>"><p class="description"><?php esc_html_e('Only legacy temporary checkout courses from older plugin versions are deleted after this period. Current private bookings use direct-to-tutor payment and do not create course products. Private Tutor Reviews courses are excluded.', 'tutor-learning-suite'); ?></p></td></tr>
                    <tr><th><?php esc_html_e('Session reminder (hours before)', 'tutor-learning-suite'); ?></th><td><input type="number" min="0" max="168" step="1" name="reminder_hours_before" value="<?php echo esc_attr($s['reminder_hours_before']); ?>"><p class="description"><?php esc_html_e('Tutor and student both get a one-time reminder email this many hours before a paid/confirmed session starts. Set to 0 to disable.', 'tutor-learning-suite'); ?></p></td></tr>
                </table>
                <p><button class="button button-primary"><?php esc_html_e('Save settings', 'tutor-learning-suite'); ?></button></p>
            </form>
            <h2><?php esc_html_e('Maintenance', 'tutor-learning-suite'); ?></h2>
            <?php $tmp_count=(int)$this->wpdb->get_var("SELECT COUNT(*) FROM {$this->wpdb->posts} p INNER JOIN {$this->wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_tlms_ps_internal_course' AND pm.meta_value='1' LEFT JOIN {$this->wpdb->postmeta} rm ON rm.post_id=p.ID AND rm.meta_key='_tlms_ps_rating_course' WHERE p.post_type='" . esc_sql(function_exists('tutor')&&isset(tutor()->course_post_type)?tutor()->course_post_type:'courses') . "' AND p.post_status NOT IN ('trash','auto-draft') AND rm.meta_id IS NULL"); ?>
            <p><?php echo esc_html(sprintf(__('Legacy temporary private-session courses currently present: %d', 'tutor-learning-suite'), $tmp_count)); ?> &nbsp; <button type="button" class="button" id="tlms-ps-run-cleanup"><?php esc_html_e('Clean legacy courses now', 'tutor-learning-suite'); ?></button></p>
            <h2><?php esc_html_e('Recent bookings', 'tutor-learning-suite'); ?></h2>
            <table class="widefat striped"><thead><tr><th>ID</th><th><?php esc_html_e('Tutor', 'tutor-learning-suite'); ?></th><th><?php esc_html_e('Student', 'tutor-learning-suite'); ?></th><th><?php esc_html_e('Date', 'tutor-learning-suite'); ?></th><th><?php esc_html_e('Time', 'tutor-learning-suite'); ?></th><th><?php esc_html_e('Type', 'tutor-learning-suite'); ?></th><th><?php esc_html_e('Price', 'tutor-learning-suite'); ?></th><th><?php esc_html_e('Status', 'tutor-learning-suite'); ?></th><th><?php esc_html_e('Actions', 'tutor-learning-suite'); ?></th></tr></thead><tbody>
            <?php foreach ($rows as $r): $tu=get_userdata($r->tutor_id); $st=get_userdata($r->student_id); ?>
                <tr><td>#<?php echo (int)$r->id; ?></td><td><?php echo esc_html($tu ? $tu->display_name : ''); ?></td><td><?php echo esc_html($st ? $st->display_name : ''); ?></td><td><?php echo esc_html($r->session_date ?: '—'); ?></td><td><?php echo esc_html($r->start_time ? substr($r->start_time,0,5).'–'.substr($r->end_time,0,5) : '—'); ?></td><td><?php echo esc_html($r->tutoring_type); ?></td><td><?php echo esc_html(number_format((float)$r->price,2).' '.$r->currency); ?></td><td><?php echo esc_html($r->status); ?></td><td><?php if($r->status==='pending_tutor' && $r->requested_hours!==null): ?><span class="tlms-ps-muted"><?php esc_html_e('Waiting for tutor proposal','tutor-learning-suite');?></span><?php elseif(in_array($r->status,['pending_tutor','pending_admin'],true)): ?><button type="button" class="button button-primary tlms-ps-admin-action" data-session-id="<?php echo (int)$r->id;?>" data-status="approved"><?php esc_html_e('Approve','tutor-learning-suite');?></button> <button type="button" class="button tlms-ps-admin-action" data-session-id="<?php echo (int)$r->id;?>" data-status="rejected"><?php esc_html_e('Reject','tutor-learning-suite');?></button><?php elseif($r->status==='approved'): ?><span class="tlms-ps-muted"><?php esc_html_e('Waiting for student payment','tutor-learning-suite');?></span><?php else: ?>&mdash;<?php endif;?></td></tr>
            <?php endforeach; ?></tbody></table>
        </div><script>(function(){if(window.TLMSPSAdminBound)return;window.TLMSPSAdminBound=true;document.addEventListener('click',function(e){var b=e.target.closest('.tlms-ps-admin-action');if(b){var st=b.dataset.status;if(st==='rejected'&&!confirm('Reject this booking?'))return;var fd=new FormData();fd.append('action','tlms_ps_review_session');fd.append('nonce','<?php echo esc_js(wp_create_nonce(self::NONCE));?>');fd.append('session_id',b.dataset.sessionId);fd.append('status',st);fetch('<?php echo esc_url_raw(admin_url('admin-ajax.php'));?>',{method:'POST',credentials:'same-origin',body:fd}).then(function(x){return x.json();}).then(function(r){alert(r.data&&r.data.message?r.data.message:(r.success?'Updated.':'Update failed.'));if(r.success)location.reload();});return;}var c=e.target.closest('#tlms-ps-run-cleanup');if(c){if(!confirm('<?php echo esc_js(__('Run private-session expiry and temporary-course cleanup now?','tutor-learning-suite'));?>'))return;c.disabled=true;var f=new FormData();f.append('action','tlms_ps_run_maintenance');f.append('nonce','<?php echo esc_js(wp_create_nonce(self::NONCE));?>');fetch('<?php echo esc_url_raw(admin_url('admin-ajax.php'));?>',{method:'POST',credentials:'same-origin',body:f}).then(function(x){return x.json();}).then(function(r){alert(r.data&&r.data.message?r.data.message:(r.success?'Cleanup completed.':'Cleanup failed.'));if(r.success)location.reload();else c.disabled=false;}).catch(function(){c.disabled=false;});}});})();</script>
        <?php
    }

    public function save_settings() {
        if (!current_user_can('manage_options') || !check_admin_referer('tlms_ps_save_settings', '_tlms_ps_settings_nonce')) wp_die('Permission denied.');
        $s = [
            'enabled' => isset($_POST['enabled']) ? 1 : 0,
            'require_payment' => isset($_POST['require_payment']) ? 1 : 0,
            'require_tutor_approval' => isset($_POST['require_tutor_approval']) ? 1 : 0,
            'require_admin_approval' => isset($_POST['require_admin_approval']) ? 1 : 0,
            'currency' => strtoupper(sanitize_text_field(wp_unslash($_POST['currency'] ?? 'DZD'))),
            'slot_step' => max(5, absint($_POST['slot_step'] ?? 30)),
            'min_notice_hours' => max(0, absint($_POST['min_notice_hours'] ?? 2)),
            'cancel_hours' => max(0, absint($_POST['cancel_hours'] ?? 24)),
            'default_duration' => max(15, absint($_POST['default_duration'] ?? 60)),
            'default_price' => max(0, (float) ($_POST['default_price'] ?? 0)),
            'allow_private_ratings' => isset($_POST['allow_private_ratings']) ? 1 : 0,
            'tutor_response_days' => min(365, max(1, absint($_POST['tutor_response_days'] ?? 3))),
            'proposal_response_days' => min(365, max(1, absint($_POST['proposal_response_days'] ?? 3))),
            'payment_timeout_days' => min(365, max(1, absint($_POST['payment_timeout_days'] ?? 2))),
            'temp_course_cleanup_days' => min(3650, max(1, absint($_POST['temp_course_cleanup_days'] ?? 7))),
            // 0 disables the reminder emails entirely.
            'reminder_hours_before' => min(168, max(0, absint($_POST['reminder_hours_before'] ?? 24))),
        ];
        update_option(self::OPTION_SETTINGS, $s);
        wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=tlms-private-sessions')); exit;
    }

    private function table($name) { return $this->wpdb->prefix . $name; }

    private function ensure_schema() {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $this->wpdb->get_charset_collate();
        $sessions = $this->table(self::TABLE_SESSIONS);
        $avail = $this->table(self::TABLE_AVAIL);
        $sql1 = "CREATE TABLE {$sessions} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            tutor_id bigint(20) unsigned NOT NULL,
            student_id bigint(20) unsigned NULL,
            session_date date NULL,
            start_time time NULL,
            end_time time NULL,
            session_timezone varchar(64) NULL,
            tutoring_type varchar(30) NOT NULL DEFAULT 'online',
            session_type varchar(20) NOT NULL DEFAULT 'one_to_one',
            duration_minutes smallint(5) unsigned NOT NULL DEFAULT 60,
            subject varchar(190) NOT NULL DEFAULT '',
            teach_category_id bigint(20) unsigned NULL,
            student_name varchar(190) NULL,
            student_email varchar(190) NULL,
            student_phone varchar(60) NULL,
            requested_hours decimal(5,2) NULL,
            preferred_time varchar(190) NULL,
            requested_session_date date NULL,
            requested_start_time time NULL,
            requested_end_time time NULL,
            requested_timezone varchar(64) NULL,
            requested_duration_minutes smallint(5) unsigned NULL,
            proposal_message text NULL,
            proposal_sent_at datetime NULL,
            student_response text NULL,
            final_access_sent_at datetime NULL,
            reminder_sent_at datetime NULL,
            resubmitted_from_id bigint(20) unsigned NULL,
            notes text NULL,
            goal text NULL,
            is_tryout tinyint(1) unsigned NOT NULL DEFAULT 0,
            terms_accepted tinyint(1) unsigned NOT NULL DEFAULT 0,
            cancellation_accepted tinyint(1) unsigned NOT NULL DEFAULT 0,
            price decimal(18,2) NOT NULL DEFAULT 0,
            currency varchar(12) NOT NULL DEFAULT 'USD',
            status varchar(30) NOT NULL DEFAULT 'pending',
            meeting_url text NULL,
            meeting_details text NULL,
            order_id bigint(20) unsigned NULL,
            native_course_id bigint(20) unsigned NULL,
            request_token varchar(64) NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY tutor_date (tutor_id, session_date),
            KEY student_date (student_id, session_date),
            KEY status_date (status, session_date),
            UNIQUE KEY request_token (request_token)
        ) {$charset};";
        $sql2 = "CREATE TABLE {$avail} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            tutor_id bigint(20) unsigned NOT NULL,
            weekday tinyint(1) unsigned NOT NULL,
            period varchar(12) NOT NULL DEFAULT 'morning',
            start_time time NOT NULL,
            end_time time NOT NULL,
            enabled tinyint(1) unsigned NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            KEY tutor_weekday (tutor_id, weekday)
        ) {$charset};";
        dbDelta($sql1);
        dbDelta($sql2);
    }

    private function ensure_request_columns() {
        $sessions = $this->table(self::TABLE_SESSIONS);
        $cols = $this->wpdb->get_col("SHOW COLUMNS FROM {$sessions}");
        if (!$cols) return;
        $adds = [
            'learner_user_id'=>"ADD learner_user_id bigint(20) unsigned NULL AFTER student_id",
            'teach_category_id'=>"ADD teach_category_id bigint(20) unsigned NULL AFTER subject",
            'student_name'=>"ADD student_name varchar(190) NULL AFTER teach_category_id",
            'student_email'=>"ADD student_email varchar(190) NULL AFTER student_name",
            'student_phone'=>"ADD student_phone varchar(60) NULL AFTER student_email",
            'requested_hours'=>"ADD requested_hours decimal(5,2) NULL AFTER student_phone",
            'preferred_time'=>"ADD preferred_time varchar(190) NULL AFTER requested_hours",
            'requested_session_date'=>"ADD requested_session_date date NULL AFTER preferred_time",
            'requested_start_time'=>"ADD requested_start_time time NULL AFTER requested_session_date",
            'requested_end_time'=>"ADD requested_end_time time NULL AFTER requested_start_time",
            'requested_timezone'=>"ADD requested_timezone varchar(64) NULL AFTER requested_end_time",
            'requested_duration_minutes'=>"ADD requested_duration_minutes smallint(5) unsigned NULL AFTER requested_timezone",
            'terms_accepted_at'=>"ADD terms_accepted_at datetime NULL AFTER cancellation_accepted",
            'cancellation_accepted_at'=>"ADD cancellation_accepted_at datetime NULL AFTER terms_accepted_at",
            'terms_policy_snapshot'=>"ADD terms_policy_snapshot longtext NULL AFTER cancellation_accepted_at",
            'cancellation_policy_snapshot'=>"ADD cancellation_policy_snapshot longtext NULL AFTER terms_policy_snapshot",
            'terms_policy_hash'=>"ADD terms_policy_hash char(64) NULL AFTER cancellation_policy_snapshot",
            'cancellation_policy_hash'=>"ADD cancellation_policy_hash char(64) NULL AFTER terms_policy_hash",
            'proposal_message'=>"ADD proposal_message text NULL AFTER requested_duration_minutes",
            'session_timezone'=>"ADD session_timezone varchar(64) NULL AFTER end_time",
            'proposal_sent_at'=>"ADD proposal_sent_at datetime NULL AFTER proposal_message",
            'tutor_responded_at'=>"ADD tutor_responded_at datetime NULL AFTER proposal_sent_at",
            'payment_offer_sent_at'=>"ADD payment_offer_sent_at datetime NULL AFTER proposal_sent_at",
            'payment_method_id'=>"ADD payment_method_id varchar(100) NULL AFTER payment_offer_sent_at",
            'payment_reference'=>"ADD payment_reference varchar(190) NULL AFTER payment_method_id",
            'payment_declared_at'=>"ADD payment_declared_at datetime NULL AFTER payment_reference",
            'payment_confirmed_at'=>"ADD payment_confirmed_at datetime NULL AFTER payment_declared_at",
            'payment_confirmed_by'=>"ADD payment_confirmed_by bigint(20) unsigned NULL AFTER payment_confirmed_at",
            'student_response'=>"ADD student_response text NULL AFTER proposal_sent_at",
            'final_access_sent_at'=>"ADD final_access_sent_at datetime NULL AFTER meeting_details",
            'resubmitted_from_id'=>"ADD resubmitted_from_id bigint(20) unsigned NULL AFTER final_access_sent_at",
            'notes'=>"ADD notes text NULL AFTER resubmitted_from_id",
            'goal'=>"ADD goal text NULL AFTER notes",
            'reminder_sent_at'=>"ADD reminder_sent_at datetime NULL AFTER final_access_sent_at",
        ];
        foreach ($adds as $col=>$sql) {
            if (!in_array($col, $cols, true)) {
                $this->wpdb->query("ALTER TABLE {$sessions} {$sql}");
                $cols[] = $col;
            }
        }
        foreach (['session_date','start_time','end_time'] as $col) {
            if (in_array($col, $cols, true)) {
                $type = $col === 'session_date' ? 'date NULL' : 'time NULL';
                $this->wpdb->query("ALTER TABLE {$sessions} MODIFY {$col} {$type}");
            }
        }
    }

    private function maybe_upgrade() {
        $v=(int)get_option('tlms_ps_db_version',0);
        $avail=$this->table(self::TABLE_AVAIL);
        $sessions=$this->table(self::TABLE_SESSIONS);
        if($v<2){
            $cols=$this->wpdb->get_col("SHOW COLUMNS FROM {$avail}");
            if($cols && !in_array('period',$cols,true)) $this->wpdb->query("ALTER TABLE {$avail} ADD period varchar(12) NOT NULL DEFAULT 'morning' AFTER weekday");
            $rows=$this->wpdb->get_results("SELECT * FROM {$avail}");
            foreach($rows as $r){
                if(!isset($r->period)) continue;
                $exists=$this->wpdb->get_var($this->wpdb->prepare("SELECT id FROM {$avail} WHERE tutor_id=%d AND weekday=%d AND period='afternoon' LIMIT 1",(int)$r->tutor_id,(int)$r->weekday));
                if(!$exists){
                    $on=(int)$r->enabled && (int)$r->weekday>=1 && (int)$r->weekday<=5;
                    $this->wpdb->insert($avail,['tutor_id'=>(int)$r->tutor_id,'weekday'=>(int)$r->weekday,'period'=>'afternoon','start_time'=>'14:00:00','end_time'=>'17:00:00','enabled'=>$on],['%d','%d','%s','%s','%s','%d']);
                }
            }
        }
        if($v<3){
            $cols=$this->wpdb->get_col("SHOW COLUMNS FROM {$sessions}");
            if($cols && !in_array('native_course_id',$cols,true)) $this->wpdb->query("ALTER TABLE {$sessions} ADD native_course_id bigint(20) unsigned NULL AFTER order_id");
        }
        if($v<5){
            $cols=$this->wpdb->get_col("SHOW COLUMNS FROM {$sessions}");
            if($cols && !in_array('meeting_details',$cols,true)) $this->wpdb->query("ALTER TABLE {$sessions} ADD meeting_details text NULL AFTER meeting_url");
        }
        if($v<6){
            $cols=$this->wpdb->get_col("SHOW COLUMNS FROM {$sessions}");
            $adds=[
                'teach_category_id'=>"ADD teach_category_id bigint(20) unsigned NULL AFTER subject",
                'is_tryout'=>"ADD is_tryout tinyint(1) unsigned NOT NULL DEFAULT 0 AFTER notes",
                'terms_accepted'=>"ADD terms_accepted tinyint(1) unsigned NOT NULL DEFAULT 0 AFTER is_tryout",
                'cancellation_accepted'=>"ADD cancellation_accepted tinyint(1) unsigned NOT NULL DEFAULT 0 AFTER terms_accepted",
                'goal'=>"ADD goal text NULL AFTER notes",
            ];
            foreach($adds as $col=>$sql){ if($cols && !in_array($col,$cols,true)) $this->wpdb->query("ALTER TABLE {$sessions} {$sql}"); }
        }
        if($v<8){
            $cols=$this->wpdb->get_results("SHOW COLUMNS FROM {$sessions}");
            $names=[]; foreach((array)$cols as $c) $names[]=$c->Field;
            // The request phase does not have a final date/time yet.
            if(in_array('session_date',$names,true)) $this->wpdb->query("ALTER TABLE {$sessions} MODIFY session_date date NULL");
            if(in_array('start_time',$names,true)) $this->wpdb->query("ALTER TABLE {$sessions} MODIFY start_time time NULL");
            if(in_array('end_time',$names,true)) $this->wpdb->query("ALTER TABLE {$sessions} MODIFY end_time time NULL");
            if(in_array('status',$names,true)) $this->wpdb->query("ALTER TABLE {$sessions} MODIFY status varchar(30) NOT NULL DEFAULT 'pending'");
            $adds=[
                'student_name'=>"ADD student_name varchar(190) NULL AFTER teach_category_id",
                'student_email'=>"ADD student_email varchar(190) NULL AFTER student_name",
                'student_phone'=>"ADD student_phone varchar(60) NULL AFTER student_email",
                'requested_hours'=>"ADD requested_hours decimal(5,2) NULL AFTER student_phone",
                'preferred_time'=>"ADD preferred_time varchar(190) NULL AFTER requested_hours",
                'proposal_message'=>"ADD proposal_message text NULL AFTER preferred_time",
            'session_timezone'=>"ADD session_timezone varchar(64) NULL AFTER end_time",
                'proposal_sent_at'=>"ADD proposal_sent_at datetime NULL AFTER proposal_message",
                'student_response'=>"ADD student_response text NULL AFTER proposal_sent_at",
                'final_access_sent_at'=>"ADD final_access_sent_at datetime NULL AFTER meeting_details",
                'resubmitted_from_id'=>"ADD resubmitted_from_id bigint(20) unsigned NULL AFTER final_access_sent_at",
            ];
            foreach($adds as $col=>$sql){ if(!in_array($col,$names,true)) $this->wpdb->query("ALTER TABLE {$sessions} {$sql}"); }
        }
        if($v<10){
            $cols=$this->wpdb->get_col("SHOW COLUMNS FROM {$sessions}");
            if($cols && !in_array('session_type',$cols,true)) $this->wpdb->query("ALTER TABLE {$sessions} ADD session_type varchar(20) NOT NULL DEFAULT 'one_to_one' AFTER tutoring_type");
        }
        if($v<11){
            $cols=$this->wpdb->get_col("SHOW COLUMNS FROM {$sessions}");
            if($cols && !in_array('request_token',$cols,true)) $this->wpdb->query("ALTER TABLE {$sessions} ADD request_token varchar(64) NULL AFTER native_course_id");
            $indexes=$this->wpdb->get_results("SHOW INDEX FROM {$sessions}");
            $has_unique=false;
            foreach((array)$indexes as $idx){ if(($idx->Key_name??'')==='request_token' && (int)($idx->Non_unique??1)===0){ $has_unique=true; break; } }
            if(!$has_unique) $this->wpdb->query("ALTER TABLE {$sessions} ADD UNIQUE KEY request_token (request_token)");
        }
        if($v<17){
            $cols=$this->wpdb->get_col("SHOW COLUMNS FROM {$sessions}");
            if($cols && !in_array('payment_offer_sent_at',$cols,true)) $this->wpdb->query("ALTER TABLE {$sessions} ADD payment_offer_sent_at datetime NULL AFTER proposal_sent_at");
        }
        if($v<18){
            $s=$this->settings();
            $s['allow_private_ratings']=isset($s['allow_private_ratings'])?(int)$s['allow_private_ratings']:1;
            $s['tutor_response_days']=isset($s['tutor_response_days'])?min(365,max(1,(int)$s['tutor_response_days'])):3;
            $s['proposal_response_days']=isset($s['proposal_response_days'])?min(365,max(1,(int)$s['proposal_response_days'])):3;
            $s['payment_timeout_days']=isset($s['payment_timeout_days'])?min(365,max(1,(int)$s['payment_timeout_days'])):2;
            $s['temp_course_cleanup_days']=isset($s['temp_course_cleanup_days'])?min(3650,max(1,(int)$s['temp_course_cleanup_days'])):7;
            update_option(self::OPTION_SETTINGS,$s);
        }
        if($v<19){
            $cols=$this->wpdb->get_col("SHOW COLUMNS FROM {$sessions}");
            if($cols && !in_array('session_timezone',$cols,true)) $this->wpdb->query("ALTER TABLE {$sessions} ADD session_timezone varchar(64) NULL AFTER end_time");
            // Existing rows were created using the site timezone; preserve that interpretation.
            $fallback = 'Africa/Algiers';
            if($cols && in_array('session_timezone',$cols,true)) {
                $this->wpdb->query($this->wpdb->prepare("UPDATE {$sessions} SET session_timezone=%s WHERE (session_timezone IS NULL OR session_timezone='') AND session_date IS NOT NULL AND start_time IS NOT NULL", $fallback));
            }
        }
        if($v<16){
            // Tutor LMS native instructor ratings count approved reviews only when
            // comment_approved is the literal string 'approved'. Older versions of
            // this addon stored WordPress-style 0/1 values, which made private
            // session reviews visible in our report but invisible to Tutor's native
            // instructor rating calculation. Normalize private reviews now.
            $this->wpdb->query("UPDATE {$this->wpdb->comments} c INNER JOIN {$this->wpdb->postmeta} pm ON pm.post_id=c.comment_post_ID AND pm.meta_key='_tlms_ps_rating_course' SET c.comment_approved='approved' WHERE c.comment_type='tutor_course_rating' AND c.comment_approved IN ('1','approved')");
            $this->wpdb->query("UPDATE {$this->wpdb->comments} c INNER JOIN {$this->wpdb->postmeta} pm ON pm.post_id=c.comment_post_ID AND pm.meta_key='_tlms_ps_rating_course' SET c.comment_approved='0' WHERE c.comment_type='tutor_course_rating' AND c.comment_approved IN ('pending','hold')");
        }
        if($v<21){
            $cols=$this->wpdb->get_col("SHOW COLUMNS FROM {$sessions}");
            if($cols && !in_array('tutor_responded_at',$cols,true)) $this->wpdb->query("ALTER TABLE {$sessions} ADD tutor_responded_at datetime NULL AFTER proposal_sent_at");
        }
        if($v<22){
            $cols=$this->wpdb->get_col("SHOW COLUMNS FROM {$sessions}");
            if($cols && !in_array('reminder_sent_at',$cols,true)) $this->wpdb->query("ALTER TABLE {$sessions} ADD reminder_sent_at datetime NULL AFTER final_access_sent_at");
        }
        update_option('tlms_ps_db_version',TLMS_PS_DB_VERSION);
    }
    private function duration_options(){ return [30,60,90,120]; }

    private function next_bookable_date($tutor_id, $type='online', $duration=60) {
        $duration_cfg = $this->duration_config($tutor_id);
        if (!isset($duration_cfg[$type]) || !in_array($duration, $duration_cfg[$type], true)) {
            $duration = !empty($duration_cfg[$type]) ? (int)$duration_cfg[$type][0] : 60;
        }
        $tz = new DateTimeZone($this->user_timezone_string($tutor_id));
        $today = new DateTimeImmutable('today', $tz);
        $now_ts = time();
        $this->seed_availability($tutor_id);
        $settings = $this->settings();
        $step = max(5, (int)$settings['slot_step']);
        $notice = max(0, (int)$settings['min_notice_hours']) * 3600;
        for ($i = 0; $i < 60; $i++) {
            $day = $today->modify('+' . $i . ' day');
            $weekday = (int)$day->format('w');
            $windows = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT start_time,end_time FROM {$this->table(self::TABLE_AVAIL)} WHERE tutor_id=%d AND weekday=%d AND enabled=1 ORDER BY start_time",
                $tutor_id, $weekday
            ));
            foreach ($windows as $w) {
                $start = new DateTimeImmutable($day->format('Y-m-d').' '.substr($w->start_time,0,5).':00', $tz);
                $end = new DateTimeImmutable($day->format('Y-m-d').' '.substr($w->end_time,0,5).':00', $tz);
                for ($slot = $start; $slot < $end; $slot = $slot->modify('+' . $step . ' minutes')) {
                    $slot_end = $slot->modify('+' . $duration . ' minutes');
                    if ($slot_end > $end) continue;
                    if ($slot->getTimestamp() < ($now_ts + $notice)) continue;
                    return $day->format('Y-m-d');
                }
            }
        }
        return $today->format('Y-m-d');
    }
    private function duration_config($tutor_id){
        $defaults=['online'=>[30,60,90,120],'tutor_home'=>[60,90,120],'student_home'=>[60,90,120]];
        $saved=(array)get_user_meta($tutor_id,'_tlms_ps_durations',true);
        foreach($defaults as $k=>$vals){
            if(array_key_exists($k,$saved)){
                $picked=array_values(array_intersect(array_map('absint',(array)$saved[$k]),$this->duration_options()));
                $defaults[$k]=$picked?:$vals;
            }
        }
        $private_settings=$this->tutor_private_settings($tutor_id);
        if(!empty($private_settings['offer_tryout'])){
            $tryout_duration=absint($private_settings['tryout_duration']??30);
            if(in_array($tryout_duration,[15,30,45,60,90,120],true)){
                foreach($defaults as $key=>$values){ if(!in_array($tryout_duration,$values,true)) $defaults[$key][]=$tryout_duration; }
            }
        }
        return $defaults;
    }
    private function pricing_config($tutor_id){
        $prices=(array)get_user_meta($tutor_id,'_tlms_ps_prices',true);
        foreach($prices as $key=>$val){ if(!preg_match('/^(online|tutor_home|student_home)_(30|60|90|120)$/',$key)) unset($prices[$key]); }
        $subject_prices=(array)get_user_meta($tutor_id,self::META_SUBJECT_PRICES,true);
        $clean_subject_prices=[];
        foreach($subject_prices as $term_id=>$val){
            $term=get_term(absint($term_id));
            if($term && !is_wp_error($term) && is_numeric($val) && (float)$val>=0) $clean_subject_prices[(int)$term_id]=max(0,(float)$val);
        }
        $factor_saved=(array)get_user_meta($tutor_id,self::META_DURATION_FACTORS,true);
        $factors=[30=>0.5,45=>0.75,60=>1,90=>1.4,120=>1.7];
        foreach($factors as $mins=>$fallback){
            if(isset($factor_saved[$mins]) && is_numeric($factor_saved[$mins]) && (float)$factor_saved[$mins]>0) $factors[$mins]=(float)$factor_saved[$mins];
        }
        return [$this->duration_config($tutor_id),$prices,$clean_subject_prices,$factors];
    }

    private function subject_choices_for_tutor($tutor_id){
        // A subject is available to students only when the tutor explicitly added it.
        // Only subjects explicitly selected in Private Sessions settings are available to students.
        $saved=(array)get_user_meta($tutor_id,self::META_SUBJECT_PRICES,true);
        $ids=array_values(array_filter(array_map('absint',array_keys($saved))));
        $out=[];
        foreach($ids as $id){
            if(!$id) continue;
            $term=get_term($id,'course-category');
            if($term && !is_wp_error($term)){
                $out[(int)$term->term_id]=[
                    'name'=>$term->name,
                    'price'=>isset($saved[$id])?(float)$saved[$id]:0
                ];
            }
        }
        return $out;
    }

    private function all_course_subject_terms(){
        $terms=get_terms(['taxonomy'=>'course-category','hide_empty'=>false,'orderby'=>'name','order'=>'ASC']);
        return is_wp_error($terms)?[]:$terms;
    }

    private function get_profile_phone($user_id){
        $user_id=absint($user_id);
        if(!$user_id) return '';
        $keys=['phone_number','_phone_number','phone_no','_phone_no','phone','_phone','billing_phone','tutor_phone','_tutor_phone'];
        foreach($keys as $key){
            $value=get_user_meta($user_id,$key,true);
            if(is_string($value) && trim($value)!=='') return sanitize_text_field($value);
        }
        return '';
    }

    private function tutor_private_settings($tutor_id){
        $defaults=[
            'offer_tryout'=>0,
            'tryout_limit_one_per_student'=>1,
            'tryout_duration'=>30,
            'tryout_price'=>0,
            'hourly_rate'=>0,
            'terms'=>'',
            'cancellation_policy'=>'',
        ];
        $saved=(array)get_user_meta($tutor_id,'_tlms_ps_private_settings',true);
        return wp_parse_args($saved,$defaults);
    }

    private function tutor_accepts_private_sessions($tutor_id){
        return 1 === (int)get_user_meta($tutor_id,'_tlms_ipe_accept_private_sessions',true);
    }

    private function tutor_teach_choices($tutor_id){
        $ids=class_exists('Tlms_Ipe_Plugin') ? (array)get_user_meta($tutor_id,'_tlms_ipe_teach_categories',true) : [];
        $out=[];
        foreach($ids as $id){ $term=get_term(absint($id)); if($term && !is_wp_error($term)) $out[(int)$term->term_id]=$term->name; }
        return $out;
    }

    private function tutor_public_profile_url($user){
        if(!$user instanceof WP_User) return '#';
        return home_url('/profile/'.rawurlencode($user->user_nicename).'/?view=instructor');
    }

    /** Public profile URL for either a tutor or learner. Tutors use the instructor view;
     * learners use the site's standard public profile route without forcing instructor mode. */
    private function public_profile_url($user){
        if(!$user instanceof WP_User) return '#';
        $base=home_url('/profile/'.rawurlencode($user->user_nicename).'/');
        if($this->is_instructor((int)$user->ID)) $base=add_query_arg('view','instructor',$base);
        return $base;
    }

    private function public_profile_link($user,$fallback=''){
        if(!$user instanceof WP_User) return esc_html($fallback);
        $label=$user->display_name ?: $fallback;
        return '<a class="tlms-ps-profile-link" href="'.esc_url($this->public_profile_url($user)).'">'.esc_html($label).'</a>';
    }

    private function get_availability($tutor_id,$enabled_only=false) {
        $sql="SELECT * FROM {$this->table(self::TABLE_AVAIL)} WHERE tutor_id=%d";
        if($enabled_only)$sql.=" AND enabled=1";
        $sql.=" ORDER BY weekday,FIELD(period,'morning','afternoon'),start_time";
        return $this->wpdb->get_results($this->wpdb->prepare($sql,$tutor_id));
    }

    private function seed_availability($tutor_id) {
        $this->maybe_upgrade();
        $table = $this->table(self::TABLE_AVAIL);
        $existing = $this->wpdb->get_results($this->wpdb->prepare("SELECT weekday,period FROM {$table} WHERE tutor_id=%d", $tutor_id));
        $present = [];
        foreach ((array)$existing as $row) {
            $present[(int)$row->weekday . ':' . (string)$row->period] = true;
        }
        for ($d=0; $d<7; $d++) {
            foreach ([
                'morning' => ['09:00:00','12:00:00'],
                'afternoon' => ['14:00:00','17:00:00'],
            ] as $period=>$times) {
                $key = $d . ':' . $period;
                if (isset($present[$key])) { continue; }
                // Availability is tutor opt-in: suggested times are prefilled but never published until enabled.
                $this->wpdb->insert($table, ['tutor_id'=>$tutor_id,'weekday'=>$d,'period'=>$period,'start_time'=>$times[0],'end_time'=>$times[1],'enabled'=>0], ['%d','%d','%s','%s','%s','%d']);
            }
        }
    }

    private function valid_request_type($type){
        return in_array($type,['online','tutor_home','student_home'],true);
    }

    private function duration_price_for_subject($tutor_id,$term_id,$minutes){
        $hourly=(float)($this->tutor_private_settings($tutor_id)['hourly_rate']??0);
        if($hourly>0 && $minutes>0) return round($hourly*$minutes/60,2);
        $prices=(array)get_user_meta($tutor_id,self::META_SUBJECT_PRICES,true);
        $base=(float)($prices[$term_id]??0);
        if($base<=0) return 0;
        $saved=(array)get_user_meta($tutor_id,self::META_DURATION_FACTORS,true);
        $defaults=[30=>0.5,45=>0.75,60=>1,90=>1.4,120=>1.7];
        if(isset($saved[$minutes]) && is_numeric($saved[$minutes]) && (float)$saved[$minutes]>0){
            $factor=(float)$saved[$minutes];
        }elseif(isset($defaults[$minutes])){
            $factor=$defaults[$minutes];
        }else{
            $factor=$minutes/60;
        }
        return round($base*$factor,2);
    }

    private function acquire_proposal_slot_lock($tutor_id,$date){
        $name='tls_ps_'.substr(hash('sha256',(int)$tutor_id.'|'.sanitize_text_field($date)),0,48);
        $acquired=(int)$this->wpdb->get_var($this->wpdb->prepare('SELECT GET_LOCK(%s, 4)',$name));
        if(!$acquired) return false;
        register_shutdown_function(function() use ($name){ global $wpdb; $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$name)); });
        return true;
    }

    private $slot_error='';
    private function proposal_slot_available($tutor_id,$date,$start,$duration,$type,$exclude_id=0){
        $this->slot_error='';
        $date=sanitize_text_field($date); $start=sanitize_text_field($start); $duration=absint($duration);
        if(!$this->valid_request_type($type) || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$date) || !preg_match('/^\d{2}:\d{2}$/',$start)) return false;
        if($duration<15 || $duration>1440 || $duration%15!==0) return false;
        try{
            $tz=new DateTimeZone($this->user_timezone_string($tutor_id));
            $st=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$date.' '.$start.':00',$tz);
            $date_errors=DateTimeImmutable::getLastErrors();
            if(!$st || ($date_errors && ($date_errors['warning_count'] || $date_errors['error_count'])) || $st->format('Y-m-d H:i')!==$date.' '.$start) return false;
            $en=$st->modify('+'.$duration.' minutes');
        }catch(Exception $e){ return false; }
        $end=$en->format('H:i');
        if($st->getTimestamp() < current_time('timestamp', true) + (int)$this->settings()['min_notice_hours']*3600){ $this->slot_error=sprintf(__('That time is too soon. Sessions need at least %d hours notice (or it is in the past).','tutor-learning-suite'),(int)$this->settings()['min_notice_hours']); return false; }
        $weekday=(int)$st->format('w');
        $windows=$this->wpdb->get_results($this->wpdb->prepare(
            "SELECT start_time,end_time FROM {$this->table(self::TABLE_AVAIL)} WHERE tutor_id=%d AND weekday=%d AND enabled=1",
            $tutor_id,$weekday
        ));
        $fits_window=false;
        foreach((array)$windows as $window){
            try{
                $window_start=new DateTimeImmutable($date.' '.substr((string)$window->start_time,0,8),$tz);
                $window_end=new DateTimeImmutable($date.' '.substr((string)$window->end_time,0,8),$tz);
                if($st >= $window_start && $en <= $window_end){ $fits_window=true; break; }
            }catch(Exception $e){ continue; }
        }
        if(!$fits_window){ $this->slot_error=__('That time is outside your weekly availability for this day. Adjust your availability or pick a time inside it.','tutor-learning-suite'); return false; }
        $conflict=$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT id FROM {$this->table(self::TABLE_SESSIONS)} WHERE tutor_id=%d AND id<>%d AND session_date=%s AND start_time < %s AND end_time > %s AND status IN ('pending','proposal_sent','approved','paid','confirmed','completed') LIMIT 1",
            $tutor_id,absint($exclude_id),$date,$en->format('H:i:s'),$st->format('H:i:s')
        ));
        if($conflict) $this->slot_error=sprintf(__('That time overlaps another booking (#%d). Choose a different time.','tutor-learning-suite'),(int)$conflict);
        return !$conflict;
    }

    public function ajax_create_request(){
        check_ajax_referer(self::NONCE,'nonce');
        // Existing installations may have an older sessions table even when the
        // stored plugin DB version is already current. Make sure the request
        // fields exist before attempting the insert.
        $this->ensure_request_columns();
        if(!is_user_logged_in()) wp_send_json_error(['message'=>__('Please log in before submitting a request.','tutor-learning-suite')],401);
        $student=get_current_user_id();
        $learner_id=absint($_POST['learner_user_id']??0);
        if($learner_id){
            if(!class_exists('TLS_Age_Safety')) wp_send_json_error(['message'=>__('Family booking authorization is unavailable. Please contact the site administrator.','tutor-learning-suite')],503);
            TLS_Age_Safety::sync_user($learner_id);
            $learner=get_userdata($learner_id);
            if(!$learner || !TLS_Age_Safety::can_parent_book($student,$learner_id)) wp_send_json_error(['message'=>__('This guardian relationship is not approved for private tutoring bookings. Check Family & Guardian and the student’s sharing permissions.','tutor-learning-suite')],403);
        } elseif(class_exists('TLS_Age_Safety') && TLS_Age_Safety::is_restricted_student($student)) {
            wp_send_json_error(['message'=>__('Private tutoring requests for this account must be submitted by an approved parent or legal guardian. Please ask them to book from their own account.','tutor-learning-suite')],403);
        } elseif('1'===(string)get_user_meta($student,'_tls_is_guardian',true) && !TLS_Age_Safety::is_tutor_student($student)) {
            wp_send_json_error(['message'=>__('Select the linked learner you are booking for. The guardian account cannot start a learner booking without identifying the child.','tutor-learning-suite')],403);
        }
        $tutor=absint($_POST['tutor_id']??0);
        if(!$this->is_instructor($tutor) || !$this->tutor_accepts_private_sessions($tutor)) wp_send_json_error(['message'=>__('This tutor is not accepting private-session requests.','tutor-learning-suite')],400);
        if($student===$tutor) wp_send_json_error(['message'=>__('You cannot request a session from yourself.','tutor-learning-suite')],400);
        $subject_id=absint($_POST['teach_category_id']??0);
        $choices=$this->subject_choices_for_tutor($tutor);
        if(!$subject_id || !isset($choices[$subject_id])) wp_send_json_error(['message'=>__('Please choose a subject this tutor teaches.','tutor-learning-suite')],400);
        $type=sanitize_key($_POST['type']??'online');
        $session_type=sanitize_key($_POST['session_type']??'one_to_one');
        if(!$this->valid_request_type($type) || !in_array($session_type,['one_to_one','group'],true)) wp_send_json_error(['message'=>__('Please choose a valid tutoring option.','tutor-learning-suite')],400);
        if($type==='student_home' && $session_type!=='one_to_one') wp_send_json_error(['message'=>__('At student home is available only for 1-to-1 tutoring.','tutor-learning-suite')],400);
        $options=$this->tutor_tutoring_options($tutor);
        $tp=$this->tutor_private_settings($tutor);
        $is_tryout=!empty($_POST['request_tryout']) ? 1 : 0;
        if($is_tryout && empty($tp['offer_tryout'])) wp_send_json_error(['message'=>__('This tutor is not currently accepting tryout requests.','tutor-learning-suite')],400);
        if($is_tryout && !empty($tp['tryout_limit_one_per_student'])){
            $resubmitted_from_id=absint($_POST['resubmitted_from_id']??0);
            $already=$this->wpdb->get_var($this->wpdb->prepare("SELECT id FROM {$this->table(self::TABLE_SESSIONS)} WHERE tutor_id=%d AND student_id=%d AND is_tryout=1 AND id<>%d LIMIT 1",$tutor,$student,$resubmitted_from_id));
            if($already) wp_send_json_error(['message'=>__('You have already requested a tryout from this tutor.','tutor-learning-suite')],400);
        }
        $option_key=$session_type.'_'.$type;
        if(empty($options[$option_key])) wp_send_json_error(['message'=>__('This tutor does not offer the selected tutoring option.','tutor-learning-suite')],400);
        $name=sanitize_text_field(wp_unslash($_POST['student_name']??''));
        $email=sanitize_email(wp_unslash($_POST['student_email']??''));
        $phone=sanitize_text_field(wp_unslash($_POST['student_phone']??''));
        $goal=sanitize_textarea_field(wp_unslash($_POST['goal']??''));
        $notes=sanitize_textarea_field(wp_unslash($_POST['notes']??''));
        $preferred_date=sanitize_text_field(wp_unslash($_POST['preferred_date']??''));
        $preferred_start=sanitize_text_field(wp_unslash($_POST['preferred_start']??''));
        $preferred_end=sanitize_text_field(wp_unslash($_POST['preferred_end']??''));
        $preferred_duration=absint($_POST['preferred_duration']??0);
        $preferred_timezone=sanitize_text_field(wp_unslash($_POST['preferred_timezone']??''));
        $current_tutor_timezone=$this->user_timezone_string($tutor);
        if(!$preferred_timezone || $preferred_timezone!==$current_tutor_timezone){ wp_send_json_error(['message'=>__('The tutor timezone or availability changed after the calendar loaded. Refresh the page and choose a new time.','tutor-learning-suite')],409); }
        $resubmitted_from_id=absint($_POST['resubmitted_from_id']??0);
        // A booking request always needs a fresh slot selected from the tutor calendar.
        // Do not trust a hidden availability_slot_required flag: clients can alter it.
        if($is_tryout && $preferred_duration !== (int)$tp['tryout_duration']) wp_send_json_error(['message'=>__('Choose the tutor’s configured tryout length in the calendar before requesting a tryout.','tutor-learning-suite')],400);
        $duration_cfg=$this->duration_config($tutor);
        $allowed_durations=$duration_cfg[$type]??[];
        if(!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$preferred_date) || !preg_match('/^\\d{2}:\\d{2}$/',$preferred_start) || !preg_match('/^\\d{2}:\\d{2}$/',$preferred_end) || !$preferred_duration || !in_array($preferred_duration,$allowed_durations,true) || !$this->proposal_slot_available($tutor,$preferred_date,$preferred_start,$preferred_duration,$type)){
            wp_send_json_error(['message'=>__('Please choose a current available time from the tutor’s calendar before sending the request.','tutor-learning-suite')],400);
        }
        try { $preferred_end_expected=(new DateTimeImmutable($preferred_date.' '.$preferred_start.':00',new DateTimeZone($this->user_timezone_string($tutor))))->modify('+'.$preferred_duration.' minutes')->format('H:i'); } catch(Exception $e) { $preferred_end_expected=''; }
        if($preferred_end!==$preferred_end_expected){ wp_send_json_error(['message'=>__('The selected time has changed. Please choose another available slot.','tutor-learning-suite')],400); }
        $preferred=$preferred_date.' '.$preferred_start.'–'.$preferred_end;
        $terms_accepted=!empty($_POST['terms_accepted'])?1:0;
        $cancellation_accepted=!empty($_POST['cancellation_accepted'])?1:0;
        if(!$terms_accepted || !$cancellation_accepted || trim((string)$tp['terms'])==='' || trim((string)$tp['cancellation_policy'])==='') wp_send_json_error(['message'=>__('Please read and agree to the tutor’s terms and cancellation policy before sending your request.','tutor-learning-suite')],400);
        // This module creates one session request at a time. The calendar is the
        // single source of truth for its duration; requested_hours remains only as
        // a compatibility field for older dashboard/admin workflow conditions.
        $hours=round($preferred_duration/60,2);
        if($name==='') { $learner_user=$learner_id?get_userdata($learner_id):false; $name=$learner_user?$learner_user->display_name:wp_get_current_user()->display_name; }
        if(!is_email($email)) $email=wp_get_current_user()->user_email;
        $parent=absint($_POST['resubmitted_from_id']??0);
        $request_token=sanitize_text_field(wp_unslash($_POST['request_token']??''));
        if(!preg_match('/^[a-f0-9]{32,64}$/i',$request_token)) $request_token=wp_generate_uuid4();
        if($parent){
            $old=$this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->table(self::TABLE_SESSIONS)} WHERE id=%d AND student_id=%d AND tutor_id=%d",$parent,$student,$tutor));
            if(!$old || !in_array($old->status,['declined','rejected'],true)) wp_send_json_error(['message'=>__('This request is no longer eligible for resubmission. Open your tutor profile and start a new request.','tutor-learning-suite')],409);
        }
        $now=current_time('mysql');
        $terms_policy_snapshot=sanitize_textarea_field((string)$tp['terms']);
        $cancellation_policy_snapshot=sanitize_textarea_field((string)$tp['cancellation_policy']);
        $data=[
            'tutor_id'=>$tutor,'student_id'=>$student,'learner_user_id'=>$learner_id?:null,'session_date'=>null,'start_time'=>null,'end_time'=>null,
            'tutoring_type'=>$type,'session_type'=>$session_type,'duration_minutes'=>$preferred_duration,'subject'=>$choices[$subject_id]['name'],'teach_category_id'=>$subject_id,
            'student_name'=>$name,'student_email'=>$email,'student_phone'=>$phone,'requested_hours'=>$hours,'preferred_time'=>$preferred,
            'requested_session_date'=>$preferred_date,'requested_start_time'=>$preferred_start.':00','requested_end_time'=>$preferred_end.':00','requested_timezone'=>$preferred_timezone,'requested_duration_minutes'=>$preferred_duration,
            'terms_accepted'=>$terms_accepted,'cancellation_accepted'=>$cancellation_accepted,
            'terms_accepted_at'=>$now,'cancellation_accepted_at'=>$now,
            'terms_policy_snapshot'=>$terms_policy_snapshot,
            'cancellation_policy_snapshot'=>$cancellation_policy_snapshot,
            'terms_policy_hash'=>hash('sha256',trim($terms_policy_snapshot)),
            'cancellation_policy_hash'=>hash('sha256',trim($cancellation_policy_snapshot)),
            'notes'=>$notes,'goal'=>$goal,'is_tryout'=>$is_tryout,'price'=>0,'currency'=>$this->settings()['currency'],'status'=>'pending_tutor',
            'resubmitted_from_id'=>$parent?:null,'request_token'=>$request_token,'created_at'=>$now,'updated_at'=>$now,
        ];
        $ok=$this->wpdb->insert($this->table(self::TABLE_SESSIONS),$data);
        if(!$ok){
            // A retry of the same AJAX request must not create a second booking.
            // The unique request token lets us return the already-created request
            // even when the browser never received the first JSON response.
            if(stripos((string)$this->wpdb->last_error,'duplicate')!==false){
                $existing_id=(int)$this->wpdb->get_var($this->wpdb->prepare("SELECT id FROM {$this->table(self::TABLE_SESSIONS)} WHERE request_token=%s LIMIT 1",$request_token));
                if($existing_id){
                    wp_send_json_success(['message'=>__('Your request was already sent. The tutor will respond with a proposal and invoice details.','tutor-learning-suite'),'redirect'=>$this->dashboard_url('private-sessions'),'session_id'=>$existing_id,'already_sent'=>true]);
                }
            }
            $db_error = $this->wpdb->last_error;
            error_log('[TLMS Private Sessions] create request failed: '.$db_error);
            wp_send_json_error(['message'=>__('Could not create the request. Please try again.','tutor-learning-suite')],500);
        }
        $id=(int)$this->wpdb->insert_id;
        // Do not let review/notification integrations run inside the request that
        // creates the booking. A slow or broken listener can otherwise make a
        // successful DB insert look like a failed submission to the student.
        wp_schedule_single_event(time()+1,self::REQUEST_CREATED_HOOK,[$id,$tutor,$student]);
        wp_send_json_success(['message'=>__('Your request was sent to the tutor. They will respond with a proposal and invoice details.','tutor-learning-suite'),'redirect'=>$this->dashboard_url('private-sessions'),'session_id'=>$id]);
    }

    public function process_request_created($id,$tutor,$student){
        $id=absint($id); $tutor=absint($tutor); $student=absint($student);
        if(!$id || !$tutor || !$student) return;
        $row=$this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->table(self::TABLE_SESSIONS)} WHERE id=%d LIMIT 1",$id));
        if(!$row) return;
        try{ do_action('tlms_private_session_request_created',$id,$tutor,$student); }catch(\Throwable $e){ error_log('[TLMS Private Sessions] request-created hook failed: '.$e->getMessage()); }
        $tu=get_userdata($tutor);
        if($tu && is_email($tu->user_email)){
            try{ wp_mail($tu->user_email,__('New private tutoring request','tutor-learning-suite'),sprintf(__('A learner sent you a private tutoring request for %s. Log in to your Tutor LMS dashboard to review it and send a proposal.','tutor-learning-suite'),$row->subject)); }catch(\Throwable $e){ error_log('[TLMS Private Sessions] request notification failed: '.$e->getMessage()); }
        }
    }

    public function ajax_send_proposal(){
        check_ajax_referer(self::NONCE,'nonce');
        if(!is_user_logged_in() || !$this->is_instructor(get_current_user_id())) wp_send_json_error(['message'=>__('Permission denied.','tutor-learning-suite')],403);
        $tutor=get_current_user_id(); $id=absint($_POST['session_id']??0);
        $row=$this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->table(self::TABLE_SESSIONS)} WHERE id=%d AND tutor_id=%d",$id,$tutor));
        if(!$row) wp_send_json_error(['message'=>__('Request not found.','tutor-learning-suite')],404);
        if($row->status==='pending_tutor' && $this->session_datetime_timestamp((string)$row->created_at) + (int)$this->settings()['tutor_response_days']*DAY_IN_SECONDS <= time()) wp_send_json_error(['message'=>__('This request has expired because the response deadline has passed.','tutor-learning-suite')],400);
        if($row->status!=='pending_tutor') wp_send_json_error(['message'=>__('This request is no longer waiting for your proposal.','tutor-learning-suite')],400);
        $date=sanitize_text_field(wp_unslash($_POST['proposal_date']??'')); $start=sanitize_text_field(wp_unslash($_POST['proposal_start']??''));
        $duration_hours=(float)($_POST['proposal_duration_hours']??1);
        $duration_quarters=round($duration_hours*4);
        if($duration_hours<=0 || $duration_hours>24 || abs($duration_hours-($duration_quarters/4))>0.00001) wp_send_json_error(['message'=>__('Please enter a valid duration in hours (15-minute increments, up to 24 hours).','tutor-learning-suite')],400);
        $duration=(int)round($duration_hours*60);
        $price=max(0,(float)($_POST['proposal_price']??0));
        $message=sanitize_textarea_field(wp_unslash($_POST['proposal_message']??''));
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)||!preg_match('/^\d{2}:\d{2}$/',$start)) wp_send_json_error(['message'=>__('Please enter a valid proposed date and start time.','tutor-learning-suite')],400);
        if($duration<15 || $duration>1440 || $duration%15!==0) wp_send_json_error(['message'=>__('Please choose a duration in 15-minute increments, up to 24 hours.','tutor-learning-suite')],400);
        $type=$row->tutoring_type;
        if(!$this->acquire_proposal_slot_lock($tutor,$date)) wp_send_json_error(['message'=>__('Another booking update is in progress for this date. Please try again.','tutor-learning-suite')],409);
        if(!$this->proposal_slot_available($tutor,$date,$start,$duration,$type,(int)$row->id)) wp_send_json_error(['message'=>$this->slot_error?:__('That proposed time is not available in your schedule or conflicts with another session.','tutor-learning-suite')],409);
        if($price<=0){
            $price=$this->duration_price_for_subject($tutor,(int)$row->teach_category_id,$duration);
            if($price<=0) wp_send_json_error(['message'=>__('Please enter a proposal price.','tutor-learning-suite')],400);
        }
        try{ $proposal_tz=new DateTimeZone($this->user_timezone_string($tutor)); $st=new DateTimeImmutable($date.' '.$start.':00',$proposal_tz); $end=$st->modify('+'.$duration.' minutes')->format('H:i:s'); }catch(Exception $e){ wp_send_json_error(['message'=>__('Invalid proposed time.','tutor-learning-suite')],400); }
        $updated=$this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->table(self::TABLE_SESSIONS)} SET session_date=%s,start_time=%s,end_time=%s,session_timezone=%s,duration_minutes=%d,price=%f,proposal_message=%s,proposal_sent_at=%s,tutor_responded_at=%s,status='proposal_sent',updated_at=%s WHERE id=%d AND tutor_id=%d AND status='pending_tutor'",
            $date,$start.':00',$end,$this->user_timezone_string($tutor),$duration,$price,$message,current_time('mysql'),current_time('mysql'),current_time('mysql'),$id,$tutor
        ));
        if($updated!==1) wp_send_json_error(['message'=>__('This request changed while the proposal was being prepared. Refresh and try again.','tutor-learning-suite')],409);
        $student=get_userdata((int)$row->student_id);
        if($student && is_email($student->user_email)) wp_mail($student->user_email,__('Your private tutoring proposal is ready','tutor-learning-suite'),__('The tutor has responded to your private tutoring request. Log in to your student dashboard to review the proposed time, details and price.','tutor-learning-suite'));
        wp_send_json_success(['message'=>__('Proposal sent to the student.','tutor-learning-suite')]);
    }

    public function ajax_decline_proposal(){
        check_ajax_referer(self::NONCE,'nonce');
        if(!is_user_logged_in()) wp_send_json_error(['message'=>__('Please log in.','tutor-learning-suite')],401);
        $student=get_current_user_id(); $id=absint($_POST['session_id']??0);
        $row=$this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->table(self::TABLE_SESSIONS)} WHERE id=%d AND student_id=%d",$id,$student));
        if(!$row || $row->status!=='proposal_sent') wp_send_json_error(['message'=>__('This proposal is no longer available.','tutor-learning-suite')],400);
        if($this->session_deadline_passed($row,'proposal')) { $this->expire_session((int)$row->id,'proposal'); wp_send_json_error(['message'=>__('This proposal has expired because the response deadline has passed.','tutor-learning-suite')],400); }
        $reason=sanitize_textarea_field(wp_unslash($_POST['reason']??''));
        $updated=$this->wpdb->query($this->wpdb->prepare("UPDATE {$this->table(self::TABLE_SESSIONS)} SET status='pending_tutor',session_date=NULL,start_time=NULL,end_time=NULL,session_timezone=NULL,duration_minutes=COALESCE(requested_duration_minutes,duration_minutes),price=0,proposal_message=NULL,proposal_sent_at=NULL,student_response=%s,updated_at=%s WHERE id=%d AND student_id=%d AND status='proposal_sent'",$reason,current_time('mysql'),$id,$student));
        if($updated!==1) wp_send_json_error(['message'=>__('This proposal changed. Refresh your dashboard and try again.','tutor-learning-suite')],409);
        $tu=get_userdata((int)$row->tutor_id);
        if($tu && is_email($tu->user_email)) wp_mail($tu->user_email,__('Student declined your private tutoring proposal','tutor-learning-suite'),__('The student declined your proposal and left a reason. The same request is now waiting for you again. Review the reason and submit a new proposal; the student does not need to send another request.','tutor-learning-suite'));
        wp_send_json_success(['message'=>__('Proposal declined. Your tutor can review your reason and send a new proposal for the same request.','tutor-learning-suite'),'redirect'=>$this->dashboard_url('private-sessions')]);
    }

    public function ajax_resend_request(){
        check_ajax_referer(self::NONCE,'nonce');
        if(!is_user_logged_in()) wp_send_json_error(['message'=>__('Please log in.','tutor-learning-suite')],401);
        $student=get_current_user_id(); $id=absint($_POST['session_id']??0);
        $row=$this->wpdb->get_row($this->wpdb->prepare("SELECT id,tutor_id,status FROM {$this->table(self::TABLE_SESSIONS)} WHERE id=%d AND student_id=%d",$id,$student));
        if(!$row || !in_array((string)$row->status,['declined','rejected'],true)) wp_send_json_error(['message'=>__('This request cannot be resubmitted.','tutor-learning-suite')],400);
        $url=add_query_arg('resubmit_id',$id,$this->booking_url((int)$row->tutor_id));
        wp_send_json_success(['message'=>__('Choose a fresh available calendar slot before resubmitting this request.','tutor-learning-suite'),'redirect'=>$url,'session_id'=>$id]);
    }

    private function get_tutor_private_stats($tutor_id){
        $tutor_id=absint($tutor_id);
        $table=$this->table(self::TABLE_SESSIONS);
        $row=$this->wpdb->get_row($this->wpdb->prepare(
            "SELECT
                COUNT(*) total_requests,
                SUM(CASE WHEN tutor_responded_at IS NOT NULL THEN 1 ELSE 0 END) responses,
                AVG(CASE WHEN tutor_responded_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, created_at, tutor_responded_at) ELSE NULL END) avg_response_seconds,
                SUM(CASE WHEN status IN ('paid','confirmed','completed') THEN 1 ELSE 0 END) confirmed_sessions,
                SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) completed_sessions,
                SUM(CASE WHEN is_tryout=1 AND status IN ('confirmed','completed','paid') THEN 1 ELSE 0 END) tryouts_approved,
                SUM(CASE WHEN status='pending_tutor' THEN 1 ELSE 0 END) pending,
                SUM(CASE WHEN status='proposal_sent' THEN 1 ELSE 0 END) proposal_sent,
                SUM(CASE WHEN status IN ('rejected','declined') THEN 1 ELSE 0 END) rejected_declined,
                SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) failed,
                COALESCE(SUM(CASE WHEN is_tryout=0 AND status IN ('paid','confirmed','completed') THEN price ELSE 0 END),0) revenue
             FROM {$table} WHERE tutor_id=%d",$tutor_id
        ));
        $total=(int)($row->total_requests??0); $responses=(int)($row->responses??0);
        $response_rate=$total?round(($responses/$total)*100):0;
        $avg=(float)($row->avg_response_seconds??0);
        if($avg<=0){ $avg_label=__('No responses yet','tutor-learning-suite'); }
        else { $mins=round($avg/60); if($mins<60) $avg_label=sprintf(_n('%d min','%d min',$mins,'tutor-learning-suite'),$mins); else { $h=intdiv($mins,60); $m=$mins%60; $avg_label=$m?sprintf(__('%dh %dmin','tutor-learning-suite'),$h,$m):sprintf(_n('%d hour','%d hours',$h,'tutor-learning-suite'),$h); } }
        $revenue=(float)($row->revenue??0);
        $currency=(string)$this->settings()['currency'];
        $upcoming=0;
        $future_rows=$this->wpdb->get_results($this->wpdb->prepare(
            "SELECT session_date,start_time,end_time,session_timezone FROM {$table} WHERE tutor_id=%d AND status IN ('paid','confirmed') AND session_date IS NOT NULL AND start_time IS NOT NULL AND end_time IS NOT NULL LIMIT 1000",
            $tutor_id
        ));
        $now_ts=time();
        foreach((array)$future_rows as $fr){
            try{ $tz=new DateTimeZone(!empty($fr->session_timezone)?(string)$fr->session_timezone:$this->user_timezone_string($tutor_id)); $end=new DateTimeImmutable($fr->session_date.' '.substr((string)$fr->end_time,0,8),$tz); if($end->getTimestamp()>$now_ts) $upcoming++; }catch(Exception $e){}
        }
        return [
            'total_requests'=>$total,'response_rate'=>$response_rate,'avg_response_label'=>$avg_label,'confirmed_sessions'=>(int)$row->confirmed_sessions,
            'completed_sessions'=>(int)$row->completed_sessions,'tryouts_approved'=>(int)$row->tryouts_approved,'upcoming_meetings'=>$upcoming,
            'revenue_label'=>number_format($revenue,2).' '.$currency,'pending'=>(int)$row->pending,'proposal_sent'=>(int)$row->proposal_sent,
            'rejected_declined'=>(int)$row->rejected_declined,'failed'=>(int)$row->failed,
        ];
    }

    public function render_instructor_dashboard($tutor_id) {
        $settings=$this->settings(); [$duration_cfg,$prices,$subject_prices,$duration_factors]=$this->pricing_config($tutor_id);
        $request_search=sanitize_text_field(wp_unslash($_GET['ps_search']??''));
        $request_status=sanitize_key($_GET['ps_status']??'');
        $request_sort=sanitize_key($_GET['ps_sort']??'newest');
        $active_tab = sanitize_key($_GET['ps_tab']??'settings'); if(!in_array($active_tab,['settings','requests','calendar','stats'],true)) $active_tab='settings';
        // The tab badge counts are cheap COUNT(*) queries needed on every load,
        // but the full row fetches below are only needed for the tab currently
        // being viewed, so they're deferred until we know which tab that is.
        $pending_count=(int)$this->wpdb->get_var($this->wpdb->prepare("SELECT COUNT(*) FROM {$this->table(self::TABLE_SESSIONS)} WHERE tutor_id=%d AND status='pending_tutor'",$tutor_id));
        $proposal_count=(int)$this->wpdb->get_var($this->wpdb->prepare("SELECT COUNT(*) FROM {$this->table(self::TABLE_SESSIONS)} WHERE tutor_id=%d AND status='proposal_sent'",$tutor_id));
        $paid_count=(int)$this->wpdb->get_var($this->wpdb->prepare("SELECT COUNT(*) FROM {$this->table(self::TABLE_SESSIONS)} WHERE tutor_id=%d AND status IN ('paid','confirmed')",$tutor_id));
        $bookings = [];
        if ($active_tab === 'requests') {
            $where="tutor_id=%d"; $params=[$tutor_id];
            if($request_search!==''){ $like='%'.$this->wpdb->esc_like($request_search).'%'; $where.=' AND (student_name LIKE %s OR student_email LIKE %s OR subject LIKE %s OR goal LIKE %s)'; array_push($params,$like,$like,$like,$like); }
            if(in_array($request_status,['pending_tutor','proposal_sent','approved','paid','confirmed','completed','declined','rejected','failed'],true)){ $where.=' AND status=%s'; $params[]=$request_status; }
            $order=$request_sort==='oldest'?'id ASC':($request_sort==='status'?"FIELD(status,'pending_tutor','proposal_sent','approved','paid','confirmed','completed','declined','rejected'), id DESC":'id DESC');
            $sql="SELECT * FROM {$this->table(self::TABLE_SESSIONS)} WHERE $where ORDER BY $order LIMIT 200";
            $bookings=$this->wpdb->get_results($this->wpdb->prepare($sql,$params));
        }
        $upcoming_meetings = [];
        if ($active_tab === 'calendar') {
            $meeting_rows = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT * FROM {$this->table(self::TABLE_SESSIONS)} WHERE tutor_id=%d AND status IN ('paid','confirmed','completed') AND session_date IS NOT NULL AND start_time IS NOT NULL AND end_time IS NOT NULL ORDER BY session_date ASC, start_time ASC LIMIT 300",
                $tutor_id
            ));
            $now_ts = time();
            $tutor_zone = new DateTimeZone($this->user_timezone_string($tutor_id));
            foreach ((array)$meeting_rows as $meeting) {
                try {
                    $meeting_tz = new DateTimeZone(!empty($meeting->session_timezone) ? (string)$meeting->session_timezone : $this->user_timezone_string($tutor_id));
                    $end_dt = new DateTimeImmutable($meeting->session_date.' '.substr((string)$meeting->end_time,0,8), $meeting_tz);
                    // Keep a meeting until its end time; once it has passed it leaves this list.
                    if ($end_dt->getTimestamp() <= $now_ts) continue;
                    $start_dt = new DateTimeImmutable($meeting->session_date.' '.substr((string)$meeting->start_time,0,8), $meeting_tz);
                    $meeting->_start_dt = $start_dt->setTimezone($tutor_zone);
                    $meeting->_end_dt = $end_dt->setTimezone($tutor_zone);
                    $upcoming_meetings[] = $meeting;
                } catch (Exception $e) {
                    continue;
                }
            }
        }
        // get_tutor_private_stats() runs a heavier aggregate query plus a
        // per-row timezone calculation loop, so it's only worth paying for
        // when the Statistics tab is actually being rendered.
        $stats = ($active_tab === 'stats') ? $this->get_tutor_private_stats($tutor_id) : [];
        ob_start(); ?>
        <div class="tlms-ps-panel tlms-ps-instructor-panel">
          <div class="tlms-ps-head"><div><h2 class="tlms-ps-page-title"><i class="tutor-icon-calendar-line" aria-hidden="true"></i><?php esc_html_e('Private Sessions','tutor-learning-suite');?></h2><p><?php esc_html_e('Set your private tutoring options and respond to student requests with a custom proposal.','tutor-learning-suite');?></p></div><a class="tutor-btn tutor-btn-primary" href="<?php echo esc_url($this->booking_url($tutor_id));?>" target="_blank" rel="noopener"><?php esc_html_e('Preview request form','tutor-learning-suite');?></a></div>
          <div class="tlms-ps-process tlms-ps-process-tutor"><div class="tlms-ps-process-title"><i class="tutor-icon-info-circle" aria-hidden="true"></i><strong><?php esc_html_e('How private sessions work','tutor-learning-suite');?></strong><span><?php esc_html_e('A simple request-to-booking process.','tutor-learning-suite');?></span></div><div class="tlms-ps-process-steps"><div><b>1</b><strong><?php esc_html_e('Set what you offer','tutor-learning-suite');?></strong><span><?php esc_html_e('Choose your subjects and the 1-to-1/group methods and locations you accept.','tutor-learning-suite');?></span></div><div><b>2</b><strong><?php esc_html_e('Receive a request','tutor-learning-suite');?></strong><span><?php esc_html_e('The learner chooses from dates and times generated from your weekly availability, then sends a request.','tutor-learning-suite');?></span></div><div><b>3</b><strong><?php esc_html_e('Send your proposal','tutor-learning-suite');?></strong><span><?php esc_html_e('Agree on the schedule and price. The learner accepts and pays you directly.','tutor-learning-suite');?></span></div></div></div>
          <div class="tlms-ps-stat-grid"><div class="tlms-ps-stat"><span><?php esc_html_e('New requests','tutor-learning-suite');?></span><strong><?php echo (int)$pending_count;?></strong></div><div class="tlms-ps-stat"><span><?php esc_html_e('Waiting for student','tutor-learning-suite');?></span><strong><?php echo (int)$proposal_count;?></strong></div><div class="tlms-ps-stat"><span><?php esc_html_e('Paid / confirmed','tutor-learning-suite');?></span><strong><?php echo (int)$paid_count;?></strong></div></div>
          <div class="tlms-ps-focus-banner <?php echo ($pending_count + $proposal_count) ? 'is-attention' : 'is-clear'; ?>">
            <span class="tlms-ps-focus-icon" aria-hidden="true"><i class="tutor-icon-inbox"></i></span>
            <div class="tlms-ps-focus-copy">
              <?php if ($pending_count + $proposal_count) : ?>
                <strong><?php printf(esc_html(_n('%d request or proposal needs your attention','%d requests or proposals need your attention',$pending_count + $proposal_count,'tutor-learning-suite')),(int)($pending_count + $proposal_count)); ?></strong>
                <span><?php esc_html_e('Review new requests and reply to proposals waiting for a student.','tutor-learning-suite'); ?></span>
              <?php else : ?>
                <strong><?php esc_html_e('You are all caught up','tutor-learning-suite'); ?></strong>
                <span><?php esc_html_e('New student requests will appear in your Requests inbox.','tutor-learning-suite'); ?></span>
              <?php endif; ?>
            </div>
            <a class="tlms-ps-focus-action" href="<?php echo esc_url(add_query_arg('ps_tab','requests',$this->dashboard_url('private-sessions'))); ?>"><?php esc_html_e('Open requests','tutor-learning-suite'); ?> <span aria-hidden="true">→</span></a>
          </div>
          <div class="tlms-ps-tabs"><a class="tlms-ps-tab <?php echo $active_tab==='settings'?'is-active':'';?>" href="<?php echo esc_url($this->dashboard_url('private-sessions'));?>"><i class="tutor-icon-settings" aria-hidden="true"></i> <?php esc_html_e('Settings','tutor-learning-suite');?></a><a class="tlms-ps-tab <?php echo $active_tab==='requests'?'is-active':'';?>" href="<?php echo esc_url(add_query_arg('ps_tab','requests',$this->dashboard_url('private-sessions')));?>"><i class="tutor-icon-inbox" aria-hidden="true"></i> <?php esc_html_e('Requests','tutor-learning-suite');?><?php if($pending_count||$proposal_count):?><span class="tlms-ps-tab-count"><?php echo (int)($pending_count+$proposal_count);?></span><?php endif;?></a><a class="tlms-ps-tab <?php echo $active_tab==='calendar'?'is-active':'';?>" href="<?php echo esc_url(add_query_arg('ps_tab','calendar',$this->dashboard_url('private-sessions')));?>"><i class="tutor-icon-calendar-line" aria-hidden="true"></i> <?php esc_html_e('Upcoming Meetings','tutor-learning-suite');?><?php if($upcoming_meetings):?><span class="tlms-ps-tab-count"><?php echo count($upcoming_meetings);?></span><?php endif;?></a><a class="tlms-ps-tab <?php echo $active_tab==='stats'?'is-active':'';?>" href="<?php echo esc_url(add_query_arg('ps_tab','stats',$this->dashboard_url('private-sessions')));?>"><i class="tutor-icon-chart-bar" aria-hidden="true"></i> <?php esc_html_e('Statistics','tutor-learning-suite');?></a></div>
          <?php if($active_tab==='calendar'): ?>
            <div class="tlms-ps-calendar-list">
              <div class="tlms-ps-section-head"><div><h3><?php esc_html_e('Upcoming meetings','tutor-learning-suite');?></h3><p class="tlms-ps-help"><?php esc_html_e('Confirmed private sessions are listed by date and time. Meetings disappear automatically after their end time.','tutor-learning-suite');?></p></div></div>
              <?php if(!$upcoming_meetings): ?>
                <div class="tlms-ps-card tlms-ps-empty-state"><div class="tlms-ps-empty-icon"><i class="tutor-icon-calendar-line" aria-hidden="true"></i></div><h3><?php esc_html_e('No upcoming private meetings','tutor-learning-suite');?></h3><p><?php esc_html_e('Confirmed private sessions will appear here automatically.','tutor-learning-suite');?></p></div>
              <?php else: foreach($upcoming_meetings as $meeting): $student=get_userdata((int)$meeting->student_id); $meeting_url=(string)$meeting->meeting_url; ?>
                <div class="tlms-ps-card tlms-ps-meeting-card">
                  <div class="tlms-ps-meeting-date"><strong><?php echo esc_html($meeting->_start_dt->format('d'));?></strong><span><?php echo esc_html($meeting->_start_dt->format('M'));?></span><small><?php echo esc_html($meeting->_start_dt->format('Y'));?></small></div>
                  <div class="tlms-ps-meeting-main">
                    <div class="tlms-ps-meeting-head"><div><h3><?php echo esc_html($meeting->subject ?: __('Private tutoring session','tutor-learning-suite'));?></h3><p><?php echo $student ? $this->public_profile_link($student,__('Student','tutor-learning-suite')) : esc_html($meeting->student_name ?: __('Student','tutor-learning-suite'));?> · <?php echo esc_html($this->session_type_label($meeting->session_type??'one_to_one'));?> · <?php echo esc_html($this->tutoring_type_label($meeting->tutoring_type));?></p></div><span class="tlms-ps-status tlms-ps-status-confirmed"><?php echo esc_html($this->status_label($meeting->status));?></span></div>
                    <div class="tlms-ps-confirmed-summary-grid">
                      <div><span><?php esc_html_e('Starting time','tutor-learning-suite');?></span><strong><?php echo esc_html($meeting->_start_dt->format('H:i').' – '.$meeting->_end_dt->format('H:i'));?></strong><small><?php echo esc_html($this->timezone_label($tutor_zone->getName(),$meeting->_start_dt->getTimestamp()));?></small></div>
                      <div><span><?php esc_html_e('Duration','tutor-learning-suite');?></span><strong><?php echo esc_html($this->format_duration_minutes((int)$meeting->duration_minutes));?></strong></div>
                      <div><span><?php esc_html_e('Price','tutor-learning-suite');?></span><strong><?php echo esc_html(number_format((float)$meeting->price,2).' '.$meeting->currency);?></strong></div>
                      <div><span><?php esc_html_e('Session format','tutor-learning-suite');?></span><strong><?php echo esc_html($this->session_type_label($meeting->session_type??'one_to_one'));?></strong></div><div><span><?php esc_html_e('Teaching method','tutor-learning-suite');?></span><strong><?php echo esc_html($this->tutoring_type_label($meeting->tutoring_type));?></strong></div>
                    </div>
                    <?php if($meeting->meeting_details):?><div class="tlms-ps-meeting-details"><span><?php esc_html_e('Details','tutor-learning-suite');?></span><p><?php echo nl2br(esc_html($meeting->meeting_details));?></p></div><?php endif;?>
                    <?php if($meeting_url):?><div class="tlms-ps-meeting-actions"><a class="tutor-btn tutor-btn-primary" href="<?php echo esc_url($meeting_url);?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Open meeting','tutor-learning-suite');?></a></div><?php endif;?>
                  </div>
                </div>
              <?php endforeach; endif; ?>
            </div>
          <?php elseif($active_tab==='stats'): ?>
            <div class="tlms-ps-stats-panel">
              <div class="tlms-ps-section-head"><div><h3><?php esc_html_e('Private-session statistics','tutor-learning-suite');?></h3><p class="tlms-ps-help"><?php esc_html_e('Statistics are calculated from your private-session activity only. No external analytics service is used.','tutor-learning-suite');?></p></div></div>
              <div class="tlms-ps-stat-grid tlms-ps-stats-grid">
                <div class="tlms-ps-stat"><span><?php esc_html_e('Total requests','tutor-learning-suite');?></span><strong><?php echo (int)$stats['total_requests'];?></strong></div>
                <div class="tlms-ps-stat"><span><?php esc_html_e('Response rate','tutor-learning-suite');?></span><strong><?php echo esc_html($stats['response_rate'].'%');?></strong></div>
                <div class="tlms-ps-stat"><span><?php esc_html_e('Average response time','tutor-learning-suite');?></span><strong><?php echo esc_html($stats['avg_response_label']);?></strong></div>
                <div class="tlms-ps-stat"><span><?php esc_html_e('Paid / confirmed','tutor-learning-suite');?></span><strong><?php echo (int)$stats['confirmed_sessions'];?></strong></div>
                <div class="tlms-ps-stat"><span><?php esc_html_e('Completed','tutor-learning-suite');?></span><strong><?php echo (int)$stats['completed_sessions'];?></strong></div>
                <div class="tlms-ps-stat"><span><?php esc_html_e('Tryouts approved','tutor-learning-suite');?></span><strong><?php echo (int)$stats['tryouts_approved'];?></strong></div>
                <div class="tlms-ps-stat"><span><?php esc_html_e('Upcoming meetings','tutor-learning-suite');?></span><strong><?php echo (int)$stats['upcoming_meetings'];?></strong></div>
                <div class="tlms-ps-stat"><span><?php esc_html_e('Private-session revenue','tutor-learning-suite');?></span><strong><?php echo esc_html($stats['revenue_label']);?></strong></div>
              </div>
              <div class="tlms-ps-card tlms-ps-stats-breakdown">
                <h3><?php esc_html_e('Request breakdown','tutor-learning-suite');?></h3>
                <div class="tlms-ps-confirmed-summary-grid">
                  <div><span><?php esc_html_e('Waiting for tutor','tutor-learning-suite');?></span><strong><?php echo (int)$stats['pending'];?></strong></div>
                  <div><span><?php esc_html_e('Waiting for student','tutor-learning-suite');?></span><strong><?php echo (int)$stats['proposal_sent'];?></strong></div>
                  <div><span><?php esc_html_e('Rejected / declined','tutor-learning-suite');?></span><strong><?php echo (int)$stats['rejected_declined'];?></strong></div>
                  <div><span><?php esc_html_e('Failed / expired','tutor-learning-suite');?></span><strong><?php echo (int)$stats['failed'];?></strong></div>
                </div>
              </div>
            </div>
          <?php elseif($active_tab==='settings'): ?>
          <?php
          $tp=$this->tutor_private_settings($tutor_id); $options=$this->tutor_tutoring_options($tutor_id);
          $this->seed_availability($tutor_id);
          $weekly_rows=$this->get_availability($tutor_id);
          $weekly_map=[];
          foreach((array)$weekly_rows as $weekly_row){$weekly_map[(int)$weekly_row->weekday][(string)$weekly_row->period]=$weekly_row;}
          $weekly_days=[0=>__('Sunday','tutor-learning-suite'),1=>__('Monday','tutor-learning-suite'),2=>__('Tuesday','tutor-learning-suite'),3=>__('Wednesday','tutor-learning-suite'),4=>__('Thursday','tutor-learning-suite'),5=>__('Friday','tutor-learning-suite'),6=>__('Saturday','tutor-learning-suite')];
          $tutor_timezone=$this->user_timezone_string($tutor_id);
          ?>
          <form method="post" class="tlms-ps-card tlms-ps-ajax-form tlms-ps-private-settings-form" data-ps-action="tlms_ps_save_tutor_settings">
            <input type="hidden" name="_tlms_ps_settings_nonce" value="<?php echo esc_attr(wp_create_nonce(self::NONCE));?>">
            <h3><?php esc_html_e('What can you teach?','tutor-learning-suite');?></h3>
            <p class="tlms-ps-help"><?php esc_html_e('Choose the subjects you teach. Students will use this list when they send a private tutoring request. Pricing is agreed later in the tutor proposal.','tutor-learning-suite');?></p>
            <?php $all_subjects=$this->all_course_subject_terms(); ?>
            <div class="tlms-ps-subject-picker"><div class="tlms-ps-add-subject-row"><select class="tlms-ps-subject-adder"><option value=""><?php esc_html_e('Add a subject you teach…','tutor-learning-suite');?></option><?php foreach($all_subjects as $term):$tid=(int)$term->term_id;if(array_key_exists($tid,$subject_prices))continue;?><option value="<?php echo $tid;?>" data-name="<?php echo esc_attr($term->name);?>"><?php echo esc_html($term->name);?></option><?php endforeach;?></select><button type="button" class="button tlms-ps-add-subject"><?php esc_html_e('Add subject','tutor-learning-suite');?></button></div><div class="tlms-ps-subject-list"><?php if(!$subject_prices):?><div class="tlms-ps-no-selected-subjects"><?php esc_html_e('No subjects added yet. Choose one above to start.','tutor-learning-suite');?></div><?php endif;?><?php foreach($subject_prices as $tid=>$price):$term=get_term((int)$tid,'course-category');if(!$term||is_wp_error($term))continue;?><div class="tlms-ps-subject-row is-selected" data-subject-id="<?php echo (int)$tid;?>"><input type="hidden" name="subject_enabled[<?php echo (int)$tid;?>]" value="1"><span class="tlms-ps-subject-name"><?php echo esc_html($term->name);?></span><button type="button" class="button-link-delete tlms-ps-remove-subject"><?php esc_html_e('Remove','tutor-learning-suite');?></button></div><?php endforeach;?></div></div>
            <hr class="tlms-ps-settings-divider">
            <h3><?php esc_html_e('Tutoring options you offer','tutor-learning-suite');?></h3>
            <p class="tlms-ps-help"><?php esc_html_e('Students will first choose 1-to-1 or Group, then choose an available teaching method. At the student home, only 1-to-1 is allowed.','tutor-learning-suite');?></p>
            <div class="tlms-ps-option-matrix">
              <?php foreach([
                'one_to_one_online'=>__('1-to-1 · Online','tutor-learning-suite'),
                'one_to_one_tutor_home'=>__('1-to-1 · Offline at tutor’s home','tutor-learning-suite'),
                'one_to_one_student_home'=>__('1-to-1 · Offline at student’s home','tutor-learning-suite'),
                'group_online'=>__('Group · Online','tutor-learning-suite'),
                'group_tutor_home'=>__('Group · Offline at tutor’s home','tutor-learning-suite'),
              ] as $key=>$label):?><label class="tlms-ps-option-card"><input type="checkbox" name="tutoring_options[<?php echo esc_attr($key);?>]" value="1" <?php checked(!empty($options[$key]));?>><span><?php echo esc_html($label);?></span></label><?php endforeach;?>
            </div>
            <hr class="tlms-ps-settings-divider">
            <section class="tlms-ps-weekly-availability" aria-labelledby="tlms-ps-weekly-availability-title">
              <div class="tlms-ps-weekly-head">
                <div><h3 id="tlms-ps-weekly-availability-title"><?php esc_html_e('Your weekly availability','tutor-learning-suite');?></h3>
                <p class="tlms-ps-help"><?php esc_html_e('Choose the days and time ranges when learners may request a private session. You can set up to two separate time blocks per day. A request is not confirmed until you approve the final proposal.','tutor-learning-suite');?></p></div>
                <span class="tlms-ps-timezone-label"><i class="tutor-icon-globe" aria-hidden="true"></i><?php echo esc_html($tutor_timezone);?></span>
              </div>
              <div class="tlms-ps-weekly-days">
                <?php foreach($weekly_days as $day_index=>$day_label): $day_rows=$weekly_map[$day_index]??[]; ?>
                  <article class="tlms-ps-weekly-day">
                    <h4><?php echo esc_html($day_label);?></h4>
                    <div class="tlms-ps-weekly-blocks">
                      <?php foreach(['morning'=>__('Time block 1','tutor-learning-suite'),'afternoon'=>__('Time block 2','tutor-learning-suite')] as $period=>$period_label): $row=$day_rows[$period]??null; $enabled=$row ? (int)$row->enabled : 0; $start_value=$row ? substr((string)$row->start_time,0,5) : ($period==='morning'?'09:00':'14:00'); $end_value=$row ? substr((string)$row->end_time,0,5) : ($period==='morning'?'12:00':'17:00'); ?>
                        <div class="tlms-ps-weekly-block">
                          <label class="tlms-ps-weekly-toggle"><input type="checkbox" name="availability_enabled[<?php echo (int)$day_index;?>][<?php echo esc_attr($period);?>]" value="1" <?php checked($enabled,1);?>><span><?php echo esc_html($period_label);?></span></label>
                          <div class="tlms-ps-weekly-times">
                            <label><span class="screen-reader-text"><?php echo esc_html(sprintf(__('Start time, %1$s, %2$s','tutor-learning-suite'),$day_label,$period_label));?></span><input type="time" name="availability_start[<?php echo (int)$day_index;?>][<?php echo esc_attr($period);?>]" value="<?php echo esc_attr($start_value);?>" aria-label="<?php echo esc_attr(sprintf(__('%1$s %2$s start time','tutor-learning-suite'),$day_label,$period_label));?>"></label>
                            <span aria-hidden="true">–</span>
                            <label><span class="screen-reader-text"><?php echo esc_html(sprintf(__('End time, %1$s, %2$s','tutor-learning-suite'),$day_label,$period_label));?></span><input type="time" name="availability_end[<?php echo (int)$day_index;?>][<?php echo esc_attr($period);?>]" value="<?php echo esc_attr($end_value);?>" aria-label="<?php echo esc_attr(sprintf(__('%1$s %2$s end time','tutor-learning-suite'),$day_label,$period_label));?>"></label>
                          </div>
                        </div>
                      <?php endforeach;?>
                    </div>
                  </article>
                <?php endforeach;?>
              </div>
              <p class="tlms-ps-weekly-note"><?php esc_html_e('Leave a time block unchecked when you are unavailable. Existing bookings are never changed by editing this weekly schedule.','tutor-learning-suite');?></p>
            </section>
            <hr class="tlms-ps-settings-divider">
            <h3><?php esc_html_e('Free tryout session','tutor-learning-suite');?></h3>
            <p class="tlms-ps-help"><?php esc_html_e('Offer a short free tryout. Students can request one from this form. Tryouts are approved or rejected directly by you and never require payment.','tutor-learning-suite');?></p>
            <div class="tlms-ps-option-matrix">
              <label class="tlms-ps-option-card"><input type="checkbox" name="offer_tryout" value="1" <?php checked(!empty($tp['offer_tryout']));?>><span><?php esc_html_e('Allow students to request a free tryout','tutor-learning-suite');?></span></label>
              <label class="tlms-ps-option-card"><input type="checkbox" name="tryout_limit_one_per_student" value="1" <?php checked(!empty($tp['tryout_limit_one_per_student']));?>><span><?php esc_html_e('Allow only one tryout per student','tutor-learning-suite');?></span></label>
            </div>
            <div class="tlms-ps-grid tlms-ps-grid-2">
              <label><span><?php esc_html_e('Tryout duration','tutor-learning-suite');?></span><select name="tryout_duration"><option value="15" <?php selected((int)$tp['tryout_duration'],15);?>>15 min</option><option value="30" <?php selected((int)$tp['tryout_duration'],30);?>>30 min</option><option value="45" <?php selected((int)$tp['tryout_duration'],45);?>>45 min</option><option value="60" <?php selected((int)$tp['tryout_duration'],60);?>>60 min</option><option value="90" <?php selected((int)$tp['tryout_duration'],90);?>>90 min</option><option value="120" <?php selected((int)$tp['tryout_duration'],120);?>>120 min</option></select><small><?php esc_html_e('The student cannot change this duration on a tryout request.','tutor-learning-suite');?></small></label>
            </div>
            <hr class="tlms-ps-settings-divider">
            <h3><?php esc_html_e('Hourly rate','tutor-learning-suite');?></h3>
            <p class="tlms-ps-help"><?php esc_html_e('Your standard price for one hour. Students see it on your booking form, and proposals default to this rate scaled to the session length (for example 90 minutes = 1.5 × your rate). You can still change the price in each proposal. Leave 0 to hide it.','tutor-learning-suite');?></p>
            <div class="tlms-ps-grid tlms-ps-grid-2"><label><span><?php printf(esc_html__('Rate per hour (%s)','tutor-learning-suite'),esc_html((string)$settings['currency']));?></span><input type="number" name="hourly_rate" min="0" step="0.01" value="<?php echo esc_attr((float)$tp['hourly_rate']>0?(float)$tp['hourly_rate']:'');?>" placeholder="0.00"></label></div>
            <hr class="tlms-ps-settings-divider">
            <h3><?php esc_html_e('Terms & cancellation policy','tutor-learning-suite');?></h3>
            <p class="tlms-ps-help"><?php esc_html_e('Both sections are required. Keep them practical and easy for students to understand. Use bullet points rather than a long paragraph.','tutor-learning-suite');?></p>
            <div class="tlms-ps-policy-editor">
              <label><span><?php esc_html_e('Terms & conditions','tutor-learning-suite');?> <b class="tlms-ps-required">*</b></span><textarea name="terms" rows="8" required placeholder="• Subjects you teach
• Approximate price range / hourly rate
• What is included in a lesson
• How students should prepare
• How you communicate and confirm lesson times
• Any other important expectations"><?php echo esc_textarea($tp['terms']);?></textarea><small><?php esc_html_e('Suggestion: mention your approximate prices or hourly rate, subjects offered, lesson format, what is included, and any expectations students should know before booking.','tutor-learning-suite');?></small></label>
              <label><span><?php esc_html_e('Cancellation policy','tutor-learning-suite');?> <b class="tlms-ps-required">*</b></span><textarea name="cancellation_policy" rows="8" required placeholder="• How much notice is needed to cancel
• Whether students can reschedule
• What happens for late cancellations
• What happens if the tutor cancels
• Any refund / credit rules
• How no-shows are handled"><?php echo esc_textarea($tp['cancellation_policy']);?></textarea><small><?php esc_html_e('Use clear bullet points. State your notice period, rescheduling rules, late-cancellation/no-show rules, and what happens if you need to cancel.','tutor-learning-suite');?></small></label>
            </div>
            <p><button type="submit" class="tutor-btn tutor-btn-primary"><?php esc_html_e('Save private-session settings','tutor-learning-suite');?></button></p><div class="tlms-ps-form-result" aria-live="polite"></div>
          </form>
          <?php else: ?>
          <div class="tlms-ps-card"><div class="tlms-ps-section-title"><h3><?php esc_html_e('Student requests & proposals','tutor-learning-suite');?></h3><p class="tlms-ps-help"><?php esc_html_e('Review what the student wants, then send a proposed schedule, price and message.','tutor-learning-suite');?></p></div>
          <form method="get" class="tlms-ps-request-toolbar">
            <input type="hidden" name="tutor_dashboard_page" value="private-sessions"><input type="hidden" name="ps_tab" value="requests">
            <input type="search" name="ps_search" value="<?php echo esc_attr($request_search);?>" placeholder="<?php echo esc_attr__('Search student, email, subject or goal…','tutor-learning-suite');?>">
            <select name="ps_status"><option value=""><?php esc_html_e('All statuses','tutor-learning-suite');?></option><?php foreach(['pending_tutor'=>'New request','proposal_sent'=>'Proposal sent','approved'=>'Approved','paid'=>'Paid','confirmed'=>'Confirmed','completed'=>'Completed','declined'=>'Declined','rejected'=>'Rejected'] as $sk=>$sl):?><option value="<?php echo esc_attr($sk);?>" <?php selected($request_status,$sk);?>><?php echo esc_html($sl);?></option><?php endforeach;?></select>
            <select name="ps_sort"><option value="newest" <?php selected($request_sort,'newest');?>><?php esc_html_e('Newest first','tutor-learning-suite');?></option><option value="oldest" <?php selected($request_sort,'oldest');?>><?php esc_html_e('Oldest first','tutor-learning-suite');?></option><option value="status" <?php selected($request_sort,'status');?>><?php esc_html_e('Status','tutor-learning-suite');?></option></select><button class="button" type="submit"><?php esc_html_e('Search','tutor-learning-suite');?></button><a class="button" href="<?php echo esc_url(add_query_arg(['tutor_dashboard_page'=>'private-sessions','ps_tab'=>'requests'],$this->dashboard_url('private-sessions')));?>"><?php esc_html_e('Reset','tutor-learning-suite');?></a>
          </form>
          <?php if($bookings):?><div class="tlms-ps-bulkbar"><label><input type="checkbox" class="tlms-ps-select-all"> <?php esc_html_e('Select all','tutor-learning-suite');?></label><select class="tlms-ps-bulk-status"><option value=""><?php esc_html_e('Bulk edit status…','tutor-learning-suite');?></option><option value="approved"><?php esc_html_e('Approve eligible','tutor-learning-suite');?></option><option value="rejected"><?php esc_html_e('Reject selected','tutor-learning-suite');?></option></select><button type="button" class="button tlms-ps-bulk-apply"><?php esc_html_e('Apply','tutor-learning-suite');?></button><span class="tlms-ps-selected-count">0 selected</span></div><?php endif;?>
          <?php if(!$bookings):?><div class="tlms-ps-empty-subjects"><?php esc_html_e('No private-session requests match your search.','tutor-learning-suite');?></div><?php else: foreach($bookings as $b): $student=get_userdata((int)$b->student_id); $requested_minutes=max(15,(int)($b->requested_duration_minutes ?: ($b->duration_minutes ?: 60))); $suggested=$this->duration_price_for_subject($tutor_id,(int)$b->teach_category_id,$requested_minutes); ?>
            <div class="tlms-ps-request-card tlms-ps-request-<?php echo esc_attr($b->status);?>"><label class="tlms-ps-request-select"><input type="checkbox" class="tlms-ps-request-check" value="<?php echo (int)$b->id;?>"><span><?php esc_html_e('Select','tutor-learning-suite');?></span></label>
              <div class="tlms-ps-request-head"><div><span class="tlms-ps-request-id">#<?php echo (int)$b->id;?></span><h4><?php echo $student ? $this->public_profile_link($student, $student->display_name) : esc_html($b->student_name);?></h4><p><?php echo esc_html($b->subject);?> · <?php echo esc_html($this->session_type_label($b->session_type??'one_to_one'));?> · <?php echo esc_html($this->tutoring_type_label($b->tutoring_type));?></p></div><span class="tlms-ps-status tlms-ps-status-<?php echo esc_attr($b->status);?>"><?php echo esc_html($this->status_label($b->status));?></span><?php if((int)$b->is_tryout):?><span class="tlms-ps-tryout-badge">TRYOUT</span><?php endif;?><?php if(in_array((string)$b->status,['pending_tutor','pending_admin','proposal_sent','approved','paid','confirmed'],true)):?><button type="button" class="button tlms-ps-cancel-session" data-session-id="<?php echo (int)$b->id;?>"><?php esc_html_e('Cancel booking','tutor-learning-suite');?></button><?php endif;?></div>
              <div class="tlms-ps-request-grid"><div><span><?php esc_html_e('Student info','tutor-learning-suite');?></span><?php echo $student ? $this->public_profile_link($student, $student->display_name) : '<strong>'.esc_html($b->student_name).'</strong>';?><small><?php echo esc_html($b->student_email);?><?php if($b->student_phone):?> · <?php echo esc_html($b->student_phone);?><?php endif;?></small></div><div><span><?php esc_html_e('Requested session','tutor-learning-suite');?></span><strong><?php echo esc_html($this->format_duration_minutes($requested_minutes));?></strong><small><?php echo esc_html($this->format_requested_slot_for_user($b,$tutor_id));?></small></div><div><span><?php esc_html_e('Goal','tutor-learning-suite');?></span><strong><?php echo esc_html(wp_trim_words($b->goal?:'—',18));?></strong></div></div>
              <?php if($b->notes):?><div class="tlms-ps-request-note"><strong><?php esc_html_e('Notes','tutor-learning-suite');?></strong><p><?php echo nl2br(esc_html($b->notes));?></p></div><?php endif;?><?php if($b->status==='pending_tutor' && $b->student_response):?><div class="tlms-ps-student-response"><strong><?php esc_html_e('Student declined your previous proposal','tutor-learning-suite');?></strong><p><?php echo nl2br(esc_html($b->student_response));?></p><small><?php esc_html_e('Review this reason and submit a new proposal below. The student does not need to send another request.','tutor-learning-suite');?></small></div><?php endif;?>
              <?php if($b->status==='pending_tutor' && (int)$b->is_tryout): ?><div class="tlms-ps-tryout-request-box"><div><strong><?php esc_html_e('Free tryout request','tutor-learning-suite');?></strong><span><?php printf(esc_html__('Requested for %s. No payment is required.','tutor-learning-suite'),esc_html($this->format_duration_minutes((int)($b->requested_duration_minutes ?: ($b->duration_minutes ?: 60)))));?></span></div><div class="tlms-ps-proposal-actions"><button type="button" class="tutor-btn tutor-btn-primary tlms-ps-tryout-decision" data-session-id="<?php echo (int)$b->id;?>" data-decision="approved"><?php esc_html_e('Approve tryout','tutor-learning-suite');?></button><button type="button" class="button tlms-ps-tryout-decision" data-session-id="<?php echo (int)$b->id;?>" data-decision="rejected"><?php esc_html_e('Reject','tutor-learning-suite');?></button></div><div class="tlms-ps-form-result" aria-live="polite"></div></div>
              <?php elseif($b->status==='pending_tutor'): ?><form class="tlms-ps-proposal-form tlms-ps-ajax-proposal" data-session-id="<?php echo (int)$b->id;?>"><div class="tlms-ps-proposal-title"><strong><?php esc_html_e('Send your proposal','tutor-learning-suite');?></strong><span><?php esc_html_e('The student will see these details and can accept or decline.','tutor-learning-suite');?></span></div><div class="tlms-ps-grid tlms-ps-proposal-grid"><label><span><?php esc_html_e('Date','tutor-learning-suite');?></span><input type="date" name="proposal_date" min="<?php echo esc_attr(wp_date('Y-m-d',time(),new DateTimeZone($this->user_timezone_string($tutor_id))));?>" required></label><label><span><?php esc_html_e('Start time','tutor-learning-suite');?></span><input type="time" name="proposal_start" required></label><label><span><?php esc_html_e('Duration (hours)','tutor-learning-suite');?></span><input type="number" name="proposal_duration_hours" min="0.25" max="24" step="0.25" value="<?php echo esc_attr(max(0.25,$requested_minutes/60));?>" required><small class="tlms-ps-help"><?php esc_html_e('15-minute increments, up to 24 hours.','tutor-learning-suite');?></small></label><label><span><?php esc_html_e('Price','tutor-learning-suite');?></span><input type="number" min="0" step="0.01" name="proposal_price" value="<?php echo esc_attr($suggested);?>" required><small><?php echo esc_html($settings['currency']);?></small></label></div><label><span><?php esc_html_e('Message / invoice details','tutor-learning-suite');?></span><textarea name="proposal_message" rows="4" placeholder="<?php echo esc_attr__('Explain what is included, the plan, and anything the student should know…','tutor-learning-suite');?>"></textarea></label><button type="submit" class="tutor-btn tutor-btn-primary"><?php esc_html_e('Send proposal','tutor-learning-suite');?></button><div class="tlms-ps-form-result" aria-live="polite"></div></form>
              <?php elseif($b->status==='proposal_sent'): ?><div class="tlms-ps-proposal-preview"><div><span><?php esc_html_e('Proposed schedule','tutor-learning-suite');?></span><strong><?php echo esc_html(wp_date(get_option('date_format'),(new DateTimeImmutable($b->session_date.' 00:00:00',new DateTimeZone(!empty($b->session_timezone)?$b->session_timezone:$this->user_timezone_string($tutor_id))))->getTimestamp(),new DateTimeZone($this->user_timezone_string($tutor_id))).' · '.$this->format_session_range_for_user($b,$tutor_id,true));?></strong></div><div><span><?php esc_html_e('Proposed price','tutor-learning-suite');?></span><strong><?php echo esc_html(number_format((float)$b->price,2).' '.$b->currency);?></strong></div><p><?php echo nl2br(esc_html($b->proposal_message?:__('No extra message was added.','tutor-learning-suite')));?></p></div>
              <?php elseif($b->status==='approved'): $declared_method_title=''; if(!empty($b->payment_method_id)){foreach($this->get_tutor_offline_methods($tutor_id) as $pm){if(isset($pm['id']) && hash_equals((string)$pm['id'],(string)$b->payment_method_id)){$declared_method_title=(string)$pm['title'];break;}}} ?><div class="tlms-ps-payment-review-box"><strong><?php esc_html_e('Direct payment status','tutor-learning-suite');?></strong><?php if(!empty($b->payment_declared_at)):?><p><?php esc_html_e('The student declared that they paid you. Check your own payment account or cash records before confirming.','tutor-learning-suite');?></p><?php if($declared_method_title):?><p><b><?php esc_html_e('Method:','tutor-learning-suite');?></b> <?php echo esc_html($declared_method_title);?></p><?php endif;?><?php if(!empty($b->payment_reference)):?><p><b><?php esc_html_e('Reference:','tutor-learning-suite');?></b> <?php echo esc_html($b->payment_reference);?></p><?php endif;?><button type="button" class="tutor-btn tutor-btn-primary tlms-ps-confirm-payment" data-session-id="<?php echo (int)$b->id;?>"><?php esc_html_e('Confirm receipt and confirm booking','tutor-learning-suite');?></button><?php else:?><p><?php esc_html_e('The student accepted your proposal. Waiting for their payment declaration.','tutor-learning-suite');?></p><?php endif;?><div class="tlms-ps-form-result" aria-live="polite"></div></div>
              <?php elseif(in_array($b->status,['paid','confirmed','completed'],true)): ?>
                <div class="tlms-ps-confirmed-summary">
                  <div class="tlms-ps-confirmed-summary-head"><strong><?php esc_html_e('Agreed session details','tutor-learning-suite');?></strong><span><?php echo $b->final_access_sent_at ? esc_html__('Final information sent','tutor-learning-suite') : esc_html__('Confirmed booking','tutor-learning-suite');?></span></div>
                  <div class="tlms-ps-confirmed-summary-grid">
                    <div><span><?php esc_html_e('Date','tutor-learning-suite');?></span><strong><?php echo $b->session_date ? esc_html(wp_date(get_option('date_format'),(new DateTimeImmutable($b->session_date.' 00:00:00',new DateTimeZone(!empty($b->session_timezone)?$b->session_timezone:$this->user_timezone_string($b->tutor_id))))->getTimestamp(),new DateTimeZone($this->user_timezone_string($tutor_id)))) : esc_html__('—','tutor-learning-suite');?></strong></div>
                    <div><span><?php esc_html_e('Starting time','tutor-learning-suite');?></span><strong><?php echo $b->start_time ? esc_html($this->format_session_datetime_for_user($b,$tutor_id,true)) : esc_html__('—','tutor-learning-suite');?></strong></div>
                    <div><span><?php esc_html_e('Duration','tutor-learning-suite');?></span><strong><?php echo esc_html($this->format_duration_minutes((int)$b->duration_minutes));?></strong></div>
                    <div><span><?php esc_html_e('Price','tutor-learning-suite');?></span><strong><?php echo esc_html(number_format((float)$b->price,2).' '.$b->currency);?></strong></div>
                    <div><span><?php esc_html_e('Session format','tutor-learning-suite');?></span><strong><?php echo esc_html($this->session_type_label($b->session_type??'one_to_one'));?></strong></div><div><span><?php esc_html_e('Teaching method','tutor-learning-suite');?></span><strong><?php echo esc_html($this->tutoring_type_label($b->tutoring_type));?></strong></div>
                  </div>
                </div>
                <form method="post" class="tlms-ps-link-form tlms-ps-ajax-form" data-ps-action="tlms_ps_update_session">
                  <input type="hidden" name="session_id" value="<?php echo (int)$b->id;?>">
                  <div class="tlms-ps-final-info-title"><strong><?php echo $b->final_access_sent_at ? esc_html__('Final session information','tutor-learning-suite') : esc_html__('Send final session information','tutor-learning-suite');?></strong><span><?php esc_html_e('The agreed date, time and price stay visible above. Add or update the final meeting/location details here.','tutor-learning-suite');?></span></div>
                  <?php if($b->meeting_url): ?><div class="tlms-ps-sent-detail"><span><?php esc_html_e('Meeting link','tutor-learning-suite');?></span><a href="<?php echo esc_url($b->meeting_url);?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($b->meeting_url);?></a></div><?php endif; ?>
                  <?php if($b->tutoring_type==='online'):?><input type="url" name="meeting_url" value="<?php echo esc_attr((string)$b->meeting_url);?>" placeholder="<?php echo esc_attr__('Online meeting link','tutor-learning-suite');?>"><?php endif;?>
                  <textarea name="meeting_details" placeholder="<?php echo esc_attr__('Final location, access instructions, or other details…','tutor-learning-suite');?>"><?php echo esc_textarea((string)$b->meeting_details);?></textarea>
                  <button type="submit" class="button button-primary"><?php echo $b->final_access_sent_at ? esc_html__('Update final information','tutor-learning-suite') : esc_html__('Send final information','tutor-learning-suite');?></button>
                </form>
              <?php endif; ?>
            </div>
          <?php endforeach; endif; ?></div>
          <?php endif; ?>
        </div>
        <?php return ob_get_clean();
    }

    /**
     * Validate and save the tutor's recurring weekly schedule.
     * Returns true or WP_Error; all inputs are checked before any row is changed.
     */
    private function save_weekly_availability_from_request($tutor_id) {
        if (!array_key_exists('availability_start', $_POST) && !array_key_exists('availability_end', $_POST) && !array_key_exists('availability_enabled', $_POST)) {
            return true; // Compatibility with an older dashboard form.
        }
        $raw_enabled = isset($_POST['availability_enabled']) ? wp_unslash((array)$_POST['availability_enabled']) : [];
        $raw_start = isset($_POST['availability_start']) ? wp_unslash((array)$_POST['availability_start']) : [];
        $raw_end = isset($_POST['availability_end']) ? wp_unslash((array)$_POST['availability_end']) : [];
        $periods = ['morning','afternoon'];
        $rows = [];

        for ($day=0; $day<7; $day++) {
            $day_ranges = [];
            foreach ($periods as $period) {
                $enabled = isset($raw_enabled[$day][$period]) && '1' === (string)$raw_enabled[$day][$period];
                $start = isset($raw_start[$day][$period]) ? sanitize_text_field((string)$raw_start[$day][$period]) : '';
                $end = isset($raw_end[$day][$period]) ? sanitize_text_field((string)$raw_end[$day][$period]) : '';
                $valid_start = (bool)preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $start);
                $valid_end = (bool)preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $end);
                if ($enabled && (!$valid_start || !$valid_end)) {
                    return new WP_Error('tls_ps_invalid_availability_time', sprintf(__('Enter valid 24-hour start and end times for %1$s (%2$s).','tutor-learning-suite'), $this->weekday_label($day), $period==='morning' ? __('time block 1','tutor-learning-suite') : __('time block 2','tutor-learning-suite')));
                }
                if (!$valid_start) { $start = $period==='morning' ? '09:00' : '14:00'; }
                if (!$valid_end) { $end = $period==='morning' ? '12:00' : '17:00'; }
                if ($enabled && $start >= $end) {
                    return new WP_Error('tls_ps_invalid_availability', sprintf(__('The end time must be later than the start time for %1$s (%2$s).','tutor-learning-suite'), $this->weekday_label($day), $period==='morning' ? __('time block 1','tutor-learning-suite') : __('time block 2','tutor-learning-suite')));
                }
                $rows[] = [
                    'weekday' => $day,
                    'period' => $period,
                    'start_time' => $start . ':00',
                    'end_time' => $end . ':00',
                    'enabled' => $enabled ? 1 : 0,
                ];
                if ($enabled) { $day_ranges[] = [$start, $end]; }
            }
            if (count($day_ranges) > 1) {
                usort($day_ranges, static function($a,$b){ return strcmp($a[0],$b[0]); });
                for ($i=1; $i<count($day_ranges); $i++) {
                    if ($day_ranges[$i][0] < $day_ranges[$i-1][1]) {
                        return new WP_Error('tls_ps_overlapping_availability', sprintf(__('Your time blocks overlap on %s. Adjust them so they do not overlap.','tutor-learning-suite'), $this->weekday_label($day)));
                    }
                }
            }
        }

        $this->seed_availability($tutor_id);
        $table = $this->table(self::TABLE_AVAIL);
        foreach ($rows as $row) {
            $updated = $this->wpdb->update($table, [
                'start_time' => $row['start_time'],
                'end_time' => $row['end_time'],
                'enabled' => $row['enabled'],
            ], [
                'tutor_id' => absint($tutor_id),
                'weekday' => $row['weekday'],
                'period' => $row['period'],
            ], ['%s','%s','%d'], ['%d','%d','%s']);
            if (false === $updated) {
                return new WP_Error('tls_ps_availability_save_failed', __('Your availability could not be saved. Please try again.','tutor-learning-suite'));
            }
        }
        return true;
    }

    private function weekday_label($weekday) {
        $labels = [__('Sunday','tutor-learning-suite'),__('Monday','tutor-learning-suite'),__('Tuesday','tutor-learning-suite'),__('Wednesday','tutor-learning-suite'),__('Thursday','tutor-learning-suite'),__('Friday','tutor-learning-suite'),__('Saturday','tutor-learning-suite')];
        return $labels[max(0,min(6,(int)$weekday))];
    }

    public function ajax_save_availability() {
        check_ajax_referer(self::NONCE,'nonce');
        if(!is_user_logged_in()||!$this->is_instructor(get_current_user_id())) wp_send_json_error(['message'=>__('Permission denied.','tutor-learning-suite')],403);
        // Accept the new nested weekly schedule fields and legacy field names.
        if (!isset($_POST['availability_start']) && isset($_POST['start'])) {
            $_POST['availability_start'] = $_POST['start'];
            $_POST['availability_end'] = $_POST['end'] ?? [];
            $_POST['availability_enabled'] = $_POST['enabled'] ?? [];
        }
        $result = $this->save_weekly_availability_from_request(get_current_user_id());
        if (is_wp_error($result)) wp_send_json_error(['message'=>$result->get_error_message()],400);
        wp_send_json_success(['message'=>__('Weekly availability saved successfully.','tutor-learning-suite')]);
    }

    public function ajax_save_pricing() {
        check_ajax_referer(self::NONCE,'nonce');if(!is_user_logged_in()||!$this->is_instructor(get_current_user_id()))wp_send_json_error(['message'=>__('Permission denied.','tutor-learning-suite')],403);
        $uid=get_current_user_id();$base=max(0,(float)($_POST['base_price']??0));$selected=(array)($_POST['duration_enabled']??[]);$raw=(array)($_POST['price']??[]);$durations=[];$clean=[];
        foreach(['online','tutor_home','student_home'] as $type){$picked=array_values(array_intersect(array_map('absint',(array)($selected[$type]??[])),$this->duration_options()));if(!$picked)$picked=$type==='online'?[30,60,90,120]:[60,90,120];$durations[$type]=$picked;}
        foreach($raw as $key=>$val){$key=sanitize_key($key);if(!preg_match('/^(online|tutor_home|student_home)_(30|60|90|120)$/',$key))continue;[$type,$mins]=explode('_',$key,2);if(!in_array((int)$mins,$durations[$type],true))continue;if($val==='')continue;$clean[$key]=max(0,(float)$val);}
        update_user_meta($uid,'_tlms_ps_base_price',$base);update_user_meta($uid,'_tlms_ps_prices',$clean);update_user_meta($uid,'_tlms_ps_durations',$durations);wp_send_json_success(['message'=>__('Durations and pricing saved successfully.','tutor-learning-suite')]);
    }

    public function ajax_save_tutor_settings(){
        check_ajax_referer(self::NONCE,'nonce');
        if(!is_user_logged_in()||!$this->is_instructor(get_current_user_id())) wp_send_json_error(['message'=>__('Permission denied.','tutor-learning-suite')],403);
        $uid=get_current_user_id();
        $valid=[15,30,45,60,90,120];
        $duration=absint($_POST['tryout_duration']??30); if(!in_array($duration,$valid,true)) $duration=30;
        $data=[
            'offer_tryout'=>!empty($_POST['offer_tryout'])?1:0,
            'tryout_limit_one_per_student'=>!empty($_POST['tryout_limit_one_per_student'])?1:0,
            'tryout_duration'=>$duration,
            'tryout_price'=>max(0,(float)($_POST['tryout_price']??0)),
            'hourly_rate'=>min(1000000,max(0,round((float)($_POST['hourly_rate']??0),2))),
            'terms'=>sanitize_textarea_field(wp_unslash($_POST['terms']??'')),
            'cancellation_policy'=>sanitize_textarea_field(wp_unslash($_POST['cancellation_policy']??'')),
        ];
        if(array_key_exists('terms',$_POST) || array_key_exists('cancellation_policy',$_POST)){
            if(trim($data['terms'])==='') wp_send_json_error(['message'=>__('Terms & conditions are required. Please add the bullet points for students.','tutor-learning-suite')],400);
            if(trim($data['cancellation_policy'])==='') wp_send_json_error(['message'=>__('Cancellation policy is required. Please add the bullet points for students.','tutor-learning-suite')],400);
        }
        // Persist the schedule only after the other required settings have passed validation.
        $availability_result = $this->save_weekly_availability_from_request($uid);
        if (is_wp_error($availability_result)) wp_send_json_error(['message'=>$availability_result->get_error_message()],400);
        $saved_options=(array)($_POST['tutoring_options']??[]);
        $allowed_options=['one_to_one_online','one_to_one_tutor_home','one_to_one_student_home','group_online','group_tutor_home'];
        $tutoring_options=[]; foreach($allowed_options as $key) $tutoring_options[$key]=!empty($saved_options[$key])?1:0;
        update_user_meta($uid,'_tlms_ps_tutoring_options',$tutoring_options);
        $subject_enabled=(array)($_POST['subject_enabled']??[]);
        $subject_prices=[];
        foreach($subject_enabled as $term_id=>$enabled){
            $term_id=absint($term_id);
            if(!$term_id || empty($enabled)) continue;
            $term=get_term($term_id,'course-category');
            if($term && !is_wp_error($term)) $subject_prices[$term_id]=1;
        }
        update_user_meta($uid,self::META_SUBJECT_PRICES,$subject_prices);
        update_user_meta($uid,'_tlms_ps_private_settings',$data);
        wp_send_json_success(['message'=>__('Private-session settings and weekly availability saved successfully.','tutor-learning-suite')]);
    }

    public function ajax_update_session() {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!is_user_logged_in() || !$this->is_instructor(get_current_user_id())) {
            wp_send_json_error(['message'=>__('Permission denied.','tutor-learning-suite')], 403);
        }
        $id=absint($_POST['session_id']??0);
        $row=$this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->table(self::TABLE_SESSIONS)} WHERE id=%d",$id));
        if(!$row || (int)$row->tutor_id!==get_current_user_id()) wp_send_json_error(['message'=>__('Session not found.','tutor-learning-suite')],404);
        if(!in_array($row->status,['paid','confirmed','completed'],true)) wp_send_json_error(['message'=>__('Meeting details can be sent after payment/confirmation.','tutor-learning-suite')],400);
        $url=esc_url_raw(wp_unslash($_POST['meeting_url']??''));
        $details=sanitize_textarea_field(wp_unslash($_POST['meeting_details']??''));
        $this->wpdb->update($this->table(self::TABLE_SESSIONS),['meeting_url'=>$url,'meeting_details'=>$details,'final_access_sent_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')],['id'=>$id],['%s','%s','%s'],['%d']);
        if((int)$row->student_id){
            $student=get_userdata((int)$row->student_id);
            if($student && is_email($student->user_email)){
                wp_mail($student->user_email,__('Your private tutoring session details are ready','tutor-learning-suite'),__('Your tutor has added the meeting details for your private session. Log in to your student dashboard to view them.','tutor-learning-suite'));
            }
        }
        wp_send_json_success(['message'=>__('Meeting details sent to the student.','tutor-learning-suite'),'can_rate'=>true,'session_id'=>$id]);
    }

    public function save_availability() {
        if (!is_user_logged_in() || !$this->is_instructor(get_current_user_id()) || !check_admin_referer('tlms_ps_save_availability','_tlms_ps_av_nonce')) wp_die('Permission denied.');
        $uid=get_current_user_id(); $enabled=(array)($_POST['enabled']??[]); $start=(array)($_POST['start']??[]); $end=(array)($_POST['end']??[]);
        $table=$this->table(self::TABLE_AVAIL); $this->seed_availability($uid);
        for($d=0;$d<7;$d++){
            $st=preg_match('/^\d{2}:\d{2}$/',$start[$d]??'')?$start[$d].':00':'09:00:00';
            $en=preg_match('/^\d{2}:\d{2}$/',$end[$d]??'')?$end[$d].':00':'17:00:00';
            $ok=$st<$en ? 1:0;
            $this->wpdb->update($table,['start_time'=>$st,'end_time'=>$en,'enabled'=>isset($enabled[$d])&&$ok?1:0],['tutor_id'=>$uid,'weekday'=>$d],['%s','%s','%d'],['%d','%d']);
        }
        wp_safe_redirect(wp_get_referer() ?: $this->dashboard_url('private-sessions')); exit;
    }

    public function save_pricing() {
        if(!is_user_logged_in() || !$this->is_instructor(get_current_user_id()) || !check_admin_referer('tlms_ps_save_pricing','_tlms_ps_price_nonce')) wp_die('Permission denied.');
        $uid=get_current_user_id();
        $base=max(0,(float)($_POST['base_price']??0));
        $raw=(array)($_POST['price']??[]); $clean=[];
        foreach($raw as $key=>$val){ $key=sanitize_key($key); if(!preg_match('/^(online|tutor_home|student_home)_(30|60|90|120)$/',$key)) continue; if($val==='') continue; $clean[$key]=max(0,(float)$val); }
        update_user_meta($uid,'_tlms_ps_base_price',$base); update_user_meta($uid,'_tlms_ps_prices',$clean);
        $selected=(array)($_POST['duration_enabled']??[]); $durations=[]; foreach(['online','tutor_home','student_home'] as $type){$picked=array_values(array_intersect(array_map('absint',(array)($selected[$type]??[])),$this->duration_options())); $durations[$type]=$picked?:($type==='online'?[30,60,90,120]:[60,90,120]);} update_user_meta($uid,'_tlms_ps_durations',$durations);
        wp_safe_redirect(wp_get_referer() ?: $this->dashboard_url('private-sessions')); exit;
    }

    /**
     * Return the single hidden Tutor LMS course used to store private-session
     * ratings for a tutor. One course per tutor keeps Tutor LMS's native review
     * model (one review per learner/course) intact while letting the admin
     * Reports > Reviews screen manage the rating normally.
     */
    private function get_or_create_rating_course($tutor_id){
        $tutor_id=absint($tutor_id);
        if(!$tutor_id || !$this->is_instructor($tutor_id)) return 0;
        global $wpdb;
        $pt=function_exists('tutor')&&isset(tutor()->course_post_type)&&tutor()->course_post_type?tutor()->course_post_type:'courses';
        $ids=$wpdb->get_col($wpdb->prepare(
            "SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE pm.meta_key=%s AND pm.meta_value=%d AND p.post_type=%s AND p.post_status NOT IN ('trash','auto-draft') ORDER BY p.ID ASC",
            '_tlms_ps_rating_tutor_id',$tutor_id,$pt
        ));
        $ids=array_values(array_unique(array_map('absint',(array)$ids)));
        $course_id=(int)($ids[0]??0);
        if(!$course_id){
            $tu=get_userdata($tutor_id);
            $title=__('Private Tutoring Reviews —','tutor-learning-suite').' '.($tu?$tu->display_name:__('Tutor','tutor-learning-suite'));
            $course_id=wp_insert_post([
                'post_type'=>$pt,
                'post_status'=>'publish',
                'post_title'=>$title,
                'post_author'=>$tutor_id,
                'post_content'=>__('Internal Tutor LMS course used only to store private tutoring ratings.','tutor-learning-suite'),
                'comment_status'=>'open',
            ],true);
            if(is_wp_error($course_id)||!$course_id) return 0;
            $course_id=(int)$course_id;
            $ids=[$course_id];
        }
        update_post_meta($course_id,'_tlms_ps_rating_course',1);
        update_post_meta($course_id,'_tlms_ps_rating_tutor_id',$tutor_id);
        update_post_meta($course_id,'_tlms_ps_internal_course',1);
        // Tutor LMS uses this user-meta relationship in several native course
        // queries (including instructor/report lookups). Keep the hidden rating
        // course registered as an instructor-owned Tutor course without exposing it.
        $linked_course_ids=(array)get_user_meta($tutor_id,'_tutor_instructor_course_id',false);
        $linked_course_ids=array_map('absint',$linked_course_ids);
        if(!in_array($course_id,$linked_course_ids,true)) add_user_meta($tutor_id,'_tutor_instructor_course_id',$course_id);
        if(get_post_status($course_id)!=='publish') wp_update_post(['ID'=>$course_id,'post_status'=>'publish']);

        // Native Tutor review/report queries identify course reviews using the
        // Tutor agent marker as well as comment_type. Older builds omitted it.
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->comments} SET comment_agent=%s WHERE comment_post_ID=%d AND comment_type='tutor_course_rating'",
            'TutorLMSPlugin',$course_id
        ));
        // Tutor's native instructor-rating calculation requires the literal
        // 'approved' value, not WordPress's legacy '1'. Keep old approved rows
        // compatible so private-session ratings contribute to the tutor's normal
        // profile/course rating totals after moderation.
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->comments} SET comment_approved='approved' WHERE comment_post_ID=%d AND comment_type='tutor_course_rating' AND comment_approved='1'",
            $course_id
        ));

        // Consolidate duplicate rating courses created by older plugin builds.
        if(count($ids)>1){
            foreach(array_slice($ids,1) as $duplicate_id){
                $duplicate_comments=$wpdb->get_col($wpdb->prepare(
                    "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_post_ID=%d AND comment_type='tutor_course_rating' ORDER BY comment_ID ASC",
                    $duplicate_id
                ));
                foreach((array)$duplicate_comments as $duplicate_comment_id){
                    $duplicate_comment_id=(int)$duplicate_comment_id;
                    $dupe=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->comments} WHERE comment_ID=%d LIMIT 1",$duplicate_comment_id));
                    if(!$dupe) continue;
                    $existing_id=(int)$wpdb->get_var($wpdb->prepare(
                        "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_post_ID=%d AND user_id=%d AND comment_type='tutor_course_rating' AND comment_approved NOT IN ('trash','spam') ORDER BY comment_ID DESC LIMIT 1",
                        $course_id,(int)$dupe->user_id
                    ));
                    if($existing_id){
                        $existing_date=strtotime((string)$wpdb->get_var($wpdb->prepare("SELECT comment_date FROM {$wpdb->comments} WHERE comment_ID=%d",$existing_id)));
                        $dupe_date=strtotime((string)$dupe->comment_date);
                        if($dupe_date>$existing_date){
                            $this->wpdb->update($wpdb->comments,[
                                'comment_content'=>$dupe->comment_content,
                                'comment_date'=>$dupe->comment_date,
                                'comment_date_gmt'=>$dupe->comment_date_gmt,
                                'comment_approved'=>$dupe->comment_approved,
                            ],['comment_ID'=>$existing_id],['%s','%s','%s','%s'],['%d']);
                            $dupe_rating=get_comment_meta($duplicate_comment_id,'tutor_rating',true);
                            if($dupe_rating!=='') update_comment_meta($existing_id,'tutor_rating',$dupe_rating);
                        }
                        wp_delete_comment($duplicate_comment_id,true);
                    }else{
                        $wpdb->update($wpdb->comments,['comment_post_ID'=>$course_id],['comment_ID'=>$duplicate_comment_id],['%d'],['%d']);
                    }
                }
                wp_delete_post($duplicate_id,true);
            }
        }
        // Normalize migrated comments and instructor linkage after consolidation too.
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->comments} SET comment_agent=%s WHERE comment_post_ID=%d AND comment_type='tutor_course_rating'",
            'TutorLMSPlugin',$course_id
        ));
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->comments} SET comment_approved='approved' WHERE comment_post_ID=%d AND comment_type='tutor_course_rating' AND comment_approved='1'",
            $course_id
        ));
        $linked_course_ids=(array)get_user_meta($tutor_id,'_tutor_instructor_course_id',false);
        $linked_course_ids=array_map('absint',$linked_course_ids);
        if(!in_array($course_id,$linked_course_ids,true)) add_user_meta($tutor_id,'_tutor_instructor_course_id',$course_id);
        return $course_id;
    }

    private function get_private_tutor_rating($tutor_id,$student_id=0){
        $course_id=$this->get_or_create_rating_course($tutor_id);
        if(!$course_id) return null;
        $student_id=$student_id?absint($student_id):get_current_user_id();
        if(!$student_id) return null;
        $comment=$this->wpdb->get_row($this->wpdb->prepare(
            "SELECT c.comment_ID,c.comment_content,c.comment_approved,cm.meta_value AS rating FROM {$this->wpdb->comments} c LEFT JOIN {$this->wpdb->commentmeta} cm ON cm.comment_id=c.comment_ID AND cm.meta_key='tutor_rating' WHERE c.comment_post_ID=%d AND c.user_id=%d AND c.comment_type='tutor_course_rating' AND c.comment_approved NOT IN ('trash','spam') ORDER BY c.comment_ID DESC LIMIT 1",
            $course_id,$student_id
        ));
        if(!$comment) return null;
        return [
            'comment_id'=>(int)$comment->comment_ID,
            'rating'=>max(1,min(5,(int)$comment->rating)),
            'review'=>(string)$comment->comment_content,
            'status'=>(string)$comment->comment_approved,
            'course_id'=>$course_id,
        ];
    }

    private function student_can_rate_tutor_for_session($session_id,$student_id,$row=null){
        $session_id=absint($session_id); $student_id=absint($student_id);
        if(!$session_id||!$student_id) return false;
        if(!$row) $row=$this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->table(self::TABLE_SESSIONS)} WHERE id=%d AND (student_id=%d OR learner_user_id=%d) LIMIT 1",$session_id,$student_id,$student_id));
        if(!$row) return false;
        if(!(int)$this->settings()['allow_private_ratings']) return false;
        if((int)$row->is_tryout) return false;
        $is_booking_account = (int)$row->student_id === $student_id;
        $is_learner_account = !empty($row->learner_user_id) && (int)$row->learner_user_id === $student_id;
        if(!$is_booking_account && !$is_learner_account) return false;
        if(class_exists('TLS_Age_Safety') && TLS_Age_Safety::is_restricted_student($student_id)) {
            if(!class_exists('TLS_Feedback_Moderation') || !TLS_Feedback_Moderation::can_child_submit_feedback($student_id)) return false;
            if(!$is_learner_account && !empty($row->learner_user_id) && (int)$row->learner_user_id !== $student_id) return false;
            if($is_learner_account && (int)$row->student_id !== (int)TLS_Feedback_Moderation::approved_guardian_for_child($student_id)) return false;
        }
        if('completed'!== (string)$row->status) return false;
        return !empty($row->final_access_sent_at);
    }

    public function ajax_get_tutor_rating(){
        check_ajax_referer(self::NONCE,'nonce');
        if(!is_user_logged_in()) wp_send_json_error(['message'=>__('Please log in.','tutor-learning-suite')],401);
        $student_id=get_current_user_id(); $session_id=absint($_POST['session_id']??0);
        $row=$this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->table(self::TABLE_SESSIONS)} WHERE id=%d AND (student_id=%d OR learner_user_id=%d) LIMIT 1",$session_id,$student_id,$student_id));
        if(!$this->student_can_rate_tutor_for_session($session_id,$student_id,$row)) wp_send_json_error(['message'=>__('You can rate this tutor after the session has been marked completed.','tutor-learning-suite')],400);
        $rating=$this->get_private_tutor_rating((int)$row->tutor_id,$student_id);
        wp_send_json_success([
            'rating'=>(int)($rating['rating']??0),
            'review'=>(string)($rating['review']??''),
            'pending'=>!empty($rating) && !in_array((string)$rating['status'],['1','approved'],true),
            'guardian_pending'=>!empty($rating['comment_id']) && class_exists('TLS_Feedback_Moderation') && 'guardian_pending' === get_comment_meta((int)$rating['comment_id'], TLS_Feedback_Moderation::STAGE_META, true),
        ]);
    }

    public function ajax_submit_tutor_rating(){
        try {
            $this->ajax_submit_tutor_rating_request();
        } catch ( Throwable $error ) {
            // Never log review text, names, emails or submitted form fields.
            error_log( '[Tutor Learning Suite] Private-session review submission failed: ' . get_class( $error ) . ' in ' . basename( $error->getFile() ) . ':' . (int) $error->getLine() );
            wp_send_json_error(
                array( 'message' => __( 'Your review could not be submitted because of a server error. Please try again later or contact the site administrator with reference TLS-REVIEW-500.', 'tutor-learning-suite' ) ),
                500
            );
        }
    }

    private function ajax_submit_tutor_rating_request(){
        check_ajax_referer(self::NONCE,'nonce');
        if(!is_user_logged_in()) wp_send_json_error(['message'=>__('Please log in.','tutor-learning-suite')],401);
        $student_id=get_current_user_id(); $session_id=absint($_POST['session_id']??0); $rating=absint($_POST['rating']??0);
        $review=wp_kses_post(wp_unslash($_POST['review']??''));
        if($rating<1||$rating>5) wp_send_json_error(['message'=>__('Please choose a rating from 1 to 5 stars.','tutor-learning-suite')],400);
        $row=$this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->table(self::TABLE_SESSIONS)} WHERE id=%d AND (student_id=%d OR learner_user_id=%d) LIMIT 1",$session_id,$student_id,$student_id));
        if(!$this->student_can_rate_tutor_for_session($session_id,$student_id,$row)) wp_send_json_error(['message'=>__('You can rate this tutor after the session has been marked completed.','tutor-learning-suite')],400);
        $course_id=$this->get_or_create_rating_course((int)$row->tutor_id);
        if(!$course_id) wp_send_json_error(['message'=>__('Could not prepare the Tutor LMS rating record. Please contact the site administrator.','tutor-learning-suite')],500);

        // A child may submit feedback for a completed session assigned to them.
        // The child remains the recorded account author, but the public label is
        // privacy-safe and the feedback enters the guardian-first moderation queue.
        $is_child_feedback = class_exists('TLS_Age_Safety') && TLS_Age_Safety::is_restricted_student($student_id);
        $is_guardian_review = !$is_child_feedback && !empty($row->learner_user_id) && absint($row->learner_user_id) !== $student_id;
        $tu = get_userdata($student_id);
        $comment_author = $is_child_feedback ? __('Learner','tutor-learning-suite') : ($tu ? wp_html_excerpt($tu->display_name, 220, '') : '');
        if($is_guardian_review) $comment_author .= ' ('.__('Parent/guardian','tutor-learning-suite').')';
        $comment_author_email = $is_child_feedback ? '' : ($tu ? $tu->user_email : '');

        $existing=$this->wpdb->get_row($this->wpdb->prepare("SELECT comment_ID FROM {$this->wpdb->comments} WHERE comment_post_ID=%d AND user_id=%d AND comment_type='tutor_course_rating' AND comment_approved NOT IN ('trash','spam') ORDER BY comment_ID DESC LIMIT 1",$course_id,$student_id));
        $date=current_time('mysql');
        if($existing){
            $comment_id=(int)$existing->comment_ID;
            $updated=$this->wpdb->update($this->wpdb->comments,[
                'comment_author'=>$comment_author,
                'comment_author_email'=>$comment_author_email,
                'comment_content'=>$review,
                'comment_date'=>$date,
                'comment_date_gmt'=>get_gmt_from_date($date),
                'comment_agent'=>'TutorLMSPlugin',
                'comment_approved'=>'0',
            ],['comment_ID'=>$comment_id],['%s','%s','%s','%s','%s','%s','%s'],['%d']);
            if($updated===false) wp_send_json_error(['message'=>__('Could not update your rating. Please try again.','tutor-learning-suite')],500);
            update_comment_meta($comment_id,'tutor_rating',$rating);
            update_comment_meta($comment_id,'_tlms_ps_private_session_id',$session_id);
            update_comment_meta($comment_id,'_tlms_ps_private_tutor_id',(int)$row->tutor_id);
            update_comment_meta($comment_id,'_tlms_ps_private_rating',1);
            update_comment_meta($comment_id,'_tls_review_author_context',$is_guardian_review?'guardian':'student');
            update_comment_meta($comment_id,'_tls_review_author_account_id',$student_id);
            update_comment_meta($comment_id,'_tls_review_learner_user_id',$is_guardian_review?absint($row->learner_user_id):$student_id);
            if($is_child_feedback && class_exists('TLS_Feedback_Moderation')) { TLS_Feedback_Moderation::stage_comment($comment_id,$student_id,'private_session_review','hold',true); }
            if($is_guardian_review && class_exists('TLS_Age_Safety')) {
                TLS_Age_Safety::audit('guardian_private_tutor_review_submitted',$student_id,['session_id'=>$session_id,'tutor_id'=>(int)$row->tutor_id,'learner_id'=>absint($row->learner_user_id)]);
            }
            do_action('tutor_before_rating_placed');
            do_action('tutor_after_rating_placed',$comment_id);
            wp_send_json_success(['message'=>$is_child_feedback?__('Your review was sent to your parent or guardian. After they approve it, the administrator will review it before publication.','tutor-learning-suite'):__('Your rating was updated and submitted for admin approval.','tutor-learning-suite'),'rating'=>$rating,'review'=>$review,'pending'=>true,'guardian_pending'=>$is_child_feedback]);
        }

        $comment_id=wp_new_comment([
            'comment_post_ID'=>$course_id,
            'comment_author'=>$comment_author,
            'comment_author_email'=>$comment_author_email,
            'comment_author_url'=>'',
            'comment_content'=>$review,
            'comment_type'=>'tutor_course_rating',
            'comment_agent'=>'TutorLMSPlugin',
            'user_id'=>$student_id,
            'comment_date'=>$date,
            'comment_date_gmt'=>get_gmt_from_date($date),
            'comment_approved'=>'0',
        ],true);
        if(is_wp_error($comment_id)) wp_send_json_error(['message'=>__('Could not submit your rating. Please try again.','tutor-learning-suite')],500);
        $comment_id=(int)$comment_id;
        add_comment_meta($comment_id,'tutor_rating',$rating,true);
        add_comment_meta($comment_id,'_tlms_ps_private_session_id',$session_id,true);
        add_comment_meta($comment_id,'_tlms_ps_private_tutor_id',(int)$row->tutor_id,true);
        add_comment_meta($comment_id,'_tlms_ps_private_rating',1,true);
        add_comment_meta($comment_id,'_tls_review_author_context',$is_guardian_review?'guardian':'student',true);
        add_comment_meta($comment_id,'_tls_review_author_account_id',$student_id,true);
        add_comment_meta($comment_id,'_tls_review_learner_user_id',$is_guardian_review?absint($row->learner_user_id):$student_id,true);
        if($is_child_feedback && class_exists('TLS_Feedback_Moderation')) { TLS_Feedback_Moderation::stage_comment($comment_id,$student_id,'private_session_review','hold',true); }
        if($is_guardian_review && class_exists('TLS_Age_Safety')) {
            TLS_Age_Safety::audit('guardian_private_tutor_review_submitted',$student_id,['session_id'=>$session_id,'tutor_id'=>(int)$row->tutor_id,'learner_id'=>absint($row->learner_user_id)]);
        }
        do_action('tutor_before_rating_placed');
        do_action('tutor_after_rating_placed',$comment_id);
        wp_send_json_success(['message'=>$is_child_feedback?__('Your review was sent to your parent or guardian. After they approve it, the administrator will review it before publication.','tutor-learning-suite'):__('Your rating was submitted and is waiting for admin approval.','tutor-learning-suite'),'rating'=>$rating,'review'=>$review,'pending'=>true,'guardian_pending'=>$is_child_feedback]);
    }

    /**
     * Keep private-session rating rows compatible with Tutor LMS native rating
     * queries. Tutor's instructor rating query matches comment_approved='approved'.
     */
    private function normalize_private_rating_statuses($course_id=0){
        $course_id=absint($course_id);
        if(!$course_id) return;
        $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->wpdb->comments} SET comment_approved='approved' WHERE comment_post_ID=%d AND comment_type='tutor_course_rating' AND comment_approved='1'",
            $course_id
        ));
    }

    public function protect_rating_course(){
        if(is_admin() || current_user_can('manage_options')) return;
        $post=get_queried_object();
        if($post instanceof WP_Post && get_post_meta($post->ID,'_tlms_ps_rating_course',true)){
            wp_safe_redirect(home_url('/'),302); exit;
        }
    }

    public function update_session() {
        if(!is_user_logged_in() || !$this->is_instructor(get_current_user_id()) || !check_admin_referer('tlms_ps_update_session','_tlms_ps_session_nonce')) wp_die('Permission denied.');
        $id=absint($_POST['session_id']??0); $row=$this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->table(self::TABLE_SESSIONS)} WHERE id=%d",$id)); if(!$row || (int)$row->tutor_id!==get_current_user_id()) wp_die('Session not found.');
        $url=esc_url_raw(wp_unslash($_POST['meeting_url']??''));
        $details=sanitize_textarea_field(wp_unslash($_POST['meeting_details']??''));
        $this->wpdb->update($this->table(self::TABLE_SESSIONS),['meeting_url'=>$url,'meeting_details'=>$details,'final_access_sent_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')],['id'=>$id],['%s','%s','%s'],['%d']);
        wp_safe_redirect(wp_get_referer() ?: $this->dashboard_url('private-sessions')); exit;
    }

    public function shortcode_booking($atts) {
        $atts=shortcode_atts(['tutor_id'=>0],$atts,'tlms_private_booking');
        $tutor_id=absint($atts['tutor_id']?:($_GET['tutor_id']??0));
        $resubmit_id=absint($_GET['resubmit_id']??0);
        if($resubmit_id && is_user_logged_in()){
            $old=$this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->table(self::TABLE_SESSIONS)} WHERE id=%d AND student_id=%d AND status IN ('declined','rejected')",$resubmit_id,get_current_user_id()));
            if(!$old) return '<div class="tlms-ps-message">'.esc_html__('This request cannot be resubmitted. Start a new request from the tutor profile.','tutor-learning-suite').'</div>';
            $tutor_id=(int)$old->tutor_id;
        }
        if(!$tutor_id || !$this->is_instructor($tutor_id)) return '<div class="tlms-ps-message">'.esc_html__('Invalid tutor.','tutor-learning-suite').'</div>';
        if(!$this->tutor_accepts_private_sessions($tutor_id)) return '<div class="tlms-ps-message">'.esc_html__('This tutor is not accepting private-session requests.','tutor-learning-suite').'</div>';
        if(!is_user_logged_in()) return '<div class="tlms-ps-message">'.esc_html__('Please log in to request a private tutoring session.','tutor-learning-suite').'</div>';
        $current_user_id = get_current_user_id();
        if ( class_exists('TLS_Age_Safety') && TLS_Age_Safety::is_restricted_student($current_user_id) ) {
            return '<div class="tlms-ps-message">'.esc_html__('Private tutoring requests for this student account must be submitted by an approved parent or legal guardian from their own account.','tutor-learning-suite').'</div>';
        }
        $booking_calendar_markup = '';
        $booking_requires_calendar = ! empty( $GLOBALS['tls_profile_booking_context'] );
        if ( ! $booking_requires_calendar && shortcode_exists( 'tutor_suite_tutor_calendar' ) ) {
            $booking_calendar_markup = do_shortcode( '[tutor_suite_tutor_calendar tutor_id="' . (int) $tutor_id . '"]' );
            $booking_requires_calendar = '' !== trim( $booking_calendar_markup );
        }
        $user=get_userdata($current_user_id); $subject_choices=$this->subject_choices_for_tutor($tutor_id); $settings=$this->settings();
        // Tutor LMS profile phone is stored in user meta. Prefer Tutor's documented phone_number key,
        // then support common legacy/custom profile keys so existing students keep their number.
        $profile_phone=$this->get_profile_phone($user->ID);
        $tls_is_guardian_only = '1' === (string) get_user_meta($current_user_id, '_tls_is_guardian', true)
            && ( ! class_exists('TLS_Age_Safety') || ! TLS_Age_Safety::is_tutor_student($current_user_id) );
        $tls_learners = get_users(['meta_key'=>'_tls_guardian_user_id','meta_value'=>$current_user_id,'fields'=>['ID','display_name']]);
        $tls_learners = array_values(array_filter((array)$tls_learners,static function($u) use ($current_user_id){
            return is_object($u) && ! empty($u->ID) && class_exists('TLS_Age_Safety') && TLS_Age_Safety::can_parent_book($current_user_id, (int) $u->ID);
        }));
        if ( $tls_is_guardian_only && ! $tls_learners ) {
            return '<div class="tlms-ps-message">'.esc_html__('No linked learner is currently approved for bookings. Complete the Family & Guardian review and obtain the student’s required booking permission first.','tutor-learning-suite').'</div>';
        }
        // Keep settings available for all request-form branches.
        $currency=(string)($settings['currency']??'DZD');
        $pref=['student_name'=>$user->display_name,'student_email'=>$user->user_email,'student_phone'=>$profile_phone,'learner_user_id'=>0,'teach_category_id'=>'','goal'=>'','notes'=>'','type'=>'online','session_type'=>'one_to_one','request_tryout'=>0];
        if(!empty($old) && (int)$old->student_id===get_current_user_id()) $pref=array_merge($pref,['student_name'=>$old->student_name,'student_email'=>$old->student_email,'student_phone'=>$old->student_phone,'learner_user_id'=>(int)($old->learner_user_id??0),'teach_category_id'=>(int)$old->teach_category_id,'goal'=>$old->goal,'notes'=>$old->notes,'type'=>$old->tutoring_type,'session_type'=>isset($old->session_type)?$old->session_type:'one_to_one','request_tryout'=>(int)$old->is_tryout]);
        $tp=$this->tutor_private_settings($tutor_id); $user_tutor=get_userdata($tutor_id); 
        ob_start(); ?>
        <div class="tlms-ps-booking tlms-ps-request-booking" data-tutor-id="<?php echo (int)$tutor_id;?>">
          <?php if($booking_calendar_markup):?><div class="tlms-ps-embedded-calendar"><?php echo $booking_calendar_markup;?></div><?php endif;?>
          <div class="tlms-ps-card tlms-ps-booking-card"><div class="tlms-ps-booking-title"><div><?php echo get_avatar($tutor_id,64);?></div><div><h2><?php echo esc_html($user_tutor?$user_tutor->display_name:'Tutor');?></h2><p><?php esc_html_e('Send a private tutoring request','tutor-learning-suite');?></p></div></div>
          <details class="tlms-ps-process"><summary class="tlms-ps-process-title"><i class="tutor-icon-info-circle" aria-hidden="true"></i><strong><?php esc_html_e('How private tutoring requests work','tutor-learning-suite');?></strong><span><?php esc_html_e('No charge when you send a request.','tutor-learning-suite');?></span></summary><div class="tlms-ps-process-steps"><div><b>1</b><strong><?php esc_html_e('Choose your learner and a real available time','tutor-learning-suite');?></strong><span><?php esc_html_e('Choose your learner and subject in this form. Select the format, teaching method, session length and preferred time in the calendar above.','tutor-learning-suite');?></span></div><div><b>2</b><strong><?php esc_html_e('Review the tutor’s proposal','tutor-learning-suite');?></strong><span><?php esc_html_e('The tutor confirms the final session time, duration and price.','tutor-learning-suite');?></span></div><div><b>3</b><strong><?php esc_html_e('Pay the tutor directly','tutor-learning-suite');?></strong><span><?php esc_html_e('Accept the proposal, follow the tutor’s payment instructions and declare payment for confirmation.','tutor-learning-suite');?></span></div></div></details>
          <div class="tlms-ps-grid tlms-ps-grid-2"><?php if($tls_learners || $tls_is_guardian_only):?><label><span><?php esc_html_e('Who will take this session?','tutor-learning-suite');?></span><select name="learner_user_id" required><?php if($tls_is_guardian_only):?><option value="0" disabled <?php selected(empty($pref['learner_user_id']));?>><?php esc_html_e('Select an approved learner','tutor-learning-suite');?></option><?php else:?><option value="0" <?php selected((int)$pref['learner_user_id'],0);?>><?php esc_html_e('Myself','tutor-learning-suite');?></option><?php endif;?><?php foreach($tls_learners as $tls_learner):?><option value="<?php echo (int)$tls_learner->ID;?>" <?php selected((int)$pref['learner_user_id'],(int)$tls_learner->ID);?>><?php echo esc_html($tls_learner->display_name);?></option><?php endforeach;?></select></label><?php endif;?><label><span><?php echo $tls_is_guardian_only ? esc_html__('Parent/guardian contact name','tutor-learning-suite') : esc_html__('Your name','tutor-learning-suite');?></span><input type="text" name="student_name" value="<?php echo esc_attr($pref['student_name']);?>" maxlength="190" required></label><label><span><?php esc_html_e('Contact email','tutor-learning-suite');?></span><input type="email" name="student_email" value="<?php echo esc_attr($pref['student_email']);?>" maxlength="190" required></label><label><span><?php esc_html_e('Contact phone','tutor-learning-suite');?></span><input type="tel" name="student_phone" value="<?php echo esc_attr($pref['student_phone']);?>" maxlength="60" autocomplete="tel" required></label><label><span><?php esc_html_e('Subject','tutor-learning-suite');?></span><select name="teach_category_id" class="tlms-ps-request-subject" required><option value=""><?php esc_html_e('Choose a subject','tutor-learning-suite');?></option><?php if(!$subject_choices):?><option value="" disabled><?php esc_html_e('The tutor has not added any teaching subjects yet.','tutor-learning-suite');?></option><?php endif;?><?php foreach($subject_choices as $tid=>$choice):?><option value="<?php echo (int)$tid;?>" <?php selected((int)$pref['teach_category_id'],(int)$tid);?>><?php echo esc_html($choice['name']);?></option><?php endforeach;?></select></label><?php if(!empty($this->tutor_private_settings($tutor_id)['offer_tryout'])): $tryout_cfg=$this->tutor_private_settings($tutor_id);?><label class="tlms-ps-tryout-option" style="grid-column:1/-1"><span class="tlms-ps-check-row"><input type="checkbox" name="request_tryout" value="1" data-tryout-duration="<?php echo (int)$tryout_cfg['tryout_duration'];?>" <?php checked(!empty($pref['request_tryout']));?>><strong><?php printf(esc_html__('Request a free %s tryout session','tutor-learning-suite'),esc_html($this->format_duration_minutes((int)$tryout_cfg['tryout_duration'])));?></strong></span><small><?php esc_html_e('The tutor will directly approve or reject this tryout request. No payment is required.','tutor-learning-suite');?></small></label><?php endif;?></div>
          <details class="tlms-ps-request-extra"><summary><?php esc_html_e('Add learning goals or a message (optional)','tutor-learning-suite');?></summary><div class="tlms-ps-request-extra-fields"><label class="tlms-ps-goal"><span><?php esc_html_e('What is your learning goal?','tutor-learning-suite');?></span><textarea name="goal" rows="3" maxlength="2000" placeholder="<?php echo esc_attr__('What do you want to learn or improve?','tutor-learning-suite');?>"><?php echo esc_textarea($pref['goal']);?></textarea></label><label class="tlms-ps-additional-info"><span><?php esc_html_e('Message for the tutor','tutor-learning-suite');?></span><textarea name="notes" rows="3" maxlength="4000" placeholder="<?php echo esc_attr__('Share your level or anything the tutor should know.','tutor-learning-suite');?>"><?php echo esc_textarea($pref['notes']);?></textarea></label></div></details>
          <?php if((float)$tp['hourly_rate']>0 && empty($GLOBALS['tls_public_instructor_id'])):?><div class="tlms-ps-hourly-rate"><span><?php esc_html_e('Hourly rate','tutor-learning-suite');?></span><strong><?php echo esc_html(number_format((float)$tp['hourly_rate'],2).' '.$currency);?> / <?php esc_html_e('hour','tutor-learning-suite');?></strong></div><?php endif;?>
          <div class="tlms-ps-request-notice"><strong><?php esc_html_e('No payment yet','tutor-learning-suite');?></strong><span><?php esc_html_e('This sends a request only. Your preferred time is not reserved or held, and another learner may request the same slot until the tutor sends a proposal. The tutor confirms the final schedule and price in that proposal. No payment is collected here.','tutor-learning-suite');?></span></div>
          <div class="tlms-ps-policy-box tlms-ps-policy-required"><div class="tlms-ps-policy-heading"><i class="tutor-icon-info-circle" aria-hidden="true"></i><strong><?php esc_html_e('Before sending your request','tutor-learning-suite');?></strong><span><?php esc_html_e('Please read both policies and confirm that you agree.','tutor-learning-suite');?></span></div><?php if($tp['terms']):?><details><summary><?php esc_html_e('Tutor terms & conditions','tutor-learning-suite');?></summary><div><?php echo nl2br(esc_html($tp['terms']));?></div></details><?php endif;?><?php if($tp['cancellation_policy']):?><details><summary><?php esc_html_e('Tutor cancellation policy','tutor-learning-suite');?></summary><div><?php echo nl2br(esc_html($tp['cancellation_policy']));?></div></details><?php endif;?><label class="tlms-ps-policy-check"><input type="checkbox" name="terms_accepted" value="1" required> <span><?php esc_html_e('I have read and agree to the tutor’s terms & conditions.','tutor-learning-suite');?></span></label><label class="tlms-ps-policy-check"><input type="checkbox" name="cancellation_accepted" value="1" required> <span><?php esc_html_e('I have read and agree to the tutor’s cancellation policy.','tutor-learning-suite');?></span></label></div>
          <input type="hidden" name="type" value="<?php echo esc_attr($pref['type']);?>"><input type="hidden" name="session_type" value="<?php echo esc_attr($pref['session_type']);?>"><input type="hidden" name="preferred_date" value=""><input type="hidden" name="preferred_start" value=""><input type="hidden" name="preferred_end" value=""><input type="hidden" name="preferred_duration" value=""><input type="hidden" name="preferred_timezone" value=""><input type="hidden" name="tutor_id" value="<?php echo (int)$tutor_id;?>"><input type="hidden" name="resubmitted_from_id" value="<?php echo (int)$resubmit_id;?>"><button type="button" class="tutor-btn tutor-btn-primary tlms-ps-send-request-btn"><?php echo $resubmit_id?esc_html__('Send new request','tutor-learning-suite'):esc_html__('Send request to tutor','tutor-learning-suite');?></button><div class="tlms-ps-result" aria-live="polite"></div>
          </div>
        </div>
        <?php return ob_get_clean();
    }

    public function render_dashboard($user_id) {
        $user_id = absint($user_id);
        // Keep the tutor management page separate from the learner page so an instructor
        // who also takes lessons can access their own private-session requests.
        if (get_query_var('tutor_dashboard_page') === 'my-private-sessions') {
            return $this->render_student_dashboard($user_id);
        }
        return $this->is_instructor($user_id) ? $this->render_instructor_dashboard($user_id) : $this->render_student_dashboard($user_id);
    }

    private function render_student_dashboard($student_id) {
        $rows=$this->wpdb->get_results($this->wpdb->prepare("SELECT * FROM {$this->table(self::TABLE_SESSIONS)} WHERE student_id=%d OR learner_user_id=%d ORDER BY id DESC LIMIT 100",$student_id,$student_id));
        $pending=(int)$this->wpdb->get_var($this->wpdb->prepare("SELECT COUNT(*) FROM {$this->table(self::TABLE_SESSIONS)} WHERE student_id=%d AND status='pending_tutor'",$student_id));
        $proposals=(int)$this->wpdb->get_var($this->wpdb->prepare("SELECT COUNT(*) FROM {$this->table(self::TABLE_SESSIONS)} WHERE student_id=%d AND status='proposal_sent'",$student_id));
        $paid=(int)$this->wpdb->get_var($this->wpdb->prepare("SELECT COUNT(*) FROM {$this->table(self::TABLE_SESSIONS)} WHERE student_id=%d AND status IN ('paid','confirmed')",$student_id));
        ob_start(); ?>
        <div class="tlms-ps-panel tlms-ps-student-panel"><div class="tlms-ps-head"><div><h2 class="tlms-ps-page-title"><i class="tutor-icon-calendar-line" aria-hidden="true"></i><?php esc_html_e('My Private Sessions','tutor-learning-suite');?></h2><p><?php esc_html_e('Requests, tutor proposals, payment and final lesson details — all in one place.','tutor-learning-suite');?></p></div></div>
          <div class="tlms-ps-process"><div class="tlms-ps-process-title"><i class="tutor-icon-info-circle" aria-hidden="true"></i><strong><?php esc_html_e('How private sessions work','tutor-learning-suite');?></strong><span><?php esc_html_e('From your request to your confirmed lesson.','tutor-learning-suite');?></span></div><div class="tlms-ps-process-steps"><div><b>1</b><strong><?php esc_html_e('Send your request','tutor-learning-suite');?></strong><span><?php esc_html_e('Select your session format, teaching method, duration and an available preferred time in the calendar.','tutor-learning-suite');?></span></div><div><b>2</b><strong><?php esc_html_e('Review the proposal','tutor-learning-suite');?></strong><span><?php esc_html_e('Your tutor replies with an agreed schedule and price.','tutor-learning-suite');?></span></div><div><b>3</b><strong><?php esc_html_e('Pay the tutor directly','tutor-learning-suite');?></strong><span><?php esc_html_e('Accept the proposal, pay the tutor using their chosen method, and declare payment for confirmation.','tutor-learning-suite');?></span></div></div></div>
          <div class="tlms-ps-stat-grid"><div class="tlms-ps-stat"><span><?php esc_html_e('Waiting for tutor','tutor-learning-suite');?></span><strong><?php echo (int)$pending;?></strong></div><div class="tlms-ps-stat"><span><?php esc_html_e('Proposals to review','tutor-learning-suite');?></span><strong><?php echo (int)$proposals;?></strong></div><div class="tlms-ps-stat"><span><?php esc_html_e('Paid / confirmed','tutor-learning-suite');?></span><strong><?php echo (int)$paid;?></strong></div></div>
          <div class="tlms-ps-focus-banner <?php echo $proposals ? 'is-attention' : 'is-clear'; ?>">
            <span class="tlms-ps-focus-icon" aria-hidden="true"><i class="tutor-icon-info-circle"></i></span>
            <div class="tlms-ps-focus-copy">
              <?php if ($proposals) : ?>
                <strong><?php printf(esc_html(_n('You have %d tutor proposal to review','You have %d tutor proposals to review',$proposals,'tutor-learning-suite')),(int)$proposals); ?></strong>
                <span><?php esc_html_e('Compare the proposed schedule and price before accepting.','tutor-learning-suite'); ?></span>
              <?php elseif ($pending) : ?>
                <strong><?php esc_html_e('Your request is with the tutor','tutor-learning-suite'); ?></strong>
                <span><?php esc_html_e('You will see the tutor’s proposal here when they respond.','tutor-learning-suite'); ?></span>
              <?php else : ?>
                <strong><?php esc_html_e('Your private sessions are up to date','tutor-learning-suite'); ?></strong>
                <span><?php esc_html_e('Your proposals, payments and confirmed lesson details will appear below.','tutor-learning-suite'); ?></span>
              <?php endif; ?>
            </div>
            <?php if ($proposals) : ?><a class="tlms-ps-focus-action" href="#tlms-ps-student-requests"><?php esc_html_e('Review proposals','tutor-learning-suite'); ?> <span aria-hidden="true">↓</span></a><?php endif; ?>
          </div>
          <?php if(!$rows):?><div class="tlms-ps-card tlms-ps-empty-state"><div class="tlms-ps-empty-icon">✦</div><h3><?php esc_html_e('No private tutoring requests yet','tutor-learning-suite');?></h3><p><?php esc_html_e('Open a tutor profile and choose Book Private Session to send your first request.','tutor-learning-suite');?></p></div><?php else: ?><div id="tlms-ps-student-requests" class="tlms-ps-student-request-list"><?php foreach($rows as $r):$tu=get_userdata((int)$r->tutor_id);?>
            <div class="tlms-ps-student-request-card"><div class="tlms-ps-student-request-head"><div class="tlms-ps-avatar"><?php echo get_avatar((int)$r->tutor_id,48, '', '', ['class'=>'tlms-ps-avatar-img']);?></div><div><h3><?php echo $tu ? $this->public_profile_link($tu,__('Tutor','tutor-learning-suite')) : esc_html__('Tutor','tutor-learning-suite');?></h3><p><?php echo esc_html($r->subject);?> · <?php echo esc_html($this->session_type_label($r->session_type??'one_to_one'));?> · <?php echo esc_html($this->tutoring_type_label($r->tutoring_type));?></p></div><span class="tlms-ps-status tlms-ps-status-<?php echo esc_attr($r->status);?>"><?php echo esc_html($this->status_label($r->status));?></span><?php if((int)$r->is_tryout):?><span class="tlms-ps-tryout-badge">TRYOUT</span><?php endif;?></div>
              <div class="tlms-ps-student-meta"><div><span><?php esc_html_e('Requested session','tutor-learning-suite');?></span><strong><?php echo esc_html($this->format_duration_minutes((int)($r->requested_duration_minutes ?: ($r->duration_minutes ?: 60))));?></strong></div><div><span><?php esc_html_e('Requested slot','tutor-learning-suite');?></span><strong><?php echo esc_html($this->format_requested_slot_for_user($r,$student_id));?></strong></div><?php if($r->session_date):?><div><span><?php esc_html_e('Proposed date','tutor-learning-suite');?></span><strong><?php echo esc_html($r->session_date);?></strong></div><?php endif;?><?php if($r->price>0):?><div><span><?php esc_html_e('Price','tutor-learning-suite');?></span><strong><?php echo esc_html(number_format((float)$r->price,2).' '.$r->currency);?></strong></div><?php endif;?></div>
              <?php if($r->goal):?><div class="tlms-ps-student-goal"><span><?php esc_html_e('Goal','tutor-learning-suite');?></span><p><?php echo nl2br(esc_html($r->goal));?></p></div><?php endif;?>
              <?php if($r->status==='proposal_sent'):?>
                <div class="tlms-ps-proposal-student">
                  <div class="tlms-ps-confirmed-summary-head"><strong><?php esc_html_e('Tutor proposal','tutor-learning-suite');?></strong><span><?php esc_html_e('Review the agreed schedule and price before accepting.','tutor-learning-suite');?></span></div>
                  <div class="tlms-ps-confirmed-summary-grid">
                    <div><span><?php esc_html_e('Date','tutor-learning-suite');?></span><strong><?php echo $r->session_date ? esc_html(wp_date(get_option('date_format'),(new DateTimeImmutable($r->session_date.' 00:00:00',new DateTimeZone(!empty($r->session_timezone)?$r->session_timezone:$this->user_timezone_string($r->tutor_id))))->getTimestamp(),new DateTimeZone($this->user_timezone_string($student_id)))) : esc_html__('—','tutor-learning-suite');?></strong></div>
                    <div><span><?php esc_html_e('Starting time','tutor-learning-suite');?></span><strong><?php echo $r->start_time ? esc_html($this->format_session_datetime_for_user($r,$student_id,true)) : esc_html__('—','tutor-learning-suite');?></strong></div>
                    <div><span><?php esc_html_e('Duration','tutor-learning-suite');?></span><strong><?php echo esc_html($this->format_duration_minutes((int)$r->duration_minutes));?></strong></div>
                    <div><span><?php esc_html_e('Price','tutor-learning-suite');?></span><strong><?php echo esc_html(number_format((float)$r->price,2).' '.$r->currency);?></strong></div>
                    <div><span><?php esc_html_e('Session format','tutor-learning-suite');?></span><strong><?php echo esc_html($this->session_type_label($r->session_type??'one_to_one'));?></strong></div><div><span><?php esc_html_e('Teaching method','tutor-learning-suite');?></span><strong><?php echo esc_html($this->tutoring_type_label($r->tutoring_type));?></strong></div>
                  </div>
                  <?php if($r->proposal_message):?><div class="tlms-ps-proposal-message"><?php echo nl2br(esc_html($r->proposal_message));?></div><?php endif;?>
                  <div class="tlms-ps-proposal-actions"><button type="button" class="tutor-btn tutor-btn-primary tlms-ps-add-cart-btn" data-session-id="<?php echo (int)$r->id;?>"><?php esc_html_e('Accept proposal','tutor-learning-suite');?></button><button type="button" class="button tlms-ps-decline-proposal" data-session-id="<?php echo (int)$r->id;?>"><?php esc_html_e('Decline & send new request','tutor-learning-suite');?></button></div>
                </div>
              <?php elseif($r->status==='approved' && $this->cart_checkout_available()): ?><div class="tlms-ps-approved-box"><strong><?php esc_html_e('Proposal accepted — complete payment at checkout','tutor-learning-suite');?></strong><p><?php printf(esc_html__('Amount: %1$s %2$s. Pay through the cart and checkout using the tutor\'s payment method.','tutor-learning-suite'),esc_html(number_format((float)$r->price,2)),esc_html($r->currency));?></p><button type="button" class="tutor-btn tutor-btn-primary tlms-ps-add-cart-btn" data-session-id="<?php echo (int)$r->id;?>"><?php esc_html_e('Go to cart & pay','tutor-learning-suite');?></button></div>
              <?php elseif($r->status==='approved'): $payment_methods=$this->get_tutor_offline_methods((int)$r->tutor_id); ?><div class="tlms-ps-approved-box"><strong><?php esc_html_e('Proposal accepted — pay the tutor directly','tutor-learning-suite');?></strong><p><?php printf(esc_html__('Agreed amount: %1$s %2$s. Ustudy does not collect or hold this payment.','tutor-learning-suite'),esc_html(number_format((float)$r->price,2)),esc_html($r->currency));?></p><?php if(!empty($r->payment_declared_at)):?><div class="tlms-ps-payment-waiting" role="status"><strong><?php esc_html_e('Payment declared','tutor-learning-suite');?></strong><p><?php esc_html_e('Your tutor has been notified. The booking becomes confirmed when the tutor confirms receipt.','tutor-learning-suite');?></p></div><?php elseif($payment_methods):?><div class="tlms-ps-payment-methods"><h4><?php esc_html_e('Tutor payment instructions','tutor-learning-suite');?></h4><?php foreach($payment_methods as $method):?><details class="tlms-ps-payment-method"><summary><?php echo esc_html($method['title']);?></summary><?php if(class_exists('TutorInstructorOfflinePayment\Methods')):$detail_rows=\TutorInstructorOfflinePayment\Methods::public_details($method);if($detail_rows):?><dl><?php foreach($detail_rows as $detail):?><div><dt><?php echo esc_html($detail['label']);?></dt><dd><?php echo esc_html($detail['value']);?></dd></div><?php endforeach;?></dl><?php endif;?><?php endif;?></details><?php endforeach;?></div><form class="tlms-ps-payment-declare-form" data-session-id="<?php echo (int)$r->id;?>"><label><span><?php esc_html_e('Payment method used','tutor-learning-suite');?></span><select name="payment_method_id" required><option value=""><?php esc_html_e('Choose a method','tutor-learning-suite');?></option><?php foreach($payment_methods as $method):?><option value="<?php echo esc_attr($method['id']);?>"><?php echo esc_html($method['title']);?></option><?php endforeach;?></select></label><label><span><?php esc_html_e('Reference (optional)','tutor-learning-suite');?></span><input type="text" name="payment_reference" maxlength="190" autocomplete="off"></label><button type="submit" class="tutor-btn tutor-btn-primary"><?php esc_html_e('I have paid the tutor','tutor-learning-suite');?></button><div class="tlms-ps-form-result" aria-live="polite"></div></form><?php else:?><p><?php esc_html_e('This tutor has not configured an active payment method. Contact the tutor through your booking thread before paying.','tutor-learning-suite');?></p><?php endif;?></div>
              <?php elseif(in_array($r->status,['paid','confirmed','completed'],true)):?>
                <div class="tlms-ps-final-box">
                  <div class="tlms-ps-final-info-title tlms-ps-session-cluster-title"><strong><?php esc_html_e('Final session information','tutor-learning-suite');?></strong></div>
                  <div class="tlms-ps-confirmed-summary-grid">
                    <?php $student_tz = new DateTimeZone($this->user_timezone_string($student_id)); $source_tz = new DateTimeZone(!empty($r->session_timezone)?$r->session_timezone:$this->user_timezone_string($r->tutor_id)); $session_date_label = '—'; if (!empty($r->session_date)) { $date_obj = new DateTimeImmutable($r->session_date.' 00:00:00', $source_tz); $session_date_label = wp_date(get_option('date_format'), $date_obj->getTimestamp(), $student_tz); } ?>
                    <div><span><?php esc_html_e('Date','tutor-learning-suite');?></span><strong><?php echo esc_html($session_date_label);?></strong></div>
                    <div><span><?php esc_html_e('Starting time','tutor-learning-suite');?></span><strong><?php echo $r->start_time ? esc_html($this->format_session_datetime_for_user($r,$student_id,true)) : esc_html__('—','tutor-learning-suite');?></strong></div>
                    <div><span><?php esc_html_e('Duration','tutor-learning-suite');?></span><strong><?php echo esc_html($this->format_duration_minutes((int)$r->duration_minutes));?></strong></div>
                    <div><span><?php esc_html_e('Price','tutor-learning-suite');?></span><strong><?php echo esc_html(number_format((float)$r->price,2).' '.$r->currency);?></strong></div>
                    <div><span><?php esc_html_e('Session format','tutor-learning-suite');?></span><strong><?php echo esc_html($this->session_type_label($r->session_type??'one_to_one'));?></strong></div><div><span><?php esc_html_e('Teaching method','tutor-learning-suite');?></span><strong><?php echo esc_html($this->tutoring_type_label($r->tutoring_type));?></strong></div>
                  </div>
                  <?php if($r->meeting_url):?><a class="tutor-btn tutor-btn-primary" target="_blank" rel="noopener" href="<?php echo esc_url($r->meeting_url);?>"><?php esc_html_e('Open meeting link','tutor-learning-suite');?></a><?php endif;?>
                  <?php if($r->meeting_details):?><p><?php echo nl2br(esc_html($r->meeting_details));?></p><?php else:?><p class="tlms-ps-muted"><?php esc_html_e('The tutor has not sent the final access details yet.','tutor-learning-suite');?></p><?php endif;?>
                  <?php if((int)$this->settings()['allow_private_ratings'] && 'completed' === (string)$r->status && $r->final_access_sent_at && !(int)$r->is_tryout):?><div class="tlms-ps-rating-cta"><div><strong><?php esc_html_e('How was your tutoring experience?','tutor-learning-suite');?></strong><span><?php if(class_exists('TLS_Age_Safety') && TLS_Age_Safety::is_restricted_student($student_id)): esc_html_e('Your review will first go to your parent or guardian, then to the administrator before publication.','tutor-learning-suite'); else: esc_html_e('Your review will be checked by an administrator before publication.','tutor-learning-suite'); endif;?></span></div><button type="button" class="tutor-btn tutor-btn-primary tlms-ps-rate-tutor-btn" data-session-id="<?php echo (int)$r->id;?>" data-tutor-id="<?php echo (int)$r->tutor_id;?>"><?php esc_html_e('Rate tutor','tutor-learning-suite');?></button></div><?php endif;?>
                </div>
              <?php elseif($r->status==='declined'):?><div class="tlms-ps-declined-box"><strong><?php esc_html_e('You declined this proposal.','tutor-learning-suite');?></strong><?php if($r->student_response):?><p class="tlms-ps-decline-reason"><b><?php esc_html_e('Your reason:','tutor-learning-suite');?></b> <?php echo nl2br(esc_html($r->student_response));?></p><?php endif;?></div>
              <?php elseif($r->status==='pending_tutor' && (int)$r->is_tryout):?><p class="tlms-ps-muted"><?php esc_html_e('Your free tryout request has been sent. The tutor can directly approve or reject it. No payment is required.','tutor-learning-suite');?></p>
              <?php elseif($r->status==='pending_tutor'):?><p class="tlms-ps-muted"><?php echo $r->student_response ? esc_html__('Your tutor is reviewing your reason and can send you a new proposal for the same request. You do not need to send another request.','tutor-learning-suite') : esc_html__('Your request has been sent. The tutor will reply with the proposed schedule and price.','tutor-learning-suite');?></p>
              <?php endif;?>
              <?php if((int)$r->student_id===(int)$student_id && in_array((string)$r->status,['pending_tutor','pending_admin','proposal_sent','approved','paid','confirmed'],true)):?><div class="tlms-ps-cancel-row"><button type="button" class="button tlms-ps-cancel-session" data-session-id="<?php echo (int)$r->id;?>"><?php esc_html_e('Cancel booking','tutor-learning-suite');?></button><small><?php esc_html_e('Any payment already made is settled directly between you and the tutor. Ustudy does not handle refunds.','tutor-learning-suite');?></small></div><?php endif;?></div>
          <?php endforeach; ?></div><?php endif;?></div><?php return ob_get_clean();
    }

    public function shortcode_dashboard() {
        if(!is_user_logged_in()) return '<div class="tlms-ps-message">'.esc_html__('Please log in to view your sessions.','tutor-learning-suite').'</div>';
        return $this->render_dashboard(get_current_user_id());
    }

    private function tutor_tutoring_options($tutor_id){
        $saved=(array)get_user_meta($tutor_id,'_tlms_ps_tutoring_options',true);
        $defaults=['one_to_one_online'=>1,'one_to_one_tutor_home'=>1,'one_to_one_student_home'=>0,'group_online'=>0,'group_tutor_home'=>0];
        $out=[];
        foreach($defaults as $key=>$enabled) $out[$key]=array_key_exists($key,$saved)?(int)(bool)$saved[$key]:(int)$enabled;
        // Never permit the unsupported group-at-student-home combination.
        return $out;
    }

    private function session_type_label($type) {
        return $type==='group' ? __('Group','tutor-learning-suite') : __('1-to-1','tutor-learning-suite');
    }

    private function tutoring_type_label($type) {
        $map=['online'=>__('Online','tutor-learning-suite'),'tutor_home'=>__('Offline at tutor’s home','tutor-learning-suite'),'student_home'=>__('Offline at student’s home','tutor-learning-suite')];
        return $map[$type]??ucwords(str_replace('_',' ',$type));
    }

    /**
     * Tutor LMS stores an individual timezone in _tutor_timezone. Use it when available;
     * otherwise use the requested fixed fallback for this private-session feature.
     */
    private function user_timezone_string($user_id) {
        $user_id = absint($user_id);
        $tz = '';
        if ($user_id && class_exists('TUTOR\\User') && method_exists('TUTOR\\User', 'get_user_timezone_string')) {
            try { $tz = (string) \TUTOR\User::get_user_timezone_string($user_id); } catch (\Throwable $e) { $tz = ''; }
        }
        // Tutor's helper falls back to site timezone. Prefer the explicit Tutor profile meta first.
        $meta = $user_id ? (string)get_user_meta($user_id, '_tutor_timezone', true) : '';
        if ($meta !== '') $tz = $meta;
        if (!$tz) $tz = 'Africa/Algiers';
        try { new DateTimeZone($tz); } catch (\Exception $e) { $tz = 'Africa/Algiers'; }
        return $tz;
    }

    private function format_session_datetime_for_user($row, $viewer_id, $include_timezone = false) {
        if (empty($row->session_date) || empty($row->start_time)) return '';
        $source_tz = !empty($row->session_timezone) ? (string)$row->session_timezone : $this->user_timezone_string((int)$row->tutor_id);
        try {
            $source = new DateTimeZone($source_tz);
        } catch (\Exception $e) {
            $source = new DateTimeZone('Africa/Algiers');
            $source_tz = 'Africa/Algiers';
        }
        $target_tz = new DateTimeZone($this->user_timezone_string($viewer_id));
        $start = new DateTimeImmutable($row->session_date.' '.substr((string)$row->start_time,0,8), $source);
        $start = $start->setTimezone($target_tz);
        $time = wp_date(get_option('time_format'), $start->getTimestamp(), $target_tz);
        if ($include_timezone) $time .= ' (' . $this->timezone_label($target_tz->getName(), $start->getTimestamp()) . ')';
        return $time;
    }

    private function timezone_label($tz, $timestamp = null) {
        try {
            $dt = new DateTimeImmutable('@'.($timestamp !== null ? (int)$timestamp : time()));
            $dt = $dt->setTimezone(new DateTimeZone($tz));
            return $tz.' · UTC'.$dt->format('P');
        } catch (\Exception $e) {
            return $tz;
        }
    }

    private function format_session_range_for_user($row, $viewer_id, $include_timezone = false) {
        if (empty($row->session_date) || empty($row->start_time) || empty($row->end_time)) return '';
        $source_tz = !empty($row->session_timezone) ? (string)$row->session_timezone : $this->user_timezone_string((int)$row->tutor_id);
        try { $source = new DateTimeZone($source_tz); } catch (\Exception $e) { $source = new DateTimeZone('Africa/Algiers'); }
        $target_tz = new DateTimeZone($this->user_timezone_string($viewer_id));
        $start = (new DateTimeImmutable($row->session_date.' '.substr((string)$row->start_time,0,8), $source))->setTimezone($target_tz);
        $end = (new DateTimeImmutable($row->session_date.' '.substr((string)$row->end_time,0,8), $source))->setTimezone($target_tz);
        $time = wp_date(get_option('time_format'), $start->getTimestamp(), $target_tz).'–'.wp_date(get_option('time_format'), $end->getTimestamp(), $target_tz);
        if ($include_timezone) $time .= ' (' . $this->timezone_label($target_tz->getName(), $start->getTimestamp()) . ')';
        return $time;
    }

    private function format_duration_minutes($minutes) {
        $minutes = max(0, (int)$minutes);
        if (!$minutes) return __('—','tutor-learning-suite');
        $hours = intdiv($minutes, 60); $mins = $minutes % 60;
        if ($hours && $mins) return number_format_i18n($hours) . 'h ' . number_format_i18n($mins) . 'min';
        if ($hours) return number_format_i18n($hours) . ' ' . (1 === $hours ? __('hour','tutor-learning-suite') : __('hours','tutor-learning-suite'));
        return number_format_i18n($mins) . ' ' . __('min','tutor-learning-suite');
    }

    private function format_requested_slot_for_user($row, $viewer_id = 0) {
        if (!empty($row->requested_session_date) && !empty($row->requested_start_time) && !empty($row->requested_end_time)) {
            $timezone = !empty($row->requested_timezone) ? (string)$row->requested_timezone : $this->user_timezone_string((int)$row->tutor_id);
            try {
                $zone = new DateTimeZone($timezone);
                $start = new DateTimeImmutable($row->requested_session_date . ' ' . $row->requested_start_time, $zone);
                $end = new DateTimeImmutable($row->requested_session_date . ' ' . $row->requested_end_time, $zone);
                return wp_date(get_option('date_format'), $start->getTimestamp(), $zone) . ' · ' . wp_date(get_option('time_format'), $start->getTimestamp(), $zone) . '–' . wp_date(get_option('time_format'), $end->getTimestamp(), $zone) . ' (' . $timezone . ')';
            } catch (Exception $e) {
                // Fall back to the legacy, already escaped-at-output preference string.
            }
        }
        return !empty($row->preferred_time) ? (string)$row->preferred_time : __('No preference given','tutor-learning-suite');
    }

    private function status_label($status) {
        $map=['pending_tutor'=>__('Waiting for tutor response','tutor-learning-suite'),'pending_admin'=>__('Waiting for admin approval','tutor-learning-suite'),'proposal_sent'=>__('Tutor proposal sent','tutor-learning-suite'),'approved'=>__('Accepted — payment arranged with tutor','tutor-learning-suite'),'declined'=>__('Declined','tutor-learning-suite'),'paid'=>__('Paid — final details pending','tutor-learning-suite'),'confirmed'=>__('Confirmed','tutor-learning-suite'),'completed'=>__('Completed','tutor-learning-suite'),'rejected'=>__('Rejected','tutor-learning-suite'),'failed'=>__('Failed — expired','tutor-learning-suite'),'cancelled'=>__('Cancelled','tutor-learning-suite')];
        return $map[$status]??ucwords(str_replace('_',' ',$status));
    }

    private function booking_url($tutor_id) {
        $page=(int)get_option(self::OPTION_BOOKING_PAGE);
        $url=$page?get_permalink($page):home_url('/private-booking/');
        return add_query_arg('tutor_id',$tutor_id,$url);
    }

    public function ajax_slots() {
        if ( function_exists( 'tls_rate_limited' ) && tls_rate_limited( 'ps_slots', 90, 60 ) ) { wp_send_json_error( array( 'message' => 'Too many requests. Please wait a moment.' ), 429 ); }
        check_ajax_referer(self::NONCE,'nonce');
        $tutor=absint($_POST['tutor_id']??0);
        $date=sanitize_text_field(wp_unslash($_POST['date']??''));
        $duration=max(15,absint($_POST['duration']??60));
        $type=sanitize_key($_POST['type']??'online');
        $session_type=sanitize_key($_POST['session_type']??'one_to_one');
        if(!$this->is_instructor($tutor) || !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$date) || !in_array($session_type,['one_to_one','group'],true)) wp_send_json_error(['message'=>__('Invalid request.','tutor-learning-suite')],400);
        $requested_timezone=sanitize_text_field(wp_unslash($_POST['timezone']??''));
        if(!$requested_timezone || $requested_timezone!==$this->user_timezone_string($tutor)) wp_send_json_error(['message'=>__('The tutor timezone changed. Refresh the page to see current availability.','tutor-learning-suite')],409);
        try {
            $tz=new DateTimeZone($this->user_timezone_string($tutor));
            $day_start=new DateTimeImmutable($date.' 00:00:00',$tz);
            if($day_start->format('Y-m-d')!==$date) wp_send_json_error(['message'=>__('Invalid calendar date.','tutor-learning-suite')],400);
            $weekday=(int)$day_start->format('w');
        } catch(Exception $e) {
            wp_send_json_error(['message'=>__('Invalid date.','tutor-learning-suite')],400);
        }
        if(!$this->valid_request_type($type)) wp_send_json_error(['message'=>__('Choose a valid tutoring method.','tutor-learning-suite')],400);
        $method_options=$this->tutor_tutoring_options($tutor);
        if(empty($method_options[$session_type.'_'.$type])) wp_send_json_error(['message'=>__('This tutor does not offer that tutoring method for the selected session format.','tutor-learning-suite')],400);
        $duration_cfg=$this->duration_config($tutor);
        if(!isset($duration_cfg[$type])||!in_array($duration,$duration_cfg[$type],true)) wp_send_json_error(['message'=>__('That duration is not available for this tutoring type.','tutor-learning-suite')],400);
        $this->seed_availability($tutor);
        $windows=$this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM {$this->table(self::TABLE_AVAIL)} WHERE tutor_id=%d AND weekday=%d AND enabled=1 ORDER BY FIELD(period,'morning','afternoon'), start_time",$tutor,$weekday
        ));
        $s=$this->settings();
        $step=max(5,(int)$s['slot_step']);
        $min_ts=time()+(int)$s['min_notice_hours']*3600;
        $slots=[];
        $booked=$this->wpdb->get_results($this->wpdb->prepare(
            "SELECT start_time,end_time FROM {$this->table(self::TABLE_SESSIONS)} WHERE tutor_id=%d AND session_date=%s AND status IN ('pending','proposal_sent','approved','paid','confirmed','completed')",$tutor,$date
        ));
        foreach($windows as $w){
            try {
                $start=new DateTimeImmutable($date.' '.substr($w->start_time,0,5).':00',$tz);
                $end=new DateTimeImmutable($date.' '.substr($w->end_time,0,5).':00',$tz);
            } catch(Exception $e) { continue; }
            for($p=$start; $p < $end; $p=$p->modify('+'.$step.' minutes')){
                $p2=$p->modify('+'.$duration.' minutes');
                if($p2>$end || $p->getTimestamp()<$min_ts) continue;
                $conflict=false;
                foreach($booked as $b){
                    try {
                        $bs=new DateTimeImmutable($date.' '.substr($b->start_time,0,5).':00',$tz);
                        $be=new DateTimeImmutable($date.' '.substr($b->end_time,0,5).':00',$tz);
                    } catch(Exception $e) { continue; }
                    if($p->getTimestamp()<$be->getTimestamp() && $p2->getTimestamp()>$bs->getTimestamp()){ $conflict=true; break; }
                }
                if(!$conflict) $slots[]=['start'=>$p->format('H:i'),'end'=>$p2->format('H:i')];
            }
        }
        $message = $slots ? '' : ($windows ? __('The tutor is available on this day, but all slots are outside the minimum booking notice or do not fit the selected duration. Please choose another date or duration.','tutor-learning-suite') : __('The tutor has no availability set for this day. Please choose another date.','tutor-learning-suite'));
        wp_send_json_success(['slots'=>$slots,'message'=>$message]);
    }

    private function slot_is_valid($tutor,$date,$start,$end,$type,$duration){
        try { $tz=new DateTimeZone($this->user_timezone_string($tutor)); $st=new DateTimeImmutable($date.' '.$start.':00',$tz); $en=new DateTimeImmutable($date.' '.$end.':00',$tz); } catch(Exception $e){ return false; }
        return ($en->getTimestamp()-$st->getTimestamp())===($duration*60) && $st->getTimestamp() >= current_time('timestamp', true) + (int)$this->settings()['min_notice_hours']*3600;
    }

    public function ajax_book() {
        check_ajax_referer(self::NONCE,'nonce');
        wp_send_json_error(['message'=>__('Direct booking is disabled. Please submit a private tutoring request first so the tutor can send you a proposal.','tutor-learning-suite')],410);
    }

    private function attach_default_private_course_thumbnail($course_id){
        $course_id=(int)$course_id;
        if(!$course_id || has_post_thumbnail($course_id)) return false;
        $file=TLMS_PS_PATH.'assets/default-private-session.png';
        if(!file_exists($file)) return false;
        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.'wp-admin/includes/media.php';
        require_once ABSPATH.'wp-admin/includes/image.php';
        $uploads=wp_upload_dir();
        if(!empty($uploads['error'])) return false;
        $name=wp_unique_filename($uploads['path'], 'default-private-session-'.$course_id.'.png');
        $dest=trailingslashit($uploads['path']).$name;
        if(!copy($file,$dest)) return false;
        $filetype=wp_check_filetype($name,null);
        $attachment_id=wp_insert_attachment([
            'post_mime_type'=>$filetype['type'] ?: 'image/png',
            'post_title'=>sprintf(__('Private Session %d','tutor-learning-suite'),$course_id),
            'post_content'=>'',
            'post_status'=>'inherit',
        ],$dest,$course_id,true);
        if(is_wp_error($attachment_id) || !$attachment_id) return false;
        $metadata=wp_generate_attachment_metadata($attachment_id,$dest);
        if($metadata) wp_update_attachment_metadata($attachment_id,$metadata);
        set_post_thumbnail($course_id,$attachment_id);
        return true;
    }

    private function ensure_private_course_enrollment_settings($course_id){
        $course_id=(int)$course_id;
        if(!$course_id || get_post_status($course_id)==='trash') return false;

        $settings=get_post_meta($course_id,'_tutor_course_settings',true);
        if(!is_array($settings)) $settings=[];
        // Tutor Native eCommerce checks these settings before allowing a course
        // to be added to the cart. Private-session proposals control schedule and
        // access themselves, so their payment course must remain purchasable.
        $settings['course_enrollment_period']='yes';
        $settings['enrollment_starts_at']='';
        $settings['enrollment_ends_at']='';
        $settings['pause_enrollment']='no';
        if(!isset($settings['maximum_students'])) $settings['maximum_students']=0;
        update_post_meta($course_id,'_tutor_course_settings',$settings);

        update_post_meta($course_id,'_tutor_course_price_type',class_exists('\TUTOR\Course') ? \TUTOR\Course::PRICE_TYPE_PAID : 'paid');
        update_post_meta($course_id,'tutor_course_selling_option',class_exists('\TUTOR\Course') ? \TUTOR\Course::SELLING_OPTION_ONE_TIME : 'one_time');
        update_post_meta($course_id,'_tutor_is_public_course','no');
        update_post_meta($course_id,'_tlms_ps_internal_course',1);
        return true;
    }

    private function prepare_native_course_for_session($session_id){
        $row=$this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->table(self::TABLE_SESSIONS)} WHERE id=%d",$session_id));
        if(!$row) return 0;

        // Repair payment metadata for courses generated by earlier plugin versions.
        if((int)$row->native_course_id){
            $existing=(int)$row->native_course_id;
            $this->ensure_private_course_enrollment_settings($existing);
            $this->attach_default_private_course_thumbnail($existing);
            if((float)$row->price>=0) update_post_meta($existing,'tutor_course_price',(float)$row->price);
            return $existing;
        }

        if(!function_exists('tutor') || !class_exists('Tutor\Models\CourseModel')) return 0;
        $course_post_type = isset(tutor()->course_post_type) && tutor()->course_post_type ? tutor()->course_post_type : \Tutor\Models\CourseModel::POST_TYPE;
        if(!$course_post_type) return 0;
        $tutor_user=get_userdata((int)$row->tutor_id);
        $course_id=wp_insert_post([
            'post_type'=>$course_post_type,
            'post_status'=>'publish',
            'post_title'=>sprintf(__('Private Session #%d - %s','tutor-learning-suite'),$session_id,$tutor_user?$tutor_user->display_name:''),
            'post_author'=>(int)$row->tutor_id,
            'post_content'=>__('Private one-to-one tutoring session. This private course is generated automatically for the booking.','tutor-learning-suite'),
        ],true);
        if(is_wp_error($course_id) || !$course_id){
            if(is_wp_error($course_id)) error_log('[TLMS Private Sessions] Native course creation failed: '.$course_id->get_error_message());
            return 0;
        }
        $course_id=(int)$course_id;
        update_post_meta($course_id,'tutor_course_price',(float)$row->price);
        update_post_meta($course_id,'tutor_course_sale_price','');
        $this->ensure_private_course_enrollment_settings($course_id);
        $this->attach_default_private_course_thumbnail($course_id);
        update_post_meta($course_id,'_tlms_ps_session_id',$session_id);
        $this->wpdb->update($this->table(self::TABLE_SESSIONS),['native_course_id'=>$course_id,'updated_at'=>current_time('mysql')],['id'=>$session_id],['%d','%s'],['%d']);
        return $course_id;
    }

    private function remove_other_private_courses_from_cart($user_id, $keep_course_id){
        global $wpdb;
        $user_id=(int)$user_id; $keep_course_id=(int)$keep_course_id;
        if(!$user_id) return;
        $cart_id=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}tutor_carts WHERE user_id=%d LIMIT 1",$user_id));
        if(!$cart_id) return;
        $items=(array)$wpdb->get_col($wpdb->prepare("SELECT course_id FROM {$wpdb->prefix}tutor_cart_items WHERE cart_id=%d",$cart_id));
        if(!$items) return;
        $internal=[];
        foreach($items as $course_id){
            $course_id=(int)$course_id;
            if($course_id && $course_id!==$keep_course_id && get_post_meta($course_id,'_tlms_ps_internal_course',true)) $internal[]=$course_id;
        }
        if(!$internal) return;
        $placeholders=implode(',',array_fill(0,count($internal),'%d'));
        $sql=$wpdb->prepare("DELETE FROM {$wpdb->prefix}tutor_cart_items WHERE cart_id=%d AND course_id IN ($placeholders)",array_merge([$cart_id],$internal));
        $wpdb->query($sql);
    }

    private function native_cart_url(){
        if(function_exists('tutor_utils')){
            $id=absint(tutor_utils()->get_option('tutor_cart_page_id'));
            if(!$id){ $pages=(array)tutor_utils()->get_option('tutor_pages'); $id=absint($pages['cart']??0); }
            if($id) return get_permalink($id);
        }
        $page=get_page_by_path('cart');
        return $page ? get_permalink($page->ID) : home_url('/cart/');
    }

    private function course_in_native_cart($user_id,$course_id){
        global $wpdb;
        $user_id=absint($user_id); $course_id=absint($course_id);
        if(!$user_id||!$course_id) return false;
        $table=$wpdb->prefix.'tutor_carts'; $items=$wpdb->prefix.'tutor_cart_items';
        $cart_id=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE user_id=%d LIMIT 1",$user_id));
        if(!$cart_id) return false;
        return (bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$items} WHERE cart_id=%d AND course_id=%d LIMIT 1",$cart_id,$course_id));
    }

    public function ajax_add_to_cart(){
        check_ajax_referer(self::NONCE,'nonce');
        if(!is_user_logged_in()) wp_send_json_error(['message'=>__('Please log in.','tutor-learning-suite')],401);
        $student_id=get_current_user_id();
        $session_id=absint($_POST['session_id']??0);
        $row=$this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->table(self::TABLE_SESSIONS)} WHERE id=%d AND student_id=%d LIMIT 1",$session_id,$student_id));
        if(!$row) wp_send_json_error(['message'=>__('Private-session request not found.','tutor-learning-suite')],404);
        if(!in_array($row->status,['proposal_sent','approved'],true)) wp_send_json_error(['message'=>__('This proposal is no longer available.','tutor-learning-suite')],400);
        if($row->status==='proposal_sent' && $this->session_deadline_passed($row,'proposal')) { $this->expire_session((int)$row->id,'proposal'); wp_send_json_error(['message'=>__('This proposal has expired. Please contact the tutor for a new proposal.','tutor-learning-suite')],400); }
        if(!$row->session_date || !$row->start_time || !$row->end_time) wp_send_json_error(['message'=>__('The tutor has not supplied a final schedule yet.','tutor-learning-suite')],400);
        if((float)$row->price<=0 || !$this->settings()['require_payment']){
            if($this->update_status($session_id,'confirmed')!==1) wp_send_json_error(['message'=>__('This proposal changed. Refresh your dashboard and try again.','tutor-learning-suite')],409);
            wp_send_json_success(['message'=>__('Your session is confirmed. No payment is required.','tutor-learning-suite'),'status'=>'confirmed']);
        }
        if($row->status==='proposal_sent'){
            $updated=$this->wpdb->query($this->wpdb->prepare(
                "UPDATE {$this->table(self::TABLE_SESSIONS)} SET status='approved', payment_offer_sent_at=COALESCE(payment_offer_sent_at,%s), updated_at=%s WHERE id=%d AND student_id=%d AND status='proposal_sent'",
                current_time('mysql'),current_time('mysql'),$session_id,$student_id
            ));
            if($updated!==1) wp_send_json_error(['message'=>__('The proposal changed. Refresh your dashboard and try again.','tutor-learning-suite')],409);
        }
        if($this->cart_checkout_available()){
            $course_id=$this->prepare_native_course_for_session($session_id);
            if($course_id && $this->add_session_course_to_cart($student_id,$course_id)){
                wp_send_json_success(['message'=>__('Proposal accepted. Continue to the cart to pay.','tutor-learning-suite'),'status'=>'approved','cart_url'=>$this->native_cart_url()]);
            }
            wp_send_json_error(['message'=>__('Could not add this session to your cart. Please try again.','tutor-learning-suite')],500);
        }
        $methods=$this->get_tutor_offline_methods((int)$row->tutor_id);
        if(!$methods) wp_send_json_success(['message'=>__('Proposal accepted. The tutor has not configured an active offline payment method yet. Contact the tutor through your booking thread.','tutor-learning-suite'),'status'=>'approved','methods'=>[]]);
        wp_send_json_success(['message'=>__('Proposal accepted. Review the tutor payment instructions in this request and declare payment after paying the tutor directly.','tutor-learning-suite'),'status'=>'approved','methods'=>array_map(function($method){return ['id'=>(string)$method['id'],'title'=>(string)$method['title']];},$methods)]);
    }

    /** True when Tutor's native cart/checkout can carry a private session. */
    private function cart_checkout_available(){
        return class_exists('\\Tutor\\Models\\CartModel') && function_exists('tutor_utils') && tutor_utils()->is_monetize_by_tutor();
    }

    /** Put the temporary session course alone into the student's native cart. */
    private function add_session_course_to_cart($user_id,$course_id){
        $user_id=absint($user_id); $course_id=absint($course_id);
        if(!$user_id || !$course_id) return false;
        $this->remove_other_private_courses_from_cart($user_id,$course_id);
        if($this->course_in_native_cart($user_id,$course_id)) return true;
        $model=new \Tutor\Models\CartModel();
        return (bool)$model->add_course_to_cart($user_id,$course_id);
    }

    private function get_tutor_offline_methods($tutor_id){
        if(class_exists('TutorInstructorOfflinePayment\\Methods')){
            return \TutorInstructorOfflinePayment\Methods::get(absint($tutor_id),true);
        }
        return [];
    }

    public function ajax_declare_payment(){
        check_ajax_referer(self::NONCE,'nonce');
        if(!is_user_logged_in()) wp_send_json_error(['message'=>__('Please log in.','tutor-learning-suite')],401);
        $student_id=get_current_user_id();
        $session_id=absint($_POST['session_id']??0);
        $method_id=sanitize_text_field(wp_unslash($_POST['payment_method_id']??''));
        $reference=sanitize_text_field(wp_unslash($_POST['payment_reference']??''));
        $row=$this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->table(self::TABLE_SESSIONS)} WHERE id=%d AND student_id=%d LIMIT 1",$session_id,$student_id));
        if(!$row || $row->status!=='approved') wp_send_json_error(['message'=>__('This booking is not awaiting a payment declaration.','tutor-learning-suite')],400);
        if(!empty($row->payment_declared_at)) wp_send_json_error(['message'=>__('Payment has already been declared. Wait for the tutor to confirm it.','tutor-learning-suite')],409);
        if($this->session_deadline_passed($row,'payment')){ $this->expire_session($session_id,'payment'); wp_send_json_error(['message'=>__('This payment offer has expired. Ask the tutor for a new proposal.','tutor-learning-suite')],400); }
        $methods=$this->get_tutor_offline_methods((int)$row->tutor_id);
        $valid=false;
        foreach($methods as $method){ if(isset($method['id']) && hash_equals((string)$method['id'],$method_id)){ $valid=true; break; } }
        if(!$valid) wp_send_json_error(['message'=>__('Choose one of the tutor’s active payment methods.','tutor-learning-suite')],400);
        $updated=$this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->table(self::TABLE_SESSIONS)} SET payment_method_id=%s,payment_reference=%s,payment_declared_at=%s,updated_at=%s WHERE id=%d AND student_id=%d AND status='approved' AND payment_declared_at IS NULL",
            $method_id,$reference,current_time('mysql'),current_time('mysql'),$session_id,$student_id
        ));
        if($updated!==1) wp_send_json_error(['message'=>__('Payment declaration could not be saved. Refresh and check the booking status.','tutor-learning-suite')],409);
        $tutor=get_userdata((int)$row->tutor_id);
        if($tutor && is_email($tutor->user_email)) wp_mail($tutor->user_email,__('A student declared a private-session payment','tutor-learning-suite'),sprintf(__('A payment was declared for private-session request #%d. Sign in to your Tutor dashboard to review and confirm it.','tutor-learning-suite'),$session_id));
        wp_send_json_success(['message'=>__('Payment declaration sent to the tutor. Your session is not confirmed until the tutor verifies receipt.','tutor-learning-suite')]);
    }

    public function ajax_confirm_payment(){
        check_ajax_referer(self::NONCE,'nonce');
        if(!is_user_logged_in() || !$this->is_instructor(get_current_user_id())) wp_send_json_error(['message'=>__('Permission denied.','tutor-learning-suite')],403);
        $tutor_id=get_current_user_id();
        $session_id=absint($_POST['session_id']??0);
        $row=$this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->table(self::TABLE_SESSIONS)} WHERE id=%d AND tutor_id=%d LIMIT 1",$session_id,$tutor_id));
        if(!$row || $row->status!=='approved' || empty($row->payment_declared_at)) wp_send_json_error(['message'=>__('No pending payment declaration was found for this booking.','tutor-learning-suite')],400);
        $updated=$this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->table(self::TABLE_SESSIONS)} SET status='confirmed',payment_confirmed_at=%s,payment_confirmed_by=%d,updated_at=%s WHERE id=%d AND tutor_id=%d AND status='approved' AND payment_declared_at IS NOT NULL",
            current_time('mysql'),$tutor_id,current_time('mysql'),$session_id,$tutor_id
        ));
        if($updated!==1) wp_send_json_error(['message'=>__('This payment was already confirmed or the booking changed.','tutor-learning-suite')],409);
        $student=get_userdata((int)$row->student_id);
        if($student && is_email($student->user_email)) wp_mail($student->user_email,__('Your private tutoring payment was confirmed','tutor-learning-suite'),sprintf(__('Your tutor confirmed receipt of payment for private-session request #%d. Your booking is now confirmed.','tutor-learning-suite'),$session_id));
        wp_send_json_success(['message'=>__('Payment confirmed. The session is now confirmed.','tutor-learning-suite')]);
    }

    public function ajax_tryout_decision(){
        check_ajax_referer(self::NONCE,'nonce');
        if(!is_user_logged_in()) wp_send_json_error(['message'=>__('Please log in.','tutor-learning-suite')],401);
        $uid=get_current_user_id();
        if(!$this->is_instructor($uid)) wp_send_json_error(['message'=>__('Permission denied.','tutor-learning-suite')],403);
        $id=absint($_POST['session_id']??0);
        $decision=sanitize_key($_POST['decision']??'');
        if(!in_array($decision,['approved','rejected'],true)) wp_send_json_error(['message'=>__('Invalid tryout decision.','tutor-learning-suite')],400);
        $row=$this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->table(self::TABLE_SESSIONS)} WHERE id=%d AND tutor_id=%d LIMIT 1",$id,$uid));
        if(!$row) wp_send_json_error(['message'=>__('Tryout request not found.','tutor-learning-suite')],404);
        if((int)$row->is_tryout!==1) wp_send_json_error(['message'=>__('This is not a tryout request.','tutor-learning-suite')],400);
        if($row->status!=='pending_tutor') wp_send_json_error(['message'=>__('This tryout request has already been handled.','tutor-learning-suite')],400);
        if($this->session_deadline_passed($row,'tutor')){
            $this->expire_session($id,'tutor');
            wp_send_json_error(['message'=>__('This tryout request has expired.','tutor-learning-suite')],400);
        }
        $student=get_userdata((int)$row->student_id);
        if($decision==='rejected'){
            if($this->update_status($id,'rejected')!==1) wp_send_json_error(['message'=>__('This request changed while you were responding. Refresh and try again.','tutor-learning-suite')],409);
            if($row->native_course_id) $this->delete_internal_course_after_payment($id,(int)$row->native_course_id);
            if($student && is_email($student->user_email)) wp_mail($student->user_email,__('Your free tryout request was rejected','tutor-learning-suite'),__('Your tutor rejected your free tryout request. Log in to your dashboard for the updated request status.','tutor-learning-suite'));
            wp_send_json_success(['message'=>__('Tryout request rejected.','tutor-learning-suite')]);
        }
        // A tryout approval confirms the learner's calendar preference. Older
        // pre-calendar tryout requests have no structured slot, so they cannot be
        // marked confirmed until a fresh request supplies an actual date and time.
        $date=(string)($row->requested_session_date??'');
        $start=substr((string)($row->requested_start_time??''),0,5);
        $requested_end=substr((string)($row->requested_end_time??''),0,5);
        $duration=(int)($row->requested_duration_minutes??0);
        $requested_timezone=(string)($row->requested_timezone??'');
        $tutor_timezone=$this->user_timezone_string($uid);
        if(!$date || !$start || !$requested_end || !$duration || !$requested_timezone){
            wp_send_json_error(['message'=>__('This older tryout request has no structured calendar time. Ask the learner to send a fresh request using your availability calendar.','tutor-learning-suite')],409);
        }
        if($requested_timezone!==$tutor_timezone){
            wp_send_json_error(['message'=>__('Your timezone changed after this request was sent. Ask the learner to choose a fresh time in your current calendar.','tutor-learning-suite')],409);
        }
        if(!$this->acquire_proposal_slot_lock($uid,$date)) wp_send_json_error(['message'=>__('Another booking update is in progress for this date. Please try again.','tutor-learning-suite')],409);
        if(!$this->proposal_slot_available($uid,$date,$start,$duration,(string)$row->tutoring_type,(int)$row->id)){
            wp_send_json_error(['message'=>__('The requested tryout time is no longer available. Ask the learner to choose another calendar slot before approving.','tutor-learning-suite')],409);
        }
        try{
            $tryout_start=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$date.' '.$start.':00',new DateTimeZone($tutor_timezone));
            $date_errors=DateTimeImmutable::getLastErrors();
            if(!$tryout_start || ($date_errors && ($date_errors['warning_count'] || $date_errors['error_count']))) throw new RuntimeException('Invalid tryout date.');
            $tryout_end=$tryout_start->modify('+'.$duration.' minutes');
            $end=$tryout_end->format('H:i:s');
        }catch(Exception $e){ wp_send_json_error(['message'=>__('The requested tryout time is invalid. Ask the learner to choose a fresh calendar slot.','tutor-learning-suite')],409); }
        if($tryout_end->format('Y-m-d')!==$date || $tryout_end->format('H:i')!==$requested_end){
            wp_send_json_error(['message'=>__('The requested tryout duration or end time no longer matches. Ask the learner to choose a fresh calendar slot.','tutor-learning-suite')],409);
        }
        $now=current_time('mysql');
        $updated=$this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->table(self::TABLE_SESSIONS)} SET session_date=%s,start_time=%s,end_time=%s,session_timezone=%s,duration_minutes=%d,status='confirmed',tutor_responded_at=%s,updated_at=%s WHERE id=%d AND tutor_id=%d AND status='pending_tutor' AND is_tryout=1",
            $date,$start.':00',$end,$tutor_timezone,$duration,$now,$now,$id,$uid
        ));
        if($updated!==1) wp_send_json_error(['message'=>__('This tryout request changed while you were approving it. Refresh and try again.','tutor-learning-suite')],409);
        if($student && is_email($student->user_email)) wp_mail($student->user_email,__('Your free tryout request was approved','tutor-learning-suite'),sprintf(__('Your tutor approved your free tryout for %1$s at %2$s (%3$s). Log in to your Private Sessions dashboard for the final details.','tutor-learning-suite'),$date,$start,$tutor_timezone));
        wp_send_json_success(['message'=>__('Free tryout approved and confirmed for the requested calendar time.','tutor-learning-suite')]);
    }

    public function ajax_review_session(){
        check_ajax_referer(self::NONCE,'nonce');
        if(!is_user_logged_in()) wp_send_json_error(['message'=>__('Please log in.','tutor-learning-suite')],401);
        $uid=get_current_user_id();
        $id=absint($_POST['session_id']??0);
        $requested=sanitize_key($_POST['status']??'');
        $row=$this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->table(self::TABLE_SESSIONS)} WHERE id=%d",$id));
        if(!$row) wp_send_json_error(['message'=>__('Session not found.','tutor-learning-suite')],404);
        $admin=current_user_can('manage_options');
        $tutor=((int)$row->tutor_id===$uid && $this->is_instructor($uid));
        if(!$admin && !$tutor) wp_send_json_error(['message'=>__('Permission denied.','tutor-learning-suite')],403);
        if(!in_array($requested,['approved','rejected'],true)) wp_send_json_error(['message'=>__('Invalid status.','tutor-learning-suite')],400);
        if($requested==='approved' && $row->status==='pending_tutor' && $row->created_at && $this->session_deadline_passed($row,'tutor')) { $this->expire_session((int)$row->id,'tutor'); wp_send_json_error(['message'=>__('This request has expired because the tutor response deadline has passed.','tutor-learning-suite')],400); }

        if($requested==='approved' && $row->status==='pending_tutor' && !(int)$row->is_tryout && $row->requested_hours!==null){
            wp_send_json_error(['message'=>__('This request uses the proposal workflow. Send a proposal with the schedule and price instead of approving it directly.','tutor-learning-suite')],400);
        }

        if($requested==='rejected'){
            if(!in_array($row->status,['pending_tutor','pending_admin'],true)) wp_send_json_error(['message'=>__('This booking is not waiting for approval.','tutor-learning-suite')],400);
            if($this->update_status($id,'rejected')!==1) wp_send_json_error(['message'=>__('This booking changed while you were responding. Refresh and try again.','tutor-learning-suite')],409);
            if($row->native_course_id) wp_update_post(['ID'=>(int)$row->native_course_id,'post_status'=>'draft']);
            wp_send_json_success(['message'=>$row->is_tryout?__('Tryout request rejected.','tutor-learning-suite'):__('Booking rejected.','tutor-learning-suite')]);
        }

        $s=$this->settings();
        $status=$row->status;
        if($status!=='pending_tutor' && !($admin && $status==='pending_admin')) wp_send_json_error(['message'=>__('This booking is not waiting for approval.','tutor-learning-suite')],400);

        // Tryouts intentionally bypass the normal proposal, admin-approval, and payment workflow.
        // Tutor approval immediately confirms the free session; an administrator may also confirm an item already waiting for admin approval.
        if((int)$row->is_tryout && (float)$row->price<=0){
            if(!$tutor && !$admin) wp_send_json_error(['message'=>__('Permission denied.','tutor-learning-suite')],403);
            if($this->update_status($id,'confirmed')!==1) wp_send_json_error(['message'=>__('This booking changed while you were responding. Refresh and try again.','tutor-learning-suite')],409);
            if($row->native_course_id) $this->delete_internal_course_after_payment((int)$row->id,(int)$row->native_course_id);
            if((int)$row->student_id){
                $student=get_userdata((int)$row->student_id);
                if($student && is_email($student->user_email)){ wp_mail($student->user_email,__('Your free tryout request was approved','tutor-learning-suite'),__('Your tutor approved your free tryout request. Log in to your Private Sessions dashboard for the session details.','tutor-learning-suite')); }
            }
            wp_send_json_success(['message'=>__('Free tryout approved and confirmed. The tutor can now send session details.','tutor-learning-suite')]);
        }

        if($tutor && !$admin){
            if((int)$s['require_admin_approval']){
                if($this->update_status($id,'pending_admin')!==1) wp_send_json_error(['message'=>__('This booking changed while you were responding. Refresh and try again.','tutor-learning-suite')],409);
                wp_send_json_success(['message'=>__('Approved by tutor. Waiting for admin approval.','tutor-learning-suite')]);
            }
            $status='approved';
        } elseif($admin){
            // Admin approval is an override and may approve either stage.
            $status='approved';
        } else {
            wp_send_json_error(['message'=>__('Permission denied.','tutor-learning-suite')],403);
        }

        // Private tutoring uses direct-to-tutor payment declarations; it must
        // never create a purchasable Tutor LMS course or start Native eCommerce.
        $final_status=(($s['require_payment'] && (float)$row->price>0)?'approved':'confirmed');
        if($this->update_status($id,$final_status)!==1) wp_send_json_error(['message'=>__('This booking changed while you were responding. Refresh and try again.','tutor-learning-suite')],409);
        if($final_status==='approved') $this->wpdb->update($this->table(self::TABLE_SESSIONS),['payment_offer_sent_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')],['id'=>$id],['%s','%s'],['%d']);
        if((int)$row->student_id){
            $student=get_userdata((int)$row->student_id);
            if($student && is_email($student->user_email)) wp_mail($student->user_email,__('Your private tutoring request was approved','tutor-learning-suite'),$final_status==='approved'?__('Your private tutoring request has been approved. Log in to your student dashboard to review the tutor’s payment methods and pay the tutor directly.','tutor-learning-suite'):__('Your private tutoring request has been approved and confirmed. Log in to your student dashboard for the session details.','tutor-learning-suite'));
        }
        wp_send_json_success(['message'=>$final_status==='approved'?__('Booking approved. The student can now arrange payment directly with you.','tutor-learning-suite'):__('Booking approved and confirmed.','tutor-learning-suite')]);
    }

    public function ajax_bulk_review_sessions(){
        check_ajax_referer(self::NONCE,'nonce'); if(!is_user_logged_in() || !$this->is_instructor(get_current_user_id())) wp_send_json_error(['message'=>__('Permission denied.','tutor-learning-suite')],403);
        $uid=get_current_user_id(); $ids=array_values(array_unique(array_filter(array_map('absint',(array)($_POST['session_ids']??[]))))); $status=sanitize_key($_POST['status']??'');
        if(!$ids || !in_array($status,['approved','rejected'],true)) wp_send_json_error(['message'=>__('Select sessions and a valid bulk action.','tutor-learning-suite')],400);
        $changed=0; $s=$this->settings();
        foreach($ids as $id){
            $row=$this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->table(self::TABLE_SESSIONS)} WHERE id=%d AND tutor_id=%d",$id,$uid));
            if(!$row || !in_array($row->status,['pending_tutor','pending_admin'],true)) continue;
            // Tutors may reject a request in their queue, but cannot approve a
            // proposal-based request without proposal + learner acceptance, nor
            // approve an admin-only queue item.
            if($status==='rejected'){
                if($this->update_status($id,'rejected')===1){ if($row->native_course_id) wp_update_post(['ID'=>(int)$row->native_course_id,'post_status'=>'draft']); $changed++; }
                continue;
            }
            if($row->status!=='pending_tutor') continue;
            if(!(int)$row->is_tryout && $row->requested_hours!==null) continue;
            if((int)$row->is_tryout && (float)$row->price<=0){
                if($this->update_status($id,'confirmed')===1){ if($row->native_course_id) $this->delete_internal_course_after_payment((int)$row->id,(int)$row->native_course_id); $changed++; }
                continue;
            }
            if((int)$s['require_admin_approval']){
                if($this->update_status($id,'pending_admin')===1) $changed++;
                continue;
            }
            $final=((int)$s['require_payment'] && (float)$row->price>0)?'approved':'confirmed';
            if($this->update_status($id,$final)===1){
                if($final==='approved') $this->wpdb->update($this->table(self::TABLE_SESSIONS),['payment_offer_sent_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')],['id'=>$id],['%s','%s'],['%d']);
                $changed++;
            }
        }
        wp_send_json_success(['message'=>sprintf(__('Updated %d selected session(s).','tutor-learning-suite'),$changed),'changed'=>$changed]);
    }

    public function on_tutor_order_placed($order_data){
        $order_id=(int)($order_data['id']??0); $user_id=(int)($order_data['user_id']??0);
        $items=is_array($order_data['items']??null)?$order_data['items']:[];
        if(!$order_id || !$items) return;
        foreach($items as $item){
            $course_id=(int)($item['item_id']??0); if(!$course_id) continue;
            $session_id=(int)get_post_meta($course_id,'_tlms_ps_session_id',true); if(!$session_id) continue;
            $row=$this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->table(self::TABLE_SESSIONS)} WHERE id=%d LIMIT 1",$session_id));
            if(!$row || ($user_id && (int)$row->student_id!==$user_id) || (int)$row->native_course_id!==$course_id) continue;
            $this->wpdb->update($this->table(self::TABLE_SESSIONS),['order_id'=>$order_id,'updated_at'=>current_time('mysql')],['id'=>$session_id],['%d','%s'],['%d']);
            if((int)$row->learner_user_id && class_exists('Tutor\\Models\\OrderMetaModel')){
                $learner_map=\Tutor\Models\OrderMetaModel::get_meta_value($order_id,'tls_private_session_learners',true);
                if(!is_array($learner_map)) $learner_map=[];
                $learner_map[(int)$session_id]=(int)$row->learner_user_id;
                \Tutor\Models\OrderMetaModel::update_meta($order_id,'tls_private_session_learners',$learner_map);
            }
        }
    }

    public function on_tutor_payment_status_changed($order_id,$old,$new){
        $order_id=(int)$order_id; if(!$order_id) return;
        $new=(string)$new;
        $target_status='';
        if(in_array($new,['paid','completed'],true)) $target_status='paid';
        elseif(in_array($new,['refunded','partially-refunded','cancelled'],true)) $target_status='rejected';
        if(!$target_status || !class_exists('\\Tutor\\Models\\OrderModel')) return;

        try{
            $model=new \Tutor\Models\OrderModel();
            $order=$model->get_order_by_id($order_id);
            if(!$order) return;
            $student_id=(int)($order->user_id??($order->student->id??0));

            // get_order_by_id() does not expose the order's item list. Resolve the
            // exact Tutor native order items through the public OrderModel method.
            $items=method_exists($model,'get_order_items_by_id') ? $model->get_order_items_by_id($order_id) : [];
            $course_ids=[];
            foreach((array)$items as $item){
                $course_id=(int)(is_object($item) ? ($item->id??0) : ($item['id']??$item['item_id']??0));
                if($course_id) $course_ids[$course_id]=true;
            }

            // Primary match: exact session order ID. This handles older bookings
            // where the item metadata was not attached correctly.
            $rows=$this->wpdb->get_results($this->wpdb->prepare(
                "SELECT * FROM {$this->table(self::TABLE_SESSIONS)} WHERE order_id=%d",
                $order_id
            ));

            // Secondary match: private-session course IDs belonging to this order.
            if($course_ids){
                $ids=implode(',',array_map('intval',array_keys($course_ids)));
                $more=$this->wpdb->get_results("SELECT * FROM {$this->table(self::TABLE_SESSIONS)} WHERE native_course_id IN ({$ids})");
                $by_id=[];
                foreach((array)$rows as $r) $by_id[(int)$r->id]=$r;
                foreach((array)$more as $r) $by_id[(int)$r->id]=$r;
                $rows=array_values($by_id);
            }

            foreach((array)$rows as $row){
                if($student_id && (int)$row->student_id!==$student_id) continue;
                if($target_status==='paid' && in_array((string)$row->status,['rejected','failed','cancelled'],true)) continue;
                if($course_ids && $row->native_course_id && !isset($course_ids[(int)$row->native_course_id]) && (int)$row->order_id!==$order_id) continue;

                // Never perform course deletion or order association merely because
                // Tutor fired a payment hook. The private-session state transition
                // must be legal (or already at the exact target state) first.
                $transition=$this->update_status((int)$row->id,$target_status);
                if(false===$transition) continue;
                if($target_status==='paid' && (int)$row->native_course_id && (1===$transition || 'paid'===(string)$row->status)){
                    $this->delete_internal_course_after_payment((int)$row->id,(int)$row->native_course_id);
                }
                if((int)$row->order_id!==$order_id){
                    $this->wpdb->update($this->table(self::TABLE_SESSIONS),['order_id'=>$order_id,'updated_at'=>current_time('mysql')],['id'=>(int)$row->id],['%d','%s'],['%d']);
                }
            }
        }catch(\Throwable $e){
            // Payment hooks must never break Tutor's checkout/order processing.
        }
    }

    /**
     * Permanently remove the temporary Tutor LMS checkout course after the exact
     * private-session order is confirmed paid. The session/order record stays
     * intact, so the booking history and payment reference remain available.
     */
    private function delete_internal_course_after_payment($session_id,$course_id){
        $session_id=absint($session_id); $course_id=absint($course_id);
        if(!$session_id || !$course_id) return false;
        $owner=(int)get_post_meta($course_id,'_tlms_ps_session_id',true);
        $internal=(string)get_post_meta($course_id,'_tlms_ps_internal_course',true);
        if($owner!==$session_id || $internal!=='1') return false;
        $this->remove_course_from_native_carts($course_id);
        // Paying enrols the student in the temporary course; drop those records with it.
        foreach((array)get_posts(['post_type'=>'tutor_enrolled','post_parent'=>$course_id,'post_status'=>'any','posts_per_page'=>-1,'fields'=>'ids']) as $enrol_id) wp_delete_post((int)$enrol_id,true);
        $result=wp_delete_post($course_id,true);
        if($result){
            $this->wpdb->update($this->table(self::TABLE_SESSIONS),['native_course_id'=>null,'updated_at'=>current_time('mysql')],['id'=>$session_id],['%d','%s'],['%d']);
            return true;
        }
        return false;
    }

    private function remove_course_from_native_carts($course_id){
        global $wpdb;
        $course_id=absint($course_id); if(!$course_id) return;
        $table=$wpdb->prefix.'tutor_cart_items';
        $exists=$wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$table));
        if($exists!==$table) return;
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE course_id=%d",$course_id));
    }

    private function reconcile_paid_sessions_for_student($student_id){
        $student_id=absint($student_id); if(!$student_id || !class_exists('\\Tutor\\Models\\OrderModel')) return;
        $rows=$this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM {$this->table(self::TABLE_SESSIONS)} WHERE student_id=%d AND status='approved' AND order_id IS NOT NULL",
            $student_id
        ));
        if(!$rows) return;
        try{
            $model=new \Tutor\Models\OrderModel();
            foreach($rows as $row){
                $order=$model->get_order_by_id((int)$row->order_id);
                if(!$order || (int)($order->user_id??($order->student->id??0))!==$student_id) continue;
                if((string)($order->payment_status??'') !== 'paid') continue;
                $items=method_exists($model,'get_order_items_by_id') ? $model->get_order_items_by_id((int)$row->order_id) : [];
                foreach((array)$items as $item){
                    $course_id=(int)(is_object($item) ? ($item->id??0) : ($item['id']??$item['item_id']??0));
                    if($course_id && (int)$row->native_course_id===$course_id){
                        $transition=$this->update_status((int)$row->id,'paid');
                        if(1===$transition && (int)$row->native_course_id) $this->delete_internal_course_after_payment((int)$row->id,(int)$row->native_course_id);
                        break;
                    }
                }
            }
        }catch(\Throwable $e){}
    }

    public function hide_internal_courses($q){
        if(!($q instanceof WP_Query)) return;
        if(is_admin() && current_user_can('manage_options')) return;
        $pt=function_exists('tutor')&&isset(tutor()->course_post_type)?tutor()->course_post_type:'courses';
        $post_type=$q->get('post_type');
        $is_course = ($post_type===$pt) || (is_array($post_type) && in_array($pt,$post_type,true));
        if(!$is_course && $post_type===null){
            $is_course = $q->is_search() || $q->is_post_type_archive($pt) || $q->is_tax('course-category') || $q->is_tax('course-tag');
        }
        if(!$is_course) return;

        // Never hide a single explicitly requested internal course from code that
        // needs to resolve it for Native eCommerce. Exclude them from listings,
        // archives, searches, and dashboard course queries instead.
        if($q->is_singular($pt) && !$q->is_search()) return;

        $meta=(array)$q->get('meta_query');
        foreach($meta as $clause){
            if(is_array($clause) && ($clause['key']??'')==='_tlms_ps_internal_course') return;
        }
        // Rating courses must remain discoverable inside wp-admin so Tutor LMS's
        // native Reviews/Reports screens can resolve the course attached to the
        // review. They are still hidden from all frontend course listings.
        $meta[]=['key'=>'_tlms_ps_internal_course','compare'=>'NOT EXISTS'];
        if(!is_admin()) $meta[]=['key'=>'_tlms_ps_rating_course','compare'=>'NOT EXISTS'];
        $q->set('meta_query',$meta);
    }

    /**
     * Exclude checkout-only private session courses from Tutor/frontend SQL
     * queries that bypass the main WP_Query filtering path.
     */
    public function hide_internal_courses_from_sql($clauses,$query){
        if(!($query instanceof WP_Query)) return $clauses;
        $pt=function_exists('tutor')&&isset(tutor()->course_post_type)?tutor()->course_post_type:'courses';
        $post_type=$query->get('post_type');
        $is_course=($post_type===$pt)||(is_array($post_type)&&in_array($pt,$post_type,true));
        if(!$is_course && $post_type===null){
            $is_course=$query->is_search()||$query->is_post_type_archive($pt)||$query->is_tax('course-category')||$query->is_tax('course-tag');
        }
        if(!$is_course) return $clauses;
        if($query->is_singular($pt) && !$query->is_search()) return $clauses;
        global $wpdb;
        $internal_alias='tlms_ps_internal_hide';
        $rating_alias='tlms_ps_rating_hide';
        if(strpos($clauses['join'],$internal_alias)===false){
            $clauses['join'].=" LEFT JOIN {$wpdb->postmeta} AS {$internal_alias} ON ({$wpdb->posts}.ID = {$internal_alias}.post_id AND {$internal_alias}.meta_key = '_tlms_ps_internal_course')";
        }
        if(strpos($clauses['where'], $internal_alias.'.meta_id IS NULL')===false){
            $clauses['where'].=" AND {$internal_alias}.meta_id IS NULL";
        }
        // Do not hide the one-per-tutor rating course inside wp-admin. Tutor's
        // native Reviews/Reports queries need to see that course to list its
        // pending/approved reviews. Frontend visitors still get the rating course
        // excluded by the secondary rating-meta condition below.
        if(!is_admin()){
            if(strpos($clauses['join'],$rating_alias)===false){
                $clauses['join'].=" LEFT JOIN {$wpdb->postmeta} AS {$rating_alias} ON ({$wpdb->posts}.ID = {$rating_alias}.post_id AND {$rating_alias}.meta_key = '_tlms_ps_rating_course')";
            }
            if(strpos($clauses['where'], $rating_alias.'.meta_id IS NULL')===false){
                $clauses['where'].=" AND {$rating_alias}.meta_id IS NULL";
            }
        }
        return $clauses;
    }

    private function get_tutor_session_price($tutor,$duration,$type){
        $prices=(array)get_user_meta($tutor,'_tlms_ps_prices',true);$key=$type.'_'.$duration; if(isset($prices[$key])&&$prices[$key]!=='')return max(0,(float)$prices[$key]);$base=get_user_meta($tutor,'_tlms_ps_base_price',true);$base=$base!==''?(float)$base:(float)$this->settings()['default_price'];return max(0,$base*($duration/60));
    }

    /** Convert a database datetime stored in the WordPress site timezone to an absolute epoch. */
    private function session_datetime_timestamp($value){
        $value=trim((string)$value);
        if($value==='') return 0;
        try{
            $dt=new DateTimeImmutable($value,wp_timezone());
            return $dt->getTimestamp();
        }catch(Exception $e){ return 0; }
    }

    private function session_deadline_passed($row,$stage){
        if(!$row) return false;
        $days=0; $base='';
        if($stage==='tutor'){ $days=(int)$this->settings()['tutor_response_days']; $base=(string)$row->created_at; }
        elseif($stage==='proposal'){ $days=(int)$this->settings()['proposal_response_days']; $base=(string)$row->proposal_sent_at; }
        elseif($stage==='payment'){ $days=(int)$this->settings()['payment_timeout_days']; $base=(string)$row->payment_offer_sent_at; }
        if($days<1 || !$base) return false;
        $ts=$this->session_datetime_timestamp($base);
        return $ts > 0 && ($ts + $days*DAY_IN_SECONDS) <= time();
    }

    private function expire_session($session_id,$stage=''){
        $session_id=absint($session_id); if(!$session_id) return false;
        $row=$this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->table(self::TABLE_SESSIONS)} WHERE id=%d LIMIT 1",$session_id));
        if(!$row) return false;
        if($this->update_status($session_id,'failed')!==1) return false;
        if((int)$row->native_course_id) $this->delete_internal_course_after_payment((int)$row->id,(int)$row->native_course_id);
        return true;
    }

    public function ajax_run_maintenance(){
        check_ajax_referer(self::NONCE,'nonce');
        if(!current_user_can('manage_options')) wp_send_json_error(['message'=>__('Permission denied.','tutor-learning-suite')],403);
        $result=$this->run_maintenance(true);
        wp_send_json_success(['message'=>sprintf(__('Maintenance complete: %1$d session(s) expired, %2$d temporary course(s) deleted, %3$d reminder email(s) sent.','tutor-learning-suite'),(int)$result['expired'],(int)$result['deleted'],(int)($result['reminders_sent']??0))]);
    }

    public function run_maintenance($manual=false){
        $s=$this->settings(); $site_now=new DateTimeImmutable('now',wp_timezone()); $expired=0; $deleted=0;
        $table=$this->table(self::TABLE_SESSIONS);
        $cut1=$site_now->modify('-'.(int)$s['tutor_response_days'].' days')->format('Y-m-d H:i:s');
        $rows=$this->wpdb->get_results($this->wpdb->prepare("SELECT * FROM {$table} WHERE status='pending_tutor' AND requested_hours IS NOT NULL AND created_at < %s",$cut1));
        foreach((array)$rows as $row){
            // Transition first. Never delete the checkout course when another
            // request has already changed the session or the CAS update fails.
            if($this->update_status((int)$row->id,'failed')===1){
                if((int)$row->native_course_id) $this->delete_internal_course_after_payment((int)$row->id,(int)$row->native_course_id);
                $expired++;
            }
        }
        $cut2=$site_now->modify('-'.(int)$s['proposal_response_days'].' days')->format('Y-m-d H:i:s');
        $rows=$this->wpdb->get_results($this->wpdb->prepare("SELECT * FROM {$table} WHERE status='proposal_sent' AND proposal_sent_at IS NOT NULL AND proposal_sent_at < %s",$cut2));
        foreach((array)$rows as $row){
            // Transition first. Never delete the checkout course when another
            // request has already changed the session or the CAS update fails.
            if($this->update_status((int)$row->id,'failed')===1){
                if((int)$row->native_course_id) $this->delete_internal_course_after_payment((int)$row->id,(int)$row->native_course_id);
                $expired++;
            }
        }
        // One reminder at the half-way point of the payment window, so learners are not surprised by the timeout.
        $half=$site_now->modify('-'.max(6,(int)$s['payment_timeout_days']*12).' hours')->format('Y-m-d H:i:s');
        $reminded=array_map('intval',(array)get_option('tlms_ps_pay_reminded',[]));
        $pending_pay=$this->wpdb->get_results($this->wpdb->prepare("SELECT * FROM {$table} WHERE status='approved' AND payment_declared_at IS NULL AND payment_offer_sent_at IS NOT NULL AND payment_offer_sent_at < %s",$half));
        foreach((array)$pending_pay as $row){
            if(in_array((int)$row->id,$reminded,true)) continue;
            $learner=get_userdata((int)$row->student_id);
            if($learner && is_email($learner->user_email)){
                $deadline=date_i18n(get_option('date_format'),strtotime((string)$row->payment_offer_sent_at.' +'.(int)$s['payment_timeout_days'].' days'));
                wp_mail($learner->user_email,__('Reminder: complete payment for your private session','tutor-learning-suite'),sprintf(__('Your private session proposal is accepted but not paid yet. Please complete payment from your dashboard by %s or the request will be cancelled.','tutor-learning-suite'),$deadline));
            }
            $reminded[]=(int)$row->id;
        }
        update_option('tlms_ps_pay_reminded',array_slice(array_values(array_unique($reminded)),-500),false);
        $cut3=$site_now->modify('-'.(int)$s['payment_timeout_days'].' days')->format('Y-m-d H:i:s');
        $rows=$this->wpdb->get_results($this->wpdb->prepare("SELECT * FROM {$table} WHERE status='approved' AND payment_offer_sent_at IS NOT NULL AND payment_offer_sent_at < %s",$cut3));
        foreach((array)$rows as $row){
            // Transition first. Never delete the checkout course when another
            // request has already changed the session or the CAS update fails.
            if($this->update_status((int)$row->id,'failed')===1){
                if((int)$row->native_course_id) $this->delete_internal_course_after_payment((int)$row->id,(int)$row->native_course_id);
                $expired++;
            }
        }
        $course_pt=function_exists('tutor')&&isset(tutor()->course_post_type)&&tutor()->course_post_type?tutor()->course_post_type:'courses';
        $course_cut=$site_now->modify('-'.(int)$s['temp_course_cleanup_days'].' days')->format('Y-m-d H:i:s');
        $orphan_ids=$this->wpdb->get_col($this->wpdb->prepare("SELECT p.ID FROM {$this->wpdb->posts} p INNER JOIN {$this->wpdb->postmeta} im ON im.post_id=p.ID AND im.meta_key='_tlms_ps_internal_course' AND im.meta_value='1' LEFT JOIN {$this->wpdb->postmeta} rm ON rm.post_id=p.ID AND rm.meta_key='_tlms_ps_rating_course' WHERE p.post_type=%s AND p.post_status NOT IN ('trash','auto-draft') AND rm.meta_id IS NULL AND p.post_date < %s",$course_pt,$course_cut));
        foreach((array)$orphan_ids as $course_id){
            $course_id=(int)$course_id; $session_id=(int)get_post_meta($course_id,'_tlms_ps_session_id',true); $safe=false;
            if($session_id){
                $session=$this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$table} WHERE id=%d LIMIT 1",$session_id));
                $safe=!$session || in_array((string)$session->status,['failed','rejected','declined'],true);
                if($session && (int)$session->order_id>0 && !in_array((string)$session->status,['failed','rejected','declined'],true)) $safe=false;
            } else $safe=!get_post_meta($course_id,'_tcsp_sponsor_id',true); // sponsorship checkout courses are removed by their own module.
            if($safe && get_post_meta($course_id,'_tlms_ps_internal_course',true)==='1' && !get_post_meta($course_id,'_tlms_ps_rating_course',true)){
                if(wp_delete_post($course_id,true)) $deleted++;
            }
        }
        $reminders_sent = $this->send_upcoming_session_reminders((int)$s['reminder_hours_before']);
        return ['expired'=>$expired,'deleted'=>$deleted,'manual'=>$manual,'reminders_sent'=>$reminders_sent];
    }

    /**
     * Emails the tutor and student a one-time reminder shortly before a
     * paid/confirmed private session starts. Runs from the hourly
     * maintenance cron (and the manual "Clean legacy courses now"
     * action), so it needs no extra scheduled event of its own.
     */
    private function send_upcoming_session_reminders($hours_before) {
        $hours_before = (int) $hours_before;
        if ($hours_before <= 0) return 0; // Reminders disabled.
        $table = $this->table(self::TABLE_SESSIONS);
        $now_ts = time();
        // Query a broad site-local date range (including timezone-edge days),
        // then validate each row against its own tutor/session timezone below.
        $window_days = (int) ceil($hours_before / 24) + 2;
        $site_tz = wp_timezone();
        $range_start = wp_date('Y-m-d', $now_ts - DAY_IN_SECONDS, $site_tz);
        $range_end = wp_date('Y-m-d', $now_ts + $window_days * DAY_IN_SECONDS, $site_tz);
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM {$table} WHERE status IN ('paid','confirmed') AND reminder_sent_at IS NULL AND session_date IS NOT NULL AND start_time IS NOT NULL AND session_date BETWEEN %s AND %s",
            $range_start, $range_end
        ));
        $sent = 0;
        foreach ((array) $rows as $row) {
            try {
                $tz = new DateTimeZone(!empty($row->session_timezone) ? (string) $row->session_timezone : $this->user_timezone_string((int) $row->tutor_id));
                $start = new DateTimeImmutable($row->session_date . ' ' . substr((string) $row->start_time, 0, 8), $tz);
            } catch (Exception $e) { continue; }
            $start_ts = $start->getTimestamp();
            // Skip sessions that already started, and ones that aren't due for a reminder yet.
            if ($start_ts <= $now_ts || ($start_ts - $now_ts) > $hours_before * HOUR_IN_SECONDS) continue;

            $subject_label = trim((string) $row->subject) ?: __('Private tutoring session', 'tutor-learning-suite');
            $when = sprintf('%s (%s)', $start->format('l, M j, Y H:i'), $this->timezone_label($tz->getName(), $start_ts));
            $tutor = get_userdata((int) $row->tutor_id);
            $student = (int) $row->student_id ? get_userdata((int) $row->student_id) : null;

            if ($student && is_email($student->user_email)) {
                wp_mail(
                    $student->user_email,
                    sprintf(__('Reminder: your private session is coming up — %s', 'tutor-learning-suite'), $subject_label),
                    sprintf(__("This is a reminder that your private tutoring session starts soon.\n\nSubject: %1\$s\nWhen: %2\$s\n\nLog in to your Private Sessions dashboard for the meeting link and details.", 'tutor-learning-suite'), $subject_label, $when)
                );
            }
            if ($tutor && is_email($tutor->user_email)) {
                wp_mail(
                    $tutor->user_email,
                    sprintf(__('Reminder: upcoming private session — %s', 'tutor-learning-suite'), $subject_label),
                    sprintf(__("This is a reminder that you have a private tutoring session starting soon.\n\nSubject: %1\$s\nWhen: %2\$s\n\nLog in to your Private Sessions dashboard for details.", 'tutor-learning-suite'), $subject_label, $when)
                );
            }
            $this->wpdb->update($table, ['reminder_sent_at' => current_time('mysql')], ['id' => (int) $row->id], ['%s'], ['%d']);
            $sent++;
        }
        return $sent;
    }

    /**
     * Build Tutor LMS calendar events from confirmed private sessions.
     * No extra posts or calendar records are created; the event is derived from
     * the authoritative private-session row so changes stay in sync.
     */
    private function get_calendar_private_session_events($user_id) {
        $user_id = absint($user_id);
        if (!$user_id) return [];
        $table = $this->table(self::TABLE_SESSIONS);
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM {$table} WHERE session_date IS NOT NULL AND start_time IS NOT NULL AND end_time IS NOT NULL AND status IN ('paid','confirmed','completed') AND (tutor_id=%d OR student_id=%d OR learner_user_id=%d) ORDER BY session_date ASC, start_time ASC",
            $user_id, $user_id, $user_id
        ));
        $events = [];
        $viewer_tz = new DateTimeZone($this->user_timezone_string($user_id));
        foreach ((array)$rows as $row) {
            try {
                $source_tz = !empty($row->session_timezone) ? (string)$row->session_timezone : $this->user_timezone_string((int)$row->tutor_id);
                $source_zone = new DateTimeZone($source_tz);
                $start = new DateTimeImmutable($row->session_date.' '.substr((string)$row->start_time,0,8), $source_zone);
                $end   = new DateTimeImmutable($row->session_date.' '.substr((string)$row->end_time,0,8), $source_zone);
                $start_local = $start->setTimezone($viewer_tz);
                $end_local = $end->setTimezone($viewer_tz);
            } catch (Exception $e) { continue; }
            $tutor = get_userdata((int)$row->tutor_id);
            $student = get_userdata((int)$row->student_id);
            $is_tutor_view = ((int)$row->tutor_id === $user_id);
            $other_name = $is_tutor_view
                ? ($student ? $student->display_name : __('Student','tutor-learning-suite'))
                : ($tutor ? $tutor->display_name : __('Tutor','tutor-learning-suite'));
            $subject = trim((string)$row->subject) ?: __('Private tutoring session','tutor-learning-suite');
            $title = sprintf(__('Private session with %s — %s','tutor-learning-suite'), $other_name, $subject);
            // Keep DateTimeImmutable objects for all subsequent formatting.
            // Only create string representations where the calendar payload needs them.
            $start_local_dt = $start_local;
            $end_local_dt   = $end_local;
            $start_local    = $start_local_dt->format('Y-m-d H:i:s');
            $end_local      = $end_local_dt->format('Y-m-d H:i:s');
            $start_iso   = $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z');
            $end_iso     = $end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z');
            $event_id    = 'tlms-private-session-'.(int)$row->id;
            $event_url   = $this->dashboard_url($is_tutor_view ? 'private-sessions' : 'my-private-sessions');
            $events[] = [
                'id'              => $event_id,
                'ID'              => $event_id,
                'session_id'      => (int)$row->id,
                'title'           => $title,
                'name'            => $title,
                'summary'         => $title,
                'post_title'      => $title,
                'post_content'    => (string)($row->meeting_details ?: $row->proposal_message ?: ''),
                'post_parent'     => 0,
                'post_type'       => 'tutor-google-meet',
                'post_status'     => 'publish',
                'post_date'       => $start_local,
                'start'           => $start_iso,
                'end'             => $end_iso,
                'start_datetime'  => $start_iso,
                'end_datetime'    => $end_iso,
                'start_date_time' => $start_iso,
                'end_date_time'   => $end_iso,
                'startDateTime'   => $start_iso,
                'endDateTime'     => $end_iso,
                'startAt'         => $start_iso,
                'endAt'           => $end_iso,
                'date'            => $start_local_dt->format('Y-m-d'),
                'event_date'      => $start_local_dt->format('Y-m-d'),
                'start_date'      => $start_local,
                'end_date'        => $end_local,
                'start_time'      => $start_local_dt->format('H:i:s'),
                'end_time'        => $end_local_dt->format('H:i:s'),
                'time'            => $start_local_dt->format('H:i').'–'.$end_local_dt->format('H:i').' ('.$this->timezone_label($viewer_tz->getName(), $start_local_dt->getTimestamp()).')',
                'guid'            => $event_url,
                'post_link'       => $event_url,
                'url'             => $event_url,
                'link'            => $event_url,
                'meta_info'       => [
                    'gm_start_date'  => $start_local_dt->format('Y-m-d'),
                    'start_datetime' => $start_local,
                    'expire_date'    => $end_local,
                    'is_expired'     => (time() > $end->getTimestamp()),
                    'private_session'=> true,
                    'timezone'        => $viewer_tz->getName(),
                ],
                'course_info'     => ['title'=>__('Private Session','tutor-learning-suite'),'post_link'=>$event_url],
                'timezone'        => $viewer_tz->getName(),
                'duration_minutes'=> (int)$row->duration_minutes,
                'event_type'      => 'meeting',
                'type'            => 'meeting',
                'calendar_type'   => 'meeting',
                'category'        => 'meeting',
                'meeting_type'    => 'private_session',
                'private_session' => true,
                'source'          => 'tlms_private_sessions',
                'course_id'       => (int)$row->native_course_id,
                'meeting_id'      => 0,
                'is_private'      => true,
            ];
        }
        return $events;
    }

    /** Add private-session events without replacing Tutor LMS's own calendar items. */
    public function inject_private_sessions_into_calendar_rest($response, $server = null, $request = null) {
        $route = '';
        $method = 'GET';
        if ($request instanceof WP_REST_Request) {
            $route = (string)$request->get_route();
            $method = strtoupper((string)$request->get_method());
        }
        if ($route && stripos($route, 'calendar') === false) return $response;
        if ($method !== 'GET') return $response;
        $user_id = $this->calendar_user_from_request($request);
        if (!$user_id || !is_user_logged_in() || (!current_user_can('manage_options') && get_current_user_id() !== $user_id)) return $response;
        $events = $this->get_calendar_private_session_events($user_id);
        if (!$events) return $response;

        $data = $response instanceof WP_REST_Response ? $response->get_data() : $response;
        $data = $this->append_events_to_calendar_payload($data, $events);
        if ($response instanceof WP_REST_Response) {
            $response->set_data($data);
            return $response;
        }
        return $data;
    }

    private function calendar_user_from_request($request) {
        $current = get_current_user_id();
        if (!$request instanceof WP_REST_Request) return $current;
        $route = (string)$request->get_route();
        if (preg_match('~/students/(\d+)/calendar~i', $route, $m)) return absint($m[1]);
        if (preg_match('~/instructors/(\d+)/calendar~i', $route, $m)) return absint($m[1]);
        foreach (['user_id','student_id','instructor_id','author_id'] as $key) {
            $v = absint($request->get_param($key));
            if ($v) return $v;
        }
        return $current;
    }

    /** A calendar may only be queried for the current account, except by a site administrator. */
    public function calendar_rest_permission($request) {
        if (!is_user_logged_in()) return false;
        $target = $this->calendar_user_from_request($request);
        if (!$target) return false;
        return (int) $target === (int) get_current_user_id() || current_user_can('manage_options');
    }

    public function add_private_calendar_localized_data($localize_data) {
        if (!is_array($localize_data) || !is_user_logged_in()) return $localize_data;
        $events = $this->get_calendar_private_session_events(get_current_user_id());
        if ($events) {
            $localize_data['tlms_private_session_calendar_events'] = $events;
            $localize_data['tlms_private_session_calendar_endpoint'] = esc_url_raw(rest_url('tlms-private-sessions/v1/calendar'));
        }
        return $localize_data;
    }

    private function append_events_to_calendar_payload($data, $events, $depth = 0) {
        if (!$events || $depth > 10) return $data;

        if (!is_array($data)) {
            return ['events' => $events];
        }

        // A bare list of event objects: append directly.
        if ($this->looks_like_event_list($data)) {
            $existing = [];
            foreach ($data as $item) {
                if (is_array($item) && isset($item['id'])) $existing[(string)$item['id']] = true;
            }
            foreach ($events as $event) {
                if (empty($existing[(string)$event['id']])) $data[] = $event;
            }
            return $data;
        }

        $event_keys = [
            'events','calendar_events','calendarEvents','items','entries','results','meetings',
            'google_meets','googleMeetings','zoom_meetings','zoomMeetings','private_sessions'
        ];

        $touched = false;
        foreach ($event_keys as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $data[$key] = $this->merge_calendar_event_list($data[$key], $events);
                $touched = true;
            }
        }

        // Date-keyed calendars, e.g. { '2026-09-06': [...] }.
        foreach ($events as $event) {
            $date = (string)($event['date'] ?? '');
            if (!$date) continue;
            foreach (array_keys($data) as $key) {
                if ((string)$key === $date && is_array($data[$key])) {
                    $data[$key] = $this->merge_calendar_event_list($data[$key], [$event]);
                    $touched = true;
                }
            }
        }

        // Recurse through common wrapper/object containers and any nested arrays.
        foreach ($data as $key => $value) {
            if (!is_array($value)) continue;
            if ($key === 'private_sessions') continue;
            $new_value = $this->append_events_to_calendar_payload($value, $events, $depth + 1);
            if ($new_value !== $value) {
                $data[$key] = $new_value;
                $touched = true;
            }
        }

        // Always expose a canonical collection as a fallback for calendar UIs or addons
        // which read a dedicated collection from the response.
        $data['private_sessions'] = $this->merge_calendar_event_list(
            isset($data['private_sessions']) && is_array($data['private_sessions']) ? $data['private_sessions'] : [],
            $events
        );

        return $data;
    }

    private function merge_calendar_event_list($list, $events) {
        if (!is_array($list)) $list = [];
        $existing = [];
        foreach ($list as $item) {
            if (is_array($item) && isset($item['id'])) $existing[(string)$item['id']] = true;
        }
        foreach ($events as $event) {
            $id = (string)($event['id'] ?? '');
            if ($id !== '' && empty($existing[$id])) {
                $list[] = $event;
                $existing[$id] = true;
            }
        }
        return $list;
    }

    private function looks_like_event_list($data) {
        if (!$data || !is_array($data)) return false;
        $first = reset($data);
        return is_array($first) && (isset($first['title']) || isset($first['event_type']) || isset($first['start']) || isset($first['date']));
    }

    /** Compatibility for releases/addons that expose a PHP calendar-data filter. */
    public function filter_native_calendar_events($calendar_data, $user_id=0) {
        $uid = absint($user_id ?: get_current_user_id());
        if (!$uid || !is_user_logged_in() || ((int) $uid !== (int) get_current_user_id() && !current_user_can('manage_options'))) return $calendar_data;
        $events = $this->get_calendar_private_session_events($uid);
        if (!$events) return $calendar_data;
        return $this->append_events_to_calendar_payload($calendar_data, $events);
    }

    /**
     * Cancel a private-session request without moving or refunding money.
     * Only the booking payer/initiator, assigned tutor or an administrator may
     * cancel. A linked learner who is not the payer may view the booking but
     * cannot cancel a booking made by their guardian.
     */
    public function ajax_cancel_session(){
        check_ajax_referer(self::NONCE,'nonce');
        if(!is_user_logged_in()) wp_send_json_error(['message'=>__('Please log in.','tutor-learning-suite')],401);
        $actor=get_current_user_id();
        $id=absint($_POST['session_id']??0);
        $reason=sanitize_textarea_field(wp_unslash($_POST['reason']??''));
        if(function_exists('mb_substr')) $reason=mb_substr($reason,0,500); else $reason=substr($reason,0,500);
        $row=$this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->table(self::TABLE_SESSIONS)} WHERE id=%d LIMIT 1",$id));
        if(!$row) wp_send_json_error(['message'=>__('Booking not found.','tutor-learning-suite')],404);
        $is_admin=current_user_can('manage_options');
        $is_tutor=((int)$row->tutor_id===$actor && $this->is_instructor($actor));
        $is_payer=((int)$row->student_id===$actor);
        if(!$is_admin && !$is_tutor && !$is_payer) wp_send_json_error(['message'=>__('Only the booking payer, assigned tutor or administrator can cancel this booking.','tutor-learning-suite')],403);
        $allowed_statuses=['pending','pending_tutor','pending_admin','proposal_sent','approved','paid','confirmed'];
        if(!in_array((string)$row->status,$allowed_statuses,true)) wp_send_json_error(['message'=>__('This booking can no longer be cancelled from this screen.','tutor-learning-suite')],409);
        if($is_payer && !$is_tutor && !$is_admin && !empty($row->session_date) && !empty($row->start_time)){
            $hours=max(0,(int)$this->settings()['cancel_hours']);
            if($hours>0){
                try{
                    $tz=new DateTimeZone(!empty($row->session_timezone)?(string)$row->session_timezone:$this->user_timezone_string((int)$row->tutor_id));
                    $start=new DateTimeImmutable($row->session_date.' '.substr((string)$row->start_time,0,8),$tz);
                    $now=new DateTimeImmutable('now',$tz);
                    if($start->getTimestamp() < $now->getTimestamp()+($hours*HOUR_IN_SECONDS)) wp_send_json_error(['message'=>sprintf(__('The cancellation notice period is %d hours. Contact the tutor or site support because this session is inside that window.','tutor-learning-suite'),$hours)],409);
                }catch(Exception $e){wp_send_json_error(['message'=>__('The session timezone could not be validated. Contact site support to cancel this booking.','tutor-learning-suite')],409);}
            }
        }
        if($this->update_status($id,'cancelled')!==1) wp_send_json_error(['message'=>__('This booking changed while cancellation was being processed. Refresh and try again.','tutor-learning-suite')],409);
        if($reason!=='') $this->wpdb->update($this->table(self::TABLE_SESSIONS),['student_response'=>$reason,'updated_at'=>current_time('mysql')],['id'=>$id],['%s','%s'],['%d']);
        if((int)$row->native_course_id) $this->delete_internal_course_after_payment($id,(int)$row->native_course_id);
        if(class_exists('TLS_Age_Safety')) TLS_Age_Safety::audit('private_session_cancelled',(int)$row->student_id,['session_id'=>$id,'tutor_id'=>(int)$row->tutor_id,'action'=>'cancelled']);
        $recipients=[];
        if($is_admin){
            $recipients[]=(int)$row->student_id;
            $recipients[]=(int)$row->tutor_id;
        } else {
            $recipients[]=$is_tutor?(int)$row->student_id:(int)$row->tutor_id;
        }
        $notice=sprintf(__('Private tutoring booking #%1$d for %2$s has been cancelled. If money was already paid directly to the tutor, any refund or settlement must be arranged directly between the payer and tutor. Ustudy does not hold or return those funds.','tutor-learning-suite'),$id,(string)$row->subject);
        foreach(array_unique(array_filter($recipients)) as $recipient_id){
            if((int)$recipient_id===(int)$actor) continue;
            $other=get_userdata($recipient_id);
            if($other && is_email($other->user_email)) wp_mail($other->user_email,__('Private tutoring booking cancelled','tutor-learning-suite'),$notice);
        }
        $message=__('Booking cancelled. Ustudy has not transferred or refunded any money. If payment was already made, the payer and tutor must settle it directly.','tutor-learning-suite');
        wp_send_json_success(['message'=>$message,'status'=>'cancelled']);
    }

    private function update_status($id,$status){
        $id=absint($id); $status=sanitize_key($status); if(!$id||$status==='') return false;
        $table=$this->table(self::TABLE_SESSIONS);
        $row=$this->wpdb->get_row($this->wpdb->prepare("SELECT status,tutor_responded_at FROM {$table} WHERE id=%d LIMIT 1",$id));
        if(!$row) return false;
        $allowed = [
            'pending' => ['pending_tutor','failed','rejected','cancelled'],
            'pending_tutor' => ['pending_admin','proposal_sent','approved','confirmed','rejected','failed','cancelled'],
            'pending_admin' => ['approved','confirmed','rejected','failed','cancelled'],
            'proposal_sent' => ['approved','pending_tutor','rejected','failed','cancelled'],
            'approved' => ['paid','confirmed','rejected','failed','cancelled'],
            'paid' => ['confirmed','completed','rejected','failed','cancelled'],
            'confirmed' => ['completed','rejected','failed','cancelled'],
        ];
        $from=(string)$row->status;
        if($from===$status) return 0;
        if(empty($allowed[$from]) || !in_array($status,$allowed[$from],true)) return false;
        $responded = ($from==='pending_tutor' && empty($row->tutor_responded_at));
        if($responded){
            $updated=$this->wpdb->query($this->wpdb->prepare("UPDATE {$table} SET status=%s,tutor_responded_at=%s,updated_at=%s WHERE id=%d AND status=%s",$status,current_time('mysql'),current_time('mysql'),$id,$from));
        } else {
            $updated=$this->wpdb->query($this->wpdb->prepare("UPDATE {$table} SET status=%s,updated_at=%s WHERE id=%d AND status=%s",$status,current_time('mysql'),$id,$from));
        }
        return 1 === (int)$updated ? 1 : false;
    }
    private function session_by_order($order_id){return $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->table(self::TABLE_SESSIONS)} WHERE order_id=%d LIMIT 1",$order_id));}
    public function inject_public_profile_button(){
        // TLS custom public profiles render the complete booking calendar and form in the sidebar.
        if ( ! empty( $GLOBALS['tls_public_instructor_id'] ) ) { return; }
        if(!$this->settings()['enabled']) return;
        $username='';
        if(isset($GLOBALS['wp']->query_vars['tutor_profile_username'])) $username=sanitize_user((string)$GLOBALS['wp']->query_vars['tutor_profile_username']);
        if(!$username) $username=sanitize_user((string)get_query_var('tutor_profile_username'));
        if(!$username) return;
        $u=get_user_by('login',$username); if(!$u) $u=get_user_by('slug',$username);
        if(!$u || !$this->is_instructor($u->ID) || !$this->tutor_accepts_private_sessions($u->ID)) return;
        $modal_id='tlms-ps-book-modal-'.(int)$u->ID;
        $button_label=__('Book Private Session','tutor-learning-suite');
        echo '<span class="tlms-ps-profile-book-host" hidden><button type="button" class="tutor-btn tutor-btn-primary tlms-ps-profile-book" data-tlms-ps-open="#'.esc_attr($modal_id).'">'.esc_html($button_label).'</button></span>';
        echo '<div id="'.esc_attr($modal_id).'" class="tlms-ps-modal" hidden aria-hidden="true"><div class="tlms-ps-modal-backdrop" data-tlms-ps-close></div><div class="tlms-ps-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="'.esc_attr($modal_id).'-title"><button type="button" class="tlms-ps-modal-close" data-tlms-ps-close aria-label="'.esc_attr__('Close','tutor-learning-suite').'">&times;</button><div class="tlms-ps-modal-heading"><span class="tlms-ps-modal-kicker">'.esc_html__('Private tutoring','tutor-learning-suite').'</span><h2 id="'.esc_attr($modal_id).'-title">'.esc_html($button_label).'</h2><p>'.esc_html__('Tell the tutor what you need. They will reply with a schedule, price and proposal.','tutor-learning-suite').'</p></div>'.$this->shortcode_booking(['tutor_id'=>$u->ID]).'</div></div>';
        echo '<script>(function(){function place(){var modal=document.getElementById('.wp_json_encode($modal_id).');if(!modal)return;var host=modal.previousElementSibling;if(!host)return;var b=host.querySelector(".tlms-ps-profile-book");if(!b)return;var area=document.querySelector(".pp-area");if(!area)return;var target=area.querySelector(".profile-name,.pp-profile-info,.tutor-profile-details,.tutor-profile-meta");(target||area).appendChild(b);host.remove();}if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",place);else place();new MutationObserver(place).observe(document.body,{childList:true,subtree:true});})();</script>';
    }
}

register_activation_hook(__FILE__, ['TLMS_Private_Sessions','activate']);
register_deactivation_hook(__FILE__, function(){ wp_clear_scheduled_hook(TLMS_Private_Sessions::CRON_HOOK); });
TLMS_Private_Sessions::instance();
