<?php
/**
 * Instructor dashboard: confirm or reject payments made to you.
 *
 * Loaded by Tutor as `dashboard/offline-payments`.
 * Override by copying to
 * `<theme>/tutor-offline-payment/dashboard/offline-payments.php`.
 *
 * @package TutorInstructorOfflinePayment
 */

use Tutor\Models\OrderModel;
use TutorInstructorOfflinePayment\Dashboard;
use TutorInstructorOfflinePayment\Methods;
use TutorInstructorOfflinePayment\Orders;
use TutorInstructorOfflinePayment\Uploads;

defined( 'ABSPATH' ) || exit;

$tioc_user_id = get_current_user_id();

// phpcs:disable WordPress.Security.NonceVerification.Recommended
$tioc_status    = isset( $_GET['tioc_status'] ) ? sanitize_key( wp_unslash( $_GET['tioc_status'] ) ) : OrderModel::PAYMENT_UNPAID;
$tioc_search    = isset( $_GET['tioc_search'] ) ? sanitize_text_field( wp_unslash( $_GET['tioc_search'] ) ) : '';
$tioc_date_from = isset( $_GET['tioc_date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['tioc_date_from'] ) ) : '';
$tioc_date_to   = isset( $_GET['tioc_date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['tioc_date_to'] ) ) : '';
$tioc_date_from = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $tioc_date_from ) ? $tioc_date_from : '';
$tioc_date_to   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $tioc_date_to ) ? $tioc_date_to : '';
$tioc_paged     = isset( $_GET['tioc_paged'] ) ? max( 1, absint( $_GET['tioc_paged'] ) ) : 1;
// phpcs:enable WordPress.Security.NonceVerification.Recommended

$tioc_per_page = 10;
$tioc_args     = array(
	'status'    => $tioc_status,
	'search'    => $tioc_search,
	'date_from' => $tioc_date_from,
	'date_to'   => $tioc_date_to,
	'limit'     => $tioc_per_page,
	'offset'    => ( $tioc_paged - 1 ) * $tioc_per_page,
);

$tioc_orders = Orders::get_for_instructor( $tioc_user_id, $tioc_args );
$tioc_total  = Orders::count_for_instructor( $tioc_user_id, $tioc_args );
$tioc_pages  = (int) ceil( $tioc_total / $tioc_per_page );
$tioc_base   = tioc_dashboard_url( Dashboard::PAGE_ORDERS );
$tioc_stats  = Orders::instructor_stats( $tioc_user_id );
$tioc_pagination_args = array(
	'tioc_status'    => $tioc_status,
	'tioc_search'    => $tioc_search,
	'tioc_date_from' => $tioc_date_from,
	'tioc_date_to'   => $tioc_date_to,
);

$tioc_tabs = array(
	OrderModel::PAYMENT_UNPAID => __( 'Awaiting confirmation', 'tutor-learning-suite' ),
	OrderModel::PAYMENT_PAID   => __( 'Confirmed', 'tutor-learning-suite' ),
	OrderModel::PAYMENT_FAILED => __( 'Rejected', 'tutor-learning-suite' ),
	'any'                      => __( 'All', 'tutor-learning-suite' ),
);
?>
<div class="tioc-dash tioc-dash-orders">

	<?php $tioc_page_tab = Dashboard::PAGE_ORDERS; include __DIR__ . '/_page-tabs.php'; ?>

	<div class="tioc-dash-head">
		<h3 class="tioc-dash-title"><?php esc_html_e( 'Offline Payments', 'tutor-learning-suite' ); ?></h3>
		<p class="tioc-dash-subtitle">
			<?php esc_html_e( 'Payments students say they made to you. Check your own account first, then confirm — confirming enrols the student immediately.', 'tutor-learning-suite' ); ?>
		</p>
	</div>

	<?php if ( ! Methods::has_active( $tioc_user_id ) ) : ?>
		<div class="tioc-alert tioc-alert-warning">
			<?php esc_html_e( 'You have not published any payment details yet, so students cannot pay you.', 'tutor-learning-suite' ); ?>
			<a href="<?php echo esc_url( tioc_dashboard_url( Dashboard::PAGE_SETUP ) ); ?>">
				<?php esc_html_e( 'Add them now', 'tutor-learning-suite' ); ?>
			</a>
		</div>
	<?php endif; ?>

	<div class="tioc-stats">
		<div class="tioc-stat-card">
			<div class="tioc-stat-value"><?php echo esc_html( $tioc_stats['pending_count'] ); ?></div>
			<div class="tioc-stat-label"><?php esc_html_e( 'Awaiting confirmation', 'tutor-learning-suite' ); ?></div>
		</div>
		<div class="tioc-stat-card">
			<div class="tioc-stat-value"><?php echo esc_html( tioc_format_price( $tioc_stats['pending_amount'] ) ); ?></div>
			<div class="tioc-stat-label"><?php esc_html_e( 'Pending amount', 'tutor-learning-suite' ); ?></div>
		</div>
		<div class="tioc-stat-card">
			<div class="tioc-stat-value"><?php echo esc_html( $tioc_stats['confirmed_month'] ); ?></div>
			<div class="tioc-stat-label"><?php esc_html_e( 'Confirmed this month', 'tutor-learning-suite' ); ?></div>
		</div>
		<div class="tioc-stat-card">
			<div class="tioc-stat-value">
				<?php
				echo $tioc_stats['avg_decision_hours']
					? esc_html( $tioc_stats['avg_decision_hours'] ) . ' ' . esc_html__( 'h', 'tutor-learning-suite' )
					: '—';
				?>
			</div>
			<div class="tioc-stat-label"><?php esc_html_e( 'Avg. time to decide (30d)', 'tutor-learning-suite' ); ?></div>
		</div>
	</div>

	<div class="tioc-notice-slot" role="status" aria-live="polite"></div>

	<div class="tioc-toolbar">
		<nav class="tioc-tabs">
			<?php foreach ( $tioc_tabs as $tioc_key => $tioc_label ) : ?>
				<a
					class="tioc-tab <?php echo esc_attr( $tioc_status === $tioc_key ? 'is-active' : '' ); ?>"
					href="<?php echo esc_url( add_query_arg( 'tioc_status', $tioc_key, $tioc_base ) ); ?>">
					<?php echo esc_html( $tioc_label ); ?>
				</a>
			<?php endforeach; ?>
		</nav>

		<div class="tioc-toolbar-actions">
			<form class="tioc-search" method="get" action="<?php echo esc_url( $tioc_base ); ?>">
				<input type="hidden" name="tioc_status" value="<?php echo esc_attr( $tioc_status ); ?>">
				<input
					class="tioc-input"
					type="search"
					name="tioc_search"
					value="<?php echo esc_attr( $tioc_search ); ?>"
					placeholder="<?php esc_attr_e( 'Order number, student name or email', 'tutor-learning-suite' ); ?>">
				<label class="tioc-visually-hidden" for="tioc-date-from"><?php esc_html_e( 'From date', 'tutor-learning-suite' ); ?></label>
				<input id="tioc-date-from" class="tioc-input" type="date" name="tioc_date_from" value="<?php echo esc_attr( $tioc_date_from ); ?>" title="<?php esc_attr_e( 'Submitted from', 'tutor-learning-suite' ); ?>">
				<label class="tioc-visually-hidden" for="tioc-date-to"><?php esc_html_e( 'To date', 'tutor-learning-suite' ); ?></label>
				<input id="tioc-date-to" class="tioc-input" type="date" name="tioc_date_to" value="<?php echo esc_attr( $tioc_date_to ); ?>" title="<?php esc_attr_e( 'Submitted to', 'tutor-learning-suite' ); ?>">
				<button type="submit" class="tioc-btn tioc-btn-ghost"><?php esc_html_e( 'Search', 'tutor-learning-suite' ); ?></button>
			</form>
			<form method="get" action="<?php echo esc_url( Dashboard::export_url() ); ?>">
				<input type="hidden" name="tioc_export" value="1">
				<input type="hidden" name="tioc_status" value="<?php echo esc_attr( $tioc_status ); ?>">
				<input type="hidden" name="tioc_search" value="<?php echo esc_attr( $tioc_search ); ?>">
				<input type="hidden" name="tioc_date_from" value="<?php echo esc_attr( $tioc_date_from ); ?>">
				<input type="hidden" name="tioc_date_to" value="<?php echo esc_attr( $tioc_date_to ); ?>">
				<?php wp_nonce_field( 'tioc_export_payments_csv' ); ?>
				<button type="submit" class="tioc-btn tioc-btn-ghost"><?php esc_html_e( 'Export CSV', 'tutor-learning-suite' ); ?></button>
			</form>
		</div>
	</div>

	<?php if ( empty( $tioc_orders ) ) : ?>

		<div class="tioc-empty-state">
			<p><?php esc_html_e( 'Nothing here.', 'tutor-learning-suite' ); ?></p>
		</div>

	<?php else : ?>

		<ul class="tioc-order-list">
			<?php
			$tioc_bulk_ids = array();
			foreach ( $tioc_orders as $tioc_order ) :
				$tioc_items      = Orders::get_items( $tioc_order->id );
				$tioc_proof      = Orders::get_proof( $tioc_order->id );
				$tioc_reference  = Orders::get_meta( $tioc_order->id, Orders::META_REFERENCE );
				$tioc_method     = Orders::get_meta( $tioc_order->id, Orders::META_METHOD_TITLE );
				$tioc_note       = Orders::get_meta( $tioc_order->id, Orders::META_STUDENT_NOTE );
				$tioc_reason     = Orders::get_meta( $tioc_order->id, Orders::META_REJECT_REASON );
				$tioc_submitted  = Orders::get_meta( $tioc_order->id, Orders::META_SUBMITTED_AT );
				$tioc_deadline   = Orders::get_meta( $tioc_order->id, Orders::META_DEADLINE_AT );
				$tioc_internal   = Orders::get_meta( $tioc_order->id, Orders::META_INTERNAL_NOTE );
				$tioc_can_manage = Orders::can_manage( $tioc_order->id, $tioc_user_id );
				$tioc_is_pending = in_array( $tioc_order->payment_status, array( OrderModel::PAYMENT_UNPAID, OrderModel::PAYMENT_PENDING ), true );

				if ( $tioc_is_pending && $tioc_can_manage ) {
					$tioc_bulk_ids[] = $tioc_order->id;
				}
				?>
				<li class="tioc-order" id="tioc-order-<?php echo esc_attr( $tioc_order->id ); ?>">

					<div class="tioc-order-head">
						<div class="tioc-order-head-left">
							<?php if ( $tioc_is_pending && $tioc_can_manage ) : ?>
								<label class="tioc-visually-hidden" for="tioc-select-<?php echo esc_attr( $tioc_order->id ); ?>">
									<?php
									/* translators: %s: order number */
									printf( esc_html__( 'Select order #%s', 'tutor-learning-suite' ), esc_html( $tioc_order->id ) );
									?>
								</label>
								<input
									type="checkbox"
									id="tioc-select-<?php echo esc_attr( $tioc_order->id ); ?>"
									class="tioc-bulk-checkbox"
									value="<?php echo esc_attr( $tioc_order->id ); ?>">
							<?php endif; ?>
							<div>
								<div class="tioc-order-id">
									<?php
									printf(
										/* translators: %s: order number */
										esc_html__( 'Order #%s', 'tutor-learning-suite' ),
										esc_html( $tioc_order->id )
									);
									?>
								</div>
								<div class="tioc-order-student">
									<?php echo esc_html( $tioc_order->student_name ? $tioc_order->student_name : __( 'Deleted user', 'tutor-learning-suite' ) ); ?>
									<?php if ( $tioc_order->student_email ) : ?>
										<a class="tioc-muted" href="<?php echo esc_url( 'mailto:' . $tioc_order->student_email ); ?>">
											<?php echo esc_html( $tioc_order->student_email ); ?>
										</a>
									<?php endif; ?>
								</div>
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
									<?php echo esc_html( $tioc_item->title ? $tioc_item->title : __( '(deleted course)', 'tutor-learning-suite' ) ); ?>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>

					<dl class="tioc-order-meta">
						<?php if ( $tioc_method ) : ?>
							<dt><?php esc_html_e( 'Paid via', 'tutor-learning-suite' ); ?></dt>
							<dd><?php echo esc_html( $tioc_method ); ?></dd>
						<?php endif; ?>

						<?php if ( $tioc_reference ) : ?>
							<dt><?php esc_html_e( 'Reference', 'tutor-learning-suite' ); ?></dt>
							<dd><code><?php echo esc_html( $tioc_reference ); ?></code></dd>
						<?php endif; ?>

						<?php if ( $tioc_submitted ) : ?>
							<dt><?php esc_html_e( 'Submitted', 'tutor-learning-suite' ); ?></dt>
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
							<dt><?php esc_html_e( 'Receipt', 'tutor-learning-suite' ); ?></dt>
							<dd class="tioc-receipt-links">
								<a
									href="<?php echo esc_url( Uploads::proof_url( $tioc_order->id ) ); ?>"
									data-tioc-receipt
									data-mime="<?php echo esc_attr( ! empty( $tioc_proof['mime'] ) ? $tioc_proof['mime'] : '' ); ?>"
									target="_blank" rel="noopener"
								>
									<?php echo esc_html( ! empty( $tioc_proof['name'] ) ? $tioc_proof['name'] : __( 'View', 'tutor-learning-suite' ) ); ?>
								</a>
								&middot;
								<a href="<?php echo esc_url( Uploads::proof_url( $tioc_order->id, true ) ); ?>">
									<?php esc_html_e( 'Download', 'tutor-learning-suite' ); ?>
								</a>
							</dd>
						<?php endif; ?>
						<?php if ( $tioc_is_pending && $tioc_deadline ) : ?>
							<dt><?php esc_html_e( 'Pay by', 'tutor-learning-suite' ); ?></dt>
							<dd>
								<?php
								echo esc_html(
									date_i18n(
										get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
										get_date_from_gmt( $tioc_deadline, 'U' )
									)
								);
								?>
							</dd>
						<?php endif; ?>
					</dl>

					<?php if ( $tioc_note ) : ?>
						<div class="tioc-order-note">
							<strong><?php esc_html_e( 'Student says:', 'tutor-learning-suite' ); ?></strong>
							<?php echo esc_html( $tioc_note ); ?>
						</div>
					<?php endif; ?>

					<?php if ( $tioc_reason ) : ?>
						<div class="tioc-order-note is-rejected">
							<strong><?php esc_html_e( 'Rejection reason:', 'tutor-learning-suite' ); ?></strong>
							<?php echo esc_html( $tioc_reason ); ?>
						</div>
					<?php endif; ?>

					<?php if ( $tioc_can_manage ) : ?>
						<div class="tioc-internal-note">
							<button type="button" class="tioc-link" data-tioc-toggle="tioc-internal-<?php echo esc_attr( $tioc_order->id ); ?>">
								<?php echo $tioc_internal ? esc_html__( 'Edit note to self', 'tutor-learning-suite' ) : esc_html__( '+ Note to self (private)', 'tutor-learning-suite' ); ?>
							</button>
							<?php if ( $tioc_internal ) : ?>
								<p class="tioc-internal-note-preview"><?php echo esc_html( $tioc_internal ); ?></p>
							<?php endif; ?>
							<form class="tioc-inline-form" id="tioc-internal-<?php echo esc_attr( $tioc_order->id ); ?>" data-tioc-action="tioc_save_internal_note" hidden>
								<input type="hidden" name="order_id" value="<?php echo esc_attr( $tioc_order->id ); ?>">
								<label class="tioc-visually-hidden" for="tioc-internal-note-<?php echo esc_attr( $tioc_order->id ); ?>"><?php esc_html_e( 'Private note, only visible to you and site administrators', 'tutor-learning-suite' ); ?></label>
								<textarea class="tioc-input" id="tioc-internal-note-<?php echo esc_attr( $tioc_order->id ); ?>" name="internal_note" rows="2"><?php echo esc_textarea( $tioc_internal ); ?></textarea>
								<p class="tioc-help"><?php esc_html_e( 'Only visible to you and administrators — the student never sees this.', 'tutor-learning-suite' ); ?></p>
								<button type="submit" class="tioc-btn tioc-btn-ghost"><?php esc_html_e( 'Save note', 'tutor-learning-suite' ); ?></button>
							</form>
						</div>
					<?php endif; ?>

					<?php if ( $tioc_is_pending && $tioc_can_manage ) : ?>
						<div class="tioc-order-actions">
							<form class="tioc-inline-form" data-tioc-action="tioc_approve_order" data-tioc-confirm="approve">
								<input type="hidden" name="order_id" value="<?php echo esc_attr( $tioc_order->id ); ?>">
								<button type="submit" class="tioc-btn tioc-btn-primary">
									<?php esc_html_e( 'I received this payment', 'tutor-learning-suite' ); ?>
								</button>
								<button type="button" class="tioc-link" data-tioc-toggle="tioc-approve-note-<?php echo esc_attr( $tioc_order->id ); ?>">
									<?php esc_html_e( '+ Add a note (optional)', 'tutor-learning-suite' ); ?>
								</button>
								<div id="tioc-approve-note-<?php echo esc_attr( $tioc_order->id ); ?>" hidden>
									<label class="tioc-visually-hidden" for="tioc-approve-note-field-<?php echo esc_attr( $tioc_order->id ); ?>"><?php esc_html_e( 'Note to include in the confirmation email', 'tutor-learning-suite' ); ?></label>
									<textarea class="tioc-input" id="tioc-approve-note-field-<?php echo esc_attr( $tioc_order->id ); ?>" name="note" rows="2" placeholder="<?php esc_attr_e( 'Sent to the student along with their confirmation email.', 'tutor-learning-suite' ); ?>"></textarea>
								</div>
							</form>

							<form class="tioc-inline-form tioc-reject-form" method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-tioc-action="tioc_reject_order">
								<input type="hidden" name="action" value="tioc_reject_order">
								<input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( \TutorInstructorOfflinePayment\Ajax::NONCE ) ); ?>">
								<input type="hidden" name="order_id" value="<?php echo esc_attr( $tioc_order->id ); ?>">
								<div class="tioc-reject-panel" role="group" aria-labelledby="tioc-reason-label-<?php echo esc_attr( $tioc_order->id ); ?>">
									<label class="tioc-label" id="tioc-reason-label-<?php echo esc_attr( $tioc_order->id ); ?>" for="tioc-reason-<?php echo esc_attr( $tioc_order->id ); ?>">
										<?php esc_html_e( 'Reason for rejection', 'tutor-learning-suite' ); ?>
									</label>
									<p class="tioc-help" id="tioc-reason-help-<?php echo esc_attr( $tioc_order->id ); ?>">
										<?php esc_html_e( 'Explain what the student needs to correct. This required message is visible to the student.', 'tutor-learning-suite' ); ?>
									</p>
									<textarea class="tioc-input" id="tioc-reason-<?php echo esc_attr( $tioc_order->id ); ?>" name="reason" rows="4" required aria-describedby="tioc-reason-help-<?php echo esc_attr( $tioc_order->id ); ?>"></textarea>
									<div class="tioc-form-actions">
										<button type="submit" class="tioc-btn tioc-btn-danger-ghost">
											<?php esc_html_e( 'Reject payment', 'tutor-learning-suite' ); ?>
										</button>
										<button type="button" class="tioc-btn tioc-btn-ghost" data-tioc-reject-cancel>
											<?php esc_html_e( 'Cancel', 'tutor-learning-suite' ); ?>
										</button>
									</div>
								</div>
								<button type="button" class="tioc-btn tioc-btn-danger-ghost" data-tioc-reject-open aria-expanded="false" aria-controls="tioc-reason-<?php echo esc_attr( $tioc_order->id ); ?>">
									<?php esc_html_e( 'Reject', 'tutor-learning-suite' ); ?>
								</button>
							</form>
						</div>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>

		<?php if ( $tioc_bulk_ids ) : ?>
			<div class="tioc-bulk-bar">
				<span class="tioc-bulk-hint"><?php esc_html_e( 'Select payments above to act on several at once.', 'tutor-learning-suite' ); ?></span>

				<form class="tioc-inline-form" id="tioc-bulk-approve-form" data-tioc-action="tioc_bulk_decide" data-tioc-confirm="bulk_approve" data-tioc-bulk="1">
					<input type="hidden" name="bulk_action" value="approve">
					<button type="submit" class="tioc-btn tioc-btn-primary"><?php esc_html_e( 'Approve selected', 'tutor-learning-suite' ); ?></button>
				</form>

				<form class="tioc-inline-form tioc-reject-form" id="tioc-bulk-reject-form" data-tioc-action="tioc_bulk_decide" data-tioc-bulk="1">
					<input type="hidden" name="bulk_action" value="reject">
					<div class="tioc-reject-panel" role="group" aria-labelledby="tioc-bulk-reason-label">
						<label class="tioc-label" id="tioc-bulk-reason-label" for="tioc-bulk-reason">
							<?php esc_html_e( 'Reason for rejection', 'tutor-learning-suite' ); ?>
						</label>
						<p class="tioc-help" id="tioc-bulk-reason-help">
							<?php esc_html_e( 'Sent to every selected student — keep it generic or use this for orders that share the same problem.', 'tutor-learning-suite' ); ?>
						</p>
						<textarea class="tioc-input" id="tioc-bulk-reason" name="reason" rows="3" required aria-describedby="tioc-bulk-reason-help"></textarea>
						<div class="tioc-form-actions">
							<button type="submit" class="tioc-btn tioc-btn-danger-ghost"><?php esc_html_e( 'Reject selected', 'tutor-learning-suite' ); ?></button>
							<button type="button" class="tioc-btn tioc-btn-ghost" data-tioc-reject-cancel><?php esc_html_e( 'Cancel', 'tutor-learning-suite' ); ?></button>
						</div>
					</div>
					<button type="button" class="tioc-btn tioc-btn-danger-ghost" data-tioc-reject-open aria-expanded="false" aria-controls="tioc-bulk-reason">
						<?php esc_html_e( 'Reject selected', 'tutor-learning-suite' ); ?>
					</button>
				</form>
			</div>
		<?php endif; ?>

		<?php if ( $tioc_pages > 1 ) : ?>
			<nav class="tioc-pagination">
				<?php for ( $tioc_page = 1; $tioc_page <= $tioc_pages; $tioc_page++ ) : ?>
					<?php if ( $tioc_page === $tioc_paged ) : ?>
						<span class="tioc-page is-current"><?php echo esc_html( $tioc_page ); ?></span>
					<?php else : ?>
						<a class="tioc-page" href="<?php echo esc_url(
							add_query_arg(
								array_merge( $tioc_pagination_args, array( 'tioc_paged' => $tioc_page ) ),
								$tioc_base
							)
						); ?>"><?php echo esc_html( $tioc_page ); ?></a>
					<?php endif; ?>
				<?php endfor; ?>
			</nav>
		<?php endif; ?>

	<?php endif; ?>
</div>
