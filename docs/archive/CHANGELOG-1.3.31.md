# Tutor Learning Suite 1.3.31

## Feedback moderation and review fixes
- Make native Tutor course review staging independent of `$_REQUEST['action']`; Tutor's own `tutor_before_rating_placed` action is the reliable capture point.
- Recover child-authored course reviews, Q&A, comments and private-session ratings that earlier plugin versions saved without guardian-stage metadata, including records whose comment status was already approved or whose stage was set to admin-pending without an actual recorded guardian decision.
- Treat `admin_pending` and `published` as guardian-approved only when matching guardian ID, child ID, guardian reviewer ID and review timestamp are all recorded.
- Block legacy admin approval paths from publishing restricted-child feedback until the guardian decision is recorded; automatically route missing-stage records to the approved guardian queue.
- Keep previously guardian-pending records pending if a third-party moderation screen changes the native comment status.
- Return edited/resubmitted child reviews to guardian approval before administrator moderation, including reviews previously in the admin-pending stage.
- Keep private-session review edits in the same guardian-first flow.
- Remove `sprintf()` use around translated percentage labels and private-session duration labels to avoid PHP 8 `ValueError: Unknown format specifier` when translations alter percent placeholders.
- Rename the booking form's local settings variable for clarity.

## Compatibility
- Retains native Tutor LMS rating/comment records and existing feedback metadata; no database migration or parent-child relinking is required.
- Reviewed against the supplied Tutor LMS Core 4.2.1 and Tutor LMS Pro 4.2.0 source.
