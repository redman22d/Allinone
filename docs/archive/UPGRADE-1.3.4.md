# Tutor Learning Suite 1.3.4 — Upgrade Guide

This release addresses security and data-integrity findings from the 1.3.3 full source audit. It is a plugin-only update from 1.3.3. Test on staging before updating production.

## Important: protect old public-upload paths before migration

New verification images and payment receipts are stored outside the public web root. Existing files are moved in bounded batches when wp-admin pages are loaded. **On Nginx, install deny rules for the legacy public locations immediately after deployment (or before deploying the update) and keep them until migration is confirmed complete.** Nginx does not read `.htaccess`.

For a standard WordPress uploads location, add these rules in the relevant `server` block:

```nginx
location ^~ /wp-content/uploads/tlms-ipe-verification/ {
    return 404;
}

location ^~ /wp-content/uploads/tutor-offline-payments/ {
    return 404;
}
```

If WordPress uses a custom uploads path, adjust the two URL paths to match the actual paths. Reload Nginx after validating its configuration. Apache sites receive legacy `.htaccess` deny files, but verify that `AllowOverride` is enabled and that a direct URL to a test legacy file returns 403/404.

The migration copies each legacy file to private storage, updates its attachment/order reference, verifies the destination and then deletes the old public copy. If a row cannot be migrated, it remains flagged for administrator review; do not remove the old directory or migration data manually.

## Private storage directory

By default, the plugin uses a directory named `tls-private-data` one level above `ABSPATH`, then separates instructor verification images and payment receipts beneath it. The directory must be writable by PHP and outside both `ABSPATH` and the actual web document root.

If the hosting layout requires a custom location, create a private writable directory outside the document root and add this to `wp-config.php` before the `/* That's all, stop editing! */` line:

```php
define( 'TLS_PRIVATE_STORAGE_DIR', '/absolute/path/outside/the/web/root/ustudy-private' );
```

Do not use a URL, a path under `public_html`, `htdocs`, `www`, or another served document root. Do not place this directory in a publicly served CDN bucket. Keep filesystem ownership/permissions restricted to the PHP process and system administrators.

## Before updating

1. Back up the database, `wp-content`, `wp-config.php`, and the existing private/DOB encryption configuration.
2. Test on a staging clone first.
3. Keep the current `TLS_AGE_ENCRYPTION_KEY` unchanged. Do not rotate WordPress authentication salts during this upgrade.
4. Keep Tutor LMS Core, Tutor LMS Pro and the current Tutor-compatible theme active.
5. Do not activate the six legacy component plugins separately while the Suite is active.
6. Confirm that the private storage directory is writable and outside the web root. If using Nginx, install the deny rules above before relying on the background/batched migration.

## Install

1. Go to **Plugins → Add New → Upload Plugin**.
2. Upload `tutor-learning-suite-1.3.4.zip` and update the existing Tutor Learning Suite plugin.
3. If WordPress does not offer an in-place replace, deactivate the old Suite, upload the new ZIP and reactivate it. Do not delete plugin tables or user metadata.
4. Visit **Settings → Permalinks** and save once.
5. Clear page, object and CDN caches.
6. Visit wp-admin over multiple requests so the bounded family, verification-document and receipt migrations can process their batches. Keep the legacy Nginx deny rules in place during this period.
7. Review the site logs and staging tests for storage permission failures. The plugin leaves migration failure/review flags when a file cannot be moved; resolve each flagged row before treating migration as complete.

## What is preserved

- Tutor LMS accounts, roles, courses, lessons, enrollments, progress, quizzes and assignments.
- Instructor profile form fields and user-meta keys.
- Course Search Pro filters, main query and pagination.
- Weekly private-session availability, existing requests, proposals and session history.
- Existing family relationship records, subject to the new versioned legacy privacy-review migration.
- DOB ciphertext and the configured encryption key.
- Existing offline-payment order records and receipt metadata.

No Tutor LMS Core/Pro files or theme files are modified. The update does not convert offline payments into platform-held funds and does not create a Ustudy wallet or escrow system.

## Staging acceptance checklist

### Authorization and family access

- Use unrelated student, tutor, parent and administrator accounts. Request `/tlms-private-sessions/v1/calendar` with another account's `user_id`, `student_id` or instructor route ID. An ordinary account must receive a permission failure and must not receive meeting details.
- Confirm a learner can see sessions booked by an approved parent on that learner's behalf, but an unrelated learner cannot.
- Change a test learner's DOB across a configured age-band boundary and test parent progress/booking access immediately without waiting for cron. Delegated access must be revoked until the new category's consent/review rules are satisfied.
- Test an existing legacy family relationship. Confirm the new versioned migration processes successive batches and does not mark completion while more records remain.
- Send invitations to an existing eligible student, an ineligible email and an unregistered email. The outward response should not disclose account existence. Test actor and target cooldowns.

### Sessions and offline payments

- Attempt to bulk approve a proposal-based session before the learner accepts the proposal. It must remain unconfirmed.
- Test allowed and disallowed source-state transitions for private sessions and offline payment orders.
- Submit concurrent approve/reject actions for one offline order in staging. Only one decision should take effect; verify Tutor's enrollment/order state and hooks.
- Test session cancellation as payer, learner (when not payer), tutor, unrelated account and administrator. Check notice-window rules and retained history. No refund is processed by the plugin.
- Test expiry while a temporary Tutor checkout course exists; verify the course is removed only after the state transition succeeds.
- Test booking date/time across site and tutor timezones, daylight-saving transitions where applicable, and reminders near midnight.
- Confirm restricted and age-unknown students cannot be manually enrolled by a tutor into a paid course; free-course manual enrollment should remain available.

### Files and verification

- Upload a test ID front/back pair and a payment receipt. Confirm the physical files are outside the web root and direct browser access is denied.
- Test authenticated receipt/ID access with an authorized administrator and deny access to a tutor, student and unrelated administrator without required capability.
- Test migration of an old ID image and receipt. Confirm the old public copy is deleted only after the private copy and reference are verified.
- On Nginx, test both legacy paths return 404 before and during migration.
- Force a failed second ID upload and confirm the first newly uploaded attachment is removed.
- Confirm full ID numbers are redacted in stored verification metadata after processing and only the required masked suffix remains.

### Ads, ratings and directory

- Confirm arbitrary ad IDs without a signed rendered token are rejected; test duplicate events and inactive campaigns. Note that the public token can only prove the event relates to a server-rendered ad, not that a human actually saw it.
- Confirm public tutor averages exclude internal private-session ratings.
- Load large tutor directories with real filters and sorting; verify ordering and totals remain correct while database work is batched.

## Known limitations

- The DOB is encrypted but user-entered. The plugin does not independently verify age or legal guardianship; administrator review depends on the site's real-world documented procedure.
- Public ad render tokens reduce arbitrary-event fabrication/replay but cannot provide proof of viewport visibility. Analytics remain a measurement, not a billing-grade proof of impression.
- The offline-payment lock serializes the Suite's own decision handlers. Exact interaction with external Tutor hooks, gateways or custom order writers requires a live integration and concurrency test.
- Tutor-directory candidate processing is batched, but global filtering and ranking still retain the lightweight matching set in memory. Load-test a production-sized directory.
- Free-text family concern reports remain restricted administrator data. A retention/deletion period and local legal basis still need to be decided and documented before launch.
- The plugin's test suites do not replace a live WordPress/Tutor LMS integration test or independent security review.
