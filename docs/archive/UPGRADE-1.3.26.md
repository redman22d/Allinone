# Upgrade to Tutor Learning Suite 1.3.26

1. Back up the WordPress database and `wp-content`.
2. Upload `tutor-learning-suite-1.3.26.zip` as an update to the existing Tutor Learning Suite. Keep Tutor LMS Core, Tutor LMS Pro, Better Messages and your existing theme active.
3. Do not relink existing parents and children. This update does not change family-link records, consent values, course enrollments or messages.
4. Clear WordPress, page/object/CDN caches and browser cache. The plugin asset version changes to 1.3.26.
5. Test the Family & Guardian page with a parent whose child already has at least one completed Tutor LMS enrollment.
6. Test Better Messages with staging accounts: (a) child + approved guardian, (b) child + guardian + tutor, (c) child + tutor without guardian, (d) child + another child, and (e) adult-only conversation.
7. Check Better Messages → Moderation/Restrictions separately; retain its normal spam, rate-limit and moderation settings.

## Behavior
- Parent course activity is read from Tutor LMS's enrolled-course IDs for the child. Existing progress-sharing authorization remains mandatory.
- Restricted minors cannot start or join direct conversations with unrelated adults or other minors. A tutor may participate in a supervised conversation only while the approved guardian is included.
- Adult learner conversations are not changed by the TLS restrictions.

Run these tests on staging before production. The plugin does not relink accounts or alter old message records; existing unsupervised threads involving restricted minors are blocked from access by the policy.
