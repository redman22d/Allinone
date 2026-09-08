<?php
/**
 * One payment-method form, used both for adding and for editing.
 *
 * Typed methods (BaridiMob/CCP, bank transfer, Yassir Pay, Phone Cash, cash) use
 * their own bundled badge, so the image upload only appears for the legacy
 * "Other" type. The instructor's phone and WhatsApp numbers are not asked for
 * here: they are set once for every method under "How students can reach you".
 *
 * @var array|null $method Existing method, or null when adding.
 * @var string     $prefix Unique suffix for element IDs.
 *
 * @package TutorInstructorOfflinePayment
 */

use TutorInstructorOfflinePayment\Methods;
use TutorInstructorOfflinePayment\Settings;

defined( 'ABSPATH' ) || exit;

$method = isset( $method ) && is_array( $method ) ? $method : null;
$prefix = isset( $prefix ) ? sanitize_key( $prefix ) : 'new';

$tioc_id           = $method ? $method['id'] : '';
$tioc_title        = $method ? $method['title'] : '';
$tioc_instructions = $method ? $method['instructions'] : '';
$tioc_profile_type = $method && ! empty( $method['profile_type'] ) ? Methods::valid_type( $method['profile_type'] ) : 'baridimob_ccp';
$tioc_profile      = $method && ! empty( $method['profile'] ) && is_array( $method['profile'] ) ? $method['profile'] : array();
$tioc_profile      = wp_parse_args(
	$tioc_profile,
	array(
		'account_holder'   => '',
		'ccp_number'       => '',
		'ccp_key'          => '',
		'bank_name'        => '',
		'rib'              => '',
		'iban'             => '',
		'branch'           => '',
		'yassir_phone'     => '',
		'phonecash_number' => '',
		'cash_location'    => '',
		'cash_contact'     => '',
		'other_details'    => '',
	)
);
$tioc_is_active  = $method ? (bool) $method['is_active'] : true;
$tioc_attachment = $method && Methods::supports_custom_image( $tioc_profile_type ) ? (int) $method['attachment_id'] : 0;
$tioc_thumb      = $tioc_attachment ? wp_get_attachment_image_url( $tioc_attachment, 'thumbnail' ) : '';

/**
 * Print the bundled badge preview for a typed method.
 *
 * @param string $type Payment type key.
 * @return void
 */
$tioc_badge = static function ( $type ) {
	$url = Methods::default_image_url( $type );

	if ( ! $url ) {
		return;
	}
	?>
	<div class="tioc-method-badge-preview">
		<img src="<?php echo esc_url( $url ); ?>" alt="<?php echo esc_attr( Methods::type_label( $type ) ); ?>">
		<span class="tioc-help"><?php esc_html_e( 'Students see this badge automatically. No image to upload.', 'tutor-instructor-offline-payment' ); ?></span>
	</div>
	<?php
};
?>
<form class="tioc-form tioc-method-form" data-tioc-action="tioc_save_method" enctype="multipart/form-data">
	<input type="hidden" name="method_id" value="<?php echo esc_attr( $tioc_id ); ?>">
	<input type="hidden" name="attachment_id" value="<?php echo esc_attr( $tioc_attachment ); ?>">

	<div class="tioc-field">
		<label class="tioc-label" for="tioc-title-<?php echo esc_attr( $prefix ); ?>">
			<?php esc_html_e( 'Name', 'tutor-instructor-offline-payment' ); ?>
		</label>
		<input
			class="tioc-input"
			type="text"
			id="tioc-title-<?php echo esc_attr( $prefix ); ?>"
			name="title"
			value="<?php echo esc_attr( $tioc_title ); ?>"
			placeholder="<?php esc_attr_e( 'BaridiMob, Bank transfer, Yassir Pay, Cash in person…', 'tutor-instructor-offline-payment' ); ?>"
			maxlength="120"
			required>
		<p class="tioc-help"><?php esc_html_e( 'What the student sees as the option label.', 'tutor-instructor-offline-payment' ); ?></p>
	</div>

	<div class="tioc-field">
		<label class="tioc-label" for="tioc-profile-type-<?php echo esc_attr( $prefix ); ?>">
			<?php esc_html_e( 'Payment type', 'tutor-instructor-offline-payment' ); ?>
		</label>
		<select class="tioc-input tioc-profile-type" id="tioc-profile-type-<?php echo esc_attr( $prefix ); ?>" name="profile_type">
			<?php foreach ( Methods::types() as $tioc_type_key => $tioc_type_label ) : ?>
				<option value="<?php echo esc_attr( $tioc_type_key ); ?>" <?php selected( $tioc_profile_type, $tioc_type_key ); ?>>
					<?php echo esc_html( $tioc_type_label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="tioc-help"><?php esc_html_e( 'The type decides which fields the student sees, and which badge is shown next to the option.', 'tutor-instructor-offline-payment' ); ?></p>
	</div>

	<div class="tioc-profile-fields" data-tioc-profile-fields>
		<div class="tioc-field" data-tioc-profile="baridimob_ccp">
			<?php $tioc_badge( 'baridimob_ccp' ); ?>
			<label class="tioc-label" for="tioc-ccp-holder-<?php echo esc_attr( $prefix ); ?>"><?php esc_html_e( 'Account holder', 'tutor-instructor-offline-payment' ); ?></label>
			<input class="tioc-input" type="text" id="tioc-ccp-holder-<?php echo esc_attr( $prefix ); ?>" name="profile[account_holder]" value="<?php echo esc_attr( $tioc_profile['account_holder'] ); ?>" placeholder="<?php esc_attr_e( 'Name exactly as it appears on the account', 'tutor-instructor-offline-payment' ); ?>" <?php disabled( 'baridimob_ccp' !== $tioc_profile_type ); ?>>
			<label class="tioc-label" for="tioc-ccp-number-<?php echo esc_attr( $prefix ); ?>"><?php esc_html_e( 'CCP number', 'tutor-instructor-offline-payment' ); ?></label>
			<input class="tioc-input" type="text" id="tioc-ccp-number-<?php echo esc_attr( $prefix ); ?>" name="profile[ccp_number]" value="<?php echo esc_attr( $tioc_profile['ccp_number'] ); ?>" inputmode="numeric">
			<label class="tioc-label" for="tioc-ccp-key-<?php echo esc_attr( $prefix ); ?>"><?php esc_html_e( 'CCP key (optional)', 'tutor-instructor-offline-payment' ); ?></label>
			<input class="tioc-input" type="text" id="tioc-ccp-key-<?php echo esc_attr( $prefix ); ?>" name="profile[ccp_key]" value="<?php echo esc_attr( $tioc_profile['ccp_key'] ); ?>" inputmode="numeric">
			<p class="tioc-help"><?php esc_html_e( 'Your BaridiMob phone number is no longer set per method. Students see the phone and WhatsApp numbers from "How students can reach you" on every method.', 'tutor-instructor-offline-payment' ); ?></p>
		</div>

		<div class="tioc-field" data-tioc-profile="bank_transfer">
			<?php $tioc_badge( 'bank_transfer' ); ?>
			<label class="tioc-label" for="tioc-bank-holder-<?php echo esc_attr( $prefix ); ?>"><?php esc_html_e( 'Account holder', 'tutor-instructor-offline-payment' ); ?></label>
			<input class="tioc-input" type="text" id="tioc-bank-holder-<?php echo esc_attr( $prefix ); ?>" name="profile[account_holder]" value="<?php echo esc_attr( $tioc_profile['account_holder'] ); ?>" <?php disabled( 'bank_transfer' !== $tioc_profile_type ); ?>>
			<label class="tioc-label" for="tioc-bank-name-<?php echo esc_attr( $prefix ); ?>"><?php esc_html_e( 'Bank name (optional)', 'tutor-instructor-offline-payment' ); ?></label>
			<input class="tioc-input" type="text" id="tioc-bank-name-<?php echo esc_attr( $prefix ); ?>" name="profile[bank_name]" value="<?php echo esc_attr( $tioc_profile['bank_name'] ); ?>">
			<label class="tioc-label" for="tioc-rib-<?php echo esc_attr( $prefix ); ?>"><?php esc_html_e( 'RIB', 'tutor-instructor-offline-payment' ); ?></label>
			<input class="tioc-input" type="text" id="tioc-rib-<?php echo esc_attr( $prefix ); ?>" name="profile[rib]" value="<?php echo esc_attr( $tioc_profile['rib'] ); ?>" inputmode="numeric">
			<label class="tioc-label" for="tioc-iban-<?php echo esc_attr( $prefix ); ?>"><?php esc_html_e( 'IBAN', 'tutor-instructor-offline-payment' ); ?></label>
			<input class="tioc-input" type="text" id="tioc-iban-<?php echo esc_attr( $prefix ); ?>" name="profile[iban]" value="<?php echo esc_attr( $tioc_profile['iban'] ); ?>">
			<label class="tioc-label" for="tioc-branch-<?php echo esc_attr( $prefix ); ?>"><?php esc_html_e( 'Branch (optional)', 'tutor-instructor-offline-payment' ); ?></label>
			<input class="tioc-input" type="text" id="tioc-branch-<?php echo esc_attr( $prefix ); ?>" name="profile[branch]" value="<?php echo esc_attr( $tioc_profile['branch'] ); ?>">
		</div>

		<div class="tioc-field" data-tioc-profile="yassir_pay">
			<?php $tioc_badge( 'yassir_pay' ); ?>
			<label class="tioc-label" for="tioc-yassir-phone-<?php echo esc_attr( $prefix ); ?>"><?php esc_html_e( 'Yassir Pay number', 'tutor-instructor-offline-payment' ); ?></label>
			<input class="tioc-input" type="tel" id="tioc-yassir-phone-<?php echo esc_attr( $prefix ); ?>" name="profile[yassir_phone]" value="<?php echo esc_attr( $tioc_profile['yassir_phone'] ); ?>" placeholder="<?php esc_attr_e( '0555 12 34 56', 'tutor-instructor-offline-payment' ); ?>">
			<label class="tioc-label" for="tioc-yassir-holder-<?php echo esc_attr( $prefix ); ?>"><?php esc_html_e( 'Account holder (optional)', 'tutor-instructor-offline-payment' ); ?></label>
			<input class="tioc-input" type="text" id="tioc-yassir-holder-<?php echo esc_attr( $prefix ); ?>" name="profile[account_holder]" value="<?php echo esc_attr( $tioc_profile['account_holder'] ); ?>" <?php disabled( 'yassir_pay' !== $tioc_profile_type ); ?>>
			<p class="tioc-help"><?php esc_html_e( 'The phone number linked to your Yassir Pay wallet. It can be different from your contact number.', 'tutor-instructor-offline-payment' ); ?></p>
		</div>

		<div class="tioc-field" data-tioc-profile="phone_cash">
			<?php $tioc_badge( 'phone_cash' ); ?>
			<label class="tioc-label" for="tioc-phonecash-<?php echo esc_attr( $prefix ); ?>"><?php esc_html_e( 'Phone Cash number', 'tutor-instructor-offline-payment' ); ?></label>
			<input class="tioc-input" type="tel" id="tioc-phonecash-<?php echo esc_attr( $prefix ); ?>" name="profile[phonecash_number]" value="<?php echo esc_attr( $tioc_profile['phonecash_number'] ); ?>" placeholder="<?php esc_attr_e( '0555 12 34 56', 'tutor-instructor-offline-payment' ); ?>">
			<label class="tioc-label" for="tioc-phonecash-holder-<?php echo esc_attr( $prefix ); ?>"><?php esc_html_e( 'Account holder (optional)', 'tutor-instructor-offline-payment' ); ?></label>
			<input class="tioc-input" type="text" id="tioc-phonecash-holder-<?php echo esc_attr( $prefix ); ?>" name="profile[account_holder]" value="<?php echo esc_attr( $tioc_profile['account_holder'] ); ?>" <?php disabled( 'phone_cash' !== $tioc_profile_type ); ?>>
		</div>

		<div class="tioc-field" data-tioc-profile="cash">
			<?php $tioc_badge( 'cash' ); ?>
			<label class="tioc-label" for="tioc-cash-location-<?php echo esc_attr( $prefix ); ?>"><?php esc_html_e( 'Cash payment location', 'tutor-instructor-offline-payment' ); ?></label>
			<input class="tioc-input" type="text" id="tioc-cash-location-<?php echo esc_attr( $prefix ); ?>" name="profile[cash_location]" value="<?php echo esc_attr( $tioc_profile['cash_location'] ); ?>">
			<label class="tioc-label" for="tioc-cash-contact-<?php echo esc_attr( $prefix ); ?>"><?php esc_html_e( 'Ask for (optional)', 'tutor-instructor-offline-payment' ); ?></label>
			<input class="tioc-input" type="text" id="tioc-cash-contact-<?php echo esc_attr( $prefix ); ?>" name="profile[cash_contact]" value="<?php echo esc_attr( $tioc_profile['cash_contact'] ); ?>">
		</div>

		<div class="tioc-field" data-tioc-profile="other">
			<label class="tioc-label" for="tioc-other-details-<?php echo esc_attr( $prefix ); ?>"><?php esc_html_e( 'Other payment details', 'tutor-instructor-offline-payment' ); ?></label>
			<input class="tioc-input" type="text" id="tioc-other-details-<?php echo esc_attr( $prefix ); ?>" name="profile[other_details]" value="<?php echo esc_attr( $tioc_profile['other_details'] ); ?>">
		</div>
	</div>

	<div class="tioc-field">
		<label class="tioc-label" for="tioc-instructions-<?php echo esc_attr( $prefix ); ?>">
			<?php esc_html_e( 'Instructions (optional)', 'tutor-instructor-offline-payment' ); ?>
		</label>
		<textarea
			class="tioc-input"
			id="tioc-instructions-<?php echo esc_attr( $prefix ); ?>"
			name="instructions"
			rows="4"
			placeholder="<?php esc_attr_e( "Put your full name in the transfer reference, then send me the receipt.", 'tutor-instructor-offline-payment' ); ?>"><?php echo esc_textarea( $tioc_instructions ); ?></textarea>
		<p class="tioc-help">
			<?php esc_html_e( 'Anything the fields above do not cover. The typed fields are enough on their own.', 'tutor-instructor-offline-payment' ); ?>
		</p>
	</div>

	<?php /* Only the legacy type keeps a custom image: every other type has its own badge. */ ?>
	<div class="tioc-field" data-tioc-profile="other">
		<label class="tioc-label" for="tioc-image-<?php echo esc_attr( $prefix ); ?>">
			<?php esc_html_e( 'Image (optional)', 'tutor-instructor-offline-payment' ); ?>
		</label>

		<?php if ( $tioc_thumb ) : ?>
			<div class="tioc-current-image">
				<img src="<?php echo esc_url( $tioc_thumb ); ?>" alt="">
				<label class="tioc-checkbox">
					<input type="checkbox" name="remove_image" value="1">
					<?php esc_html_e( 'Remove this image', 'tutor-instructor-offline-payment' ); ?>
				</label>
			</div>
		<?php endif; ?>

		<input
			class="tioc-input"
			type="file"
			id="tioc-image-<?php echo esc_attr( $prefix ); ?>"
			name="method_image"
			accept="image/*">
		<p class="tioc-help">
			<?php
			printf(
				/* translators: %s: maximum file size */
				esc_html__( 'A QR code or a photo of your payment details. Up to %s. This image is public.', 'tutor-instructor-offline-payment' ),
				esc_html( size_format( Settings::max_upload_bytes() ) )
			);
			?>
		</p>
	</div>

	<div class="tioc-field">
		<label class="tioc-checkbox">
			<input type="checkbox" name="is_active" value="1" <?php checked( $tioc_is_active ); ?>>
			<?php esc_html_e( 'Show this method at checkout', 'tutor-instructor-offline-payment' ); ?>
		</label>
	</div>

	<div class="tioc-form-actions">
		<button type="submit" class="tioc-btn tioc-btn-primary">
			<?php
			echo esc_html(
				$method
					? __( 'Save changes', 'tutor-instructor-offline-payment' )
					: __( 'Add payment method', 'tutor-instructor-offline-payment' )
			);
			?>
		</button>
	</div>
</form>
