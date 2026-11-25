# Tutor Learning Suite 1.3.32

## Guardian feedback approval access fix
- Move guardian feedback decisions from `wp-admin/admin-post.php` to the authenticated front-end Tutor Family dashboard. This avoids managed-host/security-plugin rules that deny non-administrator accounts access to admin-post endpoints.
- Keep the authenticated admin-post action as a compatibility fallback.
- Verify the action nonce, active guardian-child relationship, recorded guardian and child IDs, and current guardian user before accepting approve/reject/request-edits decisions.
- Return parents to the Family & Guardian dashboard with a clear success, expired-form or authorization notice instead of a generic access-denied page.
- Keep parent approval separate from final administrator moderation; approved feedback remains pending for the site administrator.
- No data migration or parent-child relinking is required.
