<?php
/**
 * Per-instructor offline payment method storage.
 *
 * @package TutorInstructorOfflinePayment
 */

namespace TutorInstructorOfflinePayment;

defined( 'ABSPATH' ) || exit;

/**
 * Each instructor owns a list of offline payment methods stored in user meta.
 *
 * A method is a label plus free-form instructions ("Bank: ACME, IBAN: …",
 * "Mobile money to +212…", "Cash at the studio, Tue/Thu"), optionally with an
 * image such as a QR code.
 */
class Methods {

	/**
	 * User meta key holding the method list.
	 *
	 * @var string
	 */
	const META_KEY = '_tioc_payment_methods';

	/**
	 * User meta key holding the instructor's general payment note.
	 *
	 * @var string
	 */
	const NOTE_META_KEY = '_tioc_payment_note';

	/**
	 * Maximum methods per instructor. Keeps the meta row and the checkout page
	 * a sane size.
	 *
	 * @var int
	 */
	const MAX_METHODS = 10;

	/**
	 * Legacy, free-form method type. The only type that accepts a custom image.
	 *
	 * @var string
	 */
	const TYPE_LEGACY = 'other';

	/**
	 * Supported payment types, in the order instructors see them.
	 *
	 * @return array Map of type key to label.
	 */
	public static function types() {
		/**
		 * Filter the available payment types.
		 *
		 * @param array $types Map of type key to translated label.
		 */
		return apply_filters(
			'tioc_method_types',
			array(
				'baridimob_ccp' => __( 'BaridiMob / CCP', 'tutor-learning-suite' ),
				'bank_transfer' => __( 'Bank transfer', 'tutor-learning-suite' ),
				'yassir_pay'    => __( 'Yassir Pay', 'tutor-learning-suite' ),
				'phone_cash'    => __( 'Phone Cash', 'tutor-learning-suite' ),
				'cash'          => __( 'Cash', 'tutor-learning-suite' ),
				self::TYPE_LEGACY => __( 'Other / legacy instructions', 'tutor-learning-suite' ),
			)
		);
	}

	/**
	 * Coerce any input to a supported type.
	 *
	 * @param string $type Raw type.
	 *
	 * @return string
	 */
	public static function valid_type( $type ) {
		$type = sanitize_key( (string) $type );

		return array_key_exists( $type, self::types() ) ? $type : self::TYPE_LEGACY;
	}

	/**
	 * Human label for a type.
	 *
	 * @param string $type Type key.
	 *
	 * @return string
	 */
	public static function type_label( $type ) {
		$types = self::types();
		$type  = self::valid_type( $type );

		return isset( $types[ $type ] ) ? $types[ $type ] : $type;
	}

	/**
	 * Whether a type is the legacy free-form one.
	 *
	 * @param string $type Type key.
	 *
	 * @return bool
	 */
	public static function is_legacy( $type ) {
		return self::TYPE_LEGACY === self::valid_type( $type );
	}

	/**
	 * Only legacy methods may carry an instructor-uploaded image. Every other
	 * type ships with its own artwork, so students see one consistent badge per
	 * payment type instead of whatever each instructor happened to upload.
	 *
	 * @param string $type Type key.
	 *
	 * @return bool
	 */
	public static function supports_custom_image( $type ) {
		return self::is_legacy( $type );
	}

	/**
	 * Bundled artwork for a payment type.
	 *
	 * @param string $type Type key.
	 *
	 * @return string URL, or an empty string for legacy methods.
	 */
	public static function default_image_url( $type ) {
		$type  = self::valid_type( $type );
		$slugs = array(
			'baridimob_ccp' => 'baridimob-ccp',
			'bank_transfer' => 'bank-transfer',
			'yassir_pay'    => 'yassir-pay',
			'phone_cash'    => 'phone-cash',
			'cash'          => 'cash',
		);

		$url = isset( $slugs[ $type ] ) ? TIOC_URL . 'assets/images/methods/' . $slugs[ $type ] . '.png' : '';

		/**
		 * Filter the default image for a payment type. Return an empty string to
		 * show no image at all.
		 *
		 * @param string $url  Image URL.
		 * @param string $type Type key.
		 */
		return (string) apply_filters( 'tioc_method_default_image', $url, $type );
	}

	/**
	 * Image to show for a method: the bundled artwork for typed methods, the
	 * instructor's own upload for legacy ones.
	 *
	 * @param array  $method Method or snapshot.
	 * @param string $size   Image size used for uploads.
	 *
	 * @return string
	 */
	public static function image_url( array $method, $size = 'medium' ) {
		$type = self::valid_type( isset( $method['profile_type'] ) ? $method['profile_type'] : self::TYPE_LEGACY );

		if ( ! self::supports_custom_image( $type ) ) {
			return self::default_image_url( $type );
		}

		$attachment_id = isset( $method['attachment_id'] ) ? absint( $method['attachment_id'] ) : 0;

		if ( ! $attachment_id ) {
			return '';
		}

		$url = wp_get_attachment_image_url( $attachment_id, $size );

		return $url ? $url : '';
	}

	/**
	 * Full-size target when a method image is clickable. Only uploads are worth
	 * opening; the bundled badges are decorative.
	 *
	 * @param array $method Method or snapshot.
	 *
	 * @return string
	 */
	public static function image_link_url( array $method ) {
		$type = self::valid_type( isset( $method['profile_type'] ) ? $method['profile_type'] : self::TYPE_LEGACY );

		if ( ! self::supports_custom_image( $type ) ) {
			return '';
		}

		$attachment_id = isset( $method['attachment_id'] ) ? absint( $method['attachment_id'] ) : 0;
		$url           = $attachment_id ? wp_get_attachment_url( $attachment_id ) : '';

		return $url ? $url : '';
	}

	/**
	 * The typed payment details, ready to print for a student.
	 *
	 * Empty fields are dropped, so a method only shows what the instructor
	 * actually filled in. Values flagged as `copy` are the ones a student needs
	 * to transcribe exactly (account and phone numbers).
	 *
	 * @param array $method Method or snapshot.
	 *
	 * @return array[] List of arrays with `label`, `value` and `copy`.
	 */
	public static function public_details( array $method ) {
		$type    = self::valid_type( isset( $method['profile_type'] ) ? $method['profile_type'] : self::TYPE_LEGACY );
		$profile = isset( $method['profile'] ) && is_array( $method['profile'] ) ? $method['profile'] : array();

		$map = array(
			'baridimob_ccp' => array(
				'account_holder' => array( __( 'Account holder', 'tutor-learning-suite' ), false ),
				'ccp_number'     => array( __( 'CCP number', 'tutor-learning-suite' ), true ),
				'ccp_key'        => array( __( 'CCP key', 'tutor-learning-suite' ), true ),
			),
			'bank_transfer' => array(
				'account_holder' => array( __( 'Account holder', 'tutor-learning-suite' ), false ),
				'bank_name'      => array( __( 'Bank', 'tutor-learning-suite' ), false ),
				'rib'            => array( __( 'RIB', 'tutor-learning-suite' ), true ),
				'iban'           => array( __( 'IBAN', 'tutor-learning-suite' ), true ),
				'branch'         => array( __( 'Branch', 'tutor-learning-suite' ), false ),
			),
			'yassir_pay'    => array(
				'yassir_phone'   => array( __( 'Yassir Pay number', 'tutor-learning-suite' ), true ),
				'account_holder' => array( __( 'Account holder', 'tutor-learning-suite' ), false ),
			),
			'phone_cash'    => array(
				'phonecash_number' => array( __( 'Phone Cash number', 'tutor-learning-suite' ), true ),
				'account_holder'   => array( __( 'Account holder', 'tutor-learning-suite' ), false ),
			),
			'cash'          => array(
				'cash_location' => array( __( 'Where to pay', 'tutor-learning-suite' ), false ),
				'cash_contact'  => array( __( 'Ask for', 'tutor-learning-suite' ), false ),
			),
			self::TYPE_LEGACY => array(
				'other_details' => array( __( 'Payment details', 'tutor-learning-suite' ), true ),
			),
		);

		$fields  = isset( $map[ $type ] ) ? $map[ $type ] : array();
		$details = array();

		foreach ( $fields as $field => $meta ) {
			$value = isset( $profile[ $field ] ) ? trim( (string) $profile[ $field ] ) : '';

			if ( '' === $value ) {
				continue;
			}

			$details[] = array(
				'label' => $meta[0],
				'value' => $value,
				'copy'  => (bool) $meta[1],
			);
		}

		/**
		 * Filter the student-visible detail rows for a method.
		 *
		 * @param array[] $details Detail rows.
		 * @param array   $method  Method or snapshot.
		 */
		return apply_filters( 'tioc_method_public_details', $details, $method );
	}

	/**
	 * Read an instructor's methods.
	 *
	 * @param int  $user_id     Instructor user ID.
	 * @param bool $active_only Return only enabled methods.
	 *
	 * @return array[] List of method arrays.
	 */
	public static function get( $user_id, $active_only = false ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return array();
		}

		$stored = get_user_meta( $user_id, self::META_KEY, true );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$methods = array();
		foreach ( $stored as $method ) {
			if ( ! is_array( $method ) || empty( $method['id'] ) || empty( $method['title'] ) ) {
				continue;
			}

			$method = self::normalise( $method );

			if ( $active_only && ! $method['is_active'] ) {
				continue;
			}

			$methods[] = $method;
		}

		return $methods;
	}

	/**
	 * Find one method belonging to an instructor.
	 *
	 * @param int    $user_id   Instructor user ID.
	 * @param string $method_id Method ID.
	 *
	 * @return array|null
	 */
	public static function find( $user_id, $method_id ) {
		$method_id = sanitize_key( $method_id );
		if ( ! $method_id ) {
			return null;
		}

		foreach ( self::get( $user_id ) as $method ) {
			if ( $method['id'] === $method_id ) {
				return $method;
			}
		}

		return null;
	}

	/**
	 * Whether an instructor has at least one usable method.
	 *
	 * @param int $user_id Instructor user ID.
	 *
	 * @return bool
	 */
	public static function has_active( $user_id ) {
		return (bool) self::get( $user_id, true );
	}

	/**
	 * Insert or update a method.
	 *
	 * @param int   $user_id Instructor user ID.
	 * @param array $input   Raw, unsanitised input.
	 *
	 * @return array|\WP_Error The saved method, or an error.
	 */
	public static function save( $user_id, array $input ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return new \WP_Error( 'tioc_invalid_user', __( 'Invalid user.', 'tutor-learning-suite' ) );
		}

		$title = isset( $input['title'] ) ? sanitize_text_field( $input['title'] ) : '';
		if ( '' === trim( $title ) ) {
			return new \WP_Error( 'tioc_missing_title', __( 'Give the payment method a name, for example "Bank transfer".', 'tutor-learning-suite' ) );
		}

		$instructions = isset( $input['instructions'] ) ? wp_kses_post( trim( $input['instructions'] ) ) : '';

		$methods   = self::get( $user_id );
		$method_id = isset( $input['id'] ) ? sanitize_key( $input['id'] ) : '';
		$found     = false;

		$profile_type = self::valid_type( isset( $input['profile_type'] ) ? $input['profile_type'] : self::TYPE_LEGACY );
		$profile      = self::sanitize_profile( $profile_type, isset( $input['profile'] ) && is_array( $input['profile'] ) ? $input['profile'] : array() );
		$validation   = self::validate_method_details( $profile_type, $profile, $instructions );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$record = array(
			'id'            => $method_id ? $method_id : self::generate_id(),
			'title'         => $title,
			'instructions'  => $instructions,
			'instructions_fr' => isset( $input['instructions_fr'] ) ? wp_kses_post( trim( $input['instructions_fr'] ) ) : '',
			'instructions_ar' => isset( $input['instructions_ar'] ) ? wp_kses_post( trim( $input['instructions_ar'] ) ) : '',
			'profile_type'  => $profile_type,
			'profile'       => $profile,
			'currency'      => 'DZD',
			'is_active'     => empty( $input['is_active'] ) ? 0 : 1,
			'attachment_id' => isset( $input['attachment_id'] ) ? absint( $input['attachment_id'] ) : 0,
		);

		// Typed methods ship with their own artwork, so uploads are only kept for
		// legacy methods.
		if ( ! self::supports_custom_image( $profile_type ) ) {
			$record['attachment_id'] = 0;
		}

		// Only allow images the instructor can actually see, to stop attachment ID probing.
		if ( $record['attachment_id'] && ! self::can_use_attachment( $user_id, $record['attachment_id'] ) ) {
			$record['attachment_id'] = 0;
		}

		foreach ( $methods as $index => $existing ) {
			if ( $existing['id'] === $record['id'] ) {
				$methods[ $index ] = $record;
				$found             = true;
				break;
			}
		}

		if ( ! $found ) {
			if ( count( $methods ) >= self::MAX_METHODS ) {
				return new \WP_Error(
					'tioc_too_many',
					sprintf(
						/* translators: %d: maximum number of methods */
						__( 'You can publish at most %d payment methods.', 'tutor-learning-suite' ),
						self::MAX_METHODS
					)
				);
			}

			$methods[] = $record;
		}

		update_user_meta( $user_id, self::META_KEY, $methods );

		/**
		 * Fires after an instructor saves a payment method.
		 *
		 * @param int   $user_id Instructor user ID.
		 * @param array $record  Saved method.
		 */
		do_action( 'tioc_method_saved', $user_id, $record );

		return $record;
	}

	/**
	 * Delete a method.
	 *
	 * @param int    $user_id   Instructor user ID.
	 * @param string $method_id Method ID.
	 *
	 * @return bool
	 */
	public static function delete( $user_id, $method_id ) {
		$user_id   = absint( $user_id );
		$method_id = sanitize_key( $method_id );

		if ( ! $user_id || ! $method_id ) {
			return false;
		}

		$methods = self::get( $user_id );
		$kept    = array();
		$removed = false;

		foreach ( $methods as $method ) {
			if ( $method['id'] === $method_id ) {
				$removed = true;
				continue;
			}
			$kept[] = $method;
		}

		if ( ! $removed ) {
			return false;
		}

		update_user_meta( $user_id, self::META_KEY, $kept );

		return true;
	}

	/**
	 * Read the instructor's general note.
	 *
	 * @param int $user_id Instructor user ID.
	 *
	 * @return string
	 */
	public static function get_note( $user_id ) {
		$note = get_user_meta( absint( $user_id ), self::NOTE_META_KEY, true );

		return is_string( $note ) ? $note : '';
	}

	/**
	 * Save the instructor's general note.
	 *
	 * @param int    $user_id Instructor user ID.
	 * @param string $note    Note, HTML allowed.
	 *
	 * @return void
	 */
	public static function save_note( $user_id, $note ) {
		update_user_meta( absint( $user_id ), self::NOTE_META_KEY, wp_kses_post( trim( (string) $note ) ) );
	}

	/**
	 * Fill in missing keys and coerce types.
	 *
	 * @param array $method Raw stored method.
	 *
	 * @return array
	 */
	private static function normalise( array $method ) {
		$profile_type  = self::valid_type( isset( $method['profile_type'] ) ? $method['profile_type'] : self::TYPE_LEGACY );
		$attachment_id = isset( $method['attachment_id'] ) ? absint( $method['attachment_id'] ) : 0;

		// Typed methods use their bundled artwork, so any image saved against one
		// before 1.3.0 is ignored rather than shown alongside the badge.
		if ( ! self::supports_custom_image( $profile_type ) ) {
			$attachment_id = 0;
		}

		return array(
			'id'              => sanitize_key( $method['id'] ),
			'title'           => sanitize_text_field( $method['title'] ),
			'instructions'    => isset( $method['instructions'] ) ? wp_kses_post( $method['instructions'] ) : '',
			'instructions_fr' => isset( $method['instructions_fr'] ) ? wp_kses_post( $method['instructions_fr'] ) : '',
			'instructions_ar' => isset( $method['instructions_ar'] ) ? wp_kses_post( $method['instructions_ar'] ) : '',
			'profile_type'    => $profile_type,
			'profile'         => self::sanitize_profile( $profile_type, isset( $method['profile'] ) && is_array( $method['profile'] ) ? $method['profile'] : array() ),
			'currency'        => 'DZD',
			'is_active'       => empty( $method['is_active'] ) ? 0 : 1,
			'attachment_id'   => $attachment_id,
		);
	}

	private static function sanitize_profile( $type, array $profile ) {
		$fields = array( 'account_holder', 'ccp_number', 'ccp_key', 'bank_name', 'rib', 'iban', 'branch', 'yassir_phone', 'phonecash_number', 'cash_location', 'cash_contact', 'other_details' );
		$clean  = array();
		foreach ( $fields as $field ) {
			$clean[ $field ] = isset( $profile[ $field ] ) ? sanitize_text_field( $profile[ $field ] ) : '';
		}
		return $clean;
	}

	/**
	 * Validate either the legacy instructions or the typed payment profile.
	 *
	 * Legacy methods remain valid with instructions alone. Typed methods may use
	 * their required profile fields instead of duplicating those details in the
	 * generic instructions field.
	 *
	 * @param string $type         Payment profile type.
	 * @param array  $profile      Sanitized profile fields.
	 * @param string $instructions Legacy instructions.
	 * @return true|\WP_Error
	 */
	private static function validate_method_details( $type, array $profile, $instructions ) {
		$has_instructions = '' !== trim( wp_strip_all_tags( $instructions ) );

		if ( 'baridimob_ccp' === $type ) {
			if ( $profile['ccp_number'] || $has_instructions ) {
				return true;
			}
			return new \WP_Error( 'tioc_missing_ccp', __( 'Enter a CCP number or payment instructions. Your phone number is set once under "How students can reach you".', 'tutor-learning-suite' ) );
		}

		if ( 'yassir_pay' === $type ) {
			if ( $profile['yassir_phone'] || $has_instructions ) {
				return true;
			}
			return new \WP_Error( 'tioc_missing_yassir', __( 'Enter the phone number linked to your Yassir Pay account, or payment instructions.', 'tutor-learning-suite' ) );
		}

		if ( 'phone_cash' === $type ) {
			if ( $profile['phonecash_number'] || $has_instructions ) {
				return true;
			}
			return new \WP_Error( 'tioc_missing_phonecash', __( 'Enter your Phone Cash number, or payment instructions.', 'tutor-learning-suite' ) );
		}

		if ( 'bank_transfer' === $type ) {
			if ( $profile['rib'] || $profile['iban'] || $has_instructions ) {
				return true;
			}
			return new \WP_Error( 'tioc_missing_bank', __( 'Enter a RIB, IBAN, or bank-transfer instructions.', 'tutor-learning-suite' ) );
		}

		if ( 'cash' === $type ) {
			if ( $profile['cash_location'] || $has_instructions ) {
				return true;
			}
			return new \WP_Error( 'tioc_missing_cash_location', __( 'Enter where the student can pay cash or add payment instructions.', 'tutor-learning-suite' ) );
		}

		if ( $profile['other_details'] || $has_instructions ) {
			return true;
		}

		return new \WP_Error( 'tioc_missing_other_details', __( 'Enter the payment details for this method.', 'tutor-learning-suite' ) );
	}

	public static function masked_profile( array $method ) {
		$profile = isset( $method['profile'] ) && is_array( $method['profile'] ) ? $method['profile'] : array();
		foreach ( array( 'ccp_number', 'ccp_key', 'rib', 'iban', 'yassir_phone', 'phonecash_number', 'cash_contact' ) as $field ) {
			if ( ! empty( $profile[ $field ] ) ) {
				$value = (string) $profile[ $field ];
				$profile[ $field ] = strlen( $value ) > 4 ? str_repeat( '•', max( 0, strlen( $value ) - 4 ) ) . substr( $value, -4 ) : $value;
			}
		}
		return $profile;
	}

	/**
	 * Immutable, student-visible copy of a method at the time of checkout.
	 *
	 * @param array $method Method record.
	 * @return array
	 */
	public static function snapshot( array $method ) {
		return array(
			'image'           => self::image_url( $method ),
			'id'              => isset( $method['id'] ) ? sanitize_key( $method['id'] ) : '',
			'title'           => isset( $method['title'] ) ? sanitize_text_field( $method['title'] ) : '',
			'instructions'    => isset( $method['instructions'] ) ? wp_kses_post( $method['instructions'] ) : '',
			'instructions_fr' => isset( $method['instructions_fr'] ) ? wp_kses_post( $method['instructions_fr'] ) : '',
			'instructions_ar' => isset( $method['instructions_ar'] ) ? wp_kses_post( $method['instructions_ar'] ) : '',
			'profile_type'    => self::valid_type( isset( $method['profile_type'] ) ? $method['profile_type'] : self::TYPE_LEGACY ),
			'profile'         => isset( $method['profile'] ) && is_array( $method['profile'] ) ? $method['profile'] : array(),
			'currency'        => 'DZD',
			'attachment_id'   => isset( $method['attachment_id'] ) ? absint( $method['attachment_id'] ) : 0,
		);
	}

	/**
	 * Unique-enough identifier for a method.
	 *
	 * @return string
	 */
	private static function generate_id() {
		return 'm' . substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 12 );
	}

	/**
	 * Whether the instructor may attach the given media item.
	 *
	 * @param int $user_id       Instructor user ID.
	 * @param int $attachment_id Attachment ID.
	 *
	 * @return bool
	 */
	private static function can_use_attachment( $user_id, $attachment_id ) {
		$attachment = get_post( $attachment_id );

		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return false;
		}

		if ( (int) $attachment->post_author === (int) $user_id ) {
			return true;
		}

		return user_can( $user_id, 'edit_post', $attachment_id );
	}
}
