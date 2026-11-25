<?php
/**
 * Front-end sponsorship portal and sponsorship order tracker.
 *
 * @package Tutor_Course_Search_Pro
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class TCSP_Sponsorship_Portal {
    const CPT = 'tcsp_sponsor_order';
    const NONCE = 'tcsp_sponsor_portal';
    const PAGE_SLUG = 'sponsorship';

    private $settings;

    public function __construct( TCSP_Settings $settings ) { $this->settings = $settings; }

    public function register() {
        add_action( 'init', array( $this, 'register_cpt' ), 5 );
        add_action( 'init', array( $this, 'maybe_create_page' ), 31 );
        add_shortcode( 'tcsp_sponsorship_portal', array( $this, 'shortcode' ) );
        add_action( 'admin_init', array( $this, 'admin_actions' ) );
        add_action( 'admin_init', array( $this, 'maybe_create_page_admin' ) );
        add_action( 'template_redirect', array( $this, 'handle_frontend_action' ) );
        add_action( 'template_redirect', array( $this, 'reconcile_native_orders' ), 1 );
        add_action( 'template_redirect', array( $this, 'handle_cart_requests' ), 2 );
        add_action( 'init', array( $this, 'migrate_legacy_checkout_products' ), 30 );
        add_action( 'tutor_order_payment_status_changed', array( $this, 'on_payment_status_changed' ), 30, 3 );
        add_action( 'tcsp_process_scheduled_sponsorships', array( $this, 'process_scheduled_sponsorships' ) );
        add_action( 'tcsp_process_scheduled_sponsorships', array( $this, 'send_expiry_reminders' ), 20 );
        if ( ! wp_next_scheduled( 'tcsp_process_scheduled_sponsorships' ) ) {
            wp_schedule_event( time() + 300, 'hourly', 'tcsp_process_scheduled_sponsorships' );
        }
        add_filter( 'load_dashboard_template_part_from_other_location', array( $this, 'dashboard_template_location' ), 20, 1 );
        add_filter( 'tutor_new_earning_data', array( $this, 'sponsorship_earning_data' ), 20 );
        add_filter( 'tioc_course_payee_id', array( $this, 'offline_payee_for_sponsorship' ), 10, 2 );
        add_filter( 'tutor_dashboard/instructor_nav_items', array( $this, 'dashboard_nav' ) );
        add_action( 'wp_head', array( $this, 'print_dashboard_nav_icon_style' ) );
    }

    public function register_cpt() {
        register_post_type( self::CPT, array(
            'labels' => array( 'name' => 'Sponsorship Orders', 'singular_name' => 'Sponsorship Order' ),
            'public' => false, 'show_ui' => false, 'supports' => array( 'title' ),
            'capability_type' => 'post', 'map_meta_cap' => true,
        ) );
    }

    public function maybe_create_page() {
        if ( is_admin() ) return;
        $this->ensure_frontend_pages();
    }

    public function maybe_create_page_admin() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $this->ensure_frontend_pages();
    }

    private function ensure_frontend_pages() {
        $pages = array();
        if ( $this->settings->get( 'enable_tutor_search' ) ) {
            $slug = sanitize_title( $this->settings->get( 'tutor_page_slug' ) ?: 'tutors' );
            $pages[] = array( 'slug' => $slug, 'title' => __( 'Tutors', 'tutor-learning-suite' ), 'content' => '[tcsp_tutor_search]' );
        }
        foreach ( $pages as $page ) {
            $existing = get_page_by_path( $page['slug'], OBJECT, 'page' );
            if ( $existing ) {
                $content = (string) get_post_field( 'post_content', $existing->ID );
                if ( false === strpos( $content, '[' ) ) {
                    wp_update_post( array( 'ID' => $existing->ID, 'post_content' => $page['content'] ) );
                }
                continue;
            }
            wp_insert_post( array( 'post_title' => $page['title'], 'post_name' => $page['slug'], 'post_content' => $page['content'], 'post_status' => 'publish', 'post_type' => 'page' ) );
        }
    }

    public function dashboard_nav( $links ) {
        if ( ! is_user_logged_in() ) return $links;
        // Use a verified Tutor icon font class directly (full class name, not a slug
        // that needs a "tutor-icon-" prefix appended). "offer" renders a badge/promo
        // glyph that fits sponsorship/promotion well and exists across Tutor LMS
        // versions, unlike the previous TUTOR\Icon::ANNOUNCEMENT lookup which either
        // silently fell back or produced a double-prefixed, non-existent class.
        $icon = apply_filters( 'tcsp_sponsorship_dashboard_nav_icon', $this->tutor_v4() ? 'announcement' : 'tutor-icon-speaker' );
        $links['tcsp-sponsorship'] = array(
            'title' => __( 'Sponsorship', 'tutor-learning-suite' ),
            'url'   => function_exists( 'tutor_utils' ) ? tutor_utils()->tutor_dashboard_url( 'tcsp-sponsorship' ) : home_url( '/' . self::PAGE_SLUG . '/' ),
            'icon'  => $icon,
            'active_icon' => $icon,
            'auth_cap' => tutor()->instructor_role,
        );
        return $links;
    }

    /**
     * Whether Tutor LMS renders an 'icon' font class, an SVG-based icon
     * component, or nothing at all for a filter-added nav item differs by
     * version and can't be relied on. To guarantee the icon actually shows,
     * inject a small CSS rule that draws it via ::before on the dashboard
     * nav link itself, independent of how Tutor renders the menu markup.
     * The href always contains "tcsp-sponsorship" (the dashboard sub-page
     * slug), so this only targets our own nav item.
     */
    private function tutor_v4() {
        return defined( 'TUTOR_VERSION' ) && version_compare( TUTOR_VERSION, '4.0.0', '>=' );
    }

    public function print_dashboard_nav_icon_style() {
        if ( ! is_user_logged_in() || $this->tutor_v4() ) return; // Tutor 4 draws the SVG icon itself.
        // Megaphone/announcement glyph — matches the dashicons-megaphone icon
        // already used for the wp-admin "Sponsorship" menu, and reads clearly
        // as promotion/sponsorship rather than a generic star. Built as plain
        // straight-line shapes (no arcs) so the inline SVG is guaranteed valid,
        // and rendered as a background-image (not a mask) so it's not at the
        // mercy of browser differences in mask alpha/luminance handling.
        $svg = "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%23555d66'>"
            . "<path d='M3 9v6h3l7 5V4L6 9H3z'/>"
            . "<rect x='1' y='10' width='2' height='4' rx='1'/>"
            . "<circle cx='18' cy='12' r='1.4'/>"
            . "<circle cx='20.6' cy='12' r='1'/>"
            . "</svg>";
        $data_uri = 'data:image/svg+xml,' . rawurlencode( $svg );
        ?>
        <style>
        a[href*="tcsp-sponsorship"]::before{content:"";display:inline-block;width:16px;height:16px;margin-right:8px;vertical-align:-3px;background:no-repeat center/contain url("<?php echo esc_attr( $data_uri ); ?>");}
        </style>
        <?php
    }

    public function dashboard_template_location( $path ) {
        if ( ! function_exists( 'tutor_utils' ) || ! is_user_logged_in() ) return $path;
        global $wp_query;
        $page = isset( $wp_query->query_vars['tutor_dashboard_page'] ) ? sanitize_key( $wp_query->query_vars['tutor_dashboard_page'] ) : '';
        if ( 'tcsp-sponsorship' !== $page || ! $this->user_is_instructor( get_current_user_id() ) ) return $path;
        return plugin_dir_path( dirname( __FILE__ ) ) . 'templates/dashboard/tcsp-sponsorship.php';
    }

    public function render_dashboard_template() {
        echo $this->render_portal_view();
    }

    private function user_is_instructor( $user_id ) {
        $u = get_userdata( $user_id );
        if ( ! $u ) return false;
        return in_array( 'tutor_instructor', (array) $u->roles, true ) || in_array( 'administrator', (array) $u->roles, true );
    }

    private function own_courses( $user_id ) {
        global $wpdb;
        $course_pt = TCSP_Plugin::COURSE_CPT;
        // Show the tutor's real published courses, including courses linked through Tutor LMS
        // multi-instructor assignments. Only hide internal checkout/temp courses generated by
        // Tutor Course Search Pro and the supplied Private Sessions plugin.
        $ids = get_posts( array(
            'post_type' => $course_pt,
            'post_status' => 'publish',
            'author' => absint( $user_id ),
            'posts_per_page' => -1,
            'fields' => 'ids',
            'orderby' => 'title',
            'order' => 'ASC',
        ) );
        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT CAST(um.meta_value AS UNSIGNED)
             FROM {$wpdb->usermeta} um
             INNER JOIN {$wpdb->posts} p
               ON p.ID=CAST(um.meta_value AS UNSIGNED)
              AND p.post_type=%s
              AND p.post_status='publish'
             WHERE um.user_id=%d
               AND um.meta_key='_tutor_instructor_course_id'",
            $course_pt,
            absint( $user_id )
        ) );
        $ids = array_values( array_unique( array_merge( array_map( 'absint', (array) $ids ), array_map( 'absint', (array) $rows ) ) ) );
        $out = array();
        foreach ( $ids as $id ) {
            if ( $id <= 0 || $course_pt !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) ) continue;
            // Temporary/private-session courses created by the supplied Private Sessions plugin.
            if ( '1' === (string) get_post_meta( $id, '_tlms_ps_internal_course', true ) ) continue;
            if ( '1' === (string) get_post_meta( $id, '_tlms_ps_rating_course', true ) ) continue;
            $title = get_the_title( $id );
            if ( $title ) $out[ $id ] = $title;
        }
        asort( $out, SORT_NATURAL | SORT_FLAG_CASE );
        return $out;
    }

    private function order_meta( $id, $key, $default = '' ) { $v = get_post_meta( $id, $key, true ); return '' !== $v ? $v : $default; }

    private function orders( $user_id ) {
        $q = new WP_Query( array( 'post_type' => self::CPT, 'post_status' => 'private', 'posts_per_page' => 100, 'author' => $user_id, 'orderby' => 'date', 'order' => 'DESC', 'no_found_rows' => true ) );
        return $q->posts;
    }

    private function has_open_sponsorship( $user_id, $target_type, $target_id ) {
        $existing = get_posts( array(
            'post_type' => self::CPT,
            'post_status' => 'private',
            'author' => absint( $user_id ),
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_query' => array(
                array( 'key' => '_tcsp_target_type', 'value' => $target_type ),
                array( 'key' => '_tcsp_target_id', 'value' => absint( $target_id ) ),
                array( 'key' => '_tcsp_status', 'value' => array( 'pending', 'paid', 'scheduled' ), 'compare' => 'IN' ),
            ),
            'no_found_rows' => true,
        ) );
        return ! empty( $existing );
    }

    private function plan_locked( $user_id, $target_type, $target_id, $tier ) {
        // Short-lived lock prevents rapid double-submit/race creation.
        $lock_key = 'tcsp_sp_lock_' . md5( absint( $user_id ) . '|' . $target_type . '|' . absint( $target_id ) );
        if ( get_transient( $lock_key ) ) return true;
        set_transient( $lock_key, sanitize_key( $tier ), 15 );
        return false;
    }

    private function plan_label( $type, $tier ) {
        $tiers = 'tutor' === $type ? $this->settings->tutor_promotion_tiers() : $this->settings->promotion_tiers();
        return isset( $tiers[ $tier ]['label'] ) ? $tiers[ $tier ]['label'] : $tier;
    }

    private function plan( $type, $tier ) {
        $tiers = 'tutor' === $type ? $this->settings->tutor_promotion_tiers() : $this->settings->promotion_tiers();
        return isset( $tiers[ $tier ] ) ? $tiers[ $tier ] : null;
    }

    private function max_units() {
        return min( 12, max( 1, absint( $this->settings->get( 'sponsorship_max_units' ) ?: 12 ) ) );
    }

    private function normalize_units( $units ) {
        return min( $this->max_units(), max( 1, absint( $units ) ) );
    }

    private function allowed_unit_options() {
        $max = $this->max_units();
        $options = array( 1, 3, 6, 12 );
        $options = array_values( array_filter( $options, function( $n ) use ( $max ) { return $n <= $max; } ) );
        if ( ! in_array( $max, $options, true ) ) $options[] = $max;
        return array_values( array_unique( $options ) );
    }

    private function unit_discount_pct( $plan, $units ) {
        $units = $this->normalize_units( $units );
        if ( $units >= 12 ) return min( 100, max( 0, (float) ( $plan['discount_12'] ?? 0 ) ) );
        if ( $units >= 6 ) return min( 100, max( 0, (float) ( $plan['discount_6'] ?? 0 ) ) );
        if ( $units >= 3 ) return min( 100, max( 0, (float) ( $plan['discount_3'] ?? 0 ) ) );
        return 0;
    }

    private function purchase_totals( $plan, $units ) {
        $units = $this->normalize_units( $units );
        $base = max( 0, (float) ( $plan['price'] ?? 0 ) );
        $discount_pct = $this->unit_discount_pct( $plan, $units );
        $amount = round( $base * $units * ( 1 - ( $discount_pct / 100 ) ), 2 );
        $duration = max( 1, (int) ( $plan['duration'] ?? 1 ) ) * $units;
        return array( 'units' => $units, 'unit_price' => $base, 'discount_pct' => $discount_pct, 'amount' => $amount, 'duration' => $duration );
    }

    private function duration_unit_text( $days, $plural = true ) {
        $days = max( 1, absint( $days ) );
        if ( 1 === $days ) return $plural ? __( 'day', 'tutor-learning-suite' ) : __( 'day', 'tutor-learning-suite' );
        if ( 7 === $days ) return $plural ? __( 'week', 'tutor-learning-suite' ) : __( 'week', 'tutor-learning-suite' );
        if ( 30 === $days ) return $plural ? __( 'month', 'tutor-learning-suite' ) : __( 'month', 'tutor-learning-suite' );
        if ( 365 === $days ) return $plural ? __( 'year', 'tutor-learning-suite' ) : __( 'year', 'tutor-learning-suite' );
        if ( 0 === $days % 7 ) {
            $weeks = intdiv( $days, 7 );
            return sprintf( _n( '%d week', '%d weeks', $weeks, 'tutor-learning-suite' ), $weeks );
        }
        return $days . ' ' . __( 'days', 'tutor-learning-suite' );
    }

    private function purchase_label( $plan, $units ) {
        $totals = $this->purchase_totals( $plan, $units );
        $base_days = max( 1, (int) ( $plan['duration'] ?? 1 ) );
        if ( in_array( $base_days, array( 1, 7, 30, 365 ), true ) ) {
            $unit_name = $this->duration_unit_text( $base_days, true );
            $label = sprintf( _n( '%d %s', '%d %s', $totals['units'], 'tutor-learning-suite' ), $totals['units'], $unit_name );
        } elseif ( 0 === $base_days % 7 ) {
            $label = $totals['units'] . ' × ' . intdiv( $base_days, 7 ) . ' ' . __( 'weeks', 'tutor-learning-suite' );
        } else {
            $label = $totals['units'] . ' × ' . $base_days . ' ' . __( 'days', 'tutor-learning-suite' );
        }
        return $label . ' — ' . $totals['duration'] . ' ' . __( 'days total', 'tutor-learning-suite' );
    }

    private function create_order( $user_id, $target_type, $target_id, $tier, $units = 1 ) {
        $plan = $this->plan( $target_type, $tier );
        if ( ! $plan ) return 0;
        $totals = $this->purchase_totals( $plan, $units );
        if ( $this->plan_locked( $user_id, $target_type, $target_id, $tier ) ) return 0;
        if ( $this->has_open_sponsorship( $user_id, $target_type, $target_id ) ) return 0;
        $current_tier = '';
        $current_end = '';
        if ( 'course' === $target_type ) {
            $current_tier = (string) get_post_meta( $target_id, TCSP_Plugin::PROMOTION_TIER_META, true );
            $current_end  = (string) get_post_meta( $target_id, TCSP_Plugin::PROMOTION_END_META, true );
        } else {
            $current_tier = (string) get_user_meta( $target_id, TCSP_Tutors::TIER_META, true );
            $current_end  = (string) get_user_meta( $target_id, TCSP_Tutors::END_META, true );
        }
        $today = current_time( 'Y-m-d' );
        $mode = ( $current_tier && $current_end && $current_end >= $today )
            ? ( $current_tier === $tier ? 'extend' : 'change_plan' )
            : 'new';
        $title = sprintf( '%s sponsorship — %s — %s', $this->plan_label( $target_type, $tier ), $target_type, $this->purchase_label( $plan, $totals['units'] ) );
        $id = wp_insert_post( array( 'post_type' => self::CPT, 'post_status' => 'private', 'post_title' => $title, 'post_author' => $user_id ) );
        if ( ! $id || is_wp_error( $id ) ) return 0;
        update_post_meta( $id, '_tcsp_target_type', $target_type );
        update_post_meta( $id, '_tcsp_target_id', (int) $target_id );
        update_post_meta( $id, '_tcsp_plan', $tier );
        update_post_meta( $id, '_tcsp_units', $totals['units'] );
        update_post_meta( $id, '_tcsp_unit_price', $totals['unit_price'] );
        update_post_meta( $id, '_tcsp_discount_pct', $totals['discount_pct'] );
        update_post_meta( $id, '_tcsp_amount', $totals['amount'] );
        update_post_meta( $id, '_tcsp_duration', $totals['duration'] );
        update_post_meta( $id, '_tcsp_status', 'pending' );
        update_post_meta( $id, '_tcsp_source', 'payment' );
        update_post_meta( $id, '_tcsp_mode', $mode );
        return $id;
    }

    private function order_totals( $order_id ) {
        $units = $this->normalize_units( get_post_meta( $order_id, '_tcsp_units', true ) ?: 1 );
        $type = (string) get_post_meta( $order_id, '_tcsp_target_type', true );
        $tier = (string) get_post_meta( $order_id, '_tcsp_plan', true );
        $plan = $this->plan( $type, $tier );
        if ( ! $plan ) return array( 'units' => $units, 'unit_price' => 0, 'discount_pct' => 0, 'amount' => (float) get_post_meta( $order_id, '_tcsp_amount', true ), 'duration' => max( 1, (int) get_post_meta( $order_id, '_tcsp_duration', true ) ) );
        $totals = $this->purchase_totals( $plan, $units );
        $stored_amount = get_post_meta( $order_id, '_tcsp_amount', true );
        $stored_duration = get_post_meta( $order_id, '_tcsp_duration', true );
        if ( '' !== (string) $stored_amount ) $totals['amount'] = (float) $stored_amount;
        if ( '' !== (string) $stored_duration ) $totals['duration'] = max( 1, (int) $stored_duration );
        return $totals;
    }

    public function handle_frontend_action() {
        if ( ! is_user_logged_in() || ! $this->user_is_instructor( get_current_user_id() ) || empty( $_POST['tcsp_sponsor_action'] ) ) return;
        $nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, self::NONCE ) ) return;
        if ( 'create' !== sanitize_key( wp_unslash( $_POST['tcsp_sponsor_action'] ) ) ) return;
        if ( ! empty( $_POST['target'] ) && empty( $_POST['target_type'] ) ) {
            $parts = explode( ':', sanitize_text_field( wp_unslash( $_POST['target'] ) ) );
            if ( 2 === count( $parts ) ) { $_POST['target_type'] = $parts[0]; $_POST['target_id'] = $parts[1]; }
        }
        // The order form posts either target_type/target_id or a combined "type:id" target.
        $type = isset( $_POST['target_type'] ) ? sanitize_key( wp_unslash( $_POST['target_type'] ) ) : '';
        $tier = isset( $_POST['tier'] ) ? sanitize_key( wp_unslash( $_POST['tier'] ) ) : '';
        $units = isset( $_POST['units'] ) ? $this->normalize_units( wp_unslash( $_POST['units'] ) ) : 1;
        $id = isset( $_POST['target_id'] ) ? absint( $_POST['target_id'] ) : 0;
        $user_id = get_current_user_id();
        if ( 'course' !== $type && 'tutor' !== $type ) { $this->redirect_notice( 'invalid' ); }
        if ( ! $this->plan( $type, $tier ) ) { $this->redirect_notice( 'invalid' ); }
        if ( 'tutor' === $type ) { $id = $user_id; }
        else { $owned = $this->own_courses( $user_id ); if ( ! isset( $owned[ $id ] ) ) $this->redirect_notice( 'invalid' ); }
        $order = $this->create_order( $user_id, $type, $id, $tier, $units );
        if ( ! $order ) { $this->redirect_notice( 'exists' ); }

        $course_id = $this->checkout_course_for( $order );
        if ( ! $course_id || ! $this->add_to_native_cart( $user_id, $course_id ) ) {
            $this->set_order_status( $order, 'cancelled' );
            $this->redirect_notice( 'checkout_error' );
        }
        $this->notify_new_payment_request( $order );
        wp_safe_redirect( $this->native_cart_url() );
        exit;
    }

    private function user_can_cancel_order( $order_id, $user_id ) {
        $post = get_post( $order_id );
        if ( ! $post || self::CPT !== $post->post_type || (int) $post->post_author !== (int) $user_id ) return false;
        if ( 'manual' !== (string) get_post_meta( $order_id, '_tcsp_source', true ) ) return false;
        return in_array( (string) get_post_meta( $order_id, '_tcsp_status', true ), array( 'pending', 'paid', 'active' ), true );
    }

    private function redirect_frontend_dashboard_notice( $notice ) {
        $url = $this->portal_base_url();
        $url = add_query_arg( array( 'tcsp_sp_tab' => 2, 'tcsp_s_notice' => $notice ), $url );
        wp_safe_redirect( $url );
        exit;
    }

    public function admin_set_request_status( $order_id, $status ) {
        $order_id = absint( $order_id );
        $status = sanitize_key( $status );
        $allowed = array( 'pending', 'paid', 'scheduled', 'active', 'rejected', 'cancelled' );
        if ( ! $order_id || ! in_array( $status, $allowed, true ) ) return false;
        $post = get_post( $order_id );
        if ( ! $post || self::CPT !== $post->post_type ) return false;
        $type = (string) get_post_meta( $order_id, '_tcsp_target_type', true );
        $target = absint( get_post_meta( $order_id, '_tcsp_target_id', true ) );
        $tier = (string) get_post_meta( $order_id, '_tcsp_plan', true );
        if ( ! $this->plan( $type, $tier ) || ! $target ) return false;
        $this->set_order_status( $order_id, $status );
        return true;
    }

    public function admin_actions() {
        if ( ! TCSP_Sponsorship_Manager::can_manage() || empty( $_POST['tcsp_order_action'] ) ) return;
        $nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'tcsp_order_admin' ) ) return;
        $id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
        if ( ! $id || ! $this->manager_can_manage_order( $id, get_current_user_id() ) ) return;
        $requested_action = sanitize_key( wp_unslash( $_POST['tcsp_order_action'] ) );
        if ( 'cancel_manual' === $requested_action ) {
            if ( 'manual' !== (string) get_post_meta( $id, '_tcsp_source', true ) ) return;
            $this->set_order_status( $id, 'cancelled' );
            wp_safe_redirect( add_query_arg( array( 'page' => TCSP_Sponsorship_Manager::PAGE_SLUG, 'tab' => 'requests', 'updated' => 1 ), admin_url( 'admin.php' ) ) ); exit;
        }
        $status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
        if ( ! in_array( $status, array( 'pending','paid','active','expired','rejected','cancelled' ), true ) ) return;
        $this->set_order_status( $id, $status );
        wp_safe_redirect( add_query_arg( array( 'page' => TCSP_Sponsorship_Manager::PAGE_SLUG, 'tab' => 'requests', 'updated' => 1 ), admin_url( 'admin.php' ) ) ); exit;
    }

    private function manager_can_manage_order( $id, $user_id ) {
        if ( user_can( $user_id, 'manage_options' ) ) return true;
        $type = (string) get_post_meta( $id, '_tcsp_target_type', true );
        if ( 'course' !== $type ) return false;
        $tier = (string) get_post_meta( $id, '_tcsp_plan', true );
        $plans = $this->settings->promotion_tiers();
        return isset( $plans[ $tier ]['manager_id'] ) && absint( $plans[ $tier ]['manager_id'] ) === absint( $user_id );
    }

    public function create_manual_sponsorship( $type, $target_id, $tier, $created_by = 0, $activate = true, $units = 1 ) {
        $type = sanitize_key( (string) $type );
        $target_id = absint( $target_id );
        $tier = sanitize_key( (string) $tier );
        $created_by = absint( $created_by );
        $units = $this->normalize_units( $units );
        if ( ! $created_by ) $created_by = get_current_user_id();
        if ( ! TCSP_Sponsorship_Manager::can_manage() ) return 0;
        $plan = $this->plan( $type, $tier );
        if ( ! $plan || ! $target_id ) return 0;
        if ( 'course' === $type ) {
            if ( TCSP_Plugin::COURSE_CPT !== get_post_type( $target_id ) || 'publish' !== get_post_status( $target_id ) ) return 0;
            if ( ! user_can( $created_by, 'manage_options' ) ) {
                $manager_id = absint( $plan['manager_id'] ?? 0 );
                if ( ! $manager_id || $manager_id !== $created_by ) return 0;
            }
        } elseif ( 'tutor' === $type ) {
            if ( ! user_can( $created_by, 'manage_options' ) ) return 0;
            $user = get_userdata( $target_id );
            if ( ! $user || ! in_array( 'tutor_instructor', (array) $user->roles, true ) ) return 0;
        } else return 0;
        $order_id = $this->create_order( 'course' === $type ? (int) get_post_field( 'post_author', $target_id ) : $target_id, $type, $target_id, $tier, $units );
        if ( ! $order_id ) return 0;
        update_post_meta( $order_id, '_tcsp_source', 'manual' );
        update_post_meta( $order_id, '_tcsp_created_by', $created_by );
        if ( $activate ) {
            $this->set_order_status( $order_id, 'active' );
        }
        return $order_id;
    }


    /**
     * Create/update the account-visible sponsorship record for a manual
     * assignment made directly from the admin Courses/Tutors tables.
     */
    public function upsert_manual_assignment_record( $type, $target_id, $tier, $tutor_id, $created_by = 0, $start = '', $end = '' ) {
        $type      = sanitize_key( (string) $type );
        $target_id = absint( $target_id );
        $tier      = sanitize_key( (string) $tier );
        $tutor_id  = absint( $tutor_id );
        $created_by = absint( $created_by ) ?: get_current_user_id();
        $plan = $this->plan( $type, $tier );
        if ( ! $plan || ! $target_id || ! $tutor_id ) return 0;

        // Reuse the newest open/active record for this target. Payment records
        // remain authoritative and should not be duplicated by a manual save.
        $existing = get_posts( array(
            'post_type'      => self::CPT,
            'post_status'    => 'private',
            'posts_per_page' => 10,
            'author'         => $tutor_id,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'ids',
            'meta_query'     => array(
                'relation' => 'AND',
                array( 'key' => '_tcsp_target_type', 'value' => $type ),
                array( 'key' => '_tcsp_target_id', 'value' => $target_id ),
                array( 'key' => '_tcsp_status', 'value' => array( 'pending', 'paid', 'active' ), 'compare' => 'IN' ),
            ),
            'no_found_rows'  => true,
        ) );
        $order_id = 0;
        foreach ( $existing as $candidate ) {
            $source = (string) get_post_meta( $candidate, '_tcsp_source', true );
            if ( 'manual' === $source ) { $order_id = (int) $candidate; break; }
            if ( 'active' === (string) get_post_meta( $candidate, '_tcsp_status', true ) ) { return (int) $candidate; }
        }
        if ( ! $order_id ) {
            $label = isset( $plan['label'] ) ? $plan['label'] : $tier;
            $order_id = wp_insert_post( array(
                'post_type'   => self::CPT,
                'post_status' => 'private',
                'post_title'  => sprintf( '%s sponsorship — %s', $label, $type ),
                'post_author' => $tutor_id,
            ), true );
            if ( is_wp_error( $order_id ) || ! $order_id ) return 0;
        }

        update_post_meta( $order_id, '_tcsp_target_type', $type );
        update_post_meta( $order_id, '_tcsp_target_id', $target_id );
        update_post_meta( $order_id, '_tcsp_plan', $tier );
        update_post_meta( $order_id, '_tcsp_units', 1 );
        update_post_meta( $order_id, '_tcsp_unit_price', (float) $plan['price'] );
        update_post_meta( $order_id, '_tcsp_discount_pct', 0 );
        update_post_meta( $order_id, '_tcsp_amount', (float) $plan['price'] );
        update_post_meta( $order_id, '_tcsp_duration', (int) $plan['duration'] );
        update_post_meta( $order_id, '_tcsp_source', 'manual' );
        update_post_meta( $order_id, '_tcsp_created_by', $created_by );
        update_post_meta( $order_id, '_tcsp_status', 'active' );
        $start = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $start ) ? (string) $start : current_time( 'Y-m-d' );
        $end = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $end ) ? (string) $end : wp_date( 'Y-m-d', current_time( 'timestamp' ) + ( max( 1, (int) $plan['duration'] ) * DAY_IN_SECONDS ) );
        if ( $end < $start ) $end = $start;
        update_post_meta( $order_id, '_tcsp_start', $start );
        update_post_meta( $order_id, '_tcsp_end', $end );
        if ( 'course' === $type ) {
            update_post_meta( $target_id, TCSP_Plugin::PROMOTED_META, 1 );
            update_post_meta( $target_id, TCSP_Plugin::PROMOTION_TIER_META, $tier );
            update_post_meta( $target_id, TCSP_Plugin::PROMOTION_START_META, $start );
            update_post_meta( $target_id, TCSP_Plugin::PROMOTION_END_META, $end );
        } else {
            update_user_meta( $target_id, TCSP_Tutors::PROMOTED_META, 1 );
            update_user_meta( $target_id, TCSP_Tutors::TIER_META, $tier );
            update_user_meta( $target_id, TCSP_Tutors::START_META, $start );
            update_user_meta( $target_id, TCSP_Tutors::END_META, $end );
        }
        return (int) $order_id;
    }

    private function sponsorship_admin_owner() {
        $admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) );
        return ! empty( $admins ) ? (int) $admins[0] : 0;
    }

    /** Activate immediately when Tutor marks an order paid (admin, offline confirmation or gateway). */
    public function on_payment_status_changed( $order_id = 0, $old = '', $new = '' ) {
        $this->reconcile_native_orders( true );
    }

    public function reconcile_native_orders( $force = false ) {
        $force = true === $force;
        if ( ! function_exists( 'tutor_utils' ) || ( ! $force && wp_doing_cron() ) ) return;
        if ( ! $force && get_transient( 'tcsp_reconcile_lock' ) ) return;
        if ( ! $force ) set_transient( 'tcsp_reconcile_lock', 1, 20 );
        global $wpdb;
        $orders = $wpdb->get_results( $wpdb->prepare( "SELECT o.id, o.user_id, o.order_status, o.payment_status, pm.meta_value AS sponsor_id FROM {$wpdb->prefix}tutor_orders o INNER JOIN {$wpdb->prefix}tutor_order_items oi ON oi.order_id = o.id INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = oi.item_id AND pm.meta_key = %s WHERE o.created_at_gmt >= UTC_TIMESTAMP() - INTERVAL 180 DAY ORDER BY o.id DESC LIMIT 200", '_tcsp_sponsor_id' ) );
        if ( empty( $orders ) ) return;
        $seen = array();
        foreach ( $orders as $row ) {
            $sponsor_id = absint( $row->sponsor_id );
            if ( ! $sponsor_id || self::CPT !== get_post_type( $sponsor_id ) || isset( $seen[ $sponsor_id ] ) ) continue;
            if ( (int) get_post_field( 'post_author', $sponsor_id ) !== (int) $row->user_id ) continue;
            $seen[ $sponsor_id ] = true;
            $payment = strtolower( (string) $row->payment_status );
            $status  = strtolower( (string) $row->order_status );
            if ( 'paid' === $payment && ! in_array( $status, array( 'cancelled', 'trash' ), true ) ) {
                if ( in_array( (string) get_post_meta( $sponsor_id, '_tcsp_status', true ), array( 'active', 'scheduled', 'expired', 'cancelled', 'rejected' ), true ) ) { $this->delete_checkout_course( $sponsor_id ); continue; }
                if ( true ) { // A paid sponsorship is always activated.
                    $this->activate_paid_sponsorship( $sponsor_id, absint( $row->id ) );
                } else {
                    update_post_meta( $sponsor_id, '_tcsp_native_order_id', absint( $row->id ) );
                    $old_sponsor_status = (string) get_post_meta( $sponsor_id, '_tcsp_status', true );
                    if ( ! in_array( $old_sponsor_status, array( 'active', 'scheduled', 'paid' ), true ) ) update_post_meta( $sponsor_id, '_tcsp_status', 'paid' );
                    if ( 'paid' !== $old_sponsor_status && ! in_array( $old_sponsor_status, array( 'active', 'scheduled' ), true ) ) $this->notify_assigned_manager_paid( $sponsor_id );
                }
                $this->delete_checkout_course( $sponsor_id );
            } elseif ( in_array( $payment, array( 'failed', 'refunded', 'partially-refunded' ), true ) || in_array( $status, array( 'cancelled', 'trash' ), true ) ) {
                if ( ! in_array( (string) get_post_meta( $sponsor_id, '_tcsp_status', true ), array( 'active', 'scheduled', 'expired' ), true ) ) $this->set_order_status( $sponsor_id, 'cancelled' );
            } else {
                update_post_meta( $sponsor_id, '_tcsp_native_order_id', absint( $row->id ) );
                if ( ! in_array( get_post_meta( $sponsor_id, '_tcsp_status', true ), array( 'active', 'paid', 'scheduled' ), true ) ) update_post_meta( $sponsor_id, '_tcsp_status', 'pending' );
            }
        }
    }

    private function activate_paid_sponsorship( $sponsor_id, $native_order_id ) {
        $type = (string) get_post_meta( $sponsor_id, '_tcsp_target_type', true );
        $target = absint( get_post_meta( $sponsor_id, '_tcsp_target_id', true ) );
        $tier = (string) get_post_meta( $sponsor_id, '_tcsp_plan', true );
        $plan = $this->plan( $type, $tier );
        if ( ! $plan || ! $target ) return;
        $totals = $this->order_totals( $sponsor_id );
        $days = max( 1, (int) $totals['duration'] );
        $today_ts = current_time( 'timestamp' );
        $today = current_time( 'Y-m-d' );
        $current_tier = 'course' === $type
            ? (string) get_post_meta( $target, TCSP_Plugin::PROMOTION_TIER_META, true )
            : (string) get_user_meta( $target, TCSP_Tutors::TIER_META, true );
        $current_end = 'course' === $type
            ? (string) get_post_meta( $target, TCSP_Plugin::PROMOTION_END_META, true )
            : (string) get_user_meta( $target, TCSP_Tutors::END_META, true );
        $mode = (string) get_post_meta( $sponsor_id, '_tcsp_mode', true );
        if ( ! $mode ) {
            $mode = ( $current_tier && $current_end && $current_end >= $today )
                ? ( $current_tier === $tier ? 'extend' : 'change_plan' )
                : 'new';
            update_post_meta( $sponsor_id, '_tcsp_mode', $mode );
        }
        if ( 'change_plan' === $mode && $current_tier && $current_tier !== $tier && $current_end && $current_end >= $today ) {
            $start_ts = strtotime( $current_end . ' 00:00:00' ) + DAY_IN_SECONDS;
            $start = wp_date( 'Y-m-d', $start_ts );
            $end = wp_date( 'Y-m-d', $start_ts + ( $days * DAY_IN_SECONDS ) );
            update_post_meta( $sponsor_id, '_tcsp_native_order_id', $native_order_id );
            update_post_meta( $sponsor_id, '_tcsp_status', 'scheduled' );
            update_post_meta( $sponsor_id, '_tcsp_start', $start );
            update_post_meta( $sponsor_id, '_tcsp_end', $end );
            $this->notify_tutor_status( $sponsor_id, 'scheduled' );
            return;
        }
        $extend = $current_end && $current_end >= $today && $current_tier === $tier;
        $start = $extend ? $current_end : $today;
        $base_ts = $extend ? strtotime( $current_end . ' 00:00:00' ) : $today_ts;
        $end = wp_date( 'Y-m-d', $base_ts + ( $days * DAY_IN_SECONDS ) );
        update_post_meta( $sponsor_id, '_tcsp_native_order_id', $native_order_id );
        $was_active = 'active' === (string) get_post_meta( $sponsor_id, '_tcsp_status', true );
        update_post_meta( $sponsor_id, '_tcsp_status', 'active' );
        update_post_meta( $sponsor_id, '_tcsp_start', $start );
        update_post_meta( $sponsor_id, '_tcsp_end', $end );
        if ( 'course' === $type && class_exists( 'TCSP_Sponsorship_Manager' ) ) {
            update_post_meta( $target, TCSP_Plugin::PROMOTED_META, 1 );
            update_post_meta( $target, TCSP_Plugin::PROMOTION_TIER_META, $tier );
            update_post_meta( $target, TCSP_Plugin::PROMOTION_START_META, $start );
            update_post_meta( $target, TCSP_Plugin::PROMOTION_END_META, $end );
        } elseif ( 'tutor' === $type && class_exists( 'TCSP_Tutors' ) ) {
            update_user_meta( $target, TCSP_Tutors::PROMOTED_META, 1 );
            update_user_meta( $target, TCSP_Tutors::TIER_META, $tier );
            update_user_meta( $target, TCSP_Tutors::START_META, $start );
            update_user_meta( $target, TCSP_Tutors::END_META, $end );
        }
        if ( ! $was_active ) $this->notify_tutor_status( $sponsor_id, 'active' );
    }

    private function set_order_status( $id, $status ) {
        $old_status = (string) get_post_meta( $id, '_tcsp_status', true );
        if ( $old_status === $status ) return;

        update_post_meta( $id, '_tcsp_status', $status );
        if ( in_array( $status, array( 'cancelled', 'rejected', 'expired' ), true ) ) $this->delete_checkout_course( $id );
        $type = (string) get_post_meta( $id, '_tcsp_target_type', true );
        $target = absint( get_post_meta( $id, '_tcsp_target_id', true ) );
        $tier = (string) get_post_meta( $id, '_tcsp_plan', true );
        $plan = $this->plan( $type, $tier );
        if ( ! $plan || ! $target ) return;

        if ( in_array( $status, array( 'paid', 'active' ), true ) ) {
            $totals = $this->order_totals( $id );
            $days   = max( 1, (int) $totals['duration'] );
            $today  = current_time( 'Y-m-d' );
            $current_tier = 'course' === $type
                ? (string) get_post_meta( $target, TCSP_Plugin::PROMOTION_TIER_META, true )
                : (string) get_user_meta( $target, TCSP_Tutors::TIER_META, true );
            $current_end = 'course' === $type
                ? (string) get_post_meta( $target, TCSP_Plugin::PROMOTION_END_META, true )
                : (string) get_user_meta( $target, TCSP_Tutors::END_META, true );

            $mode = (string) get_post_meta( $id, '_tcsp_mode', true );
            if ( ! $mode ) {
                $mode = ( $current_tier && $current_end && $current_end >= $today )
                    ? ( $current_tier === $tier ? 'extend' : 'change_plan' )
                    : 'new';
                update_post_meta( $id, '_tcsp_mode', $mode );
            }

            // A different plan never replaces an active paid plan immediately.
            // It becomes a scheduled sponsorship and starts the day after the
            // current sponsorship ends.
            if ( 'change_plan' === $mode && $current_end && $current_end >= $today && $current_tier && $current_tier !== $tier ) {
                $start_ts = strtotime( $current_end . ' 00:00:00' ) + DAY_IN_SECONDS;
                $start = wp_date( 'Y-m-d', $start_ts );
                $end   = wp_date( 'Y-m-d', $start_ts + ( $days * DAY_IN_SECONDS ) );
                update_post_meta( $id, '_tcsp_start', $start );
                update_post_meta( $id, '_tcsp_end', $end );
                if ( 'active' === $status ) {
                    // Admin explicitly activating a scheduled upgrade should
                    // still respect the scheduled start rather than hijacking
                    // the currently active promotion.
                    update_post_meta( $id, '_tcsp_status', 'scheduled' );
                }
                return;
            }

            // Scheduled records are activated with their already-computed
            // dates by process_scheduled_sponsorships().
            if ( 'scheduled' === $old_status && 'active' === $status ) {
                $start = (string) get_post_meta( $id, '_tcsp_start', true );
                $end   = (string) get_post_meta( $id, '_tcsp_end', true );
                if ( $start && $end ) {
                    $this->apply_target_assignment( $id, $start, $end );
                    return;
                }
            }

            $extend = $current_end && $current_end >= $today;
            $start = $extend ? $current_end : $today;
            $base_ts = $extend ? strtotime( $current_end . ' 00:00:00' ) : current_time( 'timestamp' );
            $end = wp_date( 'Y-m-d', $base_ts + ( $days * DAY_IN_SECONDS ) );
            update_post_meta( $id, '_tcsp_start', $start );
            update_post_meta( $id, '_tcsp_end', $end );
            $this->apply_target_assignment( $id, $start, $end );
        }

        if ( in_array( $status, array( 'rejected', 'cancelled', 'expired' ), true ) ) {
            // Cancelling/rejecting a future plan must not erase the currently
            // active sponsorship underneath it.
            if ( 'scheduled' !== $old_status ) {
                $this->remove_target_assignment( $type, $target );
            }
        }

        $source = (string) get_post_meta( $id, '_tcsp_source', true );
        if ( in_array( $status, array( 'active', 'scheduled', 'rejected', 'cancelled', 'expired' ), true ) ) {
            $this->notify_tutor_status( $id, $status );
        } elseif ( 'paid' === $status && 'payment' === $source ) {
            $this->notify_assigned_manager_paid( $id );
        }
    }

    private function remove_target_assignment( $type, $target ) {
        if ( ! $target ) return;
        if ( 'course' === $type ) {
            delete_post_meta( $target, TCSP_Plugin::PROMOTED_META );
            delete_post_meta( $target, TCSP_Plugin::PROMOTION_TIER_META );
            delete_post_meta( $target, TCSP_Plugin::PROMOTION_START_META );
            delete_post_meta( $target, TCSP_Plugin::PROMOTION_END_META );
        } elseif ( 'tutor' === $type ) {
            delete_user_meta( $target, TCSP_Tutors::PROMOTED_META );
            delete_user_meta( $target, TCSP_Tutors::TIER_META );
            delete_user_meta( $target, TCSP_Tutors::START_META );
            delete_user_meta( $target, TCSP_Tutors::END_META );
        }
    }

    private function apply_target_assignment( $id, $start, $end ) {
        $type = (string) get_post_meta( $id, '_tcsp_target_type', true );
        $target = absint( get_post_meta( $id, '_tcsp_target_id', true ) );
        $tier = (string) get_post_meta( $id, '_tcsp_plan', true );
        if ( ! $target || ! $tier ) return;
        update_post_meta( $id, '_tcsp_start', $start );
        update_post_meta( $id, '_tcsp_end', $end );
        if ( 'course' === $type ) {
            update_post_meta( $target, TCSP_Plugin::PROMOTED_META, '1' );
            update_post_meta( $target, TCSP_Plugin::PROMOTION_TIER_META, $tier );
            update_post_meta( $target, TCSP_Plugin::PROMOTION_START_META, $start );
            update_post_meta( $target, TCSP_Plugin::PROMOTION_END_META, $end );
        } elseif ( 'tutor' === $type ) {
            update_user_meta( $target, TCSP_Tutors::PROMOTED_META, '1' );
            update_user_meta( $target, TCSP_Tutors::TIER_META, $tier );
            update_user_meta( $target, TCSP_Tutors::START_META, $start );
            update_user_meta( $target, TCSP_Tutors::END_META, $end );
        }
    }

    public function process_scheduled_sponsorships() {
        $this->cancel_abandoned_requests();
        $today = current_time( 'Y-m-d' );
        $ids = get_posts( array(
            'post_type' => self::CPT,
            'post_status' => 'private',
            'posts_per_page' => 100,
            'fields' => 'ids',
            'meta_query' => array(
                array( 'key' => '_tcsp_status', 'value' => 'scheduled' ),
                array( 'key' => '_tcsp_start', 'value' => $today, 'compare' => '<=', 'type' => 'DATE' ),
            ),
            'orderby' => 'date',
            'order' => 'ASC',
        ) );
        foreach ( $ids as $id ) {
            $start = (string) get_post_meta( $id, '_tcsp_start', true );
            $end = (string) get_post_meta( $id, '_tcsp_end', true );
            if ( ! $start || ! $end ) continue;
            update_post_meta( $id, '_tcsp_status', 'active' );
            $this->apply_target_assignment( $id, $start, $end );
            $this->notify_tutor_status( $id, 'active' );
        }
    }

    /**
     * Cancel pending sponsorship requests whose native order was never paid
     * or submitted offline, after the configured number of days.
     */
    public function cancel_abandoned_requests() {
        $days = (int) $this->settings->get( 'sponsorship_unpaid_cancel_days' );
        if ( $days < 1 || ! class_exists( '\\Tutor\\Models\\OrderModel' ) ) return;
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
        $ids = get_posts( array(
            'post_type'      => self::CPT,
            'post_status'    => 'private',
            'posts_per_page' => 100,
            'fields'         => 'ids',
            'date_query'     => array( array( 'column' => 'post_date_gmt', 'before' => $cutoff ) ),
            'meta_query'     => array( array( 'key' => '_tcsp_status', 'value' => 'pending' ) ),
            'orderby'        => 'date',
            'order'          => 'ASC',
        ) );
        foreach ( $ids as $id ) {
            if ( ! $this->is_unpaid_pending( $id ) ) continue; // paid, processing or offline-under-review requests stay.
            $this->cancel_unpaid_request( $id );
            update_post_meta( $id, '_tcsp_auto_cancelled', current_time( 'mysql' ) );
            $this->notify_tutor_status( $id, 'cancelled' );
        }
    }

    private function notifications_enabled() {
        return (bool) $this->settings->get( 'sponsorship_notifications' );
    }

    private function target_label( $id ) {
        $type = (string) get_post_meta( $id, '_tcsp_target_type', true );
        $target = absint( get_post_meta( $id, '_tcsp_target_id', true ) );
        if ( 'course' === $type ) {
            return get_the_title( $target ) ?: __( 'Course', 'tutor-learning-suite' );
        }
        $u = get_userdata( $target );
        return $u ? $u->display_name : __( 'Tutor profile', 'tutor-learning-suite' );
    }

    private function notify_new_payment_request( $id ) {
        if ( ! $this->notifications_enabled() ) return;
        $type = (string) get_post_meta( $id, '_tcsp_target_type', true );
        $tier = (string) get_post_meta( $id, '_tcsp_plan', true );
        $manager_ids = array();
        if ( 'course' === $type ) {
            $plans = $this->settings->promotion_tiers();
            $manager_id = isset( $plans[ $tier ]['manager_id'] ) ? absint( $plans[ $tier ]['manager_id'] ) : 0;
            if ( $manager_id ) $manager_ids[] = $manager_id;
        }
        if ( empty( $manager_ids ) ) {
            $manager_ids = get_users( array( 'role' => 'administrator', 'fields' => 'ids' ) );
        }
        $tutor = get_userdata( (int) get_post_field( 'post_author', $id ) );
        $tutor_name = $tutor ? $tutor->display_name : __( 'Tutor', 'tutor-learning-suite' );
        $target_label = $this->target_label( $id );
        $subject = sprintf( __( 'New sponsorship request: %s', 'tutor-learning-suite' ), $target_label );
        $message = sprintf( __( "A new sponsorship payment request was created.\n\nTutor: %s\nTarget: %s\nType: %s\nPlan: %s\n\nPlease review it in Tutor LMS → Sponsorship.", 'tutor-learning-suite' ), $tutor_name, $target_label, ucfirst( $type ), $this->plan_label( $type, $tier ) );
        foreach ( array_unique( array_map( 'absint', $manager_ids ) ) as $manager_id ) {
            $manager = get_userdata( $manager_id );
            if ( $manager && is_email( $manager->user_email ) ) wp_mail( $manager->user_email, $subject, $message );
        }
    }

    private function notify_assigned_manager_paid( $id ) {
        if ( ! $this->notifications_enabled() ) return;
        $type = (string) get_post_meta( $id, '_tcsp_target_type', true );
        if ( 'course' !== $type ) return;
        $tier = (string) get_post_meta( $id, '_tcsp_plan', true );
        $plans = $this->settings->promotion_tiers();
        $manager_id = isset( $plans[ $tier ]['manager_id'] ) ? absint( $plans[ $tier ]['manager_id'] ) : 0;
        if ( ! $manager_id ) return;
        $manager = get_userdata( $manager_id );
        if ( ! $manager || ! is_email( $manager->user_email ) ) return;
        wp_mail( $manager->user_email, __( 'Sponsorship payment received', 'tutor-learning-suite' ), sprintf( __( "A sponsorship payment has been confirmed and is awaiting sponsorship activation.\n\nTarget: %s\nPlan: %s\n\nReview it in Tutor LMS → Sponsorship.", 'tutor-learning-suite' ), $this->target_label( $id ), $this->plan_label( $type, $tier ) ) );
    }

    /** Once a day: remind instructors 7 and 3 days before an active sponsorship ends. */
    public function send_expiry_reminders() {
        if ( ! $this->notifications_enabled() ) return;
        $today = current_time( 'Y-m-d' );
        if ( get_option( 'tcsp_expiry_reminder_day' ) === $today ) return;
        update_option( 'tcsp_expiry_reminder_day', $today, false );
        $ids = get_posts( array(
            'post_type' => self::CPT, 'post_status' => array( 'private', 'publish' ), 'posts_per_page' => 300, 'fields' => 'ids',
            'meta_query' => array( array( 'key' => '_tcsp_status', 'value' => 'active' ) ),
        ) );
        foreach ( $ids as $id ) {
            $type   = (string) get_post_meta( $id, '_tcsp_target_type', true );
            $target = absint( get_post_meta( $id, '_tcsp_target_id', true ) );
            $end    = (string) get_post_meta( $id, '_tcsp_end', true );
            if ( ! $end || ! $target ) continue;
            // Only the order that currently decides the end date (an extension supersedes older orders).
            $current_end = 'tutor' === $type ? (string) get_user_meta( $target, TCSP_Tutors::END_META, true ) : (string) get_post_meta( $target, TCSP_Plugin::PROMOTION_END_META, true );
            if ( $current_end !== $end ) continue;
            $left = (int) floor( ( strtotime( $end . ' 00:00:00' ) - strtotime( $today . ' 00:00:00' ) ) / DAY_IN_SECONDS );
            if ( $left < 0 || $left > 7 ) continue;
            $sent = array_filter( explode( ',', (string) get_post_meta( $id, '_tcsp_reminded', true ) ) );
            $mark = '';
            if ( $left <= 3 && ! in_array( '3', $sent, true ) ) $mark = '3,7';
            elseif ( $left > 3 && ! in_array( '7', $sent, true ) ) $mark = '7';
            if ( '' === $mark ) continue;
            update_post_meta( $id, '_tcsp_reminded', implode( ',', array_unique( array_merge( $sent, explode( ',', $mark ) ) ) ) );
            $this->notify_ending( $id, $left );
        }
    }

    private function notify_ending( $id, $left ) {
        $tutor = get_userdata( (int) get_post_field( 'post_author', $id ) );
        if ( ! $tutor || ! is_email( $tutor->user_email ) ) return;
        $type   = (string) get_post_meta( $id, '_tcsp_target_type', true );
        $target = absint( get_post_meta( $id, '_tcsp_target_id', true ) );
        $tier   = (string) get_post_meta( $id, '_tcsp_plan', true );
        $units  = max( 1, absint( get_post_meta( $id, '_tcsp_units', true ) ?: 1 ) );
        $renew  = add_query_arg( array( 'tcsp_sp_tab' => 1, 'tcsp_target' => $type . ':' . $target, 'tcsp_plan' => $tier, 'tcsp_units' => $units ), $this->portal_base_url() );
        $msg = sprintf(
            /* translators: 1: item, 2: plan, 3: days left, 4: renew link */
            __( "Your sponsorship for %1\$s (plan: %2\$s) ends in %3\$d day(s).\n\nRenew now to keep it running without a gap. Your choices are pre-selected:\n%4\$s", 'tutor-learning-suite' ),
            $this->target_label( $id ), $this->plan_label( $type, $tier ), $left, $renew
        );
        wp_mail( $tutor->user_email, __( 'Your sponsorship ends soon', 'tutor-learning-suite' ), $msg );
    }

    private function notify_tutor_status( $id, $status ) {
        if ( ! $this->notifications_enabled() ) return;
        $tutor = get_userdata( (int) get_post_field( 'post_author', $id ) );
        if ( ! $tutor || ! is_email( $tutor->user_email ) ) return;
        $type = (string) get_post_meta( $id, '_tcsp_target_type', true );
        $tier = (string) get_post_meta( $id, '_tcsp_plan', true );
        $target_label = $this->target_label( $id );
        $subjects = array(
            'active' => __( 'Your sponsorship is now active', 'tutor-learning-suite' ),
            'scheduled' => __( 'Your sponsorship upgrade is scheduled', 'tutor-learning-suite' ),
            'rejected' => __( 'Your sponsorship request was rejected', 'tutor-learning-suite' ),
            'cancelled' => __( 'Your sponsorship was cancelled', 'tutor-learning-suite' ),
            'expired' => __( 'Your sponsorship has expired', 'tutor-learning-suite' ),
        );
        $messages = array(
            'active' => sprintf( __( "Your sponsorship for %s is now active.\n\nPlan: %s\nStart: %s\nEnd: %s", 'tutor-learning-suite' ), $target_label, $this->plan_label( $type, $tier ), get_post_meta( $id, '_tcsp_start', true ), get_post_meta( $id, '_tcsp_end', true ) ),
            'scheduled' => sprintf( __( "Your sponsorship upgrade for %s is scheduled.\n\nPlan: %s\nStarts: %s\nEnds: %s", 'tutor-learning-suite' ), $target_label, $this->plan_label( $type, $tier ), get_post_meta( $id, '_tcsp_start', true ), get_post_meta( $id, '_tcsp_end', true ) ),
            'rejected' => sprintf( __( "Your sponsorship request for %s was rejected. Please contact the site team if you need more information.", 'tutor-learning-suite' ), $target_label ),
            'cancelled' => sprintf( __( "Your sponsorship for %s has been cancelled.", 'tutor-learning-suite' ), $target_label ),
            'expired' => sprintf( __( "Your sponsorship for %s has expired.", 'tutor-learning-suite' ), $target_label ),
        );
        if ( isset( $subjects[ $status ], $messages[ $status ] ) ) {
            wp_mail( $tutor->user_email, $subjects[ $status ], $messages[ $status ] );
        }
    }

    public function admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $posts = get_posts( array( 'post_type' => self::CPT, 'post_status' => 'private', 'posts_per_page' => 200, 'orderby' => 'date', 'order' => 'DESC' ) );
        echo '<div class="wrap"><h1>Sponsorship Orders</h1><p>Separate paid-plan records for courses and tutor profiles. Approve a paid order by changing its status to Paid or Active; this applies the matching promotion only to its target.</p><table class="widefat striped"><thead><tr><th>ID</th><th>Tutor</th><th>Target</th><th>Plan</th><th>Amount</th><th>Dates</th><th>Status</th><th>Save</th></tr></thead><tbody>';
        foreach ( $posts as $p ) {
            $type=(string)get_post_meta($p->ID,'_tcsp_target_type',true);$target=absint(get_post_meta($p->ID,'_tcsp_target_id',true));$tier=(string)get_post_meta($p->ID,'_tcsp_plan',true);$amount=(float)get_post_meta($p->ID,'_tcsp_amount',true);$status=(string)get_post_meta($p->ID,'_tcsp_status',true);$tutor=get_userdata((int)$p->post_author);$label = 'course' === $type ? get_the_title( $target ) : ( $tutor ? $tutor->display_name : 'Tutor #' . $target );$st=(string)get_post_meta($p->ID,'_tcsp_start',true);$en=(string)get_post_meta($p->ID,'_tcsp_end',true);
            echo '<tr><td>#'.(int)$p->ID.'</td><td>'.esc_html($tutor?$tutor->display_name:'Unknown').'</td><td>'.esc_html(ucfirst($type).': '.$label).'</td><td>'.esc_html($this->plan_label($type,$tier)).'</td><td>'.esc_html(number_format_i18n($amount,2)).'</td><td>'.esc_html(trim($st.' → '.$en,' →')).'</td><td><form method="post">'.wp_nonce_field('tcsp_order_admin','_wpnonce',true,false).'<input type="hidden" name="tcsp_order_action" value="status"><input type="hidden" name="order_id" value="'.(int)$p->ID.'"><select name="status">'.implode('',array_map(function($x)use($status){return '<option value="'.esc_attr($x).'" '.selected($status,$x,false).'>'.esc_html(ucfirst($x)).'</option>';},array('pending','paid','active','expired','rejected','cancelled'))).'</select></td><td><button class="button button-primary">Save</button></form></td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private function portal_base_url() {
        if ( function_exists( 'tutor_utils' ) ) return tutor_utils()->tutor_dashboard_url( 'tcsp-sponsorship' );
        return home_url( '/' . self::PAGE_SLUG . '/' );
    }

    private function esc_plan_color( $color ) {
        $color = sanitize_hex_color( $color );
        return $color ? $color : '#2271b1';
    }

    private function status_class( $status ) {
        $allowed = array( 'pending', 'paid', 'scheduled', 'active', 'expired', 'rejected', 'cancelled' );
        return in_array( $status, $allowed, true ) ? $status : 'pending';
    }

    private function view_days_left( $end ) {
        if ( ! $end ) return 0;
        $today = strtotime( current_time( 'Y-m-d' ) . ' 00:00:00' );
        $e     = strtotime( $end . ' 00:00:00' );
        return ( $e && $e >= $today ) ? (int) floor( ( $e - $today ) / DAY_IN_SECONDS ) : 0;
    }

    private function view_money( $amount ) {
        $currency = (string) apply_filters( 'tcsp_sponsorship_currency', '' );
        return number_format_i18n( (float) $amount, 2 ) . ( '' !== $currency ? ' ' . $currency : '' );
    }

    private function view_status_label( $status ) {
        $labels = array(
            'pending'   => __( 'Pending', 'tutor-learning-suite' ),
            'paid'      => __( 'Paid', 'tutor-learning-suite' ),
            'scheduled' => __( 'Scheduled', 'tutor-learning-suite' ),
            'active'    => __( 'Active', 'tutor-learning-suite' ),
            'expired'   => __( 'Expired', 'tutor-learning-suite' ),
            'rejected'  => __( 'Rejected', 'tutor-learning-suite' ),
            'cancelled' => __( 'Cancelled', 'tutor-learning-suite' ),
        );
        return isset( $labels[ $status ] ) ? $labels[ $status ] : ucfirst( $status );
    }

    /** Plan cards for one target type (course | tutor). Radios are disabled by JS for the hidden group. */
    private function view_plan_cards( $type, $plans ) {
        $max_weight = 1;
        foreach ( $plans as $p ) $max_weight = max( $max_weight, (int) ( $p['weight'] ?? 0 ) );
        ob_start();
        foreach ( $plans as $key => $plan ) :
            $color  = $this->esc_plan_color( $plan['color'] ?? '' );
            $days   = max( 1, (int) ( $plan['duration'] ?? 1 ) );
            $maxdisc = max( (float) ( $plan['discount_3'] ?? 0 ), (float) ( $plan['discount_6'] ?? 0 ), (float) ( $plan['discount_12'] ?? 0 ) );
            $meter  = (int) round( ( (int) ( $plan['weight'] ?? 0 ) / $max_weight ) * 100 );
            ?>
            <label class="tcsp-sp-plan" style="--plan-color:<?php echo esc_attr( $color ); ?>"
                data-type="<?php echo esc_attr( $type ); ?>" data-key="<?php echo esc_attr( $key ); ?>"
                data-label="<?php echo esc_attr( $plan['label'] ); ?>" data-desc="<?php echo esc_attr( $plan['description'] ?? '' ); ?>"
                data-price="<?php echo esc_attr( (float) $plan['price'] ); ?>" data-duration="<?php echo esc_attr( $days ); ?>"
                data-d3="<?php echo esc_attr( (float) ( $plan['discount_3'] ?? 0 ) ); ?>" data-d6="<?php echo esc_attr( (float) ( $plan['discount_6'] ?? 0 ) ); ?>" data-d12="<?php echo esc_attr( (float) ( $plan['discount_12'] ?? 0 ) ); ?>">
                <input type="radio" name="tier" value="<?php echo esc_attr( $key ); ?>">
                <span class="tcsp-sp-plan__card">
                    <span class="tcsp-sp-plan__tag" hidden><?php esc_html_e( 'Your current plan', 'tutor-learning-suite' ); ?></span>
                    <span class="tcsp-sp-plan__name"><?php echo esc_html( $plan['label'] ); ?></span>
                    <span class="tcsp-sp-plan__desc"><?php echo esc_html( $plan['description'] ?? '' ); ?></span>
                    <span class="tcsp-sp-plan__price"><b><?php echo esc_html( $this->view_money( (float) $plan['price'] ) ); ?></b><small>/ <?php echo esc_html( $this->duration_unit_text( $days, false ) ); ?></small></span>
                    <span class="tcsp-sp-plan__meter" title="<?php esc_attr_e( 'Relative visibility boost', 'tutor-learning-suite' ); ?>"><span class="tcsp-sp-plan__meter-label"><?php esc_html_e( 'Visibility', 'tutor-learning-suite' ); ?></span><span class="tcsp-sp-plan__bar"><i style="width:<?php echo (int) $meter; ?>%"></i></span></span>
                    <?php if ( $maxdisc > 0 ) : ?><span class="tcsp-sp-plan__save"><?php echo esc_html( sprintf( /* translators: %s: percent */ __( 'Save up to %s%% on longer terms', 'tutor-learning-suite' ), rtrim( rtrim( number_format( $maxdisc, 1 ), '0' ), '.' ) ) ); ?></span><?php endif; ?>
                </span>
            </label>
            <?php
        endforeach;
        return ob_get_clean();
    }

    private function render_portal_view() {
        if ( ! is_user_logged_in() ) return '<p>' . esc_html__( 'Please log in as a tutor to manage sponsorships.', 'tutor-learning-suite' ) . '</p>';
        $user_id = get_current_user_id();
        if ( ! $this->user_is_instructor( $user_id ) ) return '<p>' . esc_html__( 'This area is available to instructors only.', 'tutor-learning-suite' ) . '</p>';

        $course_plans = $this->settings->promotion_tiers();
        $tutor_plans  = $this->settings->tutor_promotion_tiers();
        $courses      = $this->own_courses( $user_id );
        $orders       = $this->orders( $user_id );
        $today        = current_time( 'Y-m-d' );

        // Open requests per target, plus counters for the header.
        $open_targets = array();
        $open_count   = 0;
        foreach ( $orders as $o ) {
            $st = (string) get_post_meta( $o->ID, '_tcsp_status', true );
            if ( ! in_array( $st, array( 'pending', 'paid', 'scheduled', 'active' ), true ) ) continue;
            $ot  = (string) get_post_meta( $o->ID, '_tcsp_target_type', true );
            $oid = absint( get_post_meta( $o->ID, '_tcsp_target_id', true ) );
            $open_targets[ $ot . ':' . $oid ] = $st;
            if ( in_array( $st, array( 'pending', 'paid', 'scheduled' ), true ) ) $open_count++;
        }

        // Target cards: tutor profile + each course.
        $targets = array();
        $t_tier  = (string) get_user_meta( $user_id, TCSP_Tutors::TIER_META, true );
        $t_end   = (string) get_user_meta( $user_id, TCSP_Tutors::END_META, true );
        $targets[] = array(
            'type' => 'tutor', 'id' => $user_id, 'title' => __( 'My tutor profile', 'tutor-learning-suite' ),
            'thumb' => get_avatar_url( $user_id, array( 'size' => 120 ) ),
            'tier' => ( $t_tier && $t_end && $t_end >= $today ) ? $t_tier : '', 'end' => $t_end,
            'open' => isset( $open_targets[ 'tutor:' . $user_id ] ) ? $open_targets[ 'tutor:' . $user_id ] : '',
        );
        foreach ( $courses as $cid => $ctitle ) {
            $c_tier = (string) get_post_meta( $cid, TCSP_Plugin::PROMOTION_TIER_META, true );
            $c_end  = (string) get_post_meta( $cid, TCSP_Plugin::PROMOTION_END_META, true );
            $targets[] = array(
                'type' => 'course', 'id' => (int) $cid, 'title' => $ctitle,
                'thumb' => get_the_post_thumbnail_url( $cid, 'thumbnail' ) ?: '',
                'tier' => ( $c_tier && $c_end && $c_end >= $today ) ? $c_tier : '', 'end' => $c_end,
                'open' => isset( $open_targets[ 'course:' . $cid ] ) ? $open_targets[ 'course:' . $cid ] : '',
            );
        }
        $active_now = 0; $next_end = '';
        foreach ( $targets as $t ) {
            if ( $t['tier'] ) { $active_now++; if ( '' === $next_end || $t['end'] < $next_end ) $next_end = $t['end']; }
        }

        $tab = isset( $_GET['tcsp_sp_tab'] ) ? absint( $_GET['tcsp_sp_tab'] ) : 1;
        $tab = in_array( $tab, array( 1, 2 ), true ) ? $tab : 1;
        $notice = isset( $_GET['tcsp_s_notice'] ) ? sanitize_key( wp_unslash( $_GET['tcsp_s_notice'] ) ) : '';
        $notice_text = '';
        $notice_type = 'info';
        if ( 'created' === $notice ) { $notice_text = __( 'Sponsorship request created.', 'tutor-learning-suite' ); $notice_type = 'success'; }
        elseif ( 'submitted' === $notice ) { $notice_text = __( 'Payment submitted. Your sponsorship starts once the payment is confirmed.', 'tutor-learning-suite' ); $notice_type = 'success'; }
        elseif ( 'cancelled' === $notice ) $notice_text = __( 'The unpaid sponsorship request was cancelled.', 'tutor-learning-suite' );
        elseif ( 'exists' === $notice ) { $notice_text = __( 'There is already an open sponsorship request for that item.', 'tutor-learning-suite' ); $notice_type = 'warn'; }
        elseif ( 'checkout_error' === $notice ) { $notice_text = __( 'We could not create the payment order. Please check that Tutor LMS Native eCommerce is enabled.', 'tutor-learning-suite' ); $notice_type = 'warn'; }
        elseif ( 'invalid' === $notice ) { $notice_text = __( 'That sponsorship request could not be created.', 'tutor-learning-suite' ); $notice_type = 'warn'; }
        $base = $this->portal_base_url();
        $ver  = rawurlencode( TCSP_VERSION );

        $i18n = array(
            'chooseTarget' => __( 'Choose what to promote', 'tutor-learning-suite' ),
            'choosePlan'   => __( 'Choose a plan', 'tutor-learning-suite' ),
            'month'        => __( 'month', 'tutor-learning-suite' ),   'months' => __( 'months', 'tutor-learning-suite' ),
            'week'         => __( 'week', 'tutor-learning-suite' ),    'weeks'  => __( 'weeks', 'tutor-learning-suite' ),
            'year'         => __( 'year', 'tutor-learning-suite' ),    'years'  => __( 'years', 'tutor-learning-suite' ),
            'day'          => __( 'day', 'tutor-learning-suite' ),     'days'   => __( 'days', 'tutor-learning-suite' ),
            'daysTotal'    => __( 'days total', 'tutor-learning-suite' ),
            'discount'     => __( 'Bulk discount', 'tutor-learning-suite' ),
            'startNew'     => __( 'Starts as soon as your payment is confirmed and runs until about %s.', 'tutor-learning-suite' ),
            'startExtend'  => __( 'Adds this time to your current sponsorship. It will now run until about %s.', 'tutor-learning-suite' ),
            'startChange'  => __( 'Your current plan keeps running until %1$s. The new plan then starts and runs until about %2$s.', 'tutor-learning-suite' ),
            'ctaContinue'  => __( 'Continue to payment', 'tutor-learning-suite' ),
            'ctaExtend'    => __( 'Extend and continue to payment', 'tutor-learning-suite' ),
            'ctaChange'    => __( 'Schedule plan and continue', 'tutor-learning-suite' ),
            'working'      => __( 'Creating your order…', 'tutor-learning-suite' ),
            'noResults'    => __( 'No course matches your search.', 'tutor-learning-suite' ),
        );

        ob_start(); ?>
        <link rel="stylesheet" href="<?php echo esc_url( TCSP_URL . 'assets/css/tcsp-sponsorship.css?ver=' . $ver ); ?>">
        <div class="tcsp-sp" data-today="<?php echo esc_attr( $today ); ?>" data-i18n="<?php echo esc_attr( wp_json_encode( $i18n ) ); ?>" data-currency="<?php echo esc_attr( (string) apply_filters( 'tcsp_sponsorship_currency', '' ) ); ?>">

            <header class="tcsp-sp-head">
                <div>
                    <h2><?php esc_html_e( 'Sponsorship', 'tutor-learning-suite' ); ?></h2>
                    <p><?php esc_html_e( 'Get your courses and tutor profile in front of more students.', 'tutor-learning-suite' ); ?></p>
                </div>
                <div class="tcsp-sp-stats">
                    <div><b><?php echo (int) $active_now; ?></b><span><?php esc_html_e( 'Active now', 'tutor-learning-suite' ); ?></span></div>
                    <div><b><?php echo (int) $open_count; ?></b><span><?php esc_html_e( 'Open requests', 'tutor-learning-suite' ); ?></span></div>
                    <?php if ( $next_end ) : ?><div><b><?php echo (int) $this->view_days_left( $next_end ); ?></b><span><?php esc_html_e( 'Days to next expiry', 'tutor-learning-suite' ); ?></span></div><?php endif; ?>
                </div>
            </header>

            <?php if ( $notice_text ) : ?><div class="tcsp-sp-alert tcsp-sp-alert--<?php echo esc_attr( $notice_type ); ?>" role="status"><?php echo esc_html( $notice_text ); ?></div><?php endif; ?>

            <nav class="tcsp-sp-tabs" aria-label="<?php esc_attr_e( 'Sponsorship sections', 'tutor-learning-suite' ); ?>">
                <a class="tcsp-sp-tab <?php echo 1 === $tab ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tcsp_sp_tab', 1, $base ) ); ?>"><?php esc_html_e( 'Promote', 'tutor-learning-suite' ); ?></a>
                <a class="tcsp-sp-tab <?php echo 2 === $tab ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tcsp_sp_tab', 2, $base ) ); ?>"><?php esc_html_e( 'My sponsorship status', 'tutor-learning-suite' ); ?><?php if ( $open_count ) : ?><span class="tcsp-sp-tab__count"><?php echo (int) $open_count; ?></span><?php endif; ?></a>
            </nav>

            <?php if ( 1 === $tab ) : ?>
            <form method="post" class="tcsp-sp-wizard" novalidate>
                <?php wp_nonce_field( self::NONCE ); ?>
                <input type="hidden" name="tcsp_sponsor_action" value="create">
                <input type="hidden" name="target_type" value="">
                <input type="hidden" name="target_id" value="">

                <div class="tcsp-sp-layout">
                    <div class="tcsp-sp-steps">

                        <!-- Step 1: target -->
                        <section class="tcsp-sp-step" data-step="1">
                            <h3 class="tcsp-sp-step__title"><span class="tcsp-sp-step__num">1</span><?php esc_html_e( 'What do you want to promote?', 'tutor-learning-suite' ); ?></h3>
                            <div class="tcsp-sp-seg" role="group" aria-label="<?php esc_attr_e( 'Promotion type', 'tutor-learning-suite' ); ?>">
                                <button type="button" class="tcsp-sp-seg__btn" data-type="course"><?php esc_html_e( 'A course', 'tutor-learning-suite' ); ?> <small><?php echo (int) count( $courses ); ?></small></button>
                                <button type="button" class="tcsp-sp-seg__btn" data-type="tutor"><?php esc_html_e( 'My tutor profile', 'tutor-learning-suite' ); ?></button>
                            </div>

                            <div class="tcsp-sp-group" data-type="course">
                                <?php if ( count( $courses ) > 6 ) : ?><input type="search" class="tcsp-sp-search" placeholder="<?php esc_attr_e( 'Search your courses…', 'tutor-learning-suite' ); ?>" aria-label="<?php esc_attr_e( 'Search your courses', 'tutor-learning-suite' ); ?>"><?php endif; ?>
                                <div class="tcsp-sp-targets">
                                    <?php foreach ( $targets as $t ) : if ( 'course' !== $t['type'] ) continue; $blocked = in_array( $t['open'], array( 'pending', 'paid', 'scheduled' ), true ); ?>
                                        <label class="tcsp-sp-target <?php echo $blocked ? 'is-blocked' : ''; ?>" data-title="<?php echo esc_attr( mb_strtolower( $t['title'] ) ); ?>">
                                            <input type="radio" name="target" value="course:<?php echo (int) $t['id']; ?>" data-type="course" data-id="<?php echo (int) $t['id']; ?>" data-title="<?php echo esc_attr( $t['title'] ); ?>" data-tier="<?php echo esc_attr( $t['tier'] ); ?>" data-end="<?php echo esc_attr( $t['tier'] ? $t['end'] : '' ); ?>" <?php disabled( $blocked ); ?>>
                                            <span class="tcsp-sp-target__card">
                                                <span class="tcsp-sp-target__thumb" <?php echo $t['thumb'] ? 'style="background-image:url(' . esc_url( $t['thumb'] ) . ')"' : ''; ?>><?php echo $t['thumb'] ? '' : esc_html( mb_strtoupper( mb_substr( $t['title'], 0, 1 ) ) ); ?></span>
                                                <span class="tcsp-sp-target__body">
                                                    <strong><?php echo esc_html( $t['title'] ); ?></strong>
                                                    <?php if ( $blocked ) : ?>
                                                        <em class="tcsp-sp-chip tcsp-sp-chip--warn"><?php esc_html_e( 'Request already open', 'tutor-learning-suite' ); ?></em>
                                                    <?php elseif ( $t['tier'] ) : $pl = $this->plan( 'course', $t['tier'] ); ?>
                                                        <em class="tcsp-sp-chip" style="--plan-color:<?php echo esc_attr( $this->esc_plan_color( $pl['color'] ?? '' ) ); ?>"><?php echo esc_html( sprintf( /* translators: 1: plan, 2: days */ __( '%1$s · %2$d days left', 'tutor-learning-suite' ), $this->plan_label( 'course', $t['tier'] ), $this->view_days_left( $t['end'] ) ) ); ?></em>
                                                    <?php else : ?>
                                                        <em class="tcsp-sp-chip tcsp-sp-chip--muted"><?php esc_html_e( 'Not sponsored', 'tutor-learning-suite' ); ?></em>
                                                    <?php endif; ?>
                                                </span>
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <?php if ( ! $courses ) : ?><div class="tcsp-sp-empty"><?php esc_html_e( 'You have no published courses to promote yet. You can still promote your tutor profile.', 'tutor-learning-suite' ); ?></div><?php endif; ?>
                                <div class="tcsp-sp-empty tcsp-sp-noresults" hidden></div>
                            </div>

                            <div class="tcsp-sp-group" data-type="tutor" hidden>
                                <div class="tcsp-sp-targets tcsp-sp-targets--single">
                                    <?php foreach ( $targets as $t ) : if ( 'tutor' !== $t['type'] ) continue; $blocked = in_array( $t['open'], array( 'pending', 'paid', 'scheduled' ), true ); ?>
                                        <label class="tcsp-sp-target <?php echo $blocked ? 'is-blocked' : ''; ?>">
                                            <input type="radio" name="target" value="tutor:<?php echo (int) $t['id']; ?>" data-type="tutor" data-id="<?php echo (int) $t['id']; ?>" data-title="<?php echo esc_attr( $t['title'] ); ?>" data-tier="<?php echo esc_attr( $t['tier'] ); ?>" data-end="<?php echo esc_attr( $t['tier'] ? $t['end'] : '' ); ?>" <?php disabled( $blocked ); ?>>
                                            <span class="tcsp-sp-target__card">
                                                <span class="tcsp-sp-target__thumb tcsp-sp-target__thumb--round" style="background-image:url(<?php echo esc_url( $t['thumb'] ); ?>)"></span>
                                                <span class="tcsp-sp-target__body">
                                                    <strong><?php echo esc_html( $t['title'] ); ?></strong>
                                                    <small><?php esc_html_e( 'Shown in Tutor Search and the sponsored tutors carousel.', 'tutor-learning-suite' ); ?></small>
                                                    <?php if ( $blocked ) : ?>
                                                        <em class="tcsp-sp-chip tcsp-sp-chip--warn"><?php esc_html_e( 'Request already open', 'tutor-learning-suite' ); ?></em>
                                                    <?php elseif ( $t['tier'] ) : $pl = $this->plan( 'tutor', $t['tier'] ); ?>
                                                        <em class="tcsp-sp-chip" style="--plan-color:<?php echo esc_attr( $this->esc_plan_color( $pl['color'] ?? '' ) ); ?>"><?php echo esc_html( sprintf( __( '%1$s · %2$d days left', 'tutor-learning-suite' ), $this->plan_label( 'tutor', $t['tier'] ), $this->view_days_left( $t['end'] ) ) ); ?></em>
                                                    <?php else : ?>
                                                        <em class="tcsp-sp-chip tcsp-sp-chip--muted"><?php esc_html_e( 'Not sponsored', 'tutor-learning-suite' ); ?></em>
                                                    <?php endif; ?>
                                                </span>
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </section>

                        <!-- Step 2: plan -->
                        <section class="tcsp-sp-step" data-step="2">
                            <h3 class="tcsp-sp-step__title"><span class="tcsp-sp-step__num">2</span><?php esc_html_e( 'Pick a plan', 'tutor-learning-suite' ); ?></h3>
                            <div class="tcsp-sp-group" data-type="course"><div class="tcsp-sp-plans"><?php echo $this->view_plan_cards( 'course', $course_plans ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div></div>
                            <div class="tcsp-sp-group" data-type="tutor" hidden><div class="tcsp-sp-plans"><?php echo $this->view_plan_cards( 'tutor', $tutor_plans ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div></div>
                        </section>

                        <!-- Step 3: duration -->
                        <section class="tcsp-sp-step" data-step="3">
                            <h3 class="tcsp-sp-step__title"><span class="tcsp-sp-step__num">3</span><?php esc_html_e( 'How long?', 'tutor-learning-suite' ); ?></h3>
                            <div class="tcsp-sp-units">
                                <?php foreach ( $this->allowed_unit_options() as $u ) : ?>
                                    <label class="tcsp-sp-unit"><input type="radio" name="units" value="<?php echo (int) $u; ?>" <?php checked( 1, (int) $u ); ?>><span class="tcsp-sp-unit__card"><span class="tcsp-sp-unit__main" data-unit-main><?php echo (int) $u; ?> ×</span><span class="tcsp-sp-unit__badge" data-unit-badge hidden></span></span></label>
                                <?php endforeach; ?>
                            </div>
                            <p class="tcsp-sp-hint"><?php esc_html_e( 'Longer terms may include a bulk discount. Units multiply both the price and the duration.', 'tutor-learning-suite' ); ?></p>
                        </section>

                        <details class="tcsp-sp-help">
                            <summary><?php esc_html_e( 'How extending and changing plans works', 'tutor-learning-suite' ); ?></summary>
                            <div class="tcsp-sp-help__grid">
                                <div><strong><?php esc_html_e( 'New promotion', 'tutor-learning-suite' ); ?></strong><p><?php esc_html_e( 'Starts after your payment is confirmed.', 'tutor-learning-suite' ); ?></p></div>
                                <div><strong><?php esc_html_e( 'Same plan again', 'tutor-learning-suite' ); ?></strong><p><?php esc_html_e( 'The purchased time is added to your current end date. Nothing is replaced.', 'tutor-learning-suite' ); ?></p></div>
                                <div><strong><?php esc_html_e( 'Different plan', 'tutor-learning-suite' ); ?></strong><p><?php esc_html_e( 'The new plan is scheduled to start when the current one ends.', 'tutor-learning-suite' ); ?></p></div>
                            </div>
                        </details>
                    </div>

                    <!-- Live summary -->
                    <aside class="tcsp-sp-summary" aria-live="polite">
                        <h3><?php esc_html_e( 'Order summary', 'tutor-learning-suite' ); ?></h3>
                        <div class="tcsp-sp-summary__empty" data-empty><?php esc_html_e( 'Choose what to promote and a plan to see your price.', 'tutor-learning-suite' ); ?></div>
                        <div class="tcsp-sp-summary__body" data-body hidden>
                            <div class="tcsp-sp-summary__target" data-s-target></div>
                            <div class="tcsp-sp-summary__plan"><span class="tcsp-sp-dot" data-s-dot></span><b data-s-plan></b></div>
                            <p class="tcsp-sp-summary__desc" data-s-desc></p>
                            <dl class="tcsp-sp-summary__rows">
                                <div><dt><?php esc_html_e( 'Duration', 'tutor-learning-suite' ); ?></dt><dd data-s-duration></dd></div>
                                <div><dt><?php esc_html_e( 'Price per unit', 'tutor-learning-suite' ); ?></dt><dd data-s-unit></dd></div>
                                <div class="tcsp-sp-summary__disc" data-s-disc-row hidden><dt><?php esc_html_e( 'Bulk discount', 'tutor-learning-suite' ); ?></dt><dd data-s-disc></dd></div>
                            </dl>
                            <div class="tcsp-sp-summary__total"><span><?php esc_html_e( 'Total', 'tutor-learning-suite' ); ?></span><b data-s-total></b></div>
                            <p class="tcsp-sp-summary__when" data-s-when></p>
                        </div>
                        <button type="submit" class="tcsp-sp-cta" disabled><?php esc_html_e( 'Continue to payment', 'tutor-learning-suite' ); ?></button>
                        <p class="tcsp-sp-summary__note"><?php esc_html_e( 'You will choose your payment method on the next step.', 'tutor-learning-suite' ); ?></p>
                    </aside>
                </div>
            </form>
            <script src="<?php echo esc_url( TCSP_URL . 'assets/js/tcsp-sponsorship.js?ver=' . $ver ); ?>" defer></script>

            <?php else :
                $current = array(); $history = array();
                foreach ( $orders as $order ) {
                    $st = (string) get_post_meta( $order->ID, '_tcsp_status', true );
                    if ( in_array( $st, array( 'pending', 'paid', 'scheduled', 'active' ), true ) ) $current[] = $order; else $history[] = $order;
                }
                $render_order = function( $order ) use ( $base, $today ) {
                    $type   = (string) get_post_meta( $order->ID, '_tcsp_target_type', true );
                    $target = absint( get_post_meta( $order->ID, '_tcsp_target_id', true ) );
                    $tier   = (string) get_post_meta( $order->ID, '_tcsp_plan', true );
                    $status = $this->status_class( (string) get_post_meta( $order->ID, '_tcsp_status', true ) );
                    $start  = (string) get_post_meta( $order->ID, '_tcsp_start', true );
                    $end    = (string) get_post_meta( $order->ID, '_tcsp_end', true );
                    $units  = max( 1, min( 12, absint( get_post_meta( $order->ID, '_tcsp_units', true ) ?: 1 ) ) );
                    $mode   = (string) get_post_meta( $order->ID, '_tcsp_mode', true );
                    $totals = $this->order_totals( $order->ID );
                    $plan   = $this->plan( $type, $tier );
                    $color  = $this->esc_plan_color( $plan['color'] ?? '' );
                    $title  = 'course' === $type ? ( get_the_title( $target ) ?: __( 'Deleted course', 'tutor-learning-suite' ) ) : __( 'My tutor profile', 'tutor-learning-suite' );
                    $mode_label = 'extend' === $mode ? __( 'Extension', 'tutor-learning-suite' ) : ( 'change_plan' === $mode ? __( 'Scheduled plan change', 'tutor-learning-suite' ) : __( 'New promotion', 'tutor-learning-suite' ) );
                    $unpaid = ( 'pending' === $status && $this->is_unpaid_pending( $order->ID ) );
                    $progress = null;
                    if ( 'active' === $status && $start && $end ) {
                        $s = strtotime( $start . ' 00:00:00' ); $e = strtotime( $end . ' 00:00:00' ); $n = strtotime( $today . ' 00:00:00' );
                        if ( $e > $s ) $progress = max( 0, min( 100, (int) round( ( ( $n - $s ) / ( $e - $s ) ) * 100 ) ) );
                    }
                    ?>
                    <article class="tcsp-sp-order tcsp-sp-order--<?php echo esc_attr( $status ); ?>" style="--plan-color:<?php echo esc_attr( $color ); ?>">
                        <header class="tcsp-sp-order__head">
                            <div>
                                <h4><?php echo esc_html( $title ); ?></h4>
                                <div class="tcsp-sp-order__chips">
                                    <span class="tcsp-sp-chip tcsp-sp-chip--muted"><?php echo esc_html( 'course' === $type ? __( 'Course', 'tutor-learning-suite' ) : __( 'Tutor profile', 'tutor-learning-suite' ) ); ?></span>
                                    <span class="tcsp-sp-chip" style="--plan-color:<?php echo esc_attr( $color ); ?>"><?php echo esc_html( $plan['label'] ?? $tier ); ?></span>
                                    <span class="tcsp-sp-chip tcsp-sp-chip--muted"><?php echo esc_html( $mode_label ); ?></span>
                                </div>
                            </div>
                            <span class="tcsp-sp-pill tcsp-sp-pill-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( $unpaid ? __( 'Awaiting payment', 'tutor-learning-suite' ) : $this->view_status_label( $status ) ); ?></span>
                        </header>
                        <dl class="tcsp-sp-order__grid">
                            <div><dt><?php esc_html_e( 'Term', 'tutor-learning-suite' ); ?></dt><dd><?php echo esc_html( sprintf( /* translators: 1: units, 2: days */ __( '%1$d × unit · %2$d days', 'tutor-learning-suite' ), $units, (int) $totals['duration'] ) ); ?></dd></div>
                            <div><dt><?php esc_html_e( 'Amount', 'tutor-learning-suite' ); ?></dt><dd><?php echo esc_html( $this->view_money( (float) $totals['amount'] ) ); ?></dd></div>
                            <div><dt><?php esc_html_e( 'Dates', 'tutor-learning-suite' ); ?></dt><dd><?php echo esc_html( $start && $end ? mysql2date( get_option( 'date_format' ), $start ) . ' → ' . mysql2date( get_option( 'date_format' ), $end ) : '—' ); ?></dd></div>
                        </dl>
                        <?php if ( null !== $progress ) : ?>
                            <div class="tcsp-sp-progress" role="progressbar" aria-valuenow="<?php echo (int) $progress; ?>" aria-valuemin="0" aria-valuemax="100"><i style="width:<?php echo (int) $progress; ?>%"></i></div>
                            <p class="tcsp-sp-order__note"><?php echo esc_html( sprintf( _n( '%d day left', '%d days left', $this->view_days_left( $end ), 'tutor-learning-suite' ), $this->view_days_left( $end ) ) ); ?></p>
                        <?php elseif ( 'scheduled' === $status ) : ?>
                            <p class="tcsp-sp-order__note"><?php echo esc_html( sprintf( __( 'Paid. Starts automatically on %s.', 'tutor-learning-suite' ), $start ? mysql2date( get_option( 'date_format' ), $start ) : '—' ) ); ?></p>
                        <?php elseif ( 'paid' === $status ) : ?>
                            <p class="tcsp-sp-order__note"><?php esc_html_e( 'Payment received. Your sponsorship is being activated.', 'tutor-learning-suite' ); ?></p>
                        <?php elseif ( $unpaid ) : ?>
                            <p class="tcsp-sp-order__note"><?php esc_html_e( 'Complete the payment to start your sponsorship.', 'tutor-learning-suite' ); ?></p>
                        <?php endif; ?>
                        <?php
                        $days_left_card = ( 'active' === $status && $end ) ? $this->view_days_left( $end ) : 999;
                        if ( $target && ( 'expired' === $status || $days_left_card <= 14 ) ) :
                            $renew_url = add_query_arg( array( 'tcsp_sp_tab' => 1, 'tcsp_target' => $type . ':' . $target, 'tcsp_plan' => $tier, 'tcsp_units' => $units ), $base );
                            ?>
                            <div class="tcsp-sp-order__actions"><a class="tcsp-sp-btn" href="<?php echo esc_url( $renew_url ); ?>"><?php echo esc_html( 'expired' === $status ? __( 'Sponsor again', 'tutor-learning-suite' ) : __( 'Renew now', 'tutor-learning-suite' ) ); ?></a></div>
                        <?php endif; ?>
                        <?php if ( $unpaid ) : ?>
                            <div class="tcsp-sp-order__actions">
                                <a class="tcsp-sp-btn" href="<?php echo esc_url( wp_nonce_url( add_query_arg( self::CHECKOUT_PARAM, (int) $order->ID, $base ), self::CHECKOUT_PARAM . '_' . (int) $order->ID ) ); ?>"><?php esc_html_e( 'Pay now', 'tutor-learning-suite' ); ?></a>
                                <a class="tcsp-sp-btn tcsp-sp-btn--ghost" href="<?php echo esc_url( wp_nonce_url( add_query_arg( self::CANCEL_PARAM, (int) $order->ID, $base ), self::CANCEL_PARAM . '_' . (int) $order->ID ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Cancel this unpaid sponsorship request?', 'tutor-learning-suite' ) ); ?>');"><?php esc_html_e( 'Cancel request', 'tutor-learning-suite' ); ?></a>
                            </div>
                        <?php endif; ?>
                    </article>
                    <?php
                };
                ?>
                <?php if ( $orders ) : ?>
                    <?php if ( $current ) : ?>
                        <h3 class="tcsp-sp-section-title"><?php esc_html_e( 'Current and upcoming', 'tutor-learning-suite' ); ?></h3>
                        <div class="tcsp-sp-orders"><?php foreach ( $current as $order ) $render_order( $order ); ?></div>
                    <?php endif; ?>
                    <?php if ( $history ) : ?>
                        <h3 class="tcsp-sp-section-title"><?php esc_html_e( 'History', 'tutor-learning-suite' ); ?></h3>
                        <div class="tcsp-sp-orders"><?php foreach ( $history as $order ) $render_order( $order ); ?></div>
                    <?php endif; ?>
                <?php else : ?>
                    <div class="tcsp-sp-empty tcsp-sp-empty--big">
                        <p><?php esc_html_e( 'No sponsorship purchases or requests yet.', 'tutor-learning-suite' ); ?></p>
                        <a class="tcsp-sp-btn" href="<?php echo esc_url( add_query_arg( 'tcsp_sp_tab', 1, $base ) ); ?>"><?php esc_html_e( 'Promote something', 'tutor-learning-suite' ); ?></a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php return ob_get_clean();
    }

    /* ------------------------------------------------------------------
     * Native Tutor order + pay step (no hidden checkout courses).
     *
     * The sponsorship record (tcsp_sponsor_order) is itself the item of a real
     * Tutor order. Tutor's gateways and the offline-payment module are offered
     * on a dedicated pay step, and the sponsorship activates once the native
     * order is paid. Nothing is ever enrolled and no course post is created.
     * ------------------------------------------------------------------ */


    private function payee_id() {
        return (int) apply_filters( 'tcsp_sponsorship_payee_id', $this->sponsorship_admin_owner() );
    }

    /* ---------- Native cart + checkout (temporary checkout course, deleted after use) ---------- */

    const CHECKOUT_PARAM = 'tcsp_sp_checkout';
    const CANCEL_PARAM   = 'tcsp_sp_cancel';

    /** Tutor's cart only holds real courses, so each request gets a short-lived hidden one. */
    private function cart_available() {
        return class_exists( '\Tutor\Models\CartModel' ) && function_exists( 'tutor_utils' ) && tutor_utils()->is_monetize_by_tutor();
    }

    private function checkout_course_pt() {
        return function_exists( 'tutor' ) && ! empty( tutor()->course_post_type ) ? tutor()->course_post_type : 'courses';
    }

    private function is_sponsor_item( $item_id ) {
        $item_id = absint( $item_id );
        return $item_id && $this->checkout_course_pt() === get_post_type( $item_id ) && (int) get_post_meta( $item_id, '_tcsp_sponsor_id', true ) > 0;
    }

    /** Offline payments for sponsorship go to the site owner, never to the tutor buying it. */
    public function offline_payee_for_sponsorship( $author_id, $item_id ) {
        return $this->is_sponsor_item( $item_id ) ? ( $this->payee_id() ?: $author_id ) : $author_id;
    }

    /** Sponsorship revenue belongs entirely to the site: no instructor share. */
    public function sponsorship_earning_data( $data ) {
        if ( ! is_array( $data ) || empty( $data['course_id'] ) || ! $this->is_sponsor_item( $data['course_id'] ) ) return $data;
        $total = isset( $data['course_price_total'] ) ? (float) $data['course_price_total'] : 0.0;
        $payee = $this->payee_id();
        if ( $payee ) $data['user_id'] = $payee;
        $data['instructor_amount'] = 0;
        $data['instructor_rate']   = 0;
        $data['admin_amount']      = $total;
        $data['admin_rate']        = 100;
        return $data;
    }

    /** Return the request's temporary checkout course, creating it when missing. */
    private function checkout_course_for( $sponsor_id ) {
        $sponsor_id = absint( $sponsor_id );
        if ( ! $sponsor_id || self::CPT !== get_post_type( $sponsor_id ) ) return 0;
        $existing = absint( get_post_meta( $sponsor_id, '_tcsp_cart_course_id', true ) );
        if ( $existing && $this->checkout_course_pt() === get_post_type( $existing ) && 'trash' !== get_post_status( $existing ) ) return $existing;
        $totals = $this->order_totals( $sponsor_id );
        $course_id = wp_insert_post( array(
            'post_type'    => $this->checkout_course_pt(),
            'post_status'  => 'publish',
            'post_title'   => sprintf( __( 'Sponsorship request #%d', 'tutor-learning-suite' ), $sponsor_id ),
            'post_author'  => $this->payee_id() ?: (int) get_post_field( 'post_author', $sponsor_id ),
            'post_content' => __( 'Temporary checkout item for a sponsorship request. It is removed automatically after payment or cancellation.', 'tutor-learning-suite' ),
        ), true );
        if ( is_wp_error( $course_id ) || ! $course_id ) return 0;
        $course_id = (int) $course_id;
        update_post_meta( $course_id, 'tutor_course_price', (float) $totals['amount'] );
        update_post_meta( $course_id, 'tutor_course_sale_price', '' );
        $settings = get_post_meta( $course_id, '_tutor_course_settings', true );
        if ( ! is_array( $settings ) ) $settings = array();
        $settings['course_enrollment_period'] = 'yes';
        $settings['enrollment_starts_at']     = '';
        $settings['enrollment_ends_at']       = '';
        $settings['pause_enrollment']         = 'no';
        $settings['maximum_students']         = 0;
        update_post_meta( $course_id, '_tutor_course_settings', $settings );
        update_post_meta( $course_id, '_tutor_course_price_type', class_exists( '\TUTOR\Course' ) ? \TUTOR\Course::PRICE_TYPE_PAID : 'paid' );
        update_post_meta( $course_id, 'tutor_course_selling_option', class_exists( '\TUTOR\Course' ) ? \TUTOR\Course::SELLING_OPTION_ONE_TIME : 'one_time' );
        update_post_meta( $course_id, '_tutor_is_public_course', 'no' );
        update_post_meta( $course_id, '_tlms_ps_internal_course', 1 ); // Reuses the private-sessions listing/instructor-page hiding.
        update_post_meta( $course_id, '_tcsp_sponsor_id', $sponsor_id );
        update_post_meta( $sponsor_id, '_tcsp_cart_course_id', $course_id );
        return $course_id;
    }

    /** Put the temporary course in the user's native cart; returns false on failure. */
    private function add_to_native_cart( $user_id, $course_id ) {
        if ( ! $this->cart_available() || ! $user_id || ! $course_id ) return false;
        if ( \Tutor\Models\CartModel::is_course_in_user_cart( $user_id, $course_id ) ) return true;
        return (bool) ( new \Tutor\Models\CartModel() )->add_course_to_cart( $user_id, $course_id );
    }

    private function native_cart_url() {
        return class_exists( '\Tutor\Ecommerce\CartController' ) ? \Tutor\Ecommerce\CartController::get_page_url() : home_url( '/cart/' );
    }

    /** Remove the temporary course, its cart rows and the enrolment Tutor created for it. */
    private function delete_checkout_course( $sponsor_id ) {
        $course_id = absint( get_post_meta( $sponsor_id, '_tcsp_cart_course_id', true ) );
        if ( ! $course_id ) return;
        if ( (int) get_post_meta( $course_id, '_tcsp_sponsor_id', true ) === (int) $sponsor_id ) {
            global $wpdb;
            $table = $wpdb->prefix . 'tutor_cart_items';
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) $wpdb->delete( $table, array( 'course_id' => $course_id ), array( '%d' ) );
            foreach ( (array) get_posts( array( 'post_type' => 'tutor_enrolled', 'post_parent' => $course_id, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true ) ) as $enrol_id ) wp_delete_post( (int) $enrol_id, true );
            wp_delete_post( $course_id, true );
        }
        delete_post_meta( $sponsor_id, '_tcsp_cart_course_id' );
    }

    /** The native order created at checkout for this request, if any. */
    private function native_order_for( $sponsor_id ) {
        global $wpdb;
        $course_id = absint( get_post_meta( $sponsor_id, '_tcsp_cart_course_id', true ) );
        $native = absint( get_post_meta( $sponsor_id, '_tcsp_native_order_id', true ) );
        if ( ! $native && $course_id ) {
            $native = (int) $wpdb->get_var( $wpdb->prepare( "SELECT order_id FROM {$wpdb->prefix}tutor_order_items WHERE item_id = %d ORDER BY id DESC LIMIT 1", $course_id ) );
        }
        if ( ! $native || ! class_exists( '\Tutor\Models\OrderModel' ) ) return null;
        $order = ( new \Tutor\Models\OrderModel() )->get_order_by_id( $native );
        return $order ?: null;
    }

    /** True while the request is pending and has no paid, in-progress or offline-submitted order. */
    private function is_unpaid_pending( $sponsor_id ) {
        if ( 'pending' !== (string) get_post_meta( $sponsor_id, '_tcsp_status', true ) ) return false;
        $order = $this->native_order_for( $sponsor_id );
        if ( ! $order ) return true;
        if ( \Tutor\Models\OrderModel::PAYMENT_UNPAID !== $order->payment_status || \Tutor\Models\OrderModel::ORDER_INCOMPLETE !== $order->order_status ) return false;
        return ! ( class_exists( '\TutorInstructorOfflinePayment\Orders' ) && \TutorInstructorOfflinePayment\Orders::is_offline( (int) $order->id ) );
    }

    /** Cancel an unpaid request together with its native order (if checkout was reached). */
    private function cancel_unpaid_request( $sponsor_id ) {
        $order = $this->native_order_for( $sponsor_id );
        if ( $order ) ( new \Tutor\Models\OrderModel() )->update_order( (int) $order->id, array( 'order_status' => \Tutor\Models\OrderModel::ORDER_CANCELLED ) );
        $this->set_order_status( $sponsor_id, 'cancelled' ); // also deletes the temporary course
    }

    /** "Pay now" / "Cancel" links from the sponsorship list. */
    public function handle_cart_requests() {
        if ( ! is_user_logged_in() ) return;
        $user_id = get_current_user_id();
        foreach ( array( self::CHECKOUT_PARAM, self::CANCEL_PARAM ) as $param ) {
            if ( empty( $_GET[ $param ] ) ) continue;
            $sid = absint( $_GET[ $param ] );
            $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
            if ( ! $sid || ! wp_verify_nonce( $nonce, $param . '_' . $sid ) ) return;
            if ( self::CPT !== get_post_type( $sid ) || (int) get_post_field( 'post_author', $sid ) !== $user_id || ! $this->is_unpaid_pending( $sid ) ) $this->redirect_frontend_dashboard_notice( 'invalid' );
            if ( self::CANCEL_PARAM === $param ) {
                $this->cancel_unpaid_request( $sid );
                $this->redirect_frontend_dashboard_notice( 'cancelled' );
            }
            $course_id = $this->checkout_course_for( $sid );
            if ( ! $course_id || ! $this->add_to_native_cart( $user_id, $course_id ) ) $this->redirect_notice( 'checkout_error' );
            wp_safe_redirect( $this->native_cart_url() );
            exit;
        }
    }

    /** One-time cleanup of the legacy hidden checkout products used before native sponsorship orders. */
    public function migrate_legacy_checkout_products() {
        if ( get_option( 'tcsp_sponsorship_native_orders_v1' ) ) return;
        global $wpdb;
        $items_table  = $wpdb->prefix . 'tutor_order_items';
        $orders_table = $wpdb->prefix . 'tutor_orders';
        $has_tables   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $items_table ) ) === $items_table;
        $claimed = array();
        $sponsor_posts = get_posts( array( 'post_type' => self::CPT, 'post_status' => 'any', 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'DESC', 'meta_key' => '_tcsp_checkout_course_id', 'no_found_rows' => true ) );
        if ( $has_tables ) {
            foreach ( $sponsor_posts as $sp ) {
                $product = absint( get_post_meta( $sp->ID, '_tcsp_checkout_course_id', true ) );
                if ( ! $product ) continue;
                $rows = $wpdb->get_results( $wpdb->prepare( "SELECT oi.id FROM {$items_table} oi INNER JOIN {$orders_table} o ON o.id = oi.order_id WHERE oi.item_id = %d AND o.user_id = %d ORDER BY o.id ASC", $product, (int) $sp->post_author ) );
                foreach ( (array) $rows as $row ) {
                    if ( isset( $claimed[ (int) $row->id ] ) ) continue;
                    $claimed[ (int) $row->id ] = true;
                    $wpdb->update( $items_table, array( 'item_id' => (int) $sp->ID ), array( 'id' => (int) $row->id ), array( '%d' ), array( '%d' ) );
                    if ( ! get_post_meta( $sp->ID, '_tcsp_native_order_id', true ) ) {
                        $oid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT order_id FROM {$items_table} WHERE id = %d", (int) $row->id ) );
                        if ( $oid ) update_post_meta( $sp->ID, '_tcsp_native_order_id', $oid );
                    }
                    break;
                }
            }
        }
        $products = get_posts( array( 'post_type' => TCSP_Plugin::COURSE_CPT, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true, 'suppress_filters' => true, 'meta_key' => '_tcsp_sponsorship_checkout_product', 'meta_value' => '1' ) );
        foreach ( $products as $pid ) wp_trash_post( (int) $pid );
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'tcsp\\_sponsorship\\_product\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE '\\_tcsp\\_pending\\_sponsorship\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        update_option( 'tcsp_sponsorship_native_orders_v1', time(), false );
    }

    public function shortcode() {
        return $this->render_portal_view();
    }

}
