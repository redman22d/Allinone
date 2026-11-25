# Upgrade to Tutor Learning Suite 1.3.24

1. Back up the database and `wp-content`; test on staging first.
2. Replace Tutor Learning Suite 1.3.23 with 1.3.24. Keep Tutor LMS Core and Pro active.
3. **Do not unlink or relink the parent and child.** The existing approved relationship and saved permission are retained.
4. Clear WordPress, page/object/CDN caches and the browser cache. The age-safety JavaScript asset version is bumped to 1.3.24.
5. Sign in as the child and open a published Tutor course whose Tutor price type is **Free**. Click **Enroll Now**. The native Tutor form should reach the server instead of being intercepted by the learning-safety popup.
6. If enrolment is still denied, note the new server response text: it will distinguish a missing guardian approval/permission or a course that Tutor considers purchasable. Do not disable server-side age-safety checks.
7. Test a paid course, discounted paid course, checkout, review and comment actions; all remain restricted for the child.

The browser exception is limited to the native Tutor form `tutor-enrol-course-form` with action `_tutor_course_enroll_now` and a positive course ID. The server remains authoritative and rejects any course that is not genuinely free or whose learner/guardian permission is not valid.
