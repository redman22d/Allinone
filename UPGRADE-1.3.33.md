# Upgrade to Tutor Learning Suite 1.3.33

1. Back up the WordPress database and `wp-content`.
2. Keep Tutor LMS Core, Tutor LMS Pro, Better Messages and the active theme installed.
3. Upload and replace Tutor Learning Suite with `tutor-learning-suite-1.3.33.zip`.
4. Clear WordPress, Hostinger/page/CDN and browser caches.
5. In WordPress Admin, open **Tutor LMS → Feedback Moderation**. This queue shows child-authored reviews, comments and questions that have completed guardian approval and are still waiting for an administrator.
6. Administrators can publish or reject each item from that page. Parent approval alone never publishes feedback.
7. Parents now have only **Approve for admin review** and **Reject** actions. The parent **Request changes** action has been removed.

## Data and compatibility

- No database migration or parent-child relinking is required.
- Existing guardian links, learner submissions, guardian approval records and audit records are preserved.
- Legacy records with the old `parent_changes_requested` stage remain recognized for compatibility, but no new change requests can be created.
- Tutor course ratings stay in Tutor's native pending state (`hold`) after guardian approval; WordPress comments remain pending (`0`).
- Final administrator publication writes Tutor's native `approved` status for Tutor course ratings and WordPress `1` for normal comments/Q&A.

If an earlier version accidentally published a child review immediately after parent approval, opening **Tutor LMS → Feedback Moderation** triggers a guarded recovery check. Items with a valid guardian approval record but no administrator approval timestamp are returned to the pending queue automatically.

The legacy recovery also identifies the previous hook's false admin-approval timestamp when it was recorded within five seconds of the guardian decision. New final admin decisions now record the moderator user ID as well as the timestamp.
