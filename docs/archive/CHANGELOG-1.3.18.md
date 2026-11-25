# Tutor Learning Suite 1.3.18

- Added a separate **Allow my parent/guardian to enrol me in courses** permission in the learner's Family & Privacy settings. It is separate from learning-progress sharing and private-session booking, and is revoked when age/guardian verification becomes unknown or changes across policy bands.
- Added a guardian course panel to published course pages. It lists only linked learner accounts whose relationship is approved and whose course-enrolment consent is enabled.
- Free courses can be assigned directly to the selected child by an approved guardian. The enrolment is a normal Tutor LMS `tutor_enrolled` record under the child's account, marked as guardian-assigned, with no payment or fake gift order.
- Paid courses hand off to Tutor LMS Pro's native Gift Course flow. The selected child's existing name/email are populated in the native gift form; Tutor LMS continues to own checkout, payment, delivery and claiming.
- Added a 180-day, single-course, child-specific authorization for restricted learners to claim a guardian-selected native gift. The exception is limited to Tutor LMS Pro Gift Course claim/reveal execution and does not enable ordinary self-enrolment or checkout.
- Added audit events and enrolment metadata for guardian free-course assignments and native gift authorizations/claims.
- Added a responsive parent course panel and browser-side integration for the native Tutor LMS gift modal.
