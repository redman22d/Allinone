<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class OStudy_Ads_DB {
    const ADS_TABLE = 'ostudy_ads';
    const STATS_TABLE = 'ostudy_ads_stats';
    const DB_VERSION = '1.7.0';

    public static function ads_table() {
        global $wpdb;
        return $wpdb->prefix . self::ADS_TABLE;
    }

    public static function stats_table() {
        global $wpdb;
        return $wpdb->prefix . self::STATS_TABLE;
    }

    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        $sql1 = "CREATE TABLE " . self::ads_table() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(190) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            ad_type varchar(30) NOT NULL DEFAULT 'banner',
            image_id bigint(20) unsigned NOT NULL DEFAULT 0,
            image_url text NULL,
            mobile_image_id bigint(20) unsigned NOT NULL DEFAULT 0,
            mobile_image_url text NULL,
            destination_url text NULL,
            html_code longtext NULL,
            open_new_tab tinyint(1) NOT NULL DEFAULT 0,
            start_at datetime NULL,
            end_at datetime NULL,
            priority int(11) NOT NULL DEFAULT 10,
            weight int(11) NOT NULL DEFAULT 10,
            page_targets longtext NULL,
            roles longtext NULL,
            device_targets longtext NULL,
            categories longtext NULL,
            wilayas longtext NULL,
            frequency varchar(30) NOT NULL DEFAULT 'session',
            cooldown_hours int(11) NOT NULL DEFAULT 24,
            delay_seconds decimal(6,2) NOT NULL DEFAULT 12,
            popup_close_delay decimal(6,2) NOT NULL DEFAULT 5,
            scroll_percent int(11) NOT NULL DEFAULT 0,
            fit_mode varchar(20) NOT NULL DEFAULT 'cover',
            focal_x decimal(5,2) NOT NULL DEFAULT 50,
            focal_y decimal(5,2) NOT NULL DEFAULT 50,
            padding_top int(11) unsigned NOT NULL DEFAULT 0,
            padding_right int(11) unsigned NOT NULL DEFAULT 0,
            padding_bottom int(11) unsigned NOT NULL DEFAULT 0,
            padding_left int(11) unsigned NOT NULL DEFAULT 0,
            max_impressions bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY status_priority (status, priority),
            KEY dates (start_at, end_at)
        ) $charset;";

        $sql2 = "CREATE TABLE " . self::stats_table() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            ad_id bigint(20) unsigned NOT NULL,
            stat_type varchar(20) NOT NULL,
            user_hash char(64) NULL,
            placement varchar(60) NULL,
            device varchar(20) NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY ad_type_date (ad_id, stat_type, created_at),
            KEY user_hash (user_hash)
        ) $charset;";

        dbDelta( $sql1 );
        dbDelta( $sql2 );
        dbDelta( OStudy_Ads_Orders::schema_sql() );

        if ( ! get_option( 'ostudy_ads_settings' ) ) {
            add_option( 'ostudy_ads_settings', OStudy_Ads_Utils::default_settings() );
        }
        update_option( 'ostudy_ads_db_version', self::DB_VERSION );
    }

    public static function maybe_upgrade() {
        $version = (string) get_option( 'ostudy_ads_db_version', '0' );
        if ( version_compare( $version, self::DB_VERSION, '<' ) ) {
            self::activate();
            self::migrate_settings_144();
            self::migrate_settings_147();
            return;
        }
        // A plugin-file-only update (files replaced via FTP/host file manager without
        // deactivate/reactivate) never fires register_activation_hook, so a column
        // added in a later package can still be missing even though the stored
        // version option already matches. Only pay for that self-healing SHOW COLUMNS
        // check in wp-admin - never on public page loads, which is where this query
        // used to run on every single front-end request for no benefit.
        if ( is_admin() ) {
            self::ensure_schema_columns();
        }
    }


    private static function ensure_schema_columns() {
        global $wpdb;
        $table = self::ads_table();
        $columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
        if ( ! $columns ) return;

        $missing = array();
        if ( ! in_array( 'focal_x', $columns, true ) ) $missing[] = "ADD COLUMN focal_x decimal(5,2) NOT NULL DEFAULT 50";
        if ( ! in_array( 'focal_y', $columns, true ) ) $missing[] = "ADD COLUMN focal_y decimal(5,2) NOT NULL DEFAULT 50";
        if ( ! in_array( 'fit_mode', $columns, true ) ) $missing[] = "ADD COLUMN fit_mode varchar(20) NOT NULL DEFAULT 'cover'";
        if ( ! in_array( 'padding_top', $columns, true ) ) $missing[] = "ADD COLUMN padding_top int(11) unsigned NOT NULL DEFAULT 0";
        if ( ! in_array( 'padding_right', $columns, true ) ) $missing[] = "ADD COLUMN padding_right int(11) unsigned NOT NULL DEFAULT 0";
        if ( ! in_array( 'padding_bottom', $columns, true ) ) $missing[] = "ADD COLUMN padding_bottom int(11) unsigned NOT NULL DEFAULT 0";
        if ( ! in_array( 'padding_left', $columns, true ) ) $missing[] = "ADD COLUMN padding_left int(11) unsigned NOT NULL DEFAULT 0";
        if ( ! in_array( 'wilayas', $columns, true ) ) $missing[] = "ADD COLUMN wilayas longtext NULL";
        if ( ! in_array( 'popup_close_delay', $columns, true ) ) $missing[] = "ADD COLUMN popup_close_delay decimal(6,2) NOT NULL DEFAULT 5";
        if ( $missing ) {
            foreach ( $missing as $alter ) {
                $wpdb->query( "ALTER TABLE {$table} {$alter}" );
            }
        }

        // The order table is separate from the ads table. Keep this check for
        // file-only updates where the activation hook is not run by WordPress.
        $orders_table = $wpdb->prefix . 'ostudy_ads_orders';
        $order_columns = $wpdb->get_col( "SHOW COLUMNS FROM {$orders_table}", 0 );
        if ( $order_columns && ! in_array( 'payment_proof_path', $order_columns, true ) ) {
            $wpdb->query( "ALTER TABLE {$orders_table} ADD COLUMN payment_proof_path text NULL AFTER payment_proof_id" );
        }
    }

    private static function migrate_settings_144() {
        $settings = self::get_settings();
        $changed  = false;
        // Replace the old generic profile selectors with Tutor LMS 4.0.8's actual
        // public-profile containers. Only replace values from the plugin's old defaults,
        // so a site owner who deliberately customized the selectors is not overwritten.
        $old = array(
            'selector_instructor_profile_top'  => '.tutor-user-profile, .tutor-profile, main, .site-main, #main, .content-area',
            'selector_instructor_profile_side' => '.tutor-user-profile, .tutor-profile, main, .site-main, #main, .content-area',
            'selector_course_search_top'       => '.tutor-course-archive, .tutor-courses, .tutor-course-list, main, .site-main, #main, .content-area',
            'selector_course_page_top'         => '.tutor-course-details-page, .tutor-course-single-page, .tutor-course-details, main, .site-main, #main, .content-area',
        );
        $new = array(
            'selector_instructor_profile_top'  => '.tutor-user-public-profile .tutor-user-profile-content, main, .site-main, #main, .content-area',
            'selector_instructor_profile_side' => '.tutor-user-public-profile .tutor-user-profile-sidebar',
            'selector_course_search_top'       => '.course-archive-page .tutor-row, .tutor-courses-wrap .tutor-row, .tutor-pagination-wrapper-replaceable, main, .site-main, #main, .content-area',
            'selector_course_page_top'         => '.tutor-course-details-page .tutor-row, .tutor-course-details-page, .tutor-course-single-page, main, .site-main, #main, .content-area',
        );
        foreach ( $old as $key => $value ) {
            if ( isset( $settings[ $key ] ) && $settings[ $key ] === $value ) {
                $settings[ $key ] = $new[ $key ];
                $changed = true;
            }
        }
        if ( $changed ) {
            update_option( 'ostudy_ads_settings', $settings );
        }
    }


    private static function migrate_settings_147() {
        $settings = self::get_settings();
        $changed  = false;
        $replacements = array(
            'selector_course_search_top' => array(
                '.course-archive-page .tutor-row, .tutor-courses-wrap .tutor-row, .tutor-pagination-wrapper-replaceable, main, .site-main, #main, .content-area',
                '.course-archive-page > .tutor-row, .course-archive-page',
            ),
            'selector_tutor_search_top' => array(
                '.tutor-search-results, .tutor-instructor-list-wrapper, main, .site-main, #main, .content-area',
                '.tutor-instructor-list-wrapper, .tutor-instructor-list, main, .site-main, #main, .content-area',
            ),
            'selector_instructor_profile_top' => array(
                '.tutor-user-public-profile .tutor-user-profile-content, main, .site-main, #main, .content-area',
                '.tutor-user-public-profile .photo-area + .tutor-container',
            ),
            'selector_instructor_profile_side' => array(
                '.tutor-user-profile, .tutor-profile, main, .site-main, #main, .content-area',
                '.tutor-user-public-profile .tutor-user-profile-sidebar',
            ),
            'selector_course_page_top' => array(
                '.tutor-course-details-page .tutor-row, .tutor-course-details-page, .tutor-course-single-page, main, .site-main, #main, .content-area',
                '.tutor-course-details-page > .tutor-row, .tutor-course-details-page',
            ),
            'selector_dashboard_sidebar' => array(
                '.tutor-dashboard-left-menu, .tutor-dashboard-sidebar, .tutor-dashboard-menu, .tutor-dashboard .tutor-sidebar, aside',
                '.tutor-dashboard-sidebar',
            ),
            'selector_focus_mode_side' => array(
                '.tutor-single-course-sidebar, .tutor-course-single-sidebar, .tutor-single-course-sidebar-more, .tutor-course-sidebar, aside',
                '.tutor-single-course-sidebar, .tutor-course-single-sidebar, .tutor-single-course-sidebar-more, .tutor-course-sidebar',
            ),
        );
        foreach ( $replacements as $key => $pair ) {
            if ( isset( $settings[ $key ] ) && $settings[ $key ] === $pair[0] ) {
                $settings[ $key ] = $pair[1];
                $changed = true;
            }
        }
        if ( $changed ) update_option( 'ostudy_ads_settings', $settings );
    }

    public static function deactivate() {}

    public static function get_settings() {
        $settings = get_option( 'ostudy_ads_settings', array() );
        return wp_parse_args( is_array( $settings ) ? $settings : array(), OStudy_Ads_Utils::default_settings() );
    }

    public static function get_ad( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::ads_table() . ' WHERE id = %d', absint( $id ) ) );
    }

    public static function get_ads( $args = array() ) {
        global $wpdb;
        $args = wp_parse_args( $args, array(
            'status' => 'active',
            'orderby' => 'priority ASC, weight DESC, id DESC',
            'limit' => 200,
        ) );

        // Each zone rendered on a page (course_page_top, course_page_popup, etc.)
        // independently calls get_ads() with the same default args. Memoize per
        // request so a page with several zones issues one query instead of one
        // per zone.
        static $cache = array();
        $cache_key = md5( wp_json_encode( $args ) );
        if ( array_key_exists( $cache_key, $cache ) ) return $cache[ $cache_key ];

        $where = '1=1';
        $params = array();
        if ( $args['status'] ) {
            $where .= ' AND status = %s';
            $params[] = $args['status'];
        }
        $limit = absint( $args['limit'] );
        $safe_orderby = sanitize_sql_orderby( $args['orderby'] );
        if ( ! $safe_orderby ) {
            $safe_orderby = 'priority ASC, weight DESC, id DESC';
        }
        $sql = 'SELECT * FROM ' . self::ads_table() . ' WHERE ' . $where . ' ORDER BY ' . $safe_orderby . ' LIMIT %d';
        $params[] = $limit;
        $results = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
        $cache[ $cache_key ] = $results;
        return $results;
    }

    /**
     * Count recorded impressions for an ad, used to enforce a lifetime impression
     * cap. Cached briefly so a capped campaign doesn't add a COUNT query to every
     * page view; the cap still closes within a minute of being reached.
     */
    public static function count_impressions( $ad_id ) {
        global $wpdb;
        $ad_id = absint( $ad_id );
        if ( ! $ad_id ) return 0;
        $cache_key = 'ostudy_ads_impr_' . $ad_id;
        $cached = get_transient( $cache_key );
        if ( false !== $cached ) return (int) $cached;
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::stats_table() . ' WHERE ad_id = %d AND stat_type = %s',
            $ad_id, 'impression'
        ) );
        set_transient( $cache_key, $count, 60 );
        return $count;
    }

    public static function save_ad( $data, $id = 0 ) {
        global $wpdb;
        $now = current_time( 'mysql' );
        $row = array(
            'name' => sanitize_text_field( $data['name'] ?? '' ),
            'status' => in_array( $data['status'] ?? 'active', array( 'active', 'paused' ), true ) ? $data['status'] : 'active',
            'ad_type' => in_array( $data['ad_type'] ?? 'banner', array( 'banner', 'popup', 'sidebar', 'html' ), true ) ? $data['ad_type'] : 'banner',
            'image_id' => absint( $data['image_id'] ?? 0 ),
            'image_url' => esc_url_raw( $data['image_url'] ?? '' ),
            'mobile_image_id' => absint( $data['mobile_image_id'] ?? 0 ),
            'mobile_image_url' => esc_url_raw( $data['mobile_image_url'] ?? '' ),
            'destination_url' => esc_url_raw( $data['destination_url'] ?? '' ),
            'html_code' => wp_kses_post( $data['html_code'] ?? '' ),
            'open_new_tab' => empty( $data['open_new_tab'] ) ? 0 : 1,
            'start_at' => OStudy_Ads_Utils::mysql_datetime_or_null( $data['start_at'] ?? '' ),
            'end_at' => OStudy_Ads_Utils::mysql_datetime_or_null( $data['end_at'] ?? '' ),
            'priority' => max( 1, absint( $data['priority'] ?? 10 ) ),
            'weight' => max( 1, absint( $data['weight'] ?? 10 ) ),
            'page_targets' => wp_json_encode( array_values( array_filter( (array) ( $data['page_targets'] ?? array() ) ) ) ),
            'roles' => wp_json_encode( array_values( array_filter( (array) ( $data['roles'] ?? array() ) ) ) ),
            'device_targets' => wp_json_encode( array_values( array_filter( (array) ( $data['device_targets'] ?? array() ) ) ) ),
            'categories' => wp_json_encode( array_values( array_filter( (array) ( $data['categories'] ?? array() ) ) ) ),
            'wilayas' => wp_json_encode( array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $data['wilayas'] ?? array() ) ) ) ) ),
            'frequency' => sanitize_key( $data['frequency'] ?? 'session' ),
            'cooldown_hours' => max( 0, absint( $data['cooldown_hours'] ?? 24 ) ),
            'delay_seconds' => max( 0, (float) ( $data['delay_seconds'] ?? 12 ) ),
            'popup_close_delay' => min( 120, max( 0, (float) ( $data['popup_close_delay'] ?? 5 ) ) ),
            'scroll_percent' => min( 100, max( 0, absint( $data['scroll_percent'] ?? 0 ) ) ),
            'fit_mode' => in_array( $data['fit_mode'] ?? 'cover', array( 'cover', 'contain', 'natural' ), true ) ? $data['fit_mode'] : 'cover',
            'focal_x' => round( min( 100, max( 0, (float) ( $data['focal_x'] ?? 50 ) ) ), 2 ),
            'focal_y' => round( min( 100, max( 0, (float) ( $data['focal_y'] ?? 50 ) ) ), 2 ),
            'padding_top' => min( 500, max( 0, absint( $data['padding_top'] ?? 0 ) ) ),
            'padding_right' => min( 500, max( 0, absint( $data['padding_right'] ?? 0 ) ) ),
            'padding_bottom' => min( 500, max( 0, absint( $data['padding_bottom'] ?? 0 ) ) ),
            'padding_left' => min( 500, max( 0, absint( $data['padding_left'] ?? 0 ) ) ),
            'max_impressions' => absint( $data['max_impressions'] ?? 0 ),
            'updated_at' => $now,
        );
        if ( $id ) {
            $wpdb->update( self::ads_table(), $row, array( 'id' => absint( $id ) ) );
            return absint( $id );
        }
        $row['created_at'] = $now;
        $wpdb->insert( self::ads_table(), $row );
        return absint( $wpdb->insert_id );
    }

    public static function get_ads_by_ids( $ids ) {
        global $wpdb;
        $ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );
        if ( ! $ids ) return array();
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM ' . self::ads_table() . " WHERE id IN ({$placeholders})",
            $ids
        ) );
        $by_id = array();
        foreach ( $rows as $row ) $by_id[ (int) $row->id ] = $row;
        return $by_id;
    }

    public static function delete_ad( $id ) {
        global $wpdb;
        return $wpdb->delete( self::ads_table(), array( 'id' => absint( $id ) ), array( '%d' ) );
    }
}
