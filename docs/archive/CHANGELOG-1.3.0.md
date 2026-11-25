# Tutor Learning Suite 1.3.0

## Child safety and age policy

- Added a centralized Child / Transition / Adult / Unknown age policy while preserving Tutor LMS's Student role.
- Added AES-256-GCM DOB encryption using a separately configured 32-byte secret, with support for previous-key migration and legacy AUTH-salt encrypted values.
- Added chunked migration batches for existing DOB records, including processed-record markers to avoid repeatedly scanning the same batch.
- Age-unknown accounts fail closed and do not retain parent learning or booking permissions.
- Any change between known age bands revokes delegated parent access and requires fresh consent where appropriate.
- Child and Transition guardian relationships require administrative review; Transition and Adult sharing remain off until the student explicitly enables each permission.
- Added admin verification marker requirements for Child/Transition parent access and made malformed/orphan requests rejectable.
- Added/retained a sanitized application audit trail and administrative review screen.

## Public interactions and purchases

- Blocked public Tutor LMS reviews, lesson comments, Q&A/discussions, WordPress comments and public BuddyPress activity for restricted students.
- Blocked BuddyPress messaging both from restricted students and to restricted students, including inbox access and REST access.
- Removed Tutor LMS billing/review dashboard destinations for restricted student accounts.
- Blocked paid Tutor checkout and WooCommerce add-to-cart/checkout for restricted student accounts.
- Learning access, lessons, quizzes, assignments and free enrollment remain available.

## Parent-attributed private-session reviews

- Restricted students cannot rate tutors publicly.
- Parent-booked private-session reviews remain under the parent's own account and are labelled `(Parent/guardian)`.
- Private-session tutor ratings are now shown and accepted only after the session is marked completed.

## Privacy and family workflow

- Transition-age existing-account links now wait for the site's administrator review; the student then explicitly enables sharing.
- Adult re-consent is timestamped against the linked parent when the student enables sharing.
- Unlink/revoke clears consent and verification metadata.

## Compatibility and limitations

- No Tutor LMS Core/Pro files or WordPress theme are modified or bundled.
- Compatibility target remains Tutor LMS Core 4.2.1 and Pro 4.2.0 from the supplied archives.
- Paid-course child enrollment is still tutor/admin-managed; parent checkout does not automatically enroll a child.
- BuddyPress is covered by explicit integration hooks; other messaging plugins are not automatically covered.

## Private tutoring UI enforcement

- Restricted student accounts no longer render the private-tutoring request form; the interface directs the student to an approved guardian.
- Guardian-only accounts see only linked learners for whom `can_parent_book()` currently passes. The guardian must explicitly select a learner; the form does not offer a misleading “Myself” choice.
- Server-side AJAX authorization remains authoritative and independently checks every submitted learner ID.
