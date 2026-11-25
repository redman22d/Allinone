# Upgrade to Tutor Learning Suite 1.3.22

1. Back up the database and `wp-content`, then install on staging first.
2. Keep Tutor LMS Core and Tutor LMS Pro active; do not install duplicate component plugins.
3. Update Tutor Learning Suite to 1.3.22 and clear page/object/CDN/browser caches.
4. In **Tools → TLS Child Safety**, ensure the guardian relationship is administrator-approved and the guardian account is verified.
5. In **Tutor Dashboard → Family & Privacy → Family & Guardian**, the parent must enable **Allow this child to enrol themselves in free courses**. It starts disabled.
6. Test the child account against one genuinely free course. The free enrolment should appear in the child's My Courses list.
7. Test a paid course: the parent selects a linked child in the recipient selector beside Tutor LMS Pro's native Gift Course button, completes the native checkout, and the child claims it from the Tutor LMS dashboard after the gift becomes available.
8. Confirm the child cannot use paid checkout or ordinary paid enrolment. Test a discounted paid course as well; it must remain restricted because Tutor's canonical course price type is `paid`.

The recipient selector only pre-fills Tutor Pro's existing native gift form. Tutor Pro handles the payment and gift record. No custom checkout, order or duplicate gift form is created.
