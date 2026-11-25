# Tutor Learning Suite 1.3.1

## Child-safety and review enforcement

- Hardened student-account identification with a WordPress `tutor_student` role fallback when Tutor LMS's student helper or legacy marker is unavailable. Age-unknown student accounts remain restricted.
- Added sanitized audit entries when restricted students attempt public Tutor interactions, WordPress comments, or blocked REST community/review writes. No review text, DOB, email, phone or message content is recorded in these entries.
- Guardian private-session reviews continue to use the guardian's own WordPress account ID. The `(Parent/guardian)` label is now persisted directly in `comment_author` as well as review metadata, so Tutor templates that read the raw comment author do not omit the attribution. The display filter is idempotent for existing 1.3.0 reviews.
- Guardian review audit entries record the booking/session, tutor and learner IDs, not the review text.
- Guardian reviews require the guardian's own booking to be marked `completed` and pass the existing session-rating checks. Children cannot submit private tutor ratings or public comments.
- Course reviews are not submitted on behalf of a child. A parent may submit a course review only when the parent personally satisfies Tutor LMS's native enrollment/eligibility checks.

## Compatibility

- No database schema change and no DOB encryption-format change. Keep the existing `TLS_AGE_ENCRYPTION_KEY` unchanged.
- No Tutor LMS Core/Pro files or active theme files are modified.
- Existing 1.3.0 guardian review metadata remains readable; the author filter still labels older reviews.
