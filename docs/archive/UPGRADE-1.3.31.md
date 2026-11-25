# Upgrade to Tutor Learning Suite 1.3.31

1. Back up the WordPress database and `wp-content` before upgrading.
2. Replace Tutor Learning Suite 1.3.30 with 1.3.31. Keep Tutor LMS Core, Tutor LMS Pro, Better Messages and the active theme enabled.
3. Clear WordPress/page/object/CDN caches and browser cache so PHP and JavaScript assets reload.
4. No parent-child relinking or manual metadata migration is required.
5. Open the parent's **Family & Privacy → Family & Guardian → Children's Feedback** page. The page now repairs eligible legacy child feedback records that were saved without guardian-stage metadata.
6. Test one new child course review, one edited/resubmitted review, and one completed private-session review on staging. Verify the parent approval stage appears before the admin moderation stage.

## Notes
- Percentage labels and private-session duration strings no longer pass translated percent placeholders to `sprintf()`.
- Existing admin-pending/published child items remain in that stage only when the same active guardian approval is explicitly recorded (guardian ID, child ID, reviewer ID and timestamp). Legacy items missing that proof are returned to the guardian queue before publication. A child's edit/resubmission sends the item back to guardian review.
- If `ValueError` or `Undefined variable $s` persists, inspect the PHP log's exact file and line; those errors can also originate from a theme or another plugin. Share the file:line and the immediately preceding stack frame with private data removed.
