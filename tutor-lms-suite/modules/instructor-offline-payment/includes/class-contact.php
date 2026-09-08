<?php
/**
 * Instructor contact details (phone + WhatsApp) shown at checkout.
 *
 * @package TutorInstructorOfflinePayment
 */

namespace TutorInstructorOfflinePayment;

defined( 'ABSPATH' ) || exit;

/**
 * Offline payments live or die on being able to reach the person who is holding
 * the money, so every instructor gets one phone number and one WhatsApp number
 * that students can see on the checkout page.
 *
 * The instructor configures these on the same dashboard page where they set up
 * their payment methods. When they have never touched the fields, the values are
 * imported from their Tutor LMS profile (phone number, and any WhatsApp link
 * they put in their Tutor social profile fields), so most instructors get a
 * working contact row without doing anything.
 */
class Contact {

	/**
	 * User meta key holding the contact record.
	 *
	 * @var string
	 */
	const META_KEY = '_tioc_contact';

	/** Supported profile links shared by import, setup and checkout. */
	public static function social_networks() {
		return array( 'facebook' => 'Facebook', 'instagram' => 'Instagram', 'tiktok' => 'TikTok', 'x' => 'X / Twitter', 'linkedin' => 'LinkedIn', 'telegram' => 'Telegram', 'youtube' => 'YouTube', 'website' => 'Website', 'github' => 'GitHub' );
	}

	/**
	 * Blank record.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'phone'         => '',
			'whatsapp'      => '',
			'facebook'      => '',
			'instagram'     => '',
			'tiktok'        => '',
			'x'             => '',
			'linkedin'             => '',
			'telegram'             => '',
			'youtube'             => '',
			'website'             => '',
			'github'             => '',
			'show_phone'    => 1,
			'show_whatsapp' => 1,
			'show_facebook'  => 1,
			'show_instagram' => 1,
			'show_tiktok'    => 1,
			'show_x'         => 1,
			'show_linkedin'         => 0,
			'show_telegram'         => 0,
			'show_youtube'         => 0,
			'show_website'         => 0,
			'show_github'         => 0,
		);
	}

	/**
	 * Read an instructor's contact record.
	 *
	 * @param int  $user_id  Instructor user ID.
	 * @param bool $fallback Fill empty numbers from the Tutor LMS profile and
	 *                       from legacy per-method phone numbers.
	 *
	 * @return array
	 */
	public static function get( $user_id, $fallback = true ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return self::defaults();
		}

		$stored  = get_user_meta( $user_id, self::META_KEY, true );
		$stored  = is_array( $stored ) ? $stored : array();

		// Migrate the old single `show_socials` switch to the new independent
		// network switches without unexpectedly turning previously hidden social
		// accounts back on.
		if ( isset( $stored['show_socials'] ) ) {
			$legacy_show = empty( $stored['show_socials'] ) ? 0 : 1;
			foreach ( array( 'facebook', 'instagram', 'tiktok', 'x' ) as $network ) {
				$key = 'show_' . $network;
				if ( ! array_key_exists( $key, $stored ) ) {
					$stored[ $key ] = $legacy_show;
				}
			}
		}

		$contact = wp_parse_args( $stored, self::defaults() );

		$contact['phone']         = self::sanitize_number( $contact['phone'] );
		$contact['whatsapp']      = self::sanitize_number( $contact['whatsapp'] );
		foreach ( array_keys( self::social_networks() ) as $social ) {
			$contact[ $social ] = self::sanitize_url( isset( $contact[ $social ] ) ? $contact[ $social ] : '' );
		}
		$contact['show_phone']    = empty( $contact['show_phone'] ) ? 0 : 1;
		$contact['show_whatsapp'] = empty( $contact['show_whatsapp'] ) ? 0 : 1;
		foreach ( array_keys( self::social_networks() ) as $network ) {
			$show_key = 'show_' . $network;
			$contact[ $show_key ] = empty( $contact[ $show_key ] ) ? 0 : 1;
		}
		$contact['show_socials'] = 0;
		foreach ( self::social_networks() as $network => $label ) {
			if ( ! empty( $contact[ 'show_' . $network ] ) ) $contact['show_socials'] = 1;
		}

		if ( $fallback ) {
			$imported = self::imported( $user_id );

			if ( '' === $contact['phone'] ) {
				$contact['phone'] = $imported['phone'];
			}

			if ( '' === $contact['whatsapp'] ) {
				$contact['whatsapp'] = $imported['whatsapp'] ? $imported['whatsapp'] : $contact['phone'];
			}

			foreach ( array_keys( self::social_networks() ) as $network ) {
				if ( '' === $contact[ $network ] && ! empty( $imported[ $network ] ) ) {
					$contact[ $network ] = self::sanitize_url( $imported[ $network ] );
				}
			}
		}

		/**
		 * Filter an instructor's contact record.
		 *
		 * @param array $contact Contact record.
		 * @param int   $user_id Instructor user ID.
		 */
		return apply_filters( 'tioc_instructor_contact', $contact, $user_id );
	}

	/**
	 * Whether the instructor has ever saved their own contact details.
	 *
	 * @param int $user_id Instructor user ID.
	 *
	 * @return bool
	 */
	public static function is_configured( $user_id ) {
		$stored = get_user_meta( absint( $user_id ), self::META_KEY, true );

		return is_array( $stored ) && ( ! empty( $stored['phone'] ) || ! empty( $stored['whatsapp'] ) );
	}

	/**
	 * Save the contact record.
	 *
	 * @param int   $user_id Instructor user ID.
	 * @param array $input   Raw input.
	 *
	 * @return array|\WP_Error Saved record, or an error.
	 */
	public static function save( $user_id, array $input ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return new \WP_Error( 'tioc_invalid_user', __( 'Invalid user.', 'tutor-instructor-offline-payment' ) );
		}

		$record = array(
			'phone'         => self::sanitize_number( isset( $input['phone'] ) ? $input['phone'] : '' ),
			'whatsapp'      => self::sanitize_number( isset( $input['whatsapp'] ) ? $input['whatsapp'] : '' ),
			'facebook'      => self::sanitize_url( isset( $input['facebook'] ) ? $input['facebook'] : '' ),
			'instagram'     => self::sanitize_url( isset( $input['instagram'] ) ? $input['instagram'] : '' ),
			'tiktok'        => self::sanitize_url( isset( $input['tiktok'] ) ? $input['tiktok'] : '' ),
			'x'             => self::sanitize_url( isset( $input['x'] ) ? $input['x'] : '' ),
			'linkedin'             => self::sanitize_url( isset( $input['linkedin'] ) ? $input['linkedin'] : '' ),
			'telegram'             => self::sanitize_url( isset( $input['telegram'] ) ? $input['telegram'] : '' ),
			'youtube'             => self::sanitize_url( isset( $input['youtube'] ) ? $input['youtube'] : '' ),
			'website'             => self::sanitize_url( isset( $input['website'] ) ? $input['website'] : '' ),
			'github'             => self::sanitize_url( isset( $input['github'] ) ? $input['github'] : '' ),
			'show_phone'    => empty( $input['show_phone'] ) ? 0 : 1,
			'show_whatsapp' => empty( $input['show_whatsapp'] ) ? 0 : 1,
			'show_facebook'  => empty( $input['show_facebook'] ) ? 0 : 1,
			'show_instagram' => empty( $input['show_instagram'] ) ? 0 : 1,
			'show_tiktok'    => empty( $input['show_tiktok'] ) ? 0 : 1,
			'show_x'         => empty( $input['show_x'] ) ? 0 : 1,
			'show_linkedin'         => empty( $input['show_linkedin'] ) ? 0 : 1,
			'show_telegram'         => empty( $input['show_telegram'] ) ? 0 : 1,
			'show_youtube'         => empty( $input['show_youtube'] ) ? 0 : 1,
			'show_website'         => empty( $input['show_website'] ) ? 0 : 1,
			'show_github'         => empty( $input['show_github'] ) ? 0 : 1,
		);

		$record['show_socials'] = 0;
		foreach ( self::social_networks() as $network => $label ) {
			if ( ! empty( $record[ 'show_' . $network ] ) ) $record['show_socials'] = 1;
		}

		foreach ( array( 'phone', 'whatsapp' ) as $field ) {
			if ( '' !== $record[ $field ] && ! self::looks_like_number( $record[ $field ] ) ) {
				return new \WP_Error(
					'tioc_bad_phone',
					__( 'Enter a real phone number, for example 0555 12 34 56 or +213 555 12 34 56.', 'tutor-instructor-offline-payment' )
				);
			}
		}

		// One number is enough: WhatsApp falls back to the phone number.
		if ( '' === $record['whatsapp'] && '' !== $record['phone'] ) {
			$record['whatsapp'] = $record['phone'];
		}

		update_user_meta( $user_id, self::META_KEY, $record );

		/**
		 * Fires after an instructor saves their contact details.
		 *
		 * @param int   $user_id Instructor user ID.
		 * @param array $record  Saved record.
		 */
		do_action( 'tioc_contact_saved', $user_id, $record );

		return $record;
	}

	/**
	 * Numbers we can suggest for an instructor who has not filled the form in.
	 *
	 * Order of preference: the Tutor LMS profile phone field, Tutor billing
	 * details, a WhatsApp link found in the Tutor social profile fields, and
	 * finally any phone number left over on a legacy BaridiMob method.
	 *
	 * @param int $user_id Instructor user ID.
	 *
	 * @return array{phone:string,whatsapp:string,source:string}
	 */
	public static function imported( $user_id ) {
		$user_id = absint( $user_id );
		$phone   = '';
		$source  = '';

		/**
		 * Filter the user meta keys searched for a Tutor LMS profile phone number.
		 *
		 * @param string[] $keys Meta keys, in order of preference.
		 */
		$phone_keys = apply_filters(
			'tioc_tutor_phone_meta_keys',
			array( 'phone_number', '_tutor_profile_phone', 'tutor_profile_phone', 'billing_phone' )
		);

		foreach ( $phone_keys as $key ) {
			$value = get_user_meta( $user_id, $key, true );

			if ( is_string( $value ) && self::looks_like_number( $value ) ) {
				$phone  = self::sanitize_number( $value );
				$source = 'tutor_profile';
				break;
			}
		}

		if ( '' === $phone ) {
			$billing = self::billing_phone( $user_id );

			if ( $billing ) {
				$phone  = $billing;
				$source = 'tutor_billing';
			}
		}

		$whatsapp = self::whatsapp_from_socials( $user_id );

		if ( $whatsapp && ! $source ) {
			$source = 'tutor_social';
		}

		if ( '' === $phone ) {
			$legacy = self::legacy_method_phone( $user_id );

			if ( $legacy ) {
				$phone  = $legacy;
				$source = $source ? $source : 'legacy_method';
			}
		}

		$socials = self::tutor_socials( $user_id );

		return array(
			'phone'    => $phone,
			'whatsapp' => $whatsapp ? $whatsapp : $phone,
			'facebook' => ! empty( $socials['facebook'] ) ? self::sanitize_url( $socials['facebook'] ) : '',
			'instagram'=> ! empty( $socials['instagram'] ) ? self::sanitize_url( $socials['instagram'] ) : '',
			'tiktok'   => ! empty( $socials['tiktok'] ) ? self::sanitize_url( $socials['tiktok'] ) : '',
			'x'        => ! empty( $socials['x'] ) ? self::sanitize_url( $socials['x'] ) : ( ! empty( $socials['twitter'] ) ? self::sanitize_url( $socials['twitter'] ) : '' ),
			'linkedin' => ! empty( $socials['linkedin'] ) ? self::sanitize_url( $socials['linkedin'] ) : '',
			'telegram' => ! empty( $socials['telegram'] ) ? self::sanitize_url( $socials['telegram'] ) : '',
			'youtube' => ! empty( $socials['youtube'] ) ? self::sanitize_url( $socials['youtube'] ) : '',
			'website' => ! empty( $socials['website'] ) ? self::sanitize_url( $socials['website'] ) : '',
			'github' => ! empty( $socials['github'] ) ? self::sanitize_url( $socials['github'] ) : '',
			'source'   => $source,
		);
	}

	/**
	 * Tutor LMS social profile links for a user.
	 *
	 * @param int $user_id Instructor user ID.
	 *
	 * @return array Map of network to URL.
	 */
	public static function tutor_socials( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return array();
		}

		// Tutor LMS has used the legacy `twitter` name for the X profile,
		// while newer releases/documentation call the network X. Keep both
		// aliases and a few unprefixed variants for compatibility with sites
		// that migrated Tutor data or customized the profile form.
		$keys = array(
			'whatsapp'  => array( '_tutor_profile_whatsapp', 'tutor_profile_whatsapp', 'profile_whatsapp', 'whatsapp' ),
			'facebook'  => array( '_tutor_profile_facebook', 'tutor_profile_facebook', 'profile_facebook', 'facebook' ),
			'instagram' => array( '_tutor_profile_instagram', 'tutor_profile_instagram', 'profile_instagram', 'instagram' ),
			'tiktok'    => array( '_tutor_profile_tiktok', 'tutor_profile_tiktok', 'profile_tiktok', 'tiktok' ),
			'x'         => array( '_tutor_profile_x', 'tutor_profile_x', 'profile_x', 'x', '_tutor_profile_twitter', 'tutor_profile_twitter', 'profile_twitter', 'twitter', 'twitter_url' ),
			'linkedin'  => array( '_tutor_profile_linkedin', 'tutor_profile_linkedin', 'profile_linkedin', 'linkedin' ),
			'website'   => array( '_tutor_profile_website', 'tutor_profile_website', 'profile_website', 'website' ),
			'github'    => array( '_tutor_profile_github', 'tutor_profile_github', 'profile_github', 'github' ),
			'youtube' => array( '_tutor_profile_youtube', 'tutor_profile_youtube', 'youtube' ),
			'telegram'  => array( '_tutor_profile_telegram', 'tutor_profile_telegram', 'profile_telegram', 'telegram' ),
		);

		$keys = apply_filters( 'tioc_tutor_social_meta_keys', $keys );
		$socials = array();

		foreach ( $keys as $network => $network_keys ) {
			if ( ! is_array( $network_keys ) ) {
				$network_keys = array( $network_keys );
			}

			foreach ( $network_keys as $key ) {
				$value = get_user_meta( $user_id, $key, true );
				if ( is_string( $value ) && '' !== trim( $value ) ) {
					$socials[ $network ] = trim( $value );
					break;
				}
			}
		}

		// Some customized Tutor profile implementations store the complete
		// social profile as one array. Merge it without overriding explicit values.
		foreach ( array( '_tutor_social_profile', 'tutor_social_profile', '_tutor_profile_socials', 'tutor_profile_socials', 'social_profile', 'tutor_socials' ) as $bundle_key ) {
			$bundle = get_user_meta( $user_id, $bundle_key, true );
			if ( ! is_array( $bundle ) ) {
				continue;
			}

			foreach ( array_merge( array_keys( self::social_networks() ), array( 'twitter', 'whatsapp' ) ) as $network ) {
				$value = isset( $bundle[ $network ] ) ? $bundle[ $network ] : '';
				$canonical = 'twitter' === $network ? 'x' : $network;
				if ( ! is_string( $value ) || '' === trim( $value ) || ! empty( $socials[ $canonical ] ) ) {
					continue;
				}
				if ( is_string( $value ) ) {
					$socials[ $canonical ] = trim( $value );
				}
			}
		}

		return $socials;
	}

	/**
	 * Pull a WhatsApp number out of the Tutor social profile fields, whether the
	 * instructor stored a bare number or a wa.me / api.whatsapp.com link.
	 *
	 * @param int $user_id Instructor user ID.
	 *
	 * @return string
	 */
	private static function whatsapp_from_socials( $user_id ) {
		foreach ( self::tutor_socials( $user_id ) as $value ) {
			if ( ! preg_match( '#(?:wa\.me|whatsapp\.com)/(?:send\?phone=)?\+?([0-9]{6,20})#i', $value, $matches ) ) {
				continue;
			}

			return self::sanitize_number( '+' . $matches[1] );
		}

		$direct = get_user_meta( absint( $user_id ), '_tutor_profile_whatsapp', true );

		return is_string( $direct ) && self::looks_like_number( $direct ) ? self::sanitize_number( $direct ) : '';
	}

	/**
	 * Phone number from Tutor's billing record, when the ecommerce module has one.
	 *
	 * @param int $user_id Instructor user ID.
	 *
	 * @return string
	 */
	private static function billing_phone( $user_id ) {
		if ( ! class_exists( '\Tutor\Models\BillingModel' ) ) {
			return '';
		}

		try {
			$model = new \Tutor\Models\BillingModel();
			$info  = $model->get_info( $user_id );
		} catch ( \Throwable $e ) {
			tioc_log( $e );

			return '';
		}

		if ( is_object( $info ) && ! empty( $info->billing_phone ) && self::looks_like_number( $info->billing_phone ) ) {
			return self::sanitize_number( $info->billing_phone );
		}

		return '';
	}

	/**
	 * Phone number left behind on a pre-1.3.0 BaridiMob method, before per-method
	 * phone numbers were replaced by this single contact record.
	 *
	 * @param int $user_id Instructor user ID.
	 *
	 * @return string
	 */
	private static function legacy_method_phone( $user_id ) {
		$stored = get_user_meta( absint( $user_id ), Methods::META_KEY, true );

		if ( ! is_array( $stored ) ) {
			return '';
		}

		foreach ( $stored as $method ) {
			if ( ! is_array( $method ) || empty( $method['profile'] ) || ! is_array( $method['profile'] ) ) {
				continue;
			}

			$phone = isset( $method['profile']['baridimob_phone'] ) ? $method['profile']['baridimob_phone'] : '';

			if ( self::looks_like_number( $phone ) ) {
				return self::sanitize_number( $phone );
			}
		}

		return '';
	}

	/**
	 * What the checkout page is allowed to show.
	 *
	 * @param int    $user_id  Instructor user ID.
	 * @param string $wa_text  Prefilled WhatsApp message.
	 *
	 * @return array
	 */
	public static function public_contact( $user_id, $wa_text = '' ) {
		$contact = self::get( $user_id );

		$phone    = $contact['show_phone'] ? $contact['phone'] : '';
		$whatsapp = $contact['show_whatsapp'] ? $contact['whatsapp'] : '';

		$socials = array();
		foreach ( array_keys( self::social_networks() ) as $network ) {
			$show_key = 'show_' . $network;
			if ( ! empty( $contact[ $show_key ] ) && ! empty( $contact[ $network ] ) ) {
				$socials[ $network ] = $contact[ $network ];
			}
		}

		return array(
			'phone'         => $phone,
			'phone_link'    => $phone ? self::tel_link( $phone ) : '',
			'whatsapp'      => $whatsapp,
			'whatsapp_link' => $whatsapp ? self::whatsapp_link( $whatsapp, $wa_text ) : '',
			'socials'       => $socials,
			'has_any'       => (bool) ( $phone || $whatsapp || $socials ),
		);
	}

	/**
	 * `tel:` URL for a number.
	 *
	 * @param string $phone Phone number.
	 *
	 * @return string
	 */
	public static function tel_link( $phone ) {
		$clean = preg_replace( '/[^0-9+]/', '', (string) $phone );

		return $clean ? 'tel:' . $clean : '';
	}

	/**
	 * International, digits-only form of a number, for wa.me links.
	 *
	 * Local Algerian numbers ("0555…") are expanded with the default dialling
	 * code, because wa.me refuses anything else.
	 *
	 * @param string $number Phone number.
	 *
	 * @return string
	 */
	public static function international( $number ) {
		$digits = preg_replace( '/\D+/', '', (string) $number );

		if ( ! $digits ) {
			return '';
		}

		/**
		 * Filter the dialling code used for local numbers. Defaults to Algeria.
		 *
		 * @param string $code Country calling code, digits only.
		 */
		$code = preg_replace( '/\D+/', '', (string) apply_filters( 'tioc_default_dial_code', '213' ) );

		if ( 0 === strpos( $digits, '00' ) ) {
			$digits = substr( $digits, 2 );
		} elseif ( 0 === strpos( $digits, '0' ) ) {
			$digits = $code . substr( $digits, 1 );
		} elseif ( strlen( $digits ) <= 9 ) {
			$digits = $code . $digits;
		}

		return $digits;
	}

	/**
	 * wa.me link, optionally with a prefilled message.
	 *
	 * @param string $number Phone number.
	 * @param string $text   Prefilled message.
	 *
	 * @return string
	 */
	public static function whatsapp_link( $number, $text = '' ) {
		$digits = self::international( $number );

		if ( ! $digits ) {
			return '';
		}

		$url = 'https://wa.me/' . $digits;

		return $text ? add_query_arg( 'text', $text, $url ) : $url;
	}

	/**
	 * Keep digits, spaces and the usual separators; drop everything else.
	 *
	 * @param string $number Raw input.
	 *
	 * @return string
	 */
	/**
	 * Sanitize a social-profile URL and keep only safe web protocols.
	 *
	 * @param string $url Raw URL.
	 * @return string
	 */
	public static function sanitize_url( $url ) {
		if ( ! is_string( $url ) ) return '';
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}

		if ( 0 === strpos( $url, 'www.' ) ) {
			$url = 'https://' . $url;
		}

		$url = esc_url_raw( $url, array( 'http', 'https' ) );
		return mb_substr( (string) $url, 0, 300 );
	}

	public static function sanitize_number( $number ) {
		$number = sanitize_text_field( (string) $number );
		$number = preg_replace( '/[^0-9+()\-\s\.]/', '', $number );

		return trim( mb_substr( (string) $number, 0, 32 ) );
	}

	/**
	 * Loose check: at least six digits.
	 *
	 * @param string $number Number.
	 *
	 * @return bool
	 */
	public static function looks_like_number( $number ) {
		return strlen( preg_replace( '/\D+/', '', (string) $number ) ) >= 6;
	}
}
