# Tutor Learning Suite 1.3.29

## Feedback moderation defaults
- Enabled administrator moderation by default for every native Tutor LMS course review, using Tutor LMS Core 4.2.1's `enable_course_review_moderation` option filter.
- Held all WordPress comments on course and lesson posts for administrator review by default, including adult learner and parent-authored comments.
- Preserved the restricted-child workflow: guardian review first, then administrator review.
- Adult and parent-authored private-session ratings continue to require administrator moderation; restricted-child session reviews require guardian approval before administrator moderation.
- Added the `tls_feedback_admin_moderation_enabled` filter for site owners who deliberately want to override the default.

## Compatibility and safety
- Does not change the child's underlying user ID or public privacy-safe author label.
- Does not apply public-comment moderation to unrelated WordPress post types or Better Messages conversations.
- No database migration or guardian relinking required.
