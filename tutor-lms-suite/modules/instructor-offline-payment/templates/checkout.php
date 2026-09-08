<?php
/**
 * Offline checkout, replacing `tutor/templates/ecommerce/checkout.php`.
 *
 * The cart is presented grouped by course author: each author is a separate
 * payment card with their own instructions, and each becomes its own order.
 *
 * Override by copying to `<theme>/tutor-offline-payment/checkout.php`.
 *
 * @package TutorInstructorOfflinePayment
 */

use Tutor\Ecommerce\Settings as TutorEcommerceSettings;
use Tutor\Ecommerce\Tax;
use Tutor\GDPR\Controllers\LegalConsent;
use TutorInstructorOfflinePayment\Checkout;
use TutorInstructorOfflinePayment\Contact;
use TutorInstructorOfflinePayment\Methods;
use TutorInstructorOfflinePayment\Settings;

defined( 'ABSPATH' ) || exit;

$tioc_view     = Checkout::get_view();
$tioc_logged_in = is_user_logged_in();

// Used by Tutor's own billing partial to switch to the tighter checkout grid.
$is_checkout_page = true;

$tioc_tax_in_price = class_exists( '\Tutor\Ecommerce\Tax' ) && Tax::is_tax_included_in_price();
$tioc_coupon_box   = class_exists( '\Tutor\Ecommerce\Settings' )
	&& TutorEcommerceSettings::is_coupon_usage_enabled()
	&& ! $tioc_view->coupon_applied
	&& ! $tioc_view->is_zero_price;

$tioc_checkout_url = remove_query_arg( array( 'coupon_code', 'tioc_order_placed' ) );
$tioc_buy_now_id   = isset( $_REQUEST['course_id'] ) ? absint( $_REQUEST['course_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$tioc_can_submit = $tioc_logged_in && ! empty( $tioc_view->groups ) && ! $tioc_view->blocked;

$tioc_submit_text = $tioc_view->is_zero_price
	? __( 'Place order', 'tutor-instructor-offline-payment' )
	: __( 'Submit payment for confirmation', 'tutor-instructor-offline-payment' );

/**
 * Filter the submit button label.
 *
 * @param string $text Button label.
 * @param object $view Checkout view model.
 */
$tioc_submit_text = apply_filters( 'tioc_checkout_submit_text', $tioc_submit_text, $tioc_view );
?>
<div class="tutor-checkout-page tioc-checkout">
<div class="tutor-container">
<div class="tutor-checkout-container">

	<?php if ( $tioc_coupon_box ) : ?>
		<?php /* Kept outside the order form so applying a coupon is a plain page reload. */ ?>
		<form method="get" action="<?php echo esc_url( $tioc_checkout_url ); ?>" id="tioc-coupon-form" class="tioc-coupon-form">
			<?php if ( $tioc_buy_now_id ) : ?>
				<input type="hidden" name="course_id" value="<?php echo esc_attr( $tioc_buy_now_id ); ?>">
			<?php endif; ?>
		</form>
	<?php endif; ?>

	<?php if ( ! empty( $tioc_view->flash['message'] ) ) : ?>
		<div class="tutor-alert tutor-<?php echo esc_attr( 'success' === $tioc_view->flash['type'] ? 'success' : 'warning' ); ?>">
			<div><?php echo esc_html( $tioc_view->flash['message'] ); ?></div>
		</div>
	<?php endif; ?>

	<?php if ( Settings::get( 'checkout_notice' ) ) : ?>
		<div class="tutor-alert tutor-info tioc-checkout-notice">
			<div><?php echo wp_kses_post( Settings::get( 'checkout_notice' ) ); ?></div>
		</div>
	<?php endif; ?>

	<?php foreach ( $tioc_view->notices as $tioc_notice ) : ?>
		<div class="tutor-alert tutor-warning">
			<div><?php echo esc_html( $tioc_notice ); ?></div>
		</div>
	<?php endforeach; ?>

	<?php if ( empty( $tioc_view->groups ) ) : ?>

		<div class="tioc-empty">
			<?php tutor_utils()->tutor_empty_state( __( 'There is nothing to check out. Your cart is empty.', 'tutor-instructor-offline-payment' ) ); ?>
			<div class="tutor-text-center tutor-mt-20">
				<a class="tutor-btn tutor-btn-primary" href="<?php echo esc_url( get_post_type_archive_link( tutor()->course_post_type ) ); ?>">
					<?php esc_html_e( 'Browse courses', 'tutor-instructor-offline-payment' ); ?>
				</a>
			</div>
		</div>

	<?php else : ?>

	<form method="post" id="tioc-checkout-form" class="tioc-checkout-form" enctype="multipart/form-data">
		<input type="hidden" name="tutor_action" value="<?php echo esc_attr( Checkout::ACTION ); ?>">
		<input type="hidden" name="tioc_nonce" value="<?php echo esc_attr( wp_create_nonce( Checkout::ACTION ) ); ?>">
		<input type="hidden" name="coupon_code" value="<?php echo esc_attr( $tioc_view->coupon_code ); ?>">
		<?php if ( $tioc_buy_now_id ) : ?>
			<input type="hidden" name="course_id" value="<?php echo esc_attr( $tioc_buy_now_id ); ?>">
		<?php endif; ?>

		<div class="tutor-row tutor-g-5">

			<div class="tutor-col-md-6" tutor-checkout-details>
				<div class="tutor-checkout-details">

					<?php
					// Tutor's own `add_warning_alert()` (hooked here) expects a flat
					// array of course WP_Post objects (it reads $course->ID directly).
					// $tioc_view->groups holds per-instructor group objects, not
					// courses, so flatten before firing the hook or Tutor throws
					// "Undefined property: stdClass::$ID" here.
					$tioc_all_courses = array();
					foreach ( $tioc_view->groups as $tioc_hook_group ) {
						foreach ( $tioc_hook_group->courses as $tioc_hook_course ) {
							$tioc_all_courses[] = $tioc_hook_course;
						}
					}
					do_action( 'tutor_before_checkout_order_details', $tioc_all_courses );
					?>

					<div class="tutor-checkout-details-inner">
						<h5 class="tutor-fs-5 tutor-fw-medium tutor-color-black tutor-border-bottom tutor-pb-8">
							<?php esc_html_e( 'Order Details', 'tutor-instructor-offline-payment' ); ?>
						</h5>

						<?php foreach ( $tioc_view->groups as $tioc_group ) : ?>
							<div class="tutor-checkout-detail-item tioc-group-items">

								<?php if ( $tioc_view->multi_instructor ) : ?>
									<div class="tioc-group-heading">
										<?php
										printf(
											/* translators: %s: instructor name */
											esc_html__( 'Sold by %s', 'tutor-instructor-offline-payment' ),
											'<strong>' . esc_html( $tioc_group->instructor_name ) . '</strong>'
										);
										?>
									</div>
								<?php endif; ?>

								<div class="tutor-checkout-courses">
									<?php foreach ( $tioc_group->checkout->items as $tioc_item ) : ?>
										<?php
										$tioc_course = get_post( $tioc_item->item_id );

										if ( ! $tioc_course ) {
											continue;
										}

										$tioc_thumb = get_tutor_course_thumbnail_src( 'post-thumbnail', $tioc_course->ID );
										?>
										<div class="tutor-checkout-course-item" data-course-id="<?php echo esc_attr( $tioc_item->item_id ); ?>">
											<div class="tutor-d-flex tutor-align-center tutor-gap-4px">
												<?php do_action( 'tutor_cart_item_badge', $tioc_course ); ?>
											</div>
											<div class="tutor-checkout-course-content">
												<div class="tutor-d-flex tutor-flex-column tutor-gap-1">
													<div class="tutor-checkout-course-thumb-title">
														<img src="<?php echo esc_url( $tioc_thumb ); ?>" alt="<?php echo esc_attr( $tioc_course->post_title ); ?>" />
														<h6 class="tutor-checkout-course-title">
															<a href="<?php echo esc_url( get_the_permalink( $tioc_course ) ); ?>">
																<?php echo esc_html( $tioc_course->post_title ); ?>
															</a>
														</h6>
													</div>
													<?php if ( ! empty( $tioc_item->is_coupon_applied ) ) : ?>
														<div class="tutor-checkout-coupon-badge">
															<i class="tutor-icon-tag" aria-hidden="true"></i>
															<span><?php echo esc_html( $tioc_group->checkout->coupon_title ); ?></span>
														</div>
													<?php endif; ?>
												</div>
												<div class="tutor-text-right">
													<div class="tutor-fw-bold">
														<?php tutor_print_formatted_price( $tioc_item->display_price ); ?>
													</div>
													<?php if ( $tioc_item->sale_price || $tioc_item->discount_price ) : ?>
														<div class="tutor-checkout-discount-price">
															<?php tutor_print_formatted_price( $tioc_item->regular_price ); ?>
														</div>
													<?php endif; ?>
													<?php if ( ! empty( $tioc_item->tax_amount ) && $tioc_item->tax_amount > 0 && ! empty( $tioc_item->tax_collection ) ) : ?>
														<div class="tutor-fs-8 tutor-color-muted tutor-checkout-incl-tax-label">
															<?php echo esc_html( $tioc_item->tax_amount_readable ); ?>
														</div>
													<?php endif; ?>
												</div>
											</div>
										</div>
									<?php endforeach; ?>
								</div>

								<?php if ( $tioc_view->multi_instructor ) : ?>
									<div class="tioc-group-subtotal">
										<span><?php esc_html_e( 'Payable to this instructor', 'tutor-instructor-offline-payment' ); ?></span>
										<strong><?php tutor_print_formatted_price( $tioc_group->total ); ?></strong>
									</div>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>

						<div class="tutor-checkout-detail-item tutor-checkout-summary">
							<div class="tutor-checkout-summary-item">
								<div class="tutor-fw-medium"><?php esc_html_e( 'Subtotal', 'tutor-instructor-offline-payment' ); ?></div>
								<div class="tutor-fw-bold"><?php tutor_print_formatted_price( $tioc_view->subtotal ); ?></div>
							</div>

							<?php if ( $tioc_view->sale_discount > 0 ) : ?>
								<div class="tutor-checkout-summary-item">
									<div><?php echo esc_html( apply_filters( 'tutor_checkout_sale_discount_label', __( 'Sale discount', 'tutor-instructor-offline-payment' ) ) ); ?></div>
									<div class="tutor-fw-bold">- <?php tutor_print_formatted_price( $tioc_view->sale_discount ); ?></div>
								</div>
							<?php endif; ?>

							<?php if ( $tioc_coupon_box ) : ?>
								<div class="tutor-checkout-summary-item tutor-have-a-coupon">
									<div><?php esc_html_e( 'Have a coupon?', 'tutor-instructor-offline-payment' ); ?></div>
									<button type="button" id="tioc-toggle-coupon" class="tutor-btn tutor-btn-link">
										<?php esc_html_e( 'Click here', 'tutor-instructor-offline-payment' ); ?>
									</button>
								</div>
								<div class="tioc-apply-coupon tutor-d-none">
									<input type="text" name="coupon_code" form="tioc-coupon-form" placeholder="<?php esc_attr_e( 'Add coupon code', 'tutor-instructor-offline-payment' ); ?>">
									<button type="submit" form="tioc-coupon-form" class="tutor-btn tutor-btn-secondary">
										<?php esc_html_e( 'Apply', 'tutor-instructor-offline-payment' ); ?>
									</button>
								</div>
							<?php endif; ?>

							<?php if ( $tioc_view->coupon_applied ) : ?>
								<div class="tutor-checkout-summary-item tutor-checkout-coupon-wrapper">
									<div class="tutor-checkout-coupon-badge tutor-has-delete-button">
										<i class="tutor-icon-tag" aria-hidden="true"></i>
										<span><?php echo esc_html( $tioc_view->coupon_title ); ?></span>
										<a class="tutor-btn tioc-remove-coupon" href="<?php echo esc_url( $tioc_checkout_url ); ?>" aria-label="<?php esc_attr_e( 'Remove coupon', 'tutor-instructor-offline-payment' ); ?>">
											<i class="tutor-icon-times" aria-hidden="true"></i>
										</a>
									</div>
									<div class="tutor-fw-bold tutor-discount-amount">-<?php tutor_print_formatted_price( $tioc_view->coupon_discount ); ?></div>
								</div>
							<?php endif; ?>

							<?php if ( $tioc_view->tax_amount > 0 && ! $tioc_tax_in_price ) : ?>
								<div class="tutor-checkout-summary-item tutor-checkout-tax-amount">
									<div><?php esc_html_e( 'Tax', 'tutor-instructor-offline-payment' ); ?></div>
									<div class="tutor-fw-bold"><?php tutor_print_formatted_price( $tioc_view->tax_amount ); ?></div>
								</div>
							<?php endif; ?>
						</div>

						<div class="tutor-pt-12 tutor-pb-20">
							<div class="tutor-checkout-summary-item">
								<div class="tutor-fw-medium"><?php esc_html_e( 'Grand Total', 'tutor-instructor-offline-payment' ); ?></div>
								<div class="tutor-fs-5 tutor-fw-bold tutor-checkout-grand-total">
									<?php tutor_print_formatted_price( $tioc_view->total ); ?>
								</div>
							</div>
							<?php if ( $tioc_view->tax_amount > 0 && $tioc_tax_in_price ) : ?>
								<div class="tutor-checkout-summary-item tutor-checkout-incl-tax-label">
									<div></div>
									<div class="tutor-fs-7 tutor-color-muted">
										<?php
										printf(
											/* translators: %s: tax amount */
											esc_html__( '(Incl. Tax %s)', 'tutor-instructor-offline-payment' ),
											esc_html( tioc_format_price( $tioc_view->tax_amount ) )
										);
										?>
									</div>
								</div>
							<?php endif; ?>
						</div>
					</div>
				</div>
			</div>

			<div class="tutor-col-md-6">
				<div class="tutor-checkout-billing">
					<div class="tutor-checkout-billing-inner">

						<?php if ( ! $tioc_logged_in ) : ?>
							<?php $tioc_login_url = tutor_utils()->get_option( 'enable_tutor_native_login', null ) ? '' : wp_login_url( tutor()->current_url ); ?>
							<div class="tutor-alert tutor-warning tutor-mb-20">
								<div><?php esc_html_e( 'An account is required, because your instructor needs somewhere to enrol you once they confirm your payment.', 'tutor-instructor-offline-payment' ); ?></div>
							</div>
							<div class="tutor-mb-32 tutor-d-flex tutor-align-center tutor-justify-between tutor-border tutor-radius-6 tutor-p-12">
								<p class="tutor-m-0"><?php esc_html_e( 'Already have an account?', 'tutor-instructor-offline-payment' ); ?></p>
								<button type="button" class="tutor-btn tutor-btn-secondary tutor-btn-sm tutor-open-login-modal" data-login_url="<?php echo esc_url( $tioc_login_url ); ?>">
									<?php esc_html_e( 'Login', 'tutor-instructor-offline-payment' ); ?>
								</button>
							</div>
						<?php endif; ?>

						<h5 class="tutor-fs-5 tutor-fw-medium tutor-color-black tutor-mb-12 tutor-mt-0">
							<?php esc_html_e( 'Billing Address', 'tutor-instructor-offline-payment' ); ?>
						</h5>

						<div class="tutor-billing-fields">
							<?php require tutor()->path . 'templates/ecommerce/checkout-billing-form-fields.php'; ?>
						</div>

						<?php if ( ! $tioc_view->is_zero_price ) : ?>
							<div class="tioc-payment-section tutor-mt-20">
								<h5 class="tutor-fs-5 tutor-fw-medium tutor-color-black tutor-mb-12">
									<?php
									echo esc_html(
										$tioc_view->multi_instructor
											? __( 'How you paid each instructor', 'tutor-instructor-offline-payment' )
											: __( 'How you paid', 'tutor-instructor-offline-payment' )
									);
									?>
								</h5>

								<p class="tutor-fs-7 tutor-color-muted tutor-mb-16">
									<?php
									echo esc_html(
										$tioc_view->multi_instructor
											? __( 'Each instructor collects payment for their own courses, so this cart will be submitted as one order per instructor. Pay each of them using their details below, then tell us what you did.', 'tutor-instructor-offline-payment' )
											: __( 'Pay your instructor using the details below, then tell us what you did. You will be enrolled as soon as they confirm the payment.', 'tutor-instructor-offline-payment' )
									);
									?>
								</p>

								<?php foreach ( $tioc_view->groups as $tioc_group ) : ?>
									<?php if ( $tioc_group->is_free ) { continue; } ?>

									<?php
									$tioc_contact  = isset( $tioc_group->contact ) && is_array( $tioc_group->contact ) ? $tioc_group->contact : array();
									$tioc_courses  = wp_list_pluck( $tioc_group->courses, 'post_title' );
									$tioc_wa_text  = sprintf(
										/* translators: 1: instructor name, 2: course titles, 3: amount, 4: site name */
										__( 'Hello %1$s, I am buying %2$s (%3$s) on %4$s and I am paying you directly.', 'tutor-instructor-offline-payment' ),
										$tioc_group->instructor_name,
										implode( ', ', array_map( 'strval', $tioc_courses ) ),
										tioc_format_price( $tioc_group->total ),
										wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
									);

									/**
									 * Filter the message prefilled in the instructor's WhatsApp link.
									 *
									 * @param string $text  Message.
									 * @param object $group Checkout group.
									 */
									$tioc_wa_text  = apply_filters( 'tioc_checkout_whatsapp_text', $tioc_wa_text, $tioc_group );
									$tioc_tel_link = ! empty( $tioc_contact['show_phone'] ) && ! empty( $tioc_contact['phone'] )
										? Contact::tel_link( $tioc_contact['phone'] )
										: '';
									$tioc_wa_link  = ! empty( $tioc_contact['show_whatsapp'] ) && ! empty( $tioc_contact['whatsapp'] )
										? Contact::whatsapp_link( $tioc_contact['whatsapp'], $tioc_wa_text )
										: '';
									
									$tioc_socials = array();
					foreach ( array( 'facebook', 'instagram', 'tiktok', 'x' ) as $tioc_network ) {
						$tioc_show_key = 'show_' . $tioc_network;
						if ( ! empty( $tioc_contact[ $tioc_show_key ] ) && ! empty( $tioc_contact[ $tioc_network ] ) ) {
							$tioc_socials[ $tioc_network ] = $tioc_contact[ $tioc_network ];
						}
					}

					$tioc_chat_button = '';
					if ( shortcode_exists( 'better_messages_pm_button' ) && ! empty( $tioc_group->instructor_id ) ) {
						$tioc_chat_button = do_shortcode(
							'[better_messages_pm_button text="' . __( 'Chat with instructor', 'tutor-instructor-offline-payment' ) . '" subject="' . __( 'Question about my course payment', 'tutor-instructor-offline-payment' ) . '" message="' . __( 'Hello, I have a question about paying for my course.', 'tutor-instructor-offline-payment' ) . '" target="_self" class="tioc-better-messages-button" fast_start="0" url_only="0" user_id="' . absint( $tioc_group->instructor_id ) . '"]'
						);
					}

?>
									<div class="tioc-payee" data-instructor="<?php echo esc_attr( $tioc_group->instructor_id ); ?>">
										<div class="tioc-payee-head">
											<img class="tioc-payee-avatar" src="<?php echo esc_url( $tioc_group->instructor_photo ); ?>" alt="" width="40" height="40">
											<div class="tioc-payee-identity">
												<div class="tioc-payee-name"><?php echo esc_html( $tioc_group->instructor_name ); ?></div>
												<div class="tioc-payee-amount">
													<?php
													printf(
														/* translators: %s: formatted amount */
														esc_html__( 'Amount to pay: %s', 'tutor-instructor-offline-payment' ),
														'<strong>' . esc_html( tioc_format_price( $tioc_group->total ) ) . '</strong>'
													);
													?>
												</div>
											</div>
										</div>

										<?php if ( $tioc_tel_link || $tioc_wa_link || ! empty( $tioc_socials ) || $tioc_chat_button ) : ?>
											<div class="tioc-payee-contact">
												<div class="tioc-payee-contact-top">
													<span class="tioc-payee-contact-title"><?php esc_html_e( 'Contact instructor', 'tutor-instructor-offline-payment' ); ?></span>
													<span class="tioc-payee-contact-label">
														<?php
														printf(
															/* translators: %s: instructor name */
														esc_html__( 'Need help? Reach %s before you pay.', 'tutor-instructor-offline-payment' ),
														esc_html( $tioc_group->instructor_name )
														);
														?>
													</span>
												</div>
												<div class="tioc-payee-contact-actions">
										<?php if ( $tioc_chat_button ) : ?>
											<div class="tioc-better-messages-wrap">
												<?php echo $tioc_chat_button; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
											</div>
										<?php endif; ?>
													<?php if ( $tioc_tel_link ) : ?>
														<a class="tioc-contact-btn tioc-contact-phone" href="<?php echo esc_url( $tioc_tel_link, array( 'tel' ) ); ?>" aria-label="<?php esc_attr_e( 'Call instructor', 'tutor-instructor-offline-payment' ); ?>">
															<span class="tioc-contact-icon tioc-contact-icon-svg" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M6.6 3.5 4.7 5.1c-.8.7-.9 1.8-.5 2.8 1.8 4.2 5.2 7.9 9.4 9.7 1 .4 2.1.2 2.8-.5l1.6-1.9-3.1-2.3-1.8 1.4c-1.7-.9-3.2-2.4-4.1-4.1l1.4-1.8L8.1 5 6.6 3.5Z"/></svg></span>
															<span class="tioc-contact-copy"><strong><?php esc_html_e( 'Phone', 'tutor-instructor-offline-payment' ); ?></strong><small><?php echo esc_html( $tioc_contact['phone'] ); ?></small></span>
														</a>
													<?php endif; ?>
													<?php if ( $tioc_wa_link ) : ?>
														<a class="tioc-contact-btn tioc-contact-whatsapp" href="<?php echo esc_url( $tioc_wa_link ); ?>" target="_blank" rel="noopener nofollow" aria-label="<?php esc_attr_e( 'Message instructor on WhatsApp', 'tutor-instructor-offline-payment' ); ?>">
															<span class="tioc-contact-icon tioc-contact-icon-svg" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3a8.7 8.7 0 0 0-7.6 13l-1 4 4.1-1a8.7 8.7 0 1 0 4.5-16Zm0 15.4a6.8 6.8 0 0 1-3.4-.9l-.4-.2-2.4.6.6-2.3-.2-.4a6.8 6.8 0 1 1 5.8 3.2Zm3.8-5.1c-.2-.1-1.1-.5-1.2-.5-.2-.1-.3-.1-.5.1l-.6.7c-.2.2-.3.2-.5.1-1.3-.6-2.2-1.4-2.9-2.6-.1-.2 0-.3.1-.4l.4-.5c.1-.1.1-.3 0-.4l-.5-1.1c-.1-.3-.3-.3-.5-.3h-.4c-.1 0-.4.1-.6.3-.2.2-.8.8-.8 1.9s.8 2.2.9 2.4c.1.2 1.6 2.5 3.9 3.5 2.3 1 2.3.7 2.7.7.4 0 1.2-.5 1.3-.9.2-.5.2-.9.1-1-.1-.1-.2-.2-.4-.3Z"/></svg></span>
															<span class="tioc-contact-copy"><strong><?php esc_html_e( 'WhatsApp', 'tutor-instructor-offline-payment' ); ?></strong><small><?php echo esc_html( $tioc_contact['whatsapp'] ); ?></small></span>
													</a>
													<?php endif; ?>
													<?php foreach ( array( 'facebook' => 'Facebook', 'instagram' => 'Instagram', 'tiktok' => 'TikTok', 'x' => 'X' ) as $tioc_social_key => $tioc_social_label ) : ?>
														<?php if ( ! empty( $tioc_socials[ $tioc_social_key ] ) ) : ?>
															<a class="tioc-contact-btn tioc-contact-social tioc-contact-<?php echo esc_attr( $tioc_social_key ); ?>" href="<?php echo esc_url( $tioc_socials[ $tioc_social_key ], array( 'http', 'https' ) ); ?>" target="_blank" rel="noopener nofollow" aria-label="<?php echo esc_attr( $tioc_social_label ); ?>">
																<span class="tioc-contact-icon tioc-contact-icon-svg" aria-hidden="true"><?php
																switch ( $tioc_social_key ) {
																	case 'facebook': echo '<svg viewBox="0 0 24 24"><path d="M14 8h3V4h-3c-3.31 0-5 1.69-5 5v3H6v4h3v4h4v-4h3.2l.8-4H13V9c0-.67.33-1 1-1Z"/></svg>'; break;
																	case 'instagram': echo '<svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="5" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="12" cy="12" r="4" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="17.5" cy="6.5" r="1.2"/></svg>'; break;
																	case 'tiktok': echo '<svg viewBox="0 0 24 24"><path d="M15 3c.4 2.5 1.8 4 4 4v3c-1.7 0-3.1-.5-4-1.3V15a5 5 0 1 1-5-5c.4 0 .7 0 1 .1v3.1a2 2 0 1 0 1 1.8V3h3Z"/></svg>'; break;
																	default: echo '<svg viewBox="0 0 24 24"><path d="M5 4h4.7l3.4 4.5L17 4h2l-4.9 5.6L19.5 20h-4.7l-3.8-5L6.8 20h-2l5.1-5.9L5 4Zm3.3 2 7.6 12h.8L9.1 6h-.8Z"/></svg>'; break;
																}
														?></span>
																<span class="tioc-contact-copy"><strong><?php echo esc_html( $tioc_social_label ); ?></strong><small><?php esc_html_e( 'Open profile', 'tutor-instructor-offline-payment' ); ?></small></span>
															</a>
													<?php endif; ?>
											<?php endforeach; ?>
											</div>
										</div>
									<?php endif; ?>
										<?php if ( $tioc_group->note ) : ?>
											<div class="tioc-payee-note"><?php echo wp_kses_post( wpautop( $tioc_group->note ) ); ?></div>
										<?php endif; ?>

										<?php if ( empty( $tioc_group->methods ) ) : ?>

											<div class="tutor-alert tutor-warning tutor-mt-12">
												<div>
													<?php
													printf(
														/* translators: %s: instructor name */
														esc_html__( '%s has not published any payment details yet. Please contact them, or remove their course from your cart.', 'tutor-instructor-offline-payment' ),
														esc_html( $tioc_group->instructor_name )
													);
													?>
												</div>
											</div>

										<?php else : ?>

											<div class="tioc-methods">
												<?php foreach ( $tioc_group->methods as $tioc_index => $tioc_method ) : ?>
													<?php $tioc_field_id = 'tioc-method-' . $tioc_group->instructor_id . '-' . $tioc_method['id']; ?>
													<div class="tioc-method">
														<input
															type="radio"
															class="tutor-form-check-input tioc-method-input"
															id="<?php echo esc_attr( $tioc_field_id ); ?>"
															name="tioc_method[<?php echo esc_attr( $tioc_group->instructor_id ); ?>]"
															value="<?php echo esc_attr( $tioc_method['id'] ); ?>"
															<?php checked( 0, $tioc_index ); ?>
															required>
														<label class="tioc-method-label" for="<?php echo esc_attr( $tioc_field_id ); ?>">
															<?php $tioc_badge = Methods::image_url( $tioc_method, 'thumbnail' ); ?>
															<?php if ( $tioc_badge ) : ?>
																<img class="tioc-method-badge" src="<?php echo esc_url( $tioc_badge ); ?>" alt="" width="48" height="30">
															<?php endif; ?>
															<span class="tioc-method-title"><?php echo esc_html( $tioc_method['title'] ); ?></span>
														</label>
														<div class="tioc-method-details">
															<?php $tioc_rows = Methods::public_details( $tioc_method ); ?>
															<?php if ( $tioc_rows ) : ?>
																<ul class="tioc-method-fields">
																	<?php foreach ( $tioc_rows as $tioc_row ) : ?>
																		<li>
																			<span class="tioc-method-field-label"><?php echo esc_html( $tioc_row['label'] ); ?></span>
																			<span class="tioc-method-field-value <?php echo $tioc_row['copy'] ? 'is-code' : ''; ?>"><?php echo esc_html( $tioc_row['value'] ); ?></span>
																		</li>
																	<?php endforeach; ?>
																</ul>
															<?php endif; ?>
															<?php if ( trim( wp_strip_all_tags( $tioc_method['instructions'] ) ) ) : ?>
																<div class="tioc-method-instructions">
																	<?php echo wp_kses_post( wpautop( $tioc_method['instructions'] ) ); ?>
																</div>
															<?php endif; ?>
															<?php $tioc_img = Methods::image_url( $tioc_method, 'medium' ); ?>
															<?php if ( $tioc_img && Methods::supports_custom_image( $tioc_method['profile_type'] ) ) : ?>
																<?php $tioc_img_link = Methods::image_link_url( $tioc_method ); ?>
																<a class="tioc-method-image" href="<?php echo esc_url( $tioc_img_link ? $tioc_img_link : $tioc_img ); ?>" target="_blank" rel="noopener">
																	<img src="<?php echo esc_url( $tioc_img ); ?>" alt="<?php echo esc_attr( $tioc_method['title'] ); ?>">
																</a>
															<?php endif; ?>
														</div>
													</div>
												<?php endforeach; ?>
											</div>

											<div class="tioc-payee-fields">
												<?php if ( Settings::get( 'collect_reference', 1 ) ) : ?>
													<div class="tutor-mb-16">
														<label class="tutor-form-label tutor-color-secondary" for="tioc-reference-<?php echo esc_attr( $tioc_group->instructor_id ); ?>">
															<?php esc_html_e( 'Transaction reference', 'tutor-instructor-offline-payment' ); ?>
														</label>
														<input
															class="tutor-form-control"
															type="text"
															id="tioc-reference-<?php echo esc_attr( $tioc_group->instructor_id ); ?>"
															name="tioc_reference[<?php echo esc_attr( $tioc_group->instructor_id ); ?>]"
															placeholder="<?php esc_attr_e( 'Receipt number, transfer ID, last 4 digits…', 'tutor-instructor-offline-payment' ); ?>"
															required>
														<p class="tutor-fs-8 tutor-color-muted tutor-mt-4">
															<?php esc_html_e( 'Anything that lets your instructor find the payment.', 'tutor-instructor-offline-payment' ); ?>
														</p>
													</div>
												<?php endif; ?>

												<div class="tutor-mb-16">
													<label class="tutor-form-label tutor-color-secondary" for="tioc-proof-<?php echo esc_attr( $tioc_group->instructor_id ); ?>">
														<?php
														echo esc_html(
															Settings::get( 'require_proof', 0 )
																? __( 'Receipt', 'tutor-instructor-offline-payment' )
																: __( 'Receipt (optional)', 'tutor-instructor-offline-payment' )
														);
														?>
													</label>
													<input
														class="tutor-form-control tioc-proof-input"
														type="file"
														id="tioc-proof-<?php echo esc_attr( $tioc_group->instructor_id ); ?>"
														name="tioc_proof[<?php echo esc_attr( $tioc_group->instructor_id ); ?>]"
														accept="<?php echo esc_attr( '.' . implode( ',.', Settings::allowed_extensions() ) ); ?>"
														<?php echo Settings::get( 'require_proof', 0 ) ? 'required' : ''; ?>>
													<p class="tutor-fs-8 tutor-color-muted tutor-mt-4">
														<?php
														printf(
															/* translators: 1: allowed file extensions, 2: maximum file size */
															esc_html__( 'Allowed: %1$s. Up to %2$s. Only you, this instructor and the site administrator can open it.', 'tutor-instructor-offline-payment' ),
															esc_html( implode( ', ', Settings::allowed_extensions() ) ),
															esc_html( size_format( Settings::max_upload_bytes() ) )
														);
														?>
													</p>
												</div>

												<div class="tutor-mb-16">
													<label class="tutor-form-label tutor-color-secondary" for="tioc-note-<?php echo esc_attr( $tioc_group->instructor_id ); ?>">
														<?php esc_html_e( 'Message to the instructor (optional)', 'tutor-instructor-offline-payment' ); ?>
													</label>
													<textarea
														class="tutor-form-control"
														rows="2"
														id="tioc-note-<?php echo esc_attr( $tioc_group->instructor_id ); ?>"
														name="tioc_note[<?php echo esc_attr( $tioc_group->instructor_id ); ?>]"
														placeholder="<?php esc_attr_e( 'Paid on Tuesday from my brother\'s account…', 'tutor-instructor-offline-payment' ); ?>"></textarea>
												</div>
											</div>

										<?php endif; ?>
									</div>
								<?php endforeach; ?>

								<?php if ( $tioc_view->show_gateways ) : ?>
									<div class="tioc-online-alternative">
										<p class="tutor-fs-7 tutor-color-muted tutor-mb-8">
											<?php esc_html_e( 'Prefer to pay online instead? Online payments are handled by the site and confirm instantly.', 'tutor-instructor-offline-payment' ); ?>
										</p>
										<a class="tutor-btn tutor-btn-outline-primary tutor-btn-sm" href="<?php echo esc_url( add_query_arg( 'tioc_online', 1, $tioc_checkout_url ) ); ?>">
											<?php esc_html_e( 'Pay online', 'tutor-instructor-offline-payment' ); ?>
										</a>
									</div>
								<?php endif; ?>
							</div>
						<?php else : ?>
							<div class="tutor-alert tutor-info tutor-mt-20">
								<div><?php esc_html_e( 'Nothing to pay. Submit the order and you will be enrolled right away.', 'tutor-instructor-offline-payment' ); ?></div>
							</div>
						<?php endif; ?>

						<?php
						$tioc_consents = class_exists( '\Tutor\GDPR\Controllers\LegalConsent' )
							? LegalConsent::get_consent_by_display_key( LegalConsent::DISPLAY_ON_CHECKOUT )
							: array();

						if ( tutor_utils()->count( $tioc_consents ) ) :
							foreach ( $tioc_consents as $tioc_consent ) :
								LegalConsent::render_consent_field( $tioc_consent, 'tutor-mt-20' );
							endforeach;
						else :
							$tioc_toc_link     = tutor_utils()->get_toc_page_link();
							$tioc_privacy_link = tutor_utils()->get_privacy_page_link();

							if ( null !== $tioc_toc_link ) :
								?>
								<div class="tutor-mt-20">
									<div class="tutor-form-check tutor-d-flex">
										<input type="checkbox" id="tioc_agree_to_terms" name="agree_to_terms" class="tutor-form-check-input" required>
										<label for="tioc_agree_to_terms">
											<span class="tutor-color-subdued tutor-fw-normal">
												<?php esc_html_e( 'I agree with the website\'s', 'tutor-instructor-offline-payment' ); ?>
												<a target="_blank" href="<?php echo esc_url( $tioc_toc_link ); ?>" class="tutor-color-primary"><?php esc_html_e( 'Terms of Use', 'tutor-instructor-offline-payment' ); ?></a>
												<?php if ( null !== $tioc_privacy_link ) : ?>
													<?php esc_html_e( 'and', 'tutor-instructor-offline-payment' ); ?>
													<a target="_blank" href="<?php echo esc_url( $tioc_privacy_link ); ?>" class="tutor-color-primary"><?php esc_html_e( 'Privacy Policy', 'tutor-instructor-offline-payment' ); ?></a>
												<?php endif; ?>
											</span>
										</label>
									</div>
								</div>
								<?php
							endif;
						endif;
						?>

						<?php if ( ! empty( $tioc_view->errors ) ) : ?>
							<div class="tutor-break-word tutor-mt-16">
								<div class="tutor-alert tutor-danger">
									<ul class="tutor-mb-0">
										<?php foreach ( $tioc_view->errors as $tioc_error ) : ?>
											<li class="tutor-color-danger"><?php echo esc_html( $tioc_error ); ?></li>
										<?php endforeach; ?>
									</ul>
								</div>
							</div>
						<?php endif; ?>

						<?php if ( $tioc_view->blocked ) : ?>
							<div class="tutor-alert tutor-danger tutor-mt-16">
								<div><?php esc_html_e( 'One or more instructors in this cart have not published payment details, so the order cannot be submitted yet.', 'tutor-instructor-offline-payment' ); ?></div>
							</div>
						<?php endif; ?>

						<button type="submit" id="tioc-submit-order" class="tutor-btn tutor-btn-primary tutor-btn-lg tutor-w-100 tutor-justify-center tutor-mt-16" <?php disabled( false, $tioc_can_submit ); ?>>
							<?php echo esc_html( $tioc_submit_text ); ?>
						</button>

						<?php if ( ! $tioc_view->is_zero_price ) : ?>
							<p class="tutor-fs-8 tutor-color-muted tutor-text-center tutor-mt-12 tutor-mb-0">
								<?php esc_html_e( 'Submitting does not enrol you immediately. Your instructor confirms the payment first, and you can follow the status from your dashboard.', 'tutor-instructor-offline-payment' ); ?>
							</p>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>
	</form>

	<?php endif; ?>
</div>
</div>
</div>
<?php
if ( ! $tioc_logged_in ) {
	tutor_load_template_from_custom_path( tutor()->path . '/views/modal/login.php' );
}
