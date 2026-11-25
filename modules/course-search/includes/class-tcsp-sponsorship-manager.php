<?php
/**
 * Sponsorship manager admin screen.
 *
 * @package Tutor_Course_Search_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TCSP_Sponsorship_Manager {

	const PAGE_SLUG = 'tcsp-sponsorships';
	const NONCE     = 'tcsp_sponsorship_action';
	const PER_PAGE  = 25;
	const CAPABILITY = 'manage_tcsp_sponsorships';
	const MANAGER_USERS_OPTION = 'tcsp_sponsorship_manager_users';

	/** @var TCSP_Settings */
	private $settings;

	public function __construct( TCSP_Settings $settings ) {
		$this->settings = $settings;
	}

	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 101 );
		add_action( 'admin_init', array( $this, 'sync_manager_capabilities' ), 5 );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
	}

	public static function capability() { return self::CAPABILITY; }

	public static function can_manage() {
		return current_user_can( 'manage_options' ) || current_user_can( self::CAPABILITY );
	}

	public function add_menu() {
		add_menu_page(
			__( 'Sponsorship Manager', 'tutor-learning-suite' ),
			__( 'Sponsorship', 'tutor-learning-suite' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
			'dashicons-megaphone',
			58
		);
	}

	public function sync_manager_capabilities() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		$admin_role = get_role( 'administrator' );
		if ( $admin_role ) $admin_role->add_cap( self::CAPABILITY );
		$tiers = $this->settings->promotion_tiers();
		$desired = array();
		foreach ( $tiers as $tier ) {
			$manager_id = isset( $tier['manager_id'] ) ? absint( $tier['manager_id'] ) : 0;
			if ( $manager_id ) $desired[] = $manager_id;
		}
		$desired = array_values( array_unique( $desired ) );
		$previous = get_option( self::MANAGER_USERS_OPTION, array() );
		$previous = is_array( $previous ) ? array_map( 'absint', $previous ) : array();
		foreach ( array_diff( $previous, $desired ) as $user_id ) {
			$user = new WP_User( $user_id );
			if ( $user->exists() ) $user->remove_cap( self::CAPABILITY );
		}
		foreach ( $desired as $user_id ) {
			$user = new WP_User( $user_id );
			if ( $user->exists() ) $user->add_cap( self::CAPABILITY );
		}
		update_option( self::MANAGER_USERS_OPTION, $desired, false );
	}

	/** Handle all mutating admin actions. */
	public function handle_actions() {
		if ( ! is_admin() || ! self::can_manage() ) {
			return;
		}

		if ( empty( $_POST['tcsp_sponsorship_action'] ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_POST['tcsp_sponsorship_action'] ) );

		if ( 'save_plans' === $action ) {
			if ( ! current_user_can( 'manage_options' ) ) return;
			$course_raw = isset( $_POST['promotion_tiers'] ) && is_array( $_POST['promotion_tiers'] ) ? wp_unslash( $_POST['promotion_tiers'] ) : array();
			$tutor_raw  = isset( $_POST['tutor_promotion_tiers'] ) && is_array( $_POST['tutor_promotion_tiers'] ) ? wp_unslash( $_POST['tutor_promotion_tiers'] ) : array();
			$this->settings->update_sponsorship_plans( $course_raw, $tutor_raw );
			$all = $this->settings->all();
			$all['sponsorship_max_units'] = isset( $_POST['sponsorship_max_units'] ) ? min( 12, max( 1, absint( $_POST['sponsorship_max_units'] ) ) ) : 12;
			$all['sponsorship_notifications'] = isset( $_POST['sponsorship_notifications'] ) ? 1 : 0; // Was never saved, so it could not be switched off.
			$all['sponsorship_unpaid_cancel_days'] = isset( $_POST['sponsorship_unpaid_cancel_days'] ) ? min( 365, absint( $_POST['sponsorship_unpaid_cancel_days'] ) ) : 3;
			update_option( TCSP_Settings::OPTION, $all, false );
			$this->sync_manager_capabilities();
			$this->redirect_with_notice( 'updated', __( 'Sponsorship settings saved.', 'tutor-learning-suite' ) );
		}

		if ( 'manual_sponsor' === $action ) {
			if ( ! $this->verify_action_nonce( 'tcsp_manual_sponsor' ) ) return;
			$type = isset( $_POST['manual_target_type'] ) ? sanitize_key( wp_unslash( $_POST['manual_target_type'] ) ) : '';
			$target = isset( $_POST['manual_target_id'] ) ? absint( $_POST['manual_target_id'] ) : 0;
			$tier = isset( $_POST['manual_tier'] ) ? sanitize_key( wp_unslash( $_POST['manual_tier'] ) ) : '';
			$activate = ! empty( $_POST['manual_activate'] );
			$units = isset( $_POST['manual_units'] ) ? max( 1, min( 12, absint( $_POST['manual_units'] ) ) ) : 1;
			$portal = $this->get_portal();
			if ( $portal && $portal->create_manual_sponsorship( $type, $target, $tier, get_current_user_id(), $activate, $units ) ) {
				$this->redirect_with_notice( 'updated', __( 'Manual sponsorship created.', 'tutor-learning-suite' ) );
			}
			$this->redirect_with_notice( 'none', __( 'Could not create the manual sponsorship. Check the selected plan and target.', 'tutor-learning-suite' ) );
		}

		if ( 'cancel_course_plan' === $action ) {
			if ( ! $this->verify_action_nonce( self::NONCE ) ) return;
			$course_id = isset( $_POST['course_id'] ) ? absint( $_POST['course_id'] ) : 0;
			if ( ! $course_id || ! current_user_can( 'manage_options' ) && ! $this->can_manage_course_plan( TCSP_Plugin::get_promotion_tier( $course_id ) ) ) return;
			$this->remove_course_assignment( $course_id );
			$this->cancel_related_records( 'course', $course_id );
			$this->redirect_with_notice( 'updated', __( 'Course sponsorship cancelled.', 'tutor-learning-suite' ) );
		}

		if ( 'save_tutor_row' === $action ) {
			if ( ! current_user_can( 'manage_options' ) ) return;
			$tutor_id = isset( $_POST['tutor_id'] ) ? absint( $_POST['tutor_id'] ) : 0;
			$tier = isset( $_POST['tutor_tier'] ) && ! is_array( $_POST['tutor_tier'] ) ? sanitize_key( wp_unslash( $_POST['tutor_tier'] ) ) : '';
			$start = $this->sanitize_date( isset( $_POST['tutor_start'] ) ? wp_unslash( $_POST['tutor_start'] ) : '' );
			$end = $this->sanitize_date( isset( $_POST['tutor_end'] ) ? wp_unslash( $_POST['tutor_end'] ) : '' );
			if ( $tutor_id && $this->save_tutor_assignment( $tutor_id, $tier, $start, $end ) ) {
				$this->redirect_with_notice( 'updated', __( 'Tutor sponsorship updated.', 'tutor-learning-suite' ) );
			}
			$this->redirect_with_notice( 'none', __( 'Could not update that tutor sponsorship.', 'tutor-learning-suite' ) );
		}

		if ( 'cancel_tutor_plan' === $action ) {
			if ( ! current_user_can( 'manage_options' ) ) return;
			$tutor_id = isset( $_POST['tutor_id'] ) ? absint( $_POST['tutor_id'] ) : 0;
			if ( $tutor_id ) {
				$this->remove_tutor_assignment( $tutor_id );
				$this->cancel_related_records( 'tutor', $tutor_id );
				$this->redirect_with_notice( 'updated', __( 'Tutor sponsorship cancelled.', 'tutor-learning-suite' ) );
			}
			$this->redirect_with_notice( 'none', __( 'Could not cancel that tutor sponsorship.', 'tutor-learning-suite' ) );
		}

		if ( 'bulk_tutor_sponsor' === $action ) {
			if ( ! current_user_can( 'manage_options' ) ) return;
			$tutor_ids = isset( $_POST['tutor_ids'] ) && is_array( $_POST['tutor_ids'] ) ? array_values( array_unique( array_filter( array_map( 'absint', wp_unslash( $_POST['tutor_ids'] ) ) ) ) ) : array();
			if ( ! empty( $_POST['select_all_tutors_filtered'] ) ) {
				$tutor_ids = array_map( function( $u ) { return (int) $u->ID; }, $this->get_filtered_tutors( $this->tutor_filters_from_post() ) );
			}
			$tier = isset( $_POST['bulk_tutor_tier'] ) ? sanitize_key( wp_unslash( $_POST['bulk_tutor_tier'] ) ) : '';
			$start = $this->sanitize_date( isset( $_POST['bulk_tutor_start'] ) ? wp_unslash( $_POST['bulk_tutor_start'] ) : '' );
			$end   = $this->sanitize_date( isset( $_POST['bulk_tutor_end'] ) ? wp_unslash( $_POST['bulk_tutor_end'] ) : '' );
			$count = 0;
			foreach ( $tutor_ids as $tutor_id ) {
				if ( $this->save_tutor_assignment( $tutor_id, $tier, $start, $end ) ) $count++;
			}
			$this->redirect_with_notice( 'updated', sprintf( _n( '%d tutor sponsorship updated.', '%d tutor sponsorships updated.', $count, 'tutor-learning-suite' ), $count ) );
		}

		if ( 'bulk_request_action' === $action ) {
			if ( ! self::can_manage() ) return;
			$request_ids = isset( $_POST['request_ids'] ) && is_array( $_POST['request_ids'] ) ? array_values( array_unique( array_filter( array_map( 'absint', wp_unslash( $_POST['request_ids'] ) ) ) ) ) : array();
			$bulk_status = isset( $_POST['bulk_request_status'] ) ? sanitize_key( wp_unslash( $_POST['bulk_request_status'] ) ) : '';
			$allowed = array( 'pending', 'paid', 'scheduled', 'active', 'rejected', 'cancelled' );
			$count = 0;
			$portal = $this->get_portal();
			foreach ( $request_ids as $request_id ) {
				if ( TCSP_Sponsorship_Portal::CPT !== get_post_type( $request_id ) ) continue;
				$type = (string) get_post_meta( $request_id, '_tcsp_target_type', true );
				$tier = (string) get_post_meta( $request_id, '_tcsp_plan', true );
				if ( current_user_can( 'manage_options' ) === false && ( 'course' !== $type || ! $this->can_manage_course_plan( $tier ) ) ) continue;
				if ( in_array( $bulk_status, $allowed, true ) ) {
					if ( $portal && method_exists( $portal, 'admin_set_request_status' ) && $portal->admin_set_request_status( $request_id, $bulk_status ) ) $count++;
				}
			}
			$this->redirect_with_notice( 'updated', sprintf( _n( '%d request updated.', '%d requests updated.', $count, 'tutor-learning-suite' ), $count ) );
		}

		$nonce  = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			return;
		}

		if ( isset( $_POST['save_row'] ) && ! is_array( $_POST['save_row'] ) ) {
			$course_id = absint( $_POST['save_row'] );
			$row_tiers = isset( $_POST['row_tier'] ) && is_array( $_POST['row_tier'] ) ? $_POST['row_tier'] : array();
			$row_starts = isset( $_POST['row_start'] ) && is_array( $_POST['row_start'] ) ? $_POST['row_start'] : array();
			$row_ends = isset( $_POST['row_end'] ) && is_array( $_POST['row_end'] ) ? $_POST['row_end'] : array();
			$payload = array(
				'bulk_tier' => isset( $row_tiers[ $course_id ] ) ? $row_tiers[ $course_id ] : '',
				'bulk_schedule' => 'custom',
				'bulk_start' => isset( $row_starts[ $course_id ] ) ? $row_starts[ $course_id ] : '',
				'bulk_end' => isset( $row_ends[ $course_id ] ) ? $row_ends[ $course_id ] : '',
			);
			if ( $course_id && $this->save_course_assignment( $course_id, $payload ) ) {
				$this->redirect_with_notice( 'updated', __( 'Course sponsorship updated.', 'tutor-learning-suite' ) );
			}
			$this->redirect_with_notice( 'none', __( 'Could not update that course sponsorship.', 'tutor-learning-suite' ) );
		}

		$ids = isset( $_POST['course_ids'] ) && is_array( $_POST['course_ids'] )
			? array_values( array_unique( array_filter( array_map( 'absint', wp_unslash( $_POST['course_ids'] ) ) ) ) )
			: array();

		// "All matching filters" deliberately resolves server-side, including
		// courses on pagination pages that are not currently displayed.
		if ( ! empty( $_POST['all_filtered'] ) || ! empty( $_POST['select_all_filtered'] ) ) {
			$filters = $this->request_filters_from_post();
			$ids     = $this->get_matching_course_ids( $filters );
		}

		if ( empty( $ids ) ) {
			$this->redirect_with_notice( 'none', __( 'No courses were selected.', 'tutor-learning-suite' ) );
		}

		// Guard: the bulk "assign" panel always posts with action=assign, but its
		// Plan dropdown defaults to the blank "Select plan" option. Previously an
		// empty bulk_tier here was silently treated as "remove sponsorship",
		// wiping every selected course back to Not sponsored the moment someone
		// clicked Apply without re-picking a plan. Require an explicit plan for
		// bulk assignment; removal must go through the dedicated "remove" action.
		if ( 'assign' === $action ) {
			$bulk_tier_check = isset( $_POST['bulk_tier'] ) && ! is_array( $_POST['bulk_tier'] ) ? sanitize_key( wp_unslash( $_POST['bulk_tier'] ) ) : '';
			if ( '' === $bulk_tier_check ) {
				$this->redirect_with_notice( 'none', __( 'Select a sponsorship plan before applying bulk sponsorship. Selected courses were left unchanged; use "Cancel plan" on a course to remove its sponsorship.', 'tutor-learning-suite' ) );
			}
		}

		$count = 0;
		foreach ( $ids as $course_id ) {
			if ( TCSP_Plugin::COURSE_CPT !== get_post_type( $course_id ) || 'publish' !== get_post_status( $course_id ) ) {
				continue;
			}

			switch ( $action ) {
				case 'assign':
					if ( $this->save_course_assignment( $course_id, $_POST, true ) ) {
						$count++;
					}
					break;
				case 'remove':
					$this->remove_course_assignment( $course_id );
					$this->cancel_related_records( 'course', $course_id );
					$count++;
					break;
				case 'extend30':
					$this->extend_course_30_days( $course_id );
					$this->sync_manual_assignment_record( 'course', $course_id, true );
					$count++;
					break;
			}
		}

		$message = sprintf( _n( '%d course updated.', '%d courses updated.', $count, 'tutor-learning-suite' ), $count );
		$this->redirect_with_notice( 'updated', $message );
	}

	private function save_course_assignment( $course_id, $post, $require_tier = false ) {
		$tiers = $this->settings->promotion_tiers();
		$current_tier = TCSP_Plugin::get_promotion_tier( $course_id );
		$tier  = isset( $post['bulk_tier'] ) && ! is_array( $post['bulk_tier'] ) ? sanitize_key( wp_unslash( $post['bulk_tier'] ) ) : '';
		if ( '' === $tier ) {
			// When called from a context that requires an explicit plan (bulk
			// "assign"), a blank tier means "nothing to do" — skip this course
			// rather than silently clearing its existing sponsorship.
			if ( $require_tier ) {
				return false;
			}
			if ( ! current_user_can( 'manage_options' ) && $current_tier && ! $this->can_manage_course_plan( $current_tier ) ) return false;
			$this->remove_course_assignment( $course_id );
			$this->cancel_related_records( 'course', $course_id );
			return true;
		}
		if ( ! isset( $tiers[ $tier ] ) || ! $this->can_manage_course_plan( $tier ) ) {
			return false;
		}

		// The bulk-assign panel only exposes Start/End date inputs (no separate
		// schedule-type selector), so "custom" is the only schedule it can mean.
		// Falling back to "no_expiry" when bulk_schedule wasn't posted used to
		// silently discard whatever dates were typed into those fields.
		$schedule = isset( $post['bulk_schedule'] ) && ! is_array( $post['bulk_schedule'] )
			? sanitize_key( wp_unslash( $post['bulk_schedule'] ) )
			: ( ( isset( $post['bulk_start'] ) && '' !== trim( (string) wp_unslash( $post['bulk_start'] ) ) )
				|| ( isset( $post['bulk_end'] ) && '' !== trim( (string) wp_unslash( $post['bulk_end'] ) ) )
					? 'custom'
					: 'no_expiry' );
		$start    = '';
		$end      = '';

		if ( 'custom' === $schedule ) {
			$start = $this->sanitize_date( isset( $post['bulk_start'] ) ? wp_unslash( $post['bulk_start'] ) : '' );
			$end   = $this->sanitize_date( isset( $post['bulk_end'] ) ? wp_unslash( $post['bulk_end'] ) : '' );
		} elseif ( 'duration' === $schedule ) {
			$start = current_time( 'Y-m-d' );
			$days  = isset( $post['bulk_duration'] ) ? max( 1, min( 3650, absint( $post['bulk_duration'] ) ) ) : 30;
			$end   = wp_date( 'Y-m-d', current_time( 'timestamp' ) + ( $days * DAY_IN_SECONDS ) );
		} elseif ( '30' === $schedule || '60' === $schedule || '90' === $schedule || '180' === $schedule || '365' === $schedule ) {
			$start = current_time( 'Y-m-d' );
			$days  = absint( $schedule );
			$end   = wp_date( 'Y-m-d', current_time( 'timestamp' ) + ( $days * DAY_IN_SECONDS ) );
		} elseif ( 'immediate' === $schedule ) {
			$start = current_time( 'Y-m-d' );
		} // no_expiry leaves both blank.

		if ( '' !== $start && '' !== $end && $end < $start ) {
			$end = $start;
		}

		update_post_meta( $course_id, TCSP_Plugin::PROMOTION_TIER_META, $tier );
		update_post_meta( $course_id, TCSP_Plugin::PROMOTED_META, '1' );
		if ( '' !== $start ) {
			update_post_meta( $course_id, TCSP_Plugin::PROMOTION_START_META, $start );
		} else {
			delete_post_meta( $course_id, TCSP_Plugin::PROMOTION_START_META );
		}
		if ( '' !== $end ) {
			update_post_meta( $course_id, TCSP_Plugin::PROMOTION_END_META, $end );
		} else {
			delete_post_meta( $course_id, TCSP_Plugin::PROMOTION_END_META );
		}
		$this->sync_manual_assignment_record( 'course', $course_id, false, $start, $end, get_current_user_id() );
		return true;
	}

	private function remove_course_assignment( $course_id ) {
		delete_post_meta( $course_id, TCSP_Plugin::PROMOTION_TIER_META );
		delete_post_meta( $course_id, TCSP_Plugin::PROMOTED_META );
		delete_post_meta( $course_id, TCSP_Plugin::PROMOTION_START_META );
		delete_post_meta( $course_id, TCSP_Plugin::PROMOTION_END_META );
	}

	private function save_tutor_assignment( $tutor_id, $tier, $start = '', $end = '' ) {
		if ( ! current_user_can( 'manage_options' ) ) return false;
		$user = get_userdata( $tutor_id );
		$plans = $this->settings->tutor_promotion_tiers();
		if ( ! $user || ! in_array( 'tutor_instructor', (array) $user->roles, true ) ) return false;
		if ( '' === $tier ) {
			$this->remove_tutor_assignment( $tutor_id );
			$this->cancel_related_records( 'tutor', $tutor_id );
			return true;
		}
		if ( ! isset( $plans[ $tier ] ) ) return false;
		if ( '' !== $start && '' !== $end && $end < $start ) $end = $start;
		update_user_meta( $tutor_id, TCSP_Tutors::TIER_META, $tier );
		update_user_meta( $tutor_id, TCSP_Tutors::PROMOTED_META, '1' );
		if ( '' !== $start ) update_user_meta( $tutor_id, TCSP_Tutors::START_META, $start ); else delete_user_meta( $tutor_id, TCSP_Tutors::START_META );
		if ( '' !== $end ) update_user_meta( $tutor_id, TCSP_Tutors::END_META, $end ); else delete_user_meta( $tutor_id, TCSP_Tutors::END_META );
		$this->sync_manual_assignment_record( 'tutor', $tutor_id, false, $start, $end, get_current_user_id() );
		return true;
	}


	/**
	 * Keep dashboard/account history in sync with assignments made directly
	 * from the Courses/Tutors manager tables. These assignments are manual
	 * sponsorships and therefore appear to the target tutor as well.
	 */
	private function sync_manual_assignment_record( $type, $target_id, $from_extension = false, $start = '', $end = '', $created_by = 0 ) {
		$type      = sanitize_key( $type );
		$target_id = absint( $target_id );
		if ( ! in_array( $type, array( 'course', 'tutor' ), true ) || ! $target_id ) return 0;
		$tutor_id = 'course' === $type ? (int) get_post_field( 'post_author', $target_id ) : $target_id;
		if ( ! $tutor_id ) return 0;
		$tier = 'course' === $type ? (string) TCSP_Plugin::get_promotion_tier( $target_id ) : (string) get_user_meta( $target_id, TCSP_Tutors::TIER_META, true );
		if ( '' === $tier ) return 0;
		$portal = $this->get_portal();
		if ( ! $portal || ! method_exists( $portal, 'upsert_manual_assignment_record' ) ) return 0;
		return (int) $portal->upsert_manual_assignment_record( $type, $target_id, $tier, $tutor_id, $created_by ?: get_current_user_id(), $start, $end );
	}

	private function cancel_related_records( $type, $target_id ) {
		$records = get_posts( array(
			'post_type' => TCSP_Sponsorship_Portal::CPT,
			'post_status' => 'private',
			'posts_per_page' => 100,
			'fields' => 'ids',
			'meta_query' => array(
				'relation' => 'AND',
				array( 'key' => '_tcsp_target_type', 'value' => $type ),
				array( 'key' => '_tcsp_target_id', 'value' => absint( $target_id ) ),
				array( 'key' => '_tcsp_status', 'value' => array( 'pending', 'paid', 'active' ), 'compare' => 'IN' ),
			),
		) );
		foreach ( $records as $id ) update_post_meta( $id, '_tcsp_status', 'cancelled' );
	}

	private function remove_tutor_assignment( $tutor_id ) {
		delete_user_meta( $tutor_id, TCSP_Tutors::TIER_META );
		delete_user_meta( $tutor_id, TCSP_Tutors::PROMOTED_META );
		delete_user_meta( $tutor_id, TCSP_Tutors::START_META );
		delete_user_meta( $tutor_id, TCSP_Tutors::END_META );
	}

	private function tutor_status( $tutor_id ) {
		$tier = (string) get_user_meta( $tutor_id, TCSP_Tutors::TIER_META, true );
		if ( '' === $tier || ! get_user_meta( $tutor_id, TCSP_Tutors::PROMOTED_META, true ) ) return array( 'key' => 'none', 'label' => __( 'Not sponsored', 'tutor-learning-suite' ) );
		$today = current_time( 'Y-m-d' );
		$start = (string) get_user_meta( $tutor_id, TCSP_Tutors::START_META, true );
		$end = (string) get_user_meta( $tutor_id, TCSP_Tutors::END_META, true );
		if ( $start && $today < $start ) return array( 'key' => 'upcoming', 'label' => __( 'Upcoming', 'tutor-learning-suite' ) );
		if ( $end && $today > $end ) return array( 'key' => 'expired', 'label' => __( 'Expired', 'tutor-learning-suite' ) );
		return array( 'key' => 'active', 'label' => __( 'Active', 'tutor-learning-suite' ) );
	}

	private function render_tutor_filters( $filters ) {
		$base = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=tutors' );
		?>
		<form method="get" class="tcsp-sponsor-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>"><input type="hidden" name="tab" value="tutors">
			<div><label><?php esc_html_e( 'Search tutor', 'tutor-learning-suite' ); ?></label><input type="search" name="tcsp_tutor_filter_search" value="<?php echo esc_attr( $filters['s'] ); ?>" placeholder="<?php esc_attr_e( 'Name, username or email', 'tutor-learning-suite' ); ?>"></div>
			<div><label><?php esc_html_e( 'Plan', 'tutor-learning-suite' ); ?></label><select name="tcsp_tutor_filter_tier"><option value=""><?php esc_html_e( 'All plans', 'tutor-learning-suite' ); ?></option><?php foreach ( $this->settings->tutor_promotion_tiers() as $k => $p ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $filters['tier'], $k ); ?>><?php echo esc_html( $p['label'] ); ?></option><?php endforeach; ?></select></div>
			<div><label><?php esc_html_e( 'Status', 'tutor-learning-suite' ); ?></label><select name="tcsp_tutor_filter_status"><option value=""><?php esc_html_e( 'All statuses', 'tutor-learning-suite' ); ?></option><?php foreach ( array( 'active' => 'Active', 'upcoming' => 'Upcoming', 'expired' => 'Expired', 'none' => 'Not sponsored' ) as $k => $label ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $filters['status'], $k ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></div>
			<div class="tcsp-filter-buttons"><button class="button button-primary" type="submit"><?php esc_html_e( 'Filter', 'tutor-learning-suite' ); ?></button><a class="button" href="<?php echo esc_url( $base ); ?>"><?php esc_html_e( 'Reset', 'tutor-learning-suite' ); ?></a></div>
		</form>
		<?php
	}

	private function tutor_filters_from_post() {
		$src = wp_unslash( $_POST );
		return array(
			's' => isset( $src['tcsp_tutor_filter_search'] ) ? sanitize_text_field( $src['tcsp_tutor_filter_search'] ) : '',
			'tier' => isset( $src['tcsp_tutor_filter_tier'] ) ? sanitize_key( $src['tcsp_tutor_filter_tier'] ) : '',
			'status' => isset( $src['tcsp_tutor_filter_status'] ) ? sanitize_key( $src['tcsp_tutor_filter_status'] ) : '',
		);
	}

	private function tutor_filters() {
		$get = wp_unslash( $_GET );
		return array(
			's' => isset( $get['tcsp_tutor_filter_search'] ) ? sanitize_text_field( $get['tcsp_tutor_filter_search'] ) : '',
			'tier' => isset( $get['tcsp_tutor_filter_tier'] ) ? sanitize_key( $get['tcsp_tutor_filter_tier'] ) : '',
			'status' => isset( $get['tcsp_tutor_filter_status'] ) ? sanitize_key( $get['tcsp_tutor_filter_status'] ) : '',
		);
	}

	private function get_filtered_tutors( $filters ) {
		$args = array(
			'role' => 'tutor_instructor',
			'number' => 5000,
			'orderby' => 'display_name',
			'order' => 'ASC',
			'fields' => 'all',
		);
		if ( '' !== $filters['s'] ) {
			$args['search'] = '*' . $filters['s'] . '*';
			$args['search_columns'] = array( 'user_login', 'display_name', 'user_email' );
		}
		if ( '' !== $filters['tier'] ) {
			$args['meta_key'] = TCSP_Tutors::TIER_META;
			$args['meta_value'] = $filters['tier'];
		}
		$users = get_users( $args );
		$status = $filters['status'];
		if ( '' !== $status ) {
			$users = array_values( array_filter( $users, function( $u ) use ( $status ) { return $this->tutor_status( $u->ID )['key'] === $status; } ) );
		}
		return $users;
	}

	private function extend_course_30_days( $course_id ) {
		$today = current_time( 'timestamp' );
		$end   = TCSP_Plugin::get_promotion_end( $course_id );
		$base  = $today;
		if ( $end ) {
			$end_ts = strtotime( $end . ' 23:59:59' );
			if ( $end_ts && $end_ts > $base ) {
				$base = $end_ts;
			}
		}
		$next = wp_date( 'Y-m-d', $base + ( 30 * DAY_IN_SECONDS ) );
		if ( '' === TCSP_Plugin::get_promotion_tier( $course_id ) ) {
			return;
		}
		update_post_meta( $course_id, TCSP_Plugin::PROMOTION_END_META, $next );
	}

	private function sanitize_date( $raw ) {
		$raw = is_string( $raw ) ? sanitize_text_field( $raw ) : '';
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ? $raw : '';
	}

	private function request_filters_from_post() {
		$filters = array(
			's'            => '',
			'category'     => '',
			'tier'         => '',
			'price'        => '',
			'promo_status' => 'any',
		);
		$map = array(
			's'            => 'tcsp_filter_search',
			'category'     => 'tcsp_filter_category',
			'tier'         => 'tcsp_filter_tier',
			'price'        => 'tcsp_filter_price',
			'promo_status' => 'tcsp_filter_promo',
		);
		foreach ( $map as $key => $field ) {
			if ( isset( $_POST[ $field ] ) && ! is_array( $_POST[ $field ] ) ) {
				$value = sanitize_text_field( wp_unslash( $_POST[ $field ] ) );
				$filters[ $key ] = 's' === $key ? $value : sanitize_key( $value );
			}
		}
		return $filters;
	}

	private function current_filters() {
		$get = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return array(
			's'            => isset( $get['tcsp_filter_search'] ) ? sanitize_text_field( $get['tcsp_filter_search'] ) : '',
			'category'     => isset( $get['tcsp_filter_category'] ) ? sanitize_title( $get['tcsp_filter_category'] ) : '',
			'tier'         => isset( $get['tcsp_filter_tier'] ) ? sanitize_key( $get['tcsp_filter_tier'] ) : '',
			'price'        => isset( $get['tcsp_filter_price'] ) ? sanitize_key( $get['tcsp_filter_price'] ) : '',
			'promo_status' => isset( $get['tcsp_filter_promo'] ) ? sanitize_key( $get['tcsp_filter_promo'] ) : 'any',
		);
	}

	/**
	 * Build a server-side WP_Query for the table. Search covers title and
	 * author; filters are expressed in SQL so counts and pagination stay exact.
	 */
	private function build_query_args( $filters, $count_only = false ) {
		$args = array(
			'post_type'           => TCSP_Plugin::COURSE_CPT,
			'post_status'         => 'publish',
			'ignore_sticky_posts' => true,
			'orderby'             => 'title',
			'order'               => 'ASC',
		);

		$tax_query  = array();
		// Only hide TCSP's own internal checkout products and the known
		// private-session internal/rating courses. Do NOT filter on
		// _tutor_is_public_course: a NOT-equal meta query excludes posts
		// where that key does not exist, which hid normal Tutor LMS courses.
		$meta_query = array(
			array( 'key' => '_tcsp_sponsorship_checkout_product', 'compare' => 'NOT EXISTS' ),
			array( 'key' => '_tlms_ps_internal_course', 'compare' => 'NOT EXISTS' ),
			array( 'key' => '_tlms_ps_rating_course', 'compare' => 'NOT EXISTS' ),
		);

		if ( '' !== $filters['category'] ) {
			$term = get_term_by( 'slug', $filters['category'], 'course-category' );
			if ( $term && ! is_wp_error( $term ) ) {
				$ids = array( (int) $term->term_id );
				$children = get_term_children( (int) $term->term_id, 'course-category' );
				if ( ! is_wp_error( $children ) ) {
					$ids = array_merge( $ids, array_map( 'intval', $children ) );
				}
				$tax_query[] = array(
					'taxonomy'         => 'course-category',
					'field'            => 'term_id',
					'terms'            => array_values( array_unique( $ids ) ),
					'operator'         => 'IN',
					'include_children' => false,
				);
			}
		}

		if ( 'sponsored' === $filters['tier'] ) {
			$meta_query[] = array( 'key' => TCSP_Plugin::PROMOTION_TIER_META, 'compare' => 'EXISTS' );
		} elseif ( 'unsponsored' === $filters['tier'] ) {
			$meta_query[] = array( 'key' => TCSP_Plugin::PROMOTION_TIER_META, 'compare' => 'NOT EXISTS' );
		} elseif ( '' !== $filters['tier'] ) {
			$meta_query[] = array( 'key' => TCSP_Plugin::PROMOTION_TIER_META, 'value' => $filters['tier'] );
		}

		if ( in_array( $filters['price'], array( 'free', 'paid' ), true ) ) {
			$meta_query[] = array( 'key' => TCSP_Plugin::PRICE_TYPE_META, 'value' => $filters['price'] );
		}

		if ( 'active' === $filters['promo_status'] ) {
			$today = current_time( 'Y-m-d' );
			$meta_query[] = array(
				'relation' => 'AND',
				array( 'key' => TCSP_Plugin::PROMOTION_TIER_META, 'compare' => 'EXISTS' ),
				array( 'relation' => 'OR', array( 'key' => TCSP_Plugin::PROMOTION_START_META, 'compare' => 'NOT EXISTS' ), array( 'key' => TCSP_Plugin::PROMOTION_START_META, 'value' => '', 'compare' => '=' ), array( 'key' => TCSP_Plugin::PROMOTION_START_META, 'value' => $today, 'compare' => '<=', 'type' => 'DATE' ) ),
				array( 'relation' => 'OR', array( 'key' => TCSP_Plugin::PROMOTION_END_META, 'compare' => 'NOT EXISTS' ), array( 'key' => TCSP_Plugin::PROMOTION_END_META, 'value' => '', 'compare' => '=' ), array( 'key' => TCSP_Plugin::PROMOTION_END_META, 'value' => $today, 'compare' => '>=', 'type' => 'DATE' ) ),
			);
		} elseif ( 'expired' === $filters['promo_status'] ) {
			$today = current_time( 'Y-m-d' );
			$meta_query[] = array( 'key' => TCSP_Plugin::PROMOTION_TIER_META, 'compare' => 'EXISTS' );
			$meta_query[] = array( 'key' => TCSP_Plugin::PROMOTION_END_META, 'value' => $today, 'compare' => '<', 'type' => 'DATE' );
		} elseif ( 'upcoming' === $filters['promo_status'] ) {
			$today = current_time( 'Y-m-d' );
			$meta_query[] = array( 'key' => TCSP_Plugin::PROMOTION_TIER_META, 'compare' => 'EXISTS' );
			$meta_query[] = array( 'key' => TCSP_Plugin::PROMOTION_START_META, 'value' => $today, 'compare' => '>', 'type' => 'DATE' );
		} elseif ( 'none' === $filters['promo_status'] ) {
			$meta_query[] = array( 'key' => TCSP_Plugin::PROMOTION_TIER_META, 'compare' => 'NOT EXISTS' );
		}

		if ( ! empty( $tax_query ) ) {
			$args['tax_query'] = count( $tax_query ) > 1 ? array_merge( array( 'relation' => 'AND' ), $tax_query ) : $tax_query;
		}
		if ( ! empty( $meta_query ) ) {
			$args['meta_query'] = count( $meta_query ) > 1 ? array_merge( array( 'relation' => 'AND' ), $meta_query ) : $meta_query;
		}

		if ( $count_only ) {
			$args['posts_per_page'] = 1;
			$args['fields']         = 'ids';
			$args['no_found_rows']  = false;
		} else {
			$args['posts_per_page'] = self::PER_PAGE;
			$args['paged']          = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		return $args;
	}

	private function search_filter_hooks( $filters ) {
		if ( '' === $filters['s'] ) {
			return;
		}
		add_filter( 'posts_clauses', function ( $clauses ) use ( $filters ) {
			global $wpdb;
			$like = '%' . $wpdb->esc_like( $filters['s'] ) . '%';
			$clauses['join'] .= " LEFT JOIN {$wpdb->users} tcsp_admin_author ON tcsp_admin_author.ID = {$wpdb->posts}.post_author ";
			$condition = $wpdb->prepare( "({$wpdb->posts}.post_title LIKE %s OR tcsp_admin_author.display_name LIKE %s)", $like, $like );
			$clauses['where'] .= ' AND ' . $condition;
			return $clauses;
		}, 20, 1 );
	}

	private function get_matching_course_ids( $filters ) {
		$args = $this->build_query_args( $filters, false );
		$args['posts_per_page'] = -1;
		$args['nopaging']       = true;
		$args['fields']         = 'ids';
		$query = null;
		if ( '' !== $filters['s'] ) {
			$query = $this->make_search_query( $args, $filters );
		} else {
			$query = new WP_Query( $args );
		}
		return array_map( 'absint', (array) $query->posts );
	}

	private function make_search_query( $args, $filters ) {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( $filters['s'] ) . '%';
		$clauses_filter = function ( $clauses ) use ( $like ) {
			global $wpdb;
			$clauses['join'] .= " LEFT JOIN {$wpdb->users} tcsp_admin_author ON tcsp_admin_author.ID = {$wpdb->posts}.post_author ";
			$clauses['where'] .= ' AND ' . $wpdb->prepare( "({$wpdb->posts}.post_title LIKE %s OR tcsp_admin_author.display_name LIKE %s)", $like, $like );
			return $clauses;
		};
		add_filter( 'posts_clauses', $clauses_filter, 20, 1 );
		$q = new WP_Query( $args );
		remove_filter( 'posts_clauses', $clauses_filter, 20 );
		return $q;
	}

		private function verify_action_nonce( $action ) {
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		return wp_verify_nonce( $nonce, $action );
	}

	private function get_portal() {
		global $tcsp_sponsorship_portal;
		if ( isset( $tcsp_sponsorship_portal ) && $tcsp_sponsorship_portal instanceof TCSP_Sponsorship_Portal ) return $tcsp_sponsorship_portal;
		if ( class_exists( 'TCSP_Plugin' ) && isset( TCSP_Plugin::instance()->sponsorship_portal ) ) return TCSP_Plugin::instance()->sponsorship_portal;
		return null;
	}

	private function can_manage_course_plan( $tier ) {
		if ( current_user_can( 'manage_options' ) ) return true;
		$plans = $this->settings->promotion_tiers();
		return isset( $plans[ $tier ]['manager_id'] ) && absint( $plans[ $tier ]['manager_id'] ) === get_current_user_id();
	}

	private function managers() {
		return get_users( array( 'orderby' => 'display_name', 'order' => 'ASC', 'fields' => array( 'ID', 'display_name', 'user_email' ), 'number' => 500 ) );
	}

	private function user_label( $user_id ) {
		$user = get_userdata( $user_id );
		return $user ? $user->display_name . ' (' . $user->user_email . ')' : '';
	}

	/** Return only course-plan keys assigned to the current sponsorship manager. */
	private function managed_course_tiers( $course_plans ) {
		$managed = array();
		foreach ( (array) $course_plans as $key => $plan ) {
			if ( isset( $plan['manager_id'] ) && absint( $plan['manager_id'] ) === get_current_user_id() ) {
				$managed[] = (string) $key;
			}
		}
		return $managed;
	}

	/** Counts all visible requests without loading every post into PHP memory. */
	private function request_summary_counts( $course_plans ) {
		global $wpdb;
		$posts_table = $wpdb->posts;
		$meta_table  = $wpdb->postmeta;
		$sql = "SELECT COUNT(DISTINCT p.ID) AS total,
			COUNT(DISTINCT CASE WHEN st.meta_value = 'active' THEN p.ID END) AS active,
			COUNT(DISTINCT CASE WHEN st.meta_value = 'pending' THEN p.ID END) AS pending
			FROM {$posts_table} p
			LEFT JOIN {$meta_table} st ON st.post_id = p.ID AND st.meta_key = '_tcsp_status'
			WHERE p.post_type = %s AND p.post_status = %s";
		$args = array( TCSP_Sponsorship_Portal::CPT, 'private' );
		if ( ! current_user_can( 'manage_options' ) ) {
			$tiers = $this->managed_course_tiers( $course_plans );
			if ( ! $tiers ) {
				return array( 'total' => 0, 'active' => 0, 'pending' => 0 );
			}
			$placeholders = implode( ',', array_fill( 0, count( $tiers ), '%s' ) );
			$sql .= " AND EXISTS (SELECT 1 FROM {$meta_table} mt WHERE mt.post_id = p.ID AND mt.meta_key = '_tcsp_target_type' AND mt.meta_value = 'course')";
			$sql .= " AND EXISTS (SELECT 1 FROM {$meta_table} mp WHERE mp.post_id = p.ID AND mp.meta_key = '_tcsp_plan' AND mp.meta_value IN ({$placeholders}))";
			$args = array_merge( $args, $tiers );
		}
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $args ), ARRAY_A );
		return array(
			'total'   => isset( $row['total'] ) ? (int) $row['total'] : 0,
			'active'  => isset( $row['active'] ) ? (int) $row['active'] : 0,
			'pending' => isset( $row['pending'] ) ? (int) $row['pending'] : 0,
		);
	}

	public function render_page() {
		if ( ! self::can_manage() ) return;
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';
		if ( ! in_array( $active_tab, array( 'dashboard', 'plans', 'courses', 'tutors', 'requests' ), true ) ) $active_tab = 'dashboard';
		$course_plans = $this->settings->promotion_tiers();
		$tutor_plans  = $this->settings->tutor_promotion_tiers();
		$managers = current_user_can( 'manage_options' ) ? $this->managers() : array();
		$auto_activate = (bool) $this->settings->get( 'sponsorship_auto_activate' );
		$request_counts = $this->request_summary_counts( $course_plans );
		$request_page = isset( $_GET['requests_paged'] ) ? max( 1, absint( $_GET['requests_paged'] ) ) : 1;
		$request_status = isset( $_GET['request_status'] ) ? sanitize_key( wp_unslash( $_GET['request_status'] ) ) : '';
		$request_type = isset( $_GET['request_type'] ) ? sanitize_key( wp_unslash( $_GET['request_type'] ) ) : '';
		$valid_request_statuses = array( 'pending', 'paid', 'scheduled', 'active', 'expired', 'rejected', 'cancelled' );
		if ( ! in_array( $request_status, $valid_request_statuses, true ) ) $request_status = '';
		if ( ! in_array( $request_type, array( 'course', 'tutor' ), true ) ) $request_type = '';
		$request_meta_query = array( 'relation' => 'AND' );
		$request_args = array(
			'post_type'      => TCSP_Sponsorship_Portal::CPT,
			'post_status'    => 'private',
			'posts_per_page' => self::PER_PAGE,
			'paged'          => $request_page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		if ( ! current_user_can( 'manage_options' ) ) {
			$managed_tiers = $this->managed_course_tiers( $course_plans );
			if ( ! $managed_tiers ) {
				$request_args['post__in'] = array( 0 );
			} else {
				$request_meta_query[] = array( 'key' => '_tcsp_target_type', 'value' => 'course', 'compare' => '=' );
				$request_meta_query[] = array( 'key' => '_tcsp_plan', 'value' => $managed_tiers, 'compare' => 'IN' );
			}
		} elseif ( $request_type ) {
			$request_meta_query[] = array( 'key' => '_tcsp_target_type', 'value' => $request_type, 'compare' => '=' );
		}
		if ( $request_status ) {
			$request_meta_query[] = array( 'key' => '_tcsp_status', 'value' => $request_status, 'compare' => '=' );
		}
		if ( count( $request_meta_query ) > 1 ) $request_args['meta_query'] = $request_meta_query;
		$request_query = new WP_Query( $request_args );
		$requests = $request_query->posts;
		?>
		<div class="wrap tcsp-sponsor-manager">
			<h1><?php esc_html_e( 'Sponsorship Manager', 'tutor-learning-suite' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Manage paid and manual sponsorships for courses and tutor profiles from one place. Successful system payments can activate automatically; staff can also approve or create sponsorships manually.', 'tutor-learning-suite' ); ?></p>
			<nav class="nav-tab-wrapper" style="margin:18px 0">
				<?php foreach ( array( 'dashboard' => 'Overview', 'plans' => 'Plans', 'courses' => 'Courses', 'tutors' => 'Tutors', 'requests' => 'Requests' ) as $tab => $label ) : ?>
					<a class="nav-tab <?php echo $active_tab === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => $tab ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html__( $label, 'tutor-learning-suite' ); ?></a>
				<?php endforeach; ?>
			</nav>

			<?php if ( 'dashboard' === $active_tab ) : ?>
				<div class="tcsp-manager-grid">
					<div class="tcsp-manager-card"><strong><?php echo esc_html( number_format_i18n( $request_counts['total'] ) ); ?></strong><span><?php esc_html_e( 'Sponsorship records', 'tutor-learning-suite' ); ?></span></div>
					<div class="tcsp-manager-card"><strong><?php echo esc_html( number_format_i18n( $request_counts['active'] ) ); ?></strong><span><?php esc_html_e( 'Active promotions', 'tutor-learning-suite' ); ?></span></div>
					<div class="tcsp-manager-card"><strong><?php echo esc_html( number_format_i18n( $request_counts['pending'] ) ); ?></strong><span><?php esc_html_e( 'Pending requests', 'tutor-learning-suite' ); ?></span></div>
				</div>
				<div class="tcsp-admin-plans">
					<h2><?php esc_html_e( 'Automation', 'tutor-learning-suite' ); ?></h2>
					<p><?php echo $auto_activate ? esc_html__( 'Automatic activation is ON: a successful Tutor LMS system payment changes a matching sponsorship request to Active.', 'tutor-learning-suite' ) : esc_html__( 'Automatic activation is OFF: paid requests remain pending until a manager/admin activates them.', 'tutor-learning-suite' ); ?></p>
					<p><?php esc_html_e( 'Manual sponsorships do not require payment. Use the Requests tab to create them or review requests.', 'tutor-learning-suite' ); ?></p>
				</div>
				<div class="tcsp-admin-plans">
					<h2><?php esc_html_e( 'Recommended workflow', 'tutor-learning-suite' ); ?></h2>
					<ol><li><?php esc_html_e( 'Tutor selects a course/profile and sponsorship plan.', 'tutor-learning-suite' ); ?></li><li><?php esc_html_e( 'Native Tutor LMS checkout records the payment.', 'tutor-learning-suite' ); ?></li><li><?php esc_html_e( 'When paid, the matching promotion activates automatically when automation is enabled.', 'tutor-learning-suite' ); ?></li><li><?php esc_html_e( 'Managers handle manual requests and exceptions from Requests.', 'tutor-learning-suite' ); ?></li></ol>
				</div>
			<?php elseif ( 'plans' === $active_tab ) : ?>
				<?php if ( ! current_user_can( 'manage_options' ) ) : ?>
					<div class="notice notice-warning"><p><?php esc_html_e( 'Only administrators can edit sponsorship plans and choose the Sponsoring Manager.', 'tutor-learning-suite' ); ?></p></div>
				<?php else : ?>
					<div class="tcsp-admin-plans"><h2><?php esc_html_e( 'Sponsorship Plans', 'tutor-learning-suite' ); ?></h2>
					<form method="post"><?php wp_nonce_field( self::NONCE ); ?><input type="hidden" name="tcsp_sponsorship_action" value="save_plans"><input type="hidden" name="tcsp_return_tab" value="plans">
					<label class="tcsp-auto-toggle"><input type="checkbox" name="sponsorship_notifications" value="1" <?php checked( (bool) $this->settings->get( 'sponsorship_notifications' ), true ); ?>> <?php esc_html_e( 'Send sponsorship email notifications to tutors and assigned Sponsoring Managers', 'tutor-learning-suite' ); ?></label><div class="tcsp-unit-settings"><label for="tcsp_max_units"><strong><?php esc_html_e( 'Maximum units per sponsorship purchase', 'tutor-learning-suite' ); ?></strong></label><input id="tcsp_max_units" type="number" min="1" max="12" name="sponsorship_max_units" value="<?php echo esc_attr( $this->settings->get( 'sponsorship_max_units' ) ?: 12 ); ?>" class="small-text"><span class="description"><?php esc_html_e( 'The tutor-facing unit selector uses 1 / 3 / 6 / 12 (capped by this value). Renewing an active sponsorship extends its current end date.', 'tutor-learning-suite' ); ?></span></div>
					<div class="tcsp-unit-settings"><label for="tcsp_unpaid_cancel_days"><strong><?php esc_html_e( 'Auto-cancel unpaid requests after (days)', 'tutor-learning-suite' ); ?></strong></label><input id="tcsp_unpaid_cancel_days" type="number" min="0" max="365" name="sponsorship_unpaid_cancel_days" value="<?php echo esc_attr( (int) $this->settings->get( 'sponsorship_unpaid_cancel_days' ) ); ?>" class="small-text"><span class="description"><?php esc_html_e( 'Sponsorship requests the tutor never paid (and never submitted an offline payment for) are cancelled together with their Tutor LMS order. 0 = never cancel automatically.', 'tutor-learning-suite' ); ?></span></div>
					<h3><?php esc_html_e( 'Course Sponsorship Plans', 'tutor-learning-suite' ); ?></h3>
					<table class="widefat striped tcsp-plan-editor"><thead><tr><th>Plan</th><th>Price</th><th>Days</th><th>3+ units % off</th><th>6+ units % off</th><th>12 units % off</th><th>Priority</th><th>Exposure</th><th>Color</th><th>Sponsoring Manager</th><th>Description</th></tr></thead><tbody>
					<?php foreach ( $course_plans as $key => $tier ) : ?><tr>
					<td><input type="text" name="promotion_tiers[<?php echo esc_attr( $key ); ?>][label]" value="<?php echo esc_attr( $tier['label'] ); ?>" maxlength="60"><small><?php echo esc_html( $key ); ?></small></td>
					<td><input type="number" min="0" step="0.01" name="promotion_tiers[<?php echo esc_attr( $key ); ?>][price]" value="<?php echo esc_attr( $tier['price'] ); ?>" class="small-text"></td>
					<td><input type="number" min="1" max="3650" name="promotion_tiers[<?php echo esc_attr( $key ); ?>][duration]" value="<?php echo esc_attr( $tier['duration'] ); ?>" class="small-text"></td><td><input type="number" min="0" max="100" step="0.1" name="promotion_tiers[<?php echo esc_attr( $key ); ?>][discount_3]" value="<?php echo esc_attr( $tier['discount_3'] ?? 0 ); ?>" class="small-text"></td><td><input type="number" min="0" max="100" step="0.1" name="promotion_tiers[<?php echo esc_attr( $key ); ?>][discount_6]" value="<?php echo esc_attr( $tier['discount_6'] ?? 0 ); ?>" class="small-text"></td><td><input type="number" min="0" max="100" step="0.1" name="promotion_tiers[<?php echo esc_attr( $key ); ?>][discount_12]" value="<?php echo esc_attr( $tier['discount_12'] ?? 0 ); ?>" class="small-text"></td>
					<td><input type="number" min="0" max="1000" name="promotion_tiers[<?php echo esc_attr( $key ); ?>][weight]" value="<?php echo esc_attr( $tier['weight'] ); ?>" class="small-text"></td>
					<td><input type="number" min="1" max="20" name="promotion_tiers[<?php echo esc_attr( $key ); ?>][exposure]" value="<?php echo esc_attr( $tier['exposure'] ); ?>" class="small-text"></td>
					<td><input type="text" name="promotion_tiers[<?php echo esc_attr( $key ); ?>][color]" value="<?php echo esc_attr( $tier['color'] ); ?>" pattern="#[0-9a-fA-F]{6}" class="small-text"></td>
					<td><select name="promotion_tiers[<?php echo esc_attr( $key ); ?>][manager_id]"><option value="0"><?php esc_html_e( 'Administrator only', 'tutor-learning-suite' ); ?></option><?php foreach ( $managers as $m ) : ?><option value="<?php echo (int) $m->ID; ?>" <?php selected( absint( $tier['manager_id'] ?? 0 ), (int) $m->ID ); ?>><?php echo esc_html( $m->display_name ); ?></option><?php endforeach; ?></select></td>
					<td><textarea name="promotion_tiers[<?php echo esc_attr( $key ); ?>][description]" rows="2"><?php echo esc_textarea( $tier['description'] ); ?></textarea></td>
					</tr><?php endforeach; ?></tbody></table>
					<h3><?php esc_html_e( 'Tutor Profile Sponsorship Plans', 'tutor-learning-suite' ); ?></h3>
					<table class="widefat striped tcsp-plan-editor"><thead><tr><th>Plan</th><th>Price</th><th>Days</th><th>3+ units % off</th><th>6+ units % off</th><th>12 units % off</th><th>Priority</th><th>Exposure</th><th>Color</th><th>Description</th></tr></thead><tbody>
					<?php foreach ( $tutor_plans as $key => $tier ) : ?><tr>
					<td><input type="text" name="tutor_promotion_tiers[<?php echo esc_attr( $key ); ?>][label]" value="<?php echo esc_attr( $tier['label'] ); ?>" maxlength="60"><small><?php echo esc_html( $key ); ?></small></td>
					<td><input type="number" min="0" step="0.01" name="tutor_promotion_tiers[<?php echo esc_attr( $key ); ?>][price]" value="<?php echo esc_attr( $tier['price'] ); ?>" class="small-text"></td>
					<td><input type="number" min="1" max="3650" name="tutor_promotion_tiers[<?php echo esc_attr( $key ); ?>][duration]" value="<?php echo esc_attr( $tier['duration'] ); ?>" class="small-text"></td><td><input type="number" min="0" max="100" step="0.1" name="tutor_promotion_tiers[<?php echo esc_attr( $key ); ?>][discount_3]" value="<?php echo esc_attr( $tier['discount_3'] ?? 0 ); ?>" class="small-text"></td><td><input type="number" min="0" max="100" step="0.1" name="tutor_promotion_tiers[<?php echo esc_attr( $key ); ?>][discount_6]" value="<?php echo esc_attr( $tier['discount_6'] ?? 0 ); ?>" class="small-text"></td><td><input type="number" min="0" max="100" step="0.1" name="tutor_promotion_tiers[<?php echo esc_attr( $key ); ?>][discount_12]" value="<?php echo esc_attr( $tier['discount_12'] ?? 0 ); ?>" class="small-text"></td>
					<td><input type="number" min="0" max="1000" name="tutor_promotion_tiers[<?php echo esc_attr( $key ); ?>][weight]" value="<?php echo esc_attr( $tier['weight'] ); ?>" class="small-text"></td>
					<td><input type="number" min="1" max="20" name="tutor_promotion_tiers[<?php echo esc_attr( $key ); ?>][exposure]" value="<?php echo esc_attr( $tier['exposure'] ); ?>" class="small-text"></td>
					<td><input type="text" name="tutor_promotion_tiers[<?php echo esc_attr( $key ); ?>][color]" value="<?php echo esc_attr( $tier['color'] ); ?>" pattern="#[0-9a-fA-F]{6}" class="small-text"></td>
					<td><textarea name="tutor_promotion_tiers[<?php echo esc_attr( $key ); ?>][description]" rows="2"><?php echo esc_textarea( $tier['description'] ); ?></textarea></td>
					</tr><?php endforeach; ?></tbody></table>
					<p><button class="button button-primary" type="submit"><?php esc_html_e( 'Save Sponsorship Settings', 'tutor-learning-suite' ); ?></button></p></form></div>
				<?php endif; ?>
			<?php elseif ( 'courses' === $active_tab ) : ?>
				<?php $filters = $this->current_filters(); $args = $this->build_query_args( $filters ); $q = new WP_Query( $args ); $tiers=$course_plans; ?>
				<div class="tcsp-admin-plans"><h2><?php esc_html_e( 'Course Sponsorships', 'tutor-learning-suite' ); ?></h2><p class="description"><?php esc_html_e( 'Manual course sponsorships can be assigned here. A Sponsoring Manager can manage only the course plans assigned to that manager.', 'tutor-learning-suite' ); ?></p>
				<form method="get" class="tcsp-sponsor-filters"><input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>"><input type="hidden" name="tab" value="courses"><div><label>Search course / instructor</label><input type="search" name="tcsp_filter_search" value="<?php echo esc_attr( $filters['s'] ); ?>"></div><div><label>Category</label><select name="tcsp_filter_category"><option value="">All categories</option><?php $cats=get_terms(array('taxonomy'=>'course-category','hide_empty'=>false,'orderby'=>'name','order'=>'ASC')); if(!is_wp_error($cats)) foreach($cats as $cat):?><option value="<?php echo esc_attr($cat->slug);?>" <?php selected($filters['category'],$cat->slug);?>><?php echo esc_html($this->category_label($cat,$cats));?></option><?php endforeach;?></select></div><div><label>Plan</label><select name="tcsp_filter_tier"><option value="">All</option><option value="sponsored" <?php selected($filters['tier'],'sponsored');?>>Sponsored</option><option value="unsponsored" <?php selected($filters['tier'],'unsponsored');?>>Unsponsored</option><?php foreach($tiers as $k=>$t):?><option value="<?php echo esc_attr($k);?>" <?php selected($filters['tier'],$k);?>><?php echo esc_html($t['label']);?></option><?php endforeach;?></select></div><div class="tcsp-filter-buttons"><button class="button button-primary">Filter</button><a class="button" href="<?php echo esc_url(admin_url('admin.php?page='.self::PAGE_SLUG.'&tab=courses'));?>">Reset</a></div></form>
				<form method="post" id="tcsp-bulk-form"><?php wp_nonce_field(self::NONCE); ?><input type="hidden" name="tcsp_sponsorship_action" id="tcsp-action" value="assign"><input type="hidden" name="bulk_schedule" value="custom"><input type="hidden" name="tcsp_return_tab" value="courses"><div class="tcsp-bulk-panel"><div class="tcsp-bulk-head"><label><input type="checkbox" id="tcsp-head-check"> <?php esc_html_e('Select page','tutor-learning-suite');?></label><span id="tcsp-selected-count">0 selected</span><label><input type="checkbox" id="tcsp-select-all-filtered" name="all_filtered"> <?php esc_html_e('All matching filters','tutor-learning-suite');?></label></div><div class="tcsp-bulk-fields"><div><label>Plan</label><select name="bulk_tier"><option value="">Select plan</option><?php foreach($tiers as $k=>$t):?><option value="<?php echo esc_attr($k);?>"><?php echo esc_html($t['label']);?> — <?php echo esc_html(number_format_i18n($t['price'],2));?></option><?php endforeach;?></select></div><div><label>Start</label><input type="date" name="bulk_start"></div><div><label>End</label><input type="date" name="bulk_end"></div><div><button class="button button-primary" type="submit">Apply manual sponsorship</button></div></div></div></form>
				<table class="widefat striped"><thead><tr><th class="check-column"></th><th>Course</th><th>Instructor</th><th>Plan</th><th>Status</th><th>Dates</th><th>Action</th></tr></thead><tbody><?php foreach($q->posts as $course):$cid=(int)$course->ID;$tier=(string)TCSP_Plugin::get_promotion_tier($cid);$st=TCSP_Plugin::get_promotion_start($cid);$en=TCSP_Plugin::get_promotion_end($cid);$status=$this->course_status($cid);?><tr><th class="check-column"><input type="checkbox" name="course_ids[]" value="<?php echo $cid;?>" class="tcsp-course-check" form="tcsp-bulk-form"></th><td><a href="<?php echo esc_url(get_edit_post_link($cid));?>"><strong><?php echo esc_html(get_the_title($cid));?></strong></a></td><td><?php echo esc_html(get_the_author_meta('display_name',$course->post_author));?></td><td><?php echo $tier&&isset($tiers[$tier])?esc_html($tiers[$tier]['label']):esc_html__('Not sponsored','tutor-learning-suite');?></td><td><span class="tcsp-status tcsp-status-<?php echo esc_attr($status['key']);?>"><?php echo esc_html($status['label']);?></span></td><td><?php echo esc_html(trim($st.' → '.$en,' →'));?></td><td><?php if($tier):?><form method="post" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( 'Cancel this course sponsorship?', 'tutor-learning-suite' ) ); ?>');"><?php wp_nonce_field(self::NONCE);?><input type="hidden" name="tcsp_sponsorship_action" value="cancel_course_plan"><input type="hidden" name="course_id" value="<?php echo $cid;?>"><button type="submit" class="button button-small">Cancel plan</button></form><?php endif;?></td></tr><?php endforeach;if(!$q->posts):?><tr><td colspan="7">No courses found.</td></tr><?php endif;?></tbody></table>
				</div>
			<?php elseif ( 'tutors' === $active_tab ) : ?>
				<?php $tutor_filters = $this->tutor_filters(); $tutor_users = $this->get_filtered_tutors( $tutor_filters ); $tutor_total = count( $tutor_users ); $tutor_page = isset( $_GET['tutor_paged'] ) ? max( 1, absint( $_GET['tutor_paged'] ) ) : 1; $tutor_offset = ( $tutor_page - 1 ) * self::PER_PAGE; $tutor_page_users = array_slice( $tutor_users, $tutor_offset, self::PER_PAGE ); $tutor_pages = (int) ceil( $tutor_total / self::PER_PAGE ); ?>
				<div class="tcsp-admin-plans"><h2><?php esc_html_e( 'Tutor Profile Sponsorships', 'tutor-learning-suite' ); ?></h2><p class="description"><?php esc_html_e( 'Search, filter, bulk assign plans and dates, or cancel tutor-profile sponsorships.', 'tutor-learning-suite' ); ?></p>
				<?php $this->render_tutor_filters( $tutor_filters ); ?>
				<form method="post" id="tcsp-tutor-bulk-form" class="tcsp-tutor-bulk-form"><?php wp_nonce_field(self::NONCE); ?><input type="hidden" name="tcsp_sponsorship_action" value="bulk_tutor_sponsor"><input type="hidden" name="tcsp_return_tab" value="tutors"><input type="hidden" name="tcsp_tutor_filter_search" value="<?php echo esc_attr($tutor_filters['s']);?>"><input type="hidden" name="tcsp_tutor_filter_tier" value="<?php echo esc_attr($tutor_filters['tier']);?>"><input type="hidden" name="tcsp_tutor_filter_status" value="<?php echo esc_attr($tutor_filters['status']);?>"><div class="tcsp-bulk-panel"><div class="tcsp-bulk-head"><label><input type="checkbox" id="tcsp-tutor-head-check"> <?php esc_html_e('Select page','tutor-learning-suite');?></label><span id="tcsp-tutor-selected-count">0 selected</span><label><input type="checkbox" name="select_all_tutors_filtered" value="1"> <?php esc_html_e('All matching filters','tutor-learning-suite');?></label></div><div class="tcsp-bulk-fields"><div><label>Plan</label><select name="bulk_tutor_tier"><option value="">Remove sponsorship</option><?php foreach($tutor_plans as $k=>$p):?><option value="<?php echo esc_attr($k);?>"><?php echo esc_html($p['label']);?></option><?php endforeach;?></select></div><div><label>Start</label><input type="date" name="bulk_tutor_start"></div><div><label>End</label><input type="date" name="bulk_tutor_end"></div><div><button class="button button-primary" type="submit">Apply to selected</button></div></div></div></form>
				<table class="widefat striped"><thead><tr><th class="check-column"></th><th>Tutor</th><th>Plan</th><th>Start</th><th>End</th><th>Status</th><th>Action</th></tr></thead><tbody>
				<?php foreach($tutor_page_users as $t): $tier=(string)get_user_meta($t->ID,TCSP_Tutors::TIER_META,true); $st=(string)get_user_meta($t->ID,TCSP_Tutors::START_META,true); $en=(string)get_user_meta($t->ID,TCSP_Tutors::END_META,true); $status=$this->tutor_status($t->ID); ?>
				<tr><th class="check-column"><input type="checkbox" name="tutor_ids[]" value="<?php echo (int)$t->ID; ?>" class="tcsp-tutor-check" form="tcsp-tutor-bulk-form"></th><td><strong><?php echo esc_html($t->display_name); ?></strong><br><span class="description">@<?php echo esc_html($t->user_login); ?></span></td><td><form method="post" class="tcsp-inline-status"><?php wp_nonce_field(self::NONCE); ?><input type="hidden" name="tcsp_sponsorship_action" value="save_tutor_row"><input type="hidden" name="tutor_id" value="<?php echo (int)$t->ID; ?>"><select name="tutor_tier"><option value="">Not sponsored</option><?php foreach($tutor_plans as $k=>$p): ?><option value="<?php echo esc_attr($k); ?>" <?php selected($tier,$k); ?>><?php echo esc_html($p['label']); ?></option><?php endforeach; ?></select></td><td><input type="date" name="tutor_start" value="<?php echo esc_attr($st); ?>"></td><td><input type="date" name="tutor_end" value="<?php echo esc_attr($en); ?>"></td><td><span class="tcsp-status tcsp-status-<?php echo esc_attr($status['key']); ?>"><?php echo esc_html($status['label']); ?></span></td><td><button class="button button-small" type="submit">Save</button></form> <?php if ( $tier ) : ?><form method="post" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( 'Cancel this tutor sponsorship?', 'tutor-learning-suite' ) ); ?>');"><?php wp_nonce_field(self::NONCE); ?><input type="hidden" name="tcsp_sponsorship_action" value="cancel_tutor_plan"><input type="hidden" name="tutor_id" value="<?php echo (int)$t->ID; ?>"><button class="button button-small" type="submit">Cancel plan</button></form><?php endif; ?></td></tr>
				<?php endforeach; if(!$tutor_page_users): ?><tr><td colspan="7">No tutors found.</td></tr><?php endif; ?></tbody></table>
				<?php if($tutor_pages>1): ?><div class="tablenav"><div class="tablenav-pages"><?php for($i=1;$i<=$tutor_pages;$i++): $url=add_query_arg(array('page'=>self::PAGE_SLUG,'tab'=>'tutors','tutor_paged'=>$i,'tcsp_tutor_filter_search'=>$tutor_filters['s'],'tcsp_tutor_filter_tier'=>$tutor_filters['tier'],'tcsp_tutor_filter_status'=>$tutor_filters['status']),admin_url('admin.php')); ?><a class="button <?php echo $i===$tutor_page?'button-primary':''; ?>" href="<?php echo esc_url($url); ?>"><?php echo (int)$i; ?></a><?php endfor; ?></div></div><?php endif; ?></div>

			<?php else : ?>
				<div class="tcsp-admin-plans"><h2><?php esc_html_e( 'Requests & Manual Sponsorship', 'tutor-learning-suite' ); ?></h2><p class="description"><?php esc_html_e( 'Automatic requests are created by the tutor portal and reconciled with Native Tutor LMS payments. Manual sponsorships can be created here without payment.', 'tutor-learning-suite' ); ?></p>
				<form method="get" class="tcsp-request-filters"><input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>"><input type="hidden" name="tab" value="requests"><div><label for="tcsp-request-status"><?php esc_html_e( 'Status', 'tutor-learning-suite' ); ?></label><select id="tcsp-request-status" name="request_status"><option value=""><?php esc_html_e( 'All statuses', 'tutor-learning-suite' ); ?></option><?php foreach ( $valid_request_statuses as $status_option ) : ?><option value="<?php echo esc_attr( $status_option ); ?>" <?php selected( $request_status, $status_option ); ?>><?php echo esc_html( ucfirst( $status_option ) ); ?></option><?php endforeach; ?></select></div><?php if ( current_user_can( 'manage_options' ) ) : ?><div><label for="tcsp-request-type"><?php esc_html_e( 'Promotion type', 'tutor-learning-suite' ); ?></label><select id="tcsp-request-type" name="request_type"><option value=""><?php esc_html_e( 'All types', 'tutor-learning-suite' ); ?></option><option value="course" <?php selected( $request_type, 'course' ); ?>><?php esc_html_e( 'Courses', 'tutor-learning-suite' ); ?></option><option value="tutor" <?php selected( $request_type, 'tutor' ); ?>><?php esc_html_e( 'Tutor profiles', 'tutor-learning-suite' ); ?></option></select></div><?php endif; ?><div class="tcsp-filter-buttons"><button class="button button-primary" type="submit"><?php esc_html_e( 'Filter requests', 'tutor-learning-suite' ); ?></button><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=requests' ) ); ?>"><?php esc_html_e( 'Reset', 'tutor-learning-suite' ); ?></a></div></form>
				<?php if ( current_user_can('manage_options') || ! empty($course_plans) ) : ?><form method="post" class="tcsp-manual-form"><?php wp_nonce_field('tcsp_manual_sponsor');?><input type="hidden" name="tcsp_sponsorship_action" value="manual_sponsor"><div><label>Type</label><select name="manual_target_type" id="tcsp-manual-type"><option value="course">Course</option><option value="tutor">Tutor profile</option></select></div><div><label>Target</label><select name="manual_target_id" id="tcsp-manual-target"><option value="">Select target</option><?php $all_courses=get_posts(array(
                    'post_type'=>TCSP_Plugin::COURSE_CPT,
                    'post_status'=>'publish',
                    'posts_per_page'=>200,
                    'orderby'=>'title',
                    'order'=>'ASC',
                    'meta_query'=>array(
                        // Keep normal published courses visible. Only hide
                        // TCSP's own sponsorship checkout products and the known
                        // Private Sessions internal/rating courses.
                        array('key'=>'_tcsp_sponsorship_checkout_product','compare'=>'NOT EXISTS'),
                        array('key'=>'_tlms_ps_internal_course','compare'=>'NOT EXISTS'),
                        array('key'=>'_tlms_ps_rating_course','compare'=>'NOT EXISTS'),
                    ),
                ));foreach($all_courses as $c):?><option data-type="course" value="<?php echo (int)$c->ID;?>"><?php echo esc_html($c->post_title);?></option><?php endforeach;$all_tutors=get_users(array('role'=>'tutor_instructor','number'=>200,'orderby'=>'display_name','order'=>'ASC'));foreach($all_tutors as $t):?><option data-type="tutor" value="<?php echo (int)$t->ID;?>"><?php echo esc_html($t->display_name);?></option><?php endforeach;?></select></div><div><label>Plan</label><select name="manual_tier" id="tcsp-manual-tier"><option value="">Select plan</option><?php foreach($course_plans as $k=>$t):?><option data-type="course" value="<?php echo esc_attr($k);?>"><?php echo esc_html($t['label']);?> — <?php echo esc_html(number_format_i18n($t['price'],2));?></option><?php endforeach;?><?php foreach($tutor_plans as $k=>$t):?><option data-type="tutor" value="<?php echo esc_attr($k);?>"><?php echo esc_html($t['label']);?> — <?php echo esc_html(number_format_i18n($t['price'],2));?></option><?php endforeach;?></select></div><div><label>Units</label><select name="manual_units"><?php foreach ( array_values( array_filter( array( 1, 3, 6, 12 ), function( $n ) { return $n <= min(12, max(1, absint($this->settings->get('sponsorship_max_units') ?: 12))); } ) ) as $mu ) : ?><option value="<?php echo (int)$mu; ?>"><?php echo (int)$mu; ?></option><?php endforeach; ?></select></div><label class="tcsp-auto-toggle"><input type="checkbox" name="manual_activate" value="1" checked> Activate immediately</label><button class="button button-primary">Create manual sponsorship</button></form><?php endif;?>
				<form method="post" id="tcsp-request-bulk-form"><?php wp_nonce_field(self::NONCE); ?><input type="hidden" name="tcsp_sponsorship_action" value="bulk_request_action"><div class="tcsp-bulk-panel"><div class="tcsp-bulk-head"><label><input type="checkbox" id="tcsp-request-head-check"> <?php esc_html_e('Select page','tutor-learning-suite');?></label><span id="tcsp-request-selected-count">0 selected</span></div><div class="tcsp-bulk-fields"><div><label>Bulk action</label><select name="bulk_request_status"><option value="">Choose status/action</option><option value="pending">Set Pending</option><option value="paid">Set Paid</option><option value="scheduled">Set Scheduled</option><option value="active">Activate</option><option value="rejected">Reject</option><option value="cancelled">Cancel</option></select></div><div><button class="button button-primary" type="submit">Apply to selected</button></div></div></div></form>
				<table class="widefat striped"><thead><tr><th class="check-column"></th><th>#</th><th>Tutor</th><th>Target</th><th>Type</th><th>Plan</th><th>Units</th><th>Amount</th><th>Duration</th><th>Source</th><th>Payment</th><th>Status</th><th>Action</th></tr></thead><tbody><?php foreach($requests as $r):$type=(string)get_post_meta($r->ID,'_tcsp_target_type',true);$target=absint(get_post_meta($r->ID,'_tcsp_target_id',true));$tier=(string)get_post_meta($r->ID,'_tcsp_plan',true);$amount=(float)get_post_meta($r->ID,'_tcsp_amount',true);$status=(string)get_post_meta($r->ID,'_tcsp_status',true);$source=(string)get_post_meta($r->ID,'_tcsp_source',true);$native=absint(get_post_meta($r->ID,'_tcsp_native_order_id',true));$u=get_userdata((int)$r->post_author);$label='course'===$type?get_the_title($target):get_the_author_meta('display_name',$target);$planset='tutor'===$type?$tutor_plans:$course_plans; $manager_id='course'===$type?absint($planset[$tier]['manager_id']??0):0; $allowed=current_user_can('manage_options')||('course'===$type&&$manager_id===get_current_user_id());?><tr><th class="check-column"><input type="checkbox" class="tcsp-request-check" name="request_ids[]" value="<?php echo (int)$r->ID;?>" form="tcsp-request-bulk-form"></th><td>#<?php echo (int)$r->ID;?></td><td><?php echo esc_html($u?$u->display_name:'#'.$r->post_author);?></td><td><?php echo esc_html($label);?></td><td><?php echo esc_html(ucfirst($type));?></td><td><?php echo esc_html($planset[$tier]['label']??$tier);?></td><td><?php echo (int) max(1, min(12, absint(get_post_meta($r->ID,'_tcsp_units',true) ?: 1)));?></td><td><?php echo esc_html(number_format_i18n($amount,2));?></td><td><?php echo esc_html((int)get_post_meta($r->ID,'_tcsp_duration',true) ?: (int)($planset[$tier]['duration'] ?? 0));?> days</td><td><span class="tcsp-source tcsp-source-<?php echo esc_attr($source);?>"><?php echo $source==='manual'?'Manual':'Payment';?></span></td><td><?php echo $native?'#'.(int)$native:($source==='manual'?'—':'Pending');?></td><td><span class="tcsp-status tcsp-status-<?php echo esc_attr($status);?>"><?php echo esc_html(ucfirst($status));?></span></td><td><?php if($allowed):?><div class="tcsp-row-actions"><form method="post" class="tcsp-inline-status"><?php wp_nonce_field('tcsp_order_admin');?><input type="hidden" name="tcsp_order_action" value="status"><input type="hidden" name="order_id" value="<?php echo (int)$r->ID;?>"><select name="status"><?php foreach(array('pending','paid','scheduled','active','expired','rejected','cancelled') as $op):?><option value="<?php echo esc_attr($op);?>" <?php selected($status,$op);?>><?php echo esc_html(ucfirst($op));?></option><?php endforeach;?></select><button class="button button-small">Save</button></form><?php if($source==='manual' && in_array($status,array('pending','paid','active'),true)):?><form method="post" onsubmit="return confirm('<?php echo esc_js( __( 'Cancel this manual sponsorship?', 'tutor-learning-suite' ) ); ?>');"><?php wp_nonce_field('tcsp_order_admin');?><input type="hidden" name="tcsp_order_action" value="cancel_manual"><input type="hidden" name="order_id" value="<?php echo (int)$r->ID;?>"><button class="button button-small" type="submit">Cancel manual</button></form><?php endif;?></div><?php else:?>—<?php endif;?></td></tr><?php endforeach;if(!$requests):?><tr><td colspan="13">No sponsorship requests match these filters.</td></tr><?php endif;?></tbody></table><?php if ( $request_query->max_num_pages > 1 ) : ?><div class="tcsp-request-pagination" aria-label="<?php esc_attr_e( 'Sponsorship request pages', 'tutor-learning-suite' ); ?>"><?php echo wp_kses_post( paginate_links( array( 'base' => add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => 'requests', 'request_status' => $request_status, 'request_type' => $request_type, 'requests_paged' => '%#%' ), admin_url( 'admin.php' ) ), 'format' => '', 'current' => $request_page, 'total' => (int) $request_query->max_num_pages, 'type' => 'plain', 'prev_text' => '&lsaquo; ' . __( 'Previous', 'tutor-learning-suite' ), 'next_text' => __( 'Next', 'tutor-learning-suite' ) . ' &rsaquo;' ) ) ); ?></div><?php endif; ?></div>

			<?php endif; ?>
		</div>
		<style>
		.tcsp-sponsor-manager{max-width:1600px}.tcsp-sponsor-manager .nav-tab-wrapper{display:flex;gap:5px;flex-wrap:wrap;border-bottom:1px solid #dcdcde}.tcsp-sponsor-manager .nav-tab{border-radius:8px 8px 0 0}.tcsp-admin-plans{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:clamp(14px,2vw,22px);margin:0 0 20px;box-shadow:0 5px 20px rgba(20,30,50,.035)}.tcsp-admin-plans h2,.tcsp-admin-plans h3{margin-top:0}.tcsp-manager-grid{display:grid;grid-template-columns:repeat(3,minmax(160px,1fr));gap:14px;margin:18px 0}.tcsp-manager-card{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:18px;box-shadow:0 4px 16px rgba(20,30,50,.035)}.tcsp-manager-card strong{display:block;font-size:28px;line-height:1.2}.tcsp-manager-card span{color:#646970}.tcsp-plan-editor th,.tcsp-plan-editor td{vertical-align:top}.tcsp-plan-editor input,.tcsp-plan-editor textarea,.tcsp-plan-editor select{max-width:100%;width:100%;box-sizing:border-box}.tcsp-plan-editor .small-text{width:90px}.tcsp-plan-editor small{display:block;color:#646970;margin-top:4px}.tcsp-unit-settings{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:10px 0 18px;padding:10px 12px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:8px}.tcsp-auto-toggle{display:inline-flex;gap:8px;align-items:center;margin:10px 0 18px;font-weight:600}.tcsp-sponsor-filters,.tcsp-manual-form,.tcsp-request-filters{display:flex;flex-wrap:wrap;gap:10px;align-items:end;background:#f6f7f7;border:1px solid #dcdcde;border-radius:8px;padding:14px;margin-bottom:14px}.tcsp-sponsor-filters>div,.tcsp-manual-form>div,.tcsp-request-filters>div{min-width:170px;flex:1}.tcsp-sponsor-filters label,.tcsp-manual-form label,.tcsp-request-filters label{display:block;font-size:12px;font-weight:600;margin-bottom:4px}.tcsp-sponsor-filters input,.tcsp-sponsor-filters select,.tcsp-manual-form input,.tcsp-manual-form select,.tcsp-request-filters select{width:100%}.tcsp-request-filters{align-items:end;margin:14px 0 18px;padding:12px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:10px}.tcsp-request-pagination{display:flex;justify-content:center;gap:8px;flex-wrap:wrap;margin:18px 0 4px}.tcsp-request-pagination .page-numbers{display:inline-flex;align-items:center;justify-content:center;min-width:34px;min-height:34px;padding:4px 10px;border:1px solid #dcdcde;border-radius:8px;background:#fff;text-decoration:none}.tcsp-request-pagination .page-numbers.current{background:#2271b1;border-color:#2271b1;color:#fff}.tcsp-bulk-panel{background:#f6f7f7;border:1px solid #dcdcde;border-radius:8px;padding:14px;margin-bottom:14px}.tcsp-bulk-head,.tcsp-bulk-fields{display:flex;gap:12px;align-items:end;flex-wrap:wrap}.tcsp-bulk-head{margin-bottom:12px}.tcsp-bulk-fields>div{min-width:180px;flex:1}.tcsp-bulk-fields label{display:block;font-size:12px;font-weight:600;margin-bottom:4px}.tcsp-status{display:inline-block;padding:3px 8px;border-radius:999px;font-size:11px;font-weight:600}.tcsp-status-active{background:#dcfce7;color:#166534}.tcsp-status-upcoming{background:#fef3c7;color:#92400e}.tcsp-status-expired,.tcsp-status-rejected,.tcsp-status-cancelled{background:#fee2e2;color:#991b1b}.tcsp-status-pending,.tcsp-status-paid{background:#e0e7ff;color:#3730a3}.tcsp-status-scheduled{background:#fef3c7;color:#92400e}.tcsp-inline-status{display:flex;gap:5px;align-items:center}.tcsp-inline-status select{max-width:110px}.tcsp-tutor-bulk-form,.tcsp-request-bulk-form{margin-bottom:0}.tcsp-sponsor-manager .widefat td,.tcsp-sponsor-manager .widefat th{vertical-align:middle}.tcsp-sponsor-manager .tcsp-row-actions{display:flex;gap:6px;align-items:center;flex-wrap:wrap}.tcsp-source{display:inline-block;padding:3px 7px;border-radius:999px;font-size:11px;font-weight:600;background:#f0f0f1}.tcsp-source-manual{background:#fff4cc}.tcsp-source-payment{background:#e7f1ff}

        /* OStudy admin visual refresh: make sponsorship management easier to scan. */
        .tcsp-sponsor-manager{--os-admin-ink:#172943;--os-admin-muted:#65748b;--os-admin-line:#e1e8f2;max-width:1600px;color:var(--os-admin-ink)}
        .tcsp-sponsor-manager .nav-tab-wrapper{gap:7px;margin:16px 0 20px;padding:0 0 8px;border-bottom:1px solid var(--os-admin-line)}
        .tcsp-sponsor-manager .nav-tab{padding:9px 15px;border:1px solid var(--os-admin-line);border-radius:10px;background:#fff;color:#50617a;font-weight:650;transition:background .15s ease,border-color .15s ease,color .15s ease}
        .tcsp-sponsor-manager .nav-tab:hover{background:#f1f5ff;border-color:#cbd8fb;color:#2949ba}
        .tcsp-sponsor-manager .nav-tab-active,.tcsp-sponsor-manager .nav-tab-active:focus,.tcsp-sponsor-manager .nav-tab-active:hover{border-color:#cbd8fb;background:#edf2ff;color:#2949ba}
        .tcsp-sponsor-manager .tcsp-admin-plans{border-color:var(--os-admin-line);border-radius:16px;box-shadow:0 9px 26px rgba(25,45,86,.055)}
        .tcsp-sponsor-manager .tcsp-admin-plans h2{font-size:20px;font-weight:750;color:var(--os-admin-ink)}
        .tcsp-sponsor-manager .tcsp-manager-grid{grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}
        .tcsp-sponsor-manager .tcsp-manager-card{position:relative;overflow:hidden;border-color:var(--os-admin-line);border-radius:15px;padding:20px 20px 18px;box-shadow:0 8px 22px rgba(25,45,86,.045)}
        .tcsp-sponsor-manager .tcsp-manager-card:before{content:"";position:absolute;inset:0 0 auto;height:4px;background:linear-gradient(90deg,#3558db,#6a82ee)}
        .tcsp-sponsor-manager .tcsp-manager-card strong{margin:3px 0 5px;font-size:30px;letter-spacing:-.035em;color:var(--os-admin-ink)}
        .tcsp-sponsor-manager .tcsp-manager-card span{font-size:12px;font-weight:600;color:var(--os-admin-muted)}
        .tcsp-sponsor-manager .tcsp-request-filters,.tcsp-sponsor-manager .tcsp-sponsor-filters,.tcsp-sponsor-manager .tcsp-manual-form{gap:12px;padding:16px;border:1px solid var(--os-admin-line);border-radius:13px;background:#f7f9fd}
        .tcsp-sponsor-manager .tcsp-request-filters label,.tcsp-sponsor-manager .tcsp-sponsor-filters label,.tcsp-sponsor-manager .tcsp-manual-form label{color:#40516b;font-size:12px}
        .tcsp-sponsor-manager input:not([type=checkbox]),.tcsp-sponsor-manager select,.tcsp-sponsor-manager textarea{min-height:37px;border-color:#d7e0ee;border-radius:8px;box-shadow:none}
        .tcsp-sponsor-manager input:focus,.tcsp-sponsor-manager select:focus,.tcsp-sponsor-manager textarea:focus{border-color:#7089ed;box-shadow:0 0 0 2px rgba(75,103,224,.12);outline:0}
        .tcsp-sponsor-manager .button{min-height:36px;padding:4px 13px;border-radius:8px;font-weight:650}
        .tcsp-sponsor-manager .button-primary{border-color:#3558d9;background:#3558d9}
        .tcsp-sponsor-manager .button-primary:hover{border-color:#2947b8;background:#2947b8}
        .tcsp-sponsor-manager .tcsp-bulk-panel{padding:15px;border:1px solid var(--os-admin-line);border-radius:13px;background:#f7f9fd}
        .tcsp-sponsor-manager .widefat{border:1px solid var(--os-admin-line);border-radius:12px;border-spacing:0;overflow:hidden;box-shadow:0 5px 18px rgba(25,45,86,.035)}
        .tcsp-sponsor-manager .widefat thead th{padding:12px 10px;background:#f1f5fb;color:#40516b;font-size:11px;font-weight:750;text-transform:uppercase;letter-spacing:.035em}
        .tcsp-sponsor-manager .widefat td{padding:12px 10px;border-bottom:1px solid #edf1f7;color:#263851;font-size:12px}
        .tcsp-sponsor-manager .widefat tbody tr:hover td{background:#f8faff}
        .tcsp-sponsor-manager .tcsp-status,.tcsp-sponsor-manager .tcsp-source{padding:5px 9px;font-size:11px;letter-spacing:.01em}
        .tcsp-sponsor-manager .tcsp-request-pagination{justify-content:flex-end;margin-top:16px}
        .tcsp-sponsor-manager .tcsp-request-pagination .page-numbers{min-width:36px;min-height:36px;border-color:var(--os-admin-line);border-radius:9px;color:#40516b;font-weight:650}
        .tcsp-sponsor-manager .tcsp-request-pagination .page-numbers.current{border-color:#3558d9;background:#3558d9;color:#fff}
        .tcsp-sponsor-manager :focus-visible{outline:3px solid rgba(53,88,217,.25);outline-offset:2px}

		@media(max-width:1000px){.tcsp-manager-grid{grid-template-columns:1fr}.tcsp-plan-editor{display:block;overflow:auto;white-space:nowrap}}@media(max-width:600px){.tcsp-admin-plans{padding:12px}.tcsp-request-filters,.tcsp-sponsor-filters,.tcsp-manual-form{display:grid;grid-template-columns:1fr}.tcsp-request-filters>div,.tcsp-sponsor-filters>div,.tcsp-manual-form>div{min-width:0}.tcsp-sponsor-manager .widefat{min-width:900px}.tcsp-sponsor-manager .tcsp-admin-plans>table{display:block;max-width:100%;overflow-x:auto}}
		</style>
		<script>
		(function(){
			const type=document.getElementById('tcsp-manual-type'), target=document.getElementById('tcsp-manual-target'), plan=document.getElementById('tcsp-manual-tier');
			function sync(){if(!type||!target||!plan)return;const t=type.value;Array.from(target.options).forEach(o=>{if(!o.value){o.hidden=false;return;}o.hidden=o.dataset.type!==t;});Array.from(plan.options).forEach(o=>{if(!o.value){o.hidden=false;return;}o.hidden=o.dataset.type!==t;});if(target.selectedOptions[0]&&target.selectedOptions[0].dataset.type!==t)target.value='';if(plan.selectedOptions[0]&&plan.selectedOptions[0].dataset.type!==t)plan.value='';}
			if(type){type.addEventListener('change',sync);sync();}
			const head=document.getElementById('tcsp-head-check');if(head)head.addEventListener('change',()=>document.querySelectorAll('.tcsp-course-check').forEach(x=>x.checked=head.checked));const rh=document.getElementById('tcsp-request-head-check');const rc=document.getElementById('tcsp-request-selected-count');if(rh){const boxes=()=>Array.from(document.querySelectorAll('.tcsp-request-check'));const sync=()=>{if(rc)rc.textContent=boxes().filter(x=>x.checked).length+' selected';};rh.addEventListener('change',()=>{boxes().forEach(x=>x.checked=rh.checked);sync();});boxes().forEach(x=>x.addEventListener('change',sync));}
			const th=document.getElementById('tcsp-tutor-head-check');const tc=document.getElementById('tcsp-tutor-selected-count');if(th){const boxes=()=>Array.from(document.querySelectorAll('.tcsp-tutor-check'));const sync=()=>{if(tc)tc.textContent=boxes().filter(x=>x.checked).length+' selected';};th.addEventListener('change',()=>{boxes().forEach(x=>x.checked=th.checked);sync();});boxes().forEach(x=>x.addEventListener('change',sync));}
		})();
		</script>
		<?php
	}

	private function render_row( $course, $tiers ) {

		$course_id = (int) $course->ID;
		$tier_key  = TCSP_Plugin::get_promotion_tier( $course_id );
		$start     = TCSP_Plugin::get_promotion_start( $course_id );
		$end       = TCSP_Plugin::get_promotion_end( $course_id );
		$status    = $this->course_status( $course_id );
		$terms     = get_the_terms( $course_id, 'course-category' );
		$category  = array();
		if ( $terms && ! is_wp_error( $terms ) ) { foreach ( $terms as $term ) { $category[] = $term->name; } }
		$author = get_the_author_meta( 'display_name', $course->post_author );
		?>
		<tr>
			<th class="check-column"><input type="checkbox" name="course_ids[]" value="<?php echo esc_attr( $course_id ); ?>" aria-label="<?php echo esc_attr( $course->post_title ); ?>"></th>
			<td class="tcsp-sponsor-course"><a href="<?php echo esc_url( get_edit_post_link( $course_id ) ); ?>"><strong><?php echo esc_html( get_the_title( $course_id ) ); ?></strong></a><small>#<?php echo esc_html( $course_id ); ?></small></td>
			<td><?php echo esc_html( $author ); ?></td>
			<td><?php echo esc_html( implode( ', ', $category ) ); ?></td>
			<td><select name="row_tier[<?php echo esc_attr( $course_id ); ?>]" aria-label="<?php esc_attr_e( 'Sponsorship tier', 'tutor-learning-suite' ); ?>"><option value=""><?php esc_html_e( 'Not sponsored', 'tutor-learning-suite' ); ?></option><?php foreach ( $tiers as $key => $tier ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $tier_key, $key ); ?> title="<?php echo esc_attr( isset( $tier['description'] ) ? $tier['description'] : '' ); ?>"><?php echo esc_html( $tier['label'] ); ?></option><?php endforeach; ?></select></td>
			<td><input class="tcsp-mini-date" type="date" name="row_start[<?php echo esc_attr( $course_id ); ?>]" value="<?php echo esc_attr( $start ); ?>"></td>
			<td><input class="tcsp-mini-date" type="date" name="row_end[<?php echo esc_attr( $course_id ); ?>]" value="<?php echo esc_attr( $end ); ?>"></td>
			<td><span class="tcsp-status tcsp-status-<?php echo esc_attr( $status['key'] ); ?>"><?php echo esc_html( $status['label'] ); ?></span></td>
			<td><div class="tcsp-row-actions">
				<button type="submit" name="save_row" value="<?php echo esc_attr( $course_id ); ?>" class="button button-small"><?php esc_html_e( 'Save', 'tutor-learning-suite' ); ?></button>
				<a class="button button-small" href="<?php echo esc_url( get_edit_post_link( $course_id ) ); ?>"><?php esc_html_e( 'Edit course', 'tutor-learning-suite' ); ?></a>
				<?php if ( $tier_key ) : ?><button type="button" class="button button-small tcsp-row-extend" data-id="<?php echo esc_attr( $course_id ); ?>"><?php esc_html_e( '+30 days', 'tutor-learning-suite' ); ?></button><form method="post" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( 'Cancel this course sponsorship?', 'tutor-learning-suite' ) ); ?>');"><?php wp_nonce_field( self::NONCE ); ?><input type="hidden" name="tcsp_sponsorship_action" value="cancel_course_plan"><input type="hidden" name="course_id" value="<?php echo esc_attr( $course_id ); ?>"><button type="submit" class="button button-small"><?php esc_html_e( 'Cancel plan', 'tutor-learning-suite' ); ?></button></form><?php endif; ?>
			</div></td>
		</tr>
		<?php
	}

	private function render_filter_hidden_fields( $filters ) {
		foreach ( array( 'category' => 'tcsp_filter_category', 'tier' => 'tcsp_filter_tier', 'price' => 'tcsp_filter_price', 'promo_status' => 'tcsp_filter_promo', 's' => 'tcsp_filter_search' ) as $key => $field ) {
			if ( '' !== $filters[ $key ] && 'any' !== $filters[ $key ] ) {
				echo '<input type="hidden" name="' . esc_attr( $field ) . '" value="' . esc_attr( $filters[ $key ] ) . '">';
			}
		}
	}

	private function render_pagination( $total, $paged, $filters ) {
		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages < 2 ) return;
		$base = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&paged=%#%' );
		$args = array();
		foreach ( array( 'tcsp_filter_search' => 's', 'tcsp_filter_category' => 'category', 'tcsp_filter_tier' => 'tier', 'tcsp_filter_price' => 'price', 'tcsp_filter_promo' => 'promo_status' ) as $url_key => $filter_key ) {
			if ( '' !== $filters[ $filter_key ] && 'any' !== $filters[ $filter_key ] ) $args[ $url_key ] = $filters[ $filter_key ];
		}
		$base = add_query_arg( $args, $base );
		echo '<div class="tablenav"><div class="tablenav-pages tcsp-sponsor-pagination">' . wp_kses_post( paginate_links( array( 'base' => $base, 'format' => '', 'current' => $paged, 'total' => $pages, 'type' => 'plain', 'prev_text' => '&laquo;', 'next_text' => '&raquo;' ) ) ) . '</div></div>';
	}

	private function category_label( $term, $all_terms ) {
		$depth = 0;
		$parent = (int) $term->parent;
		while ( $parent ) {
			$depth++;
			$found = null;
			foreach ( $all_terms as $candidate ) { if ( (int) $candidate->term_id === $parent ) { $found = $candidate; break; } }
			$parent = $found ? (int) $found->parent : 0;
			if ( $depth > 20 ) break;
		}
		return str_repeat( '— ', $depth ) . $term->name;
	}

	private function course_status( $course_id ) {
		if ( '' === TCSP_Plugin::get_promotion_tier( $course_id ) ) return array( 'key' => 'none', 'label' => __( 'Not sponsored', 'tutor-learning-suite' ) );
		$now = current_time( 'timestamp' ); $start = TCSP_Plugin::get_promotion_start( $course_id ); $end = TCSP_Plugin::get_promotion_end( $course_id );
		if ( $start && $now < strtotime( $start . ' 00:00:00' ) ) return array( 'key' => 'upcoming', 'label' => __( 'Upcoming', 'tutor-learning-suite' ) );
		if ( $end && $now > strtotime( $end . ' 23:59:59' ) ) return array( 'key' => 'expired', 'label' => __( 'Expired', 'tutor-learning-suite' ) );
		return array( 'key' => 'active', 'label' => __( 'Active', 'tutor-learning-suite' ) );
	}

	private function count_status_courses( $status ) {
		$filters = array( 's' => '', 'category' => '', 'tier' => '', 'price' => '', 'promo_status' => $status );
		$args = $this->build_query_args( $filters );
		$args['posts_per_page'] = 1;
		$args['paged'] = 1;
		$q = new WP_Query( $args );
		return (int) $q->found_posts;
	}

	private function redirect_with_notice( $type, $message ) {
		$url = add_query_arg( array( 'page' => self::PAGE_SLUG ), admin_url( 'admin.php' ) );
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ( isset( $_POST['tcsp_return_tab'] ) ? sanitize_key( wp_unslash( $_POST['tcsp_return_tab'] ) ) : '' );
		if ( in_array( $tab, array( 'dashboard', 'plans', 'courses', 'tutors', 'requests' ), true ) ) {
			$url = add_query_arg( 'tab', $tab, $url );
			$preserve = array(
				'paged', 'tutor_paged',
				'tcsp_filter_search', 'tcsp_filter_category', 'tcsp_filter_tier', 'tcsp_filter_price', 'tcsp_filter_promo',
				'tcsp_tutor_filter_search', 'tcsp_tutor_filter_tier', 'tcsp_tutor_filter_status',
			);
			foreach ( $preserve as $key ) {
				if ( isset( $_GET[ $key ] ) && ! is_array( $_GET[ $key ] ) ) {
					$url = add_query_arg( $key, sanitize_text_field( wp_unslash( $_GET[ $key ] ) ), $url );
				}
			}
		}
		$url = add_query_arg( array( 'tcsp_notice' => $type, 'tcsp_notice_message' => rawurlencode( $message ) ), $url );
		wp_safe_redirect( $url );
		exit;
	}
}
