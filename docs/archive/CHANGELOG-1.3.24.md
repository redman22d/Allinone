# Tutor Learning Suite 1.3.24

## Fix: free Tutor enrolment was still intercepted in the browser

- Removed the dependency on the server-localized `freeSelfEnrollmentCourseId` when identifying Tutor LMS Core's native free-enrolment form. Theme templates and page caches can make that presentation-only value unavailable even when the saved guardian permission is valid.
- The browser now allows only the native `tutor-enrol-course-form` carrying `tutor_course_action=_tutor_course_enroll_now` and a positive `tutor_course_id` to reach the server.
- This is not an authorization bypass. Server-side `tutor_before_enroll` and `tutor_enroll_data` checks still verify the current learner, age band, approved guardian relationship, saved child permission, canonical Tutor course price type and course purchasability.
- Paid checkout, cart, purchase, reviews, comments, messaging and other restricted actions remain blocked.

## Data and migration

- No guardian relinking or database migration is required. Existing approved family links and the child's saved free-course setting are retained.
- Asset version is bumped to 1.3.24 to invalidate stale cached JavaScript.

## Compatibility

- Reviewed against the supplied Tutor LMS Core 4.2.1 native `tutor-enrol-course-form` and `_tutor_course_enroll_now` implementation.
- Live enrolment still requires staging verification on the active theme and site configuration.
