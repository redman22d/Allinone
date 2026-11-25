# Tutor Learning Suite 1.3.20

## Guardian course-enrolment and Tutor LMS gifting fixes

- Removed the viewer-sensitive `can_gift_course()` gate from the parent workflow. The panel now checks the site-wide Tutor Pro Course Gifting setting and paid-course status instead of course ownership.
- Rendered Tutor Pro's official `single.course.gift-this-course-modal` template inside the guardian panel with a course-specific modal trigger, so the parent flow no longer depends on Tutor's ordinary Gift button being visible in the purchase box.
- Made the paid-course “Enrol my child” CTA active; clicking without selecting a child now displays an inline instruction.
- Improved AJAX authorization errors to distinguish an expired nonce, unavailable gifting, an unlinked child, missing guardian/learner consent, and an existing enrolment.
- Fixed Tutor student detection to fall back to the Tutor student role and configured student meta key even when `TUTOR\User::is_student()` returns false.
- New child accounts now store both the configured Tutor student meta marker and the suite's legacy marker.
- Expanded native gift-claim context detection to recognize Tutor Pro route/action naming variations and split method/class indicators across stack frames, while retaining the child/course-specific grant and live guardian permission checks.

## Security retained

- Parent must be an approved adult guardian.
- Child must be linked, approved and have explicitly enabled guardian course-enrolment consent.
- Paid gifts are still created and paid for through Tutor LMS Pro; this release does not create a custom paid order.
- Restricted children remain blocked from ordinary self-enrolment and checkout. Only a valid Tutor Pro gift-claim context with a matching parent authorization may pass the restricted enrolment guard.
