# Tutor Learning Suite 1.2.0

## Integration
- Added a Tutor LMS integration layer for public instructor-profile and course-archive template replacement, while preserving native single-course pages.
- Added Family & Guardian and Privacy & Security destinations in Tutor LMS dashboard navigation.
- Added parent linking for existing accounts with secure expiring invitations and student approval; acceptance records encrypted date of birth for age policy.
- Preserved parent-created child accounts and enabled multiple linked children per parent.
- Added fail-closed age handling: unknown age blocks parent progress/booking access; linked legacy accounts without encrypted DOB must complete age details.
- Added adult-consent renewal when an account reaches the configured age threshold. The default is 19 and is configurable through `tls_age_of_majority_threshold`.
- Added family progress display sourced from Tutor LMS enrollments and allowed private tutoring records.

## Private tutoring
- Replaced the new private-session proposal acceptance path's Tutor LMS cart/checkout behaviour with direct-to-tutor offline payment instructions.
- Added student payment declarations and tutor receipt confirmations, with authorization and atomic state checks.
- Preserved legacy order listeners/cleanup for pre-existing private-session records; new requests do not create hidden Tutor LMS checkout courses.
- Added server-side parent/child age and consent checks to delegated bookings.
- Bumped private-session asset version to invalidate old browser caches.

## Presentation
- Added/updated theme-agnostic styles for family access, availability calendars and direct payment instructions.
- No WordPress theme is included or changed.

## Compatibility
- Reviewed against supplied Tutor LMS Core 4.2.1 and Tutor LMS Pro 4.2.0 source archives.
- Does not modify Tutor LMS Core or Pro files.
