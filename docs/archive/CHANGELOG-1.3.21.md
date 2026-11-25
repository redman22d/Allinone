# Tutor Learning Suite 1.3.21

## Parent-to-child course gifting repair

- Detect Tutor LMS Pro Course Gifting through its actual `TutorPro\\GiftCourse` namespaced classes. The previous release checked nonexistent global aliases and could therefore leave the guardian CTA unavailable.
- Render the guardian selector on Tutor Core's real `tutor_course/single/entry/after` hook, after Tutor Pro has rendered its native Gift Course button and single gift modal.
- Reuse Tutor Pro's one native `#tutor-gift-this-course-form`; do not render a duplicate modal/form. Tutor Pro 4.2.0 binds its checkout JavaScript to the first form with that ID, so duplicate forms could open a modal whose submit event was not connected to checkout.
- Open the native gift modal via the actual Tutor Pro button, with Tutor's modal API as a fallback, and lock the exact `recipient_name` and `recipient_email` fields to the selected linked child.
- Fix the restricted-child claim exception to recognize Tutor Pro's exact `tutor_pro_gift_enrollment` AJAX action and `TutorPro\\GiftCourse\\GiftEnrollment::ajax_gift_enrollment` handler.
- Validate the real processed gift scheduler record, completed Tutor order, course ID, parent purchaser ID and child's account email before allowing the Tutor enrolment hook to proceed.
- Consume the parent-to-child authorization after the child enrolment is created and record the gift reference and guardian attribution on the enrolment.
- Hide ordinary Gift Course initiation from restricted learner accounts; adult accounts continue to use Tutor Pro's normal gifting feature.

## Compatibility and verification

- Target source inspected: Tutor LMS Core 4.2.1 and Tutor LMS Pro 4.2.0 supplied with the project archives.
- This release changes only Tutor Learning Suite. It does not modify Tutor LMS Core, Tutor LMS Pro or the active theme.
- Automated PHP/JS syntax and source-contract regression checks are run for this package. A real checkout and gift-claim still need a staging WordPress instance with the corresponding Tutor LMS versions and payment gateway.
