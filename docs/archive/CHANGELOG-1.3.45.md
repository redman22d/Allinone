# Tutor Learning Suite 1.3.45

## Dashboard hotfix

- Removed the Tutor CourseModel SQL-rewrite filter introduced in 1.3.44. It was unnecessary for the public-course exclusion path and could interfere with instructor dashboard course data queries.
- Removed the custom floating dashboard-mode switch. Tutor LMS 4 provides its own profile-menu switch and `tutor_switch_profile` handler; the suite now leaves that native control untouched.
- Kept the supported Tutor LMS 4 learner navigation filter registrations and legacy compatibility filters for suite dashboard pages.

## Validation

- PHP and JavaScript syntax checks passed for the package.
- ZIP integrity verified. Live dashboard API behavior still requires testing on the target WordPress installation.
