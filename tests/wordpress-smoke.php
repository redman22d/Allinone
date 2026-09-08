<?php
/**
 * Real WordPress/Tutor smoke test, run on a disposable local/development site:
 * wp eval-file /path/to/repository/tests/wordpress-smoke.php
 * Both Tutor and the suite must be active. Creates and deletes one test user.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    throw new RuntimeException( 'Run with wp eval-file on a disposable development site.' );
}
if ( ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
    WP_CLI::error( 'Refusing to modify a non-development database.' );
}
if ( ! function_exists( 'tutor' ) || ! class_exists( 'TutorInstructorOfflinePayment\Contact' ) ) {
    WP_CLI::error( 'Activate Tutor LMS and Tutor LMS Suite first.' );
}
$assert = static function ( $ok, $message ) {
    if ( ! $ok ) throw new RuntimeException( $message );
    WP_CLI::log( 'PASS: ' . $message );
};
$original_user = get_current_user_id();
$user_id = 0;
try {
    $login = 'tls-smoke-' . wp_generate_password( 12, false, false );
    $user_id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password( 32 ), 'role' => 'subscriber' ) );
    if ( is_wp_error( $user_id ) ) throw new RuntimeException( $user_id->get_error_message() );
    wp_set_current_user( $user_id );
    $icons = apply_filters( 'tutor_user_social_icons', array() );
    $assert( isset( $icons['_tutor_profile_youtube'] ), 'Native Tutor registry contains YouTube' );
    update_user_meta( $user_id, '_tutor_profile_youtube', 'https://www.youtube.com/@suite-test' );
    $contact = \TutorInstructorOfflinePayment\Contact::get( $user_id );
    $assert( $contact['youtube'] === 'https://www.youtube.com/@suite-test', 'Profile metadata imports through WordPress' );
    $assert( empty( $contact['show_youtube'] ), 'Imported YouTube is private until opted in' );
    $contact['show_youtube'] = 1;
    $saved = \TutorInstructorOfflinePayment\Contact::save( $user_id, $contact );
    $assert( ! is_wp_error( $saved ), 'Contact persists to real user metadata' );
    $public = \TutorInstructorOfflinePayment\Contact::public_contact( $user_id );
    $assert( isset( $public['socials']['youtube'] ), 'Opted-in YouTube appears in public contact' );
    $contact['show_youtube'] = 0;
    \TutorInstructorOfflinePayment\Contact::save( $user_id, $contact );
    $public = \TutorInstructorOfflinePayment\Contact::public_contact( $user_id );
    $assert( ! isset( $public['socials']['youtube'] ), 'Hidden YouTube is omitted' );
    ob_start();
    tls_suite_render_hub_page();
    $html = ob_get_clean();
    $assert( $html === '', 'Subscriber cannot render suite settings' );
    WP_CLI::success( 'WordPress smoke checks passed.' );
} finally {
    wp_set_current_user( $original_user );
    if ( is_int( $user_id ) && $user_id > 0 ) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user( $user_id );
    }
}
