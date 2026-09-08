<?php
/**
 * Suite-wide admin hub.
 *
 * Adds one "Tutor LMS Suite" screen under the Tutor LMS menu that lists all
 * five modules with their status and a link to each module's own settings
 * page. This does NOT replace those pages — each module's settings form,
 * nonce handling, and save logic are untouched and still live on their own
 * page, exactly as before the merge. This is a directory/dashboard in
 * front of them, so there was no need to touch any settings-saving code
 * (the highest-risk part of a real merge) to get a single, obvious place
 * to land in wp-admin.
 *
 * @package TutorLmsSuite
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the hub page. Hooked at a lower priority than the modules' own
 * `admin_menu` registrations (they use the default 10, or 101 for the
 * Sponsorship Manager) so this appears first in Tutor's submenu.
 *
 * @return void
 */
function tls_suite_register_hub_page() {
	add_submenu_page(
		'tutor',
		__( 'Tutor LMS Suite', 'tutor-lms-suite' ),
		__( 'Suite Overview', 'tutor-lms-suite' ),
		'manage_options',
		'tutor-lms-suite',
		'tls_suite_render_hub_page'
	);
}
add_action( 'admin_menu', 'tls_suite_register_hub_page', 5 );

/**
 * One row of the hub table: a module's name, one-line description, active
 * status, and links to its own admin page(s).
 *
 * @return array<int, array{name: string, description: string, active: bool, links: array<int, array{label: string, slug: string}>}>
 */
function tls_suite_module_directory() {
	return array(
		array(
			'name'        => __( 'Course Format', 'tutor-lms-suite' ),
			'description' => __( 'Course type, delivery format, and session fields on the course editor and course page.', 'tutor-lms-suite' ),
			'active'      => function_exists( 'tcf_is_tutor_active' ) && tcf_is_tutor_active(),
			'links'       => array(),
		),
		array(
			'name'        => __( 'Course Search Pro', 'tutor-lms-suite' ),
			'description' => __( 'Full-text course search, filters, and sponsored placements.', 'tutor-lms-suite' ),
			'active'      => class_exists( 'TCSP_Plugin' ) && TCSP_Plugin::tutor_active(),
			'links'       => array(
				array(
					'label' => __( 'Search settings', 'tutor-lms-suite' ),
					'slug'  => 'tcsp-settings',
				),
				array(
					'label' => __( 'Sponsorship manager', 'tutor-lms-suite' ),
					'slug'  => 'tcsp-sponsorships',
				),
			),
		),
		array(
			'name'        => __( 'Instructor Offline Payment', 'tutor-lms-suite' ),
			'description' => __( 'Per-instructor offline checkout, payment approval, and payout details.', 'tutor-lms-suite' ),
			'active'      => class_exists( '\\TutorInstructorOfflinePayment\\Requirements' ) && ( new \TutorInstructorOfflinePayment\Requirements() )->passes(),
			'links'       => array(
				array(
					'label' => __( 'Orders', 'tutor-lms-suite' ),
					'slug'  => 'tioc-orders',
				),
				array(
					'label' => __( 'Settings', 'tutor-lms-suite' ),
					'slug'  => 'tioc-settings',
				),
			),
		),
		array(
			'name'        => __( 'Instructor Profile Extra', 'tutor-lms-suite' ),
			'description' => __( 'Extended instructor profiles: location, education, languages, verification.', 'tutor-lms-suite' ),
			'active'      => function_exists( 'tutor' ) && function_exists( 'tutor_utils' ),
			'links'       => array(
				array(
					'label' => __( 'Instructor verification', 'tutor-lms-suite' ),
					'slug'  => 'tlms-ipe-verification',
				),
			),
		),
		array(
			'name'        => __( 'Private Sessions', 'tutor-lms-suite' ),
			'description' => __( 'Private tutoring requests, proposals, approval, and checkout.', 'tutor-lms-suite' ),
			'active'      => function_exists( 'tutor' ) || defined( 'TUTOR_VERSION' ),
			'links'       => array(
				array(
					'label' => __( 'Sessions', 'tutor-lms-suite' ),
					'slug'  => 'tlms-private-sessions',
				),
				array(
					'label' => __( 'Tutor reviews', 'tutor-lms-suite' ),
					'slug'  => 'tlms-private-sessions-reviews',
				),
			),
		),
	);
}

/**
 * Render the hub page.
 *
 * @return void
 */
function tls_suite_render_hub_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="wrap tls-suite-hub">
		<h1><?php esc_html_e( 'Tutor LMS Suite', 'tutor-lms-suite' ); ?></h1>
		<p>
			<?php esc_html_e( 'One installed plugin, five modules. Each module keeps its own settings page below - this is just a map to them, and to whether Tutor LMS currently satisfies what each one needs.', 'tutor-lms-suite' ); ?>
			<?php echo esc_html( sprintf( /* translators: %s: plugin version */ __( 'Suite version %s.', 'tutor-lms-suite' ), TLS_SUITE_VERSION ) ); ?>
		</p>

		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th style="width: 20%"><?php esc_html_e( 'Module', 'tutor-lms-suite' ); ?></th>
					<th><?php esc_html_e( 'What it does', 'tutor-lms-suite' ); ?></th>
					<th style="width: 10%"><?php esc_html_e( 'Status', 'tutor-lms-suite' ); ?></th>
					<th style="width: 25%"><?php esc_html_e( 'Manage', 'tutor-lms-suite' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( tls_suite_module_directory() as $module ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $module['name'] ); ?></strong></td>
						<td><?php echo esc_html( $module['description'] ); ?></td>
						<td>
							<?php if ( $module['active'] ) : ?>
								<span style="color:#1a7f37;">&#9679; <?php esc_html_e( 'Ready', 'tutor-lms-suite' ); ?></span>
							<?php else : ?>
								<span style="color:#b32d2e;">&#9679; <?php esc_html_e( 'Needs Tutor LMS', 'tutor-lms-suite' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( empty( $module['links'] ) ) : ?>
								&#8212;
							<?php else : ?>
								<?php foreach ( $module['links'] as $link ) : ?>
									<a class="button button-small" style="margin: 2px 4px 2px 0;" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $link['slug'] ) ); ?>">
										<?php echo esc_html( $link['label'] ); ?>
									</a>
								<?php endforeach; ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}
