<?php
/**
 * Offline order data, permissions and state transitions.
 *
 * @package TutorInstructorOfflinePayment
 */

namespace TutorInstructorOfflinePayment;

use Tutor\Models\OrderModel;
use Tutor\Models\OrderMetaModel;
use Tutor\Models\OrderActivitiesModel;

defined( 'ABSPATH' ) || exit;

/**
 * Everything that reads or mutates an offline order lives here.
 *
 * Orders are ordinary Tutor orders: they are created unpaid, tagged with the
 * instructor who is owed the money, and then handed to Tutor's own
 * `mark_as_paid()` on approval so enrolment, earnings and Tutor's built-in
 * emails all behave exactly as they do for a gateway payment.
 */
class Orders {

	/**
	 * Value stored in the order's `payment_method` column.
	 *
	 * @var string
	 */
	const PAYMENT_METHOD = 'tioc_offline';

	/**
	 * Order meta keys.
	 */
	const META_IS_OFFLINE    = '_tioc_offline';
	const META_INSTRUCTOR    = '_tioc_instructor_id';
	const META_METHOD_ID     = '_tioc_method_id';
	const META_METHOD_TITLE  = '_tioc_method_title';
	const META_REFERENCE     = '_tioc_reference';
	const META_PROOF         = '_tioc_proof';
	const META_STUDENT_NOTE  = '_tioc_student_note';
	const META_SUBMITTED_AT  = '_tioc_submitted_at';
	const META_DECIDED_AT    = '_tioc_decided_at';
	const META_DECIDED_BY    = '_tioc_decided_by';
	const META_REJECT_REASON = '_tioc_reject_reason';
	const META_HISTORY       = '_tioc_payment_history';
	const META_METHOD_SNAPSHOT = '_tioc_method_snapshot';
	const META_CURRENCY       = '_tioc_currency';
	const META_DEADLINE_AT    = '_tioc_deadline_at';
	const META_EXPIRED_AT     = '_tioc_expired_at';
	const META_REMINDED_AT    = '_tioc_reminded_at';
	const META_INTERNAL_NOTE  = '_tioc_internal_note';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'tutor_new_earning_data', array( $this, 'filter_earning_data' ) );
		add_filter( 'tutor_payment_method_labels', array( $this, 'filter_method_label' ) );
		add_action( 'tioc_expire_overdue_orders', array( __CLASS__, 'expire_overdue_orders' ) );
		add_action( 'tioc_send_deadline_reminders', array( __CLASS__, 'send_deadline_reminders' ) );
	}

	/**
	 * Expire unpaid offline orders whose immutable deadline has passed.
	 * Safe to run repeatedly: each row is re-read and only unpaid/pending orders
	 * without an expiry marker are changed.
	 *
	 * @return void
	 */
	public static function expire_overdue_orders() {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$ids = $wpdb->get_col( $wpdb->prepare(
			// NOTE: table is `tutor_ordermeta` (no underscore between "order"
			// and "meta") — every other query in this class uses that name.
			// This one previously queried a table that doesn't exist, so this
			// cron silently matched zero rows and orders never auto-expired.
			"SELECT m.order_id FROM {$wpdb->prefix}tutor_ordermeta m INNER JOIN {$wpdb->prefix}tutor_ordermeta o ON o.order_id = m.order_id AND o.meta_key = %s AND o.meta_value = '1' WHERE m.meta_key = %s AND m.meta_value <= %s",
			self::META_IS_OFFLINE,
			self::META_DEADLINE_AT,
			$now
		) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		foreach ( array_unique( array_map( 'absint', $ids ) ) as $order_id ) {
			$row = self::get_row( $order_id );
			if ( ! $row || self::get_meta( $order_id, self::META_EXPIRED_AT ) || ! in_array( $row->payment_status, array( OrderModel::PAYMENT_UNPAID, OrderModel::PAYMENT_PENDING ), true ) ) {
				continue;
			}
			if ( ! self::model()->update_order( $order_id, array( 'payment_status' => OrderModel::PAYMENT_FAILED, 'order_status' => OrderModel::ORDER_CANCELLED, 'note' => __( 'Offline payment deadline expired.', 'tutor-instructor-offline-payment' ) ) ) ) {
				continue;
			}
			self::set_meta( $order_id, self::META_EXPIRED_AT, $now );
			self::add_history( $order_id, 'expired', __( 'Payment deadline expired. This order is no longer active.', 'tutor-instructor-offline-payment' ) );
			self::add_activity( $order_id, __( 'Offline payment deadline expired.', 'tutor-instructor-offline-payment' ) );
			if ( class_exists( __NAMESPACE__ . '\\Audit' ) ) {
				Audit::log( $order_id, 'expired' );
			}
			do_action( 'tutor_order_payment_status_changed', $order_id, $row->payment_status, OrderModel::PAYMENT_FAILED );
		}
	}

	/**
	 * Fire a reminder for unpaid offline orders whose deadline is coming up.
	 *
	 * This is the handler for `tioc_send_deadline_reminders`, which was being
	 * scheduled hourly since activation but had nothing hooked to it — the
	 * `deadline_reminder_hours` setting existed and was saved, but no reminder
	 * was ever sent. Each order is only reminded once (tracked via
	 * META_REMINDED_AT); the actual email is left to Emails::register(),
	 * which listens for the `tioc_deadline_reminder_due` action fired here,
	 * matching how submitted/approved/rejected notifications already work.
	 *
	 * @return void
	 */
	public static function send_deadline_reminders() {
		$hours = absint( Settings::get( 'deadline_reminder_hours', 24 ) );

		if ( ! $hours ) {
			return;
		}

		global $wpdb;
		$now   = current_time( 'mysql', true );
		$until = gmdate( 'Y-m-d H:i:s', strtotime( $now ) + ( $hours * HOUR_IN_SECONDS ) );

		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT m.order_id FROM {$wpdb->prefix}tutor_ordermeta m
			INNER JOIN {$wpdb->prefix}tutor_ordermeta o ON o.order_id = m.order_id AND o.meta_key = %s AND o.meta_value = '1'
			WHERE m.meta_key = %s AND m.meta_value > %s AND m.meta_value <= %s
			AND m.order_id NOT IN ( SELECT order_id FROM {$wpdb->prefix}tutor_ordermeta WHERE meta_key = %s )",
			self::META_IS_OFFLINE,
			self::META_DEADLINE_AT,
			$now,
			$until,
			self::META_REMINDED_AT
		) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL

		foreach ( array_unique( array_map( 'absint', $ids ) ) as $order_id ) {
			$row = self::get_row( $order_id );

			if ( ! $row || ! in_array( $row->payment_status, array( OrderModel::PAYMENT_UNPAID, OrderModel::PAYMENT_PENDING ), true ) ) {
				continue;
			}

			/**
			 * Fires once per order when its payment deadline is within the
			 * configured reminder window.
			 *
			 * @param int $order_id Order ID.
			 */
			do_action( 'tioc_deadline_reminder_due', $order_id );

			self::set_meta( $order_id, self::META_REMINDED_AT, $now );
		}
	}

	/**
	 * Shared order model instance.
	 *
	 * @return OrderModel
	 */
	public static function model() {
		static $model = null;

		if ( null === $model ) {
			$model = new OrderModel();
		}

		return $model;
	}

	/**
	 * Fetch the raw order row without Tutor's expensive detail assembly.
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return object|null
	 */
	public static function get_row( $order_id ) {
		global $wpdb;

		$order_id = absint( $order_id );
		if ( ! $order_id ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}tutor_orders WHERE id = %d", $order_id )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return $row ? $row : null;
	}

	/**
	 * Whether this order was placed through the offline checkout.
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return bool
	 */
	public static function is_offline( $order_id ) {
		return (bool) self::get_meta( $order_id, self::META_IS_OFFLINE );
	}

	/**
	 * Read one order meta value.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $key      Meta key.
	 * @param mixed  $default  Fallback.
	 *
	 * @return mixed
	 */
	public static function get_meta( $order_id, $key, $default = '' ) {
		$order_id = absint( $order_id );
		if ( ! $order_id ) {
			return $default;
		}

		$value = OrderMetaModel::get_meta_value( $order_id, $key, true );

		return '' === $value ? $default : $value;
	}

	/**
	 * Write one order meta value.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $key      Meta key.
	 * @param mixed  $value    Meta value.
	 *
	 * @return void
	 */
	public static function set_meta( $order_id, $key, $value ) {
		OrderMetaModel::update_meta( absint( $order_id ), $key, $value );
	}

	/**
	 * The instructor who is owed the money for this order.
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return int
	 */
	public static function get_instructor_id( $order_id ) {
		return (int) self::get_meta( $order_id, self::META_INSTRUCTOR, 0 );
	}

	/**
	 * Receipt metadata for an order.
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return array Empty array when no receipt was uploaded.
	 */
	public static function get_proof( $order_id ) {
		$proof = self::get_meta( $order_id, self::META_PROOF, array() );

		return is_array( $proof ) ? $proof : array();
	}

	/**
	 * Read the student-visible payment history for an order.
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return array[]
	 */
	public static function get_history( $order_id ) {
		$history = self::get_meta( $order_id, self::META_HISTORY, array() );

		return is_array( $history ) ? $history : array();
	}

	/**
	 * Append a payment lifecycle event to the order history.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $type     Event type.
	 * @param string $message  Student-visible message.
	 *
	 * @return void
	 */
	public static function add_history( $order_id, $type, $message ) {
		$history   = self::get_history( $order_id );
		$history[] = array(
			'type'    => sanitize_key( $type ),
			'message' => sanitize_text_field( $message ),
			'at'      => current_time( 'mysql', true ),
		);

		self::set_meta( $order_id, self::META_HISTORY, array_slice( $history, -20 ) );
	}

	/**
	 * Record the offline payment details captured at checkout.
	 *
	 * @param int   $order_id Order ID.
	 * @param array $data     {
	 *     @type int    $instructor_id Payee user ID.
	 *     @type string $method_id     Chosen method ID.
	 *     @type string $method_title  Chosen method title.
	 *     @type string $reference     Student supplied transaction reference.
	 *     @type string $note          Student supplied note.
	 *     @type array  $proof         Receipt metadata from Uploads::store().
	 * }
	 *
	 * @return void
	 */
	public static function save_submission( $order_id, array $data ) {
		$order_id = absint( $order_id );
		if ( ! $order_id ) {
			return;
		}

		self::set_meta( $order_id, self::META_IS_OFFLINE, 1 );
		self::set_meta( $order_id, self::META_INSTRUCTOR, isset( $data['instructor_id'] ) ? absint( $data['instructor_id'] ) : 0 );
		self::set_meta( $order_id, self::META_METHOD_ID, isset( $data['method_id'] ) ? sanitize_key( $data['method_id'] ) : '' );
		self::set_meta( $order_id, self::META_METHOD_TITLE, isset( $data['method_title'] ) ? sanitize_text_field( $data['method_title'] ) : '' );
		self::set_meta( $order_id, self::META_REFERENCE, isset( $data['reference'] ) ? sanitize_text_field( $data['reference'] ) : '' );
		self::set_meta( $order_id, self::META_STUDENT_NOTE, isset( $data['note'] ) ? sanitize_textarea_field( $data['note'] ) : '' );
		self::set_meta( $order_id, self::META_SUBMITTED_AT, current_time( 'mysql', true ) );
		self::set_meta( $order_id, self::META_CURRENCY, 'DZD' );
		if ( ! empty( $data['method_snapshot'] ) && is_array( $data['method_snapshot'] ) ) {
			self::set_meta( $order_id, self::META_METHOD_SNAPSHOT, $data['method_snapshot'] );
		}
		$deadline_days = max( 0, absint( Settings::get( 'payment_deadline_days', 3 ) ) );
		if ( $deadline_days ) {
			self::set_meta( $order_id, self::META_DEADLINE_AT, gmdate( 'Y-m-d H:i:s', time() + ( $deadline_days * DAY_IN_SECONDS ) ) );
		}
		self::add_history( $order_id, 'submitted', __( 'Payment submitted for review.', 'tutor-instructor-offline-payment' ) );
		if ( class_exists( __NAMESPACE__ . '\\Audit' ) ) {
			Audit::log( $order_id, 'submitted', 0, array( 'method_id' => isset( $data['method_id'] ) ? $data['method_id'] : '' ) );
		}

		if ( ! empty( $data['proof'] ) && is_array( $data['proof'] ) ) {
			self::set_meta( $order_id, self::META_PROOF, $data['proof'] );
		}

		self::add_activity(
			$order_id,
			sprintf(
				/* translators: %s: payment method title */
				__( 'Offline payment submitted by the student (%s). Awaiting the course author\'s confirmation.', 'tutor-instructor-offline-payment' ),
				isset( $data['method_title'] ) ? $data['method_title'] : __( 'offline payment', 'tutor-instructor-offline-payment' )
			)
		);

		/**
		 * Fires once an offline order has been fully recorded.
		 *
		 * @param int   $order_id Order ID.
		 * @param array $data     Submission data.
		 */
		do_action( 'tioc_order_submitted', $order_id, $data );
	}

	/**
	 * Whether a user may approve or reject this order.
	 *
	 * @param int $order_id Order ID.
	 * @param int $user_id  User ID.
	 *
	 * @return bool
	 */
	public static function can_manage( $order_id, $user_id = 0 ) {
		$user_id  = $user_id ? absint( $user_id ) : get_current_user_id();
		$order_id = absint( $order_id );

		if ( ! $user_id || ! $order_id ) {
			return false;
		}

		$allowed = false;

		if ( self::get_instructor_id( $order_id ) === $user_id ) {
			$allowed = true;
		} elseif ( user_can( $user_id, 'manage_options' ) && Settings::get( 'admin_can_approve', 1 ) ) {
			$allowed = true;
		}

		/**
		 * Filter whether a user can act on an offline order.
		 *
		 * @param bool $allowed  Current decision.
		 * @param int  $order_id Order ID.
		 * @param int  $user_id  User ID.
		 */
		return (bool) apply_filters( 'tioc_can_manage_order', $allowed, $order_id, $user_id );
	}

	/**
	 * Whether a user may see this order and its receipt.
	 *
	 * @param int $order_id Order ID.
	 * @param int $user_id  User ID.
	 *
	 * @return bool
	 */
	public static function can_view( $order_id, $user_id = 0 ) {
		$user_id  = $user_id ? absint( $user_id ) : get_current_user_id();
		$order_id = absint( $order_id );

		if ( ! $user_id || ! $order_id ) {
			return false;
		}

		$allowed = false;

		if ( self::get_instructor_id( $order_id ) === $user_id ) {
			// Payee instructor recorded at checkout.
			$allowed = true;
		} elseif ( user_can( $user_id, 'manage_options' ) || user_can( $user_id, 'manage_tutor' ) ) {
			// Site administrators and Tutor staff.
			$allowed = true;
		} else {
			$row = self::get_row( $order_id );

			if ( $row && (int) $row->user_id === $user_id ) {
				// The student who placed the order.
				$allowed = true;
			} else {
				// Fallback for orders saved before the instructor meta existed:
				// the author (payee) of any course on the order may look.
				foreach ( self::get_items( $order_id ) as $item ) {
					if ( function_exists( 'tioc_get_payee_id' ) && (int) tioc_get_payee_id( $item->id ) === $user_id ) {
						$allowed = true;
						break;
					}
				}
			}
		}

		/**
		 * Filter whether a user may view an offline order and its receipt.
		 *
		 * @param bool $allowed  Current decision.
		 * @param int  $order_id Order ID.
		 * @param int  $user_id  User ID.
		 */
		return (bool) apply_filters( 'tioc_can_view_order', $allowed, $order_id, $user_id );
	}


	/**
	 * Whether a student may attach or update the receipt for their own order.
	 *
	 * Only the student who placed the order can do this, and only while it is
	 * still awaiting a decision — once it is approved or rejected the record
	 * is final.
	 *
	 * @since 1.1.0
	 *
	 * @param int $order_id Order ID.
	 * @param int $user_id  User ID.
	 *
	 * @return bool
	 */
	public static function can_submit_proof( $order_id, $user_id = 0 ) {
		$user_id  = $user_id ? absint( $user_id ) : get_current_user_id();
		$order_id = absint( $order_id );

		if ( ! $user_id || ! $order_id || ! self::is_offline( $order_id ) ) {
			return false;
		}

		$row = self::get_row( $order_id );

		if ( ! $row || (int) $row->user_id !== $user_id ) {
			return false;
		}

		$waiting = in_array(
			$row->payment_status,
			array( OrderModel::PAYMENT_UNPAID, OrderModel::PAYMENT_PENDING, OrderModel::PAYMENT_FAILED ),
			true
		);

		/**
		 * Filter whether a student may (still) submit a receipt for this order.
		 *
		 * @param bool $waiting  Current decision.
		 * @param int  $order_id Order ID.
		 * @param int  $user_id  User ID.
		 */
		return (bool) apply_filters( 'tioc_can_submit_proof', $waiting, $order_id, $user_id );
	}

	/**
	 * Put a rejected order back into the instructor's review queue.
	 *
	 * Called when a student resubmits payment details after a rejection, so
	 * the order does not stay stuck showing "not accepted" forever.
	 *
	 * @since 1.1.0
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return bool
	 */
	public static function reopen( $order_id ) {
		$order_id = absint( $order_id );
		$row      = self::get_row( $order_id );

		if ( ! $row || OrderModel::PAYMENT_FAILED !== $row->payment_status ) {
			return false;
		}

		$updated = self::model()->update_order(
			$order_id,
			array(
				'payment_status' => OrderModel::PAYMENT_UNPAID,
			)
		);

		if ( $updated ) {
			self::set_meta( $order_id, self::META_REJECT_REASON, '' );
			self::set_meta( $order_id, self::META_SUBMITTED_AT, current_time( 'mysql', true ) );
			self::add_history( $order_id, 'resubmitted', __( 'Payment details resubmitted and returned to review.', 'tutor-instructor-offline-payment' ) );
			if ( class_exists( __NAMESPACE__ . '\\Audit' ) ) {
				Audit::log( $order_id, 'resubmitted', get_current_user_id() );
			}
		}

		return (bool) $updated;
	}

	/**
	 * Approve an offline payment: mark the order paid, which enrols the student
	 * and writes the earnings record through Tutor's own pipeline.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $note     Optional note from the approver.
	 * @param int    $user_id  Acting user, defaults to the current user.
	 *
	 * @return true|\WP_Error
	 */
	public static function approve( $order_id, $note = '', $user_id = 0 ) {
		$order_id = absint( $order_id );
		$user_id  = $user_id ? absint( $user_id ) : get_current_user_id();

		$row = self::get_row( $order_id );
		if ( ! $row ) {
			return new \WP_Error( 'tioc_no_order', __( 'That order no longer exists.', 'tutor-instructor-offline-payment' ) );
		}

		if ( ! self::is_offline( $order_id ) ) {
			return new \WP_Error( 'tioc_not_offline', __( 'That order was not placed through the offline checkout.', 'tutor-instructor-offline-payment' ) );
		}

		if ( ! self::can_manage( $order_id, $user_id ) ) {
			return new \WP_Error( 'tioc_forbidden', __( 'You are not allowed to act on this order.', 'tutor-instructor-offline-payment' ) );
		}

		if ( OrderModel::PAYMENT_PAID === $row->payment_status ) {
			return new \WP_Error( 'tioc_already_paid', __( 'That order is already marked as paid.', 'tutor-instructor-offline-payment' ) );
		}

		$note = sanitize_textarea_field( $note );

		$message = $note
			? $note
			: sprintf(
				/* translators: %s: display name of the approver */
				__( 'Offline payment confirmed by %s.', 'tutor-instructor-offline-payment' ),
				self::actor_name( $user_id )
			);

		if ( ! self::model()->mark_as_paid( $order_id, $message ) ) {
			return new \WP_Error( 'tioc_update_failed', __( 'The order could not be updated. Please try again.', 'tutor-instructor-offline-payment' ) );
		}

		self::set_meta( $order_id, self::META_DECIDED_AT, current_time( 'mysql', true ) );
		self::set_meta( $order_id, self::META_DECIDED_BY, $user_id );
		self::add_history( $order_id, 'approved', __( 'Payment confirmed and enrolment completed.', 'tutor-instructor-offline-payment' ) );
		self::add_activity( $order_id, $message );
		if ( class_exists( __NAMESPACE__ . '\\Audit' ) ) {
			Audit::log( $order_id, 'approved', $user_id );
		}

		/**
		 * Fires after an offline payment is approved.
		 *
		 * @param int    $order_id Order ID.
		 * @param int    $user_id  Approver.
		 * @param string $note     Note recorded on the order.
		 */
		do_action( 'tioc_order_approved', $order_id, $user_id, $note );

		return true;
	}

	/**
	 * Reject an offline payment.
	 *
	 * The order is marked failed and cancelled, and Tutor's status-changed hook
	 * is fired so any enrolment created earlier is cancelled too.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $reason   Reason shown to the student.
	 * @param int    $user_id  Acting user.
	 *
	 * @return true|\WP_Error
	 */
	public static function reject( $order_id, $reason = '', $user_id = 0 ) {
		$order_id = absint( $order_id );
		$user_id  = $user_id ? absint( $user_id ) : get_current_user_id();

		$row = self::get_row( $order_id );
		if ( ! $row ) {
			return new \WP_Error( 'tioc_no_order', __( 'That order no longer exists.', 'tutor-instructor-offline-payment' ) );
		}

		if ( ! self::is_offline( $order_id ) ) {
			return new \WP_Error( 'tioc_not_offline', __( 'That order was not placed through the offline checkout.', 'tutor-instructor-offline-payment' ) );
		}

		if ( ! self::can_manage( $order_id, $user_id ) ) {
			return new \WP_Error( 'tioc_forbidden', __( 'You are not allowed to act on this order.', 'tutor-instructor-offline-payment' ) );
		}

		if ( OrderModel::PAYMENT_FAILED === $row->payment_status ) {
			return new \WP_Error( 'tioc_already_rejected', __( 'That order has already been rejected.', 'tutor-instructor-offline-payment' ) );
		}

		$reason = sanitize_textarea_field( $reason );
		if ( '' === $reason ) {
			return new \WP_Error( 'tioc_rejection_reason_required', __( 'A rejection reason is required and will be shown to the student.', 'tutor-instructor-offline-payment' ) );
		}

		$message = $reason
			? sprintf(
				/* translators: 1: display name of the reviewer, 2: reason */
				__( 'Offline payment rejected by %1$s. Reason: %2$s', 'tutor-instructor-offline-payment' ),
				self::actor_name( $user_id ),
				$reason
			)
			: sprintf(
				/* translators: %s: display name of the reviewer */
				__( 'Offline payment rejected by %s.', 'tutor-instructor-offline-payment' ),
				self::actor_name( $user_id )
			);

		$previous = $row->payment_status;

		$updated = self::model()->update_order(
			$order_id,
			array(
				'payment_status' => OrderModel::PAYMENT_FAILED,
				'order_status'   => OrderModel::ORDER_CANCELLED,
				'note'           => $message,
			)
		);

		if ( ! $updated ) {
			return new \WP_Error( 'tioc_update_failed', __( 'The order could not be updated. Please try again.', 'tutor-instructor-offline-payment' ) );
		}

		self::set_meta( $order_id, self::META_REJECT_REASON, $reason );
		self::set_meta( $order_id, self::META_DECIDED_AT, current_time( 'mysql', true ) );
		self::set_meta( $order_id, self::META_DECIDED_BY, $user_id );
		self::add_history(
			$order_id,
			'rejected',
			$reason
				? sprintf(
					/* translators: %s: rejection reason */
					__( 'Payment rejected. Reason: %s', 'tutor-instructor-offline-payment' ),
					$reason
				)
				: __( 'Payment rejected. You can correct the details and resubmit it for review.', 'tutor-instructor-offline-payment' )
		);
		self::add_activity( $order_id, $message );
		if ( class_exists( __NAMESPACE__ . '\\Audit' ) ) {
			Audit::log( $order_id, 'rejected', $user_id, array( 'reason' => $reason ) );
		}

		// Let Tutor cancel any enrolment that had already been created.
		do_action( 'tutor_order_payment_status_changed', $order_id, $previous, OrderModel::PAYMENT_FAILED );

		/**
		 * Fires after an offline payment is rejected.
		 *
		 * @param int    $order_id Order ID.
		 * @param int    $user_id  Reviewer.
		 * @param string $reason   Reason recorded on the order.
		 */
		do_action( 'tioc_order_rejected', $order_id, $user_id, $reason );

		return true;
	}

	/**
	 * Orders awaiting or already decided for one instructor.
	 *
	 * @param int   $instructor_id Instructor user ID.
	 * @param array $args          {
	 *     @type string $status Payment status filter, or 'any'.
	 *     @type string $search Free text search on order ID or student.
	 *     @type int    $limit  Page size.
	 *     @type int    $offset Offset.
	 * }
	 *
	 * @return object[]
	 */
	public static function get_for_instructor( $instructor_id, array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'status' => 'any',
				'search' => '',
				'limit'  => 20,
				'offset' => 0,
			)
		);

		return self::query( absint( $instructor_id ), 0, $args, false );
	}

	/**
	 * Count of orders for one instructor.
	 *
	 * @param int   $instructor_id Instructor user ID.
	 * @param array $args          See get_for_instructor().
	 *
	 * @return int
	 */
	public static function count_for_instructor( $instructor_id, array $args = array() ) {
		$args = wp_parse_args( $args, array( 'status' => 'any', 'search' => '' ) );

		return (int) self::query( absint( $instructor_id ), 0, $args, true );
	}

	/**
	 * A student's own offline orders.
	 *
	 * @param int   $student_id Student user ID.
	 * @param array $args       See get_for_instructor().
	 *
	 * @return object[]
	 */
	public static function get_for_student( $student_id, array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'status' => 'any',
				'search' => '',
				'limit'  => 20,
				'offset' => 0,
			)
		);

		return self::query( 0, absint( $student_id ), $args, false );
	}

	/**
	 * Count of a student's offline orders.
	 *
	 * @param int   $student_id Student user ID.
	 * @param array $args       See get_for_instructor().
	 *
	 * @return int
	 */
	public static function count_for_student( $student_id, array $args = array() ) {
		$args = wp_parse_args( $args, array( 'status' => 'any', 'search' => '' ) );

		return (int) self::query( 0, absint( $student_id ), $args, true );
	}

	/**
	 * How many payments are waiting on this instructor.
	 *
	 * @param int $instructor_id Instructor user ID.
	 *
	 * @return int
	 */
	public static function pending_count( $instructor_id ) {
		return self::count_for_instructor( $instructor_id, array( 'status' => OrderModel::PAYMENT_UNPAID ) );
	}

	/**
	 * Every offline order, for the site-wide admin overview.
	 *
	 * @param array $args See get_for_instructor().
	 *
	 * @return object[]
	 */
	public static function get_all( array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'status' => 'any',
				'search' => '',
				'limit'  => 20,
				'offset' => 0,
			)
		);

		return self::query( 0, 0, $args, false );
	}

	/**
	 * Count of every offline order.
	 *
	 * @param array $args See get_for_instructor().
	 *
	 * @return int
	 */
	public static function count_all( array $args = array() ) {
		$args = wp_parse_args( $args, array( 'status' => 'any', 'search' => '' ) );

		return (int) self::query( 0, 0, $args, true );
	}

	/**
	 * Return a CSV-safe value. Spreadsheet formula prefixes are neutralised so
	 * exported student and course data cannot execute when opened.
	 *
	 * @param mixed $value Value to export.
	 *
	 * @return string
	 */
	public static function csv_value( $value ) {
		$value = wp_strip_all_tags( (string) $value );
		$value = preg_replace( '/[\r\n\t]+/', ' ', $value );
		$value = trim( $value );

		if ( '' !== $value && in_array( substr( $value, 0, 1 ), array( '=', '+', '-', '@' ), true ) ) {
			$value = "'" . $value;
		}

		return $value;
	}

	/**
	 * Build one privacy-safe CSV row for an offline order.
	 *
	 * Receipt locations, references, notes, rejection reasons and payment-method
	 * instructions are deliberately excluded because they can contain sensitive
	 * payment information.
	 *
	 * @param object $order Order row from query().
	 *
	 * @return array
	 */
	public static function csv_row( $order ) {
		$order_id    = absint( $order->id );
		$instructor  = get_userdata( self::get_instructor_id( $order_id ) );
		$student     = get_userdata( (int) $order->user_id );
		$items       = self::get_items( $order_id );
		$course_list = array();

		foreach ( $items as $item ) {
			$course_list[] = $item->title ? $item->title : __( '(deleted course)', 'tutor-instructor-offline-payment' );
		}

		return array(
			self::csv_value( $order_id ),
			self::csv_value( number_format_i18n( (float) $order->total_price, 2 ) ),
			'DZD',
			self::csv_value( self::status_label( $order->payment_status ) ),
			self::csv_value( self::get_meta( $order_id, self::META_SUBMITTED_AT ) ),
			self::csv_value( self::get_meta( $order_id, self::META_DECIDED_AT ) ),
			self::csv_value( implode( ' | ', $course_list ) ),
			self::csv_value( $student ? $student->display_name : __( 'Deleted user', 'tutor-instructor-offline-payment' ) ),
			self::csv_value( $student ? $student->user_email : '' ),
			self::csv_value( $instructor ? $instructor->display_name : __( 'Deleted user', 'tutor-instructor-offline-payment' ) ),
			self::csv_value( $instructor ? $instructor->user_email : '' ),
			self::csv_value( self::get_meta( $order_id, self::META_METHOD_TITLE ) ),
		);
	}

	/**
	 * Summary numbers for one instructor's "Offline Payments" dashboard page:
	 * how much is waiting on them right now, and how they've been doing.
	 *
	 * @param int $instructor_id Instructor user ID.
	 *
	 * @return array {
	 *     @type int   $pending_count      Orders awaiting a decision.
	 *     @type float $pending_amount    Sum of those orders' totals.
	 *     @type int   $confirmed_month    Orders confirmed since the 1st of this month.
	 *     @type int   $avg_decision_hours Average hours between submission and decision, last 30 days. 0 if no data.
	 * }
	 */
	public static function instructor_stats( $instructor_id ) {
		global $wpdb;

		$instructor_id = absint( $instructor_id );
		$order_meta    = $wpdb->prefix . 'tutor_ordermeta';
		$orders        = $wpdb->prefix . 'tutor_orders';

		$base_join = "INNER JOIN {$order_meta} AS flag ON flag.order_id = o.id AND flag.meta_key = %s
			LEFT JOIN {$order_meta} AS payee ON payee.order_id = o.id AND payee.meta_key = %s";

		$pending = $wpdb->get_row( $wpdb->prepare(
			"SELECT COUNT(*) AS c, COALESCE(SUM(o.total_price), 0) AS total
			FROM {$orders} AS o {$base_join}
			WHERE payee.meta_value = %s AND o.payment_status IN (%s, %s) AND o.order_status <> %s",
			self::META_IS_OFFLINE,
			self::META_INSTRUCTOR,
			(string) $instructor_id,
			OrderModel::PAYMENT_UNPAID,
			OrderModel::PAYMENT_PENDING,
			OrderModel::ORDER_TRASH
		) ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery

		$month_start = gmdate( 'Y-m-01 00:00:00' );

		$confirmed_month = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$orders} AS o {$base_join}
			LEFT JOIN {$order_meta} AS decided ON decided.order_id = o.id AND decided.meta_key = %s
			WHERE payee.meta_value = %s AND o.payment_status = %s AND decided.meta_value >= %s",
			self::META_IS_OFFLINE,
			self::META_INSTRUCTOR,
			self::META_DECIDED_AT,
			(string) $instructor_id,
			OrderModel::PAYMENT_PAID,
			$month_start
		) ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery

		$since = gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) );

		$hours = $wpdb->get_col( $wpdb->prepare(
			"SELECT TIMESTAMPDIFF(MINUTE, submitted.meta_value, decided.meta_value)
			FROM {$orders} AS o {$base_join}
			INNER JOIN {$order_meta} AS submitted ON submitted.order_id = o.id AND submitted.meta_key = %s
			INNER JOIN {$order_meta} AS decided ON decided.order_id = o.id AND decided.meta_key = %s
			WHERE payee.meta_value = %s AND o.payment_status IN (%s, %s) AND decided.meta_value >= %s",
			self::META_IS_OFFLINE,
			self::META_INSTRUCTOR,
			self::META_SUBMITTED_AT,
			self::META_DECIDED_AT,
			(string) $instructor_id,
			OrderModel::PAYMENT_PAID,
			OrderModel::PAYMENT_FAILED,
			$since
		) ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery

		$hours    = array_filter( array_map( 'floatval', (array) $hours ), static function ( $m ) {
			return $m >= 0;
		} );
		$avg_hours = $hours ? round( array_sum( $hours ) / count( $hours ) / 60, 1 ) : 0;

		return array(
			'pending_count'      => $pending ? (int) $pending->c : 0,
			'pending_amount'     => $pending ? (float) $pending->total : 0.0,
			'confirmed_month'    => $confirmed_month,
			'avg_decision_hours' => $avg_hours,
		);
	}

	/**
	 * How many offline payments are unpaid site-wide.
	 *
	 * @return int
	 */
	public static function pending_count_all() {
		return self::count_all( array( 'status' => OrderModel::PAYMENT_UNPAID ) );
	}

	/**
	 * Shared query for the instructor and student listings.
	 *
	 * @param int   $instructor_id Filter by payee, 0 to skip.
	 * @param int   $student_id    Filter by buyer, 0 to skip.
	 * @param array $args          Query args.
	 * @param bool  $count_only    Return a count instead of rows.
	 *
	 * @return object[]|int
	 */
	private static function query( $instructor_id, $student_id, array $args, $count_only ) {
		global $wpdb;

		$orders    = $wpdb->prefix . 'tutor_orders';
		$order_meta = $wpdb->prefix . 'tutor_ordermeta';

		$joins  = " INNER JOIN {$order_meta} AS flag ON flag.order_id = o.id AND flag.meta_key = %s ";
		$params = array( self::META_IS_OFFLINE );
		$where  = ' WHERE 1=1 ';

		// The payee is always joined so listings can show it without a query per row.
		$joins   .= " LEFT JOIN {$order_meta} AS payee ON payee.order_id = o.id AND payee.meta_key = %s ";
		$params[] = self::META_INSTRUCTOR;

		$has_date_range = ! empty( $args['date_from'] ) || ! empty( $args['date_to'] );

		if ( $has_date_range ) {
			// Joined here, alongside the other joins, so this param lines up
			// with where it actually renders in the final SQL — joins render
			// before WHERE, regardless of the order conditions are appended
			// to $where below.
			$joins   .= " LEFT JOIN {$order_meta} AS submitted ON submitted.order_id = o.id AND submitted.meta_key = %s ";
			$params[] = self::META_SUBMITTED_AT;
		}

		if ( $instructor_id ) {
			$where   .= ' AND payee.meta_value = %s ';
			$params[] = (string) $instructor_id;
		}

		if ( $student_id ) {
			$where   .= ' AND o.user_id = %d ';
			$params[] = $student_id;
		}

		$statuses = self::get_payment_statuses();
		if ( ! empty( $args['status'] ) && 'any' !== $args['status'] && in_array( $args['status'], $statuses, true ) ) {
			$where   .= ' AND o.payment_status = %s ';
			$params[] = $args['status'];
		}

		$where .= " AND o.order_status <> '" . OrderModel::ORDER_TRASH . "' ";

		if ( $has_date_range ) {
			if ( ! empty( $args['date_from'] ) ) {
				$where   .= ' AND submitted.meta_value >= %s ';
				$params[] = $args['date_from'] . ' 00:00:00';
			}

			if ( ! empty( $args['date_to'] ) ) {
				$where   .= ' AND submitted.meta_value <= %s ';
				$params[] = $args['date_to'] . ' 23:59:59';
			}
		}

		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where   .= ' AND ( o.id LIKE %s OR u.display_name LIKE %s OR u.user_email LIKE %s ) ';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$student_join = " LEFT JOIN {$wpdb->users} AS u ON u.ID = o.user_id ";

		if ( $count_only ) {
			$sql = "SELECT COUNT(DISTINCT o.id) FROM {$orders} AS o {$joins} {$student_join} {$where}";

			return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
		}

		$limit  = isset( $args['limit'] ) ? max( 1, min( 200, (int) $args['limit'] ) ) : 20;
		$offset = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;

		$sql = "SELECT o.*, u.display_name AS student_name, u.user_email AS student_email, payee.meta_value AS instructor_id
			FROM {$orders} AS o {$joins} {$student_join} {$where}
			ORDER BY o.id DESC LIMIT %d OFFSET %d";

		$params[] = $limit;
		$params[] = $offset;

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery

		return $rows ? $rows : array();
	}

	/**
	 * Course and bundle titles on an order.
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return object[] Each with `id` and `title`.
	 */
	public static function get_items( $order_id ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT oi.item_id AS id, p.post_title AS title, oi.regular_price, oi.sale_price, oi.discount_price
				FROM {$wpdb->prefix}tutor_order_items AS oi
				LEFT JOIN {$wpdb->posts} AS p ON p.ID = oi.item_id
				WHERE oi.order_id = %d",
				absint( $order_id )
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return $rows ? $rows : array();
	}

	/**
	 * Payment statuses this plugin recognises.
	 *
	 * @return string[]
	 */
	public static function get_payment_statuses() {
		return array(
			OrderModel::PAYMENT_UNPAID,
			OrderModel::PAYMENT_PAID,
			OrderModel::PAYMENT_FAILED,
			OrderModel::PAYMENT_REFUNDED,
			OrderModel::PAYMENT_PARTIALLY_REFUNDED,
			OrderModel::PAYMENT_PENDING,
		);
	}

	/**
	 * Student facing label for a payment status.
	 *
	 * @param string $status Payment status.
	 *
	 * @return string
	 */
	public static function status_label( $status ) {
		$labels = array(
			OrderModel::PAYMENT_UNPAID             => __( 'Awaiting confirmation', 'tutor-instructor-offline-payment' ),
			OrderModel::PAYMENT_PENDING            => __( 'Awaiting confirmation', 'tutor-instructor-offline-payment' ),
			OrderModel::PAYMENT_PAID               => __( 'Confirmed', 'tutor-instructor-offline-payment' ),
			OrderModel::PAYMENT_FAILED             => __( 'Rejected', 'tutor-instructor-offline-payment' ),
			OrderModel::PAYMENT_REFUNDED           => __( 'Refunded', 'tutor-instructor-offline-payment' ),
			OrderModel::PAYMENT_PARTIALLY_REFUNDED => __( 'Partially refunded', 'tutor-instructor-offline-payment' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : ucfirst( str_replace( '-', ' ', (string) $status ) );
	}

	/**
	 * CSS modifier for a payment status badge.
	 *
	 * @param string $status Payment status.
	 *
	 * @return string
	 */
	public static function status_class( $status ) {
		switch ( $status ) {
			case OrderModel::PAYMENT_PAID:
				return 'is-paid';
			case OrderModel::PAYMENT_FAILED:
				return 'is-rejected';
			case OrderModel::PAYMENT_REFUNDED:
			case OrderModel::PAYMENT_PARTIALLY_REFUNDED:
				return 'is-refunded';
			default:
				return 'is-pending';
		}
	}

	/**
	 * Label the custom payment method wherever Tutor prints it.
	 *
	 * @param array $labels Method slug to label map.
	 *
	 * @return array
	 */
	public function filter_method_label( $labels ) {
		if ( ! is_array( $labels ) ) {
			return $labels;
		}

		$labels[ self::PAYMENT_METHOD ] = __( 'Offline payment to instructor', 'tutor-instructor-offline-payment' );

		return $labels;
	}

	/**
	 * Optionally credit the whole amount to the course author.
	 *
	 * The author physically received the cash, so recording an admin commission
	 * would show the site as owing them money it never handled. Only applies to
	 * offline orders, and only when the site owner opts in.
	 *
	 * @param array $data Earning row about to be inserted.
	 *
	 * @return array
	 */
	public function filter_earning_data( $data ) {
		if ( ! is_array( $data ) || empty( $data['order_id'] ) ) {
			return $data;
		}

		if ( 'instructor_full' !== Settings::get( 'earnings_mode', 'default' ) ) {
			return $data;
		}

		if ( ! self::is_offline( $data['order_id'] ) ) {
			return $data;
		}

		$total = isset( $data['course_price_grand_total'] ) ? (float) $data['course_price_grand_total'] : 0;

		$data['instructor_amount'] = $total;
		$data['instructor_rate']   = 100;
		$data['admin_amount']      = 0;
		$data['admin_rate']        = 0;
		$data['commission_type']   = 'percent';

		return $data;
	}

	/**
	 * Append a line to the order's activity history.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $message  Message.
	 *
	 * @return void
	 */
	public static function add_activity( $order_id, $message ) {
		if ( ! class_exists( '\Tutor\Models\OrderActivitiesModel' ) ) {
			return;
		}

		$payload             = new \stdClass();
		$payload->order_id   = absint( $order_id );
		$payload->meta_key   = OrderActivitiesModel::META_KEY_HISTORY;
		$payload->meta_value = wp_json_encode(
			array(
				'date'    => current_time( 'mysql' ),
				'message' => wp_strip_all_tags( $message ),
			)
		);

		( new OrderActivitiesModel() )->add_order_meta( $payload );
	}

	/**
	 * Display name for an acting user, with a sane fallback.
	 *
	 * @param int $user_id User ID.
	 *
	 * @return string
	 */
	private static function actor_name( $user_id ) {
		$user = get_userdata( $user_id );

		return $user ? $user->display_name : __( 'the course author', 'tutor-instructor-offline-payment' );
	}
}
