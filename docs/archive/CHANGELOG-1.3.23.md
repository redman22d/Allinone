# Tutor Learning Suite 1.3.23

## Fix: permitted free-course enrolment was intercepted in the browser

- The child-safety modal script previously intercepted every enrolment-looking click and form submission for restricted learners, even when a guardian had explicitly enabled free-course self-enrolment.
- The server now localizes a narrowly scoped exception only on a single Tutor course page after verifying the current child, approved guardian relationship, child age band, saved guardian permission, canonical Tutor price type and non-purchasable status.
- The browser exception matches Tutor LMS Core's native `tutor-enrol-course-form`, `_tutor_course_enroll_now` action and exact `tutor_course_id`.
- Only that exact free-course form is allowed through the modal guard. Paid checkout, cart, purchase, review, comment, messaging and other restricted actions remain blocked.
- Server-side `tutor_before_enroll` and `tutor_enroll_data` checks remain authoritative.

## Compatibility

- Reviewed against the supplied Tutor LMS Core 4.2.1 enrolment template and `EnrollmentModel::do_enroll()` implementation.
- Live enrolment still requires staging verification on the active theme and site configuration.
