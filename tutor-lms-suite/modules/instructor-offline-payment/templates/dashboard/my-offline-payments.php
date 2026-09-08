<?php
/**
 * Student dashboard: the offline payments I have submitted.
 *
 * Loaded by Tutor as `dashboard/my-offline-payments`.
 * Override by copying to
 * `<theme>/tutor-offline-payment/dashboard/my-offline-payments.php`.
 *
 * @package TutorInstructorOfflinePayment
 */

use Tutor\Models\OrderModel;
use TutorInstructorOfflinePayment\Checkout;
use TutorInstructorOfflinePayment\Contact;
use TutorInstructorOfflinePayment\Dashboard;
use TutorInstructorOfflinePayment\Methods;
use TutorInstructorOfflinePayment\Orders;
use TutorInstructorOfflinePayment\Uploads;

defined( 'ABSPATH' ) || exit;

$tioc_user_id = get_current_user_id();

// phpcs:disable WordPress.Security.NonceVerification.Recommended
$tioc_status = isset( $_GET['tioc_status'] ) ? sanitize_key( wp_unslash( $_GET['tioc_status'] ) ) : 'any';
$tioc_paged  = isset( $_GET['tioc_paged'] ) ? max( 1, absint( $_GET['tioc_paged'] ) ) : 1;
$tioc_placed = ! empty( $_GET['tioc_order_placed'] );
// phpcs:enable WordPress.Security.NonceVerification.Recommended

$tioc_per_page = 10;
$tioc_args     = array(
	'status' => $tioc_status,
	'limit'  => $tioc_per_page,
	'offset' => ( $tioc_paged - 1 ) * $tioc_per_page,
);

$tioc_orders = Orders::get_for_student( $tioc_user_id, $tioc_args );
$tioc_total  = Orders::count_for_student( $tioc_user_id, $tioc_args );
$tioc_pages  = (int) ceil( $tioc_total / $tioc_per_page );
$tioc_base   = tioc_dashboard_url( Dashboard::PAGE_STUDENT );

// Flash message set by the checkout, plus anything that only partly succeeded.
$tioc_notice = Checkout::take_notice( $tioc_user_id );
$tioc_errors = Checkout::take_errors( $tioc_user_id );
?>
<div class="tioc-dash tioc-dash-student">

	<div class="tioc-dash-head">
		<h3 class="tioc-dash-title"><?php esc_html_e( 'My Payments', 'tutor-instructor-offline-payment' ); ?></h3>
		<p class="tioc-dash-subtitle">
			<?php esc_html_e( 'Payments you have sent directly to a course author. Each author confirms their own payments, and you are enrolled the moment they do.', 'tutor-instructor-offline-payment' ); ?>
		</p>
	</div>

	<?php if ( ! empty( $tioc_notice['message'] ) ) : ?>
		<div class="tioc-alert tioc-alert-<?php echo esc_attr( isset( $tioc_notice['type'] ) ? $tioc_notice['type'] : 'success' ); ?>">
			<?php echo esc_html( $tioc_notice['message'] ); ?>
		</div>
	<?php elseif ( $tioc_placed ) : ?>
		<div class="tioc-alert tioc-alert-success">
			<?php esc_html_e( 'Your order has been submitted. You will be enrolled as soon as your instructor confirms the payment.', 'tutor-instructor-offline-payment' ); ?>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $tioc_errors ) ) : ?>
		<div class="tioc-alert tioc-alert-error">
			<ul>
				<?php foreach ( $tioc_errors as $tioc_error ) : ?>
					<li><?php echo esc_html( $tioc_error ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<?php if ( empty( $tioc_orders ) ) : ?>

		<div class="tioc-empty-state">
			<p><?php esc_html_e( 'You have not submitted any offline payments yet.', 'tutor-instructor-offline-payment' ); ?></p>
			<a class="tioc-btn tioc-btn-primary" href="<?php echo esc_url( tutor_utils()->course_archive_page_url() ); ?>">
				<?php esc_html_e( 'Browse courses', 'tutor-instructor-offline-payment' ); ?>
			</a>
		</div>

	<?php else : ?>

		<ul class="tioc-order-list">
			<?php foreach ( $tioc_orders as $tioc_order ) : ?>
				<?php
				$tioc_items       = Orders::get_items( $tioc_order->id );
				$tioc_instructor  = (int) $tioc_order->instructor_id;
				$tioc_proof       = Orders::get_proof( $tioc_order->id );
				$tioc_reference   = Orders::get_meta( $tioc_order->id, Orders::META_REFERENCE );
				$tioc_method_name = Orders::get_meta( $tioc_order->id, Orders::META_METHOD_TITLE );
				$tioc_method_id   = Orders::get_meta( $tioc_order->id, Orders::META_METHOD_ID );
				$tioc_reason      = Orders::get_meta( $tioc_order->id, Orders::META_REJECT_REASON );
				$tioc_submitted   = Orders::get_meta( $tioc_order->id, Orders::META_SUBMITTED_AT );
				$tioc_history     = Orders::get_history( $tioc_order->id );
				$tioc_waiting     = in_array( $tioc_order->payment_status, array( OrderModel::PAYMENT_UNPAID, OrderModel::PAYMENT_PENDING ), true );
				$tioc_rejected    = OrderModel::PAYMENT_FAILED === $tioc_order->payment_status;
				$tioc_deadline    = Orders::get_meta( $tioc_order->id, Orders::META_DEADLINE_AT );
				$tioc_deadline_ts = $tioc_deadline ? (int) get_date_from_gmt( $tioc_deadline, 'U' ) : 0;
				$tioc_urgent      = $tioc_waiting && $tioc_deadline_ts && $tioc_deadline_ts - time() < DAY_IN_SECONDS;

				// While the payment is unconfirmed, keep the author's instructions
				// visible so the student can still go and pay.
				$tioc_method = $tioc_waiting ? Methods::find( $tioc_instructor, $tioc_method_id ) : null;
				?>
				<li class="tioc-order" id="tioc-order-<?php echo esc_attr( $tioc_order->id ); ?>">

					<div class="tioc-order-head">
						<div>
							<div class="tioc-order-id">
								<?php
								printf(
									/* translators: %s: order number */
									esc_html__( 'Order #%s', 'tutor-instructor-offline-payment' ),
									esc_html( $tioc_order->id )
								);
								?>
							</div>
							<div class="tioc-order-student">
								<?php
								printf(
									/* translators: %s: instructor name */
									esc_html__( 'Paid to %s', 'tutor-instructor-offline-payment' ),
									esc_html( tioc_get_payee_name( $tioc_instructor ) )
								);
								?>
							</div>
						</div>

						<div class="tioc-order-head-right">
							<span class="tioc-badge <?php echo esc_attr( Orders::status_class( $tioc_order->payment_status ) ); ?>">
								<?php echo esc_html( Orders::status_label( $tioc_order->payment_status ) ); ?>
							</span>
							<div class="tioc-order-total"><?php echo esc_html( tioc_format_price( $tioc_order->total_price ) ); ?></div>
						</div>
					</div>

					<ul class="tioc-order-items">
						<?php foreach ( $tioc_items as $tioc_item ) : ?>
							<li>
								<a href="<?php echo esc_url( get_permalink( $tioc_item->id ) ); ?>">
									<?php echo esc_html( $tioc_item->title ? $tioc_item->title : __( '(deleted course)', 'tutor-instructor-offline-payment' ) ); ?>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>

					<dl class="tioc-order-meta">
						<?php if ( $tioc_method_name ) : ?>
							<dt><?php esc_html_e( 'Payment method', 'tutor-instructor-offline-payment' ); ?></dt>
							<dd><?php echo esc_html( $tioc_method_name ); ?></dd>
						<?php endif; ?>

						<?php if ( $tioc_reference ) : ?>
							<dt><?php esc_html_e( 'Your reference', 'tutor-instructor-offline-payment' ); ?></dt>
							<dd><code><?php echo esc_html( $tioc_reference ); ?></code></dd>
						<?php endif; ?>

						<?php if ( $tioc_submitted ) : ?>
							<dt><?php esc_html_e( 'Submitted', 'tutor-instructor-offline-payment' ); ?></dt>
							<dd>
								<?php
								echo esc_html(
									date_i18n(
										get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
										get_date_from_gmt( $tioc_submitted, 'U' )
									)
								);
								?>
							</dd>
						<?php endif; ?>

						<?php if ( ! empty( $tioc_proof['file'] ) ) : ?>
							<dt><?php esc_html_e( 'Your receipt', 'tutor-instructor-offline-payment' ); ?></dt>
							<dd class="tioc-receipt-links">
								<a
									href="<?php echo esc_url( Uploads::proof_url( $tioc_order->id ) ); ?>"
									data-tioc-receipt
									data-mime="<?php echo esc_attr( ! empty( $tioc_proof['mime'] ) ? $tioc_proof['mime'] : '' ); ?>"
									target="_blank" rel="noopener"
								>
									<?php echo esc_html( ! empty( $tioc_proof['name'] ) ? $tioc_proof['name'] : __( 'View', 'tutor-instructor-offline-payment' ) ); ?>
								</a>
								&middot;
								<a href="<?php echo esc_url( Uploads::proof_url( $tioc_order->id, true ) ); ?>">
									<?php esc_html_e( 'Download', 'tutor-instructor-offline-payment' ); ?>
								</a>
							</dd>
						<?php endif; ?>
						<?php if ( $tioc_waiting && $tioc_deadline ) : ?>
							<dt><?php esc_html_e( 'Pay by', 'tutor-instructor-offline-payment' ); ?></dt>
							<dd class="<?php echo $tioc_urgent ? 'tioc-deadline-urgent' : ''; ?>">
								<?php
								echo esc_html(
									date_i18n(
										get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
										$tioc_deadline_ts
									)
								);
								?>
							</dd>
						<?php endif; ?>
					</dl>

					<?php if ( $tioc_waiting ) : ?>
						<div class="tioc-order-note<?php echo $tioc_urgent ? ' is-rejected' : ''; ?>">
							<?php esc_html_e( 'Waiting for the course author to confirm they received your payment. If you have not sent it yet, the details are below.', 'tutor-instructor-offline-payment' ); ?>
							<?php if ( $tioc_deadline_ts ) : ?>
								<?php if ( $tioc_deadline_ts > time() ) : ?>
									<br>
									<?php
									printf(
										/* translators: %s: human-readable time remaining, e.g. "5 hours" */
										esc_html__( 'This order is automatically cancelled in %s if it is not confirmed by then.', 'tutor-instructor-offline-payment' ),
										esc_html( human_time_diff( time(), $tioc_deadline_ts ) )
									);
									?>
								<?php else : ?>
									<br>
									<?php esc_html_e( 'This order has passed its payment deadline and may be cancelled shortly.', 'tutor-instructor-offline-payment' ); ?>
								<?php endif; ?>
							<?php endif; ?>
						</div>

						<?php if ( $tioc_method ) : ?>
							<div class="tioc-method-recap">
								<div class="tioc-method-recap-head">
									<?php $tioc_badge = Methods::image_url( $tioc_method, 'thumbnail' ); ?>
									<?php if ( $tioc_badge ) : ?>
										<img class="tioc-method-badge" src="<?php echo esc_url( $tioc_badge ); ?>" alt="" width="48" height="30">
									<?php endif; ?>
									<strong><?php echo esc_html( $tioc_method['title'] ); ?></strong>
								</div>

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

								<?php echo wp_kses_post( wpautop( $tioc_method['instructions'] ) ); ?>

								<?php if ( Methods::supports_custom_image( $tioc_method['profile_type'] ) ) : ?>
									<?php $tioc_thumb = Methods::image_url( $tioc_method, 'medium' ); ?>
									<?php if ( $tioc_thumb ) : ?>
										<img class="tioc-method-image" src="<?php echo esc_url( $tioc_thumb ); ?>" alt="">
									<?php endif; ?>
								<?php endif; ?>

								<?php
								$tioc_contact = Contact::public_contact(
									$tioc_instructor,
									sprintf(
										/* translators: %s: order number */
										__( 'Hello, about my order #%s: I am paying you offline for my enrolment.', 'tutor-instructor-offline-payment' ),
										$tioc_order->id
									)
								);
								?>
								<?php if ( $tioc_contact['has_any'] ) : ?>
									<div class="tioc-payee-contact">
										<span class="tioc-payee-contact-label"><?php esc_html_e( 'Need help paying?', 'tutor-instructor-offline-payment' ); ?></span>
										<?php if ( $tioc_contact['phone_link'] ) : ?>
											<a class="tioc-contact-btn tioc-contact-phone" href="<?php echo esc_url( $tioc_contact['phone_link'], array( 'tel' ) ); ?>">
												<span><?php echo esc_html( $tioc_contact['phone'] ); ?></span>
											</a>
										<?php endif; ?>
										<?php if ( $tioc_contact['whatsapp_link'] ) : ?>
											<a class="tioc-contact-btn tioc-contact-whatsapp" href="<?php echo esc_url( $tioc_contact['whatsapp_link'] ); ?>" target="_blank" rel="noopener nofollow">
												<span><?php esc_html_e( 'WhatsApp', 'tutor-instructor-offline-payment' ); ?></span>
											</a>
										<?php endif; ?>
									</div>
								<?php endif; ?>
							</div>
						<?php endif; ?>

						<?php if ( Orders::can_submit_proof( $tioc_order->id, $tioc_user_id ) ) : ?>
							<form class="tioc-proof-form tioc-form" data-tioc-action="tioc_submit_proof" enctype="multipart/form-data">
								<p class="tioc-help" style="margin-top: 0;">
									<?php esc_html_e( 'Send or update your payment proof:', 'tutor-instructor-offline-payment' ); ?>
								</p>

								<div class="tioc-field">
									<label class="tioc-label" for="tioc-later-reference-<?php echo esc_attr( $tioc_order->id ); ?>">
										<?php esc_html_e( 'Reference', 'tutor-instructor-offline-payment' ); ?>
									</label>
									<input
										type="text"
										class="tioc-input"
										id="tioc-later-reference-<?php echo esc_attr( $tioc_order->id ); ?>"
										name="reference"
										value="<?php echo esc_attr( $tioc_reference ); ?>"
									>
								</div>

								<div class="tioc-field">
									<label class="tioc-label" for="tioc-later-proof-<?php echo esc_attr( $tioc_order->id ); ?>">
										<?php esc_html_e( 'Receipt', 'tutor-instructor-offline-payment' ); ?>
									</label>
									<input
										type="file"
										class="tioc-input"
										id="tioc-later-proof-<?php echo esc_attr( $tioc_order->id ); ?>"
										name="proof"
									>
								</div>

								<input type="hidden" name="order_id" value="<?php echo esc_attr( $tioc_order->id ); ?>">

								<div class="tioc-form-actions">
									<button type="submit" class="tioc-btn tioc-btn-ghost">
										<?php esc_html_e( 'Send', 'tutor-instructor-offline-payment' ); ?>
									</button>
								</div>
							</form>
						<?php endif; ?>
					<?php endif; ?>

					<?php if ( $tioc_rejected ) : ?>
						<div class="tioc-order-note is-rejected">
							<strong><?php esc_html_e( 'This payment was not accepted.', 'tutor-instructor-offline-payment' ); ?></strong>
							<?php if ( $tioc_reason ) : ?>
								<?php echo esc_html( $tioc_reason ); ?>
							<?php else : ?>
								<?php esc_html_e( 'No reason was given. Contact the course author if you believe this is a mistake.', 'tutor-instructor-offline-payment' ); ?>
							<?php endif; ?>
						</div>

						<?php if ( Orders::can_submit_proof( $tioc_order->id, $tioc_user_id ) ) : ?>
							<form class="tioc-proof-form tioc-form tioc-resubmission-form" data-tioc-action="tioc_submit_proof" enctype="multipart/form-data">
								<h4 class="tioc-proof-title"><?php esc_html_e( 'Correct and resubmit payment', 'tutor-instructor-offline-payment' ); ?></h4>
								<p class="tioc-help">
									<?php esc_html_e( 'Attach a new receipt to return this payment to review. Your previous receipt will be replaced.', 'tutor-instructor-offline-payment' ); ?>
								</p>
								<div class="tioc-field">
									<label class="tioc-label" for="tioc-resubmit-reference-<?php echo esc_attr( $tioc_order->id ); ?>"><?php esc_html_e( 'Updated reference', 'tutor-instructor-offline-payment' ); ?></label>
									<input type="text" class="tioc-input" id="tioc-resubmit-reference-<?php echo esc_attr( $tioc_order->id ); ?>" name="reference" value="<?php echo esc_attr( $tioc_reference ); ?>">
								</div>
								<div class="tioc-field">
									<label class="tioc-label" for="tioc-resubmit-proof-<?php echo esc_attr( $tioc_order->id ); ?>"><?php esc_html_e( 'New receipt', 'tutor-instructor-offline-payment' ); ?></label>
									<input type="file" class="tioc-input" id="tioc-resubmit-proof-<?php echo esc_attr( $tioc_order->id ); ?>" name="proof" required>
									<p class="tioc-help"><?php esc_html_e( 'A new receipt is required for resubmission.', 'tutor-instructor-offline-payment' ); ?></p>
								</div>
								<input type="hidden" name="order_id" value="<?php echo esc_attr( $tioc_order->id ); ?>">
								<div class="tioc-form-actions">
									<button type="submit" class="tioc-btn tioc-btn-primary"><?php esc_html_e( 'Resubmit for review', 'tutor-instructor-offline-payment' ); ?></button>
								</div>
							</form>
						<?php endif; ?>
					<?php endif; ?>

					<?php if ( ! empty( $tioc_history ) ) : ?>
						<div class="tioc-payment-history">
							<h4 class="tioc-history-title"><?php esc_html_e( 'Payment history', 'tutor-instructor-offline-payment' ); ?></h4>
							<ol class="tioc-history-list">
								<?php foreach ( array_reverse( $tioc_history ) as $tioc_event ) : ?>
									<?php if ( empty( $tioc_event['message'] ) ) { continue; } ?>
									<li class="tioc-history-item is-<?php echo esc_attr( isset( $tioc_event['type'] ) ? $tioc_event['type'] : 'update' ); ?>">
										<span><?php echo esc_html( $tioc_event['message'] ); ?></span>
										<?php if ( ! empty( $tioc_event['at'] ) ) : ?>
											<time><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), get_date_from_gmt( $tioc_event['at'], 'U' ) ) ); ?></time>
										<?php endif; ?>
									</li>
								<?php endforeach; ?>
							</ol>
						</div>
					<?php endif; ?>

					<?php if ( OrderModel::PAYMENT_PAID === $tioc_order->payment_status ) : ?>
						<div class="tioc-order-note is-paid">
							<?php esc_html_e( 'Payment confirmed. You are enrolled — start learning whenever you like.', 'tutor-instructor-offline-payment' ); ?>
						</div>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>

		<?php if ( $tioc_pages > 1 ) : ?>
			<nav class="tioc-pagination">
				<?php for ( $tioc_page = 1; $tioc_page <= $tioc_pages; $tioc_page++ ) : ?>
					<?php if ( $tioc_page === $tioc_paged ) : ?>
						<span class="tioc-page is-current"><?php echo esc_html( $tioc_page ); ?></span>
					<?php else : ?>
						<a class="tioc-page" href="<?php echo esc_url(
							add_query_arg(
								array(
									'tioc_status' => $tioc_status,
									'tioc_paged'  => $tioc_page,
								),
								$tioc_base
							)
						); ?>"><?php echo esc_html( $tioc_page ); ?></a>
					<?php endif; ?>
				<?php endfor; ?>
			</nav>
		<?php endif; ?>

	<?php endif; ?>
</div>
