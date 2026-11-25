# Tutor Learning Suite 1.3.22

## Simplified child course enrolment

- Child/transition/restricted learner accounts may self-enrol only in courses whose Tutor LMS canonical `_tutor_course_price_type` is `free`, and only when a verified, administrator-approved guardian has enabled the separate free-course permission for that child.
- The permission is default-off, managed by the guardian in **Family & Guardian**, and revoked on unlink, administrator revocation, age-band change, missing age data, or failed guardian verification.
- Paid courses remain blocked from child checkout and ordinary self-enrolment. A parent purchases a paid course for a linked child using Tutor LMS Pro's native Course Gifting flow.
- The custom duplicate gift form and custom parent course-enrolment panel were removed. A small linked-child selector prefills the single native Tutor Pro gift form; Tutor Pro remains responsible for checkout, delivery and claiming.
- A child may claim a paid gift only while Tutor Pro is processing its real gift-claim AJAX action and the scheduler record, recipient account, paid course, approved purchaser/guardian and completed order all match.
- Child free enrolments and guardian gift claims are attribution-logged on Tutor LMS enrolment records.
- Fixed child-side permission display so it no longer incorrectly requires the parent to be the current viewer.

## Compatibility

- Source integration reviewed against supplied Tutor LMS Core 4.2.1 and Tutor LMS Pro 4.2.0 archives.
- Requires Tutor LMS Core and Pro to remain active. Live checkout/claim testing is still required on the site's staging environment.
