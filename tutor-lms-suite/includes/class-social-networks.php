<?php
/** Shared profile/payment social-network definitions. */
defined( 'ABSPATH' ) || exit;

class TLS_Suite_Social_Networks {
    public static function labels() {
        return array( 'facebook' => 'Facebook', 'instagram' => 'Instagram', 'tiktok' => 'TikTok', 'x' => 'X / Twitter', 'linkedin' => 'LinkedIn', 'telegram' => 'Telegram', 'youtube' => 'YouTube', 'website' => 'Website', 'github' => 'GitHub' );
    }

    public static function default_visible( $network ) {
        // Preserve legacy visibility; new networks require an explicit opt-in.
        return in_array( $network, array( 'facebook', 'instagram', 'tiktok', 'x' ), true ) ? 1 : 0;
    }

    public static function profile_fields( $icons ) {
		$icons[ '_tutor_profile_instagram' ] = array(
			'label'        => __( 'Instagram', 'tutor-lms-ipe' ),
			'placeholder'  => 'https://instagram.com/username',
			'icon_classes' => 'tutor-icon-brand-instagram tlms-ipe-social-icon',
			'svg_icon'     => \TUTOR\Icon::GLOBE,
			'pattern'      => '^https?:\/\/(www\.)?instagram\.com\/[A-Za-z0-9._]+\/?$',
		);
		$icons[ '_tutor_profile_telegram' ]  = array(
			'label'        => __( 'Telegram', 'tutor-lms-ipe' ),
			'placeholder'  => 'https://t.me/username',
			'icon_classes' => 'tutor-icon-brand-telegram tlms-ipe-social-icon',
			'svg_icon'     => \TUTOR\Icon::GLOBE,
			'pattern'      => '^https?:\/\/(t\.me|telegram\.me)\/[A-Za-z0-9_]+\/?$',
		);
		$icons[ '_tutor_profile_whatsapp' ]  = array(
			'label'        => __( 'WhatsApp', 'tutor-lms-ipe' ),
			'placeholder'  => 'https://wa.me/213555123456',
			'icon_classes' => 'tutor-icon-brand-whatsapp tlms-ipe-social-icon',
			'svg_icon'     => \TUTOR\Icon::ANDROID_PHONE,
			'pattern'      => '^(https?:\/\/(wa\.me\/|api\.whatsapp\.com\/send\?phone=).+|(\+|00)?[0-9 ]{8,20})$',
		);
		$icons[ '_tutor_profile_tiktok' ]    = array(
			'label'        => __( 'TikTok', 'tutor-lms-ipe' ),
			'placeholder'  => 'https://tiktok.com/@username',
			'icon_classes' => 'tutor-icon-brand-tiktok tlms-ipe-social-icon',
			'svg_icon'     => \TUTOR\Icon::GLOBE,
			'pattern'      => '^https?:\/\/(www\.)?tiktok\.com\/@[A-Za-z0-9._]+\/?$',
		);

		// Use Tutor's social registry for native rendering and saving. Keep a
		// definition supplied by Tutor or another extension if one exists.
		if ( ! isset( $icons[ '_tutor_profile_youtube' ] ) ) {
			$icons[ '_tutor_profile_youtube' ] = array(
				'label'        => __( 'YouTube', 'tutor-lms-ipe' ),
				'placeholder'  => 'https://www.youtube.com/@yourchannel',
				'icon_classes' => 'tutor-icon-brand-youtube tlms-ipe-social-icon',
				'svg_icon'     => \TUTOR\Icon::GLOBE,
				// Handles, channel IDs, legacy channel URLs and shared video URLs.
				'pattern'      => '^https?:\/\/(?:(?:www\.|m\.)?youtube\.com|youtu\.be)\/[^\s]+$',
			);
		}

        return $icons;
    }
}
