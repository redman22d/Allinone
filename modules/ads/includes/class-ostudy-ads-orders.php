<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Ad orders: lets visitors (guests or logged-in users) request an advertisement,
 * receive an order number, and follow its status. Admins manage orders and can
 * turn an order into a real ad in one click.
 *
 * Shortcodes:
 *   [ostudy_ad_order]  order form + "Track my order" tab
 *   [ostudy_ad_track]  tracking panel only
 */
class OStudy_Ads_Orders {
    const TABLE = 'ostudy_ads_orders';
    const PRIVATE_PROOF_DIR = 'ostudy-ads-payment-proofs';
    private static $instance;
    private $assets_ready = false;

    public static function instance() {
        if ( ! self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_shortcode( 'ostudy_ad_order', array( $this, 'shortcode_order' ) );
        add_shortcode( 'ostudy_ad_track', array( $this, 'shortcode_track' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
        add_action( 'wp_ajax_ostudy_ads_order_submit', array( $this, 'ajax_submit' ) );
        add_action( 'wp_ajax_nopriv_ostudy_ads_order_submit', array( $this, 'ajax_submit' ) );
        add_action( 'wp_ajax_ostudy_ads_order_track', array( $this, 'ajax_track' ) );
        add_action( 'wp_ajax_nopriv_ostudy_ads_order_track', array( $this, 'ajax_track' ) );
        add_action( 'wp_ajax_ostudy_ads_order_proof', array( $this, 'ajax_proof' ) );
        add_action( 'wp_ajax_nopriv_ostudy_ads_order_proof', array( $this, 'ajax_proof' ) );
        add_action( 'wp_ajax_nopriv_ostudy_ads_order_availability', array( $this, 'ajax_availability' ) );
        add_action( 'wp_ajax_ostudy_ads_order_availability', array( $this, 'ajax_availability' ) );
        add_action( 'wp_ajax_ostudy_ads_order_renew', array( $this, 'ajax_renew' ) );
        add_action( 'wp_ajax_nopriv_ostudy_ads_order_renew', array( $this, 'ajax_renew' ) );
        add_shortcode( 'ostudy_advertise', array( $this, 'shortcode_advertise' ) );
        add_action( 'ostudy_ads_daily_orders', array( $this, 'run_daily' ) );
        if ( ! wp_next_scheduled( 'ostudy_ads_daily_orders' ) ) wp_schedule_event( time() + 900, 'daily', 'ostudy_ads_daily_orders' );
        add_action( 'admin_post_ostudy_ads_view_proof', array( $this, 'serve_payment_proof' ) );
        add_action( 'admin_init', array( $this, 'migrate_legacy_proofs' ), 30 );
        if ( is_admin() ) {
            add_action( 'admin_menu', array( $this, 'admin_menu' ), 20 );
            add_action( 'admin_init', array( $this, 'admin_handle_post' ) );
        }
    }

    /* ------------------------------------------------------------------
     * Schema / data helpers
     * ---------------------------------------------------------------- */

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    public static function schema_sql() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        return 'CREATE TABLE ' . self::table() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_code varchar(20) NOT NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            contact_name varchar(190) NOT NULL DEFAULT '',
            email varchar(190) NOT NULL DEFAULT '',
            phone varchar(60) NOT NULL DEFAULT '',
            business_name varchar(190) NOT NULL DEFAULT '',
            ad_title varchar(190) NOT NULL DEFAULT '',
            ad_type varchar(30) NOT NULL DEFAULT 'banner',
            placements longtext NULL,
            description longtext NULL,
            destination_url text NULL,
            image_id bigint(20) unsigned NOT NULL DEFAULT 0,
            image_url text NULL,
            mobile_image_id bigint(20) unsigned NOT NULL DEFAULT 0,
            mobile_image_url text NULL,
            need_design tinyint(1) NOT NULL DEFAULT 0,
            start_date date NULL,
            duration_days int(11) NOT NULL DEFAULT 7,
            budget varchar(60) NOT NULL DEFAULT '',
            amount_due varchar(60) NOT NULL DEFAULT '',
            payment_proof_id bigint(20) unsigned NOT NULL DEFAULT 0,
            payment_proof_path text NULL,
            payment_proof_url text NULL,
            payment_reference varchar(120) NOT NULL DEFAULT '',
            payment_note text NULL,
            payment_submitted_at datetime NULL,
            reminders_sent varchar(20) NOT NULL DEFAULT '',
            status varchar(30) NOT NULL DEFAULT 'pending',
            public_note text NULL,
            internal_note text NULL,
            history longtext NULL,
            ad_id bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY order_code (order_code),
            KEY status (status),
            KEY user_id (user_id),
            KEY email (email)
        ) $charset;";
    }

    public static function statuses() {
        $d = 'tutor-learning-suite';
        return array(
            'pending'          => array( 'label' => __( 'Received', $d ), 'color' => '#d97706', 'desc' => __( 'We received your request and it is waiting for review.', $d ) ),
            'in_review'        => array( 'label' => __( 'In review', $d ), 'color' => '#2563eb', 'desc' => __( 'Our team is checking your ad and details.', $d ) ),
            'awaiting_payment' => array( 'label' => __( 'Awaiting payment', $d ), 'color' => '#7c3aed', 'desc' => __( 'Your ad is approved. Complete the payment so we can schedule it.', $d ) ),
            'payment_review'   => array( 'label' => __( 'Payment under review', $d ), 'color' => '#0891b2', 'desc' => __( 'We received your payment proof and are verifying it.', $d ) ),
            'live'             => array( 'label' => __( 'Live', $d ), 'color' => '#059669', 'desc' => __( 'Your ad is running on the platform.', $d ) ),
            'completed'        => array( 'label' => __( 'Completed', $d ), 'color' => '#475569', 'desc' => __( 'The campaign has finished. Thank you!', $d ) ),
            'rejected'         => array( 'label' => __( 'Rejected', $d ), 'color' => '#dc2626', 'desc' => __( 'We could not approve this ad. See the note from our team.', $d ) ),
            'cancelled'        => array( 'label' => __( 'Cancelled', $d ), 'color' => '#64748b', 'desc' => __( 'This order was cancelled.', $d ) ),
        );
    }

    /** Happy-path steps shown as the progress timeline. */
    public static function flow() {
        return array( 'pending', 'in_review', 'awaiting_payment', 'payment_review', 'live', 'completed' );
    }

    public static function durations() {
        return array( 7 => __( '1 week', 'tutor-learning-suite' ), 14 => __( '2 weeks', 'tutor-learning-suite' ), 30 => __( '1 month', 'tutor-learning-suite' ), 60 => __( '2 months', 'tutor-learning-suite' ), 90 => __( '3 months', 'tutor-learning-suite' ) );
    }

    /** Public, advertiser-friendly placement list: zone => [ type, label ]. */
    public static function placement_choices() {
        $d = 'tutor-learning-suite';
        return array(
            'home_top'                 => array( 'banner',  __( 'Homepage — top banner', $d ) ),
            'course_search_top'        => array( 'banner',  __( 'Course catalog — top banner', $d ) ),
            'tutor_search_top'         => array( 'banner',  __( 'Instructor search — top banner', $d ) ),
            'instructor_profile_top'   => array( 'banner',  __( 'Instructor profiles — top banner', $d ) ),
            'course_page_top'          => array( 'banner',  __( 'Course pages — top banner', $d ) ),
            'dashboard_sidebar'        => array( 'sidebar', __( 'Student dashboard — sidebar', $d ) ),
            'instructor_profile_side'  => array( 'sidebar', __( 'Instructor profiles — side', $d ) ),
            'home_popup'               => array( 'popup',   __( 'Homepage — popup', $d ) ),
            'course_search_popup'      => array( 'popup',   __( 'Course catalog — popup', $d ) ),
            'tutor_search_popup'       => array( 'popup',   __( 'Instructor search — popup', $d ) ),
            'instructor_profile_popup' => array( 'popup',   __( 'Instructor profiles — popup', $d ) ),
            'course_page_popup'        => array( 'popup',   __( 'Course pages — popup', $d ) ),
            'dashboard_popup'          => array( 'popup',   __( 'Student dashboard — popup', $d ) ),
        );
    }

    public static function get_order( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', absint( $id ) ) );
    }

    public static function get_order_by_code( $code ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE order_code = %s', $code ) );
    }

    private static function count_status( $status ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE status = %s', $status ) );
    }

    private static function generate_code() {
        global $wpdb;
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O/1/I confusion
        $max = strlen( $alphabet ) - 1;
        for ( $i = 0; $i < 10; $i++ ) {
            $code = 'AD-';
            for ( $j = 0; $j < 8; $j++ ) $code .= $alphabet[ random_int( 0, $max ) ];
            $exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE order_code = %s', $code ) );
            if ( ! $exists ) return $code;
        }
        return 'AD-' . strtoupper( substr( preg_replace( '/[^A-Za-z0-9]/', '', wp_generate_password( 20, false, false ) ), 0, 8 ) );
    }

    private static function normalize_code( $input ) {
        $c = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $input ) );
        if ( 10 === strlen( $c ) && 0 === strpos( $c, 'AD' ) ) $c = substr( $c, 2 );
        return 8 === strlen( $c ) ? 'AD-' . $c : '';
    }

    private static function contact_matches( $order, $contact ) {
        $contact = trim( (string) $contact );
        if ( '' === $contact ) return false;
        if ( is_email( $contact ) ) {
            return '' !== (string) $order->email && hash_equals( strtolower( (string) $order->email ), strtolower( $contact ) );
        }
        $given = preg_replace( '/\D+/', '', $contact );
        $saved = preg_replace( '/\D+/', '', (string) $order->phone );
        if ( strlen( $given ) < 8 || strlen( $saved ) < 8 ) return false;
        return substr( $given, -9 ) === substr( $saved, -9 );
    }

    private static function can_access( $order, $contact ) {
        if ( ! $order ) return false;
        if ( is_user_logged_in() && (int) $order->user_id === get_current_user_id() ) return true;
        return self::contact_matches( $order, $contact );
    }

    private static function rate_limited( $bucket, $max, $window ) {
        $ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0';
        $key = 'ostudy_ao_' . $bucket . '_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ), 0, 20 );
        $n   = (int) get_transient( $key );
        if ( $n >= $max ) return true;
        set_transient( $key, $n + 1, $window );
        return false;
    }

    private static function history_add( $history_json, $status, $note = '' ) {
        $h = json_decode( (string) $history_json, true );
        if ( ! is_array( $h ) ) $h = array();
        $h[] = array( 't' => gmdate( 'Y-m-d H:i:s' ), 's' => $status, 'n' => $note );
        return wp_json_encode( $h );
    }

    private static function order_page_url() {
        $s = OStudy_Ads_DB::get_settings();
        return ! empty( $s['order_page_url'] ) ? $s['order_page_url'] : home_url( '/' );
    }

    private static function track_url( $code ) {
        return add_query_arg( 'ad_order', rawurlencode( $code ), self::order_page_url() );
    }

    private static function local_date( $mysql_gmt ) {
        $ts = strtotime( $mysql_gmt . ' UTC' );
        return $ts ? wp_date( get_option( 'date_format' ) . ' · H:i', $ts, wp_timezone() ) : '';
    }

    /* ------------------------------------------------------------------
     * Pricing, availability, image rules
     * ---------------------------------------------------------------- */

    public static function currency_label() {
        $s = OStudy_Ads_DB::get_settings();
        return trim( (string) $s['order_currency'] );
    }

    public static function format_money( $n ) {
        $n   = (float) $n;
        $txt = number_format_i18n( $n, abs( $n - round( $n ) ) < 0.005 ? 0 : 2 );
        $cur = self::currency_label();
        return '' !== $cur ? $txt . ' ' . $cur : $txt;
    }

    /** zone => price per 7 days (only zones with a price). */
    public static function zone_prices() {
        $s = OStudy_Ads_DB::get_settings();
        $out = array();
        foreach ( (array) $s['order_prices'] as $zone => $price ) {
            $price = (float) $price;
            if ( $price > 0 ) $out[ sanitize_key( $zone ) ] = $price;
        }
        return $out;
    }

    public static function pricing_enabled() {
        return (bool) self::zone_prices();
    }

    /** days => percent, ascending. */
    public static function discount_tiers() {
        $s = OStudy_Ads_DB::get_settings();
        $out = array();
        foreach ( (array) $s['order_discounts'] as $days => $pct ) {
            $pct = (float) $pct;
            if ( $pct > 0 ) $out[ (int) $days ] = min( 90, $pct );
        }
        ksort( $out );
        return $out;
    }

    public static function discount_for_days( $days ) {
        $pct = 0;
        foreach ( self::discount_tiers() as $d => $p ) if ( $days >= $d ) $pct = $p;
        return $pct;
    }

    public static function quote( $zones, $days ) {
        $prices = self::zone_prices();
        $per_week = 0;
        foreach ( (array) $zones as $z ) if ( isset( $prices[ $z ] ) ) $per_week += $prices[ $z ];
        $gross = $per_week * ( max( 1, (int) $days ) / 7 );
        $pct   = self::discount_for_days( (int) $days );
        return array( 'gross' => round( $gross, 2 ), 'discount_pct' => $pct, 'total' => round( $gross * ( 1 - $pct / 100 ), 2 ) );
    }

    public static function reserved_statuses() {
        return array( 'awaiting_payment', 'payment_review', 'live' );
    }

    public static function order_end_date( $o ) {
        if ( empty( $o->start_date ) ) return '';
        return gmdate( 'Y-m-d', strtotime( $o->start_date . ' +' . ( max( 1, (int) $o->duration_days ) - 1 ) . ' days' ) );
    }

    /** How many reserved orders overlap this placement during the given dates. */
    public static function zone_usage( $zone, $start, $days, $exclude_id = 0 ) {
        global $wpdb;
        $end = gmdate( 'Y-m-d', strtotime( $start . ' +' . ( max( 1, (int) $days ) - 1 ) . ' days' ) );
        $sts = self::reserved_statuses();
        $in  = implode( ',', array_fill( 0, count( $sts ), '%s' ) );
        $sql = 'SELECT COUNT(*) FROM ' . self::table() . " WHERE status IN ($in) AND id <> %d AND placements LIKE %s AND start_date <= %s AND DATE_ADD(start_date, INTERVAL (duration_days - 1) DAY) >= %s";
        $args = array_merge( $sts, array( (int) $exclude_id, '%' . $wpdb->esc_like( '"' . $zone . '"' ) . '%', $end, $start ) );
        return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
    }

    public static function availability( $zones, $start, $days, $exclude_id = 0 ) {
        $s = OStudy_Ads_DB::get_settings();
        $max = (int) $s['order_max_per_placement'];
        $choices = self::placement_choices();
        $out = array();
        foreach ( (array) $zones as $z ) {
            $used = $max > 0 ? self::zone_usage( $z, $start, $days, $exclude_id ) : 0;
            $out[ $z ] = array( 'label' => isset( $choices[ $z ] ) ? $choices[ $z ][1] : $z, 'used' => $used, 'max' => $max, 'full' => ( $max > 0 && $used >= $max ) );
        }
        return $out;
    }

    /** zone => [ width, height ] parsed from the recommended desktop size. */
    public static function zone_sizes() {
        $dims = OStudy_Ads_Utils::recommended_dimensions();
        $out = array();
        foreach ( self::placement_choices() as $z => $c ) {
            if ( isset( $dims[ $z ]['desktop'] ) && preg_match( '/(\d+)\s*[×x]\s*(\d+)/u', $dims[ $z ]['desktop'], $m ) ) $out[ $z ] = array( (int) $m[1], (int) $m[2] );
        }
        return $out;
    }

    /** Returns a human message when the image does not suit the chosen placements, else ''. Mirrored in the JS. */
    public static function size_problem( $w, $h, $zones ) {
        $sizes = self::zone_sizes();
        $recs = array();
        foreach ( (array) $zones as $z ) if ( isset( $sizes[ $z ] ) ) $recs[ $z ] = $sizes[ $z ];
        if ( ! $recs || $w < 1 || $h < 1 ) return '';
        $max_w = 0; $ratios = array();
        foreach ( $recs as $r ) { $max_w = max( $max_w, $r[0] ); $ratios[] = $r[0] / $r[1]; }
        $min_w = (int) round( $max_w * 0.6 );
        if ( $w < $min_w ) {
            return sprintf( /* translators: 1,2: image size; 3: min width; 4: recommended width */ __( 'Your image is %1$d × %2$d px. For this placement it should be at least %3$d px wide (recommended %4$d px).', 'tutor-learning-suite' ), $w, $h, $min_w, $max_w );
        }
        if ( max( $ratios ) / min( $ratios ) <= 1.1 ) {
            $target = array_sum( $ratios ) / count( $ratios );
            $actual = $w / $h;
            if ( $actual < $target * 0.7 || $actual > $target * 1.3 ) {
                $rec = reset( $recs );
                return sprintf( __( 'The shape of your image (%1$d × %2$d px) does not fit this placement. Please use about %3$d × %4$d px.', 'tutor-learning-suite' ), $w, $h, $rec[0], $rec[1] );
            }
        }
        return '';
    }

    /* ------------------------------------------------------------------
     * Front-end: assets + shortcodes
     * ---------------------------------------------------------------- */

    public function maybe_enqueue() {
        $post = get_post();
        if ( $post && ( has_shortcode( (string) $post->post_content, 'ostudy_ad_order' ) || has_shortcode( (string) $post->post_content, 'ostudy_ad_track' ) || has_shortcode( (string) $post->post_content, 'ostudy_advertise' ) ) ) {
            $this->enqueue_assets();
        }
    }

    private function enqueue_assets() {
        if ( $this->assets_ready ) return;
        $this->assets_ready = true;
        $s = OStudy_Ads_DB::get_settings();
        wp_enqueue_style( 'ostudy-ads-order', OSTUDY_ADS_URL . 'assets/css/ostudy-ads-order.css', array(), OSTUDY_ADS_VERSION );
        wp_enqueue_script( 'ostudy-ads-order', OSTUDY_ADS_URL . 'assets/js/ostudy-ads-order.js', array(), OSTUDY_ADS_VERSION, true );
        $d = 'tutor-learning-suite';
        wp_localize_script( 'ostudy-ads-order', 'OStudyAdOrder', array(
            'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
            'nonce'    => is_user_logged_in() ? wp_create_nonce( 'ostudy_ads_order' ) : '',
            'maxBytes' => max( 1, absint( $s['order_max_upload_mb'] ) ) * MB_IN_BYTES,
            'pricing'  => array(
                'enabled'   => self::pricing_enabled(),
                'perWeek'   => (object) self::zone_prices(),
                'discounts' => array_map( function ( $d, $p ) { return array( 'days' => $d, 'pct' => $p ); }, array_keys( self::discount_tiers() ), array_values( self::discount_tiers() ) ),
                'currency'  => self::currency_label(),
            ),
            'maxSlots'     => (int) $s['order_max_per_placement'],
            'sizes'        => (object) self::zone_sizes(),
            'enforceSizes' => ! empty( $s['order_enforce_sizes'] ),
            'i18n'     => array(
                'required'   => __( 'Please fill in this field.', $d ),
                'pickFormat' => __( 'Choose an ad format.', $d ),
                'pickPlace'  => __( 'Choose at least one placement.', $d ),
                'pickImage'  => __( 'Upload your ad image, or tick “I need help designing my ad”.', $d ),
                'tooBig'     => __( 'This image is too large.', $d ),
                'badType'    => __( 'Please upload a JPG, PNG, WEBP or GIF image.', $d ),
                'badEmail'   => __( 'Enter a valid email address.', $d ),
                'badUrl'     => __( 'Enter a full link starting with http:// or https://', $d ),
                'terms'      => __( 'Please accept the advertising terms.', $d ),
                'sending'    => __( 'Sending…', $d ),
                'send'       => __( 'Send my order', $d ),
                'looking'    => __( 'Searching…', $d ),
                'track'      => __( 'Track order', $d ),
                'copied'     => __( 'Copied!', $d ),
                'copy'       => __( 'Copy number', $d ),
                'network'    => __( 'Connection problem. Please try again.', $d ),
                'proofNeed'  => __( 'Please choose your payment receipt (image or PDF).', $d ),
                'proofType'  => __( 'Please upload a JPG, PNG, WEBP image or a PDF.', $d ),
                'sendProof'  => __( 'Send payment proof', $d ),
                'estimated'  => __( 'Estimated total', $d ),
                'discountNote' => __( 'includes a %s%% long-booking discount', $d ),
                'perWeekNote'  => __( 'Final price is confirmed by our team.', $d ),
                'checking'     => __( 'Checking availability…', $d ),
                'fullFor'      => __( 'Fully booked for these dates: %s. Try other dates or another placement.', $d ),
                'availOk'      => __( 'Great — your dates are available.', $d ),
                'sizeWarnPrefix' => __( 'Image size:', $d ),
                'renew'        => __( 'Renew this ad', $d ),
                'renewing'     => __( 'Creating renewal…', $d ),
                'amountLabel'  => __( 'Estimated amount', $d ),
                'emailSent'  => __( 'A confirmation email has been sent to your address.', $d ),
                'emailFailed'=> __( 'Your order was saved, but we could not send the confirmation email. Please save your order number below.', $d ),
            ),
        ) );
    }

    public function shortcode_order( $atts ) {
        $atts = shortcode_atts( array( 'tracking' => '1' ), $atts, 'ostudy_ad_order' );
        $s = OStudy_Ads_DB::get_settings();
        $this->enqueue_assets();
        if ( empty( $s['orders_enabled'] ) ) {
            return '<div class="osao"><div class="osao__closed">' . esc_html__( 'Ad orders are currently closed. Please check back soon.', 'tutor-learning-suite' ) . '</div></div>';
        }
        $show_track = '0' !== (string) $atts['tracking'];
        $open_track = $show_track && ! empty( $_GET['ad_order'] ); // phpcs:ignore WordPress.Security.NonceVerification
        return $this->render_app( true, $show_track, $open_track ? 'track' : 'order', false );
    }

    public function shortcode_track( $atts ) {
        $this->enqueue_assets();
        return $this->render_app( false, true, 'track', false );
    }

    /** [ostudy_advertise] — one page for business ads, course/tutor sponsorship and order tracking. */
    public function shortcode_advertise( $atts ) {
        $this->enqueue_assets();
        $s = OStudy_Ads_DB::get_settings();
        $orders_open = ! empty( $s['orders_enabled'] );
        $tab = isset( $_GET['ad_tab'] ) ? sanitize_key( wp_unslash( $_GET['ad_tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        if ( ! empty( $_GET['ad_order'] ) ) $tab = 'track'; // phpcs:ignore WordPress.Security.NonceVerification
        if ( ! in_array( $tab, array( 'order', 'sponsor', 'track' ), true ) ) $tab = $orders_open ? 'order' : 'sponsor';
        if ( 'order' === $tab && ! $orders_open ) $tab = 'sponsor';
        return $this->render_app( $orders_open, true, $tab, true );
    }

    private function render_sponsor_panel() {
        $d = 'tutor-learning-suite';
        $html = '<h3 class="osao__h">' . esc_html__( 'Promote your course or tutor profile', $d ) . '</h3>';
        $html .= '<p class="osao__lead">' . esc_html__( 'Instructors can get their courses and profile in front of more students with a sponsorship plan: highlighted placement in search results and sponsored carousels.', $d ) . '</p>';
        $currency = (string) apply_filters( 'tcsp_sponsorship_currency', self::currency_label() );
        $fmt = function ( $n ) use ( $currency ) {
            return number_format_i18n( (float) $n, abs( (float) $n - round( (float) $n ) ) < 0.005 ? 0 : 2 ) . ( '' !== $currency ? ' ' . $currency : '' );
        };
        $unit = function ( $days ) use ( $d ) {
            $days = (int) $days;
            if ( 7 === $days ) return __( 'week', $d );
            if ( 30 === $days ) return __( 'month', $d );
            if ( 365 === $days ) return __( 'year', $d );
            return $days . ' ' . __( 'days', $d );
        };
        if ( class_exists( 'TCSP_Plugin' ) && isset( TCSP_Plugin::instance()->settings ) ) {
            $st = TCSP_Plugin::instance()->settings;
            foreach ( array( 'course' => array( __( 'Course sponsorship', $d ), $st->promotion_tiers() ), 'tutor' => array( __( 'Tutor profile sponsorship', $d ), $st->tutor_promotion_tiers() ) ) as $grp ) {
                if ( empty( $grp[1] ) ) continue;
                $html .= '<h4 class="osao__h osao__h--sub">' . esc_html( $grp[0] ) . '</h4><div class="osao-planrow">';
                foreach ( $grp[1] as $plan ) {
                    $color = sanitize_hex_color( $plan['color'] ?? '' ) ?: '#4f46e5';
                    $html .= '<div class="osao-plan" style="--plan:' . esc_attr( $color ) . '"><strong>' . esc_html( $plan['label'] ) . '</strong><span>' . esc_html( $plan['description'] ?? '' ) . '</span><b>' . esc_html( $fmt( $plan['price'] ) ) . ' <small>/ ' . esc_html( $unit( $plan['duration'] ?? 30 ) ) . '</small></b></div>';
                }
                $html .= '</div>';
            }
        }
        $url = function_exists( 'tutor_utils' ) ? tutor_utils()->tutor_dashboard_url( 'tcsp-sponsorship' ) : '';
        $html .= '<div class="osao-cta">';
        if ( ! is_user_logged_in() ) {
            $html .= '<p>' . esc_html__( 'Log in with your instructor account to choose a plan and pay.', $d ) . '</p><a class="osao__btn" href="' . esc_url( wp_login_url( $url ?: get_permalink() ) ) . '">' . esc_html__( 'Log in to continue', $d ) . '</a>';
        } elseif ( $url && function_exists( 'tutor_utils' ) && tutor_utils()->is_instructor( get_current_user_id() ) ) {
            $html .= '<p>' . esc_html__( 'Open your instructor dashboard to pick a course or your profile, choose a plan and pay.', $d ) . '</p><a class="osao__btn" href="' . esc_url( $url ) . '">' . esc_html__( 'Go to sponsorship', $d ) . '</a>';
        } else {
            $html .= '<p>' . esc_html__( 'Sponsorship is available to instructors. If you teach on this platform, make sure you are logged in with your instructor account.', $d ) . '</p>';
        }
        return $html . '</div>';
    }

    private function render_app( $with_order, $with_track, $active, $with_sponsor ) {
        $open_track = ( 'track' === $active );
        $s       = OStudy_Ads_DB::get_settings();
        $accent  = sanitize_hex_color( $s['order_accent_color'] ) ?: '#4f46e5';
        $user    = wp_get_current_user();
        $prefill = array(
            'name'  => $user && $user->exists() ? $user->display_name : '',
            'email' => $user && $user->exists() ? $user->user_email : '',
        );
        $dim = OStudy_Ads_Utils::recommended_dimensions();

        ob_start();
        ?>
        <div class="osao" style="--osao-accent:<?php echo esc_attr( $accent ); ?>" data-no-translation="1">
            <?php
            $tab_list = array();
            if ( $with_order )   $tab_list['order']   = $with_sponsor ? __( 'Advertise my business', 'tutor-learning-suite' ) : __( 'Order an ad', 'tutor-learning-suite' );
            if ( $with_sponsor ) $tab_list['sponsor'] = __( 'Promote a course or tutor', 'tutor-learning-suite' );
            if ( $with_track )   $tab_list['track']   = __( 'Track my order', 'tutor-learning-suite' );
            if ( count( $tab_list ) > 1 ) : ?>
            <div class="osao__tabs" role="tablist">
                <?php foreach ( $tab_list as $tk => $tl ) : ?>
                <button type="button" class="osao__tab <?php echo $tk === $active ? 'is-active' : ''; ?>" role="tab" data-tab="<?php echo esc_attr( $tk ); ?>" aria-selected="<?php echo $tk === $active ? 'true' : 'false'; ?>"><?php echo esc_html( $tl ); ?></button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if ( $with_sponsor ) : ?>
            <section class="osao__panel" data-panel="sponsor" <?php echo 'sponsor' === $active ? '' : 'hidden'; ?>>
                <?php echo $this->render_sponsor_panel(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </section>
            <?php endif; ?>

            <?php if ( $with_order ) : ?>
            <section class="osao__panel" data-panel="order" <?php echo 'order' === $active ? '' : 'hidden'; ?>>
                <form class="osao__form" novalidate enctype="multipart/form-data">
                    <input type="hidden" name="action" value="ostudy_ads_order_submit">
                    <input type="hidden" name="nonce" value="">
                    <input type="hidden" name="elapsed" value="0">
                    <div class="osao__hp" aria-hidden="true"><label>Website <input type="text" name="company_website" tabindex="-1" autocomplete="off"></label></div>

                    <ol class="osao__steps" aria-label="<?php esc_attr_e( 'Progress', 'tutor-learning-suite' ); ?>">
                        <li class="is-active" data-dot="1"><span>1</span><em><?php esc_html_e( 'Format', 'tutor-learning-suite' ); ?></em></li>
                        <li data-dot="2"><span>2</span><em><?php esc_html_e( 'Your ad', 'tutor-learning-suite' ); ?></em></li>
                        <li data-dot="3"><span>3</span><em><?php esc_html_e( 'Contact', 'tutor-learning-suite' ); ?></em></li>
                    </ol>

                    <!-- Step 1 -->
                    <div class="osao__step is-active" data-step="1">
                        <h3 class="osao__h"><?php esc_html_e( 'What kind of ad do you want?', 'tutor-learning-suite' ); ?></h3>
                        <div class="osao__formats">
                            <?php
                            $formats = array(
                                'banner'  => array( __( 'Banner', 'tutor-learning-suite' ), __( 'A wide image at the top of a page. Best for reach.', 'tutor-learning-suite' ) ),
                                'sidebar' => array( __( 'Sidebar', 'tutor-learning-suite' ), __( 'A compact card beside the content. Always in view.', 'tutor-learning-suite' ) ),
                                'popup'   => array( __( 'Popup', 'tutor-learning-suite' ), __( 'A focused overlay that appears after a short delay.', 'tutor-learning-suite' ) ),
                            );
                            foreach ( $formats as $key => $f ) :
                                ?>
                                <label class="osao__format">
                                    <input type="radio" name="ad_type" value="<?php echo esc_attr( $key ); ?>">
                                    <span class="osao__format-card">
                                        <span class="osao__format-art osao__format-art--<?php echo esc_attr( $key ); ?>" aria-hidden="true"></span>
                                        <strong><?php echo esc_html( $f[0] ); ?></strong>
                                        <small><?php echo esc_html( $f[1] ); ?></small>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>

                        <h3 class="osao__h osao__h--sub"><?php esc_html_e( 'Where should it appear?', 'tutor-learning-suite' ); ?></h3>
                        <p class="osao__hint osao__placements-empty"><?php esc_html_e( 'Pick a format first.', 'tutor-learning-suite' ); ?></p>
                        <div class="osao__placements" hidden>
                            <?php $zprices = self::zone_prices(); foreach ( self::placement_choices() as $zone => $p ) :
                                $size = isset( $dim[ $zone ]['desktop'] ) ? $dim[ $zone ]['desktop'] : '';
                                ?>
                                <label class="osao__place" data-type="<?php echo esc_attr( $p[0] ); ?>" hidden>
                                    <input type="checkbox" name="placements[]" value="<?php echo esc_attr( $zone ); ?>">
                                    <span><b><?php echo esc_html( $p[1] ); ?></b><?php if ( $size ) : ?><small><?php echo esc_html( $size ); ?></small><?php endif; ?><?php if ( isset( $zprices[ $zone ] ) ) : ?><em class="osao__place-price"><?php echo esc_html( self::format_money( $zprices[ $zone ] ) ); ?> / <?php esc_html_e( 'week', 'tutor-learning-suite' ); ?></em><?php endif; ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Step 2 -->
                    <div class="osao__step" data-step="2" hidden>
                        <h3 class="osao__h"><?php esc_html_e( 'Tell us about your ad', 'tutor-learning-suite' ); ?></h3>
                        <div class="osao__grid">
                            <label class="osao__field"><span><?php esc_html_e( 'Ad title', 'tutor-learning-suite' ); ?> *</span>
                                <input type="text" name="ad_title" maxlength="120" required placeholder="<?php esc_attr_e( 'e.g. Summer maths bootcamp', 'tutor-learning-suite' ); ?>"></label>
                            <label class="osao__field"><span><?php esc_html_e( 'Business / brand name', 'tutor-learning-suite' ); ?></span>
                                <input type="text" name="business_name" maxlength="120"></label>
                            <label class="osao__field osao__field--wide"><span><?php esc_html_e( 'Link when someone clicks the ad', 'tutor-learning-suite' ); ?></span>
                                <input type="url" name="destination_url" inputmode="url" placeholder="https://"></label>
                            <label class="osao__field osao__field--wide"><span><?php esc_html_e( 'Anything we should know?', 'tutor-learning-suite' ); ?></span>
                                <textarea name="description" rows="3" maxlength="1500" placeholder="<?php esc_attr_e( 'Offer, audience, tone, special requests…', 'tutor-learning-suite' ); ?>"></textarea></label>
                        </div>

                        <div class="osao__upload">
                            <label class="osao__drop">
                                <input type="file" name="ad_image" accept="image/jpeg,image/png,image/webp,image/gif">
                                <span class="osao__drop-icon" aria-hidden="true">⬆</span>
                                <strong><?php esc_html_e( 'Upload your ad image', 'tutor-learning-suite' ); ?></strong>
                                <small><?php echo esc_html( sprintf( /* translators: %d: megabytes */ __( 'JPG, PNG, WEBP or GIF · up to %d MB', 'tutor-learning-suite' ), max( 1, absint( $s['order_max_upload_mb'] ) ) ) ); ?></small>
                            </label>
                            <div class="osao__preview" hidden>
                                <div class="osao__preview-frame"><span class="osao__preview-badge"><?php esc_html_e( 'Ad', 'tutor-learning-suite' ); ?></span><img alt=""></div>
                                <button type="button" class="osao__link" data-clear-image><?php esc_html_e( 'Remove image', 'tutor-learning-suite' ); ?></button>
                            </div>
                            <p class="osao__hint osao__size-hint" hidden></p>
                            <label class="osao__check"><input type="checkbox" name="need_design" value="1"> <span><?php esc_html_e( 'I need help designing my ad', 'tutor-learning-suite' ); ?></span></label>
                        </div>

                        <div class="osao__grid">
                            <label class="osao__field"><span><?php esc_html_e( 'Preferred start date', 'tutor-learning-suite' ); ?> *</span>
                                <input type="date" name="start_date" min="<?php echo esc_attr( wp_date( 'Y-m-d', null, wp_timezone() ) ); ?>" required></label>
                            <label class="osao__field"><span><?php esc_html_e( 'Duration', 'tutor-learning-suite' ); ?></span>
                                <select name="duration_days">
                                    <?php foreach ( self::durations() as $days => $label ) : ?>
                                        <option value="<?php echo esc_attr( $days ); ?>"><?php echo esc_html( $label ); ?></option>
                                    <?php endforeach; ?>
                                </select></label>
                            <label class="osao__field osao__field--wide"><span><?php esc_html_e( 'Budget (optional)', 'tutor-learning-suite' ); ?></span>
                                <input type="text" name="budget" maxlength="50" placeholder="<?php esc_attr_e( 'e.g. 5 000 DZD', 'tutor-learning-suite' ); ?>"></label>
                        </div>
                    </div>

                    <!-- Step 3 -->
                    <div class="osao__step" data-step="3" hidden>
                        <h3 class="osao__h"><?php esc_html_e( 'How can we reach you?', 'tutor-learning-suite' ); ?></h3>
                        <div class="osao__grid">
                            <label class="osao__field"><span><?php esc_html_e( 'Full name', 'tutor-learning-suite' ); ?> *</span>
                                <input type="text" name="contact_name" maxlength="120" required autocomplete="name" value="<?php echo esc_attr( $prefill['name'] ); ?>"></label>
                            <label class="osao__field"><span><?php esc_html_e( 'Email', 'tutor-learning-suite' ); ?> *</span>
                                <input type="email" name="email" maxlength="150" required autocomplete="email" value="<?php echo esc_attr( $prefill['email'] ); ?>"></label>
                            <label class="osao__field osao__field--wide"><span><?php esc_html_e( 'Phone / WhatsApp (optional)', 'tutor-learning-suite' ); ?></span>
                                <input type="tel" name="phone" maxlength="40" autocomplete="tel"></label>
                        </div>
                        <?php if ( ! is_user_logged_in() ) : ?>
                            <p class="osao__note"><?php esc_html_e( 'No account needed. After sending, you will get an order number to follow your ad at any time.', 'tutor-learning-suite' ); ?></p>
                        <?php endif; ?>
                        <label class="osao__check"><input type="checkbox" name="terms" value="1" required> <span><?php esc_html_e( 'I confirm my ad is lawful, truthful and suitable for all ages, and I accept that it may be reviewed or refused.', 'tutor-learning-suite' ); ?></span></label>
                    </div>

                    <div class="osao__avail" role="status" hidden></div>
                    <div class="osao__total" hidden><div><span><?php esc_html_e( 'Estimated total', 'tutor-learning-suite' ); ?></span><b data-total></b></div><small data-total-note></small></div>
                    <p class="osao__error" role="alert" hidden></p>
                    <div class="osao__nav">
                        <button type="button" class="osao__btn osao__btn--ghost" data-prev hidden><?php esc_html_e( 'Back', 'tutor-learning-suite' ); ?></button>
                        <button type="button" class="osao__btn" data-next><?php esc_html_e( 'Continue', 'tutor-learning-suite' ); ?></button>
                        <button type="submit" class="osao__btn" data-submit hidden><?php esc_html_e( 'Send my order', 'tutor-learning-suite' ); ?></button>
                    </div>
                </form>

                <div class="osao__success" hidden>
                    <div class="osao__success-icon" aria-hidden="true">✓</div>
                    <h3 class="osao__h"><?php esc_html_e( 'Order received!', 'tutor-learning-suite' ); ?></h3>
                    <p><?php esc_html_e( 'Save your order number. You will need it to follow your ad.', 'tutor-learning-suite' ); ?></p>
                    <div class="osao__code" data-code></div>
                    <div class="osao__success-actions">
                        <button type="button" class="osao__btn osao__btn--ghost" data-copy-code><?php esc_html_e( 'Copy number', 'tutor-learning-suite' ); ?></button>
                        <?php if ( $with_track ) : ?><button type="button" class="osao__btn" data-go-track><?php esc_html_e( 'Track my order', 'tutor-learning-suite' ); ?></button><?php endif; ?>
                    </div>
                    <p class="osao__amount" data-amount hidden></p>
                    <p class="osao__hint" data-mail-note></p>
                </div>
            </section>
            <?php endif; ?>

            <?php if ( $with_track ) : ?>
            <section class="osao__panel" data-panel="track" <?php echo 'track' === $active ? '' : 'hidden'; ?>>
                <h3 class="osao__h"><?php esc_html_e( 'Follow your ad', 'tutor-learning-suite' ); ?></h3>
                <form class="osao__trackform" novalidate>
                    <input type="hidden" name="action" value="ostudy_ads_order_track">
                    <div class="osao__grid">
                        <label class="osao__field"><span><?php esc_html_e( 'Order number', 'tutor-learning-suite' ); ?></span>
                            <input type="text" name="code" placeholder="AD-XXXXXXXX" autocomplete="off" autocapitalize="characters" value="<?php echo isset( $_GET['ad_order'] ) ? esc_attr( strtoupper( sanitize_text_field( wp_unslash( $_GET['ad_order'] ) ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification ?>"></label>
                        <label class="osao__field"><span><?php esc_html_e( 'Email or phone used on the order', 'tutor-learning-suite' ); ?></span>
                            <input type="text" name="contact" autocomplete="off"></label>
                    </div>
                    <p class="osao__error" role="alert" hidden></p>
                    <button type="submit" class="osao__btn"><?php esc_html_e( 'Track order', 'tutor-learning-suite' ); ?></button>
                </form>
                <div class="osao__result" aria-live="polite"></div>
                <?php
                if ( is_user_logged_in() ) {
                    $mine = $this->orders_for_user( get_current_user_id() );
                    if ( $mine ) {
                        echo '<h3 class="osao__h osao__h--sub">' . esc_html__( 'My orders', 'tutor-learning-suite' ) . '</h3><div class="osao__mine">';
                        foreach ( $mine as $o ) {
                            $st = self::statuses();
                            $meta = isset( $st[ $o->status ] ) ? $st[ $o->status ] : $st['pending'];
                            echo '<details class="osao__mine-item"><summary><span class="osao__mine-code">' . esc_html( $o->order_code ) . '</span><span class="osao__mine-title">' . esc_html( $o->ad_title ) . '</span><span class="osao__chip" style="--chip:' . esc_attr( $meta['color'] ) . '">' . esc_html( $meta['label'] ) . '</span></summary>';
                            echo $this->render_order_card( $o ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                            echo '</details>';
                        }
                        echo '</div>';
                    }
                }
                ?>
            </section>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private function orders_for_user( $user_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE user_id = %d ORDER BY id DESC LIMIT 15', absint( $user_id ) ) );
    }

    /** Server-rendered order card (all values escaped). */
    private function render_order_card( $o ) {
        global $wpdb;
        $statuses = self::statuses();
        $status   = isset( $statuses[ $o->status ] ) ? $o->status : 'pending';
        $meta     = $statuses[ $status ];
        $flow     = self::flow();
        $places   = self::placement_choices();
        $labels   = array();
        foreach ( (array) json_decode( (string) $o->placements, true ) as $z ) {
            if ( isset( $places[ $z ] ) ) $labels[] = $places[ $z ][1];
        }
        $types = array( 'banner' => __( 'Banner', 'tutor-learning-suite' ), 'popup' => __( 'Popup', 'tutor-learning-suite' ), 'sidebar' => __( 'Sidebar', 'tutor-learning-suite' ) );

        $pos = array_search( $status, $flow, true );
        $terminal = in_array( $status, array( 'rejected', 'cancelled' ), true );

        $html  = '<article class="osao-card">';
        $html .= '<header class="osao-card__head"><div><span class="osao-card__label">' . esc_html__( 'Order number', 'tutor-learning-suite' ) . '</span><div class="osao-card__code">' . esc_html( $o->order_code ) . '</div></div>';
        $html .= '<span class="osao__chip osao__chip--lg" style="--chip:' . esc_attr( $meta['color'] ) . '">' . esc_html( $meta['label'] ) . '</span></header>';
        $html .= '<p class="osao-card__desc">' . esc_html( $meta['desc'] ) . '</p>';

        if ( $terminal ) {
            $html .= '<div class="osao-alert osao-alert--' . esc_attr( $status ) . '"><strong>' . esc_html( $meta['label'] ) . '</strong></div>';
        } else {
            $html .= '<ol class="osao-timeline">';
            foreach ( $flow as $i => $step ) {
                $cls = ( false !== $pos && $i < $pos ) ? 'is-done' : ( $i === $pos ? 'is-current' : '' );
                $html .= '<li class="' . esc_attr( $cls ) . '"><span class="osao-timeline__dot">' . ( 'is-done' === $cls ? '✓' : (int) ( $i + 1 ) ) . '</span><span class="osao-timeline__label">' . esc_html( $statuses[ $step ]['label'] ) . '</span></li>';
            }
            $html .= '</ol>';
        }

        if ( ! empty( $o->public_note ) ) {
            $html .= '<div class="osao-note"><strong>' . esc_html__( 'Message from our team', 'tutor-learning-suite' ) . '</strong><p>' . nl2br( esc_html( $o->public_note ) ) . '</p></div>';
        }

        if ( in_array( $status, array( 'awaiting_payment', 'payment_review' ), true ) ) {
            $html .= $this->render_payment_box( $o, $status );
        }

        $rows = array(
            __( 'Ad', 'tutor-learning-suite' )       => $o->ad_title,
            __( 'Format', 'tutor-learning-suite' )   => isset( $types[ $o->ad_type ] ) ? $types[ $o->ad_type ] : $o->ad_type,
            __( 'Placements', 'tutor-learning-suite' ) => implode( ' · ', $labels ),
        );
        if ( '' !== trim( (string) $o->amount_due ) && ! in_array( $status, array( 'awaiting_payment', 'payment_review' ), true ) ) {
            $rows[ __( 'Estimated price', 'tutor-learning-suite' ) ] = $o->amount_due;
        }
        if ( $o->start_date ) {
            $end = gmdate( 'Y-m-d', strtotime( $o->start_date . ' +' . max( 1, (int) $o->duration_days - 1 ) . ' days' ) );
            $rows[ __( 'Schedule', 'tutor-learning-suite' ) ] = mysql2date( get_option( 'date_format' ), $o->start_date ) . ' → ' . mysql2date( get_option( 'date_format' ), $end );
        }
        $html .= '<dl class="osao-card__meta">';
        foreach ( $rows as $k => $v ) {
            if ( '' === (string) $v ) continue;
            $html .= '<div><dt>' . esc_html( $k ) . '</dt><dd>' . esc_html( $v ) . '</dd></div>';
        }
        $html .= '</dl>';

        if ( $o->ad_id && in_array( $status, array( 'live', 'completed' ), true ) ) {
            $row = $wpdb->get_row( $wpdb->prepare( 'SELECT SUM(stat_type="impression") i, SUM(stat_type="click") c FROM ' . OStudy_Ads_DB::stats_table() . ' WHERE ad_id = %d', $o->ad_id ) );
            $imp = $row ? (int) $row->i : 0;
            $clk = $row ? (int) $row->c : 0;
            $ctr = $imp ? number_format_i18n( ( $clk / $imp ) * 100, 2 ) . '%' : '0%';
            $html .= '<div class="osao-stats"><div><b>' . esc_html( number_format_i18n( $imp ) ) . '</b><span>' . esc_html__( 'Views', 'tutor-learning-suite' ) . '</span></div><div><b>' . esc_html( number_format_i18n( $clk ) ) . '</b><span>' . esc_html__( 'Clicks', 'tutor-learning-suite' ) . '</span></div><div><b>' . esc_html( $ctr ) . '</b><span>' . esc_html__( 'Click rate', 'tutor-learning-suite' ) . '</span></div></div>';
        }

        if ( in_array( $status, array( 'live', 'completed' ), true ) ) {
            $end_d = self::order_end_date( $o );
            $left  = $end_d ? (int) floor( ( strtotime( $end_d ) - strtotime( wp_date( 'Y-m-d', null, wp_timezone() ) ) ) / DAY_IN_SECONDS ) : -1;
            if ( 'completed' === $status || $left <= 14 ) {
                $html .= '<div class="osao-card__actions"><button type="button" class="osao__btn osao-renew" data-code="' . esc_attr( $o->order_code ) . '">' . esc_html__( 'Renew this ad', 'tutor-learning-suite' ) . '</button><p class="osao__hint">' . esc_html__( 'Starts right after the current run and reuses your image and details.', 'tutor-learning-suite' ) . '</p><p class="osao__error" role="alert" hidden></p></div>';
            }
        }

        $hist = json_decode( (string) $o->history, true );
        if ( is_array( $hist ) && $hist ) {
            $html .= '<details class="osao-history"><summary>' . esc_html__( 'Order history', 'tutor-learning-suite' ) . '</summary><ul>';
            foreach ( array_reverse( $hist ) as $h ) {
                $label = isset( $statuses[ $h['s'] ] ) ? $statuses[ $h['s'] ]['label'] : $h['s'];
                $html .= '<li><b>' . esc_html( $label ) . '</b><time>' . esc_html( self::local_date( $h['t'] ) ) . '</time></li>';
            }
            $html .= '</ul></details>';
        }
        return $html . '</article>';
    }

    /** Payment instructions + proof upload form (all values escaped). */
    private function render_payment_box( $o, $status ) {
        $s     = OStudy_Ads_DB::get_settings();
        $instr = trim( (string) $s['payment_instructions'] );
        $html  = '<section class="osao-pay"><h4 class="osao-pay__title">' . esc_html__( 'Payment', 'tutor-learning-suite' ) . '</h4>';
        if ( '' !== trim( (string) $o->amount_due ) ) {
            $html .= '<div class="osao-pay__amount"><span>' . esc_html__( 'Amount due', 'tutor-learning-suite' ) . '</span><b>' . esc_html( $o->amount_due ) . '</b></div>';
        }
        if ( '' !== $instr ) {
            $html .= '<div class="osao-pay__instr">' . nl2br( esc_html( $instr ) ) . '</div>';
        }
        $html .= '<p class="osao-pay__ref">' . sprintf( /* translators: %s: order number */ esc_html__( 'Please write your order number %s in the payment reference.', 'tutor-learning-suite' ), '<strong>' . esc_html( $o->order_code ) . '</strong>' ) . '</p>';

        if ( 'payment_review' === $status ) {
            $when = $o->payment_submitted_at ? self::local_date( $o->payment_submitted_at ) : '';
            $html .= '<div class="osao-pay__done"><strong>✓ ' . esc_html__( 'Payment proof received', 'tutor-learning-suite' ) . '</strong>' . ( $when ? '<span>' . esc_html( $when ) . '</span>' : '' ) . ( $o->payment_reference ? '<span>' . esc_html__( 'Reference', 'tutor-learning-suite' ) . ': ' . esc_html( $o->payment_reference ) . '</span>' : '' ) . '</div>';
        } else {
            $html .= '<form class="osao-proof" enctype="multipart/form-data" novalidate>';
            $html .= '<input type="hidden" name="action" value="ostudy_ads_order_proof"><input type="hidden" name="code" value="' . esc_attr( $o->order_code ) . '">';
            $html .= '<h5>' . esc_html__( 'Already paid? Send your proof', 'tutor-learning-suite' ) . '</h5>';
            $html .= '<label class="osao-proof__file"><input type="file" name="proof" accept="image/jpeg,image/png,image/webp,image/gif,application/pdf"><span class="osao-proof__pick"><b>' . esc_html__( 'Choose receipt', 'tutor-learning-suite' ) . '</b><small>' . esc_html__( 'Screenshot, photo or PDF', 'tutor-learning-suite' ) . '</small></span><span class="osao-proof__name"></span></label>';
            $html .= '<div class="osao__grid"><label class="osao__field"><span>' . esc_html__( 'Transaction / receipt number (optional)', 'tutor-learning-suite' ) . '</span><input type="text" name="reference" maxlength="100"></label>';
            $html .= '<label class="osao__field"><span>' . esc_html__( 'Note (optional)', 'tutor-learning-suite' ) . '</span><input type="text" name="note" maxlength="300"></label></div>';
            $html .= '<p class="osao__error" role="alert" hidden></p><button type="submit" class="osao__btn">' . esc_html__( 'Send payment proof', 'tutor-learning-suite' ) . '</button></form>';
        }
        return $html . '</section>';
    }

    /* ------------------------------------------------------------------
     * AJAX: submit + track
     * ---------------------------------------------------------------- */

    private function fail( $message, $field = '', $status = 400 ) {
        wp_send_json_error( array( 'message' => $message, 'field' => $field ), $status );
    }

    /**
     * Return the protected directory used for advertising payment proofs.
     * The directory must be outside both the WordPress install and the web root.
     *
     * @return string|false Absolute directory with trailing slash.
     */
    private static function private_proof_dir() {
        $root = defined( 'TLS_PRIVATE_STORAGE_DIR' ) && is_string( TLS_PRIVATE_STORAGE_DIR )
            ? rtrim( TLS_PRIVATE_STORAGE_DIR, '/\\' )
            : rtrim( dirname( rtrim( ABSPATH, '/\\' ) ), '/\\' ) . '/tls-private-data';
        if ( '' === $root ) {
            return false;
        }
        // Validate the nearest existing ancestor before creating anything. This
        // prevents a bad TLS_PRIVATE_STORAGE_DIR value from creating a private
        // receipt folder inside public uploads or the WordPress document root.
        $root_real = realpath( $root );
        $ancestor  = $root_real ? $root_real : realpath( dirname( $root ) );
        if ( ! $ancestor ) {
            return false;
        }
        $blocked_roots = array_filter(
            array(
                realpath( ABSPATH ),
                ! empty( $_SERVER['DOCUMENT_ROOT'] ) ? realpath( (string) $_SERVER['DOCUMENT_ROOT'] ) : false,
            )
        );
        $root_candidate = $root_real ? $root_real : trailingslashit( wp_normalize_path( $ancestor ) ) . basename( $root );
        $root_candidate = trailingslashit( wp_normalize_path( $root_candidate ) );
        foreach ( $blocked_roots as $blocked_root ) {
            $blocked_root = trailingslashit( wp_normalize_path( $blocked_root ) );
            if ( 0 === stripos( $root_candidate, $blocked_root ) ) {
                return false;
            }
        }

        $dir = trailingslashit( $root ) . self::PRIVATE_PROOF_DIR . '/';
        if ( ! wp_mkdir_p( $dir ) ) {
            return false;
        }
        $real_dir = realpath( $dir );
        if ( ! $real_dir ) {
            return false;
        }
        $real_dir = trailingslashit( wp_normalize_path( $real_dir ) );
        foreach ( $blocked_roots as $blocked_root ) {
            $blocked_root = trailingslashit( wp_normalize_path( $blocked_root ) );
            if ( 0 === stripos( $real_dir, $blocked_root ) ) {
                return false;
            }
        }

        $htaccess = $real_dir . '.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            $rules = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n";
            file_put_contents( $htaccess, $rules ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        }
        $index = $real_dir . 'index.php';
        if ( ! file_exists( $index ) ) {
            file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        }
        return $real_dir;
    }

    /** Store a verified file in private storage and return its relative path. */
    private function copy_proof_to_private_storage( $source, $extension ) {
        $base = self::private_proof_dir();
        if ( ! $base || ! is_file( $source ) || ! is_readable( $source ) ) {
            return new WP_Error( 'private_storage', __( 'Private receipt storage is unavailable. Please contact the site administrator.', 'tutor-learning-suite' ) );
        }
        $extension = strtolower( preg_replace( '/[^a-z0-9]/', '', (string) $extension ) );
        if ( ! in_array( $extension, array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf' ), true ) ) {
            return new WP_Error( 'proof_extension', __( 'This receipt file type is not supported.', 'tutor-learning-suite' ) );
        }
        $subdir = gmdate( 'Y/m' );
        $target_dir = $base . $subdir . '/';
        if ( ! wp_mkdir_p( $target_dir ) ) {
            return new WP_Error( 'private_storage', __( 'The private receipt folder could not be created.', 'tutor-learning-suite' ) );
        }
        $filename = 'receipt-' . gmdate( 'Ymd' ) . '-' . wp_generate_password( 24, false, false ) . '.' . $extension;
        $target = $target_dir . $filename;
        if ( ! @copy( $source, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            return new WP_Error( 'proof_copy', __( 'The receipt could not be saved. Please try again.', 'tutor-learning-suite' ) );
        }
        @chmod( $target, 0640 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.chmod_chmod
        if ( ! is_file( $target ) || filesize( $target ) !== filesize( $source ) ) {
            wp_delete_file( $target );
            return new WP_Error( 'proof_verify', __( 'The saved receipt could not be verified. Please try again.', 'tutor-learning-suite' ) );
        }
        return $subdir . '/' . $filename;
    }

    /** Validate and store a newly uploaded payment proof outside public uploads. */
    private function save_private_proof( $file, $max_bytes ) {
        if ( ! is_array( $file ) || empty( $file['tmp_name'] ) || empty( $file['name'] ) || ! isset( $file['size'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
            return new WP_Error( 'proof_upload', __( 'The uploaded receipt could not be verified.', 'tutor-learning-suite' ) );
        }
        if ( (int) $file['size'] < 1 || (int) $file['size'] > $max_bytes ) {
            return new WP_Error( 'proof_size', sprintf( __( 'The receipt is larger than %s.', 'tutor-learning-suite' ), size_format( $max_bytes ) ) );
        }
        $mimes = array(
            'jpg|jpeg|jpe' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
        );
        $check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], $mimes );
        $is_pdf = isset( $check['type'] ) && 'application/pdf' === $check['type'];
        if ( empty( $check['type'] ) || ( $is_pdf && '%PDF-' !== (string) file_get_contents( $file['tmp_name'], false, null, 0, 5 ) ) || ( ! $is_pdf && ! @getimagesize( $file['tmp_name'] ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            return new WP_Error( 'proof_type', __( 'Please upload a valid JPG, PNG, WEBP image or PDF receipt.', 'tutor-learning-suite' ) );
        }
        $extension = isset( $check['ext'] ) ? $check['ext'] : pathinfo( $file['name'], PATHINFO_EXTENSION );
        $base = self::private_proof_dir();
        if ( ! $base ) {
            return new WP_Error( 'private_storage', __( 'Private receipt storage is unavailable. Please contact the site administrator.', 'tutor-learning-suite' ) );
        }
        $subdir = gmdate( 'Y/m' );
        $target_dir = $base . $subdir . '/';
        if ( ! wp_mkdir_p( $target_dir ) ) {
            return new WP_Error( 'private_storage', __( 'The private receipt folder could not be created.', 'tutor-learning-suite' ) );
        }
        $filename = 'receipt-' . gmdate( 'Ymd' ) . '-' . wp_generate_password( 24, false, false ) . '.' . strtolower( $extension );
        $target = $target_dir . $filename;
        if ( ! @move_uploaded_file( $file['tmp_name'], $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            return new WP_Error( 'proof_move', __( 'The receipt could not be saved. Please try again.', 'tutor-learning-suite' ) );
        }
        @chmod( $target, 0640 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.chmod_chmod
        if ( ! is_file( $target ) || filesize( $target ) !== (int) $file['size'] ) {
            wp_delete_file( $target );
            return new WP_Error( 'proof_verify', __( 'The saved receipt could not be verified. Please try again.', 'tutor-learning-suite' ) );
        }
        return array( 'path' => $subdir . '/' . $filename, 'mime' => $check['type'] );
    }

    /** Resolve a receipt path while preventing traversal and symlink escapes. */
    private static function resolve_private_proof( $relative_path ) {
        $relative_path = (string) $relative_path;
        if ( '' === $relative_path || false !== strpos( $relative_path, '..' ) || false !== strpos( $relative_path, "\0" ) ) {
            return false;
        }
        $base = self::private_proof_dir();
        if ( ! $base ) {
            return false;
        }
        $candidate = realpath( $base . ltrim( str_replace( '\\', '/', $relative_path ), '/' ) );
        if ( ! $candidate || ! is_file( $candidate ) ) {
            return false;
        }
        $base_real = trailingslashit( wp_normalize_path( realpath( $base ) ) );
        $candidate = wp_normalize_path( $candidate );
        return 0 === strpos( $candidate, $base_real ) ? $candidate : false;
    }

    private function delete_private_proof( $relative_path ) {
        $path = self::resolve_private_proof( $relative_path );
        if ( $path && file_exists( $path ) ) {
            wp_delete_file( $path );
        }
    }

    /** Migrate a legacy media-library receipt and remove its public attachment. */
    private function migrate_order_proof( $order ) {
        if ( ! $order || ! empty( $order->payment_proof_path ) || empty( $order->payment_proof_id ) ) {
            return false;
        }
        $attachment_id = absint( $order->payment_proof_id );
        $source = get_attached_file( $attachment_id );
        if ( ! $source || ! is_file( $source ) ) {
            return false;
        }
        $type = wp_check_filetype( $source );
        $mime = isset( $type['type'] ) ? $type['type'] : '';
        if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf' ), true ) ) {
            return false;
        }
        $stored = $this->copy_proof_to_private_storage( $source, pathinfo( $source, PATHINFO_EXTENSION ) );
        if ( is_wp_error( $stored ) ) {
            return false;
        }
        global $wpdb;
        $updated = $wpdb->update(
            self::table(),
            array( 'payment_proof_path' => $stored, 'payment_proof_url' => '', 'payment_proof_id' => 0 ),
            array( 'id' => absint( $order->id ) ),
            array( '%s', '%s', '%d' ),
            array( '%d' )
        );
        if ( false === $updated ) {
            $this->delete_private_proof( $stored );
            return false;
        }
        wp_delete_attachment( $attachment_id, true );
        return true;
    }

    /** Migrate old public receipt attachments in small batches during admin requests. */
    public function migrate_legacy_proofs() {
        // admin_init also fires during admin-ajax.php requests. Do not run file
        // migrations inside Tutor dashboard analytics/profile-switch requests.
        if ( ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) ||
            ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) ||
            ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        global $wpdb;
        $table = self::table();
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists !== $table ) {
            return;
        }
        $columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
        if ( ! $columns || ! in_array( 'payment_proof_path', $columns, true ) ) {
            return;
        }
        $cursor = absint( get_option( 'ostudy_ads_receipt_migration_cursor', 0 ) );
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id > %d AND payment_proof_id > 0 AND (payment_proof_path IS NULL OR payment_proof_path = '') ORDER BY id ASC LIMIT 10",
                $cursor
            )
        );
        if ( ! $rows ) {
            // Start a new pass on the next admin request. Unmigrated failures
            // are revisited, while a single inaccessible file cannot starve
            // later receipts in the same batch.
            delete_option( 'ostudy_ads_receipt_migration_cursor' );
            return;
        }
        $last_id = $cursor;
        foreach ( (array) $rows as $row ) {
            $this->migrate_order_proof( $row );
            $last_id = max( $last_id, absint( $row->id ) );
        }
        update_option( 'ostudy_ads_receipt_migration_cursor', $last_id, false );
    }

    /** Build an administrator-only, nonce-protected receipt URL. */
    private function proof_view_url( $order_id ) {
        $url = add_query_arg(
            array( 'action' => 'ostudy_ads_view_proof', 'order_id' => absint( $order_id ) ),
            admin_url( 'admin-post.php' )
        );
        return wp_nonce_url( $url, 'ostudy_ads_view_proof_' . absint( $order_id ) );
    }

    /** Stream a receipt only to an administrator. */
    public function serve_payment_proof() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to view this payment receipt.', 'tutor-learning-suite' ), '', array( 'response' => 403 ) );
        }
        $order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
        if ( ! $order_id ) {
            wp_die( esc_html__( 'Missing order.', 'tutor-learning-suite' ), '', array( 'response' => 400 ) );
        }
        check_admin_referer( 'ostudy_ads_view_proof_' . $order_id );
        $order = self::get_order( $order_id );
        if ( $order && empty( $order->payment_proof_path ) && ! empty( $order->payment_proof_id ) ) {
            $this->migrate_order_proof( $order );
            $order = self::get_order( $order_id );
        }
        $path = $order ? self::resolve_private_proof( $order->payment_proof_path ) : false;
        if ( ! $path ) {
            wp_die( esc_html__( 'This receipt is not available in private storage.', 'tutor-learning-suite' ), '', array( 'response' => 404 ) );
        }
        $extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
        $mime_types = array( 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'pdf' => 'application/pdf' );
        if ( ! isset( $mime_types[ $extension ] ) ) {
            wp_die( esc_html__( 'Unsupported receipt type.', 'tutor-learning-suite' ), '', array( 'response' => 415 ) );
        }
        nocache_headers();
        header( 'Content-Type: ' . $mime_types[ $extension ] );
        $disposition = 'pdf' === $extension ? 'attachment' : 'inline';
        header( 'Content-Disposition: ' . $disposition . '; filename="ad-payment-proof-' . $order_id . '.' . $extension . '"' );
        header( 'Content-Length: ' . (string) filesize( $path ) );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Cache-Control: private, no-store, no-cache, must-revalidate' );
        readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
        exit;
    }

    private function save_upload( $field, $max_bytes, $allow_pdf = false ) {
        if ( empty( $_FILES[ $field ] ) || empty( $_FILES[ $field ]['name'] ) ) return null;
        $file = $_FILES[ $field ]; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        if ( UPLOAD_ERR_NO_FILE === (int) $file['error'] ) return null;
        if ( UPLOAD_ERR_OK !== (int) $file['error'] ) return new WP_Error( 'upload', __( 'The file could not be uploaded. Please try a smaller file.', 'tutor-learning-suite' ) );
        if ( (int) $file['size'] > $max_bytes ) return new WP_Error( 'size', sprintf( __( 'The file is larger than %s.', 'tutor-learning-suite' ), size_format( $max_bytes ) ) );

        // Payment proofs contain financial information; unlike ad creative images,
        // they must never be placed in the public Media Library/uploads directory.
        if ( $allow_pdf ) {
            return $this->save_private_proof( $file, $max_bytes );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        $mimes = array( 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp' );
        $check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], $mimes );
        if ( empty( $check['type'] ) || ! @getimagesize( $file['tmp_name'] ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            return new WP_Error( 'type', __( 'Please upload a JPG, PNG, WEBP or GIF image.', 'tutor-learning-suite' ) );
        }
        $moved = wp_handle_upload( $file, array( 'test_form' => false, 'mimes' => $mimes ) );
        if ( ! empty( $moved['error'] ) ) return new WP_Error( 'move', __( 'The image could not be saved. Please try again.', 'tutor-learning-suite' ) );
        $att = wp_insert_attachment( array(
            'post_mime_type' => $moved['type'],
            'post_title'     => sanitize_text_field( pathinfo( $file['name'], PATHINFO_FILENAME ) ),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ), $moved['file'] );
        if ( is_wp_error( $att ) || ! $att ) {
            wp_delete_file( $moved['file'] );
            return new WP_Error( 'att', __( 'The image could not be saved. Please try again.', 'tutor-learning-suite' ) );
        }
        wp_update_attachment_metadata( $att, wp_generate_attachment_metadata( $att, $moved['file'] ) );
        update_post_meta( $att, '_ostudy_ad_order_creative', 1 );
        return array( 'id' => (int) $att, 'url' => esc_url_raw( $moved['url'] ) );
    }

    public function ajax_submit() {
        if ( function_exists( 'tls_rate_limited' ) && tls_rate_limited( 'ad_order_submit', 10, 600 ) ) { wp_send_json_error( array( 'message' => __( 'Too many requests. Please wait a moment.', 'tutor-learning-suite' ) ), 429 ); }
        $s = OStudy_Ads_DB::get_settings();
        if ( empty( $s['orders_enabled'] ) ) $this->fail( __( 'Ad orders are currently closed.', 'tutor-learning-suite' ), '', 403 );
        if ( is_user_logged_in() && ! check_ajax_referer( 'ostudy_ads_order', 'nonce', false ) ) {
            $this->fail( __( 'Your session expired. Please refresh the page and try again.', 'tutor-learning-suite' ), '', 403 );
        }
        // Spam traps: honeypot + minimum fill time (set by the page's JavaScript).
        if ( ! empty( $_POST['company_website'] ) ) $this->fail( __( 'Your order could not be sent.', 'tutor-learning-suite' ) );
        if ( absint( $_POST['elapsed'] ?? 0 ) < 2500 ) $this->fail( __( 'Please review your order for a moment, then send it again.', 'tutor-learning-suite' ), '', 429 );
        if ( self::rate_limited( 'submit', 5, HOUR_IN_SECONDS ) ) $this->fail( __( 'Too many orders from your connection. Please try again later.', 'tutor-learning-suite' ), '', 429 );

        $d = 'tutor-learning-suite';
        $post = wp_unslash( $_POST );
        $choices = self::placement_choices();

        $ad_type = sanitize_key( $post['ad_type'] ?? '' );
        if ( ! in_array( $ad_type, array( 'banner', 'sidebar', 'popup' ), true ) ) $this->fail( __( 'Choose an ad format.', $d ), 'ad_type' );

        $placements = array();
        foreach ( (array) ( $post['placements'] ?? array() ) as $zone ) {
            $zone = sanitize_key( $zone );
            if ( isset( $choices[ $zone ] ) && $choices[ $zone ][0] === $ad_type ) $placements[] = $zone;
        }
        $placements = array_values( array_unique( $placements ) );
        if ( ! $placements ) $this->fail( __( 'Choose at least one placement.', $d ), 'placements' );

        $title = sanitize_text_field( $post['ad_title'] ?? '' );
        if ( '' === $title ) $this->fail( __( 'Please enter a title for your ad.', $d ), 'ad_title' );

        $url = trim( (string) ( $post['destination_url'] ?? '' ) );
        if ( '' !== $url ) {
            $url = esc_url_raw( $url, array( 'http', 'https' ) );
            if ( '' === $url ) $this->fail( __( 'Enter a full link starting with http:// or https://', $d ), 'destination_url' );
        }

        $name  = sanitize_text_field( $post['contact_name'] ?? '' );
        $email = sanitize_email( $post['email'] ?? '' );
        if ( '' === $name ) $this->fail( __( 'Please enter your name.', $d ), 'contact_name' );
        if ( ! is_email( $email ) ) $this->fail( __( 'Enter a valid email address.', $d ), 'email' );
        if ( empty( $post['terms'] ) ) $this->fail( __( 'Please accept the advertising terms.', $d ), 'terms' );

        $start = sanitize_text_field( $post['start_date'] ?? '' );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start ) || strtotime( $start ) < strtotime( wp_date( 'Y-m-d', null, wp_timezone() ) ) ) {
            $this->fail( __( 'Choose a start date from today onwards.', $d ), 'start_date' );
        }
        $days = absint( $post['duration_days'] ?? 7 );
        if ( ! isset( self::durations()[ $days ] ) ) $days = 7;
        $need_design = empty( $post['need_design'] ) ? 0 : 1;

        // Slot limits: refuse placements that are already fully booked for these dates.
        $full = array();
        foreach ( self::availability( $placements, $start, $days ) as $a ) if ( $a['full'] ) $full[] = $a['label'];
        if ( $full ) $this->fail( sprintf( /* translators: %s: placement names */ __( 'Fully booked for these dates: %s. Please choose other dates or another placement.', $d ), implode( ', ', $full ) ), 'placements', 409 );

        // Image shape/size must suit the chosen placements.
        if ( ! empty( $s['order_enforce_sizes'] ) && ! empty( $_FILES['ad_image']['tmp_name'] ) && is_uploaded_file( $_FILES['ad_image']['tmp_name'] ) ) {
            $info = @getimagesize( $_FILES['ad_image']['tmp_name'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            if ( $info ) {
                $problem = self::size_problem( (int) $info[0], (int) $info[1], $placements );
                if ( '' !== $problem ) $this->fail( $problem, 'ad_image' );
            }
        }
        $amount_due = '';
        if ( self::pricing_enabled() ) {
            $q = self::quote( $placements, $days );
            if ( $q['total'] > 0 ) $amount_due = self::format_money( $q['total'] );
        }

        $max_bytes = max( 1, absint( $s['order_max_upload_mb'] ) ) * MB_IN_BYTES;
        $image = $this->save_upload( 'ad_image', $max_bytes );
        if ( is_wp_error( $image ) ) $this->fail( $image->get_error_message(), 'ad_image' );
        if ( ! $image && ! $need_design ) $this->fail( __( 'Upload your ad image, or tick “I need help designing my ad”.', $d ), 'ad_image' );

        global $wpdb;
        $now  = current_time( 'mysql', true );
        $code = self::generate_code();
        $ok = $wpdb->insert( self::table(), array(
            'order_code'      => $code,
            'user_id'         => get_current_user_id(),
            'contact_name'    => $name,
            'email'           => $email,
            'phone'           => sanitize_text_field( $post['phone'] ?? '' ),
            'business_name'   => sanitize_text_field( $post['business_name'] ?? '' ),
            'ad_title'        => $title,
            'ad_type'         => $ad_type,
            'placements'      => wp_json_encode( $placements ),
            'description'     => mb_substr( sanitize_textarea_field( $post['description'] ?? '' ), 0, 1500 ),
            'destination_url' => $url,
            'image_id'        => $image ? $image['id'] : 0,
            'image_url'       => $image ? $image['url'] : '',
            'need_design'     => $need_design,
            'start_date'      => $start,
            'duration_days'   => $days,
            'budget'          => sanitize_text_field( $post['budget'] ?? '' ),
            'amount_due'      => $amount_due,
            'status'          => 'pending',
            'history'         => self::history_add( '', 'pending' ),
            'created_at'      => $now,
            'updated_at'      => $now,
        ) );
        if ( ! $ok ) {
            if ( $image ) wp_delete_attachment( $image['id'], true );
            $this->fail( __( 'We could not save your order. Please try again.', $d ), '', 500 );
        }

        $order = self::get_order( $wpdb->insert_id );
        $sent  = $this->email_customer_received( $order );
        $this->email_admin_new( $order );

        wp_send_json_success( array(
            'code'      => $code,
            'amount'    => $amount_due,
            'email_sent' => (bool) $sent,
            'track_url' => self::track_url( $code ),
        ) );
    }

    public function ajax_track() {
        if ( function_exists( 'tls_rate_limited' ) && tls_rate_limited( 'ad_order_track', 30, 60 ) ) { wp_send_json_error( array( 'message' => __( 'Too many requests. Please wait a moment.', 'tutor-learning-suite' ) ), 429 ); }
        if ( self::rate_limited( 'track', 15, 10 * MINUTE_IN_SECONDS ) ) {
            $this->fail( __( 'Too many attempts. Please wait a few minutes and try again.', 'tutor-learning-suite' ), '', 429 );
        }
        $code = self::normalize_code( sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) ) );
        $contact = sanitize_text_field( wp_unslash( $_POST['contact'] ?? '' ) );
        $order = $code ? self::get_order_by_code( $code ) : null;

        $allowed = self::can_access( $order, $contact );
        // One neutral message for both "not found" and "wrong contact" so codes cannot be probed.
        if ( ! $allowed ) {
            $this->fail( __( 'We could not find an order with those details. Check the number and the email or phone you used.', 'tutor-learning-suite' ), '', 404 );
        }
        wp_send_json_success( array( 'html' => $this->render_order_card( $order ) ) );
    }

    public function ajax_proof() {
        if ( function_exists( 'tls_rate_limited' ) && tls_rate_limited( 'ad_order_proof', 10, 600 ) ) { wp_send_json_error( array( 'message' => __( 'Too many requests. Please wait a moment.', 'tutor-learning-suite' ) ), 429 ); }
        $d = 'tutor-learning-suite';
        if ( is_user_logged_in() && ! check_ajax_referer( 'ostudy_ads_order', 'nonce', false ) ) {
            $this->fail( __( 'Your session expired. Please refresh the page and try again.', $d ), '', 403 );
        }
        if ( self::rate_limited( 'proof', 8, HOUR_IN_SECONDS ) ) $this->fail( __( 'Too many uploads. Please try again later.', $d ), '', 429 );

        $code    = self::normalize_code( sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) ) );
        $contact = sanitize_text_field( wp_unslash( $_POST['contact'] ?? '' ) );
        $order   = $code ? self::get_order_by_code( $code ) : null;
        if ( ! self::can_access( $order, $contact ) ) {
            $this->fail( __( 'We could not verify this order. Look it up again with your order number and email or phone.', $d ), '', 403 );
        }
        if ( 'awaiting_payment' !== $order->status ) {
            $this->fail( __( 'This order is not waiting for a payment right now.', $d ), '', 409 );
        }

        $s = OStudy_Ads_DB::get_settings();
        $proof = $this->save_upload( 'proof', max( 1, absint( $s['order_max_upload_mb'] ) ) * MB_IN_BYTES, true );
        if ( is_wp_error( $proof ) ) $this->fail( $proof->get_error_message() );
        if ( ! $proof ) $this->fail( __( 'Please choose your payment receipt (image or PDF).', $d ), 'proof' );

        global $wpdb;
        $now = current_time( 'mysql', true );
        $updated = $wpdb->update( self::table(), array(
            'payment_proof_id'     => 0,
            'payment_proof_path'   => $proof['path'],
            'payment_proof_url'    => '',
            'payment_reference'    => sanitize_text_field( wp_unslash( $_POST['reference'] ?? '' ) ),
            'payment_note'         => mb_substr( sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) ), 0, 300 ),
            'payment_submitted_at' => $now,
            'status'               => 'payment_review',
            'history'              => self::history_add( $order->history, 'payment_review' ),
            'updated_at'           => $now,
        ), array( 'id' => (int) $order->id ) );
        if ( false === $updated ) {
            $this->delete_private_proof( $proof['path'] );
            $this->fail( __( 'We could not save your payment proof. Please try again.', $d ), '', 500 );
        }
        if ( ! empty( $order->payment_proof_id ) ) {
            wp_delete_attachment( (int) $order->payment_proof_id, true );
        }
        if ( ! empty( $order->payment_proof_path ) ) {
            $this->delete_private_proof( $order->payment_proof_path );
        }
        $fresh = self::get_order( $order->id );
        $to = is_email( $s['order_notify_email'] ) ? $s['order_notify_email'] : get_option( 'admin_email' );
        wp_mail( $to, sprintf( '[%s] %s %s', self::site_name(), __( 'Payment proof received for', $d ), $fresh->order_code ), implode( "\n", array(
            __( 'A customer sent a payment proof.', $d ), '',
            __( 'Order', $d ) . ': ' . $fresh->order_code,
            __( 'Reference', $d ) . ': ' . $fresh->payment_reference, '',
            __( 'Review it:', $d ) . ' ' . admin_url( 'admin.php?page=ostudy-ads-orders&order=' . (int) $fresh->id ),
        ) ) );
        wp_send_json_success( array( 'html' => $this->render_order_card( $fresh ) ) );
    }

    public function ajax_availability() {
        if ( self::rate_limited( 'avail', 80, 10 * MINUTE_IN_SECONDS ) ) $this->fail( __( 'Too many requests. Please wait a moment.', 'tutor-learning-suite' ), '', 429 );
        $choices = self::placement_choices();
        $zones = array();
        foreach ( (array) ( $_POST['zones'] ?? array() ) as $z ) { // phpcs:ignore WordPress.Security.NonceVerification
            $z = sanitize_key( wp_unslash( $z ) );
            if ( isset( $choices[ $z ] ) ) $zones[] = $z;
        }
        $start = sanitize_text_field( wp_unslash( $_POST['start_date'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
        $days  = absint( $_POST['duration_days'] ?? 7 ); // phpcs:ignore WordPress.Security.NonceVerification
        if ( ! $zones || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start ) || ! isset( self::durations()[ $days ] ) ) $this->fail( __( 'Invalid request.', 'tutor-learning-suite' ) );
        $out = array();
        foreach ( self::availability( $zones, $start, $days ) as $z => $a ) $out[] = array( 'zone' => $z, 'label' => $a['label'], 'full' => $a['full'] );
        wp_send_json_success( array( 'zones' => $out ) );
    }

    public function ajax_renew() {
        $d = 'tutor-learning-suite';
        if ( is_user_logged_in() && ! check_ajax_referer( 'ostudy_ads_order', 'nonce', false ) ) {
            $this->fail( __( 'Your session expired. Please refresh the page and try again.', $d ), '', 403 );
        }
        if ( self::rate_limited( 'renew', 6, HOUR_IN_SECONDS ) ) $this->fail( __( 'Too many requests. Please try again later.', $d ), '', 429 );
        $code    = self::normalize_code( sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) ) );
        $contact = sanitize_text_field( wp_unslash( $_POST['contact'] ?? '' ) );
        $o = $code ? self::get_order_by_code( $code ) : null;
        if ( ! self::can_access( $o, $contact ) ) $this->fail( __( 'We could not verify this order. Look it up again with your order number and email or phone.', $d ), '', 403 );
        if ( ! in_array( $o->status, array( 'live', 'completed' ), true ) ) $this->fail( __( 'Only live or completed orders can be renewed.', $d ), '', 409 );

        $s = OStudy_Ads_DB::get_settings();
        if ( empty( $s['orders_enabled'] ) ) $this->fail( __( 'Ad orders are currently closed.', $d ), '', 403 );
        $today = wp_date( 'Y-m-d', null, wp_timezone() );
        $end   = self::order_end_date( $o );
        $start = $end ? gmdate( 'Y-m-d', strtotime( $end . ' +1 day' ) ) : '';
        $tomorrow = gmdate( 'Y-m-d', strtotime( $today . ' +1 day' ) );
        if ( '' === $start || $start < $tomorrow ) $start = $tomorrow;
        $days = max( 1, (int) $o->duration_days );
        if ( ! isset( self::durations()[ $days ] ) ) $days = 7;
        $zones = array_values( array_filter( (array) json_decode( (string) $o->placements, true ) ) );

        $full = array();
        foreach ( self::availability( $zones, $start, $days ) as $a ) if ( $a['full'] ) $full[] = $a['label'];
        if ( $full ) $this->fail( sprintf( __( 'Fully booked right after your current run: %s. Please place a new order with different dates.', $d ), implode( ', ', $full ) ), '', 409 );

        $amount_due = '';
        if ( self::pricing_enabled() ) {
            $q = self::quote( $zones, $days );
            if ( $q['total'] > 0 ) $amount_due = self::format_money( $q['total'] );
        }
        global $wpdb;
        $now = current_time( 'mysql', true );
        $new_code = self::generate_code();
        $ok = $wpdb->insert( self::table(), array(
            'order_code' => $new_code, 'user_id' => (int) $o->user_id ?: get_current_user_id(),
            'contact_name' => $o->contact_name, 'email' => $o->email, 'phone' => $o->phone, 'business_name' => $o->business_name,
            'ad_title' => $o->ad_title, 'ad_type' => $o->ad_type, 'placements' => $o->placements, 'description' => $o->description,
            'destination_url' => $o->destination_url, 'image_id' => (int) $o->image_id, 'image_url' => $o->image_url,
            'mobile_image_id' => (int) $o->mobile_image_id, 'mobile_image_url' => $o->mobile_image_url, 'need_design' => (int) $o->need_design,
            'start_date' => $start, 'duration_days' => $days, 'budget' => $o->budget, 'amount_due' => $amount_due,
            'status' => 'pending', 'history' => self::history_add( '', 'pending', sprintf( __( 'Renewal of %s', $d ), $o->order_code ) ),
            'created_at' => $now, 'updated_at' => $now,
        ) );
        if ( ! $ok ) $this->fail( __( 'We could not create the renewal. Please try again.', $d ), '', 500 );
        $fresh = self::get_order( $wpdb->insert_id );
        $this->email_customer_received( $fresh );
        $this->email_admin_new( $fresh );
        wp_send_json_success( array(
            'code'    => $new_code,
            'message' => sprintf( __( 'Renewal created: %s. We will review it and confirm the payment details.', $d ), $new_code ),
            'html'    => $this->render_order_card( $fresh ),
        ) );
    }

    /** Daily: complete finished campaigns and send "ending soon" reminders. */
    public function run_daily() {
        global $wpdb;
        $s = OStudy_Ads_DB::get_settings();
        $today = wp_date( 'Y-m-d', null, wp_timezone() );
        $rows = $wpdb->get_results( "SELECT * FROM " . self::table() . " WHERE status = 'live' AND start_date IS NOT NULL" );
        $statuses = self::statuses();
        foreach ( (array) $rows as $o ) {
            $end = self::order_end_date( $o );
            if ( ! $end ) continue;
            $left = (int) floor( ( strtotime( $end ) - strtotime( $today ) ) / DAY_IN_SECONDS );
            if ( $left < 0 ) {
                $wpdb->update( self::table(), array( 'status' => 'completed', 'history' => self::history_add( $o->history, 'completed' ), 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $o->id ) );
                if ( $o->ad_id && OStudy_Ads_DB::get_ad( $o->ad_id ) ) $wpdb->update( OStudy_Ads_DB::ads_table(), array( 'status' => 'paused', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $o->ad_id ) );
                $this->email_customer_status( self::get_order( $o->id ), $statuses['completed']['label'] );
                continue;
            }
            if ( empty( $s['order_reminders'] ) ) continue;
            $sent = array_filter( explode( ',', (string) $o->reminders_sent ) );
            $mark = '';
            if ( $left <= 3 && ! in_array( '3', $sent, true ) ) $mark = '3,7';
            elseif ( $left <= 7 && $left > 3 && ! in_array( '7', $sent, true ) ) $mark = '7';
            if ( '' === $mark ) continue;
            $wpdb->update( self::table(), array( 'reminders_sent' => implode( ',', array_unique( array_merge( $sent, explode( ',', $mark ) ) ) ) ), array( 'id' => (int) $o->id ) );
            $this->email_customer_ending( $o, $left );
        }
    }

    private function email_customer_ending( $o, $left ) {
        if ( ! is_email( $o->email ) ) return false;
        $link = add_query_arg( array( 'ad_order' => rawurlencode( $o->order_code ), 'ad_renew' => 1 ), self::order_page_url() );
        $lines = array(
            sprintf( __( 'Hello %s,', 'tutor-learning-suite' ), $o->contact_name ), '',
            sprintf( _n( 'Your ad "%1$s" (order %2$s) ends in %3$d day.', 'Your ad "%1$s" (order %2$s) ends in %3$d days.', max( 1, $left ), 'tutor-learning-suite' ), $o->ad_title, $o->order_code, max( 0, $left ) ), '',
            __( 'Want to keep it running? Open your order and press "Renew this ad". Your details and image are reused.', 'tutor-learning-suite' ),
            $link, '', self::site_name(),
        );
        return wp_mail( $o->email, sprintf( '[%s] %s', self::site_name(), sprintf( __( 'Your ad %s ends soon', 'tutor-learning-suite' ), $o->order_code ) ), implode( "\n", $lines ) );
    }

    /* ------------------------------------------------------------------
     * Emails
     * ---------------------------------------------------------------- */

    private static function site_name() {
        return wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
    }

    private function email_customer_received( $o ) {
        $lines = array(
            sprintf( __( 'Hello %s,', 'tutor-learning-suite' ), $o->contact_name ),
            '',
            __( 'Thank you! We received your advertising request.', 'tutor-learning-suite' ),
            '',
            __( 'Your order number:', 'tutor-learning-suite' ) . ' ' . $o->order_code,
            __( 'Follow its progress here:', 'tutor-learning-suite' ) . ' ' . self::track_url( $o->order_code ),
            '',
            __( 'To open the tracker you will need this number and the email or phone you used on the order.', 'tutor-learning-suite' ),
            '',
            self::site_name(),
        );
        return wp_mail( $o->email, sprintf( '[%s] %s %s', self::site_name(), __( 'Ad order', 'tutor-learning-suite' ), $o->order_code ), implode( "\n", $lines ) );
    }

    private function email_customer_status( $o, $label ) {
        if ( ! is_email( $o->email ) ) return false;
        $lines = array(
            sprintf( __( 'Hello %s,', 'tutor-learning-suite' ), $o->contact_name ),
            '',
            sprintf( __( 'Your ad order %1$s is now: %2$s', 'tutor-learning-suite' ), $o->order_code, $label ),
        );
        if ( ! empty( $o->public_note ) ) {
            $lines[] = '';
            $lines[] = __( 'Message from our team:', 'tutor-learning-suite' );
            $lines[] = $o->public_note;
        }
        if ( 'awaiting_payment' === $o->status ) {
            $s = OStudy_Ads_DB::get_settings();
            $lines[] = '';
            if ( '' !== trim( (string) $o->amount_due ) ) $lines[] = __( 'Amount due:', 'tutor-learning-suite' ) . ' ' . $o->amount_due;
            if ( '' !== trim( (string) $s['payment_instructions'] ) ) { $lines[] = __( 'How to pay:', 'tutor-learning-suite' ); $lines[] = $s['payment_instructions']; }
            $lines[] = __( 'After paying, open the tracking page and send your payment proof.', 'tutor-learning-suite' );
        }
        $lines[] = '';
        $lines[] = __( 'Track your order:', 'tutor-learning-suite' ) . ' ' . self::track_url( $o->order_code );
        $lines[] = '';
        $lines[] = self::site_name();
        return wp_mail( $o->email, sprintf( '[%s] %s %s — %s', self::site_name(), __( 'Ad order', 'tutor-learning-suite' ), $o->order_code, $label ), implode( "\n", $lines ) );
    }

    private function email_admin_new( $o ) {
        $s  = OStudy_Ads_DB::get_settings();
        $to = is_email( $s['order_notify_email'] ) ? $s['order_notify_email'] : get_option( 'admin_email' );
        $lines = array(
            __( 'A new advertising order was submitted.', 'tutor-learning-suite' ),
            '',
            __( 'Order', 'tutor-learning-suite' ) . ': ' . $o->order_code,
            __( 'Ad', 'tutor-learning-suite' ) . ': ' . $o->ad_title . ' (' . $o->ad_type . ')',
            __( 'From', 'tutor-learning-suite' ) . ': ' . $o->contact_name . ' <' . $o->email . '> ' . $o->phone,
            '',
            __( 'Review it:', 'tutor-learning-suite' ) . ' ' . admin_url( 'admin.php?page=ostudy-ads-orders&order=' . absint( $o->id ) ),
        );
        return wp_mail( $to, sprintf( '[%s] %s %s', self::site_name(), __( 'New ad order', 'tutor-learning-suite' ), $o->order_code ), implode( "\n", $lines ) );
    }

    /* ------------------------------------------------------------------
     * Admin
     * ---------------------------------------------------------------- */

    public function admin_menu() {
        $pending = self::count_status( 'pending' );
        $label   = esc_html__( 'Orders', 'tutor-learning-suite' ) . ( $pending ? ' <span class="awaiting-mod">' . (int) $pending . '</span>' : '' );
        add_submenu_page( 'ostudy-ads', __( 'Ad Orders', 'tutor-learning-suite' ), $label, 'manage_options', 'ostudy-ads-orders', array( $this, 'admin_page' ) );
    }

    public function admin_handle_post() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        global $wpdb;

        if ( isset( $_POST['ostudy_order_test_mail'] ) ) {
            check_admin_referer( 'ostudy_order_test_mail' );
            $s  = OStudy_Ads_DB::get_settings();
            $to = is_email( $s['order_notify_email'] ) ? $s['order_notify_email'] : get_option( 'admin_email' );
            $ok = wp_mail( $to, '[' . self::site_name() . '] ' . __( 'Ad orders: test email', 'tutor-learning-suite' ), __( 'If you can read this, order notifications can reach you.', 'tutor-learning-suite' ) );
            wp_safe_redirect( admin_url( 'admin.php?page=ostudy-ads-orders&mailtest=' . ( $ok ? 'ok' : 'fail' ) ) );
            exit;
        }

        if ( isset( $_POST['ostudy_order_update'] ) ) {
            $id = absint( $_POST['order_id'] ?? 0 );
            check_admin_referer( 'ostudy_order_update_' . $id );
            $order = self::get_order( $id );
            if ( $order ) {
                $statuses = self::statuses();
                $new = sanitize_key( $_POST['status'] ?? $order->status );
                if ( ! isset( $statuses[ $new ] ) ) $new = $order->status;
                $note = sanitize_textarea_field( wp_unslash( $_POST['public_note'] ?? '' ) );
                $data = array(
                    'status'        => $new,
                    'amount_due'    => sanitize_text_field( wp_unslash( $_POST['amount_due'] ?? '' ) ),
                    'public_note'   => $note,
                    'internal_note' => sanitize_textarea_field( wp_unslash( $_POST['internal_note'] ?? '' ) ),
                    'updated_at'    => current_time( 'mysql', true ),
                );
                $changed = ( $new !== $order->status );
                if ( $changed ) $data['history'] = self::history_add( $order->history, $new, $note );
                $wpdb->update( self::table(), $data, array( 'id' => $id ) );

                // Keep the linked advertisement in step with the order.
                if ( $changed && $order->ad_id && OStudy_Ads_DB::get_ad( $order->ad_id ) ) {
                    if ( 'live' === $new ) $wpdb->update( OStudy_Ads_DB::ads_table(), array( 'status' => 'active', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $order->ad_id ) );
                    if ( in_array( $new, array( 'completed', 'rejected', 'cancelled' ), true ) ) $wpdb->update( OStudy_Ads_DB::ads_table(), array( 'status' => 'paused', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $order->ad_id ) );
                }
                if ( ( $changed || $note !== (string) $order->public_note ) && ! empty( $_POST['notify_customer'] ) ) {
                    $fresh = self::get_order( $id );
                    $this->email_customer_status( $fresh, $statuses[ $new ]['label'] );
                }
            }
            wp_safe_redirect( admin_url( 'admin.php?page=ostudy-ads-orders&order=' . $id . '&updated=1' ) );
            exit;
        }

        if ( isset( $_POST['ostudy_order_create_ad'] ) ) {
            $id = absint( $_POST['order_id'] ?? 0 );
            check_admin_referer( 'ostudy_order_create_ad_' . $id );
            $order = self::get_order( $id );
            if ( $order && ! $order->ad_id ) {
                $ad_id = $this->create_ad_from_order( $order );
                if ( $ad_id ) {
                    $wpdb->update( self::table(), array( 'ad_id' => $ad_id, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $id ) );
                    wp_safe_redirect( admin_url( 'admin.php?page=ostudy-ads-edit&id=' . $ad_id . '&from_order=' . $id ) );
                    exit;
                }
            }
            wp_safe_redirect( admin_url( 'admin.php?page=ostudy-ads-orders&order=' . $id ) );
            exit;
        }

        if ( isset( $_GET['delete_order'] ) && isset( $_GET['page'] ) && 'ostudy-ads-orders' === $_GET['page'] ) {
            $id = absint( $_GET['delete_order'] );
            check_admin_referer( 'ostudy_order_delete_' . $id );
            $wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
            wp_safe_redirect( admin_url( 'admin.php?page=ostudy-ads-orders&deleted=1' ) );
            exit;
        }
    }

    private function create_ad_from_order( $o ) {
        $tz    = wp_timezone();
        $start = $o->start_date ? $o->start_date . ' 00:00' : '';
        $end   = '';
        if ( $o->start_date ) {
            try {
                $s   = new DateTimeImmutable( $start, $tz );
                $end = $s->modify( '+' . max( 1, (int) $o->duration_days ) . ' days' )->modify( '-1 minute' )->format( 'Y-m-d H:i' );
            } catch ( Exception $e ) { $end = ''; }
        }
        $popup = ( 'popup' === $o->ad_type );
        return OStudy_Ads_DB::save_ad( array(
            'name'            => trim( ( $o->business_name ?: $o->contact_name ) . ' — ' . $o->ad_title ) . ' [' . $o->order_code . ']',
            'status'          => 'paused', // admin reviews targeting, then sets the order Live to activate
            'ad_type'         => $o->ad_type,
            'image_id'        => $o->image_id,
            'image_url'       => $o->image_url,
            'mobile_image_id' => $o->mobile_image_id,
            'mobile_image_url' => $o->mobile_image_url,
            'destination_url' => $o->destination_url,
            'open_new_tab'    => 1,
            'start_at'        => $start,
            'end_at'          => $end,
            'page_targets'    => json_decode( (string) $o->placements, true ),
            'device_targets'  => array( 'desktop', 'tablet', 'mobile' ),
            'frequency'       => $popup ? 'cooldown' : 'always',
            'cooldown_hours'  => 24,
            'fit_mode'        => $popup ? 'contain' : 'cover',
        ) );
    }

    public function admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        if ( ! empty( $_GET['order'] ) ) { $this->admin_detail( absint( $_GET['order'] ) ); return; }
        global $wpdb;
        $statuses = self::statuses();
        $filter   = sanitize_key( $_GET['status'] ?? '' );
        $search   = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
        $paged    = max( 1, absint( $_GET['paged'] ?? 1 ) );
        $per      = 20;

        $counts = array();
        foreach ( (array) $wpdb->get_results( 'SELECT status, COUNT(*) c FROM ' . self::table() . ' GROUP BY status' ) as $r ) $counts[ $r->status ] = (int) $r->c;
        $total = array_sum( $counts );

        $where = '1=1'; $params = array();
        if ( $filter && isset( $statuses[ $filter ] ) ) { $where .= ' AND status = %s'; $params[] = $filter; }
        if ( '' !== $search ) {
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $where .= ' AND (order_code LIKE %s OR email LIKE %s OR contact_name LIKE %s OR business_name LIKE %s OR ad_title LIKE %s OR phone LIKE %s)';
            array_push( $params, $like, $like, $like, $like, $like, $like );
        }
        $count_sql = 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE ' . $where;
        $found = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );
        $sql = 'SELECT * FROM ' . self::table() . ' WHERE ' . $where . ' ORDER BY id DESC LIMIT %d OFFSET %d';
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, array( $per, ( $paged - 1 ) * $per ) ) ) );

        $base = admin_url( 'admin.php?page=ostudy-ads-orders' );
        echo '<div class="wrap ostudy-ads-admin"><h1>' . esc_html__( 'Ad Orders', 'tutor-learning-suite' ) . '</h1>';
        if ( isset( $_GET['deleted'] ) ) echo '<div class="notice notice-success"><p>' . esc_html__( 'Order deleted.', 'tutor-learning-suite' ) . '</p></div>';
        if ( isset( $_GET['mailtest'] ) ) echo '<div class="notice ' . ( 'ok' === $_GET['mailtest'] ? 'notice-success' : 'notice-error' ) . '"><p>' . esc_html( 'ok' === $_GET['mailtest'] ? __( 'Test email sent. Check your inbox (and spam).', 'tutor-learning-suite' ) : __( 'WordPress could not send the test email. Install an SMTP plugin so order emails are delivered.', 'tutor-learning-suite' ) ) . '</p></div>';
        $this->render_setup_check();
        echo '<div class="ostudy-order-help"><strong>' . esc_html__( 'Order form shortcode:', 'tutor-learning-suite' ) . '</strong> <code>[ostudy_ad_order]</code> <button type="button" class="button-link ostudy-copy-shortcode" data-copy="[ostudy_ad_order]">' . esc_html__( 'Copy', 'tutor-learning-suite' ) . '</button> &nbsp;·&nbsp; <strong>' . esc_html__( 'Tracking only:', 'tutor-learning-suite' ) . '</strong> <code>[ostudy_ad_track]</code></div>';

        echo '<ul class="subsubsub"><li><a href="' . esc_url( $base ) . '" class="' . ( $filter ? '' : 'current' ) . '">' . esc_html__( 'All', 'tutor-learning-suite' ) . ' <span class="count">(' . (int) $total . ')</span></a></li>';
        foreach ( $statuses as $key => $st ) {
            if ( empty( $counts[ $key ] ) ) continue;
            echo ' | <li><a href="' . esc_url( add_query_arg( 'status', $key, $base ) ) . '" class="' . ( $filter === $key ? 'current' : '' ) . '">' . esc_html( $st['label'] ) . ' <span class="count">(' . (int) $counts[ $key ] . ')</span></a></li>';
        }
        echo '</ul>';
        echo '<form method="get" class="search-box"><input type="hidden" name="page" value="ostudy-ads-orders">';
        if ( $filter ) echo '<input type="hidden" name="status" value="' . esc_attr( $filter ) . '">';
        echo '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Order no., name, email…', 'tutor-learning-suite' ) . '"> <button class="button">' . esc_html__( 'Search', 'tutor-learning-suite' ) . '</button></form>';

        echo '<table class="widefat striped ostudy-orders-table"><thead><tr><th>' . esc_html__( 'Order', 'tutor-learning-suite' ) . '</th><th>' . esc_html__( 'Ad', 'tutor-learning-suite' ) . '</th><th>' . esc_html__( 'Customer', 'tutor-learning-suite' ) . '</th><th>' . esc_html__( 'Format', 'tutor-learning-suite' ) . '</th><th>' . esc_html__( 'Start', 'tutor-learning-suite' ) . '</th><th>' . esc_html__( 'Status', 'tutor-learning-suite' ) . '</th><th>' . esc_html__( 'Received', 'tutor-learning-suite' ) . '</th></tr></thead><tbody>';
        foreach ( $rows as $o ) {
            $st   = isset( $statuses[ $o->status ] ) ? $statuses[ $o->status ] : $statuses['pending'];
            $view = add_query_arg( 'order', (int) $o->id, $base );
            echo '<tr><td><a href="' . esc_url( $view ) . '"><strong>' . esc_html( $o->order_code ) . '</strong></a></td>';
            echo '<td>' . esc_html( $o->ad_title ) . ( $o->business_name ? '<br><small>' . esc_html( $o->business_name ) . '</small>' : '' ) . '</td>';
            echo '<td>' . esc_html( $o->contact_name ) . '<br><small>' . esc_html( $o->email ) . ( $o->user_id ? '' : ' · ' . esc_html__( 'guest', 'tutor-learning-suite' ) ) . '</small></td>';
            echo '<td>' . esc_html( ucfirst( $o->ad_type ) ) . '</td><td>' . esc_html( $o->start_date ?: '—' ) . '</td>';
            echo '<td><span class="ostudy-chip" style="--chip:' . esc_attr( $st['color'] ) . '">' . esc_html( $st['label'] ) . '</span></td>';
            echo '<td>' . esc_html( self::local_date( $o->created_at ) ) . '</td></tr>';
        }
        if ( ! $rows ) echo '<tr><td colspan="7">' . esc_html__( 'No orders yet.', 'tutor-learning-suite' ) . '</td></tr>';
        echo '</tbody></table>';
        $pages = (int) ceil( $found / $per );
        if ( $pages > 1 ) {
            echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( array(
                'base' => add_query_arg( 'paged', '%#%', $base . ( $filter ? '&status=' . $filter : '' ) . ( $search ? '&s=' . rawurlencode( $search ) : '' ) ),
                'format' => '', 'current' => $paged, 'total' => $pages,
            ) ) ) . '</div></div>';
        }
        echo '</div>';
    }

    /** Quick health check so the admin can verify the setup before going live. */
    private function render_setup_check() {
        $d = 'tutor-learning-suite';
        $s = OStudy_Ads_DB::get_settings();
        $checks = array();
        $url = (string) $s['order_page_url'];
        $page_ok = false;
        if ( $url ) {
            $pid = url_to_postid( $url );
            if ( $pid ) { $p = get_post( $pid ); $page_ok = $p && ( has_shortcode( $p->post_content, 'ostudy_ad_order' ) || has_shortcode( $p->post_content, 'ostudy_advertise' ) ); }
        }
        $checks[] = array( $page_ok, __( 'Order page', $d ), $page_ok ? __( 'Found and contains the order shortcode.', $d ) : __( 'Set "Order page URL" in Settings and put [ostudy_ad_order] (or [ostudy_advertise]) on that page.', $d ) );
        $checks[] = array( ! empty( $s['orders_enabled'] ), __( 'Orders open', $d ), ! empty( $s['orders_enabled'] ) ? __( 'Visitors can place orders.', $d ) : __( 'Orders are switched off in Settings.', $d ) );
        $has_instr = '' !== trim( (string) $s['payment_instructions'] );
        $checks[] = array( $has_instr, __( 'Payment instructions', $d ), $has_instr ? __( 'Set.', $d ) : __( 'Empty. Customers will not know how to pay.', $d ) );
        $checks[] = array( self::pricing_enabled() ? true : null, __( 'Prices', $d ), self::pricing_enabled() ? __( 'Prices configured; the form shows a live total.', $d ) : __( 'No prices set. You will quote each order by hand (optional).', $d ) );
        $checks[] = array( (int) $s['order_max_per_placement'] > 0 ? true : null, __( 'Slot limit', $d ), (int) $s['order_max_per_placement'] > 0 ? sprintf( __( 'Max %d ads per placement at the same time.', $d ), (int) $s['order_max_per_placement'] ) : __( 'Unlimited. Set a limit to avoid selling a placement twice.', $d ) );
        $up = wp_upload_dir();
        $checks[] = array( empty( $up['error'] ) && wp_is_writable( $up['basedir'] ), __( 'Uploads', $d ), ( empty( $up['error'] ) && wp_is_writable( $up['basedir'] ) ) ? __( 'Uploads folder is writable.', $d ) : __( 'The uploads folder is not writable. Image and receipt uploads will fail.', $d ) );
        $priv = self::private_proof_dir();
        $checks[] = array( (bool) $priv, __( 'Private receipts', $d ), $priv ? __( 'Receipts are stored outside the web root and only admins can open them.', $d ) : __( 'Could not create the private receipts folder outside the web root. Define TLS_PRIVATE_STORAGE_DIR in wp-config.php to a writable folder above your public directory.', $d ) );
        $checks[] = array( defined( 'WP_CACHE' ) && WP_CACHE ? null : true, __( 'Page cache', $d ), ( defined( 'WP_CACHE' ) && WP_CACHE ) ? __( 'A page cache looks active. Exclude the order page from caching so sessions and prices stay correct.', $d ) : __( 'No page cache detected.', $d ) );
        $cron_off = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
        $checks[] = array( $cron_off ? null : true, __( 'Reminders (cron)', $d ), $cron_off ? __( 'WP-Cron is disabled. Make sure a real server cron calls wp-cron.php, or reminders and auto-completion will not run.', $d ) : ( wp_next_scheduled( 'ostudy_ads_daily_orders' ) ? __( 'Daily job is scheduled.', $d ) : __( 'Daily job not scheduled yet; it is created on the next page load.', $d ) ) );
        echo '<details class="ostudy-setup-check"' . ( in_array( false, array_column( $checks, 0 ), true ) ? ' open' : '' ) . '><summary>' . esc_html__( 'Setup check', $d ) . '</summary><ul>';
        foreach ( $checks as $c ) {
            $cls = true === $c[0] ? 'ok' : ( false === $c[0] ? 'bad' : 'warn' );
            echo '<li class="is-' . esc_attr( $cls ) . '"><b>' . ( 'ok' === $cls ? '✓' : ( 'bad' === $cls ? '✗' : '!' ) ) . '</b><span><strong>' . esc_html( $c[1] ) . '</strong> — ' . esc_html( $c[2] ) . '</span></li>';
        }
        echo '</ul><form method="post">';
        wp_nonce_field( 'ostudy_order_test_mail' );
        echo '<button class="button" name="ostudy_order_test_mail" value="1">' . esc_html__( 'Send a test email', $d ) . '</button> <span class="description">' . esc_html__( 'Confirms that order notifications reach you.', $d ) . '</span></form></details>';
    }

    private function admin_detail( $id ) {
        $o = self::get_order( $id );
        echo '<div class="wrap ostudy-ads-admin">';
        if ( ! $o ) { echo '<h1>' . esc_html__( 'Order not found', 'tutor-learning-suite' ) . '</h1><p><a href="' . esc_url( admin_url( 'admin.php?page=ostudy-ads-orders' ) ) . '">&larr; ' . esc_html__( 'Back to orders', 'tutor-learning-suite' ) . '</a></p></div>'; return; }
        $statuses = self::statuses();
        $places   = self::placement_choices();
        $labels   = array();
        foreach ( (array) json_decode( (string) $o->placements, true ) as $z ) if ( isset( $places[ $z ] ) ) $labels[] = $places[ $z ][1];
        $st = isset( $statuses[ $o->status ] ) ? $statuses[ $o->status ] : $statuses['pending'];

        echo '<h1>' . esc_html( $o->order_code ) . ' <span class="ostudy-chip" style="--chip:' . esc_attr( $st['color'] ) . '">' . esc_html( $st['label'] ) . '</span></h1>';
        echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=ostudy-ads-orders' ) ) . '">&larr; ' . esc_html__( 'Back to orders', 'tutor-learning-suite' ) . '</a></p>';
        if ( isset( $_GET['updated'] ) ) echo '<div class="notice notice-success"><p>' . esc_html__( 'Order updated.', 'tutor-learning-suite' ) . '</p></div>';

        echo '<div class="ostudy-order-grid"><div class="ostudy-order-card"><h2>' . esc_html__( 'Request', 'tutor-learning-suite' ) . '</h2><table class="ostudy-order-meta">';
        $meta = array(
            __( 'Ad title', 'tutor-learning-suite' ) => $o->ad_title,
            __( 'Business', 'tutor-learning-suite' ) => $o->business_name,
            __( 'Format', 'tutor-learning-suite' ) => ucfirst( $o->ad_type ),
            __( 'Placements', 'tutor-learning-suite' ) => implode( ', ', $labels ),
            __( 'Start / duration', 'tutor-learning-suite' ) => ( $o->start_date ?: '—' ) . ' · ' . (int) $o->duration_days . ' ' . __( 'days', 'tutor-learning-suite' ),
            __( 'Budget', 'tutor-learning-suite' ) => $o->budget,
            __( 'Needs design help', 'tutor-learning-suite' ) => $o->need_design ? __( 'Yes', 'tutor-learning-suite' ) : __( 'No', 'tutor-learning-suite' ),
            __( 'Customer', 'tutor-learning-suite' ) => $o->contact_name . ( $o->user_id ? '' : ' (' . __( 'guest', 'tutor-learning-suite' ) . ')' ),
            __( 'Email', 'tutor-learning-suite' ) => $o->email,
            __( 'Phone', 'tutor-learning-suite' ) => $o->phone,
            __( 'Received', 'tutor-learning-suite' ) => self::local_date( $o->created_at ),
        );
        foreach ( $meta as $k => $v ) {
            if ( '' === trim( (string) $v ) ) continue;
            echo '<tr><th>' . esc_html( $k ) . '</th><td>' . esc_html( $v ) . '</td></tr>';
        }
        if ( $o->destination_url ) echo '<tr><th>' . esc_html__( 'Link', 'tutor-learning-suite' ) . '</th><td><a href="' . esc_url( $o->destination_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $o->destination_url ) . '</a></td></tr>';
        echo '</table>';
        if ( $o->description ) echo '<h3>' . esc_html__( 'Notes from the customer', 'tutor-learning-suite' ) . '</h3><p>' . nl2br( esc_html( $o->description ) ) . '</p>';
        if ( $o->image_url ) echo '<h3>' . esc_html__( 'Creative', 'tutor-learning-suite' ) . '</h3><a href="' . esc_url( $o->image_url ) . '" target="_blank" rel="noopener noreferrer"><img class="ostudy-order-creative" src="' . esc_url( $o->image_url ) . '" alt=""></a>';
        echo '</div>';

        $cap = OStudy_Ads_DB::get_settings();
        $zone_list = array_values( array_filter( (array) json_decode( (string) $o->placements, true ) ) );
        $cap_html = '';
        if ( (int) $cap['order_max_per_placement'] > 0 && $o->start_date && $zone_list ) {
            ob_start();
            echo '<div class="ostudy-order-card"><h2>' . esc_html__( 'Availability for these dates', 'tutor-learning-suite' ) . '</h2><ul class="ostudy-capacity">';
            foreach ( self::availability( $zone_list, $o->start_date, (int) $o->duration_days, (int) $o->id ) as $a ) {
                $over = $a['used'] >= $a['max'];
                echo '<li class="' . ( $over ? 'is-over' : 'is-ok' ) . '"><span>' . esc_html( $a['label'] ) . '</span><b>' . (int) $a['used'] . ' / ' . (int) $a['max'] . ' ' . esc_html__( 'booked', 'tutor-learning-suite' ) . ( $over ? ' — ' . esc_html__( 'at capacity', 'tutor-learning-suite' ) : '' ) . '</b></li>';
            }
            echo '</ul><p class="description">' . esc_html__( 'Counts other orders that are Awaiting payment, Under review or Live. Approving this order into a full placement would overbook it.', 'tutor-learning-suite' ) . '</p></div>';
            $cap_html = ob_get_clean();
        }
        echo '<div>' . $cap_html . '<div class="ostudy-order-card"><h2>' . esc_html__( 'Manage', 'tutor-learning-suite' ) . '</h2><form method="post">';
        wp_nonce_field( 'ostudy_order_update_' . $o->id );
        echo '<input type="hidden" name="order_id" value="' . (int) $o->id . '"><p><label><strong>' . esc_html__( 'Status', 'tutor-learning-suite' ) . '</strong><br><select name="status">';
        foreach ( $statuses as $key => $s ) echo '<option value="' . esc_attr( $key ) . '" ' . selected( $o->status, $key, false ) . '>' . esc_html( $s['label'] ) . '</option>';
        echo '</select></label></p>';
        echo '<p><label><strong>' . esc_html__( 'Amount due', 'tutor-learning-suite' ) . '</strong><br><input type="text" name="amount_due" class="regular-text" value="' . esc_attr( $o->amount_due ) . '" placeholder="' . esc_attr__( 'e.g. 5 000 DZD', 'tutor-learning-suite' ) . '"></label><span class="description">' . esc_html__( 'Shown with the payment instructions when the order is Awaiting payment.', 'tutor-learning-suite' ) . '</span></p>';
        echo '<p><label><strong>' . esc_html__( 'Message to the customer', 'tutor-learning-suite' ) . '</strong><br><textarea name="public_note" rows="4" class="large-text">' . esc_textarea( $o->public_note ) . '</textarea></label><span class="description">' . esc_html__( 'Shown on the tracking page (payment instructions, reasons for rejection, etc.).', 'tutor-learning-suite' ) . '</span></p>';
        echo '<p><label><strong>' . esc_html__( 'Internal note', 'tutor-learning-suite' ) . '</strong><br><textarea name="internal_note" rows="3" class="large-text">' . esc_textarea( $o->internal_note ) . '</textarea></label><span class="description">' . esc_html__( 'Only visible to admins.', 'tutor-learning-suite' ) . '</span></p>';
        echo '<p><label><input type="checkbox" name="notify_customer" value="1" checked> ' . esc_html__( 'Email the customer about this update', 'tutor-learning-suite' ) . '</label></p>';
        echo '<p><button class="button button-primary" name="ostudy_order_update" value="1">' . esc_html__( 'Save order', 'tutor-learning-suite' ) . '</button></p></form></div>';

        if ( $o->payment_proof_path || $o->payment_proof_id || $o->payment_proof_url ) {
            if ( empty( $o->payment_proof_path ) && ! empty( $o->payment_proof_id ) ) {
                $this->migrate_order_proof( $o );
                $o = self::get_order( $id );
            }
            $proof_path = $o ? (string) $o->payment_proof_path : '';
            echo '<div class="ostudy-order-card"><h2>' . esc_html__( 'Payment proof', 'tutor-learning-suite' ) . '</h2>';
            echo '<p class="description">' . esc_html( self::local_date( $o->payment_submitted_at ) ) . ( $o->payment_reference ? ' · ' . esc_html__( 'Ref', 'tutor-learning-suite' ) . ': ' . esc_html( $o->payment_reference ) : '' ) . '</p>';
            if ( $o->payment_note ) echo '<p>' . esc_html( $o->payment_note ) . '</p>';
            if ( $proof_path && self::resolve_private_proof( $proof_path ) ) {
                $is_pdf = ( 'pdf' === strtolower( pathinfo( $proof_path, PATHINFO_EXTENSION ) ) );
                $proof_url = $this->proof_view_url( $id );
                if ( $is_pdf ) echo '<p><a class="button" href="' . esc_url( $proof_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open PDF receipt', 'tutor-learning-suite' ) . '</a></p>';
                else echo '<a href="' . esc_url( $proof_url ) . '" target="_blank" rel="noopener noreferrer"><img class="ostudy-order-creative" src="' . esc_url( $proof_url ) . '" alt="' . esc_attr__( 'Payment receipt', 'tutor-learning-suite' ) . '"></a>';
            } else {
                echo '<p class="notice notice-warning inline"><strong>' . esc_html__( 'The legacy receipt could not yet be moved into private storage. It is not linked here; check the server storage configuration and reload this page.', 'tutor-learning-suite' ) . '</strong></p>';
            }
            echo '<p class="description">' . esc_html__( 'This receipt is available to administrators only. To accept: set status to Live (or back to Awaiting payment with a message if the proof is not valid).', 'tutor-learning-suite' ) . '</p></div>';
        }
        echo '<div class="ostudy-order-card"><h2>' . esc_html__( 'Advertisement', 'tutor-learning-suite' ) . '</h2>';
        if ( $o->ad_id && OStudy_Ads_DB::get_ad( $o->ad_id ) ) {
            echo '<p>' . esc_html__( 'Linked ad created.', 'tutor-learning-suite' ) . ' <a class="button" href="' . esc_url( admin_url( 'admin.php?page=ostudy-ads-edit&id=' . (int) $o->ad_id ) ) . '">' . esc_html__( 'Open ad', 'tutor-learning-suite' ) . '</a></p><p class="description">' . esc_html__( 'Setting the order to Live activates this ad. Completed, Rejected or Cancelled pauses it.', 'tutor-learning-suite' ) . '</p>';
        } else {
            echo '<p class="description">' . esc_html__( 'Creates a paused ad pre-filled with this order’s image, link, placements and dates. Review it, then set the order to Live.', 'tutor-learning-suite' ) . '</p><form method="post">';
            wp_nonce_field( 'ostudy_order_create_ad_' . $o->id );
            echo '<input type="hidden" name="order_id" value="' . (int) $o->id . '"><button class="button" name="ostudy_order_create_ad" value="1">' . esc_html__( 'Create ad from this order', 'tutor-learning-suite' ) . '</button></form>';
        }
        echo '</div>';

        $del = wp_nonce_url( admin_url( 'admin.php?page=ostudy-ads-orders&delete_order=' . (int) $o->id ), 'ostudy_order_delete_' . $o->id );
        echo '<p><a class="ostudy-order-delete" href="' . esc_url( $del ) . '" onclick="return confirm(\'' . esc_js( __( 'Delete this order permanently?', 'tutor-learning-suite' ) ) . '\')">' . esc_html__( 'Delete order', 'tutor-learning-suite' ) . '</a></p>';
        echo '</div></div></div>';
    }
}
