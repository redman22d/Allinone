<?php
/** Saved public contact preview; never renders hidden profile fields. */
use TutorInstructorOfflinePayment\Contact;
defined( 'ABSPATH' ) || exit;
$tioc_preview = Contact::public_contact( get_current_user_id() );
$tioc_labels = Contact::social_networks();
?>
<section class="tioc-contact-preview" aria-labelledby="tioc-contact-preview-title">
    <h4 id="tioc-contact-preview-title"><?php esc_html_e( 'Student-visible contact details', 'tutor-instructor-offline-payment' ); ?></h4>
    <p class="tioc-help"><?php esc_html_e( 'Preview of your saved contact details, not the full checkout page. Save changes above to refresh this preview.', 'tutor-instructor-offline-payment' ); ?></p>
    <?php if ( ! $tioc_preview['has_any'] ) : ?>
        <p><?php esc_html_e( 'No contact details are visible to students.', 'tutor-instructor-offline-payment' ); ?></p>
    <?php else : ?>
        <ul>
            <?php if ( $tioc_preview['phone'] ) : ?>
                <li><?php esc_html_e( 'Phone', 'tutor-instructor-offline-payment' ); ?>: <?php echo esc_html( $tioc_preview['phone'] ); ?></li>
            <?php endif; ?>
            <?php if ( $tioc_preview['whatsapp'] ) : ?>
                <li>WhatsApp: <?php echo esc_html( $tioc_preview['whatsapp'] ); ?></li>
            <?php endif; ?>
            <?php foreach ( $tioc_preview['socials'] as $tioc_network => $tioc_url ) : ?>
                <li><a href="<?php echo esc_url( $tioc_url, array( 'http', 'https' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $tioc_labels[ $tioc_network ] ); ?></a></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
