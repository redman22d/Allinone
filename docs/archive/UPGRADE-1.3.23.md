# Upgrade to Tutor Learning Suite 1.3.23

1. Back up the database and `wp-content`; test on staging first.
2. Replace Tutor Learning Suite 1.3.22 with 1.3.23. Keep Tutor LMS Core and Pro active.
3. Clear page/object/CDN caches and browser cache. The asset version is bumped to 1.3.23.
4. Confirm the parent still has the child-specific **Allow this child to enrol themselves in free courses** setting enabled. Do not re-enable it if the child is no longer under the approved guardian relationship.
5. Sign in as the child and open the specific published course whose Tutor price type is **Free**. Click **Enroll Now**.
6. Confirm it proceeds without the learning-safety popup and appears under the child's **My Courses**.
7. Test a paid course, a discounted paid course, checkout, review and comment actions; all remain restricted for the child.

The browser exception applies only to the current single-course page and the exact native Tutor form containing `tutor_course_id` and `tutor_course_action=_tutor_course_enroll_now`. Server-side checks remain in force. If the free course still fails, capture the WordPress/Tutor AJAX or PHP error from that staging request; do not disable the age-safety plugin or remove server-side restrictions.
