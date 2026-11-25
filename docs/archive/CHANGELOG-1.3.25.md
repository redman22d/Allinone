# Tutor Learning Suite 1.3.25

## Fix: guardian-approved free enrolment still blocked

- Replaced the runtime free-enrolment permission read with a side-effect-free validation of the approved guardian relationship, stored guardian consent and child free-enrolment permission. The previous path re-entered the general age/relationship synchronization routine during enrolment checks; that routine is intended for reconciliation and can revoke stale metadata while the request is being authorized.
- Parent permission save now verifies the updated meta write and uses the same read-only approval checks used by the child enrolment path.
- Added a single decision function for child free-course enrolment. It returns a specific denial reason for invalid student, age category, guardian approval, missing parental permission, invalid course, paid course, or additional purchase/access requirements.
- Server-side Tutor hooks use the same decision function, and audit events now include a sanitized denial reason.
- Blocked native enrolment posts now return to the course page when the browser referer is missing, rather than falling back to the site homepage.
- Browser detection of Tutor's native free-enrolment form now relies on Tutor's hidden action and course-ID fields, not theme-dependent CSS classes or method attributes. Server-side authorization remains mandatory.

## Security boundaries

- Paid and purchasable courses remain blocked for restricted child accounts.
- Guardian relationship, age band, guardian verification, student acceptance and the explicit child permission are all checked on the server.
- No guardian relinking or data migration is required.

## Validation

- Added runtime tests using mocked WordPress/Tutor APIs for the complete parent permission -> child read -> free enrolment authorization path.
- Added negative tests for paid courses, purchasable courses, disabled permission and unapproved guardian relationship.
- Tested against Tutor LMS Core 4.2.1's `tutor_before_enroll` and `tutor_enroll_data` enrollment path.
- Live WordPress transaction still requires staging verification.
