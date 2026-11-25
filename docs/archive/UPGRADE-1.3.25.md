# Upgrade to Tutor Learning Suite 1.3.25

1. Back up the WordPress database and `wp-content`.
2. Upload `tutor-learning-suite-1.3.25.zip` as an update to the existing Tutor Learning Suite. Keep Tutor LMS Core and Pro active.
3. Do not unlink or recreate parent/child relationships. This version preserves existing guardian records and the saved free-course permission.
4. Clear WordPress/page/CDN caches and browser cache; the asset version changes to 1.3.25.
5. On staging, log in as the parent and confirm the child permission still shows enabled. Then log in as the child and test a published Tutor course explicitly marked Free.
6. Test a paid course too; child self-enrolment and checkout must remain blocked.

When a course is blocked, the notice now identifies the specific reason and the request returns to the course page when the referer header is missing. If a course is reported as paid or purchasable, inspect Tutor LMS's price type and enabled access/membership add-ons for that course.

This release does not require a database migration or guardian relinking.
