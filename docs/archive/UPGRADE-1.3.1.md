# Upgrade to Tutor Learning Suite 1.3.1

This is a plugin-only hardening release. It does not replace the theme, Tutor LMS Core or Tutor LMS Pro.

## Before upgrading

1. Back up the WordPress database and `wp-content`.
2. Keep the existing `TLS_AGE_ENCRYPTION_KEY` configured exactly as it is. This release does not change DOB encryption or require the 1.3.0 DOB migration to be repeated.
3. Keep Tutor LMS Core, Tutor LMS Pro and your existing theme active. Do not activate the six bundled component plugins separately.
4. Apply the update on staging before production.

## Install

1. Upload `tutor-learning-suite-1.3.1.zip` under **Plugins → Add New Plugin → Upload Plugin** and replace/update the existing Tutor Learning Suite installation.
2. Confirm the plugin reports version **1.3.1**.
3. Clear WordPress, page, object and CDN caches.
4. Run the review/privacy tests in `AGE-SAFETY-1.3.1.md`.

## Important review behaviour

- Child, Transition and Unknown Tutor LMS student accounts cannot create public course reviews, private tutor ratings, Tutor lesson comments/Q&A, WordPress comments, or blocked BuddyPress interactions. These controls are enforced server-side.
- A guardian can review a private tutoring session booked from the guardian's own account only after completion. The review author ID remains the guardian's user ID and the public author label is `(Parent/guardian)`.
- A guardian cannot create a Tutor LMS course review as a proxy for the child. Course reviews remain subject to Tutor LMS's own eligibility checks for the account submitting the review.
- Existing 1.3.0 guardian reviews continue to receive the author label via the metadata display filter.

No DOB re-encryption or database migration is introduced by this release. This release does not establish legal guardianship or certify compliance with Algerian law; retain counsel review and staging verification before launch.
