<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class OStudy_Ads_Tracking {
    private static $instance;
    public static function instance() {
        if ( ! self::$instance ) self::$instance = new self();
        return self::$instance;
    }
    private function __construct() {
        add_action( 'wp_ajax_ostudy_ads_track', array( $this, 'ajax_track' ) );
        add_action( 'wp_ajax_nopriv_ostudy_ads_track', array( $this, 'ajax_track' ) );
    }

    private static function valid_render_token( $token, $ad_id, $placement ) {
        if ( ! is_string( $token ) || 1 !== substr_count( $token, '.' ) ) return false;
        list( $encoded, $signature ) = explode( '.', $token, 2 );
        if ( '' === $encoded || ! preg_match( '/^[a-f0-9]{64}$/', $signature ) ) return false;
        $expected = hash_hmac( 'sha256', $encoded, wp_salt( 'auth' ) );
        if ( ! hash_equals( $expected, $signature ) ) return false;
        $base64 = strtr( $encoded, '-_', '+/' );
        $base64 .= str_repeat( '=', ( 4 - ( strlen( $base64 ) % 4 ) ) % 4 );
        $json = base64_decode( $base64, true );
        $payload = is_string( $json ) ? json_decode( $json, true ) : null;
        if ( ! is_array( $payload )
            || absint( $payload['ad_id'] ?? 0 ) !== absint( $ad_id )
            || sanitize_key( $payload['placement'] ?? '' ) !== sanitize_key( $placement )
            || absint( $payload['expires'] ?? 0 ) < time()
            || absint( $payload['expires'] ?? 0 ) > time() + 600
            || ! preg_match( '/^[a-f0-9]{32}$/', (string) ( $payload['nonce'] ?? '' ) ) ) {
            return false;
        }
        $render = get_transient( 'ostudy_ads_render_' . $payload['nonce'] );
        if ( ! is_array( $render )
            || absint( $render['ad_id'] ?? 0 ) !== absint( $ad_id )
            || sanitize_key( $render['placement'] ?? '' ) !== sanitize_key( $placement )
            || absint( $render['expires'] ?? 0 ) < time() ) {
            return false;
        }
        return $payload;
    }

    /** Check active window using the WordPress site timezone, not offset-adjusted pseudo timestamps. */
    private static function is_ad_active_now( $ad ) {
        if ( ! $ad || 'active' !== (string) $ad->status ) return false;
        try {
            $timezone = wp_timezone();
            $now = new DateTimeImmutable( 'now', $timezone );
            if ( ! empty( $ad->start_at ) ) {
                $start = new DateTimeImmutable( (string) $ad->start_at, $timezone );
                if ( $start > $now ) return false;
            }
            if ( ! empty( $ad->end_at ) ) {
                $end = new DateTimeImmutable( (string) $ad->end_at, $timezone );
                if ( $end < $now ) return false;
            }
        } catch ( Exception $e ) {
            return false;
        }
        return true;
    }

    private static function consume_render_event( $payload, $type ) {
        if ( ! is_array( $payload ) || empty( $payload['nonce'] ) ) return false;
        $key = 'ostudy_ads_render_' . sanitize_key( $payload['nonce'] );
        $render = get_transient( $key );
        if ( ! is_array( $render ) ) return false;
        $events = isset( $render['events'] ) && is_array( $render['events'] ) ? $render['events'] : array();
        if ( ! empty( $events[ $type ] ) ) return false;
        $events[ $type ] = time();
        $render['events'] = $events;
        $ttl = max( 1, absint( $render['expires'] ?? 0 ) - time() );
        set_transient( $key, $render, $ttl );
        return true;
    }

    public function ajax_track() {
        if ( function_exists( 'tls_rate_limited' ) && tls_rate_limited( 'ad_track', 60, 60 ) ) { wp_send_json_error( array( 'message' => 'Too many requests. Please wait a moment.' ), 429 ); }
        check_ajax_referer( 'ostudy_ads_track', 'nonce' );
        $ad_id = absint( $_POST['ad_id'] ?? 0 );
        $type = sanitize_key( $_POST['type'] ?? '' );
        $placement = sanitize_key( $_POST['placement'] ?? '' );
        $token = sanitize_text_field( wp_unslash( $_POST['tracking_token'] ?? '' ) );
        $render_payload = self::valid_render_token( $token, $ad_id, $placement );
        if ( ! $ad_id || ! in_array( $type, array( 'impression', 'click' ), true ) || ! $render_payload ) {
            wp_send_json_error( array( 'invalid_event' => true ), 403 );
        }
        if ( ! self::consume_render_event( $render_payload, $type ) ) {
            wp_send_json_success( array( 'deduped' => true ) );
        }
        $ad = OStudy_Ads_DB::get_ad( $ad_id );
        if ( ! self::is_ad_active_now( $ad ) ) wp_send_json_error( array( 'inactive_ad' => true ), 403 );
        global $wpdb;
        $hash_source = wp_get_session_token();
        if ( ! $hash_source ) $hash_source = wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' ) . '|' . wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' );
        $hash = hash_hmac( 'sha256', (string) $hash_source, wp_salt( 'auth' ) );

        // Guard against duplicate/rapid-fire events for the same visitor+ad+event
        // type (retry storms, the JS's own delayed re-scan passes, or a scripted
        // flood) inflating impression/click counts and any impression-based cap.
        $dedup_key = 'ostudy_ads_evt_' . substr( $hash, 0, 20 ) . '_' . $ad_id . '_' . $type;
        $dedup_window = ( 'impression' === $type ) ? 60 : 5;
        if ( false !== get_transient( $dedup_key ) ) {
            wp_send_json_success( array( 'deduped' => true ) );
        }
        set_transient( $dedup_key, 1, $dedup_window );

        // Coarse per-visitor rate cap across all ads/events, independent of the
        // per-event dedup above, to blunt a flood that varies ad_id/type to dodge it.
        $rate_key = 'ostudy_ads_rate_' . substr( $hash, 0, 20 );
        $rate_count = (int) get_transient( $rate_key );
        if ( $rate_count >= 60 ) {
            wp_send_json_error( array( 'rate_limited' => true ) );
        }
        set_transient( $rate_key, $rate_count + 1, 60 );

        $wpdb->insert( OStudy_Ads_DB::stats_table(), array(
            'ad_id' => $ad_id,
            'stat_type' => $type,
            'user_hash' => $hash,
            'placement' => $placement,
            'device' => OStudy_Ads_Utils::current_device(),
            'created_at' => current_time( 'mysql' ),
        ), array( '%d', '%s', '%s', '%s', '%s', '%s' ) );
        wp_send_json_success();
    }
}
