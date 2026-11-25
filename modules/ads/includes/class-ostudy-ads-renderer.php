<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class OStudy_Ads_Renderer {
    private static $instance;
    private $rendered = array();

    public static function instance() {
        if ( ! self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
        // Tutor LMS 4.x outputs the course feature video/thumbnail first, then fires
        // tutor_course/single/before/inner-wrap. Use that exact native hook as the
        // authoritative public-course banner location so the feature-video player
        // cannot swallow, overlap, or replace the banner insertion point.
        add_action( 'tutor_course/single/before/inner-wrap', array( $this, 'render_course_top_hook' ), 5 );
        // Auto-render everything into a hidden staging area at the end of the document.
        // JS relocates each zone into the correct semantic page container before display.
        // Print the staging markup before WordPress prints footer scripts.  If this runs
        // after wp_print_footer_scripts, the mounting JS can execute before the staging
        // nodes exist, leaving every automatic ad hidden in the staging area.
        add_action( 'wp_footer', array( $this, 'render_auto_zones' ), 1 );
        add_shortcode( 'ostudy_ad', array( $this, 'shortcode' ) );
        add_shortcode( 'ostudy_ads', array( $this, 'shortcode' ) );
    }

    public function assets() {
        $settings = OStudy_Ads_DB::get_settings();
        if ( empty( $settings['enabled'] ) ) return;
        wp_enqueue_style( 'ostudy-ads', OSTUDY_ADS_URL . 'assets/css/ostudy-ads.css', array(), OSTUDY_ADS_VERSION );
        wp_enqueue_script( 'ostudy-ads', OSTUDY_ADS_URL . 'assets/js/ostudy-ads.js', array(), OSTUDY_ADS_VERSION, true );
        wp_localize_script( 'ostudy-ads', 'OStudyAds', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'ostudy_ads_track' ),
            'popupMobile' => ! empty( $settings['popup_mobile'] ),
            'placements' => OStudy_Ads_Utils::placement_selectors(),
        ) );
    }

    private function allowed_to_render( $ad = null ) {
        $settings = OStudy_Ads_DB::get_settings();
        if ( empty( $settings['enabled'] ) || empty( $settings['auto_render'] ) ) return false;

        // An advertisement explicitly targeted to administrators must be visible to
        // administrators even when the global safety setting hides non-targeted ads.
        // This makes the per-ad Audience selection authoritative.
        if ( ! empty( $settings['hide_for_admins'] ) && current_user_can( 'manage_options' ) ) {
            $targeted_admin = false;
            if ( $ad ) {
                $roles = OStudy_Ads_Utils::json_array( $ad->roles );
                $targeted_admin = in_array( 'administrator', $roles, true );
            }
            if ( ! $targeted_admin ) return false;
        }
        return true;
    }

    private function course_top_render_html() {
        if ( is_admin() || ! OStudy_Ads_Utils::is_single_course() || OStudy_Ads_Utils::is_focus_mode() ) return '';
        $settings = OStudy_Ads_DB::get_settings();
        if ( empty( $settings['enabled'] ) || empty( $settings['auto_render'] ) ) return '';
        return $this->render_zone( 'course_page_top' );
    }

    public function render_course_top_hook() {
        $html = $this->course_top_render_html();
        if ( $html ) {
            // This zone is already printed in its final, correct position (not the
            // hidden staging area), so it must carry .is-mounted itself: the mounting
            // JS only ever looks inside #ostudy-ads-staging, and the base CSS rule
            // `.ostudy-ad:not(.is-mounted){display:none!important}` would otherwise
            // hide this banner forever since nothing else adds that class for it.
            $html = preg_replace( '/class="ostudy-ad([^"]*)"/', 'class="ostudy-ad$1 is-mounted"', $html, 1 );
            echo '<div class="ostudy-ads-tutor-hook ostudy-ads-tutor-course-top" data-ostudy-course-banner="1">' . $html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
    }

    public function render_auto_zones() {
        $settings = OStudy_Ads_DB::get_settings();
        if ( empty( $settings['enabled'] ) || empty( $settings['auto_render'] ) ) return;

        $page = OStudy_Ads_Utils::page_key();
        $zones = array();
        $settings = OStudy_Ads_DB::get_settings();

        if ( OStudy_Ads_Utils::is_focus_mode() ) {
            if ( ! empty( $settings['focus_mode_enabled'] ) ) {
                $zones[] = 'focus_mode_top';
                $zones[] = 'focus_mode_popup';
            }
            if ( ! empty( $settings['focus_mode_side_enabled'] ) ) $zones[] = 'focus_mode_side';
        } else {
            $map = array(
                'home' => array( 'home_top', 'home_popup' ),
                'course_search' => array( 'course_search_top', 'course_search_popup' ),
                'tutor_search' => array( 'tutor_search_top', 'tutor_search_popup' ),
                'instructor_profile' => array( 'instructor_profile_top', 'instructor_profile_side', 'instructor_profile_popup' ),
                'course_page' => array( 'course_page_top', 'course_page_popup' ),
                'dashboard' => array( 'dashboard_sidebar', 'dashboard_popup' ),
            );
            if ( isset( $map[ $page ] ) ) $zones = $map[ $page ];
        }

        $html = '';
        foreach ( $zones as $zone ) {
            $rendered = $this->render_zone( $zone );
            if ( $rendered ) $html .= $rendered;
        }
        if ( $html ) {
            echo '<div id="ostudy-ads-staging" class="ostudy-ads-staging" aria-hidden="true">' . $html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
    }

    /**
     * Manual shortcode placement.
     *
     * Examples:
     * [ostudy_ad id=123]
     * [ostudy_ad id=123 zone=home_top]
     * [ostudy_ad zone=home_top]
     *
     * An explicit ad ID is useful when editing a specific page with Elementor,
     * Gutenberg, a Tutor LMS page builder, or a normal WordPress shortcode block.
     * Manual ID placement still respects the ad's status, schedule, device, role,
     * category and frequency rules; its page_targets are intentionally not required
     * because the page itself is selected by where the shortcode is inserted.
     */
    public function shortcode( $atts ) {
        $atts = shortcode_atts( array(
            'id' => 0,
            'zone' => '',
            'placement' => '',
            'respect_page_target' => '0',
        ), $atts, 'ostudy_ad' );

        $zone = sanitize_key( $atts['zone'] ?: $atts['placement'] );
        $id   = absint( $atts['id'] );

        if ( $id ) {
            return $this->render_ad_by_id( $id, $zone, '0' !== (string) $atts['respect_page_target'] );
        }

        // Zone-based shortcode is the easiest way to reuse normal campaign rotation
        // while placing the zone exactly where the page editor inserts the shortcode.
        if ( $zone ) return $this->render_zone( $zone );

        return '';
    }

    private function render_zone( $zone ) {
        if ( ! $zone || isset( $this->rendered[ $zone ] ) ) return '';
        $ads = $this->eligible_ads_for_zone( $zone );
        if ( empty( $ads ) ) return '';
        $this->rendered[ $zone ] = true;
        $ad = $this->choose_ad( $ads );
        if ( ! $ad ) return '';
        return $this->render_ad( $ad, $zone );
    }

    private function eligible_ads_for_zone( $zone ) {
        $ads = OStudy_Ads_DB::get_ads();
        $out = array();
        foreach ( $ads as $ad ) {
            if ( ! $this->is_eligible( $ad, $zone ) ) continue;
            $targets = OStudy_Ads_Utils::json_array( $ad->page_targets );
            if ( ! in_array( $zone, $targets, true ) ) continue;
            if ( ! $this->type_allowed_for_zone( $ad->ad_type, $zone ) ) continue;
            $out[] = $ad;
        }
        return $out;
    }

    private function type_allowed_for_zone( $type, $zone ) {
        $type = sanitize_key( $type );
        if ( false !== strpos( $zone, '_popup' ) ) return 'popup' === $type;
        if ( 'dashboard_sidebar' === $zone || 'focus_mode_side' === $zone || 'instructor_profile_side' === $zone ) return in_array( $type, array( 'sidebar', 'banner', 'html' ), true );
        return in_array( $type, array( 'banner', 'html' ), true );
    }

    private function is_eligible( $ad, $zone = '' ) {
        if ( isset( $ad->status ) && 'active' !== $ad->status ) return false;
        $now = current_time( 'timestamp', true );
        if ( ! empty( $ad->start_at ) && $now < strtotime( $ad->start_at . ' UTC' ) ) return false;
        if ( ! empty( $ad->end_at ) && $now > strtotime( $ad->end_at . ' UTC' ) ) return false;

        $devices = OStudy_Ads_Utils::json_array( $ad->device_targets );
        if ( $devices && ! in_array( OStudy_Ads_Utils::current_device(), $devices, true ) ) return false;
        $roles = OStudy_Ads_Utils::json_array( $ad->roles );
        $settings = OStudy_Ads_DB::get_settings();
        if ( ! empty( $settings['hide_for_admins'] ) && current_user_can( 'manage_options' ) && ! in_array( 'administrator', $roles, true ) ) return false;
        if ( ! OStudy_Ads_Utils::user_role_match( $roles ) ) return false;

        $cats = OStudy_Ads_Utils::json_array( $ad->categories );
        if ( $cats ) {
            $current = OStudy_Ads_Utils::selected_categories();
            // A category-targeted ad is only valid on a course page with a matching category.
            if ( empty( $current ) || ! array_intersect( array_map( 'absint', $cats ), $current ) ) return false;
        }
        $wilayas = OStudy_Ads_Utils::json_array( $ad->wilayas ?? '' );
        if ( $wilayas ) {
            $current_wilayas = OStudy_Ads_Utils::current_course_wilayas();
            $wanted = array_map( 'sanitize_text_field', $wilayas );
            if ( empty( $current_wilayas ) || ! array_intersect( $wanted, $current_wilayas ) ) return false;
        }

        if ( ! empty( $ad->max_impressions ) && OStudy_Ads_DB::count_impressions( $ad->id ) >= absint( $ad->max_impressions ) ) {
            return false;
        }

        return $this->frequency_allows( $ad, $zone );
    }

    private function frequency_allows( $ad, $zone = '' ) {
        $frequency = sanitize_key( $ad->frequency );
        if ( 'always' === $frequency ) return true;
        // Must exactly match the cookie name markSeen() writes in ostudy-ads.js
        // (ostudy_ads_seen_{id}_{placement}), or this check can never see the
        // cookie the front-end actually sets and the cap silently never engages.
        $key = 'ostudy_ads_seen_' . absint( $ad->id ) . '_' . sanitize_key( $zone ?: 'global' );
        if ( 'session' === $frequency ) return ! isset( $_COOKIE[ $key ] );
        if ( 'cooldown' === $frequency ) {
            $seen = isset( $_COOKIE[ $key ] ) ? absint( $_COOKIE[ $key ] ) : 0;
            $hours = max( 1, absint( $ad->cooldown_hours ) );
            return ! $seen || ( time() - $seen ) >= ( $hours * HOUR_IN_SECONDS );
        }
        return true;
    }

    private function choose_ad( $ads ) {
        usort( $ads, function( $a, $b ) {
            if ( (int) $a->priority === (int) $b->priority ) return (int) $b->weight <=> (int) $a->weight;
            return (int) $a->priority <=> (int) $b->priority;
        } );
        $top_priority = (int) $ads[0]->priority;
        $pool = array_values( array_filter( $ads, function( $ad ) use ( $top_priority ) { return (int) $ad->priority === $top_priority; } ) );
        $sum = array_sum( array_map( function( $a ) { return max( 1, (int) $a->weight ); }, $pool ) );
        $pick = wp_rand( 1, max( 1, $sum ) );
        foreach ( $pool as $ad ) {
            $pick -= max( 1, (int) $ad->weight );
            if ( $pick <= 0 ) return $ad;
        }
        return $pool[0];
    }

    private function render_ad_by_id( $id, $zone, $respect_page_target = false ) {
        $ad = OStudy_Ads_DB::get_ad( $id );
        if ( ! $ad ) return '';
        // Resolve the final zone once and use it for both the eligibility/frequency
        // check and the rendered output. Checking eligibility against the raw
        // (possibly empty) $zone while rendering with a different fallback zone
        // would let the cookie-based frequency check and the actual output disagree.
        $manual_zone = $zone ?: 'manual';
        if ( ! $this->is_eligible( $ad, $manual_zone ) ) return '';
        if ( $respect_page_target && $zone ) {
            $targets = OStudy_Ads_Utils::json_array( $ad->page_targets );
            if ( $targets && ! in_array( $zone, $targets, true ) ) return '';
        }

        // A manual shortcode may appear more than once on a page. Give the renderer
        // a stable per-ad/per-zone key so frequency logic is not accidentally skipped.
        $render_key = 'manual_' . absint( $id ) . '_' . sanitize_key( $manual_zone );
        if ( isset( $this->rendered[ $render_key ] ) ) return '';
        $this->rendered[ $render_key ] = true;
        return $this->render_ad( $ad, $manual_zone );
    }

    private function normalize_image_url( $url ) {
        $url = html_entity_decode( trim( (string) $url ), ENT_QUOTES, 'UTF-8' );
        if ( '' === $url ) return '';
        if ( 0 === strpos( $url, '//' ) ) {
            $url = ( is_ssl() ? 'https:' : 'http:' ) . $url;
        } elseif ( 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ) {
            $url = home_url( $url );
        }
        return esc_url_raw( $url );
    }

    private function resolve_image_url( $attachment_id, $url ) {
        $attachment_id = absint( $attachment_id );
        if ( $attachment_id ) {
            $attachment_url = wp_get_attachment_image_url( $attachment_id, 'full' );
            if ( $attachment_url ) return $attachment_url;
            $attachment_url = wp_get_attachment_url( $attachment_id );
            if ( $attachment_url ) return $attachment_url;
        }
        return $this->normalize_image_url( $url );
    }

    private function bare_image_url_from_html( $html ) {
        $value = html_entity_decode( trim( (string) $html ), ENT_QUOTES, 'UTF-8' );
        if ( '' === $value ) return '';

        // Accept an actual <img src="..."> stored in Custom HTML.
        if ( preg_match( '#<img\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\']#i', $value, $m ) ) {
            return $this->normalize_image_url( $m[1] );
        }

        // Accept a bare URL even when the WordPress editor wrapped it in <p>, <a>, etc.
        $text = trim( wp_strip_all_tags( $value ) );
        $text = trim( $text, "\"'\t\r\n <>" );
        if ( preg_match( '#^(?:(?:https?:)?//|/)[^\s<>]+$#i', $text ) ) {
            return $this->normalize_image_url( $text );
        }
        return '';
    }

    private function padding_style( $ad ) {
        $top = min( 500, max( 0, absint( $ad->padding_top ?? 0 ) ) );
        $right = min( 500, max( 0, absint( $ad->padding_right ?? 0 ) ) );
        $bottom = min( 500, max( 0, absint( $ad->padding_bottom ?? 0 ) ) );
        $left = min( 500, max( 0, absint( $ad->padding_left ?? 0 ) ) );
        return 'padding:' . $top . 'px ' . $right . 'px ' . $bottom . 'px ' . $left . 'px;';
    }

    /**
     * Small "Ad" sticker rendered on top of every ad unit so visitors can always
     * tell sponsored content apart from real site content at a glance. Purely
     * visual/decorative (aria-hidden) since the container already carries a
     * proper aria-label="Advertisement" for assistive tech.
     */
    private function badge_html() {
        $html = '<span class="ostudy-ad__badge" aria-hidden="true">' . esc_html__( 'Ad', 'tutor-learning-suite' ) . '</span>';
        // Optional "Advertise here" link (shown on hover) that leads to the page holding [ostudy_ad_order].
        $settings = OStudy_Ads_DB::get_settings();
        if ( ! empty( $settings['orders_enabled'] ) && ! empty( $settings['order_page_url'] ) ) {
            $html .= '<a class="ostudy-ad__advertise" href="' . esc_url( $settings['order_page_url'] ) . '">' . esc_html__( 'Advertise here', 'tutor-learning-suite' ) . ' →</a>';
        }
        return $html;
    }

    private function tracking_token( $ad_id, $placement ) {
        try {
            $nonce = bin2hex( random_bytes( 16 ) );
        } catch ( \Throwable $e ) {
            $nonce = md5( wp_generate_password( 64, true, true ) );
        }
        $expires = time() + 600;
        $placement = sanitize_key( $placement );
        // The signed value binds an event to a real render. The transient is a
        // server-side one-render record, preventing clients from inventing IDs
        // or replaying the same impression/click indefinitely.
        set_transient( 'ostudy_ads_render_' . $nonce, array(
            'ad_id' => absint( $ad_id ),
            'placement' => $placement,
            'expires' => $expires,
            'events' => array(),
        ), 600 );
        $payload = wp_json_encode( array( 'ad_id' => absint( $ad_id ), 'placement' => $placement, 'expires' => $expires, 'nonce' => $nonce ) );
        $encoded = rtrim( strtr( base64_encode( $payload ), '+/', '-_' ), '=' );
        return $encoded . '.' . hash_hmac( 'sha256', $encoded, wp_salt( 'auth' ) );
    }

    private function render_ad( $ad, $zone ) {
        $classes = 'ostudy-ad ostudy-ad--' . sanitize_html_class( $ad->ad_type ) . ' ostudy-ad--zone-' . sanitize_html_class( $zone );
        $attrs = array(
            'data-ad-id' => absint( $ad->id ),
            'data-placement' => $zone,
            'data-track-token' => $this->tracking_token( $ad->id, $zone ),
            'data-frequency' => $ad->frequency,
            'data-delay' => (float) $ad->delay_seconds,
            'data-close-delay' => (float) ( $ad->popup_close_delay ?? 5 ),
            'data-scroll' => (int) $ad->scroll_percent,
            'data-cooldown' => (int) $ad->cooldown_hours,
        );
        $attr_html = '';
        foreach ( $attrs as $k => $v ) $attr_html .= ' ' . $k . '="' . esc_attr( $v ) . '"';

        $common_no_translate = ' data-no-translation="1" data-no-dynamic-translation="1"';

        if ( 'html' === $ad->ad_type ) {
            $raw_html = trim( (string) $ad->html_code );
            $html_image = $this->bare_image_url_from_html( $raw_html );
            if ( $html_image ) {
                $pad_style = $this->padding_style( $ad );
                return '<div class="' . esc_attr( $classes ) . '"' . $attr_html . $common_no_translate . ' aria-label="Advertisement">' . $this->badge_html() . '<img data-no-translation="1" data-no-dynamic-translation="1" data-original-src="' . esc_url( $html_image ) . '" loading="lazy" decoding="async" style="' . esc_attr( $pad_style ) . '" src="' . esc_url( $html_image ) . '" alt="' . esc_attr( $ad->name ) . '"></div>';
            }
            return '<div class="' . esc_attr( $classes ) . '"' . $attr_html . $common_no_translate . ' aria-label="Advertisement">' . $this->badge_html() . wp_kses_post( $ad->html_code ) . '</div>';
        }

        $image = $this->resolve_image_url( $ad->image_id, $ad->image_url );
        $mobile = $this->resolve_image_url( $ad->mobile_image_id, $ad->mobile_image_url );
        $image_fallback = $ad->image_url ? $this->normalize_image_url( $ad->image_url ) : '';
        $mobile_fallback = $ad->mobile_image_url ? $this->normalize_image_url( $ad->mobile_image_url ) : '';
        $target = $ad->destination_url ? esc_url( $ad->destination_url ) : '';
        $tag_open = $target ? '<a href="' . $target . '"' . ( $ad->open_new_tab ? ' target="_blank" rel="noopener sponsored"' : '' ) . '>' : '';
        $tag_close = $target ? '</a>' : '';
        $fit = in_array( $ad->fit_mode, array( 'cover', 'contain', 'natural' ), true ) ? $ad->fit_mode : 'cover';
        $style = '--ostudy-fit:' . esc_attr( $fit ) . ';--ostudy-pos:' . esc_attr( (float) $ad->focal_x ) . '% ' . esc_attr( (float) $ad->focal_y ) . '%;';
        $padding_style = $this->padding_style( $ad );

        $media = '';
        if ( $image ) {
            if ( $mobile ) {
                $mobile_extra = $mobile_fallback && $mobile_fallback !== $mobile ? ' data-fallback-src="' . esc_url( $mobile_fallback ) . '"' : '';
                $media .= '<picture data-no-translation="1"><source media="(max-width: 767px)" srcset="' . esc_url( $mobile ) . '"' . $mobile_extra . '>';
            }
            $fallback_extra = $image_fallback && $image_fallback !== $image ? ' data-fallback-src="' . esc_url( $image_fallback ) . '"' : '';
            $media .= '<img data-no-translation="1" data-no-dynamic-translation="1" data-original-src="' . esc_url( $image ) . '" loading="lazy" decoding="async"' . $fallback_extra . ' style="' . esc_attr( $style . $padding_style ) . '" src="' . esc_url( $image ) . '" alt="' . esc_attr( $ad->name ) . '">';
            if ( $mobile ) $media .= '</picture>';
        }

        if ( 'popup' === $ad->ad_type ) {
            return '<div class="' . esc_attr( $classes ) . ' ostudy-ad-popup"' . $attr_html . $common_no_translate . ' aria-hidden="true"><div class="ostudy-ad-popup__backdrop"></div><div class="ostudy-ad-popup__dialog" role="dialog" aria-modal="true">' . $this->badge_html() . '<button type="button" class="ostudy-ad-popup__close" aria-label="Close">×</button>' . $tag_open . $media . $tag_close . '</div></div>';
        }
        if ( 'sidebar' === $ad->ad_type ) {
            return '<aside class="' . esc_attr( $classes ) . '"' . $attr_html . $common_no_translate . ' aria-label="Advertisement">' . $this->badge_html() . $tag_open . $media . $tag_close . '</aside>';
        }
        return '<div class="' . esc_attr( $classes ) . '"' . $attr_html . $common_no_translate . ' aria-label="Advertisement">' . $this->badge_html() . $tag_open . $media . $tag_close . '</div>';
    }
}
