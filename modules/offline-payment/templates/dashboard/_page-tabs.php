<?php
/**
 * Shared tab strip for the instructor offline-payment pages.
 *
 * @package TutorInstructorOfflinePayment
 */

use TutorInstructorOfflinePayment\Dashboard;
use TutorInstructorOfflinePayment\Orders;

defined( 'ABSPATH' ) || exit;

$tioc_pending_now = Orders::pending_count( get_current_user_id() );
$tioc_current     = isset( $tioc_page_tab ) ? $tioc_page_tab : Dashboard::PAGE_ORDERS;
$tioc_page_tabs   = array(
	Dashboard::PAGE_ORDERS => __( 'Offline payments', 'tutor-learning-suite' ) . ( $tioc_pending_now ? ' (' . (int) $tioc_pending_now . ')' : '' ),
	Dashboard::PAGE_SETUP  => __( 'Payment details', 'tutor-learning-suite' ),
);
?>
<nav class="tioc-page-tabs" aria-label="<?php esc_attr_e( 'Offline payments', 'tutor-learning-suite' ); ?>">
	<?php foreach ( $tioc_page_tabs as $tioc_slug => $tioc_label ) : ?>
		<a class="<?php echo $tioc_slug === $tioc_current ? 'is-active' : ''; ?>" href="<?php echo esc_url( tioc_dashboard_url( $tioc_slug ) ); ?>"<?php echo $tioc_slug === $tioc_current ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $tioc_label ); ?></a>
	<?php endforeach; ?>
</nav>
