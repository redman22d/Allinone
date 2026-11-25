# Tutor Learning Suite 1.3.1 — Age & Child-Safety Configuration

This document is an implementation/deployment guide, not legal advice. Have an Algerian lawyer review age categories, guardian authority, consent language, data processing and retention before collecting children's personal data.

## 1. Configure the separate encryption key before activation

Generate a 32-byte secret on your hosting/server:

```bash
openssl rand -hex 32
```

Add the resulting 64-character hexadecimal value to `wp-config.php`, above the `/* That's all, stop editing! */` line:

```php
define( 'TLS_AGE_ENCRYPTION_KEY', 'PASTE_YOUR_64_HEX_CHARACTER_SECRET_HERE' );
```

Do not paste the actual secret into ChatGPT, email, support tickets, JavaScript, theme files or version control. Keep it in secure server configuration and protected backups. Do not use the example placeholder as a real key.

The plugin uses AES-256-GCM with authenticated data. If the key is missing, invalid or unavailable, DOB cannot be read and the age category becomes `unknown`; student financial/public-interaction permissions and parent access fail closed.

## 2. Upgrade from 1.2.0 and migrate old encrypted DOB values

The previous 1.2.0 build derived its legacy DOB encryption key from the WordPress `AUTH_KEY` salt. Version 1.3.0 and later use the separate `TLS_AGE_ENCRYPTION_KEY`.

Before upgrade:

1. Make a database backup and a secure copy of `wp-config.php`.
2. Keep all WordPress authentication salts exactly as they are until the migration finishes.
3. Add `TLS_AGE_ENCRYPTION_KEY` before activating 1.3.1 (the same key is retained across later updates).
4. Activate 1.3.1, then open **Tools → TLS Child Safety** as an administrator.
5. Click **Migrate legacy encrypted dates of birth**. Each run checks up to 5,000 unprocessed account records. If the notice says more records remain, click the migration button again. Continue until it says no unprocessed date records remain.
6. Review the number of records migrated and the number processed. A processed record that could not be decrypted is not repaired by the migration; its account remains age-unknown and must have the DOB re-entered through the allowed account flow or corrected through a documented administrator procedure.
7. After confirming migration, make a fresh secure backup. Do not delete old backups until the recovery period expires.

If WordPress auth salts were rotated before the legacy values were migrated, the old data may no longer be decryptable. Do not guess a date or change account permissions manually; the account must remain restricted until its DOB is re-entered and verified through the site's procedure.

### Rotating the new key later

To rotate from a previous v2 key:

1. Back up database and `wp-config.php`.
2. Put the new 64-character value in `TLS_AGE_ENCRYPTION_KEY`.
3. Temporarily define `TLS_AGE_ENCRYPTION_KEY_PREVIOUS` with the old 64-character value.
4. Use the migration button and allow login/daily sync to re-encrypt records.
5. Verify all affected accounts are no longer age-unknown and no further migration candidates remain.
6. Remove `TLS_AGE_ENCRYPTION_KEY_PREVIOUS` only after successful verification and a secure backup.

Never remove the current key or the previous key while records still depend on it.

## 3. Age policy

The default threshold is 19 because that is the current UStudy product setting. It is adjustable with:

```php
add_filter( 'tls_age_of_majority_threshold', function () {
    return 19;
} );
```

The plugin clamps the configured value between 18 and 25.

- **Child:** under 18. Existing-account guardian links require student acceptance and administrator review. A parent-created account requires parent/guardian attestation and administrator review.
- **Transition:** 18 to under the configured threshold. The guardian relationship requires administrator review, but progress sharing and booking are off until the student explicitly opts in after review.
- **Adult:** at/above the configured threshold. Parent sharing is off until the student explicitly consents; consent can be revoked.
- **Unknown:** missing/unreadable DOB or missing encryption key. Treat as restricted. Do not assume adult.

Any change between known age bands revokes delegated parent permissions. A fresh student action is required before relevant sharing can resume. For a Child/Transition relationship, the administrator verification marker must also be present.

The age threshold is a software setting only. Do not treat it as a definitive statement of legal capacity or guardian authority.

## 4. Administrator guardian review procedure

Open **Tools → TLS Child Safety**. The pending queue shows the student, linked parent, age band and request timestamp. Before approving:

1. Follow a written procedure approved by the site's lawyer for establishing the claimed parent/legal-guardian relationship.
2. Check the submitted request and the account relationship using the approved procedure. If the relationship cannot be established, reject it.
3. Retain only the minimum evidence required by the approved policy, in a protected system with access/retention controls. The plugin does not upload or validate identity documents.
4. Tick **Site verification procedure completed** only when the procedure has actually been performed, then approve.
5. Review the audit entry.

The checkbox records that an administrator says the site's procedure was completed. It does not independently verify identity, legal guardianship or legal consent.

For Child accounts, approval enables the configured parent permissions, which the student can later revoke. For Transition accounts, approval does not turn on progress or booking permissions; the student must explicitly select each permission. Adult accounts require fresh student consent without a guardian-verification marker.

An old relationship with missing consent metadata should not be approved as-is. Reject/clear it and ask the parties to complete the new link flow, or follow a counsel-approved recovery procedure.

## 5. What restricted students can and cannot do

Restricted means Child, Transition or Unknown. The Tutor LMS Student role is preserved.

They can continue accessible learning: lessons, quizzes, assignments, and free course enrollment. The current version blocks public Tutor course ratings, Tutor lesson comments/Q&A, WordPress comments, public BuddyPress activity, BuddyPress private messages, paid Tutor checkout, and WooCommerce add-to-cart/checkout. Billing/review destinations are removed from the Tutor dashboard.

The WooCommerce block is site-wide for a restricted student account while WooCommerce is active. If the site sells non-educational products, this broad rule may need a separately reviewed product-type exception before use.

BuddyPress messaging is blocked both for a restricted sender and for a message addressed to a restricted student. This prevents tutors from sending BuddyPress DMs directly to children. Until a moderated parent-included communication channel is built, use the approved booking/guardian route or the site's published support contact. Other third-party chat plugins are not automatically covered.

Parents do not receive automatic access to every student record. A parent can view or book only the linked student when the matching permission, consent, age category and administrator review requirements are currently valid.

## 6. Review and public-comment policy

Restricted students (Child, Transition or Unknown) cannot submit public Tutor LMS course reviews, private-session tutor ratings, Tutor lesson comments/Q&A, WordPress comments, or the covered BuddyPress public activity/private-message actions. The checks are enforced on the server; hiding buttons is only a usability layer.

A parent/guardian may submit a private-tutoring review only for a session created from that parent's own account for a linked learner, and only after the session is marked `completed` and all session-rating checks pass. The WordPress comment's `user_id` is the parent account ID. The display name is stored with `(Parent/guardian)` and the review metadata records the author account and learner association. Submission creates an audit event without storing the review text or contact details in the audit row.

A parent cannot submit a Tutor LMS course review as a proxy for a child. A parent may submit a course review only when the parent personally satisfies Tutor LMS's native enrollment/eligibility checks. Never represent a parent as the child or imply the child personally authored a review. Existing 1.3.0 guardian review metadata remains supported by the display filter.


## 7. Operational requirements

- Use HTTPS across the whole site.
- Restrict `wp-config.php`, backups, database exports and administrator accounts.
- Enable administrator 2FA through a suitable WordPress security solution.
- Configure a real server cron to call `wp-cron.php` daily; default WP-Cron runs when site traffic triggers it.
- Define a process for DOB correction. The ordinary student privacy form only accepts a DOB when age is unknown; a known DOB cannot be self-edited.
- Define data retention/deletion, incident response, access logging and guardian dispute procedures with counsel.
- Test direct AJAX/REST calls, not just hidden UI buttons.

## 8. Known boundaries

- No government ID verification or legal guardian verification is built into the plugin.
- No parent checkout that automatically purchases/enrolls a course for a child is included. Tutors/administrators must manually enroll the child in Tutor LMS.
- The parent review label applies to the private tutoring review path where the parent booked the session. It does not turn a parent into a course reviewer for a course the parent did not take.
- BuddyPress is covered by the suite's specific hooks; other chat/community plugins need separate security review.
- The plugin does not certify compliance with Algerian law or replace counsel's review.


## 9. 1.3.1 staging acceptance checks

- As Child, Transition and Unknown students, attempt a Tutor course rating through the normal page and direct AJAX action; expect HTTP 403 and an audit event.
- Attempt Tutor lesson comments, Q&A, WordPress comments and REST comment/review writes as a restricted student; expect denial.
- Confirm restricted students can still open enrolled course content, lessons, quizzes and assignments, and can use free enrollment where otherwise available.
- As an adult guardian, create a private-session booking for an approved linked learner. Before completion, rating must be denied. After completion and final-access eligibility, submit a rating.
- Confirm the resulting review is attached to the guardian's WordPress user ID, the author reads `Parent/guardian`, the child is never the author, and the audit log has the guardian-review event.
- Confirm the guardian cannot review as the child through a course review endpoint. A parent may review a course only through their own Tutor LMS enrollment.
- Confirm an ordinary adult learner's own eligible course/private-session reviews continue to work.
- Confirm duplicate rating edits keep the correct current author attribution and remain pending moderation after update.

This guide is an implementation/deployment aid, not legal advice or proof of compliance. No live WordPress/Tutor LMS staging environment was available during this package build; run these checks on staging before production.
