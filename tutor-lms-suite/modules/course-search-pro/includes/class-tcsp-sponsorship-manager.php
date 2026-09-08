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

	/** @var TCSP_Settings */
	private $settings;

	public function __construct( TCSP_Settings $settings ) {
		$this->settings = $settings;
	}

	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 101 );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
	}

	public function add_menu() {
		add_submenu_page(
			'tutor',
			__( 'Sponsorship Manager', 'tutor-course-search-pro' ),
			__( 'Sponsorship Manager', 'tutor-course-search-pro' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/** Handle all mutating admin actions. */
	public function handle_actions() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( empty( $_POST['tcsp_sponsorship_action'] ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_POST['tcsp_sponsorship_action'] ) );
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
				$this->redirect_with_notice( 'updated', __( 'Course sponsorship updated.', 'tutor-course-search-pro' ) );
			}
			$this->redirect_with_notice( 'none', __( 'Could not update that course sponsorship.', 'tutor-course-search-pro' ) );
		}

		$ids = isset( $_POST['course_ids'] ) && is_array( $_POST['course_ids'] )
			? array_values( array_unique( array_filter( array_map( 'absint', wp_unslash( $_POST['course_ids'] ) ) ) ) )
			: array();

		// "All matching filters" deliberately resolves server-side, including
		// courses on pagination pages that are not currently displayed.
		if ( ! empty( $_POST['all_filtered'] ) ) {
			$filters = $this->request_filters_from_post();
			$ids     = $this->get_matching_course_ids( $filters );
		}

		if ( empty( $ids ) ) {
			$this->redirect_with_notice( 'none', __( 'No courses were selected.', 'tutor-course-search-pro' ) );
		}

		$count = 0;
		foreach ( $ids as $course_id ) {
			if ( TCSP_Plugin::COURSE_CPT !== get_post_type( $course_id ) || 'publish' !== get_post_status( $course_id ) ) {
				continue;
			}

			switch ( $action ) {
				case 'assign':
					if ( $this->save_course_assignment( $course_id, $_POST ) ) {
						$count++;
					}
					break;
				case 'remove':
					$this->remove_course_assignment( $course_id );
					$count++;
					break;
				case 'extend30':
					$this->extend_course_30_days( $course_id );
					$count++;
					break;
			}
		}

		$message = sprintf( _n( '%d course updated.', '%d courses updated.', $count, 'tutor-course-search-pro' ), $count );
		$this->redirect_with_notice( 'updated', $message );
	}

	private function save_course_assignment( $course_id, $post ) {
		$tiers = $this->settings->promotion_tiers();
		$tier  = isset( $post['bulk_tier'] ) && ! is_array( $post['bulk_tier'] ) ? sanitize_key( wp_unslash( $post['bulk_tier'] ) ) : '';
		if ( '' === $tier ) {
			$this->remove_course_assignment( $course_id );
			return true;
		}
		if ( ! isset( $tiers[ $tier ] ) ) {
			return false;
		}

		$schedule = isset( $post['bulk_schedule'] ) && ! is_array( $post['bulk_schedule'] ) ? sanitize_key( wp_unslash( $post['bulk_schedule'] ) ) : 'no_expiry';
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
		return true;
	}

	private function remove_course_assignment( $course_id ) {
		delete_post_meta( $course_id, TCSP_Plugin::PROMOTION_TIER_META );
		delete_post_meta( $course_id, TCSP_Plugin::PROMOTED_META );
		delete_post_meta( $course_id, TCSP_Plugin::PROMOTION_START_META );
		delete_post_meta( $course_id, TCSP_Plugin::PROMOTION_END_META );
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
		$meta_query = array();

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

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$filters = $this->current_filters();
		$args    = $this->build_query_args( $filters );
		$search_filter = null;
		if ( '' !== $filters['s'] ) {
			global $wpdb;
			$like = '%' . $wpdb->esc_like( $filters['s'] ) . '%';
			$search_filter = function ( $clauses ) use ( $like ) {
				global $wpdb;
				$clauses['join'] .= " LEFT JOIN {$wpdb->users} tcsp_admin_author ON tcsp_admin_author.ID = {$wpdb->posts}.post_author ";
				$clauses['where'] .= ' AND ' . $wpdb->prepare( "({$wpdb->posts}.post_title LIKE %s OR tcsp_admin_author.display_name LIKE %s)", $like, $like );
				return $clauses;
			};
			add_filter( 'posts_clauses', $search_filter, 20, 1 );
		}
		$q = new WP_Query( $args );
		if ( $search_filter ) {
			remove_filter( 'posts_clauses', $search_filter, 20 );
		}

		$categories = get_terms( array( 'taxonomy' => 'course-category', 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC' ) );
		$categories = is_wp_error( $categories ) ? array() : $categories;
		$tiers      = $this->settings->promotion_tiers();
		$total      = (int) $q->found_posts;
		$active     = $this->count_status_courses( 'active' );
		$upcoming   = $this->count_status_courses( 'upcoming' );
		$expired    = $this->count_status_courses( 'expired' );
		$unsponsored = $this->count_status_courses( 'none' );

		settings_errors( 'tcsp_sponsorship' );
		?>
		<div class="wrap tcsp-sponsor-manager">
			<h1><?php esc_html_e( 'Sponsorship Manager', 'tutor-course-search-pro' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Assign sponsorship tiers, manage dates, filter courses, and perform bulk updates without loading the entire course catalog.', 'tutor-course-search-pro' ); ?></p>

			<div class="tcsp-plan-summary">
				<?php foreach ( $tiers as $key => $tier ) : ?>
					<div class="tcsp-plan-summary-card" style="border-left-color:<?php echo esc_attr( $tier['color'] ); ?>">
						<strong><?php echo esc_html( $tier['label'] ); ?></strong>
						<span class="tcsp-plan-weight">Weight <?php echo esc_html( $tier['weight'] ); ?> · Exposure ×<?php echo esc_html( $tier['exposure'] ); ?></span>
						<p><?php echo esc_html( isset( $tier['description'] ) ? $tier['description'] : '' ); ?></p>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="tcsp-sponsor-stats">
				<div><strong><?php echo esc_html( $total ); ?></strong><span><?php esc_html_e( 'Matching', 'tutor-course-search-pro' ); ?></span></div>
				<div><strong><?php echo esc_html( $active ); ?></strong><span><?php esc_html_e( 'Active', 'tutor-course-search-pro' ); ?></span></div>
				<div><strong><?php echo esc_html( $upcoming ); ?></strong><span><?php esc_html_e( 'Upcoming', 'tutor-course-search-pro' ); ?></span></div>
				<div><strong><?php echo esc_html( $expired ); ?></strong><span><?php esc_html_e( 'Expired', 'tutor-course-search-pro' ); ?></span></div>
				<div><strong><?php echo esc_html( $unsponsored ); ?></strong><span><?php esc_html_e( 'Unsponsored', 'tutor-course-search-pro' ); ?></span></div>
			</div>

			<form method="get" class="tcsp-sponsor-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
				<div><label><?php esc_html_e( 'Search course / instructor', 'tutor-course-search-pro' ); ?></label><input type="search" name="tcsp_filter_search" value="<?php echo esc_attr( $filters['s'] ); ?>" placeholder="<?php esc_attr_e( 'Type a course or instructor…', 'tutor-course-search-pro' ); ?>"></div>
				<div><label><?php esc_html_e( 'Category', 'tutor-course-search-pro' ); ?></label><select name="tcsp_filter_category"><option value=""><?php esc_html_e( 'All categories', 'tutor-course-search-pro' ); ?></option><?php foreach ( $categories as $cat ) : ?><option value="<?php echo esc_attr( $cat->slug ); ?>" <?php selected( $filters['category'], $cat->slug ); ?>><?php echo esc_html( $this->category_label( $cat, $categories ) ); ?></option><?php endforeach; ?></select></div>
				<div><label><?php esc_html_e( 'Tier', 'tutor-course-search-pro' ); ?></label><select name="tcsp_filter_tier"><option value=""><?php esc_html_e( 'All sponsorships', 'tutor-course-search-pro' ); ?></option><option value="sponsored" <?php selected( $filters['tier'], 'sponsored' ); ?>><?php esc_html_e( 'Any sponsored', 'tutor-course-search-pro' ); ?></option><option value="unsponsored" <?php selected( $filters['tier'], 'unsponsored' ); ?>><?php esc_html_e( 'Unsponsored', 'tutor-course-search-pro' ); ?></option><?php foreach ( $tiers as $key => $tier ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filters['tier'], $key ); ?> title="<?php echo esc_attr( isset( $tier['description'] ) ? $tier['description'] : '' ); ?>"><?php echo esc_html( $tier['label'] ); ?></option><?php endforeach; ?></select></div>
				<div><label><?php esc_html_e( 'Price', 'tutor-course-search-pro' ); ?></label><select name="tcsp_filter_price"><option value=""><?php esc_html_e( 'All prices', 'tutor-course-search-pro' ); ?></option><option value="free" <?php selected( $filters['price'], 'free' ); ?>><?php esc_html_e( 'Free', 'tutor-course-search-pro' ); ?></option><option value="paid" <?php selected( $filters['price'], 'paid' ); ?>><?php esc_html_e( 'Paid', 'tutor-course-search-pro' ); ?></option></select></div>
				<div><label><?php esc_html_e( 'Date status', 'tutor-course-search-pro' ); ?></label><select name="tcsp_filter_promo"><option value="any" <?php selected( $filters['promo_status'], 'any' ); ?>><?php esc_html_e( 'Any status', 'tutor-course-search-pro' ); ?></option><option value="active" <?php selected( $filters['promo_status'], 'active' ); ?>><?php esc_html_e( 'Active', 'tutor-course-search-pro' ); ?></option><option value="upcoming" <?php selected( $filters['promo_status'], 'upcoming' ); ?>><?php esc_html_e( 'Upcoming', 'tutor-course-search-pro' ); ?></option><option value="expired" <?php selected( $filters['promo_status'], 'expired' ); ?>><?php esc_html_e( 'Expired', 'tutor-course-search-pro' ); ?></option><option value="none" <?php selected( $filters['promo_status'], 'none' ); ?>><?php esc_html_e( 'Not sponsored', 'tutor-course-search-pro' ); ?></option></select></div>
				<div class="tcsp-filter-buttons"><button class="button button-primary"><?php esc_html_e( 'Filter', 'tutor-course-search-pro' ); ?></button><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>"><?php esc_html_e( 'Reset', 'tutor-course-search-pro' ); ?></a></div>
			</form>

			<form method="post" id="tcsp-bulk-form">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="tcsp_sponsorship_action" id="tcsp-action" value="assign">
				<?php $this->render_filter_hidden_fields( $filters ); ?>
				<div class="tcsp-bulk-panel">
					<div class="tcsp-bulk-head"><strong><?php esc_html_e( 'Bulk sponsorship actions', 'tutor-course-search-pro' ); ?></strong><span id="tcsp-selected-count">0 selected</span><label class="tcsp-select-all-label"><input type="checkbox" id="tcsp-select-page"> <?php esc_html_e( 'Select page', 'tutor-course-search-pro' ); ?></label><label class="tcsp-select-all-label"><input type="checkbox" id="tcsp-select-all-filtered"> <?php esc_html_e( 'Select all matching filters', 'tutor-course-search-pro' ); ?></label></div>
					<div class="tcsp-bulk-fields">
						<div><label><?php esc_html_e( 'Action', 'tutor-course-search-pro' ); ?></label><select id="tcsp-bulk-action-select"><option value="assign"><?php esc_html_e( 'Assign / update tier', 'tutor-course-search-pro' ); ?></option><option value="remove"><?php esc_html_e( 'Remove sponsorship', 'tutor-course-search-pro' ); ?></option><option value="extend30"><?php esc_html_e( 'Extend end date by 30 days', 'tutor-course-search-pro' ); ?></option></select></div>
						<div class="tcsp-assign-only"><label><?php esc_html_e( 'Tier', 'tutor-course-search-pro' ); ?></label><select name="bulk_tier"><option value=""><?php esc_html_e( 'Choose tier…', 'tutor-course-search-pro' ); ?></option><?php foreach ( $tiers as $key => $tier ) : ?><option value="<?php echo esc_attr( $key ); ?>" title="<?php echo esc_attr( isset( $tier['description'] ) ? $tier['description'] : '' ); ?>"><?php echo esc_html( $tier['label'] ); ?> (<?php echo esc_html( $tier['weight'] ); ?>)</option><?php endforeach; ?></select></div>
						<div class="tcsp-assign-only"><label><?php esc_html_e( 'Schedule', 'tutor-course-search-pro' ); ?></label><select name="bulk_schedule" id="tcsp-bulk-schedule"><option value="immediate"><?php esc_html_e( 'Start immediately · no expiry', 'tutor-course-search-pro' ); ?></option><option value="30">30 days</option><option value="60">60 days</option><option value="90">90 days</option><option value="180">180 days</option><option value="365">1 year</option><option value="duration"><?php esc_html_e( 'Custom duration', 'tutor-course-search-pro' ); ?></option><option value="custom"><?php esc_html_e( 'Custom start / end dates', 'tutor-course-search-pro' ); ?></option><option value="no_expiry"><?php esc_html_e( 'No dates / always active', 'tutor-course-search-pro' ); ?></option></select></div>
						<div class="tcsp-duration tcsp-assign-only" hidden><label><?php esc_html_e( 'Duration (days)', 'tutor-course-search-pro' ); ?></label><input type="number" min="1" max="3650" name="bulk_duration" value="30"></div>
						<div class="tcsp-custom-dates tcsp-assign-only" hidden><label><?php esc_html_e( 'Start', 'tutor-course-search-pro' ); ?></label><input type="date" name="bulk_start"></div>
						<div class="tcsp-custom-dates tcsp-assign-only" hidden><label><?php esc_html_e( 'End', 'tutor-course-search-pro' ); ?></label><input type="date" name="bulk_end"></div>
						<div><button type="submit" class="button button-primary" id="tcsp-bulk-submit"><?php esc_html_e( 'Apply to selected', 'tutor-course-search-pro' ); ?></button></div>
					</div>
				</div>

				<table class="wp-list-table widefat fixed striped tcsp-sponsor-table"><thead><tr><th class="check-column"><input type="checkbox" id="tcsp-head-check"></th><th><?php esc_html_e( 'Course', 'tutor-course-search-pro' ); ?></th><th><?php esc_html_e( 'Instructor', 'tutor-course-search-pro' ); ?></th><th><?php esc_html_e( 'Category', 'tutor-course-search-pro' ); ?></th><th><?php esc_html_e( 'Tier', 'tutor-course-search-pro' ); ?></th><th><?php esc_html_e( 'Start', 'tutor-course-search-pro' ); ?></th><th><?php esc_html_e( 'End', 'tutor-course-search-pro' ); ?></th><th><?php esc_html_e( 'Status', 'tutor-course-search-pro' ); ?></th><th><?php esc_html_e( 'Actions', 'tutor-course-search-pro' ); ?></th></tr></thead><tbody>
				<?php if ( empty( $q->posts ) ) : ?><tr><td colspan="9"><?php esc_html_e( 'No courses match the current filters.', 'tutor-course-search-pro' ); ?></td></tr><?php else : foreach ( $q->posts as $course ) : $this->render_row( $course, $tiers ); endforeach; endif; ?>
				</tbody></table>
			</form>

			<?php $this->render_pagination( $total, isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1, $filters ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		</div>
		<style>
			.tcsp-sponsor-manager{max-width:1500px}.tcsp-plan-summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:18px 0}.tcsp-plan-summary-card{border-left:4px solid #2563eb;background:#fff;border:1px solid #dcdcde;border-left-width:4px;border-radius:6px;padding:13px}.tcsp-plan-summary-card strong{display:block;font-size:15px}.tcsp-plan-summary-card .tcsp-plan-weight{display:block;color:#646970;font-size:12px;margin-top:4px}.tcsp-plan-summary-card p{margin:8px 0 0;color:#444;font-size:13px;line-height:1.45}.tcsp-sponsor-stats{display:grid;grid-template-columns:repeat(5,minmax(120px,1fr));gap:12px;margin:18px 0}.tcsp-sponsor-stats>div{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:14px}.tcsp-sponsor-stats strong{display:block;font-size:24px}.tcsp-sponsor-stats span{color:#646970}.tcsp-sponsor-filters{display:flex;flex-wrap:wrap;gap:10px;align-items:end;background:#fff;border:1px solid #dcdcde;padding:14px;border-radius:6px;margin-bottom:14px}.tcsp-sponsor-filters>div{min-width:150px;flex:1}.tcsp-sponsor-filters label,.tcsp-bulk-fields label{display:block;font-size:12px;font-weight:600;margin-bottom:4px}.tcsp-sponsor-filters input,.tcsp-sponsor-filters select,.tcsp-bulk-fields input,.tcsp-bulk-fields select{width:100%}.tcsp-filter-buttons{display:flex;gap:6px;align-items:end;flex:0 0 auto!important}.tcsp-bulk-panel{background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;padding:12px;margin-bottom:12px}.tcsp-bulk-head{display:flex;gap:18px;align-items:center;flex-wrap:wrap;margin-bottom:10px}.tcsp-select-all-label{font-weight:400;font-size:12px}.tcsp-bulk-fields{display:flex;gap:10px;flex-wrap:wrap;align-items:end}.tcsp-bulk-fields>div{min-width:160px;flex:1}.tcsp-sponsor-table td,.tcsp-sponsor-table th{vertical-align:middle}.tcsp-sponsor-course strong{display:block}.tcsp-sponsor-course small{color:#646970}.tcsp-status{display:inline-block;padding:3px 8px;border-radius:999px;font-size:11px;font-weight:600}.tcsp-status-active{background:#dcfce7;color:#166534}.tcsp-status-upcoming{background:#fef3c7;color:#92400e}.tcsp-status-expired{background:#fee2e2;color:#991b1b}.tcsp-status-none{background:#f1f5f9;color:#475569}.tcsp-row-actions{display:flex;gap:5px;flex-wrap:wrap}.tcsp-row-actions form{display:inline}.tcsp-mini-date{width:125px}.tcsp-sponsor-pagination{margin-top:16px}.tcsp-sponsor-pagination .page-numbers{margin-right:4px}@media(max-width:1100px){.tcsp-plan-summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:18px 0}.tcsp-plan-summary-card{border-left:4px solid #2563eb;background:#fff;border:1px solid #dcdcde;border-left-width:4px;border-radius:6px;padding:13px}.tcsp-plan-summary-card strong{display:block;font-size:15px}.tcsp-plan-summary-card .tcsp-plan-weight{display:block;color:#646970;font-size:12px;margin-top:4px}.tcsp-plan-summary-card p{margin:8px 0 0;color:#444;font-size:13px;line-height:1.45}.tcsp-sponsor-stats{grid-template-columns:repeat(3,1fr)}.tcsp-sponsor-table{display:block;overflow:auto}}@media(max-width:782px){.tcsp-plan-summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:18px 0}.tcsp-plan-summary-card{border-left:4px solid #2563eb;background:#fff;border:1px solid #dcdcde;border-left-width:4px;border-radius:6px;padding:13px}.tcsp-plan-summary-card strong{display:block;font-size:15px}.tcsp-plan-summary-card .tcsp-plan-weight{display:block;color:#646970;font-size:12px;margin-top:4px}.tcsp-plan-summary-card p{margin:8px 0 0;color:#444;font-size:13px;line-height:1.45}.tcsp-sponsor-stats{grid-template-columns:repeat(2,1fr)}}
		</style>
		<script>
		(function(){
			const form=document.getElementById('tcsp-bulk-form'); if(!form)return;
			const pageChecks=()=>Array.from(form.querySelectorAll('input[name="course_ids[]"]'));
			const selectPage=document.getElementById('tcsp-select-page');
			const selectAll=document.getElementById('tcsp-select-all-filtered');
			const head=document.getElementById('tcsp-head-check');
			const countEl=document.getElementById('tcsp-selected-count');
			const actionSelect=document.getElementById('tcsp-bulk-action-select');
			const actionHidden=document.getElementById('tcsp-action');
			const assignOnly=form.querySelectorAll('.tcsp-assign-only');
			function update(){
				const checks=pageChecks(); let n=checks.filter(c=>c.checked).length;
				countEl.textContent=n+' selected'+(selectAll.checked?' · all matching filters':'');
				head.checked=checks.length>0 && n===checks.length; head.indeterminate=n>0&&n<checks.length;
				if(selectAll.checked) countEl.textContent='All matching courses selected';
			}
			function toggleAssign(){const show=actionSelect.value==='assign';assignOnly.forEach(el=>{el.hidden=!show;});document.getElementById('tcsp-bulk-submit').textContent=show?'Apply to selected':(actionSelect.value==='remove'?'Remove sponsorship':'Extend 30 days');}
			selectPage.addEventListener('change',()=>{pageChecks().forEach(c=>c.checked=selectPage.checked);update();});
			head.addEventListener('change',()=>{pageChecks().forEach(c=>c.checked=head.checked);update();});
			selectAll.addEventListener('change',()=>{update();});
			actionSelect.addEventListener('change',()=>{actionHidden.value=actionSelect.value;toggleAssign();});
			pageChecks().forEach(c=>c.addEventListener('change',update));
			document.querySelectorAll('.tcsp-row-extend').forEach(btn=>btn.addEventListener('click',function(){
				if(!window.confirm('Extend this sponsorship by 30 days?')) return;
				pageChecks().forEach(c=>c.checked=String(c.value)===String(btn.dataset.id));
				actionSelect.value='extend30'; actionHidden.value='extend30'; toggleAssign(); update(); form.requestSubmit();
			}));
			document.getElementById('tcsp-bulk-schedule').addEventListener('change',function(){document.querySelectorAll('.tcsp-duration').forEach(e=>e.hidden=this.value!=='duration');document.querySelectorAll('.tcsp-custom-dates').forEach(e=>e.hidden=this.value!=='custom');});
			form.addEventListener('submit',function(e){
				if(e.submitter && e.submitter.name==='save_row'){ return; }
				if(selectAll.checked){const hidden=document.createElement('input');hidden.type='hidden';hidden.name='all_filtered';hidden.value='1';form.appendChild(hidden);}
				else if(pageChecks().filter(c=>c.checked).length===0){e.preventDefault();alert('Please select at least one course.');return;}
				if(actionSelect.value==='assign' && !form.querySelector('[name="bulk_tier"]').value){e.preventDefault();alert('Please choose a sponsorship tier.');return;}
				let msg=actionSelect.value==='remove'?'Remove sponsorship from the selected courses?':actionSelect.value==='extend30'?'Extend sponsorship end dates by 30 days?': 'Apply the selected sponsorship tier and schedule?';
				if(!window.confirm(msg))e.preventDefault();
			});
			toggleAssign(); update();
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
			<td><select name="row_tier[<?php echo esc_attr( $course_id ); ?>]" aria-label="<?php esc_attr_e( 'Sponsorship tier', 'tutor-course-search-pro' ); ?>"><option value=""><?php esc_html_e( 'Not sponsored', 'tutor-course-search-pro' ); ?></option><?php foreach ( $tiers as $key => $tier ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $tier_key, $key ); ?> title="<?php echo esc_attr( isset( $tier['description'] ) ? $tier['description'] : '' ); ?>"><?php echo esc_html( $tier['label'] ); ?></option><?php endforeach; ?></select></td>
			<td><input class="tcsp-mini-date" type="date" name="row_start[<?php echo esc_attr( $course_id ); ?>]" value="<?php echo esc_attr( $start ); ?>"></td>
			<td><input class="tcsp-mini-date" type="date" name="row_end[<?php echo esc_attr( $course_id ); ?>]" value="<?php echo esc_attr( $end ); ?>"></td>
			<td><span class="tcsp-status tcsp-status-<?php echo esc_attr( $status['key'] ); ?>"><?php echo esc_html( $status['label'] ); ?></span></td>
			<td><div class="tcsp-row-actions">
				<button type="submit" name="save_row" value="<?php echo esc_attr( $course_id ); ?>" class="button button-small"><?php esc_html_e( 'Save', 'tutor-course-search-pro' ); ?></button>
				<a class="button button-small" href="<?php echo esc_url( get_edit_post_link( $course_id ) ); ?>"><?php esc_html_e( 'Edit course', 'tutor-course-search-pro' ); ?></a>
				<?php if ( $tier_key ) : ?><button type="button" class="button button-small tcsp-row-extend" data-id="<?php echo esc_attr( $course_id ); ?>"><?php esc_html_e( '+30 days', 'tutor-course-search-pro' ); ?></button><?php endif; ?>
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
		if ( '' === TCSP_Plugin::get_promotion_tier( $course_id ) ) return array( 'key' => 'none', 'label' => __( 'Not sponsored', 'tutor-course-search-pro' ) );
		$now = current_time( 'timestamp' ); $start = TCSP_Plugin::get_promotion_start( $course_id ); $end = TCSP_Plugin::get_promotion_end( $course_id );
		if ( $start && $now < strtotime( $start . ' 00:00:00' ) ) return array( 'key' => 'upcoming', 'label' => __( 'Upcoming', 'tutor-course-search-pro' ) );
		if ( $end && $now > strtotime( $end . ' 23:59:59' ) ) return array( 'key' => 'expired', 'label' => __( 'Expired', 'tutor-course-search-pro' ) );
		return array( 'key' => 'active', 'label' => __( 'Active', 'tutor-course-search-pro' ) );
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
		$url = add_query_arg( array( 'tcsp_notice' => $type, 'tcsp_notice_message' => rawurlencode( $message ) ), $url );
		wp_safe_redirect( $url );
		exit;
	}
}
