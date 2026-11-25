<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class OStudy_Ads_Admin {
    private static $instance;
    public static function instance() {
        if ( ! self::$instance ) self::$instance = new self();
        return self::$instance;
    }
    private function __construct() {
        add_action( 'admin_menu', array( $this, 'menu' ) );
        add_action( 'admin_init', array( $this, 'register' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
    }
    public function menu() {
        add_menu_page( 'OStudy Ads', 'OStudy Ads', 'manage_options', 'ostudy-ads', array( $this, 'ads_page' ), 'dashicons-megaphone', 58 );
        add_submenu_page( 'ostudy-ads', 'All Ads', 'All Ads', 'manage_options', 'ostudy-ads', array( $this, 'ads_page' ) );
        add_submenu_page( 'ostudy-ads', 'Add New', 'Add New', 'manage_options', 'ostudy-ads-edit', array( $this, 'edit_page' ) );
        add_submenu_page( 'ostudy-ads', 'Analytics', 'Analytics', 'manage_options', 'ostudy-ads-analytics', array( $this, 'analytics_page' ) );
        add_submenu_page( 'ostudy-ads', 'Settings', 'Settings', 'manage_options', 'ostudy-ads-settings', array( $this, 'settings_page' ) );
    }
    public function register() {
        register_setting( 'ostudy_ads_settings_group', 'ostudy_ads_settings', array( 'sanitize_callback' => array( $this, 'sanitize_settings' ) ) );
        if ( isset( $_POST['ostudy_ads_save'] ) && current_user_can( 'manage_options' ) ) {
            check_admin_referer( 'ostudy_ads_save_ad' );
            $id = absint( $_POST['ad_id'] ?? 0 );
            
            $posted = wp_unslash( $_POST );
            $posted['categories'] = array_values( array_filter( array_map( 'absint', preg_split( '/\s*,\s*/', (string) ( $posted['categories_csv'] ?? '' ) ) ) ) );
            $posted['wilayas'] = array_values( array_filter( array_map( 'sanitize_text_field', preg_split( '/\s*,\s*/', (string) ( $posted['wilayas_csv'] ?? '' ) ) ) ) );
            OStudy_Ads_DB::save_ad( $posted, $id );
            wp_safe_redirect( admin_url( 'admin.php?page=ostudy-ads&saved=1' ) );
            exit;
        }
        if ( isset( $_GET['delete_ad'] ) && current_user_can( 'manage_options' ) ) {
            check_admin_referer( 'ostudy_ads_delete_' . absint( $_GET['delete_ad'] ) );
            OStudy_Ads_DB::delete_ad( absint( $_GET['delete_ad'] ) );
            wp_safe_redirect( admin_url( 'admin.php?page=ostudy-ads&deleted=1' ) );
            exit;
        }
    }
    public function sanitize_settings( $settings ) {
        $defaults = OStudy_Ads_Utils::default_settings();
        $clean = array();
        foreach ( $defaults as $key => $default ) {
            if ( in_array( $key, array( 'enabled', 'auto_render', 'focus_mode_enabled', 'focus_mode_side_enabled', 'popup_once_per_session', 'popup_mobile', 'hide_for_admins', 'orders_enabled', 'order_enforce_sizes', 'order_reminders' ), true ) ) $clean[ $key ] = empty( $settings[ $key ] ) ? 0 : 1;
            elseif ( 'order_prices' === $key ) {
                $clean[ $key ] = array();
                $valid = OStudy_Ads_Orders::placement_choices();
                foreach ( (array) ( $settings[ $key ] ?? array() ) as $zone => $price ) {
                    $zone = sanitize_key( $zone ); $price = round( (float) $price, 2 );
                    if ( isset( $valid[ $zone ] ) && $price > 0 ) $clean[ $key ][ $zone ] = $price;
                }
            }
            elseif ( 'order_discounts' === $key ) {
                $clean[ $key ] = array();
                foreach ( array( 30, 60, 90 ) as $days ) $clean[ $key ][ $days ] = min( 90, max( 0, round( (float) ( $settings[ $key ][ $days ] ?? 0 ), 1 ) ) );
            }
            elseif ( 'order_max_per_placement' === $key ) $clean[ $key ] = min( 50, absint( $settings[ $key ] ?? 0 ) );
            elseif ( 'payment_instructions' === $key ) $clean[ $key ] = sanitize_textarea_field( $settings[ $key ] ?? '' );
            elseif ( 'order_page_url' === $key ) $clean[ $key ] = esc_url_raw( $settings[ $key ] ?? '' );
            elseif ( 'order_notify_email' === $key ) $clean[ $key ] = sanitize_email( $settings[ $key ] ?? '' );
            elseif ( 'order_accent_color' === $key ) $clean[ $key ] = sanitize_hex_color( $settings[ $key ] ?? '' ) ?: $default;
            elseif ( 'order_max_upload_mb' === $key ) $clean[ $key ] = min( 10, max( 1, absint( $settings[ $key ] ?? $default ) ) );
            elseif ( 0 === strpos( $key, 'selector_' ) ) $clean[ $key ] = sanitize_textarea_field( $settings[ $key ] ?? $default );
            else $clean[ $key ] = sanitize_text_field( $settings[ $key ] ?? $default );
        }
        return $clean;
    }
    public function assets( $hook ) {
        if ( false === strpos( $hook, 'ostudy-ads' ) ) return;
        wp_enqueue_media();
        wp_enqueue_style( 'ostudy-ads-admin', OSTUDY_ADS_URL . 'assets/css/admin.css', array(), OSTUDY_ADS_VERSION );
        wp_enqueue_script( 'ostudy-ads-admin', OSTUDY_ADS_URL . 'assets/js/admin.js', array( 'jquery' ), OSTUDY_ADS_VERSION, true );
    }
    public function ads_page() {
        $ads = OStudy_Ads_DB::get_ads( array( 'status' => '' ) );
        echo '<div class="wrap ostudy-ads-admin"><h1>OStudy Ads <a class="page-title-action" href="' . esc_url( admin_url( 'admin.php?page=ostudy-ads-edit' ) ) . '">Add New</a></h1>';
        if ( isset( $_GET['saved'] ) ) echo '<div class="notice notice-success"><p>Advertisement saved.</p></div>';
        if ( isset( $_GET['deleted'] ) ) echo '<div class="notice notice-success"><p>Advertisement deleted.</p></div>';
        echo '<div class="ostudy-shortcode-help">';
        echo '<h2>Shortcodes</h2>';
        echo '<p>Use these in a Gutenberg Shortcode block, Elementor Shortcode widget, a page section, or any WordPress area that accepts shortcodes. The ad is rendered exactly where you place the shortcode.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Use</th><th>Shortcode</th><th>How it works</th></tr></thead><tbody>';
        echo '<tr><td><strong>Specific ad</strong></td><td><code>[ostudy_ad id=123]</code></td><td>Shows ad ID 123 exactly at the shortcode location.</td></tr>';
        echo '<tr><td><strong>Specific ad + zone styling</strong></td><td><code>[ostudy_ad id=123 zone=home_top]</code></td><td>Same exact location; the zone controls the ad styling/placement class.</td></tr>';
        echo '<tr><td><strong>Choose an eligible ad</strong></td><td><code>[ostudy_ad zone=home_top]</code></td><td>Chooses an active eligible ad for that zone according to scheduling, device, role, category and frequency rules.</td></tr>';
        echo '<tr><td><strong>Force page-target check</strong></td><td><code>[ostudy_ad id=123 zone=home_top respect_page_target=1]</code></td><td>Also requires the ad\'s automatic page-target selection to match the current page.</td></tr>';
        echo '<tr><td><strong>Order an ad (form + tracking)</strong></td><td><code>[ostudy_ad_order]</code></td><td>Modern 3-step order form for users and guests, with a "Track my order" tab. Guests get an order number.</td></tr>';
        echo '<tr><td><strong>Tracking only</strong></td><td><code>[ostudy_ad_track]</code></td><td>Just the order tracker (order number + email/phone). Logged-in users also see their own orders.</td></tr>';
        echo '</tbody></table>';
        echo '<p class="description"><strong>Tip:</strong> For a custom homepage or landing page, use the specific-ad shortcode so you control the exact position. For a reusable zone, use the zone shortcode.</p>';
        echo '</div>';
        echo '<table class="widefat striped"><thead><tr><th>Name</th><th>ID / Shortcode</th><th>Type</th><th>Status</th><th>Priority</th><th>Placements</th><th>Start</th><th>End</th><th></th></tr></thead><tbody>';
        foreach ( $ads as $ad ) {
            $placements = implode( ', ', OStudy_Ads_Utils::json_array( $ad->page_targets ) );
            $edit = admin_url( 'admin.php?page=ostudy-ads-edit&id=' . absint( $ad->id ) );
            $delete = wp_nonce_url( admin_url( 'admin.php?page=ostudy-ads&delete_ad=' . absint( $ad->id ) ), 'ostudy_ads_delete_' . absint( $ad->id ) );
            $status = OStudy_Ads_Utils::display_status( $ad );
            $id = absint( $ad->id );
            $shortcode = '[ostudy_ad id=' . $id . ']';
            echo '<tr><td><strong><a href="' . esc_url( $edit ) . '">' . esc_html( $ad->name ) . '</a></strong></td>';
            echo '<td><code>' . esc_html( $shortcode ) . '</code><br><button type="button" class="button-link ostudy-copy-shortcode" data-copy="' . esc_attr( $shortcode ) . '">Copy</button></td>';
            echo '<td>' . esc_html( ucfirst( $ad->ad_type ) ) . '</td><td>' . esc_html( $status ) . '</td><td>' . absint( $ad->priority ) . '</td><td>' . esc_html( $placements ) . '</td>';
            echo '<td>' . esc_html( OStudy_Ads_Utils::format_admin_datetime( $ad->start_at ) ) . '</td>';
            echo '<td>' . esc_html( OStudy_Ads_Utils::format_admin_datetime( $ad->end_at ) ) . '</td>';
            echo '<td><a href="' . esc_url( $edit ) . '">Edit</a> | <a href="' . esc_url( $delete ) . '" onclick="return confirm(\'Delete this ad?\')">Delete</a></td></tr>';
        }
        if ( empty( $ads ) ) echo '<tr><td colspan="9">No advertisements yet. Create an ad first, then its shortcode will appear here.</td></tr>';
        echo '</tbody></table></div>';
    }
    public function edit_page() {
        $id = absint( $_GET['id'] ?? 0 );
        $ad = $id ? OStudy_Ads_DB::get_ad( $id ) : null;
        $v = function( $key, $default = '' ) use ( $ad ) { return $ad ? $ad->$key : $default; };
        $targets = $ad ? OStudy_Ads_Utils::json_array( $ad->page_targets ) : array();
        $roles = $ad ? OStudy_Ads_Utils::json_array( $ad->roles ) : array();
        $devices = $ad ? OStudy_Ads_Utils::json_array( $ad->device_targets ) : array( 'desktop', 'tablet', 'mobile' );
        $cats = $ad ? OStudy_Ads_Utils::json_array( $ad->categories ) : array();
        $wilayas = $ad ? OStudy_Ads_Utils::json_array( $ad->wilayas ?? '' ) : array();
        echo '<div class="wrap ostudy-ads-admin"><h1>' . esc_html( $id ? 'Edit Advertisement' : 'Add Advertisement' ) . '</h1>';
        if ( isset( $_GET['from_order'] ) ) echo '<div class="notice notice-info"><p>This ad was created from an order and is <strong>paused</strong>. Check the targeting below, then set the order to <strong>Live</strong> in OStudy Ads &rarr; Orders to activate it.</p></div>';
        echo '<form method="post">';
        wp_nonce_field( 'ostudy_ads_save_ad' );
        echo '<input type="hidden" name="ad_id" value="' . absint( $id ) . '"><table class="form-table">';
        $this->row_text( 'name', 'Name', $v('name'), 'Internal campaign name' );
        $this->row_select( 'status', 'Status', array( 'active' => 'Active', 'paused' => 'Paused' ), $v('status','active') );
        $this->row_select( 'ad_type', 'Ad type', array( 'banner' => 'Banner', 'popup' => 'Popup', 'sidebar' => 'Sidebar', 'html' => 'Custom HTML' ), $v('ad_type','banner') );
        echo '</table><h2>Creative</h2><table class="form-table">';
        $this->media_row( 'image_id', 'image_url', 'Desktop image', $v('image_id'), $v('image_url') );
        $this->media_row( 'mobile_image_id', 'mobile_image_url', 'Mobile image (optional)', $v('mobile_image_id'), $v('mobile_image_url') );
        echo '<tr><th>Recommended image sizes</th><td><div class="ostudy-dimensions-box">';
        $this->render_dimensions_help( $targets );
        echo '</div><p class="description">Images are not required to match exactly. Larger images are scaled down without stretching. Smaller images are allowed but may look soft. The fit mode below controls cropping/empty space.</p></td></tr>';
        $this->row_select( 'fit_mode', 'Image fit', array( 'cover' => 'Cover — fill area and crop when needed', 'contain' => 'Contain — show the whole image', 'natural' => 'Natural — preserve image ratio/height' ), $v('fit_mode','cover') );
        $this->row_select( 'focal_x', 'Horizontal focus', array( '0'=>'Left','25'=>'25%','50'=>'Center','75'=>'75%','100'=>'Right' ), number_format( (float) $v('focal_x','50'), 0, '.', '' ) );
        $this->row_select( 'focal_y', 'Vertical focus', array( '0'=>'Top','25'=>'25%','50'=>'Center','75'=>'75%','100'=>'Bottom' ), number_format( (float) $v('focal_y','50'), 0, '.', '' ) );
        echo '<tr><th>Custom padding</th><td><div class="ostudy-padding-grid">';
        $this->row_number_inline( 'padding_top', 'Top', $v('padding_top','0') );
        $this->row_number_inline( 'padding_right', 'Right', $v('padding_right','0') );
        $this->row_number_inline( 'padding_bottom', 'Bottom', $v('padding_bottom','0') );
        $this->row_number_inline( 'padding_left', 'Left', $v('padding_left','0') );
        echo '</div><p class="description">Optional spacing in pixels around the ad. Useful when another profile plugin changes the available space or when the image needs a small manual offset. Use 0 for no extra padding.</p></td></tr>';
        echo '<tr><th>Focus behavior</th><td><p class="description">Focus is used when Image fit is <strong>Cover</strong>. It changes which part of a cropped image remains visible. Values and padding are saved per advertisement.</p></td></tr>';
        $this->row_text( 'destination_url', 'Destination URL', $v('destination_url'), 'Optional click URL' );
        $this->row_checkbox( 'open_new_tab', 'Open in new tab', ! empty( $v('open_new_tab') ) );
        $this->row_textarea( 'html_code', 'Custom HTML', $v('html_code'), 'Used only for Custom HTML type. Scripts are stripped by WordPress.' );
        echo '</table><h2>Targeting & rotation</h2><table class="form-table">';
        $this->row_checkboxes( 'page_targets[]', 'Placements', self::placements(), $targets, true );
        $this->row_checkboxes( 'roles[]', 'Audience roles', array( 'visitor' => 'Visitors', 'subscriber' => 'Students/Users', 'tutor' => 'Tutors', 'administrator' => 'Administrators' ), $roles, false );
        $this->row_checkboxes( 'device_targets[]', 'Devices', array( 'desktop' => 'Desktop', 'tablet' => 'Tablet', 'mobile' => 'Mobile' ), $devices, false );
        $this->row_text( 'categories_csv', 'Course category IDs', implode(',', array_map('absint',$cats)), 'Optional comma-separated Tutor LMS course-category term IDs.' );
        $this->row_text( 'wilayas_csv', 'Course Wilaya IDs', implode(',', array_map('strval',$wilayas)), "Optional comma-separated Wilaya codes from Tutor LMS Instructor Profile Extra (for example: 31,16). Matches the course instructor meta _tlms_ipe_wilaya value." );
        $this->row_datetime( 'start_at', 'Start date/time', $v('start_at'), 'Choose the date and time from the calendar. Uses your WordPress site timezone.' );
        $this->row_datetime( 'end_at', 'End date/time', $v('end_at'), 'Choose the date and time from the calendar. Leave blank for no end. Uses your WordPress site timezone.' );
        $this->row_text( 'priority', 'Priority', $v('priority',10), 'Lower number wins. Same priority uses weight.' );
        $this->row_text( 'weight', 'Rotation weight', $v('weight',10), 'Higher weight is selected more often among the same priority.' );
        $this->row_select( 'frequency', 'Frequency', array( 'always' => 'Every eligible page view', 'session' => 'Once per session', 'cooldown' => 'Cooldown' ), $v('frequency','session') );
        $this->row_text( 'cooldown_hours', 'Cooldown hours', $v('cooldown_hours',24), 'Used for Cooldown frequency.' );
        $this->row_text( 'delay_seconds', 'Popup delay (seconds)', $v('delay_seconds',12), 'Only used by Popup ads. Time before popup opens.' );
        $this->row_text( 'popup_close_delay', 'Popup close lock (seconds)', $v('popup_close_delay',5), 'Only used by Popup ads. Prevents the close button, backdrop, and Escape key until this timer finishes.' );
        $this->row_text( 'scroll_percent', 'Popup scroll trigger (%)', $v('scroll_percent',0), '0 disables scroll trigger.' );
        $this->row_text( 'max_impressions', 'Lifetime impression cap', $v('max_impressions',0), 'Optional. Automatically stops showing this ad once it reaches this many recorded impressions (checked against Analytics data). Use 0 for no cap.' );
        if ( $id && $v('max_impressions',0) ) {
            $seen = OStudy_Ads_DB::count_impressions( $id );
            echo '<tr><th>Impression cap progress</th><td>' . number_format_i18n( $seen ) . ' / ' . number_format_i18n( absint( $v('max_impressions',0) ) ) . '<p class="description">Impression counts can lag up to a minute behind live traffic.</p></td></tr>';
        }
        echo '</table>';
        echo '<div class="ostudy-shortcode-box"><h2>Shortcodes for this ad</h2>';
        if ( $id ) {
            $id_shortcode = '[ostudy_ad id=' . absint( $id ) . ']';
            echo '<p><strong>Specific ad (recommended for exact placement):</strong></p><p><code>' . esc_html( $id_shortcode ) . '</code> <button type="button" class="button-link ostudy-copy-shortcode" data-copy="' . esc_attr( $id_shortcode ) . '">Copy</button></p>';
            echo '<p><strong>With zone styling:</strong></p><p><code>[ostudy_ad id=' . absint( $id ) . ' zone=home_top]</code></p>';
            echo '<p class="description">Replace <code>home_top</code> with the zone you need. The shortcode location always determines where the ad is rendered.</p>';
            echo '<p><strong>Respect automatic page targeting too:</strong></p><p><code>[ostudy_ad id=' . absint( $id ) . ' zone=home_top respect_page_target=1]</code></p>';
            echo '<p class="description">Use this only when you want the ad to appear via shortcode <em>and</em> require its selected automatic page target to match.</p>';
        } else {
            echo '<p>Save the ad first. After it receives an ID, the exact shortcode will be shown here and on <strong>OStudy Ads → All Ads</strong>.</p>';
            echo '<p><code>[ostudy_ad id=123]</code></p>';
        }
        echo '<h3>Available zone examples</h3><p><code>home_top</code> <code>course_search_top</code> <code>tutor_search_top</code> <code>instructor_profile_top</code> <code>course_page_top</code> <code>dashboard_sidebar</code> <code>focus_mode_top</code></p>';
        echo '<p class="description">Popup ads remain popups when their ad type is Popup. Sidebar ads use their sidebar styling. Shortcodes do not connect to or modify your existing course-sponsorship system.</p></div>';
        echo '<p class="submit"><button class="button button-primary" type="submit" name="ostudy_ads_save" value="1">Save Advertisement</button></p></form></div>';
    }
    public function analytics_page() {
        global $wpdb;
        $rows = $wpdb->get_results( 'SELECT ad_id, SUM(stat_type="impression") impressions, SUM(stat_type="click") clicks FROM ' . OStudy_Ads_DB::stats_table() . ' GROUP BY ad_id ORDER BY impressions DESC LIMIT 200' );
        // Fetch every referenced ad in one query instead of one query per row.
        $ads_by_id = OStudy_Ads_DB::get_ads_by_ids( wp_list_pluck( $rows, 'ad_id' ) );
        echo '<div class="wrap ostudy-ads-admin"><h1>Ad Analytics</h1><table class="widefat striped"><thead><tr><th>Ad</th><th>Impressions</th><th>Clicks</th><th>CTR</th><th>Impression cap</th></tr></thead><tbody>';
        foreach ( $rows as $row ) {
            $ad = $ads_by_id[ (int) $row->ad_id ] ?? null;
            $impressions = absint( $row->impressions ); $clicks = absint( $row->clicks ); $ctr = $impressions ? number_format_i18n( ( $clicks / $impressions ) * 100, 2 ) . '%' : '0%';
            $cap = $ad ? absint( $ad->max_impressions ?? 0 ) : 0;
            $cap_text = $cap ? number_format_i18n( $impressions ) . ' / ' . number_format_i18n( $cap ) . ( $impressions >= $cap ? ' (reached)' : '' ) : '—';
            echo '<tr><td>' . esc_html( $ad ? $ad->name : ('#' . $row->ad_id) ) . '</td><td>' . number_format_i18n($impressions) . '</td><td>' . number_format_i18n($clicks) . '</td><td>' . esc_html($ctr) . '</td><td>' . esc_html($cap_text) . '</td></tr>';
        }
        if ( empty($rows) ) echo '<tr><td colspan="5">No tracking data yet.</td></tr>';
        echo '</tbody></table></div>';
    }
    public function settings_page() {
        $s = OStudy_Ads_DB::get_settings();
        echo '<div class="wrap ostudy-ads-admin"><h1>OStudy Ads Settings</h1><form method="post" action="options.php">';
        settings_fields('ostudy_ads_settings_group');
        echo '<table class="form-table">';
        $this->settings_checkbox('enabled','Enable advertising',!empty($s['enabled']));
        $this->settings_checkbox('auto_render','Automatic placement rendering',!empty($s['auto_render']));
        $this->settings_checkbox('hide_for_admins','Hide ads for administrators unless explicitly targeted',!empty($s['hide_for_admins']));
        $this->settings_checkbox('popup_mobile','Allow popup ads on mobile',!empty($s['popup_mobile']));
        $this->settings_checkbox('focus_mode_enabled','Enable Focus Mode top banner/popup',!empty($s['focus_mode_enabled']));
        $this->settings_checkbox('focus_mode_side_enabled','Enable Focus Mode side ads',!empty($s['focus_mode_side_enabled']));
        echo '</table><h2>Ad orders</h2><p class="description">Let visitors and guests request an ad with <code>[ostudy_ad_order]</code>. Guests receive an order number to follow their request.</p><table class="form-table">';
        $this->settings_checkbox('orders_enabled','Accept ad orders',!empty($s['orders_enabled']));
        $this->settings_text('order_page_url','Order page URL (page containing [ostudy_ad_order])',$s['order_page_url']);
        $this->settings_text('order_notify_email','Notify this email about new orders (blank = site admin email)',$s['order_notify_email']);
        $this->settings_text('order_accent_color','Order form accent color (hex)',$s['order_accent_color']);
        $this->settings_text('order_max_upload_mb','Max creative / receipt upload (MB, 1-10)',$s['order_max_upload_mb']);
        echo '<tr><th><label for="ostudy-setting-payment_instructions">Default payment instructions</label></th><td><textarea class="large-text" rows="7" id="ostudy-setting-payment_instructions" name="ostudy_ads_settings[payment_instructions]" placeholder="Bank / CCP / BaridiMob account, phone-cash number, name, etc.">' . esc_textarea( $s['payment_instructions'] ) . '</textarea><p class="description">Shown to customers whenever their order is <strong>Awaiting payment</strong> (and included in the status email), together with the amount due you set on the order and a form to upload their payment proof. Line breaks are kept.</p></td></tr>';
        echo '</table><h2>Pricing, availability &amp; reminders</h2><p class="description">Optional. With prices set, the order form shows a live total and fills &ldquo;Amount due&rdquo; automatically. Leave every price empty to keep quotes manual.</p><table class="form-table">';
        $this->settings_text('order_currency','Currency label (e.g. DZD)',$s['order_currency']);
        echo '<tr><th>Price per week, per placement</th><td><table class="ostudy-price-grid"><tbody>';
        foreach ( OStudy_Ads_Orders::placement_choices() as $zone => $choice ) {
            $val = isset( $s['order_prices'][ $zone ] ) ? $s['order_prices'][ $zone ] : '';
            echo '<tr><td>' . esc_html( $choice[1] ) . ' <small>(' . esc_html( $choice[0] ) . ')</small></td><td><input type="number" min="0" step="any" class="small-text" name="ostudy_ads_settings[order_prices][' . esc_attr( $zone ) . ']" value="' . esc_attr( $val ) . '"></td></tr>';
        }
        echo '</tbody></table><p class="description">Price for 7 days. Longer runs are prorated (30 days = 30/7 weeks) before the discounts below.</p></td></tr>';
        echo '<tr><th>Long-booking discounts (%)</th><td>';
        foreach ( array( 30 => '30+ days', 60 => '60+ days', 90 => '90+ days' ) as $days => $label ) {
            $val = isset( $s['order_discounts'][ $days ] ) ? $s['order_discounts'][ $days ] : 0;
            echo '<label style="margin-right:14px">' . esc_html( $label ) . ' <input type="number" min="0" max="90" step="0.5" class="small-text" name="ostudy_ads_settings[order_discounts][' . (int) $days . ']" value="' . esc_attr( $val ) . '"></label>';
        }
        echo '</td></tr>';
        echo '<tr><th><label for="ostudy-setting-order_max_per_placement">Max ads per placement at the same time</label></th><td><input type="number" min="0" max="50" class="small-text" id="ostudy-setting-order_max_per_placement" name="ostudy_ads_settings[order_max_per_placement]" value="' . esc_attr( (int) $s['order_max_per_placement'] ) . '"><p class="description">0 = unlimited. Orders that are Awaiting payment, Under review or Live reserve a slot for their dates; the form refuses dates that are full.</p></td></tr>';
        $this->settings_checkbox('order_enforce_sizes','Require image sizes that fit the placement',!empty($s['order_enforce_sizes']));
        $this->settings_checkbox('order_reminders','E-mail advertisers 7 and 3 days before their ad ends',!empty($s['order_reminders']));
        echo '</table><h2>Page detection</h2><table class="form-table">';
        $this->settings_text('dashboard_path','Dashboard path',$s['dashboard_path']);
        $this->settings_text('tutors_path','Instructor search path',$s['tutors_path']);
        $this->settings_text('courses_path','Course search path',$s['courses_path']);
        $this->settings_text('profile_path','Instructor profile base path',$s['profile_path']);
        echo '</table><h2>Automatic placement selectors</h2><p class="description">Ads are initially created at the end of the HTML document, hidden, then moved into these containers by the placement engine. This prevents banners from appearing underneath the entire page because of theme/Tutor hook differences. Leave the defaults unless your theme uses a custom wrapper.</p><table class="form-table">';
        $this->settings_textarea('selector_home_top','Home top banner target',$s['selector_home_top'],'CSS selector list. The first matching element is used; banner is inserted before it.');
        $this->settings_textarea('selector_course_search_top','Course search banner target',$s['selector_course_search_top'],'First matching course-list/container element.');
        $this->settings_textarea('selector_tutor_search_top','Instructor search (/tutors) banner target',$s['selector_tutor_search_top'],'Your custom /tutors page target.');
        $this->settings_textarea('selector_instructor_profile_top','Instructor public profile banner target',$s['selector_instructor_profile_top'],'Profile content container.');
        $this->settings_textarea('selector_instructor_profile_side','Instructor public profile side-ad alignment target',$s['selector_instructor_profile_side'],'Tutor LMS 4.x public-profile sidebar column. The side ad is appended inside this real sidebar.');
        echo '<tr><th>Instructor profile side placement</th><td><strong>Tutor LMS 4.x reserved profile rail</strong><p class="description">Tutor LMS 4.x creates an empty profile rail with CSS floats rather than a functional sidebar. The ad is inserted into the shared profile container and positioned in that reserved 278px rail so it cannot push the biography/courses block above or below the ad.</p></td></tr>';
        $this->settings_textarea('selector_course_page_top','Public course banner target',$s['selector_course_page_top'],'Public course content container.');
        $this->settings_textarea('selector_dashboard_sidebar','Dashboard sidebar ad target',$s['selector_dashboard_sidebar'],'Sidebar/menu container. Ad is appended inside it.');
        $this->settings_textarea('selector_focus_mode_top','Focus Mode top banner target',$s['selector_focus_mode_top'],'Focus-mode lesson content container.');
        $this->settings_textarea('selector_focus_mode_side','Focus Mode side ad target',$s['selector_focus_mode_side'],'Course sidebar container.');
        echo '</table><p class="description"><strong>Audience note:</strong> Tutor LMS instructors use the <code>tutor_instructor</code> role internally; selecting <strong>Tutors</strong> now matches that Tutor role. Administrators selected on an ad are shown even when the global administrator-hide setting is enabled.</p><p class="description"><strong>Important:</strong> Top banners use <em>before</em>; dashboard/focus side ads use <em>inside</em>. Popups are always fixed to the viewport and are not moved into page content.</p><p class="description">Your instructor search remains explicitly configured as <code>/tutors</code>. The automatic renderer never modifies Tutor LMS core templates or the existing sponsorship system.</p>'; submit_button(); echo '</form></div>';
    }
    private static function placements() {
        return array(
            'home_top'=>'Home top banner','home_popup'=>'Home popup',
            'course_search_top'=>'Course search top banner','course_search_popup'=>'Course search popup',
            'tutor_search_top'=>'Instructor search top banner','tutor_search_popup'=>'Instructor search popup',
            'instructor_profile_top'=>'Instructor profile top banner','instructor_profile_side'=>'Instructor profile side ad','instructor_profile_popup'=>'Instructor profile popup',
            'course_page_top'=>'Public course top banner','course_page_popup'=>'Public course popup',
            'dashboard_sidebar'=>'Dashboard sidebar','dashboard_popup'=>'Dashboard popup',
            'focus_mode_top'=>'Focus mode top banner','focus_mode_side'=>'Focus mode side','focus_mode_popup'=>'Focus mode popup',
        );
    }
    private function render_dimensions_help( $targets ) {
        $dimensions = OStudy_Ads_Utils::recommended_dimensions();
        $seen = array();
        foreach ( (array) $targets as $target ) {
            if ( isset( $dimensions[ $target ] ) ) {
                $seen[ $target ] = $dimensions[ $target ];
            }
        }
        if ( empty( $seen ) ) {
            echo '<p><strong>Select a placement</strong> to see its recommended size.</p>';
            return;
        }
        echo '<table class="widefat striped ostudy-size-table"><thead><tr><th>Placement</th><th>Desktop</th><th>Mobile</th></tr></thead><tbody>';
        foreach ( $seen as $target => $size ) {
            echo '<tr><td>' . esc_html( ucwords( str_replace( '_', ' ', $target ) ) ) . '</td><td>' . esc_html( $size['desktop'] ) . '</td><td>' . esc_html( $size['mobile'] ) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p class="description"><strong>File size guidance:</strong> banner ≤ 500 KB, popup ≤ 700 KB, sidebar ≤ 350 KB. Larger files are allowed, but the plugin warns you because they can slow page loading.</p>';
    }

    private function row_text($name,$label,$value,$desc='') { echo '<tr><th><label for="ostudy-' . esc_attr($name) . '">' . esc_html($label) . '</label></th><td><input class="regular-text" id="ostudy-' . esc_attr($name) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">'; if($desc) echo '<p class="description">'.esc_html($desc).'</p>'; echo '</td></tr>'; }
    private function row_datetime($name,$label,$value,$desc='') {
        $input_value = '';
        if ( ! empty( $value ) ) {
            $timestamp = strtotime( $value . ' UTC' );
            if ( $timestamp ) {
                $input_value = wp_date( 'Y-m-d\TH:i', $timestamp, wp_timezone() );
            }
        }
        echo '<tr><th><label for="ostudy-' . esc_attr($name) . '">' . esc_html($label) . '</label></th><td><input type="datetime-local" id="ostudy-' . esc_attr($name) . '" name="' . esc_attr($name) . '" value="' . esc_attr($input_value) . '" step="60">';
        echo '<p class="description">' . esc_html($desc) . '</p></td></tr>';
    }
    private function row_select($name,$label,$options,$value) { echo '<tr><th>'.esc_html($label).'</th><td><select name="'.esc_attr($name).'">'; foreach($options as $k=>$v) echo '<option value="'.esc_attr($k).'" '.selected($value,$k,false).'>'.esc_html($v).'</option>'; echo '</select></td></tr>'; }
    private function row_number_inline($name,$label,$value) { echo '<label style="display:inline-flex;align-items:center;gap:6px;margin:0 10px 8px 0;"><span>'.esc_html($label).'</span><input type="number" min="0" max="500" step="1" name="'.esc_attr($name).'" value="'.esc_attr(max(0,(int)$value)).'" style="width:90px"> <span>px</span></label>'; }
    private function row_checkbox($name,$label,$checked) { echo '<tr><th>'.esc_html($label).'</th><td><label><input type="checkbox" name="'.esc_attr($name).'" value="1" '.checked($checked,true,false).'> Yes</label></td></tr>'; }
    private function row_checkboxes($name,$label,$options,$checked,$wide) { echo '<tr><th>'.esc_html($label).'</th><td><div class="ostudy-check-grid">'; foreach($options as $k=>$v) echo '<label><input type="checkbox" name="'.esc_attr($name).'" value="'.esc_attr($k).'" '.checked(in_array($k,$checked,true),true,false).'> '.esc_html($v).'</label>'; echo '</div></td></tr>'; }
    private function row_textarea($name,$label,$value,$desc='') { echo '<tr><th>'.esc_html($label).'</th><td><textarea rows="6" class="large-text code" name="'.esc_attr($name).'">'.esc_textarea($value).'</textarea><p class="description">'.esc_html($desc).'</p></td></tr>'; }
    private function media_row($idName,$urlName,$label,$id,$url) {
        $meta = $id ? wp_get_attachment_metadata( absint( $id ) ) : array();
        $width = ! empty( $meta['width'] ) ? absint( $meta['width'] ) : 0;
        $height = ! empty( $meta['height'] ) ? absint( $meta['height'] ) : 0;
        $file_path = $id ? get_attached_file( absint( $id ) ) : '';
        $filesize = ( $file_path && file_exists( $file_path ) ) ? (int) filesize( $file_path ) : 0;
        $meta_text = ( $width && $height ) ? $width . ' × ' . $height . ' px' : '';
        if ( $filesize ) $meta_text .= ( $meta_text ? ' • ' : '' ) . size_format( $filesize, 1 );
        echo '<tr><th>'.esc_html($label).'</th><td><input type="hidden" class="ostudy-media-id" name="'.esc_attr($idName).'" value="'.absint($id).'"><input type="url" class="regular-text ostudy-media-url" name="'.esc_attr($urlName).'" value="'.esc_attr($url).'"><button type="button" class="button ostudy-media-pick">Choose image</button><div class="ostudy-media-meta">'.esc_html($meta_text).'</div><div class="ostudy-media-warning"></div><div class="ostudy-media-preview">'.($url?'<img src="'.esc_url($url).'" alt="">':'').'</div></td></tr>';
    }
    private function settings_checkbox($name,$label,$checked) { echo '<tr><th>'.esc_html($label).'</th><td><label><input type="checkbox" name="ostudy_ads_settings['.esc_attr($name).']" value="1" '.checked($checked,true,false).'> Enabled</label></td></tr>'; }
    private function settings_text($name,$label,$value) { echo '<tr><th>'.esc_html($label).'</th><td><input class="regular-text" name="ostudy_ads_settings['.esc_attr($name).']" value="'.esc_attr($value).'"></td></tr>'; }
    private function settings_textarea($name,$label,$value,$desc='') { echo '<tr><th><label for="ostudy-setting-'.esc_attr($name).'">'.esc_html($label).'</label></th><td><textarea class="large-text code" rows="2" id="ostudy-setting-'.esc_attr($name).'" name="ostudy_ads_settings['.esc_attr($name).']">'.esc_textarea($value).'</textarea><p class="description">'.esc_html($desc).'</p></td></tr>'; }
    private function settings_select($name,$label,$value,$options,$desc='') { echo '<tr><th><label for="ostudy-setting-'.esc_attr($name).'">'.esc_html($label).'</label></th><td><select id="ostudy-setting-'.esc_attr($name).'" name="ostudy_ads_settings['.esc_attr($name).']">'; foreach ((array)$options as $k=>$v) echo '<option value="'.esc_attr($k).'" '.selected($value,$k,false).'>'.esc_html($v).'</option>'; echo '</select>'; if($desc) echo '<p class="description">'.esc_html($desc).'</p>'; echo '</td></tr>'; }
}
