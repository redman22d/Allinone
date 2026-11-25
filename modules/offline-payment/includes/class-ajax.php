<?php
/**
 * AJAX endpoints.
 *
 * @package TutorInstructorOfflinePayment
 */

namespace TutorInstructorOfflinePayment;

defined( 'ABSPATH' ) || exit;

/**
 * Everything an instructor does from the front-end dashboard goes through here.
 * Every endpoint checks the nonce, requires a logged-in user, and re-checks
 * ownership server-side rather than trusting anything the form sent.
 */
class Ajax {

	/**
	 * Nonce action shared by all endpoints.
	 *
	 * @var string
	 */
	const NONCE = 'tioc_dashboard';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		$actions = array(
			'tioc_save_method'        => 'save_method',
			'tioc_delete_method'      => 'delete_method',
			'tioc_save_note'          => 'save_note',
			'tioc_save_contact'       => 'save_contact',
			'tioc_import_contact'     => 'import_contact',
			'tioc_approve_order'      => 'approve_order',
			'tioc_reject_order'       => 'reject_order',
			'tioc_submit_proof'       => 'submit_proof',
			'tioc_bulk_decide'        => 'bulk_decide',
			'tioc_save_internal_note' => 'save_internal_note',
			'tioc_save_email_pref'    => 'save_email_pref',
		);

		foreach ( $actions as $action => $callback ) {
			add_action( 'wp_ajax_' . $action, array( $this, $callback ) );
			add_action( 'wp_ajax_nopriv_' . $action, array( $this, 'reject_guest' ) );
		}
	}

	/**
	 * Guests have nothing to do here.
	 *
	 * @return void
	 */
	public function reject_guest() {
		wp_send_json_error( array( 'message' => __( 'Please log in and try again.', 'tutor-learning-suite' ) ), 401 );
	}

	/**
	 * Save or update a payment method.
	 *
	 * @return void
	 */
	public function save_method() {
		$user_id = self::guard_instructor();

		$input = array(
			'id'              => isset( $_POST['method_id'] ) ? sanitize_key( wp_unslash( $_POST['method_id'] ) ) : '',
			'title'           => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '',
			'instructions'    => isset( $_POST['instructions'] ) ? wp_kses_post( wp_unslash( $_POST['instructions'] ) ) : '',
			'instructions_fr' => isset( $_POST['instructions_fr'] ) ? wp_kses_post( wp_unslash( $_POST['instructions_fr'] ) ) : '',
			'instructions_ar' => isset( $_POST['instructions_ar'] ) ? wp_kses_post( wp_unslash( $_POST['instructions_ar'] ) ) : '',
			'profile_type'    => isset( $_POST['profile_type'] ) ? sanitize_key( wp_unslash( $_POST['profile_type'] ) ) : 'other',
			'profile'         => isset( $_POST['profile'] ) && is_array( $_POST['profile'] ) ? wp_unslash( $_POST['profile'] ) : array(),
			'is_active'       => empty( $_POST['is_active'] ) ? 0 : 1,
			'attachment_id'   => isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0,
		);

		if ( ! empty( $_POST['remove_image'] ) ) {
			$input['attachment_id'] = 0;
		}

		// Typed methods ship with their own badge, so an upload arriving for one
		// (stale form, scripted request) is ignored rather than stored.
		if ( ! Methods::supports_custom_image( $input['profile_type'] ) ) {
			$input['attachment_id'] = 0;
		}

		// A newly uploaded QR code or bank slip. Unlike student receipts these are
		// meant to be public, so the media library is the right home for them.
		if ( ! empty( $_FILES['method_image']['name'] ) && Methods::supports_custom_image( $input['profile_type'] ) ) {
			$attachment_id = self::handle_image_upload( 'method_image' );

			if ( is_wp_error( $attachment_id ) ) {
				wp_send_json_error( array( 'message' => $attachment_id->get_error_message() ), 400 );
			}

			$input['attachment_id'] = $attachment_id;
		}

		$saved = Methods::save( $user_id, $input );

		if ( is_wp_error( $saved ) ) {
			wp_send_json_error( array( 'message' => $saved->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Payment method saved.', 'tutor-learning-suite' ),
				'method'  => $saved,
				'reload'  => true,
			)
		);
	}

	/**
	 * Delete a payment method.
	 *
	 * @return void
	 */
	public function delete_method() {
		$user_id   = self::guard_instructor();
		$method_id = isset( $_POST['method_id'] ) ? sanitize_key( wp_unslash( $_POST['method_id'] ) ) : '';

		if ( ! Methods::delete( $user_id, $method_id ) ) {
			wp_send_json_error( array( 'message' => __( 'That payment method could not be found.', 'tutor-learning-suite' ) ), 404 );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Payment method removed.', 'tutor-learning-suite' ),
				'reload'  => true,
			)
		);
	}

	/**
	 * Save the instructor's general note shown at checkout.
	 *
	 * @return void
	 */
	public function save_note() {
		$user_id = self::guard_instructor();
		$note    = isset( $_POST['note'] ) ? wp_kses_post( wp_unslash( $_POST['note'] ) ) : '';

		Methods::save_note( $user_id, $note );

		wp_send_json_success( array( 'message' => __( 'Note saved.', 'tutor-learning-suite' ) ) );
	}

	/**
	 * Save the instructor's phone and WhatsApp numbers.
	 *
	 * @return void
	 */
	public function save_contact() {
		$user_id = self::guard_instructor();

		$saved = Contact::save(
			$user_id,
			array(
				'email'         => isset( $_POST['email'] ) ? wp_unslash( $_POST['email'] ) : '',
				'show_email'    => empty( $_POST['show_email'] ) ? 0 : 1,
				'phone'         => isset( $_POST['phone'] ) ? wp_unslash( $_POST['phone'] ) : '',
				'whatsapp'      => isset( $_POST['whatsapp'] ) ? wp_unslash( $_POST['whatsapp'] ) : '',
				'facebook'      => isset( $_POST['facebook'] ) ? wp_unslash( $_POST['facebook'] ) : '',
				'instagram'     => isset( $_POST['instagram'] ) ? wp_unslash( $_POST['instagram'] ) : '',
				'tiktok'        => isset( $_POST['tiktok'] ) ? wp_unslash( $_POST['tiktok'] ) : '',
				'x'             => isset( $_POST['x'] ) ? wp_unslash( $_POST['x'] ) : '',
				'linkedin'      => isset( $_POST['linkedin'] ) ? wp_unslash( $_POST['linkedin'] ) : '',
				'telegram'      => isset( $_POST['telegram'] ) ? wp_unslash( $_POST['telegram'] ) : '',
				'youtube'       => isset( $_POST['youtube'] ) ? wp_unslash( $_POST['youtube'] ) : '',
				'website'       => isset( $_POST['website'] ) ? wp_unslash( $_POST['website'] ) : '',
				'github'        => isset( $_POST['github'] ) ? wp_unslash( $_POST['github'] ) : '',
				'show_phone'    => empty( $_POST['show_phone'] ) ? 0 : 1,
				'show_whatsapp' => empty( $_POST['show_whatsapp'] ) ? 0 : 1,
				'show_facebook'  => empty( $_POST['show_facebook'] ) ? 0 : 1,
				'show_instagram' => empty( $_POST['show_instagram'] ) ? 0 : 1,
				'show_tiktok'    => empty( $_POST['show_tiktok'] ) ? 0 : 1,
				'show_x'         => empty( $_POST['show_x'] ) ? 0 : 1,
				'show_linkedin'  => empty( $_POST['show_linkedin'] ) ? 0 : 1,
				'show_telegram'  => empty( $_POST['show_telegram'] ) ? 0 : 1,
				'show_youtube'   => empty( $_POST['show_youtube'] ) ? 0 : 1,
			)
		);

		if ( is_wp_error( $saved ) ) {
			wp_send_json_error( array( 'message' => $saved->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Contact details saved. Students will see them at checkout.', 'tutor-learning-suite' ),
				'reload'  => true,
			)
		);
	}

	/**
	 * Copy the phone and WhatsApp numbers found on the instructor's Tutor LMS
	 * profile into their offline-payment contact details.
	 *
	 * @return void
	 */
	public function import_contact() {
		$user_id  = self::guard_instructor();
		$imported = Contact::imported( $user_id );

		$has_contact_email = ! empty( $imported['email'] );
		$has_social = ! empty( $imported['facebook'] ) || ! empty( $imported['instagram'] ) || ! empty( $imported['tiktok'] ) || ! empty( $imported['x'] ) || ! empty( $imported['linkedin'] ) || ! empty( $imported['telegram'] ) || ! empty( $imported['youtube'] ) || ! empty( $imported['website'] ) || ! empty( $imported['github'] );

		if ( ! $imported['email'] && ! $imported['phone'] && ! $imported['whatsapp'] && ! $has_social ) {
			wp_send_json_error(
				array( 'message' => __( 'Your Tutor LMS profile has no contact email, phone, WhatsApp, or supported social profile to import.', 'tutor-learning-suite' ) ),
				400
			);
		}

		$existing = Contact::get( $user_id, false );

		$saved = Contact::save(
			$user_id,
			array(
				'email'         => $imported['email'] ? $imported['email'] : $existing['email'],
				'show_email'    => isset( $existing['show_email'] ) ? $existing['show_email'] : 1,
				'phone'         => $imported['phone'] ? $imported['phone'] : $existing['phone'],
				'whatsapp'      => $imported['whatsapp'] ? $imported['whatsapp'] : $existing['whatsapp'],
				'facebook'      => $imported['facebook'] ? $imported['facebook'] : $existing['facebook'],
				'instagram'     => $imported['instagram'] ? $imported['instagram'] : $existing['instagram'],
				'tiktok'        => $imported['tiktok'] ? $imported['tiktok'] : $existing['tiktok'],
				'x'             => $imported['x'] ? $imported['x'] : $existing['x'],
				'linkedin'      => $imported['linkedin'] ? $imported['linkedin'] : $existing['linkedin'],
				'telegram'      => $imported['telegram'] ? $imported['telegram'] : $existing['telegram'],
				'youtube'       => $imported['youtube'] ? $imported['youtube'] : $existing['youtube'],
				'website'       => $imported['website'] ? $imported['website'] : $existing['website'],
				'github'        => $imported['github'] ? $imported['github'] : $existing['github'],
				'show_phone'    => $existing['show_phone'],
				'show_whatsapp' => $existing['show_whatsapp'],
				'show_facebook'  => isset( $existing['show_facebook'] ) ? $existing['show_facebook'] : 1,
				'show_instagram' => isset( $existing['show_instagram'] ) ? $existing['show_instagram'] : 1,
				'show_tiktok'    => isset( $existing['show_tiktok'] ) ? $existing['show_tiktok'] : 1,
				'show_x'         => isset( $existing['show_x'] ) ? $existing['show_x'] : 1,
				'show_linkedin'  => isset( $existing['show_linkedin'] ) ? $existing['show_linkedin'] : 1,
				'show_telegram'  => isset( $existing['show_telegram'] ) ? $existing['show_telegram'] : 1,
				'show_youtube'   => isset( $existing['show_youtube'] ) ? $existing['show_youtube'] : 1,
			)
		);

		if ( is_wp_error( $saved ) ) {
			wp_send_json_error( array( 'message' => $saved->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Imported from your Tutor LMS profile.', 'tutor-learning-suite' ),
				'reload'  => true,
			)
		);
	}

	/**
	 * Confirm a payment and enrol the student.
	 *
	 * @return void
	 */
	public function approve_order() {
		$user_id  = self::guard();
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$note     = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';

		$result = Orders::approve( $order_id, $note, $user_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array( 'message' => $result->get_error_message() ),
				'tioc_forbidden' === $result->get_error_code() ? 403 : 400
			);
		}

		wp_send_json_success(
			array(
				'message' => __( 'Payment confirmed. The student has been enrolled.', 'tutor-learning-suite' ),
				'reload'  => true,
			)
		);
	}

	/**
	 * Reject a payment.
	 *
	 * @return void
	 */
	public function reject_order() {
		$user_id  = self::guard();
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$reason   = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';

		$result = Orders::reject( $order_id, $reason, $user_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array( 'message' => $result->get_error_message() ),
				'tioc_forbidden' === $result->get_error_code() ? 403 : 400
			);
		}

		wp_send_json_success(
			array(
				'message' => __( 'Payment rejected. The student has been notified.', 'tutor-learning-suite' ),
				'reload'  => true,
			)
		);
	}

	/**
	 * Let a student attach or replace the receipt on their own order,
	 * whenever they did not (or could not) send it during checkout.
	 *
	 * @since 1.1.0
	 *
	 * @return void JSON response
	 */
	public function submit_proof() {
		$user_id = self::guard();

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;

		if ( ! $order_id || ! Orders::can_submit_proof( $order_id, $user_id ) ) {
			wp_send_json_error(
				array( 'message' => __( 'This order can no longer accept a receipt.', 'tutor-learning-suite' ) ),
				403
			);
		}

		$reference = isset( $_POST['reference'] ) ? sanitize_text_field( wp_unslash( $_POST['reference'] ) ) : '';
		$file      = isset( $_FILES['proof'] ) ? $_FILES['proof'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated in Uploads::store().
		$has_file  = is_array( $file ) && ! empty( $file['tmp_name'] );

		$existing_proof = Orders::get_proof( $order_id );
		$has_proof      = ! empty( $existing_proof['file'] );
		$row            = Orders::get_row( $order_id );
		$is_resubmission = $row && \Tutor\Models\OrderModel::PAYMENT_FAILED === $row->payment_status;

		if ( ! $reference && ! $has_file && ! $has_proof ) {
			wp_send_json_error(
				array( 'message' => __( 'Add a transaction reference or attach a receipt before sending.', 'tutor-learning-suite' ) ),
				400
			);
		}

		if ( $is_resubmission && ! $has_file ) {
			wp_send_json_error(
				array( 'message' => __( 'Attach a new receipt before resubmitting this rejected payment.', 'tutor-learning-suite' ) ),
				400
			);
		}

		if ( $reference ) {
			Orders::set_meta( $order_id, Orders::META_REFERENCE, $reference );
		}

		if ( $has_file ) {
			$stored = Uploads::store( $file );

			if ( is_wp_error( $stored ) ) {
				wp_send_json_error( array( 'message' => $stored->get_error_message() ), 400 );
			}

			$old = Orders::get_proof( $order_id );
			Orders::set_meta( $order_id, Orders::META_PROOF, $stored );

			if ( ! empty( $old['file'] ) ) {
				Uploads::delete( $old['file'] );
			}
		}

		$reopened = Orders::reopen( $order_id );

		Orders::add_activity(
			$order_id,
			$reopened
				? __( 'The student resubmitted payment details after a rejection.', 'tutor-learning-suite' )
				: __( 'The student sent additional payment details after checkout.', 'tutor-learning-suite' )
		);

		/**
		 * Fires after a student attaches payment details to an existing order.
		 *
		 * @since 1.1.0
		 *
		 * @param int $order_id Order ID.
		 * @param int $user_id  Student user ID.
		 */
		do_action( 'tioc_proof_submitted_later', $order_id, $user_id );

		wp_send_json_success(
			array(
				'message' => __( 'Sent. The course author has been notified to check your payment.', 'tutor-learning-suite' ),
				'reload'  => true,
			)
		);
	}

	/**
	 * Approve or reject several orders at once. Each order is still checked
	 * individually with Orders::can_manage() / approve() / reject() — nothing
	 * here trusts that the whole selection belongs to the caller.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function bulk_decide() {
		$user_id     = self::guard();
		$bulk_action = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
		$order_ids   = isset( $_POST['order_ids'] ) && is_array( $_POST['order_ids'] )
			? array_unique( array_map( 'absint', wp_unslash( $_POST['order_ids'] ) ) )
			: array();
		$reason      = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';

		if ( ! in_array( $bulk_action, array( 'approve', 'reject' ), true ) || ! $order_ids ) {
			wp_send_json_error( array( 'message' => __( 'Select at least one payment first.', 'tutor-learning-suite' ) ), 400 );
		}

		if ( 'reject' === $bulk_action && ! $reason ) {
			wp_send_json_error( array( 'message' => __( 'Add a reason before rejecting.', 'tutor-learning-suite' ) ), 400 );
		}

		$done    = 0;
		$skipped = 0;

		foreach ( $order_ids as $order_id ) {
			$result = 'approve' === $bulk_action
				? Orders::approve( $order_id, '', $user_id )
				: Orders::reject( $order_id, $reason, $user_id );

			if ( is_wp_error( $result ) ) {
				$skipped++;
				continue;
			}

			$done++;
		}

		if ( ! $done ) {
			wp_send_json_error( array( 'message' => __( 'None of the selected payments could be updated.', 'tutor-learning-suite' ) ), 400 );
		}

		$message = 'approve' === $bulk_action
			/* translators: %d: number of orders confirmed */
			? sprintf( _n( '%d payment confirmed.', '%d payments confirmed.', $done, 'tutor-learning-suite' ), $done )
			/* translators: %d: number of orders rejected */
			: sprintf( _n( '%d payment rejected.', '%d payments rejected.', $done, 'tutor-learning-suite' ), $done );

		if ( $skipped ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of orders that could not be updated */
				_n( '%d could not be updated.', '%d could not be updated.', $skipped, 'tutor-learning-suite' ),
				$skipped
			);
		}

		wp_send_json_success(
			array(
				'message' => $message,
				'reload'  => true,
			)
		);
	}

	/**
	 * Save a private note on an order, visible only to the instructor who
	 * manages it and site administrators — never to the student.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function save_internal_note() {
		$user_id  = self::guard();
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;

		if ( ! $order_id || ! Orders::can_manage( $order_id, $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You cannot manage this order.', 'tutor-learning-suite' ) ), 403 );
		}

		$note = isset( $_POST['internal_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['internal_note'] ) ) : '';

		Orders::set_meta( $order_id, Orders::META_INTERNAL_NOTE, $note );

		wp_send_json_success( array( 'message' => __( 'Note saved.', 'tutor-learning-suite' ) ) );
	}

	/**
	 * Save an instructor's notification preference (currently: whether to
	 * email them about each new offline payment).
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function save_email_pref() {
		$user_id = self::guard_instructor();
		$mute    = empty( $_POST['mute_new_payment_email'] ) ? 0 : 1;

		update_user_meta( $user_id, 'tioc_mute_new_payment_email', $mute );

		wp_send_json_success( array( 'message' => __( 'Preference saved.', 'tutor-learning-suite' ) ) );
	}

	/**
	 * Verify the nonce and require a logged-in user.
	 *
	 * @return int Current user ID.
	 */
	private static function guard() {
		check_ajax_referer( self::NONCE, 'nonce' );

		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Please log in and try again.', 'tutor-learning-suite' ) ), 401 );
		}

		return $user_id;
	}

	/**
	 * Same, plus the instructor capability.
	 *
	 * @return int Current user ID.
	 */
	private static function guard_instructor() {
		$user_id = self::guard();

		$allowed = current_user_can( 'manage_options' )
			|| ( function_exists( 'tutor' ) && current_user_can( tutor()->instructor_role ) )
			|| ( function_exists( 'tutor_utils' ) && tutor_utils()->is_instructor( $user_id ) );

		if ( ! $allowed ) {
			wp_send_json_error( array( 'message' => __( 'Only instructors can manage payment details.', 'tutor-learning-suite' ) ), 403 );
		}

		return $user_id;
	}

	/**
	 * Move an uploaded image into the media library.
	 *
	 * @param string $field $_FILES key.
	 *
	 * @return int|\WP_Error Attachment ID.
	 */
	private static function handle_image_upload( $field ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return new \WP_Error( 'tioc_cannot_upload', __( 'You are not allowed to upload files.', 'tutor-learning-suite' ) );
		}

		$file = isset( $_FILES[ $field ] ) ? $_FILES[ $field ] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated below.

		if ( ! is_array( $file ) || empty( $file['tmp_name'] ) || UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new \WP_Error( 'tioc_upload_failed', __( 'The image could not be uploaded.', 'tutor-learning-suite' ) );
		}

		if ( (int) $file['size'] > Settings::max_upload_bytes() ) {
			return new \WP_Error(
				'tioc_too_large',
				sprintf(
					/* translators: %s: maximum file size */
					__( 'That image is too large. The maximum is %s.', 'tutor-learning-suite' ),
					size_format( Settings::max_upload_bytes() )
				)
			);
		}

		$check = wp_check_filetype_and_ext( $file['tmp_name'], sanitize_file_name( $file['name'] ) );

		if ( empty( $check['type'] ) || 0 !== strpos( $check['type'], 'image/' ) ) {
			return new \WP_Error( 'tioc_not_image', __( 'Please upload an image file.', 'tutor-learning-suite' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = media_handle_upload( $field, 0 );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		return (int) $attachment_id;
	}
}
