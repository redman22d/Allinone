# Upgrade to Tutor Learning Suite 1.3.0

This is a plugin-only release. It does not include or replace the active WordPress theme and does not modify Tutor LMS Core or Pro.

## Before upgrading

1. Back up the database, `wp-content`, and `wp-config.php` to secure storage.
2. Confirm Tutor LMS Core 4.2.1 and Pro 4.2.0, or arrange compatibility testing for your installed versions.
3. Deactivate the six standalone component plugins bundled into Tutor Learning Suite, if they are still active separately. Do not delete their data. Keep Tutor LMS Core, Tutor LMS Pro and your current theme active.
4. Configure `TLS_AGE_ENCRYPTION_KEY` before activating this release. Generate it with `openssl rand -hex 32`; store it only in `wp-config.php` or a protected server secret configuration. Never commit or share it.
5. Keep WordPress AUTH_KEY salts unchanged until all legacy encrypted DOB records have been migrated.

## Install

1. Upload `tutor-learning-suite-1.3.0.zip` using **Plugins → Add New Plugin → Upload Plugin**.
2. Activate Tutor Learning Suite.
3. Open **Tools → TLS Child Safety** and run the DOB migration batch. Repeat until the page reports no unprocessed records remain.
4. If any record cannot be decrypted, leave that account age-unknown and restricted until the date of birth is corrected through the site's documented process.
5. Review every pending guardian relationship under **Tools → TLS Child Safety**. Approve only after the site's documented guardian verification procedure is completed. The approval checkbox records the administrator's action; it is not identity verification by the software.
6. Save **Settings → Permalinks** once.
7. Verify the active theme's Tutor LMS profile/archive settings and test the custom instructor profile, course archive, family dashboard and private-session flow on staging.

## Key changes

- Tutor LMS Student roles are preserved. Age bands are an additive permission layer; no replacement WordPress role is created.
- Child accounts (under 18), transition accounts (18 to below the configured threshold), and unknown-age accounts are restricted from public reviews, comments, Q&A, BuddyPress public activity/private messages, paid checkout and sensitive dashboard destinations.
- Students can continue using available learning features, including course lessons, quizzes, assignments and free enrollment.
- A parent can view or book for a linked learner only when the age-specific site review, student consent and individual permission are valid.
- Child-account parent visibility/booking permissions are enabled after administrative review and may be revoked by the student. Transition-age and adult students must explicitly opt in after review.
- Private tutoring requests from a restricted student account are blocked in both the interface and AJAX endpoint. Parent booking forms list only linked learners currently authorized for booking.
- A parent review for a private-tutoring session is submitted using the parent's own account and is labelled Parent/guardian. The session must be completed.
- The active theme is not replaced. Tutor LMS Core and Pro files are not modified.

## Important scope limits

- Paid Tutor LMS/WooCommerce checkout is blocked for restricted student accounts. The WooCommerce restriction is site-wide for those accounts; if the website sells unrelated goods, do not enable this policy without a reviewed exception design.
- BuddyPress messaging is blocked for restricted senders and recipients. Other third-party chat plugins are not automatically covered.
- Parent course purchases that directly enroll a child are not included; a tutor or administrator must manually enroll the learner in Tutor LMS.
- The software does not verify identity documents or establish legal guardianship. It supports an administrator review workflow only.
- The default adult threshold is 19, matching the current UStudy product setting. Have Algerian counsel verify how it applies to each service and consent. This software setting is not legal advice or proof of compliance.

See `AGE-SAFETY-1.3.0.md` for encryption-key migration, review procedure, permissions, limitations and staging tests.
