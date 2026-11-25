# Tutor Learning Suite 1.3.3 — Family-link and age-safety notes

## Who can end an active family link?

| Actor | Can end an active link? | Route |
|---|---|---|
| Child student (under 18) | No, not directly | Can submit a private concern for administrator review |
| Transition-age student (18 to below the configured adult threshold) | No, not directly | Can submit a private concern for administrator review |
| Adult student (at/above the configured threshold; default 19) | Yes | Family & Guardian → End family link |
| Linked parent/guardian | Yes | Family & Guardian → Linked children → Unlink |
| Site administrator | Yes, after reviewing an open concern | Tools → TLS Child Safety → End family link |

A student may reject an invitation before a relationship is activated. Rejecting a pending invitation is different from revoking an already-active parent link.

## Student concern process

1. An under-threshold student opens **Dashboard → Family & Guardian**.
2. They choose **Report a concern about this family link**, add an explanation and submit it.
3. The link remains active during review; no automatic access change happens just because a report was submitted.
4. A site administrator reviews the concern in **Tools → TLS Child Safety**.
5. The administrator chooses one of two documented outcomes:
   - **Review — keep link:** marks the concern reviewed and retains the relationship and currently enabled permissions.
   - **End family link:** removes the relationship and delegated parent permissions.

The concern reason is shown only in the administrator safety-review screen and remains in the administrator-visible closed history if the parent or an adult student ends the link before review. The administrator notification email does not include the concern text. The plugin does not automatically email the parent that a student submitted a safety concern; the administrator must decide whether and how to communicate under the site's safeguarding procedure.

## What ending the link changes

The plugin removes the active guardian relationship and its delegated permissions, including parent access to learning progress and booking-on-behalf permissions. It updates the guardian status and records an audit event.

The plugin does **not** delete either WordPress account, Tutor LMS enrollments, lessons, quizzes, grades, educational progress, private-session request history, proposals or payment declarations. The resolved concern remains as limited audit context.

A later family link is a new relationship request and must go through the site's configured acceptance and review process. Previous consent is not silently restored.

## Important implementation and policy notes

- The active relationship is stored against the student account. Underage self-unlink attempts are rejected server-side and audited; removing the button alone is not relied on.
- The parent-side unlink action checks that the current parent is the guardian recorded on that student account.
- Adult self-unlink checks the student role and adult age band on the server.
- Administrator actions require `manage_options` and action-specific nonces.
- Age-unknown accounts remain restricted by the age-safety policy.
- The default adult threshold is 19. The site's threshold and Algerian legal interpretation must be reviewed with qualified local counsel before launch.
- An administrator review is not an identity-verification service. The site operator must maintain a documented, proportionate process for confirming a claimed guardian relationship.
