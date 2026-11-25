# Tutor Learning Suite 1.3.33

## Guardian-to-admin moderation workflow
- Parent approval now writes the exact native pending database status directly (`hold` for Tutor course ratings, `0` for WordPress comments), without firing transition hooks that could normalize the Tutor custom status and mark a review published.
- Admin transition handlers now verify the persisted database status before marking feedback published. Tutor's `tutor_course_review_approved` callback also refuses to publish while the record is still held/pending.
- Added **Tutor LMS → Feedback Moderation**, a dedicated administrator queue for all child feedback that has completed guardian approval and still awaits an administrator.
- Administrators can publish or reject from that queue. Publication updates the native Tutor/WordPress status only after an explicit administrator action.
- Removed the parent's **Request changes** action. Parents can approve for administrator review or reject. Existing historical `parent_changes_requested` records remain recognized for compatibility, but no new change requests can be created.
- No data migration, guardian relinking or child permission reset is required.

## Verification
- Updated feedback moderation regression coverage for pending-status persistence, admin queue visibility, guardian approval verification and removal of request-edits UI.
- On opening the administrator queue, recover older reviews that were marked published at parent approval but have no recorded administrator-approval timestamp; return them to the native pending state.
- Recovery also handles the previous transition-hook bug that could create an administrator-approval timestamp within the same request as the guardian decision: an approval timestamp within five seconds of the guardian approval is treated as part of that old parent action, not as a separate administrator decision.
- New final administrator approvals record the administrator user ID as well as the approval timestamp.
