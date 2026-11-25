# Tutor Learning Suite 1.3.44

## Dashboard compatibility fixes

- Register Family & Privacy, Private Sessions, and My Payments on Tutor LMS 4's dedicated `tutor_student_dashboard_nav` filter while retaining the legacy learner-navigation filter.
- Add defensive array validation to learner navigation callbacks.
- Harden the private-session internal-course exclusion applied to Tutor's instructor CourseModel query: validate the input type, avoid duplicate conditions, use prepared values, and only rewrite the expected Tutor SQL shape.
- Prevent legacy Ads receipt and instructor-verification file migrations from running during admin-AJAX, cron, or REST requests, keeping Tutor dashboard data and profile-switch requests free from unrelated migration work.

## Validation

- PHP syntax checks and JavaScript syntax checks are run against the complete plugin package.
- Live WordPress/Tutor LMS behavior still requires staging-site verification.
