<?php
/**
 * Instructor dashboard: publish your own payment details.
 *
 * Loaded by Tutor as `dashboard/offline-payment-setup`.
 * Override by copying to
 * `<theme>/tutor-offline-payment/dashboard/offline-payment-setup.php`.
 *
 * @package TutorInstructorOfflinePayment
 */

use TutorInstructorOfflinePayment\Contact;
use TutorInstructorOfflinePayment\Methods;
use TutorInstructorOfflinePayment\Settings;

defined( 'ABSPATH' ) || exit;

$tioc_user_id = get_current_user_id();
$tioc_methods = Methods::get( $tioc_user_id );
$tioc_note    = Methods::get_note( $tioc_user_id );
$tioc_active  = 0;

foreach ( $tioc_methods as $tioc_method ) {
	$tioc_active += $tioc_method['is_active'] ? 1 : 0;
}

$tioc_can_add    = count( $tioc_methods ) < Methods::MAX_METHODS;
$tioc_contact    = Contact::get( $tioc_user_id );
$tioc_imported   = Contact::imported( $tioc_user_id );
$tioc_configured = Contact::is_configured( $tioc_user_id );
$tioc_can_import = ! $tioc_configured && ( $tioc_imported['phone'] || $tioc_imported['whatsapp'] || $tioc_imported['facebook'] || $tioc_imported['instagram'] || $tioc_imported['tiktok'] || $tioc_imported['x'] );
?>
<div class="tioc-dash tioc-dash-setup">

	<div class="tioc-dash-head">
		<h3 class="tioc-dash-title"><?php esc_html_e( 'Payment Details', 'tutor-instructor-offline-payment' ); ?></h3>
		<p class="tioc-dash-subtitle">
			<?php esc_html_e( 'Students pay you directly. Whatever you publish here is what they see at checkout, so include everything they need to send you the money and everything you need to recognise the payment afterwards.', 'tutor-instructor-offline-payment' ); ?>
		</p>
	</div>

	<div class="tioc-notice-slot" role="status" aria-live="polite"></div>

	<?php if ( ! $tioc_active ) : ?>
		<div class="tioc-alert tioc-alert-warning">
			<?php
			echo esc_html(
				Settings::get( 'block_unconfigured', 1 )
					? __( 'You have no active payment method, so your paid courses cannot currently be bought. Add one below and switch it on.', 'tutor-instructor-offline-payment' )
					: __( 'You have no active payment method, so students will not be told how to pay you. Add one below and switch it on.', 'tutor-instructor-offline-payment' )
			);
			?>
		</div>
	<?php endif; ?>

	<section class="tioc-card">
		<h4 class="tioc-card-title"><?php esc_html_e( 'How students can reach you', 'tutor-instructor-offline-payment' ); ?></h4>
		<p class="tioc-muted">
			<?php esc_html_e( 'Shown at checkout with every payment method, so a student who is halfway through paying you can ask a question instead of abandoning the order. Set once here, not per method.', 'tutor-instructor-offline-payment' ); ?>
		</p>

		<?php if ( ! $tioc_configured && ( $tioc_contact['phone'] || $tioc_contact['whatsapp'] ) ) : ?>
			<p class="tioc-help">
				<?php esc_html_e( 'These numbers were imported from your Tutor LMS profile. Save them to confirm, or change them first.', 'tutor-instructor-offline-payment' ); ?>
			</p>
		<?php endif; ?>

		<form class="tioc-form" data-tioc-action="tioc_save_contact">
			<div class="tioc-field">
				<label class="tioc-label" for="tioc-contact-phone"><?php esc_html_e( 'Phone number', 'tutor-instructor-offline-payment' ); ?></label>
				<input class="tioc-input" type="tel" id="tioc-contact-phone" name="phone" value="<?php echo esc_attr( $tioc_contact['phone'] ); ?>" placeholder="<?php esc_attr_e( '0555 12 34 56', 'tutor-instructor-offline-payment' ); ?>" maxlength="32">
				<label class="tioc-checkbox">
					<input type="checkbox" name="show_phone" value="1" <?php checked( (bool) $tioc_contact['show_phone'] ); ?>>
					<?php esc_html_e( 'Show my phone number at checkout', 'tutor-instructor-offline-payment' ); ?>
				</label>
			</div>

			<div class="tioc-field">
				<label class="tioc-label" for="tioc-contact-whatsapp"><?php esc_html_e( 'WhatsApp number', 'tutor-instructor-offline-payment' ); ?></label>
				<input class="tioc-input" type="tel" id="tioc-contact-whatsapp" name="whatsapp" value="<?php echo esc_attr( $tioc_contact['whatsapp'] ); ?>" placeholder="<?php esc_attr_e( 'Same as above unless you use another number', 'tutor-instructor-offline-payment' ); ?>" maxlength="32">
				<label class="tioc-checkbox">
					<input type="checkbox" name="show_whatsapp" value="1" <?php checked( (bool) $tioc_contact['show_whatsapp'] ); ?>>
					<?php esc_html_e( 'Show a WhatsApp button at checkout', 'tutor-instructor-offline-payment' ); ?>
				</label>
				<p class="tioc-help">
					<?php esc_html_e( 'Leave empty to reuse your phone number. The button opens WhatsApp with the course and the amount already written, so you know what the student is paying for.', 'tutor-instructor-offline-payment' ); ?>
				</p>
			</div>

			<div class="tioc-field tioc-social-settings">
				<label class="tioc-label"><?php esc_html_e( 'Social profiles', 'tutor-instructor-offline-payment' ); ?></label>
				<div class="tioc-social-grid">
					<div class="tioc-social-setting">
						<input class="tioc-input" type="url" id="tioc-contact-facebook" name="facebook" value="<?php echo esc_attr( $tioc_contact['facebook'] ); ?>" placeholder="Facebook URL">
						<label class="tioc-checkbox"><input type="checkbox" name="show_facebook" value="1" <?php checked( ! empty( $tioc_contact['show_facebook'] ) ); ?>> <?php esc_html_e( 'Show Facebook', 'tutor-instructor-offline-payment' ); ?></label>
					</div>
					<div class="tioc-social-setting">
						<input class="tioc-input" type="url" id="tioc-contact-instagram" name="instagram" value="<?php echo esc_attr( $tioc_contact['instagram'] ); ?>" placeholder="Instagram URL">
						<label class="tioc-checkbox"><input type="checkbox" name="show_instagram" value="1" <?php checked( ! empty( $tioc_contact['show_instagram'] ) ); ?>> <?php esc_html_e( 'Show Instagram', 'tutor-instructor-offline-payment' ); ?></label>
					</div>
					<div class="tioc-social-setting">
						<input class="tioc-input" type="url" id="tioc-contact-tiktok" name="tiktok" value="<?php echo esc_attr( $tioc_contact['tiktok'] ); ?>" placeholder="TikTok URL">
						<label class="tioc-checkbox"><input type="checkbox" name="show_tiktok" value="1" <?php checked( ! empty( $tioc_contact['show_tiktok'] ) ); ?>> <?php esc_html_e( 'Show TikTok', 'tutor-instructor-offline-payment' ); ?></label>
					</div>
					<div class="tioc-social-setting">
						<input class="tioc-input" type="url" id="tioc-contact-x" name="x" value="<?php echo esc_attr( $tioc_contact['x'] ); ?>" placeholder="X / Twitter URL">
						<label class="tioc-checkbox"><input type="checkbox" name="show_x" value="1" <?php checked( ! empty( $tioc_contact['show_x'] ) ); ?>> <?php esc_html_e( 'Show X / Twitter', 'tutor-instructor-offline-payment' ); ?></label>
					</div>
				</div>
				<p class="tioc-help"><?php esc_html_e( 'Each network has its own checkout switch. Turn off only the accounts you do not want students to see.', 'tutor-instructor-offline-payment' ); ?></p>
			</div>

			<div class="tioc-form-actions">
				<button type="submit" class="tioc-btn tioc-btn-primary"><?php esc_html_e( 'Save contact details', 'tutor-instructor-offline-payment' ); ?></button>
			</div>
		</form>

		<?php if ( $tioc_can_import ) : ?>
			<form class="tioc-inline-form tioc-mt-8" data-tioc-action="tioc_import_contact">
				<button type="submit" class="tioc-btn tioc-btn-ghost">
					<?php esc_html_e( 'Import from my Tutor LMS profile', 'tutor-instructor-offline-payment' ); ?>
				</button>
			</form>
		<?php endif; ?>
	</section>

	<section class="tioc-card">
		<h4 class="tioc-card-title"><?php esc_html_e( 'Your payment methods', 'tutor-instructor-offline-payment' ); ?></h4>

		<?php if ( empty( $tioc_methods ) ) : ?>
			<p class="tioc-muted"><?php esc_html_e( 'Nothing published yet.', 'tutor-instructor-offline-payment' ); ?></p>
		<?php else : ?>
			<ul class="tioc-method-list">
				<?php foreach ( $tioc_methods as $tioc_method ) : ?>
					<?php $tioc_edit_id = 'tioc-edit-' . $tioc_method['id']; ?>
					<li class="tioc-method-row">
						<div class="tioc-method-row-main">
							<div class="tioc-method-row-title">
								<?php echo esc_html( $tioc_method['title'] ); ?>
								<span class="tioc-badge is-type"><?php echo esc_html( Methods::type_label( $tioc_method['profile_type'] ) ); ?></span>
								<span class="tioc-badge <?php echo esc_attr( $tioc_method['is_active'] ? 'is-paid' : 'is-pending' ); ?>">
									<?php
									echo esc_html(
										$tioc_method['is_active']
											? __( 'Shown at checkout', 'tutor-instructor-offline-payment' )
											: __( 'Hidden', 'tutor-instructor-offline-payment' )
									);
									?>
								</span>
							</div>
							<?php $tioc_details = Methods::public_details( $tioc_method ); ?>
							<?php if ( $tioc_details ) : ?>
								<ul class="tioc-method-detail-list">
									<?php foreach ( $tioc_details as $tioc_detail ) : ?>
										<li>
											<span class="tioc-method-detail-label"><?php echo esc_html( $tioc_detail['label'] ); ?></span>
											<span class="tioc-method-detail-value <?php echo $tioc_detail['copy'] ? 'is-code' : ''; ?>"><?php echo esc_html( $tioc_detail['value'] ); ?></span>
										</li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
							<div class="tioc-method-row-body">
								<?php echo wp_kses_post( wpautop( $tioc_method['instructions'] ) ); ?>
							</div>
							<?php $tioc_thumb = Methods::image_url( $tioc_method, 'thumbnail' ); ?>
							<?php if ( $tioc_thumb ) : ?>
								<img class="tioc-method-row-thumb" src="<?php echo esc_url( $tioc_thumb ); ?>" alt="">
							<?php endif; ?>
						</div>

						<div class="tioc-method-row-actions">
							<button type="button" class="tioc-btn tioc-btn-ghost" data-tioc-toggle="<?php echo esc_attr( $tioc_edit_id ); ?>">
								<?php esc_html_e( 'Edit', 'tutor-instructor-offline-payment' ); ?>
							</button>

							<form class="tioc-inline-form" data-tioc-action="tioc_delete_method" data-tioc-confirm="delete">
								<input type="hidden" name="method_id" value="<?php echo esc_attr( $tioc_method['id'] ); ?>">
								<button type="submit" class="tioc-btn tioc-btn-danger-ghost">
									<?php esc_html_e( 'Remove', 'tutor-instructor-offline-payment' ); ?>
								</button>
							</form>
						</div>

						<div class="tioc-collapse" id="<?php echo esc_attr( $tioc_edit_id ); ?>" hidden>
							<?php
							\TutorInstructorOfflinePayment\Templates::render(
								'dashboard/partials/method-form',
								array(
									'method' => $tioc_method,
									'prefix' => $tioc_method['id'],
								)
							);
							?>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</section>

	<section class="tioc-card">
		<h4 class="tioc-card-title"><?php esc_html_e( 'Add a payment method', 'tutor-instructor-offline-payment' ); ?></h4>

		<?php if ( ! $tioc_can_add ) : ?>
			<p class="tioc-muted">
				<?php
				printf(
					/* translators: %d: maximum number of payment methods */
					esc_html__( 'You have reached the limit of %d payment methods. Remove one to add another.', 'tutor-instructor-offline-payment' ),
					absint( Methods::MAX_METHODS )
				);
				?>
			</p>
		<?php else : ?>
			<?php
			\TutorInstructorOfflinePayment\Templates::render(
				'dashboard/partials/method-form',
				array(
					'method' => null,
					'prefix' => 'new',
				)
			);
			?>
		<?php endif; ?>
	</section>

	<section class="tioc-card">
		<h4 class="tioc-card-title"><?php esc_html_e( 'General note', 'tutor-instructor-offline-payment' ); ?></h4>
		<p class="tioc-muted">
			<?php esc_html_e( 'Shown once above your payment methods at checkout, whichever method the student picks. Good for opening hours, a phone number, or how long confirmation usually takes.', 'tutor-instructor-offline-payment' ); ?>
		</p>

		<form class="tioc-form" data-tioc-action="tioc_save_note">
			<div class="tioc-field">
				<label class="tioc-label" for="tioc-note-field"><?php esc_html_e( 'Note to students', 'tutor-instructor-offline-payment' ); ?></label>
				<textarea class="tioc-input" id="tioc-note-field" name="note" rows="4" placeholder="<?php esc_attr_e( 'I confirm payments every evening. WhatsApp me on … if it is urgent.', 'tutor-instructor-offline-payment' ); ?>"><?php echo esc_textarea( $tioc_note ); ?></textarea>
			</div>
			<div class="tioc-form-actions">
				<button type="submit" class="tioc-btn tioc-btn-primary"><?php esc_html_e( 'Save note', 'tutor-instructor-offline-payment' ); ?></button>
			</div>
		</form>
	</section>

	<section class="tioc-card">
		<h4 class="tioc-card-title"><?php esc_html_e( 'Notifications', 'tutor-instructor-offline-payment' ); ?></h4>
		<p class="tioc-muted">
			<?php esc_html_e( 'New payments always appear on your "Offline Payments" dashboard whether or not you get an email.', 'tutor-instructor-offline-payment' ); ?>
		</p>

		<form class="tioc-form" data-tioc-action="tioc_save_email_pref">
			<label class="tioc-checkbox-row">
				<input type="checkbox" name="mute_new_payment_email" value="1" <?php checked( (bool) get_user_meta( $tioc_user_id, 'tioc_mute_new_payment_email', true ) ); ?>>
				<?php esc_html_e( 'Do not email me when a student submits a new payment', 'tutor-instructor-offline-payment' ); ?>
			</label>
			<div class="tioc-form-actions">
				<button type="submit" class="tioc-btn tioc-btn-primary"><?php esc_html_e( 'Save preference', 'tutor-instructor-offline-payment' ); ?></button>
			</div>
		</form>
	</section>
</div>
