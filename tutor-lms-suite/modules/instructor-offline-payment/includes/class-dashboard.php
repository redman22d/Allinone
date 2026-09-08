<?php
/**
 * Front-end dashboard pages.
 *
 * @package TutorInstructorOfflinePayment
 */

namespace TutorInstructorOfflinePayment;

use TUTOR\Icon;

defined( 'ABSPATH' ) || exit;

/**
 * Three pages are added to the Tutor dashboard:
 *
 * - instructors publish their payment details ("Payment details"),
 * - instructors confirm or reject payments made to them ("Offline payments"),
 * - students watch the status of what they submitted ("My payments").
 *
 * Tutor builds its own rewrite rules from the same nav filters, so registering the
 * items is all that is needed for the URLs to exist; the rules are flushed once
 * after activation.
 */
class Dashboard {

	/**
	 * Instructor page: publish payment details.
	 *
	 * @var string
	 */
	const PAGE_SETUP = 'offline-payment-setup';

	/**
	 * Instructor page: confirm payments.
	 *
	 * @var string
	 */
	const PAGE_ORDERS = 'offline-payments';

	/**
	 * Student page: payment status.
	 *
	 * @var string
	 */
	const PAGE_STUDENT = 'my-offline-payments';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'tutor_dashboard/instructor_nav_items', array( $this, 'add_instructor_pages' ) );
		add_filter( 'tutor_dashboard/nav_items', array( $this, 'add_student_page' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_tioc_export_payments_csv', array( $this, 'export_payments_csv' ) );
		add_action( 'init', array( $this, 'maybe_export_frontend' ), 5 );
	}

	/**
	 * Query argument that triggers the front-end CSV export endpoint.
	 *
	 * The export used to post to admin-post.php. That URL lives under
	 * /wp-admin/, which Tutor LMS and most security plugins redirect away for
	 * anyone who is not an administrator, so instructors saw "access denied"
	 * before this plugin's own permission check ever ran. The front-end
	 * endpoint runs exactly the same nonce and ownership checks.
	 *
	 * @var string
	 */
	const EXPORT_QUERY_ARG = 'tioc_export';

	/**
	 * Handle a front-end export request, if this is one.
	 *
	 * @return void
	 */
	public function maybe_export_frontend() {
		if ( empty( $_GET[ self::EXPORT_QUERY_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified in export_payments_csv().
			return;
		}

		$this->export_payments_csv();
	}

	/**
	 * URL of the front-end export endpoint.
	 *
	 * @return string
	 */
	public static function export_url() {
		return home_url( '/' );
	}

	/**
	 * Export offline payments as a CSV. Administrators receive all records;
	 * instructors receive only records where they are the recorded payee.
	 *
	 * @return void
	 */
	public function export_payments_csv() {
		if ( ! is_user_logged_in() ) {
			auth_redirect();
			exit;
		}

		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'tioc_export_payments_csv' ) ) {
			wp_die(
				esc_html__( 'That export link has expired. Please reload the payments page and try again.', 'tutor-instructor-offline-payment' ),
				'',
				array( 'response' => 403 )
			);
		}

		$user_id  = get_current_user_id();
		$is_admin = current_user_can( 'manage_options' ) || current_user_can( 'manage_tutor' );

		// No role check beyond "logged in": get_for_instructor() below is
		// strictly scoped server-side to `instructor_id = $user_id`, so a user
		// with no offline orders simply downloads an empty file. Gating on a
		// role (or on tutor_utils()->is_instructor(), which needs an approved
		// instructor profile) is what produced the false "access denied" for
		// people who were managing those very orders elsewhere in the same
		// dashboard.

		$status    = isset( $_GET['tioc_status'] ) ? sanitize_key( wp_unslash( $_GET['tioc_status'] ) ) : 'any';
		$search    = isset( $_GET['tioc_search'] ) ? sanitize_text_field( wp_unslash( $_GET['tioc_search'] ) ) : '';
		$date_from = isset( $_GET['tioc_date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['tioc_date_from'] ) ) : '';
		$date_to   = isset( $_GET['tioc_date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['tioc_date_to'] ) ) : '';
		$args = array(
			'status'    => $status,
			'search'    => $search,
			'date_from' => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ? $date_from : '',
			'date_to'   => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ? $date_to : '',
			'limit'     => 200,
			'offset'    => 0,
		);
		$rows = array();

		do {
			$batch = $is_admin ? Orders::get_all( $args ) : Orders::get_for_instructor( $user_id, $args );
			$rows = array_merge( $rows, $batch );
			$args['offset'] += count( $batch );
		} while ( 200 === count( $batch ) );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="offline-payment-records-' . gmdate( 'Y-m-d' ) . '.csv"' );
		header( 'X-Content-Type-Options: nosniff' );

		while ( ob_get_level() ) {
			ob_end_clean();
		}

		$output = fopen( 'php://output', 'w' );

		// Byte order mark: without it Excel reads the UTF-8 names and course
		// titles as Latin-1 and mangles every accented character.
		fwrite( $output, "\xEF\xBB\xBF" );
		fputcsv( $output, array( 'Order ID', 'Amount', 'Currency', 'Status', 'Submitted (UTC)', 'Decided (UTC)', 'Course(s)', 'Student', 'Student email', 'Instructor', 'Instructor email', 'Payment method' ) );
		foreach ( $rows as $row ) {
			fputcsv( $output, Orders::csv_row( $row ) );
		}
		fclose( $output );
		exit;
	}

	/**
	 * Add the instructor pages.
	 *
	 * The student page is added here too: an instructor buying someone else's
	 * course still needs to see their own payment status.
	 *
	 * @param array $items Nav items.
	 *
	 * @return array
	 */
	public function add_instructor_pages( $items ) {
		$pending = Orders::pending_count( get_current_user_id() );

		$items[ self::PAGE_ORDERS ] = array(
			'title'       => $pending
				? sprintf(
					/* translators: %d: number of payments awaiting confirmation */
					__( 'Offline Payments (%d)', 'tutor-instructor-offline-payment' ),
					$pending
				)
				: __( 'Offline Payments', 'tutor-instructor-offline-payment' ),
			'auth_cap'    => tutor()->instructor_role,
			'icon'        => Icon::DOLLAR,
			'active_icon' => Icon::DOLLAR,
		);

		$items[ self::PAGE_SETUP ] = array(
			'title'       => __( 'Payment Details', 'tutor-instructor-offline-payment' ),
			'auth_cap'    => tutor()->instructor_role,
			'icon'        => Icon::WALLET,
			'active_icon' => Icon::WALLET,
		);

		// "My Payments" (a student's own purchases) is added separately via
		// add_student_page(), which Tutor applies to every dashboard user
		// regardless of role. Adding it again here duplicated the item on an
		// instructor's own sidebar; it now only ever appears in the learner
		// section of the dashboard.

		return $items;
	}

	/**
	 * Add the student page.
	 *
	 * @param array $items Nav items.
	 *
	 * @return array
	 */
	public function add_student_page( $items ) {
		$items[ self::PAGE_STUDENT ] = array(
			'title'       => __( 'My Payments', 'tutor-instructor-offline-payment' ),
			'icon'        => Icon::RECEIPT_PERCENT,
			'active_icon' => Icon::RECEIPT_PERCENT,
		);

		return $items;
	}

	/**
	 * All dashboard page slugs this plugin owns.
	 *
	 * @return string[]
	 */
	public static function pages() {
		return array( self::PAGE_SETUP, self::PAGE_ORDERS, self::PAGE_STUDENT );
	}

	/**
	 * Whether the current request is one of this plugin's dashboard pages.
	 *
	 * @return bool
	 */
	public static function is_current_page() {
		global $wp_query;

		if ( empty( $wp_query->query_vars['tutor_dashboard_page'] ) ) {
			return false;
		}

		return in_array( $wp_query->query_vars['tutor_dashboard_page'], self::pages(), true );
	}

	/**
	 * Whether the current request is the Tutor checkout page.
	 *
	 * @return bool
	 */
	public static function is_checkout_page() {
		if ( ! class_exists( '\Tutor\Ecommerce\CheckoutController' ) ) {
			return false;
		}

		$page_id = \Tutor\Ecommerce\CheckoutController::get_page_id();

		return $page_id && is_page( $page_id );
	}

	/**
	 * Load styles and scripts where they are needed.
	 *
	 * @return void
	 */
	public function enqueue() {
		$is_checkout  = tioc_is_enabled() && self::is_checkout_page();
		$is_dashboard = self::is_current_page();

		if ( ! $is_checkout && ! $is_dashboard ) {
			return;
		}

		wp_enqueue_style( 'tioc', TIOC_URL . 'assets/css/tioc.css', array(), TIOC_VERSION );

		if ( $is_checkout ) {
			wp_enqueue_script( 'tioc-checkout', TIOC_URL . 'assets/js/tioc-checkout.js', array(), TIOC_VERSION, true );
			wp_localize_script(
				'tioc-checkout',
				'TIOC_Checkout',
				array(
					'maxUploadBytes' => Settings::max_upload_bytes(),
					'maxUploadLabel' => size_format( Settings::max_upload_bytes() ),
					'extensions'     => Settings::allowed_extensions(),
					'i18n'           => array(
						'tooLarge'      => __( 'That file is too large. The maximum is %s.', 'tutor-instructor-offline-payment' ),
						'badType'       => __( 'That file type is not accepted. Allowed types: %s.', 'tutor-instructor-offline-payment' ),
						'confirm'       => __( 'Submit your offline payment details? In Algeria, pay the instructor directly using the selected method (DZD), then wait for their confirmation before enrolment.', 'tutor-instructor-offline-payment' ),
						'confirmTitle'  => __( 'Confirm payment submission', 'tutor-instructor-offline-payment' ),
						'confirmOk'     => __( 'Yes, submit', 'tutor-instructor-offline-payment' ),
						'confirmCancel' => __( 'Cancel', 'tutor-instructor-offline-payment' ),
					),
				)
			);
		}

		if ( $is_dashboard ) {
			wp_enqueue_script( 'tioc-dashboard', TIOC_URL . 'assets/js/tioc-dashboard.js', array( 'wp-i18n' ), TIOC_VERSION, true );
			wp_localize_script(
				'tioc-dashboard',
				'TIOC_Dashboard',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( Ajax::NONCE ),
					'i18n'    => array(
						'confirmDelete'      => __( 'Remove this payment method? Students will no longer see it at checkout.', 'tutor-instructor-offline-payment' ),
						'confirmApprove'     => __( 'Confirm that you received this payment? The student will be enrolled straight away.', 'tutor-instructor-offline-payment' ),
						'confirmDeleteTitle' => __( 'Remove payment method', 'tutor-instructor-offline-payment' ),
						'confirmApproveTitle' => __( 'Confirm payment', 'tutor-instructor-offline-payment' ),
						'confirmBulkApprove'      => __( 'Confirm that you received all of the selected payments? Each student will be enrolled straight away.', 'tutor-instructor-offline-payment' ),
						'confirmBulkApproveTitle' => __( 'Confirm selected payments', 'tutor-instructor-offline-payment' ),
						'bulkNone'           => __( 'Select at least one payment first.', 'tutor-instructor-offline-payment' ),
						'confirmOk'          => __( 'Yes, continue', 'tutor-instructor-offline-payment' ),
						'confirmCancel'      => __( 'Cancel', 'tutor-instructor-offline-payment' ),
						'rejectTitle'        => __( 'Reject payment', 'tutor-instructor-offline-payment' ),
						'rejectHelp'         => __( 'Explain what the student needs to correct. This required message is visible to the student.', 'tutor-instructor-offline-payment' ),
						'rejectCancel'       => __( 'Cancel', 'tutor-instructor-offline-payment' ),
						'rejectSubmit'       => __( 'Reject payment', 'tutor-instructor-offline-payment' ),
						'saving'             => __( 'Saving…', 'tutor-instructor-offline-payment' ),
						'error'              => __( 'Something went wrong. Please try again.', 'tutor-instructor-offline-payment' ),
					),
				)
			);
		}
	}
}
